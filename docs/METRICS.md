# Aicountly Sales — what every number on a dashboard means

A figure on a dashboard is a claim, and a claim nobody can check is a claim
people eventually stop believing. This is the definition of each one: who owns
it, what dates it covers, which statuses it counts, and what it does when it
cannot be worked out.

The definitions live in code as well as here — `MetricsService` holds one
predicate per cohort and both the KPI and the list behind it are built from it,
so a card saying 14 opens a list of 14. The two cannot drift apart because
there is only one of them.

## The rules that apply to all of them

**Missing is never zero.** Every metric carries a `status`. `unavailable` and
`forbidden` render as those words with a reason underneath, never as 0.
"Smart Books did not answer" and "no sales this month" are different facts, and
a zero that means the first is a lie somebody will act on.

**A denominator of zero is not 0%.** A rate with nothing decided yet reports
`available: false`. "0% conversion" reads as "we convert nothing", which is a
much worse statement than "nothing has been decided".

**As-of and period are different questions.** A period metric covers dated
documents between `from` and `to`. An as-of metric is the state at `as_of`,
which defaults to today and never runs past `to`. Looking at September on the
16th means actuals to the 16th and the target for the whole month.

**Mixed currencies are not summed.** Where more than one currency is in use the
card carries a warning rather than adding them together.

---

## Owned by Sales

| Metric | Basis | Included | Excluded | Unavailable when |
|---|---|---|---|---|
| **Open quotation value** | As-of | Latest revision, status DRAFT / APPROVAL_PENDING / APPROVED / SENT, validity not passed | Superseded revisions, converted, cancelled, rejected, lapsed | never — it is ours |
| **Confirmed order value** | Period, by `order_date` | CONFIRMED, RESERVATION_PENDING, RESERVED, PARTIALLY_FULFILLED, FULFILLED, CLOSED | DRAFT, CANCELLED | never |
| **Quote conversion** | Period, by `quotation_date` | accepted + converted, over accepted + converted + rejected + lapsed. Latest revision only | Drafts and quotations still inside their validity — they have not failed to convert | nothing decided in the period |
| **On-time delivery** | Period, by final dispatch date | Orders fully delivered in the period that carried a promise date, scored on their LAST dispatch | Partial deliveries until the order completes; orders with no promise date (reported separately) | nothing completed in the period |
| **Active buyers** | Period | Distinct customers with a confirmed order dated in the period | Quotations — a quotation nobody accepted does not make a customer | never |
| **Repeat purchase rate** | Period | Active buyers who also ordered earlier in the same financial year, over all active buyers | — | nobody ordered in the period |
| **Open orders / ready / at risk** | As-of | CONFIRMED, RESERVATION_PENDING, RESERVED, PARTIALLY_FULFILLED. "At risk" is a promise date already passed | FULFILLED, CLOSED, CANCELLED | never |
| **Target attainment** | Period | Actual over the company target covering the period | — | no target configured — reported as "Target not configured", never as a zero target |

### Quotation expiry is derived

`valid_until` in the past makes a quotation expired **at read time**. There is
no nightly job setting a flag, because a job that fails on a Sunday leaves dead
quotations counted as pipeline on Monday with nothing to say so. The one stored
column, `expired_at`, records a person closing one off deliberately.

### Attribution

An order belongs to the salesperson recorded on it, and to exactly one. Orders
with nobody on them are reported as a separate "unattributed" row rather than
shared out — an order counted towards two people lets a team beat a target
neither of them met.

---

## Owned by Smart Books, read live

Nothing below is stored in this product. Each is fetched on the request that
renders it, and its card degrades on its own when Books cannot be reached.

| Metric | Basis | Notes |
|---|---|---|
| **Net invoiced sales** | Period, by voucher date | Posted sales vouchers net of cancellations and credit notes, as Books reports them. Sales does not add up its own order lines and call the result revenue |
| **Outstanding** | As-of the cutoff | Everything customers owe at the date, **whenever it was invoiced**. Deliberately not narrowed to the selected month: an invoice raised in March and unpaid in September is outstanding today |
| **Overdue** | As-of the cutoff | The part of the outstanding past its due date. Rising overdue is never shown as a favourable trend |
| **Receivables ageing** | As-of the cutoff | Bucketed here (not due / 1–30 / 31–60 / 61+) from Books' own due dates and balances. Credits and advances are treated exactly as Books treats them |

---

## Owned by Inventory, read live

**Availability** on the fulfilment queue is Inventory's answer, fetched in one
batched call for the rows on screen, carrying the time it was read. Where
Inventory cannot answer the column says **Unknown** — never "In stock", which is
the one word on that screen somebody would promise a delivery on. A
services-only order reports `not_applicable` rather than sitting there looking
blocked by a stock question that does not apply to it.

---

## The forecast

Three components, each checkable:

```
projection = recognised so far
           + confirmed orders not yet invoiced, due on or before the period end
           + open quotations closing in the period × conversion rate × (1 − discount)
```

**The three double-counts it avoids**, each of which would flatter the number:

1. An accepted quotation that became an order — converted quotations are not
   open, so they are not in the pipeline cohort.
2. An order already invoiced — the backlog uses only the **uninvoiced** portion
   of each line, so an order half billed contributes only its other half.
3. A quotation that cannot be decided until next month — only quotations whose
   validity ends inside the period are eligible.

The conversion rate is the one this company actually achieves: the period's own
rate when at least five quotations have been decided in it, otherwise the
financial year to date. **When nothing has ever been decided there is no rate**,
the pipeline component is zero, and the panel says so. An invented conversion
rate is an invented forecast.

The range is a **scenario band, not a confidence interval**, and is labelled as
such: the same arithmetic with the conversion assumption moved 40% either way.
Only the pipeline component moves — money already invoiced is not uncertain and
must not be drawn as though it were.

**No language model produces any of these numbers.** AI may be asked to explain
the arithmetic in the company's own words when a provider is configured; the
figures, the thresholds and the ranking are code.

---

## Order status is five questions, not one

A single status column was being asked to answer all of these at once, and
different readers took it to mean different ones:

| Facet | Question | Owner |
|---|---|---|
| `approval` | Is this order agreed internally? | Sales |
| `stock` | Has Inventory held what we promised? | Sales' record of Inventory's answer |
| `fulfilment` | How much of it has actually left? | Sales, written from Inventory's responses |
| `invoice` | Has Books billed it? | Sales' record of the voucher Books created |
| `payment` | Has the customer paid? | **Books. Deliberately not answered here** |

The last one is not inferred. A payment state guessed from our own invoice
requests would say "paid" about money nobody has received.
