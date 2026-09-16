/**
 * Line-item editing, shared by the quotation and the sales-order editors.
 *
 * The two documents agree on what a line is — an item or a service, a quantity,
 * an agreed rate, a discount and an estimated tax — so they share the editor.
 * What differs is what the document is called and what happens on save, and
 * that stays with each screen.
 *
 * NO TOTAL IS INVENTED HERE. The arithmetic below is a preview so the user can
 * see what they are typing; the figures that get stored are recomputed on the
 * server from the same rules that route approvals. Two places that both claim
 * to compute a document total is one place too many, and the browser is the one
 * that does not get to be right.
 */

import { Trash2 } from 'lucide-react'
import { api } from '../services/api'
import type { CatalogItem } from '../services/types'
import { Button, Field, Input, money } from '../ui'
import { ItemPicker } from './LivePicker'

export interface DraftLine {
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
  is_optional?: boolean
}

export function emptyLine(): DraftLine {
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
    is_optional: false,
  }
}

export function lineNet(line: DraftLine): number {
  const gross = Number(line.quantity || 0) * Number(line.rate || 0)

  return gross - (gross * Number(line.discount_pc || 0)) / 100
}

export function documentTotals(lines: DraftLine[]): { subtotal: number; discount: number; tax: number; total: number } {
  // An optional line is an offer the customer does not have to take, so it is
  // not part of the price. Including it would make the quotation read as more
  // expensive than what is actually being proposed.
  const counted = lines.filter((line) => !line.is_optional)

  const subtotal = counted.reduce((sum, line) => sum + Number(line.quantity || 0) * Number(line.rate || 0), 0)
  const discount = counted.reduce(
    (sum, line) => sum + (Number(line.quantity || 0) * Number(line.rate || 0) * Number(line.discount_pc || 0)) / 100,
    0,
  )
  const tax = counted.reduce((sum, line) => sum + (lineNet(line) * Number(line.estimated_tax_pc || 0)) / 100, 0)

  return { subtotal, discount, tax, total: subtotal - discount + tax }
}

/** A line is only saveable once it names something: an item, or a service description. */
export function lineError(line: DraftLine): string | null {
  if (!line.is_service && line.item_id === null) return 'Choose an item, or mark this as a service line.'
  if (line.is_service && line.description.trim() === '') return 'A service line needs a description.'
  if (!(Number(line.quantity) > 0)) return 'Quantity must be more than zero.'
  if (Number(line.rate) < 0) return 'A rate cannot be negative.'
  if (Number(line.discount_pc) < 0 || Number(line.discount_pc) > 100) return 'A discount is between 0 and 100 per cent.'

  return null
}

export function LineEditor({
  lines,
  onChange,
  customerAccountId,
  currency,
  quantityLabel = 'Quantity',
  allowOptional = false,
  showErrors = false,
}: {
  lines: DraftLine[]
  onChange: (lines: DraftLine[]) => void
  customerAccountId?: number | null
  currency: string
  quantityLabel?: string
  allowOptional?: boolean
  showErrors?: boolean
}) {
  const patch = (key: string, changes: Partial<DraftLine>) => {
    onChange(lines.map((line) => (line.key === key ? { ...line, ...changes } : line)))
  }

  /**
   * Ask the backend what this item should cost.
   *
   * The price comes from our own price books; the COST it may be checked
   * against comes from Inventory, live, inside that same call. Nothing about
   * the item is stored here beyond its id.
   */
  const applyItem = async (key: string, item: CatalogItem) => {
    patch(key, {
      item_id: item.item_id,
      item_label: item.item_name,
      unit_id: item.unit_id,
      description: item.item_name,
    })

    try {
      const response = await api.post<{ rate: number }>('v1/catalog/price', {
        item_id: item.item_id,
        quantity: Number(lines.find((line) => line.key === key)?.quantity ?? 1),
        customer_account_id: customerAccountId ?? undefined,
      })
      if (response.data.rate > 0) patch(key, { rate: String(response.data.rate) })
    } catch {
      // No price book rule, or pricing unreachable. The user types the rate;
      // leaving the field at zero is better than inventing a number.
    }
  }

  const totals = documentTotals(lines)

  return (
    <div className="sales-stack">
      {lines.map((line, index) => {
        const error = showErrors ? lineError(line) : null

        return (
          <fieldset
            key={line.key}
            style={{
              border: `1px solid ${error ? 'var(--danger)' : 'var(--line)'}`,
              borderRadius: 11,
              padding: '14px',
              display: 'grid',
              gap: 12,
              margin: 0,
              minWidth: 0,
            }}
          >
            <legend className="sales-visually-hidden">Line {index + 1}</legend>

            <div className="sales-row-between">
              <div className="sales-row" style={{ gap: 14 }}>
                <label className="sales-row" style={{ gap: 6, fontSize: 13 }}>
                  <input
                    type="checkbox"
                    checked={line.is_service}
                    onChange={(event) =>
                      patch(line.key, { is_service: event.target.checked, item_id: null, item_label: '' })
                    }
                  />
                  Service line (no stock item)
                </label>
                {allowOptional && (
                  <label className="sales-row" style={{ gap: 6, fontSize: 13 }}>
                    <input
                      type="checkbox"
                      checked={Boolean(line.is_optional)}
                      onChange={(event) => patch(line.key, { is_optional: event.target.checked })}
                    />
                    Optional extra
                  </label>
                )}
              </div>
              <Button
                tone="ghost"
                small
                onClick={() => onChange(lines.length > 1 ? lines.filter((l) => l.key !== line.key) : lines)}
                disabled={lines.length === 1}
                title="Remove this line"
              >
                <Trash2 size={14} aria-hidden /> Remove
              </Button>
            </div>

            <div
              style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(auto-fit, minmax(8.5rem, 1fr))',
                gap: 12,
                alignItems: 'end',
              }}
            >
              <div style={{ gridColumn: 'span 2', minWidth: 0 }}>
                {line.is_service ? (
                  <Field label="Description" required>
                    <Input
                      value={line.description}
                      onChange={(event) => patch(line.key, { description: event.target.value })}
                      aria-invalid={showErrors && line.description.trim() === ''}
                    />
                  </Field>
                ) : (
                  <ItemPicker selectedLabel={line.item_label || null} onPick={(item) => void applyItem(line.key, item)} />
                )}
              </div>
              <Field label={quantityLabel} required>
                <Input
                  value={line.quantity}
                  inputMode="decimal"
                  onChange={(event) => patch(line.key, { quantity: event.target.value })}
                  aria-invalid={showErrors && !(Number(line.quantity) > 0)}
                />
              </Field>
              <Field label="Rate" required>
                <Input value={line.rate} inputMode="decimal" onChange={(event) => patch(line.key, { rate: event.target.value })} />
              </Field>
              <Field label="Discount %">
                <Input
                  value={line.discount_pc}
                  inputMode="decimal"
                  onChange={(event) => patch(line.key, { discount_pc: event.target.value })}
                />
              </Field>
              <Field label="Tax %" hint="Estimate only">
                <Input
                  value={line.estimated_tax_pc}
                  inputMode="decimal"
                  onChange={(event) => patch(line.key, { estimated_tax_pc: event.target.value })}
                />
              </Field>
              <div style={{ textAlign: 'right', paddingBottom: 6 }}>
                <div className="sales-note">Line</div>
                <div className="sales-numeric" style={{ fontWeight: 650 }}>
                  {money(lineNet(line), currency)}
                </div>
              </div>
            </div>

            {error && <p className="sales-field-error" style={{ margin: 0 }}>{error}</p>}
          </fieldset>
        )
      })}

      <dl
        style={{
          display: 'grid',
          gridTemplateColumns: 'auto auto',
          gap: '6px 24px',
          justifyContent: 'end',
          margin: 0,
        }}
      >
        <dt className="sales-muted">Subtotal</dt>
        <dd className="sales-numeric" style={{ margin: 0 }}>
          {money(totals.subtotal, currency)}
        </dd>
        <dt className="sales-muted">Discount</dt>
        <dd className="sales-numeric" style={{ margin: 0 }}>
          −{money(totals.discount, currency)}
        </dd>
        <dt className="sales-muted" title="Smart Books calculates the tax actually charged when the invoice is raised.">
          Estimated tax
        </dt>
        <dd className="sales-numeric" style={{ margin: 0 }}>
          {money(totals.tax, currency)}
        </dd>
        <dt style={{ fontWeight: 700 }}>Total</dt>
        <dd className="sales-numeric" style={{ margin: 0, fontWeight: 700 }}>
          {money(totals.total, currency)}
        </dd>
      </dl>

      <p className="sales-note" style={{ margin: 0, textAlign: 'right' }}>
        Totals are recalculated on save. Tax shown is an estimate — Smart Books charges and files the real figure.
      </p>
    </div>
  )
}
