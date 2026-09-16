-- ---------------------------------------------------------------------------
-- Aicountly Sales — targets, commission, portal acceptance, integration, audit
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sales_targets (
    target_id       BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    fy_id           BIGINT       NOT NULL,
    bo_id           BIGINT       NOT NULL DEFAULT 0,
    -- salesperson | territory | channel | company | item_group
    target_scope    TEXT         NOT NULL,
    salesperson_id  BIGINT       REFERENCES sales_people(salesperson_id) ON DELETE CASCADE,
    territory_id    BIGINT       REFERENCES sales_territories(territory_id) ON DELETE CASCADE,
    channel_id      BIGINT       REFERENCES sales_channels(channel_id) ON DELETE CASCADE,
    item_grp_id     BIGINT,
    period_start    DATE         NOT NULL,
    period_end      DATE         NOT NULL,
    -- value | quantity | orders | new_customers
    metric          TEXT         NOT NULL DEFAULT 'value',
    target_value    NUMERIC(18,4) NOT NULL DEFAULT 0,
    notes           TEXT,
    created_by      TEXT         NOT NULL,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_sales_targets_period ON sales_targets (cmp_id, fy_id, period_start, period_end);

-- NOTE: there is no `achieved_value` column, on purpose.
-- Achievement is invoiced revenue, and invoiced revenue is Books' answer. It is
-- composed at read time from the Books register. A stored achievement would be
-- a number that drifts from the accounts the moment an invoice is cancelled,
-- and nothing here would know.

CREATE TABLE IF NOT EXISTS sales_commission_rules (
    rule_id         BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    rule_code       TEXT         NOT NULL,
    rule_name       TEXT         NOT NULL,
    -- flat_pc | slab | per_unit | margin_share
    rule_kind       TEXT         NOT NULL DEFAULT 'flat_pc',
    applies_to      JSONB        NOT NULL DEFAULT '{}'::jsonb,
    slabs           JSONB        NOT NULL DEFAULT '[]'::jsonb,
    rate_pc         NUMERIC(6,3) NOT NULL DEFAULT 0,
    -- invoiced | collected — collected reads Books' receipts, invoiced its register
    basis           TEXT         NOT NULL DEFAULT 'invoiced',
    valid_from      DATE,
    valid_to        DATE,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, rule_code)
);

-- The CALCULATION is ours and is stored: it is a decision this product made,
-- and re-deriving last quarter's commission from today's rules would silently
-- restate what somebody was already paid. The revenue it was calculated FROM
-- stays Books', and the basis columns record which figures were used.
CREATE TABLE IF NOT EXISTS sales_commission_calculations (
    calculation_id  BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    fy_id           BIGINT       NOT NULL,
    rule_id         BIGINT       REFERENCES sales_commission_rules(rule_id) ON DELETE SET NULL,
    salesperson_id  BIGINT       REFERENCES sales_people(salesperson_id) ON DELETE CASCADE,
    period_start    DATE         NOT NULL,
    period_end      DATE         NOT NULL,
    basis_amount    NUMERIC(18,4) NOT NULL DEFAULT 0,
    commission_amount NUMERIC(18,4) NOT NULL DEFAULT 0,
    -- The Books documents this was computed from, as references, so the figure
    -- can be explained without holding a copy of any of them.
    source_references JSONB      NOT NULL DEFAULT '[]'::jsonb,
    -- DRAFT | APPROVED | PAID
    status          TEXT         NOT NULL DEFAULT 'DRAFT',
    calculated_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    approved_by     TEXT,
    approved_at     TIMESTAMPTZ,
    UNIQUE (cmp_id, rule_id, salesperson_id, period_start, period_end)
);

-- --------------------------------------------------------------------------
-- Customer portal acceptance — the customer's own act, which only we witness
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sales_portal_acceptances (
    acceptance_id   BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    quotation_id    BIGINT       NOT NULL REFERENCES sales_quotations(quotation_id) ON DELETE CASCADE,
    -- Long, random, single-purpose. Never a sequential id: a guessable portal
    -- link is one loop away from reading every customer's pricing.
    access_token_hash TEXT       NOT NULL,
    token_expires_at TIMESTAMPTZ NOT NULL,
    -- ACCEPTED | REJECTED | REVISION_REQUESTED
    decision        TEXT,
    decided_at      TIMESTAMPTZ,
    decided_name    TEXT,
    decided_email   TEXT,
    decided_ip      TEXT,
    comment         TEXT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (access_token_hash)
);

-- --------------------------------------------------------------------------
-- Integration commands — intent and outcome, never data
--
-- See src/IntegrationCommand.php. A row is "post this to Books" plus the key
-- that makes retrying it safe, plus what Books called the result. There is no
-- copy of any remote document here and therefore nothing to reconcile, which is
-- why this product has no reconciliation job.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sales_integration_commands (
    command_id      BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    fy_id           BIGINT       NOT NULL,
    bo_id           BIGINT       NOT NULL DEFAULT 0,
    target_service  TEXT         NOT NULL,     -- books | inventory | contacts
    command_type    TEXT         NOT NULL,     -- e.g. sales.invoice.request
    entity_type     TEXT         NOT NULL,     -- order | quotation | return
    entity_id       BIGINT       NOT NULL,
    -- Minted once, before the first call, and reused by every retry. This is
    -- what stops a timeout becoming a second invoice.
    idempotency_key TEXT         NOT NULL,
    -- PENDING | POSTING | COMPLETED | FAILED | BLOCKED
    status          TEXT         NOT NULL DEFAULT 'PENDING',
    attempts        INT          NOT NULL DEFAULT 0,
    -- Enough to retry the call. Not a copy of the answer.
    request_summary JSONB        NOT NULL DEFAULT '{}'::jsonb,
    -- Ids and numbers the other product returned. Nothing else from its body.
    external_reference JSONB,
    last_error      TEXT,
    last_attempt_at TIMESTAMPTZ,
    completed_at    TIMESTAMPTZ,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, idempotency_key)
);

CREATE INDEX IF NOT EXISTS idx_sales_commands_entity ON sales_integration_commands (cmp_id, entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_sales_commands_open   ON sales_integration_commands (cmp_id, status)
    WHERE status IN ('PENDING', 'POSTING', 'FAILED', 'BLOCKED');

-- --------------------------------------------------------------------------
-- Audit — ours only. Books audits vouchers, Inventory audits movements.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sales_audit_log (
    audit_id        BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    fy_id           BIGINT       NOT NULL,
    bo_id           BIGINT       NOT NULL DEFAULT 0,
    actor_uuid      TEXT         NOT NULL,
    actor_kind      TEXT         NOT NULL,
    source_app      TEXT         NOT NULL,
    action          TEXT         NOT NULL,
    entity_type     TEXT         NOT NULL,
    entity_id       TEXT,
    before_state    JSONB,
    after_state     JSONB,
    reason          TEXT,
    ip_address      TEXT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_sales_audit_entity ON sales_audit_log (cmp_id, entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_sales_audit_time   ON sales_audit_log (cmp_id, created_at DESC);

-- Append-only, and enforced rather than intended. The statutory retention for
-- an audit trail is eight years; a trail that can be edited is not a trail.
-- Row-level triggers deliberately do not fire on TRUNCATE, which is what lets a
-- test suite reset its schema. Nothing in production truncates this.
CREATE OR REPLACE FUNCTION sales_audit_immutable() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'sales_audit_log is append-only (attempted %)', TG_OP;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_sales_audit_no_update ON sales_audit_log;
CREATE TRIGGER trg_sales_audit_no_update
    BEFORE UPDATE ON sales_audit_log
    FOR EACH ROW EXECUTE FUNCTION sales_audit_immutable();

DROP TRIGGER IF EXISTS trg_sales_audit_no_delete ON sales_audit_log;
CREATE TRIGGER trg_sales_audit_no_delete
    BEFORE DELETE ON sales_audit_log
    FOR EACH ROW EXECUTE FUNCTION sales_audit_immutable();

-- --------------------------------------------------------------------------
-- Settings and saved views
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sales_settings (
    cmp_id              BIGINT       PRIMARY KEY,
    quotation_prefix    TEXT         NOT NULL DEFAULT 'QT',
    order_prefix        TEXT         NOT NULL DEFAULT 'SO',
    rma_prefix          TEXT         NOT NULL DEFAULT 'RMA',
    quotation_validity_days INT      NOT NULL DEFAULT 15,
    -- allow | warn | block | approval_required
    credit_control_mode TEXT         NOT NULL DEFAULT 'warn',
    -- Reserve stock when an order is confirmed, or only when it is picked.
    reserve_on_confirm  BOOLEAN      NOT NULL DEFAULT TRUE,
    -- ordered | delivered — what an invoice request bills by, by default.
    default_invoice_basis TEXT       NOT NULL DEFAULT 'delivered',
    require_quotation_approval_above_pc NUMERIC(6,3) NOT NULL DEFAULT 10,
    default_warehouse_id BIGINT,
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS sales_user_preferences (
    preference_id   BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    user_uuid       TEXT         NOT NULL,
    scope           TEXT         NOT NULL,
    payload         JSONB        NOT NULL DEFAULT '{}'::jsonb,
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, user_uuid, scope)
);
