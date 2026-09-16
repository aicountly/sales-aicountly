<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Clients\ManageClient;

/**
 * Whether this session owns the company it is working in.
 *
 * WHY THIS CLASS EXISTS. `acs_type` is a property of a USER AND A COMPANY
 * TOGETHER — it is a column on a Manage company row, and it answers "what is
 * this person's access to this company". It was being read from the portal's
 * `validatesession` response, which is not company-scoped and has never
 * contained it. So `accessType()` returned null for every real user, the owner
 * short-circuit in Permissions never fired, and because
 * `sales_permission_assignments` is empty on a new company, every signed-in
 * person held ZERO permissions. The symptom was a dashboard that answered
 * "You do not have permission to view quotations" to the company's own owner.
 *
 * The one test that covered permissions handed Auth a fake session containing
 * `acs_type => 1`, so it asserted the mock rather than the behaviour and the
 * suite stayed green. That is the more useful lesson than the bug.
 *
 * WHERE IT COMES FROM NOW. The Manage company row, resolved with the same
 * precedence the browser already uses (`web/src/company/manageShapes.ts`):
 * `acs_type` wins, then the `ownership` label, then `is_creator`. Context
 * already fetches `companyinfo` to check tenancy, so the common path costs no
 * extra call; the `companies` list is consulted only when `companyinfo` does not
 * carry the field.
 *
 * UNKNOWN IS NOT OWNER. A company Manage cannot describe returns null, and
 * Permissions then falls through to the profile table rather than granting
 * everything. An access check that fails open is not an access check.
 */
final class CompanyAccess
{
    public const OWNER = 1;
    public const MEMBER = 0;

    /** @var array<string, int|null> */
    private static array $cache = [];

    /** Labels Manage uses for somebody who is not the owner. */
    private const MEMBER_LABELS = ['shared', 'delegated', 'user', 'viewer', 'editor', 'member'];

    /**
     * Remember what `Context::assertAllowed()` already learned.
     *
     * The tenancy check fetches the company row on every scoped request. Taking
     * the access type off that same response is free; asking Manage a second
     * time for a field we have just been handed is not.
     */
    public static function remember(Context $ctx, Auth $auth, array $companyRow): void
    {
        self::$cache[self::key($ctx, $auth)] = self::fromRow($companyRow);
    }

    /**
     * This session's access type for this company, or null if Manage did not say.
     */
    public static function accessType(Context $ctx, Auth $auth): ?int
    {
        // A trusted product backend is not a person and has no company
        // ownership. Permissions handles service callers before it gets here.
        if ($auth->isService()) {
            return null;
        }

        $key = self::key($ctx, $auth);
        if (array_key_exists($key, self::$cache)) {
            $known = self::$cache[$key];
            if ($known !== null) {
                return $known;
            }
        }

        // `companyinfo` did not carry it. The company LIST does, because that is
        // the payload the switcher reads to label a company "shared".
        $resolved = self::fromCompanyList($ctx, $auth);
        self::$cache[$key] = $resolved;

        return $resolved;
    }

    public static function isOwner(Context $ctx, Auth $auth): bool
    {
        return self::accessType($ctx, $auth) === self::OWNER;
    }

    /**
     * Resolve the access type from one Manage company row.
     *
     * Mirrors `resolveAcsType` in web/src/company/manageShapes.ts on purpose:
     * two places deciding who owns a company differently is worse than the
     * duplication, and the browser's version is already audited against the
     * real payloads.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): ?int
    {
        if (isset($row['acs_type']) && is_numeric($row['acs_type'])) {
            $acs = (int) $row['acs_type'];
            if ($acs === self::OWNER || $acs === self::MEMBER) {
                return $acs;
            }
        }

        $ownership = isset($row['ownership']) && is_string($row['ownership'])
            ? strtolower(trim($row['ownership']))
            : '';
        if ($ownership === 'owner') {
            return self::OWNER;
        }
        if ($ownership !== '' && in_array($ownership, self::MEMBER_LABELS, true)) {
            return self::MEMBER;
        }

        if (self::truthy($row['is_creator'] ?? null)) {
            return self::OWNER;
        }

        return null;
    }

    /** Test seam: the integration suite sets this instead of standing up Manage. */
    public static function seed(Context $ctx, Auth $auth, ?int $accessType): void
    {
        self::$cache[self::key($ctx, $auth)] = $accessType;
    }

    public static function forget(): void
    {
        self::$cache = [];
    }

    // -----------------------------------------------------------------------

    private static function fromCompanyList(Context $ctx, Auth $auth): ?int
    {
        $result = (new ManageClient())->withSession($auth->sesKey())->companies(['limit' => 200]);
        if (!($result['ok'] ?? false)) {
            // Manage is unreachable. Unknown, not owner: the caller falls
            // through to the profile table, which is the safe direction.
            return null;
        }

        foreach (self::rowsOf($result['body'] ?? []) as $row) {
            $rowId = (int) ($row['comp_id'] ?? $row['cmp_id'] ?? $row['id'] ?? 0);
            if ($rowId === $ctx->cmpId) {
                return self::fromRow($row);
            }
        }

        return null;
    }

    /**
     * Company rows out of any `/manage/companies` envelope.
     *
     * Manage's list shape has grown several forms over the years; this follows
     * the same order `extractCompanyRows` does in the browser.
     *
     * @return list<array<string, mixed>>
     */
    private static function rowsOf(mixed $body): array
    {
        if (!is_array($body)) {
            return [];
        }
        if (array_is_list($body)) {
            return array_values(array_filter($body, 'is_array'));
        }

        foreach ([$body['data'] ?? null, $body['companies'] ?? null, $body['items'] ?? null] as $candidate) {
            if (is_array($candidate) && array_is_list($candidate)) {
                return array_values(array_filter($candidate, 'is_array'));
            }
            if (is_array($candidate)) {
                foreach ([$candidate['companies'] ?? null, $candidate['items'] ?? null] as $nested) {
                    if (is_array($nested) && array_is_list($nested)) {
                        return array_values(array_filter($nested, 'is_array'));
                    }
                }
                if (isset($candidate['comp_id']) || isset($candidate['cmp_id']) || isset($candidate['id'])) {
                    return [$candidate];
                }
            }
        }

        return [];
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y'], true);
        }

        return false;
    }

    private static function key(Context $ctx, Auth $auth): string
    {
        return $ctx->cmpId . ':' . $auth->fingerprint();
    }
}
