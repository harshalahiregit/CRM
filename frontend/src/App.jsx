import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { AuthProvider } from '@/context/AuthContext'
import { ThemeProvider } from '@/context/ThemeContext'
import { ProtectedRoute, GuestRoute } from '@/router/ProtectedRoute'
import AppShell from '@/components/layout/AppShell'
import { Suspense, lazy } from 'react'

// Auth pages (eager)
import LoginPage           from '@/pages/auth/LoginPage'
import RegisterPage        from '@/pages/auth/RegisterPage'
import VendorRegisterPage  from '@/pages/auth/VendorRegisterPage'
import TPVRegisterPage     from '@/pages/auth/TPVRegisterPage'
import ClientRegisterPage  from '@/pages/auth/ClientRegisterPage'
import PendingApprovalPage from '@/pages/auth/PendingApprovalPage'

// Core pages (lazy)
const DashboardPage = lazy(() => import('@/pages/dashboard/DashboardPage'))
const ModulesPage   = lazy(() => import('@/pages/modules/ModulesPage'))

// HR Module (lazy)
const HRLayout         = lazy(() => import('@/modules/hr/HRLayout'))
const HRDashboard      = lazy(() => import('@/modules/hr/pages/HRDashboard'))
const ManpowerRequests = lazy(() => import('@/modules/hr/pages/ManpowerRequests'))
const JobPostings      = lazy(() => import('@/modules/hr/pages/JobPostings'))
const Candidates       = lazy(() => import('@/modules/hr/pages/Candidates'))
const CandidateProfile = lazy(() => import('@/modules/hr/pages/CandidateProfile'))
const Interviews       = lazy(() => import('@/modules/hr/pages/Interviews'))
const OfferLetters     = lazy(() => import('@/modules/hr/pages/OfferLetters'))
const Onboarding       = lazy(() => import('@/modules/hr/pages/Onboarding'))
const Employees        = lazy(() => import('@/modules/hr/pages/Employees'))

// Sales Module (lazy)
const SalesLayout      = lazy(() => import('@/modules/sales/SalesLayout'))
const SalesDashboard   = lazy(() => import('@/modules/sales/pages/SalesDashboard'))
const Proposals        = lazy(() => import('@/modules/sales/pages/Proposals'))
const Estimates        = lazy(() => import('@/modules/sales/pages/Estimates'))
const SalesInvoices    = lazy(() => import('@/modules/sales/pages/Invoices'))
const DeliveryNotes    = lazy(() => import('@/modules/sales/pages/DeliveryNotes'))
const SalesPayments    = lazy(() => import('@/modules/sales/pages/Payments'))
const CreditNotes      = lazy(() => import('@/modules/sales/pages/CreditNotes'))
const SalesItems       = lazy(() => import('@/modules/sales/pages/Items'))
const ProposalDetail   = lazy(() => import('@/modules/sales/pages/ProposalDetail'))
const InvoiceDetail    = lazy(() => import('@/modules/sales/pages/InvoiceDetail'))
const EstimateDetail   = lazy(() => import('@/modules/sales/pages/EstimateDetail'))

function ComingSoon({ name }) {
  return (
    <div className="flex flex-col items-center justify-center min-h-[55vh] gap-4 animate-fade-in">
      <div
        className="w-16 h-16 rounded-3xl flex items-center justify-center text-2xl"
        style={{ background: 'linear-gradient(135deg,rgba(124,58,237,0.15),rgba(91,33,182,0.1))', border: '1px solid rgba(124,58,237,0.2)' }}
      >
        🚧
      </div>
      <div className="text-center">
        <h2 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>{name}</h2>
        <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>This module is under construction.</p>
      </div>
    </div>
  )
}

function PageLoader() {
  return (
    <div className="space-y-4 animate-fade-in p-2">
      <div className="skeleton h-8 w-48 rounded-xl" style={{ background: 'var(--border)' }} />
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {[1,2,3,4].map(i => (
          <div key={i} className="kpi-3d space-y-3">
            <div className="skeleton w-11 h-11 rounded-2xl" style={{ background: 'var(--border)' }} />
            <div className="skeleton h-7 w-16 rounded-xl" style={{ background: 'var(--border)' }} />
            <div className="skeleton h-3 w-24 rounded-lg" style={{ background: 'var(--border)' }} />
          </div>
        ))}
      </div>
    </div>
  )
}

const S = ({ children }) => <Suspense fallback={<PageLoader />}>{children}</Suspense>

export default function App() {
  return (
    <BrowserRouter>
      <ThemeProvider>
        <AuthProvider>
          <Routes>
            <Route path="/" element={<Navigate to="/app/dashboard" replace />} />

            {/* Auth routes */}
            <Route path="/auth">
              <Route path="login"    element={<GuestRoute><LoginPage /></GuestRoute>} />
              <Route path="register" element={<RegisterPage />} />
              <Route path="register/vendor"  element={<VendorRegisterPage />} />
              <Route path="register/tpv"     element={<TPVRegisterPage />} />
              <Route path="register/client"  element={<ClientRegisterPage />} />
              <Route path="pending-approval" element={<PendingApprovalPage />} />
              <Route path="forgot-password"  element={<GuestRoute><ComingSoon name="Forgot Password" /></GuestRoute>} />
              <Route path="verify-email"     element={<ComingSoon name="Email Verification" />} />
            </Route>

            {/* Protected app routes */}
            <Route path="/app" element={<ProtectedRoute><AppShell /></ProtectedRoute>}>
              <Route index element={<Navigate to="dashboard" replace />} />
              <Route path="dashboard" element={<S><DashboardPage /></S>} />
              <Route path="modules"   element={<S><ModulesPage /></S>} />

              {/* HR MODULE */}
              <Route path="hr" element={<S><HRLayout /></S>}>
                <Route index element={<Navigate to="dashboard" replace />} />
                <Route path="dashboard"         element={<S><HRDashboard /></S>} />
                <Route path="manpower-requests" element={<S><ManpowerRequests /></S>} />
                <Route path="jobs"              element={<S><JobPostings /></S>} />
                <Route path="candidates"        element={<S><Candidates /></S>} />
                <Route path="candidates/:id"    element={<S><CandidateProfile /></S>} />
                <Route path="interviews"        element={<S><Interviews /></S>} />
                <Route path="offers"            element={<S><OfferLetters /></S>} />
                <Route path="onboarding"        element={<S><Onboarding /></S>} />
                <Route path="employees"         element={<S><Employees /></S>} />
              </Route>

              {/* SALES MODULE */}
              <Route path="sales" element={<S><SalesLayout /></S>}>
                <Route index element={<Navigate to="dashboard" replace />} />
                <Route path="dashboard"      element={<S><SalesDashboard /></S>} />
                <Route path="proposals"      element={<S><Proposals /></S>} />
                <Route path="estimates"      element={<S><Estimates /></S>} />
                <Route path="invoices"       element={<S><SalesInvoices /></S>} />
                <Route path="delivery-notes" element={<S><DeliveryNotes /></S>} />
                <Route path="payments"       element={<S><SalesPayments /></S>} />
                <Route path="credit-notes"   element={<S><CreditNotes /></S>} />
                <Route path="items"             element={<S><SalesItems /></S>} />
                <Route path="proposals/:id"    element={<S><ProposalDetail /></S>} />
                <Route path="invoices/:id"     element={<S><InvoiceDetail /></S>} />
                <Route path="estimates/:id"    element={<S><EstimateDetail /></S>} />
              </Route>

              {/* Core CRM */}
              <Route path="contacts"     element={<ComingSoon name="Contacts" />} />
              <Route path="contacts/new" element={<ComingSoon name="New Contact" />} />
              <Route path="contacts/:id" element={<ComingSoon name="Contact Detail" />} />
              <Route path="deals"        element={<ComingSoon name="Deals" />} />
              <Route path="deals/new"    element={<ComingSoon name="New Deal" />} />
              <Route path="deals/:id"    element={<ComingSoon name="Deal Detail" />} />
              <Route path="tasks"        element={<ComingSoon name="Tasks" />} />
              <Route path="projects"     element={<ComingSoon name="Projects" />} />
              <Route path="invoices"     element={<ComingSoon name="Invoices" />} />
              <Route path="vendors"      element={<ComingSoon name="Vendors" />} />
              <Route path="tickets"      element={<ComingSoon name="Tickets" />} />
              <Route path="reports/*"    element={<ComingSoon name="Reports" />} />
              <Route path="settings/*"   element={<ComingSoon name="Settings" />} />
            </Route>

            <Route path="/vendor-portal/*" element={<ComingSoon name="Vendor Portal" />} />

            <Route path="*" element={
              <div className="flex flex-col items-center justify-center min-h-screen gap-4" style={{ background: 'var(--bg-global)' }}>
                <span className="text-gradient font-black" style={{ fontSize: '5rem' }}>404</span>
                <p style={{ color: 'var(--text-muted)' }}>Page not found</p>
                <a href="/app/dashboard" className="btn-3d">Go to Dashboard</a>
              </div>
            } />
          </Routes>
        </AuthProvider>
      </ThemeProvider>
    </BrowserRouter>
  )
}