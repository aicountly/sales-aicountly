<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Clients\InventoryClient;
use Aicountly\Api\Domain\FulfilmentService;
use Aicountly\Api\Domain\InvoiceRequestService;
use Aicountly\Api\Domain\OrderService;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

final class OrdersController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'order.view');

        $params = Http::listParams(['order_date', 'order_no', 'total_amount', 'status', 'committed_date', 'created_at'], 'order_date');
        $result = (new OrderService($ctx, $auth))->search([
            'status'              => Http::param('status'),
            'customer_account_id' => Http::intParam('customer_account_id'),
            'salesperson_id'      => Http::intParam('salesperson_id'),
            'territory_id'        => Http::intParam('territory_id'),
            'channel_id'          => Http::intParam('channel_id'),
            'from'                => Http::param('from'),
            'to'                  => Http::param('to'),
            'q'                   => $params['q'],
            'open_only'           => Http::param('open_only') === '1',
        ], $params['limit'], $params['offset'], $params['sort'], $params['order']);

        Http::list($result['rows'], $result['total'], $params['limit'], $params['offset']);
    }

    public static function show(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'order.view');

        $order = (new OrderService($ctx, $auth))->find((int) $id);
        if ($order === []) {
            Http::notFound('That order does not exist.');
        }

        Http::data($order);
    }

    public static function create(): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new OrderService($ctx, $auth))->create(Http::body()), 201);
    }

    public static function confirm(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new OrderService($ctx, $auth))->confirm((int) $id, Http::body()));
    }

    public static function reserve(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'fulfilment.request');
        Http::data((new OrderService($ctx, $auth))->requestReservation((int) $id));
    }

    public static function dispatch(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new FulfilmentService($ctx, $auth))->requestIssue((int) $id, Http::body()));
    }

    public static function invoice(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new InvoiceRequestService($ctx, $auth))->request((int) $id, Http::body()));
    }

    public static function retryInvoice(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new InvoiceRequestService($ctx, $auth))->retry((int) $id));
    }

    public static function cancel(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new OrderService($ctx, $auth))->cancel((int) $id, Http::body()));
    }

    /**
     * The order beside what Inventory currently says about it.
     *
     * Composed at read time, every time. This endpoint is the reason this
     * product needs no stock table: the answer is Inventory's, fetched now, and
     * shown next to our own progress figures rather than replacing them.
     */
    public static function fulfilmentStatus(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'order.view');

        $order = (new OrderService($ctx, $auth))->find((int) $id);
        if ($order === []) {
            Http::notFound('That order does not exist.');
        }

        $itemLines = array_values(array_filter($order['lines'], static fn (array $l) => $l['item_id'] !== null));
        $live = [];
        $inventoryReachable = true;

        if ($itemLines !== []) {
            $client = (new InventoryClient())->withSession($auth->sesKey());
            $check = $client->checkAvailability($ctx, array_map(static fn (array $l) => [
                'item_id'      => (int) $l['item_id'],
                'warehouse_id' => $l['warehouse_id'] === null ? null : (int) $l['warehouse_id'],
                'batch_id'     => $l['batch_id'] === null ? null : (int) $l['batch_id'],
                'unit_id'      => $l['unit_id'] === null ? null : (int) $l['unit_id'],
                'qty'          => max(0.0, (float) $l['ordered_qty'] - (float) $l['delivered_qty']),
            ], $itemLines));

            if ($check['ok']) {
                foreach ((array) ($check['body']['data'] ?? []) as $index => $row) {
                    $line = $itemLines[$index] ?? null;
                    if ($line !== null) {
                        $live[(int) $line['line_id']] = $row;
                    }
                }
            } else {
                // Say so rather than showing zeros that look like "out of stock".
                $inventoryReachable = false;
            }
        }

        Http::data([
            'order_id'            => (int) $order['order_id'],
            'status'              => $order['status'],
            'inventory_reachable' => $inventoryReachable,
            'lines'               => array_map(static fn (array $line) => [
                'line_id'        => (int) $line['line_id'],
                'line_no'        => (int) $line['line_no'],
                'item_id'        => $line['item_id'] === null ? null : (int) $line['item_id'],
                'ordered_qty'    => (float) $line['ordered_qty'],
                'delivered_qty'  => (float) $line['delivered_qty'],
                'invoiced_qty'   => (float) $line['invoiced_qty'],
                'returned_qty'   => (float) $line['returned_qty'],
                'outstanding_qty' => round((float) $line['ordered_qty'] - (float) $line['delivered_qty'], 4),
                // Straight from Inventory, unmodified and unstored.
                'live_availability' => $live[(int) $line['line_id']] ?? null,
            ], $order['lines']),
        ]);
    }
}
