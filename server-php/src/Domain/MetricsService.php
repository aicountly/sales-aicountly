<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Context;
use Aicountly\Api\Db;

/**
 * Every number the dashboards show, defined once.
 *
 * WHY THIS CLASS EXISTS. A dashboard KPI and the list you reach by clicking it
 * have to be the same question asked twice. When the card counts one thing and
 * the drilldown filters another, the user finds out by counting rows, and from
 * then on believes neither. So the predicate lives here, in one place, and both
 * the aggregate and the drilldown are built from it.
 *
 * THE DEFINITIONS, stated rather than implied:
 *
 *   Open quotation value    ours, AS-OF. Latest revision only, status still
 *                           live, validity not passed. A superseded revision is
 *                           not an open offer and must never be added to one.
 *   Confirmed order value   ours, PERIOD by order_date. Everything from
 *                           confirmation onward; drafts and cancellations out.
 *   Net invoiced sales      BOOKS', PERIOD by voucher date. Never computed here.
 *   Outstanding / overdue   BOOKS', AS-OF the cutoff. Never computed here.
 *   Quote conversion        ours, PERIOD. accepted+converted over DECIDED, and
 *                           "no decisions yet" is unavailable, not 0%.
 *
 * EXPIRY IS DERIVED. `valid_until` in the past makes a quotation expired at read
 * time — there is no nightly job to set a flag, because a job that fails on a
 * Sunday leaves dead quotations counted as pipeline on Monday and nothing says
 * so. The one stored column, `expired_at`, records a person closing one off.
 */
final class MetricsService
{
    /** Quotation statuses that are still a live offer, before validity is applied. */
    public const QUOTATION_LIVE = ['DRAFT', 'APPROVAL_PENDING', 'APPROVED', 'SENT'];

    /** Quotation statuses where the customer (or time) has answered. */
    public const QUOTATION_DECIDED = ['ACCEPTED', 'CONVERTED', 'REJECTED'];

    /** Order statuses that represent a commitment we have made to a customer. */
    public const ORDER_COMMITTED = [
        'CONFIRMED', 'RESERVATION_PENDING', 'RESERVED', 'PARTIALLY_FULFILLED', 'FULFILLED', 'CLOSED',
    ];

    /** Order statuses still needing work before the promise is kept. */
    public const ORDER_OPEN = ['CONFIRMED', 'RESERVATION_PENDING', 'RESERVED', 'PARTIALLY_FULFILLED'];

    public function __construct(private readonly Context $ctx)
    {
    }

    // -----------------------------------------------------------------------
    // Reusable SQL fragments — the single definition of each cohort
    // -----------------------------------------------------------------------

    /**
     * "This row is the newest revision of its quotation."
     *
     * Revisions are separate rows pointing back at what they replace, so the
     * predicate is "nothing supersedes me" rather than a max() over the number.
     */
    public static function latestRevision(string $alias = 'q'): string
    {
        return "NOT EXISTS (SELECT 1 FROM sales_quotations sup WHERE sup.supersedes_id = {$alias}.quotation_id)";
    }

    /** "This quotation is still an offer the customer could accept today." */
    public static function quotationOpen(string $alias = 'q', string $asOfParam = ':as_of'): string
    {
        $live = "'" . implode("','", self::QUOTATION_LIVE) . "'";

        return "{$alias}.status IN ({$live})
                AND {$alias}.expired_at IS NULL
                AND ({$alias}.valid_until IS NULL OR {$alias}.valid_until >= {$asOfParam}::date)
                AND " . self::latestRevision($alias);
    }

    /** "Time ran out on this offer." Derived, never stored. */
    public static function quotationExpired(string $alias = 'q', string $asOfParam = ':as_of'): string
    {
        $live = "'" . implode("','", self::QUOTATION_LIVE) . "'";

        return "{$alias}.status IN ({$live})
                AND ({$alias}.expired_at IS NOT NULL
                     OR ({$alias}.valid_until IS NOT NULL AND {$alias}.valid_until < {$asOfParam}::date))
                AND " . self::latestRevision($alias);
    }

    /**
     * The status to SHOW, which is not always the status stored.
     *
     * Used by the lists as well as the dashboards so a quotation does not read
     * "Sent" on one screen and "Expired" on the next.
     */
    public static function effectiveStatusSql(string $alias = 'q', string $asOfParam = ':as_of'): string
    {
        $live = "'" . implode("','", self::QUOTATION_LIVE) . "'";

        return "CASE
                  WHEN {$alias}.status IN ({$live})
                   AND ({$alias}.expired_at IS NOT NULL
                        OR ({$alias}.valid_until IS NOT NULL AND {$alias}.valid_until < {$asOfParam}::date))
                  THEN 'EXPIRED'
                  ELSE {$alias}.status
                END";
    }

    public static function orderCommitted(string $alias = 'o'): string
    {
        return "{$alias}.status IN ('" . implode("','", self::ORDER_COMMITTED) . "')";
    }

    public static function orderOpen(string $alias = 'o'): string
    {
        return "{$alias}.status IN ('" . implode("','", self::ORDER_OPEN) . "')";
    }

    // -----------------------------------------------------------------------
    // Quotations
    // -----------------------------------------------------------------------

    /**
     * Open pipeline as at a date.
     *
     * @return array{value:float, count:int, currency_mixed:bool, expiring_soon:int,
     *               expiring_value:float, awaiting_response:int, expired_count:int, expired_value:float}
     */
    public function openPipeline(string $asOf, int $expiringDays = 7): array
    {
        [$scope, $params] = $this->ctx->scopeClause('q');
        $params['as_of'] = $asOf;
        $params['soon'] = (new \DateTimeImmutable($asOf))->modify('+' . $expiringDays . ' days')->format('Y-m-d');

        $row = Db::first(
            'SELECT
                COUNT(*) FILTER (WHERE ' . self::quotationOpen() . ')                      AS open_count,
                COALESCE(SUM(q.total_amount) FILTER (WHERE ' . self::quotationOpen() . '), 0) AS open_value,
                COUNT(*) FILTER (WHERE ' . self::quotationOpen() . " AND q.status = 'SENT') AS awaiting_response,
                COUNT(*) FILTER (WHERE " . self::quotationOpen() . ' AND q.valid_until IS NOT NULL AND q.valid_until <= :soon::date) AS expiring_soon,
                COALESCE(SUM(q.total_amount) FILTER (WHERE ' . self::quotationOpen() . ' AND q.valid_until IS NOT NULL AND q.valid_until <= :soon::date), 0) AS expiring_value,
                COUNT(*) FILTER (WHERE ' . self::quotationExpired() . ')                   AS expired_count,
                COALESCE(SUM(q.total_amount) FILTER (WHERE ' . self::quotationExpired() . '), 0) AS expired_value,
                COUNT(DISTINCT q.currency_code)                                            AS currencies
             FROM sales_quotations q
             WHERE ' . $scope,
            $params,
        ) ?? [];

        return [
            'value'             => (float) ($row['open_value'] ?? 0),
            'count'             => (int) ($row['open_count'] ?? 0),
            'awaiting_response' => (int) ($row['awaiting_response'] ?? 0),
            'expiring_soon'     => (int) ($row['expiring_soon'] ?? 0),
            'expiring_value'    => (float) ($row['expiring_value'] ?? 0),
            'expired_count'     => (int) ($row['expired_count'] ?? 0),
            'expired_value'     => (float) ($row['expired_value'] ?? 0),
            'currency_mixed'    => (int) ($row['currencies'] ?? 0) > 1,
        ];
    }

    /**
     * Quote conversion over a documented cohort.
     *
     * DENOMINATOR: quotations raised in the period, latest revision only, whose
     * outcome is known — accepted, converted, rejected, or lapsed unanswered.
     * A draft written this morning has not failed to convert and is not counted
     * against the rate.
     *
     * A denominator of zero returns available=false. "0%" would read as "we
     * convert nothing", which is a different and much worse statement than
     * "nothing has been decided yet".
     *
     * @return array{available:bool, rate_pc:float|null, accepted:int, decided:int,
     *               basis:string, accepted_value:float}
     */
    public function quoteConversion(string $from, string $to, string $asOf): array
    {
        [$scope, $params] = $this->ctx->scopeClause('q');
        $params += ['from' => $from, 'to' => $to, 'as_of' => $asOf];

        $decidedIn = "'" . implode("','", self::QUOTATION_DECIDED) . "'";

        $row = Db::first(
            'SELECT
                COUNT(*) FILTER (WHERE q.status IN (' . $decidedIn . '))                AS decided_explicit,
                COUNT(*) FILTER (WHERE ' . self::quotationExpired() . ')                AS lapsed,
                COUNT(*) FILTER (WHERE q.status IN (\'ACCEPTED\', \'CONVERTED\'))       AS accepted,
                COALESCE(SUM(q.total_amount) FILTER (WHERE q.status IN (\'ACCEPTED\', \'CONVERTED\')), 0) AS accepted_value
             FROM sales_quotations q
             WHERE ' . $scope . ' AND q.quotation_date BETWEEN :from AND :to AND ' . self::latestRevision(),
            $params,
        ) ?? [];

        $accepted = (int) ($row['accepted'] ?? 0);
        $decided = (int) ($row['decided_explicit'] ?? 0) + (int) ($row['lapsed'] ?? 0);

        return [
            'available'      => $decided > 0,
            'rate_pc'        => $decided > 0 ? round($accepted / $decided * 100, 1) : null,
            'accepted'       => $accepted,
            'decided'        => $decided,
            'accepted_value' => (float) ($row['accepted_value'] ?? 0),
            'basis'          => 'Accepted or converted, over quotations raised in the period whose outcome is known '
                . '(accepted, converted, declined, or lapsed past validity). Latest revision only.',
        ];
    }

    /**
     * Pipeline lanes for the Kanban board — counts and values, not rows.
     *
     * The board asks for its cards lane by lane with its own limit. Drawing a
     * board by fetching every quotation and grouping in the browser is how a
     * dashboard becomes unusable at the exact moment the business succeeds.
     *
     * @return list<array{stage:string, count:int, value:float}>
     */
    public function pipelineLanes(string $asOf): array
    {
        [$scope, $params] = $this->ctx->scopeClause('q');
        $params['as_of'] = $asOf;

        $rows = Db::all(
            'SELECT ' . self::effectiveStatusSql() . ' AS stage,
                    COUNT(*) AS card_count,
                    COALESCE(SUM(q.total_amount), 0) AS stage_value
             FROM sales_quotations q
             WHERE ' . $scope . ' AND ' . self::latestRevision() . "
               AND q.status <> 'CANCELLED'
             GROUP BY 1
             ORDER BY 1",
            $params,
        );

        return array_map(static fn (array $r) => [
            'stage' => (string) $r['stage'],
            'count' => (int) $r['card_count'],
            'value' => (float) $r['stage_value'],
        ], $rows);
    }

    /**
     * Cards for one lane, newest activity first, bounded.
     *
     * @return array{rows:list<array<string, mixed>>, total:int}
     */
    public function pipelineCards(string $stage, string $asOf, int $limit, int $offset): array
    {
        [$scope, $params] = $this->ctx->scopeClause('q');
        $params += ['as_of' => $asOf, 'stage' => $stage];

        $where = $scope . ' AND ' . self::latestRevision() . ' AND ' . self::effectiveStatusSql() . ' = :stage';

        $rows = Db::all(
            'SELECT q.quotation_id, q.quotation_no, q.revision_no, q.quotation_date, q.valid_until,
                    q.customer_account_id, q.customer_name_snapshot, q.total_amount, q.currency_code,
                    q.salesperson_id, q.sent_at, q.status AS stored_status,
                    ' . self::effectiveStatusSql() . ' AS status,
                    sp.display_code AS salesperson_code,
                    GREATEST(q.updated_at, q.created_at) AS last_change_at,
                    (SELECT MAX(f.contacted_on) FROM sales_followups f
                      WHERE f.cmp_id = q.cmp_id AND f.quotation_id = q.quotation_id) AS last_followup_on,
                    (:as_of::date - q.quotation_date) AS age_days,
                    CASE WHEN q.valid_until IS NULL THEN NULL
                         ELSE (q.valid_until - :as_of::date) END AS days_to_expiry
             FROM sales_quotations q
             LEFT JOIN sales_people sp ON sp.salesperson_id = q.salesperson_id
             WHERE ' . $where . '
             ORDER BY q.valid_until NULLS LAST, q.quotation_date DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params,
        );

        $total = (int) Db::scalar('SELECT COUNT(*) FROM sales_quotations q WHERE ' . $where, $params);

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Quotations with a reason to act on them today, most urgent first.
     *
     * @return list<array<string, mixed>>
     */
    public function quotationsNeedingAction(string $asOf, int $limit = 10): array
    {
        [$scope, $params] = $this->ctx->scopeClause('q');
        $params += ['as_of' => $asOf, 'limit' => $limit];

        return Db::all(
            'SELECT q.quotation_id, q.quotation_no, q.revision_no, q.customer_account_id,
                    q.customer_name_snapshot, q.total_amount, q.currency_code, q.valid_until,
                    q.quotation_date, q.sent_at, q.status AS stored_status,
                    ' . self::effectiveStatusSql() . ' AS status,
                    CASE WHEN q.valid_until IS NULL THEN NULL ELSE (q.valid_until - :as_of::date) END AS days_to_expiry,
                    COALESCE(
                        (:as_of::date - (SELECT MAX(f.contacted_on) FROM sales_followups f
                                          WHERE f.cmp_id = q.cmp_id AND f.quotation_id = q.quotation_id)),
                        (:as_of::date - q.sent_at::date)
                    ) AS days_since_contact,
                    EXISTS (SELECT 1 FROM sales_approval_requests a
                             WHERE a.cmp_id = q.cmp_id AND a.entity_type = \'quotation\'
                               AND a.entity_id = q.quotation_id AND a.status = \'PENDING\') AS approval_pending
             FROM sales_quotations q
             WHERE ' . $scope . ' AND ' . self::quotationOpen() . '
             ORDER BY
                q.valid_until NULLS LAST,
                q.total_amount DESC
             LIMIT :limit',
            $params,
        );
    }

    // -----------------------------------------------------------------------
    // Orders
    // -----------------------------------------------------------------------

    /**
     * Order commitment for a period, plus the open backlog as at today.
     *
     * @return array{confirmed_value:float, confirmed_count:int, open_count:int,
     *               ready_count:int, at_risk_count:int, undelivered_value:float,
     *               uninvoiced_value:float, currency_mixed:bool}
     */
    public function orderCommitment(string $from, string $to, string $asOf): array
    {
        [$scope, $params] = $this->ctx->scopeClause('o');
        $params += ['from' => $from, 'to' => $to, 'as_of' => $asOf];

        $period = Db::first(
            'SELECT COUNT(*) AS confirmed_count,
                    COALESCE(SUM(o.total_amount), 0) AS confirmed_value,
                    COUNT(DISTINCT o.currency_code) AS currencies
             FROM sales_orders o
             WHERE ' . $scope . ' AND o.order_date BETWEEN :from AND :to AND ' . self::orderCommitted(),
            $params,
        ) ?? [];

        // The backlog is AS-OF, not for the period: an order confirmed in March
        // and still undelivered in September is today's problem, and a period
        // filter would hide exactly the ones that have been open longest.
        [$openScope, $openParams] = $this->ctx->scopeClause('o');
        $openParams['as_of'] = $asOf;

        $open = Db::first(
            'SELECT
                COUNT(*) FILTER (WHERE ' . self::orderOpen() . ') AS open_count,
                COUNT(*) FILTER (WHERE ' . self::orderOpen() . " AND o.status IN ('RESERVED', 'PARTIALLY_FULFILLED')) AS ready_count,
                COUNT(*) FILTER (WHERE " . self::orderOpen() . ' AND o.committed_date IS NOT NULL AND o.committed_date < :as_of::date) AS at_risk_count
             FROM sales_orders o
             WHERE ' . $openScope,
            $openParams,
        ) ?? [];

        $backlog = Db::first(
            'SELECT
                COALESCE(SUM(GREATEST(l.ordered_qty - l.delivered_qty, 0) * l.rate), 0) AS undelivered_value,
                COALESCE(SUM(GREATEST(l.delivered_qty - l.invoiced_qty, 0) * l.rate), 0) AS uninvoiced_value
             FROM sales_order_lines l
             JOIN sales_orders o ON o.order_id = l.order_id
             WHERE ' . $openScope . ' AND ' . self::orderCommitted(),
            $openParams,
        ) ?? [];

        return [
            'confirmed_value'   => (float) ($period['confirmed_value'] ?? 0),
            'confirmed_count'   => (int) ($period['confirmed_count'] ?? 0),
            'open_count'        => (int) ($open['open_count'] ?? 0),
            'ready_count'       => (int) ($open['ready_count'] ?? 0),
            'at_risk_count'     => (int) ($open['at_risk_count'] ?? 0),
            'undelivered_value' => (float) ($backlog['undelivered_value'] ?? 0),
            'uninvoiced_value'  => (float) ($backlog['uninvoiced_value'] ?? 0),
            'currency_mixed'    => (int) ($period['currencies'] ?? 0) > 1,
        ];
    }

    /**
     * Where open orders stand right now.
     *
     * These are OVERLAPPING MILESTONES, not a funnel: an order that is reserved
     * is also confirmed, and one line can be dispatched while another waits. The
     * API says so in `basis` and the UI repeats it, because four descending
     * numbers on a dashboard are read as a conversion funnel unless something
     * stops the reader — and "34 of 86 dispatched" is a very different claim
     * from "34 orders dropped out between reserved and dispatched".
     *
     * @return array{basis:string, stages:list<array{key:string, label:string, count:int, description:string}>}
     */
    public function orderStages(): array
    {
        [$scope, $params] = $this->ctx->scopeClause('o');

        $row = Db::first(
            'SELECT
                COUNT(*) FILTER (WHERE ' . self::orderCommitted() . ') AS confirmed,
                COUNT(*) FILTER (WHERE EXISTS (
                    SELECT 1 FROM sales_order_lines l
                     WHERE l.order_id = o.order_id AND l.inventory_reservation_uuid IS NOT NULL)) AS reserved,
                COUNT(*) FILTER (WHERE EXISTS (
                    SELECT 1 FROM sales_order_lines l
                     WHERE l.order_id = o.order_id AND l.delivered_qty > 0)) AS dispatched,
                COUNT(*) FILTER (WHERE ' . self::orderCommitted() . ' AND NOT EXISTS (
                    SELECT 1 FROM sales_order_lines l
                     WHERE l.order_id = o.order_id AND l.delivered_qty < l.ordered_qty)) AS delivered
             FROM sales_orders o
             WHERE ' . $scope . ' AND ' . self::orderCommitted(),
            $params,
        ) ?? [];

        return [
            'basis'  => 'Milestones reached, counted independently. An order appears at every milestone '
                . 'it has passed, so these are not the stages of a funnel and do not subtract from one another.',
            'stages' => [
                ['key' => 'confirmed', 'label' => 'Confirmed', 'count' => (int) ($row['confirmed'] ?? 0),
                    'description' => 'Committed to the customer'],
                ['key' => 'reserved', 'label' => 'Reserved', 'count' => (int) ($row['reserved'] ?? 0),
                    'description' => 'At least one line held in Inventory'],
                ['key' => 'dispatched', 'label' => 'Dispatched', 'count' => (int) ($row['dispatched'] ?? 0),
                    'description' => 'At least one line has left'],
                ['key' => 'delivered', 'label' => 'Delivered', 'count' => (int) ($row['delivered'] ?? 0),
                    'description' => 'Every line delivered in full'],
            ],
        ];
    }

    /**
     * The fulfilment work queue: open orders with their own progress figures.
     *
     * Availability is NOT here. It is Inventory's answer and is fetched live by
     * the controller for exactly the rows being shown, because a stock figure
     * this product stored would be wrong before the page finished painting.
     *
     * @return array{rows:list<array<string, mixed>>, total:int}
     */
    public function fulfilmentQueue(string $asOf, int $limit, int $offset, ?string $risk = null): array
    {
        [$scope, $params] = $this->ctx->scopeClause('o');
        $params['as_of'] = $asOf;

        $where = $scope . ' AND ' . self::orderOpen();
        if ($risk === 'late') {
            $where .= ' AND o.committed_date IS NOT NULL AND o.committed_date < :as_of::date';
        } elseif ($risk === 'due_soon') {
            $where .= ' AND o.committed_date IS NOT NULL AND o.committed_date BETWEEN :as_of::date AND (:as_of::date + 7)';
        }

        $rows = Db::all(
            'SELECT o.order_id, o.order_no, o.order_date, o.customer_account_id, o.customer_name_snapshot,
                    o.status, o.committed_date, o.requested_date, o.total_amount, o.currency_code,
                    o.warehouse_id, o.fulfilment_mode,
                    CASE WHEN o.committed_date IS NULL THEN NULL
                         ELSE (o.committed_date - :as_of::date) END AS days_to_promise,
                    (SELECT COUNT(*) FROM sales_order_lines l WHERE l.order_id = o.order_id) AS line_count,
                    (SELECT COUNT(*) FROM sales_order_lines l
                      WHERE l.order_id = o.order_id AND l.is_service = FALSE) AS stock_line_count,
                    (SELECT COUNT(*) FROM sales_order_lines l
                      WHERE l.order_id = o.order_id AND l.delivered_qty >= l.ordered_qty) AS delivered_lines,
                    (SELECT COUNT(*) FROM sales_order_lines l
                      WHERE l.order_id = o.order_id AND l.inventory_reservation_uuid IS NOT NULL) AS reserved_lines,
                    (SELECT COALESCE(SUM(GREATEST(l.ordered_qty - l.delivered_qty, 0)), 0)
                       FROM sales_order_lines l WHERE l.order_id = o.order_id) AS outstanding_qty,
                    (SELECT COALESCE(SUM(GREATEST(l.delivered_qty - l.invoiced_qty, 0)), 0)
                       FROM sales_order_lines l WHERE l.order_id = o.order_id) AS uninvoiced_qty,
                    (SELECT COUNT(*) FROM sales_invoice_requests r
                      WHERE r.order_id = o.order_id AND r.status = \'POSTED\') AS posted_invoices,
                    (SELECT COUNT(*) FROM sales_integration_commands c
                      WHERE c.cmp_id = o.cmp_id AND c.entity_type = \'order\' AND c.entity_id = o.order_id
                        AND c.status IN (\'FAILED\', \'BLOCKED\')) AS stuck_commands
             FROM sales_orders o
             WHERE ' . $where . '
             ORDER BY o.committed_date NULLS LAST, o.order_date
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params,
        );

        $total = (int) Db::scalar('SELECT COUNT(*) FROM sales_orders o WHERE ' . $where, $params);

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Delivery commitments coming up, for the right-hand panel.
     *
     * @return list<array<string, mixed>>
     */
    public function upcomingCommitments(string $asOf, int $days = 14, int $limit = 8): array
    {
        [$scope, $params] = $this->ctx->scopeClause('o');
        $params += ['as_of' => $asOf, 'limit' => $limit, 'days' => $days];

        return Db::all(
            'SELECT o.order_id, o.order_no, o.customer_account_id, o.customer_name_snapshot,
                    o.committed_date, o.status, o.total_amount, o.currency_code,
                    (o.committed_date - :as_of::date) AS days_to_promise,
                    (SELECT COALESCE(SUM(GREATEST(l.ordered_qty - l.delivered_qty, 0)), 0)
                       FROM sales_order_lines l WHERE l.order_id = o.order_id) AS outstanding_qty,
                    (SELECT COUNT(*) FROM sales_order_lines l
                      WHERE l.order_id = o.order_id AND l.is_service = FALSE
                        AND l.inventory_reservation_uuid IS NULL AND l.delivered_qty < l.ordered_qty) AS unreserved_lines
             FROM sales_orders o
             WHERE ' . $scope . ' AND ' . self::orderOpen() . '
               AND o.committed_date IS NOT NULL
               AND o.committed_date <= (:as_of::date + :days::int)
             ORDER BY o.committed_date
             LIMIT :limit',
            $params,
        );
    }

    /**
     * On-time delivery over a period.
     *
     * DEFINITION, because there is more than one defensible one. An order counts
     * when it became fully delivered inside the period AND carried a promise
     * date. It is on time when the LAST dispatch happened on or before that
     * promise. A partial delivery does not score until the order completes:
     * sending one box early and the rest a fortnight late is not on-time
     * delivery, and counting it as such is how a 94% is built out of unhappy
     * customers. Orders with no promise date are reported separately rather than
     * quietly treated as on time.
     *
     * @return array{available:bool, rate_pc:float|null, measured:int, on_time:int,
     *               without_promise:int, basis:string}
     */
    public function onTimeDelivery(string $from, string $to): array
    {
        [$scope, $params] = $this->ctx->scopeClause('o');
        $params += ['from' => $from, 'to' => $to];

        $row = Db::first(
            "WITH completed AS (
                SELECT o.order_id, o.committed_date,
                       (SELECT MAX(f.updated_at)::date FROM sales_fulfilment_requests f
                         WHERE f.order_id = o.order_id AND f.status = 'COMPLETED') AS last_dispatch
                  FROM sales_orders o
                 WHERE {$scope}
                   AND o.status IN ('FULFILLED', 'CLOSED')
                   AND NOT EXISTS (SELECT 1 FROM sales_order_lines l
                                    WHERE l.order_id = o.order_id AND l.delivered_qty < l.ordered_qty)
            )
            SELECT
                COUNT(*) FILTER (WHERE committed_date IS NOT NULL AND last_dispatch IS NOT NULL
                                   AND last_dispatch BETWEEN :from AND :to) AS measured,
                COUNT(*) FILTER (WHERE committed_date IS NOT NULL AND last_dispatch IS NOT NULL
                                   AND last_dispatch BETWEEN :from AND :to
                                   AND last_dispatch <= committed_date)     AS on_time,
                COUNT(*) FILTER (WHERE committed_date IS NULL AND last_dispatch BETWEEN :from AND :to) AS no_promise
            FROM completed",
            $params,
        ) ?? [];

        $measured = (int) ($row['measured'] ?? 0);
        $onTime = (int) ($row['on_time'] ?? 0);

        return [
            'available'       => $measured > 0,
            'rate_pc'         => $measured > 0 ? round($onTime / $measured * 100, 1) : null,
            'measured'        => $measured,
            'on_time'         => $onTime,
            'without_promise' => (int) ($row['no_promise'] ?? 0),
            'basis'           => 'Orders fully delivered in the period that carried a promise date, scored on the '
                . 'date of their final dispatch. Partial deliveries do not score until the order completes.',
        ];
    }

    // -----------------------------------------------------------------------
    // Customers — what Sales can answer about them. Balances are Books'.
    // -----------------------------------------------------------------------

    /**
     * Buyers and repeat rate.
     *
     * COHORT, stated: an active buyer placed at least one committed order in the
     * period. A repeat buyer is an active buyer who ALSO ordered before the
     * period began, within the same financial year. Both halves use committed
     * orders, so a quotation nobody accepted never makes someone a customer.
     *
     * @return array{active:int, repeat:int, repeat_rate_pc:float|null, available:bool,
     *               previous_active:int, basis:string}
     */
    public function buyerActivity(string $from, string $to): array
    {
        [$scope, $params] = $this->ctx->scopeClause('o');
        $params += ['from' => $from, 'to' => $to];

        $row = Db::first(
            "WITH period AS (
                SELECT DISTINCT o.customer_account_id
                  FROM sales_orders o
                 WHERE {$scope} AND o.order_date BETWEEN :from AND :to AND " . self::orderCommitted() . '
            ), earlier AS (
                SELECT DISTINCT o.customer_account_id
                  FROM sales_orders o
                 WHERE ' . $scope . ' AND o.order_date < :from AND ' . self::orderCommitted() . '
            )
            SELECT (SELECT COUNT(*) FROM period)  AS active,
                   (SELECT COUNT(*) FROM earlier) AS previous_active,
                   (SELECT COUNT(*) FROM period p JOIN earlier e USING (customer_account_id)) AS repeat_buyers',
            $params,
        ) ?? [];

        $active = (int) ($row['active'] ?? 0);
        $repeat = (int) ($row['repeat_buyers'] ?? 0);

        return [
            'available'       => $active > 0,
            'active'          => $active,
            'repeat'          => $repeat,
            'previous_active' => (int) ($row['previous_active'] ?? 0),
            'repeat_rate_pc'  => $active > 0 ? round($repeat / $active * 100, 1) : null,
            'basis'           => 'Customers with a confirmed order in the period who also ordered earlier in this '
                . 'financial year, over all customers with a confirmed order in the period.',
        ];
    }

    /**
     * Ordering rhythm per customer — what a reorder suggestion has to be built on.
     *
     * Returns the gap between consecutive orders so a suggestion can say "28 to
     * 32 days apart, last order 30 days ago" instead of guessing. Customers with
     * fewer than three orders are returned with `sufficient_history` false, and
     * the caller must show that rather than inventing a cadence from two points.
     *
     * @return list<array<string, mixed>>
     */
    public function reorderCandidates(string $asOf, int $limit = 10): array
    {
        [$scope, $params] = $this->ctx->scopeClause('o');
        $params += ['as_of' => $asOf, 'limit' => $limit];

        return Db::all(
            "WITH ordered AS (
                SELECT o.customer_account_id, o.order_date, o.order_id, o.customer_name_snapshot,
                       LAG(o.order_date) OVER (PARTITION BY o.customer_account_id ORDER BY o.order_date) AS previous_date
                  FROM sales_orders o
                 WHERE {$scope} AND " . self::orderCommitted() . '
            ), gaps AS (
                SELECT customer_account_id,
                       MAX(customer_name_snapshot) AS customer_name,
                       COUNT(*)                    AS order_count,
                       MAX(order_date)             AS last_order_date,
                       MIN(order_date - previous_date) FILTER (WHERE previous_date IS NOT NULL) AS min_gap_days,
                       MAX(order_date - previous_date) FILTER (WHERE previous_date IS NOT NULL) AS max_gap_days,
                       ROUND(AVG(order_date - previous_date) FILTER (WHERE previous_date IS NOT NULL), 0) AS avg_gap_days
                  FROM ordered
                 GROUP BY customer_account_id
            )
            SELECT customer_account_id, customer_name, order_count, last_order_date,
                   min_gap_days, max_gap_days, avg_gap_days,
                   (:as_of::date - last_order_date) AS days_since_last_order,
                   (order_count >= 3 AND avg_gap_days IS NOT NULL) AS sufficient_history
              FROM gaps
             WHERE order_count >= 3
               AND avg_gap_days IS NOT NULL
               AND (:as_of::date - last_order_date) >= (avg_gap_days * 0.8)
             ORDER BY ((:as_of::date - last_order_date) - avg_gap_days) DESC
             LIMIT :limit',
            $params,
        );
    }

    /**
     * Customers buying materially less than they used to.
     *
     * Compares the period against the same number of days immediately before it.
     * Both windows are committed orders, so the comparison is like for like.
     *
     * @return list<array<string, mixed>>
     */
    public function decliningCustomers(string $from, string $to, int $limit = 10): array
    {
        [$scope, $params] = $this->ctx->scopeClause('o');
        $params += ['from' => $from, 'to' => $to, 'limit' => $limit];

        return Db::all(
            "WITH span AS (SELECT (:to::date - :from::date) + 1 AS days),
            current_window AS (
                SELECT o.customer_account_id, MAX(o.customer_name_snapshot) AS customer_name,
                       SUM(o.total_amount) AS value, COUNT(*) AS orders
                  FROM sales_orders o
                 WHERE {$scope} AND o.order_date BETWEEN :from AND :to AND " . self::orderCommitted() . '
                 GROUP BY o.customer_account_id
            ), prior_window AS (
                SELECT o.customer_account_id, SUM(o.total_amount) AS value, COUNT(*) AS orders
                  FROM sales_orders o, span
                 WHERE ' . $scope . ' AND o.order_date >= (:from::date - span.days)
                   AND o.order_date < :from::date AND ' . self::orderCommitted() . '
                 GROUP BY o.customer_account_id
            )
            SELECT p.customer_account_id,
                   COALESCE(c.customer_name, \'\')      AS customer_name,
                   COALESCE(c.value, 0)                AS current_value,
                   COALESCE(c.orders, 0)               AS current_orders,
                   p.value                             AS prior_value,
                   p.orders                            AS prior_orders,
                   ROUND(((COALESCE(c.value, 0) - p.value) / NULLIF(p.value, 0)) * 100, 1) AS change_pc
              FROM prior_window p
              LEFT JOIN current_window c ON c.customer_account_id = p.customer_account_id
             WHERE p.value > 0
               AND COALESCE(c.value, 0) < p.value * 0.7
             ORDER BY (p.value - COALESCE(c.value, 0)) DESC
             LIMIT :limit',
            $params,
        );
    }

    /**
     * Our side of a collection conversation: who we last spoke to and what they promised.
     *
     * @param list<int> $accountIds
     * @return array<int, array<string, mixed>> keyed by customer_account_id
     */
    public function lastContacts(array $accountIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
        if ($ids === []) {
            return [];
        }

        // One query for the whole page. A per-row lookup here is the N+1 that
        // makes a collections screen take four seconds.
        $placeholders = implode(',', array_map(static fn (int $i) => ':a' . $i, array_keys($ids)));
        $params = ['cmp' => $this->ctx->cmpId];
        foreach ($ids as $index => $id) {
            $params['a' . $index] = $id;
        }

        $rows = Db::all(
            "SELECT DISTINCT ON (customer_account_id)
                    customer_account_id, contacted_on, channel, note, promised_amount, promised_on, outcome
               FROM sales_followups
              WHERE cmp_id = :cmp AND customer_account_id IN ({$placeholders})
              ORDER BY customer_account_id, contacted_on DESC, followup_id DESC",
            $params,
        );

        $byAccount = [];
        foreach ($rows as $row) {
            $byAccount[(int) $row['customer_account_id']] = $row;
        }

        return $byAccount;
    }

    /**
     * What we sold each customer in the period — ours, from our own orders.
     *
     * This is ORDER value, not invoiced revenue, and is labelled as such
     * wherever it is shown. Invoiced revenue is Books'.
     *
     * @return list<array<string, mixed>>
     */
    public function topCustomers(string $from, string $to, int $limit = 5): array
    {
        [$scope, $params] = $this->ctx->scopeClause('o');
        $params += ['from' => $from, 'to' => $to, 'limit' => $limit];

        return Db::all(
            'SELECT o.customer_account_id,
                    MAX(o.customer_name_snapshot) AS customer_name_snapshot,
                    COUNT(*)                      AS order_count,
                    SUM(o.total_amount)           AS total_value,
                    MAX(o.currency_code)          AS currency_code,
                    COUNT(DISTINCT o.currency_code) AS currencies
             FROM sales_orders o
             WHERE ' . $scope . ' AND o.order_date BETWEEN :from AND :to AND ' . self::orderCommitted() . '
             GROUP BY o.customer_account_id
             ORDER BY total_value DESC
             LIMIT :limit',
            $params,
        );
    }

    // -----------------------------------------------------------------------
    // Targets and attribution
    // -----------------------------------------------------------------------

    /**
     * The target covering a period, at company scope.
     *
     * Absent means absent. A missing target is reported as "not configured" and
     * never defaulted to zero, because a zero target makes every month a
     * triumph and the attainment percentage meaningless.
     *
     * @return array{configured:bool, value:float|null, target_id:int|null, period:array{start:string, end:string}|null}
     */
    public function companyTarget(string $from, string $to): array
    {
        [$scope, $params] = $this->ctx->scopeClause('t');
        $params += ['from' => $from, 'to' => $to];

        $row = Db::first(
            "SELECT t.target_id, t.target_value, t.period_start, t.period_end
               FROM sales_targets t
              WHERE {$scope} AND t.target_scope = 'company' AND t.metric = 'value'
                AND t.period_start <= :to::date AND t.period_end >= :from::date
              ORDER BY (t.period_end - t.period_start) ASC
              LIMIT 1",
            $params,
        );

        if ($row === null) {
            return ['configured' => false, 'value' => null, 'target_id' => null, 'period' => null];
        }

        return [
            'configured' => true,
            'value'      => (float) $row['target_value'],
            'target_id'  => (int) $row['target_id'],
            'period'     => ['start' => (string) $row['period_start'], 'end' => (string) $row['period_end']],
        ];
    }

    /**
     * Per-representative targets, with the order value attributed to each.
     *
     * ATTRIBUTION: an order belongs to the salesperson recorded on it, and to
     * exactly one. Orders with nobody on them are returned as a separate
     * "unattributed" row rather than spread across the team — splitting them
     * would let one order count towards several people's attainment, which is
     * how a team can collectively beat a target nobody individually met.
     *
     * Invoiced actuals are Books' and are merged in by the controller where the
     * register is reachable; the order figure is always available and is
     * labelled for what it is.
     *
     * @return array{rows:list<array<string, mixed>>, unattributed:array<string, mixed>}
     */
    public function teamPerformance(string $from, string $to): array
    {
        [$scope, $params] = $this->ctx->scopeClause('o');
        $params += ['from' => $from, 'to' => $to];

        $rows = Db::all(
            'SELECT sp.salesperson_id, sp.display_code, sp.user_uuid,
                    COALESCE(SUM(o.total_amount), 0) AS order_value,
                    COUNT(o.order_id)                AS order_count,
                    (SELECT COALESCE(SUM(t.target_value), 0) FROM sales_targets t
                      WHERE t.cmp_id = sp.cmp_id AND t.salesperson_id = sp.salesperson_id
                        AND t.metric = \'value\'
                        AND t.period_start <= :to::date AND t.period_end >= :from::date) AS target_value
             FROM sales_people sp
             LEFT JOIN sales_orders o
                    ON o.salesperson_id = sp.salesperson_id
                   AND o.order_date BETWEEN :from AND :to
                   AND ' . self::orderCommitted() . '
                   AND ' . $scope . '
             WHERE sp.cmp_id = :ctx_cmp_id AND sp.is_active = TRUE
             GROUP BY sp.salesperson_id, sp.display_code, sp.user_uuid
             ORDER BY order_value DESC',
            $params,
        );

        [$uScope, $uParams] = $this->ctx->scopeClause('o');
        $uParams += ['from' => $from, 'to' => $to];
        $unattributed = Db::first(
            'SELECT COALESCE(SUM(o.total_amount), 0) AS order_value, COUNT(*) AS order_count
             FROM sales_orders o
             WHERE ' . $uScope . ' AND o.salesperson_id IS NULL
               AND o.order_date BETWEEN :from AND :to AND ' . self::orderCommitted(),
            $uParams,
        ) ?? [];

        return [
            'rows' => array_map(static fn (array $r) => [
                'salesperson_id' => (int) $r['salesperson_id'],
                'display_code'   => $r['display_code'],
                'user_uuid'      => $r['user_uuid'],
                'order_value'    => (float) $r['order_value'],
                'order_count'    => (int) $r['order_count'],
                'target_value'   => (float) $r['target_value'],
                'target_set'     => (float) $r['target_value'] > 0,
                'attainment_pc'  => (float) $r['target_value'] > 0
                    ? round((float) $r['order_value'] / (float) $r['target_value'] * 100, 1)
                    : null,
            ], $rows),
            'unattributed' => [
                'order_value' => (float) ($unattributed['order_value'] ?? 0),
                'order_count' => (int) ($unattributed['order_count'] ?? 0),
            ],
        ];
    }

    /**
     * Daily cumulative order value across the period — the shape of the month.
     *
     * STOPS AT THE AS-OF DATE. A cumulative line drawn to the end of the month
     * flattens after today, and a flat line reads as "sales stopped" rather than
     * "the month has not happened yet".
     *
     * @return list<array{date:string, daily:float, cumulative:float}>
     */
    public function dailyOrderTrend(string $from, string $to, string $asOf): array
    {
        $end = min($to, $asOf);
        [$scope, $params] = $this->ctx->scopeClause('o');
        $params += ['from' => $from, 'to' => $end];

        $rows = Db::all(
            "SELECT d.day::date AS day,
                    COALESCE(SUM(o.total_amount), 0) AS daily
               FROM generate_series(:from::date, :to::date, interval '1 day') AS d(day)
               LEFT JOIN sales_orders o
                      ON o.order_date = d.day::date
                     AND {$scope} AND " . self::orderCommitted() . '
              GROUP BY d.day
              ORDER BY d.day',
            $params,
        );

        $cumulative = 0.0;
        $series = [];
        foreach ($rows as $row) {
            $daily = (float) $row['daily'];
            $cumulative += $daily;
            $series[] = [
                'date'       => (string) $row['day'],
                'daily'      => round($daily, 2),
                'cumulative' => round($cumulative, 2),
            ];
        }

        return $series;
    }

    /**
     * Cross-service work that has not finished, by entity, for the attention list.
     *
     * @return array{failed:int, blocked:int}
     */
    public function stuckCommands(): array
    {
        $row = Db::first(
            "SELECT COUNT(*) FILTER (WHERE status = 'FAILED')  AS failed,
                    COUNT(*) FILTER (WHERE status = 'BLOCKED') AS blocked
               FROM sales_integration_commands
              WHERE cmp_id = :cmp",
            ['cmp' => $this->ctx->cmpId],
        ) ?? [];

        return ['failed' => (int) ($row['failed'] ?? 0), 'blocked' => (int) ($row['blocked'] ?? 0)];
    }

    /** Orders whose reservation has been asked for and not yet confirmed by Inventory. */
    public function awaitingStockConfirmation(): int
    {
        [$scope, $params] = $this->ctx->scopeClause('o');

        return (int) Db::scalar(
            "SELECT COUNT(*) FROM sales_orders o WHERE {$scope} AND o.status = 'RESERVATION_PENDING'",
            $params,
        );
    }

    public function pendingApprovals(): int
    {
        return (int) Db::scalar(
            "SELECT COUNT(*) FROM sales_approval_requests WHERE cmp_id = :cmp AND status = 'PENDING'",
            ['cmp' => $this->ctx->cmpId],
        );
    }

    /** @return array{count:int, value:float} */
    public function pendingApprovalValue(): array
    {
        $row = Db::first(
            "SELECT COUNT(*) AS approval_count,
                    COALESCE(SUM(COALESCE(q.total_amount, o.total_amount, 0)), 0) AS approval_value
               FROM sales_approval_requests a
               LEFT JOIN sales_quotations q ON a.entity_type = 'quotation' AND q.quotation_id = a.entity_id
               LEFT JOIN sales_orders     o ON a.entity_type = 'order'     AND o.order_id     = a.entity_id
              WHERE a.cmp_id = :cmp AND a.status = 'PENDING'",
            ['cmp' => $this->ctx->cmpId],
        ) ?? [];

        return ['count' => (int) ($row['approval_count'] ?? 0), 'value' => (float) ($row['approval_value'] ?? 0)];
    }

    /** The company's reporting currency, as used by its own documents. */
    public function reportingCurrency(): string
    {
        $currency = Db::scalar(
            'SELECT currency_code FROM sales_orders WHERE cmp_id = :cmp
             UNION ALL SELECT currency_code FROM sales_quotations WHERE cmp_id = :cmp
             LIMIT 1',
            ['cmp' => $this->ctx->cmpId],
        );

        return is_string($currency) && $currency !== '' ? $currency : 'INR';
    }
}
