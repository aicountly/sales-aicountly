import { useNavigate } from 'react-router-dom'
import { AlertTriangle, ClipboardList, Info, Package, Plus, Truck } from 'lucide-react'
import { api } from '../../services/api'
import type { FulfilmentDashboard, OrderAvailability, QueueRow } from '../../services/dashboards'
import { useApi } from '../../hooks/useApi'
import { useSales } from '../../context/SalesContext'
import {
  Badge,
  Button,
  DataState,
  DataTable,
  date,
  Notice,
  Pagination,
  Panel,
  qty,
  relativeDays,
  Skeleton,
  timeAgo,
} from '../../ui'
import { DASHBOARDS, DashboardFrame, InsightCard, MetricsSection, usePeriod } from './frame'

const VIEW = DASHBOARDS[2]

const ICONS = {
  open_orders: <ClipboardList size={17} />,
  ready_for_dispatch: <Package size={17} />,
  at_risk: <AlertTriangle size={17} />,
  on_time_delivery: <Truck size={17} />,
}

/**
 * Dashboard 3 — what we promised, what is actually available, and what is late.
 *
 * AVAILABILITY IS INVENTORY'S, READ NOW. It arrives with the rows and carries
 * the time it was read. Where Inventory could not answer, the column says
 * "Unknown" — never "In stock", which is the one word on this screen somebody
 * would promise a delivery on.
 *
 * THE STAGES ARE NOT A FUNNEL and the panel says so. An order that is reserved
 * is also confirmed; four descending numbers get read as drop-off unless
 * something stops the reader.
 */
export default function FulfilmentDashboardView() {
  const navigate = useNavigate()
  const { scope, can } = useSales()
  const { period, setMonth, params, setParam } = usePeriod()

  const risk = params.get('risk') ?? ''
  const offset = Number.parseInt(params.get('offset') ?? '0', 10) || 0
  const limit = 10

  const { data, loading, error, reload } = useApi<{ data: FulfilmentDashboard }>(
    (signal) =>
      api.one<FulfilmentDashboard>(
        'v1/dashboard/fulfilment',
        { from: period.from, to: period.to, as_of: period.as_of, risk: risk || undefined, limit, offset },
        signal,
      ),
    [scope?.cmp_id, scope?.fy_id, scope?.bo_id, period.from, period.to, period.as_of, risk, offset],
    Boolean(scope),
  )

  const dashboard = data?.data
  const currency = dashboard?.currency ?? 'INR'
  const availability = dashboard?.availability

  return (
    <DashboardFrame
      view={VIEW}
      period={period}
      onMonthChange={setMonth}
      freshness={dashboard?.freshness}
      onRefresh={reload}
      error={error}
      onRetry={reload}
      primaryAction={
        can('order.create') ? (
          <Button tone="primary" onClick={() => navigate('/orders/new')}>
            <Plus size={16} aria-hidden /> New sales order
          </Button>
        ) : undefined
      }
      filters={
        <label>
          Delivery risk
          <select
            value={risk}
            onChange={(event) => {
              setParam('risk', event.target.value || null)
              setParam('offset', null)
            }}
          >
            <option value="">All open orders</option>
            <option value="due_soon">Due within 7 days</option>
            <option value="late">Promise date passed</option>
          </select>
        </label>
      }
    >
      <MetricsSection metrics={dashboard?.metrics} currency={currency} loading={loading} icons={ICONS} />

      {availability?.status === 'unavailable' && (
        <div style={{ marginBottom: 20 }}>
          <Notice tone="warning" title="Inventory did not answer">
            {availability.reason} Everything below is from Sales and is unaffected; the availability column shows
            Unknown rather than a stock figure nobody checked.
          </Notice>
        </div>
      )}

      <div className="sales-dashboard-grid">
        <div className="sales-main">
          <Panel
            title="Order progress"
            description={dashboard?.stages.basis}
            action={
              <span className="sales-note sales-row" style={{ gap: 5 }}>
                <Info size={13} aria-hidden /> Independent counts
              </span>
            }
          >
            {loading || !dashboard ? (
              <Skeleton height={130} />
            ) : (
              <div className="sales-facets">
                {dashboard.stages.stages.map((stage) => (
                  <div key={stage.key} className="sales-facet">
                    <div className="sales-metric-label">{stage.label}</div>
                    <div className="sales-metric-value" style={{ fontSize: 28 }}>
                      {stage.count}
                    </div>
                    <p>{stage.description}</p>
                  </div>
                ))}
              </div>
            )}
          </Panel>

          <Panel
            title="Fulfilment work queue"
            description={
              availability?.read_at
                ? `Availability read from Inventory ${timeAgo(availability.read_at)}`
                : 'Availability comes from Inventory, live'
            }
            flush
            footer={
              <Pagination
                total={dashboard?.queue.total ?? 0}
                limit={limit}
                offset={offset}
                label="orders"
                onChange={(next) => setParam('offset', next === 0 ? null : String(next))}
              />
            }
          >
            <DataTable<QueueRow>
              loading={loading}
              caption="Fulfilment work queue"
              rows={dashboard?.queue.rows ?? []}
              rowKey={(row) => row.order_id}
              onRowClick={(row) => navigate(`/orders/${row.order_id}`)}
              empty={risk ? 'No order matches that risk filter.' : 'Nothing is waiting to go out.'}
              columns={[
                {
                  key: 'order',
                  header: 'Order',
                  render: (row) => (
                    <>
                      <span className="sales-cell-primary">{row.order_no}</span>
                      <span className="sales-cell-sub">{date(row.order_date)}</span>
                    </>
                  ),
                },
                {
                  key: 'customer',
                  header: 'Customer',
                  render: (row) => row.customer_name_snapshot ?? `Account ${row.customer_account_id}`,
                },
                {
                  key: 'promise',
                  header: 'Promise date',
                  render: (row) => (
                    <>
                      {date(row.committed_date)}
                      {row.days_to_promise !== null && (
                        <span className={`sales-cell-sub${row.days_to_promise < 0 ? ' sales-tone-negative' : ''}`}>
                          {relativeDays(row.days_to_promise)}
                        </span>
                      )}
                    </>
                  ),
                },
                {
                  key: 'stock',
                  header: 'Availability',
                  render: (row) => <AvailabilityCell live={availability?.by_order[String(row.order_id)]} />,
                },
                { key: 'fulfilment', header: 'Fulfilment', render: (row) => <FulfilmentCell row={row} /> },
                { key: 'invoice', header: 'Invoice', render: (row) => <InvoiceCell row={row} /> },
                {
                  key: 'action',
                  header: '',
                  render: (row) => (
                    <Button small onClick={() => navigate(`/orders/${row.order_id}`)}>
                      {Number(row.stuck_commands) > 0 ? 'Fix' : Number(row.delivered_lines) > 0 ? 'Track' : 'Review'}
                    </Button>
                  ),
                },
              ]}
            />
          </Panel>
        </div>

        <aside className="sales-aside" aria-label="Delivery commitments and suggestions">
          <Panel
            title="Delivery risk"
            description="Commitments in the next fortnight"
            action={
              <Button small onClick={() => setParam('risk', 'due_soon')}>
                View all
              </Button>
            }
          >
            {loading ? (
              <Skeleton height={160} />
            ) : (dashboard?.commitments.length ?? 0) === 0 ? (
              <DataState status="empty" message="No delivery is promised in the next fortnight." />
            ) : (
              <ul style={{ listStyle: 'none', padding: 0, margin: 0, display: 'grid', gap: 12 }}>
                {dashboard!.commitments.map((commitment) => {
                  const late = commitment.days_to_promise < 0
                  const unreserved = Number(commitment.unreserved_lines) > 0

                  return (
                    <li key={commitment.order_id}>
                      <div className="sales-row-between" style={{ gap: 8 }}>
                        <div style={{ minWidth: 0 }}>
                          <div className="sales-note">{date(commitment.committed_date)}</div>
                          <Button
                            tone="ghost"
                            small
                            onClick={() => navigate(`/orders/${commitment.order_id}`)}
                            style={{ padding: 0 }}
                          >
                            {commitment.order_no}
                          </Button>
                          <div className="sales-note sales-truncate">
                            {commitment.customer_name_snapshot ?? `Account ${commitment.customer_account_id}`}
                          </div>
                        </div>
                        <div style={{ textAlign: 'right', flex: 'none' }}>
                          <div className="sales-numeric">{qty(commitment.outstanding_qty)} units</div>
                          <Badge tone={late ? 'danger' : unreserved ? 'warning' : 'success'} dot>
                            {late ? 'Overdue' : unreserved ? 'At risk (partial)' : 'On track'}
                          </Badge>
                        </div>
                      </div>
                    </li>
                  )
                })}
              </ul>
            )}
          </Panel>

          {loading ? (
            <Skeleton height={180} />
          ) : (dashboard?.insights.length ?? 0) > 0 ? (
            dashboard!.insights.map((insight) => (
              <InsightCard
                key={insight.id}
                insight={insight}
                currency={currency}
                onAction={() => {
                  const order = insight.evidence.find((item) => item.kind === 'order')
                  if (order) navigate(`/orders/${order.id}`)
                }}
              />
            ))
          ) : (
            <Panel title="Dispatch suggestions">
              <DataState
                status="empty"
                message={
                  availability?.status === 'unavailable'
                    ? 'None: Inventory could not be read, and a partial-delivery suggestion built on a stale stock figure is advice to ship goods that may not be there.'
                    : 'None — nothing due soon is short of stock.'
                }
              />
            </Panel>
          )}
        </aside>
      </div>
    </DashboardFrame>
  )
}

/**
 * What Inventory says, or an honest admission that it did not say anything.
 *
 * `not_applicable` is its own case: a services-only order has no stock question
 * and must not sit on the queue looking blocked by one.
 */
function AvailabilityCell({ live }: { live?: OrderAvailability }) {
  if (!live) return <span className="sales-muted">—</span>

  if (live.status === 'not_applicable') {
    return <Badge>No stock lines</Badge>
  }
  if (live.status === 'unavailable') {
    return (
      <Badge tone="warning" dot>
        Unknown
      </Badge>
    )
  }

  const short = live.short_lines ?? 0
  const partial = live.partial_lines ?? 0

  if (short === 0) {
    return (
      <>
        <Badge tone="success" dot>
          Available
        </Badge>
        <span className="sales-cell-sub">
          {qty(live.available_qty)} of {qty(live.requested_qty)} available
        </span>
      </>
    )
  }

  return (
    <>
      <Badge tone={partial > 0 ? 'warning' : 'danger'} dot>
        {partial > 0 ? 'Partial' : 'Short'}
      </Badge>
      <span className="sales-cell-sub">
        {qty(live.available_qty)} of {qty(live.requested_qty)} available
      </span>
    </>
  )
}

/** Fulfilment progress — our own figure, from Inventory's answers to our dispatches. */
function FulfilmentCell({ row }: { row: QueueRow }) {
  const lines = Number(row.line_count)
  const delivered = Number(row.delivered_lines)
  const reserved = Number(row.reserved_lines)

  if (Number(row.stuck_commands) > 0) {
    return (
      <Badge tone="danger" dot>
        Request failed
      </Badge>
    )
  }
  if (delivered >= lines && lines > 0) return <Badge tone="success">Delivered</Badge>
  if (delivered > 0) return <Badge tone="warning">Part delivered</Badge>
  if (row.status === 'RESERVATION_PENDING') return <Badge tone="warning">Awaiting stock</Badge>
  if (reserved > 0) return <Badge tone="info">Ready</Badge>

  return <Badge>Not reserved</Badge>
}

/** Invoice status is its own question — and payment is Books', not ours to guess. */
function InvoiceCell({ row }: { row: QueueRow }) {
  const posted = Number(row.posted_invoices)
  const uninvoiced = Number(row.uninvoiced_qty)

  if (posted === 0) return <Badge>Not invoiced</Badge>
  if (uninvoiced > 0) return <Badge tone="warning">Part invoiced</Badge>

  return <Badge tone="success">Invoiced</Badge>
}
