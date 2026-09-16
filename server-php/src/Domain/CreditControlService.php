<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;

/**
 * May we take this order on credit?
 *
 * Every figure in the answer — limit, outstanding, overdue — is read from Books
 * on this request. NOTHING is stored. A customer balance copied into this
 * database is wrong the moment a receipt is entered anywhere else, and a credit
 * check against a stale balance is worse than no check: it is a check that says
 * yes with authority.
 *
 * What IS stored is our decision and who overrode it, on the order. That is a
 * fact about our workflow and it is the only part we own.
 */
final class CreditControlService
{
    public const ALLOW             = 'ALLOW';
    public const WARN              = 'WARN';
    public const BLOCK             = 'BLOCK';
    public const APPROVAL_REQUIRED = 'APPROVAL_REQUIRED';
    public const UNAVAILABLE       = 'UNAVAILABLE';

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    /**
     * @return array{
     *   decision:string, reason:string, checked_at:string,
     *   credit_limit:?float, outstanding:?float, overdue:?float,
     *   exposure_after:?float, oldest_overdue_days:?int
     * }
     */
    public function evaluate(int $customerAccountId, float $orderValue): array
    {
        $mode = (string) (Db::scalar(
            'SELECT credit_control_mode FROM sales_settings WHERE cmp_id = :cmp',
            ['cmp' => $this->ctx->cmpId],
        ) ?? 'warn');

        $now = gmdate('c');

        if ($mode === 'allow') {
            return $this->answer(self::ALLOW, 'Credit control is switched off for this company.', $now);
        }

        $books = (new BooksClient());
        $books = $this->auth->isService()
            ? $books->withService($this->auth->uuid)
            : $books->withSession($this->auth->sesKey());

        $account = $books->account($this->ctx, $customerAccountId);
        $outstanding = $books->billByBill($this->ctx, [
            'account_id' => $customerAccountId,
            'party_type' => 'debtor',
        ]);

        if (!$account['ok'] || !$outstanding['ok']) {
            // Books unreachable. We do not guess and we do not silently allow.
            // The order can still be saved as a draft; what is refused is the
            // claim that the customer's credit was checked.
            return $this->answer(
                self::UNAVAILABLE,
                'Could not reach Books to check this customer\'s credit. The order can be saved, but not confirmed on credit.',
                $now,
            );
        }

        $accountData = $account['body']['data'] ?? [];
        $creditLimit = self::number($accountData, ['credit_limit', 'cr_limit', 'credit_limit_amount']);
        $creditDays  = self::number($accountData, ['credit_days', 'cr_days']);

        [$totalOutstanding, $overdue, $oldestOverdueDays] = $this->summarise($outstanding['body']['data'] ?? [], $creditDays);

        $exposureAfter = $totalOutstanding + $orderValue;

        // An overdue bill is a stronger signal than a limit: a customer inside
        // their limit who has not paid for ninety days is the one that hurts.
        if ($overdue > 0 && $oldestOverdueDays !== null && $oldestOverdueDays > 0) {
            $decision = $mode === 'block' ? self::BLOCK : ($mode === 'approval_required' ? self::APPROVAL_REQUIRED : self::WARN);

            return $this->answer(
                $decision,
                sprintf('%s is overdue, the oldest by %d days.', self::money($overdue), $oldestOverdueDays),
                $now,
                $creditLimit,
                $totalOutstanding,
                $overdue,
                $exposureAfter,
                $oldestOverdueDays,
            );
        }

        if ($creditLimit !== null && $creditLimit > 0 && $exposureAfter > $creditLimit) {
            $decision = $mode === 'block' ? self::BLOCK : ($mode === 'approval_required' ? self::APPROVAL_REQUIRED : self::WARN);

            return $this->answer(
                $decision,
                sprintf(
                    'This order takes exposure to %s against a limit of %s.',
                    self::money($exposureAfter),
                    self::money($creditLimit),
                ),
                $now,
                $creditLimit,
                $totalOutstanding,
                $overdue,
                $exposureAfter,
                $oldestOverdueDays,
            );
        }

        return $this->answer(
            self::ALLOW,
            'Within limit and nothing overdue.',
            $now,
            $creditLimit,
            $totalOutstanding,
            $overdue,
            $exposureAfter,
            $oldestOverdueDays,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{0:float, 1:float, 2:?int}
     */
    private function summarise(array $rows, ?float $creditDays): array
    {
        $total = 0.0;
        $overdue = 0.0;
        $oldest = null;
        $today = new \DateTimeImmutable('today');

        foreach ($rows as $row) {
            $balance = (float) (self::number($row, ['balance', 'outstanding', 'pending_amount', 'amount']) ?? 0);
            if ($balance <= 0) {
                continue;
            }
            $total += $balance;

            $dueRaw = $row['due_date'] ?? $row['bill_due_date'] ?? null;
            if (!is_string($dueRaw) || $dueRaw === '') {
                // No due date: fall back to the bill date plus the customer's
                // credit days, which is what the term actually means.
                $billRaw = $row['bill_date'] ?? $row['voucher_date'] ?? $row['date'] ?? null;
                if (!is_string($billRaw) || $billRaw === '' || $creditDays === null) {
                    continue;
                }
                try {
                    $due = (new \DateTimeImmutable($billRaw))->modify('+' . (int) $creditDays . ' days');
                } catch (\Throwable) {
                    continue;
                }
            } else {
                try {
                    $due = new \DateTimeImmutable($dueRaw);
                } catch (\Throwable) {
                    continue;
                }
            }

            if ($due < $today) {
                $overdue += $balance;
                $days = (int) $today->diff($due)->days;
                $oldest = $oldest === null ? $days : max($oldest, $days);
            }
        }

        return [round($total, 4), round($overdue, 4), $oldest];
    }

    /** @param array<string, mixed> $row */
    private static function number(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                return (float) $row[$key];
            }
        }

        return null;
    }

    private static function money(float $value): string
    {
        return '₹' . number_format($value, 2);
    }

    private function answer(
        string $decision,
        string $reason,
        string $checkedAt,
        ?float $creditLimit = null,
        ?float $outstanding = null,
        ?float $overdue = null,
        ?float $exposureAfter = null,
        ?int $oldestOverdueDays = null,
    ): array {
        return [
            'decision'            => $decision,
            'reason'              => $reason,
            'checked_at'          => $checkedAt,
            'credit_limit'        => $creditLimit,
            'outstanding'         => $outstanding,
            'overdue'             => $overdue,
            'exposure_after'      => $exposureAfter,
            'oldest_overdue_days' => $oldestOverdueDays,
        ];
    }
}
