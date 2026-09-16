import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AlertTriangle, ArrowRight, Repeat, ShoppingCart, TrendingDown, Users, Wallet } from 'lucide-react'
import { api, ApiError } from '../../services/api'
import type { CollectionRow, CollectionsDashboard } from '../../services/dashboards'
import { useApi } from '../../hooks/useApi'
import { useSales } from '../../context/SalesContext'
import {
  Badge,
  Button,
  DataState,
  DataTable,
  date,
  Drawer,
  money,
  moneyShort,
  Notice,
  Panel,
  Skeleton,
  Textarea,
} from '../../ui'
import { BarChart } from '../../ui/charts'
import { DASHBOARDS, DashboardFrame, InsightCard, MetricsSection, usePeriod } from './frame'

const VIEW = DASHBOARDS[3]

const ICONS = {
  active_buyers: <Users size={17} />,
  repeat_rate: <Repeat size={17} />,
  outstanding: <Wallet size={17} />,
  overdue: <AlertTriangle size={17} />,
}

const BUCKET_TONE: Record<string, 'brand' | 'teal' | 'amber' | 'red'> = {
  not_due: 'brand',
  d1_30: 'teal',
  d31_60: 'amber',
  d61_plus: 'red',
}

/**
 * Dashboard 4 — who is buying, who has stopped, and who owes us money.
 *
 * THE BALANCES ARE BOOKS'. Every figure in the ageing chart and the priorities
 * table is read live from Smart Books on this request, bucketed on Books' own
 * due dates. Nothing is reconstructed by adding up a page of invoices — that
 * produces a number that is quietly wrong on any customer with more invoices
 * than fit on a page, and nobody finds out.
 *
 * THE CONVERSATION IS OURS. Who rang the customer last and what they promised
 * is a Sales record, merged onto Books' balance at read time. Neither half is
 * copied into the other's database.
 */
export default function CollectionsDashboardView() {
  const navigate = useNavigate()
  const { scope, can } = useSales()
  const { period, setMonth } = usePeriod()
  const [drafting, setDrafting] = useState<CollectionRow | null>(null)

  const { data, loading, error, reload } = useApi<{ data: CollectionsDashboard }>(
    (signal) =>
      api.one<CollectionsDashboard>(
        'v1/dashboard/collections',
        { from: period.from, to: period.to, as_of: period.as_of },
        signal,
      ),
    [scope?.cmp_id, scope?.fy_id, scope?.bo_id, period.from, period.to, period.as_of],
    Boolean(scope),
  )

  const dashboard = data?.data
  const currency = dashboard?.currency ?? 'INR'
  const ageing = dashboard?.ageing

  return (
    <DashboardFrame
      view={VIEW}
      period={period}
      onMonthChange={setMonth}
      freshness={dashboard?.freshness}
      onRefresh={reload}
      error={error}
      onRetry={reload}
      primaryAction={
        <Button tone="primary" onClick={() => navigate('/customers')}>
          Review follow-ups <ArrowRight size={15} aria-hidden />
        </Button>
      }
    >
      <MetricsSection metrics={dashboard?.metrics} currency={currency} loading={loading} icons={ICONS} />

      <div className="sales-dashboard-grid">
        <div className="sales-main">
          <Panel
            title="Receivables ageing"
            description={ageing?.basis}
            action={
              ageing?.status === 'ready' ? (
                <span className="sales-note">Balances from Books · as at {ageing.as_of}</span>
              ) : undefined
            }
          >
            {loading ? (
              <Skeleton height={220} />
            ) : ageing?.status !== 'ready' ? (
              <DataState
                status={ageing?.status === 'forbidden' ? 'forbidden' : 'unavailable'}
                message={ageing?.reason ?? 'Smart Books could not be read, so ageing cannot be shown.'}
                retry={reload}
              />
            ) : (
              <BarChart
                data={ageing.buckets.map((bucket) => ({
                  key: bucket.key,
                  label: bucket.label,
                  value: bucket.value,
                  tone: BUCKET_TONE[bucket.key] ?? 'brand',
                }))}
                format={(value) => moneyShort(value, currency)}
                valueLabel="Outstanding"
                summary={`Receivables ageing at ${ageing.as_of}: ${ageing.buckets
                  .map((bucket) => `${bucket.label} ${moneyShort(bucket.value, currency)}`)
                  .join(', ')}.`}
              />
            )}
          </Panel>

          <Panel
            title="Collection priorities"
            description="Worst overdue first, with our own last contact beside it"
            flush
          >
            {dashboard?.priorities.status !== 'ready' && !loading ? (
              <div style={{ padding: 20 }}>
                <Notice tone="warning" title="Balances could not be read">
                  {dashboard?.priorities.reason ?? 'Smart Books did not answer.'}
                </Notice>
              </div>
            ) : (
              <DataTable<CollectionRow>
                loading={loading}
                caption="Collection priorities"
                rows={dashboard?.priorities.rows ?? []}
                rowKey={(row) => row.customer_account_id}
                empty="Nothing is outstanding."
                columns={[
                  {
                    key: 'customer',
                    header: 'Customer',
                    render: (row) => (
                      <>
                        <span className="sales-cell-primary">
                          {row.customer_name || `Account ${row.customer_account_id}`}
                        </span>
                        <span className="sales-cell-sub">
                          {row.bill_count} open bill{row.bill_count === 1 ? '' : 's'}
                        </span>
                      </>
                    ),
                  },
                  { key: 'outstanding', header: 'Outstanding', numeric: true, render: (row) => money(row.outstanding, currency) },
                  {
                    key: 'overdue',
                    header: 'Overdue',
                    numeric: true,
                    render: (row) => (
                      <span className={row.overdue > 0 ? 'sales-tone-negative' : undefined}>{money(row.overdue, currency)}</span>
                    ),
                  },
                  {
                    key: 'oldest',
                    header: 'Oldest due',
                    render: (row) =>
                      row.oldest_due_date ? (
                        <>
                          {date(row.oldest_due_date)}
                          <span className="sales-cell-sub">{row.oldest_days} days</span>
                        </>
                      ) : (
                        <span className="sales-muted">Not yet due</span>
                      ),
                  },
                  {
                    key: 'contact',
                    header: 'Last contact',
                    render: (row) =>
                      row.last_contact_on ? (
                        <>
                          {date(row.last_contact_on)}
                          <span className="sales-cell-sub">{row.last_contact_note ?? row.last_contact_via}</span>
                        </>
                      ) : (
                        <span className="sales-muted">Never</span>
                      ),
                  },
                  {
                    key: 'promise',
                    header: 'Next action',
                    render: (row) =>
                      row.promised_on && row.promise_outcome === 'open' ? (
                        <Badge tone="info">
                          Promised {money(row.promised_amount, currency)} by {date(row.promised_on)}
                        </Badge>
                      ) : (
                        <Button small onClick={() => setDrafting(row)} disabled={!can('quotation.view')}>
                          Draft reminder
                        </Button>
                      ),
                  },
                ]}
              />
            )}
          </Panel>
        </div>

        <aside className="sales-aside" aria-label="Customer opportunities">
          <Panel title="Customer opportunities" description="Signals from our own order history">
            {loading ? (
              <Skeleton height={160} />
            ) : (
              <div className="sales-priority-list">
                <OpportunityRow
                  icon={<ShoppingCart size={16} />}
                  count={dashboard?.opportunities.reorder_due.length ?? 0}
                  label="customers due to reorder"
                  onOpen={() => navigate('/customers?signal=reorder')}
                />
                <OpportunityRow
                  icon={<TrendingDown size={16} />}
                  count={dashboard?.opportunities.declining.length ?? 0}
                  label="customers buying less"
                  tone="warning"
                  onOpen={() => navigate('/customers?signal=declining')}
                />
                <OpportunityRow
                  icon={<AlertTriangle size={16} />}
                  count={(dashboard?.priorities.rows ?? []).filter((row) => row.overdue > 0).length}
                  label="accounts with overdue bills"
                  tone="danger"
                  onOpen={() => navigate('/customers?signal=overdue')}
                />
              </div>
            )}
          </Panel>

          {loading ? (
            <Skeleton height={190} />
          ) : (dashboard?.insights.length ?? 0) > 0 ? (
            dashboard!.insights.map((insight) => (
              <InsightCard
                key={insight.id}
                insight={insight}
                currency={currency}
                onAction={() => {
                  const customer = insight.evidence.find((item) => item.kind === 'customer')
                  if (insight.action.kind === 'draft_quotation' && customer) {
                    navigate(`/quotations/new?customer_account_id=${customer.id}`)
                  } else if (customer) {
                    navigate(`/customers/${customer.id}`)
                  }
                }}
              />
            ))
          ) : (
            <DataState
              status="empty"
              message="No reorder suggestion: no customer has the three orders and a steady rhythm one would have to be built on."
            />
          )}

          <Panel title="Top customers this period" description="By confirmed order value — not invoiced revenue">
            {loading ? (
              <Skeleton height={120} />
            ) : (dashboard?.opportunities.top_customers.length ?? 0) === 0 ? (
              <DataState status="empty" message="No confirmed orders in this period." />
            ) : (
              <ol style={{ listStyle: 'none', padding: 0, margin: 0, display: 'grid', gap: 10 }}>
                {dashboard!.opportunities.top_customers.map((customer) => (
                  <li key={customer.customer_account_id} className="sales-row-between" style={{ gap: 10 }}>
                    <span className="sales-truncate">
                      {customer.customer_name_snapshot ?? `Account ${customer.customer_account_id}`}
                    </span>
                    <strong className="sales-numeric">{money(customer.total_value, customer.currency_code)}</strong>
                  </li>
                ))}
              </ol>
            )}
          </Panel>
        </aside>
      </div>

      {drafting && <ReminderDrawer row={drafting} onClose={() => setDrafting(null)} currency={currency} />}
    </DashboardFrame>
  )
}

function OpportunityRow({
  icon,
  count,
  label,
  tone,
  onOpen,
}: {
  icon: React.ReactNode
  count: number
  label: string
  tone?: 'warning' | 'danger'
  onOpen: () => void
}) {
  return (
    <button type="button" className="sales-priority" onClick={onOpen} disabled={count === 0}>
      <span
        aria-hidden
        className={
          tone === 'danger'
            ? 'sales-metric-icon sales-metric-icon-danger'
            : tone === 'warning'
              ? 'sales-metric-icon sales-metric-icon-warning'
              : 'sales-metric-icon'
        }
      >
        {icon}
      </span>
      <span style={{ minWidth: 0 }}>
        <strong style={{ fontSize: 20 }}>{count}</strong>
        <span>{label}</span>
      </span>
      <ArrowRight size={16} aria-hidden style={{ color: 'var(--muted)' }} />
    </button>
  )
}

/**
 * A reminder for a person to read, change and send.
 *
 * NOTHING IS SENT FROM HERE and nothing is recorded by opening it. The wording
 * comes from the server, built from the customer's own record; the amount is
 * deliberately absent from the draft because the balance is Books' and is not
 * copied into an email this product composed. Logging the contact is a separate,
 * explicit act, because a "reminder sent" that a dialog set by opening would
 * report a week of chasing that never happened.
 */
function ReminderDrawer({ row, onClose, currency }: { row: CollectionRow; onClose: () => void; currency: string }) {
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const [note, setNote] = useState('')

  const draft = useApi<{ data: { subject: string; body: string; note: string } }>(
    (signal) =>
      api.one<{ subject: string; body: string; note: string }>(
        'v1/followups/draft',
        { followup_kind: 'collection', customer_account_id: row.customer_account_id },
        signal,
      ),
    [row.customer_account_id],
    true,
  )

  const [body, setBody] = useState('')
  const text = body || draft.data?.data.body || ''

  async function logContact() {
    setBusy(true)
    setMessage(null)
    try {
      await api.post('v1/followups', {
        followup_kind: 'collection',
        customer_account_id: row.customer_account_id,
        channel: 'email',
        note: note.trim() || 'Payment reminder sent.',
      })
      setMessage('Logged. It will show as the last contact on this account.')
    } catch (error) {
      setMessage(error instanceof ApiError ? error.message : String(error))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Drawer title={`Reminder for ${row.customer_name || `Account ${row.customer_account_id}`}`} onClose={onClose}>
      <Notice tone="info" title="A suggestion, not an action">
        Nothing is sent from here. Copy this into whatever you normally use, then log the contact so the next person
        chasing this account can see it.
      </Notice>

      <div className="sales-facets">
        <div className="sales-facet">
          <dt>Outstanding</dt>
          <dd className="sales-numeric">{money(row.outstanding, currency)}</dd>
          <p>From Smart Books</p>
        </div>
        <div className="sales-facet">
          <dt>Overdue</dt>
          <dd className="sales-numeric sales-tone-negative">{money(row.overdue, currency)}</dd>
          <p>{row.oldest_days > 0 ? `Oldest ${row.oldest_days} days` : 'Nothing past due'}</p>
        </div>
      </div>

      {draft.loading ? (
        <Skeleton height={160} />
      ) : draft.error ? (
        <Notice tone="danger" title="Could not draft a reminder">
          {draft.error}
        </Notice>
      ) : (
        <>
          <p className="sales-note" style={{ margin: 0 }}>
            <strong>{draft.data?.data.subject}</strong>
          </p>
          <div className="sales-field">
            <span>Message</span>
            <Textarea value={text} onChange={(event) => setBody(event.target.value)} rows={10} />
          </div>
          <div className="sales-field">
            <span>What happened, for the record</span>
            <Textarea
              value={note}
              onChange={(event) => setNote(event.target.value)}
              rows={2}
              placeholder="Spoke to their finance team, promised by Friday…"
            />
          </div>
          <div className="sales-actions">
            <Button
              tone="primary"
              disabled={busy}
              onClick={() => {
                void navigator.clipboard?.writeText(text)
                setMessage('Copied to the clipboard.')
              }}
            >
              Copy message
            </Button>
            <Button disabled={busy} onClick={logContact}>
              Log this contact
            </Button>
          </div>
          {message && <p className="sales-note">{message}</p>}
        </>
      )}
    </Drawer>
  )
}
