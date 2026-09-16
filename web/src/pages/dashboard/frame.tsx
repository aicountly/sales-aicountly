/**
 * What all five dashboards share: the tab strip, the period filter, the KPI
 * row, the insight card, the drilldown resolver and the freshness footer.
 *
 * THE TAB AND THE FILTERS LIVE IN THE URL. A dashboard somebody cannot send to
 * a colleague is a dashboard they screenshot instead, and a screenshot of a
 * figure is how a stale number outlives the thing it described.
 *
 * ONLY THE ACTIVE VIEW LOADS. Each tab is its own endpoint and its own query;
 * switching tabs cancels the one you left. Five dashboards that all load at
 * once is five times the work for one screen of it.
 */

import { useCallback, useMemo, useState, type ReactNode } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { AlertTriangle, CalendarDays, CheckCircle2, ChevronRight, FileText, Package, RefreshCw } from 'lucide-react'
import {
  Button,
  Drawer,
  MetricRow,
  Notice,
  timeAgo,
  type Metric,
} from '../../ui'
import type { Freshness, Insight, Priority } from '../../services/dashboards'

export const DASHBOARDS = [
  {
    id: 'overview',
    path: '/',
    label: 'Overview',
    title: 'Sales overview',
    subtitle: 'Your revenue, commitments and priorities in one place.',
  },
  {
    id: 'pipeline',
    path: '/dashboard/pipeline',
    label: 'Pipeline & Quotations',
    title: 'Turn interest into confirmed orders',
    subtitle: 'Move every quotation forward with a clear next action.',
  },
  {
    id: 'fulfilment',
    path: '/dashboard/fulfilment',
    label: 'Orders & Fulfilment',
    title: 'Keep every customer promise',
    subtitle: 'Coordinate orders, availability and delivery.',
  },
  {
    id: 'collections',
    path: '/dashboard/collections',
    label: 'Customers & Collections',
    title: 'Grow customer value. Protect cash flow.',
    subtitle: 'Customer purchasing signals and receivable follow-ups.',
  },
  {
    id: 'forecast',
    path: '/dashboard/forecast',
    label: 'Performance & Forecast',
    title: 'See the gap. Shape the next move.',
    subtitle: 'Sales performance, explainable forecasts and scenarios.',
  },
] as const

export type DashboardId = (typeof DASHBOARDS)[number]['id']

// ---------------------------------------------------------------------------
// Period, in the URL
// ---------------------------------------------------------------------------

export interface Period {
  from: string
  to: string
  as_of: string
  month: string
}

function monthBounds(month: string): { from: string; to: string } {
  const [year, m] = month.split('-').map(Number)
  const from = new Date(Date.UTC(year, m - 1, 1))
  const to = new Date(Date.UTC(year, m, 0))

  return { from: from.toISOString().slice(0, 10), to: to.toISOString().slice(0, 10) }
}

function currentMonth(): string {
  return new Date().toISOString().slice(0, 7)
}

/**
 * The selected period, read from and written to the query string.
 *
 * `as_of` is deliberately separate from `to`. Looking at September on the 16th
 * means actuals to the 16th and the target for the whole month; drawing the
 * actual line to the 30th would flatten it after today and read as a collapse.
 */
export function usePeriod(): {
  period: Period
  setMonth: (month: string) => void
  params: URLSearchParams
  setParam: (key: string, value: string | null) => void
} {
  const [params, setParams] = useSearchParams()
  const month = params.get('month') ?? currentMonth()

  const period = useMemo<Period>(() => {
    const { from, to } = monthBounds(month)
    const today = new Date().toISOString().slice(0, 10)

    return { from, to, as_of: today < to ? (today > from ? today : from) : to, month }
  }, [month])

  const setMonth = useCallback(
    (next: string) => {
      const updated = new URLSearchParams(params)
      if (next === currentMonth()) updated.delete('month')
      else updated.set('month', next)
      setParams(updated, { replace: true })
    },
    [params, setParams],
  )

  const setParam = useCallback(
    (key: string, value: string | null) => {
      const updated = new URLSearchParams(params)
      if (value === null || value === '') updated.delete(key)
      else updated.set(key, value)
      setParams(updated, { replace: true })
    },
    [params, setParams],
  )

  return { period, setMonth, params, setParam }
}

export function MonthFilter({ period, onChange }: { period: Period; onChange: (month: string) => void }) {
  return (
    <label className="sales-row" style={{ gap: 8, color: 'var(--muted)', fontSize: 12 }}>
      <CalendarDays size={15} aria-hidden />
      <span className="sales-visually-hidden">Period</span>
      <input
        type="month"
        value={period.month}
        max={currentMonth()}
        onChange={(event) => onChange(event.target.value || currentMonth())}
        style={{
          minHeight: 40,
          padding: '8px 12px',
          border: '1px solid var(--line-strong)',
          borderRadius: 8,
          background: '#fff',
          color: 'var(--ink)',
        }}
      />
    </label>
  )
}

// ---------------------------------------------------------------------------
// Drilldowns — a route name and filters, never a URL from the server
// ---------------------------------------------------------------------------

const DRILLDOWN_ROUTES: Record<string, string> = {
  quotations: '/quotations',
  orders: '/orders',
  collections: '/dashboard/collections',
  fulfilment: '/dashboard/fulfilment',
  targets: '/targets',
  approvals: '/approvals',
  customers: '/customers',
}

/**
 * Resolve a drilldown against this app's OWN route table.
 *
 * The server sends a route NAME and a set of filters. It never sends a URL, so
 * nothing arriving from an API — or from anything that influenced one — can
 * point a browser at an address this application did not choose.
 */
export function useDrilldown(): (metric: Metric) => void {
  const navigate = useNavigate()

  return useCallback(
    (metric: Metric) => {
      if (!metric.drilldown) return
      const path = DRILLDOWN_ROUTES[metric.drilldown.route]
      if (!path) return

      const search = new URLSearchParams()
      for (const [key, value] of Object.entries(metric.drilldown.params ?? {})) {
        if (value !== null && value !== undefined && value !== '') search.set(key, String(value))
      }
      navigate(search.toString() ? `${path}?${search}` : path)
    },
    [navigate],
  )
}

export const INSIGHT_ROUTES: Record<string, string> = {
  review_quotations: '/quotations?open=1',
  review_orders: '/orders?open_only=1',
  review_approvals: '/approvals',
  review_followups: '/dashboard/collections',
  review_forecast: '/dashboard/forecast',
  retry_commands: '/orders?stuck=1',
  draft_quotation: '/quotations/new',
}

// ---------------------------------------------------------------------------
// Insight card
// ---------------------------------------------------------------------------

/**
 * A suggestion, its reason, the records behind it, and one action.
 *
 * "Why this?" opens the rule that produced it. Everything on this card is
 * deterministic — the label says "Rule-based insight" unless a configured
 * provider rephrased the wording, and in either case the arithmetic was ours.
 */
export function InsightCard({
  insight,
  onAction,
  currency,
  rank,
}: {
  insight: Insight
  onAction?: (insight: Insight) => void
  currency?: string
  /** Position in a ranked list, shown as a chip. Omitted for a standalone card. */
  rank?: number
}) {
  const [explaining, setExplaining] = useState(false)
  const navigate = useNavigate()

  const evidenceLink = (kind: string, id: number): string | null => {
    if (kind === 'quotation') return `/quotations/${id}`
    if (kind === 'order') return `/orders/${id}`
    if (kind === 'customer') return `/customers/${id}`

    return null
  }

  const act = () => {
    if (onAction) {
      onAction(insight)
      return
    }
    const route = INSIGHT_ROUTES[insight.action.kind]
    if (route) navigate(route)
  }

  return (
    <section className="sales-insight" aria-label="Suggested action">
      <span className="sales-eyebrow">{insight.origin === 'ai' ? 'AI suggestion' : 'Rule-based insight'}</span>
      <h3 className="sales-row" style={{ gap: 10, alignItems: 'baseline' }}>
        {rank !== undefined && (
          <span
            aria-hidden
            style={{
              display: 'grid',
              placeItems: 'center',
              width: 22,
              height: 22,
              flex: 'none',
              borderRadius: 999,
              background: 'var(--action)',
              color: '#fff',
              fontSize: 12,
              fontWeight: 700,
            }}
          >
            {rank}
          </span>
        )}
        {insight.title}
      </h3>
      <p>{insight.reason}</p>

      <ul className="sales-evidence">
        {insight.evidence.map((item) => {
          const href = evidenceLink(item.kind, item.id)

          return (
            <li key={`${item.kind}-${item.id}-${item.label}`}>
              {href ? <Link to={href}>{item.label}</Link> : <strong>{item.label}</strong>}
              {item.note && <span>· {item.note}</span>}
            </li>
          )
        })}
      </ul>

      <div className="sales-actions">
        <Button tone="primary" onClick={act}>
          {insight.action.label}
        </Button>
        <Button onClick={() => setExplaining(true)}>Why this?</Button>
      </div>

      <small>Based on data as of {insight.as_of}</small>

      {explaining && (
        <Drawer title="Why this suggestion" onClose={() => setExplaining(false)}>
          <dl className="sales-definition-list">
            <div>
              <dt>What it says</dt>
              <dd>{insight.title}</dd>
            </div>
            <div>
              <dt>Why</dt>
              <dd>{insight.reason}</dd>
            </div>
            <div>
              <dt>Records it came from</dt>
              <dd>
                <ul className="sales-evidence" style={{ marginBottom: 0 }}>
                  {insight.evidence.map((item) => {
                    const href = evidenceLink(item.kind, item.id)

                    return (
                      <li key={`${item.kind}-${item.id}-${item.label}-drawer`}>
                        {href ? <Link to={href}>{item.label}</Link> : <strong>{item.label}</strong>}
                        {item.note && <span>· {item.note}</span>}
                      </li>
                    )
                  })}
                </ul>
              </dd>
            </div>
            {Object.keys(insight.rule).length > 0 && (
              <div>
                <dt>The rule that fired</dt>
                <dd>
                  {Object.entries(insight.rule)
                    .map(([key, value]) => `${key.replace(/_/g, ' ')}: ${String(value)}`)
                    .join(' · ')}
                </dd>
              </div>
            )}
            <div>
              <dt>How it was produced</dt>
              <dd>
                {insight.origin === 'ai'
                  ? 'The wording was rephrased by the AI provider configured for this company. The records, the '
                    + 'thresholds and the ranking are this product’s own arithmetic — a model never supplies a figure.'
                  : 'Entirely by rules in this product. Every threshold and count above can be checked against the '
                    + 'records listed, and no language model was involved.'}
              </dd>
            </div>
            <div>
              <dt>What it will not do</dt>
              <dd>
                Nothing here approves a discount, moves stock, changes a commitment or sends anything to a customer.
                Each action opens the screen where a person decides.
                {currency ? ` Amounts are in ${currency}.` : ''}
              </dd>
            </div>
          </dl>
        </Drawer>
      )}
    </section>
  )
}

// ---------------------------------------------------------------------------
// The frame
// ---------------------------------------------------------------------------

export function PriorityList({ items, onOpen }: { items: Priority[]; onOpen: (item: Priority) => void }) {
  if (items.length === 0) {
    return (
      <div className="sales-state" role="status">
        <p>Nothing needs you right now. Quotations are inside their validity and no promise date has slipped.</p>
      </div>
    )
  }

  return (
    <div className="sales-priority-list">
      {items.map((item) => (
        <button key={item.key} type="button" className="sales-priority" onClick={() => onOpen(item)}>
          <span
            aria-hidden
            className={
              item.icon === 'risk'
                ? 'sales-metric-icon sales-metric-icon-danger'
                : item.icon === 'approval'
                  ? 'sales-metric-icon sales-metric-icon-warning'
                  : 'sales-metric-icon'
            }
          >
            {PRIORITY_ICON[item.icon]}
          </span>
          <span style={{ minWidth: 0 }}>
            <strong>{item.title}</strong>
            <span>{item.detail}</span>
            <span className="sales-tone-positive" style={{ display: 'block', fontWeight: 650, marginTop: 6 }}>
              {item.action.label}
            </span>
          </span>
          <ChevronRight size={16} aria-hidden style={{ color: 'var(--muted)' }} />
        </button>
      ))}
    </div>
  )
}

const PRIORITY_ICON: Record<Priority['icon'], ReactNode> = {
  quotation: <FileText size={16} />,
  order: <Package size={16} />,
  approval: <CheckCircle2 size={16} />,
  risk: <AlertTriangle size={16} />,
}

export function DashboardTabs({ current }: { current: DashboardId }) {
  const [params] = useSearchParams()
  const query = params.toString()

  return (
    <nav className="sales-dashboard-nav" aria-label="Sales dashboard views">
      {DASHBOARDS.map((view) => (
        <Link
          key={view.id}
          to={query ? `${view.path}?${query}` : view.path}
          aria-current={view.id === current ? 'page' : undefined}
        >
          {view.label}
        </Link>
      ))}
    </nav>
  )
}

export function FreshnessFooter({ freshness, onRefresh }: { freshness?: Freshness; onRefresh: () => void }) {
  return (
    <footer className="sales-freshness">
      <ul>
        {(freshness?.sources ?? []).map((source) => (
          <li key={source.what}>
            {source.what}: <strong>{source.owner}</strong>
          </li>
        ))}
      </ul>
      <div className="sales-row" style={{ gap: 10 }}>
        <span>Last updated {freshness ? timeAgo(freshness.generated_at) : '—'}</span>
        <Button tone="ghost" small onClick={onRefresh} title="Reload this dashboard">
          <RefreshCw size={13} aria-hidden /> Refresh
        </Button>
      </div>
    </footer>
  )
}

export function DashboardFrame({
  view,
  period,
  onMonthChange,
  primaryAction,
  filters,
  children,
  freshness,
  onRefresh,
  error,
  onRetry,
}: {
  view: (typeof DASHBOARDS)[number]
  period: Period
  onMonthChange: (month: string) => void
  primaryAction?: ReactNode
  filters?: ReactNode
  children: ReactNode
  freshness?: Freshness
  onRefresh: () => void
  error?: string | null
  onRetry?: () => void
}) {
  return (
    <>
      <DashboardTabs current={view.id} />

      <header className="sales-page-header">
        <div>
          <h1>{view.title}</h1>
          <p>{view.subtitle}</p>
        </div>
        <div className="sales-actions">
          <MonthFilter period={period} onChange={onMonthChange} />
          {primaryAction}
        </div>
      </header>

      {filters && (
        <div className="sales-filters" role="group" aria-label="Dashboard filters">
          {filters}
        </div>
      )}

      {error && (
        <div style={{ marginBottom: 20 }}>
          <Notice
            tone="danger"
            title="This dashboard could not be loaded"
            action={
              onRetry ? (
                <Button small onClick={onRetry}>
                  Retry
                </Button>
              ) : undefined
            }
          >
            {error}
          </Notice>
        </div>
      )}

      {children}

      <FreshnessFooter freshness={freshness} onRefresh={onRefresh} />
    </>
  )
}

export function MetricsSection({
  metrics,
  currency,
  loading,
  icons,
}: {
  metrics: Metric[] | undefined
  currency: string
  loading: boolean
  icons?: Record<string, ReactNode>
}) {
  const placeholders: Metric[] = useMemo(
    () =>
      Array.from({ length: 4 }, (_, index) => ({
        id: `placeholder-${index}`,
        label: '—',
        status: 'loading' as const,
        value: null,
        unit: 'count' as const,
      })),
    [],
  )
  const onOpen = useDrilldown()

  return <MetricRow metrics={loading || !metrics ? placeholders : metrics} currency={currency} onOpen={onOpen} icons={icons} />
}
