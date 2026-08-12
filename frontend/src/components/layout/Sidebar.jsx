import {
  LayoutDashboard, Users, Briefcase, CheckSquare, FolderOpen, Receipt, Truck, LifeBuoy,
  BarChart2, Settings, ChevronLeft, ChevronRight, LogOut, User, Zap,
  Package, UserCheck, CalendarDays, FileText, Rocket, Building2, ClipboardList,
  ChevronDown, Shield, UserCog, IndianRupee, FileSignature, CreditCard, FileX, ShoppingBag,
  UserPlus, Link2, RefreshCw, LayoutTemplate, Globe, TrendingUp, Landmark, BookText, Scale,
  ArrowLeftRight, BookOpen, Boxes, PackagePlus, PackageMinus, Warehouse, History, Network, FileQuestion,
  BarChart3, Activity, Layers3, ScanLine, ClipboardCheck, ShoppingCart, Hourglass, Wrench,
  CalendarRange, Handshake, Factory, Undo2, Wallet, Award, GraduationCap, ShieldCheck, Bell, Search, X,
  Settings2
} from 'lucide-react'
import { NavLink, useNavigate, useLocation } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useAuth } from '@/context/AuthContext'
import { useTheme } from '@/context/ThemeContext'
import { isModuleInstalled } from '@/modules/registry'
import { helpdeskApi } from '@/services/helpdeskApi'
import sangoeIcon from '@/assets/sangoe-icon.png'
import { useState, useEffect } from 'react'
import clsx from 'clsx'
import { leadApi } from '@/services/leadApi'

// NOTE: 'Contacts' and 'Deals' were removed — both were dead "Coming Soon"
// links. Contacts are the existing Customer module's contacts, and there is no
// separate Deal entity by design (leads are the pipeline, same as the old CRM).
// Portal roles never reach the staff ticket queue, so don't poll the badge for them.
const EXTERNAL_ROLES = ['client', 'vendor', 'third_party_vendor']

const NAV_ITEMS = [
  { label: 'Dashboard', icon: LayoutDashboard, path: '/app/dashboard' },
  { label: 'Tasks', icon: CheckSquare, path: '/app/tasks' },
  { label: 'Projects', icon: FolderOpen, path: '/app/projects' },
  { label: 'Settings', icon: Settings, path: '/app/settings' },
]

// The modules the sidebar search jumps to — the top-level module landing pages,
// searched by name. Keywords widen matches (e.g. "stock" → Inventory).
const MODULE_SEARCH = [
  { label: 'Modules',    path: '/app/modules',          icon: Package,         kw: 'marketplace install' },
  { label: 'Dashboard',  path: '/app/dashboard',        icon: LayoutDashboard, kw: 'home' },
  { label: 'Tasks',      path: '/app/tasks',            icon: CheckSquare,     kw: 'todo' },
  { label: 'Projects',   path: '/app/projects',         icon: FolderOpen,      kw: '' },
  { label: 'Helpdesk',   path: '/app/helpdesk/tickets', icon: LifeBuoy,        kw: 'tickets support' },
  { label: 'Inventory',  path: '/app/inventory',        icon: Boxes,           kw: 'stock warehouse items' },
  { label: 'Sales',      path: '/app/sales/dashboard',  icon: TrendingUp,      kw: 'revenue leads' },
  { label: 'Accounts',   path: '/app/accounts',         icon: Landmark,        kw: 'finance ledger' },
  { label: 'HR',         path: '/app/hr/dashboard',     icon: Users,           kw: 'recruitment employees payroll' },
  { label: 'Purchase',   path: '/app/purchase/dashboard', icon: ShoppingCart,  kw: 'procurement orders' },
  { label: 'TPV',        path: '/app/tpv/dashboard',    icon: UserCheck,       kw: 'third party vendor workforce' },
  { label: 'Customers',  path: '/app/customers',        icon: Building2,       kw: 'clients' },
  { label: 'Compliance', path: '/app/tpv/compliance',   icon: ShieldCheck,     kw: 'hsse checklists' },
]

// Which module the current route belongs to — used to PIN that module's header
// at the top of the sidebar. Ordered longest-prefix-first so e.g.
// /app/tpv/compliance resolves to Compliance, not TPV.
const PINNED_MODULES = [
  { base: '/app/tpv/compliance', label: 'Compliance', icon: ShieldCheck,     path: '/app/tpv/compliance' },
  { base: '/app/dashboard',      label: 'Dashboard',  icon: LayoutDashboard, path: '/app/dashboard' },
  { base: '/app/tasks',          label: 'Tasks',      icon: CheckSquare,     path: '/app/tasks' },
  { base: '/app/projects',       label: 'Projects',   icon: FolderOpen,      path: '/app/projects' },
  { base: '/app/helpdesk',       label: 'Helpdesk',   icon: LifeBuoy,        path: '/app/helpdesk/tickets' },
  { base: '/app/inventory',      label: 'Inventory',  icon: Boxes,           path: '/app/inventory' },
  { base: '/app/sales',          label: 'Sales',      icon: TrendingUp,      path: '/app/sales/dashboard' },
  { base: '/app/accounts',       label: 'Accounts',   icon: Landmark,        path: '/app/accounts' },
  { base: '/app/hr',             label: 'HR',         icon: Users,           path: '/app/hr/dashboard' },
  { base: '/app/purchase',       label: 'Purchase',   icon: ShoppingCart,    path: '/app/purchase/dashboard' },
  { base: '/app/tpv',            label: 'TPV',        icon: UserCheck,       path: '/app/tpv/dashboard' },
  { base: '/app/customers',      label: 'Customers',  icon: Building2,       path: '/app/customers' },
]

// ── HRMS sidebar structure (paths/APIs/permissions unchanged) ──
//   HRMS
//   ├── Dashboard
//   ├── Recruitment        (collapsible group)
//   ├── Employees
//   └── HR Records         (collapsible group) → Organization Setup
const HR_DASHBOARD = { label: 'Dashboard', path: '/app/hr/dashboard', icon: LayoutDashboard }
const HR_EMPLOYEES = { label: 'Employees', path: '/app/hr/employees', icon: Building2 }

const HR_RECRUITMENT_ITEMS = [
  { label: 'Manpower Requests', path: '/app/hr/manpower-requests', icon: ClipboardList },
  { label: 'Job Postings', path: '/app/hr/jobs', icon: Briefcase },
  { label: 'Candidates', path: '/app/hr/candidates', icon: Users },
  { label: 'Interviews', path: '/app/hr/interviews', icon: CalendarDays },
  { label: 'Offer Letters', path: '/app/hr/offers', icon: FileText },
  { label: 'Onboarding', path: '/app/hr/onboarding', icon: Rocket },
]

const HR_RECORDS_ITEMS = [
  { label: 'Organization Setup', path: '/app/hr/organization-setup', icon: Boxes },
  { label: 'Organization Chart', path: '/app/hr/org-chart', icon: Network },
  { label: 'Interview Questions', path: '/app/hr/interview-questions', icon: FileQuestion },
  { label: 'HR Operations', path: '/app/hr/operations', icon: Settings2 },
  { label: 'Employee Surveys', path: '/app/hr/surveys', icon: ClipboardList },
  { label: 'Payroll', path: '/app/hr/payroll', icon: Wallet },
  { label: 'Performance', path: '/app/hr/performance', icon: Award },
  { label: 'Leave Management', path: '/app/hr/leave-management', icon: CalendarDays },
  { label: 'Learning & Development', path: '/app/hr/learning-development', icon: GraduationCap },
  { label: 'Probation Management', path: '/app/hr/probation-management', icon: ShieldCheck },
  { label: 'Exit Management', path: '/app/hr/exit-management', icon: LogOut },
  { label: 'Notifications', path: '/app/hr/settings/notifications', icon: Bell },
]

// Flat list of every HR leaf — used only for the collapsed icon rail.
const HR_ALL_LEAVES = [HR_DASHBOARD, ...HR_RECRUITMENT_ITEMS, HR_EMPLOYEES, ...HR_RECORDS_ITEMS]

// Grouped so the ~17 sales micro-modules stay scannable instead of rendering
// as one long flat list. A muted mini-header is emitted whenever `group`
// changes (see the Sales render loop below).
const SALES_SUB_ITEMS = [
  { group: 'Pipeline',        label: 'Sales Dashboard', path: '/app/sales/dashboard', icon: LayoutDashboard },
  { group: 'Pipeline',        label: 'Leads', path: '/app/sales/leads', icon: UserPlus },
  { group: 'Pipeline',        label: 'Tasks', path: '/app/sales/tasks', icon: CheckSquare },
  { group: 'Pipeline',        label: 'Forecast', path: '/app/sales/forecast', icon: TrendingUp },

  { group: 'Documents',       label: 'Proposals', path: '/app/sales/proposals', icon: FileSignature },
  { group: 'Documents',       label: 'Proposal Templates', path: '/app/sales/proposal-templates', icon: LayoutTemplate },
  { group: 'Documents',       label: 'Estimates', path: '/app/sales/estimates', icon: ClipboardList },
  { group: 'Documents',       label: 'Proforma Invoices', path: '/app/sales/proforma-invoices', icon: ClipboardList },
  { group: 'Documents',       label: 'Tax Invoices', path: '/app/sales/invoices', icon: Receipt },
  { group: 'Documents',       label: 'Delivery Notes', path: '/app/sales/delivery-notes', icon: Truck },
  { group: 'Documents',       label: 'Credit Notes', path: '/app/sales/credit-notes', icon: FileX },

  { group: 'Billing',         label: 'Payments', path: '/app/sales/payments', icon: CreditCard },
  { group: 'Billing',         label: 'Payment Links', path: '/app/sales/payment-links', icon: Link2 },
  { group: 'Billing',         label: 'Retainer Invoices', path: '/app/sales/retainer-invoices', icon: RefreshCw },
  { group: 'Billing',         label: 'Commission', path: '/app/sales/commission', icon: IndianRupee },

  { group: 'Catalog & Setup', label: 'Items', path: '/app/sales/items', icon: ShoppingBag },
  { group: 'Catalog & Setup', label: 'Contracts', path: '/app/sales/contracts', icon: FileSignature },
  { group: 'Catalog & Setup', label: 'Web-to-Lead', path: '/app/sales/web-to-lead', icon: Globe },
]

const ACCOUNTS_SUB_ITEMS = [
  { label: 'Dashboard',       path: '/app/accounts/dashboard',       icon: LayoutDashboard },
  { label: 'Chart of Accounts', path: '/app/accounts/chart-of-accounts', icon: Landmark },
  { label: 'Vouchers',        path: '/app/accounts/vouchers',        icon: BookText },
  { label: 'Registers',       path: '/app/accounts/registers',       icon: BookOpen },
  { label: 'Bills',           path: '/app/accounts/bills',           icon: Receipt },
  { label: 'Banking',         path: '/app/accounts/banking',         icon: CreditCard },
  { label: 'Cheques',         path: '/app/accounts/cheques',         icon: FileText },
  { label: 'Transfer Funds',  path: '/app/accounts/transfer',        icon: ArrowLeftRight },
  { label: 'Budgets',         path: '/app/accounts/budgets',         icon: BarChart2 },
  { label: 'Reports',         path: '/app/accounts/reports',         icon: Scale },
  { label: 'Settings',        path: '/app/accounts/settings',        icon: Settings },
]

const HELPDESK_SUB_ITEMS = [
  { label: 'Analytics', path: '/app/helpdesk/analytics', icon: BarChart2 },
  { label: 'Tickets', path: '/app/helpdesk/tickets', icon: LifeBuoy },
  { label: 'Knowledge Base', path: '/app/helpdesk/knowledge-base', icon: FileText },
  { label: 'KB Admin', path: '/app/helpdesk/kb-admin', icon: FileText },
  { label: 'Widget', path: '/app/helpdesk/widget', icon: Package },
]

// Inventory OS — mirrors the blueprint's left-nav parent + its sub-pages.
const INVENTORY_SUB_ITEMS = [
  { label: 'Inventory Dashboard', path: '/app/inventory', icon: LayoutDashboard, end: true },
  // High in the list on purpose: on a warehouse floor this is the first thing
  // someone reaches for, not a tool buried under reports.
  { label: 'Scan', path: '/app/inventory/scan', icon: ScanLine },
  { label: 'Items', path: '/app/inventory/products', icon: Package },
  { label: 'Receiving voucher', path: '/app/inventory/vouchers/receipt', icon: PackagePlus },
  { label: 'Delivery voucher', path: '/app/inventory/vouchers/delivery', icon: PackageMinus },
  { label: 'Pick, pack & ship', path: '/app/inventory/fulfilment', icon: Truck },
  // Next to the daily work, not under Reports: a count is something people DO.
  { label: 'Physical counts', path: '/app/inventory/counts', icon: ClipboardCheck },
  { label: 'Internal delivery note', path: '/app/inventory/vouchers/internal', icon: ArrowLeftRight },
  // Right under the note it comes from — the consignment is what happens next.
  { label: 'Consignments', path: '/app/inventory/transfers', icon: Truck },
  { label: 'Loss & adjustment', path: '/app/inventory/vouchers/loss_adjustment', icon: Scale },
  { label: 'Warehouse', path: '/app/inventory/warehouses', icon: Warehouse },
  { label: 'Vendors', path: '/app/inventory/vendors', icon: Truck },
  { label: 'Purchase orders', path: '/app/inventory/purchase-orders', icon: ShoppingCart },
  { label: 'Vendor-managed', path: '/app/inventory/vmi', icon: Handshake },
  { label: 'Traceability', path: '/app/inventory/traceability', icon: Layers3 },
  { label: 'Inventory history', path: '/app/inventory/history', icon: History },
  { label: 'Analytics', path: '/app/inventory/analytics', icon: Activity },
  { label: 'Dead stock', path: '/app/inventory/dead-stock', icon: Hourglass },
  { label: 'Assets', path: '/app/inventory/assets', icon: Wrench },
  { label: 'Rentals', path: '/app/inventory/rentals', icon: CalendarRange },
  { label: 'Manufacturing', path: '/app/inventory/manufacturing', icon: Factory },
  { label: 'Report', path: '/app/inventory/reports', icon: BarChart3 },
  { label: 'Settings', path: '/app/inventory/settings', icon: Settings },
]

const PURCHASE_SUB_ITEMS = [
  { label: 'Dashboard',        path: '/app/purchase/dashboard',     icon: LayoutDashboard },
  { label: 'Items',            path: '/app/purchase/catalog',       icon: Package },
  { label: 'Vendors',          path: '/app/purchase/vendors',       icon: Truck },
  { label: 'Vendor-Items',     path: '/app/purchase/vendor-items',  icon: Boxes },
  { label: 'Purchase request', path: '/app/purchase/requests',      icon: ClipboardList },
  { label: 'Quotations',       path: '/app/purchase/quotations',    icon: FileSignature },
  { label: 'Purchase order',   path: '/app/purchase/orders',        icon: ShoppingBag },
  { label: 'Order Returns',    path: '/app/purchase/order-returns', icon: Undo2 },
  { label: 'Contracts',        path: '/app/purchase/contracts',     icon: FileText },
  { label: 'Debit Notes',      path: '/app/purchase/debit-notes',   icon: FileX },
  { label: 'Invoices',         path: '/app/purchase/invoices',      icon: Receipt },
  { label: 'Reports',          path: '/app/purchase/reports',       icon: BarChart2 },
  { label: 'Setting',          path: '/app/purchase/settings',      icon: Settings },
]

// Internal staff view of the TPV module.
const TPV_ADMIN_ITEMS = [
  { label: 'Dashboard',       path: '/app/tpv/dashboard', icon: LayoutDashboard },
  { label: 'Kickoff Meeting', path: '/app/tpv/kickoff',   icon: CalendarDays },
]
// TPV (vendor) login view — only their onboarding + their workforce.
const TPV_VENDOR_ITEMS = [
  { label: 'Onboarding', path: '/app/tpv/onboarding', icon: Rocket },
  { label: 'Workforce',  path: '/app/tpv/workforce',  icon: UserCheck },
]

// Every sub-page across the modules, tagged with its parent — so the sidebar
// search finds e.g. "Payroll", "Cheques" or "Debit Notes", not just top-level
// module names. Built from the same lists the nav renders, so it never drifts.
const SUBMODULE_SEARCH = [
  ...HR_RECRUITMENT_ITEMS.map(i => ({ ...i, module: 'HR' })),
  { ...HR_EMPLOYEES, module: 'HR' },
  ...HR_RECORDS_ITEMS.map(i => ({ ...i, module: 'HR' })),
  ...SALES_SUB_ITEMS.map(i => ({ ...i, module: 'Sales' })),
  ...ACCOUNTS_SUB_ITEMS.map(i => ({ ...i, module: 'Accounts' })),
  ...HELPDESK_SUB_ITEMS.map(i => ({ ...i, module: 'Helpdesk' })),
  ...INVENTORY_SUB_ITEMS.map(i => ({ ...i, module: 'Inventory' })),
  ...PURCHASE_SUB_ITEMS.map(i => ({ ...i, module: 'Purchase' })),
  ...TPV_ADMIN_ITEMS.map(i => ({ ...i, module: 'TPV' })),
]

export default function Sidebar({ collapsed, onToggle }) {
  const { user, tenant, logout } = useAuth()
  const { isDark } = useTheme()
  const navigate = useNavigate()
  const [hrExpanded, setHrExpanded] = useState(true)
  const [recruitExpanded, setRecruitExpanded] = useState(true)
  const [hrRecordsExpanded, setHrRecordsExpanded] = useState(true)
  const [salesExpanded, setSalesExpanded] = useState(true)
  const [accountsExpanded, setAccountsExpanded] = useState(true)
  const [helpdeskExpanded, setHelpdeskExpanded] = useState(true)
  const [inventoryExpanded, setInventoryExpanded] = useState(true)
  const [tpvExpanded, setTpvExpanded] = useState(true)
  const [purchaseExpanded, setPurchaseExpanded] = useState(true)
  const [pinOpen, setPinOpen] = useState(true)   // pinned module's sub-list open?
  const hrInstalled = isModuleInstalled('hr')
  // Admin/staff see Dashboard + Kickoff; a TPV (vendor) login sees Onboarding + Workforce.
  const tpvItems = ['third_party_vendor', 'vendor'].includes(user?.role)
    ? TPV_VENDOR_ITEMS
    : TPV_ADMIN_ITEMS
  const [activeLeadsCount, setActiveLeadsCount] = useState(null)
  const [moduleQuery, setModuleQuery] = useState('')
  const { pathname } = useLocation()
  // The module the current route lives in — pinned at the top of the sidebar.
  const activeModule = PINNED_MODULES.find(m => pathname.startsWith(m.base))
  // Its sub-pages, shown under the pin so you can move around the open module
  // without scrolling. Modules that are a single page (Tasks, Customers…) have none.
  const activeSubItems = {
    '/app/helpdesk': HELPDESK_SUB_ITEMS,
    '/app/inventory': INVENTORY_SUB_ITEMS,
    '/app/sales': SALES_SUB_ITEMS,
    '/app/accounts': ACCOUNTS_SUB_ITEMS,
    '/app/purchase': PURCHASE_SUB_ITEMS,
    '/app/hr': HR_ALL_LEAVES,
    '/app/tpv': tpvItems,
  }[activeModule?.base] || []
  // Which module's normal block to hide (it's shown in the pin instead). Only in
  // the expanded sidebar — the collapsed icon rail shows no pin, so hide nothing.
  const pinnedBase = !collapsed && activeSubItems.length > 0 ? activeModule?.base : null
  const q = moduleQuery.trim().toLowerCase()
  // Modules first, then any sub-page whose name matches — one combined list.
  const moduleResults = q ? [
    ...MODULE_SEARCH.filter(m => (m.label + ' ' + m.kw).toLowerCase().includes(q)).map(m => ({ ...m, sub: false })),
    ...SUBMODULE_SEARCH.filter(s => s.label.toLowerCase().includes(q)).map(s => ({ ...s, sub: true })),
  ].slice(0, 40) : []
  const goModule = (path) => { setModuleQuery(''); navigate(path) }

  useEffect(() => {
    leadApi.summary().then(s => setActiveLeadsCount(s.active)).catch(() => {})
  }, [])

  // REQ-04-lite: unseen-ticket badge. Polls every 30s; staff-only (portal roles
  // get a 403, so skip the request entirely). Errors leave the badge hidden.
  const isInternal = !!user && !EXTERNAL_ROLES.includes(user.role)
  const { data: unseen } = useQuery({
    queryKey: ['helpdesk-unseen-count'],
    queryFn: () => helpdeskApi.tickets.unseenCount(),
    enabled: isInternal,
    refetchInterval: 30000,
    refetchIntervalInBackground: false,
    staleTime: 15000,
    retry: false,
  })
  const unseenCount = unseen?.count ?? 0
  // Open / closed counts for the Tickets row — "O6 C5" style, colour-coded.
  const { data: statusCounts } = useQuery({
    queryKey: ['helpdesk-status-counts'],
    queryFn: () => helpdeskApi.tickets.statusCounts(),
    enabled: isInternal,
    refetchInterval: 30000,
    refetchIntervalInBackground: false,
    staleTime: 15000,
    retry: false,
  })

  const handleLogout = async () => { await logout(); navigate('/auth/login') }

  // ── HR nav render helpers (leaf link + collapsible sub-group header) ──
  // Kept local so the three-level HRMS tree stays DRY without touching the
  // Sales / Purchase / TPV blocks, which keep their existing two-level markup.
  const HrLeaf = ({ item, indent = '28px' }) => (
    <NavLink to={item.path}>
      {({ isActive }) => {
        const Icon = item.icon
        return (
          <div title={collapsed ? item.label : ''} className={clsx('nav-3d mb-0.5', isActive && 'nav-3d-active')} style={{ justifyContent: collapsed ? 'center' : undefined, paddingLeft: collapsed ? undefined : indent }}>
            <div className="flex-shrink-0 w-6 h-6 rounded-lg flex items-center justify-center" style={{ background: isActive ? 'rgba(255,255,255,0.15)' : 'rgba(124,58,237,0.06)' }}>
              <Icon size={12} />
            </div>
            {!collapsed && <span className="truncate text-xs">{item.label}</span>}
            {isActive && !collapsed && <div className="ml-auto w-1.5 h-1.5 rounded-full" style={{ background: '#c4b5fd' }} />}
          </div>
        )
      }}
    </NavLink>
  )

  const HrGroupHeader = ({ label, icon: Icon, expanded, onToggle }) => (
    <button onClick={onToggle} className="nav-3d mb-0.5 w-full" style={{ justifyContent: 'flex-start', paddingLeft: '28px' }}>
      <div className="flex-shrink-0 w-6 h-6 rounded-lg flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.1)' }}>
        <Icon size={12} />
      </div>
      <span className="truncate text-xs font-semibold flex-1 text-left">{label}</span>
      <ChevronDown size={12} className={clsx('transition-transform duration-200', expanded && 'rotate-180')} />
    </button>
  )

  return (
    <aside
      className={clsx('hidden md:flex flex-col sidebar-3d', collapsed && 'sidebar-collapsed')}
      style={{ width: collapsed ? 72 : 260 }}
    >
      {/* ── Logo ──────────────────────────────────────────── */}
      <div
        className="flex items-center gap-3 px-4 py-5 min-h-[64px] relative"
        style={{ borderBottom: '1px solid var(--border)' }}
      >
        {/* Sangoe logo mark */}
        <img
          src={sangoeIcon}
          alt="Sangoe"
          className="w-9 h-9 rounded-2xl object-contain flex-shrink-0 transition-transform duration-200 hover:scale-110"
        />

        {!collapsed && (
          <div className="overflow-hidden flex-1">
            <p className="text-sm font-black truncate" style={{ color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
              {tenant?.name || 'Sangoe CRM'}
            </p>
            <div className="flex items-center gap-1 mt-0.5">
              <div className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse" />
              <p className="text-[10px] truncate" style={{ color: 'var(--text-muted)' }}>
                {tenant?.subdomain || 'workspace'}.sangoe.in
              </p>
            </div>
          </div>
        )}

        {/* Version badge */}
        {!collapsed && (
          <span
            className="text-[9px] font-black px-1.5 py-0.5 rounded-md flex-shrink-0"
            style={{ background: 'rgba(124,58,237,0.15)', color: '#a78bfa', border: '1px solid rgba(124,58,237,0.2)' }}
          >
            v2
          </span>
        )}
      </div>

      {/* Module search — sits ABOVE the scrolling nav so it's always visible and
          the sticky module headers below can pin to the nav's top cleanly. */}
      {collapsed ? (
          <button onClick={() => navigate('/app/modules')} title="Search modules" className="nav-3d mb-2 w-full" style={{ justifyContent: 'center' }}>
            <div className="flex-shrink-0 w-7 h-7 rounded-xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.06)' }}>
              <Search size={14} />
            </div>
          </button>
        ) : (
          <div className="px-3 pt-2 pb-2 relative z-30" style={{ background: 'var(--bg-sidebar)' }}>
            <div className="relative">
              <Search size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
              <input
                value={moduleQuery}
                onChange={e => setModuleQuery(e.target.value)}
                onKeyDown={e => { if (e.key === 'Enter' && moduleResults[0]) goModule(moduleResults[0].path); if (e.key === 'Escape') setModuleQuery('') }}
                placeholder="Search modules…"
                className="w-full text-sm rounded-xl outline-none"
                style={{ padding: '8px 26px 8px 30px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}
              />
              {moduleQuery && (
                <button onClick={() => setModuleQuery('')} className="absolute right-2 top-1/2 -translate-y-1/2" aria-label="Clear">
                  <X size={13} style={{ color: 'var(--text-muted)' }} />
                </button>
              )}
            </div>
            {q && (
              <div className="mt-1 rounded-xl overflow-hidden max-h-[60vh] overflow-y-auto scrollbar-hide" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)' }}>
                {moduleResults.length === 0 ? (
                  <p className="text-xs px-3 py-3" style={{ color: 'var(--text-muted)' }}>Nothing matches “{moduleQuery}”.</p>
                ) : moduleResults.map(m => {
                  const Icon = m.icon
                  return (
                    <button key={`${m.sub ? 'sub' : 'mod'}-${m.path}`} onClick={() => goModule(m.path)}
                      className="w-full flex items-center gap-2 px-3 py-2 text-left transition-colors hover:bg-[rgba(124,58,237,0.08)]">
                      <Icon size={14} style={{ color: '#a78bfa' }} />
                      <span className="text-sm truncate" style={{ color: 'var(--text-h)' }}>{m.label}</span>
                      {m.sub && <span className="ml-auto text-[10px] font-semibold px-1.5 py-0.5 rounded shrink-0" style={{ background: 'rgba(124,58,237,0.12)', color: '#a78bfa' }}>{m.module}</span>}
                    </button>
                  )
                })}
              </div>
            )}
          </div>
        )}

      {/* ── Navigation ─────────────────────────────────────── */}
      {/* min-h-0 lets this flex child shrink so its own overflow scrolls, even
          with the fixed logo/search blocks taking space above it. */}
      <nav className="flex-1 min-h-0 pb-3 overflow-y-auto scrollbar-hide">
        {/* Pinned open-module — its header is sticky (stays put at the top while
            you scroll), and its sub-pages sit right under it. Both live INSIDE
            this scroll container, so the sidebar scrolls normally and the fixed
            footer can never squeeze the scroll area to nothing. The module's
            duplicate further down is hidden (see the per-module guards below). */}
        {!collapsed && activeModule && activeSubItems.length > 0 && (
          <>
            <div className="sb-pin-head sticky top-0 z-20 flex items-center gap-2 px-3 py-2 mb-0.5" style={{ background: 'var(--bg-sidebar)' }}>
              <button onClick={() => navigate(activeModule.path)} className="flex items-center gap-2 min-w-0 flex-1">
                <activeModule.icon size={15} style={{ color: '#a78bfa' }} className="shrink-0" />
                <span className="text-sm font-semibold truncate" style={{ color: 'var(--text-h)' }}>{activeModule.label}</span>
                <span className="text-[9px] font-black px-1.5 py-0.5 rounded shrink-0" style={{ background: 'rgba(124,58,237,0.22)', color: '#a78bfa' }}>OPEN</span>
              </button>
              <button onClick={() => setPinOpen(o => !o)} className="shrink-0 p-0.5" aria-label={pinOpen ? 'Collapse' : 'Expand'} title={pinOpen ? 'Collapse' : 'Expand'}>
                <ChevronDown size={14} className={clsx('transition-transform duration-200', !pinOpen && '-rotate-90')} style={{ color: '#a78bfa' }} />
              </button>
            </div>
            {pinOpen && activeSubItems.map(item => {
              const Icon = item.icon
              return (
                <NavLink key={`pin-${item.path}`} to={item.path} end={item.end}>
                  {({ isActive }) => (
                    <div className={clsx('nav-3d mb-0.5', isActive && 'nav-3d-active')} style={{ paddingLeft: '28px' }}>
                      <div className="flex-shrink-0 w-6 h-6 rounded-lg flex items-center justify-center" style={{ background: isActive ? 'rgba(255,255,255,0.15)' : 'rgba(124,58,237,0.06)' }}>
                        <Icon size={12} />
                      </div>
                      <span className="truncate text-xs">{item.label}</span>
                      {isActive && <div className="ml-auto w-1.5 h-1.5 rounded-full" style={{ background: '#c4b5fd' }} />}
                    </div>
                  )}
                </NavLink>
              )
            })}
            <div className="mx-3 my-2" style={{ borderTop: '1px solid var(--border)' }} />
          </>
        )}

        {/* Section label */}
        {!collapsed && <p className="label-caps px-5 mb-2">Main Menu</p>}

        {/* Modules link */}
        <NavLink to="/app/modules">
          {({ isActive }) => (
            <div title={collapsed ? 'Modules' : ''} className={clsx('nav-3d mb-0.5', isActive && 'nav-3d-active')} style={{ justifyContent: collapsed ? 'center' : undefined }}>
              <div className="flex-shrink-0 w-7 h-7 rounded-xl flex items-center justify-center" style={{ background: isActive ? 'rgba(255,255,255,0.15)' : 'rgba(124,58,237,0.06)' }}>
                <Package size={14} />
              </div>
              {!collapsed && <span className="truncate text-sm">Modules</span>}
              {isActive && !collapsed && <div className="ml-auto w-1.5 h-1.5 rounded-full" style={{ background: '#c4b5fd' }} />}
            </div>
          )}
        </NavLink>

        {NAV_ITEMS.map(({ label, icon: Icon, path }) => (
          <NavLink key={path} to={path}>
            {({ isActive }) => (
              <div
                title={collapsed ? label : ''}
                className={clsx('nav-3d mb-0.5', isActive && 'nav-3d-active')}
                style={{ justifyContent: collapsed ? 'center' : undefined }}
              >
                {/* Icon with 3D container */}
                <div
                  className="flex-shrink-0 w-7 h-7 rounded-xl flex items-center justify-center transition-all duration-200"
                  style={{
                    background: isActive
                      ? 'rgba(255,255,255,0.15)'
                      : 'rgba(124,58,237,0.06)',
                  }}
                >
                  <Icon size={15} />
                </div>
                {!collapsed && <span className="truncate text-sm">{label}</span>}
                {/* Active dot */}
                {isActive && !collapsed && (
                  <div className="ml-auto">
                    <div
                      className="w-1.5 h-1.5 rounded-full"
                      style={{ background: isDark ? '#c4b5fd' : '#ffffff', boxShadow: `0 0 6px ${isDark ? '#a78bfa' : '#fff'}` }}
                    />
                  </div>
                )}
              </div>
            )}
          </NavLink>
        ))}

        {/* ── HRMS sub-nav (when installed) ── */}
        {/* Hidden while HR is the pinned module — it's shown in the pin above. */}
        {hrInstalled && pinnedBase !== '/app/hr' && (
          <div className="mt-2">
            {!collapsed && <p className="label-caps px-5 mb-1 mt-3" style={{ color: '#a78bfa' }}>HR Module</p>}
            {/* HRMS parent toggle */}
            <button
              onClick={() => setHrExpanded(e => !e)}
              title={collapsed ? 'HRMS' : ''}
              className="nav-3d mb-0.5 w-full"
              style={{ justifyContent: collapsed ? 'center' : undefined, color: '#a78bfa' }}
            >
              <div className="flex-shrink-0 w-7 h-7 rounded-xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.15)' }}>
                <span style={{ fontSize: 13 }}>👥</span>
              </div>
              {!collapsed && <><span className="truncate text-sm font-semibold flex-1 text-left">HRMS</span><ChevronDown size={13} className={clsx('transition-transform duration-200', hrExpanded && 'rotate-180')} /></>}
            </button>

            {/* Collapsed rail: flatten every leaf to an icon (all pages reachable). */}
            {collapsed
              ? HR_ALL_LEAVES.map(item => <HrLeaf key={item.path} item={item} />)
              : hrExpanded && (
                <>
                  {/* Dashboard */}
                  <HrLeaf item={HR_DASHBOARD} />

                  {/* Recruitment group */}
                  <HrGroupHeader label="Recruitment" icon={Briefcase} expanded={recruitExpanded} onToggle={() => setRecruitExpanded(e => !e)} />
                  {recruitExpanded && HR_RECRUITMENT_ITEMS.map(item => <HrLeaf key={item.path} item={item} indent="44px" />)}

                  {/* Employees (top-level) */}
                  <HrLeaf item={HR_EMPLOYEES} />

                  {/* HR Records group */}
                  <HrGroupHeader label="HR Records" icon={FolderOpen} expanded={hrRecordsExpanded} onToggle={() => setHrRecordsExpanded(e => !e)} />
                  {hrRecordsExpanded && HR_RECORDS_ITEMS.map(item => <HrLeaf key={item.path} item={item} indent="44px" />)}
                </>
              )}
          </div>
        )}

        {/* ── ADMIN SECTION (Admin Only) ── */}
        {user?.role === 'admin' && (
          <div className="mt-2">
            {!collapsed && <p className="label-caps px-5 mb-1 mt-3" style={{ color: '#10b981' }}>Admin Tools</p>}
            <NavLink to="/app/admin/staff">
              {({ isActive }) => (
                <div
                  title={collapsed ? 'Staff Management' : ''}
                  className={clsx('nav-3d mb-0.5', isActive && 'nav-3d-active')}
                  style={{ justifyContent: collapsed ? 'center' : undefined }}
                >
                  <div
                    className="flex-shrink-0 w-7 h-7 rounded-xl flex items-center justify-center"
                    style={{
                      background: isActive
                        ? 'rgba(16,185,129,0.2)'
                        : 'rgba(16,185,129,0.1)',
                    }}
                  >
                    <UserCog size={14} style={{ color: '#10b981' }} />
                  </div>
                  {!collapsed && <span className="truncate text-sm" style={{ color: isActive ? '#10b981' : undefined }}>Staff Management</span>}
                  {isActive && !collapsed && (
                    <div className="ml-auto w-1.5 h-1.5 rounded-full" style={{ background: '#10b981' }} />
                  )}
                </div>
              )}
            </NavLink>
          </div>
        )}

        {/* ── Customers (standalone) ── */}
        <div className="mt-2">
          {!collapsed && <p className="label-caps px-5 mb-1 mt-3" style={{ color: '#a78bfa' }}>Customers</p>}
          <NavLink to="/app/customers">
            {({ isActive }) => (
              <div title={collapsed ? 'Customers' : ''} className={clsx('nav-3d mb-0.5', isActive && 'nav-3d-active')} style={{ justifyContent: collapsed ? 'center' : undefined }}>
                <div className="flex-shrink-0 w-7 h-7 rounded-xl flex items-center justify-center" style={{ background: isActive ? 'rgba(255,255,255,0.15)' : 'rgba(124,58,237,0.15)' }}>
                  <Building2 size={13} style={{ color: isActive ? '#fff' : '#a78bfa' }} />
                </div>
                {!collapsed && <span className="truncate text-sm font-semibold flex-1 text-left">Customer Directory</span>}
                {isActive && !collapsed && <div className="ml-auto w-1.5 h-1.5 rounded-full" style={{ background: '#c4b5fd' }} />}
              </div>
            )}
          </NavLink>
        </div>

        {/* ── Accounts Module sub-nav ── */}
        <div className={clsx('mt-2', pinnedBase === '/app/accounts' && 'hidden')}>
          {!collapsed && <p className="label-caps px-5 mb-1 mt-3" style={{ color: '#a78bfa' }}>Accounts & Finance</p>}
          <button
            onClick={() => setAccountsExpanded(e => !e)}
            title={collapsed ? 'Accounts & Finance' : ''}
            className="nav-3d mb-0.5 w-full"
            style={{ justifyContent: collapsed ? 'center' : undefined, color: '#a78bfa' }}
          >
            <div className="flex-shrink-0 w-7 h-7 rounded-xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.15)' }}>
              <Landmark size={13} style={{ color: '#a78bfa' }} />
            </div>
            {!collapsed && <><span className="truncate text-sm font-semibold flex-1 text-left">Accounts & Finance</span><ChevronDown size={13} className={clsx('transition-transform duration-200', accountsExpanded && 'rotate-180')} /></>}
          </button>
          {(accountsExpanded || collapsed) && ACCOUNTS_SUB_ITEMS.map(({ label, path, icon: Icon }) => (
            <NavLink key={path} to={path}>
              {({ isActive }) => (
                <div title={collapsed ? label : ''} className={clsx('nav-3d mb-0.5', isActive && 'nav-3d-active')} style={{ justifyContent: collapsed ? 'center' : undefined, paddingLeft: collapsed ? undefined : '28px' }}>
                  <div className="flex-shrink-0 w-6 h-6 rounded-lg flex items-center justify-center" style={{ background: isActive ? 'rgba(255,255,255,0.15)' : 'rgba(124,58,237,0.06)' }}>
                    <Icon size={12} />
                  </div>
                  {!collapsed && <span className="truncate text-xs">{label}</span>}
                  {isActive && !collapsed && <div className="ml-auto w-1.5 h-1.5 rounded-full" style={{ background: '#c4b5fd' }} />}
                </div>
              )}
            </NavLink>
          ))}
        </div>

        {/* ── Sales Module sub-nav ── */}
        <div className={clsx('mt-2', pinnedBase === '/app/sales' && 'hidden')}>
          {!collapsed && <p className="label-caps px-5 mb-1 mt-3" style={{ color: '#a78bfa' }}>Sales & Revenue</p>}
          <button
            onClick={() => setSalesExpanded(e => !e)}
            title={collapsed ? 'Sales & Revenue' : ''}
            className="nav-3d mb-0.5 w-full"
            style={{ justifyContent: collapsed ? 'center' : undefined, color: '#a78bfa' }}
          >
            <div className="flex-shrink-0 w-7 h-7 rounded-xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.15)' }}>
              <IndianRupee size={13} style={{ color: '#a78bfa' }} />
            </div>
            {!collapsed && <><span className="truncate text-sm font-semibold flex-1 text-left">Sales & Revenue</span><ChevronDown size={13} className={clsx('transition-transform duration-200', salesExpanded && 'rotate-180')} /></>}
          </button>
          {(salesExpanded || collapsed) && SALES_SUB_ITEMS.map(({ group, label, path, icon: Icon }, i) => (
            <div key={path}>
            {/* Mini group header — only when the group changes, and never in the collapsed icon rail */}
            {!collapsed && group && group !== SALES_SUB_ITEMS[i - 1]?.group && (
              <p className="label-caps px-5 mt-2 mb-1" style={{ paddingLeft: '28px', fontSize: '9px', opacity: 0.75 }}>{group}</p>
            )}
            <NavLink to={path}>
              {({ isActive }) => (
                <div title={collapsed ? label : ''} className={clsx('nav-3d mb-0.5', isActive && 'nav-3d-active')} style={{ justifyContent: collapsed ? 'center' : undefined, paddingLeft: collapsed ? undefined : '28px' }}>
                  <div className="flex-shrink-0 w-6 h-6 rounded-lg flex items-center justify-center" style={{ background: isActive ? 'rgba(255,255,255,0.15)' : 'rgba(124,58,237,0.06)' }}>
                    <Icon size={12} />
                  </div>
                  {!collapsed && <span className="truncate text-xs">{label}</span>}
                  {!collapsed && label === 'Leads' && activeLeadsCount > 0 && (
                    <span className="ml-auto text-[10px] font-bold px-1.5 py-0.5 rounded-full" style={{ background: isActive ? 'rgba(255,255,255,0.2)' : 'rgba(124,58,237,0.15)', color: isActive ? '#fff' : '#a78bfa' }}>
                      {activeLeadsCount}
                    </span>
                  )}
                  {isActive && !collapsed && label !== 'Leads' && <div className="ml-auto w-1.5 h-1.5 rounded-full" style={{ background: '#c4b5fd' }} />}
                </div>
              )}
            </NavLink>
            </div>
          ))}
        </div>

        {/* ── Helpdesk Module sub-nav ── */}
        <div className={clsx('mt-2', pinnedBase === '/app/helpdesk' && 'hidden')}>
          {!collapsed && <p className="label-caps px-5 mb-1 mt-3" style={{ color: '#22d3ee' }}>Helpdesk & Support</p>}
          <button
            onClick={() => setHelpdeskExpanded(e => !e)}
            title={collapsed ? 'Helpdesk & Support' : ''}
            className="nav-3d mb-0.5 w-full"
            style={{ justifyContent: collapsed ? 'center' : undefined, color: '#22d3ee' }}
          >
            <div className="flex-shrink-0 w-7 h-7 rounded-xl flex items-center justify-center" style={{ background: 'rgba(6,182,212,0.15)' }}>
              <LifeBuoy size={13} style={{ color: '#22d3ee' }} />
            </div>
            {!collapsed && <><span className="truncate text-sm font-semibold flex-1 text-left">Helpdesk & Support</span><ChevronDown size={13} className={clsx('transition-transform duration-200', helpdeskExpanded && 'rotate-180')} /></>}
          </button>
          {(helpdeskExpanded || collapsed) && HELPDESK_SUB_ITEMS.map(({ label, path, icon: Icon }) => {
            // The Tickets row shows Open / Closed counts (colour-coded) plus a
            // small "new" dot when there are unseen tickets.
            const isTickets = label === 'Tickets'
            const openN = statusCounts?.open ?? 0
            const closedN = statusCounts?.closed ?? 0
            const showCounts = isTickets && (openN > 0 || closedN > 0)
            const showDot = isTickets && unseenCount > 0
            return (
            <NavLink key={path} to={path}>
              {({ isActive }) => (
                <div title={collapsed ? label : ''} className={clsx('nav-3d mb-0.5', isActive && 'nav-3d-active')} style={{ justifyContent: collapsed ? 'center' : undefined, paddingLeft: collapsed ? undefined : '28px' }}>
                  <div className="relative flex-shrink-0 w-6 h-6 rounded-lg flex items-center justify-center" style={{ background: isActive ? 'rgba(255,255,255,0.15)' : 'rgba(6,182,212,0.06)' }}>
                    <Icon size={12} />
                    {/* Collapsed rail: a bare dot stands in for the counts. */}
                    {showDot && collapsed && (
                      <span className="absolute -top-0.5 -right-0.5 w-2 h-2 rounded-full" style={{ background: 'var(--color-danger-500)', border: '1px solid var(--bg-card)' }} />
                    )}
                  </div>
                  {!collapsed && <span className="truncate text-xs">{label}</span>}
                  {isTickets && !collapsed && showCounts && (
                    <span className="ml-auto flex items-center gap-1">
                      {showDot && <span className="w-1.5 h-1.5 rounded-full" style={{ background: 'var(--color-danger-500)' }} title={`${unseenCount} new`} />}
                      <span className="text-[10px] font-bold rounded-md px-1.5 py-0.5" title={`${openN} open`}
                        style={{ background: 'rgba(34,211,238,0.16)', color: '#22d3ee' }}>O{openN}</span>
                      <span className="text-[10px] font-bold rounded-md px-1.5 py-0.5" title={`${closedN} closed`}
                        style={{ background: 'rgba(16,185,129,0.16)', color: '#10b981' }}>C{closedN}</span>
                    </span>
                  )}
                  {isActive && !collapsed && !showCounts && <div className="ml-auto w-1.5 h-1.5 rounded-full" style={{ background: '#67e8f9' }} />}
                </div>
              )}
            </NavLink>
            )
          })}
        </div>

        {/* ── Inventory Module sub-nav ── */}
        <div className={clsx('mt-2', pinnedBase === '/app/inventory' && 'hidden')}>
          {!collapsed && <p className="label-caps px-5 mb-1 mt-3" style={{ color: '#10b981' }}>Inventory</p>}
          <button
            onClick={() => setInventoryExpanded(e => !e)}
            title={collapsed ? 'Inventory' : ''}
            className="nav-3d mb-0.5 w-full"
            style={{ justifyContent: collapsed ? 'center' : undefined, color: '#10b981' }}
          >
            <div className="flex-shrink-0 w-7 h-7 rounded-xl flex items-center justify-center" style={{ background: 'rgba(16,185,129,0.15)' }}>
              <Boxes size={13} style={{ color: '#10b981' }} />
            </div>
            {!collapsed && <><span className="truncate text-sm font-semibold flex-1 text-left">Inventory</span><ChevronDown size={13} className={clsx('transition-transform duration-200', inventoryExpanded && 'rotate-180')} /></>}
          </button>
          {(inventoryExpanded || collapsed) && INVENTORY_SUB_ITEMS.map(({ label, path, icon: Icon, end }) => (
            // `end` on the dashboard row — without it /app/inventory stays
            // highlighted while you're on any of its child pages.
            <NavLink key={path} to={path} end={end}>
              {({ isActive }) => (
                <div title={collapsed ? label : ''} className={clsx('nav-3d mb-0.5', isActive && 'nav-3d-active')} style={{ justifyContent: collapsed ? 'center' : undefined, paddingLeft: collapsed ? undefined : '28px' }}>
                  <div className="flex-shrink-0 w-6 h-6 rounded-lg flex items-center justify-center" style={{ background: isActive ? 'rgba(255,255,255,0.15)' : 'rgba(16,185,129,0.06)' }}>
                    <Icon size={12} />
                  </div>
                  {!collapsed && <span className="truncate text-xs">{label}</span>}
                  {isActive && !collapsed && <div className="ml-auto w-1.5 h-1.5 rounded-full" style={{ background: '#6ee7b7' }} />}
                </div>
              )}
            </NavLink>
          ))}
        </div>

        {/* ── Purchase Module sub-nav ── */}
        <div className={clsx('mt-2', pinnedBase === '/app/purchase' && 'hidden')}>
          {!collapsed && <p className="label-caps px-5 mb-1 mt-3" style={{ color: '#a78bfa' }}>Purchase</p>}
          <button
            onClick={() => setPurchaseExpanded(e => !e)}
            title={collapsed ? 'Purchase' : ''}
            className="nav-3d mb-0.5 w-full"
            style={{ justifyContent: collapsed ? 'center' : undefined, color: '#a78bfa' }}
          >
            <div className="flex-shrink-0 w-7 h-7 rounded-xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.15)' }}>
              <ShoppingCart size={13} style={{ color: '#a78bfa' }} />
            </div>
            {!collapsed && <><span className="truncate text-sm font-semibold flex-1 text-left">Purchase</span><ChevronDown size={13} className={clsx('transition-transform duration-200', purchaseExpanded && 'rotate-180')} /></>}
          </button>
          {(purchaseExpanded || collapsed) && PURCHASE_SUB_ITEMS.map(({ label, path, icon: Icon }) => (
            <NavLink key={path} to={path}>
              {({ isActive }) => (
                <div title={collapsed ? label : ''} className={clsx('nav-3d mb-0.5', isActive && 'nav-3d-active')} style={{ justifyContent: collapsed ? 'center' : undefined, paddingLeft: collapsed ? undefined : '28px' }}>
                  <div className="flex-shrink-0 w-6 h-6 rounded-lg flex items-center justify-center" style={{ background: isActive ? 'rgba(255,255,255,0.15)' : 'rgba(124,58,237,0.06)' }}>
                    <Icon size={12} />
                  </div>
                  {!collapsed && <span className="truncate text-xs">{label}</span>}
                  {isActive && !collapsed && <div className="ml-auto w-1.5 h-1.5 rounded-full" style={{ background: '#c4b5fd' }} />}
                </div>
              )}
            </NavLink>
          ))}
        </div>

        {/* ── TPV Module sub-nav ── */}
        <div className={clsx('mt-2', pinnedBase === '/app/tpv' && 'hidden')}>
          {!collapsed && <p className="label-caps px-5 mb-1 mt-3" style={{ color: '#a78bfa' }}>Thirdparty Vendor</p>}
          <button
            onClick={() => setTpvExpanded(e => !e)}
            title={collapsed ? 'Thirdparty Vendor' : ''}
            className="nav-3d mb-0.5 w-full"
            style={{ justifyContent: collapsed ? 'center' : undefined, color: '#a78bfa' }}
          >
            <div className="flex-shrink-0 w-7 h-7 rounded-xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.15)' }}>
              <Shield size={13} style={{ color: '#a78bfa' }} />
            </div>
            {!collapsed && <><span className="truncate text-sm font-semibold flex-1 text-left">Thirdparty Vendor</span><ChevronDown size={13} className={clsx('transition-transform duration-200', tpvExpanded && 'rotate-180')} /></>}
          </button>
          {(tpvExpanded || collapsed) && tpvItems.map(({ label, path, icon: Icon }) => (
            <NavLink key={path} to={path}>
              {({ isActive }) => (
                <div title={collapsed ? label : ''} className={clsx('nav-3d mb-0.5', isActive && 'nav-3d-active')} style={{ justifyContent: collapsed ? 'center' : undefined, paddingLeft: collapsed ? undefined : '28px' }}>
                  <div className="flex-shrink-0 w-6 h-6 rounded-lg flex items-center justify-center" style={{ background: isActive ? 'rgba(255,255,255,0.15)' : 'rgba(124,58,237,0.06)' }}>
                    <Icon size={12} />
                  </div>
                  {!collapsed && <span className="truncate text-xs">{label}</span>}
                  {isActive && !collapsed && <div className="ml-auto w-1.5 h-1.5 rounded-full" style={{ background: '#c4b5fd' }} />}
                </div>
              )}
            </NavLink>
          ))}
        </div>
      </nav>

      {/* ── Bottom Controls ────────────────────────────────── */}
      <div className="p-3 space-y-1" style={{ borderTop: '1px solid var(--border)' }}>
        {/* Theme toggle lives in the header (Sun/Moon icon) — no duplicate here. */}

        {/* User profile card */}
        {!collapsed && (
          <div
            className="flex items-center gap-2.5 px-3 py-2.5 rounded-xl"
            style={{
              background: isDark
                ? 'linear-gradient(135deg,rgba(124,58,237,0.12),rgba(91,33,182,0.08))'
                : 'linear-gradient(135deg,rgba(124,58,237,0.08),rgba(124,58,237,0.04))',
              border: '1px solid var(--border-purple)',
              boxShadow: '0 2px 8px rgba(124,58,237,0.1)',
            }}
          >
            <div
              className="w-8 h-8 rounded-xl flex items-center justify-center text-xs font-black text-white flex-shrink-0"
              style={{
                background: 'linear-gradient(145deg,#9f67ff,#7C3AED,#5b21b6)',
                boxShadow: '0 3px 10px rgba(124,58,237,0.4), inset 0 1px 0 rgba(255,255,255,0.2)',
              }}
            >
              {user?.name?.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2) || 'U'}
            </div>
            <div className="flex-1 overflow-hidden">
              <p className="text-xs font-semibold truncate" style={{ color: 'var(--text-h)' }}>{user?.name}</p>
              <p className="text-[10px] truncate capitalize" style={{ color: 'var(--text-muted)' }}>
                {user?.role?.replace(/_/g, ' ')}
              </p>
            </div>
            <Zap size={11} style={{ color: '#a78bfa', flexShrink: 0 }} />
          </div>
        )}

        {/* Logout */}
        <button
          onClick={handleLogout}
          title="Logout"
          className="w-full flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-200"
          style={{ color: '#f87171', justifyContent: collapsed ? 'center' : undefined }}
          onMouseEnter={e => e.currentTarget.style.background = 'rgba(239,68,68,0.08)'}
          onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
        >
          <div className="w-7 h-7 rounded-xl flex items-center justify-center" style={{ background: 'rgba(239,68,68,0.1)' }}>
            <LogOut size={14} />
          </div>
          {!collapsed && <span>Logout</span>}
        </button>
      </div>

      {/* ── Collapse Toggle ────────────────────────────────── */}
      <button
        onClick={onToggle}
        className="absolute -right-4 top-20 w-8 h-8 rounded-xl flex items-center justify-center transition-all duration-200 z-50"
        style={{
          background: 'var(--bg-card)',
          border: '1px solid var(--border-purple)',
          color: 'var(--text-muted)',
          boxShadow: '0 4px 14px rgba(124,58,237,0.2), inset 0 1px 0 var(--card-shine)',
        }}
        onMouseEnter={e => {
          e.currentTarget.style.background = 'linear-gradient(145deg,#9f67ff,#7C3AED)'
          e.currentTarget.style.color = '#fff'
          e.currentTarget.style.boxShadow = '0 6px 20px rgba(124,58,237,0.45)'
        }}
        onMouseLeave={e => {
          e.currentTarget.style.background = 'var(--bg-card)'
          e.currentTarget.style.color = 'var(--text-muted)'
          e.currentTarget.style.boxShadow = '0 4px 14px rgba(124,58,237,0.2), inset 0 1px 0 var(--card-shine)'
        }}
      >
        {collapsed ? <ChevronRight size={13} /> : <ChevronLeft size={13} />}
      </button>
    </aside>
  )
}
