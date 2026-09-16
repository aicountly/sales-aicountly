import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { api } from '../services/api'
import { useApi } from '../hooks/useApi'
import { useSales } from '../context/SalesContext'
import { Badge, Button, DataTable, date, money, Notice, Pagination, Panel, SearchInput } from '../ui'

interface CustomerRow {
  customer_account_id: number
  customer_name: string | null
  order_count: string
  total_value: string
  currency_code: string
  last_order_date: string | null
  days_since_last_order: string | null
  quotation_count: string
  last_contact_on: string | null
  last_contact_via: string | null
  reorder_due: boolean
  declining: boolean
  average_gap_days: string | null
}

const SIGNALS = [
  { value: '', label: 'All customers' },
  { value: 'reorder', label: 'Due to reorder' },
  { value: 'declining', label: 'Buying less' },
]

/**
 * The customer list, as Sales sees it.
 *
 * WHAT IT IS NOT: a customer master. Contacts owns identity and Books owns the
 * ledger. Every row here is built from OUR documents — what we quoted, what
 * they ordered, when we last spoke — keyed on the account id those products
 * own. Nothing about the customer is stored on this side.
 */
export default function Customers() {
  const navigate = useNavigate()
  const { scope } = useSales()
  const [params, setParams] = useSearchParams()
  const [term, setTerm] = useState(params.get('q') ?? '')
  const [offset, setOffset] = useState(0)
  const limit = 25

  const signal = params.get('signal') ?? ''

  const { data, loading, error, reload } = useApi(
    (abort) => api.list<CustomerRow>('v1/customers', { q: term || undefined, signal: signal || undefined, limit, offset }, abort),
    [scope?.cmp_id, scope?.fy_id, scope?.bo_id, term, signal, offset],
    Boolean(scope),
  )

  const setSignal = (next: string) => {
    const updated = new URLSearchParams(params)
    if (next) updated.set('signal', next)
    else updated.delete('signal')
    setParams(updated, { replace: true })
    setOffset(0)
  }

  return (
    <div className="sales-stack">
      <header className="sales-page-header">
        <div>
          <h1>Customers</h1>
          <p>Who buys from us, how often, and when we last spoke to them.</p>
        </div>
      </header>

      {error && (
        <Notice tone="danger" title="Could not load customers" action={<Button small onClick={reload}>Retry</Button>}>
          {error}
        </Notice>
      )}

      <Panel
        title={`${data?.meta.total ?? 0} customer${(data?.meta.total ?? 0) === 1 ? '' : 's'}`}
        action={
          <div className="sales-actions">
            <SearchInput value={term} onChange={(value) => { setTerm(value); setOffset(0) }} label="Search customers" placeholder="Search by name…" />
            <label className="sales-visually-hidden" htmlFor="customer-signal">
              Filter by signal
            </label>
            <select id="customer-signal" value={signal} onChange={(event) => setSignal(event.target.value)} style={{ minHeight: 40, padding: '9px 12px', border: '1px solid var(--line-strong)', borderRadius: 8, background: '#fff' }}>
              {SIGNALS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </div>
        }
        flush
        footer={<Pagination total={data?.meta.total ?? 0} limit={limit} offset={offset} label="customers" onChange={setOffset} />}
      >
        <DataTable<CustomerRow>
          loading={loading}
          caption="Customers"
          rows={data?.data ?? []}
          rowKey={(row) => row.customer_account_id}
          onRowClick={(row) => navigate(`/customers/${row.customer_account_id}`)}
          empty={signal ? 'No customer matches that signal right now.' : 'No customer has ordered yet.'}
          columns={[
            {
              key: 'customer',
              header: 'Customer',
              render: (row) => (
                <>
                  <span className="sales-cell-primary">{row.customer_name ?? `Account ${row.customer_account_id}`}</span>
                  <span className="sales-cell-sub">Account {row.customer_account_id}</span>
                </>
              ),
            },
            { key: 'orders', header: 'Orders', numeric: true, render: (row) => row.order_count },
            {
              key: 'value',
              header: 'Order value',
              numeric: true,
              render: (row) => money(row.total_value, row.currency_code),
            },
            {
              key: 'last',
              header: 'Last order',
              render: (row) => (
                <>
                  {date(row.last_order_date)}
                  {row.days_since_last_order !== null && (
                    <span className="sales-cell-sub">{row.days_since_last_order} days ago</span>
                  )}
                </>
              ),
            },
            {
              key: 'contact',
              header: 'Last contact',
              render: (row) =>
                row.last_contact_on ? (
                  <>
                    {date(row.last_contact_on)}
                    <span className="sales-cell-sub">{row.last_contact_via}</span>
                  </>
                ) : (
                  <span className="sales-muted">Never</span>
                ),
            },
            {
              key: 'signal',
              header: 'Signal',
              render: (row) =>
                row.reorder_due ? (
                  <Badge tone="success" dot>
                    Due to reorder
                  </Badge>
                ) : row.declining ? (
                  <Badge tone="warning" dot>
                    Buying less
                  </Badge>
                ) : (
                  <span className="sales-muted">—</span>
                ),
            },
          ]}
        />
      </Panel>
    </div>
  )
}
