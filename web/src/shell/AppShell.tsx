import { NavLink, Outlet } from 'react-router-dom'
import {
  AlertTriangle,
  BadgeIndianRupee,
  FileText,
  LayoutDashboard,
  LogOut,
  Map,
  PackageCheck,
  RotateCcw,
  Settings as SettingsIcon,
} from 'lucide-react'
import { useAuth } from '../auth/AuthProvider'
import { AppLauncher } from '../components/AppLauncher'
import { useSales } from '../context/SalesContext'
import { CompanyPicker } from './CompanyPicker'

const NAV = [
  { to: '/', label: 'Dashboard', icon: LayoutDashboard, exact: true },
  { to: '/quotations', label: 'Quotations', icon: FileText, permission: 'quotation.view' },
  { to: '/orders', label: 'Sales Orders', icon: PackageCheck, permission: 'order.view' },
  { to: '/returns', label: 'Returns', icon: RotateCcw, permission: 'return.create' },
  { to: '/price-books', label: 'Price Books', icon: BadgeIndianRupee, permission: 'pricebook.view' },
  { to: '/territories', label: 'Territories', icon: Map, permission: 'territory.manage' },
  { to: '/approvals', label: 'Approvals', icon: AlertTriangle },
  { to: '/settings', label: 'Settings', icon: SettingsIcon },
] as const

export function AppShell() {
  const { signOut } = useAuth()
  const { session, can, scope } = useSales()

  return (
    <div style={{ display: 'flex', minHeight: '100vh' }}>
      <aside
        style={{
          width: 'var(--sidebar-w)',
          flexShrink: 0,
          borderRight: '1px solid var(--border)',
          background: 'var(--surface-2)',
          display: 'flex',
          flexDirection: 'column',
        }}
      >
        <div style={{ padding: '1rem', borderBottom: '1px solid var(--border)' }}>
          <div style={{ fontWeight: 700, fontSize: '1rem', letterSpacing: '-0.01em' }}>AICOUNTLY</div>
          <div style={{ color: 'var(--muted)', fontSize: '0.8rem' }}>Sales</div>
        </div>

        <nav style={{ padding: '0.5rem', flex: 1, overflowY: 'auto' }}>
          {NAV.filter((entry) => !('permission' in entry) || can(entry.permission as string)).map((entry) => {
            const Icon = entry.icon
            return (
              <NavLink
                key={entry.to}
                to={entry.to}
                end={'exact' in entry ? entry.exact : false}
                style={({ isActive }) => ({
                  display: 'flex',
                  alignItems: 'center',
                  gap: '0.6rem',
                  padding: '0.5rem 0.65rem',
                  marginBottom: '0.15rem',
                  borderRadius: 'var(--radius-sm)',
                  color: isActive ? 'var(--fg)' : 'var(--muted)',
                  background: isActive ? 'var(--surface)' : 'transparent',
                  fontWeight: isActive ? 600 : 400,
                  textDecoration: 'none',
                })}
              >
                <Icon size={16} aria-hidden />
                {entry.label}
              </NavLink>
            )
          })}
        </nav>

        <div style={{ padding: '0.75rem', borderTop: '1px solid var(--border)' }}>
          <div style={{ fontSize: '0.82rem', marginBottom: '0.5rem', overflow: 'hidden', textOverflow: 'ellipsis' }}>
            {session?.display_name ?? '—'}
            {session?.is_owner && (
              <span style={{ color: 'var(--muted)', fontSize: '0.75rem', display: 'block' }}>Company owner</span>
            )}
          </div>
          <button
            type="button"
            onClick={signOut}
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: '0.4rem',
              width: '100%',
              padding: '0.4rem 0.5rem',
              background: 'transparent',
              border: '1px solid var(--border-strong)',
              borderRadius: 'var(--radius-sm)',
              cursor: 'pointer',
              color: 'var(--muted)',
            }}
          >
            <LogOut size={14} aria-hidden /> Log out
          </button>
        </div>
      </aside>

      <div style={{ flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column' }}>
        <header
          style={{
            height: 'var(--header-h)',
            borderBottom: '1px solid var(--border)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            padding: '0 1rem',
            gap: '1rem',
            background: 'var(--surface)',
          }}
        >
          <CompanyPicker />
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
            {scope && (
              <span style={{ color: 'var(--muted)', fontSize: '0.8rem' }} className="num">
                Company {scope.cmp_id} · FY {scope.fy_id}
                {scope.bo_id > 0 ? ` · Branch ${scope.bo_id}` : ''}
              </span>
            )}
            <AppLauncher />
          </div>
        </header>

        <main style={{ flex: 1, padding: '1.25rem', minWidth: 0, background: 'var(--bg)' }}>
          <Outlet />
        </main>
      </div>
    </div>
  )
}
