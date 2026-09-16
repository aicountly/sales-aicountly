<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

/**
 * Live reads against manage.aicountly.com.
 *
 * Manage owns the company, the branch and the financial year. This product
 * stores `cmp_id`, `bo_id` and `fy_id` as bare references and nothing else — no
 * company name, no branch master, no financial-year dates. A screen that needs
 * the company's name or the year's start date asks here, on that request.
 *
 * That is not pedantry: a financial year whose dates were copied once is a year
 * that keeps the old dates after somebody corrects them, and every document
 * dated near the boundary then lands in the wrong year.
 */
final class ManageClient extends ApiClient
{
    private string $authorization = '';

    public function service(): string
    {
        return 'manage';
    }

    protected function productionBase(): string
    {
        return 'https://manage.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://manage.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'MANAGE_API_BASE';
    }

    public function withSession(string $sesKey): self
    {
        $this->authorization = 'Bearer ' . $sesKey;

        return $this;
    }

    /**
     * Company, its branches and its financial years, in one call.
     *
     * Deliberately one call and memoised for the request: the context check runs
     * on every scoped endpoint, and asking per endpoint would put Manage in the
     * hot path of every screen this product draws.
     */
    public function companyInfo(int $cmpId): array
    {
        return $this->request('GET', 'companyinfo' . self::query(['comp_id' => $cmpId]), null, ['Authorization' => $this->authorization]);
    }

    /** Companies this session may open — the company switcher. */
    public function companies(array $filters = []): array
    {
        return $this->request('GET', 'companies' . self::query($filters), null, ['Authorization' => $this->authorization]);
    }
}
