import { Link } from 'react-router-dom'
import { api } from '../services/api'
import type { DashboardSummary } from '../services/types'
import { useApi } from '../hooks/useApi'
import { useSales } from '../context/SalesContext'
import { Card, DataTable, money, Notice, StatCard } from '../ui'

export default function SalesDashboard() {
  const { scope } = useSales()
  const { data, loading, error, reload } = useApi<{ data: DashboardSummary }>(
    (signal) => api.one<DashboardSummary>('v1/dashboard', undefined, signal),
    [scope?.cmp_id, scope?.fy_id, scope?.bo_id],
    Boolean(scope),
  )

  if (error) {
    return (
      <Notice tone="danger" title="Could not load the dashboard">
        {error} <button onClick={reload}>Retry</button>
      </Notice>
    )
  }

  const summary = data?.data

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <h1 style={{ margin: 0, fontSize: '1.3rem' }}>Sales</h1>

      {summary && summary.attention.stuck_commands > 0 && (
        <Notice
          tone="danger"
          title={`${summary.attention.stuck_commands} cross-app ${summary.attention.stuck_commands === 1 ? 'request has' : 'requests have'} not completed`}
        >
          Something we asked Books or Inventory to do did not finish. Open the order to see the error and retry — nothing
          is retried behind your back.
        </Notice>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(11rem, 1fr))', gap: '0.75rem' }}>
        <StatCard label="Quotations" value={loading ? '…' : (summary?.quotations.total ?? 0)} hint={`${summary?.quotations.conversion_pc ?? 0}% converted`} />
        <StatCard
          label="Awaiting approval"
          value={loading ? '…' : (summary?.quotations.awaiting_approval ?? 0)}
          tone={(summary?.quotations.awaiting_approval ?? 0) > 0 ? 'warning' : 'default'}
        />
        <StatCard label="Open orders" value={loading ? '…' : (summary?.orders.open ?? 0)} hint={`${summary?.orders.total ?? 0} in period`} />
        <StatCard
          label="Reservation pending"
          value={loading ? '…' : (summary?.orders.reservation_pending ?? 0)}
          tone={(summary?.orders.reservation_pending ?? 0) > 0 ? 'warning' : 'default'}
        />
        <StatCard label="Undelivered" value={loading ? '…' : money(summary?.backlog.undelivered_value)} hint="ordered, not yet dispatched" />
        <StatCard label="Uninvoiced" value={loading ? '…' : money(summary?.backlog.uninvoiced_value)} hint="dispatched, not yet billed" />
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(20rem, 1fr))', gap: '1rem' }}>
        <Card title="From Books, live">
          {summary?.financial.available ? (
            <div style={{ display: 'grid', gap: '0.75rem' }}>
              <StatCard
                label="Receivable"
                value={money(summary.financial.receivable_total ?? 0)}
                hint={`${summary.financial.receivable_count ?? 0} open bills`}
              />
              <p style={{ color: 'var(--muted)', fontSize: '0.8rem', margin: 0 }}>
                Read from Smart Books on this page load. Sales keeps no receivable figure of its own, so this is never
                out of date and never disagrees with the accounts.
              </p>
            </div>
          ) : (
            <Notice tone="warning" title="Books did not answer">
              {summary?.financial.reason ??
                'The financial cards need Smart Books. Everything above is from Sales and is unaffected.'}
            </Notice>
          )}
        </Card>

        <Card title="Top customers" action={<Link to="/orders">All orders</Link>}>
          <DataTable
            loading={loading}
            rows={summary?.top_customers ?? []}
            rowKey={(row) => row.customer_account_id}
            empty="No orders in this period."
            columns={[
              {
                key: 'name',
                header: 'Customer',
                render: (row) => row.customer_name_snapshot ?? `Account ${row.customer_account_id}`,
              },
              { key: 'orders', header: 'Orders', numeric: true, render: (row) => row.order_count },
              { key: 'value', header: 'Value', numeric: true, render: (row) => money(row.total_value) },
            ]}
          />
        </Card>
      </div>
    </div>
  )
}
