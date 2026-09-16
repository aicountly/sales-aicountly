import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { Download, Plus } from 'lucide-react'
import { api } from '../services/api'
import type { SalesOrder } from '../services/types'
import { useApi } from '../hooks/useApi'
import { useSales } from '../context/SalesContext'
import { getApiBaseUrl } from '../config'
import {
  Badge,
  Button,
  DataTable,
  date,
  money,
  Notice,
  Pagination,
  Panel,
  qty,
  relativeDays,
  SearchInput,
  Select,
  StatusBadge,
} from '../ui'

type OrderRow = SalesOrder & {
  line_count: string
  stock_line_count: string
  reserved_lines: string
  ordered_qty: string
  delivered_qty: string
  invoiced_qty: string
  stuck_commands: string
  pending_approvals: string
}

const STATUSES = [
  '',
  'DRAFT',
  'CONFIRMED',
  'RESERVATION_PENDING',
  'RESERVED',
  'PARTIALLY_FULFILLED',
  'FULFILLED',
  'CLOSED',
  'CANCELLED',
]

/**
 * The order list.
 *
 * Progress gets its own columns rather than being folded into the status badge.
 * "Delivered" and "Invoiced" are separate questions with separate answers, and a
 * single COMPLETED badge standing in for both is the thing this list used to do
 * and the thing people read wrong.
 */
export default function Orders() {
  const navigate = useNavigate()
  const { scope, can } = useSales()
  const [params, setParams] = useSearchParams()

  const status = params.get('status') ?? ''
  const openOnly = params.get('open_only') === '1'
  const committed = params.get('committed') === '1'
  const late = params.get('late') === '1'
  const [term, setTerm] = useState(params.get('q') ?? '')
  const [debounced, setDebounced] = useState(term)
  const [offset, setOffset] = useState(0)
  const limit = 25

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(term), 300)

    return () => clearTimeout(timer)
  }, [term])

  useEffect(() => setOffset(0), [status, openOnly, committed, late, debounced])

  const { data, loading, error, reload } = useApi(
    (signal) =>
      api.list<OrderRow>(
        'v1/orders',
        {
          status: status || undefined,
          open_only: openOnly ? 1 : undefined,
          committed: committed ? 1 : undefined,
          late: late ? 1 : undefined,
          from: params.get('from') ?? undefined,
          to: params.get('to') ?? undefined,
          q: debounced || undefined,
          limit,
          offset,
        },
        signal,
      ),
    [scope?.cmp_id, scope?.fy_id, scope?.bo_id, status, openOnly, committed, late, debounced, offset, params.get('from'), params.get('to')],
    Boolean(scope),
  )

  const setFilter = (key: string, value: string | null) => {
    const updated = new URLSearchParams(params)
    if (value === null || value === '') updated.delete(key)
    else updated.set(key, value)
    setParams(updated, { replace: true })
  }

  const exportUrl = () => {
    if (!scope) return '#'
    const search = new URLSearchParams({
      cmp_id: String(scope.cmp_id),
      fy_id: String(scope.fy_id),
      bo_id: String(scope.bo_id),
    })
    for (const key of ['status', 'open_only', 'committed', 'late', 'from', 'to']) {
      const value = params.get(key)
      if (value) search.set(key, value)
    }
    if (debounced) search.set('q', debounced)

    return `${getApiBaseUrl()}/v1/orders/export?${search}`
  }

  const activeFilter = committed
    ? 'Confirmed orders in the selected period — the same set the dashboard card counted'
    : late
      ? 'Open orders whose promise date has already passed'
      : openOnly
        ? 'Open orders only'
        : null

  return (
    <div className="sales-stack">
      <header className="sales-page-header">
        <div>
          <h1>Sales orders</h1>
          <p>What we committed to, and how far along each one is.</p>
        </div>
        <div className="sales-actions">
          {can('reports.view') && (
            <Button onClick={() => window.open(exportUrl(), '_blank', 'noopener')}>
              <Download size={15} aria-hidden /> Export
            </Button>
          )}
          {can('order.create') && (
            <Button tone="primary" onClick={() => navigate('/orders/new')}>
              <Plus size={16} aria-hidden /> New sales order
            </Button>
          )}
        </div>
      </header>

      {error && (
        <Notice tone="danger" title="Could not load orders" action={<Button small onClick={reload}>Retry</Button>}>
          {error}
        </Notice>
      )}

      {activeFilter && (
        <Notice
          tone="info"
          title={activeFilter}
          action={
            <Button
              small
              onClick={() => {
                const updated = new URLSearchParams(params)
                for (const key of ['committed', 'late', 'open_only', 'from', 'to']) updated.delete(key)
                setParams(updated, { replace: true })
              }}
            >
              Clear
            </Button>
          }
        >
          Filters are in the address bar, so this view can be shared as a link.
        </Notice>
      )}

      <Panel
        title={`${data?.meta.total ?? 0} order${(data?.meta.total ?? 0) === 1 ? '' : 's'}`}
        action={
          <div className="sales-actions">
            <SearchInput value={term} onChange={setTerm} label="Search orders" placeholder="Number, customer or PO…" />
            <label className="sales-row" style={{ gap: 6, fontSize: 13 }}>
              <input type="checkbox" checked={openOnly} onChange={(event) => setFilter('open_only', event.target.checked ? '1' : null)} />
              Open only
            </label>
            <Select
              value={status}
              onChange={(event) => setFilter('status', event.target.value || null)}
              aria-label="Filter by status"
              style={{ minWidth: '11rem' }}
            >
              {STATUSES.map((value) => (
                <option key={value} value={value}>
                  {value === '' ? 'All statuses' : value.replace(/_/g, ' ')}
                </option>
              ))}
            </Select>
          </div>
        }
        flush
        footer={<Pagination total={data?.meta.total ?? 0} limit={limit} offset={offset} label="orders" onChange={setOffset} />}
      >
        <DataTable<OrderRow>
          loading={loading}
          caption="Sales orders"
          rows={data?.data ?? []}
          rowKey={(row) => row.order_id}
          onRowClick={(row) => navigate(`/orders/${row.order_id}`)}
          empty={debounced || status ? 'No order matches those filters.' : 'No orders yet. Accept a quotation, or raise one directly.'}
          columns={[
            {
              key: 'no',
              header: 'Number',
              render: (row) => (
                <>
                  <Link to={`/orders/${row.order_id}`} onClick={(event) => event.stopPropagation()} className="sales-cell-primary">
                    {row.order_no}
                  </Link>
                  <span className="sales-cell-sub">{date(row.order_date)}</span>
                </>
              ),
            },
            {
              key: 'customer',
              header: 'Customer',
              render: (row) => (
                <>
                  {row.customer_name_snapshot ?? `Account ${row.customer_account_id}`}
                  {row.customer_po_ref && <span className="sales-cell-sub">PO {row.customer_po_ref}</span>}
                </>
              ),
            },
            {
              key: 'promise',
              header: 'Promise date',
              render: (row) => (
                <>
                  {date(row.committed_date)}
                  {row.committed_date && (
                    <span className="sales-cell-sub">{relativeDays(daysUntil(row.committed_date))}</span>
                  )}
                </>
              ),
            },
            { key: 'status', header: 'Status', render: (row) => <StatusBadge status={row.status} /> },
            {
              key: 'delivered',
              header: 'Delivered',
              numeric: true,
              render: (row) => <Progress delivered={row.delivered_qty} ordered={row.ordered_qty} />,
            },
            {
              key: 'invoiced',
              header: 'Invoiced',
              numeric: true,
              render: (row) => <Progress delivered={row.invoiced_qty} ordered={row.ordered_qty} />,
            },
            {
              key: 'flags',
              header: '',
              render: (row) =>
                Number(row.stuck_commands) > 0 ? (
                  <Badge tone="danger" dot>
                    Request failed
                  </Badge>
                ) : Number(row.pending_approvals) > 0 ? (
                  <Badge tone="warning" dot>
                    Approval pending
                  </Badge>
                ) : null,
            },
            { key: 'total', header: 'Total', numeric: true, render: (row) => money(row.total_amount, row.currency_code) },
          ]}
        />
      </Panel>
    </div>
  )
}

function daysUntil(iso: string | null): number | null {
  if (!iso) return null
  const target = new Date(`${iso}T00:00:00Z`).getTime()
  const today = new Date(new Date().toISOString().slice(0, 10) + 'T00:00:00Z').getTime()

  return Math.round((target - today) / 86400000)
}

/** Progress as a fraction, not a badge: "80 of 100" answers the question a badge dodges. */
function Progress({ delivered, ordered }: { delivered: string; ordered: string }) {
  const done = Number(delivered || 0)
  const total = Number(ordered || 0)

  if (total === 0) return <span className="sales-muted">—</span>
  if (done === 0) return <span className="sales-muted">0 of {qty(total)}</span>

  return (
    <span className={done >= total ? 'sales-tone-positive' : undefined}>
      {qty(done)} of {qty(total)}
    </span>
  )
}
