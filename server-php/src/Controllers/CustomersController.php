<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\FinancialPositionService;
use Aicountly\Api\Domain\MetricsService;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * Customers, as Sales sees them.
 *
 * THIS IS NOT A CUSTOMER MASTER and must never become one. Contacts owns
 * identity, Books owns the ledger. What is assembled here is the SALES VIEW of
 * a party: what we quoted them, what they ordered, how often they come back,
 * what we last said to them — joined at read time to the balance Books reports.
 *
 * The account id is the join. No name, no address, no credit limit and no
 * balance is stored on this side; `customer_name_snapshot` is the name printed
 * on a document and is read back as exactly that.
 */
final class CustomersController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'order.view');

        $params = Http::listParams(['total_value', 'last_order_date', 'order_count'], 'total_value');
        $asOf = Http::param('as_of') ?? gmdate('Y-m-d');
        $signal = Http::param('signal');
        $term = trim((string) ($params['q'] ?? ''));

        [$scope, $bindings] = $ctx->scopeClause('o');
        $where = [$scope, "o.status <> 'CANCELLED'"];
        if ($term !== '') {
            $where[] = 'o.customer_name_snapshot ILIKE :term';
            $bindings['term'] = '%' . $term . '%';
        }
        $clause = implode(' AND ', $where);

        $rows = Db::all(
            "SELECT o.customer_account_id,
                    MAX(o.customer_name_snapshot)                              AS customer_name,
                    COUNT(*) FILTER (WHERE o.status <> 'DRAFT')                AS order_count,
                    COALESCE(SUM(o.total_amount) FILTER (WHERE o.status <> 'DRAFT'), 0) AS total_value,
                    MAX(o.currency_code)                                       AS currency_code,
                    MAX(o.order_date)                                          AS last_order_date,
                    (:as_of::date - MAX(o.order_date))                         AS days_since_last_order,
                    (SELECT COUNT(*) FROM sales_quotations q
                      WHERE q.cmp_id = :quote_cmp AND q.customer_account_id = o.customer_account_id) AS quotation_count
               FROM sales_orders o
              WHERE {$clause}
              GROUP BY o.customer_account_id
              ORDER BY {$params['sort']} {$params['order']} NULLS LAST
              LIMIT {$params['limit']} OFFSET {$params['offset']}",
            $bindings + ['as_of' => $asOf, 'quote_cmp' => $ctx->cmpId],
        );

        $total = (int) Db::scalar(
            "SELECT COUNT(DISTINCT o.customer_account_id) FROM sales_orders o WHERE {$clause}",
            $bindings,
        );

        // Our own last contact, in one query for the whole page.
        $metrics = new MetricsService($ctx);
        $contacts = $metrics->lastContacts(array_column($rows, 'customer_account_id'));

        // The signals that drive the opportunity panel, so the list can be
        // filtered by the same thing the dashboard counted.
        $reorder = [];
        foreach ($metrics->reorderCandidates($asOf, 100) as $candidate) {
            $reorder[(int) $candidate['customer_account_id']] = $candidate;
        }
        $declining = [];
        foreach ($metrics->decliningCustomers(gmdate('Y-m-01', strtotime($asOf)), $asOf, 100) as $candidate) {
            $declining[(int) $candidate['customer_account_id']] = $candidate;
        }

        $out = [];
        foreach ($rows as $row) {
            $accountId = (int) $row['customer_account_id'];
            $contact = $contacts[$accountId] ?? null;
            $entry = $row + [
                'last_contact_on'  => $contact['contacted_on'] ?? null,
                'last_contact_via' => $contact['channel'] ?? null,
                'reorder_due'      => isset($reorder[$accountId]),
                'declining'        => isset($declining[$accountId]),
                'average_gap_days' => $reorder[$accountId]['avg_gap_days'] ?? null,
            ];

            if ($signal === 'reorder' && !$entry['reorder_due']) {
                continue;
            }
            if ($signal === 'declining' && !$entry['declining']) {
                continue;
            }
            $out[] = $entry;
        }

        Http::list($out, $signal ? count($out) : $total, $params['limit'], $params['offset'], ['as_of' => $asOf]);
    }

    /**
     * One customer: our documents, our follow-ups, and Books' live position.
     *
     * The three halves stay labelled. "You have ordered ₹4.2L from us" and
     * "you owe us ₹1.8L" are different statements from different systems, and
     * a screen that merges them into one figure is a screen that will be used
     * to argue with a customer and lose.
     */
    public static function show(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'order.view');

        $accountId = (int) $id;
        $asOf = Http::param('as_of') ?? gmdate('Y-m-d');

        [$scope, $bindings] = $ctx->scopeClause('o');
        $bindings['acc'] = $accountId;

        $summary = Db::first(
            "SELECT MAX(o.customer_name_snapshot) AS customer_name,
                    COUNT(*) FILTER (WHERE o.status <> 'CANCELLED' AND o.status <> 'DRAFT') AS order_count,
                    COALESCE(SUM(o.total_amount) FILTER (WHERE o.status <> 'CANCELLED' AND o.status <> 'DRAFT'), 0) AS total_value,
                    MIN(o.order_date) AS first_order_date,
                    MAX(o.order_date) AS last_order_date,
                    MAX(o.currency_code) AS currency_code
               FROM sales_orders o
              WHERE {$scope} AND o.customer_account_id = :acc",
            $bindings,
        ) ?? [];

        $orders = Db::all(
            "SELECT o.order_id, o.order_no, o.order_date, o.status, o.total_amount, o.currency_code,
                    o.committed_date, o.customer_po_ref
               FROM sales_orders o
              WHERE {$scope} AND o.customer_account_id = :acc
              ORDER BY o.order_date DESC, o.order_id DESC
              LIMIT 15",
            $bindings,
        );

        [$qScope, $qBindings] = $ctx->scopeClause('q');
        $qBindings += ['acc' => $accountId, 'as_of' => $asOf];
        $quotations = Db::all(
            'SELECT q.quotation_id, q.quotation_no, q.revision_no, q.quotation_date, q.valid_until,
                    q.total_amount, q.currency_code,
                    ' . MetricsService::effectiveStatusSql() . ' AS status
               FROM sales_quotations q
              WHERE ' . $qScope . ' AND q.customer_account_id = :acc AND ' . MetricsService::latestRevision() . '
              ORDER BY q.quotation_date DESC, q.quotation_id DESC
              LIMIT 15',
            $qBindings,
        );

        $followups = Db::all(
            'SELECT followup_id, followup_kind, channel, contacted_on, note, promised_amount, promised_on, outcome, created_by
               FROM sales_followups WHERE cmp_id = :cmp AND customer_account_id = :acc
              ORDER BY contacted_on DESC, followup_id DESC LIMIT 20',
            ['cmp' => $ctx->cmpId, 'acc' => $accountId],
        );

        // Books, live, and only if this user may see money.
        $position = ['status' => 'forbidden', 'reason' => 'You do not have permission to view sales reports.'];
        if (Permissions::allows($ctx, $auth, 'reports.view')) {
            $books = new FinancialPositionService($ctx, (new BooksClient())->withSession($auth->sesKey()));
            $priorities = $books->collectionPriorities($asOf, 500);
            if ($priorities['status'] === 'ready') {
                $match = null;
                foreach ($priorities['rows'] as $row) {
                    if ((int) $row['customer_account_id'] === $accountId) {
                        $match = $row;
                        break;
                    }
                }
                $position = $match === null
                    ? ['status' => 'ready', 'reason' => null, 'outstanding' => 0.0, 'overdue' => 0.0,
                       'bill_count' => 0, 'oldest_due_date' => null, 'oldest_days' => 0]
                    : ['status' => 'ready', 'reason' => null] + $match;
            } else {
                $position = ['status' => 'unavailable', 'reason' => $priorities['reason']];
            }
        }

        Http::data([
            'customer_account_id' => $accountId,
            'as_of'      => $asOf,
            'summary'    => $summary,
            'orders'     => $orders,
            'quotations' => $quotations,
            'followups'  => $followups,
            'position'   => $position,
            'basis'      => 'Order and quotation values are ours and are what we agreed. Outstanding and overdue '
                . 'are Smart Books\' and are read live — the two measure different things and are never added together.',
        ]);
    }
}
