import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api } from '../services/api'
import type { SalesOrder } from '../services/types'
import { useApi } from '../hooks/useApi'
import { useSales } from '../context/SalesContext'
import { Card, DataTable, date, money, Notice, Select, StatusBadge } from '../ui'

const STATUSES = ['', 'DRAFT', 'CONFIRMED', 'RESERVATION_PENDING', 'RESERVED', 'PARTIALLY_FULFILLED', 'FULFILLED', 'CLOSED', 'CANCELLED']

export default function Orders() {
  const navigate = useNavigate()
  const { scope } = useSales()
  const [status, setStatus] = useState('')
  const [openOnly, setOpenOnly] = useState(false)

  const { data, loading, error, reload } = useApi(
    (signal) =>
      api.list<SalesOrder>('v1/orders', { status: status || undefined, open_only: openOnly ? 1 : undefined, limit: 100 }, signal),
    [scope?.cmp_id, scope?.fy_id, scope?.bo_id, status, openOnly],
    Boolean(scope),
  )

  return (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <h1 style={{ margin: 0, fontSize: '1.3rem' }}>Sales orders</h1>

      {error && (
        <Notice tone="danger" title="Could not load orders">
          {error} <button onClick={reload}>Retry</button>
        </Notice>
      )}

      <Card
        title={`${data?.meta.total ?? 0} order${(data?.meta.total ?? 0) === 1 ? '' : 's'}`}
        action={
          <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
            <label style={{ display: 'flex', alignItems: 'center', gap: '0.35rem', fontSize: '0.85rem' }}>
              <input type="checkbox" checked={openOnly} onChange={(event) => setOpenOnly(event.target.checked)} />
              Open only
            </label>
            <Select value={status} onChange={(event) => setStatus(event.target.value)} style={{ width: '12rem' }}>
              {STATUSES.map((value) => (
                <option key={value} value={value}>
                  {value === '' ? 'All statuses' : value.replace(/_/g, ' ')}
                </option>
              ))}
            </Select>
          </div>
        }
      >
        <DataTable
          loading={loading}
          rows={data?.data ?? []}
          rowKey={(row) => row.order_id}
          onRowClick={(row) => navigate(`/orders/${row.order_id}`)}
          empty="No orders yet. Accept a quotation, or raise an order directly."
          columns={[
            {
              key: 'no',
              header: 'Number',
              render: (row) => (
                <Link to={`/orders/${row.order_id}`} onClick={(e) => e.stopPropagation()}>
                  {row.order_no}
                </Link>
              ),
            },
            { key: 'date', header: 'Date', render: (row) => date(row.order_date) },
            {
              key: 'customer',
              header: 'Customer',
              render: (row) => row.customer_name_snapshot ?? `Account ${row.customer_account_id}`,
            },
            { key: 'po', header: 'Customer PO', render: (row) => row.customer_po_ref ?? '—' },
            { key: 'committed', header: 'Committed', render: (row) => date(row.committed_date) },
            { key: 'status', header: 'Status', render: (row) => <StatusBadge status={row.status} /> },
            { key: 'total', header: 'Total', numeric: true, render: (row) => money(row.total_amount, row.currency_code) },
          ]}
        />
      </Card>
    </div>
  )
}
