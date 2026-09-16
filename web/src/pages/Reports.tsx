import { useState } from 'react'
import { Download, FileSpreadsheet } from 'lucide-react'
import { getApiBaseUrl } from '../config'
import { useSales } from '../context/SalesContext'
import { Button, Field, Input, Notice, Panel, Select } from '../ui'

const REPORTS = [
  {
    id: 'quotations',
    path: 'v1/quotations/export',
    title: 'Quotations',
    description:
      'Every quotation raised in the period, latest revision only, with its status as at today and the order it '
      + 'became. Superseded revisions are left out — they are history, not offers.',
    statuses: ['', 'DRAFT', 'APPROVAL_PENDING', 'APPROVED', 'SENT', 'ACCEPTED', 'REJECTED', 'CONVERTED', 'CANCELLED'],
  },
  {
    id: 'orders',
    path: 'v1/orders/export',
    title: 'Sales orders',
    description:
      'Orders dated in the period with their commercial totals and progress: ordered, delivered and invoiced '
      + 'quantities side by side, rather than one status standing in for all three.',
    statuses: ['', 'DRAFT', 'CONFIRMED', 'RESERVATION_PENDING', 'RESERVED', 'PARTIALLY_FULFILLED', 'FULFILLED', 'CLOSED', 'CANCELLED'],
  },
] as const

/**
 * Exports.
 *
 * AN EXPORT IS THE SAME QUERY AS THE SCREEN. The filters below are the filters
 * the list endpoints take, run through the same permission checks and the same
 * company scope. A download that quietly ignored them would be a file somebody
 * forwards believing it says something else.
 *
 * The file is bounded and says so when it is truncated, rather than handing
 * back a partial file that looks complete.
 */
export default function Reports() {
  const { scope, can } = useSales()
  const month = new Date().toISOString().slice(0, 7)
  const [from, setFrom] = useState(`${month}-01`)
  const [to, setTo] = useState(new Date().toISOString().slice(0, 10))
  const [status, setStatus] = useState<Record<string, string>>({})

  const download = (path: string, id: string) => {
    if (!scope) return
    const search = new URLSearchParams({
      cmp_id: String(scope.cmp_id),
      fy_id: String(scope.fy_id),
      bo_id: String(scope.bo_id),
      from,
      to,
    })
    if (status[id]) search.set('status', status[id])

    window.open(`${getApiBaseUrl()}/${path}?${search}`, '_blank', 'noopener')
  }

  return (
    <div className="sales-stack">
      <header className="sales-page-header">
        <div>
          <h1>Reports</h1>
          <p>Export what is on screen, under the same filters and the same permissions.</p>
        </div>
      </header>

      {!can('reports.view') && (
        <Notice tone="warning" title="You cannot export from Sales">
          Exporting needs the Sales reporting permission. Ask a company owner to grant it.
        </Notice>
      )}

      <Panel title="Period" description="Applied to every export below">
        <div className="sales-form-grid">
          <Field label="From" required>
            <Input type="date" value={from} max={to} onChange={(event) => setFrom(event.target.value)} />
          </Field>
          <Field label="To" required>
            <Input type="date" value={to} min={from} onChange={(event) => setTo(event.target.value)} />
          </Field>
        </div>
      </Panel>

      <div className="sales-form-grid">
        {REPORTS.map((report) => (
          <Panel
            key={report.id}
            title={
              <span className="sales-row" style={{ gap: 8 }}>
                <FileSpreadsheet size={16} aria-hidden /> {report.title}
              </span>
            }
            description={report.description}
          >
            <div className="sales-stack-tight">
              <Field label="Status">
                <Select
                  value={status[report.id] ?? ''}
                  onChange={(event) => setStatus((current) => ({ ...current, [report.id]: event.target.value }))}
                >
                  {report.statuses.map((value) => (
                    <option key={value} value={value}>
                      {value === '' ? 'All statuses' : value.replace(/_/g, ' ')}
                    </option>
                  ))}
                </Select>
              </Field>
              <div className="sales-actions">
                <Button tone="primary" disabled={!can('reports.view') || !scope} onClick={() => download(report.path, report.id)}>
                  <Download size={15} aria-hidden /> Download CSV
                </Button>
              </div>
            </div>
          </Panel>
        ))}
      </div>

      <Notice tone="info" title="Where the financial reports are">
        Invoices, receipts, the receivables ledger and the GST returns belong to Smart Books and are reported there.
        Sales does not keep a second copy of any of them, so there is nothing here that could disagree with the
        accounts.
      </Notice>
    </div>
  )
}
