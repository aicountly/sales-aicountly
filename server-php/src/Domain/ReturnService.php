<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Clients\InventoryClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\IntegrationCommand;
use Aicountly\Api\Permissions;

/**
 * Returns and RMA.
 *
 * Three products, three responsibilities, and keeping them apart is the whole
 * design:
 *
 *   Sales      the commercial decision — may this come back, on what terms,
 *              who approved it, is it restocked or written off
 *   Inventory  the physical receipt and the condition of the goods
 *   Books      the credit note and what it does to the receivable
 *
 * We hold the decision and two reference uuids. We do not hold the stock
 * movement and we do not hold the credit.
 */
final class ReturnService
{
    public const COMMAND_RECEIPT = 'sales.return.receipt';
    public const COMMAND_CREDIT  = 'sales.return.credit_note';

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'return.create');

        $customerAccountId = (int) ($input['customer_account_id'] ?? 0);
        if ($customerAccountId <= 0) {
            Http::validationFailed('Choose the customer this return is from.', ['field' => 'customer_account_id']);
        }

        $lines = $this->normaliseLines($input['lines'] ?? []);
        if ($lines === []) {
            Http::validationFailed('A return needs at least one line.', ['field' => 'lines']);
        }

        return Db::transaction(function () use ($input, $customerAccountId, $lines) {
            $rmaNo = NumberSeries::next($this->ctx, 'rma');

            $returnId = (int) Db::insert('sales_return_requests', [
                'cmp_id'              => $this->ctx->cmpId,
                'fy_id'               => $this->ctx->fyId,
                'bo_id'               => $this->ctx->boId,
                'rma_no'              => $rmaNo,
                'return_date'         => self::date($input['return_date'] ?? null),
                'order_id'            => self::id($input['order_id'] ?? null),
                'customer_account_id' => $customerAccountId,
                'books_invoice_uuid'  => self::text($input['books_invoice_uuid'] ?? null),
                'books_invoice_id'    => self::id($input['books_invoice_id'] ?? null),
                'status'              => 'DRAFT',
                'reason_code'         => self::text($input['reason_code'] ?? null),
                'reason_note'         => self::text($input['reason_note'] ?? null),
                'resolution'          => self::text($input['resolution'] ?? null) ?? 'credit_note',
                'restock'             => (bool) ($input['restock'] ?? true),
                'created_by'          => $this->auth->uuid,
            ], 'return_id');

            foreach ($lines as $line) {
                Db::insert('sales_return_lines', [
                    'return_id'      => $returnId,
                    'cmp_id'         => $this->ctx->cmpId,
                    'line_no'        => $line['line_no'],
                    'order_line_id'  => $line['order_line_id'],
                    'item_id'        => $line['item_id'],
                    'unit_id'        => $line['unit_id'],
                    'warehouse_id'   => $line['warehouse_id'],
                    'batch_id'       => $line['batch_id'],
                    'return_qty'     => $line['return_qty'],
                    'rate'           => $line['rate'],
                    'line_amount'    => $line['line_amount'],
                    'condition_code' => $line['condition_code'],
                ], 'line_id');
            }

            Audit::record($this->ctx, $this->auth, 'return.created', 'return', $returnId, null, ['rma_no' => $rmaNo]);

            return $this->find($returnId);
        });
    }

    /** @param array<string, mixed> $input */
    public function approve(int $returnId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'return.approve');

        $return = $this->find($returnId);
        if ($return === []) {
            Http::notFound('That return does not exist.');
        }
        if ($return['status'] !== 'DRAFT' && $return['status'] !== 'SUBMITTED') {
            Http::conflict('This return is already ' . strtolower((string) $return['status']) . '.');
        }

        Db::update('sales_return_requests', [
            'status'      => 'APPROVED',
            'approved_by' => $this->auth->uuid,
            'approved_at' => self::now(),
            'updated_at'  => self::now(),
        ], ['return_id' => $returnId, 'cmp_id' => $this->ctx->cmpId]);

        Audit::record($this->ctx, $this->auth, 'return.approved', 'return', $returnId, ['status' => $return['status']], ['status' => 'APPROVED'], self::text($input['note'] ?? null) ?? '');

        return $this->find($returnId);
    }

    /**
     * Receive the goods back — Inventory's job, requested by us.
     *
     * A damaged return is still received: Inventory records the condition and
     * decides where it lands. Refusing to receive it would leave goods in the
     * building that no system knows about.
     */
    public function receive(int $returnId): array
    {
        Permissions::assert($this->ctx, $this->auth, 'return.approve');

        $return = $this->find($returnId);
        if ($return === []) {
            Http::notFound('That return does not exist.');
        }
        if ($return['status'] !== 'APPROVED') {
            Http::conflict('Approve the return before receiving the goods.');
        }

        $stockLines = array_values(array_filter($return['lines'], static fn (array $l) => $l['item_id'] !== null && (float) $l['return_qty'] > 0));
        if ($stockLines === []) {
            Db::update('sales_return_requests', ['status' => 'RECEIVED', 'updated_at' => self::now()], ['return_id' => $returnId, 'cmp_id' => $this->ctx->cmpId]);

            return $this->find($returnId);
        }

        $command = IntegrationCommand::open($this->ctx, 'inventory', self::COMMAND_RECEIPT, 'return', $returnId, ['lines' => count($stockLines)]);
        $commandId = (int) $command['command_id'];
        if (($command['status'] ?? '') === IntegrationCommand::COMPLETED) {
            return $this->find($returnId);
        }
        IntegrationCommand::markPosting($commandId);

        $payload = [
            'document_type'        => 'SALES_RETURN',
            'document_date'        => (string) $return['return_date'],
            'source_app'           => 'sales',
            'source_document_type' => 'sales.return',
            'source_document_id'   => $returnId,
            'source_document_uuid' => (string) $return['return_uuid'],
            'source_document_no'   => (string) $return['rma_no'],
            'party_ref'            => (string) $return['customer_account_id'],
            'narration'            => 'Customer return ' . $return['rma_no'],
            'lines'                => array_map(static fn (array $line) => [
                'source_line_ref' => (string) $line['line_id'],
                'item_id'         => (int) $line['item_id'],
                'warehouse_id'    => $line['warehouse_id'] === null ? null : (int) $line['warehouse_id'],
                'batch_id'        => $line['batch_id'] === null ? null : (int) $line['batch_id'],
                'unit_id'         => $line['unit_id'] === null ? null : (int) $line['unit_id'],
                'qty'             => (float) $line['return_qty'],
                'rate'            => (float) $line['rate'],
                'amount'          => (float) $line['line_amount'],
                'direction'       => 'in',
                'description'     => $line['condition_code'] === 'good' ? null : ('Returned ' . $line['condition_code']),
            ], $stockLines),
            'metadata' => [
                'restock'    => (bool) $return['restock'],
                'conditions' => array_map(static fn (array $l) => ['line_id' => $l['line_id'], 'condition' => $l['condition_code']], $stockLines),
            ],
        ];

        $response = (new InventoryClient())->withService($this->auth->uuid)->postDocument($this->ctx, $payload, (string) $command['idempotency_key']);

        if (!$response['ok']) {
            $message = $response['error'] ?? 'Inventory did not accept the return.';
            in_array($response['status'], [409, 422], true)
                ? IntegrationCommand::block($commandId, $message)
                : IntegrationCommand::fail($commandId, $message);
            Http::error(502, 'inventory_unavailable', $message, ['retryable' => true]);
        }

        $document = $response['body']['data'] ?? [];
        IntegrationCommand::complete($commandId, ['inventory_document_uuid' => $document['document_uuid'] ?? null]);

        Db::transaction(function () use ($returnId, $document, $stockLines) {
            Db::update('sales_return_requests', [
                'status'                 => 'RECEIVED',
                'inventory_document_uuid' => self::text($document['document_uuid'] ?? null),
                'updated_at'             => self::now(),
            ], ['return_id' => $returnId, 'cmp_id' => $this->ctx->cmpId]);

            foreach ($stockLines as $line) {
                Db::update('sales_return_lines', ['received_qty' => (float) $line['return_qty']], ['line_id' => (int) $line['line_id']]);
                if ($line['order_line_id'] !== null) {
                    Db::run(
                        'UPDATE sales_order_lines SET returned_qty = returned_qty + :qty, updated_at = :now WHERE line_id = :id',
                        ['qty' => (float) $line['return_qty'], 'now' => self::now(), 'id' => (int) $line['order_line_id']],
                    );
                }
            }
        });

        Audit::record($this->ctx, $this->auth, 'return.received', 'return', $returnId, null, ['inventory_document_uuid' => $document['document_uuid'] ?? null]);

        return $this->find($returnId);
    }

    /** Ask Books for the credit note. Books owns the credit and the receivable adjustment. */
    public function requestCreditNote(int $returnId): array
    {
        Permissions::assert($this->ctx, $this->auth, 'return.approve');

        $return = $this->find($returnId);
        if ($return === []) {
            Http::notFound('That return does not exist.');
        }
        if (!in_array($return['status'], ['RECEIVED', 'APPROVED'], true)) {
            Http::conflict('Receive the goods before raising the credit note.');
        }
        if ($return['books_credit_note_uuid'] !== null) {
            Http::conflict('A credit note has already been raised for this return.');
        }

        $command = IntegrationCommand::open($this->ctx, 'books', self::COMMAND_CREDIT, 'return', $returnId, ['rma_no' => $return['rma_no']]);
        $commandId = (int) $command['command_id'];
        IntegrationCommand::markPosting($commandId);

        $payload = [
            'vch_date'     => (string) $return['return_date'],
            'party_acc_id' => (int) $return['customer_account_id'],
            'narration'    => 'Credit note against return ' . $return['rma_no'],
            'reference_no' => (string) $return['rma_no'],
            'bo_id'        => (int) $return['bo_id'],

            'source_app'           => 'sales',
            'source_document_type' => 'sales.return',
            'source_document_id'   => $returnId,
            'source_document_uuid' => (string) $return['return_uuid'],

            // The invoice being credited, so Books can allocate against the
            // original bill rather than leaving an unapplied credit.
            'against_voucher_id'   => $return['books_invoice_id'] === null ? null : (int) $return['books_invoice_id'],
            'against_voucher_uuid' => $return['books_invoice_uuid'],

            'inventory_lines' => array_values(array_map(static fn (array $line) => [
                'source_line_ref' => (string) $line['line_id'],
                'item_id'         => $line['item_id'] === null ? null : (int) $line['item_id'],
                'unit_id'         => $line['unit_id'] === null ? null : (int) $line['unit_id'],
                'mc_id'           => $line['warehouse_id'] === null ? null : (int) $line['warehouse_id'],
                'qty'             => (float) $line['return_qty'],
                'rate'            => (float) $line['rate'],
                'amount'          => (float) $line['line_amount'],
            ], array_filter($return['lines'], static fn (array $l) => $l['item_id'] !== null))),
        ];

        $response = (new BooksClient())
            ->withService($this->auth->uuid)
            ->createAndPostVoucher($this->ctx, BooksClient::VCH_CREDIT_NOTE, $payload, (string) $command['idempotency_key']);

        if (!$response['ok']) {
            $message = $response['error'] ?? 'Books did not accept the credit note.';
            in_array($response['status'], [409, 422], true)
                ? IntegrationCommand::block($commandId, $message)
                : IntegrationCommand::fail($commandId, $message);
            Http::error(502, 'books_unavailable', 'Could not reach Books to raise the credit note. Nothing has been credited — press Retry.', ['retryable' => true, 'detail' => $message]);
        }

        $voucher = $response['body']['data'] ?? [];
        IntegrationCommand::complete($commandId, [
            'books_credit_note_id'   => $voucher['vch_txn_id'] ?? null,
            'books_credit_note_uuid' => $voucher['vch_uuid'] ?? null,
        ]);

        Db::update('sales_return_requests', [
            'status'                 => 'CREDITED',
            'books_credit_note_id'   => self::id($voucher['vch_txn_id'] ?? $voucher['voucher_id'] ?? null),
            'books_credit_note_uuid' => self::text($voucher['vch_uuid'] ?? $voucher['voucher_uuid'] ?? null),
            'updated_at'             => self::now(),
        ], ['return_id' => $returnId, 'cmp_id' => $this->ctx->cmpId]);

        Audit::record($this->ctx, $this->auth, 'return.credited', 'return', $returnId, null, ['books_credit_note_id' => $voucher['vch_txn_id'] ?? null]);

        return $this->find($returnId);
    }

    /** @return array<string, mixed> */
    public function find(int $returnId): array
    {
        $row = Db::first('SELECT * FROM sales_return_requests WHERE return_id = :id AND cmp_id = :cmp', ['id' => $returnId, 'cmp' => $this->ctx->cmpId]);
        if ($row === null) {
            return [];
        }
        $row['lines'] = Db::all('SELECT * FROM sales_return_lines WHERE return_id = :id ORDER BY line_no', ['id' => $returnId]);
        $row['commands'] = IntegrationCommand::forEntity($this->ctx, 'return', $returnId);

        return $row;
    }

    /** @return array{rows:list<array<string, mixed>>, total:int} */
    public function search(array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        [$scope, $params] = $this->ctx->scopeClause('r');
        $where = [$scope];

        if (!empty($filters['status'])) {
            $where[] = 'r.status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['customer_account_id'])) {
            $where[] = 'r.customer_account_id = :customer';
            $params['customer'] = (int) $filters['customer_account_id'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(r.rma_no ILIKE :term OR r.reason_note ILIKE :term)';
            $params['term'] = '%' . $filters['q'] . '%';
        }

        $clause = implode(' AND ', $where);
        $sortColumn = in_array($sort, ['return_date', 'rma_no', 'status', 'created_at'], true) ? $sort : 'return_date';

        return [
            'rows'  => Db::all("SELECT r.* FROM sales_return_requests r WHERE {$clause} ORDER BY r.{$sortColumn} {$order}, r.return_id {$order} LIMIT {$limit} OFFSET {$offset}", $params),
            'total' => (int) Db::scalar("SELECT COUNT(*) FROM sales_return_requests r WHERE {$clause}", $params),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function normaliseLines(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $lines = [];
        $lineNo = 0;
        foreach ($raw as $line) {
            if (!is_array($line)) {
                continue;
            }
            $qty = round((float) ($line['return_qty'] ?? $line['qty'] ?? 0), 4);
            if ($qty <= 0) {
                continue;
            }
            $rate = round((float) ($line['rate'] ?? 0), 4);
            $condition = self::text($line['condition_code'] ?? null) ?? 'good';
            if (!in_array($condition, ['good', 'damaged', 'expired', 'wrong_item'], true)) {
                Http::validationFailed('Condition must be good, damaged, expired or wrong_item.', ['field' => 'condition_code']);
            }

            $lines[] = [
                'line_no'        => ++$lineNo,
                'order_line_id'  => self::id($line['order_line_id'] ?? null),
                'item_id'        => self::id($line['item_id'] ?? null),
                'unit_id'        => self::id($line['unit_id'] ?? null),
                'warehouse_id'   => self::id($line['warehouse_id'] ?? null),
                'batch_id'       => self::id($line['batch_id'] ?? null),
                'return_qty'     => $qty,
                'rate'           => $rate,
                'line_amount'    => round($qty * $rate, 4),
                'condition_code' => $condition,
            ];
        }

        return $lines;
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
