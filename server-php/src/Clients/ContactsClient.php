<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Env;

/**
 * Live reads and writes against the authoritative party directory.
 *
 * A customer's identity — name, mobile, email, GSTIN, addresses — belongs to
 * Contacts. When a user adds a customer from inside this product the record is
 * created THERE and we keep the id. There is no customer table here.
 *
 * Books separately holds the same party's ACCOUNTING identity (the ledger).
 * Those are two different facts about one party and both are read live; neither
 * is copied here.
 *
 * Contacts is optional in a deployment. Where CONTACTS_API_BASE is unset the
 * caller falls back to Books' own party ledger, which is why every method
 * reports its failure rather than throwing: a missing directory degrades the
 * party picker, it does not break the sale.
 */
final class ContactsClient extends ApiClient
{
    private string $authorization = '';

    public function service(): string
    {
        return 'contacts';
    }

    protected function productionBase(): string
    {
        return 'https://contacts.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://contacts.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'CONTACTS_API_BASE';
    }

    public function withSession(string $sesKey): self
    {
        $this->authorization = 'Bearer ' . $sesKey;

        return $this;
    }

    public function configured(): bool
    {
        return Env::get('CONTACTS_API_BASE') !== '' || Env::get('CONTACTS_ENABLED') === '1';
    }

    public function search(string $term, array $filters = []): array
    {
        return $this->request('GET', 'api/v1/contacts' . self::query(['q' => $term, 'limit' => 20] + $filters), null, ['Authorization' => $this->authorization]);
    }

    public function contact(string $contactId): array
    {
        return $this->request('GET', 'api/v1/contacts/' . rawurlencode($contactId), null, ['Authorization' => $this->authorization]);
    }

    /** @param array<string, mixed> $payload */
    public function create(array $payload, string $idempotencyKey): array
    {
        return $this->request('POST', 'api/v1/contacts', $payload, [
            'Authorization'   => $this->authorization,
            'Idempotency-Key' => $idempotencyKey,
        ], true);
    }
}
