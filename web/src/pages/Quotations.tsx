import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Plus } from 'lucide-react'
import { api } from '../services/api'
import type { Quotation } from '../services/types'
import { useApi } from '../hooks/useApi'
import { useSales } from '../context/SalesContext'
import { Button, Card, DataTable, date, money, Notice, Select, StatusBadge } from '../ui'

const STATUSES = [
  '',
  'DRAFT',
  'APPROVAL_PENDING',
  'APPROVED',
  'SENT',
  'ACCEPTED',
  'REJECTED',
  'CONVERTED',
  'CANCELLED',
]

export default function Quotations() {
  const navigate = useNavigate()
  const { scope, can } = useSales()
  const [status, setStatus] = useState('')

  const { data, loading, error, reload } = useApi(
    (signal) => api.list<Quotation>('v1/quotations', { status: status || undefined, limit: 100 }, signal),
    [scope?.cmp_id, scope?.fy_id, scope?.bo_id, status],
    Boolean(scope),
  )

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <header style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '1rem' }}>
        <h1 style={{ margin: 0, fontSize: '1.3rem' }}>Quotations</h1>
        {can('quotation.create') && (
          <Button tone="primary" onClick={() => navigate('/quotations/new')}>
            <Plus size={15} aria-hidden /> New quotation
          </Button>
        )}
      </header>

      {error && (
        <Notice tone="danger" title="Could not load quotations">
          {error} <Button tone="ghost" onClick={reload}>Retry</Button>
        </Notice>
      )}

      <Card
        action={
          <Select value={status} onChange={(event) => setStatus(event.target.value)} style={{ width: '12rem' }}>
            {STATUSES.map((value) => (
              <option key={value} value={value}>
                {value === '' ? 'All statuses' : value.replace(/_/g, ' ')}
              </option>
            ))}
          </Select>
        }
        title={`${data?.meta.total ?? 0} quotation${(data?.meta.total ?? 0) === 1 ? '' : 's'}`}
      >
        <DataTable
          loading={loading}
          rows={data?.data ?? []}
          rowKey={(row) => row.quotation_id}
          onRowClick={(row) => navigate(`/quotations/${row.quotation_id}`)}
          empty="No quotations yet. Create one to get started."
          columns={[
            {
              key: 'no',
              header: 'Number',
              render: (row) => (
                <Link to={`/quotations/${row.quotation_id}`} onClick={(e) => e.stopPropagation()}>
                  {row.quotation_no}
                  {row.revision_no > 0 && (
                    <span style={{ color: 'var(--muted)' }}> rev {row.revision_no}</span>
                  )}
                </Link>
              ),
            },
            { key: 'date', header: 'Date', render: (row) => date(row.quotation_date) },
            {
              key: 'customer',
              header: 'Customer',
              render: (row) => row.customer_name_snapshot ?? `Account ${row.customer_account_id}`,
            },
            { key: 'valid', header: 'Valid until', render: (row) => date(row.valid_until) },
            { key: 'status', header: 'Status', render: (row) => <StatusBadge status={row.status} /> },
            {
              key: 'total',
              header: 'Total',
              numeric: true,
              render: (row) => money(row.total_amount, row.currency_code),
            },
          ]}
        />
      </Card>
    </div>
  )
}
