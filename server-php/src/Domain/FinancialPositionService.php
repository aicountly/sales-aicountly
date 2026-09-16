<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Context;

/**
 * The financial half of the Sales dashboards, read live from Books.
 *
 * NOTHING HERE IS STORED. Not the invoice, not the balance, not the ageing
 * bucket. Every figure is fetched on the request that renders it and thrown
 * away afterwards, which is why a receipt entered in Books while this page was
 * open shows on the next refresh rather than on the next night's sync.
 *
 * DEGRADING HONESTLY is the other half of the job. Every method returns a
 * status alongside its value:
 *
 *   ready        Books answered; the figure is Books' own
 *   unavailable  Books did not answer, and the caller must SAY SO
 *   forbidden    this user may not see it
 *
 * There is no fourth case where a failure quietly becomes 0. "Overdue: ₹0"
 * and "Books did not answer" look nothing alike to a credit controller, and a
 * dashboard that confuses them will eventually let somebody ship to a debtor.
 */
final class FinancialPositionService
{
    /** Ageing buckets, in days past the due date. The last one has no upper bound. */
    public const BUCKETS = [
        ['key' => 'not_due', 'label' => 'Not due', 'from' => null, 'to' => 0],
        ['key' => 'd1_30', 'label' => '1–30 days', 'from' => 1, 'to' => 30],
        ['key' => 'd31_60', 'label' => '31–60 days', 'from' => 31, 'to' => 60],
        ['key' => 'd61_plus', 'label' => '61+ days', 'from' => 61, 'to' => null],
    ];

    public function __construct(
        private readonly Context $ctx,
        private readonly BooksClient $books,
    ) {
    }

    /**
     * Net invoiced sales for a period, as Books reports it.
     *
     * This product does not add up its own order lines and call the result
     * revenue. An order is a promise; revenue is a posted voucher, and only
     * Books knows which vouchers were posted, cancelled or credited.
     *
     * @return array{status:string, value:float|null, invoice_count:int|null,
     *               reason:string|null, basis:string, source:string}
     */
    public function netInvoicedSales(string $from, string $to): array
    {
        $response = $this->books->salesDashboard($this->ctx, ['from' => $from, 'to' => $to]);

        $basis = 'Posted sales vouchers dated in the period, net of cancellations and credit notes, '
            . 'as reported by Smart Books.';

        if (!$response['ok']) {
            return [
                'status'        => 'unavailable',
                'value'         => null,
                'invoice_count' => null,
                'reason'        => self::reason($response),
                'basis'         => $basis,
                'source'        => 'books',
            ];
        }

        $data = (array) ($response['body']['data'] ?? []);
        $value = self::firstNumeric($data, ['net_sales', 'total_sales', 'net_invoiced', 'invoiced_value', 'total']);

        if ($value === null) {
            // Books answered with a shape this version does not recognise.
            // Saying so beats printing whichever number happened to be first.
            return [
                'status'        => 'unavailable',
                'value'         => null,
                'invoice_count' => null,
                'reason'        => 'Smart Books answered in a format this version does not recognise.',
                'basis'         => $basis,
                'source'        => 'books',
            ];
        }

        return [
            'status'        => 'ready',
            'value'         => round($value, 2),
            'invoice_count' => isset($data['invoice_count']) ? (int) $data['invoice_count'] : null,
            'reason'        => null,
            'basis'         => $basis,
            'source'        => 'books',
        ];
    }

    /**
     * Receivables ageing as at a cutoff, bucketed on Books' own due dates.
     *
     * The buckets are computed here from the bills Books returns — the split is
     * presentation, the due date and the balance are Books'. Crucially the
     * cutoff is NOT narrowed to the selected month: an invoice raised in March
     * and unpaid in September is outstanding today, and a dashboard that only
     * counts this month's invoices reports a healthy ledger for a company that
     * is owed a fortune.
     *
     * @return array{status:string, reason:string|null, as_of:string,
     *               total:float|null, overdue:float|null, buckets:list<array<string, mixed>>,
     *               oldest_days:int|null, bill_count:int|null, basis:string, source:string}
     */
    public function ageing(string $asOf): array
    {
        $basis = 'Open customer bills from Smart Books at the cutoff date, bucketed on each bill\'s own due date. '
            . 'Credits and advances are treated exactly as Books treats them.';

        $response = $this->books->billByBill($this->ctx, ['party_type' => 'debtor', 'as_on' => $asOf]);

        if (!$response['ok']) {
            return [
                'status' => 'unavailable', 'reason' => self::reason($response), 'as_of' => $asOf,
                'total' => null, 'overdue' => null, 'buckets' => [], 'oldest_days' => null,
                'bill_count' => null, 'basis' => $basis, 'source' => 'books',
            ];
        }

        $rows = (array) ($response['body']['data'] ?? []);
        $cutoff = new \DateTimeImmutable($asOf);

        $buckets = [];
        foreach (self::BUCKETS as $bucket) {
            $buckets[$bucket['key']] = $bucket + ['value' => 0.0, 'count' => 0];
        }

        $total = 0.0;
        $overdue = 0.0;
        $oldest = null;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $balance = self::firstNumeric($row, ['balance', 'outstanding', 'amount_due', 'pending']);
            if ($balance === null || abs($balance) < 0.005) {
                continue;
            }

            $total += $balance;
            $daysPastDue = self::daysPastDue($row, $cutoff);

            if ($daysPastDue > 0) {
                $overdue += $balance;
                $oldest = $oldest === null ? $daysPastDue : max($oldest, $daysPastDue);
            }

            $key = self::bucketFor($daysPastDue);
            $buckets[$key]['value'] += $balance;
            $buckets[$key]['count']++;
        }

        return [
            'status'      => 'ready',
            'reason'      => null,
            'as_of'       => $asOf,
            'total'       => round($total, 2),
            'overdue'     => round($overdue, 2),
            'oldest_days' => $oldest,
            'bill_count'  => count($rows),
            'buckets'     => array_values(array_map(static fn (array $b) => [
                'key'   => $b['key'],
                'label' => $b['label'],
                'value' => round($b['value'], 2),
                'count' => $b['count'],
            ], $buckets)),
            'basis'  => $basis,
            'source' => 'books',
        ];
    }

    /**
     * Who owes what, worst first, for the collection priorities table.
     *
     * Grouped from the same bill list, so the table and the ageing chart above
     * it cannot disagree — they are one Books answer presented two ways.
     *
     * @return array{status:string, reason:string|null, rows:list<array<string, mixed>>, source:string}
     */
    public function collectionPriorities(string $asOf, int $limit = 10): array
    {
        $response = $this->books->billByBill($this->ctx, ['party_type' => 'debtor', 'as_on' => $asOf]);

        if (!$response['ok']) {
            return ['status' => 'unavailable', 'reason' => self::reason($response), 'rows' => [], 'source' => 'books'];
        }

        $cutoff = new \DateTimeImmutable($asOf);
        $byAccount = [];

        foreach ((array) ($response['body']['data'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $balance = self::firstNumeric($row, ['balance', 'outstanding', 'amount_due', 'pending']);
            if ($balance === null || abs($balance) < 0.005) {
                continue;
            }

            $accountId = (int) ($row['acc_id'] ?? $row['account_id'] ?? $row['party_id'] ?? 0);
            $name = (string) ($row['acc_name'] ?? $row['party_name'] ?? $row['account_name'] ?? '');
            if (!isset($byAccount[$accountId])) {
                $byAccount[$accountId] = [
                    'customer_account_id' => $accountId,
                    'customer_name'       => $name,
                    'outstanding'         => 0.0,
                    'overdue'             => 0.0,
                    'bill_count'          => 0,
                    'oldest_due_date'     => null,
                    'oldest_days'         => 0,
                ];
            }

            $daysPastDue = self::daysPastDue($row, $cutoff);
            $byAccount[$accountId]['outstanding'] += $balance;
            $byAccount[$accountId]['bill_count']++;
            if ($daysPastDue > 0) {
                $byAccount[$accountId]['overdue'] += $balance;
                if ($daysPastDue > $byAccount[$accountId]['oldest_days']) {
                    $byAccount[$accountId]['oldest_days'] = $daysPastDue;
                    $byAccount[$accountId]['oldest_due_date'] = self::dueDate($row);
                }
            }
            if ($byAccount[$accountId]['customer_name'] === '' && $name !== '') {
                $byAccount[$accountId]['customer_name'] = $name;
            }
        }

        $rows = array_values($byAccount);
        usort($rows, static fn (array $a, array $b) => $b['overdue'] <=> $a['overdue'] ?: $b['outstanding'] <=> $a['outstanding']);

        return [
            'status' => 'ready',
            'reason' => null,
            'rows'   => array_map(static fn (array $r) => [
                'customer_account_id' => $r['customer_account_id'],
                'customer_name'       => $r['customer_name'],
                'outstanding'         => round($r['outstanding'], 2),
                'overdue'             => round($r['overdue'], 2),
                'bill_count'          => $r['bill_count'],
                'oldest_due_date'     => $r['oldest_due_date'],
                'oldest_days'         => $r['oldest_days'],
            ], array_slice($rows, 0, $limit)),
            'source' => 'books',
        ];
    }

    /**
     * Day-by-day cumulative invoiced sales, for the chart beneath the KPI.
     *
     * Built from Books' own voucher register so the line and the card are the
     * same answer counted the same way. It STOPS AT THE AS-OF DATE: continuing
     * a cumulative line to the end of the month draws a flat stretch that reads
     * as "sales collapsed" rather than "those days have not happened".
     *
     * @return array{status:string, reason:string|null, series:list<array{date:string, daily:float, cumulative:float}>, source:string}
     */
    public function dailyInvoicedSeries(string $from, string $to, string $asOf): array
    {
        $end = min($to, $asOf);
        $response = $this->books->registers($this->ctx, [
            'from' => $from,
            'to' => $end,
            'vch_type_id' => BooksClient::VCH_SALES,
            'limit' => 1000,
        ]);

        if (!$response['ok']) {
            return ['status' => 'unavailable', 'reason' => self::reason($response), 'series' => [], 'source' => 'books'];
        }

        $daily = [];
        foreach ((array) ($response['body']['data'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $date = null;
            foreach (['vch_date', 'voucher_date', 'date', 'txn_date'] as $key) {
                if (!empty($row[$key]) && is_string($row[$key])) {
                    $date = substr($row[$key], 0, 10);
                    break;
                }
            }
            $amount = self::firstNumeric($row, ['net_amount', 'total_amount', 'amount', 'grand_total']);
            if ($date === null || $amount === null) {
                continue;
            }
            $daily[$date] = ($daily[$date] ?? 0.0) + $amount;
        }

        if ($daily === []) {
            // The register was readable but held nothing this version could
            // date. An empty chart with no explanation is worse than saying so.
            return [
                'status' => 'unavailable',
                'reason' => 'Smart Books returned no dated sales vouchers for this period.',
                'series' => [],
                'source' => 'books',
            ];
        }

        $series = [];
        $cumulative = 0.0;
        $cursor = new \DateTimeImmutable($from);
        $stop = new \DateTimeImmutable($end);
        while ($cursor <= $stop) {
            $key = $cursor->format('Y-m-d');
            $cumulative += $daily[$key] ?? 0.0;
            $series[] = ['date' => $key, 'daily' => round($daily[$key] ?? 0.0, 2), 'cumulative' => round($cumulative, 2)];
            $cursor = $cursor->modify('+1 day');
        }

        return ['status' => 'ready', 'reason' => null, 'series' => $series, 'source' => 'books'];
    }

    /**
     * Invoiced revenue attributed to each salesperson, where Books can supply it.
     *
     * Books' register is keyed by voucher, not by our salesperson, so the
     * attribution is done here from OUR orders: the invoice requests we raised
     * carry both the order and the voucher. Where the register cannot be read
     * the caller falls back to order value and labels it as order value —
     * never as revenue.
     *
     * @return array{status:string, reason:string|null, by_voucher:array<string, float>, source:string}
     */
    public function invoicedByVoucher(string $from, string $to): array
    {
        $response = $this->books->registers($this->ctx, [
            'from' => $from,
            'to' => $to,
            'vch_type_id' => BooksClient::VCH_SALES,
            'limit' => 500,
        ]);

        if (!$response['ok']) {
            return ['status' => 'unavailable', 'reason' => self::reason($response), 'by_voucher' => [], 'source' => 'books'];
        }

        $byVoucher = [];
        foreach ((array) ($response['body']['data'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $uuid = (string) ($row['vch_uuid'] ?? $row['voucher_uuid'] ?? '');
            $id = (string) ($row['vch_txn_id'] ?? $row['voucher_id'] ?? '');
            $amount = self::firstNumeric($row, ['net_amount', 'total_amount', 'amount', 'grand_total']);
            if ($amount === null) {
                continue;
            }
            if ($uuid !== '') {
                $byVoucher[$uuid] = $amount;
            }
            if ($id !== '') {
                $byVoucher[$id] = $amount;
            }
        }

        return ['status' => 'ready', 'reason' => null, 'by_voucher' => $byVoucher, 'source' => 'books'];
    }

    // -----------------------------------------------------------------------

    private static function daysPastDue(array $row, \DateTimeImmutable $cutoff): int
    {
        // Books may already have done the arithmetic. Prefer its answer: it
        // knows about grace periods and credit terms that we do not.
        foreach (['days_overdue', 'overdue_days', 'age_days'] as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                return max(0, (int) $row[$key]);
            }
        }

        $due = self::dueDate($row);
        if ($due === null) {
            return 0;
        }

        try {
            $dueDate = new \DateTimeImmutable($due);
        } catch (\Throwable) {
            return 0;
        }

        return max(0, (int) $cutoff->diff($dueDate)->format('%r%a') * -1);
    }

    private static function dueDate(array $row): ?string
    {
        foreach (['due_date', 'bill_due_date', 'due_on'] as $key) {
            if (!empty($row[$key]) && is_string($row[$key])) {
                return $row[$key];
            }
        }

        return null;
    }

    private static function bucketFor(int $daysPastDue): string
    {
        foreach (self::BUCKETS as $bucket) {
            $afterFrom = $bucket['from'] === null || $daysPastDue >= $bucket['from'];
            $beforeTo = $bucket['to'] === null || $daysPastDue <= $bucket['to'];
            if ($afterFrom && $beforeTo) {
                return $bucket['key'];
            }
        }

        return 'd61_plus';
    }

    /** @param list<string> $keys */
    private static function firstNumeric(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (float) $data[$key];
            }
        }

        return null;
    }

    /** @param array{ok:bool, status:int, error?:?string} $response */
    private static function reason(array $response): string
    {
        $status = (int) ($response['status'] ?? 0);
        if ($status === 403) {
            return 'Smart Books did not allow this user to read the sales ledger.';
        }
        if ($status === 0) {
            return 'Smart Books could not be reached.';
        }

        return 'Smart Books did not answer in time (HTTP ' . $status . ').';
    }
}
