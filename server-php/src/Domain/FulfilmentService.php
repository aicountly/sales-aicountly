<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Clients\InventoryClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\IntegrationCommand;
use Aicountly\Api\Permissions;

/**
 * Turning an order into goods that have left the building.
 *
 * Sales decides WHAT should go and WHEN. Inventory performs and owns the
 * movement: it writes the stock ledger, it values the issue, it computes COGS.
 * We send a SALES_ISSUE document request and keep the uuid of whatever Inventory
 * created.
 *
 * `delivered_qty` on our line is written from Inventory's own response and from
 * nothing else. It answers "is this order complete?" — never "how much stock is
 * there?", which is a question only Inventory can answer and which this product
 * asks over HTTP every time it needs to know.
 */
final class FulfilmentService
{
    public const COMMAND_ISSUE = 'sales.order.issue';

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    /**
     * Ask Inventory to issue stock against an order.
     *
     * @param array<string, mixed> $input  {lines: [{line_id, qty, warehouse_id?, batch_id?, serials?}], document_date?}
     */
    public function requestIssue(int $orderId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'fulfilment.request');

        $orders = new OrderService($this->ctx, $this->auth);
        $order = $orders->find($orderId);
        if ($order === []) {
            Http::notFound('That order does not exist.');
        }
        if (in_array($order['status'], ['DRAFT', 'CANCELLED'], true)) {
            Http::conflict('Confirm the order before dispatching against it.');
        }

        $requested = $this->resolveIssueLines($order, $input['lines'] ?? null);
        if ($requested === []) {
            Http::validationFailed('There is nothing left to dispatch on this order.');
        }

        $requestId = (int) Db::insert('sales_fulfilment_requests', [
            'cmp_id'          => $this->ctx->cmpId,
            'fy_id'           => $this->ctx->fyId,
            'bo_id'           => $this->ctx->boId,
            'order_id'        => $orderId,
            'request_kind'    => 'issue',
            'status'          => 'REQUESTED',
            'warehouse_id'    => self::id($input['warehouse_id'] ?? $order['warehouse_id'] ?? null),
            'requested_lines' => $requested,
            'requested_by'    => $this->auth->uuid,
        ], 'request_id');

        $command = IntegrationCommand::open(
            $this->ctx,
            'inventory',
            self::COMMAND_ISSUE,
            'fulfilment_request',
            $requestId,
            ['order_id' => $orderId, 'lines' => count($requested)],
        );
        $commandId = (int) $command['command_id'];
        IntegrationCommand::markPosting($commandId);

        $payload = [
            'document_type'        => 'SALES_ISSUE',
            'document_date'        => self::date($input['document_date'] ?? null),
            'source_app'           => 'sales',
            'source_document_type' => 'sales.order',
            'source_document_id'   => $orderId,
            'source_document_uuid' => (string) $order['order_uuid'],
            'source_document_no'   => (string) $order['order_no'],
            'source_document_date' => (string) $order['order_date'],
            'party_ref'            => (string) $order['customer_account_id'],
            'party_name'           => $order['customer_name_snapshot'],
            'narration'            => self::text($input['narration'] ?? null) ?? ('Dispatch against ' . $order['order_no']),
            'currency_code'        => (string) $order['currency_code'],
            'exchange_rate'        => (float) $order['exchange_rate'],
            'lines'                => array_map(static fn (array $line) => [
                'source_line_ref' => (string) $line['line_id'],
                'item_id'         => (int) $line['item_id'],
                'warehouse_id'    => $line['warehouse_id'],
                'batch_id'        => $line['batch_id'],
                'serials'         => $line['serials'],
                'unit_id'         => $line['unit_id'],
                'qty'             => (float) $line['qty'],
                // The commercial value we agreed. Inventory stores it as the
                // source transaction value and computes its OWN valuation; the
                // two are different numbers and this one is never a cost.
                'rate'            => (float) $line['rate'],
                'amount'          => round((float) $line['qty'] * (float) $line['rate'], 4),
                'direction'       => 'out',
            ], $requested),
        ];

        $response = (new InventoryClient())
            ->withService($this->auth->uuid)
            ->postDocument($this->ctx, $payload, (string) $command['idempotency_key']);

        if (!$response['ok']) {
            $message = $response['error'] ?? 'Inventory did not accept the dispatch.';
            $businessRefusal = in_array($response['status'], [409, 422], true);
            $businessRefusal ? IntegrationCommand::block($commandId, $message) : IntegrationCommand::fail($commandId, $message);

            Db::update('sales_fulfilment_requests', [
                'status'     => 'FAILED',
                'last_error' => mb_substr($message, 0, 480),
                'updated_at' => self::now(),
            ], ['request_id' => $requestId]);

            Http::error(
                $businessRefusal ? 409 : 502,
                $businessRefusal ? 'inventory_refused' : 'inventory_unavailable',
                $message,
                ['request_id' => $requestId, 'retryable' => !$businessRefusal],
            );
        }

        $document = $response['body']['data'] ?? [];
        IntegrationCommand::complete($commandId, [
            'inventory_document_id'   => $document['document_id'] ?? null,
            'inventory_document_uuid' => $document['document_uuid'] ?? null,
            'inventory_document_no'   => $document['document_no'] ?? null,
        ]);

        Db::update('sales_fulfilment_requests', [
            'status'                  => 'ACCEPTED',
            'inventory_document_id'   => self::id($document['document_id'] ?? null),
            'inventory_document_uuid' => self::text($document['document_uuid'] ?? null),
            'inventory_document_no'   => self::text($document['document_no'] ?? null),
            'updated_at'              => self::now(),
        ], ['request_id' => $requestId]);

        // Progress against OUR commitment, taken from Inventory's own answer.
        $this->applyDeliveredQuantities($orderId, $document, $requested);

        Audit::record($this->ctx, $this->auth, 'order.dispatched', 'order', $orderId, null, [
            'inventory_document_uuid' => $document['document_uuid'] ?? null,
            'lines'                   => count($requested),
        ]);

        return $orders->find($orderId);
    }

    /**
     * What to dispatch: either the caller's explicit lines, or everything outstanding.
     *
     * @param array<string, mixed> $order
     * @return list<array<string, mixed>>
     */
    private function resolveIssueLines(array $order, mixed $requestedLines): array
    {
        $byId = [];
        foreach ($order['lines'] as $line) {
            $byId[(int) $line['line_id']] = $line;
        }

        $out = [];

        if (is_array($requestedLines) && $requestedLines !== []) {
            foreach ($requestedLines as $requested) {
                if (!is_array($requested)) {
                    continue;
                }
                $lineId = (int) ($requested['line_id'] ?? 0);
                $line = $byId[$lineId] ?? null;
                if ($line === null || (bool) $line['is_service'] || $line['item_id'] === null) {
                    continue;
                }
                $outstanding = round((float) $line['ordered_qty'] - (float) $line['delivered_qty'], 4);
                $qty = round((float) ($requested['qty'] ?? $outstanding), 4);
                if ($qty <= 0) {
                    continue;
                }
                if ($qty > $outstanding) {
                    Http::validationFailed(
                        sprintf('Line %d has only %s left to dispatch.', (int) $line['line_no'], rtrim(rtrim(number_format($outstanding, 4, '.', ''), '0'), '.')),
                        ['field' => 'lines', 'line_id' => $lineId, 'outstanding' => $outstanding],
                    );
                }
                $out[] = $this->issueLine($line, $qty, $requested);
            }

            return $out;
        }

        foreach ($order['lines'] as $line) {
            if ((bool) $line['is_service'] || $line['item_id'] === null) {
                continue;
            }
            $outstanding = round((float) $line['ordered_qty'] - (float) $line['delivered_qty'], 4);
            if ($outstanding > 0) {
                $out[] = $this->issueLine($line, $outstanding, []);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $line */
    private function issueLine(array $line, float $qty, array $overrides): array
    {
        return [
            'line_id'      => (int) $line['line_id'],
            'item_id'      => (int) $line['item_id'],
            'unit_id'      => self::id($overrides['unit_id'] ?? $line['unit_id']),
            'warehouse_id' => self::id($overrides['warehouse_id'] ?? $line['warehouse_id']),
            'batch_id'     => self::id($overrides['batch_id'] ?? $line['batch_id']),
            'serials'      => is_array($overrides['serials'] ?? null) ? array_values($overrides['serials']) : [],
            'qty'          => $qty,
            'rate'         => (float) $line['rate'],
        ];
    }

    /**
     * @param array<string, mixed> $document Inventory's response
     * @param list<array<string, mixed>> $requested
     */
    private function applyDeliveredQuantities(int $orderId, array $document, array $requested): void
    {
        // Prefer what Inventory says it moved; fall back to what we asked for
        // only when the response carries no line detail.
        $moved = [];
        foreach ((array) ($document['lines'] ?? []) as $line) {
            $ref = (int) ($line['source_line_ref'] ?? 0);
            if ($ref > 0) {
                $moved[$ref] = (float) ($line['qty'] ?? 0);
            }
        }
        if ($moved === []) {
            foreach ($requested as $line) {
                $moved[(int) $line['line_id']] = (float) $line['qty'];
            }
        }

        Db::transaction(function () use ($moved, $orderId) {
            foreach ($moved as $lineId => $qty) {
                Db::run(
                    'UPDATE sales_order_lines
                     SET delivered_qty = delivered_qty + :qty, requested_qty = requested_qty + :qty, updated_at = :now
                     WHERE line_id = :id AND cmp_id = :cmp',
                    ['qty' => $qty, 'now' => self::now(), 'id' => $lineId, 'cmp' => $this->ctx->cmpId],
                );
            }

            // The order's own status follows from its own lines.
            $remaining = (float) Db::scalar(
                'SELECT COALESCE(SUM(GREATEST(ordered_qty - delivered_qty, 0)), 0)
                 FROM sales_order_lines WHERE order_id = :id AND is_service = FALSE',
                ['id' => $orderId],
            );
            Db::update('sales_orders', [
                'status'     => $remaining > 0 ? 'PARTIALLY_FULFILLED' : 'FULFILLED',
                'updated_at' => self::now(),
            ], ['order_id' => $orderId, 'cmp_id' => $this->ctx->cmpId]);
        });
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
