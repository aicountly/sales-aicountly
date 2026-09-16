-- ---------------------------------------------------------------------------
-- Aicountly Sales — what the five dashboards needed that the schema did not have
--
-- Three things, and none of them is a copy of another product's data:
--
--   1. HOW a quotation was sent. `sent_at` already existed, but nothing recorded
--      the channel or the reference, so "Sent" was a badge a click could set
--      without anything having been sent. A status that cannot be evidenced is
--      not a status.
--
--   2. Our own follow-up activity — the call we made, the reminder we sent, the
--      date the customer promised to pay. That conversation happened to us and
--      nobody else records it. The BALANCE it is about stays Books'; what is
--      stored here is what was said and when.
--
--   3. Indexes for the aggregates the dashboards run.
--
-- Deliberately still absent: any receivable balance, any ageing bucket, any
-- achieved-target figure, any stock number. Every one of those is composed at
-- read time from the product that owns it.
-- ---------------------------------------------------------------------------

-- --------------------------------------------------------------------------
-- 1. Evidence that a document actually went out
-- --------------------------------------------------------------------------

ALTER TABLE sales_quotations
    -- email | portal | whatsapp | printed | manual — how it left the building.
    ADD COLUMN IF NOT EXISTS sent_channel TEXT,
    -- The address, portal link id or "handed over at the meeting" note the
    -- sender recorded. A fact about OUR document, frozen like the name snapshot;
    -- never read back as a contact master.
    ADD COLUMN IF NOT EXISTS sent_reference TEXT,
    ADD COLUMN IF NOT EXISTS sent_by TEXT,
    -- Set only when a person closes off a quotation the customer let lapse.
    -- Expiry itself is DERIVED from valid_until at read time, so there is no
    -- nightly job whose failure would silently keep dead quotations "open".
    ADD COLUMN IF NOT EXISTS expired_at TIMESTAMPTZ;

-- --------------------------------------------------------------------------
-- 2. Follow-ups — ours, and only ours
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sales_followups (
    followup_id     BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    fy_id           BIGINT       NOT NULL,
    bo_id           BIGINT       NOT NULL DEFAULT 0,

    -- quotation | collection | reorder
    followup_kind   TEXT         NOT NULL,
    -- Books' account id. A reference: no name, no balance, no credit limit.
    customer_account_id BIGINT   NOT NULL,
    quotation_id    BIGINT       REFERENCES sales_quotations(quotation_id) ON DELETE CASCADE,
    order_id        BIGINT       REFERENCES sales_orders(order_id) ON DELETE CASCADE,

    -- call | email | whatsapp | meeting | portal | note
    channel         TEXT         NOT NULL DEFAULT 'note',
    contacted_on    DATE         NOT NULL DEFAULT CURRENT_DATE,
    note            TEXT,

    -- What the customer undertook to do. Our record of a conversation, not a
    -- copy of Books' balance: this is the number they SAID, which stays true
    -- whether or not they honour it.
    promised_amount NUMERIC(18,4),
    promised_on     DATE,
    -- open | kept | broken | cancelled
    outcome         TEXT         NOT NULL DEFAULT 'open',

    created_by      TEXT         NOT NULL,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_sales_followups_customer
    ON sales_followups (cmp_id, customer_account_id, contacted_on DESC);
CREATE INDEX IF NOT EXISTS idx_sales_followups_quotation
    ON sales_followups (cmp_id, quotation_id);
CREATE INDEX IF NOT EXISTS idx_sales_followups_promise
    ON sales_followups (cmp_id, promised_on)
    WHERE outcome = 'open' AND promised_on IS NOT NULL;

-- --------------------------------------------------------------------------
-- 3. Indexes the dashboard aggregates need
--
-- Every dashboard query is company + financial year + a date range. Without
-- these the pipeline board and the ageing panel sequentially scan the whole
-- quotation table on every page load.
-- --------------------------------------------------------------------------

CREATE INDEX IF NOT EXISTS idx_sales_quotations_validity
    ON sales_quotations (cmp_id, fy_id, valid_until)
    WHERE status IN ('DRAFT', 'APPROVAL_PENDING', 'APPROVED', 'SENT');

CREATE INDEX IF NOT EXISTS idx_sales_quotations_supersedes
    ON sales_quotations (supersedes_id)
    WHERE supersedes_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_sales_orders_committed
    ON sales_orders (cmp_id, fy_id, committed_date)
    WHERE status NOT IN ('CANCELLED', 'CLOSED');

CREATE INDEX IF NOT EXISTS idx_sales_orders_salesperson
    ON sales_orders (cmp_id, fy_id, salesperson_id, order_date);

-- One order per quotation, enforced rather than hoped for. Converting the same
-- quotation twice used to be a second commitment to the same customer for the
-- same goods, and nothing in the schema stopped it.
CREATE UNIQUE INDEX IF NOT EXISTS idx_sales_orders_one_per_quotation
    ON sales_orders (cmp_id, quotation_id)
    WHERE quotation_id IS NOT NULL AND status <> 'CANCELLED';
