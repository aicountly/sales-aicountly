import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { Download, Plus } from 'lucide-react'
import { api } from '../services/api'
import type { Quotation } from '../services/types'
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
  relativeDays,
  SearchInput,
  Select,
  StatusBadge,
} from '../ui'

type QuotationRow = Quotation & {
  effective_status: string
  converted_order_id: number | null
  converted_order_no: string | null
}

const STATUSES = ['', 'DRAFT', 'APPROVAL_PENDING', 'APPROVED', 'SENT', 'ACCEPTED', 'REJECTED', 'CONVERTED', 'CANCELLED']

/**
 * The quotation list.
 *
 * Filters live in the URL so a filtered view is a link, and so a KPI drilldown
 * from a dashboard lands on exactly the rows that KPI counted — `open=1` here
 * is the same predicate the card was counted with, not a similar one.
 *
 * The status shown is the EFFECTIVE status: a quotation whose validity has
 * passed reads Expired even though the row still says Sent, because that is
 * what is true today and it is what the dashboards count.
 */
export default function Quotations() {
  const navigate = useNavigate()
  const { scope, can } = useSales()
  const [params, setParams] = useSearchParams()

  const status = params.get('status') ?? ''
  const openOnly = params.get('open') === '1'
  const expiringDays = params.get('expiring_days') ?? ''
  const [term, setTerm] = useState(params.get('q') ?? '')
  const [debounced, setDebounced] = useState(term)
  const [offset, setOffset] = useState(0)
  const limit = 25

  // A keystroke is not a query. Waiting a beat turns twelve requests into one.
  useEffect(() => {
    const timer = setTimeout(() => setDebounced(term), 300)

    return () => clearTimeout(timer)
  }, [term])

  useEffect(() => setOffset(0), [status, openOnly, expiringDays, debounced])

  const { data, loading, error, reload } = useApi(
    (signal) =>
      api.list<QuotationRow>(
        'v1/quotations',
        {
          status: status || undefined,
          open: openOnly ? 1 : undefined,
          expiring_days: expiringDays || undefined,
          q: debounced || undefined,
          limit,
          offset,
        },
        signal,
      ),
    [scope?.cmp_id, scope?.fy_id, scope?.bo_id, status, openOnly, expiringDays, debounced, offset],
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
    if (status) search.set('status', status)
    if (openOnly) search.set('open', '1')
    if (expiringDays) search.set('expiring_days', expiringDays)
    if (debounced) search.set('q', debounced)

    return `${getApiBaseUrl()}/v1/quotations/export?${search}`
  }

  const activeFilter = openOnly
    ? 'Open quotations only — latest revision, inside validity'
    : expiringDays
      ? `Expiring within ${expiringDays} days`
      : null

  return (
    <div className="sales-stack">
      <header className="sales-page-header">
        <div>
          <h1>Quotations</h1>
          <p>What we offered, to whom, and until when.</p>
        </div>
        <div className="sales-actions">
          {can('reports.view') && (
            <Button onClick={() => window.open(exportUrl(), '_blank', 'noopener')}>
              <Download size={15} aria-hidden /> Export
            </Button>
          )}
          {can('quotation.create') && (
            <Button tone="primary" onClick={() => navigate('/quotations/new')}>
              <Plus size={16} aria-hidden /> New quotation
            </Button>
          )}
        </div>
      </header>

      {error && (
        <Notice tone="danger" title="Could not load quotations" action={<Button small onClick={reload}>Retry</Button>}>
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
                setFilter('open', null)
                setFilter('expiring_days', null)
              }}
            >
              Clear
            </Button>
          }
        >
          This is the same set of records the dashboard card counted.
        </Notice>
      )}

      <Panel
        title={`${data?.meta.total ?? 0} quotation${(data?.meta.total ?? 0) === 1 ? '' : 's'}`}
        action={
          <div className="sales-actions">
            <SearchInput
              value={term}
              onChange={setTerm}
              label="Search quotations"
              placeholder="Number, customer or PO…"
            />
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
        footer={
          <Pagination
            total={data?.meta.total ?? 0}
            limit={limit}
            offset={offset}
            label="quotations"
            onChange={setOffset}
          />
        }
      >
        <DataTable<QuotationRow>
          loading={loading}
          caption="Quotations"
          rows={data?.data ?? []}
          rowKey={(row) => row.quotation_id}
          onRowClick={(row) => navigate(`/quotations/${row.quotation_id}`)}
          empty={
            debounced || status
              ? 'No quotation matches those filters.'
              : 'No quotations yet. Create one to get started.'
          }
          columns={[
            {
              key: 'no',
              header: 'Number',
              render: (row) => (
                <Link to={`/quotations/${row.quotation_id}`} onClick={(event) => event.stopPropagation()} className="sales-cell-primary">
                  {row.quotation_no}
                  {row.revision_no > 0 && <span className="sales-muted"> rev {row.revision_no}</span>}
                </Link>
              ),
            },
            { key: 'date', header: 'Date', render: (row) => date(row.quotation_date) },
            {
              key: 'customer',
              header: 'Customer',
              render: (row) => row.customer_name_snapshot ?? `Account ${row.customer_account_id}`,
            },
            {
              key: 'valid',
              header: 'Valid until',
              render: (row) => (
                <>
                  {date(row.valid_until)}
                  {row.effective_status === 'EXPIRED' && <span className="sales-cell-sub sales-tone-negative">Lapsed</span>}
                </>
              ),
            },
            {
              key: 'status',
              header: 'Status',
              render: (row) => (
                <>
                  <StatusBadge status={row.effective_status ?? row.status} />
                  {row.converted_order_no && (
                    <span className="sales-cell-sub">
                      <Link to={`/orders/${row.converted_order_id}`} onClick={(event) => event.stopPropagation()}>
                        {row.converted_order_no}
                      </Link>
                    </span>
                  )}
                </>
              ),
            },
            {
              key: 'total',
              header: 'Total',
              numeric: true,
              render: (row) => money(row.total_amount, row.currency_code),
            },
          ]}
        />
      </Panel>
    </div>
  )
}

/** Exported so the pipeline board and this list describe a lapse the same way. */
export function ExpiryBadge({ days }: { days: number | null }) {
  if (days === null) return null

  return <Badge tone={days <= 0 ? 'danger' : days <= 7 ? 'warning' : 'neutral'}>{relativeDays(days)}</Badge>
}
