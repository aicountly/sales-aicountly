<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\IntegrationCommand;
use Aicountly\Api\Permissions;

/**
 * The Sales dashboard.
 *
 * Composed at read time from two sources that stay apart:
 *
 *   OURS      quotation and order counts, conversion, pending approvals,
 *             fulfilment backlog — questions only this product can answer
 *   BOOKS'    invoiced revenue and outstanding — fetched live, on this request
 *
 * There is no analytics table here and no nightly roll-up. A stored revenue
 * figure would be a second answer to a question the accounts already answer, and
 * the two would disagree the first time an invoice was cancelled.
 *
 * Books being slow or down degrades the financial cards and nothing else: the
 * Sales half still renders, and the cards that could not load say so instead of
 * showing a zero that reads like "no sales this month".
 */
final class DashboardController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter();

        $from = Http::param('from') ?? gmdate('Y-m-01');
        $to = Http::param('to') ?? gmdate('Y-m-d');

        [$scope, $params] = $ctx->scopeClause();
        $rangeParams = $params + ['from' => $from, 'to' => $to];

        $quotations = Db::first(
            "SELECT
                COUNT(*)                                                      AS total,
                COUNT(*) FILTER (WHERE status = 'APPROVAL_PENDING')           AS awaiting_approval,
                COUNT(*) FILTER (WHERE status = 'SENT')                       AS awaiting_customer,
                COUNT(*) FILTER (WHERE status = 'ACCEPTED')                   AS accepted,
                COUNT(*) FILTER (WHERE status = 'CONVERTED')                  AS converted,
                COALESCE(SUM(total_amount), 0)                                AS total_value
             FROM sales_quotations
             WHERE {$scope} AND quotation_date BETWEEN :from AND :to",
            $rangeParams,
        ) ?? [];

        $orders = Db::first(
            "SELECT
                COUNT(*)                                                       AS total,
                COUNT(*) FILTER (WHERE status IN ('CONFIRMED','RESERVED','RESERVATION_PENDING')) AS open_orders,
                COUNT(*) FILTER (WHERE status = 'PARTIALLY_FULFILLED')          AS partially_fulfilled,
                COUNT(*) FILTER (WHERE status = 'RESERVATION_PENDING')          AS reservation_pending,
                COALESCE(SUM(total_amount), 0)                                  AS total_value
             FROM sales_orders
             WHERE {$scope} AND order_date BETWEEN :from AND :to",
            $rangeParams,
        ) ?? [];

        $backlog = Db::first(
            "SELECT
                COALESCE(SUM(GREATEST(l.ordered_qty - l.delivered_qty, 0) * l.rate), 0) AS undelivered_value,
                COALESCE(SUM(GREATEST(l.delivered_qty - l.invoiced_qty, 0) * l.rate), 0) AS uninvoiced_value
             FROM sales_order_lines l
             JOIN sales_orders o ON o.order_id = l.order_id
             WHERE o.cmp_id = :ctx_cmp_id AND o.fy_id = :ctx_fy_id
               AND o.status NOT IN ('CANCELLED', 'CLOSED')",
            ['ctx_cmp_id' => $ctx->cmpId, 'ctx_fy_id' => $ctx->fyId],
        ) ?? [];

        $pendingApprovals = (int) Db::scalar(
            "SELECT COUNT(*) FROM sales_approval_requests WHERE cmp_id = :cmp AND status = 'PENDING'",
            ['cmp' => $ctx->cmpId],
        );

        $stuckCommands = (int) Db::scalar(
            "SELECT COUNT(*) FROM sales_integration_commands WHERE cmp_id = :cmp AND status IN ('FAILED', 'BLOCKED')",
            ['cmp' => $ctx->cmpId],
        );

        $topCustomers = Db::all(
            "SELECT customer_account_id, customer_name_snapshot, COUNT(*) AS order_count, SUM(total_amount) AS total_value
             FROM sales_orders
             WHERE {$scope} AND order_date BETWEEN :from AND :to AND status <> 'CANCELLED'
             GROUP BY customer_account_id, customer_name_snapshot
             ORDER BY total_value DESC
             LIMIT 5",
            $rangeParams,
        );

        // --- Books, live. Failure degrades these cards only. ---------------
        $financial = ['available' => false, 'reason' => null];
        if (Permissions::allows($ctx, $auth, 'reports.view')) {
            $books = (new BooksClient())->withSession($auth->sesKey());
            $dashboard = $books->salesDashboard($ctx, ['from' => $from, 'to' => $to]);
            $receivables = $books->billByBill($ctx, ['party_type' => 'debtor', 'as_on' => $to]);

            if ($dashboard['ok']) {
                $financial['available'] = true;
                $financial['sales'] = $dashboard['body']['data'] ?? [];
            } else {
                $financial['reason'] = 'Books did not answer in time.';
            }

            if ($receivables['ok']) {
                $rows = (array) ($receivables['body']['data'] ?? []);
                $total = 0.0;
                foreach ($rows as $row) {
                    $total += (float) ($row['balance'] ?? $row['outstanding'] ?? 0);
                }
                $financial['receivable_total'] = round($total, 2);
                $financial['receivable_count'] = count($rows);
            }
        }

        Http::data([
            'period' => ['from' => $from, 'to' => $to],
            'quotations' => [
                'total'             => (int) ($quotations['total'] ?? 0),
                'awaiting_approval' => (int) ($quotations['awaiting_approval'] ?? 0),
                'awaiting_customer' => (int) ($quotations['awaiting_customer'] ?? 0),
                'accepted'          => (int) ($quotations['accepted'] ?? 0),
                'converted'         => (int) ($quotations['converted'] ?? 0),
                'total_value'       => (float) ($quotations['total_value'] ?? 0),
                // Conversion is ours to compute: both numbers are our documents.
                'conversion_pc'     => (int) ($quotations['total'] ?? 0) > 0
                    ? round(((int) ($quotations['converted'] ?? 0) / (int) $quotations['total']) * 100, 1)
                    : 0.0,
            ],
            'orders' => [
                'total'               => (int) ($orders['total'] ?? 0),
                'open'                => (int) ($orders['open_orders'] ?? 0),
                'partially_fulfilled' => (int) ($orders['partially_fulfilled'] ?? 0),
                'reservation_pending' => (int) ($orders['reservation_pending'] ?? 0),
                'total_value'         => (float) ($orders['total_value'] ?? 0),
            ],
            'backlog' => [
                'undelivered_value' => (float) ($backlog['undelivered_value'] ?? 0),
                'uninvoiced_value'  => (float) ($backlog['uninvoiced_value'] ?? 0),
            ],
            'attention' => [
                'pending_approvals' => $pendingApprovals,
                'stuck_commands'    => $stuckCommands,
            ],
            'top_customers' => $topCustomers,
            'financial'     => $financial,
        ]);
    }

    public static function approvals(): void
    {
        [$auth, $ctx] = self::enter();

        $status = Http::param('status') ?? 'PENDING';
        $params = Http::listParams(['created_at'], 'created_at');

        $rows = Db::all(
            'SELECT a.*,
                    q.quotation_no, q.customer_name_snapshot AS quotation_customer, q.total_amount AS quotation_total,
                    o.order_no,     o.customer_name_snapshot AS order_customer,     o.total_amount AS order_total
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
}
