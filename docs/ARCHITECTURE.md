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

## Who can do what

Two products answer two different questions, and confusing them is what made
this app unusable for its own owner for a while.

**Manage answers WHO.** A person, their name and email, and which companies they
may open. `acs_type` on a Manage company row says what that person's access to
*that company* is — `1` is the owner. Sales reads it live and stores none of it
(`CompanyAccess`, fed from the company row `Context::assertAllowed()` already
fetches, falling back to Manage's company list).

**Sales answers WHAT.** `sales_permission_profiles` is a named set of codes from
`Permissions::CATALOG`; `sales_permission_assignments` ties a profile to a Manage
uuid. That uuid is the only thing about a person stored in this database — no
name, no email, no second user directory to drift out of step with the first.
Settings → Access is the screen; `AccessController` is the API.

The company owner holds the whole catalogue implicitly, so a company is usable
on its first day before anyone has configured a profile. **Ownership is not
assignable here** — granting it in Sales would be Sales overruling Manage on a
question Manage owns.

Two rules this has to keep:

- **Unknown is not owner.** A company Manage cannot describe resolves to `null`,
  and the check falls through to the profile table. An access check that fails
  open is not an access check — `when Manage cannot answer, nobody is an owner`
  in the suite pins the direction.
- **A test must not assert its own mock.** The original suite handed `Auth` a
  fake session containing `acs_type => 1`, so it stayed green while `acs_type`
  was being read from the portal session — which is not company-scoped and has
  never carried it. Every real user resolved to non-owner with zero permissions.
  The fixtures now seed `CompanyAccess` through the same door production writes
  to, and the stub answers `companies/{id}/share` and `validatesession` so the
  harness can exercise the *user* path rather than only the service-key path,
  where every permission check is bypassed by design.

## Degrading honestly

When Books or Inventory cannot be reached:

- the Sales half of every screen still renders — it is ours;
- the remote half says *why* it is missing, and is never shown as zero. "Books
  did not answer" and "no sales this month" are different facts, and a zero that
  means the first is a lie the user will act on;
- a credit check that could not run reports `UNAVAILABLE`, never `ALLOW`.

The same applies to permissions. A view built from several areas of the product
refuses only when the caller may see *nothing* on it (`requireAny`), and each
panel it cannot show says so — the Overview does not blank all five tabs because
one card needed `quotation.view`. A finished load with no metrics renders the
error, not the placeholders: a spinner that never resolves tells somebody the app
is slow when it is actually refusing.

## The dashboards

Five views, five endpoints, and each one loads only what it draws. A single fat
`/dashboard` would make the Overview wait for the receivables ageing it does not
show, which is how a dashboard ends up with a spinner people learn to scroll
past.

| View | Ours | Theirs, live |
|---|---|---|
| Overview | pipeline, commitments, priorities | invoiced sales, overdue (Books) |
| Pipeline & Quotations | lanes, conversion, follow-ups | — |
| Orders & Fulfilment | orders, delivery progress | availability (Inventory) |
| Customers & Collections | buyers, cadence, follow-ups | balances and ageing (Books) |
| Performance & Forecast | targets, projection, attribution | invoiced sales (Books) |

Every figure's definition, and what it does when it cannot be worked out, is in
[METRICS.md](METRICS.md). Two rules from it are worth repeating here because
they are the architecture showing through:

- **A KPI and its drilldown are one predicate.** `MetricsService` holds it, and
  both the card and the list it opens are built from it. They cannot disagree.
- **Missing is never zero.** A metric Books could not answer renders as
  "Unavailable" with the reason, not as a figure somebody would act on.

## The suggestions are rules, not a model

`InsightService` produces every suggestion on every dashboard, in PHP, from
records the user can open. Each one carries the ids it was derived from, and a
suggestion with no evidence is not returned at all — that rule is what stops the
panel becoming a horoscope. Its action is a name from a fixed list, resolved by
the browser against its own route table, so nothing crossing that boundary can
send anybody anywhere this application did not intend.

Where a provider is configured for the company in console.aicountly.org, AI may
rephrase the WORDING of a suggestion this product has already produced. It never
invents one, never supplies a figure, never picks the action, and the call is
server-side — there is no `VITE_` variable for a provider key, on purpose. With
nothing configured every suggestion still works, labelled "Rule-based insight".

## Running the tests

```bash
server-php/tests/run.sh      # PHP: domain, metrics, forecast, ownership
cd web && npm test           # React: formatting and the company-switch race
```

The PHP suite runs against a real PostgreSQL and a stub standing in for Books
and Inventory, so what is exercised is the actual SQL, the actual HTTP client
and the actual idempotency behaviour. It includes the **release-blocking
ownership tests**: they read `information_schema` and fail if a mirror table, a
cached remote field or a stored balance has appeared anywhere in the schema.

Alongside them are the tests for the claims the dashboards make — that a
superseded revision does not inflate the pipeline, that conversion is
unavailable rather than 0% when nothing has been decided, that converting a
quotation twice produces one order, and that the forecast does not count an
invoiced order twice.
