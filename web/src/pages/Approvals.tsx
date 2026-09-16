import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, ApiError } from '../services/api'
import type { ApprovalRequest } from '../services/types'
import { useApi } from '../hooks/useApi'
import { useSales } from '../context/SalesContext'
import {
  Badge,
  Button,
  DataTable,
  date,
  Drawer,
  Field,
  money,
  Notice,
  Pagination,
  Panel,
  Select,
  StatusBadge,
  Textarea,
} from '../ui'

type ApprovalRow = ApprovalRequest & {
  quotation_no?: string | null
  quotation_customer?: string | null
  quotation_total?: string | null
  quotation_currency?: string | null
  order_no?: string | null
  order_customer?: string | null
  order_total?: string | null
  order_currency?: string | null
}

/**
 * The approvals inbox.
 *
 * Deciding is a SERVER decision. The buttons below call the same transition the
 * document's own page does, so the permission check, the state check and the
 * audit entry all happen in one place. Nothing here flips a badge locally and
 * hopes the backend agrees.
 *
 * An approval with a note is worth more than one without: three months later
 * "why was this 22% discount allowed?" is a question somebody has to answer.
 */
export default function Approvals() {
  const navigate = useNavigate()
  const { scope, can } = useSales()
  const [status, setStatus] = useState('PENDING')
  const [offset, setOffset] = useState(0)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [deciding, setDeciding] = useState<{ row: ApprovalRow; action: 'approve' | 'reject' } | null>(null)
  const limit = 25

  const { data, loading, reload } = useApi(
    (signal) => api.list<ApprovalRow>('v1/approvals', { status, limit, offset }, signal),
    [scope?.cmp_id, status, offset],
    Boolean(scope),
  )

  async function decide(row: ApprovalRow, action: 'approve' | 'reject', note: string) {
    setBusy(true)
    setError(null)
    try {
      if (row.entity_type === 'quotation') {
        await api.post(`v1/quotations/${row.entity_id}/${action}`, { note })
      } else {
        throw new ApiError(400, 'unsupported', 'Only quotation approvals can be decided from here.')
      }
      setDeciding(null)
      reload()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="sales-stack">
      <header className="sales-page-header">
        <div>
          <h1>Approvals</h1>
          <p>Documents that breached a pricing rule and need a decision before they can go out.</p>
        </div>
      </header>

      {error && (
        <Notice tone="danger" title="That did not work" onDismiss={() => setError(null)}>
          {error}
        </Notice>
      )}

      <Panel
        title={`${data?.meta.total ?? 0} ${status.toLowerCase()}`}
        action={
          <Select
            value={status}
            onChange={(event) => {
              setStatus(event.target.value)
              setOffset(0)
            }}
            aria-label="Filter approvals"
            style={{ minWidth: '10rem' }}
          >
            <option value="PENDING">Waiting</option>
            <option value="APPROVED">Approved</option>
            <option value="REJECTED">Rejected</option>
          </Select>
        }
        flush
        footer={<Pagination total={data?.meta.total ?? 0} limit={limit} offset={offset} label="approvals" onChange={setOffset} />}
      >
        <DataTable<ApprovalRow>
          loading={loading}
          caption="Approvals"
          rows={data?.data ?? []}
          rowKey={(row) => row.approval_id}
          empty={status === 'PENDING' ? 'Nothing is waiting on you.' : 'Nothing here.'}
          columns={[
            {
              key: 'doc',
              header: 'Document',
              render: (row) => (
                <Button
                  tone="ghost"
                  small
                  style={{ padding: 0 }}
                  onClick={() =>
                    navigate(row.entity_type === 'quotation' ? `/quotations/${row.entity_id}` : `/orders/${row.entity_id}`)
                  }
                >
                  {row.quotation_no ?? row.order_no ?? `${row.entity_type} #${row.entity_id}`}
                </Button>
              ),
            },
            {
              key: 'customer',
              header: 'Customer',
              render: (row) => row.quotation_customer ?? row.order_customer ?? '—',
            },
            {
              key: 'why',
              header: 'Why',
              render: (row) => (
                <>
                  <span>{row.reason_detail ?? row.reason_kind}</span>
                  {row.threshold_value !== null && row.actual_value !== null && (
                    <span className="sales-cell-sub">
                      Limit {row.threshold_value}% · asked for {row.actual_value}%
                    </span>
                  )}
                </>
              ),
            },
            {
              key: 'value',
              header: 'Value',
              numeric: true,
              render: (row) =>
                money(
                  row.quotation_total ?? row.order_total,
                  row.quotation_currency ?? row.order_currency ?? 'INR',
                ),
            },
            { key: 'raised', header: 'Raised', render: (row) => date(row.created_at) },
            {
              key: 'status',
              header: 'Status',
              render: (row) => (
                <>
                  <StatusBadge status={row.status} />
                  {row.decision_note && <span className="sales-cell-sub">{row.decision_note}</span>}
                </>
              ),
            },
            {
              key: 'actions',
              header: '',
              render: (row) =>
                row.status === 'PENDING' && row.entity_type === 'quotation' && can('quotation.approve') ? (
                  <div className="sales-actions">
                    <Button tone="primary" small disabled={busy} onClick={() => setDeciding({ row, action: 'approve' })}>
                      Approve
                    </Button>
                    <Button tone="danger" small disabled={busy} onClick={() => setDeciding({ row, action: 'reject' })}>
                      Reject
                    </Button>
                  </div>
                ) : row.status === 'PENDING' && row.entity_type !== 'quotation' ? (
                  <Badge tone="warning">Decide on the document</Badge>
                ) : null,
            },
          ]}
        />
      </Panel>

      {deciding && (
        <DecisionDrawer
          row={deciding.row}
          action={deciding.action}
          busy={busy}
          onClose={() => setDeciding(null)}
          onConfirm={(note) => decide(deciding.row, deciding.action, note)}
        />
      )}
    </div>
  )
}

function DecisionDrawer({
  row,
  action,
  busy,
  onClose,
  onConfirm,
}: {
  row: ApprovalRow
  action: 'approve' | 'reject'
  busy: boolean
  onClose: () => void
  onConfirm: (note: string) => void
}) {
  const [note, setNote] = useState('')
  const approving = action === 'approve'

  return (
    <Drawer title={`${approving ? 'Approve' : 'Reject'} ${row.quotation_no ?? row.order_no ?? 'this document'}`} onClose={onClose}>
      <dl className="sales-definition-list">
        <div>
          <dt>Customer</dt>
          <dd>{row.quotation_customer ?? row.order_customer ?? '—'}</dd>
        </div>
        <div>
          <dt>Why it needs a decision</dt>
          <dd>{row.reason_detail ?? row.reason_kind}</dd>
        </div>
        <div>
          <dt>Value</dt>
          <dd>{money(row.quotation_total ?? row.order_total, row.quotation_currency ?? row.order_currency ?? 'INR')}</dd>
        </div>
      </dl>

      <Notice tone={approving ? 'info' : 'warning'} title={approving ? 'Approving lets this go out' : 'Rejecting stops it'}>
        {approving
          ? 'The quotation moves to Approved and can be sent. The decision and your note are recorded against it.'
          : 'The quotation is rejected and cannot be sent as it stands. It can still be revised.'}
      </Notice>

      <Field
        label="Note"
        hint="Three months from now somebody will ask why this was decided. Answer them here."
      >
        <Textarea value={note} onChange={(event) => setNote(event.target.value)} rows={3} />
      </Field>

      <div className="sales-actions">
        <Button tone={approving ? 'primary' : 'danger'} disabled={busy} onClick={() => onConfirm(note)}>
          {busy ? 'Recording…' : approving ? 'Approve' : 'Reject'}
        </Button>
        <Button onClick={onClose} disabled={busy}>
          Cancel
        </Button>
      </div>
    </Drawer>
  )
}
