<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Clients\InventoryClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\FinancialPositionService;
use Aicountly\Api\Domain\ForecastService;
use Aicountly\Api\Domain\InsightService;
use Aicountly\Api\Domain\MetricsService;
use Aicountly\Api\Http;
use Aicountly\Api\IntegrationCommand;
use Aicountly\Api\Permissions;

/**
 * The five Sales dashboards.
 *
 * One endpoint each, and each one loads ONLY what its own view draws. A single
 * fat /dashboard that answered every question would make the Overview wait for
 * the receivables ageing it does not show, which is how a dashboard ends up
 * with a spinner people learn to scroll past.
 *
 * TWO SOURCES, KEPT APART, on every one of them:
 *
 *   OURS    quotations, orders, commitments, approvals, conversion — questions
 *           only this product can answer, from its own tables
 *   THEIRS  invoiced revenue and what is owed (Books), availability and
 *           reservations (Inventory) — fetched live, on this request
 *
 * A remote failure degrades the cards that needed it and nothing else. Those
 * cards come back with `status: "unavailable"` and a reason, never a zero:
 * "Books did not answer" and "no sales this month" are different facts, and a
 * zero that means the first is a lie somebody will act on.
 *
 * KPI AND DRILLDOWN ARE THE SAME QUESTION. Every metric carries a `drilldown`
 * naming the route and the exact filters that reproduce it, and both the card
 * and the list are built from MetricsService — so the number on the card and
 * the number of rows behind it cannot drift apart.
 */
final class DashboardController extends Controller
{
    // -----------------------------------------------------------------------
    // 1. Overview — revenue, commitments and what to do first
    // -----------------------------------------------------------------------

    public static function overview(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'quotation.view');

        [$from, $to, $asOf] = self::period();
        $metrics = new MetricsService($ctx);
        $currency = $metrics->reportingCurrency();

        $pipeline = $metrics->openPipeline($asOf);
        $orders = $metrics->orderCommitment($from, $to, $asOf);
        $approvals = $metrics->pendingApprovalValue();

        // --- Books, live. Failure degrades these two cards only. ------------
        $maySeeFinancials = Permissions::allows($ctx, $auth, 'reports.view');
        $books = self::financials($ctx, $auth);
        $invoiced = $maySeeFinancials
            ? $books->netInvoicedSales($from, $to)
            : self::forbiddenFigure('Net invoiced sales is part of Sales reporting.');
        $ageing = $maySeeFinancials
            ? $books->ageing($asOf)
            : self::forbiddenFigure('Receivables are part of Sales reporting.');

        $target = $metrics->companyTarget($from, $to);

        // The chart follows the KPI: invoiced when Books can supply a dated
        // series, our own committed orders when it cannot — and it says which,
        // because the two are not the same measure and pretending otherwise is
        // how a chart ends up disagreeing with the card above it.
        $invoicedSeries = $maySeeFinancials ? $books->dailyInvoicedSeries($from, $to, $asOf) : ['status' => 'forbidden'];
        $usingInvoiced = ($invoicedSeries['status'] ?? '') === 'ready';
        $series = $usingInvoiced ? $invoicedSeries['series'] : $metrics->dailyOrderTrend($from, $to, $asOf);

        $achieved = $usingInvoiced
            ? ($invoiced['status'] === 'ready' ? $invoiced['value'] : null)
            : $orders['confirmed_value'];

        Http::data([
            'view'     => 'overview',
            'period'   => ['from' => $from, 'to' => $to, 'as_of' => $asOf],
            'currency' => $currency,
            'metrics'  => [
                self::metric('net_invoiced_sales', 'Net invoiced sales', $invoiced['status'], $invoiced['value'] ?? null, [
                    'unit'       => 'currency',
                    'definition' => $invoiced['basis'],
                    'source'     => 'books',
                    'reason'     => $invoiced['reason'] ?? null,
                    'comparison' => $invoiced['status'] === 'ready' && ($invoiced['invoice_count'] ?? null) !== null
                        ? $invoiced['invoice_count'] . ' invoices in the period'
                        : null,
                ]),
                self::metric('confirmed_orders', 'Confirmed orders', 'ready', $orders['confirmed_value'], [
                    'unit'       => 'currency',
                    'definition' => 'Value of orders confirmed with the customer, dated in the period. '
                        . 'A commitment, not revenue — Books recognises revenue when the invoice is posted.',
                    'source'     => 'sales',
                    'comparison' => $orders['confirmed_count'] . ' orders',
                    'drilldown'  => ['route' => 'orders', 'params' => ['from' => $from, 'to' => $to, 'committed' => 1]],
                    'warning'    => $orders['currency_mixed'] ? 'More than one currency is in use; totals are not summed across currencies.' : null,
                ]),
                self::metric('open_quotations', 'Open quotations', 'ready', $pipeline['value'], [
                    'unit'       => 'currency',
                    'definition' => 'Latest revision of every quotation still open and inside its validity, as at '
                        . $asOf . '. Superseded revisions and lapsed quotations are excluded.',
                    'source'     => 'sales',
                    'comparison' => $pipeline['count'] . ' open · ' . $pipeline['awaiting_response'] . ' awaiting a reply',
                    'drilldown'  => ['route' => 'quotations', 'params' => ['open' => 1]],
                ]),
                self::metric('overdue_receivables', 'Overdue receivables', $ageing['status'], $ageing['overdue'] ?? null, [
                    'unit'       => 'currency',
                    'definition' => 'Customer bills past their due date at ' . $asOf . ', from Smart Books.',
                    'source'     => 'books',
                    'reason'     => $ageing['reason'] ?? null,
                    // Rising overdue is never good news, whatever the arrow does.
                    'tone'       => ($ageing['overdue'] ?? 0) > 0 ? 'negative' : 'neutral',
                    'comparison' => ($ageing['status'] ?? '') === 'ready' && $ageing['oldest_days']
                        ? 'oldest ' . $ageing['oldest_days'] . ' days past due'
                        : null,
                    'drilldown'  => ['route' => 'collections', 'params' => []],
                ]),
            ],
            'chart' => [
                'measure'     => $usingInvoiced ? 'invoiced' : 'orders',
                'measure_label' => $usingInvoiced ? 'Invoiced sales' : 'Confirmed order value',
                'basis'       => $usingInvoiced
                    ? 'Cumulative posted sales vouchers from Smart Books, to ' . $asOf . '.'
                    : 'Cumulative confirmed order value from Sales, to ' . $asOf . '. '
                        . 'Smart Books could not supply a dated invoice series, so this chart shows what Sales committed rather than what was billed.',
                'series'      => $series,
                'target'      => $target,
                'achieved'    => $achieved,
                'as_of'       => $asOf,
                'period_end'  => $to,
            ],
            'priorities'  => self::priorities($metrics, $pipeline, $orders, $approvals, $asOf, $currency),
            'attention'   => self::attentionRows($ctx, $auth, $metrics, $asOf),
            'insights'    => (new InsightService($ctx, $metrics))->overview($asOf, ['currency' => $currency]),
            'freshness'   => self::freshness([
                'Quotations, orders and commitments' => 'Sales',
                'Invoiced sales and receivables'     => 'Smart Books, live',
            ]),
        ]);
    }

    // -----------------------------------------------------------------------
    // 2. Pipeline & Quotations
    // -----------------------------------------------------------------------

    public static function pipeline(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'quotation.view');

        [$from, $to, $asOf] = self::period();
        $metrics = new MetricsService($ctx);
        $currency = $metrics->reportingCurrency();

        $open = $metrics->openPipeline($asOf);
        $conversion = $metrics->quoteConversion($from, $to, $asOf);

        // The board asks for its lanes with their own bound. Fetching every
        // quotation to group them in the browser is how a board stops working
        // at exactly the point the business starts succeeding.
        $laneLimit = max(1, min(25, Http::intParam('lane_limit', 6) ?? 6));
        $stages = Http::param('stages') ?? 'DRAFT,SENT,NEGOTIATION';
        $lanes = [];
        foreach (array_filter(array_map('trim', explode(',', $stages))) as $stage) {
            $cards = $metrics->pipelineCards($stage, $asOf, $laneLimit, 0);
            $lanes[] = [
                'stage' => $stage,
                'label' => ucfirst(strtolower(str_replace('_', ' ', $stage))),
                'total' => $cards['total'],
                'cards' => $cards['rows'],
            ];
        }

        Http::data([
            'view'     => 'pipeline',
            'period'   => ['from' => $from, 'to' => $to, 'as_of' => $asOf],
            'currency' => $currency,
            'metrics'  => [
                self::metric('open_quotations', 'Open quotations', 'ready', $open['value'], [
                    'unit'       => 'currency',
                    'definition' => 'Latest revision, still open, inside validity, as at ' . $asOf . '.',
                    'source'     => 'sales',
                    'comparison' => $open['count'] . ' quotations',
                    'drilldown'  => ['route' => 'quotations', 'params' => ['open' => 1]],
                ]),
                self::metric('awaiting_response', 'Awaiting response', 'ready', $open['awaiting_response'], [
                    'unit'       => 'count',
                    'definition' => 'Sent to the customer and not yet answered.',
                    'source'     => 'sales',
                    'drilldown'  => ['route' => 'quotations', 'params' => ['status' => 'SENT']],
                ]),
                self::metric('expiring_7d', 'Expiring in 7 days', 'ready', $open['expiring_soon'], [
                    'unit'       => 'count',
                    'definition' => 'Open quotations whose validity ends within seven days of ' . $asOf . '.',
                    'source'     => 'sales',
                    'tone'       => $open['expiring_soon'] > 0 ? 'negative' : 'neutral',
                    'comparison' => $open['expiring_soon'] > 0 ? 'worth ' . self::plain($open['expiring_value']) : null,
                    'drilldown'  => ['route' => 'quotations', 'params' => ['expiring_days' => 7]],
                ]),
                self::metric(
                    'quote_conversion',
                    'Quote conversion',
                    $conversion['available'] ? 'ready' : 'unavailable',
                    $conversion['rate_pc'],
                    [
                        'unit'       => 'percent',
                        'definition' => $conversion['basis'],
                        'source'     => 'sales',
                        'reason'     => $conversion['available'] ? null : 'No quotation raised in this period has been decided yet.',
                        'comparison' => $conversion['available']
                            ? $conversion['accepted'] . ' of ' . $conversion['decided'] . ' decided'
                            : null,
                    ],
                ),
            ],
            'lanes'          => $lanes,
            'lane_stages'    => $metrics->pipelineLanes($asOf),
            'needing_action' => $metrics->quotationsNeedingAction($asOf, 8),
            'insights'       => (new InsightService($ctx, $metrics))->followUpPriorities($asOf, 3),
            'freshness'      => self::freshness(['Quotations and conversion' => 'Sales']),
        ]);
    }

    /** One lane, paged — what the board asks for when a lane is scrolled or filtered. */
    public static function pipelineLane(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'quotation.view');

        [, , $asOf] = self::period();
        $params = Http::listParams(['valid_until'], 'valid_until');
        $stage = strtoupper((string) (Http::param('stage') ?? 'DRAFT'));

        $result = (new MetricsService($ctx))->pipelineCards($stage, $asOf, $params['limit'], $params['offset']);

        Http::list($result['rows'], $result['total'], $params['limit'], $params['offset'], ['stage' => $stage]);
    }

    // -----------------------------------------------------------------------
    // 3. Orders & Fulfilment
    // -----------------------------------------------------------------------

    public static function fulfilment(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'order.view');

        [$from, $to, $asOf] = self::period();
        $metrics = new MetricsService($ctx);
        $currency = $metrics->reportingCurrency();

        $orders = $metrics->orderCommitment($from, $to, $asOf);
        $onTime = $metrics->onTimeDelivery($from, $to);

        $params = Http::listParams(['committed_date'], 'committed_date', 'asc');
        $queue = $metrics->fulfilmentQueue($asOf, min(25, $params['limit']), $params['offset'], Http::param('risk'));

        // One batched call to Inventory for the rows actually on screen. Asking
        // per row would be a cross-service N+1: twenty-five round trips, each
        // parking a PHP worker at both ends.
        $availability = self::liveAvailability($ctx, $auth, $queue['rows']);

        Http::data([
            'view'     => 'fulfilment',
            'period'   => ['from' => $from, 'to' => $to, 'as_of' => $asOf],
            'currency' => $currency,
            'metrics'  => [
                self::metric('open_orders', 'Open orders', 'ready', $orders['open_count'], [
                    'unit'       => 'count',
                    'definition' => 'Orders confirmed with a customer and not yet fully delivered, as at ' . $asOf . '.',
                    'source'     => 'sales',
                    'drilldown'  => ['route' => 'orders', 'params' => ['open_only' => 1]],
                ]),
                self::metric('ready_for_dispatch', 'Ready for dispatch', 'ready', $orders['ready_count'], [
                    'unit'       => 'count',
                    'definition' => 'Open orders with stock reserved or already part-delivered.',
                    'source'     => 'sales',
                    'drilldown'  => ['route' => 'orders', 'params' => ['status' => 'RESERVED']],
                ]),
                self::metric('at_risk', 'At-risk deliveries', 'ready', $orders['at_risk_count'], [
                    'unit'       => 'count',
                    'definition' => 'Open orders whose promise date has already passed.',
                    'source'     => 'sales',
                    'tone'       => $orders['at_risk_count'] > 0 ? 'negative' : 'neutral',
                    'drilldown'  => ['route' => 'fulfilment', 'params' => ['risk' => 'late']],
                ]),
                self::metric(
                    'on_time_delivery',
                    'On-time delivery',
                    $onTime['available'] ? 'ready' : 'unavailable',
                    $onTime['rate_pc'],
                    [
                        'unit'       => 'percent',
                        'definition' => $onTime['basis'],
                        'source'     => 'sales',
                        'reason'     => $onTime['available'] ? null : 'No order with a promise date has completed in this period.',
                        'comparison' => $onTime['available']
                            ? $onTime['on_time'] . ' of ' . $onTime['measured'] . ' measured'
                                . ($onTime['without_promise'] > 0 ? ' · ' . $onTime['without_promise'] . ' had no promise date' : '')
                            : null,
                    ],
                ),
            ],
            'stages'      => $metrics->orderStages(),
            'queue'       => [
                'rows'   => $queue['rows'],
                'total'  => $queue['total'],
                'limit'  => min(25, $params['limit']),
                'offset' => $params['offset'],
            ],
            'availability' => $availability,
            'commitments'  => $metrics->upcomingCommitments($asOf, 14, 8),
            'insights'     => (new InsightService($ctx, $metrics))
                ->fulfilmentSuggestions($asOf, $queue['rows'], $availability['by_order'], 2),
            'freshness'    => self::freshness([
                'Orders and delivery progress' => 'Sales',
                'Availability and reservations' => 'Inventory, live',
            ]),
        ]);
    }

    // -----------------------------------------------------------------------
    // 4. Customers & Collections
    // -----------------------------------------------------------------------

    public static function collections(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'order.view');

        [$from, $to, $asOf] = self::period();
        $metrics = new MetricsService($ctx);
        $currency = $metrics->reportingCurrency();

        $buyers = $metrics->buyerActivity($from, $to);
        $maySeeFinancials = Permissions::allows($ctx, $auth, 'reports.view');

        $books = self::financials($ctx, $auth);
        $ageing = $maySeeFinancials ? $books->ageing($asOf) : self::forbiddenFigure('Receivables are part of Sales reporting.');
        $priorities = $maySeeFinancials
            ? $books->collectionPriorities($asOf, 10)
            : ['status' => 'forbidden', 'reason' => 'Receivables are part of Sales reporting.', 'rows' => [], 'source' => 'books'];

        // Our own side of the conversation, merged onto Books' balances. The
        // balance is theirs; who rang the customer last is ours, and neither
        // half is copied into the other's database.
        $contacts = $metrics->lastContacts(array_column($priorities['rows'], 'customer_account_id'));
        $rows = array_map(static function (array $row) use ($contacts): array {
            $contact = $contacts[$row['customer_account_id']] ?? null;

            return $row + [
                'last_contact_on'  => $contact['contacted_on'] ?? null,
                'last_contact_via' => $contact['channel'] ?? null,
                'last_contact_note' => $contact['note'] ?? null,
                'promised_amount'  => $contact['promised_amount'] ?? null,
                'promised_on'      => $contact['promised_on'] ?? null,
                'promise_outcome'  => $contact['outcome'] ?? null,
            ];
        }, $priorities['rows']);

        $reorder = $metrics->reorderCandidates($asOf, 10);
        $declining = $metrics->decliningCustomers($from, $to, 10);

        Http::data([
            'view'     => 'collections',
            'period'   => ['from' => $from, 'to' => $to, 'as_of' => $asOf],
            'currency' => $currency,
            'metrics'  => [
                self::metric('active_buyers', 'Active buyers', 'ready', $buyers['active'], [
                    'unit'       => 'count',
                    'definition' => 'Customers with at least one confirmed order dated in the period.',
                    'source'     => 'sales',
                    'drilldown'  => ['route' => 'orders', 'params' => ['from' => $from, 'to' => $to, 'committed' => 1]],
                ]),
                self::metric(
                    'repeat_rate',
                    'Repeat purchase rate',
                    $buyers['available'] ? 'ready' : 'unavailable',
                    $buyers['repeat_rate_pc'],
                    [
                        'unit'       => 'percent',
                        'definition' => $buyers['basis'],
                        'source'     => 'sales',
                        'reason'     => $buyers['available'] ? null : 'Nobody has ordered in this period yet.',
                        'comparison' => $buyers['available'] ? $buyers['repeat'] . ' of ' . $buyers['active'] . ' bought before' : null,
                    ],
                ),
                self::metric('outstanding', 'Outstanding', $ageing['status'], $ageing['total'] ?? null, [
                    'unit'       => 'currency',
                    'definition' => 'Everything customers owe at ' . $asOf . ', whenever it was invoiced — not just this period.',
                    'source'     => 'books',
                    'reason'     => $ageing['reason'] ?? null,
                    'comparison' => ($ageing['status'] ?? '') === 'ready' ? $ageing['bill_count'] . ' open bills' : null,
                ]),
                self::metric('overdue', 'Overdue', $ageing['status'], $ageing['overdue'] ?? null, [
                    'unit'       => 'currency',
                    'definition' => 'The part of the outstanding that is past its due date at ' . $asOf . '.',
                    'source'     => 'books',
                    'reason'     => $ageing['reason'] ?? null,
                    'tone'       => ($ageing['overdue'] ?? 0) > 0 ? 'negative' : 'neutral',
                ]),
            ],
            'ageing'        => $ageing,
            'priorities'    => ['status' => $priorities['status'], 'reason' => $priorities['reason'] ?? null, 'rows' => $rows],
            'opportunities' => [
                'reorder_due' => array_values(array_filter($reorder, static fn (array $r) => !empty($r['sufficient_history']))),
                'declining'   => $declining,
                'top_customers' => $metrics->topCustomers($from, $to, 5),
            ],
            'insights'  => (new InsightService($ctx, $metrics))->customerSuggestions($asOf, $reorder, $declining, $currency),
            'freshness' => self::freshness([
                'Buyers, orders and follow-ups' => 'Sales',
                'Balances and ageing'           => 'Smart Books, live',
            ]),
        ]);
    }

    // -----------------------------------------------------------------------
    // 5. Performance & Forecast
    // -----------------------------------------------------------------------

    public static function forecast(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'reports.view');

        [$from, $to, $asOf] = self::period();
        $metrics = new MetricsService($ctx);
        $currency = $metrics->reportingCurrency();
        $books = self::financials($ctx, $auth);

        // One measure runs the whole screen — the KPI, the chart and the
        // forecast. Mixing "invoiced" into the card and "ordered" into the line
        // beneath it is how a dashboard ends up contradicting itself.
        $measure = Http::param('measure') === 'orders' ? 'orders' : 'invoiced';
        $invoiced = $books->netInvoicedSales($from, $to);
        $invoicedSeries = $books->dailyInvoicedSeries($from, $to, $asOf);

        if ($measure === 'invoiced' && ($invoiced['status'] !== 'ready' || $invoicedSeries['status'] !== 'ready')) {
            // Do not silently substitute a different measure. Report that the
            // requested one is unavailable and let the user pick the other.
            $measure = 'orders_fallback';
        }

        $orderTotals = $metrics->orderCommitment($from, $to, $asOf);
        $usingOrders = $measure !== 'invoiced';

        $actual = $usingOrders
            ? [
                'status' => 'ready',
                'value'  => $orderTotals['confirmed_value'],
                'reason' => null,
                'basis'  => 'Confirmed order value dated in the period, from Sales. This is committed business, '
                    . 'not recognised revenue.',
                'source' => 'sales',
            ]
            : $invoiced;

        $actualSeries = $usingOrders
            ? $metrics->dailyOrderTrend($from, $to, $asOf)
            : $invoicedSeries['series'];

        $projection = (new ForecastService($ctx, $metrics))->project($from, $to, $asOf, $actual, [
            'conversion_pc' => Http::param('conversion_pc'),
            'discount_pc'   => Http::param('discount_pc'),
        ]);

        $series = (new ForecastService($ctx, $metrics))->series($actualSeries, $projection, $to);
        $target = $projection['target'];
        $team = $metrics->teamPerformance($from, $to);

        Http::data([
            'view'     => 'forecast',
            'period'   => ['from' => $from, 'to' => $to, 'as_of' => $asOf],
            'currency' => $currency,
            'measure'  => [
                'key'      => $usingOrders ? 'orders' : 'invoiced',
                'label'    => $usingOrders ? 'Confirmed order value' : 'Net invoiced sales',
                'basis'    => $actual['basis'],
                'fallback' => $measure === 'orders_fallback',
                'fallback_reason' => $measure === 'orders_fallback'
                    ? ($invoiced['reason'] ?? $invoicedSeries['reason'] ?? 'Smart Books could not supply invoiced sales for this period.')
                    : null,
            ],
            'metrics' => [
                self::metric(
                    'period_target',
                    'Period target',
                    $target['configured'] ? 'ready' : 'unavailable',
                    $target['value'],
                    [
                        'unit'       => 'currency',
                        'definition' => 'The value target configured for this company and period.',
                        'source'     => 'sales',
                        'reason'     => $target['configured'] ? null : 'Target not configured.',
                        'drilldown'  => ['route' => 'targets', 'params' => []],
                    ],
                ),
                self::metric('actual_to_date', 'Actual to date', $actual['status'], $actual['value'] ?? null, [
                    'unit'       => 'currency',
                    'definition' => $actual['basis'],
                    'source'     => $actual['source'],
                    'reason'     => $actual['reason'] ?? null,
                ]),
                self::metric(
                    'projected',
                    'Projected period-end',
                    $projection['available'] ? 'ready' : 'unavailable',
                    $projection['available'] ? $projection['projected']['mid'] : null,
                    [
                        'unit'       => 'currency',
                        'definition' => 'Recognised so far, plus confirmed orders still to be billed, plus open '
                            . 'quotations weighted at the measured conversion rate. ' . $projection['label'] . '.',
                        'source'     => 'sales',
                        'reason'     => $projection['reason'],
                        'range'      => $projection['projected'],
                    ],
                ),
                self::metric(
                    'attainment',
                    'Target attainment',
                    $target['configured'] && $target['attainment_pc'] !== null ? 'ready' : 'unavailable',
                    $target['attainment_pc'],
                    [
                        'unit'       => 'percent',
                        'definition' => 'Actual to date over the configured target for the period.',
                        'source'     => 'sales',
                        'reason'     => $target['configured'] ? null : 'Target not configured.',
                        'progress'   => $target['attainment_pc'],
                    ],
                ),
            ],
            'chart' => [
                'series'     => $series,
                'target'     => $target,
                'as_of'      => $asOf,
                'period_end' => $to,
                'basis'      => $projection['range_basis'],
            ],
            'forecast' => $projection,
            'team'     => $team,
            'insights' => (new InsightService($ctx, $metrics))->forecastExplanation($asOf, $projection, $currency),
            'freshness' => self::freshness([
                'Orders, targets and the projection' => 'Sales',
                'Invoiced sales'                     => 'Smart Books, live',
            ]),
        ]);
    }

    // -----------------------------------------------------------------------
    // Approvals inbox and the unfinished-command list (unchanged contracts)
    // -----------------------------------------------------------------------

    public static function approvals(): void
    {
        [$auth, $ctx] = self::enter();

        $status = Http::param('status') ?? 'PENDING';
        $params = Http::listParams(['created_at'], 'created_at');

        $rows = Db::all(
            'SELECT a.*,
                    q.quotation_no, q.customer_name_snapshot AS quotation_customer, q.total_amount AS quotation_total,
                    q.currency_code AS quotation_currency,
                    o.order_no,     o.customer_name_snapshot AS order_customer,     o.total_amount AS order_total,
                    o.currency_code AS order_currency
             FROM sales_approval_requests a
             LEFT JOIN sales_quotations q ON a.entity_type = \'quotation\' AND q.quotation_id = a.entity_id
             LEFT JOIN sales_orders     o ON a.entity_type = \'order\'     AND o.order_id     = a.entity_id
             WHERE a.cmp_id = :cmp AND a.status = :status
             ORDER BY a.created_at DESC
             LIMIT ' . $params['limit'] . ' OFFSET ' . $params['offset'],
            ['cmp' => $ctx->cmpId, 'status' => $status],
        );

        $total = (int) Db::scalar(
            'SELECT COUNT(*) FROM sales_approval_requests WHERE cmp_id = :cmp AND status = :status',
            ['cmp' => $ctx->cmpId, 'status' => $status],
        );

        Http::list($rows, $total, $params['limit'], $params['offset']);
    }

    /**
     * Cross-service work that has not finished.
     *
     * This is the screen that replaces a reconciliation job. Everything stuck is
     * shown here, on purpose, with its error and a Retry — rather than being
     * swept up by a cron at 2am that nobody reads the output of.
     */
    public static function commands(): void
    {
        [$auth, $ctx] = self::enter();
        Http::data(IntegrationCommand::outstanding($ctx, Http::intParam('limit', 100) ?? 100));
    }

    // =======================================================================
    // Shared helpers
    // =======================================================================

    /**
     * The period every dashboard runs on.
     *
     * `as_of` is separate from `to` on purpose. A user looking at September on
     * the 16th wants actuals to the 16th and the target for the whole month, and
     * a cumulative line drawn to the 30th would flatten after today and read as
     * a collapse in sales.
     *
     * @return array{0:string, 1:string, 2:string}
     */
    private static function period(): array
    {
        $from = self::dateParam('from') ?? gmdate('Y-m-01');
        $to = self::dateParam('to') ?? gmdate('Y-m-t', strtotime($from));
        $today = gmdate('Y-m-d');
        $asOf = self::dateParam('as_of') ?? ($today < $to ? $today : $to);

        if ($from > $to) {
            Http::validationFailed('The start of the period is after its end.', ['field' => 'from']);
        }
        // An as-of outside the period makes every figure on the screen ambiguous.
        $asOf = max($from, min($asOf, $to));

        return [$from, $to, $asOf];
    }

    private static function dateParam(string $name): ?string
    {
        $raw = Http::param($name);
        if ($raw === null || $raw === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            Http::validationFailed('Dates must be written as YYYY-MM-DD.', ['field' => $name]);
        }

        return $raw;
    }

    private static function financials(Context $ctx, \Aicountly\Api\Auth $auth): FinancialPositionService
    {
        return new FinancialPositionService($ctx, (new BooksClient())->withSession($auth->sesKey()));
    }

    /**
     * One KPI card, in the shape every dashboard uses.
     *
     * `definition` is not decoration. A card that says "₹48.6L" and nothing else
     * gets read as whatever the reader already believed; one that says which
     * dates, which statuses and whose number it is can be checked.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function metric(string $id, string $label, string $status, mixed $value, array $options = []): array
    {
        return [
            'id'         => $id,
            'label'      => $label,
            'status'     => $status,
            'value'      => $value,
            'unit'       => $options['unit'] ?? 'count',
            'tone'       => $options['tone'] ?? 'neutral',
            'definition' => $options['definition'] ?? null,
            'comparison' => $options['comparison'] ?? null,
            'source'     => $options['source'] ?? 'sales',
            'reason'     => $options['reason'] ?? null,
            'warning'    => $options['warning'] ?? null,
            'range'      => $options['range'] ?? null,
            'progress'   => $options['progress'] ?? null,
            // A route name and filters, resolved by the UI against its own route
            // table. Never a URL from the server, so nothing here can send a
            // browser somewhere the app did not intend.
            'drilldown'  => $options['drilldown'] ?? null,
        ];
    }

    /** @return array{status:string, value:null, reason:string, basis:string, source:string} */
    private static function forbiddenFigure(string $what): array
    {
        return [
            'status' => 'forbidden',
            'value'  => null,
            'reason' => $what . ' You do not have permission to view sales reports.',
            'basis'  => $what,
            'source' => 'books',
            'total'  => null,
            'overdue' => null,
            'buckets' => [],
            'oldest_days' => null,
            'bill_count' => null,
            'invoice_count' => null,
        ];
    }

    /**
     * Today's priorities — only the ones that are actually true right now.
     *
     * An empty list is a valid and useful answer. A panel that always shows
     * three items invents one when there is nothing to do, and after a week
     * nobody reads it.
     *
     * @param array<string, mixed> $pipeline
     * @param array<string, mixed> $orders
     * @param array{count:int, value:float} $approvals
     * @return list<array<string, mixed>>
     */
    private static function priorities(
        MetricsService $metrics,
        array $pipeline,
        array $orders,
        array $approvals,
        string $asOf,
        string $currency,
    ): array {
        $out = [];

        if ($pipeline['expiring_soon'] > 0) {
            $out[] = [
                'key'    => 'expiring',
                'icon'   => 'quotation',
                'title'  => $pipeline['expiring_soon'] . ' quotation' . ($pipeline['expiring_soon'] === 1 ? '' : 's')
                    . ' expire' . ($pipeline['expiring_soon'] === 1 ? 's' : '') . ' this week',
                'detail' => 'Potential value ' . self::plain($pipeline['expiring_value']),
                'action' => ['kind' => InsightService::ACTION_REVIEW_QUOTATIONS, 'label' => 'Review quotations'],
                'count'  => $pipeline['expiring_soon'],
                'value'  => $pipeline['expiring_value'],
            ];
        }

        $awaitingStock = $metrics->awaitingStockConfirmation();
        if ($awaitingStock > 0) {
            $out[] = [
                'key'    => 'awaiting-stock',
                'icon'   => 'order',
                'title'  => $awaitingStock . ' order' . ($awaitingStock === 1 ? '' : 's') . ' need stock confirmation',
                'detail' => 'Reservation requested from Inventory and not yet confirmed',
                'action' => ['kind' => InsightService::ACTION_REVIEW_ORDERS, 'label' => 'Review orders'],
                'count'  => $awaitingStock,
                'value'  => null,
            ];
        }

        if ($approvals['count'] > 0) {
            $out[] = [
                'key'    => 'approvals',
                'icon'   => 'approval',
                'title'  => $approvals['count'] . ' approval' . ($approvals['count'] === 1 ? '' : 's') . ' pending',
                'detail' => 'Total value ' . self::plain($approvals['value']),
                'action' => ['kind' => InsightService::ACTION_REVIEW_APPROVALS, 'label' => 'Review approvals'],
                'count'  => $approvals['count'],
                'value'  => $approvals['value'],
            ];
        }

        if ($orders['at_risk_count'] > 0) {
            $out[] = [
                'key'    => 'delivery-risk',
                'icon'   => 'risk',
                'title'  => $orders['at_risk_count'] . ' delivery promise' . ($orders['at_risk_count'] === 1 ? '' : 's') . ' already passed',
                'detail' => 'Promise dates in the past on orders that are still open',
                'action' => ['kind' => InsightService::ACTION_REVIEW_ORDERS, 'label' => 'Review orders'],
                'count'  => $orders['at_risk_count'],
                'value'  => null,
            ];
        }

        return $out;
    }

    /**
     * Orders that need somebody today, with the reason each one is here.
     *
     * @return array{rows:list<array<string, mixed>>, inventory:array<string, mixed>}
     */
    private static function attentionRows(Context $ctx, \Aicountly\Api\Auth $auth, MetricsService $metrics, string $asOf): array
    {
        $queue = $metrics->fulfilmentQueue($asOf, 6, 0, null);
        $availability = self::liveAvailability($ctx, $auth, $queue['rows']);

        $rows = [];
        foreach ($queue['rows'] as $order) {
            $live = $availability['by_order'][(int) $order['order_id']] ?? null;
            $issue = self::issueFor($order, $live);
            if ($issue === null) {
                continue;
            }
            $rows[] = $order + ['issue' => $issue];
        }

        return ['rows' => $rows, 'inventory' => ['status' => $availability['status'], 'reason' => $availability['reason']]];
    }

    /**
     * Why this order is on the attention list. Most serious reason wins.
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed>|null $live
     * @return array{kind:string, label:string, tone:string}|null
     */
    private static function issueFor(array $order, ?array $live): ?array
    {
        if ((int) $order['stuck_commands'] > 0) {
            return ['kind' => 'stuck', 'label' => 'Cross-app request failed', 'tone' => 'danger'];
        }
        if ($live !== null && ($live['status'] ?? '') === 'ready' && (int) ($live['short_lines'] ?? 0) > 0) {
            return ['kind' => 'shortfall', 'label' => 'Stock shortfall', 'tone' => 'danger'];
        }
        if ($order['days_to_promise'] !== null && (int) $order['days_to_promise'] < 0) {
            return ['kind' => 'late', 'label' => 'Promise date passed', 'tone' => 'danger'];
        }
        if ($order['days_to_promise'] !== null && (int) $order['days_to_promise'] <= 1) {
            return ['kind' => 'due', 'label' => 'Delivery due', 'tone' => 'warning'];
        }
        if ($order['status'] === 'RESERVATION_PENDING') {
            return ['kind' => 'reservation', 'label' => 'Awaiting stock confirmation', 'tone' => 'warning'];
        }

        return null;
    }

    /**
     * Live availability for the orders on screen, in ONE call to Inventory.
     *
     * Unavailable means unavailable. An order whose stock could not be checked
     * is reported as such and never as "In stock" — a fulfilment screen that
     * guesses is worse than one that admits it does not know, because somebody
     * will promise a delivery on the strength of it.
     *
     * @param list<array<string, mixed>> $orders
     * @return array{status:string, reason:string|null, read_at:string|null, by_order:array<int, array<string, mixed>>}
     */
    private static function liveAvailability(Context $ctx, \Aicountly\Api\Auth $auth, array $orders): array
    {
        if ($orders === []) {
            return ['status' => 'ready', 'reason' => null, 'read_at' => gmdate('c'), 'by_order' => []];
        }

        $orderIds = array_map(static fn (array $o) => (int) $o['order_id'], $orders);
        $placeholders = implode(',', array_map(static fn (int $i) => ':o' . $i, array_keys($orderIds)));
        $params = [];
        foreach ($orderIds as $index => $id) {
            $params['o' . $index] = $id;
        }

        $lines = Db::all(
            "SELECT l.line_id, l.order_id, l.item_id, l.warehouse_id, l.batch_id, l.unit_id,
                    GREATEST(l.ordered_qty - l.delivered_qty, 0) AS outstanding_qty
               FROM sales_order_lines l
              WHERE l.order_id IN ({$placeholders}) AND l.is_service = FALSE AND l.item_id IS NOT NULL
                AND l.ordered_qty > l.delivered_qty",
            $params,
        );

        // A service-only order has nothing to check and must not be held up by a
        // stock question that does not apply to it.
        if ($lines === []) {
            $byOrder = [];
            foreach ($orderIds as $id) {
                $byOrder[$id] = ['status' => 'not_applicable', 'reason' => 'No stock lines outstanding on this order.'];
            }

            return ['status' => 'ready', 'reason' => null, 'read_at' => gmdate('c'), 'by_order' => $byOrder];
        }

        $response = (new InventoryClient())->withSession($auth->sesKey())->checkAvailability($ctx, array_map(
            static fn (array $l) => [
                'item_id'      => (int) $l['item_id'],
                'warehouse_id' => $l['warehouse_id'] === null ? null : (int) $l['warehouse_id'],
                'batch_id'     => $l['batch_id'] === null ? null : (int) $l['batch_id'],
                'unit_id'      => $l['unit_id'] === null ? null : (int) $l['unit_id'],
                'qty'          => (float) $l['outstanding_qty'],
            ],
            $lines,
        ));

        if (!$response['ok']) {
            $reason = ($response['status'] ?? 0) === 0
                ? 'Inventory could not be reached, so availability is unknown for these orders.'
                : 'Inventory did not answer in time (HTTP ' . ($response['status'] ?? 0) . ').';

            $byOrder = [];
            foreach ($orderIds as $id) {
                $byOrder[$id] = ['status' => 'unavailable', 'reason' => $reason];
            }

            return ['status' => 'unavailable', 'reason' => $reason, 'read_at' => null, 'by_order' => $byOrder];
        }

        $answers = (array) ($response['body']['data'] ?? []);
        $readAt = gmdate('c');
        $byOrder = [];

        foreach ($lines as $index => $line) {
            $orderId = (int) $line['order_id'];
            $answer = $answers[$index] ?? null;
            $byOrder[$orderId] ??= [
                'status' => 'ready', 'reason' => null, 'read_at' => $readAt,
                'full_lines' => 0, 'partial_lines' => 0, 'short_lines' => 0,
                'requested_qty' => 0.0, 'available_qty' => 0.0, 'lines' => [],
            ];

            $requested = (float) $line['outstanding_qty'];
            $available = $answer === null ? 0.0 : (float) ($answer['available'] ?? 0);
            $ok = $answer !== null && ($answer['ok'] ?? ($available >= $requested));

            $byOrder[$orderId]['requested_qty'] += $requested;
            $byOrder[$orderId]['available_qty'] += min($available, $requested);
            if ($ok) {
                $byOrder[$orderId]['full_lines']++;
            } elseif ($available > 0) {
                $byOrder[$orderId]['partial_lines']++;
                $byOrder[$orderId]['short_lines']++;
            } else {
                $byOrder[$orderId]['short_lines']++;
            }

            $byOrder[$orderId]['lines'][] = [
                'line_id'       => (int) $line['line_id'],
                'item_id'       => (int) $line['item_id'],
                'requested_qty' => $requested,
                'available_qty' => $available,
                'shortfall_qty' => max(0.0, $requested - $available),
            ];
        }

        foreach ($orderIds as $id) {
            $byOrder[$id] ??= ['status' => 'not_applicable', 'reason' => 'No stock lines outstanding on this order.'];
        }

        return ['status' => 'ready', 'reason' => null, 'read_at' => $readAt, 'by_order' => $byOrder];
    }

    /** @param array<string, string> $sources */
    private static function freshness(array $sources): array
    {
        return [
            'generated_at' => gmdate('c'),
            'sources'      => array_map(
                static fn (string $owner, string $what) => ['what' => $what, 'owner' => $owner],
                array_values($sources),
                array_keys($sources),
            ),
        ];
    }

    /** A bare number for a sentence the browser will not re-format. */
    private static function plain(float $value): string
    {
        if (abs($value) >= 10000000) {
            return '₹' . number_format($value / 10000000, 1) . 'Cr';
        }
        if (abs($value) >= 100000) {
            return '₹' . number_format($value / 100000, 1) . 'L';
        }

        return '₹' . number_format($value, 0);
    }
}
