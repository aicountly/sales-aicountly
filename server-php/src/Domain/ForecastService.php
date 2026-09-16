<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Context;
use Aicountly\Api\Db;

/**
 * A forecast you can argue with.
 *
 * It is arithmetic, not a model, and it says so: every figure on the screen is
 * one of three components the user can check for themselves.
 *
 *   already there   what has actually been invoiced (or ordered) so far
 *   + committed     orders we hold that are not yet invoiced and are due inside
 *                   the period — the most certain future revenue there is
 *   + weighted      open quotations that will be decided inside the period,
 *     pipeline      multiplied by the conversion rate this company actually
 *                   achieves, not a number somebody liked the look of
 *
 * THE THREE DOUBLE-COUNTS IT AVOIDS, each of which would flatter the number:
 *
 *   1. An accepted quotation that became an order would be counted twice — once
 *      as pipeline, once as backlog. Converted quotations are excluded from the
 *      pipeline cohort by definition: they are no longer open.
 *   2. An order already invoiced would be counted twice — once in the actual,
 *      once in the backlog. The backlog uses only the UNINVOICED portion of
 *      each line, so an order half billed contributes only its other half.
 *   3. A quotation that will not be decided until next month would inflate this
 *      month. Only quotations whose validity ends inside the period are
 *      eligible, because the rest cannot close in time to be invoiced.
 *
 * WHAT IT IS NOT. There is no confidence interval here and the word is not used.
 * The range is a SCENARIO BAND: the same arithmetic with the conversion
 * assumption moved, so a reader can see how much of the projection rests on
 * quotations that have not been won yet. Calling that a 90% confidence interval
 * would be inventing a statistic.
 *
 * AI IS NOT INVOLVED IN ANY NUMBER ON THIS SCREEN. It may be asked to explain
 * the arithmetic afterwards; it never produces it.
 */
final class ForecastService
{
    /** How far the scenario band moves the conversion assumption, either way. */
    private const BAND = 0.4;

    public function __construct(
        private readonly Context $ctx,
        private readonly MetricsService $metrics,
    ) {
    }

    /**
     * @param array{conversion_pc?:float|null, discount_pc?:float|null} $scenario
     * @param array{status:string, value:float|null, reason:string|null, basis:string, source:string} $actual
     * @return array<string, mixed>
     */
    public function project(string $from, string $to, string $asOf, array $actual, array $scenario = []): array
    {
        $periodEnd = new \DateTimeImmutable($to);
        $asOfDate = new \DateTimeImmutable(min($asOf, $to));

        $conversion = $this->metrics->quoteConversion($from, $to, $asOf);
        $baselineConversion = $this->baselineConversion($conversion);

        $backlog = $this->committedBacklog($to);
        $pipeline = $this->eligiblePipeline($asOf, $to);

        // The scenario overrides the measured assumptions. Nothing is written:
        // this is a planning surface and the label on it says so.
        $scenarioConversion = self::clampPc($scenario['conversion_pc'] ?? null);
        $scenarioDiscount = self::clampPc($scenario['discount_pc'] ?? null);
        $usingScenario = $scenarioConversion !== null || $scenarioDiscount !== null;

        $appliedConversion = $scenarioConversion ?? $baselineConversion['rate_pc'];
        $appliedDiscount = $scenarioDiscount ?? $pipeline['average_discount_pc'];

        // Pipeline is weighted off the GROSS quoted value so the discount
        // assumption has something to act on: applying a discount to a figure
        // that already has one baked in would double-discount it.
        $pipelineExpected = $appliedConversion === null
            ? 0.0
            : $pipeline['gross'] * ($appliedConversion / 100) * (1 - ($appliedDiscount ?? 0) / 100);

        $actualValue = $actual['status'] === 'ready' ? (float) ($actual['value'] ?? 0) : null;
        $available = $actualValue !== null;

        $mid = $available ? $actualValue + $backlog['value'] + $pipelineExpected : null;

        // The band moves ONLY the uncertain component. The money already
        // invoiced is not uncertain and must not be made to look as though it is.
        $bandLow = $appliedConversion === null ? 0.0 : max(0.0, $appliedConversion * (1 - self::BAND));
        $bandHigh = $appliedConversion === null ? 0.0 : min(100.0, $appliedConversion * (1 + self::BAND));
        $lowPipeline = $pipeline['gross'] * ($bandLow / 100) * (1 - ($appliedDiscount ?? 0) / 100);
        $highPipeline = $pipeline['gross'] * ($bandHigh / 100) * (1 - ($appliedDiscount ?? 0) / 100);

        $target = $this->metrics->companyTarget($from, $to);

        $daysRemaining = max(0, (int) $asOfDate->diff($periodEnd)->format('%a'));

        $reason = null;
        if (!$available) {
            $reason = $actual['reason'] ?? 'The actual figure for this period could not be read.';
        } elseif ($appliedConversion === null && $pipeline['count'] > 0) {
            // Pipeline exists but nothing has ever been decided, so there is no
            // honest weight to apply to it. The projection still stands on the
            // actual and the backlog, and the panel says the pipeline is unweighted.
            $reason = 'No quotation has been decided yet, so open quotations are not weighted into this projection.';
        }

        return [
            'available'   => $available,
            'reason'      => $reason,
            'method'      => 'rule_based',
            'label'       => 'Rule-based estimate',
            'as_of'       => $asOf,
            'period'      => ['from' => $from, 'to' => $to],
            'days_remaining' => $daysRemaining,
            'actual'      => $actual,
            'components'  => [
                [
                    'key' => 'actual', 'label' => 'Recognised so far',
                    'value' => $actualValue, 'source' => $actual['source'],
                    'detail' => $actual['basis'],
                ],
                [
                    'key' => 'backlog', 'label' => 'Confirmed orders not yet invoiced',
                    'value' => round($backlog['value'], 2), 'source' => 'sales',
                    'detail' => $backlog['count'] . ' order' . ($backlog['count'] === 1 ? '' : 's')
                        . ' due on or before ' . $to . ', counted only for the part not already billed.',
                ],
                [
                    'key' => 'pipeline', 'label' => 'Open quotations, weighted',
                    'value' => round($pipelineExpected, 2), 'source' => 'sales',
                    'detail' => $pipeline['count'] . ' quotation' . ($pipeline['count'] === 1 ? '' : 's')
                        . ' closing in this period, at '
                        . ($appliedConversion === null ? 'no measured conversion rate' : self::pc($appliedConversion) . ' conversion')
                        . ' and ' . self::pc($appliedDiscount ?? 0) . ' average discount.',
                ],
            ],
            'projected' => $available ? [
                'mid'  => round($mid, 2),
                'low'  => round($actualValue + $backlog['value'] + $lowPipeline, 2),
                'high' => round($actualValue + $backlog['value'] + $highPipeline, 2),
            ] : null,
            'range_basis' => 'A scenario band, not a confidence interval: the same arithmetic with the conversion '
                . 'assumption moved ' . (int) (self::BAND * 100) . '% either way. Only the pipeline component moves.',
            'assumptions' => [
                [
                    'key' => 'conversion_pc',
                    'label' => 'Quotation conversion',
                    'value' => $appliedConversion,
                    'measured' => $baselineConversion['rate_pc'],
                    'source' => $scenarioConversion !== null ? 'scenario' : $baselineConversion['source'],
                    'detail' => $baselineConversion['detail'],
                ],
                [
                    'key' => 'discount_pc',
                    'label' => 'Average discount',
                    'value' => $appliedDiscount,
                    'measured' => $pipeline['average_discount_pc'],
                    'source' => $scenarioDiscount !== null ? 'scenario' : 'measured',
                    'detail' => 'The discount already carried by the open quotations in this period.',
                ],
            ],
            'scenario' => [
                'applied'       => $usingScenario,
                'conversion_pc' => $appliedConversion,
                'discount_pc'   => $appliedDiscount,
                'note'          => 'Planning only — does not change records.',
            ],
            'target' => $target + [
                'attainment_pc' => ($target['configured'] && $target['value'] > 0 && $actualValue !== null)
                    ? round($actualValue / $target['value'] * 100, 1)
                    : null,
                'projected_attainment_pc' => ($target['configured'] && $target['value'] > 0 && $mid !== null)
                    ? round($mid / $target['value'] * 100, 1)
                    : null,
                'gap' => ($target['configured'] && $mid !== null) ? round($target['value'] - $mid, 2) : null,
            ],
        ];
    }

    /**
     * The chart line, built from the same numbers as the KPI above it.
     *
     * The actual series is whatever the caller measured; the projection is a
     * straight line from the last actual point to the projected mid, with the
     * band around it. It has to END on `projected.mid` or the chart and the card
     * are telling the reader two different things, and they will believe the
     * chart.
     *
     * @param list<array{date:string, cumulative:float}> $actualSeries
     * @param array<string, mixed> $projection
     * @return list<array<string, mixed>>
     */
    public function series(array $actualSeries, array $projection, string $to): array
    {
        $points = [];
        foreach ($actualSeries as $point) {
            $points[] = [
                'date'      => $point['date'],
                'actual'    => round((float) $point['cumulative'], 2),
                'projected' => null,
                'low'       => null,
                'high'      => null,
            ];
        }

        if (!$projection['available'] || $projection['projected'] === null) {
            return $points;
        }

        $last = end($points) ?: null;
        $startValue = $last === null ? 0.0 : (float) $last['actual'];
        $startDate = $last === null ? $projection['as_of'] : $last['date'];

        $begin = new \DateTimeImmutable($startDate);
        $end = new \DateTimeImmutable($to);
        $days = max(1, (int) $begin->diff($end)->format('%a'));

        // The join point carries both lines so the chart has no visible gap
        // between what happened and what is expected.
        if ($last !== null) {
            $points[count($points) - 1]['projected'] = $startValue;
            $points[count($points) - 1]['low'] = $startValue;
            $points[count($points) - 1]['high'] = $startValue;
        }

        $targets = [
            'projected' => (float) $projection['projected']['mid'],
            'low'       => (float) $projection['projected']['low'],
            'high'      => (float) $projection['projected']['high'],
        ];

        for ($day = 1; $day <= $days; $day++) {
            $fraction = $day / $days;
            $date = $begin->modify('+' . $day . ' day');
            $points[] = [
                'date'      => $date->format('Y-m-d'),
                'actual'    => null,
                'projected' => round($startValue + ($targets['projected'] - $startValue) * $fraction, 2),
                'low'       => round($startValue + ($targets['low'] - $startValue) * $fraction, 2),
                'high'      => round($startValue + ($targets['high'] - $startValue) * $fraction, 2),
            ];
        }

        return $points;
    }

    // -----------------------------------------------------------------------

    /**
     * Uninvoiced value of orders promised on or before the period end.
     *
     * `ordered - invoiced` per line, not the order total: an order half billed
     * has already contributed its first half to the actual.
     *
     * @return array{value:float, count:int}
     */
    private function committedBacklog(string $to): array
    {
        [$scope, $params] = $this->ctx->scopeClause('o');
        $params['to'] = $to;

        $row = Db::first(
            'SELECT COALESCE(SUM(GREATEST(l.ordered_qty - l.invoiced_qty, 0) * l.rate), 0) AS backlog_value,
                    COUNT(DISTINCT o.order_id) AS order_count
               FROM sales_order_lines l
               JOIN sales_orders o ON o.order_id = l.order_id
              WHERE ' . $scope . ' AND ' . MetricsService::orderCommitted() . '
                AND l.ordered_qty > l.invoiced_qty
                AND (o.committed_date IS NULL OR o.committed_date <= :to::date)',
            $params,
        ) ?? [];

        return ['value' => (float) ($row['backlog_value'] ?? 0), 'count' => (int) ($row['order_count'] ?? 0)];
    }

    /**
     * Open quotations that can still be decided inside the period.
     *
     * @return array{gross:float, net:float, count:int, average_discount_pc:float|null}
     */
    private function eligiblePipeline(string $asOf, string $to): array
    {
        [$scope, $params] = $this->ctx->scopeClause('q');
        $params += ['as_of' => $asOf, 'to' => $to];

        $row = Db::first(
            'SELECT COALESCE(SUM(q.subtotal_amount), 0) AS gross,
                    COALESCE(SUM(q.total_amount), 0)    AS net,
                    COUNT(*)                            AS quotation_count,
                    CASE WHEN COALESCE(SUM(q.subtotal_amount), 0) > 0
                         THEN ROUND((SUM(q.discount_amount) / SUM(q.subtotal_amount)) * 100, 2)
                         ELSE NULL END                  AS average_discount_pc
               FROM sales_quotations q
              WHERE ' . $scope . ' AND ' . MetricsService::quotationOpen() . '
                AND (q.valid_until IS NULL OR q.valid_until <= :to::date)',
            $params,
        ) ?? [];

        return [
            'gross'               => (float) ($row['gross'] ?? 0),
            'net'                 => (float) ($row['net'] ?? 0),
            'count'               => (int) ($row['quotation_count'] ?? 0),
            'average_discount_pc' => $row['average_discount_pc'] === null ? null : (float) $row['average_discount_pc'],
        ];
    }

    /**
     * The conversion rate to weight the pipeline with.
     *
     * The period's own rate if anything has been decided in it; otherwise the
     * financial year to date, which is a larger and steadier sample. If nothing
     * has ever been decided there is no rate, and the caller must not substitute
     * one — an invented conversion rate is an invented forecast.
     *
     * @param array{available:bool, rate_pc:float|null, decided:int} $periodConversion
     * @return array{rate_pc:float|null, source:string, detail:string}
     */
    private function baselineConversion(array $periodConversion): array
    {
        if ($periodConversion['available'] && $periodConversion['decided'] >= 5) {
            return [
                'rate_pc' => $periodConversion['rate_pc'],
                'source'  => 'measured_period',
                'detail'  => 'Measured over the ' . $periodConversion['decided'] . ' quotations decided in this period.',
            ];
        }

        // Too few decisions this period for the rate to mean anything. Widen to
        // the financial year rather than reporting a rate built on two documents.
        [$scope, $params] = $this->ctx->scopeClause('q');

        $row = Db::first(
            "SELECT COUNT(*) FILTER (WHERE q.status IN ('ACCEPTED', 'CONVERTED')) AS accepted,
                    COUNT(*) FILTER (WHERE q.status IN ('ACCEPTED', 'CONVERTED', 'REJECTED')) AS decided
               FROM sales_quotations q
              WHERE {$scope} AND " . MetricsService::latestRevision(),
            $params,
        ) ?? [];

        $decided = (int) ($row['decided'] ?? 0);
        if ($decided === 0) {
            return [
                'rate_pc' => null,
                'source'  => 'unavailable',
                'detail'  => 'No quotation has been accepted or declined yet, so there is no conversion rate to apply.',
            ];
        }

        return [
            'rate_pc' => round((int) ($row['accepted'] ?? 0) / $decided * 100, 1),
            'source'  => 'measured_year',
            'detail'  => 'Measured over the ' . $decided . ' quotations decided so far this financial year — '
                . 'this period alone has too few decisions to be meaningful.',
        ];
    }

    private static function clampPc(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return max(0.0, min(100.0, round((float) $value, 2)));
    }

    private static function pc(?float $value): string
    {
        return $value === null ? '—' : rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.') . '%';
    }
}
