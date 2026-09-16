import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ChevronLeft, Send } from 'lucide-react'
import { api, ApiError } from '../services/api'
import type { Quotation } from '../services/types'
import { useApi } from '../hooks/useApi'
import { useSales } from '../context/SalesContext'
import {
  Badge,
  Button,
  DataTable,
  date,
  Drawer,
  Field,
  Input,
  money,
  Notice,
  Panel,
  qty,
  Select,
  StatusBadge,
  Textarea,
} from '../ui'

type QuotationView = Quotation & {
  effective_status: string
  sent_channel: string | null
  sent_reference: string | null
  sent_at: string | null
  expired_at: string | null
  superseded_by: number | null
  converted_order: { order_id: number; order_no: string; status: string } | null
  revisions: Array<{
    quotation_id: number
    revision_no: number
    status: string
    total_amount: string
    created_at: string
    created_by: string
  }>
  followups: Array<{ followup_id: number; channel: string; contacted_on: string; note: string | null; created_by: string }>
}

/** Which actions make sense in which state, so the UI offers nothing that would be refused. */
const ACTIONS: Record<string, Array<{ action: string; label: string; permission: string; tone?: 'primary' | 'danger' }>> = {
  DRAFT: [
    { action: 'send', label: 'Send to customer', permission: 'quotation.send', tone: 'primary' },
    { action: 'cancel', label: 'Cancel', permission: 'quotation.create', tone: 'danger' },
  ],
  APPROVAL_PENDING: [
    { action: 'approve', label: 'Approve', permission: 'quotation.approve', tone: 'primary' },
    { action: 'reject', label: 'Reject', permission: 'quotation.approve', tone: 'danger' },
  ],
  APPROVED: [
    { action: 'send', label: 'Send to customer', permission: 'quotation.send', tone: 'primary' },
    { action: 'cancel', label: 'Cancel', permission: 'quotation.create', tone: 'danger' },
  ],
  SENT: [
    { action: 'accept', label: 'Customer accepted', permission: 'quotation.create', tone: 'primary' },
    { action: 'decline', label: 'Customer declined', permission: 'quotation.create' },
    { action: 'cancel', label: 'Cancel', permission: 'quotation.create', tone: 'danger' },
  ],
  EXPIRED: [{ action: 'expire', label: 'Close off as lapsed', permission: 'quotation.create' }],
}

export default function QuotationDetail() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { scope, can } = useSales()
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)
  const [sending, setSending] = useState(false)

  const { data, loading, error, reload } = useApi(
    (signal) => api.one<QuotationView>(`v1/quotations/${id}`, undefined, signal),
    [id, scope?.cmp_id],
    Boolean(scope && id),
  )

  const quotation = data?.data

  async function run(action: string, body: Record<string, unknown> = {}) {
    setBusy(true)
    setActionError(null)
    try {
      await api.post<Quotation>(`v1/quotations/${id}/${action}`, body)
      reload()
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  /**
   * Convert to an order.
   *
   * The payload is built on the SERVER from the quotation as stored, and the
   * call is idempotent: pressing this twice returns the order that already
   * exists rather than committing the company to the same goods again.
   */
  async function convertToOrder() {
    if (!quotation) return
    setBusy(true)
    setActionError(null)
    try {
      const response = await api.post<{ order_id: number }>(`v1/quotations/${quotation.quotation_id}/convert`, {})
      navigate(`/orders/${response.data.order_id}`)
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <p className="sales-muted">Loading…</p>
  if (error) {
    return (
      <Notice tone="danger" title="Could not load this quotation" action={<Button small onClick={reload}>Retry</Button>}>
        {error}
      </Notice>
    )
  }
  if (!quotation) return <Notice tone="warning">That quotation does not exist.</Notice>

  const effective = quotation.effective_status ?? quotation.status
  const actions = (ACTIONS[effective] ?? []).filter((entry) => can(entry.permission))
  const pendingApprovals = quotation.approvals.filter((approval) => approval.status === 'PENDING')
  const superseded = quotation.superseded_by !== null

  return (
    <div className="sales-stack">
      <header className="sales-page-header">
        <div style={{ minWidth: 0 }}>
          <Link to="/quotations" className="sales-row" style={{ gap: 4, fontSize: 13 }}>
            <ChevronLeft size={14} aria-hidden /> Quotations
          </Link>
          <h1 className="sales-row" style={{ marginTop: 6, gap: 12 }}>
            {quotation.quotation_no}
            {quotation.revision_no > 0 && <span className="sales-muted" style={{ fontSize: 17 }}>revision {quotation.revision_no}</span>}
            <StatusBadge status={effective} />
          </h1>
          <p>
            {quotation.customer_name_snapshot ?? `Account ${quotation.customer_account_id}`} · {date(quotation.quotation_date)}
            {quotation.valid_until && ` · valid until ${date(quotation.valid_until)}`}
          </p>
        </div>

        <div className="sales-actions">
          {actions.map((entry) =>
            entry.action === 'send' ? (
              <Button key={entry.action} tone="primary" disabled={busy} onClick={() => setSending(true)}>
                <Send size={15} aria-hidden /> {entry.label}
              </Button>
            ) : (
              <Button key={entry.action} tone={entry.tone ?? 'secondary'} disabled={busy} onClick={() => run(entry.action)}>
                {entry.label}
              </Button>
            ),
          )}
          {effective === 'ACCEPTED' && !quotation.converted_order && can('order.create') && (
            <Button tone="primary" disabled={busy} onClick={convertToOrder}>
              Convert to order
            </Button>
          )}
          {can('quotation.create') && !superseded && !['CONVERTED', 'CANCELLED'].includes(quotation.status) && (
            <Button disabled={busy} onClick={() => navigate(`/quotations/${quotation.quotation_id}/revise`)}>
              Revise
            </Button>
          )}
        </div>
      </header>

      {actionError && (
        <Notice tone="danger" title="That did not work" onDismiss={() => setActionError(null)}>
          {actionError}
        </Notice>
      )}

      {superseded && (
        <Notice
          tone="warning"
          title="This revision has been superseded"
          action={
            <Button small onClick={() => navigate(`/quotations/${quotation.superseded_by}`)}>
              Open the latest
            </Button>
          }
        >
          It is kept so the version the customer saw stays readable, but it cannot be sent or accepted.
        </Notice>
      )}

      {quotation.converted_order && (
        <Notice
          tone="success"
          title={`Converted to order ${quotation.converted_order.order_no}`}
          action={
            <Button small onClick={() => navigate(`/orders/${quotation.converted_order!.order_id}`)}>
              Open the order
            </Button>
          }
        >
          A quotation becomes one order and only one. Converting again returns this same order.
        </Notice>
      )}

      {pendingApprovals.length > 0 && (
        <Notice tone="warning" title="Waiting for approval">
          <ul style={{ margin: '4px 0 0', paddingLeft: '1.1rem' }}>
            {pendingApprovals.map((approval) => (
              <li key={approval.approval_id}>{approval.reason_detail}</li>
            ))}
          </ul>
        </Notice>
      )}

      <div className="sales-facets">
        <div className="sales-facet">
          <dt>Status</dt>
          <dd>
            <StatusBadge status={effective} />
          </dd>
          <p>{effective === 'EXPIRED' ? 'Validity has passed' : 'As recorded on this document'}</p>
        </div>
        <div className="sales-facet">
          <dt>Sent</dt>
          <dd>{quotation.sent_at ? date(quotation.sent_at) : 'Not sent'}</dd>
          <p>
            {quotation.sent_at
              ? `${quotation.sent_channel ?? 'unrecorded channel'} · ${quotation.sent_reference ?? 'no reference'}`
              : 'A quotation counts as sent only once it has actually gone'}
          </p>
        </div>
        <div className="sales-facet">
          <dt>Approval</dt>
          <dd>{pendingApprovals.length > 0 ? 'Pending' : quotation.approved_by ? 'Approved' : 'Not required'}</dd>
          <p>{quotation.approvals.length} request{quotation.approvals.length === 1 ? '' : 's'} on this document</p>
        </div>
        <div className="sales-facet">
          <dt>Order</dt>
          <dd>{quotation.converted_order?.order_no ?? '—'}</dd>
          <p>{quotation.converted_order ? 'Converted' : 'Not yet converted'}</p>
        </div>
      </div>

      <div className="sales-dashboard-grid">
        <div className="sales-main">
          <Panel title="Lines" flush>
            <DataTable
              caption="Quotation lines"
              rows={quotation.lines}
              rowKey={(line) => line.line_id}
              columns={[
                { key: 'no', header: '#', width: '3rem', render: (line) => line.line_no },
                {
                  key: 'item',
                  header: 'Item',
                  render: (line) => (
                    <>
                      <span className="sales-cell-primary">
                        {line.description ?? (line.is_service ? 'Service' : `Item #${line.item_id}`)}
                      </span>
                      {!line.is_service && <span className="sales-cell-sub">Inventory item {line.item_id}</span>}
                      {line.is_optional && (
                        <span className="sales-cell-sub">
                          <Badge>Optional extra — not in the total</Badge>
                        </span>
                      )}
                    </>
                  ),
                },
                { key: 'qty', header: 'Qty', numeric: true, render: (line) => qty(line.quantity) },
                { key: 'rate', header: 'Rate', numeric: true, render: (line) => money(line.rate, quotation.currency_code) },
                { key: 'disc', header: 'Disc %', numeric: true, render: (line) => qty(line.discount_pc) },
                { key: 'tax', header: 'Tax %', numeric: true, render: (line) => qty(line.estimated_tax_pc) },
                {
                  key: 'amount',
                  header: 'Amount',
                  numeric: true,
                  render: (line) => money(line.line_amount, quotation.currency_code),
                },
              ]}
            />

            <div style={{ padding: '16px 20px' }}>
              <dl style={{ display: 'grid', gridTemplateColumns: 'auto auto', gap: '6px 24px', justifyContent: 'end', margin: 0 }}>
                <dt className="sales-muted">Subtotal</dt>
                <dd className="sales-numeric" style={{ margin: 0 }}>{money(quotation.subtotal_amount, quotation.currency_code)}</dd>
                <dt className="sales-muted">Discount</dt>
                <dd className="sales-numeric" style={{ margin: 0 }}>−{money(quotation.discount_amount, quotation.currency_code)}</dd>
                <dt className="sales-muted" title="Books calculates the tax that is actually charged when the invoice is raised.">
                  Estimated tax
                </dt>
                <dd className="sales-numeric" style={{ margin: 0 }}>{money(quotation.estimated_tax_amount, quotation.currency_code)}</dd>
                <dt style={{ fontWeight: 700 }}>Total</dt>
                <dd className="sales-numeric" style={{ margin: 0, fontWeight: 700 }}>{money(quotation.total_amount, quotation.currency_code)}</dd>
              </dl>
              <p className="sales-note" style={{ marginTop: 12, marginBottom: 0, textAlign: 'right' }}>
                Tax shown here is an estimate for the customer's benefit. Smart Books calculates and files the tax
                actually charged when the invoice is raised.
              </p>
            </div>
          </Panel>

          {(quotation.payment_terms || quotation.delivery_terms || quotation.notes) && (
            <Panel title="Terms and notes">
              <dl className="sales-definition-list">
                {quotation.payment_terms && (
                  <div>
                    <dt>Payment terms</dt>
                    <dd>{quotation.payment_terms}</dd>
                  </div>
                )}
                {quotation.delivery_terms && (
                  <div>
                    <dt>Delivery terms</dt>
                    <dd>{quotation.delivery_terms}</dd>
                  </div>
                )}
                {quotation.notes && (
                  <div>
                    <dt>Notes</dt>
                    <dd>{quotation.notes}</dd>
                  </div>
                )}
              </dl>
            </Panel>
          )}
        </div>

        <aside className="sales-aside">
          <Panel title="Revision history" description="Every version is kept, none is overwritten">
            <ol style={{ listStyle: 'none', padding: 0, margin: 0, display: 'grid', gap: 12 }}>
              {(quotation.revisions ?? []).map((revision) => (
                <li key={revision.quotation_id} className="sales-row-between" style={{ gap: 10 }}>
                  <div style={{ minWidth: 0 }}>
                    {revision.quotation_id === quotation.quotation_id ? (
                      <strong>Revision {revision.revision_no} (this one)</strong>
                    ) : (
                      <Link to={`/quotations/${revision.quotation_id}`}>Revision {revision.revision_no}</Link>
                    )}
                    <div className="sales-note">{date(revision.created_at)}</div>
                  </div>
                  <span className="sales-numeric">{money(revision.total_amount, quotation.currency_code)}</span>
                </li>
              ))}
            </ol>
          </Panel>

          <Panel title="Follow-ups" description="What we have said to the customer about this">
            {(quotation.followups ?? []).length === 0 ? (
              <p className="sales-note" style={{ margin: 0 }}>
                Nothing logged yet.
              </p>
            ) : (
              <ul style={{ listStyle: 'none', padding: 0, margin: 0, display: 'grid', gap: 10 }}>
                {quotation.followups.map((followup) => (
                  <li key={followup.followup_id}>
                    <div className="sales-row" style={{ gap: 8 }}>
                      <strong>{date(followup.contacted_on)}</strong>
                      <Badge>{followup.channel}</Badge>
                    </div>
                    {followup.note && <p className="sales-note" style={{ margin: '3px 0 0' }}>{followup.note}</p>}
                  </li>
                ))}
              </ul>
            )}
          </Panel>
        </aside>
      </div>

      {sending && (
        <SendDialog
          quotationNo={quotation.quotation_no}
          busy={busy}
          onClose={() => setSending(false)}
          onSend={async (channel, reference, note) => {
            await run('send', { channel, reference })
            if (note.trim()) {
              try {
                await api.post('v1/followups', {
                  followup_kind: 'quotation',
                  quotation_id: quotation.quotation_id,
                  customer_account_id: quotation.customer_account_id,
                  channel: channel === 'printed' || channel === 'manual' ? 'note' : channel,
                  note,
                })
                reload()
              } catch {
                // The send itself succeeded; failing to log the note beside it
                // must not make the user think the quotation did not go out.
              }
            }
            setSending(false)
          }}
        />
      )}
    </div>
  )
}

/**
 * Recording a send.
 *
 * This is not "mark as sent". The backend refuses the transition without a
 * channel and a reference, because "Sent" has to mean something a person can
 * point at — an address, a portal link, or who it was handed to. Opening this
 * dialog and closing it again changes nothing.
 */
function SendDialog({
  quotationNo,
  busy,
  onClose,
  onSend,
}: {
  quotationNo: string
  busy: boolean
  onClose: () => void
  onSend: (channel: string, reference: string, note: string) => Promise<void>
}) {
  const [channel, setChannel] = useState('email')
  const [reference, setReference] = useState('')
  const [note, setNote] = useState('')

  const hint: Record<string, string> = {
    email: 'The address it was emailed to',
    portal: 'The portal link the customer was given',
    whatsapp: 'The number it was sent to',
    printed: 'Who it was handed to, and when',
    manual: 'How it reached them',
  }

  return (
    <Drawer title={`Record sending ${quotationNo}`} onClose={onClose}>
      <Notice tone="info" title="This records what happened">
        Sales has no outbound mail server and does not pretend to. Send the quotation the way you normally would, then
        record it here so the pipeline reflects reality rather than a button somebody pressed.
      </Notice>

      <Field label="How was it sent?" required>
        <Select value={channel} onChange={(event) => setChannel(event.target.value)}>
          <option value="email">Email</option>
          <option value="portal">Customer portal</option>
          <option value="whatsapp">WhatsApp</option>
          <option value="printed">Printed and handed over</option>
          <option value="manual">Something else</option>
        </Select>
      </Field>

      <Field label="Where did it go?" required hint={hint[channel]}>
        <Input value={reference} onChange={(event) => setReference(event.target.value)} placeholder={hint[channel]} />
      </Field>

      <Field label="Anything worth noting">
        <Textarea value={note} onChange={(event) => setNote(event.target.value)} rows={3} />
      </Field>

      <div className="sales-actions">
        <Button tone="primary" disabled={busy || reference.trim() === ''} onClick={() => void onSend(channel, reference.trim(), note)}>
          {busy ? 'Recording…' : 'Record as sent'}
        </Button>
        <Button onClick={onClose} disabled={busy}>
          Cancel
        </Button>
      </div>
    </Drawer>
  )
}
