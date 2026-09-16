/**
 * The Sales UI primitives.
 *
 * Deliberately small and styled from the CSS variables in index.css, which are
 * the same tokens Books and Inventory use — a user who moves between products
 * should not feel they have changed application.
 */

import type { CSSProperties, ReactNode } from 'react'

// ---------------------------------------------------------------------------
// Buttons
// ---------------------------------------------------------------------------

type ButtonTone = 'primary' | 'secondary' | 'ghost' | 'danger'

export function Button({
  children,
  onClick,
  tone = 'secondary',
  disabled = false,
  type = 'button',
  title,
  style,
}: {
  children: ReactNode
  onClick?: () => void
  tone?: ButtonTone
  disabled?: boolean
  type?: 'button' | 'submit'
  title?: string
  style?: CSSProperties
}) {
  const tones: Record<ButtonTone, CSSProperties> = {
    primary: { background: 'var(--accent)', color: 'var(--accent-fg)', borderColor: 'var(--accent)' },
    secondary: { background: 'var(--surface)', color: 'var(--fg)', borderColor: 'var(--border-strong)' },
    ghost: { background: 'transparent', color: 'var(--muted)', borderColor: 'transparent' },
    danger: { background: 'var(--danger-bg)', color: 'var(--danger)', borderColor: 'var(--danger)' },
  }

  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled}
      title={title}
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: '0.4rem',
        padding: '0.4rem 0.75rem',
        borderRadius: 'var(--radius-sm)',
        border: '1px solid',
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.55 : 1,
        fontWeight: 500,
        ...tones[tone],
        ...style,
      }}
    >
      {children}
    </button>
  )
}

// ---------------------------------------------------------------------------
// Surfaces
// ---------------------------------------------------------------------------

export function Card({
  title,
  action,
  children,
  style,
}: {
  title?: ReactNode
  action?: ReactNode
  children: ReactNode
  style?: CSSProperties
}) {
  return (
    <section
      style={{
        background: 'var(--surface)',
        border: '1px solid var(--border)',
        borderRadius: 'var(--radius)',
        boxShadow: 'var(--shadow)',
        ...style,
      }}
    >
      {(title || action) && (
        <header
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: '1rem',
            padding: '0.75rem 1rem',
            borderBottom: '1px solid var(--border)',
          }}
        >
          <h2 style={{ margin: 0, fontSize: '0.95rem', fontWeight: 600 }}>{title}</h2>
          {action}
        </header>
      )}
      <div style={{ padding: '1rem' }}>{children}</div>
    </section>
  )
}

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
  const accents: Record<string, string> = {
    default: 'var(--fg)',
    warning: 'var(--warning)',
    danger: 'var(--danger)',
    success: 'var(--success)',
  }

  return (
    <div
      style={{
        background: 'var(--surface)',
        border: '1px solid var(--border)',
        borderRadius: 'var(--radius)',
        padding: '0.9rem 1rem',
        minWidth: 0,
      }}
    >
      <div style={{ color: 'var(--muted)', fontSize: '0.78rem', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
        {label}
      </div>
      <div
        className="num"
        style={{ fontSize: '1.5rem', fontWeight: 600, marginTop: '0.3rem', textAlign: 'left', color: accents[tone ?? 'default'] }}
      >
        {value}
      </div>
      {hint && <div style={{ color: 'var(--muted)', fontSize: '0.8rem', marginTop: '0.2rem' }}>{hint}</div>}
    </div>
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
  EXPIRED: 'neutral',
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

export function StatusBadge({ status }: { status: string }) {
  const tone = STATUS_TONES[status] ?? 'neutral'
  const palette = {
    neutral: { bg: 'var(--surface-2)', fg: 'var(--muted)', border: 'var(--border)' },
    info: { bg: 'var(--info-bg)', fg: 'var(--info)', border: 'var(--info)' },
    warning: { bg: 'var(--warning-bg)', fg: 'var(--warning)', border: 'var(--warning)' },
    success: { bg: 'var(--success-bg)', fg: 'var(--success)', border: 'var(--success)' },
    danger: { bg: 'var(--danger-bg)', fg: 'var(--danger)', border: 'var(--danger)' },
  }[tone]

  return (
    <span
      style={{
        display: 'inline-block',
        padding: '0.1rem 0.5rem',
        borderRadius: '999px',
        fontSize: '0.72rem',
        fontWeight: 600,
        letterSpacing: '0.02em',
        background: palette.bg,
        color: palette.fg,
        border: `1px solid ${palette.border}`,
        whiteSpace: 'nowrap',
      }}
    >
      {status.replace(/_/g, ' ')}
    </span>
  )
}

export function Notice({
  tone = 'info',
  title,
  children,
  action,
}: {
  tone?: 'info' | 'warning' | 'danger' | 'success'
  title?: string
  children?: ReactNode
  action?: ReactNode
}) {
  const palette = {
    info: { bg: 'var(--info-bg)', fg: 'var(--info)' },
    warning: { bg: 'var(--warning-bg)', fg: 'var(--warning)' },
    danger: { bg: 'var(--danger-bg)', fg: 'var(--danger)' },
    success: { bg: 'var(--success-bg)', fg: 'var(--success)' },
  }[tone]

  return (
    <div
      role={tone === 'danger' ? 'alert' : 'status'}
      style={{
        background: palette.bg,
        border: `1px solid ${palette.fg}`,
        borderRadius: 'var(--radius-sm)',
        padding: '0.65rem 0.85rem',
        display: 'flex',
        alignItems: 'flex-start',
        justifyContent: 'space-between',
        gap: '1rem',
      }}
    >
      <div style={{ minWidth: 0 }}>
        {title && <strong style={{ color: palette.fg, display: 'block' }}>{title}</strong>}
        {children && <div style={{ marginTop: title ? '0.2rem' : 0 }}>{children}</div>}
      </div>
      {action}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Forms
// ---------------------------------------------------------------------------

const controlStyle: CSSProperties = {
  width: '100%',
  padding: '0.4rem 0.55rem',
  border: '1px solid var(--border-strong)',
  borderRadius: 'var(--radius-sm)',
  background: 'var(--surface)',
}

export function Field({ label, hint, error, children }: { label: string; hint?: string; error?: string; children: ReactNode }) {
  return (
    <label style={{ display: 'block' }}>
      <span style={{ display: 'block', fontSize: '0.8rem', color: 'var(--muted)', marginBottom: '0.25rem' }}>{label}</span>
      {children}
      {hint && !error && <span style={{ display: 'block', fontSize: '0.75rem', color: 'var(--muted)', marginTop: '0.2rem' }}>{hint}</span>}
      {error && <span style={{ display: 'block', fontSize: '0.75rem', color: 'var(--danger)', marginTop: '0.2rem' }}>{error}</span>}
    </label>
  )
}

export function Input(props: React.InputHTMLAttributes<HTMLInputElement>) {
  return <input {...props} style={{ ...controlStyle, ...(props.style ?? {}) }} />
}

export function Select(props: React.SelectHTMLAttributes<HTMLSelectElement>) {
  return <select {...props} style={{ ...controlStyle, ...(props.style ?? {}) }} />
}

export function Textarea(props: React.TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return <textarea {...props} style={{ ...controlStyle, minHeight: '4rem', ...(props.style ?? {}) }} />
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
}: {
  columns: Column<T>[]
  rows: T[]
  rowKey: (row: T) => string | number
  onRowClick?: (row: T) => void
  empty?: ReactNode
  loading?: boolean
}) {
  if (loading) {
    return <div style={{ padding: '2rem', textAlign: 'center', color: 'var(--muted)' }}>Loading…</div>
  }
  if (rows.length === 0) {
    return <div style={{ padding: '2rem', textAlign: 'center', color: 'var(--muted)' }}>{empty}</div>
  }

  return (
    <div style={{ overflowX: 'auto' }}>
      <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '0.88rem' }}>
        <thead>
          <tr>
            {columns.map((column) => (
              <th
                key={column.key}
                style={{
                  textAlign: column.numeric ? 'right' : 'left',
                  padding: '0.5rem 0.6rem',
                  borderBottom: '1px solid var(--border-strong)',
                  color: 'var(--muted)',
                  fontWeight: 600,
                  fontSize: '0.78rem',
                  textTransform: 'uppercase',
                  letterSpacing: '0.03em',
                  whiteSpace: 'nowrap',
                  width: column.width,
                }}
              >
                {column.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr
              key={rowKey(row)}
              onClick={onRowClick ? () => onRowClick(row) : undefined}
              style={{ cursor: onRowClick ? 'pointer' : 'default' }}
            >
              {columns.map((column) => (
                <td
                  key={column.key}
                  className={column.numeric ? 'num' : undefined}
                  style={{ padding: '0.5rem 0.6rem', borderBottom: '1px solid var(--border)' }}
                >
                  {column.render(row)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
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
