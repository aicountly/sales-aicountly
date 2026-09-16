import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ChevronLeft } from 'lucide-react'
import { api, ApiError } from '../services/api'
import type { CreditVerdict, SalesOrder } from '../services/types'
import { useApi } from '../hooks/useApi'
import { useSales } from '../context/SalesContext'
import { CommandStrip } from '../components/CommandStrip'
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
  StatusBadge,
  type BadgeTone,
} from '../ui'

interface Facet {
  state: string
  label: string
  tone: 'neutral' | 'success' | 'warning' | 'danger'
  detail: string | null
}

type OrderView = SalesOrder & {
  facets: Record<'approval' | 'stock' | 'fulfilment' | 'invoice' | 'payment', Facet>
  approvals: Array<{ approval_id: number; reason_detail: string | null; status: string }>
}

interface LiveFulfilment {
  order_id: number
  status: string
  inventory_reachable: boolean
  lines: Array<{
    line_id: number
    line_no: number
    item_id: number | null
    ordered_qty: number
    delivered_qty: number
    invoiced_qty: number
    returned_qty: number
    outstanding_qty: number
    live_availability: { available?: number; shortfall?: number; ok?: boolean } | null
  }>
}

const FACET_LABELS: Array<{ key: keyof OrderView['facets']; title: string }> = [
  { key: 'approval', title: 'Approval' },
  { key: 'stock', title: 'Stock' },
  { key: 'fulfilment', title: 'Fulfilment' },
  { key: 'invoice', title: 'Invoice' },
  { key: 'payment', title: 'Payment' },
]

/**
 * One order, with the five questions its status used to answer at once kept
 * apart: is it agreed, is the stock held, has it gone, has it been billed, has
 * it been paid.
 *
 * The last of those is deliberately not answered here. Whether the customer has
 * paid is Books' answer; a payment state inferred from our own invoice requests
 * would say "paid" about money nobody has received.
 */
export default function OrderDetail() {
  const { id } = useParams<{ id: string }>()
  const { scope, can } = useSales()
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)
  const [credit, setCredit] = useState<CreditVerdict | null>(null)
  const [cancelReason, setCancelReason] = useState('')
  const [cancelling, setCancelling] = useState(false)
  const [dispatching, setDispatching] = useState(false)

  const { data, loading, error, reload } = useApi(
    (signal) => api.one<OrderView>(`v1/orders/${id}`, undefined, signal),
    [id, scope?.cmp_id],
    Boolean(scope && id),
  )

  // Fetched separately so a slow Inventory delays the live column and not the
  // order itself. The order is ours and always renders.
  const live = useApi(
    (signal) => api.one<LiveFulfilment>(`v1/orders/${id}/fulfilment-status`, undefined, signal),
    [id, scope?.cmp_id, data?.data?.status],
    Boolean(scope && id && data),
  )

  const order = data?.data

  async function act(path: string, body: Record<string, unknown> = {}) {
    setBusy(true)
    setActionError(null)
    try {
      const response = await api.post<SalesOrder>(`v1/orders/${id}/${path}`, body)
      if (response.data.credit) setCredit(response.data.credit)
      if (response.data.reservation_error) setActionError(response.data.reservation_error)
      reload()
      live.reload()
    } catch (err) {
      if (err instanceof ApiError) {
        setActionError(err.message)
        const verdict = err.details.credit as CreditVerdict | undefined
        if (verdict) setCredit(verdict)
      } else {
        setActionError(String(err))
      }
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <p className="sales-muted">Loading…</p>
  if (error) {
    return (
      <Notice tone="danger" title="Could not load this order" action={<Button small onClick={reload}>Retry</Button>}>
        {error}
      </Notice>
    )
  }
  if (!order) return <Notice tone="warning">That order does not exist.</Notice>

  const liveByLine = new Map((live.data?.data.lines ?? []).map((line) => [line.line_id, line]))
  const canDispatch = ['CONFIRMED', 'RESERVED', 'PARTIALLY_FULFILLED'].includes(order.status)
  const canInvoice = order.lines.some((line) => Number(line.delivered_qty) > Number(line.invoiced_qty))
  const isOpen = !['CANCELLED', 'CLOSED'].includes(order.status)
  const facets = order.facets

  return (
    <div className="sales-stack">
      <header className="sales-page-header">
        <div style={{ minWidth: 0 }}>
          <Link to="/orders" className="sales-row" style={{ gap: 4, fontSize: 13 }}>
            <ChevronLeft size={14} aria-hidden /> Sales orders
          </Link>
          <h1 className="sales-row" style={{ marginTop: 6, gap: 12 }}>
            {order.order_no}
            <StatusBadge status={order.status} />
          </h1>
          <p>
            {order.customer_name_snapshot ?? `Account ${order.customer_account_id}`} · {date(order.order_date)}
            {order.customer_po_ref && ` · PO ${order.customer_po_ref}`}
            {order.quotation_id && (
              <>
                {' · from '}
                <Link to={`/quotations/${order.quotation_id}`}>quotation</Link>
              </>
            )}
          </p>
        </div>

        <div className="sales-actions">
          {order.status === 'DRAFT' && can('order.confirm') && (
            <Button tone="primary" disabled={busy} onClick={() => act('confirm')}>
              Confirm order
            </Button>
          )}
          {order.status === 'RESERVATION_PENDING' && can('fulfilment.request') && (
            <Button disabled={busy} onClick={() => act('reserve')}>
              Retry reservation
            </Button>
          )}
          {canDispatch && can('fulfilment.request') && (
            <Button disabled={busy} onClick={() => setDispatching(true)}>
              Dispatch
            </Button>
          )}
          {canInvoice && can('invoice.request') && (
            <Button tone="primary" disabled={busy} onClick={() => act('invoice', { basis: 'delivered' })}>
              Raise invoice in Books
            </Button>
          )}
          {isOpen && can('order.cancel') && (
            <Button tone="danger" disabled={busy} onClick={() => setCancelling(true)}>
              Cancel
            </Button>
          )}
        </div>
      </header>

      {actionError && (
        <Notice tone="danger" title="That did not work" onDismiss={() => setActionError(null)}>
          {actionError}
        </Notice>
      )}

      {/* Five questions, five answers. One badge saying COMPLETED was being read
          as all of them at once, and differently by each reader. */}
      {facets && (
        <dl className="sales-facets">
          {FACET_LABELS.map(({ key, title }) => (
            <div key={key} className="sales-facet">
              <dt>{title}</dt>
              <dd>
                <Badge tone={facets[key].tone as BadgeTone} dot>
                  {facets[key].label}
                </Badge>
              </dd>
              {facets[key].detail && <p>{facets[key].detail}</p>}
            </div>
          ))}
        </dl>
      )}

      <CommandStrip
        commands={order.commands}
        busy={busy}
        onRetry={(command) => {
          if (command.command_type === 'sales.order.reserve') void act('reserve')
          else if (command.command_type === 'sales.invoice.request') void act('invoice', { basis: 'delivered' })
          else if (command.command_type === 'sales.order.issue') void act('dispatch')
        }}
      />

      {credit && (
        <Notice
          tone={credit.decision === 'ALLOW' ? 'success' : credit.decision === 'BLOCK' ? 'danger' : 'warning'}
          title={`Credit check: ${credit.decision.replace(/_/g, ' ').toLowerCase()}`}
          onDismiss={() => setCredit(null)}
        >
          {credit.reason}
          <span className="sales-row" style={{ gap: 18, marginTop: 6, fontSize: 13 }}>
            {credit.credit_limit !== null && <span>Limit {money(credit.credit_limit)}</span>}
            {credit.outstanding !== null && <span>Outstanding {money(credit.outstanding)}</span>}
            {credit.overdue !== null && credit.overdue > 0 && (
              <span className="sales-tone-negative">Overdue {money(credit.overdue)}</span>
            )}
          </span>
          <span className="sales-note" style={{ display: 'block', marginTop: 6 }}>
            Read from Smart Books when the check ran. Sales holds no customer balance of its own.
          </span>
        </Notice>
      )}

      {live.data && !live.data.data.inventory_reachable && (
        <Notice tone="warning" title="Live stock is unavailable">
          Inventory did not answer, so the availability column is blank. That is not the same as zero stock — the
          order's own figures below are unaffected.
        </Notice>
      )}

      <Panel title="Lines" flush>
        <DataTable
          caption="Order lines"
          rows={order.lines}
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
                </>
              ),
            },
            { key: 'ordered', header: 'Ordered', numeric: true, render: (line) => qty(line.ordered_qty) },
            { key: 'delivered', header: 'Delivered', numeric: true, render: (line) => qty(line.delivered_qty) },
            { key: 'invoiced', header: 'Invoiced', numeric: true, render: (line) => qty(line.invoiced_qty) },
            {
              key: 'available',
              header: 'Available now',
              numeric: true,
              render: (line) => {
                const row = liveByLine.get(line.line_id)
                if (line.is_service) return <span className="sales-muted">n/a</span>
                if (!row?.live_availability) return <span className="sales-muted">Unknown</span>
                const available = row.live_availability.available ?? 0
                const short = (row.live_availability.shortfall ?? 0) > 0

                return <span className={short ? 'sales-tone-negative' : undefined}>{qty(available)}</span>
              },
            },
            { key: 'rate', header: 'Rate', numeric: true, render: (line) => money(line.rate, order.currency_code) },
            { key: 'amount', header: 'Amount', numeric: true, render: (line) => money(line.line_amount, order.currency_code) },
          ]}
        />
        <div style={{ padding: '14px 20px' }}>
          <p className="sales-note" style={{ margin: 0 }}>
            "Available now" is read from Inventory on this page load and is not stored. Ordered, delivered and invoiced
            are this order's own progress.
          </p>
        </div>
      </Panel>

      <div className="sales-form-grid">
        <Panel title="Dispatches" flush>
          <DataTable
            caption="Dispatches"
            rows={order.fulfilments}
            rowKey={(row) => row.request_id}
            empty="Nothing dispatched yet."
            columns={[
              { key: 'date', header: 'Requested', render: (row) => date(row.created_at) },
              { key: 'status', header: 'Status', render: (row) => <StatusBadge status={row.status} /> },
              {
                key: 'doc',
                header: 'Inventory document',
                render: (row) => row.inventory_document_no ?? row.inventory_document_uuid ?? '—',
              },
            ]}
          />
        </Panel>

        <Panel title="Invoices" flush>
          <DataTable
            caption="Invoices"
            rows={order.invoice_requests}
            rowKey={(row) => row.request_id}
            empty="Nothing invoiced yet."
            columns={[
              { key: 'date', header: 'Requested', render: (row) => date(row.created_at) },
              { key: 'basis', header: 'Basis', render: (row) => row.basis },
              { key: 'status', header: 'Status', render: (row) => <StatusBadge status={row.status} /> },
              {
                key: 'voucher',
                header: 'Books voucher',
                render: (row) => row.books_voucher_no ?? (row.books_voucher_id ? `#${row.books_voucher_id}` : '—'),
              },
            ]}
          />
          <div style={{ padding: '14px 20px' }}>
            <p className="sales-note" style={{ margin: 0 }}>
              The invoice itself lives in Smart Books. Sales keeps the reference so you can find it — not a copy of it.
            </p>
          </div>
        </Panel>
      </div>

      {cancelling && (
        <Drawer title={`Cancel ${order.order_no}`} onClose={() => setCancelling(false)}>
          <Notice tone="warning" title="This releases any reserved stock">
            The order stays on record with its reason. Nothing already invoiced can be cancelled from here — that is a
            credit note, and it belongs in Smart Books.
          </Notice>
          <Field label="Why is it being cancelled?" required>
            <Input value={cancelReason} onChange={(event) => setCancelReason(event.target.value)} placeholder="Customer withdrew the order" />
          </Field>
          <div className="sales-actions">
            <Button
              tone="danger"
              disabled={busy || cancelReason.trim() === ''}
              onClick={async () => {
                await act('cancel', { reason: cancelReason.trim() })
                setCancelling(false)
                setCancelReason('')
              }}
            >
              Cancel the order
            </Button>
            <Button onClick={() => setCancelling(false)} disabled={busy}>
              Keep it
            </Button>
          </div>
        </Drawer>
      )}

      {dispatching && (
        <DispatchDrawer
          order={order}
          live={liveByLine}
          busy={busy}
          onClose={() => setDispatching(false)}
          onDispatch={async (lines) => {
            await act('dispatch', lines.length > 0 ? { lines } : {})
            setDispatching(false)
          }}
        />
      )}
    </div>
  )
}

/**
 * Choosing what to send.
 *
 * Partial dispatch is the whole point of this dialog: when Inventory can cover
 * part of an order and the promise date is close, sending what is available and
 * back-ordering the rest keeps the date for most of it. The quantities default
 * to what is outstanding and are capped there — the backend refuses more, and
 * offering a field that will be rejected wastes the user's time.
 */
function DispatchDrawer({
  order,
  live,
  busy,
  onClose,
  onDispatch,
}: {
  order: SalesOrder
  live: Map<number, { live_availability: { available?: number } | null; outstanding_qty: number }>
  busy: boolean
  onClose: () => void
  onDispatch: (lines: Array<{ line_id: number; qty: number }>) => Promise<void>
}) {
  const outstanding = order.lines
    .map((line) => ({
      line,
      remaining: Math.max(0, Number(line.ordered_qty) - Number(line.delivered_qty)),
      available: live.get(line.line_id)?.live_availability?.available ?? null,
    }))
    .filter((entry) => entry.remaining > 0)

  const [quantities, setQuantities] = useState<Record<number, string>>(() =>
    Object.fromEntries(outstanding.map((entry) => [entry.line.line_id, String(entry.remaining)])),
  )

  const chosen = outstanding
    .map((entry) => ({ line_id: entry.line.line_id, qty: Number(quantities[entry.line.line_id] ?? 0) }))
    .filter((entry) => entry.qty > 0)

  const overCommitted = outstanding.some(
    (entry) => Number(quantities[entry.line.line_id] ?? 0) > entry.remaining,
  )

  return (
    <Drawer title={`Dispatch from ${order.order_no}`} onClose={onClose}>
      <Notice tone="info" title="Sales asks, Inventory moves">
        This sends a request to Inventory. It writes the stock ledger and values the issue; what comes back is what
        gets recorded as delivered here.
      </Notice>

      {outstanding.length === 0 ? (
        <p>Everything on this order has already been dispatched.</p>
      ) : (
        <>
          {outstanding.map((entry) => (
            <Field
              key={entry.line.line_id}
              label={entry.line.description ?? `Line ${entry.line.line_no}`}
              hint={
                entry.available === null
                  ? `${qty(entry.remaining)} outstanding · availability unknown`
                  : `${qty(entry.remaining)} outstanding · ${qty(entry.available)} available now`
              }
              error={
                Number(quantities[entry.line.line_id] ?? 0) > entry.remaining
                  ? `Only ${qty(entry.remaining)} is outstanding on this line.`
                  : undefined
              }
            >
              <Input
                inputMode="decimal"
                value={quantities[entry.line.line_id] ?? ''}
                onChange={(event) =>
                  setQuantities((current) => ({ ...current, [entry.line.line_id]: event.target.value }))
                }
                aria-invalid={Number(quantities[entry.line.line_id] ?? 0) > entry.remaining}
              />
            </Field>
          ))}

          <div className="sales-actions">
            <Button tone="primary" disabled={busy || chosen.length === 0 || overCommitted} onClick={() => void onDispatch(chosen)}>
              {busy ? 'Asking Inventory…' : `Dispatch ${chosen.length} line${chosen.length === 1 ? '' : 's'}`}
            </Button>
            <Button onClick={onClose} disabled={busy}>
              Cancel
            </Button>
          </div>
        </>
      )}
    </Drawer>
  )
}
