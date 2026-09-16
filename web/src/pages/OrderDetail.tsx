import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, ApiError } from '../services/api'
import type { CreditVerdict, SalesOrder } from '../services/types'
import { useApi } from '../hooks/useApi'
import { useSales } from '../context/SalesContext'
import { CommandStrip } from '../components/CommandStrip'
import { Button, Card, DataTable, date, money, Notice, qty, StatusBadge } from '../ui'

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

export default function OrderDetail() {
  const { id } = useParams<{ id: string }>()
  const { scope, can } = useSales()
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)
  const [credit, setCredit] = useState<CreditVerdict | null>(null)
  const [cancelReason, setCancelReason] = useState('')
  const [cancelling, setCancelling] = useState(false)

  const { data, loading, error, reload } = useApi(
    (signal) => api.one<SalesOrder>(`v1/orders/${id}`, undefined, signal),
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

  if (loading) return <p style={{ color: 'var(--muted)' }}>Loading…</p>
  if (error) {
    return (
      <Notice tone="danger" title="Could not load this order">
        {error} <Button tone="ghost" onClick={reload}>Retry</Button>
      </Notice>
    )
  }
  if (!order) return <Notice tone="warning">That order does not exist.</Notice>

  const liveByLine = new Map((live.data?.data.lines ?? []).map((line) => [line.line_id, line]))
  const canDispatch = ['CONFIRMED', 'RESERVED', 'PARTIALLY_FULFILLED'].includes(order.status)
  const canInvoice = order.lines.some((line) => Number(line.delivered_qty) > Number(line.invoiced_qty))
  const isOpen = !['CANCELLED', 'CLOSED'].includes(order.status)

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <header style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '1rem', flexWrap: 'wrap' }}>
        <div>
          <Link to="/orders" style={{ fontSize: '0.85rem' }}>← Sales orders</Link>
          <h1 style={{ margin: '0.25rem 0 0', fontSize: '1.3rem', display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
            {order.order_no}
            <StatusBadge status={order.status} />
          </h1>
          <p style={{ margin: '0.3rem 0 0', color: 'var(--muted)' }}>
            {order.customer_name_snapshot ?? `Account ${order.customer_account_id}`} · {date(order.order_date)}
            {order.customer_po_ref && ` · PO ${order.customer_po_ref}`}
          </p>
        </div>

        <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
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
            <Button disabled={busy} onClick={() => act('dispatch')}>
              Dispatch outstanding
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

      {actionError && <Notice tone="danger" title="That did not work">{actionError}</Notice>}

      {cancelling && (
        <Card title="Cancel this order">
          <div style={{ display: 'grid', gap: '0.6rem' }}>
            <p style={{ margin: 0, color: 'var(--muted)' }}>
              Cancelling releases any stock reserved for this order. Say why — it is recorded against the order.
            </p>
            <input
              value={cancelReason}
              onChange={(event) => setCancelReason(event.target.value)}
              placeholder="Reason for cancellation"
              style={{ padding: '0.4rem 0.55rem', border: '1px solid var(--border-strong)', borderRadius: 'var(--radius-sm)', background: 'var(--surface)' }}
            />
            <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
              <Button onClick={() => setCancelling(false)}>Keep the order</Button>
              <Button
                tone="danger"
                disabled={busy || cancelReason.trim() === ''}
                onClick={async () => {
                  await act('cancel', { reason: cancelReason.trim() })
                  setCancelling(false)
                  setCancelReason('')
                }}
              >
                Cancel order
              </Button>
            </div>
          </div>
        </Card>
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
        >
          <p style={{ margin: 0 }}>{credit.reason}</p>
          <div style={{ display: 'flex', gap: '1.25rem', marginTop: '0.4rem', flexWrap: 'wrap', fontSize: '0.85rem' }}>
            {credit.credit_limit !== null && <span>Limit {money(credit.credit_limit)}</span>}
            {credit.outstanding !== null && <span>Outstanding {money(credit.outstanding)}</span>}
            {credit.overdue !== null && credit.overdue > 0 && <span style={{ color: 'var(--danger)' }}>Overdue {money(credit.overdue)}</span>}
          </div>
          <p style={{ margin: '0.4rem 0 0', fontSize: '0.78rem', color: 'var(--muted)' }}>
            Read from Smart Books when the check ran. Sales holds no customer balance of its own.
          </p>
        </Notice>
      )}

      {live.data && !live.data.data.inventory_reachable && (
        <Notice tone="warning" title="Live stock is unavailable">
          Inventory did not answer, so the availability column is blank. That is not the same as zero stock — the order's
          own figures below are unaffected.
        </Notice>
      )}

      <Card title="Lines">
        <DataTable
          rows={order.lines}
          rowKey={(line) => line.line_id}
          columns={[
            { key: 'no', header: '#', width: '3rem', render: (line) => line.line_no },
            {
              key: 'item',
              header: 'Item',
              render: (line) =>
                line.is_service ? (
                  line.description ?? 'Service'
                ) : (
                  <span>
                    {line.description ?? `Item #${line.item_id}`}
                    <span style={{ color: 'var(--muted)', fontSize: '0.78rem', display: 'block' }}>Inventory item {line.item_id}</span>
                  </span>
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
                if (!row?.live_availability) return <span style={{ color: 'var(--muted)' }}>—</span>
                const available = row.live_availability.available ?? 0
                const short = (row.live_availability.shortfall ?? 0) > 0
                return <span style={{ color: short ? 'var(--danger)' : undefined }}>{qty(available)}</span>
              },
            },
            { key: 'rate', header: 'Rate', numeric: true, render: (line) => money(line.rate, order.currency_code) },
            { key: 'amount', header: 'Amount', numeric: true, render: (line) => money(line.line_amount, order.currency_code) },
          ]}
        />
        <p style={{ color: 'var(--muted)', fontSize: '0.78rem', marginTop: '0.75rem', marginBottom: 0 }}>
          "Available now" is read from Inventory on this page load and is not stored. Ordered, delivered and invoiced are
          this order's own progress.
        </p>
      </Card>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(20rem, 1fr))', gap: '1rem' }}>
        <Card title="Dispatches">
          <DataTable
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
        </Card>

        <Card title="Invoices">
          <DataTable
            rows={order.invoice_requests}
            rowKey={(row) => row.request_id}
            empty="Nothing invoiced yet."
            columns={[
              { key: 'date', header: 'Requested', render: (row) => date(row.created_at) },
              { key: 'basis', header: 'Basis', render: (row) => row.basis },
              { key: 'status', header: 'Status', render: (row) => <StatusBadge status={row.status} /> },
              { key: 'voucher', header: 'Books voucher', render: (row) => row.books_voucher_no ?? (row.books_voucher_id ? `#${row.books_voucher_id}` : '—') },
            ]}
          />
          <p style={{ color: 'var(--muted)', fontSize: '0.78rem', marginTop: '0.6rem', marginBottom: 0 }}>
            The invoice itself lives in Smart Books. Sales keeps the reference so you can find it — not a copy of it.
          </p>
        </Card>
      </div>
    </div>
  )
}
