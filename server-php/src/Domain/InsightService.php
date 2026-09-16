<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Context;
use Aicountly\Api\Env;

/**
 * The suggestions on the dashboards, and the rules that produce them.
 *
 * WHAT DECIDES: code in this file. Every threshold, every count, every ranking
 * is arithmetic over records the user can open. Nothing here asks a language
 * model what to recommend, because a recommendation nobody can check is not a
 * recommendation — it is a guess with good grammar.
 *
 * WHAT AI MAY DO, when a provider is configured for this company in
 * console.aicountly.org: rewrite the `reason` of a suggestion this class has
 * already produced, in the company's own language. It never invents the
 * suggestion, never supplies a number, never chooses the action, and never sees
 * a provider key from the browser — the call is server-side or it does not
 * happen. With no provider configured, every suggestion here still works and is
 * labelled "Rule-based insight", which is what it has been all along.
 *
 * EVERY SUGGESTION CARRIES ITS EVIDENCE: the records it was derived from, as
 * ids the UI turns into links. A suggestion whose evidence list is empty is not
 * returned at all. That rule is what stops this becoming a horoscope.
 *
 * ACTIONS ARE FROM A FIXED LIST. `action.kind` is one of the constants below and
 * is resolved by the UI against its own route table. No URL, no SQL and no
 * command ever crosses this boundary, so nothing that reaches a browser from
 * here can be made to navigate somewhere it should not.
 */
final class InsightService
{
    public const ACTION_REVIEW_QUOTATIONS = 'review_quotations';
    public const ACTION_REVIEW_ORDERS     = 'review_orders';
    public const ACTION_REVIEW_APPROVALS  = 'review_approvals';
    public const ACTION_REVIEW_FOLLOWUPS  = 'review_followups';
    public const ACTION_DRAFT_FOLLOWUP    = 'draft_followup';
    public const ACTION_DRAFT_REMINDER    = 'draft_reminder';
    public const ACTION_DRAFT_QUOTATION   = 'draft_quotation';
    public const ACTION_REVIEW_ORDER      = 'review_order';
    public const ACTION_REVIEW_CUSTOMER   = 'review_customer';
    public const ACTION_REVIEW_FORECAST   = 'review_forecast';
    public const ACTION_RETRY_COMMANDS    = 'retry_commands';

    private const ALLOWED_ACTIONS = [
        self::ACTION_REVIEW_QUOTATIONS, self::ACTION_REVIEW_ORDERS, self::ACTION_REVIEW_APPROVALS,
        self::ACTION_REVIEW_FOLLOWUPS, self::ACTION_DRAFT_FOLLOWUP, self::ACTION_DRAFT_REMINDER,
        self::ACTION_DRAFT_QUOTATION, self::ACTION_REVIEW_ORDER, self::ACTION_REVIEW_CUSTOMER,
        self::ACTION_REVIEW_FORECAST, self::ACTION_RETRY_COMMANDS,
    ];

    public function __construct(
        private readonly Context $ctx,
        private readonly MetricsService $metrics,
    ) {
    }

    /**
     * Is an AI provider configured for this deployment?
     *
     * Governance lives in console.aicountly.org; this only reports what the
     * server was given. The browser is never told a key exists, only whether
     * explanations can be rephrased.
     */
    public static function aiConfigured(): bool
    {
        return Env::get('AI_PROVIDER_BASE') !== '' && Env::get('AI_PROVIDER_KEY') !== '';
    }

    /**
     * The Overview suggestion: what is worth doing first this morning.
     *
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    public function overview(string $asOf, array $context): array
    {
        $insights = [];

        // 1. Quotations about to lapse, ranked by value. The clock is the only
        //    thing on this dashboard that cannot be recovered once it runs out.
        $expiring = $this->metrics->quotationsNeedingAction($asOf, 25);
        $lapsingSoon = array_values(array_filter(
            $expiring,
            static fn (array $q) => $q['days_to_expiry'] !== null && (int) $q['days_to_expiry'] <= 7,
        ));

        if ($lapsingSoon !== []) {
            $value = array_sum(array_map(static fn (array $q) => (float) $q['total_amount'], $lapsingSoon));
            $quiet = array_values(array_filter(
                $lapsingSoon,
                static fn (array $q) => $q['days_since_contact'] === null || (int) $q['days_since_contact'] >= 5,
            ));

            $insights[] = $this->build(
                'expiring-quotations',
                count($quiet) > 0
                    ? 'Chase the expiring quotations nobody has followed up'
                    : 'Quotations are about to lapse',
                sprintf(
                    '%d quotation%s worth %s %s validity within 7 days%s.',
                    count($lapsingSoon),
                    count($lapsingSoon) === 1 ? '' : 's',
                    self::amount($value, $context['currency'] ?? 'INR'),
                    count($lapsingSoon) === 1 ? 'loses' : 'lose',
                    count($quiet) > 0
                        ? sprintf('; %d of them have had no contact for five days or more', count($quiet))
                        : '',
                ),
                self::ACTION_REVIEW_QUOTATIONS,
                'Review quotations',
                array_map(static fn (array $q) => [
                    'kind'  => 'quotation',
                    'id'    => (int) $q['quotation_id'],
                    'label' => (string) $q['quotation_no'],
                    'note'  => (int) $q['days_to_expiry'] <= 0
                        ? 'validity ends today'
                        : (int) $q['days_to_expiry'] . ' days left',
                ], array_slice($lapsingSoon, 0, 6)),
                $asOf,
                ['days_to_expiry' => 7, 'quiet_days' => 5],
            );
        }

        // 2. Orders whose promise date has passed. A missed promise the customer
        //    finds out about before we do costs more than the order.
        $late = array_values(array_filter(
            $this->metrics->fulfilmentQueue($asOf, 25, 0, 'late')['rows'],
            static fn (array $o) => $o['days_to_promise'] !== null,
        ));

        if ($late !== []) {
            $insights[] = $this->build(
                'late-promises',
                'Promise dates have passed on open orders',
                sprintf(
                    '%d open order%s past the date we committed to, the oldest by %d days.',
                    count($late),
                    count($late) === 1 ? '' : 's',
                    abs((int) $late[0]['days_to_promise']),
                ),
                self::ACTION_REVIEW_ORDERS,
                'Review orders',
                array_map(static fn (array $o) => [
                    'kind'  => 'order',
                    'id'    => (int) $o['order_id'],
                    'label' => (string) $o['order_no'],
                    'note'  => abs((int) $o['days_to_promise']) . ' days late',
                ], array_slice($late, 0, 6)),
                $asOf,
            );
        }

        // 3. Cross-service work that did not finish. There is no cron sweeping
        //    these up, by design, so the dashboard is where they surface.
        $stuck = $this->metrics->stuckCommands();
        if ($stuck['failed'] + $stuck['blocked'] > 0) {
            $insights[] = $this->build(
                'stuck-commands',
                'Requests to Books or Inventory have not completed',
                sprintf(
                    '%d request%s could not be delivered%s. Nothing is retried behind your back, so these wait for a person.',
                    $stuck['failed'] + $stuck['blocked'],
                    $stuck['failed'] + $stuck['blocked'] === 1 ? '' : 's',
                    $stuck['blocked'] > 0 ? sprintf(', %d of them refused outright', $stuck['blocked']) : '',
                ),
                self::ACTION_RETRY_COMMANDS,
                'Open the affected orders',
                [['kind' => 'commands', 'id' => 0, 'label' => 'Unfinished requests', 'note' => 'on their own orders']],
                $asOf,
            );
        }

        return $insights;
    }

    /**
     * Pipeline follow-up priorities, ranked by what is actually at stake.
     *
     * Each one carries its OWN reason and its OWN action — a board where every
     * card says "Draft follow-up" teaches people to stop reading the cards.
     *
     * @return list<array<string, mixed>>
     */
    public function followUpPriorities(string $asOf, int $limit = 3): array
    {
        $candidates = $this->metrics->quotationsNeedingAction($asOf, 40);
        $scored = [];

        foreach ($candidates as $quotation) {
            $days = $quotation['days_to_expiry'] === null ? null : (int) $quotation['days_to_expiry'];
            $silent = $quotation['days_since_contact'] === null ? null : (int) $quotation['days_since_contact'];
            $value = (float) $quotation['total_amount'];

            // Pending approval is OUR delay, not the customer's, and is the only
            // one of these a follow-up email would be the wrong answer to.
            if (!empty($quotation['approval_pending'])) {
                $scored[] = [
                    'score'  => 900 + min(99, $value / 10000),
                    'key'    => 'approval-' . $quotation['quotation_id'],
                    'title'  => 'Revised price awaiting approval',
                    'reason' => 'The customer is waiting on us, not the other way round: this quotation has been '
                        . 'sitting in the approval queue and cannot be sent until somebody decides.',
                    'action' => self::ACTION_REVIEW_APPROVALS,
                    'label'  => 'Review approval',
                    'quotation' => $quotation,
                ];
                continue;
            }

            if ($days !== null && $days <= 3) {
                $scored[] = [
                    'score'  => 800 + (3 - $days) * 10 + min(99, $value / 10000),
                    'key'    => 'expiry-' . $quotation['quotation_id'],
                    'title'  => $days <= 0 ? 'Quote validity ends today' : 'Quote expires in ' . $days . ' days',
                    'reason' => 'Validity runs out on ' . $quotation['valid_until'] . '. After that the price has to be '
                        . 're-quoted, and a re-quote is a fresh decision for the customer rather than a reminder.',
                    'action' => self::ACTION_DRAFT_FOLLOWUP,
                    'label'  => 'Draft follow-up',
                    'quotation' => $quotation,
                ];
                continue;
            }

            if ($silent !== null && $silent >= 7 && $quotation['stored_status'] === 'SENT') {
                $scored[] = [
                    'score'  => 600 + min(99, $silent) + min(99, $value / 10000),
                    'key'    => 'silence-' . $quotation['quotation_id'],
                    'title'  => 'No response for ' . $silent . ' days',
                    'reason' => 'Sent on ' . substr((string) ($quotation['sent_at'] ?? ''), 0, 10)
                        . ' with nothing back since. A quotation this quiet is usually waiting on a question '
                        . 'nobody asked.',
                    'action' => self::ACTION_DRAFT_FOLLOWUP,
                    'label'  => 'Draft follow-up',
                    'quotation' => $quotation,
                ];
            }
        }

        usort($scored, static fn (array $a, array $b) => $b['score'] <=> $a['score']);

        // One per reason. Three cards all reading "Quote validity ends today"
        // is a panel people stop reading by Wednesday — and it hides the
        // approval sitting in our own queue behind two copies of the same
        // sentence. Show the worst of each kind, then the next kind down.
        $seen = [];
        $distinct = [];
        foreach ($scored as $entry) {
            $kind = explode('-', $entry['key'], 2)[0];
            if (isset($seen[$kind])) {
                continue;
            }
            $seen[$kind] = true;
            $distinct[] = $entry;
        }
        // Only if there genuinely are not enough distinct reasons do we fall
        // back to a second card of the same kind.
        foreach ($scored as $entry) {
            if (count($distinct) >= $limit) {
                break;
            }
            if (!in_array($entry, $distinct, true)) {
                $distinct[] = $entry;
            }
        }
        $scored = $distinct;

        $out = [];
        foreach (array_slice($scored, 0, $limit) as $entry) {
            $quotation = $entry['quotation'];
            $out[] = $this->build(
                $entry['key'],
                $entry['title'],
                $entry['reason'],
                $entry['action'],
                $entry['label'],
                [[
                    'kind'  => 'quotation',
                    'id'    => (int) $quotation['quotation_id'],
                    'label' => (string) $quotation['quotation_no'],
                    'note'  => (string) ($quotation['customer_name_snapshot'] ?? 'Account ' . $quotation['customer_account_id']),
                ]],
                $asOf,
            );
        }

        return $out;
    }

    /**
     * Fulfilment suggestion: where a partial delivery would keep a promise.
     *
     * It needs the LIVE availability the controller has just fetched, because a
     * partial-delivery suggestion built on a stored stock figure is advice to
     * ship goods that may not be there. With no live figure, no suggestion.
     *
     * @param list<array<string, mixed>> $queue rows from MetricsService::fulfilmentQueue
     * @param array<int, array<string, mixed>> $availability keyed by order_id
     * @return list<array<string, mixed>>
     */
    public function fulfilmentSuggestions(string $asOf, array $queue, array $availability, int $limit = 2): array
    {
        $out = [];

        foreach ($queue as $order) {
            if (count($out) >= $limit) {
                break;
            }
            $live = $availability[(int) $order['order_id']] ?? null;
            if ($live === null || ($live['status'] ?? '') !== 'ready') {
                continue;
            }

            $partial = (int) ($live['partial_lines'] ?? 0);
            $short = (int) ($live['short_lines'] ?? 0);
            $daysToPromise = $order['days_to_promise'] === null ? null : (int) $order['days_to_promise'];

            if ($partial > 0 && $short > 0 && $daysToPromise !== null && $daysToPromise <= 7) {
                $out[] = $this->build(
                    'partial-delivery-' . $order['order_id'],
                    'A partial delivery could protect the ' . $order['committed_date'] . ' commitment',
                    sprintf(
                        'Inventory can cover %d of %d stock line%s on %s right now, and the promise date is %s. '
                        . 'Dispatching what is available and back-ordering the rest keeps the date for most of the order. '
                        . 'Sales does not move stock — this opens the dispatch screen for you to decide.',
                        $partial,
                        $partial + $short,
                        ($partial + $short) === 1 ? '' : 's',
                        $order['order_no'],
                        $daysToPromise <= 0 ? 'already past' : 'in ' . $daysToPromise . ' days',
                    ),
                    self::ACTION_REVIEW_ORDER,
                    'Review dispatch options',
                    [[
                        'kind'  => 'order',
                        'id'    => (int) $order['order_id'],
                        'label' => (string) $order['order_no'],
                        'note'  => (string) ($order['customer_name_snapshot'] ?? ''),
                    ]],
                    $asOf,
                    ['availability_read_at' => $live['read_at'] ?? null],
                );
            }
        }

        return $out;
    }

    /**
     * Reorder and risk suggestions for the customers dashboard.
     *
     * A reorder suggestion requires THREE orders and a measurable rhythm. Two
     * orders make a line, not a pattern, and telling a salesperson a customer is
     * "due" on the strength of one gap is how they learn to ignore the panel.
     *
     * @param list<array<string, mixed>> $reorder
     * @param list<array<string, mixed>> $declining
     * @return list<array<string, mixed>>
     */
    public function customerSuggestions(string $asOf, array $reorder, array $declining, string $currency): array
    {
        $out = [];

        foreach ($reorder as $candidate) {
            if (empty($candidate['sufficient_history'])) {
                continue;
            }
            $min = (int) $candidate['min_gap_days'];
            $max = (int) $candidate['max_gap_days'];
            $since = (int) $candidate['days_since_last_order'];

            $out[] = $this->build(
                'reorder-' . $candidate['customer_account_id'],
                ($candidate['customer_name'] ?: 'Account ' . $candidate['customer_account_id']) . ' may be ready to reorder',
                sprintf(
                    'Their last %d orders were %s apart. The most recent was %d days ago, which is at or past that rhythm.',
                    (int) $candidate['order_count'],
                    $min === $max ? $min . ' days' : $min . '–' . $max . ' days',
                    $since,
                ),
                self::ACTION_DRAFT_QUOTATION,
                'Draft quotation',
                [[
                    'kind'  => 'customer',
                    'id'    => (int) $candidate['customer_account_id'],
                    'label' => (string) ($candidate['customer_name'] ?: 'Account ' . $candidate['customer_account_id']),
                    'note'  => 'last order ' . $candidate['last_order_date'],
                ]],
                $asOf,
                ['orders_considered' => (int) $candidate['order_count'], 'average_gap_days' => (int) $candidate['avg_gap_days']],
            );
            break;
        }

        foreach ($declining as $candidate) {
            $out[] = $this->build(
                'declining-' . $candidate['customer_account_id'],
                ($candidate['customer_name'] ?: 'Account ' . $candidate['customer_account_id']) . ' is buying less',
                sprintf(
                    'Ordered %s in this period against %s in the equivalent window before it — down %s.',
                    self::amount((float) $candidate['current_value'], $currency),
                    self::amount((float) $candidate['prior_value'], $currency),
                    $candidate['change_pc'] === null ? 'sharply' : abs((float) $candidate['change_pc']) . '%',
                ),
                self::ACTION_REVIEW_CUSTOMER,
                'Review history',
                [[
                    'kind'  => 'customer',
                    'id'    => (int) $candidate['customer_account_id'],
                    'label' => (string) ($candidate['customer_name'] ?: 'Account ' . $candidate['customer_account_id']),
                    'note'  => (int) $candidate['current_orders'] . ' orders this period',
                ]],
                $asOf,
            );
            break;
        }

        return $out;
    }

    /**
     * What the forecast actually rests on.
     *
     * Explains the arithmetic the ForecastService did; it does not produce a
     * number of its own, and there is nothing here for a model to invent.
     *
     * @param array<string, mixed> $projection
     * @return list<array<string, mixed>>
     */
    public function forecastExplanation(string $asOf, array $projection, string $currency): array
    {
        if (!$projection['available']) {
            return [];
        }

        $components = [];
        foreach ($projection['components'] as $component) {
            $components[$component['key']] = $component;
        }

        $pipeline = (float) ($components['pipeline']['value'] ?? 0);
        $mid = (float) ($projection['projected']['mid'] ?? 0);
        if ($mid <= 0) {
            return [];
        }

        $share = round($pipeline / $mid * 100);
        $target = $projection['target'];

        $title = $share >= 25
            ? sprintf('%d%% of the projection depends on quotations not yet won', $share)
            : 'Most of the projection is already committed';

        $reason = sprintf(
            '%s is already recognised, %s sits in confirmed orders still to be billed, and %s is open pipeline '
            . 'weighted at the conversion rate this company actually achieves. Only the last part can still go either way.',
            self::amount((float) ($components['actual']['value'] ?? 0), $currency),
            self::amount((float) ($components['backlog']['value'] ?? 0), $currency),
            self::amount($pipeline, $currency),
        );

        if ($target['configured'] && $target['gap'] !== null && $target['gap'] > 0) {
            $reason .= sprintf(' At this rate the period ends %s short of target.', self::amount((float) $target['gap'], $currency));
        }

        return [$this->build(
            'forecast-composition',
            $title,
            $reason,
            self::ACTION_REVIEW_QUOTATIONS,
            'View contributing quotations',
            [[
                'kind'  => 'forecast',
                'id'    => 0,
                'label' => 'Rule-based estimate',
                'note'  => $projection['days_remaining'] . ' days left in the period',
            ]],
            $asOf,
            ['conversion_pc' => $projection['scenario']['conversion_pc'], 'method' => $projection['method']],
        )];
    }

    // -----------------------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $evidence
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    private function build(
        string $id,
        string $title,
        string $reason,
        string $action,
        string $actionLabel,
        array $evidence,
        string $asOf,
        array $rule = [],
    ): array {
        // A suggestion with no records behind it is an opinion. Refuse to make one.
        if ($evidence === []) {
            throw new \LogicException('An insight must cite the records it came from.');
        }
        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            throw new \LogicException('Unknown insight action: ' . $action);
        }

        return [
            'id'         => $id,
            'origin'     => 'rule',
            'title'      => $title,
            'reason'     => $reason,
            'action'     => ['kind' => $action, 'label' => $actionLabel],
            'evidence'   => $evidence,
            'as_of'      => $asOf,
            'rule'       => $rule,
            'ai_enabled' => self::aiConfigured(),
        ];
    }

    private static function amount(float $value, string $currency): string
    {
        $symbol = $currency === 'INR' ? '₹' : $currency . ' ';

        if ($currency === 'INR' && abs($value) >= 100000) {
            return $symbol . number_format($value / 100000, 1) . 'L';
        }

        return $symbol . number_format($value, 0);
    }
}
