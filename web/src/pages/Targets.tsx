import { useState } from 'react'
import { Plus, Trash2 } from 'lucide-react'
import { api, ApiError } from '../services/api'
import { useApi } from '../hooks/useApi'
import { useSales } from '../context/SalesContext'
import {
  Button,
  DataTable,
  Field,
  Input,
  money,
  Notice,
  Pagination,
  Panel,
  Select,
  StatusBadge,
} from '../ui'
import { Progress } from '../ui/charts'

interface Target {
  target_id: number
  target_scope: string
  salesperson_id: number | null
  salesperson_code: string | null
  territory_id: number | null
  territory_name: string | null
  channel_id: number | null
  channel_name: string | null
  period_start: string
  period_end: string
  metric: string
  target_value: string
  notes: string | null
}

interface Attainment {
  period: { from: string; to: string }
  company: { configured: boolean; value: number | null }
  team: {
    rows: Array<{
      salesperson_id: number
      display_code: string | null
      order_value: number
      target_value: number
      target_set: boolean
      attainment_pc: number | null
    }>
    unattributed: { order_value: number; order_count: number }
  }
  basis: string
}

interface Salesperson {
  salesperson_id: number
  display_code: string | null
}

/**
 * Targets and quotas.
 *
 * The table has been in the schema since the start with nothing able to read or
 * write it, so every attainment figure in the product had nothing to measure
 * against. This screen is that missing half.
 *
 * WHAT IS STORED IS THE TARGET. Achievement is composed at read time from
 * orders (and, where Books can supply it, from invoiced revenue) — there is no
 * `achieved` column, because a stored one drifts from the accounts the first
 * time an invoice is cancelled and nothing here would know.
 */
export default function Targets() {
  const { scope, can } = useSales()
  const [offset, setOffset] = useState(0)
  const [error, setError] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)
  const limit = 20

  const month = new Date().toISOString().slice(0, 7)
  const monthStart = `${month}-01`
  const monthEnd = new Date(Date.UTC(Number(month.slice(0, 4)), Number(month.slice(5, 7)), 0)).toISOString().slice(0, 10)

  const list = useApi(
    (signal) => api.list<Target>('v1/targets', { limit, offset }, signal),
    [scope?.cmp_id, scope?.fy_id, offset],
    Boolean(scope),
  )

  const attainment = useApi(
    (signal) => api.one<Attainment>('v1/targets/attainment', { from: monthStart, to: monthEnd }, signal),
    [scope?.cmp_id, scope?.fy_id, monthStart],
    Boolean(scope),
  )

  const people = useApi(
    (signal) => api.list<Salesperson>('v1/salespeople', { limit: 100 }, signal),
    [scope?.cmp_id],
    Boolean(scope),
  )

  async function remove(target: Target) {
    setError(null)
    try {
      await api.del(`v1/targets/${target.target_id}`)
      list.reload()
      attainment.reload()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    }
  }

  return (
    <div className="sales-stack">
      <header className="sales-page-header">
        <div>
          <h1>Targets</h1>
          <p>What each period is being measured against. Achievement is worked out from the records, never stored.</p>
        </div>
        {can('target.manage') && (
          <Button tone="primary" onClick={() => setCreating(true)}>
            <Plus size={16} aria-hidden /> New target
          </Button>
        )}
      </header>

      {error && (
        <Notice tone="danger" title="That did not work" onDismiss={() => setError(null)}>
          {error}
        </Notice>
      )}

      <Panel
        title="This month's attainment"
        description={attainment.data?.data.basis}
      >
        {attainment.loading ? (
          <p className="sales-muted">Loading…</p>
        ) : !attainment.data?.data.company.configured ? (
          <Notice tone="info" title="Target not configured">
            No company target covers {monthStart} to {monthEnd}. Attainment cannot be shown until one exists.
          </Notice>
        ) : (
          <div className="sales-stack-tight">
            {attainment.data.data.team.rows.map((row) => (
              <div key={row.salesperson_id} className="sales-row-between" style={{ gap: 14 }}>
                <span style={{ minWidth: 140 }}>{row.display_code ?? `#${row.salesperson_id}`}</span>
                <div style={{ flex: 1, minWidth: 120 }}>
                  <Progress
                    value={row.order_value}
                    max={row.target_value || 1}
                    label={`${row.display_code ?? row.salesperson_id} attainment`}
                    tone={(row.attainment_pc ?? 0) < 60 ? 'warning' : 'brand'}
                  />
                </div>
                <span className="sales-numeric" style={{ minWidth: 150, textAlign: 'right' }}>
                  {money(row.order_value)} {row.target_set ? `of ${money(row.target_value)}` : '(no target)'}
                </span>
              </div>
            ))}
            {attainment.data.data.team.unattributed.order_count > 0 && (
              <p className="sales-note" style={{ marginTop: 8 }}>
                {attainment.data.data.team.unattributed.order_count} orders worth{' '}
                {money(attainment.data.data.team.unattributed.order_value)} have no salesperson recorded and are not
                counted towards anyone.
              </p>
            )}
          </div>
        )}
      </Panel>

      <Panel
        title="All targets"
        flush
        footer={
          <Pagination
            total={list.data?.meta.total ?? 0}
            limit={limit}
            offset={offset}
            label="targets"
            onChange={setOffset}
          />
        }
      >
        <DataTable<Target>
          loading={list.loading}
          caption="Targets"
          rows={list.data?.data ?? []}
          rowKey={(row) => row.target_id}
          empty="No targets are configured yet."
          columns={[
            {
              key: 'scope',
              header: 'For',
              render: (row) => (
                <>
                  <span className="sales-cell-primary">
                    {row.target_scope === 'company'
                      ? 'Whole company'
                      : (row.salesperson_code ?? row.territory_name ?? row.channel_name ?? `#${row.target_id}`)}
                  </span>
                  <span className="sales-cell-sub">{row.target_scope}</span>
                </>
              ),
            },
            { key: 'period', header: 'Period', render: (row) => `${row.period_start} → ${row.period_end}` },
            { key: 'metric', header: 'Metric', render: (row) => <StatusBadge status={row.metric.toUpperCase()} /> },
            { key: 'value', header: 'Target', numeric: true, render: (row) => money(row.target_value) },
            {
              key: 'actions',
              header: '',
              render: (row) =>
                can('target.manage') ? (
                  <Button small tone="danger" onClick={() => remove(row)} title="Delete this target">
                    <Trash2 size={13} aria-hidden /> Delete
                  </Button>
                ) : null,
            },
          ]}
        />
      </Panel>

      {creating && (
        <TargetForm
          people={people.data?.data ?? []}
          defaultStart={monthStart}
          defaultEnd={monthEnd}
          onClose={() => setCreating(false)}
          onSaved={() => {
            setCreating(false)
            list.reload()
            attainment.reload()
          }}
        />
      )}
    </div>
  )
}

function TargetForm({
  people,
  defaultStart,
  defaultEnd,
  onClose,
  onSaved,
}: {
  people: Salesperson[]
  defaultStart: string
  defaultEnd: string
  onClose: () => void
  onSaved: () => void
}) {
  const [targetScope, setTargetScope] = useState('company')
  const [salespersonId, setSalespersonId] = useState('')
  const [start, setStart] = useState(defaultStart)
  const [end, setEnd] = useState(defaultEnd)
  const [value, setValue] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function save() {
    setBusy(true)
    setError(null)
    try {
      await api.post('v1/targets', {
        target_scope: targetScope,
        salesperson_id: targetScope === 'salesperson' ? Number(salespersonId) : undefined,
        period_start: start,
        period_end: end,
        metric: 'value',
        target_value: Number(value),
      })
      onSaved()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Panel
      title="New target"
      description="A value target for a period. Zero is refused — it would make every month a triumph."
      action={
        <Button tone="ghost" small onClick={onClose}>
          Cancel
        </Button>
      }
    >
      <div className="sales-stack">
        {error && (
          <Notice tone="danger" title="Could not save">
            {error}
          </Notice>
        )}
        <div className="sales-form-grid">
          <Field label="Target for" required>
            <Select value={targetScope} onChange={(event) => setTargetScope(event.target.value)}>
              <option value="company">Whole company</option>
              <option value="salesperson">A salesperson</option>
            </Select>
          </Field>
          {targetScope === 'salesperson' && (
            <Field label="Salesperson" required>
              <Select value={salespersonId} onChange={(event) => setSalespersonId(event.target.value)}>
                <option value="">Choose…</option>
                {people.map((person) => (
                  <option key={person.salesperson_id} value={person.salesperson_id}>
                    {person.display_code ?? `#${person.salesperson_id}`}
                  </option>
                ))}
              </Select>
            </Field>
          )}
          <Field label="Period start" required>
            <Input type="date" value={start} onChange={(event) => setStart(event.target.value)} />
          </Field>
          <Field label="Period end" required>
            <Input type="date" value={end} onChange={(event) => setEnd(event.target.value)} />
          </Field>
          <Field label="Target value" required hint="In the company's reporting currency">
            <Input type="number" min="1" step="1" value={value} onChange={(event) => setValue(event.target.value)} />
          </Field>
        </div>
        <div className="sales-actions">
          <Button
            tone="primary"
            disabled={busy || !value || (targetScope === 'salesperson' && !salespersonId)}
            onClick={save}
          >
            Save target
          </Button>
          <Button onClick={onClose} disabled={busy}>
            Cancel
          </Button>
        </div>
      </div>
    </Panel>
  )
}
