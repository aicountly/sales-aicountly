import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { AlertTriangle, Clock, FileText, LayoutGrid, MessageSquare, Plus, Rows3, TrendingUp } from 'lucide-react'
import { api } from '../../services/api'
import type { PipelineDashboard, QuotationActionRow, QuotationCard } from '../../services/dashboards'
import { useApi } from '../../hooks/useApi'
import { useSales } from '../../context/SalesContext'
import {
  Badge,
  Button,
  DataState,
  DataTable,
  date,
  money,
  Panel,
  relativeDays,
  Skeleton,
  StatusBadge,
} from '../../ui'
import { DASHBOARDS, DashboardFrame, InsightCard, MetricsSection, usePeriod } from './frame'

const VIEW = DASHBOARDS[1]

const ICONS = {
  open_quotations: <FileText size={17} />,
  awaiting_response: <MessageSquare size={17} />,
  expiring_7d: <Clock size={17} />,
  quote_conversion: <TrendingUp size={17} />,
}

/** The lanes the board opens on. Terminal states stay reachable through the list. */
const ACTIVE_STAGES = ['DRAFT', 'SENT', 'APPROVAL_PENDING']

const LANE_CLASS: Record<string, string> = {
  DRAFT: 'sales-lane',
  SENT: 'sales-lane sales-lane-sent',
  APPROVAL_PENDING: 'sales-lane sales-lane-negotiation',
}

/**
 * Dashboard 2 — every open quotation with a next action against it.
 *
 * THE BOARD IS SERVER-PAGED. Each lane asks for its own bounded set of cards
 * and the server returns the lane's real total beside them, so the header count
 * is right even though only six cards are drawn. Fetching every quotation and
 * grouping it in the browser works until the day the business succeeds.
 *
 * There is no drag-and-drop. Moving a card would be a status transition, and
 * every one of those is a permission check and an approval rule on the server.
 * A board that lets you drag a quotation into Sent without sending it is
 * exactly the lie this rebuild was meant to remove; the explicit actions on the
 * quotation are the honest version.
 */
export default function PipelineDashboardView() {
  const navigate = useNavigate()
  const { scope, can } = useSales()
  const { period, setMonth, params, setParam } = usePeriod()
  const [layout, setLayout] = useState<'board' | 'list'>(() => (params.get('layout') === 'list' ? 'list' : 'board'))

  const { data, loading, error, reload } = useApi<{ data: PipelineDashboard }>(
    (signal) =>
      api.one<PipelineDashboard>(
        'v1/dashboard/pipeline',
        { from: period.from, to: period.to, as_of: period.as_of, stages: ACTIVE_STAGES.join(','), lane_limit: 6 },
        signal,
      ),
    [scope?.cmp_id, scope?.fy_id, scope?.bo_id, period.from, period.to, period.as_of],
    Boolean(scope),
  )

  const dashboard = data?.data
  const currency = dashboard?.currency ?? 'INR'

  const setLayoutMode = (mode: 'board' | 'list') => {
    setLayout(mode)
    setParam('layout', mode === 'board' ? null : 'list')
  }

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
        can('quotation.create') ? (
          <Button tone="primary" onClick={() => navigate('/quotations/new')}>
            <Plus size={16} aria-hidden /> New quotation
          </Button>
        ) : undefined
      }
    >
      <MetricsSection metrics={dashboard?.metrics} currency={currency} loading={loading} icons={ICONS} />

      <div className="sales-dashboard-grid">
        <div className="sales-main">
          <Panel
            title="Open pipeline"
            description="Draft, sent and awaiting approval. Accepted, declined and lapsed quotations stay in the list."
            action={
              <div className="sales-actions" role="group" aria-label="Layout">
                <Button
                  small
                  tone={layout === 'board' ? 'primary' : 'secondary'}
                  onClick={() => setLayoutMode('board')}
                >
                  <LayoutGrid size={14} aria-hidden /> Board
                </Button>
                <Button small tone={layout === 'list' ? 'primary' : 'secondary'} onClick={() => setLayoutMode('list')}>
                  <Rows3 size={14} aria-hidden /> List
                </Button>
              </div>
            }
          >
            {loading || !dashboard ? (
              <Skeleton height={260} />
            ) : layout === 'board' ? (
              <div className="sales-kanban">
                {dashboard.lanes.map((lane) => (
                  <section key={lane.stage} className={LANE_CLASS[lane.stage] ?? 'sales-lane'} aria-label={lane.label}>
                    <header>
                      <h3>{laneLabel(lane.stage)}</h3>
                      <span>{lane.total}</span>
                    </header>
                    {lane.cards.length === 0 ? (
                      <p className="sales-note" style={{ margin: 0 }}>
                        Nothing in this lane.
                      </p>
                    ) : (
                      <ul>
                        {lane.cards.map((card) => (
                          <li key={card.quotation_id}>
                            <QuotationLaneCard card={card} onOpen={() => navigate(`/quotations/${card.quotation_id}`)} />
                          </li>
                        ))}
                      </ul>
                    )}
                    {lane.total > lane.cards.length && (
                      <div style={{ marginTop: 10 }}>
                        <Link to={`/quotations?status=${lane.stage}`} style={{ fontSize: 12 }}>
                          See all {lane.total} →
                        </Link>
                      </div>
                    )}
                  </section>
                ))}
              </div>
            ) : (
              <DataTable<QuotationCard>
                caption="Open quotations"
                rows={dashboard.lanes.flatMap((lane) => lane.cards)}
                rowKey={(row) => row.quotation_id}
                onRowClick={(row) => navigate(`/quotations/${row.quotation_id}`)}
                empty="No open quotations."
                columns={[
                  { key: 'no', header: 'Quotation', render: (row) => <span className="sales-cell-primary">{row.quotation_no}</span> },
                  {
                    key: 'customer',
                    header: 'Customer',
                    render: (row) => row.customer_name_snapshot ?? `Account ${row.customer_account_id}`,
                  },
                  { key: 'status', header: 'Stage', render: (row) => <StatusBadge status={row.status} /> },
                  { key: 'owner', header: 'Owner', render: (row) => row.salesperson_code ?? '—' },
                  { key: 'age', header: 'Age', numeric: true, render: (row) => `${row.age_days}d` },
                  { key: 'valid', header: 'Valid until', render: (row) => date(row.valid_until) },
                  { key: 'value', header: 'Value', numeric: true, render: (row) => money(row.total_amount, row.currency_code) },
                ]}
              />
            )}
          </Panel>

          <Panel title="Quotations needing action" description="Closest to lapsing first" flush>
            <DataTable<QuotationActionRow>
              loading={loading}
              caption="Quotations needing action"
              rows={dashboard?.needing_action ?? []}
              rowKey={(row) => row.quotation_id}
              onRowClick={(row) => navigate(`/quotations/${row.quotation_id}`)}
              empty="Nothing needs chasing."
              columns={[
                { key: 'no', header: 'Quotation', render: (row) => <span className="sales-cell-primary">{row.quotation_no}</span> },
                {
                  key: 'customer',
                  header: 'Customer',
                  render: (row) => row.customer_name_snapshot ?? `Account ${row.customer_account_id}`,
                },
                { key: 'value', header: 'Value', numeric: true, render: (row) => money(row.total_amount, row.currency_code) },
                {
                  key: 'activity',
                  header: 'Last activity',
                  render: (row) =>
                    row.days_since_contact === null ? (
                      <span className="sales-muted">Not contacted</span>
                    ) : (
                      relativeDays(-row.days_since_contact)
                    ),
                },
                {
                  key: 'expiry',
                  header: 'Expiry',
                  render: (row) => (
                    <>
                      {date(row.valid_until)}
                      {row.days_to_expiry !== null && row.days_to_expiry <= 7 && (
                        <span className="sales-cell-sub sales-tone-negative">{relativeDays(row.days_to_expiry)}</span>
                      )}
                    </>
                  ),
                },
                { key: 'next', header: 'Next step', render: (row) => <NextStep row={row} /> },
              ]}
            />
          </Panel>
        </div>

        <aside className="sales-aside" aria-label="Follow-up priorities">
          {loading ? (
            <Skeleton height={190} />
          ) : (dashboard?.insights.length ?? 0) === 0 ? (
            <Panel title="Follow-up priorities" description="Ranked by what is actually at stake">
              <DataState
                status="empty"
                message="Nothing is waiting on a follow-up: no quotation is close to lapsing, silent, or stuck in approval."
              />
            </Panel>
          ) : (
            <>
              <h2 className="sales-eyebrow" style={{ marginBottom: 0 }}>
                Follow-up priorities
              </h2>
              {dashboard!.insights.map((insight, index) => (
                <InsightCard
                  key={insight.id}
                  insight={insight}
                  currency={currency}
                  rank={index + 1}
                  onAction={() => {
                    const quotation = insight.evidence.find((item) => item.kind === 'quotation')
                    if (insight.action.kind === 'review_approvals') navigate('/approvals')
                    else if (quotation) navigate(`/quotations/${quotation.id}`)
                  }}
                />
              ))}
            </>
          )}

          <Panel title="Conversion basis" description="How the rate above is calculated">
            <p className="sales-note" style={{ margin: 0 }}>
              {dashboard?.metrics.find((metric) => metric.id === 'quote_conversion')?.definition ??
                'Accepted or converted, over quotations whose outcome is known.'}
            </p>
          </Panel>
        </aside>
      </div>
    </DashboardFrame>
  )
}

function laneLabel(stage: string): string {
  if (stage === 'APPROVAL_PENDING') return 'Awaiting approval'

  return stage.charAt(0) + stage.slice(1).toLowerCase().replace(/_/g, ' ')
}

function QuotationLaneCard({ card, onOpen }: { card: QuotationCard; onOpen: () => void }) {
  const expiringSoon = card.days_to_expiry !== null && card.days_to_expiry <= 3

  return (
    <button type="button" className="sales-quote-card" onClick={onOpen}>
      <div className="sales-row-between" style={{ gap: 8, marginBottom: 6 }}>
        <strong>
          {card.quotation_no}
          {card.revision_no > 0 && <span className="sales-muted"> rev {card.revision_no}</span>}
        </strong>
        {card.salesperson_code && (
          <span
            aria-label={`Owner ${card.salesperson_code}`}
            style={{
              display: 'grid',
              placeItems: 'center',
              width: 24,
              height: 24,
              borderRadius: 999,
              background: 'var(--brand-soft)',
              color: 'var(--action)',
              fontSize: 10,
              fontWeight: 700,
              flex: 'none',
            }}
          >
            {card.salesperson_code}
          </span>
        )}
      </div>
      <div className="sales-note" style={{ marginBottom: 4 }}>
        {card.customer_name_snapshot ?? `Account ${card.customer_account_id}`}
      </div>
      <div className="sales-quote-amount">{money(card.total_amount, card.currency_code)}</div>
      <span className="sales-note">
        {card.sent_at ? `Sent ${relativeDays(-Math.round(card.age_days))}` : `Created ${relativeDays(-card.age_days)}`}
        {card.days_to_expiry !== null && !expiringSoon && ` · expires ${relativeDays(card.days_to_expiry)}`}
      </span>
      {expiringSoon && (
        <div style={{ marginTop: 8 }}>
          <Badge tone="warning">
            <AlertTriangle size={12} aria-hidden />
            {card.days_to_expiry !== null && card.days_to_expiry <= 0 ? 'Expires today' : `Expires ${relativeDays(card.days_to_expiry)}`}
          </Badge>
        </div>
      )}
    </button>
  )
}

/**
 * The next step for one quotation — and it is genuinely different per row.
 *
 * A panel where every line says "Send follow-up" is a panel people stop
 * reading: an approval sitting in our own queue is not something the customer
 * can be chased about.
 */
function NextStep({ row }: { row: QuotationActionRow }) {
  if (row.approval_pending) {
    return <Badge tone="warning">Get revised price approved</Badge>
  }
  if (row.days_to_expiry !== null && row.days_to_expiry <= 3) {
    return (
      <Badge tone="danger">
        <AlertTriangle size={12} aria-hidden /> Follow up before {row.valid_until}
      </Badge>
    )
  }
  if (row.stored_status === 'DRAFT') {
    return <Badge tone="info">Finish and send</Badge>
  }
  if (row.days_since_contact !== null && row.days_since_contact >= 7) {
    return <Badge tone="warning">Send follow-up</Badge>
  }

  return <Badge>Awaiting a reply</Badge>
}
