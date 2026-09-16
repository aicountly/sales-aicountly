/**
 * Shapes the Sales API returns.
 *
 * Note what is NOT here: there is no Item, Customer, Warehouse or Invoice type
 * with a full body. Where one of those appears it is an id, and the display
 * fields beside it come from a live call to the product that owns them. That is
 * the architecture showing through in the type definitions, and it is deliberate.
 */

export interface QuotationLine {
  line_id: number
  line_no: number
  item_id: number | null
  unit_id: number | null
  warehouse_id: number | null
  is_service: boolean
  description: string | null
  quantity: string
  rate: string
  discount_pc: string
  discount_amount: string
  tax_cat_id: number | null
  estimated_tax_pc: string
  line_amount: string
  is_optional: boolean
}

export interface ApprovalRequest {
  approval_id: number
  entity_type: string
  entity_id: number
  reason_kind: string
  reason_detail: string | null
  threshold_value: string | null
  actual_value: string | null
  status: string
  requested_by: string
  decided_by: string | null
  decided_at: string | null
  decision_note: string | null
  created_at: string
}

export interface Quotation {
  quotation_id: number
  quotation_uuid: string
  quotation_no: string
  quotation_date: string
  revision_no: number
  supersedes_id: number | null
  customer_account_id: number
  customer_name_snapshot: string | null
  salesperson_id: number | null
  territory_id: number | null
  channel_id: number | null
  price_book_id: number | null
  status: string
  valid_until: string | null
  currency_code: string
  subtotal_amount: string
  discount_amount: string
  estimated_tax_amount: string
  total_amount: string
  payment_terms: string | null
  delivery_terms: string | null
  notes: string | null
  customer_po_ref: string | null
  created_by: string
  approved_by: string | null
  created_at: string
  lines: QuotationLine[]
  approvals: ApprovalRequest[]
}

export interface OrderLine {
  line_id: number
  line_no: number
  item_id: number | null
  unit_id: number | null
  warehouse_id: number | null
  batch_id: number | null
  is_service: boolean
  description: string | null
  ordered_qty: string
  requested_qty: string
  delivered_qty: string
  invoiced_qty: string
  returned_qty: string
  rate: string
  discount_pc: string
  discount_amount: string
  estimated_tax_pc: string
  line_amount: string
  inventory_reservation_uuid: string | null
  committed_date: string | null
}

/**
 * A cross-service command and where it got to.
 *
 * This is what replaces a reconciliation job: anything unfinished is visible on
 * the document it belongs to, with the error that stopped it.
 */
export interface IntegrationCommand {
  command_id: number
  target_service: string
  command_type: string
  status: 'PENDING' | 'POSTING' | 'COMPLETED' | 'FAILED' | 'BLOCKED'
  attempts: number
  last_error: string | null
  external_reference: Record<string, unknown> | string | null
  last_attempt_at: string | null
  completed_at: string | null
}

export interface FulfilmentRequest {
  request_id: number
  request_kind: string
  status: string
  inventory_document_uuid: string | null
  inventory_document_no: string | null
  last_error: string | null
  created_at: string
}

export interface InvoiceRequest {
  request_id: number
  basis: string
  status: string
  books_voucher_id: number | null
  books_voucher_uuid: string | null
  books_voucher_no: string | null
  last_error: string | null
  created_at: string
}

export interface SalesOrder {
  order_id: number
  order_uuid: string
  order_no: string
  order_date: string
  quotation_id: number | null
  customer_account_id: number
  customer_name_snapshot: string | null
  customer_po_ref: string | null
  salesperson_id: number | null
  status: string
  fulfilment_mode: string
  requested_date: string | null
  committed_date: string | null
  warehouse_id: number | null
  currency_code: string
  subtotal_amount: string
  discount_amount: string
  estimated_tax_amount: string
  total_amount: string
  credit_decision: string | null
  credit_override_by: string | null
  cancel_reason: string | null
  created_at: string
  lines: OrderLine[]
  commands: IntegrationCommand[]
  fulfilments: FulfilmentRequest[]
  invoice_requests: InvoiceRequest[]
  /** Present only on the response to confirm(). */
  credit?: CreditVerdict
  reservation_error?: string
}

export interface CreditVerdict {
  decision: 'ALLOW' | 'WARN' | 'BLOCK' | 'APPROVAL_REQUIRED' | 'UNAVAILABLE'
  reason: string
  checked_at: string
  credit_limit: number | null
  outstanding: number | null
  overdue: number | null
  exposure_after: number | null
  oldest_overdue_days: number | null
}

export interface ReturnLine {
  line_id: number
  line_no: number
  order_line_id: number | null
  item_id: number | null
  return_qty: string
  received_qty: string
  rate: string
  line_amount: string
  condition_code: string
}

export interface ReturnRequest {
  return_id: number
  rma_no: string
  return_date: string
  order_id: number | null
  customer_account_id: number
  status: string
  reason_code: string | null
  reason_note: string | null
  resolution: string
  restock: boolean
  inventory_document_uuid: string | null
  books_credit_note_uuid: string | null
  lines: ReturnLine[]
  commands: IntegrationCommand[]
}

export interface DashboardSummary {
  period: { from: string; to: string }
  quotations: {
    total: number
    awaiting_approval: number
    awaiting_customer: number
    accepted: number
    converted: number
    total_value: number
    conversion_pc: number
  }
  orders: {
    total: number
    open: number
    partially_fulfilled: number
    reservation_pending: number
    total_value: number
  }
  backlog: { undelivered_value: number; uninvoiced_value: number }
  attention: { pending_approvals: number; stuck_commands: number }
  top_customers: Array<{
    customer_account_id: number
    customer_name_snapshot: string | null
    order_count: string
    total_value: string
  }>
  /** Books, live. `available: false` means Books did not answer — not zero sales. */
  financial: {
    available: boolean
    reason: string | null
    sales?: Record<string, unknown>
    receivable_total?: number
    receivable_count?: number
  }
}

/** An item as Inventory describes it. Rendered, never stored. */
export interface CatalogItem {
  item_id: number
  item_name: string
  item_sku: string | null
  item_alias: string | null
  unit_id: number | null
  hsn_sac: string | null
  mrp: string | null
  is_active: boolean
}

/** A customer as Books describes it (its party ledger). Rendered, never stored. */
export interface CatalogCustomer {
  acc_id: number
  acc_name: string
  gstin?: string | null
  credit_limit?: string | number | null
  credit_days?: string | number | null
}

export interface PriceBook {
  price_book_id: number
  book_code: string
  book_name: string
  currency_code: string
  scope_kind: string
  customer_account_id: number | null
  channel_id: number | null
  territory_id: number | null
  valid_from: string | null
  valid_to: string | null
  priority: number
  is_active: boolean
  rule_count?: string
  rules?: PriceBookRule[]
}

export interface PriceBookRule {
  rule_id: number
  item_id: number | null
  item_grp_id: number | null
  min_qty: string
  max_qty: string | null
  rate_kind: string
  rate: string
  discount_pc: string
  min_margin_pc: string | null
}

export interface Territory {
  territory_id: number
  territory_code: string
  territory_name: string
  parent_id: number | null
  is_active: boolean
}

export interface Channel {
  channel_id: number
  channel_code: string
  channel_name: string
  channel_kind: string
  is_active: boolean
}

export interface Salesperson {
  salesperson_id: number
  user_uuid: string
  display_code: string | null
  territory_id: number | null
  territory_name?: string | null
  channel_id: number | null
  channel_name?: string | null
  max_discount_pc: string
  is_active: boolean
}

export interface SalesSettings {
  cmp_id: number
  quotation_prefix: string
  order_prefix: string
  rma_prefix: string
  quotation_validity_days: number
  credit_control_mode: 'allow' | 'warn' | 'block' | 'approval_required'
  reserve_on_confirm: boolean
  default_invoice_basis: 'ordered' | 'delivered'
  require_quotation_approval_above_pc: string
  default_warehouse_id: number | null
}
