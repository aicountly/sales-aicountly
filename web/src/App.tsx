import { useEffect } from 'react'
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { useAuth } from './auth/AuthProvider'
import { SalesProvider, useSales } from './context/SalesContext'
import { AppShell } from './shell/AppShell'
import SignIn from './pages/SignIn'
import SalesDashboard from './pages/SalesDashboard'
import Quotations from './pages/Quotations'
import QuotationDetail from './pages/QuotationDetail'
import QuotationEditor from './pages/QuotationEditor'
import Orders from './pages/Orders'
import OrderDetail from './pages/OrderDetail'
import { ReturnDetail, ReturnsList } from './pages/Returns'
import Approvals from './pages/Approvals'
import PriceBooks from './pages/PriceBooks'
import Territories from './pages/Territories'
import Settings from './pages/Settings'
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
            <Route
              index
              element={
                <RequireScope>
                  <SalesDashboard />
                </RequireScope>
              }
            />
            <Route path="quotations">
              <Route index element={<RequireScope><Quotations /></RequireScope>} />
              <Route path="new" element={<RequireScope><QuotationEditor /></RequireScope>} />
              <Route path=":id" element={<RequireScope><QuotationDetail /></RequireScope>} />
              <Route path=":id/revise" element={<RequireScope><QuotationEditor /></RequireScope>} />
            </Route>
            <Route path="orders">
              <Route index element={<RequireScope><Orders /></RequireScope>} />
              <Route path=":id" element={<RequireScope><OrderDetail /></RequireScope>} />
            </Route>
            <Route path="returns">
              <Route index element={<RequireScope><ReturnsList /></RequireScope>} />
              <Route path=":id" element={<RequireScope><ReturnDetail /></RequireScope>} />
            </Route>
            <Route path="approvals" element={<RequireScope><Approvals /></RequireScope>} />
            <Route path="price-books" element={<RequireScope><PriceBooks /></RequireScope>} />
            <Route path="territories" element={<RequireScope><Territories /></RequireScope>} />
            <Route path="settings" element={<RequireScope><Settings /></RequireScope>} />
            {/* The portal callback lands here once AuthProvider has consumed the token. */}
            <Route path="auth/callback" element={<Navigate to="/" replace />} />
            <Route path="*" element={<Notice tone="warning">That page does not exist.</Notice>} />
          </Route>
        </Routes>
      </BrowserRouter>
    </SalesProvider>
  )
}
