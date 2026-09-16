<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Domain\ReturnService;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

final class ReturnsController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'return.create');

        $params = Http::listParams(['return_date', 'rma_no', 'status', 'created_at'], 'return_date');
        $result = (new ReturnService($ctx, $auth))->search([
            'status'              => Http::param('status'),
            'customer_account_id' => Http::intParam('customer_account_id'),
            'q'                   => $params['q'],
        ], $params['limit'], $params['offset'], $params['sort'], $params['order']);

        Http::list($result['rows'], $result['total'], $params['limit'], $params['offset']);
    }

    public static function show(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'return.create');

        $return = (new ReturnService($ctx, $auth))->find((int) $id);
        if ($return === []) {
            Http::notFound('That return does not exist.');
        }

        Http::data($return);
    }

    public static function create(): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new ReturnService($ctx, $auth))->create(Http::body()), 201);
    }

    public static function approve(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new ReturnService($ctx, $auth))->approve((int) $id, Http::body()));
    }

    public static function receive(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new ReturnService($ctx, $auth))->receive((int) $id));
    }

    public static function creditNote(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Http::data((new ReturnService($ctx, $auth))->requestCreditNote((int) $id));
    }
}
