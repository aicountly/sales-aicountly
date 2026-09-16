<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Domain\OrderService;
use Aicountly\Api\Domain\QuotationService;
use Aicountly\Api\Export;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

final class QuotationsController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'quotation.view');

        $params = Http::listParams(
            ['quotation_date', 'quotation_no', 'total_amount', 'status', 'valid_until', 'created_at'],
            'quotation_date',
        );
        $result = (new QuotationService($ctx, $auth))->search(self::filters(), $params['limit'], $params['offset'], $params['sort'], $params['order']);

        Http::list($result['rows'], $result['total'], $params['limit'], $params['offset'], [
            'filters' => self::filters(),
        ]);
    }

    /**
     * The same list as a CSV.
     *
     * Same filters, same permission check, same predicate — an export that
     * ignored the filters on screen would hand someone a file that does not
     * match what they were looking at, and they would not find out until after
     * they had sent it on.
     */
    public static function export(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'quotation.view');
        Permissions::assert($ctx, $auth, 'reports.view');

        $result = (new QuotationService($ctx, $auth))->search(
            self::filters(),
            Export::MAX_ROWS,
            0,
            Http::param('sort') ?? 'quotation_date',
            strtolower((string) (Http::param('order') ?? 'desc')) === 'asc' ? 'ASC' : 'DESC',
        );

        Export::csv('quotations', [
            'quotation_no'    => 'Quotation',
            'revision_no'     => 'Revision',
            'quotation_date'  => 'Date',
            'customer_name_snapshot' => 'Customer',
            'effective_status' => 'Status',
            'valid_until'     => 'Valid until',
            'currency_code'   => 'Currency',
            'subtotal_amount' => 'Subtotal',
            'discount_amount' => 'Discount',
            'estimated_tax_amount' => 'Estimated tax',
            'total_amount'    => 'Total',
            'converted_order_no' => 'Order',
        ], $result['rows'], $result['total']);
    }

    public static function show(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'quotation.view');

        $quotation = (new QuotationService($ctx, $auth))->find((int) $id);
        if ($quotation === []) {
            Http::notFound('That quotation does not exist.');
        }

        Http::data($quotation);
    }

    public static function create(): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new QuotationService($ctx, $auth))->create(Http::body()), 201);
    }

    public static function revise(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new QuotationService($ctx, $auth))->revise((int) $id, Http::body()), 201);
    }

    /**
     * Turn this quotation into a sales order.
     *
     * The payload is built on the server from the quotation as stored, and the
     * conversion is idempotent: asking twice returns the order that already
     * exists rather than committing the company to the same goods a second time.
     */
    public static function convert(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'order.create');

        $orders = new OrderService($ctx, $auth);
        $existing = $orders->orderForQuotation((int) $id);
        if ($existing !== null) {
            Http::data($existing + ['already_converted' => true]);
        }

        $payload = (new QuotationService($ctx, $auth))->conversionPayload((int) $id);
        $body = Http::body();

        // The caller may set the dates and the warehouse; it may not restate the
        // prices. Those were agreed on the quotation.
        foreach (['order_date', 'requested_date', 'committed_date', 'warehouse_id', 'customer_po_ref', 'customer_po_date', 'fulfilment_mode', 'priority'] as $key) {
            if (isset($body[$key]) && $body[$key] !== '') {
                $payload[$key] = $body[$key];
            }
        }

        Http::data($orders->create($payload), 201);
    }

    public static function transition(string $id, string $action): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new QuotationService($ctx, $auth))->transition((int) $id, $action, Http::body()));
    }

    /** @return array<string, mixed> */
    private static function filters(): array
    {
        return [
            'status'              => Http::param('status'),
            'customer_account_id' => Http::intParam('customer_account_id'),
            'salesperson_id'      => Http::intParam('salesperson_id'),
            'from'                => Http::param('from'),
            'to'                  => Http::param('to'),
            'as_of'               => Http::param('as_of'),
            'q'                   => trim((string) (Http::param('q') ?? '')),
            'open'                => Http::param('open') === '1',
            'expired'             => Http::param('expired') === '1',
            'expiring_days'       => Http::intParam('expiring_days'),
            'include_superseded'  => Http::param('include_superseded') === '1',
        ];
    }
}
