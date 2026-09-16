import { useNavigate } from 'react-router-dom'
import { AlertTriangle, BarChart3, FileText, Plus, Receipt } from 'lucide-react'
import { api } from '../../services/api'
import type { AttentionRow, OverviewDashboard } from '../../services/dashboards'
import { useApi } from '../../hooks/useApi'
import { useSales } from '../../context/SalesContext'
import { Badge, Button, DataTable, DataState, money, moneyShort, Notice, Panel, relativeDays, Skeleton } from '../../ui'
import { TrendChart } from '../../ui/charts'
import { DASHBOARDS, DashboardFrame, InsightCard, MetricsSection, PriorityList, usePeriod, INSIGHT_ROUTES } from './frame'

const VIEW = DASHBOARDS[0]

const ICONS = {
  net_invoiced_sales: <BarChart3 size={17} />,
  confirmed_orders: <Receipt size={17} />,
  open_quotations: <FileText size={17} />,
  overdue_receivables: <AlertTriangle size={17} />,
}

/**
 * Dashboard 1 — where the money is, what is committed, and what to do first.
 *
 * The chart says which measure it is drawing. When Smart Books can supply a
 * dated invoice series the line is invoiced sales and matches the card above
 * it; when it cannot, the line is our own committed order value and the caption
 * says so rather than quietly changing what the reader is looking at.
 */
export default function OverviewDashboard() {
  const navigate = useNavigate()
  const { scope, can } = useSales()
  const { period, setMonth } = usePeriod()

  const { data, loading, error, reload } = useApi<{ data: OverviewDashboard }>(
    (signal) =>
      api.one<OverviewDashboard>(
        'v1/dashboard/overview',
        { from: period.from, to: period.to, as_of: period.as_of },
        signal,
      ),
    [scope?.cmp_id, scope?.fy_id, scope?.bo_id, period.from, period.to, period.as_of],
    Boolean(scope),
  )

  const dashboard = data?.data
  const currency = dashboard?.currency ?? 'INR'

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
            title={`${dashboard?.chart.measure_label ?? 'Sales'} against target`}
            description={dashboard?.chart.basis}
          >
            {loading || !dashboard ? (
              <Skeleton height={260} />
            ) : (
              <>
                <TrendChart
                  series={dashboard.chart.series}
                  target={dashboard.chart.target.configured ? dashboard.chart.target.value : null}
                  targetLabel="Target"
                  actualLabel={dashboard.chart.measure_label}
                  format={(value) => moneyShort(value, currency)}
                  asOf={dashboard.chart.as_of}
                  summary={`${dashboard.chart.measure_label} accumulating from ${period.from} to ${dashboard.chart.as_of}${
                    dashboard.chart.target.configured ? `, against a target of ${moneyShort(dashboard.chart.target.value, currency)}` : ''
                  }.`}
                />
                <TargetLine dashboard={dashboard} currency={currency} />
              </>
            )}
          </Panel>

          <Panel
            title="Orders requiring attention"
            description="Open orders with a reason to look at them today"
            action={
              <Button small onClick={() => navigate('/orders?open_only=1')}>
                View all
              </Button>
            }
            flush
          >
            {dashboard?.attention.inventory.status === 'unavailable' && (
              <div style={{ padding: '12px 20px 0' }}>
                <Notice tone="warning" title="Availability is unknown">
                  {dashboard.attention.inventory.reason ??
                    'Inventory did not answer, so stock shortfalls are not shown on these rows.'}
                </Notice>
              </div>
            )}
            <DataTable<AttentionRow>
              loading={loading}
              caption="Orders requiring attention"
              rows={dashboard?.attention.rows ?? []}
              rowKey={(row) => row.order_id}
              onRowClick={(row) => navigate(`/orders/${row.order_id}`)}
              empty="No order needs attention right now."
              columns={[
                {
                  key: 'customer',
                  header: 'Customer',
                  render: (row) => (
                    <span className="sales-cell-primary">
                      {row.customer_name_snapshot ?? `Account ${row.customer_account_id}`}
                    </span>
                  ),
                },
                { key: 'order', header: 'Order', render: (row) => row.order_no },
                {
                  key: 'promise',
                  header: 'Promise date',
                  render: (row) => (
                    <>
                      {row.committed_date ?? '—'}
                      {row.days_to_promise !== null && (
                        <span className="sales-cell-sub">{relativeDays(row.days_to_promise)}</span>
                      )}
                    </>
                  ),
                },
                {
                  key: 'value',
                  header: 'Value',
                  numeric: true,
                  render: (row) => money(row.total_amount, row.currency_code),
                },
                {
                  key: 'issue',
                  header: 'Issue',
                  render: (row) => (
                    <Badge tone={row.issue.tone} dot>
                      {row.issue.label}
                    </Badge>
                  ),
                },
                {
                  key: 'action',
                  header: '',
                  render: (row) => (
                    <Button small onClick={() => navigate(`/orders/${row.order_id}`)}>
                      Review
                    </Button>
                  ),
                },
              ]}
            />
          </Panel>
        </div>

        <aside className="sales-aside" aria-label="Priorities and insights">
          <Panel title="Today's priorities" description="Only what is actually true right now">
            {loading ? (
              <Skeleton height={140} />
            ) : (
              <PriorityList
                items={dashboard?.priorities ?? []}
                onOpen={(item) => {
                  const route = INSIGHT_ROUTES[item.action.kind]
                  if (route) navigate(route)
                }}
              />
            )}
          </Panel>

          {loading ? (
            <Skeleton height={190} />
          ) : (dashboard?.insights.length ?? 0) > 0 ? (
            dashboard!.insights.map((insight) => (
              <InsightCard key={insight.id} insight={insight} currency={currency} />
            ))
          ) : (
            <DataState status="empty" message="No suggestion today — nothing in the data warrants one." />
          )}
        </aside>
      </div>
    </DashboardFrame>
  )
}

/**
 * The one-line verdict under the chart.
 *
 * It uses the SAME achieved figure and the SAME target as the chart above it,
 * so the sentence and the picture cannot disagree. Where no target has been
 * configured it says that, rather than treating zero as the target and
 * declaring every month a triumph.
 */
function TargetLine({ dashboard, currency }: { dashboard: OverviewDashboard; currency: string }) {
  const { target, achieved, as_of: asOf } = dashboard.chart

  if (!target.configured || !target.value) {
    return (
      <div style={{ marginTop: 14 }}>
        <Notice tone="info" title="Target not configured">
          Set a value target for this period to see progress against it.
        </Notice>
      </div>
    )
  }

  if (achieved === null) {
    return (
      <div style={{ marginTop: 14 }}>
        <Notice tone="warning" title="Progress cannot be measured">
          {dashboard.metrics.find((metric) => metric.id === 'net_invoiced_sales')?.reason ??
            'The actual figure for this period could not be read.'}
        </Notice>
      </div>
    )
  }

  const share = Math.round((achieved / target.value) * 100)
  const onTrack = share >= 40

  return (
    <div style={{ marginTop: 14 }}>
      <Notice tone={onTrack ? 'success' : 'warning'} title={onTrack ? 'On track' : 'Behind target'}>
        {money(achieved, currency)} achieved ({share}% of {money(target.value, currency)}) as at {asOf}.
      </Notice>
    </div>
  )
}
