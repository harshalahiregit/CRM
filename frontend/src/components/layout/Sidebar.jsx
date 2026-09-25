import {
  Activity, ArrowLeftRight, Award, Banknote, BarChart2, BarChart3, Bell, BookOpen, BookText, Boxes, Briefcase, Bug, Building2, CalendarCheck, CalendarClock, CalendarDays, CalendarOff, CalendarRange, CheckSquare, ChevronDown, ChevronLeft, ChevronRight, ClipboardCheck, ClipboardList, Clock, Contact, CreditCard, Factory, FileCheck2, FileQuestion, FileSignature, FileText, FileX, FolderOpen, Globe, GraduationCap, Handshake, HelpCircle, History, Hourglass, IndianRupee, Landmark, Layers3, LayoutDashboard, LayoutTemplate, LifeBuoy, Link2, LogOut, MessageSquare, Network, Package, PackageMinus, PackagePlus, PartyPopper, PenLine, Receipt, RefreshCw, Rocket, Scale, ScanLine, Search, Settings, Settings2, Shield, ShieldCheck, ShoppingBag, ShoppingCart, SlidersHorizontal, Stethoscope, TrendingUp, Truck, Undo2, User, UserCheck, UserCog, UserPlus, UserRound, Users, Wallet, Warehouse, Wrench, X, Zap, Container,
} from 'lucide-react'
import { NavLink, useNavigate, useLocation } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useAuth } from '@/context/AuthContext'
import { canUseSire } from '@/lib/sire/access'
import { canUseStos } from '@/lib/stos/access'
import { useTheme } from '@/context/ThemeContext'
import { helpdeskApi } from '@/services/helpdeskApi'
import sangoeIcon from '@/assets/sangoe-icon.png'
import { Fragment, useState, useEffect, useRef } from 'react'
import clsx from 'clsx'
import { leadApi } from '@/services/leadApi'
// The Purchase and TPV trees, imported rather than restated. Both used to be
// written out again below in a different shape, so the sidebar and the module
// page disagreed about what the sections were called and what was in them.
import { PURCHASE_GROUPS } from '@/modules/purchase/purchaseNav'
import { TPV_GROUPS } from '@/modules/tpv/tpvNav'

// NOTE: 'Contacts' and 'Deals' were removed — both were dead "Coming Soon"
// links. Contacts are the existing Customer module's contacts, and there is no
// separate Deal entity by design (leads are the pipeline, same as the old CRM).
// Portal roles never reach the staff ticket queue, so don't poll the badge for them.
const EXTERNAL_ROLES = ['client', 'vendor', 'third_party_vendor']

const NAV_ITEMS = [
  { label: 'Dashboard', icon: LayoutDashboard, path: '/app/dashboard' },
  { label: 'Tasks', icon: CheckSquare, path: '/app/tasks' },
  { label: 'Projects', icon: FolderOpen, path: '/app/projects' },
  // Meetings sits here, not under TPV, because it is now company-wide: every
  // internal role can call one and sees their own. It stayed invisible to
  // everyone who never opens TPV or Purchase while living only in those two
  // sidebars. EXTERNAL_ROLES never see this list, so no gate is needed.
  { label: 'Meetings', icon: CalendarDays, path: '/app/meetings' },
  // Contracts is its own module, not part of Sales: an agreement is signed with
  // customers AND vendors, so burying it under one of them hides it from the
  // other. Top-level, which places it above the Purchase module block below.
  { label: 'Contracts', icon: FileSignature, path: '/app/contracts' },
  { label: 'Settings', icon: Settings, path: '/app/settings' },
]

// The modules the sidebar search jumps to — the top-level module landing pages,
// searched by name. Keywords widen matches (e.g. "stock" → Inventory).
const MODULE_SEARCH = [
  { label: 'Modules',    path: '/app/modules',          icon: Package,         kw: 'marketplace install' },
  { label: 'Dashboard',  path: '/app/dashboard',        icon: LayoutDashboard, kw: 'home' },
  { label: 'Tasks',      path: '/app/tasks',            icon: CheckSquare,     kw: 'todo' },
  { label: 'Projects',   path: '/app/projects',         icon: FolderOpen,      kw: '' },
  { label: 'Meetings',   path: '/app/meetings',         icon: CalendarDays,    kw: 'meeting mom minutes agenda kickoff' },
  { label: 'Contracts',  path: '/app/contracts',        icon: FileSignature,   kw: 'agreement sign signature renewal nda' },
  { label: 'Helpdesk',   path: '/app/helpdesk/tickets', icon: LifeBuoy,        kw: 'tickets support' },
  { label: 'Inventory',  path: '/app/inventory',        icon: Boxes,           kw: 'stock warehouse items' },
  { label: 'Sales',      path: '/app/sales/dashboard',  icon: TrendingUp,      kw: 'revenue leads' },
  { label: 'Accounts',   path: '/app/accounts',         icon: Landmark,        kw: 'finance ledger' },
  { label: 'HR',         path: '/app/hr/dashboard',     icon: Users,           kw: 'recruitment employees payroll' },
  { label: 'Purchase',   path: '/app/purchase/dashboard', icon: ShoppingCart,  kw: 'procurement orders' },
  { label: 'TPV',        path: '/app/tpv/dashboard',    icon: UserCheck,       kw: 'third party vendor workforce' },
  { label: 'Customers',  path: '/app/customers',        icon: Building2,       kw: 'clients directory accounts' },
  { label: 'Compliance', path: '/app/tpv/compliance',   icon: ShieldCheck,     kw: 'hsse checklists' },
  // CTD §4 — lands on the entry point, not on the Orders list. The module used
  // to open on a table, which is why nobody could find the search.
  { label: 'Transport',  path: '/app/transport', icon: Truck,          kw: 'stos trips orders logistics haulage fleet vehicles drivers workshop telematics search container lr' },

  // Added to the nav but never to this list, so they were unreachable by
  // search while sitting in plain sight in the sidebar. `when` gates a result
  // the same way the nav gates the section it belongs to -- offering somebody a
  // destination that answers 403 is worse than not offering it.
  { label: 'SIRE',       path: '/app/sire/dashboard',   icon: Bug,             kw: 'issues defects bugs engineering quality releases', when: canUseSire },
  // The second 'Transport' entry that used to sit here pointed at /app/stos/fleet
  // and was indistinguishable from the one above — same label, same Truck icon.
  // Its keywords moved up so a search for "fleet" or "telematics" still lands.
  { label: 'Settings',   path: '/app/settings',         icon: Settings,        kw: 'preferences configuration company profile' },
  { label: 'Staff Management', path: '/app/admin/staff', icon: UserCog,        kw: 'users team roles permissions staff admin', when: (u) => u?.role === 'admin' },
]

// NOTE: PINNED_MODULES was removed with the pinned-header block it fed. The
// sidebar no longer derives anything from the current route — sections open on
// a click and only on a click.

// ── HRMS sidebar structure (paths/APIs/permissions unchanged) ──
//   HRMS
//   ├── Dashboard
//   ├── Recruitment        (collapsible group)
//   ├── Employees
//   └── HR Records         (collapsible group) → Organization Setup
const HR_DASHBOARD = { label: 'Dashboard', path: '/app/hr/dashboard', icon: LayoutDashboard }
const HR_EMPLOYEES = { label: 'Employees', path: '/app/hr/employees', icon: Building2 }

const HR_RECRUITMENT_ITEMS = [
  { label: 'Manpower Requests',  path: '/app/hr/manpower-requests',   icon: ClipboardList },
  { label: 'Job Postings',       path: '/app/hr/jobs',                icon: Briefcase },
  { label: 'Candidates',         path: '/app/hr/candidates',          icon: Users },
  { label: 'Interviews',         path: '/app/hr/interviews',          icon: CalendarClock },
  // Belongs with interviews, not adrift in a general list.
  { label: 'Interview Questions', path: '/app/hr/interview-questions', icon: HelpCircle },
  { label: 'Offer Letters',      path: '/app/hr/offers',              icon: FileSignature },
  { label: 'Onboarding',         path: '/app/hr/onboarding',          icon: UserPlus },
]

// ── HR, grouped by what somebody came to do ───────────────────────────────────
//
// These were one flat list of twenty entries under 'HR Records', which is a list
// you scan rather than read. Grouped by task instead: attendance things together,
// requests together, the employee lifecycle together. Each group is small enough
// to take in at a glance, and the rail collapses to a handful of rows.

const HR_ATTENDANCE_ITEMS = [
  { label: 'Attendance Register', path: '/app/hr/attendance',          icon: CalendarCheck },
  { label: 'Correction Requests', path: '/app/hr/corrections',         icon: PenLine },
  { label: 'Attendance Reports',  path: '/app/hr/attendance-reports',  icon: BarChart3 },
  { label: 'Holidays & Events',   path: '/app/hr/holidays',            icon: PartyPopper },
]

// Expense and Advance live here rather than as groups of their own — they are
// two more things an employee asks for, alongside leave.
const HR_REQUEST_ITEMS = [
  { label: 'Leave Management', path: '/app/hr/leave-management', icon: CalendarDays },
  { label: 'Expense Claims',   path: '/app/hr/expense-claims',   icon: Receipt },
  { label: 'Advances',         path: '/app/hr/advances',         icon: Wallet },
]

const HR_LIFECYCLE_ITEMS = [
  { label: 'Probation Management',   path: '/app/hr/probation-management',  icon: ShieldCheck },
  { label: 'Performance',            path: '/app/hr/performance',           icon: Award },
  { label: 'Learning & Development', path: '/app/hr/learning-development',  icon: GraduationCap },
  { label: 'Employee Surveys',       path: '/app/hr/surveys',               icon: ClipboardList },
  { label: 'Exit Management',        path: '/app/hr/exit-management',       icon: LogOut },
  // Occupational-health records for people who belong to no vendor: internal
  // staff, client contacts and site visitors. The doctor portal has been filing
  // these since it was built and nothing could read them back.
  { label: 'Medical Records',        path: '/app/medical/general',          icon: Stethoscope },
]

const HR_ORG_ITEMS = [
  /*
   * First, because it is the answer to the question the other three only
   * partly answer. The HR masters are spread across seven screens — leave
   * types under Leave Management, shifts under HR Operations, and so on —
   * which is fine once you know, and a dead end when you do not. This entry
   * is the index; it moves nothing.
   */
  { label: 'HR Configuration',   path: '/app/hr/configuration',      icon: SlidersHorizontal },
  { label: 'Organization Setup', path: '/app/hr/organization-setup', icon: FolderOpen },
  { label: 'Organization Chart', path: '/app/hr/org-chart',          icon: Network },
  { label: 'HR Operations',      path: '/app/hr/operations',         icon: Settings2 },
]

// Left at the top level because they are opened often and on their own.
const HR_TOP_LEVEL = [
  { label: 'Payroll',        path: '/app/hr/payroll',                icon: IndianRupee },
  { label: 'Notifications',  path: '/app/hr/settings/notifications', icon: Bell },
  { label: 'Demo Requests',  path: '/app/hr/demo-requests',          icon: MessageSquare },
  { label: 'HR Settings',    path: '/app/hr/settings',               icon: Settings2 },
]

// ── A person's own requests ───────────────────────────────────────────────────
//
// NOT duplicates of the screens above, though they look like it. The API has two
// surfaces on purpose: /hr/me/* is auth-only and returns your own, while
// /hr/advances and /hr/corrections require hr.advances and hr.manage. Somebody
// without those permissions opening the management screen gets a 403, so these
// are the only way they can see what they asked for.
//
// Hidden from anyone who can already see the management screens, which is what
// makes an admin's rail free of the pair. When per-permission menus arrive this
// condition is the thing to replace.
const HR_MINE_ITEMS = [
  { label: 'My Leave',       path: '/app/hr/my-leave',        icon: CalendarOff },
  { label: 'My Expenses',    path: '/app/hr/my-expenses',     icon: IndianRupee },
  { label: 'My Advances',    path: '/app/hr/my-advances',     icon: Banknote },
  { label: 'My Corrections', path: '/app/hr/my-corrections',  icon: PenLine },
]

// Flat list of every HR leaf — used only for the collapsed icon rail.
const HR_ALL_LEAVES = [
  HR_DASHBOARD, HR_EMPLOYEES,
  ...HR_RECRUITMENT_ITEMS, ...HR_ATTENDANCE_ITEMS, ...HR_REQUEST_ITEMS,
  ...HR_LIFECYCLE_ITEMS, ...HR_ORG_ITEMS, ...HR_TOP_LEVEL, ...HR_MINE_ITEMS,
]

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

// SIRE - the engineering defect track. Deliberately its own section and NOT
// under Helpdesk: a ticket closes when the requester is happy, a SIRE case when
// the fix ships verified. Same word "issue", different object.
const SIRE_SUB_ITEMS = [
  { label: 'Dashboard',     path: '/app/sire/dashboard',     icon: LayoutDashboard },
  { label: 'My Work',       path: '/app/sire/my-work',       icon: CheckSquare },
  { label: 'Releases',      path: '/app/sire/releases',      icon: Rocket },
  { label: 'Quality',       path: '/app/sire/quality',       icon: ShieldCheck },
  { label: 'Insights',      path: '/app/sire/insights',      icon: BarChart3 },
]

// STOS (Sangoe Transport OS) - the FLEET & ASSET control tower. A vehicle is not
// stock, it is an operating asset with papers, a device and a workshop history.
//
// These are no longer a section of their own. There used to be TWO top-level
// entries both labelled "Transport" with the same Truck icon — one for
// operations, one for fleet — and nothing on screen told them apart. They are
// now one section; see TRANSPORT_SUB_ITEMS.
//
// As of 2026-09-17 the DATA is merged too (D-62): one `vehicles` master, one
// driver directory. The fleet screens live in TRANSPORT_SUB_ITEMS below, each
// carrying its own `canUseStos` gate.

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

// Purchase and TPV: the flat page lists that used to live here are gone.
//
// The sidebar kept its own copy of each module tree while the module page kept
// another. Purchase was the worse of the two — fifty pages in one undifferentiated
// column here, the same pages sorted into ten clusters up there, with labels that
// had already drifted apart ("Workforce" meant two different screens depending on
// which list you read). Both now render purchaseNav.js / tpvNav.js, so there is one
// tree and one set of names.
//
// Flattened views, for the collapsed icon rail and the search box — derived, so a
// page added to a cluster is reachable and findable without a second edit here.
const flatten = (groups) => groups.flatMap(g => g.items)
const PURCHASE_SUB_ITEMS = flatten(PURCHASE_GROUPS)
const TPV_ADMIN_ITEMS = flatten(TPV_GROUPS)

// TPV (vendor) login view — only their onboarding + their workforce.
const TPV_VENDOR_ITEMS = [
  { label: "Onboarding", path: "/app/tpv/onboarding", icon: Rocket },
  { label: "Workforce",  path: "/app/tpv/workforce",  icon: UserCheck },
]

// Every sub-page across the modules, tagged with its parent — so the sidebar
// search finds e.g. "Payroll", "Cheques" or "Debit Notes", not just top-level
// module names. Built from the same lists the nav renders, so it never drifts.
// Transport OS (STOS) sub-nav. Only the two screens SNG-TRN-006/007 built —
// a nav entry that 404s is worse than a missing one, so later clusters arrive
// with the tickets that build them.
const TRANSPORT_SUB_ITEMS = [
  // First on the rail, because CTD §4 calls it "the preferred entry point".
  { label: 'Find',             path: '/app/transport',          icon: Search, end: true },
  { label: 'Transport Orders', path: '/app/transport/orders',   icon: Package },
  { label: 'Trips',            path: '/app/transport/trips',    icon: Truck },
  { label: 'Consignments',     path: '/app/transport/consignments', icon: Boxes },
  { label: 'Containers',       path: '/app/transport/containers', icon: Container },
  // Fleet's screens (Person 2), merged into this one rail on 2026-09-17 — D-62.
  // They replaced P1's `Vehicles`/`Drivers` placeholders at the same position,
  // and the data behind them is now one master, not two. Each keeps its own
  // `when`, so a customer never sees a fleet board that would answer 403.
  { label: 'Fleet',            path: '/app/transport/fleet',    icon: Truck,      when: canUseStos },
  // T-54. Missed here when it was added to TransportLayout's tab bar, and
  // found by opening the app: the two rails are separate lists, so a trailer
  // register reachable from one and not the other is simply lost to anyone
  // who navigates by the sidebar.
  { label: 'Trailers',         path: '/app/transport/trailers', icon: Container,  when: canUseStos },
  { label: 'Drivers',          path: '/app/transport/drivers',  icon: UserRound,  when: canUseStos },
  { label: 'Workshop',         path: '/app/transport/workshop', icon: Wrench,     when: canUseStos },
]

const SUBMODULE_SEARCH = [
  ...HR_RECRUITMENT_ITEMS.map(i => ({ ...i, module: 'HR' })),
  { ...HR_EMPLOYEES, module: 'HR' },
  ...HR_ATTENDANCE_ITEMS.map(i => ({ ...i, module: 'HR' })),
  ...HR_REQUEST_ITEMS.map(i => ({ ...i, module: 'HR' })),
  ...HR_LIFECYCLE_ITEMS.map(i => ({ ...i, module: 'HR' })),
  ...HR_ORG_ITEMS.map(i => ({ ...i, module: 'HR' })),
  ...HR_TOP_LEVEL.map(i => ({ ...i, module: 'HR' })),
  ...HR_MINE_ITEMS.map(i => ({ ...i, module: 'HR' })),
  ...SALES_SUB_ITEMS.map(i => ({ ...i, module: 'Sales' })),
  ...ACCOUNTS_SUB_ITEMS.map(i => ({ ...i, module: 'Accounts' })),
  ...HELPDESK_SUB_ITEMS.map(i => ({ ...i, module: 'Helpdesk' })),
  ...INVENTORY_SUB_ITEMS.map(i => ({ ...i, module: 'Inventory' })),
  ...PURCHASE_SUB_ITEMS.map(i => ({ ...i, module: 'Purchase' })),
  ...TPV_ADMIN_ITEMS.map(i => ({ ...i, module: 'TPV' })),
  ...TRANSPORT_SUB_ITEMS.map(i => ({ ...i, module: 'Transport' })),

  // Same omission one level down: "My Work", "Releases" and "Workshop" are real
  // screens somebody will search for by name.
  ...SIRE_SUB_ITEMS.map(i => ({ ...i, module: 'SIRE', when: canUseSire })),
  // The fleet screens are already part of TRANSPORT_SUB_ITEMS above, which
  // contains it, and listing it twice would show every fleet screen twice in
  // search results. Each item carries its own `when: canUseStos`, which the
  // spread preserves.
]

/**
 * @param inDrawer  this instance IS the mobile drawer's contents, so it must
 *                  render below 768px — where the standalone desktop copy is
 *                  deliberately hidden. Two instances are mounted (see
 *                  AppShell) and exactly one is on screen at any width; without
 *                  this flag the same `hidden md:flex` hid both, and a phone got
 *                  a hamburger that opened an empty drawer.
 */
export default function Sidebar({ collapsed, onToggle, openSection, toggleSection, isGroupOpen, toggleGroup, inDrawer = false }) {
  const { user, tenant, logout, canSee, scopeOf } = useAuth()

  // Whether this person runs HR for the company, or only has their own record
  // here. Answered by the server through the permission grid — the sidebar used
  // to guess from `user.role`, so every management item rendered for everybody
  // and each one 403'd on click, and a team lead who genuinely could approve
  // things was shown the same menu as somebody who could not.
  const managesHr = canSee('hr_attendance')
  const { isDark } = useTheme()
  const navigate = useNavigate()
  /**
   * ONE open module at a time. The id is owned by AppShell (see
   * sidebarSection.js) because two Sidebars are mounted — the mobile drawer and
   * the desktop one — and they must not disagree about what is open.
   *
   * The accordion is true by construction: a single id has nowhere to record a
   * second open section, so opening one closes the other with no bookkeeping.
   *
   * SCROLL, on a reload: restoring a section is not enough on its own. Even
   * fully collapsed the nav is ~24 rows — fourteen links plus ten group labels —
   * which overflows a laptop viewport, so Inventory, Purchase and TPV sit below
   * the fold before anything is open. A page load resets scrollTop to 0, so a
   * restored lower section reopened correctly and was simply never seen; only HR,
   * being first, looked like it worked. The effect below brings it into view.
   *
   * It adjusts the nav's OWN scrollTop rather than calling scrollIntoView. The
   * mobile Sidebar is always mounted, just translated off-canvas, and
   * scrollIntoView on a hidden copy would scroll its ancestors — the window
   * included. Touching nav.scrollTop can only ever move this one element.
   *
   * Nothing is derived from the current route. Sections open on a click and
   * only on a click; the last click is what gets remembered.
   */
  const navRef = useRef(null)
  /**
   * Bring the open section into view — on a reload AND on a click.
   *
   * This used to run on mount only, so clicking a section never scrolled. That
   * left the LAST section unusable: click "Thirdparty Vendor" and its header is
   * already visible (you just clicked it), so everything it opens lands below
   * the fold with nothing bringing it back.
   *
   * Measuring the header alone is what made it useless here — the header being
   * on screen says nothing about whether its ITEMS are. So measure the section's
   * whole block: header plus everything the click revealed. If that block does
   * not fit, pull the header up towards the top of the nav, which shows as many
   * of its items as the space allows.
   *
   * Only on opening. Scrolling when a section CLOSES would jump the list under
   * someone who just clicked to collapse it.
   */
  useEffect(() => {
    if (!openSection) return          // closing: leave the scroll alone
    const nav = navRef.current
    const header = nav?.querySelector(`[data-section="${openSection}"]`)
    if (!nav || !header) return

    // The block wrapping this section's header and its items. Falling back to
    // the header keeps this a no-op rather than a crash if the markup changes.
    const block = header.closest('[data-section-block]') || header
    const navBox = nav.getBoundingClientRect()
    const blockBox = block.getBoundingClientRect()
    const headBox = header.getBoundingClientRect()

    const fits = blockBox.top >= navBox.top && blockBox.bottom <= navBox.bottom
    if (fits) return                  // already fully visible: do not twitch

    // Header towards the top, so the items below it get the remaining space.
    // The browser clamps to the real scroll range, so a short last section
    // simply stops where the content ends.
    nav.scrollTop += headBox.top - navBox.top - 8
  }, [openSection])

  // HR's inner groups (Recruitment, HR Records) start closed and open only on a
  // click — and independently of each other, unlike the module accordion above.
  // State is owned by AppShell so the two mounted Sidebars agree, and persisted
  // so a refresh does not undo the click. See sidebarSection.js.
  // Admin/staff see the ten TPV sections; a TPV (vendor) login sees only their
  // own Onboarding + Workforce.
  const isVendorLogin = ['third_party_vendor', 'vendor'].includes(user?.role)
  const tpvItems = isVendorLogin ? TPV_VENDOR_ITEMS : TPV_ADMIN_ITEMS
  const [activeLeadsCount, setActiveLeadsCount] = useState(null)
  const [moduleQuery, setModuleQuery] = useState('')
  const { pathname } = useLocation()
  const q = moduleQuery.trim().toLowerCase()
  // Modules first, then any sub-page whose name matches — one combined list.
  // `when` is optional: an entry without one is open to anybody who can see the
  // sidebar at all. With one, the search hides what the nav would hide -- the
  // two disagreeing is how a search result becomes a 403.
  const allowed = (entry) => !entry.when || entry.when(user)

  const moduleResults = q ? [
    ...MODULE_SEARCH
      .filter(allowed)
      .filter(m => (m.label + ' ' + m.kw).toLowerCase().includes(q))
      .map(m => ({ ...m, sub: false })),
    ...SUBMODULE_SEARCH
      .filter(allowed)
      // Match the module name too, so "sire" finds My Work and "hr" finds
      // Payroll -- searching for a module and expecting its pages is the whole
      // reason somebody types a module name into a box labelled Search modules.
      .filter(s => (s.label + ' ' + (s.module || '')).toLowerCase().includes(q))
      .map(s => ({ ...s, sub: true })),
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

  // ── Nav render helpers (leaf link + collapsible sub-group header) ──
  // Written for HR's three-level tree; Purchase and TPV use them too now that
  // their clusters live in the sidebar rather than in a strip along the top of
  // the page.
  const NavLeaf = ({ item, indent = '28px' }) => (
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

  const NavGroupHeader = ({ label, icon: Icon, expanded, onToggle }) => (
    <button onClick={onToggle} className="nav-3d mb-0.5 w-full" style={{ justifyContent: 'flex-start', paddingLeft: '28px' }}>
      <div className="flex-shrink-0 w-6 h-6 rounded-lg flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.1)' }}>
        <Icon size={12} />
      </div>
      <span className="truncate text-xs font-semibold flex-1 text-left">{label}</span>
      <ChevronDown size={12} className={clsx('transition-transform duration-200', expanded && 'rotate-180')} />
    </button>
  )

  /**
   * A module's clusters, rendered as collapsible folders.
   *
   * Purchase and TPV define one tree each (purchaseNav.js / tpvNav.js) and it is
   * drawn HERE, once. It used to be drawn twice: this sidebar named the sections
   * and the module page repeated the same names in a pill rail across the top,
   * with a second rail under it for whichever section was open — so "Vendors"
   * and "Vendor Master" both appeared twice on one screen, in two different
   * shapes, and neither copy told you the other existed.
   *
   * A cluster holding a single page is drawn as that page rather than as a
   * folder you have to open to find one thing.
   */
  const ClusterTree = ({ groups, prefix }) => (
    <>
      {groups.map(g => {
        if (g.items.length === 1) {
          return <NavLeaf key={g.items[0].path} item={{ ...g.items[0], label: g.label }} />
        }
        const id = `${prefix}-${g.label.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')}`
        // The cluster you are standing in is always open. You cannot be asked to
        // go looking for the page already on screen.
        const here = g.items.some(it => pathname === it.path || pathname.startsWith(it.path + '/'))
        const expanded = here || isGroupOpen(id)
        return (
          <Fragment key={id}>
            <NavGroupHeader label={g.label} icon={g.icon} expanded={expanded} onToggle={() => toggleGroup(id)} />
            {expanded && g.items.map(it => <NavLeaf key={it.path} item={it} indent="44px" />)}
          </Fragment>
        )
      })}
    </>
  )

  return (
    <aside
      className={clsx(
        'flex-col sidebar-3d',
        // The drawer's copy is shown by the drawer itself (which is md:hidden);
        // the standalone copy is the desktop one and stays off a phone.
        inDrawer ? 'flex' : 'hidden md:flex',
        collapsed && 'sidebar-collapsed',
      )}
      // Inside the drawer the wrapper owns the width, and `position: fixed`
      // from .sidebar-3d would otherwise pin this to the viewport rather than
      // to the panel sliding in.
      style={{ width: collapsed ? 72 : 260, ...(inDrawer ? { position: 'relative' } : null) }}
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
      <nav ref={navRef} className="flex-1 min-h-0 pb-3 overflow-y-auto scrollbar-hide">
        {/* The pinned open-module block was removed here.
            It duplicated the module's own sub-nav lower down (which is why a
            `pinnedBase` guard existed to hide that copy), and because it was
            inserted at the TOP of this scroll container, entering or leaving a
            module changed the number of rows above the scroll position. The
            browser keeps scrollTop, so the content slid under you and you had to
            scroll to find where you were. With the accordion below, the list is
            short enough that the pin has nothing left to solve. */}

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

        {/* ── HR sub-nav ── */}
        {/* Always rendered now, on two counts.
            It used to hide itself while HR was pinned, on the grounds that the pin
            showed the same thing — but the pin showed a FLATTENED version, so being
            on an HR page silently swapped the grouped tree for a long
            undifferentiated list. One tree, one name, either way.
            It was also gated on isModuleInstalled('hr'), the only such gate in the
            app — every other module renders unconditionally. That gate read
            localStorage, so HR vanished on a new browser, a new machine or a
            colleague's login and had to be "installed" again from the Modules page.
            It protected nothing: the routes and the API are not gated, so
            /app/hr/dashboard always loaded regardless. Removing it makes HR behave
            like the other eleven modules. Per-tenant module entitlement, if it is
            wanted, belongs in the database and not in one browser's storage. */}
          <div data-section-block className="mt-2">
            {!collapsed && <p className="label-caps px-5 mb-1 mt-3" style={{ color: '#a78bfa' }}>HR Module</p>}
            {/* HRMS parent toggle */}
            <button
              onClick={() => toggleSection('hr')}
            data-section="hr"
              title={collapsed ? 'HR' : ''}
              className="nav-3d mb-0.5 w-full"
              style={{ justifyContent: collapsed ? 'center' : undefined, color: '#a78bfa' }}
            >
              <div className="flex-shrink-0 w-7 h-7 rounded-xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.15)' }}>
                <span style={{ fontSize: 13 }}>👥</span>
              </div>
              {/* "HR". This said HRMS while the old pinned
                  header said HR, so the same module had two names depending on
                  which page you happened to be standing on. */}
              {!collapsed && <><span className="truncate text-sm font-semibold flex-1 text-left">HR</span><ChevronDown size={13} className={clsx('transition-transform duration-200', openSection === 'hr' && 'rotate-180')} /></>}
            </button>

            {/* Collapsed rail: flatten every leaf to an icon (all pages reachable). */}
            {collapsed
              ? HR_ALL_LEAVES.map(item => <NavLeaf key={item.path} item={item} />)
              : openSection === 'hr' && (
                <>
                  {/* Dashboard */}
                  {/* /api/hr/dashboard is permission:hr_attendance,view_global —
                      the same grid module `managesHr` already reads, so this is
                      an exact mirror of the server's gate rather than a guess.
                      It was the one HR item still offering a locked door: the
                      Attendance and Requests groups below have been gated on it
                      since they were added. */}
                  {managesHr && <NavLeaf item={HR_DASHBOARD} />}

                  {/* Recruitment group */}
                  <NavGroupHeader label="Recruitment" icon={Briefcase} expanded={isGroupOpen('recruitment')} onToggle={() => toggleGroup('recruitment')} />
                  {isGroupOpen('recruitment') && HR_RECRUITMENT_ITEMS.map(item => <NavLeaf key={item.path} item={item} indent="44px" />)}

                  {/* Employees (top-level) */}
                  <NavLeaf item={HR_EMPLOYEES} />

                  {/* Attendance and Requests are the HR QUEUES — everybody's
                      records, not your own. Shown only to somebody the server
                      will actually let in, so the menu stops offering doors that
                      are locked. */}
                  {managesHr && (
                    <>
                      <NavGroupHeader label="Attendance" icon={CalendarCheck} expanded={isGroupOpen('hr-attendance')} onToggle={() => toggleGroup('hr-attendance')} />
                      {isGroupOpen('hr-attendance') && HR_ATTENDANCE_ITEMS.map(item => <NavLeaf key={item.path} item={item} indent="44px" />)}

                      <NavGroupHeader label="Requests" icon={Receipt} expanded={isGroupOpen('hr-requests')} onToggle={() => toggleGroup('hr-requests')} />
                      {isGroupOpen('hr-requests') && HR_REQUEST_ITEMS.map(item => <NavLeaf key={item.path} item={item} indent="44px" />)}
                    </>
                  )}

                  {/* Employee lifecycle */}
                  <NavGroupHeader label="Employee Lifecycle" icon={Award} expanded={isGroupOpen('hr-lifecycle')} onToggle={() => toggleGroup('hr-lifecycle')} />
                  {isGroupOpen('hr-lifecycle') && HR_LIFECYCLE_ITEMS.map(item => <NavLeaf key={item.path} item={item} indent="44px" />)}

                  {/* Organization */}
                  <NavGroupHeader label="Organization" icon={FolderOpen} expanded={isGroupOpen('hr-org')} onToggle={() => toggleGroup('hr-org')} />
                  {isGroupOpen('hr-org') && HR_ORG_ITEMS.map(item => <NavLeaf key={item.path} item={item} indent="44px" />)}

                  {/* Opened often enough to stay at the top level */}
                  {HR_TOP_LEVEL.map(item => <NavLeaf key={item.path} item={item} />)}

                  {/* A person's own requests — for somebody who cannot open the
                      management screens above. Now an actual answer rather than
                      "is this person an admin", which was wrong for everybody in
                      between: an accounts user saw both sets, a team lead neither
                      of the right ones. */}
                  {!managesHr && (
                    <>
                      <NavGroupHeader label="My Requests" icon={UserRound} expanded={isGroupOpen('hr-mine')} onToggle={() => toggleGroup('hr-mine')} />
                      {isGroupOpen('hr-mine') && HR_MINE_ITEMS.map(item => <NavLeaf key={item.path} item={item} indent="44px" />)}
                    </>
                  )}
                </>
              )}
          </div>

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
        <div data-section-block className={clsx('mt-2')}>
          {!collapsed && <p className="label-caps px-5 mb-1 mt-3" style={{ color: '#a78bfa' }}>Accounts & Finance</p>}
          <button
            onClick={() => toggleSection('accounts')}
            data-section="accounts"
            title={collapsed ? 'Accounts & Finance' : ''}
            className="nav-3d mb-0.5 w-full"
            style={{ justifyContent: collapsed ? 'center' : undefined, color: '#a78bfa' }}
          >
            <div className="flex-shrink-0 w-7 h-7 rounded-xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.15)' }}>
              <Landmark size={13} style={{ color: '#a78bfa' }} />
            </div>
            {!collapsed && <><span className="truncate text-sm font-semibold flex-1 text-left">Accounts & Finance</span><ChevronDown size={13} className={clsx('transition-transform duration-200', openSection === 'accounts' && 'rotate-180')} /></>}
          </button>
          {(openSection === 'accounts' || collapsed) && ACCOUNTS_SUB_ITEMS.map(({ label, path, icon: Icon }) => (
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
        <div data-section-block className={clsx('mt-2')}>
          {!collapsed && <p className="label-caps px-5 mb-1 mt-3" style={{ color: '#a78bfa' }}>Sales & Revenue</p>}
          <button
            onClick={() => toggleSection('sales')}
            data-section="sales"
            title={collapsed ? 'Sales & Revenue' : ''}
            className="nav-3d mb-0.5 w-full"
            style={{ justifyContent: collapsed ? 'center' : undefined, color: '#a78bfa' }}
          >
            <div className="flex-shrink-0 w-7 h-7 rounded-xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.15)' }}>
              <IndianRupee size={13} style={{ color: '#a78bfa' }} />
            </div>
            {!collapsed && <><span className="truncate text-sm font-semibold flex-1 text-left">Sales & Revenue</span><ChevronDown size={13} className={clsx('transition-transform duration-200', openSection === 'sales' && 'rotate-180')} /></>}
          </button>
          {(openSection === 'sales' || collapsed) && SALES_SUB_ITEMS.map(({ group, label, path, icon: Icon }, i) => (
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
        <div data-section-block className={clsx('mt-2')}>
          {!collapsed && <p className="label-caps px-5 mb-1 mt-3" style={{ color: '#22d3ee' }}>Helpdesk & Support</p>}
          <button
            onClick={() => toggleSection('helpdesk')}
            data-section="helpdesk"
            title={collapsed ? 'Helpdesk & Support' : ''}
            className="nav-3d mb-0.5 w-full"
            style={{ justifyContent: collapsed ? 'center' : undefined, color: '#22d3ee' }}
          >
            <div className="flex-shrink-0 w-7 h-7 rounded-xl flex items-center justify-center" style={{ background: 'rgba(6,182,212,0.15)' }}>
              <LifeBuoy size={13} style={{ color: '#22d3ee' }} />
            </div>
            {!collapsed && <><span className="truncate text-sm font-semibold flex-1 text-left">Helpdesk & Support</span><ChevronDown size={13} className={clsx('transition-transform duration-200', openSection === 'helpdesk' && 'rotate-180')} /></>}
          </button>
          {(openSection === 'helpdesk' || collapsed) && HELPDESK_SUB_ITEMS.map(({ label, path, icon: Icon }) => {
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
        <div data-section-block className={clsx('mt-2')}>
          {!collapsed && <p className="label-caps px-5 mb-1 mt-3" style={{ color: '#10b981' }}>Inventory</p>}
          <button
            onClick={() => toggleSection('inventory')}
            data-section="inventory"
            title={collapsed ? 'Inventory' : ''}
            className="nav-3d mb-0.5 w-full"
            style={{ justifyContent: collapsed ? 'center' : undefined, color: '#10b981' }}
          >
            <div className="flex-shrink-0 w-7 h-7 rounded-xl flex items-center justify-center" style={{ background: 'rgba(16,185,129,0.15)' }}>
              <Boxes size={13} style={{ color: '#10b981' }} />
            </div>
            {!collapsed && <><span className="truncate text-sm font-semibold flex-1 text-left">Inventory</span><ChevronDown size={13} className={clsx('transition-transform duration-200', openSection === 'inventory' && 'rotate-180')} /></>}
          </button>
          {(openSection === 'inventory' || collapsed) && INVENTORY_SUB_ITEMS.map(({ label, path, icon: Icon, end }) => (
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
        <div data-section-block className={clsx('mt-2')}>
          {!collapsed && <p className="label-caps px-5 mb-1 mt-3" style={{ color: '#a78bfa' }}>Purchase</p>}
          <button
            onClick={() => toggleSection('purchase')}
            data-section="purchase"
            title={collapsed ? 'Purchase' : ''}
            className="nav-3d mb-0.5 w-full"
            style={{ justifyContent: collapsed ? 'center' : undefined, color: '#a78bfa' }}
          >
            <div className="flex-shrink-0 w-7 h-7 rounded-xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.15)' }}>
              <ShoppingCart size={13} style={{ color: '#a78bfa' }} />
            </div>
            {!collapsed && <><span className="truncate text-sm font-semibold flex-1 text-left">Purchase</span><ChevronDown size={13} className={clsx('transition-transform duration-200', openSection === 'purchase' && 'rotate-180')} /></>}
          </button>
          {/* Collapsed rail: every page as an icon. Expanded: the clusters. */}
          {collapsed
            ? PURCHASE_SUB_ITEMS.map(item => <NavLeaf key={item.path} item={item} />)
            : openSection === 'purchase' && <ClusterTree groups={PURCHASE_GROUPS} prefix="pu" />}
        </div>

        {/* ── TPV Module sub-nav ── */}
        <div data-section-block className={clsx('mt-2')}>
          {!collapsed && <p className="label-caps px-5 mb-1 mt-3" style={{ color: '#a78bfa' }}>Thirdparty Vendor</p>}
          <button
            onClick={() => toggleSection('tpv')}
            data-section="tpv"
            title={collapsed ? 'Thirdparty Vendor' : ''}
            className="nav-3d mb-0.5 w-full"
            style={{ justifyContent: collapsed ? 'center' : undefined, color: '#a78bfa' }}
          >
            <div className="flex-shrink-0 w-7 h-7 rounded-xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.15)' }}>
              <Shield size={13} style={{ color: '#a78bfa' }} />
            </div>
            {!collapsed && <><span className="truncate text-sm font-semibold flex-1 text-left">Thirdparty Vendor</span><ChevronDown size={13} className={clsx('transition-transform duration-200', openSection === 'tpv' && 'rotate-180')} /></>}
          </button>
          {/* A vendor login gets its own two rows, not the governance tree. */}
          {collapsed
            ? tpvItems.map(item => <NavLeaf key={item.path} item={item} />)
            : openSection === 'tpv' && (isVendorLogin
                ? tpvItems.map(item => <NavLeaf key={item.path} item={item} />)
                : <ClusterTree groups={TPV_GROUPS} prefix="tpv" />)}
        </div>

        {/* ── Transport Module sub-nav ── */}
        <div data-section-block className={clsx('mt-2')}>
          {!collapsed && <p className="label-caps px-5 mb-1 mt-3" style={{ color: '#a78bfa' }}>Transport</p>}
          <button
            onClick={() => toggleSection('transport')}
            data-section="transport"
            title={collapsed ? 'Transport' : ''}
            className="nav-3d mb-0.5 w-full"
            style={{ justifyContent: collapsed ? 'center' : undefined, color: '#a78bfa' }}
          >
            <div className="flex-shrink-0 w-7 h-7 rounded-xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.15)' }}>
              <Truck size={13} style={{ color: '#a78bfa' }} />
            </div>
            {!collapsed && <><span className="truncate text-sm font-semibold flex-1 text-left">Transport OS</span><ChevronDown size={13} className={clsx('transition-transform duration-200', openSection === 'transport' && 'rotate-180')} /></>}
          </button>
          {/* `when` is honoured per item, not per section: the fleet screens
              were gated by canUseStos when they had a section of their own, and
              folding them in here must not quietly widen who sees them. */}
          {(openSection === 'transport' || collapsed)
            && TRANSPORT_SUB_ITEMS.filter(({ when }) => !when || when(user)).map(({ label, path, icon: Icon, end }) => (
            <NavLink key={path} to={path} end={end}>
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

        {/* -- SIRE Module sub-nav -- internal engineering only, so a customer
            is not shown a section that answers 403 behind every link. -- */}
        {canUseSire(user) && (
        <div data-section-block className={clsx('mt-2')}>
          {!collapsed && <p className="label-caps px-5 mb-1 mt-3" style={{ color: '#fb7185' }}>Issues & Quality</p>}
          <button
            onClick={() => toggleSection('sire')}
            data-section="sire"
            title={collapsed ? 'Issues & Quality' : ''}
            className="nav-3d mb-0.5 w-full"
            style={{ justifyContent: collapsed ? 'center' : undefined, color: '#fb7185' }}
          >
            <div className="flex-shrink-0 w-7 h-7 rounded-xl flex items-center justify-center" style={{ background: 'rgba(244,63,94,0.15)' }}>
              <Bug size={13} style={{ color: '#fb7185' }} />
            </div>
            {!collapsed && <><span className="truncate text-sm font-semibold flex-1 text-left">Issues & Quality</span><ChevronDown size={13} className={clsx('transition-transform duration-200', openSection === 'sire' && 'rotate-180')} /></>}
          </button>
          {(openSection === 'sire' || collapsed) && SIRE_SUB_ITEMS.map(({ label, path, icon: Icon }) => (
            <NavLink key={path} to={path}>
              {({ isActive }) => (
                <div title={collapsed ? label : ''} className={clsx('nav-3d mb-0.5', isActive && 'nav-3d-active')} style={{ justifyContent: collapsed ? 'center' : undefined, paddingLeft: collapsed ? undefined : '28px' }}>
                  <div className="flex-shrink-0 w-6 h-6 rounded-lg flex items-center justify-center" style={{ background: isActive ? 'rgba(255,255,255,0.15)' : 'rgba(244,63,94,0.06)' }}>
                    <Icon size={12} />
                  </div>
                  {!collapsed && <span className="truncate text-xs">{label}</span>}
                  {isActive && !collapsed && <div className="ml-auto w-1.5 h-1.5 rounded-full" style={{ background: '#fda4af' }} />}
                </div>
              )}
            </NavLink>
          ))}
        </div>
        )}

        {/* The STOS fleet sub-nav used to be a SECOND top-level section here,
            labelled "Transport" with the same Truck icon as the one above it.
            Its three screens now live in TRANSPORT_SUB_ITEMS, each carrying its
            own `when: canUseStos`, so the gate is unchanged and the sidebar has
            one Transport entry instead of two indistinguishable ones. */}
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
