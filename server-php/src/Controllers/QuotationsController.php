<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Domain\QuotationService;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

final class QuotationsController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'quotation.view');

        $params = Http::listParams(['quotation_date', 'quotation_no', 'total_amount', 'status', 'created_at'], 'quotation_date');
        $result = (new QuotationService($ctx, $auth))->search([
            'status'              => Http::param('status'),
            'customer_account_id' => Http::intParam('customer_account_id'),
            'salesperson_id'      => Http::intParam('salesperson_id'),
            'from'                => Http::param('from'),
            'to'                  => Http::param('to'),
            'q'                   => $params['q'],
            'include_superseded'  => Http::param('include_superseded') === '1',
        ], $params['limit'], $params['offset'], $params['sort'], $params['order']);

        Http::list($result['rows'], $result['total'], $params['limit'], $params['offset']);
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

    public static function transition(string $id, string $action): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new QuotationService($ctx, $auth))->transition((int) $id, $action, Http::body()));
    }
}
