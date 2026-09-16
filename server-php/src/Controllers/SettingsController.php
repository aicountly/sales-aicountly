<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

final class SettingsController extends Controller
{
    /** Who the caller is and what this product lets them do. The app's first call. */
    public static function session(): void
    {
        [$auth, $ctx] = self::enter();

        Http::data([
            'uuid'         => $auth->uuid,
            'display_name' => $auth->displayName(),
            'kind'         => $auth->kind,
            'is_owner'     => $auth->accessType() === 1,
            'context'      => $ctx->asQuery(),
            'permissions'  => Permissions::granted($ctx, $auth),
        ]);
    }

    public static function permissions(): void
    {
        [$auth, $ctx] = self::enter();

        Http::data([
            'catalog' => Permissions::CATALOG,
            'granted' => Permissions::granted($ctx, $auth),
        ]);
    }

    public static function show(): void
    {
        [$auth, $ctx] = self::enter();

        $row = Db::first('SELECT * FROM sales_settings WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]);
        if ($row === null) {
            Db::insert('sales_settings', ['cmp_id' => $ctx->cmpId], 'cmp_id');
            $row = Db::first('SELECT * FROM sales_settings WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]);
        }

        Http::data($row ?? []);
    }

    public static function update(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'settings.manage');

        $body = Http::body();
        $allowed = [
            'quotation_prefix', 'order_prefix', 'rma_prefix', 'quotation_validity_days',
            'credit_control_mode', 'reserve_on_confirm', 'default_invoice_basis',
            'require_quotation_approval_above_pc', 'default_warehouse_id',
        ];

        $changes = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $body)) {
                $changes[$field] = $body[$field];
            }
        }

        if (isset($changes['credit_control_mode']) && !in_array($changes['credit_control_mode'], ['allow', 'warn', 'block', 'approval_required'], true)) {
            Http::validationFailed('Credit control must be allow, warn, block or approval_required.', ['field' => 'credit_control_mode']);
        }
        if (isset($changes['default_invoice_basis']) && !in_array($changes['default_invoice_basis'], ['ordered', 'delivered'], true)) {
            Http::validationFailed('Default invoice basis must be ordered or delivered.', ['field' => 'default_invoice_basis']);
        }

        if ($changes !== []) {
            $changes['updated_at'] = gmdate('Y-m-d H:i:s');
            // Make sure the row exists before updating it: a company that has
            // never opened Settings has no row, and an UPDATE would quietly
            // change nothing while answering 200.
            Db::run('INSERT INTO sales_settings (cmp_id) VALUES (:cmp) ON CONFLICT (cmp_id) DO NOTHING', ['cmp' => $ctx->cmpId]);
            Db::update('sales_settings', $changes, ['cmp_id' => $ctx->cmpId]);
        }

        self::show();
    }
}
