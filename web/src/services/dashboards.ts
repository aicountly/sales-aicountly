/**
 * The shapes the five dashboard endpoints return.
 *
 * Note what a metric carries besides its number: a STATUS, a REASON when it
 * could not be read, a DEFINITION saying which dates and which statuses it
 * counted, and a DRILLDOWN naming the route and filters that reproduce it.
 * Those four are what let a user check a figure instead of believing it.
 */

import type { Metric } from '../ui'

export type { Metric }

export interface Freshness {
  generated_at: string
  sources: Array<{ what: string; owner: string }>
}

export interface InsightEvidence {
  kind: 'quotation' | 'order' | 'customer' | 'commands' | 'forecast'
  id: number
  label: string
  note?: string | null
}

export interface Insight {
  id: string
  origin: 'rule' | 'ai'
  title: string
  reason: string
  action: { kind: string; label: string }
  evidence: InsightEvidence[]
  as_of: string
  rule: Record<string, unknown>
  ai_enabled: boolean
}

export interface Priority {
  key: string
  icon: 'quotation' | 'order' | 'approval' | 'risk'
  title: string
  detail: string
  action: { kind: string; label: string }
  count: number
  value: number | null
}

export interface TrendPoint {
  date: string
  daily?: number
  cumulative?: number
  actual?: number | null
  projected?: number | null
  low?: number | null
  high?: number | null
}

export interface TargetInfo {
  configured: boolean
  value: number | null
  target_id: number | null
  period: { start: string; end: string } | null
  attainment_pc?: number | null
  projected_attainment_pc?: number | null
  gap?: number | null
}

interface DashboardBase {
  view: string
  period: { from: string; to: string; as_of: string }
  currency: string
  metrics: Metric[]
  insights: Insight[]
  freshness: Freshness
}

// --- 1. Overview -----------------------------------------------------------

export interface AttentionRow {
  order_id: number
  order_no: string
  customer_account_id: number
  customer_name_snapshot: string | null
  total_amount: string
  currency_code: string
  committed_date: string | null
  days_to_promise: number | null
  status: string
  issue: { kind: string; label: string; tone: 'danger' | 'warning' }
}

export interface OverviewDashboard extends DashboardBase {
  chart: {
    measure: 'invoiced' | 'orders'
    measure_label: string
    basis: string
    series: TrendPoint[]
    target: TargetInfo
    achieved: number | null
    as_of: string
    period_end: string
  }
  priorities: Priority[]
  attention: { rows: AttentionRow[]; inventory: { status: string; reason: string | null } }
}

// --- 2. Pipeline -----------------------------------------------------------

export interface QuotationCard {
  quotation_id: number
  quotation_no: string
  revision_no: number
  quotation_date: string
  valid_until: string | null
  customer_account_id: number
  customer_name_snapshot: string | null
  total_amount: string
  currency_code: string
  salesperson_id: number | null
  salesperson_code: string | null
  sent_at: string | null
  status: string
  stored_status: string
  last_change_at: string
  last_followup_on: string | null
  age_days: number
  days_to_expiry: number | null
}

export interface QuotationActionRow extends QuotationCard {
  days_since_contact: number | null
  approval_pending: boolean
}

export interface PipelineDashboard extends DashboardBase {
  lanes: Array<{ stage: string; label: string; total: number; cards: QuotationCard[] }>
  lane_stages: Array<{ stage: string; count: number; value: number }>
  needing_action: QuotationActionRow[]
}

// --- 3. Fulfilment ---------------------------------------------------------

export interface QueueRow {
  order_id: number
  order_no: string
  order_date: string
  customer_account_id: number
  customer_name_snapshot: string | null
  status: string
  committed_date: string | null
  requested_date: string | null
  total_amount: string
  currency_code: string
  warehouse_id: number | null
  fulfilment_mode: string
  days_to_promise: number | null
  line_count: string
  stock_line_count: string
  delivered_lines: string
  reserved_lines: string
  outstanding_qty: string
  uninvoiced_qty: string
  posted_invoices: string
  stuck_commands: string
}

export interface OrderAvailability {
  status: 'ready' | 'unavailable' | 'not_applicable'
  reason?: string | null
  read_at?: string
  full_lines?: number
  partial_lines?: number
  short_lines?: number
  requested_qty?: number
  available_qty?: number
  lines?: Array<{ line_id: number; item_id: number; requested_qty: number; available_qty: number; shortfall_qty: number }>
}

export interface FulfilmentDashboard extends DashboardBase {
  stages: { basis: string; stages: Array<{ key: string; label: string; count: number; description: string }> }
  queue: { rows: QueueRow[]; total: number; limit: number; offset: number }
  availability: { status: string; reason: string | null; read_at: string | null; by_order: Record<string, OrderAvailability> }
  commitments: Array<{
    order_id: number
    order_no: string
    customer_account_id: number
    customer_name_snapshot: string | null
    committed_date: string
    status: string
    total_amount: string
    currency_code: string
    days_to_promise: number
    outstanding_qty: string
    unreserved_lines: string
  }>
}

// --- 4. Collections --------------------------------------------------------

export interface AgeingBucket {
  key: string
  label: string
  value: number
  count: number
}

export interface CollectionRow {
  customer_account_id: number
  customer_name: string
  outstanding: number
  overdue: number
  bill_count: number
  oldest_due_date: string | null
  oldest_days: number
  last_contact_on: string | null
  last_contact_via: string | null
  last_contact_note: string | null
  promised_amount: string | null
  promised_on: string | null
  promise_outcome: string | null
}

export interface CollectionsDashboard extends DashboardBase {
  ageing: {
    status: string
    reason: string | null
    as_of: string
    total: number | null
    overdue: number | null
    buckets: AgeingBucket[]
    oldest_days: number | null
    bill_count: number | null
    basis: string
  }
  priorities: { status: string; reason: string | null; rows: CollectionRow[] }
  opportunities: {
    reorder_due: Array<{
      customer_account_id: number
      customer_name: string | null
      order_count: string
      last_order_date: string
      min_gap_days: string
      max_gap_days: string
      avg_gap_days: string
      days_since_last_order: string
      sufficient_history: boolean
    }>
    declining: Array<{
      customer_account_id: number
      customer_name: string
      current_value: string
      current_orders: string
      prior_value: string
      prior_orders: string
      change_pc: string | null
    }>
    top_customers: Array<{
      customer_account_id: number
      customer_name_snapshot: string | null
      order_count: string
      total_value: string
      currency_code: string
    }>
  }
}

// --- 5. Forecast -----------------------------------------------------------

export interface ForecastComponent {
  key: 'actual' | 'backlog' | 'pipeline'
  label: string
  value: number | null
  source: string
  detail: string
}

export interface Projection {
  available: boolean
  reason: string | null
  method: string
  label: string
  as_of: string
  period: { from: string; to: string }
  days_remaining: number
  actual: { status: string; value: number | null; reason: string | null; basis: string; source: string }
  components: ForecastComponent[]
  projected: { mid: number; low: number; high: number } | null
  range_basis: string
  assumptions: Array<{ key: string; label: string; value: number | null; measured: number | null; source: string; detail: string }>
  scenario: { applied: boolean; conversion_pc: number | null; discount_pc: number | null; note: string }
  target: TargetInfo
}

export interface TeamRow {
  salesperson_id: number
  display_code: string | null
  user_uuid: string
  order_value: number
  order_count: number
  target_value: number
  target_set: boolean
  attainment_pc: number | null
}

export interface ForecastDashboard extends DashboardBase {
  measure: { key: 'invoiced' | 'orders'; label: string; basis: string; fallback: boolean; fallback_reason: string | null }
  chart: { series: TrendPoint[]; target: TargetInfo; as_of: string; period_end: string; basis: string }
  forecast: Projection
  team: { rows: TeamRow[]; unattributed: { order_value: number; order_count: number } }
}
