import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, ApiError } from '../services/api'
import type { ApprovalRequest } from '../services/types'
import { useApi } from '../hooks/useApi'
import { useSales } from '../context/SalesContext'
import { Button, Card, DataTable, date, money, Notice, StatusBadge } from '../ui'

type ApprovalRow = ApprovalRequest & {
  quotation_no?: string | null
  quotation_customer?: string | null
  quotation_total?: string | null
  order_no?: string | null
  order_customer?: string | null
  order_total?: string | null
}

export default function Approvals() {
  const navigate = useNavigate()
  const { scope, can } = useSales()
  const [status, setStatus] = useState('PENDING')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const { data, loading, reload } = useApi(
    (signal) => api.list<ApprovalRow>('v1/approvals', { status, limit: 100 }, signal),
    [scope?.cmp_id, status],
    Boolean(scope),
  )

  async function decide(row: ApprovalRow, action: 'approve' | 'reject') {
    if (row.entity_type !== 'quotation') return
    setBusy(true)
    setError(null)
    try {
      await api.post(`v1/quotations/${row.entity_id}/${action}`, {})
      reload()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <h1 style={{ margin: 0, fontSize: '1.3rem' }}>Approvals</h1>

      {error && <Notice tone="danger" title="That did not work">{error}</Notice>}

      <Card
        title={`${data?.meta.total ?? 0} waiting`}
        action={
          <select value={status} onChange={(event) => setStatus(event.target.value)} style={{ padding: '0.35rem' }}>
            <option value="PENDING">Pending</option>
            <option value="APPROVED">Approved</option>
            <option value="REJECTED">Rejected</option>
          </select>
        }
      >
        <DataTable
          loading={loading}
          rows={data?.data ?? []}
          rowKey={(row) => row.approval_id}
          empty="Nothing is waiting on you."
          columns={[
            {
              key: 'doc',
              header: 'Document',
              render: (row) => (
                <button
                  type="button"
                  onClick={() => navigate(row.entity_type === 'quotation' ? `/quotations/${row.entity_id}` : `/orders/${row.entity_id}`)}
                  style={{ background: 'none', border: 'none', color: 'var(--link)', cursor: 'pointer', padding: 0 }}
                >
                  {row.quotation_no ?? row.order_no ?? `${row.entity_type} #${row.entity_id}`}
                </button>
              ),
            },
            { key: 'customer', header: 'Customer', render: (row) => row.quotation_customer ?? row.order_customer ?? '—' },
            { key: 'why', header: 'Why', render: (row) => row.reason_detail ?? row.reason_kind },
            { key: 'value', header: 'Value', numeric: true, render: (row) => money(row.quotation_total ?? row.order_total) },
            { key: 'raised', header: 'Raised', render: (row) => date(row.created_at) },
            { key: 'status', header: 'Status', render: (row) => <StatusBadge status={row.status} /> },
            {
              key: 'actions',
              header: '',
              render: (row) =>
                row.status === 'PENDING' && row.entity_type === 'quotation' && can('quotation.approve') ? (
                  <div style={{ display: 'flex', gap: '0.35rem' }}>
                    <Button tone="primary" disabled={busy} onClick={() => decide(row, 'approve')}>Approve</Button>
                    <Button tone="danger" disabled={busy} onClick={() => decide(row, 'reject')}>Reject</Button>
                  </div>
                ) : null,
            },
          ]}
        />
      </Card>
    </div>
  )
}
