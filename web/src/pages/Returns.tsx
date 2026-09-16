import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { Link } from 'react-router-dom'
import { api, ApiError } from '../services/api'
import type { ReturnRequest } from '../services/types'
import { useApi } from '../hooks/useApi'
import { useSales } from '../context/SalesContext'
import { CommandStrip } from '../components/CommandStrip'
import { Button, Card, DataTable, date, money, Notice, qty, StatusBadge } from '../ui'

export function ReturnsList() {
  const navigate = useNavigate()
  const { scope } = useSales()
  const { data, loading, error, reload } = useApi(
    (signal) => api.list<ReturnRequest>('v1/returns', { limit: 100 }, signal),
    [scope?.cmp_id, scope?.fy_id, scope?.bo_id],
    Boolean(scope),
  )

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <h1 style={{ margin: 0, fontSize: '1.3rem' }}>Returns</h1>

      {error && (
        <Notice tone="danger" title="Could not load returns">
          {error} <Button tone="ghost" onClick={reload}>Retry</Button>
        </Notice>
      )}

      <Notice tone="info">
        Sales decides whether goods may come back and on what terms. Inventory records the physical receipt and Smart
        Books issues the credit — this screen links the three without holding a copy of either.
      </Notice>

      <Card title={`${data?.meta.total ?? 0} return${(data?.meta.total ?? 0) === 1 ? '' : 's'}`}>
        <DataTable
          loading={loading}
          rows={data?.data ?? []}
          rowKey={(row) => row.return_id}
          onRowClick={(row) => navigate(`/returns/${row.return_id}`)}
          empty="No returns raised."
          columns={[
            { key: 'no', header: 'RMA', render: (row) => <Link to={`/returns/${row.return_id}`} onClick={(e) => e.stopPropagation()}>{row.rma_no}</Link> },
            { key: 'date', header: 'Date', render: (row) => date(row.return_date) },
            { key: 'customer', header: 'Customer', render: (row) => `Account ${row.customer_account_id}` },
            { key: 'reason', header: 'Reason', render: (row) => row.reason_note ?? row.reason_code ?? '—' },
            { key: 'resolution', header: 'Resolution', render: (row) => row.resolution.replace(/_/g, ' ') },
            { key: 'status', header: 'Status', render: (row) => <StatusBadge status={row.status} /> },
          ]}
        />
      </Card>
    </div>
  )
}

export function ReturnDetail() {
  const { id } = useParams<{ id: string }>()
  const { scope, can } = useSales()
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)

  const { data, loading, error, reload } = useApi(
    (signal) => api.one<ReturnRequest>(`v1/returns/${id}`, undefined, signal),
    [id, scope?.cmp_id],
    Boolean(scope && id),
  )

  async function act(path: string) {
    setBusy(true)
    setActionError(null)
    try {
      await api.post(`v1/returns/${id}/${path}`, {})
      reload()
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <p style={{ color: 'var(--muted)' }}>Loading…</p>
  if (error) return <Notice tone="danger" title="Could not load this return">{error}</Notice>

  const rma = data?.data
  if (!rma) return <Notice tone="warning">That return does not exist.</Notice>

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <header style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '1rem', flexWrap: 'wrap' }}>
        <div>
          <Link to="/returns" style={{ fontSize: '0.85rem' }}>← Returns</Link>
          <h1 style={{ margin: '0.25rem 0 0', fontSize: '1.3rem', display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
            {rma.rma_no}
            <StatusBadge status={rma.status} />
          </h1>
        </div>
        <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
          {['DRAFT', 'SUBMITTED'].includes(rma.status) && can('return.approve') && (
            <Button tone="primary" disabled={busy} onClick={() => act('approve')}>Approve</Button>
          )}
          {rma.status === 'APPROVED' && can('return.approve') && (
            <Button tone="primary" disabled={busy} onClick={() => act('receive')}>Receive into Inventory</Button>
          )}
          {['RECEIVED', 'APPROVED'].includes(rma.status) && !rma.books_credit_note_uuid && can('return.approve') && (
            <Button tone="primary" disabled={busy} onClick={() => act('credit-note')}>Raise credit note in Books</Button>
          )}
        </div>
      </header>

      {actionError && <Notice tone="danger" title="That did not work">{actionError}</Notice>}

      <CommandStrip commands={rma.commands} busy={busy} />

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(16rem, 1fr))', gap: '1rem' }}>
        <Card title="Where this stands">
          <dl style={{ display: 'grid', gridTemplateColumns: 'auto 1fr', gap: '0.4rem 1rem', margin: 0 }}>
            <dt style={{ color: 'var(--muted)' }}>Resolution</dt>
            <dd style={{ margin: 0 }}>{rma.resolution.replace(/_/g, ' ')}</dd>
            <dt style={{ color: 'var(--muted)' }}>Restock</dt>
            <dd style={{ margin: 0 }}>{rma.restock ? 'Yes' : 'No — written off'}</dd>
            <dt style={{ color: 'var(--muted)' }}>Inventory receipt</dt>
            <dd style={{ margin: 0 }}>{rma.inventory_document_uuid ?? 'Not received yet'}</dd>
            <dt style={{ color: 'var(--muted)' }}>Books credit note</dt>
            <dd style={{ margin: 0 }}>{rma.books_credit_note_uuid ?? 'Not raised yet'}</dd>
          </dl>
        </Card>

        <Card title="Reason">
          <p style={{ margin: 0 }}>{rma.reason_note ?? rma.reason_code ?? 'No reason recorded.'}</p>
        </Card>
      </div>

      <Card title="Lines">
        <DataTable
          rows={rma.lines}
          rowKey={(line) => line.line_id}
          columns={[
            { key: 'no', header: '#', width: '3rem', render: (line) => line.line_no },
            { key: 'item', header: 'Item', render: (line) => `Inventory item ${line.item_id}` },
            { key: 'qty', header: 'Returning', numeric: true, render: (line) => qty(line.return_qty) },
            { key: 'received', header: 'Received', numeric: true, render: (line) => qty(line.received_qty) },
            { key: 'condition', header: 'Condition', render: (line) => line.condition_code.replace(/_/g, ' ') },
            { key: 'amount', header: 'Amount', numeric: true, render: (line) => money(line.line_amount) },
          ]}
        />
      </Card>
    </div>
  )
}
