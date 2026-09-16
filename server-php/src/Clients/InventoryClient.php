<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Context;
use Aicountly\Api\Env;

/**
 * Live reads and writes against inventory.aicountly.com (API contract v1:
 * Inventory-aicountly/docs/INVENTORY_API_CONTRACT.md).
 *
 * Inventory is the ONLY authority for items, warehouses, batches, serials, stock
 * balances, availability, valuation and physical movement. Nothing this class
 * returns is stored in this product's database. Where a document of ours needs
 * an item, we keep `item_id` and the commercial facts *we* agreed (quantity,
 * rate, delivery date) — never the item's name, group, unit or stock.
 *
 * Two ways to authenticate, and the choice is not cosmetic:
 *
 *   withSession()  the signed-in user's portal ses_key. Inventory applies that
 *                  user's own permissions. Used for every read a screen makes.
 *   withService()  this product's INVENTORY_SERVICE_KEY plus the actor uuid.
 *                  Used for document writes, where we act as a product rather
 *                  than as a person and Inventory records source_app = sales.
 */
final class InventoryClient extends ApiClient
{
    private string $authorization = '';
    private string $serviceKey = '';
    private string $actorUuid = '';

    public function service(): string
    {
        return 'inventory';
    }

    protected function productionBase(): string
    {
        return 'https://inventory.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://inventory.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'INVENTORY_API_BASE';
    }

    public function withSession(string $sesKey): self
    {
        $this->authorization = 'Bearer ' . $sesKey;
        $this->serviceKey = '';

        return $this;
    }

    public function withService(string $actorUuid): self
    {
        $this->serviceKey = Env::get('INVENTORY_SERVICE_KEY');
        $this->actorUuid = $actorUuid;
        $this->authorization = '';

        return $this;
    }

    /** @return array<string, string> */
    private function authHeaders(): array
    {
        if ($this->serviceKey !== '') {
            return ['X-Service-Key' => $this->serviceKey, 'X-Actor-Uuid' => $this->actorUuid];
        }

        return ['Authorization' => $this->authorization];
    }

    /** @param array<string, mixed>|null $body */
    private function call(string $method, string $path, ?array $body = null, bool $required = false, array $extraHeaders = []): array
    {
        return $this->request($method, $path, $body, $this->authHeaders() + $extraHeaders, $required);
    }

    // -----------------------------------------------------------------------
    // Masters — read only. Inventory owns every one of these.
    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $filters */
    public function items(Context $ctx, array $filters = []): array
    {
        return $this->call('GET', 'v1/items' . self::query($filters + $ctx->asQuery()));
    }

    public function searchItems(Context $ctx, string $term, int $limit = 20): array
    {
        return $this->call('GET', 'v1/items/search' . self::query(['q' => $term, 'limit' => $limit] + $ctx->asQuery()));
    }

    public function item(Context $ctx, int $itemId): array
    {
        return $this->call('GET', 'v1/items/' . $itemId . self::query($ctx->asQuery()));
    }

    public function itemByBarcode(Context $ctx, string $code): array
    {
        return $this->call('GET', 'v1/items/by-barcode/' . rawurlencode($code) . self::query($ctx->asQuery()));
    }

    /**
     * One call for many items.
     *
     * Every screen that lists document lines needs the item names behind them.
     * Doing that per line is the loop the cross-service rules call out; this is
     * the hoisted version, and it is why nothing here is ever stored locally.
     *
     * @param list<int> $itemIds
     */
    public function bulkLookupItems(Context $ctx, array $itemIds): array
    {
        if ($itemIds === []) {
            return ['ok' => true, 'status' => 200, 'body' => ['data' => []], 'error' => null];
        }

        return $this->call('POST', 'v1/items/bulk-lookup', ['ids' => array_values(array_unique($itemIds))] + $ctx->asBody());
    }

    public function warehouses(Context $ctx): array
    {
        return $this->call('GET', 'v1/warehouses' . self::query(['limit' => 200] + $ctx->asQuery()));
    }

    public function uoms(Context $ctx): array
    {
        return $this->call('GET', 'v1/uom' . self::query(['limit' => 200] + $ctx->asQuery()));
    }

    public function itemGroups(Context $ctx): array
    {
        return $this->call('GET', 'v1/item-groups' . self::query(['limit' => 200] + $ctx->asQuery()));
    }

    public function batches(Context $ctx, int $itemId): array
    {
        return $this->call('GET', 'v1/batches' . self::query(['item_id' => $itemId, 'limit' => 200] + $ctx->asQuery()));
    }

    public function bom(Context $ctx, int $bomId): array
    {
        return $this->call('GET', 'v1/bill-of-materials/' . $bomId . self::query($ctx->asQuery()));
    }

    // -----------------------------------------------------------------------
    // Availability and stock — always live, never cached past the request
    // -----------------------------------------------------------------------

    public function availability(Context $ctx, int $itemId, ?int $warehouseId = null, ?int $batchId = null): array
    {
        return $this->call('GET', 'v1/availability' . self::query([
            'item_id' => $itemId, 'warehouse_id' => $warehouseId, 'batch_id' => $batchId,
        ] + $ctx->asQuery()));
    }

    /**
     * Available-to-promise for a whole document in one call.
     *
     * @param list<array{item_id:int, warehouse_id?:int|null, batch_id?:int|null, qty:float, unit_id?:int|null}> $lines
     */
    public function checkAvailability(Context $ctx, array $lines): array
    {
        return $this->call('POST', 'v1/availability/check', ['lines' => $lines] + $ctx->asBody());
    }

    public function stockBalances(Context $ctx, array $filters = []): array
    {
        return $this->call('GET', 'v1/stock-balances' . self::query($filters + $ctx->asQuery()));
    }

    public function replenishment(Context $ctx, array $filters = []): array
    {
        return $this->call('GET', 'v1/reports/replenishment' . self::query($filters + $ctx->asQuery()));
    }

    /** Unit cost, for a margin check. Read at the moment of the check; never stored as our own cost. */
    public function unitCosts(Context $ctx, array $itemIds, ?string $asOf = null): array
    {
        return $this->call('GET', 'v1/valuation/unit-costs' . self::query([
            'item_ids' => implode(',', $itemIds), 'as_of' => $asOf,
        ] + $ctx->asQuery()));
    }

    // -----------------------------------------------------------------------
    // Reservations — Inventory owns the reservation; we keep its uuid
    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $payload */
    public function createReservation(Context $ctx, array $payload, string $idempotencyKey): array
    {
        return $this->call('POST', 'v1/reservations', $payload + $ctx->asBody(), true, ['Idempotency-Key' => $idempotencyKey]);
    }

    public function releaseReservation(Context $ctx, int $reservationId, string $idempotencyKey): array
    {
        return $this->call('POST', 'v1/reservations/' . $reservationId . '/release', $ctx->asBody(), true, ['Idempotency-Key' => $idempotencyKey]);
    }

    public function fulfilReservation(Context $ctx, int $reservationId, array $payload, string $idempotencyKey): array
    {
        return $this->call('POST', 'v1/reservations/' . $reservationId . '/fulfil', $payload + $ctx->asBody(), true, ['Idempotency-Key' => $idempotencyKey]);
    }

    public function reservations(Context $ctx, array $filters = []): array
    {
        return $this->call('GET', 'v1/reservations' . self::query($filters + $ctx->asQuery()));
    }

    // -----------------------------------------------------------------------
    // Inventory documents — Inventory creates and owns them; we keep the uuid
    // -----------------------------------------------------------------------

    /**
     * Create and post in one transaction. The only write path we use for stock.
     *
     * @param array<string, mixed> $payload create payload per the v1 contract
     */
    public function postDocument(Context $ctx, array $payload, string $idempotencyKey): array
    {
        return $this->call('POST', 'v1/inventory-documents/post', $payload + $ctx->asBody(), true, ['Idempotency-Key' => $idempotencyKey]);
    }

    public function reverseDocument(Context $ctx, int $documentId, string $reason, string $idempotencyKey): array
    {
        return $this->call('POST', 'v1/inventory-documents/' . $documentId . '/reverse', ['reason' => $reason] + $ctx->asBody(), true, ['Idempotency-Key' => $idempotencyKey]);
    }

    public function document(Context $ctx, int $documentId): array
    {
        return $this->call('GET', 'v1/inventory-documents/' . $documentId . self::query($ctx->asQuery()));
    }

    public function documentByUuid(Context $ctx, string $uuid): array
    {
        return $this->call('GET', 'v1/inventory-documents/by-uuid/' . rawurlencode($uuid) . self::query($ctx->asQuery()));
    }

    /** Find what Inventory made of one of OUR documents — the reconciliation key, used instead of storing a copy. */
    public function documentBySource(Context $ctx, string $sourceApp, string $sourceType, int|string $sourceId): array
    {
        return $this->call('GET', 'v1/inventory-documents/by-source' . self::query([
            'source_app' => $sourceApp, 'source_document_type' => $sourceType, 'source_document_id' => $sourceId,
        ] + $ctx->asQuery()));
    }

    public function documents(Context $ctx, array $filters = []): array
    {
        return $this->call('GET', 'v1/inventory-documents' . self::query($filters + $ctx->asQuery()));
    }

    public function pendingQuantities(Context $ctx, array $filters = []): array
    {
        return $this->call('GET', 'v1/pending-quantities' . self::query($filters + $ctx->asQuery()));
    }

    public function settings(Context $ctx): array
    {
        return $this->call('GET', 'v1/settings' . self::query($ctx->asQuery()));
    }
}
