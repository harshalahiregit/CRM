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

// HR Module (lazy)
const HRLayout = lazy(() => import('@/modules/hr/HRLayout'))
const HRDashboard = lazy(() => import('@/modules/hr/pages/HRDashboard'))
const ManpowerRequests = lazy(() => import('@/modules/hr/pages/ManpowerRequests'))
const JobPostings = lazy(() => import('@/modules/hr/pages/JobPostings'))
const Candidates = lazy(() => import('@/modules/hr/pages/Candidates'))
const CandidateProfile = lazy(() => import('@/modules/hr/pages/CandidateProfile'))
const Interviews = lazy(() => import('@/modules/hr/pages/Interviews'))
const OfferLetters = lazy(() => import('@/modules/hr/pages/OfferLetters'))
const Onboarding = lazy(() => import('@/modules/hr/pages/Onboarding'))
const Employees = lazy(() => import('@/modules/hr/pages/Employees'))

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
const ProposalWizard = lazy(() => import('@/modules/sales/pages/ProposalWizard'))
const ProposalPortal = lazy(() => import('@/modules/sales/pages/ProposalPortal'))
const ContractDetail = lazy(() => import('@/modules/sales/pages/ContractDetail'))
const ContractPortal = lazy(() => import('@/modules/sales/pages/ContractPortal'))
const InvoiceDetail = lazy(() => import('@/modules/sales/pages/InvoiceDetail'))
const EstimateDetail = lazy(() => import('@/modules/sales/pages/EstimateDetail'))
const Leads = lazy(() => import('@/modules/sales/pages/Leads'))
const LeadDetail = lazy(() => import('@/modules/sales/pages/LeadDetail'))
const LeadGoals = lazy(() => import('@/modules/sales/pages/LeadGoals'))
const PaymentLinks = lazy(() => import('@/modules/sales/pages/PaymentLinks'))
const RetainerInvoices = lazy(() => import('@/modules/sales/pages/RetainerInvoices'))
const ProposalTemplates = lazy(() => import('@/modules/sales/pages/ProposalTemplates'))
const ProposalTemplateEditor = lazy(() => import('@/modules/sales/pages/ProposalTemplateEditor'))
const Contracts = lazy(() => import('@/modules/sales/pages/Contracts'))
const WebToLeadForms = lazy(() => import('@/modules/sales/pages/WebToLeadForms'))
const Forecast = lazy(() => import('@/modules/sales/pages/Forecast'))
const Commission = lazy(() => import('@/modules/sales/pages/Commission'))
const SettingsLayout = lazy(() => import('@/modules/settings/SettingsLayout'))
const MailSettings = lazy(() => import('@/modules/settings/pages/MailSettings'))
const CustomFieldsSettings = lazy(() => import('@/modules/settings/pages/CustomFieldsSettings'))
const CompanyFinanceSettings = lazy(() => import('@/modules/settings/pages/CompanyFinanceSettings'))
const TaxRatesSettings = lazy(() => import('@/modules/settings/pages/TaxRatesSettings'))
const ExpenseCategoriesSettings = lazy(() => import('@/modules/settings/pages/ExpenseCategoriesSettings'))
const AccountGroupsSettings = lazy(() => import('@/modules/settings/pages/AccountGroupsSettings'))
const PublicLeadForm = lazy(() => import('@/modules/sales/public/PublicLeadForm'))

// Customer Module (lazy)
const CustomerLayout = lazy(() => import('@/modules/customer/CustomerLayout'))
const Customers = lazy(() => import('@/modules/customer/pages/Customers'))
const CustomerDetail = lazy(() => import('@/modules/customer/pages/CustomerDetail'))

// Accounts Module (lazy)
const AccountsLayout = lazy(() => import('@/modules/accounts/AccountsLayout'))
const AccDashboard = lazy(() => import('@/modules/accounts/pages/Dashboard'))
const AccBills = lazy(() => import('@/modules/accounts/pages/Bills'))
const AccTransfer = lazy(() => import('@/modules/accounts/pages/Transfer'))
const ChartOfAccounts = lazy(() => import('@/modules/accounts/pages/ChartOfAccounts'))
const Vouchers = lazy(() => import('@/modules/accounts/pages/Vouchers'))
const VoucherDetail = lazy(() => import('@/modules/accounts/pages/VoucherDetail'))
const AccountsSettings = lazy(() => import('@/modules/accounts/pages/Settings'))
const AccBankAccounts = lazy(() => import('@/modules/accounts/pages/BankAccounts'))
const AccReconcile = lazy(() => import('@/modules/accounts/pages/Reconcile'))
const AccCheques = lazy(() => import('@/modules/accounts/pages/Cheques'))
const AccBudgets = lazy(() => import('@/modules/accounts/pages/Budgets'))
const AccBudgetDetail = lazy(() => import('@/modules/accounts/pages/BudgetDetail'))
const AccRegisters = lazy(() => import('@/modules/accounts/pages/Registers'))
const AccRegisterDetail = lazy(() => import('@/modules/accounts/pages/RegisterDetail'))
const AccReportsIndex = lazy(() => import('@/modules/accounts/pages/reports/ReportsIndex'))
const AccTrialBalance = lazy(() => import('@/modules/accounts/pages/reports/TrialBalance'))
const AccProfitLoss = lazy(() => import('@/modules/accounts/pages/reports/ProfitAndLoss'))
const AccBalanceSheet = lazy(() => import('@/modules/accounts/pages/reports/BalanceSheet'))
const AccLedgerStatement = lazy(() => import('@/modules/accounts/pages/reports/LedgerStatement'))
const AccGeneralLedger = lazy(() => import('@/modules/accounts/pages/reports/GeneralLedger'))
const AccDayBook = lazy(() => import('@/modules/accounts/pages/reports/DayBook'))
const AccCashFlow = lazy(() => import('@/modules/accounts/pages/reports/CashFlow'))
const AccAgeing = lazy(() => import('@/modules/accounts/pages/reports/Ageing'))
const AccGstr1 = lazy(() => import('@/modules/accounts/pages/reports/Gstr1'))
const AccGstr3b = lazy(() => import('@/modules/accounts/pages/reports/Gstr3b'))
const AccTds = lazy(() => import('@/modules/accounts/pages/reports/Tds'))

// Helpdesk Module (lazy)
const HelpdeskLayout = lazy(() => import('@/modules/helpdesk/HelpdeskLayout'))
const HelpdeskAnalytics = lazy(() => import('@/modules/helpdesk/pages/HelpdeskAnalytics'))
const TicketGrid = lazy(() => import('@/modules/helpdesk/pages/TicketGrid'))
const KnowledgeBaseHome = lazy(() => import('@/modules/helpdesk/pages/KnowledgeBaseHome'))
const KbArticleView = lazy(() => import('@/modules/helpdesk/pages/KbArticleView'))
const KbAdmin = lazy(() => import('@/modules/helpdesk/pages/KbAdmin'))
const WidgetSettings = lazy(() => import('@/modules/helpdesk/pages/WidgetSettings'))
const SupportSettings = lazy(() => import('@/modules/helpdesk/pages/SupportSettings'))
const StatusManager = lazy(() => import('@/modules/settings/pages/StatusManager'))
const TicketThread = lazy(() => import('@/modules/helpdesk/components/TicketThread'))
// Public (no-auth) Helpdesk pages
const PublicArticle = lazy(() => import('@/modules/helpdesk/public/PublicArticle'))
const PublicKb = lazy(() => import('@/modules/helpdesk/public/PublicKb'))

// Projects Module (lazy)
const ProjectList = lazy(() => import('@/modules/projects/pages/ProjectList'))
const ProjectDetail = lazy(() => import('@/modules/projects/pages/ProjectDetail'))

// Task Module (lazy)
const TaskBoard = lazy(() => import('@/modules/tasks/pages/TaskBoard'))
const TaskDetail = lazy(() => import('@/modules/tasks/pages/TaskDetail'))
const TaskOverview = lazy(() => import('@/modules/tasks/pages/TaskOverview'))

// Inventory Module (lazy)
const InventoryDashboard = lazy(() => import('@/modules/inventory/pages/InventoryDashboard'))
const InventoryProducts = lazy(() => import('@/modules/inventory/pages/ProductList'))
const InventoryProductDetail = lazy(() => import('@/modules/inventory/pages/ProductDetail'))
const InventoryWarehouses = lazy(() => import('@/modules/inventory/pages/Warehouses'))
const InventorySettings = lazy(() => import('@/modules/inventory/pages/InventorySettings'))
const InventoryVouchers = lazy(() => import('@/modules/inventory/pages/VoucherList'))
const InventoryHistory = lazy(() => import('@/modules/inventory/pages/InventoryHistory'))
const InventoryReports = lazy(() => import('@/modules/inventory/pages/InventoryReports'))
const InventoryAnalytics = lazy(() => import('@/modules/inventory/pages/InventoryAnalytics'))
const InventoryTraceability = lazy(() => import('@/modules/inventory/pages/InventoryTraceability'))
const InventoryScan = lazy(() => import('@/modules/inventory/pages/InventoryScan'))
const InventoryFulfilment = lazy(() => import('@/modules/inventory/pages/InventoryFulfilment'))
const InventoryCounts = lazy(() => import('@/modules/inventory/pages/InventoryCounts'))
const InventoryTransfers = lazy(() => import('@/modules/inventory/pages/InventoryTransfers'))
const InventoryVendors = lazy(() => import('@/modules/inventory/pages/InventoryVendors'))
const InventoryPurchaseOrders = lazy(() => import('@/modules/inventory/pages/InventoryPurchaseOrders'))
const InventoryDeadStock = lazy(() => import('@/modules/inventory/pages/InventoryDeadStock'))
const InventoryAssets = lazy(() => import('@/modules/inventory/pages/InventoryAssets'))
const InventoryRentals = lazy(() => import('@/modules/inventory/pages/InventoryRentals'))
const InventoryVmi = lazy(() => import('@/modules/inventory/pages/InventoryVmi'))
const InventoryManufacturing = lazy(() => import('@/modules/inventory/pages/InventoryManufacturing'))

// TPV (third-party vendor) module + shared Compliance/Kickoff engines
const TPVLayout = lazy(() => import('@/modules/tpv/TPVLayout'))
const TpvVendors = lazy(() => import('@/modules/tpv/pages/TpvVendors'))
const TpvVendorDetail = lazy(() => import('@/modules/tpv/pages/TpvVendorDetail'))
const TpvOnboardings = lazy(() => import('@/modules/tpv/pages/TpvOnboardings'))
const TpvOnboardingWizard = lazy(() => import('@/modules/tpv/pages/TpvOnboardingWizard'))
const TpvWorkers = lazy(() => import('@/modules/tpv/pages/TpvWorkers'))
const TpvWorkerWizard = lazy(() => import('@/modules/tpv/pages/TpvWorkerWizard'))
const TpvGateLog = lazy(() => import('@/modules/tpv/pages/TpvGateLog'))
const TpvStrikes = lazy(() => import('@/modules/tpv/pages/TpvStrikes'))
const ComplianceWorkspace = lazy(() => import('@/modules/compliance/pages/ComplianceWorkspace'))
const TemplateBuilder = lazy(() => import('@/modules/compliance/pages/TemplateBuilder'))
const ChecklistDetail = lazy(() => import('@/modules/compliance/pages/ChecklistDetail'))
const KickoffMeetings = lazy(() => import('@/modules/shared/pages/KickoffMeetings'))
const KickoffMeetingDetail = lazy(() => import('@/modules/shared/pages/KickoffMeetingDetail'))
// Public, token-authed screens (no login — the token is the credential)
const GateScan = lazy(() => import('@/pages/gate/GateScan'))
const ChecklistFill = lazy(() => import('@/pages/checklist/ChecklistFill'))
const KickoffAck = lazy(() => import('@/pages/kickoff/KickoffAck'))

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
        <Route path="pending-approval" element={<PendingApprovalPage />} />
        <Route path="forgot-password" element={<GuestRoute><ComingSoon name="Forgot Password" /></GuestRoute>} />
        <Route path="verify-email" element={<ComingSoon name="Email Verification" />} />
      </Route>

      {/* Public token-authed screens (no login — the token IS the credential) */}
      <Route path="/scan/:token" element={<S><GateScan /></S>} />
      <Route path="/checklist/:token" element={<S><ChecklistFill /></S>} />
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
          <Route path="candidates" element={<S><Candidates /></S>} />
          <Route path="candidates/:id" element={<S><CandidateProfile /></S>} />
          <Route path="interviews" element={<S><Interviews /></S>} />
          <Route path="offers" element={<S><OfferLetters /></S>} />
          <Route path="onboarding" element={<S><Onboarding /></S>} />
          <Route path="employees" element={<S><Employees /></S>} />
        </Route>

        {/* SALES MODULE */}
        <Route path="sales" element={<S><SalesLayout /></S>}>
          <Route index element={<Navigate to="dashboard" replace />} />
          <Route path="dashboard" element={<S><SalesDashboard /></S>} />
          <Route path="proposals" element={<S><Proposals /></S>} />
          <Route path="proposals/new" element={<S><ProposalWizard /></S>} />
          <Route path="proposals/:id/edit" element={<S><ProposalWizard /></S>} />
          <Route path="estimates" element={<S><Estimates docType="estimate" /></S>} />
          <Route path="proforma-invoices" element={<S><Estimates docType="proforma" /></S>} />
          <Route path="invoices" element={<S><SalesInvoices /></S>} />
          <Route path="delivery-notes" element={<S><DeliveryNotes /></S>} />
          <Route path="payments" element={<S><SalesPayments /></S>} />
          <Route path="credit-notes" element={<S><CreditNotes /></S>} />
          <Route path="items" element={<S><SalesItems /></S>} />
          <Route path="proposals/:id" element={<S><ProposalDetail /></S>} />
          <Route path="invoices/:id" element={<S><InvoiceDetail /></S>} />
          <Route path="estimates/:id" element={<S><EstimateDetail /></S>} />
          <Route path="leads" element={<S><Leads /></S>} />
          <Route path="leads/:id" element={<S><LeadDetail /></S>} />
          <Route path="lead-goals" element={<S><LeadGoals /></S>} />
          <Route path="payment-links" element={<S><PaymentLinks /></S>} />
          <Route path="retainer-invoices" element={<S><RetainerInvoices /></S>} />
          <Route path="proposal-templates" element={<S><ProposalTemplates /></S>} />
          <Route path="proposal-templates/new" element={<S><ProposalTemplateEditor /></S>} />
          <Route path="proposal-templates/:id/edit" element={<S><ProposalTemplateEditor /></S>} />
          {/* Sales Tasks retired — the Tasks module (owner: Shivam) is now the single
              Tasks feature; sales tasks are tasks with rel_type=customer/contract. */}
          <Route path="tasks" element={<Navigate to="/app/tasks" replace />} />
          <Route path="contracts" element={<S><Contracts /></S>} />
          <Route path="contracts/:id" element={<S><ContractDetail /></S>} />
          <Route path="web-to-lead" element={<S><WebToLeadForms /></S>} />
          <Route path="forecast" element={<S><Forecast /></S>} />
          <Route path="commission" element={<S><Commission /></S>} />
        </Route>

        {/* CUSTOMER MODULE */}
        <Route path="customers" element={<S><CustomerLayout /></S>}>
          <Route index element={<S><Customers /></S>} />
          <Route path=":id" element={<S><CustomerDetail /></S>} />
        </Route>

        {/* ACCOUNTS MODULE */}
        <Route path="accounts" element={<S><AccountsLayout /></S>}>
          <Route index element={<Navigate to="dashboard" replace />} />
          <Route path="dashboard" element={<S><AccDashboard /></S>} />
          <Route path="bills" element={<S><AccBills /></S>} />
          <Route path="transfer" element={<S><AccTransfer /></S>} />
          <Route path="chart-of-accounts" element={<S><ChartOfAccounts /></S>} />
          <Route path="vouchers" element={<S><Vouchers /></S>} />
          <Route path="vouchers/:id" element={<S><VoucherDetail /></S>} />
          <Route path="registers" element={<S><AccRegisters /></S>} />
          <Route path="registers/:ledgerId" element={<S><AccRegisterDetail /></S>} />
          <Route path="reports" element={<S><AccReportsIndex /></S>} />
          <Route path="reports/trial-balance" element={<S><AccTrialBalance /></S>} />
          <Route path="reports/profit-loss" element={<S><AccProfitLoss /></S>} />
          <Route path="reports/balance-sheet" element={<S><AccBalanceSheet /></S>} />
          <Route path="reports/ledger-statement" element={<S><AccLedgerStatement /></S>} />
          <Route path="reports/general-ledger" element={<S><AccGeneralLedger /></S>} />
          <Route path="reports/day-book" element={<S><AccDayBook /></S>} />
          <Route path="reports/cash-flow" element={<S><AccCashFlow /></S>} />
          <Route path="reports/ageing" element={<S><AccAgeing /></S>} />
          <Route path="reports/gstr-1" element={<S><AccGstr1 /></S>} />
          <Route path="reports/gstr-3b" element={<S><AccGstr3b /></S>} />
          <Route path="reports/tds" element={<S><AccTds /></S>} />
          <Route path="banking" element={<S><AccBankAccounts /></S>} />
          <Route path="banking/:bankId/reconcile" element={<S><AccReconcile /></S>} />
          <Route path="cheques" element={<S><AccCheques /></S>} />
          <Route path="budgets" element={<S><AccBudgets /></S>} />
          <Route path="budgets/:id" element={<S><AccBudgetDetail /></S>} />
          <Route path="settings" element={<S><AccountsSettings /></S>} />
        </Route>

        {/* HELPDESK MODULE */}
        <Route path="helpdesk" element={<S><HelpdeskLayout /></S>}>
          <Route index element={<Navigate to="analytics" replace />} />
          <Route path="analytics" element={<S><HelpdeskAnalytics /></S>} />
          <Route path="tickets" element={<S><TicketGrid /></S>} />
          <Route path="tickets/:id" element={<S><TicketThread /></S>} />
          <Route path="knowledge-base" element={<S><KnowledgeBaseHome /></S>} />
          <Route path="knowledge-base/:id" element={<S><KbArticleView /></S>} />
          <Route path="kb-admin" element={<S><KbAdmin /></S>} />
          <Route path="widget" element={<S><WidgetSettings /></S>} />
          <Route path="settings" element={<S><SupportSettings /></S>} />
        </Route>

        {/* TPV MODULE (third-party vendors) + shared Compliance/Kickoff engines */}
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
        <Route path="tasks" element={<S><TaskBoard /></S>} />
        {/* Static segment before :id so "overview" isn't parsed as a task id. */}
        <Route path="tasks/overview" element={<S><TaskOverview /></S>} />
        <Route path="tasks/:id" element={<S><TaskDetail /></S>} />
        <Route path="projects" element={<S><ProjectList /></S>} />
        <Route path="projects/:id" element={<S><ProjectDetail /></S>} />

        {/* Inventory OS — Phase 1 foundation */}
        <Route path="inventory" element={<S><InventoryDashboard /></S>} />
        <Route path="inventory/products" element={<S><InventoryProducts /></S>} />
        <Route path="inventory/products/:id" element={<S><InventoryProductDetail /></S>} />
        <Route path="inventory/warehouses" element={<S><InventoryWarehouses /></S>} />
        {/* One page serves all four document types — :type selects the config. */}
        <Route path="inventory/vouchers/:type" element={<S><InventoryVouchers /></S>} />
        <Route path="inventory/history" element={<S><InventoryHistory /></S>} />
        <Route path="inventory/reports" element={<S><InventoryReports /></S>} />
        <Route path="inventory/analytics" element={<S><InventoryAnalytics /></S>} />
        <Route path="inventory/traceability" element={<S><InventoryTraceability /></S>} />
        <Route path="inventory/scan" element={<S><InventoryScan /></S>} />
        <Route path="inventory/fulfilment" element={<S><InventoryFulfilment /></S>} />
        <Route path="inventory/counts" element={<S><InventoryCounts /></S>} />
        <Route path="inventory/transfers" element={<S><InventoryTransfers /></S>} />
        <Route path="inventory/vendors" element={<S><InventoryVendors /></S>} />
        <Route path="inventory/purchase-orders" element={<S><InventoryPurchaseOrders /></S>} />
        <Route path="inventory/dead-stock" element={<S><InventoryDeadStock /></S>} />
        <Route path="inventory/assets" element={<S><InventoryAssets /></S>} />
        <Route path="inventory/rentals" element={<S><InventoryRentals /></S>} />
        <Route path="inventory/vmi" element={<S><InventoryVmi /></S>} />
        <Route path="inventory/manufacturing" element={<S><InventoryManufacturing /></S>} />
        <Route path="inventory/settings" element={<S><InventorySettings /></S>} />

        <Route path="invoices" element={<ComingSoon name="Invoices" />} />
        <Route path="vendors" element={<ComingSoon name="Vendors" />} />
        <Route path="tickets" element={<ComingSoon name="Tickets" />} />
        <Route path="reports/*" element={<ComingSoon name="Reports" />} />
        <Route path="settings" element={<S><SettingsLayout /></S>}>
          <Route index element={<Navigate to="mail" replace />} />
          <Route path="mail" element={<S><MailSettings /></S>} />
          <Route path="custom-fields" element={<S><CustomFieldsSettings /></S>} />
          <Route path="company" element={<S><CompanyFinanceSettings /></S>} />
          <Route path="tax-rates" element={<S><TaxRatesSettings /></S>} />
          <Route path="expense-categories" element={<S><ExpenseCategoriesSettings /></S>} />
          <Route path="account-groups" element={<S><AccountGroupsSettings /></S>} />
          <Route path="statuses" element={<S><StatusManager /></S>} />
        </Route>
      </Route>

      {/* Public Helpdesk (no auth): shareable KB article + tenant help center */}
      <Route path="/kb/a/:slug" element={<S><PublicArticle /></S>} />
      <Route path="/kb/:key" element={<S><PublicKb /></S>} />

      {/* Public Web-to-Lead form (no auth) */}
      <Route path="/f/:token" element={<S><PublicLeadForm /></S>} />

      {/* Public proposal portal (share link / QR target) */}
      <Route path="/portal/proposals/:token" element={<S><ProposalPortal /></S>} />
      <Route path="/portal/contracts/:token" element={<S><ContractPortal /></S>} />

      <Route path="/vendor-portal/*" element={<ComingSoon name="Vendor Portal" />} />

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
