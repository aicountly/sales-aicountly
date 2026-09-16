<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * Sales-owned masters: territories, channels, sales people, price books.
 *
 * Note what is absent: no items, no customers, no warehouses. Those belong to
 * Inventory, Contacts/Books and Inventory respectively, and are created through
 * their APIs — see CatalogController.
 */
final class MastersController extends Controller
{
    public static function listTerritories(): void
    {
        [$auth, $ctx] = self::enter();
        Http::data(Db::all(
            'SELECT * FROM sales_territories WHERE cmp_id = :cmp ORDER BY territory_name',
            ['cmp' => $ctx->cmpId],
        ));
    }

    public static function createTerritory(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'territory.manage');

        $body = Http::body();
        $code = self::requireText($body['territory_code'] ?? null, 'territory_code');
        $name = self::requireText($body['territory_name'] ?? null, 'territory_name');

        self::guardDuplicate('sales_territories', 'territory_code', $code, $ctx->cmpId, 'A territory with that code already exists.');

        $id = (int) Db::insert('sales_territories', [
            'cmp_id'         => $ctx->cmpId,
            'bo_id'          => $ctx->boId,
            'territory_code' => $code,
            'territory_name' => $name,
            'parent_id'      => self::id($body['parent_id'] ?? null),
        ], 'territory_id');

        Audit::record($ctx, $auth, 'territory.created', 'territory', $id, null, ['code' => $code]);
        Http::data(Db::first('SELECT * FROM sales_territories WHERE territory_id = :id', ['id' => $id]) ?? [], 201);
    }

    public static function listChannels(): void
    {
        [$auth, $ctx] = self::enter();
        Http::data(Db::all('SELECT * FROM sales_channels WHERE cmp_id = :cmp ORDER BY channel_name', ['cmp' => $ctx->cmpId]));
    }

    public static function createChannel(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'territory.manage');

        $body = Http::body();
        $code = self::requireText($body['channel_code'] ?? null, 'channel_code');
        $name = self::requireText($body['channel_name'] ?? null, 'channel_name');
        $kind = self::text($body['channel_kind'] ?? null) ?? 'direct';

        if (!in_array($kind, ['direct', 'dealer', 'distributor', 'retail', 'online', 'export'], true)) {
            Http::validationFailed('Channel kind must be direct, dealer, distributor, retail, online or export.', ['field' => 'channel_kind']);
        }
        self::guardDuplicate('sales_channels', 'channel_code', $code, $ctx->cmpId, 'A channel with that code already exists.');

        $id = (int) Db::insert('sales_channels', [
            'cmp_id'       => $ctx->cmpId,
            'channel_code' => $code,
            'channel_name' => $name,
            'channel_kind' => $kind,
        ], 'channel_id');

        Audit::record($ctx, $auth, 'channel.created', 'channel', $id, null, ['code' => $code]);
        Http::data(Db::first('SELECT * FROM sales_channels WHERE channel_id = :id', ['id' => $id]) ?? [], 201);
    }

    public static function listSalespeople(): void
    {
        [$auth, $ctx] = self::enter();
        // The portal owns names and logins. What is returned here is the Sales
        // role: territory, channel, discount limit — joined to nothing remote.
        Http::data(Db::all(
            'SELECT p.*, t.territory_name, c.channel_name
             FROM sales_people p
             LEFT JOIN sales_territories t ON t.territory_id = p.territory_id
             LEFT JOIN sales_channels   c ON c.channel_id   = p.channel_id
             WHERE p.cmp_id = :cmp
             ORDER BY p.display_code NULLS LAST, p.salesperson_id',
            ['cmp' => $ctx->cmpId],
        ));
    }

    public static function createSalesperson(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'territory.manage');

        $body = Http::body();
        $uuid = self::requireText($body['user_uuid'] ?? null, 'user_uuid');

        $existing = Db::first('SELECT salesperson_id FROM sales_people WHERE cmp_id = :cmp AND user_uuid = :uuid', ['cmp' => $ctx->cmpId, 'uuid' => $uuid]);
        if ($existing !== null) {
            Http::conflict('That user is already set up as a salesperson.');
        }

        $maxDiscount = (float) ($body['max_discount_pc'] ?? 0);
        if ($maxDiscount < 0 || $maxDiscount > 100) {
            Http::validationFailed('A discount limit must be between 0 and 100 percent.', ['field' => 'max_discount_pc']);
        }

        $id = (int) Db::insert('sales_people', [
            'cmp_id'          => $ctx->cmpId,
            'user_uuid'       => $uuid,
            'display_code'    => self::text($body['display_code'] ?? null),
            'territory_id'    => self::id($body['territory_id'] ?? null),
            'channel_id'      => self::id($body['channel_id'] ?? null),
            'reports_to'      => self::id($body['reports_to'] ?? null),
            'max_discount_pc' => $maxDiscount,
        ], 'salesperson_id');

        Audit::record($ctx, $auth, 'salesperson.created', 'salesperson', $id, null, ['user_uuid' => $uuid]);
        Http::data(Db::first('SELECT * FROM sales_people WHERE salesperson_id = :id', ['id' => $id]) ?? [], 201);
    }

    public static function listPriceBooks(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'pricebook.view');

        Http::data(Db::all(
            'SELECT b.*, (SELECT COUNT(*) FROM sales_price_book_rules r WHERE r.price_book_id = b.price_book_id) AS rule_count
             FROM sales_price_books b WHERE b.cmp_id = :cmp ORDER BY b.priority, b.book_name',
            ['cmp' => $ctx->cmpId],
        ));
    }

    public static function showPriceBook(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'pricebook.view');

        $book = Db::first('SELECT * FROM sales_price_books WHERE price_book_id = :id AND cmp_id = :cmp', ['id' => (int) $id, 'cmp' => $ctx->cmpId]);
        if ($book === null) {
            Http::notFound('That price book does not exist.');
        }
        $book['rules'] = Db::all('SELECT * FROM sales_price_book_rules WHERE price_book_id = :id ORDER BY item_id NULLS LAST, min_qty', ['id' => (int) $id]);

        Http::data($book);
    }

    public static function createPriceBook(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'pricebook.manage');

        $body = Http::body();
        $code = self::requireText($body['book_code'] ?? null, 'book_code');
        $name = self::requireText($body['book_name'] ?? null, 'book_name');
        $scope = self::text($body['scope_kind'] ?? null) ?? 'standard';

        if (!in_array($scope, ['standard', 'customer', 'channel', 'territory', 'contract'], true)) {
            Http::validationFailed('Scope must be standard, customer, channel, territory or contract.', ['field' => 'scope_kind']);
        }
        self::guardDuplicate('sales_price_books', 'book_code', $code, $ctx->cmpId, 'A price book with that code already exists.');

        $id = (int) Db::insert('sales_price_books', [
            'cmp_id'              => $ctx->cmpId,
            'bo_id'               => $ctx->boId,
            'book_code'           => $code,
            'book_name'           => $name,
            'currency_code'       => self::text($body['currency_code'] ?? null) ?? 'INR',
            'scope_kind'          => $scope,
            'customer_account_id' => self::id($body['customer_account_id'] ?? null),
            'channel_id'          => self::id($body['channel_id'] ?? null),
            'territory_id'        => self::id($body['territory_id'] ?? null),
            'valid_from'          => self::text($body['valid_from'] ?? null),
            'valid_to'            => self::text($body['valid_to'] ?? null),
            'priority'            => (int) ($body['priority'] ?? 100),
        ], 'price_book_id');

        Audit::record($ctx, $auth, 'price_book.created', 'price_book', $id, null, ['code' => $code, 'scope' => $scope]);
        self::showPriceBook((string) $id);
    }

    public static function updatePriceBook(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'pricebook.manage');

        $before = Db::first('SELECT * FROM sales_price_books WHERE price_book_id = :id AND cmp_id = :cmp', ['id' => (int) $id, 'cmp' => $ctx->cmpId]);
        if ($before === null) {
            Http::notFound('That price book does not exist.');
        }

        $body = Http::body();
        $changes = [];
        foreach (['book_name', 'currency_code', 'valid_from', 'valid_to', 'priority', 'is_active'] as $field) {
            if (array_key_exists($field, $body)) {
                $changes[$field] = $body[$field];
            }
        }
        if ($changes !== []) {
            $changes['updated_at'] = gmdate('Y-m-d H:i:s');
            Db::update('sales_price_books', $changes, ['price_book_id' => (int) $id, 'cmp_id' => $ctx->cmpId]);
            Audit::record($ctx, $auth, 'price_book.updated', 'price_book', (int) $id, $before, $changes);
        }

        self::showPriceBook($id);
    }

    public static function addPriceRule(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'pricebook.manage');

        $book = Db::first('SELECT price_book_id FROM sales_price_books WHERE price_book_id = :id AND cmp_id = :cmp', ['id' => (int) $id, 'cmp' => $ctx->cmpId]);
        if ($book === null) {
            Http::notFound('That price book does not exist.');
        }

        $body = Http::body();
        $itemId = self::id($body['item_id'] ?? null);
        $groupId = self::id($body['item_grp_id'] ?? null);
        if ($itemId === null && $groupId === null) {
            Http::validationFailed('A rule needs either an item or an item group.', ['field' => 'item_id']);
        }

        $rateKind = self::text($body['rate_kind'] ?? null) ?? 'fixed';
        if (!in_array($rateKind, ['fixed', 'discount_pc', 'markup_pc_on_cost'], true)) {
            Http::validationFailed('Rate kind must be fixed, discount_pc or markup_pc_on_cost.', ['field' => 'rate_kind']);
        }

        $minQty = (float) ($body['min_qty'] ?? 0);
        $maxQty = isset($body['max_qty']) && $body['max_qty'] !== null ? (float) $body['max_qty'] : null;
        if ($maxQty !== null && $maxQty < $minQty) {
            Http::validationFailed('The maximum quantity cannot be below the minimum.', ['field' => 'max_qty']);
        }

        $ruleId = (int) Db::insert('sales_price_book_rules', [
            'price_book_id' => (int) $id,
            'cmp_id'        => $ctx->cmpId,
            'item_id'       => $itemId,
            'item_grp_id'   => $groupId,
            'unit_id'       => self::id($body['unit_id'] ?? null),
            'min_qty'       => $minQty,
            'max_qty'       => $maxQty,
            'rate_kind'     => $rateKind,
            'rate'          => (float) ($body['rate'] ?? 0),
            'discount_pc'   => (float) ($body['discount_pc'] ?? 0),
            'min_margin_pc' => isset($body['min_margin_pc']) && $body['min_margin_pc'] !== null ? (float) $body['min_margin_pc'] : null,
            'valid_from'    => self::text($body['valid_from'] ?? null),
            'valid_to'      => self::text($body['valid_to'] ?? null),
        ], 'rule_id');

        Audit::record($ctx, $auth, 'price_rule.created', 'price_book', (int) $id, null, ['rule_id' => $ruleId, 'item_id' => $itemId]);
        self::showPriceBook($id);
    }

    public static function deletePriceRule(string $id, string $ruleId): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'pricebook.manage');

        $deleted = Db::run(
            'DELETE FROM sales_price_book_rules WHERE rule_id = :rule AND price_book_id = :book AND cmp_id = :cmp',
            ['rule' => (int) $ruleId, 'book' => (int) $id, 'cmp' => $ctx->cmpId],
        )->rowCount();

        if ($deleted === 0) {
            Http::notFound('That pricing rule does not exist.');
        }

        Audit::record($ctx, $auth, 'price_rule.deleted', 'price_book', (int) $id, ['rule_id' => (int) $ruleId], null);
        self::showPriceBook($id);
    }

    // -----------------------------------------------------------------------

    private static function guardDuplicate(string $table, string $column, string $value, int $cmpId, string $message): void
    {
        $existing = Db::first(
            'SELECT 1 FROM ' . Db::quoteIdentifier($table) . ' WHERE cmp_id = :cmp AND ' . Db::quoteIdentifier($column) . ' = :value',
            ['cmp' => $cmpId, 'value' => $value],
        );
        if ($existing !== null) {
            Http::conflict($message);
        }
    }

    private static function requireText(mixed $value, string $field): string
    {
        $text = self::text($value);
        if ($text === null) {
            Http::validationFailed(str_replace('_', ' ', ucfirst($field)) . ' is required.', ['field' => $field]);
        }

        return $text;
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function id(mixed $value): ?int
    {
        return ($value === null || $value === '' || (int) $value === 0) ? null : (int) $value;
    }
}
