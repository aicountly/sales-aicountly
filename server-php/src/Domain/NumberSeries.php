<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Context;
use Aicountly\Api\Db;

/**
 * Document numbers for the documents this product owns.
 *
 * Quotations, orders and RMAs are ours, so we number them. An INVOICE number is
 * not ours — Books assigns it from its own voucher series, because that series
 * is a statutory record and there can only be one of it.
 *
 * The number is allocated inside the caller's transaction with the row locked,
 * so two people pressing Save at the same instant get consecutive numbers
 * rather than the same one. Reading MAX(number)+1 would not: both would read
 * the same maximum.
 */
final class NumberSeries
{
    /**
     * Next number for a document kind: quotation | order | rma.
     *
     * The prefix and the financial year are part of the number because a
     * document number is only unique within a year, and an auditor reading
     * SO/2026-27/0004 should not have to ask which year it belongs to.
     */
    public static function next(Context $ctx, string $kind): string
    {
        [$table, $column, $prefixColumn, $default] = match ($kind) {
            'quotation' => ['sales_quotations', 'quotation_no', 'quotation_prefix', 'QT'],
            'order'     => ['sales_orders', 'order_no', 'order_prefix', 'SO'],
            'rma'       => ['sales_return_requests', 'rma_no', 'rma_prefix', 'RMA'],
            default     => throw new \InvalidArgumentException('Unknown document kind ' . $kind),
        };

        $prefix = (string) (Db::scalar(
            'SELECT ' . Db::quoteIdentifier($prefixColumn) . ' FROM sales_settings WHERE cmp_id = :cmp',
            ['cmp' => $ctx->cmpId],
        ) ?? $default);

        $stem = sprintf('%s/%d/', $prefix, $ctx->fyId);

        // FOR UPDATE on the matching rows: the lock is what makes the next
        // number exclusive for the length of the transaction.
        $highest = Db::scalar(
            'SELECT ' . Db::quoteIdentifier($column) . '
             FROM ' . Db::quoteIdentifier($table) . '
             WHERE cmp_id = :cmp AND fy_id = :fy AND ' . Db::quoteIdentifier($column) . ' LIKE :stem
             ORDER BY length(' . Db::quoteIdentifier($column) . ') DESC, ' . Db::quoteIdentifier($column) . ' DESC
             LIMIT 1
             FOR UPDATE',
            ['cmp' => $ctx->cmpId, 'fy' => $ctx->fyId, 'stem' => $stem . '%'],
        );

        $sequence = 1;
        if (is_string($highest) && preg_match('/(\d+)$/', $highest, $m) === 1) {
            $sequence = (int) $m[1] + 1;
        }

        return $stem . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
