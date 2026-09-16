<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Append-only audit of THIS product's own actions.
 *
 * Books audits its vouchers and Inventory audits its movements; neither is
 * copied here. What this records is what only this product knows: who approved
 * a discount beyond their limit, who revised a quotation, who cancelled an
 * order and why.
 */
final class Audit
{
    public const TABLE = 'sales_audit_log';

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public static function record(
        Context $ctx,
        Auth $auth,
        string $action,
        string $entityType,
        int|string|null $entityId,
        ?array $before = null,
        ?array $after = null,
        string $reason = '',
    ): void {
        try {
            Db::insert(self::TABLE, [
                'cmp_id'       => $ctx->cmpId,
                'fy_id'        => $ctx->fyId,
                'bo_id'        => $ctx->boId,
                'actor_uuid'   => $auth->uuid,
                'actor_kind'   => $auth->kind,
                'source_app'   => $auth->sourceApp,
                'action'       => $action,
                'entity_type'  => $entityType,
                'entity_id'    => $entityId === null ? null : (string) $entityId,
                'before_state' => $before,
                'after_state'  => $after,
                'reason'       => $reason !== '' ? $reason : null,
                'ip_address'   => self::clientIp(),
                'created_at'   => gmdate('Y-m-d H:i:s'),
            ], 'audit_id');
        } catch (\Throwable $e) {
            // An audit write must never be the reason a user's save fails. It is
            // logged loudly instead, because a silently missing audit row is
            // worse than a noisy one.
            error_log('[audit] failed to record ' . $action . ' on ' . $entityType . ': ' . $e->getMessage());
        }
    }

    private static function clientIp(): ?string
    {
        $candidates = [$_SERVER['HTTP_X_FORWARDED_FOR'] ?? '', $_SERVER['REMOTE_ADDR'] ?? ''];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            $first = trim(explode(',', $candidate)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }

        return null;
    }
}
