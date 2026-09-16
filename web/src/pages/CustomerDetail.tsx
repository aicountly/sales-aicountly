import { Link, useNavigate, useParams } from 'react-router-dom'
import { ChevronLeft, Plus } from 'lucide-react'
import { api } from '../services/api'
import { useApi } from '../hooks/useApi'
import { useSales } from '../context/SalesContext'
import { Badge, Button, DataState, DataTable, date, money, Notice, Panel, Skeleton, StatusBadge } from '../ui'

interface CustomerView {
  customer_account_id: number
  as_of: string
  summary: {
    customer_name: string | null
    order_count: string
    total_value: string
    first_order_date: string | null
    last_order_date: string | null
    currency_code: string | null
  }
  orders: Array<{
    order_id: number
    order_no: string
    order_date: string
    status: string
    total_amount: string
    currency_code: string
    committed_date: string | null
    customer_po_ref: string | null
  }>
  quotations: Array<{
    quotation_id: number
    quotation_no: string
    revision_no: number
    quotation_date: string
    valid_until: string | null
    total_amount: string
    currency_code: string
    status: string
  }>
  followups: Array<{
    followup_id: number
    followup_kind: string
    channel: string
    contacted_on: string
    note: string | null
    promised_amount: string | null
    promised_on: string | null
    outcome: string
    created_by: string
  }>
  position: {
    status: 'ready' | 'unavailable' | 'forbidden'
    reason: string | null
    outstanding?: number
    overdue?: number
    bill_count?: number
    oldest_due_date?: string | null
    oldest_days?: number
  }
  basis: string
}

/**
 * One customer, with the three sources kept visibly apart.
 *
 * "You have ordered ₹4.2L from us" and "you owe us ₹1.8L" come from different
 * systems and mean different things. A screen that adds them into one figure is
 * a screen somebody will take into an argument with a customer and lose, so the
 * panels stay separate and each says whose number it is.
 */
export default function CustomerDetail() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { scope, can } = useSales()

  const { data, loading, error, reload } = useApi(
    (signal) => api.one<CustomerView>(`v1/customers/${id}`, undefined, signal),
    [id, scope?.cmp_id, scope?.fy_id],
    Boolean(scope && id),
  )

  const customer = data?.data
  const currency = customer?.summary.currency_code ?? 'INR'

  if (error) {
    return (
      <Notice tone="danger" title="Could not load this customer" action={<Button small onClick={reload}>Retry</Button>}>
        {error}
      </Notice>
    )
  }

  return (
    <div className="sales-stack">
      <header className="sales-page-header">
        <div>
          <Link to="/customers" className="sales-row" style={{ gap: 4, fontSize: 13 }}>
            <ChevronLeft size={14} aria-hidden /> Customers
          </Link>
          <h1 style={{ marginTop: 6 }}>{customer?.summary.customer_name ?? `Account ${id}`}</h1>
          <p>
            {customer
              ? `${customer.summary.order_count} orders · first ordered ${date(customer.summary.first_order_date)}`
              : 'Loading…'}
          </p>
        </div>
        {can('quotation.create') && (
          <Button tone="primary" onClick={() => navigate(`/quotations/new?customer_account_id=${id}`)}>
            <Plus size={16} aria-hidden /> New quotation
          </Button>
        )}
      </header>

      {loading ? (
        <Skeleton height={120} />
      ) : (
        <div className="sales-facets">
          <div className="sales-facet">
            <dt>Ordered from us</dt>
            <dd className="sales-numeric">{money(customer?.summary.total_value, currency)}</dd>
            <p>Sales · confirmed order value</p>
          </div>
          <div className="sales-facet">
            <dt>Outstanding</dt>
            <dd className="sales-numeric">
              {customer?.position.status === 'ready' ? money(customer.position.outstanding, currency) : '—'}
            </dd>
            <p>{customer?.position.status === 'ready' ? 'Smart Books · live' : (customer?.position.reason ?? 'Unavailable')}</p>
          </div>
          <div className="sales-facet">
            <dt>Overdue</dt>
            <dd className={`sales-numeric${(customer?.position.overdue ?? 0) > 0 ? ' sales-tone-negative' : ''}`}>
              {customer?.position.status === 'ready' ? money(customer.position.overdue, currency) : '—'}
            </dd>
            <p>
              {customer?.position.status === 'ready'
                ? customer.position.oldest_days
                  ? `Oldest ${customer.position.oldest_days} days`
                  : 'Nothing past due'
                : 'Smart Books did not answer'}
            </p>
          </div>
          <div className="sales-facet">
            <dt>Last ordered</dt>
            <dd>{date(customer?.summary.last_order_date)}</dd>
            <p>Sales</p>
          </div>
        </div>
      )}

      <p className="sales-note">{customer?.basis}</p>

      <div className="sales-dashboard-grid">
        <div className="sales-main">
          <Panel title="Recent orders" flush>
            <DataTable
              loading={loading}
              caption="Recent orders"
              rows={customer?.orders ?? []}
              rowKey={(row) => row.order_id}
              onRowClick={(row) => navigate(`/orders/${row.order_id}`)}
              empty="No orders yet."
              columns={[
                { key: 'no', header: 'Order', render: (row) => <span className="sales-cell-primary">{row.order_no}</span> },
                { key: 'date', header: 'Date', render: (row) => date(row.order_date) },
                { key: 'po', header: 'Customer PO', render: (row) => row.customer_po_ref ?? '—' },
                { key: 'status', header: 'Status', render: (row) => <StatusBadge status={row.status} /> },
                { key: 'total', header: 'Total', numeric: true, render: (row) => money(row.total_amount, row.currency_code) },
              ]}
            />
          </Panel>

          <Panel title="Recent quotations" flush>
            <DataTable
              loading={loading}
              caption="Recent quotations"
              rows={customer?.quotations ?? []}
              rowKey={(row) => row.quotation_id}
              onRowClick={(row) => navigate(`/quotations/${row.quotation_id}`)}
              empty="No quotations yet."
              columns={[
                {
                  key: 'no',
                  header: 'Quotation',
                  render: (row) => (
                    <span className="sales-cell-primary">
                      {row.quotation_no}
                      {row.revision_no > 0 && <span className="sales-muted"> rev {row.revision_no}</span>}
                    </span>
                  ),
                },
                { key: 'date', header: 'Date', render: (row) => date(row.quotation_date) },
                { key: 'valid', header: 'Valid until', render: (row) => date(row.valid_until) },
                { key: 'status', header: 'Status', render: (row) => <StatusBadge status={row.status} /> },
                { key: 'total', header: 'Total', numeric: true, render: (row) => money(row.total_amount, row.currency_code) },
              ]}
            />
          </Panel>
        </div>

        <aside className="sales-aside">
          <Panel title="Follow-ups" description="What we said, and what they said back">
            {loading ? (
              <Skeleton height={140} />
            ) : (customer?.followups.length ?? 0) === 0 ? (
              <DataState status="empty" message="Nothing has been logged against this account." />
            ) : (
              <ul style={{ listStyle: 'none', padding: 0, margin: 0, display: 'grid', gap: 14 }}>
                {customer!.followups.map((followup) => (
                  <li key={followup.followup_id}>
                    <div className="sales-row" style={{ gap: 8 }}>
                      <strong>{date(followup.contacted_on)}</strong>
                      <Badge>{followup.channel}</Badge>
                      {followup.promised_on && (
                        <Badge tone={followup.outcome === 'broken' ? 'danger' : followup.outcome === 'kept' ? 'success' : 'info'}>
                          Promised {money(followup.promised_amount, currency)} by {date(followup.promised_on)}
                        </Badge>
                      )}
                    </div>
                    {followup.note && <p className="sales-note" style={{ margin: '4px 0 0' }}>{followup.note}</p>}
                  </li>
                ))}
              </ul>
            )}
          </Panel>
        </aside>
      </div>
    </div>
  )
}
