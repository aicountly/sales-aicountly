import { useState } from 'react'
import { api, ApiError } from '../services/api'
import type { PriceBook } from '../services/types'
import { useApi } from '../hooks/useApi'
import { useSales } from '../context/SalesContext'
import { Button, Card, DataTable, date, Field, Input, money, Notice, qty, Select, StatusBadge } from '../ui'

export default function PriceBooks() {
  const { scope, can } = useSales()
  const [creating, setCreating] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [form, setForm] = useState({ book_code: '', book_name: '', scope_kind: 'standard', priority: '100' })
  const [openBook, setOpenBook] = useState<number | null>(null)

  const { data, loading, reload } = useApi(
    (signal) => api.get<{ data: PriceBook[] }>('v1/price-books', undefined, signal),
    [scope?.cmp_id],
    Boolean(scope),
  )

  const detail = useApi(
    (signal) => api.one<PriceBook>(`v1/price-books/${openBook}`, undefined, signal),
    [openBook, scope?.cmp_id],
    Boolean(scope && openBook),
  )

  async function create() {
    setBusy(true)
    setError(null)
    try {
      await api.post('v1/price-books', {
        book_code: form.book_code.trim(),
        book_name: form.book_name.trim(),
        scope_kind: form.scope_kind,
        priority: Number(form.priority) || 100,
      })
      setCreating(false)
      setForm({ book_code: '', book_name: '', scope_kind: 'standard', priority: '100' })
      reload()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <header style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <h1 style={{ margin: 0, fontSize: '1.3rem' }}>Price books</h1>
        {can('pricebook.manage') && <Button tone="primary" onClick={() => setCreating(true)}>New price book</Button>}
      </header>

      <Notice tone="info">
        A price book is a commercial policy and belongs to Sales. Inventory's valuation is a different number with a
        different owner — it is read from Inventory when a margin rule needs it, never copied here.
      </Notice>

      {error && <Notice tone="danger" title="Could not save">{error}</Notice>}

      {creating && (
        <Card title="New price book">
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(11rem, 1fr))', gap: '0.75rem' }}>
            <Field label="Code">
              <Input value={form.book_code} onChange={(e) => setForm({ ...form, book_code: e.target.value })} placeholder="STD-2026" />
            </Field>
            <Field label="Name">
              <Input value={form.book_name} onChange={(e) => setForm({ ...form, book_name: e.target.value })} placeholder="Standard list 2026" />
            </Field>
            <Field label="Applies to">
              <Select value={form.scope_kind} onChange={(e) => setForm({ ...form, scope_kind: e.target.value })}>
                <option value="standard">Everyone (standard)</option>
                <option value="customer">One customer</option>
                <option value="channel">A channel</option>
                <option value="territory">A territory</option>
                <option value="contract">A contract</option>
              </Select>
            </Field>
            <Field label="Priority" hint="Lower wins">
              <Input value={form.priority} inputMode="numeric" onChange={(e) => setForm({ ...form, priority: e.target.value })} />
            </Field>
          </div>
          <div style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end', marginTop: '0.85rem' }}>
            <Button onClick={() => setCreating(false)}>Cancel</Button>
            <Button tone="primary" disabled={busy || !form.book_code.trim() || !form.book_name.trim()} onClick={create}>
              Create
            </Button>
          </div>
        </Card>
      )}

      <Card title={`${data?.data.length ?? 0} price book${(data?.data.length ?? 0) === 1 ? '' : 's'}`}>
        <DataTable
          loading={loading}
          rows={data?.data ?? []}
          rowKey={(row) => row.price_book_id}
          onRowClick={(row) => setOpenBook(row.price_book_id === openBook ? null : row.price_book_id)}
          empty="No price books yet. Without one, rates are typed in by hand on each quotation."
          columns={[
            { key: 'code', header: 'Code', render: (row) => row.book_code },
            { key: 'name', header: 'Name', render: (row) => row.book_name },
            { key: 'scope', header: 'Applies to', render: (row) => row.scope_kind },
            { key: 'valid', header: 'Valid', render: (row) => (row.valid_from ? `${date(row.valid_from)} – ${date(row.valid_to)}` : 'Always') },
            { key: 'rules', header: 'Rules', numeric: true, render: (row) => row.rule_count ?? '0' },
            { key: 'priority', header: 'Priority', numeric: true, render: (row) => row.priority },
            { key: 'active', header: 'Status', render: (row) => <StatusBadge status={row.is_active ? 'APPROVED' : 'CANCELLED'} /> },
          ]}
        />
      </Card>

      {openBook && detail.data && (
        <Card title={`Rules in ${detail.data.data.book_name}`}>
          <DataTable
            loading={detail.loading}
            rows={detail.data.data.rules ?? []}
            rowKey={(rule) => rule.rule_id}
            empty="No rules yet."
            columns={[
              { key: 'target', header: 'Applies to', render: (rule) => (rule.item_id ? `Inventory item ${rule.item_id}` : `Item group ${rule.item_grp_id}`) },
              { key: 'from', header: 'From qty', numeric: true, render: (rule) => qty(rule.min_qty) },
              { key: 'to', header: 'To qty', numeric: true, render: (rule) => (rule.max_qty ? qty(rule.max_qty) : '—') },
              { key: 'kind', header: 'Kind', render: (rule) => rule.rate_kind.replace(/_/g, ' ') },
              { key: 'rate', header: 'Rate', numeric: true, render: (rule) => (rule.rate_kind === 'fixed' ? money(rule.rate) : `${qty(rule.rate)}%`) },
              { key: 'margin', header: 'Min margin', numeric: true, render: (rule) => (rule.min_margin_pc ? `${qty(rule.min_margin_pc)}%` : '—') },
            ]}
          />
        </Card>
      )}
    </div>
  )
}
