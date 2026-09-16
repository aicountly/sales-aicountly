import { useState } from 'react'
import { Building2 } from 'lucide-react'
import { Button, Field, Input } from '../ui'
import { useSales } from '../context/SalesContext'

/**
 * Which company, branch and financial year the app is working in.
 *
 * The three ids are typed in rather than chosen from a list because the list
 * belongs to Manage: a company switcher that reads from a local table is the
 * first step towards a company master this product has no business holding.
 * Manage's own switcher is the long-term home for this, and the launcher in the
 * header is the way there.
 */
export function CompanyPicker() {
  const { scope, setCompanyScope } = useSales()
  const [open, setOpen] = useState(!scope)
  const [cmp, setCmp] = useState(String(scope?.cmp_id ?? ''))
  const [fy, setFy] = useState(String(scope?.fy_id ?? ''))
  const [bo, setBo] = useState(String(scope?.bo_id ?? 0))

  function apply() {
    const cmpId = Number.parseInt(cmp, 10)
    const fyId = Number.parseInt(fy, 10)
    if (!cmpId || !fyId) return
    setCompanyScope({ cmp_id: cmpId, fy_id: fyId, bo_id: Number.parseInt(bo, 10) || 0 })
    setOpen(false)
  }

  if (!open) {
    return (
      <Button tone="ghost" onClick={() => setOpen(true)}>
        <Building2 size={15} aria-hidden /> Change company
      </Button>
    )
  }

  return (
    <div style={{ display: 'flex', alignItems: 'flex-end', gap: '0.5rem', flexWrap: 'wrap' }}>
      <div style={{ width: '7rem' }}>
        <Field label="Company id">
          <Input value={cmp} onChange={(e) => setCmp(e.target.value)} inputMode="numeric" autoFocus />
        </Field>
      </div>
      <div style={{ width: '7rem' }}>
        <Field label="Financial year id">
          <Input value={fy} onChange={(e) => setFy(e.target.value)} inputMode="numeric" />
        </Field>
      </div>
      <div style={{ width: '7rem' }}>
        <Field label="Branch id" hint="0 = all">
          <Input value={bo} onChange={(e) => setBo(e.target.value)} inputMode="numeric" />
        </Field>
      </div>
      <Button tone="primary" onClick={apply}>
        Open
      </Button>
      {scope && (
        <Button tone="ghost" onClick={() => setOpen(false)}>
          Cancel
        </Button>
      )}
    </div>
  )
}
