/**
 * The Sales UI primitives.
 *
 * Everything inside the product area is built from these, so a dashboard, a
 * list, a detail page and a form look like one application rather than four.
 * The styling lives in sales-ui.css and is scoped to `.sales-ui`; these
 * components only pick class names, which is what keeps the two in step.
 *
 * Three rules run through all of it:
 *
 *   - A NUMBER SAYS WHAT IT IS. Amounts are right-aligned, tabular, and carry
 *     their currency. A column of money that does not line up is a column
 *     somebody has to read twice.
 *   - MISSING IS NOT ZERO. `Unavailable` and `Restricted` are first-class
 *     states with their own rendering, because a dash that means "Books did not
 *     answer" and a 0 that means "no sales" must never look the same.
 *   - AN ACTION SAYS WHAT WILL HAPPEN. Buttons are verbs, destructive ones are
 *     outlined in red, and nothing is offered that the backend would refuse.
 */

import { useEffect, useRef, type CSSProperties, type ReactNode } from 'react'
import { AlertTriangle, ChevronLeft, ChevronRight, Info, Search, X } from 'lucide-react'
import './sales-ui.css'

// ---------------------------------------------------------------------------
// Buttons
// ---------------------------------------------------------------------------

type ButtonTone = 'primary' | 'secondary' | 'ghost' | 'danger'

const TONE_CLASS: Record<ButtonTone, string> = {
  primary: 'sales-button sales-button-primary',
  secondary: 'sales-button',
  ghost: 'sales-button sales-button-ghost',
  danger: 'sales-button sales-button-danger',
}

export function Button({
  children,
  onClick,
  tone = 'secondary',
  disabled = false,
  type = 'button',
  title,
  small = false,
  style,
}: {
  children: ReactNode
  onClick?: () => void
  tone?: ButtonTone
  disabled?: boolean
  type?: 'button' | 'submit'
  title?: string
  small?: boolean
  style?: CSSProperties
}) {
  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled}
      title={title}
      className={`${TONE_CLASS[tone]}${small ? ' sales-button-small' : ''}`}
      style={style}
    >
      {children}
    </button>
  )
}

// ---------------------------------------------------------------------------
// Surfaces
// ---------------------------------------------------------------------------

export function Panel({
  title,
  description,
  action,
  children,
  footer,
  flush = false,
}: {
  title?: ReactNode
  description?: ReactNode
  action?: ReactNode
  children: ReactNode
  footer?: ReactNode
  flush?: boolean
}) {
  return (
    <section className="sales-panel" aria-label={typeof title === 'string' ? title : undefined}>
      {(title || action) && (
        <header className="sales-panel-header">
          <div style={{ minWidth: 0 }}>
            {title && <h2>{title}</h2>}
            {description && <p>{description}</p>}
          </div>
          {action}
        </header>
      )}
      <div className={flush ? 'sales-panel-content sales-panel-content-flush' : 'sales-panel-content'}>{children}</div>
      {footer && <div className="sales-panel-footer">{footer}</div>}
    </section>
  )
}

/** Kept for the screens that were written against the old primitive. */
export const Card = Panel

// ---------------------------------------------------------------------------
// KPI cards
// ---------------------------------------------------------------------------

export type MetricStatus = 'ready' | 'loading' | 'unavailable' | 'forbidden'

export interface Metric {
  id: string
  label: string
  status: MetricStatus
  value: number | string | null
  unit: 'currency' | 'count' | 'percent'
  tone?: 'neutral' | 'positive' | 'negative' | 'warning'
  definition?: string | null
  comparison?: string | null
  source?: string
  reason?: string | null
  warning?: string | null
  progress?: number | null
  range?: { low: number; mid: number; high: number } | null
  drilldown?: { route: string; params: Record<string, string | number> } | null
}

export function formatMetric(metric: Metric, currency: string): string {
  if (metric.value === null || metric.value === undefined) return '—'
  const numeric = typeof metric.value === 'string' ? Number.parseFloat(metric.value) : metric.value
  if (!Number.isFinite(numeric)) return String(metric.value)

  // Short form on a card, full precision on a document. "₹48.6L" is how this
  // figure is spoken; "₹48,63,921.40" is what you reconcile against, and the
  // two belong in different places.
  if (metric.unit === 'currency') return moneyShort(numeric, currency)
  if (metric.unit === 'percent') return `${Math.round(numeric * 10) / 10}%`

  return new Intl.NumberFormat('en-IN').format(numeric)
}

/**
 * One KPI.
 *
 * A metric that could not be read renders as "Unavailable" with the reason
 * underneath, and is not clickable — there is nothing behind it to drill into.
 * Showing a zero there would be a lie the user acts on.
 */
export function MetricCard({
  metric,
  currency,
  onOpen,
  icon,
}: {
  metric: Metric
  currency: string
  onOpen?: (metric: Metric) => void
  icon?: ReactNode
}) {
  const ready = metric.status === 'ready'
  const canOpen = ready && Boolean(metric.drilldown) && Boolean(onOpen)

  const displayed =
    metric.status === 'loading'
      ? 'Loading…'
      : metric.status === 'forbidden'
        ? 'Restricted'
        : !ready
          ? 'Unavailable'
          : formatMetric(metric, currency)

  const iconClass =
    metric.tone === 'negative'
      ? 'sales-metric-icon sales-metric-icon-danger'
      : metric.tone === 'warning'
        ? 'sales-metric-icon sales-metric-icon-warning'
        : 'sales-metric-icon'

  const body = (
    <>
      <div className="sales-metric-head">
        {icon && (
          <span className={iconClass} aria-hidden>
            {icon}
          </span>
        )}
        <span className="sales-metric-label">{metric.label}</span>
      </div>
      <strong className={ready ? 'sales-metric-value' : 'sales-metric-value sales-metric-value-muted'}>{displayed}</strong>
      {ready && metric.progress !== null && metric.progress !== undefined && (
        <div
          className={`sales-meter${metric.progress < 60 ? ' sales-meter-warning' : ''}`}
          role="progressbar"
          aria-valuenow={Math.round(metric.progress)}
          aria-valuemin={0}
          aria-valuemax={100}
          aria-label={`${metric.label} progress`}
        >
          <span style={{ width: `${Math.min(100, Math.max(0, metric.progress))}%` }} />
        </div>
      )}
      <span className={`sales-note${metric.tone === 'negative' ? ' sales-tone-negative' : ''}`}>
        {ready ? (metric.comparison ?? metric.definition) : (metric.reason ?? metric.definition)}
      </span>
      {metric.warning && <span className="sales-note sales-tone-warning">{metric.warning}</span>}
    </>
  )

  if (canOpen) {
    return (
      <button
        type="button"
        className="sales-metric sales-metric-action"
        onClick={() => onOpen?.(metric)}
        aria-label={`${metric.label}: ${displayed}. View the records behind this`}
        title={metric.definition ?? undefined}
      >
        {body}
      </button>
    )
  }

  return (
    <article className="sales-metric" aria-busy={metric.status === 'loading'} title={metric.definition ?? undefined}>
      {body}
    </article>
  )
}

export function MetricRow({
  metrics,
  currency,
  onOpen,
  icons,
}: {
  metrics: Metric[]
  currency: string
  onOpen?: (metric: Metric) => void
  icons?: Record<string, ReactNode>
}) {
  return (
    <section className="sales-metrics" aria-label="Key metrics">
      {metrics.map((metric) => (
        <MetricCard key={metric.id} metric={metric} currency={currency} onOpen={onOpen} icon={icons?.[metric.id]} />
      ))}
    </section>
  )
}

/** The older stat card, still used by a couple of small screens. */
export function StatCard({
  label,
  value,
  hint,
  tone,
}: {
  label: string
  value: ReactNode
  hint?: ReactNode
  tone?: 'default' | 'warning' | 'danger' | 'success'
}) {
  return (
    <article className="sales-metric">
      <span className="sales-metric-label">{label}</span>
      <strong className="sales-metric-value" style={tone === 'danger' ? { color: 'var(--danger)' } : tone === 'warning' ? { color: 'var(--warning)' } : undefined}>
        {value}
      </strong>
      {hint && <span className="sales-note">{hint}</span>}
    </article>
  )
}

// ---------------------------------------------------------------------------
// Status
// ---------------------------------------------------------------------------

/**
 * Status colours carry meaning, so they are assigned by what the status MEANS
 * rather than alphabetically: anything waiting on a person is amber, anything
 * finished is green, anything that failed is red.
 */
const STATUS_TONES: Record<string, 'neutral' | 'info' | 'warning' | 'success' | 'danger'> = {
  DRAFT: 'neutral',
  APPROVAL_PENDING: 'warning',
  APPROVED: 'info',
  SENT: 'info',
  ACCEPTED: 'success',
  REJECTED: 'danger',
  REVISION_REQUESTED: 'warning',
  NEGOTIATION: 'warning',
  EXPIRED: 'danger',
  CONVERTED: 'success',
  CANCELLED: 'neutral',
  CONFIRMED: 'info',
  RESERVATION_PENDING: 'warning',
  RESERVED: 'info',
  PARTIALLY_FULFILLED: 'warning',
  FULFILLED: 'success',
  CLOSED: 'success',
  SUBMITTED: 'warning',
  RECEIVED: 'info',
  CREDITED: 'success',
  PENDING: 'warning',
  POSTING: 'info',
  COMPLETED: 'success',
  FAILED: 'danger',
  BLOCKED: 'danger',
  POSTED: 'success',
  REQUESTED: 'warning',
}

const BADGE_CLASS = {
  neutral: 'sales-badge',
  info: 'sales-badge sales-badge-info',
  warning: 'sales-badge sales-badge-warning',
  success: 'sales-badge sales-badge-success',
  danger: 'sales-badge sales-badge-danger',
} as const

export type BadgeTone = keyof typeof BADGE_CLASS

export function Badge({ tone = 'neutral', children, dot = false }: { tone?: BadgeTone; children: ReactNode; dot?: boolean }) {
  return (
    <span className={BADGE_CLASS[tone]}>
      {dot && <span className="sales-dot" aria-hidden />}
      {children}
    </span>
  )
}

export function StatusBadge({ status }: { status: string }) {
  return <Badge tone={STATUS_TONES[status] ?? 'neutral'}>{status.replace(/_/g, ' ').toLowerCase().replace(/^./, (c) => c.toUpperCase())}</Badge>
}

export function Notice({
  tone = 'info',
  title,
  children,
  action,
  onDismiss,
}: {
  tone?: 'info' | 'warning' | 'danger' | 'success'
  title?: string
  children?: ReactNode
  action?: ReactNode
  /** When given, the notice can be closed. Errors otherwise pile up on screen. */
  onDismiss?: () => void
}) {
  return (
    <div role={tone === 'danger' ? 'alert' : 'status'} className={`sales-notice sales-notice-${tone}`}>
      <div className="sales-row" style={{ gap: 10, alignItems: 'flex-start', minWidth: 0, flexWrap: 'nowrap' }}>
        <span aria-hidden style={{ marginTop: 2, flex: 'none', color: tone === 'danger' ? 'var(--danger)' : tone === 'warning' ? 'var(--warning)' : 'var(--action)' }}>
          {tone === 'danger' || tone === 'warning' ? <AlertTriangle size={16} /> : <Info size={16} />}
        </span>
        <div style={{ minWidth: 0 }}>
          {title && <strong>{title}</strong>}
          {children && <p style={{ margin: title ? '3px 0 0' : 0 }}>{children}</p>}
        </div>
      </div>
      <div className="sales-row" style={{ flexWrap: 'nowrap', flex: 'none' }}>
        {action}
        {onDismiss && (
          <button type="button" onClick={onDismiss} aria-label="Dismiss" className="sales-button sales-button-ghost sales-button-small">
            <X size={14} aria-hidden />
          </button>
        )}
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Loading, empty and unavailable
// ---------------------------------------------------------------------------

export function DataState({
  status,
  message,
  retry,
  children,
}: {
  status: 'ready' | 'loading' | 'empty' | 'error' | 'unavailable' | 'forbidden'
  message?: ReactNode
  retry?: () => void
  children?: ReactNode
}) {
  if (status === 'ready') return <>{children}</>

  return (
    <div className="sales-state" role="status">
      {status === 'loading' && <div className="sales-skeleton" />}
      <p>
        {message ??
          (status === 'loading'
            ? 'Loading…'
            : status === 'empty'
              ? 'Nothing here yet.'
              : status === 'forbidden'
                ? 'You do not have permission to see this.'
                : 'This could not be loaded.')}
      </p>
      {(status === 'error' || status === 'unavailable') && retry && (
        <Button onClick={retry} small>
          Retry
        </Button>
      )}
    </div>
  )
}

export function Skeleton({ height = 110 }: { height?: number }) {
  return <div className="sales-skeleton" style={{ height }} />
}

// ---------------------------------------------------------------------------
// Forms
// ---------------------------------------------------------------------------

export function Field({
  label,
  hint,
  error,
  required,
  children,
}: {
  label: string
  hint?: string
  error?: string
  required?: boolean
  children: ReactNode
}) {
  return (
    <label className="sales-field">
      <span>
        {label}
        {required && (
          <span className="sales-required" aria-hidden>
            *
          </span>
        )}
      </span>
      {children}
      {hint && !error && <span className="sales-field-hint">{hint}</span>}
      {error && <span className="sales-field-error">{error}</span>}
    </label>
  )
}

export function Input(props: React.InputHTMLAttributes<HTMLInputElement>) {
  return <input {...props} />
}

export function Select(props: React.SelectHTMLAttributes<HTMLSelectElement>) {
  return <select {...props} />
}

export function Textarea(props: React.TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return <textarea {...props} />
}

export function SearchInput({
  value,
  onChange,
  placeholder = 'Search…',
  label,
}: {
  value: string
  onChange: (value: string) => void
  placeholder?: string
  label: string
}) {
  return (
    <div className="sales-search">
      <Search size={15} aria-hidden />
      <input
        type="search"
        value={value}
        onChange={(event) => onChange(event.target.value)}
        placeholder={placeholder}
        aria-label={label}
      />
    </div>
  )
}

/**
 * Warn before leaving a form with unsaved work.
 *
 * Only a real browser-level guard, because React Router 7's own blocker is not
 * available under the data-router-less setup this app uses. It covers the case
 * that actually loses work — a closed tab or a back button.
 */
export function useUnsavedChanges(dirty: boolean): void {
  useEffect(() => {
    if (!dirty) return undefined

    const handler = (event: BeforeUnloadEvent) => {
      event.preventDefault()
      event.returnValue = ''
    }
    window.addEventListener('beforeunload', handler)

    return () => window.removeEventListener('beforeunload', handler)
  }, [dirty])
}

// ---------------------------------------------------------------------------
// Table
// ---------------------------------------------------------------------------

export interface Column<T> {
  key: string
  header: ReactNode
  /** Right-aligned tabular figures. Use for anything numeric. */
  numeric?: boolean
  width?: string
  render: (row: T) => ReactNode
}

export function DataTable<T>({
  columns,
  rows,
  rowKey,
  onRowClick,
  empty = 'Nothing here yet.',
  loading = false,
  caption,
}: {
  columns: Column<T>[]
  rows: T[]
  rowKey: (row: T) => string | number
  onRowClick?: (row: T) => void
  empty?: ReactNode
  loading?: boolean
  caption?: string
}) {
  if (loading) {
    return (
      <div className="sales-stack-tight" style={{ padding: '12px 0' }}>
        <Skeleton height={16} />
        <Skeleton height={16} />
        <Skeleton height={16} />
      </div>
    )
  }

  return (
    <div className="sales-table-region" role="region" aria-label={caption ?? 'Records'} tabIndex={0}>
      <table className={onRowClick ? 'sales-table sales-table-clickable' : 'sales-table'}>
        {caption && <caption className="sales-visually-hidden">{caption}</caption>}
        <thead>
          <tr>
            {columns.map((column) => (
              <th key={column.key} scope="col" className={column.numeric ? 'sales-numeric' : undefined} style={{ width: column.width }}>
                {column.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.length > 0 ? (
            rows.map((row) => (
              <tr
                key={rowKey(row)}
                onClick={onRowClick ? () => onRowClick(row) : undefined}
                onKeyDown={
                  onRowClick
                    ? (event) => {
                        if (event.key === 'Enter') onRowClick(row)
                      }
                    : undefined
                }
                tabIndex={onRowClick ? 0 : undefined}
              >
                {columns.map((column) => (
                  <td key={column.key} className={column.numeric ? 'sales-numeric' : undefined}>
                    {column.render(row)}
                  </td>
                ))}
              </tr>
            ))
          ) : (
            <tr>
              <td colSpan={columns.length} style={{ textAlign: 'center', color: 'var(--muted)', padding: '28px 12px' }}>
                {empty}
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  )
}

/**
 * Server pagination.
 *
 * The count is the SERVER's total, not the length of the page: a footer that
 * says "50 records" because fifty came back is wrong on every list with more
 * than fifty, and nobody notices until they go looking for the fifty-first.
 */
export function Pagination({
  total,
  limit,
  offset,
  onChange,
  label = 'records',
}: {
  total: number
  limit: number
  offset: number
  onChange: (offset: number) => void
  label?: string
}) {
  if (total <= limit) {
    return (
      <div className="sales-pagination">
        <span>
          {total} {total === 1 ? label.replace(/s$/, '') : label}
        </span>
      </div>
    )
  }

  const first = offset + 1
  const last = Math.min(offset + limit, total)

  return (
    <div className="sales-pagination">
      <span>
        {first}–{last} of {total} {label}
      </span>
      <div className="sales-actions">
        <Button small disabled={offset === 0} onClick={() => onChange(Math.max(0, offset - limit))}>
          <ChevronLeft size={14} aria-hidden /> Previous
        </Button>
        <Button small disabled={last >= total} onClick={() => onChange(offset + limit)}>
          Next <ChevronRight size={14} aria-hidden />
        </Button>
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Drawer — "Why this?" and metric definitions
// ---------------------------------------------------------------------------

export function Drawer({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode }) {
  const panelRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    panelRef.current?.focus()
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)

    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  return (
    <div className="sales-drawer-backdrop" onClick={onClose} role="presentation">
      <div
        ref={panelRef}
        className="sales-drawer"
        role="dialog"
        aria-modal="true"
        aria-label={title}
        tabIndex={-1}
        onClick={(event) => event.stopPropagation()}
      >
        <div className="sales-row-between">
          <h2>{title}</h2>
          <Button tone="ghost" small onClick={onClose}>
            <X size={16} aria-hidden /> Close
          </Button>
        </div>
        {children}
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------

/**
 * Indian digit grouping (1,23,456.78) because that is what the numbers here are
 * read in. The currency comes from the document, never hard-coded: Books
 * supports more than one and a schema that assumed INR would have to be undone.
 */
export function money(value: number | string | null | undefined, currency = 'INR'): string {
  const amount = typeof value === 'string' ? Number.parseFloat(value) : (value ?? 0)
  if (!Number.isFinite(amount)) return '—'

  return new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency,
    maximumFractionDigits: 2,
  }).format(amount)
}

/**
 * Money at a glance — lakhs and crores, which is how these figures are spoken.
 *
 * Used on KPI cards and chart axes, never on a document: an invoice total
 * rounded to "₹2.4L" is not a total anybody can reconcile.
 */
export function moneyShort(value: number | string | null | undefined, currency = 'INR'): string {
  const amount = typeof value === 'string' ? Number.parseFloat(value) : (value ?? 0)
  if (!Number.isFinite(amount)) return '—'

  const symbol = currency === 'INR' ? '₹' : ''
  const prefix = amount < 0 ? '-' : ''
  const magnitude = Math.abs(amount)

  if (currency !== 'INR') {
    return `${prefix}${new Intl.NumberFormat('en-IN', { style: 'currency', currency, maximumFractionDigits: 0 }).format(magnitude)}`
  }
  if (magnitude >= 10000000) return `${prefix}${symbol}${(magnitude / 10000000).toFixed(magnitude >= 100000000 ? 0 : 1)}Cr`
  if (magnitude >= 100000) return `${prefix}${symbol}${(magnitude / 100000).toFixed(magnitude >= 10000000 ? 0 : 1)}L`
  if (magnitude >= 1000) return `${prefix}${symbol}${new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 }).format(magnitude)}`

  return `${prefix}${symbol}${magnitude.toFixed(0)}`
}

export function qty(value: number | string | null | undefined): string {
  const amount = typeof value === 'string' ? Number.parseFloat(value) : (value ?? 0)
  if (!Number.isFinite(amount)) return '—'

  // Trailing zeros on a quantity are noise: 100.0000 reads worse than 100.
  return String(Number.parseFloat(amount.toFixed(4)))
}

export function date(value: string | null | undefined): string {
  if (!value) return '—'
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return value

  return new Intl.DateTimeFormat('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }).format(parsed)
}

export function dateShort(value: string | null | undefined): string {
  if (!value) return '—'
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return value

  return new Intl.DateTimeFormat('en-IN', { day: 'numeric', month: 'short' }).format(parsed)
}

/** "3 days ago", "in 2 days" — the unit a promise date is actually judged in. */
export function relativeDays(days: number | null | undefined): string {
  if (days === null || days === undefined || !Number.isFinite(days)) return '—'
  const whole = Math.round(days)
  if (whole === 0) return 'today'
  if (whole === 1) return 'tomorrow'
  if (whole === -1) return 'yesterday'

  return whole > 0 ? `in ${whole} days` : `${Math.abs(whole)} days ago`
}

export function timeAgo(value: string | null | undefined): string {
  if (!value) return '—'
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return value

  const minutes = Math.round((Date.now() - parsed.getTime()) / 60000)
  if (minutes < 1) return 'just now'
  if (minutes < 60) return `${minutes} min ago`
  const hours = Math.round(minutes / 60)
  if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'} ago`

  return date(value)
}
