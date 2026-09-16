import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, ApiError } from '../services/api'
import type { SalesSettings } from '../services/types'
import { useApi } from '../hooks/useApi'
import { useSales } from '../context/SalesContext'
import { Button, Card, Field, Input, Notice, Select } from '../ui'

export default function Settings() {
  const { scope, can, session } = useSales()
  const [form, setForm] = useState<Partial<SalesSettings>>({})
  const [saved, setSaved] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const { data, loading, reload } = useApi(
    (signal) => api.one<SalesSettings>('v1/settings', undefined, signal),
    [scope?.cmp_id],
    Boolean(scope),
  )

  useEffect(() => {
    if (data?.data) setForm(data.data)
  }, [data])

  const editable = can('settings.manage')

  async function save() {
    setBusy(true)
    setError(null)
    setSaved(false)
    try {
      await api.put('v1/settings', form)
      setSaved(true)
      reload()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  if (loading) return <p style={{ color: 'var(--muted)' }}>Loading…</p>

  return (
    <div className="sales-stack" style={{ maxWidth: '48rem' }}>
      <header className="sales-page-header">
        <div>
          <h1>Settings</h1>
          <p>How this company numbers its documents and where its limits sit.</p>
        </div>
      </header>

      {can('access.manage') && (
        <Notice tone="info" title="Who can do what">
          Sales permissions live in this app, not in Manage.{' '}
          <Link to="/settings/access">Open Access</Link> to create permission profiles and give them
          to the people Manage has let into this company.
        </Notice>
      )}

      {error && <Notice tone="danger" title="Could not save">{error}</Notice>}
      {saved && <Notice tone="success">Saved.</Notice>}
      {!editable && <Notice tone="info">You can see these settings but not change them.</Notice>}

      <Card title="Documents">
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(11rem, 1fr))', gap: '0.85rem' }}>
          <Field label="Quotation prefix">
            <Input disabled={!editable} value={form.quotation_prefix ?? ''} onChange={(e) => setForm({ ...form, quotation_prefix: e.target.value })} />
          </Field>
          <Field label="Order prefix">
            <Input disabled={!editable} value={form.order_prefix ?? ''} onChange={(e) => setForm({ ...form, order_prefix: e.target.value })} />
          </Field>
          <Field label="RMA prefix">
            <Input disabled={!editable} value={form.rma_prefix ?? ''} onChange={(e) => setForm({ ...form, rma_prefix: e.target.value })} />
          </Field>
          <Field label="Quotation validity (days)">
            <Input
              disabled={!editable}
              inputMode="numeric"
              value={String(form.quotation_validity_days ?? '')}
              onChange={(e) => setForm({ ...form, quotation_validity_days: Number(e.target.value) })}
            />
          </Field>
        </div>
        <p style={{ color: 'var(--muted)', fontSize: '0.8rem', marginBottom: 0 }}>
          Invoice numbers are not set here: Smart Books assigns those from its own voucher series, which is a statutory
          record and can only have one owner.
        </p>
      </Card>

      <Card title="Credit control">
        <Field label="When a customer is over limit or overdue" hint="Every figure behind the decision is read live from Smart Books.">
          <Select
            disabled={!editable}
            value={form.credit_control_mode ?? 'warn'}
            onChange={(e) => setForm({ ...form, credit_control_mode: e.target.value as SalesSettings['credit_control_mode'] })}
          >
            <option value="allow">Do not check</option>
            <option value="warn">Warn, but let the order through</option>
            <option value="approval_required">Require an approval</option>
            <option value="block">Block the order</option>
          </Select>
        </Field>
        <div style={{ marginTop: '0.85rem' }}>
          <Field label="Require approval on a quotation discount above (%)">
            <Input
              disabled={!editable}
              inputMode="decimal"
              value={String(form.require_quotation_approval_above_pc ?? '')}
              onChange={(e) => setForm({ ...form, require_quotation_approval_above_pc: e.target.value })}
            />
          </Field>
        </div>
      </Card>

      <Card title="Fulfilment">
        <div style={{ display: 'grid', gap: '0.85rem' }}>
          <label style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
            <input
              type="checkbox"
              disabled={!editable}
              checked={Boolean(form.reserve_on_confirm)}
              onChange={(e) => setForm({ ...form, reserve_on_confirm: e.target.checked })}
            />
            Reserve stock in Inventory when an order is confirmed
          </label>
          <Field label="Invoice by default on">
            <Select
              disabled={!editable}
              value={form.default_invoice_basis ?? 'delivered'}
              onChange={(e) => setForm({ ...form, default_invoice_basis: e.target.value as SalesSettings['default_invoice_basis'] })}
            >
              <option value="delivered">What has been delivered</option>
              <option value="ordered">What was ordered</option>
            </Select>
          </Field>
        </div>
      </Card>

      {session && (
        <Card title="Your permissions in Sales">
          {session.is_owner ? (
            <p style={{ margin: 0 }}>You own this company, so you hold every Sales permission.</p>
          ) : (
            <ul style={{ margin: 0, paddingLeft: '1.1rem', columns: 2 }}>
              {session.permissions.map((permission) => (
                <li key={permission} style={{ fontSize: '0.85rem' }}>{permission}</li>
              ))}
            </ul>
          )}
        </Card>
      )}

      {editable && (
        <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
          <Button tone="primary" disabled={busy} onClick={save}>{busy ? 'Saving…' : 'Save settings'}</Button>
        </div>
      )}
    </div>
  )
}
