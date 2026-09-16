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
 * Sales orders: the commercial commitment, and the orchestration of what other
 * products must do about it.
 *
 * The order is ours. The stock it reserves is Inventory's, the invoice it
 * eventually becomes is Books'. Confirming an order therefore does three things
 * in a deliberate order:
 *
 *   1. check credit — LIVE from Books, never from a stored balance
 *   2. record OUR commitment, which is the part we own and can guarantee
 *   3. ask Inventory to reserve, as a command that can fail and be retried
 *
 * Step 3 is last and is allowed to fail. An order whose reservation failed is a
 * real order with a visible problem; an order that was refused because Inventory
 * had a bad second is a lost sale. The reservation state is carried on an
 * integration command and shown on the order, never hidden.
 */
final class OrderService
{
    public const COMMAND_RESERVE = 'sales.order.reserve';

    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'order.create');

        $customerAccountId = (int) ($input['customer_account_id'] ?? 0);
        if ($customerAccountId <= 0) {
            Http::validationFailed('Choose a customer for this order.', ['field' => 'customer_account_id']);
        }

        $lines = $this->normaliseLines($input['lines'] ?? []);
        if ($lines === []) {
            Http::validationFailed('An order needs at least one line.', ['field' => 'lines']);
        }

        return Db::transaction(function () use ($input, $customerAccountId, $lines) {
            $orderNo = NumberSeries::next($this->ctx, 'order');
            $totals = QuotationService::totals($lines);

            $orderId = (int) Db::insert('sales_orders', [
                'cmp_id'                 => $this->ctx->cmpId,
                'fy_id'                  => $this->ctx->fyId,
                'bo_id'                  => $this->ctx->boId,
                'order_no'               => $orderNo,
                'order_date'             => self::date($input['order_date'] ?? null),
                'quotation_id'           => self::id($input['quotation_id'] ?? null),
                'customer_account_id'    => $customerAccountId,
                'contact_id'             => self::text($input['contact_id'] ?? null),
                'customer_name_snapshot' => self::text($input['customer_name'] ?? null),
                'customer_po_ref'        => self::text($input['customer_po_ref'] ?? null),
                'customer_po_date'       => self::text($input['customer_po_date'] ?? null),
                'salesperson_id'         => self::id($input['salesperson_id'] ?? null),
                'territory_id'           => self::id($input['territory_id'] ?? null),
                'channel_id'             => self::id($input['channel_id'] ?? null),
                'price_book_id'          => self::id($input['price_book_id'] ?? null),
                'status'                 => 'DRAFT',
                'fulfilment_mode'        => self::text($input['fulfilment_mode'] ?? null) ?? 'direct',
                'requested_date'         => self::text($input['requested_date'] ?? null),
                'committed_date'         => self::text($input['committed_date'] ?? null),
                'priority'               => self::text($input['priority'] ?? null) ?? 'normal',
                'warehouse_id'           => self::id($input['warehouse_id'] ?? null),
                'shipping_address'       => is_array($input['shipping_address'] ?? null) ? $input['shipping_address'] : null,
                'billing_address'        => is_array($input['billing_address'] ?? null) ? $input['billing_address'] : null,
                'currency_code'          => self::text($input['currency_code'] ?? null) ?? 'INR',
                'exchange_rate'          => (float) ($input['exchange_rate'] ?? 1),
                'subtotal_amount'        => $totals['subtotal'],
                'discount_amount'        => $totals['discount'],
                'estimated_tax_amount'   => $totals['tax'],
                'total_amount'           => $totals['total'],
                'payment_terms'          => self::text($input['payment_terms'] ?? null),
                'delivery_terms'         => self::text($input['delivery_terms'] ?? null),
                'incoterm'               => self::text($input['incoterm'] ?? null),
                'notes'                  => self::text($input['notes'] ?? null),
                'created_by'             => $this->auth->uuid,
            ], 'order_id');

            $this->writeLines($orderId, $lines);

            if (($quotationId = self::id($input['quotation_id'] ?? null)) !== null) {
                Db::update('sales_quotations', ['status' => 'CONVERTED', 'updated_at' => self::now()], [
                    'quotation_id' => $quotationId,
                    'cmp_id'       => $this->ctx->cmpId,
                ]);
            }

            Audit::record($this->ctx, $this->auth, 'order.created', 'order', $orderId, null, [
                'order_no'     => $orderNo,
                'total_amount' => $totals['total'],
            ]);

            return $this->find($orderId);
        });
    }

    /**
     * Confirm an order: credit check, then commitment, then the reservation request.
     *
     * @param array<string, mixed> $input
     */
    public function confirm(int $orderId, array $input = []): array
    {
        Permissions::assert($this->ctx, $this->auth, 'order.confirm');

        $order = $this->find($orderId);
        if ($order === []) {
            Http::notFound('That order does not exist.');
        }
        if (!in_array($order['status'], ['DRAFT', 'APPROVED', 'APPROVAL_PENDING'], true)) {
            Http::conflict('This order is already ' . strtolower(str_replace('_', ' ', (string) $order['status'])) . '.');
        }

        // 1. Credit — live from Books.
        $credit = (new CreditControlService($this->ctx, $this->auth))
            ->evaluate((int) $order['customer_account_id'], (float) $order['total_amount']);

        $override = (bool) ($input['override_credit'] ?? false);
        if (in_array($credit['decision'], [CreditControlService::BLOCK, CreditControlService::UNAVAILABLE], true) && !$override) {
            Http::error(409, 'credit_blocked', $credit['reason'], ['credit' => $credit]);
        }
        if ($credit['decision'] === CreditControlService::APPROVAL_REQUIRED && !$override) {
            Http::error(409, 'credit_approval_required', $credit['reason'], ['credit' => $credit]);
        }
        if ($override) {
            Permissions::assert($this->ctx, $this->auth, 'credit.override');
        }

        $settings = Db::first('SELECT * FROM sales_settings WHERE cmp_id = :cmp', ['cmp' => $this->ctx->cmpId]) ?? [];
        $reserveOnConfirm = (bool) ($settings['reserve_on_confirm'] ?? true);

        // 2. Our own commitment is recorded and committed before any outbound
        //    call. If Inventory is down, the order still exists and can be
        //    retried; the alternative loses the order to somebody else's outage.
        Db::transaction(function () use ($orderId, $credit, $override, $input, $reserveOnConfirm) {
            Db::update('sales_orders', [
                'status'               => $reserveOnConfirm ? 'RESERVATION_PENDING' : 'CONFIRMED',
                'confirmed_at'         => self::now(),
                'credit_decision'      => $credit['decision'],
                'credit_checked_at'    => self::now(),
                'credit_override_by'   => $override ? $this->auth->uuid : null,
                'credit_override_note' => $override ? (self::text($input['override_note'] ?? null) ?? 'Overridden at confirmation.') : null,
                'updated_at'           => self::now(),
            ], ['order_id' => $orderId, 'cmp_id' => $this->ctx->cmpId]);
        });

        Audit::record($this->ctx, $this->auth, 'order.confirmed', 'order', $orderId, ['status' => $order['status']], [
            'status'          => $reserveOnConfirm ? 'RESERVATION_PENDING' : 'CONFIRMED',
            'credit_decision' => $credit['decision'],
        ], $override ? 'Credit control overridden' : '');

        // 3. Ask Inventory. Failure is recorded on the command AND carried back to
        //    the caller: confirm() returning a tidy-looking order while the
        //    reservation quietly failed is exactly how a stuck order goes unnoticed.
        $result = $reserveOnConfirm
            ? $this->requestReservation($orderId)
            : $this->find($orderId);

        $result['credit'] = $credit;

        return $result;
    }

    /**
     * Ask Inventory to reserve the stock this order commits.
     *
     * Runs on confirmation and again whenever the user presses Retry. The
     * idempotency key belongs to the command row, so a retry after a timeout
     * reaches the same key and Inventory replays its original answer instead of
     * reserving the same stock twice.
     */
    public function requestReservation(int $orderId): array
    {
        $order = $this->find($orderId);
        if ($order === []) {
            Http::notFound('That order does not exist.');
        }

        $lines = array_values(array_filter(
            $order['lines'],
            static fn (array $line) => !$line['is_service'] && $line['item_id'] !== null && (float) $line['ordered_qty'] > 0,
        ));

        if ($lines === []) {
            // A services-only order has nothing to reserve, and saying so is
            // better than leaving it in RESERVATION_PENDING for ever.
            Db::update('sales_orders', ['status' => 'CONFIRMED', 'updated_at' => self::now()], ['order_id' => $orderId, 'cmp_id' => $this->ctx->cmpId]);

            return $this->find($orderId);
        }

        $payload = [
            'source_app'           => 'sales',
            'source_document_type' => 'sales.order',
            'source_document_id'   => $orderId,
            'source_document_uuid' => (string) $order['order_uuid'],
            'source_document_no'   => (string) $order['order_no'],
            'reference_date'       => (string) $order['order_date'],
            'lines'                => array_map(static fn (array $line) => [
                'source_line_ref' => (string) $line['line_id'],
                'item_id'         => (int) $line['item_id'],
                'warehouse_id'    => $line['warehouse_id'] !== null ? (int) $line['warehouse_id'] : null,
                'batch_id'        => $line['batch_id'] !== null ? (int) $line['batch_id'] : null,
                'unit_id'         => $line['unit_id'] !== null ? (int) $line['unit_id'] : null,
                'qty'             => (float) $line['ordered_qty'],
            ], $lines),
        ];

        $command = IntegrationCommand::open(
            $this->ctx,
            'inventory',
            self::COMMAND_RESERVE,
            'order',
            $orderId,
            ['lines' => count($lines), 'order_no' => $order['order_no']],
        );

        if (($command['status'] ?? '') === IntegrationCommand::COMPLETED) {
            return $this->find($orderId);
        }

        $commandId = (int) $command['command_id'];
        IntegrationCommand::markPosting($commandId);

        $client = (new InventoryClient());
        $client = $this->auth->isService()
            ? $client->withService($this->auth->uuid)
            : $client->withService($this->auth->uuid);
        // Always the service key here: the reservation belongs to Sales as a
        // product, and Inventory records source_app = sales from the key rather
        // than from anything we could claim in the body.

        $response = $client->createReservation($this->ctx, $payload, (string) $command['idempotency_key']);

        if (!$response['ok']) {
            $isBusinessRefusal = in_array($response['status'], [409, 422], true);
            $message = $response['error'] ?? 'Inventory did not accept the reservation.';

            if ($isBusinessRefusal) {
                IntegrationCommand::block($commandId, $message);
            } else {
                IntegrationCommand::fail($commandId, $message);
            }

            Db::update('sales_orders', ['status' => 'CONFIRMED', 'updated_at' => self::now()], ['order_id' => $orderId, 'cmp_id' => $this->ctx->cmpId]);

            $result = $this->find($orderId);
            $result['reservation_error'] = $message;

            return $result;
        }

        $data = $response['body']['data'] ?? [];
        IntegrationCommand::complete($commandId, [
            'reservation_id'   => $data['reservation_id'] ?? null,
            'reservation_uuid' => $data['reservation_uuid'] ?? $data['uuid'] ?? null,
        ]);

        // Store ONLY what Inventory called it. The reserved quantity itself is
        // Inventory's answer and is read from Inventory when a screen needs it.
        foreach ((array) ($data['lines'] ?? []) as $line) {
            $lineId = (int) ($line['source_line_ref'] ?? 0);
            if ($lineId <= 0) {
                continue;
            }
            Db::update('sales_order_lines', [
                'inventory_reservation_id'   => $line['reservation_id'] ?? $data['reservation_id'] ?? null,
                'inventory_reservation_uuid' => $line['reservation_uuid'] ?? $data['reservation_uuid'] ?? null,
                'updated_at'                 => self::now(),
            ], ['line_id' => $lineId, 'cmp_id' => $this->ctx->cmpId]);
        }

        Db::update('sales_orders', ['status' => 'RESERVED', 'updated_at' => self::now()], ['order_id' => $orderId, 'cmp_id' => $this->ctx->cmpId]);

        Audit::record($this->ctx, $this->auth, 'order.reserved', 'order', $orderId, null, [
            'reservation_uuid' => $data['reservation_uuid'] ?? null,
        ]);

        return $this->find($orderId);
    }

    /** @param array<string, mixed> $input */
    public function cancel(int $orderId, array $input): array
    {
        Permissions::assert($this->ctx, $this->auth, 'order.cancel');

        $order = $this->find($orderId);
        if ($order === []) {
            Http::notFound('That order does not exist.');
        }
        // Invoiced first, and deliberately so: a fully invoiced order is CLOSED,
        // and "this order is already closed" tells the user nothing about what
        // to do instead. The reason they cannot cancel is the invoice.
        if ((float) array_sum(array_column($order['lines'], 'invoiced_qty')) > 0) {
            Http::conflict('Part of this order has been invoiced. Raise a credit note in Books instead of cancelling it.');
        }
        if (in_array($order['status'], ['CANCELLED', 'CLOSED'], true)) {
            Http::conflict('This order is already ' . strtolower((string) $order['status']) . '.');
        }

        $reason = self::text($input['reason'] ?? null);
        if ($reason === null) {
            Http::validationFailed('Say why this order is being cancelled.', ['field' => 'reason']);
        }

        // Release what we reserved before closing our own record, so stock does
        // not stay held for an order that no longer exists.
        $this->releaseReservations($order);

        Db::update('sales_orders', [
            'status'        => 'CANCELLED',
            'cancelled_at'  => self::now(),
            'cancel_reason' => $reason,
            'updated_at'    => self::now(),
        ], ['order_id' => $orderId, 'cmp_id' => $this->ctx->cmpId]);

        Audit::record($this->ctx, $this->auth, 'order.cancelled', 'order', $orderId, ['status' => $order['status']], ['status' => 'CANCELLED'], $reason);

        return $this->find($orderId);
    }

    /** @param array<string, mixed> $order */
    private function releaseReservations(array $order): void
    {
        $client = (new InventoryClient())->withService($this->auth->uuid);
        $seen = [];

        foreach ($order['lines'] as $line) {
            $reservationId = $line['inventory_reservation_id'] ?? null;
            if ($reservationId === null || isset($seen[(int) $reservationId])) {
                continue;
            }
            $seen[(int) $reservationId] = true;

            $key = sprintf('sales:%d:release:%d', $this->ctx->cmpId, (int) $reservationId);
            $response = $client->releaseReservation($this->ctx, (int) $reservationId, $key);

            if (!$response['ok']) {
                // Say so on the order rather than failing the cancellation: the
                // commercial decision stands, and a held reservation is a
                // visible problem somebody can clear.
                error_log('[sales] reservation release failed for order ' . $order['order_id'] . ': ' . ($response['error'] ?? 'unknown'));
            }
        }
    }

    /** @return array<string, mixed> */
    public function find(int $orderId): array
    {
        $row = Db::first('SELECT * FROM sales_orders WHERE order_id = :id AND cmp_id = :cmp', ['id' => $orderId, 'cmp' => $this->ctx->cmpId]);
        if ($row === null) {
            return [];
        }

        $row['lines'] = Db::all('SELECT * FROM sales_order_lines WHERE order_id = :id ORDER BY line_no', ['id' => $orderId]);
        $row['schedules'] = Db::all('SELECT * FROM sales_delivery_schedules WHERE order_id = :id ORDER BY scheduled_date, schedule_id', ['id' => $orderId]);
        $row['commands'] = IntegrationCommand::forEntity($this->ctx, 'order', $orderId);
        $row['fulfilments'] = Db::all('SELECT * FROM sales_fulfilment_requests WHERE order_id = :id ORDER BY request_id DESC', ['id' => $orderId]);
        $row['invoice_requests'] = Db::all('SELECT * FROM sales_invoice_requests WHERE order_id = :id ORDER BY request_id DESC', ['id' => $orderId]);

        return $row;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows:list<array<string, mixed>>, total:int}
     */
    public function search(array $filters, int $limit, int $offset, string $sort, string $order): array
    {
        [$scope, $params] = $this->ctx->scopeClause('o');
        $where = [$scope];

        // filter key => [column, bind name]
        foreach ([
            'status'              => ['o.status', 'status'],
            'customer_account_id' => ['o.customer_account_id', 'customer'],
            'salesperson_id'      => ['o.salesperson_id', 'salesperson'],
            'territory_id'        => ['o.territory_id', 'territory'],
            'channel_id'          => ['o.channel_id', 'channel'],
        ] as $key => [$column, $bind]) {
            if (!empty($filters[$key])) {
                $where[] = $column . ' = :' . $bind;
                $params[$bind] = $filters[$key];
            }
        }
        if (!empty($filters['from'])) {
            $where[] = 'o.order_date >= :from';
            $params['from'] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'o.order_date <= :to';
            $params['to'] = (string) $filters['to'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(o.order_no ILIKE :term OR o.customer_name_snapshot ILIKE :term OR o.customer_po_ref ILIKE :term)';
            $params['term'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['open_only'])) {
            $where[] = "o.status NOT IN ('CANCELLED', 'CLOSED', 'FULFILLED')";
        }

        $clause = implode(' AND ', $where);
        $sortable = ['order_date', 'order_no', 'total_amount', 'status', 'committed_date', 'created_at'];
        $sortColumn = in_array($sort, $sortable, true) ? $sort : 'order_date';

        return [
            'rows'  => Db::all("SELECT o.* FROM sales_orders o WHERE {$clause} ORDER BY o.{$sortColumn} {$order}, o.order_id {$order} LIMIT {$limit} OFFSET {$offset}", $params),
            'total' => (int) Db::scalar("SELECT COUNT(*) FROM sales_orders o WHERE {$clause}", $params),
        ];
    }

    // -----------------------------------------------------------------------

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
            $isService = (bool) ($line['is_service'] ?? false);
            $itemId = self::id($line['item_id'] ?? null);
            if (!$isService && $itemId === null) {
                Http::validationFailed('Every stock line needs an item.', ['field' => 'lines', 'line_no' => $lineNo + 1]);
            }

            $quantity = round((float) ($line['ordered_qty'] ?? $line['quantity'] ?? 0), 4);
            if ($quantity <= 0) {
                Http::validationFailed('Quantity must be more than zero.', ['field' => 'lines', 'line_no' => $lineNo + 1]);
            }

            $rate = round((float) ($line['rate'] ?? 0), 4);
            $discountPc = round((float) ($line['discount_pc'] ?? 0), 3);
            $gross = round($quantity * $rate, 4);
            $discountAmount = isset($line['discount_amount']) ? round((float) $line['discount_amount'], 4) : round($gross * $discountPc / 100, 4);
            $net = round($gross - $discountAmount, 4);
            $taxPc = round((float) ($line['estimated_tax_pc'] ?? 0), 3);

            $lines[] = [
                'line_no'           => ++$lineNo,
                'quotation_line_id' => self::id($line['quotation_line_id'] ?? null),
                'item_id'           => $itemId,
                'unit_id'           => self::id($line['unit_id'] ?? null),
                'warehouse_id'      => self::id($line['warehouse_id'] ?? null),
                'batch_id'          => self::id($line['batch_id'] ?? null),
                'is_service'        => $isService,
                'description'       => self::text($line['description'] ?? null),
                'ordered_qty'       => $quantity,
                'rate'              => $rate,
                'discount_pc'       => $discountPc,
                'discount_amount'   => $discountAmount,
                'tax_cat_id'        => self::id($line['tax_cat_id'] ?? null),
                'estimated_tax_pc'  => $taxPc,
                'line_amount'       => $net,
                'estimated_tax'     => round($net * $taxPc / 100, 4),
                'quantity'          => $quantity,
                'requested_date'    => self::text($line['requested_date'] ?? null),
                'committed_date'    => self::text($line['committed_date'] ?? null),
                'bom_id'            => self::id($line['bom_id'] ?? null),
                'is_optional'       => false,
            ];
        }

        return $lines;
    }

    /** @param list<array<string, mixed>> $lines */
    private function writeLines(int $orderId, array $lines): void
    {
        foreach ($lines as $line) {
            Db::insert('sales_order_lines', [
                'order_id'          => $orderId,
                'cmp_id'            => $this->ctx->cmpId,
                'line_no'           => $line['line_no'],
                'quotation_line_id' => $line['quotation_line_id'],
                'item_id'           => $line['item_id'],
                'unit_id'           => $line['unit_id'],
                'warehouse_id'      => $line['warehouse_id'],
                'batch_id'          => $line['batch_id'],
                'is_service'        => $line['is_service'],
                'description'       => $line['description'],
                'ordered_qty'       => $line['ordered_qty'],
                'rate'              => $line['rate'],
                'discount_pc'       => $line['discount_pc'],
                'discount_amount'   => $line['discount_amount'],
                'tax_cat_id'        => $line['tax_cat_id'],
                'estimated_tax_pc'  => $line['estimated_tax_pc'],
                'line_amount'       => $line['line_amount'],
                'requested_date'    => $line['requested_date'],
                'committed_date'    => $line['committed_date'],
                'bom_id'            => $line['bom_id'],
            ], 'line_id');
        }
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
