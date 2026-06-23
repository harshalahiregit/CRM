import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { AuthProvider } from '@/context/AuthContext'
import { ThemeProvider } from '@/context/ThemeContext'
import { ProtectedRoute, GuestRoute } from '@/router/ProtectedRoute'
import AppShell from '@/components/layout/AppShell'
import { Suspense, lazy } from 'react'

// Auth pages (eager — small & needed immediately)
import LoginPage           from '@/pages/auth/LoginPage'
import RegisterPage        from '@/pages/auth/RegisterPage'
import VendorRegisterPage  from '@/pages/auth/VendorRegisterPage'
import TPVRegisterPage     from '@/pages/auth/TPVRegisterPage'
import ClientRegisterPage  from '@/pages/auth/ClientRegisterPage'
import PendingApprovalPage from '@/pages/auth/PendingApprovalPage'

// App pages (lazy — only load when navigated to)
const DashboardPage = lazy(() => import('@/pages/dashboard/DashboardPage'))

// Placeholder for modules not yet built
function ComingSoon({ name }) {
  return (
    <div className="flex flex-col items-center justify-center min-h-[60vh] gap-4 animate-fade-in">
      <div className="w-16 h-16 rounded-2xl bg-primary-600/20 flex items-center justify-center">
        <span className="text-2xl">🚧</span>
      </div>
      <h2 className="text-xl font-semibold text-white">{name}</h2>
      <p className="text-gray-400 text-sm">This module is coming soon.</p>
    </div>
  )
}

// Lazy page loader with skeleton fallback
function PageLoader() {
  return (
    <div className="space-y-4 animate-fade-in">
      <div className="skeleton h-8 w-48 rounded" />
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {[1,2,3,4].map(i => (
          <div key={i} className="card space-y-3">
            <div className="skeleton h-10 w-10 rounded-xl" />
            <div className="skeleton h-6 w-16 rounded" />
            <div className="skeleton h-4 w-24 rounded" />
          </div>
        ))}
      </div>
    </div>
  )
}

export default function App() {
  return (
    <BrowserRouter>
      <ThemeProvider>
        <AuthProvider>
          <Routes>
            {/* Root redirect */}
            <Route path="/" element={<Navigate to="/app/dashboard" replace />} />

            {/* Auth routes (guest only) */}
            <Route path="/auth">
              <Route path="login"    element={<GuestRoute><LoginPage /></GuestRoute>} />
              <Route path="register" element={<RegisterPage />} />
              <Route path="register/vendor"   element={<VendorRegisterPage />} />
              <Route path="register/tpv"      element={<TPVRegisterPage />} />
              <Route path="register/client"   element={<ClientRegisterPage />} />
              <Route path="pending-approval"  element={<PendingApprovalPage />} />
              <Route path="register/setup"    element={<GuestRoute><ComingSoon name="Subdomain Setup" /></GuestRoute>} />
              <Route path="register/payment"  element={<GuestRoute><ComingSoon name="Plan Selection" /></GuestRoute>} />
              <Route path="forgot-password"   element={<GuestRoute><ComingSoon name="Forgot Password" /></GuestRoute>} />
              <Route path="verify-email"      element={<ComingSoon name="Email Verification" />} />
            </Route>

            {/* Protected app routes */}
            <Route path="/app" element={
              <ProtectedRoute>
                <AppShell />
              </ProtectedRoute>
            }>
              <Route index element={<Navigate to="dashboard" replace />} />
              <Route path="dashboard" element={
                <Suspense fallback={<PageLoader />}><DashboardPage /></Suspense>
              } />
              <Route path="contacts"       element={<ComingSoon name="Contacts" />} />
              <Route path="contacts/new"   element={<ComingSoon name="New Contact" />} />
              <Route path="contacts/:id"   element={<ComingSoon name="Contact Detail" />} />
              <Route path="deals"          element={<ComingSoon name="Deals" />} />
              <Route path="deals/new"      element={<ComingSoon name="New Deal" />} />
              <Route path="deals/:id"      element={<ComingSoon name="Deal Detail" />} />
              <Route path="tasks"          element={<ComingSoon name="Tasks" />} />
              <Route path="tasks/new"      element={<ComingSoon name="New Task" />} />
              <Route path="projects"       element={<ComingSoon name="Projects" />} />
              <Route path="projects/new"   element={<ComingSoon name="New Project" />} />
              <Route path="invoices"       element={<ComingSoon name="Invoices" />} />
              <Route path="invoices/new"   element={<ComingSoon name="New Invoice" />} />
              <Route path="payments"       element={<ComingSoon name="Payments" />} />
              <Route path="vendors"        element={<ComingSoon name="Vendors" />} />
              <Route path="vendors/new"    element={<ComingSoon name="Add Vendor" />} />
              <Route path="purchase-orders"     element={<ComingSoon name="Purchase Orders" />} />
              <Route path="purchase-orders/new" element={<ComingSoon name="New Purchase Order" />} />
              <Route path="tickets"        element={<ComingSoon name="Tickets" />} />
              <Route path="tickets/new"    element={<ComingSoon name="New Ticket" />} />
              <Route path="reports/*"      element={<ComingSoon name="Reports" />} />
              <Route path="settings/*"     element={<ComingSoon name="Settings" />} />
            </Route>

            {/* Vendor portal */}
            <Route path="/vendor-portal/*" element={<ComingSoon name="Vendor Portal" />} />

            {/* 404 */}
            <Route path="*" element={
              <div className="flex flex-col items-center justify-center min-h-screen gap-4 bg-gray-950">
                <span className="text-6xl">404</span>
                <p className="text-gray-400">Page not found</p>
                <a href="/app/dashboard" className="btn-primary">Go to Dashboard</a>
              </div>
            } />
          </Routes>
        </AuthProvider>
      </ThemeProvider>
    </BrowserRouter>
  )
}
