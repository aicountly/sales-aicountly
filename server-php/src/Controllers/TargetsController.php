<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\MetricsService;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

/**
 * Targets and quotas.
 *
 * The table has been in the schema since the beginning and nothing could read or
 * write it, so every attainment figure on every screen had nothing to measure
 * against. This is that missing half.
 *
 * WHAT IS STORED: the target. WHAT IS NOT: the achievement. There is no
 * `achieved_value` column and there must never be one — achievement is invoiced
 * revenue, invoiced revenue is Books' answer, and a stored copy drifts from the
 * accounts the first time an invoice is cancelled with nothing here to notice.
 * Attainment is composed at read time, every time.
 */
final class TargetsController extends Controller
{
    private const SCOPES = ['company', 'salesperson', 'territory', 'channel'];
    private const METRICS = ['value', 'quantity', 'orders', 'new_customers'];

    public static function index(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'reports.view');

        $params = Http::listParams(['period_start', 'target_value'], 'period_start');
        [$scope, $bindings] = $ctx->scopeClause('t');

        $where = [$scope];
        if (($from = Http::param('from')) !== null && $from !== '') {
            $where[] = 't.period_end >= :from::date';
            $bindings['from'] = $from;
        }
        if (($to = Http::param('to')) !== null && $to !== '') {
            $where[] = 't.period_start <= :to::date';
            $bindings['to'] = $to;
        }
        if (($targetScope = Http::param('target_scope')) !== null && $targetScope !== '') {
            $where[] = 't.target_scope = :target_scope';
            $bindings['target_scope'] = $targetScope;
        }

        $clause = implode(' AND ', $where);

        $rows = Db::all(
            "SELECT t.*, sp.display_code AS salesperson_code, ter.territory_name, ch.channel_name
               FROM sales_targets t
               LEFT JOIN sales_people sp       ON sp.salesperson_id = t.salesperson_id
               LEFT JOIN sales_territories ter ON ter.territory_id  = t.territory_id
               LEFT JOIN sales_channels ch     ON ch.channel_id     = t.channel_id
              WHERE {$clause}
              ORDER BY t.{$params['sort']} {$params['order']}, t.target_id DESC
              LIMIT {$params['limit']} OFFSET {$params['offset']}",
            $bindings,
        );

        $total = (int) Db::scalar("SELECT COUNT(*) FROM sales_targets t WHERE {$clause}", $bindings);

        Http::list($rows, $total, $params['limit'], $params['offset']);
    }

    public static function create(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'target.manage');

        $body = Http::body();
        $targetScope = (string) ($body['target_scope'] ?? 'company');
        if (!in_array($targetScope, self::SCOPES, true)) {
            Http::validationFailed('A target is set for the company, a salesperson, a territory or a channel.', ['field' => 'target_scope']);
        }

        $metric = (string) ($body['metric'] ?? 'value');
        if (!in_array($metric, self::METRICS, true)) {
            Http::validationFailed('Unknown target metric.', ['field' => 'metric']);
        }

        $start = self::date($body['period_start'] ?? null, 'period_start');
        $end = self::date($body['period_end'] ?? null, 'period_end');
        if ($start > $end) {
            Http::validationFailed('The target period starts after it ends.', ['field' => 'period_end']);
        }

        $value = (float) ($body['target_value'] ?? 0);
        if ($value <= 0) {
            // A zero target makes every month a triumph and every attainment
            // percentage meaningless. Refuse it rather than storing nonsense.
            Http::validationFailed('A target has to be more than zero.', ['field' => 'target_value']);
        }

        $ownerId = self::ownerFor($targetScope, $body);

        // Two overlapping company targets for the same metric make "the target"
        // ambiguous, and the dashboard would silently pick one.
        $clash = Db::first(
            'SELECT target_id FROM sales_targets
              WHERE cmp_id = :cmp AND fy_id = :fy AND target_scope = :scope AND metric = :metric
                AND COALESCE(salesperson_id, 0) = :sp AND COALESCE(territory_id, 0) = :ter AND COALESCE(channel_id, 0) = :ch
                AND period_start <= :end::date AND period_end >= :start::date',
            [
                'cmp' => $ctx->cmpId, 'fy' => $ctx->fyId, 'scope' => $targetScope, 'metric' => $metric,
                'sp' => $ownerId['salesperson_id'] ?? 0, 'ter' => $ownerId['territory_id'] ?? 0,
                'ch' => $ownerId['channel_id'] ?? 0, 'start' => $start, 'end' => $end,
            ],
        );
        if ($clash !== null) {
            Http::conflict('A target for that scope and period already exists.', ['target_id' => (int) $clash['target_id']]);
        }

        $targetId = (int) Db::insert('sales_targets', [
            'cmp_id'       => $ctx->cmpId,
            'fy_id'        => $ctx->fyId,
            'bo_id'        => $ctx->boId,
            'target_scope' => $targetScope,
            'salesperson_id' => $ownerId['salesperson_id'],
            'territory_id' => $ownerId['territory_id'],
            'channel_id'   => $ownerId['channel_id'],
            'period_start' => $start,
            'period_end'   => $end,
            'metric'       => $metric,
            'target_value' => $value,
            'notes'        => self::text($body['notes'] ?? null),
            'created_by'   => $auth->uuid,
        ], 'target_id');

        Audit::record($ctx, $auth, 'target.created', 'target', $targetId, null, [
            'target_scope' => $targetScope, 'metric' => $metric, 'target_value' => $value,
            'period' => $start . '..' . $end,
        ]);

        Http::data(Db::first('SELECT * FROM sales_targets WHERE target_id = :id', ['id' => $targetId]) ?? [], 201);
    }

    public static function update(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'target.manage');

        $existing = Db::first(
            'SELECT * FROM sales_targets WHERE target_id = :id AND cmp_id = :cmp',
            ['id' => (int) $id, 'cmp' => $ctx->cmpId],
        );
        if ($existing === null) {
            Http::notFound('That target does not exist.');
        }

        $body = Http::body();
        $changes = [];
        if (isset($body['target_value'])) {
            $value = (float) $body['target_value'];
            if ($value <= 0) {
                Http::validationFailed('A target has to be more than zero.', ['field' => 'target_value']);
            }
            $changes['target_value'] = $value;
        }
        if (isset($body['notes'])) {
            $changes['notes'] = self::text($body['notes']);
        }
        if ($changes === []) {
            Http::validationFailed('Nothing to change.');
        }

        $changes['updated_at'] = gmdate('Y-m-d H:i:s');
        Db::update('sales_targets', $changes, ['target_id' => (int) $id, 'cmp_id' => $ctx->cmpId]);

        Audit::record($ctx, $auth, 'target.updated', 'target', (int) $id, $existing, $changes);

        Http::data(Db::first('SELECT * FROM sales_targets WHERE target_id = :id', ['id' => (int) $id]) ?? []);
    }

    public static function destroy(string $id): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'target.manage');

        $existing = Db::first(
            'SELECT * FROM sales_targets WHERE target_id = :id AND cmp_id = :cmp',
            ['id' => (int) $id, 'cmp' => $ctx->cmpId],
        );
        if ($existing === null) {
            Http::notFound('That target does not exist.');
        }

        Db::run('DELETE FROM sales_targets WHERE target_id = :id AND cmp_id = :cmp', ['id' => (int) $id, 'cmp' => $ctx->cmpId]);
        Audit::record($ctx, $auth, 'target.deleted', 'target', (int) $id, $existing, null);

        Http::data(['target_id' => (int) $id, 'deleted' => true]);
    }

    /** Targets beside what has been achieved against them, composed at read time. */
    public static function attainment(): void
    {
        [$auth, $ctx] = self::enter();
        Permissions::assert($ctx, $auth, 'reports.view');

        $from = Http::param('from') ?? gmdate('Y-m-01');
        $to = Http::param('to') ?? gmdate('Y-m-t', strtotime($from));

        $metrics = new MetricsService($ctx);

        Http::data([
            'period'  => ['from' => $from, 'to' => $to],
            'company' => $metrics->companyTarget($from, $to),
            'team'    => $metrics->teamPerformance($from, $to),
            'basis'   => 'Attainment is order value attributed to the salesperson recorded on each order. '
                . 'Orders with nobody on them are reported separately rather than shared out, because an order '
                . 'counted towards two people lets a team beat a target neither of them met.',
        ]);
    }

    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $body @return array{salesperson_id:?int, territory_id:?int, channel_id:?int} */
    private static function ownerFor(string $targetScope, array $body): array
    {
        $owner = ['salesperson_id' => null, 'territory_id' => null, 'channel_id' => null];
        $key = match ($targetScope) {
            'salesperson' => 'salesperson_id',
            'territory'   => 'territory_id',
            'channel'     => 'channel_id',
            default       => null,
        };

        if ($key === null) {
            return $owner;
        }

        $value = (int) ($body[$key] ?? 0);
        if ($value <= 0) {
            Http::validationFailed('Choose who this target is for.', ['field' => $key]);
        }
        $owner[$key] = $value;

        return $owner;
    }

    private static function date(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) !== 1) {
            Http::validationFailed('Give the date as YYYY-MM-DD.', ['field' => $field]);
        }

        return trim($value);
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
