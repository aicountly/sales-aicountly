import { useState } from 'react'
import { api, ApiError } from '../services/api'
import type { Channel, Salesperson, Territory } from '../services/types'
import { useApi } from '../hooks/useApi'
import { useSales } from '../context/SalesContext'
import { Button, Card, DataTable, Field, Input, Notice, qty, Select } from '../ui'

export default function Territories() {
  const { scope, can } = useSales()
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [territory, setTerritory] = useState({ code: '', name: '' })
  const [channel, setChannel] = useState({ code: '', name: '', kind: 'direct' })
  const [person, setPerson] = useState({ uuid: '', code: '', max_discount_pc: '0' })

  const territories = useApi((s) => api.get<{ data: Territory[] }>('v1/territories', undefined, s), [scope?.cmp_id], Boolean(scope))
  const channels = useApi((s) => api.get<{ data: Channel[] }>('v1/channels', undefined, s), [scope?.cmp_id], Boolean(scope))
  const people = useApi((s) => api.get<{ data: Salesperson[] }>('v1/salespeople', undefined, s), [scope?.cmp_id], Boolean(scope))

  async function submit(path: string, body: Record<string, unknown>, reload: () => void, reset: () => void) {
    setBusy(true)
    setError(null)
    try {
      await api.post(path, body)
      reset()
      reload()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  const editable = can('territory.manage')

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <h1 style={{ margin: 0, fontSize: '1.3rem' }}>Territories, channels and sales people</h1>

      <Notice tone="info">
        A salesperson here is a portal user with a Sales role. Their name and login stay with the portal — what this
        product owns is the territory they cover and the discount they may approve.
      </Notice>

      {error && <Notice tone="danger" title="Could not save">{error}</Notice>}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(22rem, 1fr))', gap: '1rem' }}>
        <Card title="Territories">
          <DataTable
            loading={territories.loading}
            rows={territories.data?.data ?? []}
            rowKey={(row) => row.territory_id}
            empty="None yet."
            columns={[
              { key: 'code', header: 'Code', render: (row) => row.territory_code },
              { key: 'name', header: 'Name', render: (row) => row.territory_name },
            ]}
          />
          {editable && (
            <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'flex-end', marginTop: '0.85rem' }}>
              <Field label="Code"><Input value={territory.code} onChange={(e) => setTerritory({ ...territory, code: e.target.value })} /></Field>
              <Field label="Name"><Input value={territory.name} onChange={(e) => setTerritory({ ...territory, name: e.target.value })} /></Field>
              <Button
                tone="primary"
                disabled={busy || !territory.code.trim() || !territory.name.trim()}
                onClick={() =>
                  submit('v1/territories', { territory_code: territory.code.trim(), territory_name: territory.name.trim() }, territories.reload, () =>
                    setTerritory({ code: '', name: '' }),
                  )
                }
              >
                Add
              </Button>
            </div>
          )}
        </Card>

        <Card title="Channels">
          <DataTable
            loading={channels.loading}
            rows={channels.data?.data ?? []}
            rowKey={(row) => row.channel_id}
            empty="None yet."
            columns={[
              { key: 'code', header: 'Code', render: (row) => row.channel_code },
              { key: 'name', header: 'Name', render: (row) => row.channel_name },
              { key: 'kind', header: 'Kind', render: (row) => row.channel_kind },
            ]}
          />
          {editable && (
            <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'flex-end', marginTop: '0.85rem' }}>
              <Field label="Code"><Input value={channel.code} onChange={(e) => setChannel({ ...channel, code: e.target.value })} /></Field>
              <Field label="Name"><Input value={channel.name} onChange={(e) => setChannel({ ...channel, name: e.target.value })} /></Field>
              <Field label="Kind">
                <Select value={channel.kind} onChange={(e) => setChannel({ ...channel, kind: e.target.value })}>
                  {['direct', 'dealer', 'distributor', 'retail', 'online', 'export'].map((k) => (
                    <option key={k} value={k}>{k}</option>
                  ))}
                </Select>
              </Field>
              <Button
                tone="primary"
                disabled={busy || !channel.code.trim() || !channel.name.trim()}
                onClick={() =>
                  submit('v1/channels', { channel_code: channel.code.trim(), channel_name: channel.name.trim(), channel_kind: channel.kind }, channels.reload, () =>
                    setChannel({ code: '', name: '', kind: 'direct' }),
                  )
                }
              >
                Add
              </Button>
            </div>
          )}
        </Card>
      </div>

      <Card title="Sales people">
        <DataTable
          loading={people.loading}
          rows={people.data?.data ?? []}
          rowKey={(row) => row.salesperson_id}
          empty="None yet."
          columns={[
            { key: 'code', header: 'Code', render: (row) => row.display_code ?? '—' },
            { key: 'uuid', header: 'Portal user', render: (row) => <code style={{ fontSize: '0.8rem' }}>{row.user_uuid}</code> },
            { key: 'territory', header: 'Territory', render: (row) => row.territory_name ?? '—' },
            { key: 'channel', header: 'Channel', render: (row) => row.channel_name ?? '—' },
            { key: 'discount', header: 'Max discount', numeric: true, render: (row) => `${qty(row.max_discount_pc)}%` },
          ]}
        />
        {editable && (
          <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'flex-end', marginTop: '0.85rem', flexWrap: 'wrap' }}>
            <Field label="Portal user uuid"><Input value={person.uuid} onChange={(e) => setPerson({ ...person, uuid: e.target.value })} /></Field>
            <Field label="Code"><Input value={person.code} onChange={(e) => setPerson({ ...person, code: e.target.value })} /></Field>
            <Field label="Max discount %" hint="Above this, a quotation needs approval">
              <Input value={person.max_discount_pc} inputMode="decimal" onChange={(e) => setPerson({ ...person, max_discount_pc: e.target.value })} />
            </Field>
            <Button
              tone="primary"
              disabled={busy || !person.uuid.trim()}
              onClick={() =>
                submit(
                  'v1/salespeople',
                  { user_uuid: person.uuid.trim(), display_code: person.code.trim() || undefined, max_discount_pc: Number(person.max_discount_pc) || 0 },
                  people.reload,
                  () => setPerson({ uuid: '', code: '', max_discount_pc: '0' }),
                )
              }
            >
              Add
            </Button>
          </div>
        )}
      </Card>
    </div>
  )
}
