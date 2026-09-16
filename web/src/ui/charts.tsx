/**
 * Charts, drawn as inline SVG.
 *
 * WHY NOT A CHART LIBRARY. There was none installed — this product's whole
 * runtime is React, the router and an icon set — and the three shapes these
 * dashboards need (a cumulative line against a target, a forecast with a band,
 * a bucketed bar) are a few dozen lines of SVG each. Adding a charting runtime
 * to draw them would be the largest dependency in the project, and would still
 * need this much wrapper code to make it accessible and to match the palette.
 *
 * ACCESSIBILITY IS NOT OPTIONAL HERE. Every chart is `role="img"` with a
 * written summary, and every one of them ships the same numbers as a real
 * table underneath, collapsed by default. A chart nobody can read with a
 * screen reader is a chart that excludes people from their own sales figures.
 *
 * NOTHING IS INVENTED. A null in a series is a gap in the line, not a zero:
 * "we have not invoiced anything today" and "today has not happened yet" look
 * identical once you draw a zero, and only one of them is true.
 */

import { useId, useMemo, useState, type ReactNode } from 'react'

export interface SeriesPoint {
  date: string
  actual?: number | null
  projected?: number | null
  low?: number | null
  high?: number | null
  cumulative?: number | null
}

interface Scale {
  x: (index: number) => number
  y: (value: number) => number
}

const PAD = { top: 16, right: 18, bottom: 30, left: 56 }

function niceCeiling(value: number): number {
  if (value <= 0) return 1
  const magnitude = 10 ** Math.floor(Math.log10(value))
  const normalised = value / magnitude
  const step = normalised <= 1 ? 1 : normalised <= 2 ? 2 : normalised <= 2.5 ? 2.5 : normalised <= 5 ? 5 : 10

  return step * magnitude
}

function path(points: Array<{ x: number; y: number } | null>): string {
  let d = ''
  let penDown = false
  for (const point of points) {
    if (point === null) {
      penDown = false
      continue
    }
    d += `${penDown ? 'L' : 'M'}${point.x.toFixed(1)} ${point.y.toFixed(1)} `
    penDown = true
  }

  return d.trim()
}

function shortDate(iso: string): string {
  const parsed = new Date(iso)
  if (Number.isNaN(parsed.getTime())) return iso

  return new Intl.DateTimeFormat('en-IN', { day: 'numeric', month: 'short' }).format(parsed)
}

/** Collapsed table beneath every chart — the same numbers, readable by anything. */
function ChartData({ caption, head, rows }: { caption: string; head: string[]; rows: string[][] }) {
  const [open, setOpen] = useState(false)

  return (
    <details open={open} onToggle={(event) => setOpen((event.target as HTMLDetailsElement).open)}>
      <summary style={{ cursor: 'pointer', color: 'var(--muted)', fontSize: 12, padding: '6px 0' }}>
        {open ? 'Hide' : 'Show'} the figures behind this chart
      </summary>
      <div className="sales-table-region" style={{ maxHeight: 260, overflowY: 'auto' }}>
        <table className="sales-table">
          <caption className="sales-visually-hidden">{caption}</caption>
          <thead>
            <tr>
              {head.map((cell, index) => (
                <th key={cell} scope="col" className={index === 0 ? undefined : 'sales-numeric'}>
                  {cell}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row[0]}>
                {row.map((cell, index) => (
                  <td key={index} className={index === 0 ? undefined : 'sales-numeric'}>
                    {cell}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </details>
  )
}

function useScale(series: SeriesPoint[], maxValue: number, width: number, height: number): Scale {
  return useMemo(() => {
    const innerWidth = Math.max(1, width - PAD.left - PAD.right)
    const innerHeight = Math.max(1, height - PAD.top - PAD.bottom)
    const span = Math.max(1, series.length - 1)
    const top = niceCeiling(maxValue)

    return {
      x: (index: number) => PAD.left + (index / span) * innerWidth,
      y: (value: number) => PAD.top + innerHeight - (Math.max(0, value) / top) * innerHeight,
    }
  }, [series.length, maxValue, width, height])
}

/**
 * Cumulative actual against a target, with an optional projection and band.
 *
 * The actual line stops where the data stops. The projection is dashed and the
 * band is shaded, so nobody mistakes an estimate for something that happened.
 */
export function TrendChart({
  series,
  target,
  targetLabel = 'Target',
  actualLabel = 'Actual',
  projectedLabel = 'Projected',
  format,
  summary,
  asOf,
}: {
  series: SeriesPoint[]
  target?: number | null
  targetLabel?: string
  actualLabel?: string
  projectedLabel?: string
  format: (value: number) => string
  summary: string
  asOf?: string | null
}) {
  const gradientId = useId()
  const width = 720
  const height = 300

  const values = series.flatMap((point) =>
    [point.actual, point.projected, point.high, point.cumulative].filter(
      (value): value is number => typeof value === 'number',
    ),
  )
  const maxValue = Math.max(target ?? 0, ...values, 1)
  const scale = useScale(series, maxValue, width, height)
  const top = niceCeiling(maxValue)

  const actualPoints = series.map((point, index) => {
    const value = point.actual ?? point.cumulative
    return typeof value === 'number' ? { x: scale.x(index), y: scale.y(value) } : null
  })
  const projectedPoints = series.map((point, index) =>
    typeof point.projected === 'number' ? { x: scale.x(index), y: scale.y(point.projected) } : null,
  )

  const bandPoints = series
    .map((point, index) => ({ point, index }))
    .filter(({ point }) => typeof point.low === 'number' && typeof point.high === 'number')

  const bandPath =
    bandPoints.length > 1
      ? `${path(bandPoints.map(({ point, index }) => ({ x: scale.x(index), y: scale.y(point.high as number) })))} ` +
        `${bandPoints
          .slice()
          .reverse()
          .map(({ point, index }) => `L${scale.x(index).toFixed(1)} ${scale.y(point.low as number).toFixed(1)}`)
          .join(' ')} Z`
      : ''

  const lastActual = [...series].reverse().find((point) => typeof (point.actual ?? point.cumulative) === 'number')
  const lastActualValue = lastActual ? (lastActual.actual ?? lastActual.cumulative ?? 0) : 0
  const lastActualIndex = lastActual ? series.indexOf(lastActual) : -1
  const lastProjected = [...series].reverse().find((point) => typeof point.projected === 'number')

  const ticks = [0, 0.25, 0.5, 0.75, 1].map((fraction) => fraction * top)
  const labelEvery = Math.max(1, Math.ceil(series.length / 7))

  if (series.length === 0) {
    return <p className="sales-state">No data for this period yet.</p>
  }

  return (
    <>
      <div className="sales-chart">
        <svg
          viewBox={`0 0 ${width} ${height}`}
          preserveAspectRatio="none"
          width="100%"
          height="100%"
          role="img"
          aria-label={summary}
        >
          <defs>
            <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor="#25b003" stopOpacity="0.22" />
              <stop offset="100%" stopColor="#25b003" stopOpacity="0.02" />
            </linearGradient>
          </defs>

          {ticks.map((tick) => (
            <g key={tick}>
              <line
                x1={PAD.left}
                x2={width - PAD.right}
                y1={scale.y(tick)}
                y2={scale.y(tick)}
                stroke="#e6ece8"
                strokeWidth="1"
              />
              <text x={PAD.left - 8} y={scale.y(tick) + 4} textAnchor="end" fontSize="11" fill="#7a8a81">
                {format(tick)}
              </text>
            </g>
          ))}

          {typeof target === 'number' && target > 0 && (
            <g>
              <line
                x1={PAD.left}
                x2={width - PAD.right}
                y1={scale.y(target)}
                y2={scale.y(target)}
                stroke="#94a49a"
                strokeWidth="1.5"
                strokeDasharray="6 5"
              />
              <text x={width - PAD.right} y={scale.y(target) - 6} textAnchor="end" fontSize="11" fill="#6c7d74">
                {targetLabel} {format(target)}
              </text>
            </g>
          )}

          {bandPath && <path d={bandPath} fill="#25b003" fillOpacity="0.12" />}

          {lastActualIndex >= 0 && (
            <path
              d={`${path(actualPoints.slice(0, lastActualIndex + 1))} L${scale.x(lastActualIndex).toFixed(1)} ${
                height - PAD.bottom
              } L${PAD.left} ${height - PAD.bottom} Z`}
              fill={`url(#${gradientId})`}
            />
          )}

          <path d={path(projectedPoints)} fill="none" stroke="#25b003" strokeWidth="2.5" strokeDasharray="7 6" />
          <path
            d={path(actualPoints)}
            fill="none"
            stroke="#25b003"
            strokeWidth="3"
            strokeLinecap="round"
            strokeLinejoin="round"
          />

          {lastActualIndex >= 0 && (
            <>
              <circle cx={scale.x(lastActualIndex)} cy={scale.y(lastActualValue)} r="5" fill="#187900" />
              <text
                x={Math.min(scale.x(lastActualIndex), width - PAD.right - 4)}
                y={scale.y(lastActualValue) - 12}
                textAnchor={lastActualIndex >= series.length - 2 ? 'end' : 'middle'}
                fontSize="12"
                fontWeight="700"
                fill="#187900"
              >
                {format(lastActualValue)}
              </text>
            </>
          )}

          {series.map((point, index) =>
            index % labelEvery === 0 || index === series.length - 1 ? (
              <text
                key={point.date}
                x={scale.x(index)}
                y={height - 10}
                textAnchor={index === 0 ? 'start' : index === series.length - 1 ? 'end' : 'middle'}
                fontSize="11"
                fill="#7a8a81"
              >
                {shortDate(point.date)}
              </text>
            ) : null,
          )}
        </svg>
      </div>

      <div className="sales-row" style={{ gap: 18, fontSize: 12, color: 'var(--muted)', marginTop: 4 }}>
        <span className="sales-row" style={{ gap: 6 }}>
          <svg width="22" height="8" aria-hidden>
            <line x1="1" y1="4" x2="21" y2="4" stroke="#25b003" strokeWidth="3" strokeLinecap="round" />
          </svg>
          {actualLabel}
          {asOf ? ` (to ${shortDate(asOf)})` : ''}
        </span>
        {lastProjected && (
          <span className="sales-row" style={{ gap: 6 }}>
            <svg width="22" height="8" aria-hidden>
              <line x1="1" y1="4" x2="21" y2="4" stroke="#25b003" strokeWidth="2.5" strokeDasharray="5 4" />
            </svg>
            {projectedLabel}
          </span>
        )}
        {bandPath && (
          <span className="sales-row" style={{ gap: 6 }}>
            <svg width="22" height="10" aria-hidden>
              <rect x="1" y="1" width="20" height="8" fill="#25b003" fillOpacity="0.14" rx="2" />
            </svg>
            Scenario range
          </span>
        )}
        {typeof target === 'number' && target > 0 && (
          <span className="sales-row" style={{ gap: 6 }}>
            <svg width="22" height="8" aria-hidden>
              <line x1="1" y1="4" x2="21" y2="4" stroke="#94a49a" strokeWidth="1.5" strokeDasharray="5 4" />
            </svg>
            {targetLabel}
          </span>
        )}
      </div>

      <ChartData
        caption={summary}
        head={['Date', actualLabel, projectedLabel]}
        rows={series.map((point) => [
          shortDate(point.date),
          typeof (point.actual ?? point.cumulative) === 'number' ? format((point.actual ?? point.cumulative) as number) : '—',
          typeof point.projected === 'number' ? format(point.projected) : '—',
        ])}
      />
    </>
  )
}

export interface BarDatum {
  key: string
  label: string
  value: number
  tone?: 'brand' | 'teal' | 'amber' | 'red'
}

const BAR_FILL: Record<string, string> = {
  brand: '#25b003',
  teal: '#3aa88f',
  amber: '#e0a106',
  red: '#e06a3e',
}

/** A bucketed bar chart — the ageing panel, and anything else with named groups. */
export function BarChart({
  data,
  format,
  summary,
  valueLabel = 'Value',
}: {
  data: BarDatum[]
  format: (value: number) => string
  summary: string
  valueLabel?: string
}) {
  const width = 620
  const height = 260
  const top = niceCeiling(Math.max(...data.map((d) => d.value), 1))
  const innerHeight = height - PAD.top - PAD.bottom - 12
  const slot = (width - PAD.left - PAD.right) / Math.max(1, data.length)
  const barWidth = Math.min(96, slot * 0.58)

  if (data.length === 0) {
    return <p className="sales-state">Nothing to show for this period.</p>
  }

  return (
    <>
      <div className="sales-chart sales-chart-short">
        <svg viewBox={`0 0 ${width} ${height}`} width="100%" height="100%" role="img" aria-label={summary}>
          {[0, 0.25, 0.5, 0.75, 1].map((fraction) => {
            const y = PAD.top + 12 + innerHeight - fraction * innerHeight
            return (
              <g key={fraction}>
                <line x1={PAD.left} x2={width - PAD.right} y1={y} y2={y} stroke="#e6ece8" />
                <text x={PAD.left - 8} y={y + 4} textAnchor="end" fontSize="11" fill="#7a8a81">
                  {format(fraction * top)}
                </text>
              </g>
            )
          })}

          {data.map((datum, index) => {
            const barHeight = Math.max(2, (Math.max(0, datum.value) / top) * innerHeight)
            const x = PAD.left + slot * index + (slot - barWidth) / 2
            const y = PAD.top + 12 + innerHeight - barHeight

            return (
              <g key={datum.key}>
                <rect x={x} y={y} width={barWidth} height={barHeight} rx="6" fill={BAR_FILL[datum.tone ?? 'brand']} />
                <text x={x + barWidth / 2} y={y - 7} textAnchor="middle" fontSize="12" fontWeight="700" fill="#17231e">
                  {format(datum.value)}
                </text>
                <text
                  x={x + barWidth / 2}
                  y={height - 9}
                  textAnchor="middle"
                  fontSize="11.5"
                  fill="#596b62"
                >
                  {datum.label}
                </text>
              </g>
            )
          })}
        </svg>
      </div>

      <ChartData
        caption={summary}
        head={['Bucket', valueLabel]}
        rows={data.map((datum) => [datum.label, format(datum.value)])}
      />
    </>
  )
}

/** A labelled progress bar — attainment, and per-representative rows. */
export function Progress({
  value,
  max,
  label,
  tone,
}: {
  value: number
  max: number
  label?: ReactNode
  tone?: 'brand' | 'warning' | 'danger'
}) {
  const share = max > 0 ? Math.min(100, Math.max(0, (value / max) * 100)) : 0
  const className = tone === 'danger' ? 'sales-meter sales-meter-danger' : tone === 'warning' ? 'sales-meter sales-meter-warning' : 'sales-meter'

  return (
    <div
      role="progressbar"
      aria-valuenow={Math.round(share)}
      aria-valuemin={0}
      aria-valuemax={100}
      aria-label={typeof label === 'string' ? label : undefined}
      className={className}
    >
      <span style={{ width: `${share}%` }} />
    </div>
  )
}
