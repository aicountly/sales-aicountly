<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Clients\ManageClient;
use Aicountly\Api\CompanyAccess;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * Who may do what in Sales.
 *
 * The permission table and the CATALOG have existed since the first build, and
 * `Permissions::assert()` has been enforcing them on every query. What did not
 * exist was any way to WRITE them: no endpoint, no screen. `access.manage` was
 * a permission you could hold and not use, and the only way to grant anybody
 * anything was an INSERT typed into psql. That is what this controller is.
 *
 * TWO HALVES, AND THE LINE BETWEEN THEM IS THE WHOLE DESIGN:
 *
 *   MANAGE owns WHO. A person, their name, their email, and which companies
 *          they may open. Read live from Manage's member directory on every
 *          request. Sales stores none of it.
 *
 *   SALES owns WHAT. A profile is a named set of codes from
 *         Permissions::CATALOG; an assignment ties a profile to a Manage uuid.
 *         That uuid is the only thing about a person that is stored here.
 *
 * So a person renamed in Manage is renamed here on the next page load, and a
 * person removed from the company stops appearing without Sales being told.
 * The alternative — a `sales_users` table — would be a second directory that
 * disagrees with the first one within a week.
 *
 * THE OWNER IS NOT ASSIGNABLE. Ownership comes from Manage (CompanyAccess), and
 * the owner holds the whole catalog implicitly. Letting Sales grant or revoke
 * that would be Sales overruling Manage on a question Manage owns; the screen
 * shows owners as owners and offers no profile control for them.
 */
final class AccessController extends Controller
{
    /**
     * The profiles this company has defined, with how many people hold each.
     */
    public static function profiles(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'access.manage');

        $rows = Db::all(
            'SELECT p.profile_id, p.profile_name, p.description, p.permissions,
                    p.is_system, p.is_active, p.created_at, p.updated_at,
                    (SELECT COUNT(*) FROM ' . Permissions::TABLE_ASSIGNMENTS . ' a
                      WHERE a.profile_id = p.profile_id) AS holders
             FROM ' . Permissions::TABLE_PROFILES . ' p
             WHERE p.cmp_id = :cmp
             ORDER BY p.is_active DESC, p.profile_name',
            ['cmp' => $ctx->cmpId],
        );

        Http::data([
            'catalog'  => Permissions::CATALOG,
            'profiles' => array_map(static fn (array $row) => self::presentProfile($row), $rows),
            // The screen needs to know it is looking at a company whose owner
            // holds everything regardless of what is configured here.
            'is_owner' => CompanyAccess::isOwner($ctx, $auth),
        ]);
    }

    public static function createProfile(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'access.manage');

        $body = Http::body();
        $name = self::name($body['profile_name'] ?? null);
        $permissions = self::permissions($body['permissions'] ?? null);

        $existing = Db::first(
            'SELECT 1 FROM ' . Permissions::TABLE_PROFILES . ' WHERE cmp_id = :cmp AND LOWER(profile_name) = LOWER(:name)',
            ['cmp' => $ctx->cmpId, 'name' => $name],
        );
        if ($existing !== null) {
            Http::conflict('A profile called "' . $name . '" already exists.');
        }

        $id = (int) Db::insert(Permissions::TABLE_PROFILES, [
            'cmp_id'       => $ctx->cmpId,
            'profile_name' => $name,
            'description'  => self::description($body['description'] ?? null),
            'permissions'  => json_encode(array_values($permissions)),
        ], 'profile_id');

        Audit::record($ctx, $auth, 'access.profile_created', 'permission_profile', $id, null, [
            'profile_name' => $name,
            'permissions'  => $permissions,
        ]);

        Http::data(self::profileById($ctx, $id), 201);
    }

    public static function updateProfile(string $profileId): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'access.manage');

        $id = (int) $profileId;
        $before = self::profileRow($ctx, $id);
        $body = Http::body();

        $changes = [];
        if (array_key_exists('profile_name', $body)) {
            $changes['profile_name'] = self::name($body['profile_name']);
        }
        if (array_key_exists('description', $body)) {
            $changes['description'] = self::description($body['description']);
        }
        if (array_key_exists('permissions', $body)) {
            $changes['permissions'] = json_encode(array_values(self::permissions($body['permissions'])));
        }
        if (array_key_exists('is_active', $body)) {
            $changes['is_active'] = (bool) $body['is_active'];
        }

        if ($changes === []) {
            Http::validationFailed('Nothing to change.');
        }

        // A system profile's MEMBERSHIP of the catalog is ours to define, not
        // the company's; renaming or re-scoping it would make the same name
        // mean different things in different companies.
        if ((bool) $before['is_system'] && (isset($changes['profile_name']) || isset($changes['permissions']))) {
            Http::forbidden('A built-in profile cannot be renamed or re-scoped. Create your own profile instead.');
        }

        $changes['updated_at'] = gmdate('Y-m-d H:i:s');
        Db::update(Permissions::TABLE_PROFILES, $changes, ['profile_id' => $id, 'cmp_id' => $ctx->cmpId]);

        // Grants are memoised per request, and this request may still answer a
        // permission question about the profile it just rewrote.
        Permissions::forget();

        Audit::record($ctx, $auth, 'access.profile_updated', 'permission_profile', $id, [
            'profile_name' => $before['profile_name'],
            'permissions'  => Db::jsonColumn($before['permissions'] ?? null),
            'is_active'    => (bool) $before['is_active'],
        ], $changes);

        Http::data(self::profileById($ctx, $id));
    }

    /**
     * Delete a profile, taking its assignments with it.
     *
     * Deliberately explicit: the caller is told how many people are about to
     * lose their access, and has to say so in the request. A profile held by
     * eleven people is not something to delete by mis-clicking a row.
     */
    public static function deleteProfile(string $profileId): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'access.manage');

        $id = (int) $profileId;
        $before = self::profileRow($ctx, $id);

        if ((bool) $before['is_system']) {
            Http::forbidden('A built-in profile cannot be deleted. Deactivate it instead.');
        }

        $holders = (int) Db::first(
            'SELECT COUNT(*) AS n FROM ' . Permissions::TABLE_ASSIGNMENTS . ' WHERE cmp_id = :cmp AND profile_id = :id',
            ['cmp' => $ctx->cmpId, 'id' => $id],
        )['n'];

        if ($holders > 0 && !self::confirmed()) {
            Http::conflict(
                $holders . ' ' . ($holders === 1 ? 'person holds' : 'people hold') . ' this profile and will lose '
                . 'the access it grants. Send confirm=1 to go ahead.',
                ['holders' => $holders],
            );
        }

        // ON DELETE CASCADE on the assignment's foreign key removes the rows.
        Db::run(
            'DELETE FROM ' . Permissions::TABLE_PROFILES . ' WHERE profile_id = :id AND cmp_id = :cmp',
            ['id' => $id, 'cmp' => $ctx->cmpId],
        );
        Permissions::forget();

        Audit::record($ctx, $auth, 'access.profile_deleted', 'permission_profile', $id, [
            'profile_name' => $before['profile_name'],
            'permissions'  => Db::jsonColumn($before['permissions'] ?? null),
            'holders'      => $holders,
        ], null);

        Http::data(['deleted' => true, 'profile_id' => $id, 'assignments_removed' => $holders]);
    }

    /**
     * Everybody who may open this company, and what Sales lets each of them do.
     *
     * The people come from Manage, live. The profiles come from here. A member
     * Manage no longer lists is reported separately rather than dropped in
     * silence, because a stale assignment is something an administrator should
     * be able to see and clear.
     */
    public static function members(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'access.manage');

        $assignments = self::assignmentsByUser($ctx);
        $directory = self::directory($ctx, $auth);

        $members = [];
        $seen = [];
        foreach ($directory['rows'] as $person) {
            $uuid = (string) $person['uuid'];
            $seen[$uuid] = true;
            $members[] = [
                'uuid'         => $uuid,
                'display_name' => $person['display_name'],
                'email'        => $person['email'],
                'is_owner'     => $person['is_owner'],
                // An owner's rights do not come from a profile, and showing
                // them an empty list would read as "this person has no access".
                'profiles'     => $assignments[$uuid] ?? [],
                'effective'    => $person['is_owner'] ? Permissions::all() : self::effective($assignments[$uuid] ?? []),
                'source'       => 'manage',
            ];
        }

        // Assignments whose person Manage no longer lists for this company.
        // They grant nothing — Context::assertAllowed() would refuse the
        // session first — but they are clutter, and clutter in an access list
        // is how somebody later concludes the list cannot be trusted.
        $orphans = [];
        foreach ($assignments as $uuid => $profiles) {
            if (!isset($seen[$uuid]) && $directory['status'] === 'ready') {
                $orphans[] = ['uuid' => $uuid, 'profiles' => $profiles];
            }
        }

        Http::data([
            'members'   => $members,
            'orphans'   => $orphans,
            'directory' => ['status' => $directory['status'], 'reason' => $directory['reason'], 'source' => 'manage'],
        ]);
    }

    /**
     * Set the profiles one person holds — the whole set, not a delta.
     *
     * Replacing the set rather than adding to it is what makes the screen
     * honest: the administrator ticks boxes and sees what they ticked, and two
     * people editing the same person cannot leave a profile behind that neither
     * of them meant to grant.
     */
    public static function assign(string $userUuid): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'access.manage');

        $uuid = trim(rawurldecode($userUuid));
        if ($uuid === '') {
            Http::validationFailed('Which person? A Manage user uuid is required.');
        }

        // The person must be somebody Manage says belongs to this company.
        // Without this check, Sales would be an access-granting surface for
        // uuids Manage never admitted — and the uuid is attacker-supplied text.
        $directory = self::directory($ctx, $auth);
        if ($directory['status'] !== 'ready') {
            Http::error(503, 'directory_unavailable', 'Cannot confirm who belongs to this company right now. Please retry.');
        }

        $person = null;
        foreach ($directory['rows'] as $row) {
            if ((string) $row['uuid'] === $uuid) {
                $person = $row;
                break;
            }
        }
        if ($person === null) {
            Http::validationFailed(
                'That person does not have access to this company. Invite them in Manage first, then give them a Sales profile here.',
            );
        }
        if ($person['is_owner']) {
            Http::validationFailed(
                'The company owner already holds every Sales permission. Ownership is managed in Manage, not here.',
            );
        }

        $body = Http::body();
        $requested = $body['profile_ids'] ?? null;
        if (!is_array($requested)) {
            Http::validationFailed('profile_ids must be a list of profile ids (an empty list removes all access).', ['field' => 'profile_ids']);
        }

        $ids = [];
        foreach ($requested as $candidate) {
            $id = (int) $candidate;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);

        // Every id must be a live profile in THIS company. A profile id from
        // another company would otherwise import that company's permissions.
        if ($ids !== []) {
            $placeholders = [];
            $params2 = ['cmp' => $ctx->cmpId];
            foreach ($ids as $index => $id) {
                $placeholders[] = ':p' . $index;
                $params2['p' . $index] = $id;
            }
            $found = Db::all(
                'SELECT profile_id FROM ' . Permissions::TABLE_PROFILES . '
                 WHERE cmp_id = :cmp AND profile_id IN (' . implode(', ', $placeholders) . ')',
                $params2,
            );
            if (count($found) !== count($ids)) {
                Http::validationFailed('One of those profiles does not belong to this company.', ['field' => 'profile_ids']);
            }
        }

        $before = self::assignmentsByUser($ctx)[$uuid] ?? [];

        Db::transaction(static function () use ($ctx, $uuid, $ids): void {
            Db::run(
                'DELETE FROM ' . Permissions::TABLE_ASSIGNMENTS . ' WHERE cmp_id = :cmp AND user_uuid = :uuid',
                ['cmp' => $ctx->cmpId, 'uuid' => $uuid],
            );
            foreach ($ids as $id) {
                Db::insert(Permissions::TABLE_ASSIGNMENTS, [
                    'cmp_id'     => $ctx->cmpId,
                    'user_uuid'  => $uuid,
                    'profile_id' => $id,
                ], 'assignment_id');
            }
        });

        Permissions::forget();

        Audit::record(
            $ctx,
            $auth,
            'access.assigned',
            'permission_assignment',
            $uuid,
            ['profile_ids' => array_column($before, 'profile_id')],
            ['profile_ids' => $ids],
        );

        $after = self::assignmentsByUser($ctx)[$uuid] ?? [];

        Http::data([
            'uuid'      => $uuid,
            'profiles'  => $after,
            'effective' => self::effective($after),
        ]);
    }

    /** Clear every Sales assignment for a uuid Manage no longer lists. */
    public static function removeOrphan(string $userUuid): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'access.manage');

        $uuid = trim(rawurldecode($userUuid));
        if ($uuid === '') {
            Http::validationFailed('A Manage user uuid is required.');
        }

        $before = self::assignmentsByUser($ctx)[$uuid] ?? [];
        if ($before === []) {
            Http::notFound('That person holds no Sales profile in this company.');
        }

        Db::run(
            'DELETE FROM ' . Permissions::TABLE_ASSIGNMENTS . ' WHERE cmp_id = :cmp AND user_uuid = :uuid',
            ['cmp' => $ctx->cmpId, 'uuid' => $uuid],
        );
        Permissions::forget();

        Audit::record($ctx, $auth, 'access.revoked', 'permission_assignment', $uuid, [
            'profile_ids' => array_column($before, 'profile_id'),
        ], ['profile_ids' => []]);

        Http::data(['uuid' => $uuid, 'profiles' => [], 'effective' => []]);
    }

    // -----------------------------------------------------------------------

    /**
     * Manage's member directory for this company, live.
     *
     * Unreachable is reported, never faked: an access screen that shows an
     * empty list because Manage had a bad minute invites somebody to conclude
     * the company has no members and start granting from scratch.
     *
     * @return array{status:string, reason:string|null, rows:list<array<string, mixed>>}
     */
    private static function directory(Context $ctx, \Aicountly\Api\Auth $auth): array
    {
        if ($auth->isService()) {
            // A service caller has no session to read Manage with, and nothing
            // in the fleet needs Sales to enumerate people on its behalf.
            return ['status' => 'unavailable', 'reason' => 'The member directory is read with a user session.', 'rows' => []];
        }

        $result = (new ManageClient())->withSession($auth->sesKey())->companyMembers($ctx->cmpId);
        if (!($result['ok'] ?? false)) {
            return [
                'status' => 'unavailable',
                'reason' => 'Manage could not be reached for the list of people in this company.',
                'rows'   => [],
            ];
        }

        $body = $result['body'] ?? [];
        $rows = is_array($body['data'] ?? null) ? $body['data'] : (is_array($body) ? $body : []);

        $people = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $uuid = trim((string) ($row['uuid'] ?? $row['platform_user_uuid'] ?? ''));
            if ($uuid === '') {
                continue;
            }
            $name = $row['display_name'] ?? $row['name'] ?? null;
            $people[] = [
                'uuid'         => $uuid,
                'display_name' => is_string($name) && trim($name) !== '' ? trim($name) : null,
                'email'        => is_string($row['email'] ?? null) && trim((string) $row['email']) !== '' ? trim((string) $row['email']) : null,
                'is_owner'     => self::truthy($row['is_owner'] ?? null) || (int) ($row['access_type'] ?? $row['acs_type'] ?? 0) === 1,
            ];
        }

        return ['status' => 'ready', 'reason' => null, 'rows' => $people];
    }

    /**
     * Every assignment in this company, grouped by user uuid.
     *
     * @return array<string, list<array{profile_id:int, profile_name:string, is_active:bool}>>
     */
    private static function assignmentsByUser(Context $ctx): array
    {
        $rows = Db::all(
            'SELECT a.user_uuid, p.profile_id, p.profile_name, p.is_active
             FROM ' . Permissions::TABLE_ASSIGNMENTS . ' a
             JOIN ' . Permissions::TABLE_PROFILES . ' p ON p.profile_id = a.profile_id
             WHERE a.cmp_id = :cmp
             ORDER BY p.profile_name',
            ['cmp' => $ctx->cmpId],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['user_uuid']][] = [
                'profile_id'   => (int) $row['profile_id'],
                'profile_name' => (string) $row['profile_name'],
                'is_active'    => (bool) $row['is_active'],
            ];
        }

        return $out;
    }

    /**
     * What a set of held profiles actually grants.
     *
     * An inactive profile grants nothing — the same rule Permissions::granted()
     * applies — so the screen agrees with the enforcement rather than showing
     * an optimistic list.
     *
     * @param list<array{profile_id:int, profile_name:string, is_active:bool}> $profiles
     * @return list<string>
     */
    private static function effective(array $profiles): array
    {
        $active = array_values(array_filter($profiles, static fn (array $p) => $p['is_active']));
        if ($active === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($active as $index => $profile) {
            $placeholders[] = ':p' . $index;
            $params['p' . $index] = $profile['profile_id'];
        }

        $rows = Db::all(
            'SELECT permissions FROM ' . Permissions::TABLE_PROFILES . '
             WHERE profile_id IN (' . implode(', ', $placeholders) . ')',
            $params,
        );

        $granted = [];
        foreach ($rows as $row) {
            foreach (Db::jsonColumn($row['permissions'] ?? null) as $permission) {
                if (is_string($permission)) {
                    $granted[$permission] = true;
                }
            }
        }

        return array_keys($granted);
    }

    /** @return array<string, mixed> */
    private static function profileRow(Context $ctx, int $id): array
    {
        if ($id <= 0) {
            Http::notFound('No such profile.');
        }
        $row = Db::first(
            'SELECT * FROM ' . Permissions::TABLE_PROFILES . ' WHERE profile_id = :id AND cmp_id = :cmp',
            ['id' => $id, 'cmp' => $ctx->cmpId],
        );
        if ($row === null) {
            Http::notFound('No such profile.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private static function profileById(Context $ctx, int $id): array
    {
        $row = Db::first(
            'SELECT p.*, (SELECT COUNT(*) FROM ' . Permissions::TABLE_ASSIGNMENTS . ' a
                           WHERE a.profile_id = p.profile_id) AS holders
             FROM ' . Permissions::TABLE_PROFILES . ' p
             WHERE p.profile_id = :id AND p.cmp_id = :cmp',
            ['id' => $id, 'cmp' => $ctx->cmpId],
        );

        return $row === null ? [] : self::presentProfile($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function presentProfile(array $row): array
    {
        return [
            'profile_id'   => (int) $row['profile_id'],
            'profile_name' => (string) $row['profile_name'],
            'description'  => $row['description'] ?? null,
            'permissions'  => array_values(array_filter(Db::jsonColumn($row['permissions'] ?? null), 'is_string')),
            'is_system'    => (bool) $row['is_system'],
            'is_active'    => (bool) $row['is_active'],
            'holders'      => (int) ($row['holders'] ?? 0),
        ];
    }

    private static function name(mixed $value): string
    {
        $name = is_string($value) ? trim($value) : '';
        if ($name === '') {
            Http::validationFailed('A profile needs a name.', ['field' => 'profile_name']);
        }
        if (mb_strlen($name) > 80) {
            Http::validationFailed('A profile name cannot be longer than 80 characters.', ['field' => 'profile_name']);
        }

        return $name;
    }

    private static function description(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $text = trim($value);

        return $text === '' ? null : mb_substr($text, 0, 240);
    }

    /**
     * The permission codes a profile may carry.
     *
     * ALLOWLISTED AGAINST THE CATALOG. A code this product does not define is
     * rejected rather than stored: an unknown string in that JSON array is dead
     * weight at best, and at worst it is a permission somebody expects to be
     * enforced that no assert will ever ask about.
     *
     * @return list<string>
     */
    private static function permissions(mixed $value): array
    {
        if (!is_array($value)) {
            Http::validationFailed('permissions must be a list of permission codes.', ['field' => 'permissions']);
        }

        $codes = [];
        $unknown = [];
        foreach ($value as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $code = trim($candidate);
            if ($code === '') {
                continue;
            }
            if (!Permissions::exists($code)) {
                $unknown[] = $code;
                continue;
            }
            $codes[$code] = true;
        }

        if ($unknown !== []) {
            Http::validationFailed(
                'This product does not define ' . implode(', ', array_slice($unknown, 0, 5)) . '.',
                ['field' => 'permissions', 'unknown' => $unknown],
            );
        }

        return array_keys($codes);
    }

    private static function confirmed(): bool
    {
        $body = Http::body();

        return self::truthy(Http::param('confirm')) || self::truthy($body['confirm'] ?? null);
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
    }
}
