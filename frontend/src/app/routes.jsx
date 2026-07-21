import { Routes, Route, Navigate } from 'react-router-dom'
import { ProtectedRoute, GuestRoute } from '@/router/ProtectedRoute'
import AppShell from '@/components/layout/AppShell'
import { Suspense, lazy } from 'react'

// Auth pages (eager)
import LoginPage from '@/pages/auth/LoginPage'
import RegisterPage from '@/pages/auth/RegisterPage'
import VendorRegisterPage from '@/pages/auth/VendorRegisterPage'
import TPVRegisterPage from '@/pages/auth/TPVRegisterPage'
import ClientRegisterPage from '@/pages/auth/ClientRegisterPage'
import PendingApprovalPage from '@/pages/auth/PendingApprovalPage'

// Core pages (lazy)
const DashboardPage = lazy(() => import('@/pages/dashboard/DashboardPage'))
const ModulesPage = lazy(() => import('@/pages/modules/ModulesPage'))

// Company Portal (lazy)
const CompanyRegisterPage = lazy(() => import('@/pages/auth/CompanyRegisterPage'))
const CompanyPortalShell = lazy(() => import('@/pages/company-portal/CompanyPortalShell'))
const CompanyDashboard = lazy(() => import('@/pages/company-portal/CompanyDashboard'))
const CompanyHiringRequests = lazy(() => import('@/pages/company-portal/CompanyHiringRequests'))
const CompanyRequestDetail = lazy(() => import('@/pages/company-portal/CompanyRequestDetail'))
const CompanyProfile = lazy(() => import('@/pages/company-portal/CompanyProfile'))
const CompanySettings = lazy(() => import('@/pages/company-portal/CompanySettings'))
const CompanyReports = lazy(() => import('@/pages/company-portal/CompanyReports'))

// Public Career Portal (lazy, no auth)
const CareerPortal = lazy(() => import('@/pages/careers/CareerPortal'))
const CareerJobDetails = lazy(() => import('@/pages/careers/CareerJobDetails'))
const OnboardingPortal = lazy(() => import('@/pages/careers/OnboardingPortal'))
const OfferPortal = lazy(() => import('@/pages/careers/OfferPortal'))
const HiringRequestPortal = lazy(() => import('@/pages/careers/HiringRequestPortal'))
const ClientTrackingPortal = lazy(() => import('@/pages/careers/ClientTrackingPortal'))

// HR Module (lazy)
const HRLayout = lazy(() => import('@/modules/hr/HRLayout'))
const HRDashboard = lazy(() => import('@/modules/hr/pages/HRDashboard'))
const ManpowerRequests = lazy(() => import('@/modules/hr/pages/ManpowerRequests'))
const JobPostings = lazy(() => import('@/modules/hr/pages/JobPostings'))
const JobWorkspace = lazy(() => import('@/modules/hr/pages/JobWorkspace'))
const Candidates = lazy(() => import('@/modules/hr/pages/Candidates'))
const CandidateProfile = lazy(() => import('@/modules/hr/pages/CandidateProfile'))
const Interviews = lazy(() => import('@/modules/hr/pages/Interviews'))
const InterviewDetail = lazy(() => import('@/modules/hr/pages/InterviewDetail'))
const OfferLetters = lazy(() => import('@/modules/hr/pages/OfferLetters'))
const Onboarding = lazy(() => import('@/modules/hr/pages/Onboarding'))
const Employees = lazy(() => import('@/modules/hr/pages/Employees'))
const EmployeeProfile = lazy(() => import('@/modules/hr/pages/EmployeeProfile'))
const ExitInterview   = lazy(() => import('@/modules/hr/pages/ExitInterview'))
const RecruitmentServices = lazy(() => import('@/modules/hr/pages/RecruitmentServices'))
const RecruiterWorkspace = lazy(() => import('@/modules/hr/pages/RecruiterWorkspace'))
const CompanyApprovals = lazy(() => import('@/modules/hr/pages/CompanyApprovals'))
const Attendance = lazy(() => import('@/modules/hr/pages/Attendance'))
const EmployeeOnboarding = lazy(() => import('@/modules/hr/pages/EmployeeOnboarding'))
const EmployeeOnboardingDetail = lazy(() => import('@/modules/hr/pages/EmployeeOnboardingDetail'))

// Admin Module (lazy)
const StaffManagement = lazy(() => import('@/pages/admin/StaffManagementPage'))

// Sales Module (lazy)
const SalesLayout = lazy(() => import('@/modules/sales/SalesLayout'))
const SalesDashboard = lazy(() => import('@/modules/sales/pages/SalesDashboard'))
const Proposals = lazy(() => import('@/modules/sales/pages/Proposals'))
const Estimates = lazy(() => import('@/modules/sales/pages/Estimates'))
const SalesInvoices = lazy(() => import('@/modules/sales/pages/Invoices'))
const DeliveryNotes = lazy(() => import('@/modules/sales/pages/DeliveryNotes'))
const SalesPayments = lazy(() => import('@/modules/sales/pages/Payments'))
const CreditNotes = lazy(() => import('@/modules/sales/pages/CreditNotes'))
const SalesItems = lazy(() => import('@/modules/sales/pages/Items'))
const ProposalDetail = lazy(() => import('@/modules/sales/pages/ProposalDetail'))
const InvoiceDetail = lazy(() => import('@/modules/sales/pages/InvoiceDetail'))
const EstimateDetail = lazy(() => import('@/modules/sales/pages/EstimateDetail'))
const Leads = lazy(() => import('@/modules/sales/pages/Leads'))

// Purchase Module (lazy) — pages land here as they're built
const PurchaseLayout = lazy(() => import('@/modules/purchase/PurchaseLayout'))

const PurchaseRequests = lazy(() => import('@/modules/purchase/pages/PurchaseRequests'))
const PurchaseOrders = lazy(() => import('@/modules/purchase/pages/PurchaseOrders'))
const PurchaseInvoices = lazy(() => import('@/modules/purchase/pages/PurchaseInvoices'))
const PurchaseDashboard = lazy(() => import('@/modules/purchase/pages/PurchaseDashboard'))
const PurchaseDebitNotes = lazy(() => import('@/modules/purchase/pages/PurchaseDebitNotes'))
const PurchaseRfqs = lazy(() => import('@/modules/purchase/pages/PurchaseRfqs'))
const PurchaseRfqDetail = lazy(() => import('@/modules/purchase/pages/PurchaseRfqDetail'))
const PurchaseContracts = lazy(() => import('@/modules/purchase/pages/PurchaseContracts'))
const PurchaseContractDetail = lazy(() => import('@/modules/purchase/pages/PurchaseContractDetail'))
const PurchaseCatalog = lazy(() => import('@/modules/purchase/pages/PurchaseCatalog'))

// TPV Module (lazy) — pages land here as they're built
const TPVLayout = lazy(() => import('@/modules/tpv/TPVLayout'))
const TpvVendors = lazy(() => import('@/modules/tpv/pages/TpvVendors'))
const TpvVendorDetail = lazy(() => import('@/modules/tpv/pages/TpvVendorDetail'))
const TpvOnboardings = lazy(() => import('@/modules/tpv/pages/TpvOnboardings'))
const TpvOnboardingWizard = lazy(() => import('@/modules/tpv/pages/TpvOnboardingWizard'))
const TpvWorkers = lazy(() => import('@/modules/tpv/pages/TpvWorkers'))
const TpvWorkerWizard = lazy(() => import('@/modules/tpv/pages/TpvWorkerWizard'))
const TpvGateLog = lazy(() => import('@/modules/tpv/pages/TpvGateLog'))
const TpvStrikes = lazy(() => import('@/modules/tpv/pages/TpvStrikes'))

// Public site-gate screen (lazy, no auth — the badge QR token is the credential)
const GateScan = lazy(() => import('@/pages/gate/GateScan'))
const ChecklistFill = lazy(() => import('@/pages/checklist/ChecklistFill'))

// Compliance engine — generic, not TPV-owned (mirrors App\*\Compliance). Mounted
// under the TPV rail because TPV is its first consumer; HR's exit checklists
// will mount the same pages.
const ComplianceWorkspace = lazy(() => import('@/modules/compliance/pages/ComplianceWorkspace'))
const TemplateBuilder = lazy(() => import('@/modules/compliance/pages/TemplateBuilder'))
const ChecklistDetail = lazy(() => import('@/modules/compliance/pages/ChecklistDetail'))
// Kickoff meetings — a shared entity (modules/shared), TPV is its first consumer.
// Built polymorphically so Shivam's Project&Task module can attach without a
// second table.
const KickoffMeetings = lazy(() => import('@/modules/shared/pages/KickoffMeetings'))
const KickoffMeetingDetail = lazy(() => import('@/modules/shared/pages/KickoffMeetingDetail'))
const KickoffAck = lazy(() => import('@/pages/kickoff/KickoffAck'))

// Vendor Self-Service Portal — its own chrome, gated to vendor roles. Every
// endpoint resolves the vendor from the token (EnsureVendorPortalAccess).
const VendorPortalShell = lazy(() => import('@/pages/vendor-portal/VendorPortalShell'))
const PortalDashboard = lazy(() => import('@/pages/vendor-portal/PortalDashboard'))
const PortalDocuments = lazy(() => import('@/pages/vendor-portal/PortalDocuments'))
const PortalOrderDetail = lazy(() => import('@/pages/vendor-portal/PortalOrderDetail'))
const PortalInvoiceDetail = lazy(() => import('@/pages/vendor-portal/PortalInvoiceDetail'))

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
        {[1, 2, 3, 4].map(i => (
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

export default function AppRoutes() {
  return (
    <Routes>
      <Route path="/" element={<Navigate to="/app/dashboard" replace />} />

      {/* Auth routes */}
      <Route path="/auth">
        <Route path="login" element={<GuestRoute><LoginPage /></GuestRoute>} />
        <Route path="register" element={<RegisterPage />} />
        <Route path="register/vendor" element={<VendorRegisterPage />} />
        <Route path="register/tpv" element={<TPVRegisterPage />} />
        <Route path="register/client" element={<ClientRegisterPage />} />
        <Route path="register/company" element={<S><CompanyRegisterPage /></S>} />
        <Route path="pending-approval" element={<PendingApprovalPage />} />
        <Route path="forgot-password" element={<GuestRoute><ComingSoon name="Forgot Password" /></GuestRoute>} />
        <Route path="verify-email" element={<ComingSoon name="Email Verification" />} />
      </Route>

      {/* Public Career Portal (no auth — tenant from :slug) */}
      <Route path="/careers/:slug" element={<S><CareerPortal /></S>} />
      <Route path="/careers/:slug/jobs/:id" element={<S><CareerJobDetails /></S>} />
      <Route path="/onboarding/:token" element={<S><OnboardingPortal /></S>} />
      <Route path="/offer/:token" element={<S><OfferPortal /></S>} />
      <Route path="/hiring-request/:token" element={<S><HiringRequestPortal /></S>} />
      <Route path="/client-tracking/:token" element={<S><ClientTrackingPortal /></S>} />

      {/* Public site gate (no auth — this is the URL a worker's badge QR encodes) */}
      <Route path="/scan/:token" element={<S><GateScan /></S>} />
      {/* Public by design — a vendor's site supervisor has no login. The 48-char
          token is the credential, exactly like the gate badge above. */}
      <Route path="/checklist/:token" element={<S><ChecklistFill /></S>} />
      {/* Public kickoff-minutes acknowledgement — no auth, token is the credential. */}
      <Route path="/kickoff/ack/:token" element={<S><KickoffAck /></S>} />

      {/* Protected app routes */}
      <Route path="/app" element={<ProtectedRoute><AppShell /></ProtectedRoute>}>
        <Route index element={<Navigate to="dashboard" replace />} />
        <Route path="dashboard" element={<S><DashboardPage /></S>} />
        <Route path="modules" element={<S><ModulesPage /></S>} />

        {/* ADMIN MODULE (Admin Only) */}
        <Route path="admin">
          <Route path="staff" element={<S><StaffManagement /></S>} />
        </Route>

        {/* HR MODULE */}
        <Route path="hr" element={<S><HRLayout /></S>}>
          <Route index element={<Navigate to="dashboard" replace />} />
          <Route path="dashboard" element={<S><HRDashboard /></S>} />
          <Route path="manpower-requests" element={<S><ManpowerRequests /></S>} />
          <Route path="jobs" element={<S><JobPostings /></S>} />
          <Route path="jobs/:id" element={<S><JobWorkspace /></S>} />
          <Route path="candidates" element={<S><Candidates /></S>} />
          <Route path="candidates/:id" element={<S><CandidateProfile /></S>} />
          <Route path="interviews" element={<S><Interviews /></S>} />
          <Route path="interviews/:id" element={<S><InterviewDetail /></S>} />
          <Route path="offers" element={<S><OfferLetters /></S>} />
          <Route path="onboarding" element={<S><Onboarding /></S>} />
          <Route path="employees" element={<S><Employees /></S>} />
          <Route path="employees/:id" element={<S><EmployeeProfile /></S>} />
          <Route path="employees/:id/exit-interview" element={<S><ExitInterview /></S>} />
          <Route path="recruitment-services" element={<S><RecruitmentServices /></S>} />
          <Route path="recruiter-workspace" element={<S><RecruiterWorkspace /></S>} />
          <Route path="company-approvals" element={<S><CompanyApprovals /></S>} />
          <Route path="attendance" element={<S><Attendance /></S>} />
          <Route path="employee-onboarding" element={<S><EmployeeOnboarding /></S>} />
          <Route path="employee-onboarding/:id" element={<S><EmployeeOnboardingDetail /></S>} />
        </Route>

        {/* SALES MODULE */}
        <Route path="sales" element={<S><SalesLayout /></S>}>
          <Route index element={<Navigate to="dashboard" replace />} />
          <Route path="dashboard" element={<S><SalesDashboard /></S>} />
          <Route path="proposals" element={<S><Proposals /></S>} />
          <Route path="estimates" element={<S><Estimates /></S>} />
          <Route path="invoices" element={<S><SalesInvoices /></S>} />
          <Route path="delivery-notes" element={<S><DeliveryNotes /></S>} />
          <Route path="payments" element={<S><SalesPayments /></S>} />
          <Route path="credit-notes" element={<S><CreditNotes /></S>} />
          <Route path="items" element={<S><SalesItems /></S>} />
          <Route path="proposals/:id" element={<S><ProposalDetail /></S>} />
          <Route path="invoices/:id" element={<S><InvoiceDetail /></S>} />
          <Route path="estimates/:id" element={<S><EstimateDetail /></S>} />
          <Route path="leads" element={<S><Leads /></S>} />
        </Route>

        {/* PURCHASE MODULE */}
        <Route path="purchase" element={<S><PurchaseLayout /></S>}>
          <Route index element={<Navigate to="dashboard" replace />} />
          <Route path="dashboard" element={<S><PurchaseDashboard /></S>} />
          <Route path="requests" element={<S><PurchaseRequests /></S>} />
          <Route path="quotations" element={<S><PurchaseRfqs /></S>} />
          <Route path="quotations/:id" element={<S><PurchaseRfqDetail /></S>} />
          <Route path="orders" element={<S><PurchaseOrders /></S>} />
          <Route path="goods-received" element={<ComingSoon name="Goods Received" />} />
          <Route path="invoices" element={<S><PurchaseInvoices /></S>} />
          <Route path="debit-notes" element={<S><PurchaseDebitNotes /></S>} />
          <Route path="contracts" element={<S><PurchaseContracts /></S>} />
          <Route path="contracts/:id" element={<S><PurchaseContractDetail /></S>} />
          <Route path="catalog" element={<S><PurchaseCatalog /></S>} />
          {/* Sidebar tabs pending dedicated pages — placeholders keep nav intact */}
          <Route path="vendors" element={<ComingSoon name="Purchase Vendors" />} />
          <Route path="vendor-items" element={<ComingSoon name="Vendor Items" />} />
          <Route path="order-returns" element={<ComingSoon name="Order Returns" />} />
          <Route path="reports" element={<ComingSoon name="Purchase Reports" />} />
          <Route path="settings" element={<ComingSoon name="Purchase Settings" />} />
        </Route>

        {/* TPV MODULE */}
        <Route path="tpv" element={<S><TPVLayout /></S>}>
          <Route index element={<Navigate to="dashboard" replace />} />
          <Route path="dashboard" element={<S><TpvVendors /></S>} />
          <Route path="view/:id" element={<S><TpvVendorDetail /></S>} />
          <Route path="kickoff" element={<S><KickoffMeetings /></S>} />
          <Route path="kickoff/:id" element={<S><KickoffMeetingDetail /></S>} />
          <Route path="onboarding" element={<S><TpvOnboardings /></S>} />
          <Route path="onboarding/:id" element={<S><TpvOnboardingWizard /></S>} />
          <Route path="documents" element={<ComingSoon name="Vendor Documents" />} />
          <Route path="workforce" element={<S><TpvWorkers /></S>} />
          <Route path="workforce/:id" element={<S><TpvWorkerWizard /></S>} />
          <Route path="compliance" element={<S><ComplianceWorkspace /></S>} />
          <Route path="compliance/templates/:id" element={<S><TemplateBuilder /></S>} />
          <Route path="compliance/checklists/:id" element={<S><ChecklistDetail /></S>} />
          <Route path="gate-log" element={<S><TpvGateLog /></S>} />
          <Route path="strikes" element={<S><TpvStrikes /></S>} />
        </Route>

        {/* Core CRM */}
        <Route path="contacts" element={<ComingSoon name="Contacts" />} />
        <Route path="contacts/new" element={<ComingSoon name="New Contact" />} />
        <Route path="contacts/:id" element={<ComingSoon name="Contact Detail" />} />
        <Route path="deals" element={<ComingSoon name="Deals" />} />
        <Route path="deals/new" element={<ComingSoon name="New Deal" />} />
        <Route path="deals/:id" element={<ComingSoon name="Deal Detail" />} />
        <Route path="tasks" element={<ComingSoon name="Tasks" />} />
        <Route path="projects" element={<ComingSoon name="Projects" />} />
        <Route path="invoices" element={<ComingSoon name="Invoices" />} />
        <Route path="vendors" element={<ComingSoon name="Vendors" />} />
        <Route path="tickets" element={<ComingSoon name="Tickets" />} />
        <Route path="reports/*" element={<ComingSoon name="Reports" />} />
        <Route path="settings/*" element={<ComingSoon name="Settings" />} />
      </Route>

      {/* Vendor Self-Service Portal — vendor / third_party_vendor only. */}
      <Route path="/vendor-portal" element={
        <ProtectedRoute roles={['vendor', 'third_party_vendor']}><S><VendorPortalShell /></S></ProtectedRoute>
      }>
        <Route index element={<Navigate to="dashboard" replace />} />
        <Route path="dashboard" element={<S><PortalDashboard /></S>} />
        <Route path="documents" element={<S><PortalDocuments /></S>} />
        <Route path="orders/:id" element={<S><PortalOrderDetail /></S>} />
        <Route path="invoices/:id" element={<S><PortalInvoiceDetail /></S>} />
      </Route>

      {/* External Company Portal — company accounts only. Sprint 1: Dashboard live;
          remaining tabs land in later sprints (placeholder for now). */}
      <Route path="/company-portal" element={
        <ProtectedRoute roles={['company']}><S><CompanyPortalShell /></S></ProtectedRoute>
      }>
        <Route index element={<Navigate to="dashboard" replace />} />
        <Route path="dashboard" element={<S><CompanyDashboard /></S>} />
        <Route path="hiring-requests" element={<S><CompanyHiringRequests /></S>} />
        <Route path="hiring-requests/:id" element={<S><CompanyRequestDetail /></S>} />
        <Route path="reports" element={<S><CompanyReports /></S>} />
        <Route path="profile" element={<S><CompanyProfile /></S>} />
        <Route path="settings" element={<S><CompanySettings /></S>} />
      </Route>

      <Route path="*" element={
        <div className="flex flex-col items-center justify-center min-h-screen gap-4" style={{ background: 'var(--bg-global)' }}>
          <span className="text-gradient font-black" style={{ fontSize: '5rem' }}>404</span>
          <p style={{ color: 'var(--text-muted)' }}>Page not found</p>
          <a href="/app/dashboard" className="btn-3d">Go to Dashboard</a>
        </div>
      } />
    </Routes>
  )
}
