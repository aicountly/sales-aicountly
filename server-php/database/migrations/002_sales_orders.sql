-- ---------------------------------------------------------------------------
-- Aicountly Sales — orders, fulfilment orchestration, returns
--
-- The rule from 001 holds throughout: a column that names a row in Inventory or
-- Books is a REFERENCE. There is no stock here, no invoice here, no receivable
-- here. `sales_order_lines.delivered_qty` is the one number that looks like an
-- exception and is not — see the comment on it.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sales_orders (
    order_id        BIGSERIAL PRIMARY KEY,
    order_uuid      UUID         NOT NULL DEFAULT gen_random_uuid(),
    cmp_id          BIGINT       NOT NULL,
    fy_id           BIGINT       NOT NULL,
    bo_id           BIGINT       NOT NULL DEFAULT 0,
    order_no        TEXT         NOT NULL,
    order_date      DATE         NOT NULL,
    version_no      INT          NOT NULL DEFAULT 0,

    quotation_id    BIGINT       REFERENCES sales_quotations(quotation_id) ON DELETE SET NULL,

    customer_account_id BIGINT   NOT NULL,
    contact_id      TEXT,
    customer_name_snapshot TEXT,
    customer_po_ref TEXT,
    customer_po_date DATE,

    salesperson_id  BIGINT       REFERENCES sales_people(salesperson_id) ON DELETE SET NULL,
    territory_id    BIGINT       REFERENCES sales_territories(territory_id) ON DELETE SET NULL,
    channel_id      BIGINT       REFERENCES sales_channels(channel_id) ON DELETE SET NULL,
    price_book_id   BIGINT       REFERENCES sales_price_books(price_book_id) ON DELETE SET NULL,

    -- DRAFT | APPROVAL_PENDING | APPROVED | CONFIRMED | RESERVATION_PENDING
    -- | RESERVED | PARTIALLY_FULFILLED | FULFILLED | CLOSED | CANCELLED
    --
    -- This is the state of OUR workflow. It is not the state of the stock: that
    -- is Inventory's answer and is read from Inventory. An order can say
    -- RESERVED while Inventory has since released the reservation, which is
    -- exactly why the screen asks Inventory rather than trusting this column.
    status          TEXT         NOT NULL DEFAULT 'DRAFT',

    -- direct | pickup | ship_later | ship_from_store | dropship
    fulfilment_mode TEXT         NOT NULL DEFAULT 'direct',
    requested_date  DATE,
    committed_date  DATE,
    priority        TEXT         NOT NULL DEFAULT 'normal',

    warehouse_id    BIGINT,
    shipping_address JSONB,
    billing_address  JSONB,

    currency_code   TEXT         NOT NULL DEFAULT 'INR',
    exchange_rate   NUMERIC(18,6) NOT NULL DEFAULT 1,
    subtotal_amount      NUMERIC(18,4) NOT NULL DEFAULT 0,
    discount_amount      NUMERIC(18,4) NOT NULL DEFAULT 0,
    estimated_tax_amount NUMERIC(18,4) NOT NULL DEFAULT 0,
    total_amount         NUMERIC(18,4) NOT NULL DEFAULT 0,

    payment_terms   TEXT,
    delivery_terms  TEXT,
    incoterm        TEXT,
    notes           TEXT,

    -- The credit decision we made, and who overrode it. The BALANCE behind the
    -- decision is Books', was read live at the moment of the check, and is not
    -- stored: a copied credit balance is out of date by the next receipt.
    credit_decision      TEXT,
    credit_checked_at    TIMESTAMPTZ,
    credit_override_by   TEXT,
    credit_override_note TEXT,

    created_by      TEXT         NOT NULL,
    approved_by     TEXT,
    approved_at     TIMESTAMPTZ,
    confirmed_at    TIMESTAMPTZ,
    cancelled_at    TIMESTAMPTZ,
    cancel_reason   TEXT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, order_no)
);

CREATE INDEX IF NOT EXISTS idx_sales_orders_scope    ON sales_orders (cmp_id, fy_id, status, order_date DESC);
CREATE INDEX IF NOT EXISTS idx_sales_orders_customer ON sales_orders (cmp_id, customer_account_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_sales_orders_uuid ON sales_orders (order_uuid);

CREATE TABLE IF NOT EXISTS sales_order_lines (
    line_id         BIGSERIAL PRIMARY KEY,
    order_id        BIGINT       NOT NULL REFERENCES sales_orders(order_id) ON DELETE CASCADE,
    cmp_id          BIGINT       NOT NULL,
    line_no         INT          NOT NULL,
    quotation_line_id BIGINT     REFERENCES sales_quotation_lines(line_id) ON DELETE SET NULL,

    item_id         BIGINT,
    unit_id         BIGINT,
    warehouse_id    BIGINT,
    batch_id        BIGINT,
    is_service      BOOLEAN      NOT NULL DEFAULT FALSE,
    description     TEXT,

    ordered_qty     NUMERIC(18,4) NOT NULL DEFAULT 0,

    -- How much of OUR order we have so far asked Inventory to issue, and how
    -- much Inventory told us it issued. Progress against our own commitment —
    -- it answers "is this order complete?", never "how much stock is there?".
    -- Inventory remains the only authority for the movement itself; these are
    -- written from Inventory's own response to our request and from nothing else.
    requested_qty   NUMERIC(18,4) NOT NULL DEFAULT 0,
    delivered_qty   NUMERIC(18,4) NOT NULL DEFAULT 0,
    invoiced_qty    NUMERIC(18,4) NOT NULL DEFAULT 0,
    returned_qty    NUMERIC(18,4) NOT NULL DEFAULT 0,

    rate            NUMERIC(18,4) NOT NULL DEFAULT 0,
    discount_pc     NUMERIC(6,3)  NOT NULL DEFAULT 0,
    discount_amount NUMERIC(18,4) NOT NULL DEFAULT 0,
    tax_cat_id      BIGINT,
    estimated_tax_pc NUMERIC(6,3) NOT NULL DEFAULT 0,
    line_amount     NUMERIC(18,4) NOT NULL DEFAULT 0,

    -- What Inventory called the reservation it made for this line. A reference.
    inventory_reservation_uuid TEXT,
    inventory_reservation_id   BIGINT,

    requested_date  DATE,
    committed_date  DATE,
    parent_line_id  BIGINT       REFERENCES sales_order_lines(line_id) ON DELETE CASCADE,
    bom_id          BIGINT,

    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (order_id, line_no)
);

CREATE INDEX IF NOT EXISTS idx_sales_order_lines_item ON sales_order_lines (cmp_id, item_id);

-- Scheduled deliveries against a line — a commitment we made, not a movement.
CREATE TABLE IF NOT EXISTS sales_delivery_schedules (
    schedule_id     BIGSERIAL PRIMARY KEY,
    order_id        BIGINT       NOT NULL REFERENCES sales_orders(order_id) ON DELETE CASCADE,
    line_id         BIGINT       REFERENCES sales_order_lines(line_id) ON DELETE CASCADE,
    cmp_id          BIGINT       NOT NULL,
    scheduled_date  DATE         NOT NULL,
    scheduled_qty   NUMERIC(18,4) NOT NULL DEFAULT 0,
    warehouse_id    BIGINT,
    -- PLANNED | RELEASED | DISPATCHED | DELIVERED | CANCELLED
    status          TEXT         NOT NULL DEFAULT 'PLANNED',
    notes           TEXT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_sales_schedules_due ON sales_delivery_schedules (cmp_id, status, scheduled_date);

-- --------------------------------------------------------------------------
-- Fulfilment and invoice requests
--
-- Each row is a request WE made and the reference the owning product returned.
-- The document itself — the challan, the invoice — lives over there.
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sales_fulfilment_requests (
    request_id      BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    fy_id           BIGINT       NOT NULL,
    bo_id           BIGINT       NOT NULL DEFAULT 0,
    order_id        BIGINT       NOT NULL REFERENCES sales_orders(order_id) ON DELETE CASCADE,
    -- reserve | release | issue | return_receipt
    request_kind    TEXT         NOT NULL,
    -- REQUESTED | ACCEPTED | FAILED | CANCELLED
    status          TEXT         NOT NULL DEFAULT 'REQUESTED',
    warehouse_id    BIGINT,
    requested_lines JSONB        NOT NULL DEFAULT '[]'::jsonb,

    -- What Inventory made of it. Read the document itself from Inventory by uuid.
    inventory_document_id   BIGINT,
    inventory_document_uuid TEXT,
    inventory_document_no   TEXT,

    last_error      TEXT,
    requested_by    TEXT         NOT NULL,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_sales_fulfilment_order ON sales_fulfilment_requests (cmp_id, order_id);

CREATE TABLE IF NOT EXISTS sales_invoice_requests (
    request_id      BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    fy_id           BIGINT       NOT NULL,
    bo_id           BIGINT       NOT NULL DEFAULT 0,
    order_id        BIGINT       REFERENCES sales_orders(order_id) ON DELETE SET NULL,
    -- ordered | delivered | manual
    basis           TEXT         NOT NULL DEFAULT 'delivered',
    -- REQUESTED | POSTED | FAILED | CANCELLED
    status          TEXT         NOT NULL DEFAULT 'REQUESTED',
    requested_lines JSONB        NOT NULL DEFAULT '[]'::jsonb,

    -- Books owns the invoice. These three columns are the whole of what we keep:
    -- no amount, no tax, no party balance. The screen asks Books for those.
    books_voucher_id   BIGINT,
    books_voucher_uuid TEXT,
    books_voucher_no   TEXT,

    last_error      TEXT,
    requested_by    TEXT         NOT NULL,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_sales_invoice_req_order ON sales_invoice_requests (cmp_id, order_id);

-- --------------------------------------------------------------------------
-- Returns / RMA — we own the commercial decision, not the stock or the credit
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sales_return_requests (
    return_id       BIGSERIAL PRIMARY KEY,
    return_uuid     UUID         NOT NULL DEFAULT gen_random_uuid(),
    cmp_id          BIGINT       NOT NULL,
    fy_id           BIGINT       NOT NULL,
    bo_id           BIGINT       NOT NULL DEFAULT 0,
    rma_no          TEXT         NOT NULL,
    return_date     DATE         NOT NULL,

    order_id        BIGINT       REFERENCES sales_orders(order_id) ON DELETE SET NULL,
    customer_account_id BIGINT   NOT NULL,
    -- The Books invoice being returned against. A reference; its value is read live.
    books_invoice_uuid TEXT,
    books_invoice_id   BIGINT,

    -- DRAFT | SUBMITTED | APPROVED | REJECTED | RECEIVED | CREDITED | CLOSED | CANCELLED
    status          TEXT         NOT NULL DEFAULT 'DRAFT',
    reason_code     TEXT,
    reason_note     TEXT,
    -- refund | credit_note | replacement | repair
    resolution      TEXT         NOT NULL DEFAULT 'credit_note',
    restock         BOOLEAN      NOT NULL DEFAULT TRUE,

    -- Inventory receives the goods; Books issues the credit. Both are references.
    inventory_document_uuid TEXT,
    books_credit_note_uuid  TEXT,
    books_credit_note_id    BIGINT,

    approved_by     TEXT,
    approved_at     TIMESTAMPTZ,
    created_by      TEXT         NOT NULL,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (cmp_id, rma_no)
);

CREATE TABLE IF NOT EXISTS sales_return_lines (
    line_id         BIGSERIAL PRIMARY KEY,
    return_id       BIGINT       NOT NULL REFERENCES sales_return_requests(return_id) ON DELETE CASCADE,
    cmp_id          BIGINT       NOT NULL,
    line_no         INT          NOT NULL,
    order_line_id   BIGINT       REFERENCES sales_order_lines(line_id) ON DELETE SET NULL,
    item_id         BIGINT,
    unit_id         BIGINT,
    warehouse_id    BIGINT,
    batch_id        BIGINT,
    return_qty      NUMERIC(18,4) NOT NULL DEFAULT 0,
    received_qty    NUMERIC(18,4) NOT NULL DEFAULT 0,
    rate            NUMERIC(18,4) NOT NULL DEFAULT 0,
    line_amount     NUMERIC(18,4) NOT NULL DEFAULT 0,
    -- good | damaged | expired | wrong_item
    condition_code  TEXT         NOT NULL DEFAULT 'good',
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (return_id, line_no)
);

-- --------------------------------------------------------------------------
-- Approvals — a request, its decision and why
-- --------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sales_approval_requests (
    approval_id     BIGSERIAL PRIMARY KEY,
    cmp_id          BIGINT       NOT NULL,
    fy_id           BIGINT       NOT NULL,
    entity_type     TEXT         NOT NULL,   -- quotation | order | return
    entity_id       BIGINT       NOT NULL,
    -- discount | margin | credit | price_override | cancellation
    reason_kind     TEXT         NOT NULL,
    reason_detail   TEXT,
    threshold_value NUMERIC(18,4),
    actual_value    NUMERIC(18,4),
    -- PENDING | APPROVED | REJECTED | WITHDRAWN
    status          TEXT         NOT NULL DEFAULT 'PENDING',
    requested_by    TEXT         NOT NULL,
    decided_by      TEXT,
    decided_at      TIMESTAMPTZ,
    decision_note   TEXT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_sales_approvals_pending ON sales_approval_requests (cmp_id, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_sales_approvals_entity  ON sales_approval_requests (cmp_id, entity_type, entity_id);
