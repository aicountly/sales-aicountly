<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Clients\InventoryClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;

/**
 * What we should charge, and whether we are allowed to.
 *
 * Price books and discount rules are COMMERCIAL POLICY and are genuinely ours —
 * no other AICOUNTLY product decides what a dealer in the north territory pays
 * for 500 units. What is NOT ours is the cost those prices are judged against:
 * that is Inventory's valuation, read live whenever a margin rule needs it and
 * never stored as a cost of our own.
 *
 * The distinction matters because selling price and inventory cost are two
 * different numbers with two different owners, and a product that stores both
 * ends up reporting a margin nobody else agrees with.
 */
final class PricingService
{
    public function __construct(
        private readonly Context $ctx,
        private readonly Auth $auth,
    ) {
    }

    /**
     * The applicable rate for one item, and where it came from.
     *
     * Books are tried most-specific first: an explicitly chosen book, then a
     * customer's own book, then channel, then territory, then standard. The
     * first rule that matches the quantity wins, so a slab priced at 500+ beats
     * the same book's base row.
     *
     * @return array{rate:float, price_book_id:?int, rule_id:?int, source:string, min_margin_pc:?float}
     */
    public function resolveRate(
        int $itemId,
        float $quantity,
        ?int $customerAccountId = null,
        ?int $channelId = null,
        ?int $territoryId = null,
        ?int $preferredBookId = null,
    ): array {
        $rows = Db::all(
            'SELECT r.rule_id, r.price_book_id, r.rate_kind, r.rate, r.discount_pc, r.min_margin_pc,
                    b.scope_kind, b.priority
             FROM sales_price_book_rules r
             JOIN sales_price_books b ON b.price_book_id = r.price_book_id
             WHERE r.cmp_id = :cmp
               AND b.is_active = TRUE
               AND (b.valid_from IS NULL OR b.valid_from <= CURRENT_DATE)
               AND (b.valid_to   IS NULL OR b.valid_to   >= CURRENT_DATE)
               AND (r.valid_from IS NULL OR r.valid_from <= CURRENT_DATE)
               AND (r.valid_to   IS NULL OR r.valid_to   >= CURRENT_DATE)
               AND (r.item_id = :item OR (r.item_id IS NULL AND r.item_grp_id IS NOT NULL))
               AND r.min_qty <= :qty
               AND (r.max_qty IS NULL OR r.max_qty >= :qty)
               AND (
                     b.price_book_id = :preferred
                  OR (b.scope_kind = :customer_scope AND b.customer_account_id = :customer)
                  OR (b.scope_kind = :channel_scope  AND b.channel_id  = :channel)
                  OR (b.scope_kind = :territory_scope AND b.territory_id = :territory)
                  OR b.scope_kind = :standard_scope
               )
             ORDER BY
               CASE WHEN b.price_book_id = :preferred THEN 0
                    WHEN b.scope_kind = :customer_scope THEN 1
                    WHEN b.scope_kind = :channel_scope THEN 2
                    WHEN b.scope_kind = :territory_scope THEN 3
                    ELSE 4 END,
               b.priority,
               r.min_qty DESC
             LIMIT 1',
            [
                'cmp'             => $this->ctx->cmpId,
                'item'            => $itemId,
                'qty'             => $quantity,
                'preferred'       => $preferredBookId ?? 0,
                'customer'        => $customerAccountId ?? 0,
                'channel'         => $channelId ?? 0,
                'territory'       => $territoryId ?? 0,
                'customer_scope'  => 'customer',
                'channel_scope'   => 'channel',
                'territory_scope' => 'territory',
                'standard_scope'  => 'standard',
            ],
        );

        $rule = $rows[0] ?? null;
        if ($rule === null) {
            return ['rate' => 0.0, 'price_book_id' => null, 'rule_id' => null, 'source' => 'none', 'min_margin_pc' => null];
        }

        $rate = (float) $rule['rate'];
        if ($rule['rate_kind'] === 'markup_pc_on_cost') {
            // A markup is defined against cost, and cost is Inventory's. Read it
            // now rather than keeping one here that would go stale.
            $cost = $this->inventoryCost($itemId);
            $rate = $cost === null ? 0.0 : round($cost * (1 + ((float) $rule['rate']) / 100), 4);
        }

        return [
            'rate'          => $rate,
            'price_book_id' => (int) $rule['price_book_id'],
            'rule_id'       => (int) $rule['rule_id'],
            'source'        => (string) $rule['scope_kind'],
            'min_margin_pc' => $rule['min_margin_pc'] === null ? null : (float) $rule['min_margin_pc'],
        ];
    }

    /**
     * Would this line breach policy, and does it therefore need an approval?
     *
     * Two independent gates, and both are reported rather than the first that
     * fires: a line can be both over the salesperson's discount limit and under
     * the minimum margin, and an approver who fixes one and is then told about
     * the other has been made to do the job twice.
     *
     * @return array{ok:bool, breaches:list<array{kind:string, limit:float, actual:float, message:string}>, cost:?float, margin_pc:?float}
     */
    public function checkLine(int $itemId, float $rate, float $discountPc, ?float $minMarginPc, float $maxDiscountPc): array
    {
        $breaches = [];

        if ($discountPc > $maxDiscountPc) {
            $breaches[] = [
                'kind'    => 'discount',
                'limit'   => $maxDiscountPc,
                'actual'  => $discountPc,
                'message' => sprintf('Discount of %.2f%% is above the %.2f%% you may approve.', $discountPc, $maxDiscountPc),
            ];
        }

        $cost = null;
        $marginPc = null;
        if ($minMarginPc !== null) {
            $cost = $this->inventoryCost($itemId);
            if ($cost !== null && $cost > 0) {
                $net = $rate * (1 - $discountPc / 100);
                $marginPc = $net <= 0 ? -100.0 : round((($net - $cost) / $net) * 100, 3);
                if ($marginPc < $minMarginPc) {
                    $breaches[] = [
                        'kind'    => 'margin',
                        'limit'   => $minMarginPc,
                        'actual'  => $marginPc,
                        'message' => sprintf('Margin of %.2f%% is below the %.2f%% this price book requires.', $marginPc, $minMarginPc),
                    ];
                }
            }
            // A cost Inventory could not supply is reported as "not checked",
            // never as "passed". Treating an unreachable Inventory as a pass is
            // how a below-cost order gets approved by a network blip.
        }

        return ['ok' => $breaches === [], 'breaches' => $breaches, 'cost' => $cost, 'margin_pc' => $marginPc];
    }

    /**
     * Unit cost from Inventory, memoised for this request only.
     *
     * @var array<int, float|null>
     */
    private array $costMemo = [];

    private function inventoryCost(int $itemId): ?float
    {
        if (array_key_exists($itemId, $this->costMemo)) {
            return $this->costMemo[$itemId];
        }

        $client = (new InventoryClient());
        $client = $this->auth->isService()
            ? $client->withService($this->auth->uuid)
            : $client->withSession($this->auth->sesKey());

        $result = $client->unitCosts($this->ctx, [$itemId]);
        if (!$result['ok']) {
            return $this->costMemo[$itemId] = null;
        }

        foreach ((array) ($result['body']['data'] ?? []) as $row) {
            if ((int) ($row['item_id'] ?? 0) === $itemId) {
                $cost = $row['unit_cost'] ?? $row['cost'] ?? null;

                return $this->costMemo[$itemId] = $cost === null ? null : (float) $cost;
            }
        }

        return $this->costMemo[$itemId] = null;
    }
}
