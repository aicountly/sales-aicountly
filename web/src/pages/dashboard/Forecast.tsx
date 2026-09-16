import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Download, Gauge, LineChart, RotateCcw, Target } from 'lucide-react'
import { api } from '../../services/api'
import type { ForecastDashboard, TeamRow } from '../../services/dashboards'
import { useApi } from '../../hooks/useApi'
import { useSales } from '../../context/SalesContext'
import { getApiBaseUrl } from '../../config'
import {
  Button,
  DataState,
  DataTable,
  money,
  moneyShort,
  Notice,
  Panel,
  Skeleton,
} from '../../ui'
import { Progress, TrendChart } from '../../ui/charts'
import { DASHBOARDS, DashboardFrame, InsightCard, MetricsSection, usePeriod } from './frame'

const VIEW = DASHBOARDS[4]

const ICONS = {
  period_target: <Target size={17} />,
  actual_to_date: <LineChart size={17} />,
  projected: <Gauge size={17} />,
  attainment: <Target size={17} />,
}

/**
 * Dashboard 5 — the gap between where we are and where we said we would be.
 *
 * ONE MEASURE RUNS THE WHOLE SCREEN. The KPI, the chart and the projection all
 * use it, and when Smart Books cannot supply invoiced sales the panel says so
 * and falls back to confirmed order value with a caption — rather than silently
 * drawing one measure under a card showing another.
 *
 * THE SCENARIO WRITES NOTHING. Moving a slider re-runs the same arithmetic on
 * the server with different assumptions and re-renders; no record is touched
 * and the panel says so in as many words.
 */
export default function ForecastDashboardView() {
  const navigate = useNavigate()
  const { scope, can } = useSales()
  const { period, setMonth, params, setParam } = usePeriod()

  const urlConversion = params.get('conversion_pc')
  const urlDiscount = params.get('discount_pc')

  // The sliders move locally and only reach the server when released, so a drag
  // does not fire twenty requests.
  const [conversion, setConversion] = useState<number | null>(urlConversion ? Number(urlConversion) : null)
  const [discount, setDiscount] = useState<number | null>(urlDiscount ? Number(urlDiscount) : null)

  const { data, loading, error, reload } = useApi<{ data: ForecastDashboard }>(
    (signal) =>
      api.one<ForecastDashboard>(
        'v1/dashboard/forecast',
        {
          from: period.from,
          to: period.to,
          as_of: period.as_of,
          measure: params.get('measure') ?? undefined,
          conversion_pc: urlConversion ?? undefined,
          discount_pc: urlDiscount ?? undefined,
        },
        signal,
      ),
    [scope?.cmp_id, scope?.fy_id, scope?.bo_id, period.from, period.to, period.as_of, urlConversion, urlDiscount, params.get('measure')],
    Boolean(scope),
  )

  const dashboard = data?.data
  const currency = dashboard?.currency ?? 'INR'
  const projection = dashboard?.forecast

  // Until somebody moves a slider the controls show what was MEASURED, so the
  // starting position of the scenario is this company's own behaviour rather
  // than a round number chosen to look encouraging.
  const measuredConversion = projection?.assumptions.find((a) => a.key === 'conversion_pc')?.measured ?? null
  const measuredDiscount = projection?.assumptions.find((a) => a.key === 'discount_pc')?.measured ?? null

  useEffect(() => {
    if (conversion === null && measuredConversion !== null) setConversion(measuredConversion)
    if (discount === null && measuredDiscount !== null) setDiscount(measuredDiscount)
  }, [conversion, discount, measuredConversion, measuredDiscount])

  const exportUrl = () => {
    const search = new URLSearchParams({
      cmp_id: String(scope?.cmp_id ?? ''),
      fy_id: String(scope?.fy_id ?? ''),
      bo_id: String(scope?.bo_id ?? 0),
      from: period.from,
      to: period.to,
      committed: '1',
    })

    return `${getApiBaseUrl()}/v1/orders/export?${search}`
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
        can('reports.view') ? (
          <Button tone="primary" onClick={() => window.open(exportUrl(), '_blank', 'noopener')}>
            <Download size={15} aria-hidden /> Export report
          </Button>
        ) : undefined
      }
    >
      <MetricsSection metrics={dashboard?.metrics} currency={currency} loading={loading} icons={ICONS} />

      {dashboard?.measure.fallback && (
        <div style={{ marginBottom: 20 }}>
          <Notice
            tone="warning"
            title="Showing confirmed order value, not invoiced sales"
            action={
              <Button small onClick={() => setParam('measure', 'invoiced')}>
                Try invoiced again
              </Button>
            }
          >
            {dashboard.measure.fallback_reason} Everything on this screen — the cards, the chart and the projection —
            is on the order-value basis, which is committed business rather than recognised revenue.
          </Notice>
        </div>
      )}

      <div className="sales-dashboard-grid">
        <div className="sales-main">
          <Panel
            title={`${dashboard?.measure.label ?? 'Sales'} — actual vs forecast`}
            description={dashboard?.measure.basis}
            action={projection && <span className="sales-badge sales-badge-info">{projection.label}</span>}
          >
            {loading || !dashboard ? (
              <Skeleton height={260} />
            ) : (
              <>
                <TrendChart
                  series={dashboard.chart.series}
                  target={dashboard.chart.target.configured ? dashboard.chart.target.value : null}
                  actualLabel={`Actual (${dashboard.measure.label.toLowerCase()})`}
                  projectedLabel="Projected (rule-based estimate)"
                  format={(value) => moneyShort(value, currency)}
                  asOf={dashboard.chart.as_of}
                  summary={
                    projection?.available
                      ? `Actual to ${dashboard.chart.as_of} then a rule-based projection to ${dashboard.chart.period_end}, ending at ${moneyShort(projection.projected?.mid ?? 0, currency)} with a scenario range of ${moneyShort(projection.projected?.low ?? 0, currency)} to ${moneyShort(projection.projected?.high ?? 0, currency)}.`
                      : `Actual to ${dashboard.chart.as_of}. No projection: ${projection?.reason ?? 'the actual could not be read'}.`
                  }
                />
                <p className="sales-note" style={{ marginTop: 10 }}>
                  {dashboard.chart.basis}
                </p>
              </>
            )}
          </Panel>

          <Panel
            title="Team performance"
            description={
              dashboard && dashboard.team.unattributed.order_count > 0
                ? `${dashboard.team.unattributed.order_count} orders worth ${money(dashboard.team.unattributed.order_value, currency)} have no salesperson on them and are listed separately — an order shared between two people would let the team beat a target neither met.`
                : 'Order value attributed to the salesperson recorded on each order.'
            }
            flush
          >
            <DataTable<TeamRow>
              loading={loading}
              caption="Team performance"
              rows={dashboard?.team.rows ?? []}
              rowKey={(row) => row.salesperson_id}
              empty="No salespeople are set up for this company yet."
              columns={[
                {
                  key: 'rep',
                  header: 'Representative',
                  render: (row) => <span className="sales-cell-primary">{row.display_code ?? `#${row.salesperson_id}`}</span>,
                },
                {
                  key: 'target',
                  header: 'Target',
                  numeric: true,
                  render: (row) =>
                    row.target_set ? money(row.target_value, currency) : <span className="sales-muted">Not set</span>,
                },
                { key: 'actual', header: 'Actual', numeric: true, render: (row) => money(row.order_value, currency) },
                {
                  key: 'attainment',
                  header: 'Attainment',
                  numeric: true,
                  render: (row) =>
                    row.attainment_pc === null ? <span className="sales-muted">—</span> : `${row.attainment_pc}%`,
                },
                {
                  key: 'progress',
                  header: 'Progress',
                  width: '26%',
                  render: (row) =>
                    row.target_set ? (
                      <Progress
                        value={row.order_value}
                        max={row.target_value}
                        label={`${row.display_code ?? row.salesperson_id} attainment`}
                        tone={(row.attainment_pc ?? 0) < 60 ? 'warning' : 'brand'}
                      />
                    ) : (
                      <span className="sales-muted">Target not configured</span>
                    ),
                },
              ]}
            />
          </Panel>
        </div>

        <aside className="sales-aside" aria-label="Scenario and analysis">
          <Panel
            title="What-if scenario"
            description="Adjust the assumptions to see the effect on the projection."
            action={
              projection?.scenario.applied ? (
                <Button
                  small
                  onClick={() => {
                    setParam('conversion_pc', null)
                    setParam('discount_pc', null)
                    setConversion(measuredConversion)
                    setDiscount(measuredDiscount)
                  }}
                >
                  <RotateCcw size={13} aria-hidden /> Reset
                </Button>
              ) : undefined
            }
          >
            {loading || !projection ? (
              <Skeleton height={220} />
            ) : !projection.available ? (
              <DataState status="unavailable" message={projection.reason ?? 'Not enough data to forecast.'} />
            ) : (
              <div className="sales-scenario">
                <ScenarioSlider
                  label="Quotation conversion"
                  value={conversion ?? measuredConversion ?? 0}
                  min={0}
                  max={100}
                  onInput={setConversion}
                  onCommit={(value) => setParam('conversion_pc', String(value))}
                  measured={measuredConversion}
                />
                <ScenarioSlider
                  label="Average discount"
                  value={discount ?? measuredDiscount ?? 0}
                  min={0}
                  max={40}
                  onInput={setDiscount}
                  onCommit={(value) => setParam('discount_pc', String(value))}
                  measured={measuredDiscount}
                />

                <div className="sales-scenario-result">
                  <div className="sales-metric-label">Scenario estimate</div>
                  <strong className="sales-metric-value">{money(projection.projected?.mid ?? 0, currency)}</strong>
                  <p className="sales-note" style={{ margin: '6px 0 0' }}>
                    {projection.scenario.note}
                  </p>
                </div>

                <dl className="sales-definition-list" style={{ margin: 0 }}>
                  {projection.components.map((component) => (
                    <div key={component.key}>
                      <dt className="sales-row-between">
                        <span>{component.label}</span>
                        <span className="sales-numeric">{money(component.value ?? 0, currency)}</span>
                      </dt>
                      <dd>{component.detail}</dd>
                    </div>
                  ))}
                  <div>
                    <dt>Range</dt>
                    <dd>
                      {money(projection.projected?.low ?? 0, currency)} to {money(projection.projected?.high ?? 0, currency)}.{' '}
                      {projection.range_basis}
                    </dd>
                  </div>
                </dl>
              </div>
            )}
          </Panel>

          {loading ? (
            <Skeleton height={180} />
          ) : (dashboard?.insights.length ?? 0) > 0 ? (
            dashboard!.insights.map((insight) => (
              <InsightCard
                key={insight.id}
                insight={insight}
                currency={currency}
                onAction={() => navigate('/quotations?open=1')}
              />
            ))
          ) : null}

          {projection && !projection.target.configured && (
            <Notice
              tone="info"
              title="Target not configured"
              action={
                can('target.manage') ? (
                  <Button small onClick={() => navigate('/targets')}>
                    Set a target
                  </Button>
                ) : undefined
              }
            >
              Attainment cannot be shown until a value target exists for this period.
            </Notice>
          )}
        </aside>
      </div>
    </DashboardFrame>
  )
}

function ScenarioSlider({
  label,
  value,
  min,
  max,
  onInput,
  onCommit,
  measured,
}: {
  label: string
  value: number
  min: number
  max: number
  onInput: (value: number) => void
  onCommit: (value: number) => void
  measured: number | null
}) {
  return (
    <label>
      <span className="sales-scenario-value">
        <span>{label}</span>
        <span className="sales-numeric">{Math.round(value * 10) / 10}%</span>
      </span>
      <input
        type="range"
        min={min}
        max={max}
        step={0.5}
        value={value}
        onChange={(event) => onInput(Number(event.target.value))}
        onMouseUp={(event) => onCommit(Number((event.target as HTMLInputElement).value))}
        onTouchEnd={(event) => onCommit(Number((event.target as HTMLInputElement).value))}
        onKeyUp={(event) => onCommit(Number((event.target as HTMLInputElement).value))}
        aria-describedby={`${label}-measured`}
      />
      <span className="sales-scenario-scale">
        <span>{min}%</span>
        <span id={`${label}-measured`}>
          {measured === null ? 'nothing measured yet' : `measured ${Math.round(measured * 10) / 10}%`}
        </span>
        <span>{max}%</span>
      </span>
    </label>
  )
}
