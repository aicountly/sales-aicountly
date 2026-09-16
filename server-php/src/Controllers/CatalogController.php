<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Clients\InventoryClient;
use Aicountly\Api\Domain\CreditControlService;
use Aicountly\Api\Domain\PricingService;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * Read-through to the products that own this data.
 *
 * EVERY handler here is a pass-through. Nothing it returns is written to this
 * product's database, in any table, under any name, for any length of time.
 * They exist for two reasons and no others:
 *
 *   1. one same-origin call from the browser instead of several cross-origin
 *      ones, so the portal session key never leaves this origin;
 *   2. one place to apply this product's own permission rules on top — a
 *      salesperson without `margin.view` gets the item without its cost.
 *
 * If a future change makes one of these write a row, the architecture has been
 * broken and the release-blocking ownership test in docs/ will catch it.
 */
final class CatalogController extends Controller
{
    public static function items(): void
    {
        [$auth, $ctx] = self::enter();
        $result = (new InventoryClient())->withSession($auth->sesKey())->items($ctx, [
            'q'           => Http::param('q'),
            'limit'       => Http::intParam('limit', 50),
            'offset'      => Http::intParam('offset', 0),
            'item_grp_id' => Http::intParam('item_grp_id'),
            'is_active'   => Http::param('is_active'),
        ]);

        self::relay($result, $ctx, $auth);
    }

    public static function searchItems(): void
    {
        [$auth, $ctx] = self::enter();
        $term = (string) (Http::param('q') ?? '');
        if (mb_strlen($term) < 2) {
            Http::list([], 0, 20, 0);
        }

        self::relay(
            (new InventoryClient())->withSession($auth->sesKey())->searchItems($ctx, $term, Http::intParam('limit', 20) ?? 20),
            $ctx,
            $auth,
        );
    }

    public static function item(string $id): void
    {
        [$auth, $ctx] = self::enter();
        self::relay((new InventoryClient())->withSession($auth->sesKey())->item($ctx, (int) $id), $ctx, $auth);
    }

    public static function availability(): void
    {
        [$auth, $ctx] = self::enter();
        $itemId = Http::intParam('item_id');
        if ($itemId === null) {
            Http::validationFailed('item_id is required.', ['field' => 'item_id']);
        }

        self::relay(
            (new InventoryClient())->withSession($auth->sesKey())
                ->availability($ctx, $itemId, Http::intParam('warehouse_id'), Http::intParam('batch_id')),
            $ctx,
            $auth,
        );
    }

    public static function checkAvailability(): void
    {
        [$auth, $ctx] = self::enter();
        $lines = Http::body()['lines'] ?? [];
        if (!is_array($lines) || $lines === []) {
            Http::validationFailed('Send at least one line to check.', ['field' => 'lines']);
        }

        self::relay((new InventoryClient())->withSession($auth->sesKey())->checkAvailability($ctx, $lines), $ctx, $auth);
    }

    public static function warehouses(): void
    {
        [$auth, $ctx] = self::enter();
        self::relay((new InventoryClient())->withSession($auth->sesKey())->warehouses($ctx), $ctx, $auth);
    }

    public static function uoms(): void
    {
        [$auth, $ctx] = self::enter();
        self::relay((new InventoryClient())->withSession($auth->sesKey())->uoms($ctx), $ctx, $auth);
    }

    /** Customers are Books' party ledgers; identity detail comes from Contacts where it is deployed. */
    public static function customers(): void
    {
        [$auth, $ctx] = self::enter();
        self::relay((new BooksClient())->withSession($auth->sesKey())->accounts($ctx, [
            'q'          => Http::param('q'),
            'limit'      => Http::intParam('limit', 50),
            'offset'     => Http::intParam('offset', 0),
            'party_type' => 'debtor',
            'nature'     => 'sundry_debtors',
        ]), $ctx, $auth);
    }

    public static function taxCategories(): void
    {
        [$auth, $ctx] = self::enter();
        self::relay((new BooksClient())->withSession($auth->sesKey())->taxCategories($ctx), $ctx, $auth);
    }

    /** Live credit position for a customer — limit, outstanding, overdue, and our verdict. */
    public static function customerCredit(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'order.view');

        Http::data((new CreditControlService($ctx, $auth))
            ->evaluate((int) $id, (float) (Http::param('order_value') ?? 0)));
    }

    /** What we should charge for an item, and whether the quoted rate breaches policy. */
    public static function price(): void
    {
        [$auth, $ctx] = self::enter();
        $body = Http::body();

        $itemId = (int) ($body['item_id'] ?? 0);
        if ($itemId <= 0) {
            Http::validationFailed('item_id is required.', ['field' => 'item_id']);
        }
        $quantity = (float) ($body['quantity'] ?? 1);

        $pricing = new PricingService($ctx, $auth);
        $resolved = $pricing->resolveRate(
            $itemId,
            $quantity,
            isset($body['customer_account_id']) ? (int) $body['customer_account_id'] : null,
            isset($body['channel_id']) ? (int) $body['channel_id'] : null,
            isset($body['territory_id']) ? (int) $body['territory_id'] : null,
            isset($body['price_book_id']) ? (int) $body['price_book_id'] : null,
        );

        $answer = [
            'item_id'       => $itemId,
            'quantity'      => $quantity,
            'rate'          => $resolved['rate'],
            'price_book_id' => $resolved['price_book_id'],
            'source'        => $resolved['source'],
        ];

        if (isset($body['proposed_rate'])) {
            $check = $pricing->checkLine(
                $itemId,
                (float) $body['proposed_rate'],
                (float) ($body['discount_pc'] ?? 0),
                $resolved['min_margin_pc'],
                (float) ($body['max_discount_pc'] ?? 100),
            );
            $answer['check'] = [
                'ok'       => $check['ok'],
                'breaches' => $check['breaches'],
            ];
            // Cost and margin are commercially sensitive: a salesperson without
            // margin.view sees whether the line passes, never what it cost.
            if (Permissions::allows($ctx, $auth, 'margin.view')) {
                $answer['check']['cost'] = $check['cost'];
                $answer['check']['margin_pc'] = $check['margin_pc'];
            }
        }

        Http::data($answer);
    }

    /**
     * Hand the owning product's answer back, unchanged, with its own status.
     *
     * An upstream failure is reported as an upstream failure. Turning it into an
     * empty list would make "Inventory is down" look like "you have no items",
     * and somebody would then create the item that already exists.
     *
     * @param array{ok:bool, status:int, body:?array, error:?string} $result
     */
    private static function relay(array $result, $ctx, $auth): never
    {
        if (!$result['ok']) {
            $status = $result['status'] === 0 ? 503 : $result['status'];
            Http::error(
                $status,
                $status === 503 ? 'upstream_unavailable' : 'upstream_error',
                $status === 503
                    ? 'Could not reach the app that owns this information. Please retry.'
                    : (string) ($result['error'] ?? 'The request was refused.'),
            );
        }

        $body = $result['body'] ?? ['data' => []];

        // Strip cost from any relayed payload unless this user may see margin.
        if (!Permissions::allows($ctx, $auth, 'margin.view')) {
            $body = self::stripCostFields($body);
        }

        Http::json(200, $body);
    }

    /** @param array<string, mixed> $payload */
    private static function stripCostFields(array $payload): array
    {
        $sensitive = ['unit_cost', 'cost', 'cost_rate', 'valuation_rate', 'valuation_amount', 'purchase_rate', 'last_purchase_rate', 'margin_pc'];

        $walk = static function (array $node) use (&$walk, $sensitive): array {
            foreach ($node as $key => $value) {
                if (is_string($key) && in_array($key, $sensitive, true)) {
                    unset($node[$key]);
                    continue;
                }
                if (is_array($value)) {
                    $node[$key] = $walk($value);
                }
            }

            return $node;
        };

        return $walk($payload);
    }
}
