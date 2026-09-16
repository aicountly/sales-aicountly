import type { IntegrationCommand } from '../services/types'
import { Button, Notice, StatusBadge } from '../ui'

/**
 * What this product has asked Books and Inventory to do, and how it went.
 *
 * This strip is the visible half of an architecture with no reconciliation job.
 * Nothing sweeps failures up quietly at 2am: a request that did not complete is
 * shown here, on the document it belongs to, with the error that stopped it and
 * a Retry for the person who can actually do something about it.
 *
 * FAILED and BLOCKED are different on purpose. FAILED means the other product
 * could not be reached, so the same request may be sent again — on the same
 * idempotency key, which is what stops a retry becoming a second invoice.
 * BLOCKED means it was reached and refused: retrying will fail identically
 * until something changes, so no Retry is offered.
 */
export function CommandStrip({
  commands,
  onRetry,
  busy,
}: {
  commands: IntegrationCommand[]
  onRetry?: (command: IntegrationCommand) => void
  busy?: boolean
}) {
  const unresolved = commands.filter((command) => command.status !== 'COMPLETED')
  if (unresolved.length === 0) return null

  return (
    <div style={{ display: 'grid', gap: '0.6rem' }}>
      {unresolved.map((command) => (
        <Notice
          key={command.command_id}
          tone={command.status === 'BLOCKED' ? 'danger' : command.status === 'FAILED' ? 'danger' : 'warning'}
          title={describe(command)}
          action={
            command.status === 'FAILED' && onRetry ? (
              <Button tone="secondary" disabled={busy} onClick={() => onRetry(command)}>
                Retry
              </Button>
            ) : undefined
          }
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>
            <StatusBadge status={command.status} />
            <span style={{ color: 'var(--muted)', fontSize: '0.82rem' }}>
              {command.attempts} attempt{command.attempts === 1 ? '' : 's'}
            </span>
          </div>
          {command.last_error && (
            <p style={{ margin: '0.4rem 0 0', fontSize: '0.85rem' }}>{command.last_error}</p>
          )}
          {command.status === 'BLOCKED' && (
            <p style={{ margin: '0.4rem 0 0', fontSize: '0.82rem', color: 'var(--muted)' }}>
              This was refused rather than missed, so retrying it unchanged will fail the same way.
            </p>
          )}
        </Notice>
      ))}
    </div>
  )
}

function describe(command: IntegrationCommand): string {
  const target = command.target_service === 'books' ? 'Smart Books' : command.target_service === 'inventory' ? 'Inventory' : command.target_service
  const what: Record<string, string> = {
    'sales.order.reserve': 'Reserving stock',
    'sales.order.issue': 'Dispatching stock',
    'sales.invoice.request': 'Raising the invoice',
    'sales.return.receipt': 'Receiving the return',
    'sales.return.credit_note': 'Raising the credit note',
  }

  return `${what[command.command_type] ?? command.command_type} in ${target}`
}
