-- ---------------------------------------------------------------------------
-- Aicountly Sales — core schema
--
-- WHAT IS HERE: quotations, orders, price books, schedules, approvals — the
-- commercial commitment this product owns and nobody else records.
--
-- WHAT IS DELIBERATELY NOT HERE, and must never be added:
--   * an item master            -> Inventory owns it; we keep item_id
--   * a customer master         -> Contacts owns identity, Books owns the ledger
--   * a warehouse master        -> Inventory owns it; we keep warehouse_id
--   * a stock balance of any kind, including "cached"    -> ask Inventory, live
--   * an invoice, a ledger, a receivable, a GST figure   -> ask Books, live
--   * a company / branch / financial year master         -> Manage owns them
--
-- A reference column here (item_id, customer_account_id, books_invoice_uuid)
-- points at a row another product owns. The name, the price today, the balance
-- and the stock behind it are read over HTTP on the request that needs them.
--
-- The one exception, and it is not duplication: a quotation line keeps the rate
-- and quantity THAT QUOTATION agreed. That is a fact about our document, it
-- stays true when the item's price changes tomorrow, and it would be wrong to
-- re-read it.
-- ---------------------------------------------------------------------------

-- --------------------------------------------------------------------------
-- Permissions — this product's own, layered over the portal identity
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sales_permission_profiles (
    profile_id      BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    profile_name    TEXT         NOT NULL,
    description     TEXT,
    -- A JSON array of permission codes from Permissions::CATALOG.
    permissions     JSONB        NOT NULL DEFAULT '[]'::jsonb,
    is_system       BOOLEAN      NOT NULL DEFAULT FALSE,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, profile_name)
);

CREATE TABLE IF NOT EXISTS sales_permission_assignments (
    assignment_id   BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    -- The portal uuid. NOT a copy of the user: no name, no email, no password.
    user_uuid       TEXT         NOT NULL,
    profile_id      BIGINT       NOT NULL REFERENCES sales_permission_profiles(profile_id) ON DELETE CASCADE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, user_uuid, profile_id)
);

CREATE INDEX IF NOT EXISTS idx_sales_perm_assign_user ON sales_permission_assignments (cmp_id, user_uuid);

-- --------------------------------------------------------------------------
-- Territories, channels and sales people
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sales_territories (
    territory_id    BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    bo_id           BIGINT       NOT NULL DEFAULT 0,
    territory_code  TEXT         NOT NULL,
    territory_name  TEXT         NOT NULL,
    parent_id       BIGINT       REFERENCES sales_territories(territory_id) ON DELETE SET NULL,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, territory_code)
);

CREATE TABLE IF NOT EXISTS sales_channels (
    channel_id      BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    channel_code    TEXT         NOT NULL,
    channel_name    TEXT         NOT NULL,
    -- direct | dealer | distributor | retail | online | export
    channel_kind    TEXT         NOT NULL DEFAULT 'direct',
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, channel_code)
);

-- A salesperson is a portal user with a Sales role. Their name and login stay
-- with the portal; what is ours is the territory they cover and their limits.
CREATE TABLE IF NOT EXISTS sales_people (
    salesperson_id  BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    user_uuid       TEXT         NOT NULL,
    display_code    TEXT,
    territory_id    BIGINT       REFERENCES sales_territories(territory_id) ON DELETE SET NULL,
    channel_id      BIGINT       REFERENCES sales_channels(channel_id) ON DELETE SET NULL,
    reports_to      BIGINT       REFERENCES sales_people(salesperson_id) ON DELETE SET NULL,
    max_discount_pc NUMERIC(6,3) NOT NULL DEFAULT 0,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, user_uuid)
);

-- --------------------------------------------------------------------------
-- Price books — a COMMERCIAL policy, never an inventory valuation
--
-- Selling price and inventory cost are two different numbers that must never be
-- confused. Cost lives in Inventory and is read from there for a margin check.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sales_price_books (
    price_book_id   BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    bo_id           BIGINT       NOT NULL DEFAULT 0,
    book_code       TEXT         NOT NULL,
    book_name       TEXT         NOT NULL,
    currency_code   TEXT         NOT NULL DEFAULT 'INR',
    -- standard | customer | channel | territory | contract
    scope_kind      TEXT         NOT NULL DEFAULT 'standard',
    -- Books' account id for a customer-specific book; NULL otherwise. A reference.
    customer_account_id BIGINT,
    channel_id      BIGINT       REFERENCES sales_channels(channel_id) ON DELETE SET NULL,
    territory_id    BIGINT       REFERENCES sales_territories(territory_id) ON DELETE SET NULL,
    valid_from      DATE,
    valid_to        DATE,
    priority        INT          NOT NULL DEFAULT 100,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, book_code)
);

CREATE TABLE IF NOT EXISTS sales_price_book_rules (
    rule_id         BIGSERIAL PRIMARY KEY,
    price_book_id   BIGINT       NOT NULL REFERENCES sales_price_books(price_book_id) ON DELETE CASCADE,
    cmp_id          BIGINT       NOT NULL,
    -- Inventory's item id. The item's NAME, group and unit are not stored here.
    item_id         BIGINT,
    -- Inventory's item group id, for a rule that covers a whole group.
    item_grp_id     BIGINT,
    unit_id         BIGINT,
    min_qty         NUMERIC(18,4) NOT NULL DEFAULT 0,
    max_qty         NUMERIC(18,4),
    -- fixed | discount_pc | markup_pc_on_cost
    rate_kind       TEXT          NOT NULL DEFAULT 'fixed',
    rate            NUMERIC(18,4) NOT NULL DEFAULT 0,
    discount_pc     NUMERIC(6,3)  NOT NULL DEFAULT 0,
    -- The floor a discount may not cross without discount.override.
    min_margin_pc   NUMERIC(6,3),
    valid_from      DATE,
    valid_to        DATE,
    created_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    CHECK (item_id IS NOT NULL OR item_grp_id IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS idx_sales_price_rules_lookup
    ON sales_price_book_rules (cmp_id, item_id, min_qty);

-- --------------------------------------------------------------------------
-- Discount / promotion / scheme rules
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sales_discount_rules (
    discount_rule_id BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    rule_code       TEXT         NOT NULL,
    rule_name       TEXT         NOT NULL,
    -- line_pc | line_amount | bill_pc | bill_amount | buy_x_get_y | slab | scheme
    rule_kind       TEXT         NOT NULL,
    -- Item / group / channel / territory selectors and the rule's own parameters.
    conditions      JSONB        NOT NULL DEFAULT '{}'::jsonb,
    benefit         JSONB        NOT NULL DEFAULT '{}'::jsonb,
    -- Above this, quotation.approve is required before the document can leave draft.
    approval_above_pc NUMERIC(6,3),
    valid_from      DATE,
    valid_to        DATE,
    priority        INT          NOT NULL DEFAULT 100,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, rule_code)
);

-- --------------------------------------------------------------------------
-- Quotations
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sales_quotations (
    quotation_id    BIGSERIAL PRIMARY KEY,
    quotation_uuid  UUID         NOT NULL DEFAULT gen_random_uuid(),
    cmp_id          BIGINT       NOT NULL,
    fy_id           BIGINT       NOT NULL,
    bo_id           BIGINT       NOT NULL DEFAULT 0,
    quotation_no    TEXT         NOT NULL,
    quotation_date  DATE         NOT NULL,
    revision_no     INT          NOT NULL DEFAULT 0,
    -- The quotation this one supersedes; a revision keeps its predecessor readable.
    supersedes_id   BIGINT       REFERENCES sales_quotations(quotation_id) ON DELETE SET NULL,

    -- References. Identity lives in Contacts, the ledger in Books.
    customer_account_id BIGINT   NOT NULL,
    contact_id      TEXT,
    crm_opportunity_id TEXT,

    -- The name AS AGREED ON THIS DOCUMENT. Printed on the quotation, frozen for
    -- legal integrity; it is not the party master and is never read back as one.
    customer_name_snapshot TEXT,

    salesperson_id  BIGINT       REFERENCES sales_people(salesperson_id) ON DELETE SET NULL,
    territory_id    BIGINT       REFERENCES sales_territories(territory_id) ON DELETE SET NULL,
    channel_id      BIGINT       REFERENCES sales_channels(channel_id) ON DELETE SET NULL,
    price_book_id   BIGINT       REFERENCES sales_price_books(price_book_id) ON DELETE SET NULL,

    -- DRAFT | APPROVAL_PENDING | APPROVED | SENT | ACCEPTED | REJECTED
    -- | REVISION_REQUESTED | EXPIRED | CONVERTED | CANCELLED
    status          TEXT         NOT NULL DEFAULT 'DRAFT',
    valid_until     DATE,
    currency_code   TEXT         NOT NULL DEFAULT 'INR',
    exchange_rate   NUMERIC(18,6) NOT NULL DEFAULT 1,

    -- Our arithmetic on our own agreed numbers, for comparison and approval
    -- routing. The TAX a customer is actually charged is computed and posted by
    -- Books when the invoice is raised; what is stored here is an estimate shown
    -- on the quotation, and the column name says so.
    subtotal_amount      NUMERIC(18,4) NOT NULL DEFAULT 0,
    discount_amount      NUMERIC(18,4) NOT NULL DEFAULT 0,
    estimated_tax_amount NUMERIC(18,4) NOT NULL DEFAULT 0,
    total_amount         NUMERIC(18,4) NOT NULL DEFAULT 0,

    payment_terms   TEXT,
    delivery_terms  TEXT,
    incoterm        TEXT,
    lead_time_days  INT,
    notes           TEXT,
    terms_text      TEXT,
    customer_po_ref TEXT,

    created_by      TEXT         NOT NULL,
    approved_by     TEXT,
    approved_at     TIMESTAMPTZ,
    sent_at         TIMESTAMPTZ,
    decided_at      TIMESTAMPTZ,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, quotation_no, revision_no)
);

CREATE INDEX IF NOT EXISTS idx_sales_quotations_scope   ON sales_quotations (cmp_id, fy_id, status, quotation_date DESC);
CREATE INDEX IF NOT EXISTS idx_sales_quotations_customer ON sales_quotations (cmp_id, customer_account_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_sales_quotations_uuid ON sales_quotations (quotation_uuid);

CREATE TABLE IF NOT EXISTS sales_quotation_lines (
    line_id         BIGSERIAL PRIMARY KEY,
    quotation_id    BIGINT       NOT NULL REFERENCES sales_quotations(quotation_id) ON DELETE CASCADE,
    cmp_id          BIGINT       NOT NULL,
    line_no         INT          NOT NULL,

    -- Inventory's ids. Names, groups and stock are read from Inventory live.
    item_id         BIGINT,
    unit_id         BIGINT,
    warehouse_id    BIGINT,

    -- A service line has no item. Its description is the whole line.
    is_service      BOOLEAN      NOT NULL DEFAULT FALSE,
    description     TEXT,

    quantity        NUMERIC(18,4) NOT NULL DEFAULT 0,
    rate            NUMERIC(18,4) NOT NULL DEFAULT 0,
    discount_pc     NUMERIC(6,3)  NOT NULL DEFAULT 0,
    discount_amount NUMERIC(18,4) NOT NULL DEFAULT 0,
    -- What Books will charge is Books' answer; this is the rate we quoted for.
    tax_cat_id      BIGINT,
    estimated_tax_pc NUMERIC(6,3) NOT NULL DEFAULT 0,
    line_amount     NUMERIC(18,4) NOT NULL DEFAULT 0,

    -- Cost READ FROM INVENTORY at the moment the margin was checked, kept only
    -- so the approver can see the number the rule was judged against. It is not
    -- a cost this product owns, is never used for COGS, and is not refreshed.
    margin_check_cost NUMERIC(18,4),
    margin_check_at   TIMESTAMPTZ,

    -- A kit/bundle parent groups its components for presentation; the component
    -- structure itself is Inventory's BOM.
    parent_line_id  BIGINT       REFERENCES sales_quotation_lines(line_id) ON DELETE CASCADE,
    bom_id          BIGINT,
    is_optional     BOOLEAN      NOT NULL DEFAULT FALSE,

    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (quotation_id, line_no)
);

CREATE INDEX IF NOT EXISTS idx_sales_quotation_lines_item ON sales_quotation_lines (cmp_id, item_id);
