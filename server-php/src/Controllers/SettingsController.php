<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Auth;
use Aicountly\Api\Clients\ManageClient;
use Aicountly\Api\CompanyAccess;
use Aicountly\Api\Context;
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
            'display_name' => self::displayName($ctx, $auth),
            'kind'         => $auth->kind,
            'is_owner'     => CompanyAccess::isOwner($ctx, $auth),
            'context'      => $ctx->asQuery(),
            'permissions'  => Permissions::granted($ctx, $auth),
        ]);
    }

    /**
     * The caller's name, from whoever actually knows it.
     *
     * The portal's `validatesession` answer identifies the session; it does not
     * reliably carry a name, and `Auth::displayName()` therefore fell through to
     * the raw uuid — which is why the header was showing a bare number where a
     * person's name belongs.
     *
     * Manage's member directory does carry it, this endpoint is called once per
     * company (not per screen), and the value is used and discarded. A name
     * cached in a Sales table would be the wrong name the first time somebody
     * corrected theirs in Manage.
     */
    private static function displayName(Context $ctx, Auth $auth): string
    {
        $fallback = $auth->displayName();
        if ($auth->isService()) {
            return $fallback;
        }

        $result = (new ManageClient())->withSession($auth->sesKey())->companyMembers($ctx->cmpId);
        if (!($result['ok'] ?? false)) {
            return $fallback;
        }

        $body = $result['body'] ?? [];
        $rows = is_array($body['data'] ?? null) ? $body['data'] : (is_array($body) ? $body : []);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $uuid = (string) ($row['uuid'] ?? $row['platform_user_uuid'] ?? '');
            if ($uuid !== $auth->uuid) {
                continue;
            }
            foreach ([$row['display_name'] ?? null, $row['name'] ?? null, $row['email'] ?? null] as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    return trim($candidate);
                }
            }
        }

        return $fallback;
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
