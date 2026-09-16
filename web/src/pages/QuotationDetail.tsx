import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, ApiError } from '../services/api'
import type { Quotation } from '../services/types'
import { useApi } from '../hooks/useApi'
import { useSales } from '../context/SalesContext'
import { Button, Card, DataTable, date, money, Notice, qty, StatusBadge } from '../ui'

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
}

export default function QuotationDetail() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { scope, can } = useSales()
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)

  const { data, loading, error, reload } = useApi(
    (signal) => api.one<Quotation>(`v1/quotations/${id}`, undefined, signal),
    [id, scope?.cmp_id],
    Boolean(scope && id),
  )

  const quotation = data?.data

  async function run(action: string) {
    setBusy(true)
    setActionError(null)
    try {
      await api.post<Quotation>(`v1/quotations/${id}/${action}`, {})
      reload()
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  async function convertToOrder() {
    if (!quotation) return
    setBusy(true)
    setActionError(null)
    try {
      const response = await api.post<{ order_id: number }>('v1/orders', {
        quotation_id: quotation.quotation_id,
        customer_account_id: quotation.customer_account_id,
        customer_name: quotation.customer_name_snapshot,
        salesperson_id: quotation.salesperson_id,
        territory_id: quotation.territory_id,
        channel_id: quotation.channel_id,
        price_book_id: quotation.price_book_id,
        payment_terms: quotation.payment_terms,
        delivery_terms: quotation.delivery_terms,
        customer_po_ref: quotation.customer_po_ref,
        currency_code: quotation.currency_code,
        lines: quotation.lines
          .filter((line) => !line.is_optional)
          .map((line) => ({
            quotation_line_id: line.line_id,
            item_id: line.item_id,
            unit_id: line.unit_id,
            warehouse_id: line.warehouse_id,
            is_service: line.is_service,
            description: line.description,
            ordered_qty: Number(line.quantity),
            rate: Number(line.rate),
            discount_pc: Number(line.discount_pc),
            tax_cat_id: line.tax_cat_id,
            estimated_tax_pc: Number(line.estimated_tax_pc),
          })),
      })
      navigate(`/orders/${response.data.order_id}`)
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <p style={{ color: 'var(--muted)' }}>Loading…</p>
  if (error) {
    return (
      <Notice tone="danger" title="Could not load this quotation">
        {error} <Button tone="ghost" onClick={reload}>Retry</Button>
      </Notice>
    )
  }
  if (!quotation) return <Notice tone="warning">That quotation does not exist.</Notice>

  const actions = (ACTIONS[quotation.status] ?? []).filter((entry) => can(entry.permission))
  const pendingApprovals = quotation.approvals.filter((approval) => approval.status === 'PENDING')

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <header style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: '1rem', flexWrap: 'wrap' }}>
        <div>
          <Link to="/quotations" style={{ fontSize: '0.85rem' }}>← Quotations</Link>
          <h1 style={{ margin: '0.25rem 0 0', fontSize: '1.3rem', display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
            {quotation.quotation_no}
            {quotation.revision_no > 0 && <span style={{ color: 'var(--muted)', fontSize: '1rem' }}>revision {quotation.revision_no}</span>}
            <StatusBadge status={quotation.status} />
          </h1>
          <p style={{ margin: '0.3rem 0 0', color: 'var(--muted)' }}>
            {quotation.customer_name_snapshot ?? `Account ${quotation.customer_account_id}`} · {date(quotation.quotation_date)}
          </p>
        </div>

        <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
          {actions.map((entry) => (
            <Button key={entry.action} tone={entry.tone ?? 'secondary'} disabled={busy} onClick={() => run(entry.action)}>
              {entry.label}
            </Button>
          ))}
          {quotation.status === 'ACCEPTED' && can('order.create') && (
            <Button tone="primary" disabled={busy} onClick={convertToOrder}>
              Convert to order
            </Button>
          )}
          {can('quotation.create') && !['CONVERTED', 'CANCELLED'].includes(quotation.status) && (
            <Button disabled={busy} onClick={() => navigate(`/quotations/${quotation.quotation_id}/revise`)}>
              Revise
            </Button>
          )}
        </div>
      </header>

      {actionError && <Notice tone="danger" title="That did not work">{actionError}</Notice>}

      {pendingApprovals.length > 0 && (
        <Notice tone="warning" title="Waiting for approval">
          <ul style={{ margin: '0.3rem 0 0', paddingLeft: '1.1rem' }}>
            {pendingApprovals.map((approval) => (
              <li key={approval.approval_id}>{approval.reason_detail}</li>
            ))}
          </ul>
        </Notice>
      )}

      <Card title="Lines">
        <DataTable
          rows={quotation.lines}
          rowKey={(line) => line.line_id}
          columns={[
            { key: 'no', header: '#', width: '3rem', render: (line) => line.line_no },
            {
              key: 'item',
              header: 'Item',
              render: (line) =>
                line.is_service ? (
                  <span>{line.description ?? 'Service'}</span>
                ) : (
                  // The item id is ours; its NAME belongs to Inventory. Showing
                  // the id with the description avoids storing a name that would
                  // go stale the moment somebody renames the item.
                  <span>
                    {line.description ?? `Item #${line.item_id}`}
                    <span style={{ color: 'var(--muted)', fontSize: '0.78rem', display: 'block' }}>
                      Inventory item {line.item_id}
                    </span>
                  </span>
                ),
            },
            { key: 'qty', header: 'Qty', numeric: true, render: (line) => qty(line.quantity) },
            { key: 'rate', header: 'Rate', numeric: true, render: (line) => money(line.rate, quotation.currency_code) },
            { key: 'disc', header: 'Disc %', numeric: true, render: (line) => qty(line.discount_pc) },
            { key: 'tax', header: 'Tax %', numeric: true, render: (line) => qty(line.estimated_tax_pc) },
            { key: 'amount', header: 'Amount', numeric: true, render: (line) => money(line.line_amount, quotation.currency_code) },
          ]}
        />

        <dl
          style={{
            display: 'grid',
            gridTemplateColumns: 'auto auto',
            gap: '0.3rem 1.5rem',
            justifyContent: 'end',
            marginTop: '1rem',
            marginBottom: 0,
          }}
        >
          <dt style={{ color: 'var(--muted)' }}>Subtotal</dt>
          <dd className="num" style={{ margin: 0 }}>{money(quotation.subtotal_amount, quotation.currency_code)}</dd>
          <dt style={{ color: 'var(--muted)' }}>Discount</dt>
          <dd className="num" style={{ margin: 0 }}>−{money(quotation.discount_amount, quotation.currency_code)}</dd>
          <dt style={{ color: 'var(--muted)' }} title="Books calculates the tax that is actually charged when the invoice is raised.">
            Estimated tax
          </dt>
          <dd className="num" style={{ margin: 0 }}>{money(quotation.estimated_tax_amount, quotation.currency_code)}</dd>
          <dt style={{ fontWeight: 600 }}>Total</dt>
          <dd className="num" style={{ margin: 0, fontWeight: 600 }}>{money(quotation.total_amount, quotation.currency_code)}</dd>
        </dl>

        <p style={{ color: 'var(--muted)', fontSize: '0.78rem', marginTop: '0.75rem', marginBottom: 0 }}>
          Tax shown here is an estimate for the customer's benefit. Smart Books calculates and files the tax that is
          actually charged when the invoice is raised.
        </p>
      </Card>
    </div>
  )
}
