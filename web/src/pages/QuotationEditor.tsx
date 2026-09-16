import { useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { Plus } from 'lucide-react'
import { api, ApiError } from '../services/api'
import type { CatalogCustomer, Quotation } from '../services/types'
import { useApi } from '../hooks/useApi'
import { useSales } from '../context/SalesContext'
import { CustomerPicker } from '../components/LivePicker'
import { emptyLine, LineEditor, lineError, type DraftLine } from '../components/LineEditor'
import { Button, Field, Input, Notice, Panel, Textarea, useUnsavedChanges } from '../ui'

/**
 * Creating and revising a quotation.
 *
 * A REVISION IS A NEW ROW. The version being replaced keeps its own lines and
 * stays readable, which is the one thing that matters when there is an argument
 * later about what was agreed. Nothing is edited in place.
 *
 * The totals shown while typing are a preview. The figures that get stored are
 * recomputed on the server from the same rules that decide whether the document
 * needs an approval, so a browser that is out of date cannot talk its way past
 * a discount limit.
 */
export default function QuotationEditor() {
  const { id } = useParams<{ id?: string }>()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const { scope } = useSales()
  const revising = Boolean(id)

  const [customer, setCustomer] = useState<{ id: number; name: string } | null>(null)
  const [quotationDate, setQuotationDate] = useState(() => new Date().toISOString().slice(0, 10))
  const [validUntil, setValidUntil] = useState('')
  const [paymentTerms, setPaymentTerms] = useState('')
  const [deliveryTerms, setDeliveryTerms] = useState('')
  const [customerPo, setCustomerPo] = useState('')
  const [notes, setNotes] = useState('')
  const [lines, setLines] = useState<DraftLine[]>([emptyLine()])
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [showErrors, setShowErrors] = useState(false)

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
    setCustomer({
      id: loaded.customer_account_id,
      name: loaded.customer_name_snapshot ?? `Account ${loaded.customer_account_id}`,
    })
    setQuotationDate(loaded.quotation_date)
    setValidUntil(loaded.valid_until ?? '')
    setPaymentTerms(loaded.payment_terms ?? '')
    setDeliveryTerms(loaded.delivery_terms ?? '')
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
        is_optional: line.is_optional,
      })),
    )
  }

  // Arriving from "Draft quotation" on the customers dashboard: prefill the
  // customer rather than making somebody search for the name they just clicked.
  const prefillAccount = params.get('customer_account_id')
  const [prefilled, setPrefilled] = useState(false)
  if (!revising && prefillAccount && !prefilled && !customer) {
    setPrefilled(true)
    void api
      .one<{ acc_id: number; acc_name: string }>(`v1/catalog/customers/${prefillAccount}/credit`)
      .then((response) => setCustomer({ id: response.data.acc_id, name: response.data.acc_name }))
      .catch(() => {
        // Books could not name the account. The picker still works; we simply do
        // not guess a name for an id we cannot resolve.
      })
  }

  const dirty = customer !== null || lines.some((line) => line.item_id !== null || line.description !== '')
  useUnsavedChanges(dirty && !saving)

  const invalidLines = lines.filter((line) => lineError(line) !== null)

  async function save() {
    setShowErrors(true)
    if (!customer) {
      setError('Choose a customer first.')
      return
    }
    if (invalidLines.length > 0) {
      setError('Some lines are incomplete. Fix the ones marked in red.')
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
      delivery_terms: deliveryTerms || undefined,
      customer_po_ref: customerPo || undefined,
      notes: notes || undefined,
      lines: lines.map((line) => ({
        item_id: line.item_id,
        unit_id: line.unit_id,
        is_service: line.is_service,
        description: line.description || undefined,
        quantity: Number(line.quantity || 0),
        rate: Number(line.rate || 0),
        discount_pc: Number(line.discount_pc || 0),
        estimated_tax_pc: Number(line.estimated_tax_pc || 0),
        is_optional: Boolean(line.is_optional),
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
    <div className="sales-stack" style={{ maxWidth: '64rem' }}>
      <header className="sales-page-header">
        <div>
          <h1>{revising ? 'Revise quotation' : 'New quotation'}</h1>
          <p>{revising ? 'Saved as a new version — the one it replaces stays readable.' : 'What we are offering, and until when.'}</p>
        </div>
      </header>

      {revising && (
        <Notice tone="info" title="A revision is a new version">
          The quotation it replaces keeps its own lines and stays readable, which is what matters when there is a
          disagreement about what was agreed.
        </Notice>
      )}

      {error && (
        <Notice tone="danger" title="Could not save" onDismiss={() => setError(null)}>
          {error}
        </Notice>
      )}

      <Panel title="Customer and terms">
        <div className="sales-form-grid">
          <CustomerPicker
            selectedLabel={customer?.name}
            onPick={(picked: CatalogCustomer) => setCustomer({ id: picked.acc_id, name: picked.acc_name })}
          />
          <Field label="Quotation date" required>
            <Input type="date" value={quotationDate} onChange={(event) => setQuotationDate(event.target.value)} />
          </Field>
          <Field label="Valid until" hint="Defaults to the company setting. Past this date the quotation lapses.">
            <Input type="date" value={validUntil} onChange={(event) => setValidUntil(event.target.value)} />
          </Field>
          <Field label="Payment terms">
            <Input value={paymentTerms} onChange={(event) => setPaymentTerms(event.target.value)} placeholder="30 days" />
          </Field>
          <Field label="Delivery terms">
            <Input value={deliveryTerms} onChange={(event) => setDeliveryTerms(event.target.value)} placeholder="Ex works" />
          </Field>
          <Field label="Customer PO reference">
            <Input value={customerPo} onChange={(event) => setCustomerPo(event.target.value)} />
          </Field>
        </div>
      </Panel>

      <Panel
        title="Lines"
        description="Optional extras are shown to the customer but left out of the total."
        action={
          <Button onClick={() => setLines((current) => [...current, emptyLine()])}>
            <Plus size={15} aria-hidden /> Add line
          </Button>
        }
      >
        <LineEditor
          lines={lines}
          onChange={setLines}
          customerAccountId={customer?.id}
          currency={loaded?.currency_code ?? 'INR'}
          allowOptional
          showErrors={showErrors}
        />
      </Panel>

      <Panel title="Notes">
        <Textarea
          value={notes}
          onChange={(event) => setNotes(event.target.value)}
          placeholder="Anything the customer should see on the quotation."
        />
      </Panel>

      <div className="sales-actions" style={{ justifyContent: 'flex-end' }}>
        <Button onClick={() => navigate(-1)} disabled={saving}>
          Cancel
        </Button>
        <Button tone="primary" disabled={saving} onClick={save}>
          {saving ? 'Saving…' : revising ? 'Save revision' : 'Save quotation'}
        </Button>
      </div>
    </div>
  )
}
