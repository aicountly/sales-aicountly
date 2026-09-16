<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * This product's own permissions, layered over the portal identity.
 *
 * It does NOT duplicate user identity — the portal owns that, and Manage owns
 * which companies a user may open. What lives here is only what this product
 * can decide: may this person approve a discount past the policy, may they see
 * cost and margin, may they cancel a confirmed order.
 *
 * ENFORCED IN THE BACKEND. Every check runs here, before the query. Hiding a
 * menu item in React is a courtesy to the user, not a control: the API route is
 * one curl away and the person who wants to see the margin they were not shown
 * is exactly the person who will try it.
 *
 * The company owner (portal acs_type = 1) holds everything, so a brand-new
 * company is usable before anyone has configured a single profile.
 */
final class Permissions
{
    public const TABLE_PROFILES    = 'sales_permission_profiles';
    public const TABLE_ASSIGNMENTS = 'sales_permission_assignments';

    /**
     * Every permission this product understands, grouped for the admin screen.
     *
     * @var array<string, array<string, string>>
     */
    public const CATALOG = [
        'Quotations' => [
            'quotation.view'    => 'View quotations',
            'quotation.create'  => 'Create and revise quotations',
            'quotation.approve' => 'Approve a quotation that breached a rule',
            'quotation.send'    => 'Send a quotation to the customer',
        ],
        'Orders' => [
            'order.view'    => 'View sales orders',
            'order.create'  => 'Create sales orders',
            'order.confirm' => 'Confirm an order (reserves stock)',
            'order.amend'   => 'Amend a confirmed order',
            'order.cancel'  => 'Cancel an order',
        ],
        'Pricing' => [
            'pricebook.view'     => 'View price books',
            'pricebook.manage'   => 'Create and edit price books',
            'discount.override'  => 'Discount beyond the policy limit',
            'margin.view'        => 'See cost and margin',
        ],
        'Fulfilment' => [
            'fulfilment.request' => 'Request reservation, pick, pack and dispatch',
            'invoice.request'    => 'Ask Books to raise the invoice',
            'return.create'      => 'Raise a return / RMA',
            'return.approve'     => 'Approve a return',
        ],
        'Commercial' => [
            'credit.override'   => 'Proceed past a credit-control warning',
            'target.manage'     => 'Set targets and quotas',
            'commission.view'   => 'View commission calculations',
            'commission.manage' => 'Define commission rules',
        ],
        'Administration' => [
            'territory.manage' => 'Manage territories and channels',
            'settings.manage'  => 'Change Sales settings',
            'access.manage'    => 'Manage Sales permission profiles',
            'reports.view'     => 'View Sales reports',
        ],
    ];

    /** @var array<string, list<string>> */
    private static array $cache = [];

    /** Assert a permission, or answer 403 and stop. */
    public static function assert(Context $ctx, Auth $auth, string $permission): void
    {
        if (!self::allows($ctx, $auth, $permission)) {
            Http::forbidden('You do not have permission to ' . self::describe($permission) . '.');
        }
    }

    public static function allows(Context $ctx, Auth $auth, string $permission): bool
    {
        // A trusted product backend acts for a human its own side already
        // authorised; re-deciding that here would be this product overruling the
        // owner of the permission.
        if ($auth->isService()) {
            return true;
        }
        if ($auth->accessType() === 1) {
            return true;
        }

        return in_array($permission, self::granted($ctx, $auth), true);
    }

    /** @return list<string> */
    public static function granted(Context $ctx, Auth $auth): array
    {
        $key = $ctx->cmpId . ':' . $auth->uuid;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        if ($auth->isService() || $auth->accessType() === 1) {
            return self::$cache[$key] = self::all();
        }

        try {
            $rows = Db::all(
                'SELECT p.permissions
                 FROM ' . self::TABLE_ASSIGNMENTS . ' a
                 JOIN ' . self::TABLE_PROFILES . ' p ON p.profile_id = a.profile_id
                 WHERE a.cmp_id = :cmp AND a.user_uuid = :uuid AND p.is_active = TRUE',
                ['cmp' => $ctx->cmpId, 'uuid' => $auth->uuid],
            );
        } catch (\Throwable $e) {
            error_log('[permissions] lookup failed: ' . $e->getMessage());

            return self::$cache[$key] = [];
        }

        $granted = [];
        foreach ($rows as $row) {
            foreach (Db::jsonColumn($row['permissions'] ?? null) as $permission) {
                if (is_string($permission)) {
                    $granted[$permission] = true;
                }
            }
        }

        return self::$cache[$key] = array_keys($granted);
    }

    /** @return list<string> */
    public static function all(): array
    {
        $out = [];
        foreach (self::CATALOG as $group) {
            foreach (array_keys($group) as $permission) {
                $out[] = $permission;
            }
        }

        return $out;
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    private static function describe(string $permission): string
    {
        foreach (self::CATALOG as $group) {
            if (isset($group[$permission])) {
                return strtolower($group[$permission]);
            }
        }

        return 'do that';
    }
}
