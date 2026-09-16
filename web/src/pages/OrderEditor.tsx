import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Plus } from 'lucide-react'
import { api, ApiError } from '../services/api'
import type { CatalogCustomer, SalesOrder } from '../services/types'
import { useSales } from '../context/SalesContext'
import { CustomerPicker } from '../components/LivePicker'
import { emptyLine, LineEditor, lineError, type DraftLine } from '../components/LineEditor'
import { Button, Field, Input, Notice, Panel, Textarea, useUnsavedChanges } from '../ui'

/**
 * A sales order raised directly, without a quotation behind it.
 *
 * The usual route into an order is converting a quotation — that keeps the
 * agreed prices intact and is idempotent. This screen is for the case the
 * business actually has: a customer who rang up and ordered, with no paperwork
 * in between.
 *
 * It saves a DRAFT. Confirming is a separate, deliberate act on the order
 * itself, because confirmation runs a live credit check and asks Inventory to
 * hold stock — neither of which should happen because somebody pressed Save.
 */
export default function OrderEditor() {
  const navigate = useNavigate()
  const { scope } = useSales()
  const [params] = useSearchParams()

  const [customer, setCustomer] = useState<{ id: number; name: string } | null>(null)
  const [orderDate, setOrderDate] = useState(() => new Date().toISOString().slice(0, 10))
  const [requestedDate, setRequestedDate] = useState('')
  const [committedDate, setCommittedDate] = useState('')
  const [customerPo, setCustomerPo] = useState(params.get('customer_po_ref') ?? '')
  const [paymentTerms, setPaymentTerms] = useState('')
  const [deliveryTerms, setDeliveryTerms] = useState('')
  const [notes, setNotes] = useState('')
  const [lines, setLines] = useState<DraftLine[]>([emptyLine()])
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [showErrors, setShowErrors] = useState(false)

  const dirty = customer !== null || lines.some((line) => line.item_id !== null || line.description !== '')
  useUnsavedChanges(dirty && !saving)

  const invalidLines = lines.filter((line) => lineError(line) !== null)
  const canSave = Boolean(customer) && invalidLines.length === 0

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

    try {
      const response = await api.post<SalesOrder>('v1/orders', {
        customer_account_id: customer.id,
        customer_name: customer.name,
        order_date: orderDate,
        requested_date: requestedDate || undefined,
        committed_date: committedDate || undefined,
        customer_po_ref: customerPo || undefined,
        payment_terms: paymentTerms || undefined,
        delivery_terms: deliveryTerms || undefined,
        notes: notes || undefined,
        lines: lines.map((line) => ({
          item_id: line.item_id,
          unit_id: line.unit_id,
          is_service: line.is_service,
          description: line.description || undefined,
          ordered_qty: Number(line.quantity || 0),
          rate: Number(line.rate || 0),
          discount_pc: Number(line.discount_pc || 0),
          estimated_tax_pc: Number(line.estimated_tax_pc || 0),
        })),
      })
      navigate(`/orders/${response.data.order_id}`)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setSaving(false)
    }
  }

  if (!scope) return null

  return (
    <div className="sales-stack" style={{ maxWidth: '64rem' }}>
      <header className="sales-page-header">
        <div>
          <h1>New sales order</h1>
          <p>Raised directly, with no quotation behind it.</p>
        </div>
      </header>

      <Notice tone="info" title="This saves a draft">
        Confirming is a separate step on the order itself: it runs a live credit check against Smart Books and asks
        Inventory to hold the stock. Neither should happen because somebody pressed Save.
      </Notice>

      {error && (
        <Notice tone="danger" title="Could not save" onDismiss={() => setError(null)}>
          {error}
        </Notice>
      )}

      <Panel title="Customer and dates">
        <div className="sales-form-grid">
          <CustomerPicker
            selectedLabel={customer?.name}
            onPick={(picked: CatalogCustomer) => setCustomer({ id: picked.acc_id, name: picked.acc_name })}
          />
          <Field label="Order date" required>
            <Input type="date" value={orderDate} onChange={(event) => setOrderDate(event.target.value)} />
          </Field>
          <Field label="Requested date" hint="What the customer asked for">
            <Input type="date" value={requestedDate} onChange={(event) => setRequestedDate(event.target.value)} />
          </Field>
          <Field label="Promise date" hint="What we committed to. On-time delivery is measured against this.">
            <Input type="date" value={committedDate} onChange={(event) => setCommittedDate(event.target.value)} />
          </Field>
          <Field label="Customer PO reference">
            <Input value={customerPo} onChange={(event) => setCustomerPo(event.target.value)} />
          </Field>
          <Field label="Payment terms">
            <Input value={paymentTerms} onChange={(event) => setPaymentTerms(event.target.value)} placeholder="30 days" />
          </Field>
          <Field label="Delivery terms">
            <Input value={deliveryTerms} onChange={(event) => setDeliveryTerms(event.target.value)} placeholder="Ex works" />
          </Field>
        </div>
      </Panel>

      <Panel
        title="Lines"
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
          currency="INR"
          quantityLabel="Ordered qty"
          showErrors={showErrors}
        />
      </Panel>

      <Panel title="Notes">
        <Textarea
          value={notes}
          onChange={(event) => setNotes(event.target.value)}
          placeholder="Anything the warehouse or the customer should know."
        />
      </Panel>

      <div className="sales-actions" style={{ justifyContent: 'flex-end' }}>
        <Button onClick={() => navigate(-1)} disabled={saving}>
          Cancel
        </Button>
        <Button tone="primary" disabled={saving || (showErrors && !canSave)} onClick={save}>
          {saving ? 'Saving…' : 'Save draft order'}
        </Button>
      </div>
    </div>
  )
}
