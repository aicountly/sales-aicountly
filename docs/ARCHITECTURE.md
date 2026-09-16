# Aicountly Sales — architecture

Sales owns the **commercial commitment**: what we quoted, what was agreed, what
was ordered, and the orchestration of what other products must do about it.

It owns nothing else, and that is the whole design.

## The rule

**Authoritative data owned by another product is read LIVE, on the request that
needs it. It is never copied into this database.**

There is no synchronisation job in this product, no reconciliation cron, no
mirror table and no "cache" table holding another product's rows. There is
nothing to reconcile because there is no second copy of anything.

| Question | Answered by | How Sales gets it |
|---|---|---|
| Which company, branch, financial year? | **Manage** | `ManageClient`, live |
| What is this item, and is there stock? | **Inventory** | `InventoryClient`, live |
| What was invoiced, what is owed? | **Smart Books** | `BooksClient`, live |
| Who is this customer? | **Contacts** (identity) / **Books** (ledger) | live |
| What did we quote, agree and order? | **Sales** | its own tables |

## What is stored here, and what is not

`server-php/database/migrations/` is the complete list. Every table is a Sales
document or a Sales policy. The reference columns — `item_id`,
`customer_account_id`, `books_voucher_uuid`, `inventory_document_uuid` — point
at rows other products own. The name, the price today, the balance and the
stock behind them are fetched over HTTP.

**Three columns look like exceptions and are not:**

- `sales_quotation_lines.rate` — the rate *that quotation* agreed. A fact about
  our document, still true when the item's price changes tomorrow.
- `sales_order_lines.delivered_qty` — progress against *our* commitment,
  written from Inventory's own response to our dispatch request. It answers "is
  this order complete?", never "how much stock is there?".
- `customer_name_snapshot` — the name printed on the document, frozen for legal
  integrity. Never read back as a party master.

**Two columns that are deliberately absent**, and their absence is tested:

- there is no `achieved_value` on `sales_targets`. Achievement is invoiced
  revenue, which is Books' answer, composed at read time.
- there is no customer balance anywhere. A copied credit limit is wrong the
  moment a receipt is entered elsewhere, and a credit check against a stale
  balance is worse than none — it says yes with authority.

## How a write to another product works

```
User presses Save
   │
   ├─ 1. IntegrationCommand::open()   mint the idempotency key and STORE it
   │                                   ← this ordering is the whole defence
   │                                     against a double-posted invoice
   ├─ 2. our own row is written and committed
   │
   └─ 3. call Books / Inventory with that key
            ok      → COMPLETED, store the id they returned
            5xx     → FAILED, retryable on the SAME key
            4xx     → BLOCKED, retrying will not help
```

If the network dies after Books wrote the voucher but before we saw the answer,
the retry presents the same key and Books replays its original response. One
invoice, not two.

`FAILED` and `BLOCKED` are kept apart on purpose: a UI that offers Retry on a
business refusal teaches people to press it twice.

## Why there is no reconciliation cron

A retry runs when something happens — the user presses Retry, or the next
request touching the document drains it. A scheduled job walking the command
table would be indistinguishable from the synchronisation this architecture
exists to avoid, and it would hide failures from the one person who could fix
them.

Anything unfinished is shown on the document it belongs to (`CommandStrip` in
the UI) and counted on the dashboard.

## One orchestration path per event

Sales posts an invoice to **Books and stops there**. Books' own established
contract with Inventory handles the stock side of a sale. Posting to both for
the same invoice is how a half-posted sale happens — accounted for but not
issued — and then something has to reconcile the two.

## Cross-service re-entry

Every outbound call carries `X-Saas-Origin: sales`, and this product refuses to
call back into whichever product is currently calling it
(`CrossServiceCallContext`). Not a recursion guard — nothing recurses. Each
product runs in its own PHP-FPM pool with a small `pm.max_children`, and a
synchronous call parks the calling worker for the whole round trip. A calls B,
B calls A back, and at a handful of concurrent users every child in both pools
is blocked on the other. See `books-react-app/docs/CROSS_SERVICE_CALL_RULES.md`.

Every call has a **connect** bound as well as an overall one, because a product
that is up but not accepting is held only by the connect timeout.

## Degrading honestly

When Books or Inventory cannot be reached:

- the Sales half of every screen still renders — it is ours;
- the remote half says *why* it is missing, and is never shown as zero. "Books
  did not answer" and "no sales this month" are different facts, and a zero that
  means the first is a lie the user will act on;
- a credit check that could not run reports `UNAVAILABLE`, never `ALLOW`.

## Running the tests

```bash
server-php/tests/run.sh
```

Against a real PostgreSQL and a stub standing in for Books and Inventory. The
suite includes the **release-blocking ownership tests**: they read
`information_schema` and fail if a mirror table, a cached remote field or a
stored balance has appeared anywhere in the schema.
