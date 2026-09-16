<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\IntegrationCommand;
use Aicountly\Api\Permissions;

/**
 * Asking Books to raise the invoice.
 *
 * ONE orchestration path, on purpose. Sales posts to Books and stops there;
 * Books' own established contract with Inventory handles the stock side of a
 * sale. Posting to Books AND separately to Inventory for the same invoice is how
 * a half-posted sale happens — accounted for but not issued, or issued but not
 * accounted for — and then something has to reconcile the two, which is the
 * thing this architecture refuses to build.
 *
 * (The dispatch in FulfilmentService is a different event, not the same one: it
 * is a physical movement that happens at its own moment, against a challan, and
 * an invoice raised later settles it through Books' `stock_effect` handling.)
 *
 * What comes back is a voucher id. The invoice NUMBER, its VALUE, its TAX and
 * the RECEIVABLE it creates all stay in Books and are read from Books.
 */
final class InvoiceRequestService
{
    public const COMMAND_INVOICE = 'sales.invoice.request';

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    /**
     * @param array<string, mixed> $input {basis?: ordered|delivered|manual, lines?: [{line_id, qty}], invoice_date?}
     */
    public function request(int $orderId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'invoice.request');

        $orders = new OrderService($this->ctx, $this->auth);
        $order = $orders->find($orderId);
        if ($order === []) {
            Http::notFound('That order does not exist.');
        }
        if (in_array($order['status'], ['DRAFT', 'CANCELLED'], true)) {
            Http::conflict('Confirm the order before invoicing it.');
        }

        $settings = Db::first('SELECT default_invoice_basis FROM sales_settings WHERE cmp_id = :cmp', ['cmp' => $this->ctx->cmpId]);
        $basis = self::text($input['basis'] ?? null) ?? (string) ($settings['default_invoice_basis'] ?? 'delivered');
        if (!in_array($basis, ['ordered', 'delivered', 'manual'], true)) {
            Http::validationFailed('Invoice basis must be ordered, delivered or manual.', ['field' => 'basis']);
        }

        $lines = $this->resolveInvoiceLines($order, $basis, $input['lines'] ?? null);
        if ($lines === []) {
            Http::validationFailed(
                $basis === 'delivered'
                    ? 'Nothing has been delivered on this order yet, so there is nothing to invoice.'
                    : 'There is nothing left to invoice on this order.',
            );
        }

        $requestId = (int) Db::insert('sales_invoice_requests', [
            'cmp_id'          => $this->ctx->cmpId,
            'fy_id'           => $this->ctx->fyId,
            'bo_id'           => $this->ctx->boId,
            'order_id'        => $orderId,
            'basis'           => $basis,
            'status'          => 'REQUESTED',
            'requested_lines' => $lines,
            'requested_by'    => $this->auth->uuid,
        ], 'request_id');

        $command = IntegrationCommand::open(
            $this->ctx,
            'books',
            self::COMMAND_INVOICE,
            'invoice_request',
            $requestId,
            ['order_id' => $orderId, 'basis' => $basis, 'lines' => count($lines)],
        );
        $commandId = (int) $command['command_id'];
        IntegrationCommand::markPosting($commandId);

        $payload = $this->buildVoucherPayload($order, $lines, $input);

        $response = (new BooksClient())
            ->withService($this->auth->uuid)
            ->createAndPostVoucher($this->ctx, BooksClient::VCH_SALES, $payload, (string) $command['idempotency_key']);

        if (!$response['ok']) {
            $message = $response['error'] ?? 'Books did not accept the invoice.';
            $businessRefusal = in_array($response['status'], [409, 422], true);
            $businessRefusal ? IntegrationCommand::block($commandId, $message) : IntegrationCommand::fail($commandId, $message);

            Db::update('sales_invoice_requests', [
                'status'     => 'FAILED',
                'last_error' => mb_substr($message, 0, 480),
                'updated_at' => self::now(),
            ], ['request_id' => $requestId]);

            Http::error(
                $businessRefusal ? 409 : 502,
                $businessRefusal ? 'books_refused' : 'books_unavailable',
                // Plain language: the person raising an invoice is not the person
                // who will read a stack trace, and the one thing they need to
                // know is that they have not created a second invoice.
                $businessRefusal
                    ? $message
                    : 'Could not reach Books to raise this invoice. No invoice has been created — press Retry.',
                ['request_id' => $requestId, 'retryable' => !$businessRefusal, 'detail' => $message],
            );
        }

        $voucher = $response['body']['data'] ?? [];
        $voucherId = self::id($voucher['vch_txn_id'] ?? $voucher['voucher_id'] ?? $voucher['id'] ?? null);

        IntegrationCommand::complete($commandId, [
            'books_voucher_id'   => $voucherId,
            'books_voucher_uuid' => $voucher['vch_uuid'] ?? $voucher['voucher_uuid'] ?? null,
            'books_voucher_no'   => $voucher['vch_no'] ?? $voucher['voucher_no'] ?? null,
        ]);

        Db::update('sales_invoice_requests', [
            'status'             => 'POSTED',
            'books_voucher_id'   => $voucherId,
            'books_voucher_uuid' => self::text($voucher['vch_uuid'] ?? $voucher['voucher_uuid'] ?? null),
            'books_voucher_no'   => self::text($voucher['vch_no'] ?? $voucher['voucher_no'] ?? null),
            'updated_at'         => self::now(),
        ], ['request_id' => $requestId]);

        $this->applyInvoicedQuantities($orderId, $lines);

        Audit::record($this->ctx, $this->auth, 'order.invoiced', 'order', $orderId, null, [
            'books_voucher_id' => $voucherId,
            'basis'            => $basis,
        ]);

        return $orders->find($orderId);
    }

    /**
     * Retry a failed request on its ORIGINAL idempotency key.
     *
     * The key is the point: if Books wrote the voucher and the response was lost,
     * this replays the original answer rather than raising a second invoice.
     */
    public function retry(int $requestId): array
    {
        Permissions::assert($this->ctx, $this->auth, 'invoice.request');

        $request = Db::first(
            'SELECT * FROM sales_invoice_requests WHERE request_id = :id AND cmp_id = :cmp',
            ['id' => $requestId, 'cmp' => $this->ctx->cmpId],
        );
        if ($request === null) {
            Http::notFound('That invoice request does not exist.');
        }
        if ($request['status'] === 'POSTED') {
            Http::conflict('That invoice has already been raised in Books.');
        }

        return $this->request((int) $request['order_id'], [
            'basis' => $request['basis'],
            'lines' => array_map(
                static fn (array $line) => ['line_id' => $line['line_id'], 'qty' => $line['qty']],
                Db::jsonColumn($request['requested_lines']),
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $order
     * @return list<array<string, mixed>>
     */
    private function resolveInvoiceLines(array $order, string $basis, mixed $requestedLines): array
    {
        $byId = [];
        foreach ($order['lines'] as $line) {
            $byId[(int) $line['line_id']] = $line;
        }

        $out = [];

        if ($basis === 'manual' && is_array($requestedLines)) {
            foreach ($requestedLines as $requested) {
                if (!is_array($requested)) {
                    continue;
                }
                $line = $byId[(int) ($requested['line_id'] ?? 0)] ?? null;
                if ($line === null) {
                    continue;
                }
                $available = round((float) $line['ordered_qty'] - (float) $line['invoiced_qty'], 4);
                $qty = round((float) ($requested['qty'] ?? 0), 4);
                if ($qty <= 0) {
                    continue;
                }
                if ($qty > $available) {
                    Http::validationFailed(
                        sprintf('Line %d has only %s left to invoice.', (int) $line['line_no'], self::trimNumber($available)),
                        ['field' => 'lines', 'line_id' => (int) $line['line_id']],
                    );
                }
                $out[] = $this->invoiceLine($line, $qty);
            }

            return $out;
        }

        foreach ($order['lines'] as $line) {
            $ceiling = $basis === 'delivered' && !(bool) $line['is_service']
                ? (float) $line['delivered_qty']
                : (float) $line['ordered_qty'];
            $qty = round($ceiling - (float) $line['invoiced_qty'], 4);
            if ($qty > 0) {
                $out[] = $this->invoiceLine($line, $qty);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $line */
    private function invoiceLine(array $line, float $qty): array
    {
        return [
            'line_id'      => (int) $line['line_id'],
            'item_id'      => $line['item_id'] === null ? null : (int) $line['item_id'],
            'unit_id'      => $line['unit_id'] === null ? null : (int) $line['unit_id'],
            'warehouse_id' => $line['warehouse_id'] === null ? null : (int) $line['warehouse_id'],
            'batch_id'     => $line['batch_id'] === null ? null : (int) $line['batch_id'],
            'is_service'   => (bool) $line['is_service'],
            'description'  => $line['description'],
            'tax_cat_id'   => $line['tax_cat_id'] === null ? null : (int) $line['tax_cat_id'],
            'qty'          => $qty,
            'rate'         => (float) $line['rate'],
            'discount_pc'  => (float) $line['discount_pc'],
            'amount'       => round($qty * (float) $line['rate'] * (1 - (float) $line['discount_pc'] / 100), 4),
        ];
    }

    /**
     * The Books voucher payload.
     *
     * Note what is NOT here: no tax amount, no CGST/SGST/IGST split, no place-of-
     * supply determination, no rounding. Books computes all of that — it is the
     * product that files the return, and a second tax engine would eventually
     * disagree with the one that matters.
     *
     * @param array<string, mixed>      $order
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed>      $input
     * @return array<string, mixed>
     */
    private function buildVoucherPayload(array $order, array $lines, array $input): array
    {
        $inventoryLines = [];
        $serviceLines = [];

        foreach ($lines as $line) {
            if ($line['is_service'] || $line['item_id'] === null) {
                $serviceLines[] = [
                    'description' => $line['description'] ?? 'Service',
                    'amount'      => $line['amount'],
                    'tax_cat_id'  => $line['tax_cat_id'],
                    'source_line_ref' => (string) $line['line_id'],
                ];
                continue;
            }
            $inventoryLines[] = [
                'source_line_ref' => (string) $line['line_id'],
                'item_id'         => $line['item_id'],
                'unit_id'         => $line['unit_id'],
                'mc_id'           => $line['warehouse_id'],
                'batch_id'        => $line['batch_id'],
                'qty'             => $line['qty'],
                'rate'            => $line['rate'],
                'discount_pc'     => $line['discount_pc'],
                'amount'          => $line['amount'],
                'tax_cat_id'      => $line['tax_cat_id'],
                'description'     => $line['description'],
            ];
        }

        return [
            'vch_date'        => self::date($input['invoice_date'] ?? null),
            'party_acc_id'    => (int) $order['customer_account_id'],
            'narration'       => self::text($input['narration'] ?? null) ?? ('Against sales order ' . $order['order_no']),
            'reference_no'    => (string) $order['order_no'],
            'customer_po_ref' => $order['customer_po_ref'],
            'payment_terms'   => $order['payment_terms'],
            'currency_code'   => (string) $order['currency_code'],
            'exchange_rate'   => (float) $order['exchange_rate'],
            'bo_id'           => (int) $order['bo_id'],

            // Where this came from, so Books and anyone auditing can walk back
            // to our order without us copying anything of theirs.
            'source_app'           => 'sales',
            'source_document_type' => 'sales.order',
            'source_document_id'   => (int) $order['order_id'],
            'source_document_uuid' => (string) $order['order_uuid'],
            'source_document_no'   => (string) $order['order_no'],

            'inventory_lines' => $inventoryLines,
            'service_lines'   => $serviceLines,
            'shipping_address' => Db::jsonColumn($order['shipping_address'] ?? null) ?: null,
            'billing_address'  => Db::jsonColumn($order['billing_address'] ?? null) ?: null,
        ];
    }

    /** @param list<array<string, mixed>> $lines */
    private function applyInvoicedQuantities(int $orderId, array $lines): void
    {
        Db::transaction(function () use ($lines, $orderId) {
            foreach ($lines as $line) {
                Db::run(
                    'UPDATE sales_order_lines SET invoiced_qty = invoiced_qty + :qty, updated_at = :now
                     WHERE line_id = :id AND cmp_id = :cmp',
                    ['qty' => $line['qty'], 'now' => self::now(), 'id' => $line['line_id'], 'cmp' => $this->ctx->cmpId],
                );
            }

            $remaining = (float) Db::scalar(
                'SELECT COALESCE(SUM(GREATEST(ordered_qty - invoiced_qty, 0)), 0) FROM sales_order_lines WHERE order_id = :id',
                ['id' => $orderId],
            );
            if ($remaining <= 0) {
                Db::update('sales_orders', ['status' => 'CLOSED', 'updated_at' => self::now()], ['order_id' => $orderId, 'cmp_id' => $this->ctx->cmpId]);
            }
        });
    }

    private static function trimNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    private static function id(mixed $value): ?int
    {
        return ($value === null || $value === '' || (int) $value === 0) ? null : (int) $value;
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function date(mixed $value): string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1) {
            return trim($value);
        }

        return gmdate('Y-m-d');
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
