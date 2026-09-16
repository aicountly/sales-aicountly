<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Clients\ManageClient;

/**
 * The company / branch / financial year every scoped request carries.
 *
 * Three ids and nothing else. The names and dates behind them belong to Manage
 * and are read from Manage at the point of use.
 *
 * TENANT ISOLATION: `cmp_id` arriving in a query string is a claim, not a fact.
 * assertAllowed() checks it against what Manage says this session may open, and
 * a company the session has no access to is a 403 — never a query that simply
 * returns nothing, which would leak the difference between "no rows" and "not
 * yours" and would break the moment a query forgot its WHERE clause.
 */
final class Context
{
    /** @var array<string, bool> */
    private static array $verified = [];

    private function __construct(
        public readonly int $cmpId,
        public readonly int $fyId,
        /** 0 = consolidated, all branches. */
        public readonly int $boId,
    ) {
    }

    /** Read the scope out of the request, refusing anything incomplete. */
    public static function fromRequest(): self
    {
        $cmpId = Http::intParam('cmp_id', 0) ?? 0;
        $fyId  = Http::intParam('fy_id', 0) ?? 0;
        $boId  = Http::intParam('bo_id', 0) ?? 0;

        if ($cmpId <= 0 || $fyId <= 0) {
            Http::error(400, 'context_required', 'Pick a company and financial year first (cmp_id and fy_id are required).');
        }

        return new self($cmpId, $fyId, max(0, $boId));
    }

    /**
     * Confirm this session may open this company, per Manage.
     *
     * Memoised per request because it runs on every scoped endpoint; a failure to
     * reach Manage is a 503 and not an allow, because the alternative is serving
     * one tenant's data to another whenever Manage has a bad minute.
     */
    public function assertAllowed(Auth $auth): void
    {
        if ($auth->isService()) {
            // A service key is issued to a product, not to a person, and the
            // owning product has already checked the human behind it. What it
            // must still not do is act on a company outside the key's scope,
            // which ServiceKeyAuthenticator enforces when the key is resolved.
            return;
        }

        $key = $this->cmpId . ':' . $auth->fingerprint();
        if (isset(self::$verified[$key])) {
            return;
        }

        $result = (new ManageClient())->withSession($auth->sesKey())->companyInfo($this->cmpId);

        if (!$result['ok']) {
            // Unreachable is not "allowed". A tenant check that fails open is not
            // a tenant check.
            Http::error(503, 'context_unavailable', 'Cannot confirm company access right now. Please retry.');
        }

        $body = $result['body'] ?? [];
        $company = $body['data'] ?? $body['company'] ?? $body;
        $resolved = (int) ($company['cmp_id'] ?? $company['comp_id'] ?? $company['id'] ?? 0);

        if ($resolved !== $this->cmpId) {
            Http::forbidden('You do not have access to this company.');
        }

        self::$verified[$key] = true;
    }

    /** @return array{cmp_id:int, fy_id:int, bo_id:int} */
    public function asQuery(): array
    {
        return ['cmp_id' => $this->cmpId, 'fy_id' => $this->fyId, 'bo_id' => $this->boId];
    }

    /** @return array{cmp_id:int, fy_id:int, bo_id:int} */
    public function asBody(): array
    {
        return $this->asQuery();
    }

    /**
     * The WHERE fragment and bindings every query in this product starts with.
     *
     * `bo_id` 0 means consolidated, so it narrows only when it is set — a branch
     * user sees their branch, a company user sees everything.
     *
     * @return array{0:string, 1:array<string, mixed>}
     */
    public function scopeClause(string $alias = ''): array
    {
        $prefix = $alias === '' ? '' : $alias . '.';
        $sql = $prefix . 'cmp_id = :ctx_cmp_id AND ' . $prefix . 'fy_id = :ctx_fy_id';
        $params = ['ctx_cmp_id' => $this->cmpId, 'ctx_fy_id' => $this->fyId];

        if ($this->boId > 0) {
            $sql .= ' AND (' . $prefix . 'bo_id = :ctx_bo_id OR ' . $prefix . 'bo_id = 0)';
            $params['ctx_bo_id'] = $this->boId;
        }

        return [$sql, $params];
    }
}
