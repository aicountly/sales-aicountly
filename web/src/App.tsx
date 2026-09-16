import { useEffect } from 'react'
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { useAuth } from './auth/AuthProvider'
import { SalesProvider, useSales } from './context/SalesContext'
import { AppShell } from './shell/AppShell'
import SignIn from './pages/SignIn'
import OverviewDashboard from './pages/dashboard/Overview'
import PipelineDashboard from './pages/dashboard/Pipeline'
import FulfilmentDashboard from './pages/dashboard/Fulfilment'
import CollectionsDashboard from './pages/dashboard/Collections'
import ForecastDashboard from './pages/dashboard/Forecast'
import Quotations from './pages/Quotations'
import QuotationDetail from './pages/QuotationDetail'
import QuotationEditor from './pages/QuotationEditor'
import Orders from './pages/Orders'
import OrderDetail from './pages/OrderDetail'
import OrderEditor from './pages/OrderEditor'
import Customers from './pages/Customers'
import CustomerDetail from './pages/CustomerDetail'
import Targets from './pages/Targets'
import Reports from './pages/Reports'
import { ReturnDetail, ReturnsList } from './pages/Returns'
import Approvals from './pages/Approvals'
import PriceBooks from './pages/PriceBooks'
import Territories from './pages/Territories'
import Settings from './pages/Settings'
import Access from './pages/Access'
import { Notice } from './ui'
import { initAnalytics, trackPageView } from './utils/analytics'
import './App.css'

initAnalytics()

function PageViews() {
  const location = useLocation()
  useEffect(() => {
    trackPageView(location.pathname, document.title)
  }, [location.pathname])

  return null
}

/**
 * Nothing renders until a company is chosen.
 *
 * Every endpoint in this API is company-scoped, so a screen without a scope
 * would be a screen full of 400s. Asking once, up front, is kinder than that.
 */
function RequireScope({ children }: { children: React.ReactNode }) {
  const { scope, session, loading, error } = useSales()

  if (!scope) {
    return (
      <Notice tone="info" title="Choose a company">
        Pick the company and financial year to work in, using the selector at the top of the page.
      </Notice>
    )
  }
  if (loading && !session) return <p style={{ color: 'var(--muted)' }}>Opening…</p>
  if (error) {
    return (
      <Notice tone="danger" title="Could not open that company">
        {error}
      </Notice>
    )
  }

  return <>{children}</>
}

/** Every route is scoped; wrapping once here beats remembering to do it twelve times. */
function scoped(element: React.ReactNode) {
  return <RequireScope>{element}</RequireScope>
}

export default function App() {
  const { status } = useAuth()

  if (status === 'signed-out') return <SignIn />

  if (status !== 'authenticated') {
    return (
      <main className="screen">
        <div className="panel">
          <p className="message">Signing you in…</p>
        </div>
      </main>
    )
  }

  return (
    <SalesProvider>
      <BrowserRouter>
        <PageViews />
        <Routes>
          <Route element={<AppShell />}>
            {/* The five dashboards. The Overview is the landing page; the other
                four are their own routes so a tab is a link somebody can send. */}
            <Route index element={scoped(<OverviewDashboard />)} />
            <Route path="dashboard">
              <Route index element={<Navigate to="/" replace />} />
              <Route path="pipeline" element={scoped(<PipelineDashboard />)} />
              <Route path="fulfilment" element={scoped(<FulfilmentDashboard />)} />
              <Route path="collections" element={scoped(<CollectionsDashboard />)} />
              <Route path="forecast" element={scoped(<ForecastDashboard />)} />
            </Route>
            {/* The sidebar's "Pipeline" entry is the same board, reached directly. */}
            <Route path="pipeline" element={<Navigate to="/dashboard/pipeline" replace />} />

            <Route path="quotations">
              <Route index element={scoped(<Quotations />)} />
              <Route path="new" element={scoped(<QuotationEditor />)} />
              <Route path=":id" element={scoped(<QuotationDetail />)} />
              <Route path=":id/revise" element={scoped(<QuotationEditor />)} />
            </Route>
            <Route path="orders">
              <Route index element={scoped(<Orders />)} />
              <Route path="new" element={scoped(<OrderEditor />)} />
              <Route path=":id" element={scoped(<OrderDetail />)} />
            </Route>
            <Route path="customers">
              <Route index element={scoped(<Customers />)} />
              <Route path=":id" element={scoped(<CustomerDetail />)} />
            </Route>
            <Route path="returns">
              <Route index element={scoped(<ReturnsList />)} />
              <Route path=":id" element={scoped(<ReturnDetail />)} />
            </Route>
            <Route path="approvals" element={scoped(<Approvals />)} />
            <Route path="targets" element={scoped(<Targets />)} />
            <Route path="reports" element={scoped(<Reports />)} />
            <Route path="price-books" element={scoped(<PriceBooks />)} />
            <Route path="territories" element={scoped(<Territories />)} />
            <Route path="settings" element={scoped(<Settings />)} />
            <Route path="settings/access" element={scoped(<Access />)} />
            {/* The portal callback lands here once AuthProvider has consumed the token. */}
            <Route path="auth/callback" element={<Navigate to="/" replace />} />
            <Route path="*" element={<Notice tone="warning">That page does not exist.</Notice>} />
          </Route>
        </Routes>
      </BrowserRouter>
    </SalesProvider>
  )
}
