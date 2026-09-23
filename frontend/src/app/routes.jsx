import { Routes, Route, Navigate } from 'react-router-dom'
import { ProtectedRoute, GuestRoute } from '@/router/ProtectedRoute'
import AppShell from '@/components/layout/AppShell'
import { Suspense, lazy } from 'react'
import { useAuth } from '@/context/AuthContext'
import { purchasePortalApi } from '@/services/purchasePortalApi'

// Auth pages (eager)
import LoginPage from '@/pages/auth/LoginPage'
import RegisterPage from '@/pages/auth/RegisterPage'
import VendorRegisterPage from '@/pages/auth/VendorRegisterPage'
import TPVRegisterPage from '@/pages/auth/TPVRegisterPage'
import ClientRegisterPage from '@/pages/auth/ClientRegisterPage'
import PendingApprovalPage from '@/pages/auth/PendingApprovalPage'
import SetPasswordPage from '@/pages/auth/SetPasswordPage'
import ForgotPasswordPage from '@/pages/auth/ForgotPasswordPage'

// Core pages (lazy)
const DashboardPage = lazy(() => import('@/pages/dashboard/DashboardPage'))
const ActiveSessions = lazy(() => import('@/pages/settings/ActiveSessions'))
const MyProfile = lazy(() => import('@/pages/settings/MyProfile'))
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
const PublicScanAccess = lazy(() => import('@/pages/public/PublicScanAccess'))

// HR Module (lazy)
const HRLayout = lazy(() => import('@/modules/hr/HRLayout'))
const Reimbursements   = lazy(() => import('@/modules/hr/pages/Reimbursements'))
const MyReimbursements = lazy(() => import('@/modules/hr/pages/MyReimbursements'))
const Advances        = lazy(() => import('@/modules/hr/pages/Advances'))
const MyAdvances      = lazy(() => import('@/modules/hr/pages/MyAdvances'))
const AttendanceReports = lazy(() => import('@/modules/hr/pages/AttendanceReports'))
const MyLeave = lazy(() => import('@/modules/hr/pages/MyLeave'))
const MyCorrections = lazy(() => import('@/modules/hr/pages/MyCorrections'))
const Corrections   = lazy(() => import('@/modules/hr/pages/Corrections'))
const HrSettings    = lazy(() => import('@/modules/hr/pages/HrSettings'))
const DemoRequests  = lazy(() => import('@/modules/hr/pages/DemoRequests'))
const Holidays            = lazy(() => import('@/modules/hr/pages/Holidays'))
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
const OrganizationSetup = lazy(() => import('@/modules/hr/pages/OrganizationSetup'))
// #29 — organisation chart, derived from the employee reporting hierarchy.
const OrgChart = lazy(() => import('@/modules/hr/pages/OrgChart'))
// #10 — interview question bank + AI generation.
const InterviewQuestionBank = lazy(() => import('@/modules/hr/pages/InterviewQuestionBank'))
const HrOperations = lazy(() => import('@/modules/hr/pages/HrOperations'))
const EmployeeSurveys = lazy(() => import('@/modules/hr/pages/EmployeeSurveys'))
const Payroll = lazy(() => import('@/modules/hr/pages/Payroll'))
const Performance = lazy(() => import('@/modules/hr/pages/Performance'))
const LeaveManagement = lazy(() => import('@/modules/hr/pages/LeaveManagement'))
const ExitManagement = lazy(() => import('@/modules/hr/pages/ExitManagement'))
const LearningDevelopment = lazy(() => import('@/modules/hr/pages/LearningDevelopment'))
const ProbationManagement = lazy(() => import('@/modules/hr/pages/ProbationManagement'))
const EmployeeOnboarding = lazy(() => import('@/modules/hr/pages/EmployeeOnboarding'))
const EmployeeOnboardingDetail = lazy(() => import('@/modules/hr/pages/EmployeeOnboardingDetail'))
const NotificationCenter = lazy(() => import('@/modules/notifications/NotificationCenter'))

// Admin Module (lazy)
const StaffManagement = lazy(() => import('@/pages/admin/StaffManagementPage'))

// Sales Module (lazy)
const SalesLayout = lazy(() => import('@/modules/sales/SalesLayout'))
// The module's Report section — filterable and exportable, unlike the
// dashboard, which describes the current month and is fixed.
const SalesReports = lazy(() => import('@/modules/sales/pages/SalesReports'))
const SalesDashboard = lazy(() => import('@/modules/sales/pages/SalesDashboard'))
const SalesTasks = lazy(() => import('@/modules/sales/pages/SalesTasks'))
const Proposals = lazy(() => import('@/modules/sales/pages/Proposals'))
const Estimates = lazy(() => import('@/modules/sales/pages/Estimates'))
const SalesInvoices = lazy(() => import('@/modules/sales/pages/Invoices'))
const DeliveryNotes = lazy(() => import('@/modules/sales/pages/DeliveryNotes'))
const SalesPayments = lazy(() => import('@/modules/sales/pages/Payments'))
const CreditNotes = lazy(() => import('@/modules/sales/pages/CreditNotes'))
const SalesItems = lazy(() => import('@/modules/sales/pages/Items'))
const ProposalDetail = lazy(() => import('@/modules/sales/pages/ProposalDetail'))
const ProposalWizard = lazy(() => import('@/modules/sales/pages/ProposalWizard'))
const DocumentStart = lazy(() => import('@/modules/sales/pages/DocumentStart'))
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
// Roles and departments as data — created from Settings rather than by a
// developer. Staff JOB roles only; account types stay in code (each is a portal).
const DepartmentsSettings = lazy(() => import('@/modules/settings/pages/DepartmentsSettings'))
const GeneralBrandingSettings = lazy(() => import('@/modules/settings/pages/GeneralBrandingSettings'))
const LocalizationSettings = lazy(() => import('@/modules/settings/pages/LocalizationSettings'))
const CurrencySettings = lazy(() => import('@/modules/settings/pages/CurrencySettings'))
const DocumentNumberingSettings = lazy(() => import('@/modules/settings/pages/DocumentNumberingSettings'))
const EmailTemplatesSettings = lazy(() => import('@/modules/settings/pages/EmailTemplatesSettings'))
const UploadSettings = lazy(() => import('@/modules/settings/pages/UploadSettings'))
const SecuritySettings = lazy(() => import('@/modules/settings/pages/SecuritySettings'))
const NotificationPreferences = lazy(() => import('@/modules/settings/pages/NotificationPreferences'))
const MailSettings = lazy(() => import('@/modules/settings/pages/MailSettings'))
const WhatsAppSettings = lazy(() => import('@/modules/settings/pages/WhatsAppSettings'))
const CustomFieldsSettings = lazy(() => import('@/modules/settings/pages/CustomFieldsSettings'))
const CompanyFinanceSettings = lazy(() => import('@/modules/settings/pages/CompanyFinanceSettings'))
const TaxRatesSettings = lazy(() => import('@/modules/settings/pages/TaxRatesSettings'))
const ExpenseCategoriesSettings = lazy(() => import('@/modules/settings/pages/ExpenseCategoriesSettings'))
const AccountGroupsSettings = lazy(() => import('@/modules/settings/pages/AccountGroupsSettings'))
const RecycleBinSettings = lazy(() => import('@/modules/settings/pages/RecycleBinSettings'))
const PublicLeadForm = lazy(() => import('@/modules/sales/public/PublicLeadForm'))

// Customer Module (lazy)
const CustomerLayout = lazy(() => import('@/modules/customer/CustomerLayout'))
const Customers = lazy(() => import('@/modules/customer/pages/Customers'))
const GroupReports = lazy(() => import('@/modules/customer/pages/GroupReports'))
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
// As above: analytics answers "what needs attention now", this is the
// report you point at a range and take to a meeting.
const HelpdeskReports = lazy(() => import('@/modules/helpdesk/pages/HelpdeskReports'))
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
const PublicTicketView = lazy(() => import('@/modules/helpdesk/public/PublicTicketView'))
const PublicSupport = lazy(() => import('@/modules/helpdesk/public/PublicSupport'))

// SIRE Module (lazy) -- the engineering defect track. Not the Helpdesk: a ticket
// closes when the requester is satisfied, a SIRE case when the fix ships verified.
const SireLayout = lazy(() => import('@/modules/sire/SireLayout'))
const SireDashboard = lazy(() => import('@/modules/sire/pages/DashboardPage'))
const SireMyWork = lazy(() => import('@/modules/sire/pages/MyWorkPage'))
const SireIssueDetail = lazy(() => import('@/modules/sire/pages/IssueDetailPage'))
const SireReleaseBoard = lazy(() => import('@/modules/sire/pages/ReleaseBoardPage'))
const SireReleaseDetail = lazy(() => import('@/modules/sire/pages/ReleaseDetailPage'))
const SireReleaseNotes = lazy(() => import('@/modules/sire/pages/ReleaseNotesPage'))
const SireQuality = lazy(() => import('@/modules/sire/pages/QualityDashboardPage'))
const SireInsights = lazy(() => import('@/modules/sire/pages/EngineeringInsightsPage'))
const SireRecurrenceDetail = lazy(() => import('@/modules/sire/pages/RecurrenceDetailPage'))

// STOS Module (lazy) -- Sangoe Transport OS. This shell is the FLEET & ASSET
// control tower (Developer 2); the CEO / Operations / Driver towers read trip
// and invoice data owned by other developers and are not stubbed here.
// Fleet screens (Person 2), inside the Transport module since the D-62 merge.
const FleetOverview = lazy(() => import('@/modules/transport/fleet/pages/FleetOverview'))
const FleetVehiclePassport = lazy(() => import('@/modules/transport/fleet/pages/VehiclePassportView'))
const FleetWorkshop = lazy(() => import('@/modules/transport/fleet/pages/MaintenanceBoard'))
const FleetDrivers = lazy(() => import('@/modules/transport/fleet/pages/DriversBoard'))

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

// Purchase Module (lazy) — pages land here as they're built
const PurchaseLayout = lazy(() => import('@/modules/purchase/PurchaseLayout'))

// Sangoe Transport OS (STOS) — SNG-TRN-006 orders, SNG-TRN-007 trips.
const TransportLayout      = lazy(() => import('@/modules/transport/TransportLayout'))
const TransportFinder      = lazy(() => import('@/modules/transport/pages/TransportFinder'))
const TransportOrders      = lazy(() => import('@/modules/transport/pages/TransportOrders'))
const TransportOrderDetail = lazy(() => import('@/modules/transport/pages/TransportOrderDetail'))
const TransportTrips       = lazy(() => import('@/modules/transport/pages/TransportTrips'))
const TransportConsignments = lazy(() => import('@/modules/transport/pages/TransportConsignments'))
const TransportContainers = lazy(() => import('@/modules/transport/pages/TransportContainers'))
const ContainerPassport = lazy(() => import('@/modules/transport/pages/ContainerPassport'))
const TransportTripDetail  = lazy(() => import('@/modules/transport/pages/TransportTripDetail'))
const TransportVehicles    = lazy(() => import('@/modules/transport/pages/TransportVehicles'))
const TransportVehicleDetail = lazy(() => import('@/modules/transport/pages/TransportVehicleDetail'))
const TransportDrivers     = lazy(() => import('@/modules/transport/pages/TransportDrivers'))
const TransportDriverDetail  = lazy(() => import('@/modules/transport/pages/TransportDriverDetail'))

const PurchaseRequests = lazy(() => import('@/modules/purchase/pages/PurchaseRequests'))
const PurchaseOrders = lazy(() => import('@/modules/purchase/pages/PurchaseOrders'))
const PurchaseGoodsReceived = lazy(() => import('@/modules/purchase/pages/PurchaseGoodsReceived'))
const PurchaseInvoices = lazy(() => import('@/modules/purchase/pages/PurchaseInvoices'))
const PurchaseDashboard = lazy(() => import('@/modules/purchase/pages/PurchaseDashboard'))
const PurchaseDebitNotes = lazy(() => import('@/modules/purchase/pages/PurchaseDebitNotes'))
const PurchaseRfqs = lazy(() => import('@/modules/purchase/pages/PurchaseRfqs'))
const PurchaseRfqDetail = lazy(() => import('@/modules/purchase/pages/PurchaseRfqDetail'))
const PurchaseContracts = lazy(() => import('@/modules/purchase/pages/PurchaseContracts'))
const PurchaseComplianceRegister = lazy(() => import('@/modules/purchase/pages/PurchaseComplianceRegister'))
const PurchaseNcr = lazy(() => import('@/modules/purchase/pages/PurchaseNcr'))
const PurchaseCapaRegister = lazy(() => import('@/modules/purchase/pages/PurchaseCapaRegister'))
const PurchaseIncidents = lazy(() => import('@/modules/purchase/pages/PurchaseIncidents'))
// Replaces the former PurchaseApprovals page, whose modals closed on backdrop
// click -- the one interaction rule this codebase is explicit about. Same
// endpoint (/purchase/approval-requests); adds the server-side vendor filter the
// controller already accepted and nothing used.
const PurchaseApprovalRegister = lazy(() => import('@/modules/purchase/pages/PurchaseApprovalRegister'))
const PurchaseAnalytics = lazy(() => import('@/modules/purchase/pages/PurchaseAnalytics'))
const PurchaseDocumentVault = lazy(() => import('@/modules/purchase/pages/PurchaseDocumentVault'))
const PurchaseCommunications = lazy(() => import('@/modules/purchase/pages/PurchaseCommunications'))
const PurchaseInspections = lazy(() => import('@/modules/purchase/pages/PurchaseInspections'))
const PurchaseViolations = lazy(() => import('@/modules/purchase/pages/PurchaseViolations'))
const PurchasePerformanceIndex = lazy(() => import('@/modules/purchase/pages/PurchasePerformanceIndex'))
const PurchaseRenewals = lazy(() => import('@/modules/purchase/pages/PurchaseRenewals'))
const PurchaseOffboarding = lazy(() => import('@/modules/purchase/pages/PurchaseOffboarding'))
const PurchaseContractDetail = lazy(() => import('@/modules/purchase/pages/PurchaseContractDetail'))
const PurchaseCatalog = lazy(() => import('@/modules/purchase/pages/PurchaseCatalog'))
const PurchaseVendorItems = lazy(() => import('@/modules/purchase/pages/PurchaseVendorItems'))
const PurchaseOrderReturns = lazy(() => import('@/modules/purchase/pages/PurchaseOrderReturns'))
const PurchaseReports = lazy(() => import('@/modules/purchase/pages/PurchaseReports'))
const PurchaseSettings = lazy(() => import('@/modules/purchase/pages/PurchaseSettings'))
// Purchase Vendor admin — Purchase-owned pages (no TPV components).
const PurchaseVendors = lazy(() => import('@/modules/purchase/pages/PurchaseVendors'))

// ── Contract module ─────────────────────────────────────────────────────
// Its own module, not part of Sales: an agreement is signed with customers AND
// vendors, so it belongs to neither of their sidebars. Sales, Purchase and TPV
// keep the contract features they already had.
const ContractsList = lazy(() => import('@/modules/contract/pages/Contracts'))
const ContractForm = lazy(() => import('@/modules/contract/pages/ContractForm'))
const ContractRecord = lazy(() => import('@/modules/contract/pages/ContractDetail'))
const ContractSignPortal = lazy(() => import('@/modules/contract/pages/ContractSignPortal'))
// A party's own contracts, inside whichever portal they log into. One page; the
// api client differs because each portal authenticates a different identity.
const PortalContractsTpv = lazy(() => import('@/modules/contract/pages/portals/TpvContractsPage'))
const PortalContractsPurchase = lazy(() => import('@/modules/contract/pages/portals/PurchaseContractsPage'))
const PortalContractsClient = lazy(() => import('@/modules/contract/pages/portals/ClientContractsPage'))
// "Assigned to me" — ONE screen for all three portals. Unlike the contract pages
// above it takes no api client, because here the three really do read the same
// URL: the server works out who is asking from the token.
const PortalAssignedTasks = lazy(() => import('@/modules/tasks/portal/PortalAssignedTasks'))

const PurchaseVendorDetailLayout = lazy(() => import('@/modules/purchase/pages/vendor-detail/PurchaseVendorDetailLayout'))
const PurchaseVendorOnboardingWizard = lazy(() => import('@/modules/purchase/pages/PurchaseVendorOnboardingWizard'))
const PurchaseWorkforce = lazy(() => import('@/modules/purchase/pages/PurchaseWorkforce'))
// Purchase workforce registration — the mirror of TPV's Workers list + 5-step
// worker wizard, on Purchase's own tables (see docs/audit/PURCHASE-TPV-PARITY.md).
const PurchaseWorkers = lazy(() => import('@/modules/purchase/pages/PurchaseWorkers'))
const PurchaseWorkerWizard = lazy(() => import('@/modules/purchase/pages/PurchaseWorkerWizard'))
const PurchaseMedicalFitness = lazy(() => import('@/modules/purchase/pages/PurchaseMedicalFitness'))
const PurchasePpeMatrix = lazy(() => import('@/modules/purchase/pages/PurchasePpeMatrix'))
const PurchaseGateLog = lazy(() => import('@/modules/purchase/pages/PurchaseGateLog'))
const PurchaseWorkforceAttendance = lazy(() => import('@/modules/purchase/pages/PurchaseWorkforceAttendance'))
const PurchasePermits = lazy(() => import('@/modules/purchase/pages/PurchasePermits'))
const PurchaseWorkPackages = lazy(() => import('@/modules/purchase/pages/PurchaseWorkPackages'))
const PurchaseWorkAuthorization = lazy(() => import('@/modules/purchase/pages/PurchaseWorkAuthorization'))
const PurchaseSafetyEngagement = lazy(() => import('@/modules/purchase/pages/PurchaseSafetyEngagement'))
const PurchaseSiteRegisters = lazy(() => import('@/modules/purchase/pages/PurchaseSiteRegisters'))
const PurchaseEvidenceLocker = lazy(() => import('@/modules/purchase/pages/PurchaseEvidenceLocker'))
const PurchaseGovernanceDashboard = lazy(() => import('@/modules/purchase/pages/PurchaseGovernanceDashboard'))
const PurchaseAuthorityMatrix = lazy(() => import('@/modules/purchase/pages/PurchaseAuthorityMatrix'))
const PurchasePrequalification = lazy(() => import('@/modules/purchase/pages/PurchasePrequalification'))
const PurchaseRiskDueDiligence = lazy(() => import('@/modules/purchase/pages/PurchaseRiskDueDiligence'))
const PurchaseCompetency = lazy(() => import('@/modules/purchase/pages/PurchaseCompetency'))
// Purchase meetings now render the SHARED kickoff screens; see the routes below.

// Purchase Vendor Portal (lazy) — independent PurchaseVendor auth.
const PurchasePortalShell = lazy(() => import('@/pages/purchase-portal/PurchasePortalShell'))
const PurchasePortalStrikes = lazy(() => import('@/pages/purchase-portal/PurchasePortalStrikes'))
const PurchasePortalDashboard = lazy(() => import('@/pages/purchase-portal/PurchasePortalDashboard'))
const PurchasePortalCommercial = lazy(() => import('@/pages/purchase-portal/PurchasePortalCommercial'))
const PurchaseMyContacts = lazy(() => import('@/pages/purchase-portal/PurchaseMyContacts'))
const PurchaseMyKb = lazy(() => import('@/pages/purchase-portal/PurchaseMyKb'))
const PurchasePortalOverview = lazy(() => import('@/pages/purchase-portal/PurchasePortalOverview'))
// Shared feature pages, driven by the Purchase portal's api client for parity.
const PP_API = purchasePortalApi
const PurchasePortalOnboarding = lazy(() => import('@/pages/purchase-portal/PurchasePortalOnboarding'))
const PurchasePortalDocuments = lazy(() => import('@/pages/purchase-portal/PurchasePortalDocuments'))
const PurchasePortalApproval = lazy(() => import('@/pages/purchase-portal/PurchasePortalApproval'))
const PurchasePortalKickoff = lazy(() => import('@/pages/purchase-portal/PurchasePortalKickoff'))
const PurchasePortalPpe = lazy(() => import('@/pages/purchase-portal/PurchasePortalPpe'))
const PurchasePortalWorkforceShell = lazy(() => import('@/pages/purchase-portal/PurchasePortalWorkforceShell'))
const PurchaseWorkforceDashboard = lazy(() => import('@/modules/purchase/pages/PurchaseWorkforceDashboard'))
const PurchasePortalSupport = lazy(() => import('@/pages/purchase-portal/PurchasePortalSupport'))
const PurchasePortalProfile = lazy(() => import('@/pages/purchase-portal/PurchasePortalProfile'))

const PurchasePortalCompliance = lazy(() => import('@/pages/purchase-portal/PurchasePortalCompliance'))
const PurchasePortalGovernance = lazy(() => import('@/pages/purchase-portal/PurchasePortalGovernance'))
const PurchaseVendorForgotPassword = lazy(() => import('@/pages/purchase-portal/PurchaseVendorForgotPassword'))
const PurchaseVendorResetPassword = lazy(() => import('@/pages/purchase-portal/PurchaseVendorResetPassword'))
const PurchaseVendorVerifyEmail = lazy(() => import('@/pages/purchase-portal/PurchaseVendorVerifyEmail'))
// ── Customer portal (§ the old CRM's contact login, restored) ──
const ClientPortalForgotPassword = lazy(() => import('@/pages/client-portal/ClientPortalForgotPassword'))
const ClientPortalSetPassword = lazy(() => import('@/pages/client-portal/ClientPortalSetPassword'))
const ClientPortalGuard = lazy(() => import('@/pages/client-portal/ClientPortalGuard'))
const ClientPortalShell = lazy(() => import('@/pages/client-portal/ClientPortalShell'))
const ClientPortalDashboard = lazy(() => import('@/pages/client-portal/ClientPortalDashboard'))
const ClientPortalRecords = lazy(() => import('@/pages/client-portal/ClientPortalRecords'))
const ClientPortalProfile = lazy(() => import('@/pages/client-portal/ClientPortalProfile'))
const ClientPortalFeedback = lazy(() => import('@/pages/client-portal/ClientPortalFeedback'))
const ClientPortalStatement = lazy(() => import('@/pages/client-portal/ClientPortalStatement'))
const ClientPortalShipment = lazy(() => import('@/pages/client-portal/ClientPortalShipment'))
const PurchaseVendorPortalGuard = lazy(() => import('@/pages/purchase-portal/PurchaseVendorPortalGuard'))

// TPV Module (lazy) — pages land here as they're built
const TPVLayout = lazy(() => import('@/modules/tpv/TPVLayout'))
const TpvVendors = lazy(() => import('@/modules/tpv/pages/TpvVendors'))
const TpvDashboard = lazy(() => import('@/modules/tpv/pages/TpvDashboard'))
const TpvPrequalification = lazy(() => import('@/modules/tpv/pages/TpvPrequalification'))
const TpvRiskDueDiligence = lazy(() => import('@/modules/tpv/pages/TpvRiskDueDiligence'))
const TpvContracts = lazy(() => import('@/modules/tpv/pages/TpvContracts'))
const TpvWorkPackages = lazy(() => import('@/modules/tpv/pages/TpvWorkPackages'))
const TpvApprovalRegister = lazy(() => import('@/modules/tpv/pages/TpvApprovalRegister'))
const TpvCompetency = lazy(() => import('@/modules/tpv/pages/TpvCompetency'))
const TpvMedicalFitness = lazy(() => import('@/modules/tpv/pages/TpvMedicalFitness'))
const TpvWorkAuthorization = lazy(() => import('@/modules/tpv/pages/TpvWorkAuthorization'))
const TpvNcr = lazy(() => import('@/modules/tpv/pages/TpvNcr'))
const TpvCapaRegister = lazy(() => import('@/modules/tpv/pages/TpvCapaRegister'))
const TpvInspections = lazy(() => import('@/modules/tpv/pages/TpvInspections'))
const TpvViolations = lazy(() => import('@/modules/tpv/pages/TpvViolations'))
const TpvRenewals = lazy(() => import('@/modules/tpv/pages/TpvRenewals'))
const TpvOffboarding = lazy(() => import('@/modules/tpv/pages/TpvOffboarding'))
const TpvComplianceRegister = lazy(() => import('@/modules/tpv/pages/TpvComplianceRegister'))
const TpvVendorDetail = lazy(() => import('@/modules/tpv/pages/TpvVendorDetail'))
const TpvOnboardings = lazy(() => import('@/modules/tpv/pages/TpvOnboardings'))
const TpvTemporaryVendors = lazy(() => import('@/modules/tpv/pages/TpvTemporaryVendors'))
const TpvApprovals = lazy(() => import('@/modules/tpv/pages/TpvApprovals'))
const TpvOnboardingWizard = lazy(() => import('@/modules/tpv/pages/TpvOnboardingWizard'))
const TpvWorkers = lazy(() => import('@/modules/tpv/pages/TpvWorkers'))
const TpvPpe = lazy(() => import('@/modules/tpv/pages/TpvPpe'))
const TpvPpeMatrix = lazy(() => import('@/modules/tpv/pages/TpvPpeMatrix'))
const TpvWorkerWizard = lazy(() => import('@/modules/tpv/pages/TpvWorkerWizard'))
const TpvGateLog = lazy(() => import('@/modules/tpv/pages/TpvGateLog'))
const TpvStrikes = lazy(() => import('@/modules/tpv/pages/TpvStrikes'))
const TpvIncidents = lazy(() => import('@/modules/tpv/pages/TpvIncidents'))
const GovernanceDashboard = lazy(() => import('@/modules/tpv/pages/GovernanceDashboard'))
const MeetingPerformance = lazy(() => import('@/modules/tpv/pages/MeetingPerformance'))
const TpvPermits = lazy(() => import('@/modules/tpv/pages/TpvPermits'))
const TpvSafetyEngagement = lazy(() => import('@/modules/tpv/pages/TpvSafetyEngagement'))
const TpvReports = lazy(() => import('@/modules/tpv/pages/TpvReports'))
const TpvSiteRegisters = lazy(() => import('@/modules/tpv/pages/TpvSiteRegisters'))
const TpvEvidenceLocker = lazy(() => import('@/modules/tpv/pages/TpvEvidenceLocker'))
const TpvDocumentVault = lazy(() => import('@/modules/tpv/pages/TpvDocumentVault'))
const TpvAnalytics = lazy(() => import('@/modules/tpv/pages/TpvAnalytics'))
const TpvPerformanceIndex = lazy(() => import('@/modules/tpv/pages/TpvPerformanceIndex'))
const TpvCommunications = lazy(() => import('@/modules/tpv/pages/TpvCommunications'))
const TpvAuthorityMatrix = lazy(() => import('@/modules/tpv/pages/TpvAuthorityMatrix'))
const TpvSettings = lazy(() => import('@/modules/tpv/pages/TpvSettings'))
// Vendor-scoped Workforce workspace — its own rail, entered after Onboarding Step-6.
const WorkforceLayout = lazy(() => import('@/modules/tpv/WorkforceLayout'))
const WorkforceDashboard = lazy(() => import('@/modules/tpv/pages/WorkforceDashboard'))
const WorkforceAttendance = lazy(() => import('@/modules/tpv/pages/WorkforceAttendance'))

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
// Cross-meeting registers (Meeting.docx §8 / §9 / §10) — Decisions, Issues and
// the Open Action Items backlog, in one screen with three tabs.
const MeetingRegisters = lazy(() => import('@/modules/shared/pages/MeetingRegisters'))
const KickoffMeetingCreate = lazy(() => import('@/modules/shared/pages/KickoffMeetingCreate'))
const KickoffMeetingDetail = lazy(() => import('@/modules/shared/pages/KickoffMeetingDetail'))

// Vendor Self-Service Portal — its own chrome, gated to vendor roles. Every
// endpoint resolves the vendor from the token (EnsureVendorPortalAccess).
const VendorPortalShell = lazy(() => import('@/pages/vendor-portal/VendorPortalShell'))
const VendorPortalCompliance = lazy(() => import('@/pages/vendor-portal/VendorPortalCompliance'))
const VendorPortalGovernance = lazy(() => import('@/pages/vendor-portal/VendorPortalGovernance'))
const MyRegistrationStatus = lazy(() => import('@/pages/vendor-portal/MyRegistrationStatus'))
const PortalOnboardingEntry = lazy(() => import('@/pages/vendor-portal/PortalOnboardingEntry'))
const PortalDashboard = lazy(() => import('@/pages/vendor-portal/PortalDashboard'))
const PortalComingSoon = lazy(() => import('@/pages/vendor-portal/PortalComingSoon'))
const MyContacts = lazy(() => import('@/pages/vendor-portal/MyContacts'))
const MyOverview = lazy(() => import('@/pages/vendor-portal/MyOverview'))
const MyCustomers = lazy(() => import('@/pages/vendor-portal/MyCustomers'))
const MyKb = lazy(() => import('@/pages/vendor-portal/MyKb'))
// Training was a placeholder in BOTH portals while the register behind it had
// existed for months. One page, two APIs — the My Work pattern.
const MyTraining = lazy(() => import('@/pages/vendor-portal/MyTraining'))
const MyWork = lazy(() => import('@/pages/vendor-portal/MyWork'))
const MyPerformance = lazy(() => import('@/pages/vendor-portal/MyPerformance'))
const MyHsse = lazy(() => import('@/pages/vendor-portal/MyHsse'))
const MyShipments = lazy(() => import('@/pages/vendor-portal/MyShipments'))
const PortalDocuments = lazy(() => import('@/pages/vendor-portal/PortalDocuments'))
const PortalSupport = lazy(() => import('@/pages/vendor-portal/PortalSupport'))
const PortalOrderDetail = lazy(() => import('@/pages/vendor-portal/PortalOrderDetail'))
const PortalInvoiceDetail = lazy(() => import('@/pages/vendor-portal/PortalInvoiceDetail'))
const PortalWorkforceShell = lazy(() => import('@/pages/vendor-portal/PortalWorkforceShell'))

// Medical module — the doctor portal (one login, both vendor sides), the admin
// doctor directory, and the public certificate check the QR opens.
const DoctorPortalShell = lazy(() => import('@/pages/doctor-portal/DoctorPortalShell'))
const DoctorDashboard   = lazy(() => import('@/pages/doctor-portal/DoctorDashboard'))
const DoctorExamination = lazy(() => import('@/pages/doctor-portal/DoctorExamination'))
const DoctorExamForm    = lazy(() => import('@/pages/doctor-portal/DoctorExamForm'))
const DoctorGroupFindings = lazy(() => import('@/pages/doctor-portal/DoctorGroupFindings'))
const DoctorExaminations = lazy(() => import('@/pages/doctor-portal/DoctorExaminations'))
const DoctorProfile     = lazy(() => import('@/pages/doctor-portal/DoctorProfile'))
const MedicalDoctors    = lazy(() => import('@/pages/medical/MedicalDoctors'))
const MedicalGeneralRegister = lazy(() => import('@/pages/medical/MedicalGeneralRegister'))
const MedicalGeneralReport   = lazy(() => import('@/pages/medical/MedicalGeneralReport'))
const MedicalVerify     = lazy(() => import('@/pages/public/MedicalVerify'))
const VendorMedicalPanel = lazy(() => import('@/components/medical/VendorMedicalPanel'))
const TpvMedicalReport = lazy(() => import('@/modules/tpv/pages/TpvMedicalReport'))
const PurchaseMedicalReport = lazy(() => import('@/modules/purchase/pages/PurchaseMedicalReport'))

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

// Smart root redirect — sends each role to the correct landing page.
function RootRedirect() {
  const { isAuthenticated, user } = useAuth()
  if (!isAuthenticated) return <Navigate to="/auth/login" replace />
  const role = user?.role
  // Both vendor spellings, because both are real User roles and the portal
  // below admits both. This used to read "No 'vendor' branch: a Purchase Vendor
  // is never a User session" -- true of purchase_vendor, and nothing to do with
  // `vendor`, which three users actually hold. The two were conflated, so a
  // vendor opening / was sent to /app, which blocks that role and bounces back
  // to the same place: an infinite redirect, not a wrong page.
  //
  // purchase_vendor is genuinely absent: it signs in at
  // /auth/login?role=purchase_vendor and holds a PurchaseVendor token under its
  // own storage key, never a User session.
  if (role === 'third_party_vendor' || role === 'vendor') return <Navigate to="/vendor-portal/dashboard" replace />
  if (role === 'company') return <Navigate to="/company-portal/dashboard" replace />
  if (role === 'doctor') return <Navigate to="/doctor-portal/dashboard" replace />
  return <Navigate to="/app/dashboard" replace />
}

export default function AppRoutes() {
  return (
    <Routes>
      <Route path="/" element={<RootRedirect />} />

      {/* Public certificate check — what the QR on a medical certificate opens.
          No auth by design: a guard or an inspector holding the paper has to be
          able to check it. The API answers thinly (standing, not findings). */}
      <Route path="/verify/medical" element={<S><MedicalVerify /></S>} />
      <Route path="/verify/medical/:certificate" element={<S><MedicalVerify /></S>} />

      {/* Doctor portal — the Internal Medical Flow. One login serves both vendor
          sides; the module switch lives in the shell. */}
      <Route path="/doctor-portal" element={
        <ProtectedRoute roles={['doctor']}><S><DoctorPortalShell /></S></ProtectedRoute>
      }>
        <Route index element={<Navigate to="dashboard" replace />} />
        <Route path="dashboard"    element={<S><DoctorDashboard /></S>} />
        {/* Choosing WHO and the examination itself are two pages, not one long
            scroll. A doctor pressed Open and the form appeared below the fold,
            which looks exactly like a button that did nothing. */}
        <Route path="examine"      element={<S><DoctorExamination /></S>} />
        <Route path="examine/form"  element={<S><DoctorExamForm /></S>} />
        <Route path="examine/group" element={<S><DoctorGroupFindings /></S>} />
        <Route path="examinations" element={<S><DoctorExaminations /></S>} />
        <Route path="profile"      element={<S><DoctorProfile /></S>} />
      </Route>

      {/* Auth routes */}
      <Route path="/auth">
        <Route path="login" element={<GuestRoute><LoginPage /></GuestRoute>} />
        <Route path="register" element={<RegisterPage />} />
        <Route path="register/vendor" element={<VendorRegisterPage />} />
        <Route path="register/tpv" element={<TPVRegisterPage />} />
        <Route path="register/client" element={<ClientRegisterPage />} />
        <Route path="register/company" element={<S><CompanyRegisterPage /></S>} />
        <Route path="pending-approval" element={<PendingApprovalPage />} />
        <Route path="forgot-password" element={<GuestRoute><ForgotPasswordPage /></GuestRoute>} />
        {/* Where a vendor login-link email lands. Not GuestRoute-wrapped: an
            admin already signed in must still be able to open the link they
            just sent, to check it works. */}
        <Route path="set-password" element={<S><SetPasswordPage /></S>} />
        <Route path="verify-email" element={<ComingSoon name="Email Verification" />} />
      </Route>

      {/* Public Career Portal (no auth — tenant from :slug) */}
      <Route path="/careers/:slug" element={<S><CareerPortal /></S>} />
      <Route path="/careers/:slug/jobs/:id" element={<S><CareerJobDetails /></S>} />
      <Route path="/onboarding/:token" element={<S><OnboardingPortal /></S>} />
      <Route path="/offer/:token" element={<S><OfferPortal /></S>} />
      <Route path="/hiring-request/:token" element={<S><HiringRequestPortal /></S>} />
      <Route path="/client-tracking/:token" element={<S><ClientTrackingPortal /></S>} />

      {/* Public token-authed screens (no login — the token IS the credential) */}
      <Route path="/scan/:token" element={<S><GateScan /></S>} />
      <Route path="/checklist/:token" element={<S><ChecklistFill /></S>} />

      {/* Protected app routes — internal staff/admin only. Portal-only roles
          (TPV vendor, vendor, company) are bounced to their own portal, so a
          vendor can never reach the admin shell by link or by typing a URL. */}
      <Route path="/app" element={<ProtectedRoute blockRoles={['third_party_vendor', 'vendor', 'company', 'doctor']}><AppShell /></ProtectedRoute>}>

        <Route index element={<Navigate to="dashboard" replace />} />
        <Route path="dashboard" element={<S><DashboardPage /></S>} />

        {/* The general medical register — internal staff, client contacts and
            site visitors. Deliberately NOT under tpv/ or purchase/: these people
            belong to no vendor, and filing them under one of the two vendor
            modules would be the wrong claim about who they are. The doctor
            portal has been writing these all along with nothing able to read
            them back. */}
        <Route path="medical/general"        element={<S><MedicalGeneralRegister /></S>} />
        <Route path="medical/general/report" element={<S><MedicalGeneralReport /></S>} />
        <Route path="sessions" element={<S><ActiveSessions /></S>} />
        <Route path="profile" element={<S><MyProfile /></S>} />
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
          {/* Native expense claims. The admin queue is gated server-side by
              hr.manage; /my-expenses needs nothing but a linked employee record,
              because being yourself is not a permission. */}
          <Route path="expense-claims" element={<S><Reimbursements /></S>} />
          <Route path="my-expenses" element={<S><MyReimbursements /></S>} />
          {/* Advances. The admin queue has its own gate (hr.advances), because
              the approvers are a line manager, accounts and a director — none of
              whom satisfy the HR gate the claims queue uses. */}
          <Route path="advances" element={<S><Advances /></S>} />
          <Route path="my-advances" element={<S><MyAdvances /></S>} />
          {/* Read-only: looking at a report cannot affect a payroll run. */}
          <Route path="attendance-reports" element={<S><AttendanceReports /></S>} />
          {/* Self-service leave. The other leave routes are HR's — they file on
              somebody's behalf; this one only ever touches your own. */}
          <Route path="my-leave" element={<S><MyLeave /></S>} />
          {/* Corrections. 'corrections' is the approver queue; 'my-corrections'
              only ever touches your own. */}
          <Route path="my-corrections" element={<S><MyCorrections /></S>} />
          <Route path="corrections" element={<S><Corrections /></S>} />
          <Route path="settings" element={<S><HrSettings /></S>} />
          <Route path="demo-requests" element={<S><DemoRequests /></S>} />
            <Route path="holidays" element={<S><Holidays /></S>} />
          <Route path="organization-setup" element={<S><OrganizationSetup /></S>} />
          <Route path="org-chart" element={<S><OrgChart /></S>} />
          <Route path="interview-questions" element={<S><InterviewQuestionBank /></S>} />
          <Route path="operations" element={<S><HrOperations /></S>} />
          <Route path="surveys" element={<S><EmployeeSurveys /></S>} />
          <Route path="payroll" element={<S><Payroll /></S>} />
          <Route path="performance" element={<S><Performance /></S>} />
          <Route path="leave-management" element={<S><LeaveManagement /></S>} />
          <Route path="exit-management" element={<S><ExitManagement /></S>} />
          <Route path="learning-development" element={<S><LearningDevelopment /></S>} />
          <Route path="probation-management" element={<S><ProbationManagement /></S>} />
          <Route path="employee-onboarding" element={<S><EmployeeOnboarding /></S>} />
          <Route path="employee-onboarding/:id" element={<S><EmployeeOnboardingDetail /></S>} />
          <Route path="settings/notifications" element={<S><NotificationCenter /></S>} />
        </Route>

        {/* SALES MODULE */}
        <Route path="sales" element={<S><SalesLayout /></S>}>
          <Route index element={<Navigate to="dashboard" replace />} />
          <Route path="dashboard" element={<S><SalesDashboard /></S>} />
          <Route path="reports" element={<S><SalesReports /></S>} />
          <Route path="proposals" element={<S><Proposals /></S>} />
          <Route path="proposals/new" element={<S><ProposalWizard /></S>} />
          <Route path="proposals/:id/edit" element={<S><ProposalWizard /></S>} />
          <Route path="estimates/new" element={<S><DocumentStart docType="estimate" /></S>} />
          <Route path="estimates" element={<S><Estimates docType="estimate" /></S>} />
          <Route path="proforma-invoices" element={<S><Estimates docType="proforma" /></S>} />
          <Route path="invoices/new" element={<S><DocumentStart docType="invoice" /></S>} />
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
          {/* Sales-scoped lens on the Tasks module (owner: Shivam): shows only
              tasks with rel_type=customer/contract, never the whole task list. */}
          <Route path="tasks" element={<S><SalesTasks /></S>} />
          <Route path="contracts" element={<S><Contracts /></S>} />
          <Route path="contracts/:id" element={<S><ContractDetail /></S>} />
          <Route path="web-to-lead" element={<S><WebToLeadForms /></S>} />
          <Route path="forecast" element={<S><Forecast /></S>} />
          <Route path="commission" element={<S><Commission /></S>} />
        </Route>

        {/* CUSTOMER MODULE */}
        <Route path="customers" element={<S><CustomerLayout /></S>}>
          <Route index element={<S><Customers /></S>} />
          <Route path="group-reports" element={<S><GroupReports /></S>} />
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
          <Route path="reports" element={<S><HelpdeskReports /></S>} />
          <Route path="tickets" element={<S><TicketGrid /></S>} />
          <Route path="tickets/:id" element={<S><TicketThread /></S>} />
          <Route path="knowledge-base" element={<S><KnowledgeBaseHome /></S>} />
          <Route path="knowledge-base/:id" element={<S><KbArticleView /></S>} />
          <Route path="kb-admin" element={<S><KbAdmin /></S>} />
          <Route path="widget" element={<S><WidgetSettings /></S>} />
          <Route path="settings" element={<S><SupportSettings /></S>} />
        </Route>

        {/* SIRE MODULE (issue -> verified fix) */}
        <Route path="sire" element={<ProtectedRoute blockRoles={['client', 'third_party_vendor', 'vendor', 'company', 'doctor']}><S><SireLayout /></S></ProtectedRoute>}>
          <Route index element={<Navigate to="dashboard" replace />} />
          <Route path="dashboard" element={<S><SireDashboard /></S>} />
          <Route path="my-work" element={<S><SireMyWork /></S>} />
          <Route path="cases/:id" element={<S><SireIssueDetail /></S>} />
          <Route path="releases" element={<S><SireReleaseBoard /></S>} />
          <Route path="releases/:id" element={<S><SireReleaseDetail /></S>} />
          <Route path="release-notes/:noteId" element={<S><SireReleaseNotes /></S>} />
          <Route path="quality" element={<S><SireQuality /></S>} />
          <Route path="insights" element={<S><SireInsights /></S>} />
          <Route path="recurring/:id" element={<S><SireRecurrenceDetail /></S>} />
        </Route>


        {/* PURCHASE MODULE (procure-to-pay) */}
        <Route path="purchase" element={<S><PurchaseLayout /></S>}>
          <Route index element={<Navigate to="dashboard" replace />} />
          <Route path="dashboard" element={<S><PurchaseDashboard /></S>} />
          <Route path="requests" element={<S><PurchaseRequests /></S>} />
          <Route path="quotations" element={<S><PurchaseRfqs /></S>} />
          <Route path="quotations/:id" element={<S><PurchaseRfqDetail /></S>} />
          <Route path="orders" element={<S><PurchaseOrders /></S>} />
          <Route path="goods-received" element={<S><PurchaseGoodsReceived /></S>} />
          <Route path="invoices" element={<S><PurchaseInvoices /></S>} />
          <Route path="debit-notes" element={<S><PurchaseDebitNotes /></S>} />
          <Route path="contracts" element={<S><PurchaseContracts /></S>} />
          <Route path="contracts/:id" element={<S><PurchaseContractDetail /></S>} />
          <Route path="catalog" element={<S><PurchaseCatalog /></S>} />
          <Route path="compliance-register" element={<S><PurchaseComplianceRegister /></S>} />
          <Route path="ncr" element={<S><PurchaseNcr /></S>} />
          <Route path="capa" element={<S><PurchaseCapaRegister /></S>} />
          <Route path="incidents" element={<S><PurchaseIncidents /></S>} />
          {/* Central approval register (§12) — /api/purchase/approval-requests */}
          <Route path="approval-requests" element={<S><PurchaseApprovalRegister /></S>} />
          <Route path="inspections" element={<S><PurchaseInspections /></S>} />
          <Route path="violations" element={<S><PurchaseViolations /></S>} />
          <Route path="vpi" element={<S><PurchasePerformanceIndex /></S>} />
          <Route path="renewals" element={<S><PurchaseRenewals /></S>} />
          <Route path="offboarding" element={<S><PurchaseOffboarding /></S>} />
          <Route path="analytics" element={<S><PurchaseAnalytics /></S>} />
          <Route path="document-vault" element={<S><PurchaseDocumentVault /></S>} />
          <Route path="communications" element={<S><PurchaseCommunications /></S>} />
          {/* Purchase Vendors — Purchase-owned pages on /api/purchase/* (no TPV). */}
          <Route path="vendors" element={<S><PurchaseVendors /></S>} />
          {/* Vendor Detail workspace — persistent left sidebar + deep-linkable nested tabs */}
          <Route path="vendors/:id/*" element={<S><PurchaseVendorDetailLayout /></S>} />
          {/* The SAME onboarding queue TPV uses. It reads its data source, route
              base and FK name from useVendorModule(), which resolves to Purchase
              on this path — so the two modules stay identical by construction
              instead of by two copies drifting apart. The Purchase-only copy it
              replaces was a bare 5-column table: no KPIs, no search, no status
              filter, no step progress and no blocking reason. */}
          <Route path="onboarding" element={<S><TpvOnboardings /></S>} />
          <Route path="onboarding/:id" element={<S><PurchaseVendorOnboardingWizard /></S>} />
          {/* Admin/staff review of vendor-supplied workers. Activation inside is
              admin-only — the button is hidden for staff and the endpoint refuses them. */}
          <Route path="workforce" element={<S><PurchaseWorkforce /></S>} />
          {/* Worker register + 5-step wizard, mirroring TPV's /app/tpv/workers.
              Kept on their own paths rather than replacing /workforce above, so the
              existing review screen and its links keep working. */}
          <Route path="workers" element={<S><PurchaseWorkers /></S>} />
          <Route path="workers/:id" element={<S><PurchaseWorkerWizard /></S>} />
          {/* Cross-workforce medical fitness register + quality check —
              tenant-wide, mirroring TPV, because a reviewer works a queue
              across vendors rather than one vendor at a time. */}
          <Route path="medical" element={<S><PurchaseMedicalFitness /></S>} />
          <Route path="medical/doctors" element={<S><MedicalDoctors /></S>} />
          <Route path="medical/report" element={<S><PurchaseMedicalReport /></S>} />
          {/* PPE matrix. Purchase has no requirements table, so unlike TPV's
              prescriptive matrix this one is OBSERVED — designation against the
              kit workers in that role actually hold. */}
          <Route path="ppe/matrix" element={<S><PurchasePpeMatrix /></S>} />
          {/* Site gate. A refused scan is not a crossing, so it appears in the
              log but never on the roster or in attendance. */}
          <Route path="gate-log" element={<S><PurchaseGateLog /></S>} />
          <Route path="attendance" element={<S><PurchaseWorkforceAttendance /></S>} />
          {/* Permit To Work. Raising is open to staff; approving, rejecting,
              activating and closing are admin-only server-side. */}
          <Route path="permits" element={<S><PurchasePermits /></S>} />
          {/* The accountability spine: what a vendor is on site to deliver, and
              whether a given worker may do a given activity. Authorisation is
              derived per request and writes nothing. */}
          <Route path="work-packages" element={<S><PurchaseWorkPackages /></S>} />
          <Route path="work-authorization" element={<S><PurchaseWorkAuthorization /></S>} />
          {/* Site-wide HSSE registers, SHARED with TPV — the same rows, by
              design. One site has one safety record; two copies would each look
              complete while being half the truth. */}
          <Route path="safety" element={<S><PurchaseSafetyEngagement /></S>} />
          <Route path="site-registers" element={<S><PurchaseSiteRegisters /></S>} />
          <Route path="evidence" element={<S><PurchaseEvidenceLocker /></S>} />
          {/* Counts Purchase's OWN registers, not TPV's — see the page header. */}
          <Route path="governance" element={<S><PurchaseGovernanceDashboard /></S>} />
          {/* The tenant's sign-off org chart. Unlike the dashboard this alias IS
              legitimate: it holds no register rows, so "Safety owns permit
              approval" is equally true of both modules. */}
          <Route path="authority-matrix" element={<S><PurchaseAuthorityMatrix /></S>} />
          {/* Cross-vendor registers. These answer "who has NOT been assessed",
              which the per-vendor workspace tabs structurally cannot. */}
          <Route path="prequalification" element={<S><PurchasePrequalification /></S>} />
          <Route path="risk" element={<S><PurchaseRiskDueDiligence /></S>} />
          {/* Workforce Competency & Skill Matrix — "No Competency, No Work" (mirror of TPV §15). */}
          <Route path="competency" element={<S><PurchaseCompetency /></S>} />
          {/* Meetings — the SAME three screens TPV uses, on Purchase's tables.
              meetingEngineApi resolves the engine from the path, so these post
              to /api/purchase/kickoff here and /api/kickoff under /app/tpv.
              The Purchase-owned copies they replace were 2,073 lines against
              the shared engine's 4,715: no calendar, no meeting-type manager,
              no live vendor snapshot, no AI agenda or summary, no carry-forward
              picker, and no cross-meeting registers. */}
          <Route path="kickoff" element={<S><KickoffMeetings /></S>} />
          <Route path="kickoff/new" element={<S><KickoffMeetingCreate /></S>} />
          <Route path="kickoff/:id/edit" element={<S><KickoffMeetingCreate /></S>} />
          <Route path="kickoff/:id" element={<S><KickoffMeetingDetail /></S>} />
          {/* The SAME register screen TPV uses. useMeetingModule() resolves the
              Purchase engine on this path, so decisions, issues and the open
              action backlog now read ACROSS meetings here too — previously they
              were only visible inside the one meeting that produced them. */}
          <Route path="meetings/registers" element={<S><MeetingRegisters /></S>} />
          <Route path="meetings/registers/:register" element={<S><MeetingRegisters /></S>} />
          {/* Sidebar tabs pending dedicated pages — placeholders keep nav intact */}
          {/* Vendor Items — Purchase Vendor ↔ Inventory Item mapping */}
          <Route path="vendor-items" element={<S><PurchaseVendorItems /></S>} />
          {/* Order Returns — goods returned to a Purchase Vendor (OR-####) */}
          <Route path="order-returns" element={<S><PurchaseOrderReturns /></S>} />
          {/* Reports — read-only Purchase aggregations (tables + charts) */}
          <Route path="reports" element={<S><PurchaseReports /></S>} />
          {/* Settings — Purchase-owned config + vendor-category master */}
          <Route path="settings" element={<S><PurchaseSettings /></S>} />
        </Route>

        {/* Sangoe Transport OS — orders and trips only; later clusters arrive
            with the tickets that build them. */}
        <Route path="transport" element={<S><TransportLayout /></S>}>
          {/* CTD §4 — "the preferred entry point". This slot held a redirect to
              the Orders list, which meant Transport opened on a table and the
              one search that understands transport identifiers was reachable
              only from inside the Containers page or from ⌘K. */}
          <Route index element={<S><TransportFinder /></S>} />
          <Route path="orders" element={<S><TransportOrders /></S>} />
          <Route path="orders/:id" element={<S><TransportOrderDetail /></S>} />
          <Route path="trips" element={<S><TransportTrips /></S>} />
          <Route path="consignments" element={<S><TransportConsignments /></S>} />
          <Route path="containers" element={<S><TransportContainers /></S>} />
          <Route path="containers/:id" element={<S><ContainerPassport /></S>} />
          <Route path="trips/:id" element={<S><TransportTripDetail /></S>} />
          {/* Fleet (Person 2), merged in 2026-09-17 — D-62.
              P1's placeholder Vehicles/Drivers screens are unrouted here rather
              than deleted: removing the files is P1's step under TEAM-CONTRACTS
              §1a, and unrouting is what actually ends the duplicate UI.
              The old paths still resolve so nobody's bookmark breaks. */}
          <Route path="fleet" element={<S><FleetOverview /></S>} />
          <Route path="fleet/vehicles/:id" element={<S><FleetVehiclePassport /></S>} />
          <Route path="workshop" element={<S><FleetWorkshop /></S>} />
          <Route path="vehicles" element={<S><FleetOverview /></S>} />
          <Route path="vehicles/:id" element={<S><FleetVehiclePassport /></S>} />
          <Route path="drivers" element={<S><FleetDrivers /></S>} />
          <Route path="drivers/:id" element={<S><FleetDrivers /></S>} />
        </Route>

        {/* TPV MODULE */}
        <Route path="tpv" element={<S><TPVLayout /></S>}>
          <Route index element={<Navigate to="dashboard" replace />} />
          {/* Dashboard now renders the real HSSE/workforce roll-up (was the
              vendor list). The Vendor Master list moved to its own /vendors
              route so "Dashboard" means dashboard. */}
          <Route path="dashboard" element={<S><TpvDashboard /></S>} />
          <Route path="vendors" element={<S><TpvVendors /></S>} />
          {/* §6/§7 module-level qualification queues (per-vendor assessment lives
              on the vendor workspace tabs). */}
          <Route path="prequalification" element={<S><TpvPrequalification /></S>} />
          <Route path="risk" element={<S><TpvRiskDueDiligence /></S>} />
          {/* §8 Contracts & Work Orders — TPV-owned commercial spine. */}
          <Route path="contracts" element={<S><TpvContracts /></S>} />
          {/* §13 Work Packages — Vendor→Project→WP→Activity→Workforce spine. */}
          <Route path="work-packages" element={<S><TpvWorkPackages /></S>} />
          {/* §12 central Approval register — generic, separate from onboarding approvals. */}
          <Route path="approval-register" element={<S><TpvApprovalRegister /></S>} />
          {/* §15 Competency & Training. */}
          <Route path="competency" element={<S><TpvCompetency /></S>} />
          {/* §3/§16 Medical Fitness register + the Medical module's quality
              check. The doctor directory is deliberately module-neutral: one
              doctor serves both vendor sides, so there is one list of them. */}
          <Route path="medical" element={<S><TpvMedicalFitness /></S>} />
          <Route path="medical/doctors" element={<S><MedicalDoctors /></S>} />
          <Route path="medical/report" element={<S><TpvMedicalReport /></S>} />
          {/* §19 Unified Work Authorization — read-only composite verdict. */}
          <Route path="work-authorization" element={<S><TpvWorkAuthorization /></S>} />
          {/* §24 Non-Conformance Reports. */}
          <Route path="ncr" element={<S><TpvNcr /></S>} />
          <Route path="capa" element={<S><TpvCapaRegister /></S>} />
          {/* §22 Inspections & Audits. */}
          <Route path="inspections" element={<S><TpvInspections /></S>} />
          {/* §26 Vendor Violations & Strike escalation. */}
          <Route path="violations" element={<S><TpvViolations /></S>} />
          {/* §28 Renewal & Extension. */}
          <Route path="renewals" element={<S><TpvRenewals /></S>} />
          {/* §29 Offboarding / Closure. */}
          <Route path="offboarding" element={<S><TpvOffboarding /></S>} />
          {/* §21 Compliance register — per-vendor 14-category compliance. */}
          <Route path="compliance-register" element={<S><TpvComplianceRegister /></S>} />
          <Route path="view/:id" element={<S><TpvVendorDetail /></S>} />
          <Route path="kickoff" element={<S><KickoffMeetings /></S>} />
          <Route path="kickoff/new" element={<S><KickoffMeetingCreate /></S>} />
          {/* Edit reuses the CREATE page rather than a second form — it is the
              only screen with participants, MOM items, mode and venue. Declared
              before :id so "edit" is never captured as a meeting id. */}
          <Route path="kickoff/:id/edit" element={<S><KickoffMeetingCreate /></S>} />
          <Route path="kickoff/:id" element={<S><KickoffMeetingDetail /></S>} />
          {/* Meeting.docx §9's "searchable Decision Register", §10's issue
              register and §8's action backlog — across every meeting, not one. */}
          <Route path="meetings/registers" element={<S><MeetingRegisters /></S>} />
          <Route path="meetings/registers/:register" element={<S><MeetingRegisters /></S>} />
          <Route path="performance" element={<S><MeetingPerformance /></S>} />
          {/* The onboarding QUEUE stays — it is how staff find work. The wizard
              itself does not: Steps 1–6 are the vendor's own workflow and the
              only mount is /vendor-portal/onboarding/:id. `editable` was derived
              from status alone, never role, so an admin opening this route could
              accept the kickoff MOM, type the vendor's bank details and tick the
              vendor's declaration — entries the audit trail then attributes to
              the vendor. Admin review and approval live on the vendor record
              (TpvVendorDetail / TpvVendorDocuments) and are unchanged.
              Old links land on the queue rather than 404. */}
          <Route path="onboarding" element={<S><TpvOnboardings /></S>} />
          <Route path="onboarding/:id" element={<Navigate to="/app/tpv/onboarding" replace />} />
          <Route path="temporary" element={<S><TpvTemporaryVendors /></S>} />
          <Route path="approvals" element={<S><TpvApprovals /></S>} />
          {/* Was a ComingSoon placeholder with no implementation. Documents are
              managed per vendor, so send an old link to the vendor list. */}
          <Route path="documents" element={<Navigate to="/app/tpv/vendors" replace />} />
          <Route path="workforce" element={<S><TpvWorkers /></S>} />
          <Route path="workforce/:id" element={<S><TpvWorkerWizard /></S>} />
          <Route path="ppe" element={<S><TpvPpe /></S>} />
          <Route path="ppe/matrix" element={<S><TpvPpeMatrix /></S>} />
          <Route path="compliance" element={<S><ComplianceWorkspace /></S>} />
          <Route path="compliance/templates/:id" element={<S><TemplateBuilder /></S>} />
          <Route path="compliance/checklists/:id" element={<S><ChecklistDetail /></S>} />
          <Route path="gate-log" element={<S><TpvGateLog /></S>} />
          <Route path="strikes" element={<S><TpvStrikes /></S>} />
          <Route path="incidents" element={<S><TpvIncidents /></S>} />
          <Route path="permits" element={<S><TpvPermits /></S>} />
          <Route path="safety-engagement" element={<S><TpvSafetyEngagement /></S>} />
          <Route path="governance" element={<S><GovernanceDashboard /></S>} />
          <Route path="reports" element={<S><TpvReports /></S>} />
          <Route path="site-registers" element={<S><TpvSiteRegisters /></S>} />
          <Route path="evidence" element={<S><TpvEvidenceLocker /></S>} />
          <Route path="document-vault" element={<S><TpvDocumentVault /></S>} />
          <Route path="analytics" element={<S><TpvAnalytics /></S>} />
          <Route path="vpi" element={<S><TpvPerformanceIndex /></S>} />
          <Route path="communications" element={<S><TpvCommunications /></S>} />
          <Route path="authority-matrix" element={<S><TpvAuthorityMatrix /></S>} />
          <Route path="settings" element={<S><TpvSettings /></S>} />
        </Route>

        {/* WORKFORCE — vendor-scoped workspace (own rail), entered from Onboarding Step-6.
            Sibling of the TPV rail so it swaps in its own ModuleShell. Reuses the
            existing Workers/Wizard/GateLog/Strikes screens, scoped to :vendorId. */}
        <Route path="tpv/workforce/vendor/:vendorId" element={<S><WorkforceLayout /></S>}>
          <Route index element={<Navigate to="dashboard" replace />} />
          <Route path="dashboard" element={<S><WorkforceDashboard /></S>} />
          <Route path="workers" element={<S><TpvWorkers /></S>} />
          <Route path="workers/:id" element={<S><TpvWorkerWizard /></S>} />
          <Route path="ppe" element={<S><TpvPpe /></S>} />
          <Route path="gate-log" element={<S><TpvGateLog /></S>} />
          <Route path="attendance" element={<S><WorkforceAttendance /></S>} />
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

        {/* The shared meeting engine, on a neutral path.
            It was only ever mounted under /app/tpv/kickoff and
            /app/purchase/kickoff, yet kickoffApi.list() is not scoped to
            either — both mounts show the same tenant-wide list. So a meeting
            belonging to a Customer had nowhere to be opened, and the link from
            the customer's Meetings tab 404'd. Same components, no module
            chrome, alongside Tasks and Projects which are equally cross-module. */}
        <Route path="meetings" element={<S><KickoffMeetings /></S>} />
        {/* Scheduling lives here too now that Meetings is a company-wide module:
            every internal role can call one, and sending them to /app/tpv to do
            it would put a vendor module in the way of a team catch-up. Static
            "new" before ":id" so it is not parsed as a meeting id. */}
        <Route path="meetings/new" element={<S><KickoffMeetingCreate /></S>} />
        <Route path="meetings/:id/edit" element={<S><KickoffMeetingCreate /></S>} />
        <Route path="meetings/:id" element={<S><KickoffMeetingDetail /></S>} />

        {/* The Contract module. Static "new" before ":id" so it is not parsed
            as a contract id. */}
        <Route path="contracts" element={<S><ContractsList /></S>} />
        <Route path="contracts/new" element={<S><ContractForm /></S>} />
        <Route path="contracts/:id/edit" element={<S><ContractForm /></S>} />
        <Route path="contracts/:id" element={<S><ContractRecord /></S>} />

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

        <Route path="tickets" element={<ComingSoon name="Tickets" />} />
        <Route path="settings" element={<S><SettingsLayout /></S>}>
          <Route index element={<Navigate to="general" replace />} />
          <Route path="general" element={<S><GeneralBrandingSettings /></S>} />
          <Route path="mail" element={<S><MailSettings /></S>} />
          <Route path="whatsapp" element={<S><WhatsAppSettings /></S>} />
          <Route path="custom-fields" element={<S><CustomFieldsSettings /></S>} />
          <Route path="company" element={<S><CompanyFinanceSettings /></S>} />
          <Route path="tax-rates" element={<S><TaxRatesSettings /></S>} />
          <Route path="expense-categories" element={<S><ExpenseCategoriesSettings /></S>} />
          <Route path="account-groups" element={<S><AccountGroupsSettings /></S>} />
          <Route path="localization" element={<S><LocalizationSettings /></S>} />
          <Route path="currency" element={<S><CurrencySettings /></S>} />
          <Route path="numbering" element={<S><DocumentNumberingSettings /></S>} />
          <Route path="email-templates" element={<S><EmailTemplatesSettings /></S>} />
          <Route path="upload" element={<S><UploadSettings /></S>} />
          <Route path="security" element={<S><SecuritySettings /></S>} />
          <Route path="notification-preferences" element={<S><NotificationPreferences /></S>} />
          <Route path="recycle-bin" element={<S><RecycleBinSettings /></S>} />
          <Route path="statuses" element={<S><StatusManager /></S>} />
          {/* /settings/roles retired: it managed a second staff-role catalogue
              (access_roles) beside the live one. staff_roles owns the vocabulary,
              permissions and scope together, and is maintained in Staff
              Management at /admin/roles. */}
          <Route path="departments" element={<S><DepartmentsSettings /></S>} />
          {/* Each module's own settings, reachable from the one Setup panel as
              well as from inside the module. Same component either way, so the
              two can never drift apart — this is a second door, not a copy. */}
          <Route path="modules/accounts" element={<S><AccountsSettings /></S>} />
          <Route path="modules/helpdesk" element={<S><SupportSettings /></S>} />
          <Route path="modules/purchase" element={<S><PurchaseSettings /></S>} />
          <Route path="modules/tpv" element={<S><TpvSettings /></S>} />
        </Route>
      </Route>

      {/* Public Helpdesk (no auth): shareable KB article + tenant help center */}
      <Route path="/kb/a/:slug" element={<S><PublicArticle /></S>} />
      <Route path="/kb/:key" element={<S><PublicKb /></S>} />
      <Route path="/ticket/:ref" element={<S><PublicTicketView /></S>} />
      {/* The shareable support link. Same widget key as /kb/:key and the embed
          snippet, so one key gives a site its help centre AND its contact form. */}
      <Route path="/support/:key" element={<S><PublicSupport /></S>} />

      {/* Public Web-to-Lead form (no auth) */}
      <Route path="/f/:token" element={<S><PublicLeadForm /></S>} />

      {/* Public proposal portal (share link / QR target) */}
      <Route path="/portal/proposals/:token" element={<S><ProposalPortal /></S>} />
      <Route path="/portal/contracts/:token" element={<S><ContractPortal /></S>} />
      {/* The Contract module's own signing page. A customer or vendor opens this
          from an e-mailed link and has no account, so it sits outside the app
          shell entirely. Distinct path from the Sales portal above, which serves
          that module's own contracts. */}
      <Route path="/contracts/sign/:token" element={<S><ContractSignPortal /></S>} />
      <Route path="/contracts/verify/:token" element={<S><ContractSignPortal /></S>} />

      {/* Vendor Self-Service Portal — vendor / third_party_vendor only.
          Purchase-side vendors see Dashboard + Documents + Orders + Invoices.
          Third-party vendors see Dashboard + Onboarding (always) + Workforce (after activation).
          All data is scoped server-side from the auth token — no vendor id in any URL. */}
      <Route path="/vendor-portal" element={
        <ProtectedRoute roles={['vendor', 'third_party_vendor']}><S><VendorPortalShell /></S></ProtectedRoute>
      }>
        <Route index element={<Navigate to="dashboard" replace />} />
        <Route path="dashboard"         element={<S><PortalDashboard /></S>} />

        {/* Onboarding is the VENDOR's own six-step workflow, and this is now its
            ONLY mount — the admin copy under /app/tpv/onboarding/:id was removed
            because it rendered the same wizard fully editable for staff.
            useVendorModule() resolves portalApi from the /vendor-portal path, and
            canApprove/canManage are both false for a third_party_vendor, so the
            admin-only review (Step 4) and approval (Step 6) controls render as
            read-only status for the vendor.
            The portal has no LIST — a vendor has exactly one onboarding, which
            PortalOnboardingEntry resolves from the token. */}
        <Route path="registration"      element={<S><MyRegistrationStatus /></S>} />
        <Route path="compliance"        element={<S><VendorPortalCompliance /></S>} />
        <Route path="governance"        element={<S><VendorPortalGovernance /></S>} />
        <Route path="support"           element={<S><PortalSupport /></S>} />
        <Route path="agreements"        element={<S><PortalContractsTpv /></S>} />
        <Route path="onboarding"        element={<S><PortalOnboardingEntry /></S>} />
        <Route path="onboarding/:id"    element={<S><TpvOnboardingWizard /></S>} />

        {/* TPV Workforce — PortalWorkforceShell resolves vendor from token (no :vendorId in URL). */}
        <Route path="workforce" element={<S><PortalWorkforceShell /></S>}>
          <Route index element={<Navigate to="dashboard" replace />} />
          <Route path="dashboard"   element={<S><WorkforceDashboard /></S>} />
          <Route path="workers"     element={<S><TpvWorkers /></S>} />
          <Route path="workers/:id" element={<S><TpvWorkerWizard /></S>} />
          <Route path="ppe"         element={<S><TpvPpe /></S>} />
          {/* Gate Log is the security desk's record — admin-only. */}
          <Route path="gate-log"    element={<Navigate to="/vendor-portal/workforce/attendance" replace />} />
          <Route path="attendance"  element={<S><WorkforceAttendance /></S>} />
          <Route path="strikes"     element={<S><TpvStrikes /></S>} />
        </Route>

        {/* Purchase-side vendor routes (vendor role only) */}
        <Route path="documents"         element={<S><PortalDocuments /></S>} />
        <Route path="contacts"          element={<S><MyContacts /></S>} />
        <Route path="overview"          element={<S><MyOverview /></S>} />
        <Route path="customers"         element={<S><MyCustomers /></S>} />
        <Route path="kb"                element={<S><MyKb /></S>} />
        {/* Medical — the External Medical Flow. The vendor files certificates
            its own doctor signed and answers the quality team about them. */}
        <Route path="medical"           element={<S><VendorMedicalPanel base="/portal" /></S>} />
        <Route path="training"          element={<S><MyTraining /></S>} />
        <Route path="projects"          element={<S><MyWork view="projects" /></S>} />
        <Route path="tasks"             element={<S><MyWork view="tasks" /></S>} />
        {/* Two task lists here, and they are genuinely two things: `tasks` above
            is what was assigned to THIS LOGIN as a user, this one is what was
            assigned by name to the vendor's contacts, who have no login of their
            own. Merging them would need one of the two kinds of assignment to
            pretend to be the other. */}
        <Route path="team-tasks"        element={<S><PortalAssignedTasks /></S>} />
        <Route path="tickets"           element={<S><MyWork view="tickets" /></S>} />
        <Route path="expenses"          element={<S><MyWork view="expenses" /></S>} />
        <Route path="risk-score"        element={<S><MyPerformance view="risk" /></S>} />
        <Route path="feedback"          element={<S><MyPerformance view="feedback" /></S>} />
        <Route path="penalty"           element={<S><MyPerformance view="penalty" /></S>} />
        <Route path="awards"            element={<S><MyPerformance view="award" /></S>} />
        <Route path="referrals"         element={<S><MyPerformance view="referral" /></S>} />
        <Route path="ptw"               element={<S><MyHsse view="ptw" /></S>} />
        <Route path="incidents"         element={<S><MyHsse view="incidents" /></S>} />
        <Route path="pre-alert"         element={<S><MyShipments view="pre-alert" /></S>} />
        <Route path="packages"          element={<S><MyShipments view="packages" /></S>} />
        <Route path="shipping"          element={<S><MyShipments view="shipping" /></S>} />
        <Route path="orders/:id"        element={<S><PortalOrderDetail /></S>} />
        <Route path="invoices/:id"      element={<S><PortalInvoiceDetail /></S>} />

        {/* Roadmap sections not yet built — the full nav tree stays navigable. */}
        <Route path="s/:key"            element={<S><PortalComingSoon /></S>} />
      </Route>

      {/* Purchase Vendor Portal — auth (public, independent PurchaseVendor login) */}
      {/* Retired: there is ONE login page. Kept as a redirect rather than
          deleted because activation e-mails, bookmarks and printed onboarding
          packs already point here — those must land on the form, not a 404. */}
      <Route path="/purchase-portal/login" element={<Navigate to="/auth/login?role=purchase_vendor" replace />} />
      {/* The Purchase portal's own registration form is retired — there is one
          register page for every identity now. Redirected rather than deleted:
          the old path is in sent invitations and vendor bookmarks, and a 404
          there reads as "this company stopped taking suppliers". */}
      <Route path="/purchase-portal/register" element={<Navigate to="/auth/register" replace />} />
      <Route path="/purchase-portal/forgot-password" element={<S><PurchaseVendorForgotPassword /></S>} />
      <Route path="/purchase-portal/reset-password"  element={<S><PurchaseVendorResetPassword /></S>} />

      {/* Customer portal. Public auth screens, then everything behind the
          contact-token guard. The record screens share one table component —
          they differ only in columns, and each is refused server-side if the
          contact was never granted that section. */}
      {/* Retired — see the note on /purchase-portal/login. */}
      <Route path="/portal/login" element={<Navigate to="/auth/login?role=client" replace />} />
      <Route path="/portal/forgot-password" element={<S><ClientPortalForgotPassword /></S>} />
      <Route path="/portal/set-password"    element={<S><ClientPortalSetPassword /></S>} />
      <Route path="/portal" element={<S><ClientPortalGuard><ClientPortalShell /></ClientPortalGuard></S>}>
        <Route index element={<Navigate to="/portal/dashboard" replace />} />
        <Route path="dashboard"    element={<S><ClientPortalDashboard /></S>} />
        <Route path="statement"    element={<S><ClientPortalStatement /></S>} />
        <Route path="profile"      element={<S><ClientPortalProfile /></S>} />
        <Route path="feedback" element={<S><ClientPortalFeedback /></S>} />
        <Route path="agreements"   element={<S><PortalContractsClient /></S>} />
        {/* Work assigned to THIS contact by name. The client portal had no task
            screen at all, so a task given to a client's coordinator reached them
            only as an email with nowhere to go back to. */}
        <Route path="my-tasks"     element={<S><PortalAssignedTasks /></S>} />
        {['invoices', 'payments', 'credit-notes', 'estimates', 'proposals',
          'contracts', 'projects', 'tickets', 'shipments', 'files', 'notes', 'contacts'].map(v => (
          <Route key={v} path={v} element={<S><ClientPortalRecords view={v} /></S>} />
        ))}
        {/* One shipment. A page of its own rather than a row-expand, because the
            shared records table is a table — giving it a detail mode for one
            screen changes it for the other eleven. */}
        <Route path="shipments/:id" element={<S><ClientPortalShipment /></S>} />
      </Route>
      <Route path="/purchase-portal/verify-email"    element={<S><PurchaseVendorVerifyEmail /></S>} />

      {/* Purchase Vendor Portal — authenticated (PurchaseVendor token only). Data
          scoped server-side from the token; no vendor id in any URL. Independent
          of the shared user/vendor/TPV portal. */}
      <Route path="/purchase-portal" element={
        <PurchaseVendorPortalGuard><S><PurchasePortalShell /></S></PurchaseVendorPortalGuard>
      }>
        <Route index element={<Navigate to="dashboard" replace />} />
        <Route path="dashboard"  element={<S><PurchasePortalDashboard /></S>} />
        {/* The six onboarding steps are the VENDOR's own work — profile and final
            submission exist in no other screen, so with these redirected in place
            nothing in the product could submit a purchase onboarding at all. The
            admin page at /app/purchase/onboarding/:id stays review-only (documents
            + Approve/Reject/Hold) and is unaffected.
            PurchasePortalOnboarding resolves the record from the token via
            onboarding.self() — no id in the URL. */}
        <Route path="agreements" element={<S><PortalContractsPurchase /></S>} />
        {/* Tasks assigned to this vendor's own contacts. The Purchase portal has
            no per-contact login — the vendor signs in as the company — so this
            is its team's work, with each row naming whose it is. */}
        <Route path="tasks"      element={<S><PortalAssignedTasks /></S>} />
        <Route path="onboarding" element={<S><PurchasePortalOnboarding /></S>} />
        <Route path="documents"  element={<S><PurchasePortalDocuments /></S>} />
        <Route path="compliance" element={<S><PurchasePortalCompliance /></S>} />
        <Route path="governance" element={<S><PurchasePortalGovernance /></S>} />
        <Route path="approval"   element={<S><PurchasePortalApproval /></S>} />
        <Route path="kickoff"    element={<S><PurchasePortalKickoff /></S>} />
        {/* My Workforce — unlocked once the vendor is Active. The 5-step worker
            lifecycle; step 5 (badge) is read-only here, activation is admin-only.

            These are the SAME components the Purchase admin module uses, the way
            TPV has always mounted TpvWorkers/TpvWorkerWizard on both surfaces.
            They previously could not be: the portal client named the identical
            endpoints differently (`workers.list` vs `workforce.workers`), so a
            541-line copy of the screen existed instead — a different layout with
            a cut-down wizard beside a 2,244-line one the portal never used. */}
        <Route path="workforce" element={<S><PurchasePortalWorkforceShell /></S>}>
          <Route index element={<Navigate to="dashboard" replace />} />
          <Route path="dashboard"   element={<S><PurchaseWorkforceDashboard /></S>} />
          <Route path="workers"     element={<S><PurchaseWorkers /></S>} />
          <Route path="workers/:id" element={<S><PurchaseWorkerWizard /></S>} />
          <Route path="ppe"         element={<S><PurchasePortalPpe /></S>} />
          <Route path="attendance"  element={<S><PurchaseWorkforceAttendance /></S>} />
          <Route path="strikes"     element={<S><PurchasePortalStrikes /></S>} />
        </Route>
        <Route path="ppe"        element={<S><PurchasePortalPpe /></S>} />
        {/* Medical — the Purchase mirror of the External Medical Flow. */}
        <Route path="medical"    element={<S><VendorMedicalPanel base="/portal/purchase" /></S>} />
        <Route path="training"   element={<S><MyTraining api={PP_API} /></S>} />
        <Route path="profile"    element={<S><PurchasePortalProfile /></S>} />
        <Route path="support"    element={<S><PurchasePortalSupport /></S>} />

        {/* Commercial (read-only) — one component, driven by the view prop. */}
        {/* The Inventory items the buyer approved this vendor to supply. */}
        <Route path="items"       element={<S><PurchasePortalCommercial view="items" /></S>} />
        <Route path="orders"      element={<S><PurchasePortalCommercial view="orders" /></S>} />
        <Route path="quotations"  element={<S><PurchasePortalCommercial view="quotations" /></S>} />
        <Route path="contracts"   element={<S><PurchasePortalCommercial view="contracts" /></S>} />
        <Route path="invoices"    element={<S><PurchasePortalCommercial view="invoices" /></S>} />
        <Route path="debit-notes" element={<S><PurchasePortalCommercial view="debit-notes" /></S>} />
        <Route path="statement"   element={<S><PurchasePortalCommercial view="statement" /></S>} />
        <Route path="payments"    element={<S><PurchasePortalCommercial view="payments" /></S>} />
        <Route path="contacts"    element={<S><PurchaseMyContacts /></S>} />
        <Route path="kb"          element={<S><PurchaseMyKb /></S>} />
        <Route path="overview"    element={<S><PurchasePortalOverview /></S>} />

        {/* Parity with the TPV portal — same shared pages, Purchase api client. */}
        <Route path="customers"   element={<S><MyCustomers api={PP_API} /></S>} />
        {/* Purchase used to mount these read-only (caps.ticketWrite:false), which
            hid Raise Ticket AND made every row unclickable, so a Purchase vendor
            could see tickets and neither open nor open one. The portal now has
            the raise/detail/reply endpoints the TPV portal has always had, so
            both portals mount MyWork the same way. */}
        <Route path="projects"    element={<S><MyWork view="projects" api={PP_API} /></S>} />
        <Route path="tasks"       element={<S><MyWork view="tasks" api={PP_API} /></S>} />
        <Route path="tickets"     element={<S><MyWork view="tickets" api={PP_API} /></S>} />
        <Route path="expenses"    element={<S><MyWork view="expenses" api={PP_API} /></S>} />
        <Route path="feedback"    element={<S><MyPerformance view="feedback" api={PP_API} /></S>} />
        <Route path="penalty"     element={<S><MyPerformance view="penalty" api={PP_API} /></S>} />
        <Route path="awards"      element={<S><MyPerformance view="award" api={PP_API} /></S>} />
        <Route path="referrals"   element={<S><MyPerformance view="referral" api={PP_API} /></S>} />
        <Route path="pre-alert"   element={<S><MyShipments view="pre-alert" api={PP_API} /></S>} />
        <Route path="packages"    element={<S><MyShipments view="packages" api={PP_API} /></S>} />
        <Route path="shipping"    element={<S><MyShipments view="shipping" api={PP_API} /></S>} />
        <Route path="risk-score"  element={<S><MyPerformance view="risk" api={PP_API} /></S>} />
        <Route path="ptw"         element={<S><MyHsse view="ptw" api={PP_API} /></S>} />
        <Route path="incidents"   element={<S><MyHsse view="incidents" api={PP_API} /></S>} />

        {/* Roadmap sections not yet built — the full nav tree stays navigable. */}
        <Route path="s/:key"     element={<S><PortalComingSoon /></S>} />
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

      {/* Public Standalone QR Scan Verification Page */}
      <Route path="/scan-access/:token" element={<S><PublicScanAccess /></S>} />

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
