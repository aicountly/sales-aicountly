import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { Trash2 } from 'lucide-react'
import { api, ApiError } from '../services/api'
import type { CatalogCustomer, CatalogItem, Quotation } from '../services/types'
import { useApi } from '../hooks/useApi'
import { useSales } from '../context/SalesContext'
import { CustomerPicker, ItemPicker } from '../components/LivePicker'
import { Button, Card, Field, Input, money, Notice, Textarea } from '../ui'

interface DraftLine {
  key: string
  item_id: number | null
  /** Shown while editing only. Never saved: the name belongs to Inventory. */
  item_label: string
  unit_id: number | null
  is_service: boolean
  description: string
  quantity: string
  rate: string
  discount_pc: string
  estimated_tax_pc: string
}

function emptyLine(): DraftLine {
  return {
    key: Math.random().toString(36).slice(2),
    item_id: null,
    item_label: '',
    unit_id: null,
    is_service: false,
    description: '',
    quantity: '1',
    rate: '0',
    discount_pc: '0',
    estimated_tax_pc: '18',
  }
}

function lineNet(line: DraftLine): number {
  const gross = Number(line.quantity || 0) * Number(line.rate || 0)
  return gross - (gross * Number(line.discount_pc || 0)) / 100
}

export default function QuotationEditor() {
  const { id } = useParams<{ id?: string }>()
  const navigate = useNavigate()
  const { scope } = useSales()
  const revising = Boolean(id)

  const [customer, setCustomer] = useState<{ id: number; name: string } | null>(null)
  const [quotationDate, setQuotationDate] = useState(() => new Date().toISOString().slice(0, 10))
  const [validUntil, setValidUntil] = useState('')
  const [paymentTerms, setPaymentTerms] = useState('')
  const [customerPo, setCustomerPo] = useState('')
  const [notes, setNotes] = useState('')
  const [lines, setLines] = useState<DraftLine[]>([emptyLine()])
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // When revising, start from the quotation being replaced rather than a blank
  // form: a revision is almost always a small change to an agreed document.
  const existing = useApi(
    (signal) => api.one<Quotation>(`v1/quotations/${id}`, undefined, signal),
    [id],
    revising && Boolean(scope),
  )

  const loaded = existing.data?.data
  const [seeded, setSeeded] = useState(false)
  if (revising && loaded && !seeded) {
    setSeeded(true)
    setCustomer({ id: loaded.customer_account_id, name: loaded.customer_name_snapshot ?? `Account ${loaded.customer_account_id}` })
    setQuotationDate(loaded.quotation_date)
    setValidUntil(loaded.valid_until ?? '')
    setPaymentTerms(loaded.payment_terms ?? '')
    setCustomerPo(loaded.customer_po_ref ?? '')
    setNotes(loaded.notes ?? '')
    setLines(
      loaded.lines.map((line) => ({
        key: String(line.line_id),
        item_id: line.item_id,
        item_label: line.description ?? (line.item_id ? `Item #${line.item_id}` : ''),
        unit_id: line.unit_id,
        is_service: line.is_service,
        description: line.description ?? '',
        quantity: line.quantity,
        rate: line.rate,
        discount_pc: line.discount_pc,
        estimated_tax_pc: line.estimated_tax_pc,
      })),
    )
  }

  function patchLine(key: string, patch: Partial<DraftLine>) {
    setLines((current) => current.map((line) => (line.key === key ? { ...line, ...patch } : line)))
  }

  /**
   * Ask the backend what this item should cost.
   *
   * The price comes from our own price books; the COST it may be checked
   * against comes from Inventory, live, inside that same call. Nothing about
   * the item is stored here beyond its id.
   */
  async function applyCatalogItem(key: string, item: CatalogItem) {
    patchLine(key, { item_id: item.item_id, item_label: item.item_name, unit_id: item.unit_id, description: item.item_name })

    try {
      const response = await api.post<{ rate: number }>('v1/catalog/price', {
        item_id: item.item_id,
        quantity: Number(lines.find((line) => line.key === key)?.quantity ?? 1),
        customer_account_id: customer?.id,
      })
      if (response.data.rate > 0) {
        patchLine(key, { rate: String(response.data.rate) })
      }
    } catch {
      // No price book rule, or pricing unreachable. The user types the rate;
      // leaving the field at zero is better than inventing a number.
    }
  }

  const subtotal = lines.reduce((sum, line) => sum + Number(line.quantity || 0) * Number(line.rate || 0), 0)
  const discount = lines.reduce(
    (sum, line) => sum + (Number(line.quantity || 0) * Number(line.rate || 0) * Number(line.discount_pc || 0)) / 100,
    0,
  )
  const tax = lines.reduce((sum, line) => sum + (lineNet(line) * Number(line.estimated_tax_pc || 0)) / 100, 0)

  async function save() {
    if (!customer) {
      setError('Choose a customer first.')
      return
    }
    setSaving(true)
    setError(null)

    const payload = {
      customer_account_id: customer.id,
      customer_name: customer.name,
      quotation_date: quotationDate,
      valid_until: validUntil || undefined,
      payment_terms: paymentTerms || undefined,
      customer_po_ref: customerPo || undefined,
      notes: notes || undefined,
      lines: lines
        .filter((line) => line.is_service || line.item_id)
        .map((line) => ({
          item_id: line.item_id,
          unit_id: line.unit_id,
          is_service: line.is_service,
          description: line.description || undefined,
          quantity: Number(line.quantity || 0),
          rate: Number(line.rate || 0),
          discount_pc: Number(line.discount_pc || 0),
          estimated_tax_pc: Number(line.estimated_tax_pc || 0),
        })),
    }

    try {
      const response = revising
        ? await api.post<Quotation>(`v1/quotations/${id}/revise`, payload)
        : await api.post<Quotation>('v1/quotations', payload)
      navigate(`/quotations/${response.data.quotation_id}`)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div style={{ display: 'grid', gap: '1rem', maxWidth: '64rem' }}>
      <h1 style={{ margin: 0, fontSize: '1.3rem' }}>{revising ? 'Revise quotation' : 'New quotation'}</h1>

      {revising && (
        <Notice tone="info">
          A revision is saved as a new version. The quotation it replaces keeps its own lines and stays readable — which
          is what matters when there is a disagreement about what was agreed.
        </Notice>
      )}

      {error && <Notice tone="danger" title="Could not save">{error}</Notice>}

      <Card title="Customer and terms">
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(13rem, 1fr))', gap: '0.85rem' }}>
          <CustomerPicker
            selectedLabel={customer?.name}
            onPick={(picked: CatalogCustomer) => setCustomer({ id: picked.acc_id, name: picked.acc_name })}
          />
          <Field label="Quotation date">
            <Input type="date" value={quotationDate} onChange={(event) => setQuotationDate(event.target.value)} />
          </Field>
          <Field label="Valid until" hint="Defaults to the company setting">
            <Input type="date" value={validUntil} onChange={(event) => setValidUntil(event.target.value)} />
          </Field>
          <Field label="Payment terms">
            <Input value={paymentTerms} onChange={(event) => setPaymentTerms(event.target.value)} placeholder="30 days" />
          </Field>
          <Field label="Customer PO reference">
            <Input value={customerPo} onChange={(event) => setCustomerPo(event.target.value)} />
          </Field>
        </div>
      </Card>

      <Card title="Lines" action={<Button onClick={() => setLines((current) => [...current, emptyLine()])}>Add line</Button>}>
        <div style={{ display: 'grid', gap: '0.85rem' }}>
          {lines.map((line) => (
            <div
              key={line.key}
              style={{
                border: '1px solid var(--border)',
                borderRadius: 'var(--radius-sm)',
                padding: '0.75rem',
                display: 'grid',
                gap: '0.6rem',
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '1rem' }}>
                <label style={{ display: 'flex', alignItems: 'center', gap: '0.4rem', fontSize: '0.85rem' }}>
                  <input
                    type="checkbox"
                    checked={line.is_service}
                    onChange={(event) => patchLine(line.key, { is_service: event.target.checked, item_id: null, item_label: '' })}
                  />
                  Service line (no stock item)
                </label>
                <Button
                  tone="ghost"
                  onClick={() => setLines((current) => (current.length > 1 ? current.filter((l) => l.key !== line.key) : current))}
                  title="Remove this line"
                >
                  <Trash2 size={14} aria-hidden />
                </Button>
              </div>

              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(9rem, 1fr))', gap: '0.6rem', alignItems: 'end' }}>
                {line.is_service ? (
                  <div style={{ gridColumn: 'span 2' }}>
                    <Field label="Description">
                      <Input value={line.description} onChange={(event) => patchLine(line.key, { description: event.target.value })} />
                    </Field>
                  </div>
                ) : (
                  <div style={{ gridColumn: 'span 2' }}>
                    <ItemPicker selectedLabel={line.item_label || null} onPick={(item) => void applyCatalogItem(line.key, item)} />
                  </div>
                )}
                <Field label="Quantity">
                  <Input value={line.quantity} inputMode="decimal" onChange={(event) => patchLine(line.key, { quantity: event.target.value })} />
                </Field>
                <Field label="Rate">
                  <Input value={line.rate} inputMode="decimal" onChange={(event) => patchLine(line.key, { rate: event.target.value })} />
                </Field>
                <Field label="Discount %">
                  <Input value={line.discount_pc} inputMode="decimal" onChange={(event) => patchLine(line.key, { discount_pc: event.target.value })} />
                </Field>
                <Field label="Tax %" hint="Estimate only">
                  <Input value={line.estimated_tax_pc} inputMode="decimal" onChange={(event) => patchLine(line.key, { estimated_tax_pc: event.target.value })} />
                </Field>
                <div style={{ textAlign: 'right', paddingBottom: '0.35rem' }}>
                  <div style={{ fontSize: '0.78rem', color: 'var(--muted)' }}>Line</div>
                  <div className="num" style={{ fontWeight: 600 }}>{money(lineNet(line))}</div>
                </div>
              </div>
            </div>
          ))}
        </div>

        <dl style={{ display: 'grid', gridTemplateColumns: 'auto auto', gap: '0.3rem 1.5rem', justifyContent: 'end', marginTop: '1rem', marginBottom: 0 }}>
          <dt style={{ color: 'var(--muted)' }}>Subtotal</dt>
          <dd className="num" style={{ margin: 0 }}>{money(subtotal)}</dd>
          <dt style={{ color: 'var(--muted)' }}>Discount</dt>
          <dd className="num" style={{ margin: 0 }}>−{money(discount)}</dd>
          <dt style={{ color: 'var(--muted)' }}>Estimated tax</dt>
          <dd className="num" style={{ margin: 0 }}>{money(tax)}</dd>
          <dt style={{ fontWeight: 600 }}>Total</dt>
          <dd className="num" style={{ margin: 0, fontWeight: 600 }}>{money(subtotal - discount + tax)}</dd>
        </dl>
      </Card>

      <Card title="Notes">
        <Textarea value={notes} onChange={(event) => setNotes(event.target.value)} placeholder="Anything the customer should see on the quotation." />
      </Card>

      <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end' }}>
        <Button onClick={() => navigate(-1)}>Cancel</Button>
        <Button tone="primary" disabled={saving} onClick={save}>
          {saving ? 'Saving…' : revising ? 'Save revision' : 'Save quotation'}
        </Button>
      </div>
    </div>
  )
}
