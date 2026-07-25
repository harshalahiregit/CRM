import {
  LayoutDashboard, Users, Briefcase, CheckSquare,
  FolderOpen, Receipt, Truck, LifeBuoy,
  BarChart2, Settings, ChevronLeft, ChevronRight,
  LogOut, User, Moon, Sun, Sparkles, Zap, Package,
  UserCheck, CalendarDays, FileText, Rocket, Building2,
  ClipboardList, ChevronDown, Shield, UserCog,
  IndianRupee, FileSignature, CreditCard, FileX,
  ShoppingBag, UserPlus, Link2, RefreshCw, LayoutTemplate, Globe, TrendingUp,
  Landmark, BookText, Scale, ArrowLeftRight, BookOpen
} from 'lucide-react'
import { NavLink, useNavigate } from 'react-router-dom'
import { useAuth } from '@/context/AuthContext'
import { useTheme } from '@/context/ThemeContext'
import { isModuleInstalled } from '@/modules/registry'
import { useState, useEffect } from 'react'
import clsx from 'clsx'
import { leadApi } from '@/services/leadApi'

// NOTE: 'Contacts' and 'Deals' were removed — both were dead "Coming Soon"
// links. Contacts are the existing Customer module's contacts, and there is no
// separate Deal entity by design (leads are the pipeline, same as the old CRM).
const NAV_ITEMS = [
  { label: 'Dashboard', icon: LayoutDashboard, path: '/app/dashboard' },
  { label: 'Tasks', icon: CheckSquare, path: '/app/tasks' },
  { label: 'Projects', icon: FolderOpen, path: '/app/projects' },
  { label: 'Invoices', icon: Receipt, path: '/app/invoices' },
  { label: 'Vendors', icon: Truck, path: '/app/vendors' },
  { label: 'Tickets', icon: LifeBuoy, path: '/app/tickets' },
  { label: 'Reports', icon: BarChart2, path: '/app/reports' },
  { label: 'Settings', icon: Settings, path: '/app/settings' },
]

const HR_SUB_ITEMS = [
  { label: 'HR Dashboard', path: '/app/hr/dashboard', icon: LayoutDashboard },
  { label: 'Manpower Requests', path: '/app/hr/manpower-requests', icon: ClipboardList },
  { label: 'Job Postings', path: '/app/hr/jobs', icon: Briefcase },
  { label: 'Candidates', path: '/app/hr/candidates', icon: Users },
  { label: 'Interviews', path: '/app/hr/interviews', icon: CalendarDays },
  { label: 'Offer Letters', path: '/app/hr/offers', icon: FileText },
  { label: 'Onboarding', path: '/app/hr/onboarding', icon: Rocket },
  { label: 'Employees', path: '/app/hr/employees', icon: Building2 },
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

const HELPDESK_SUB_ITEMS = [
  { label: 'Analytics', path: '/app/helpdesk/analytics', icon: BarChart2 },
  { label: 'My Tasks', path: '/app/helpdesk/my-tasks', icon: CheckSquare },
  { label: 'Tickets', path: '/app/helpdesk/tickets', icon: LifeBuoy },
  { label: 'Knowledge Base', path: '/app/helpdesk/knowledge-base', icon: FileText },
  { label: 'KB Admin', path: '/app/helpdesk/kb-admin', icon: FileText },
  { label: 'Widget', path: '/app/helpdesk/widget', icon: Package },
]

export default function Sidebar({ collapsed, onToggle }) {
  const { user, tenant, logout } = useAuth()
  const { isDark, toggleTheme } = useTheme()
  const navigate = useNavigate()
  const [hrExpanded, setHrExpanded] = useState(true)
  const [salesExpanded, setSalesExpanded] = useState(true)
  const [accountsExpanded, setAccountsExpanded] = useState(true)
  const [helpdeskExpanded, setHelpdeskExpanded] = useState(true)
  const hrInstalled = isModuleInstalled('hr')
  const [activeLeadsCount, setActiveLeadsCount] = useState(null)

  useEffect(() => {
    leadApi.summary().then(s => setActiveLeadsCount(s.active)).catch(() => {})
  }, [])

  const handleLogout = async () => { await logout(); navigate('/auth/login') }

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
        {/* 3D Logo mark */}
        <div
          className="w-9 h-9 rounded-2xl flex items-center justify-center flex-shrink-0 transition-transform duration-200 hover:scale-110"
          style={{
            background: 'linear-gradient(145deg,#9f67ff,#7C3AED,#5b21b6)',
            boxShadow: '0 6px 20px rgba(124,58,237,0.45), inset 0 1px 0 rgba(255,255,255,0.25)',
          }}
        >
          <Sparkles size={16} className="text-white" />
        </div>

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

      {/* ── Navigation ─────────────────────────────────────── */}
      <nav className="flex-1 py-3 overflow-y-auto scrollbar-hide">
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

        {/* ── HR Module sub-nav (when installed) ── */}
        {hrInstalled && (
          <div className="mt-2">
            {!collapsed && <p className="label-caps px-5 mb-1 mt-3" style={{ color: '#a78bfa' }}>HR Module</p>}
            {/* HR parent toggle */}
            <button
              onClick={() => setHrExpanded(e => !e)}
              title={collapsed ? 'HR & Recruitment' : ''}
              className="nav-3d mb-0.5 w-full"
              style={{ justifyContent: collapsed ? 'center' : undefined, color: '#a78bfa' }}
            >
              <div className="flex-shrink-0 w-7 h-7 rounded-xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.15)' }}>
                <span style={{ fontSize: 13 }}>👥</span>
              </div>
              {!collapsed && <><span className="truncate text-sm font-semibold flex-1 text-left">HR & Recruitment</span><ChevronDown size={13} className={clsx('transition-transform duration-200', hrExpanded && 'rotate-180')} /></>}
            </button>
            {/* Sub items */}
            {(hrExpanded || collapsed) && HR_SUB_ITEMS.map(({ label, path, icon: Icon }) => (
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
        <div className="mt-2">
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
        <div className="mt-2">
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
        <div className="mt-2">
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
          {(helpdeskExpanded || collapsed) && HELPDESK_SUB_ITEMS.map(({ label, path, icon: Icon }) => (
            <NavLink key={path} to={path}>
              {({ isActive }) => (
                <div title={collapsed ? label : ''} className={clsx('nav-3d mb-0.5', isActive && 'nav-3d-active')} style={{ justifyContent: collapsed ? 'center' : undefined, paddingLeft: collapsed ? undefined : '28px' }}>
                  <div className="flex-shrink-0 w-6 h-6 rounded-lg flex items-center justify-center" style={{ background: isActive ? 'rgba(255,255,255,0.15)' : 'rgba(6,182,212,0.06)' }}>
                    <Icon size={12} />
                  </div>
                  {!collapsed && <span className="truncate text-xs">{label}</span>}
                  {isActive && !collapsed && <div className="ml-auto w-1.5 h-1.5 rounded-full" style={{ background: '#67e8f9' }} />}
                </div>
              )}
            </NavLink>
          ))}
        </div>
      </nav>

      {/* ── Bottom Controls ────────────────────────────────── */}
      <div className="p-3 space-y-1" style={{ borderTop: '1px solid var(--border)' }}>
        {/* Theme toggle — 3D pill */}
        <button
          onClick={toggleTheme}
          title={isDark ? 'Switch to Light' : 'Switch to Dark'}
          className="w-full flex items-center gap-2.5 px-3 py-2.5 rounded-xl transition-all duration-200"
          style={{
            color: 'var(--text-muted)',
            justifyContent: collapsed ? 'center' : undefined,
          }}
          onMouseEnter={e => { e.currentTarget.style.background = 'rgba(124,58,237,0.08)'; e.currentTarget.style.color = 'var(--text-h)' }}
          onMouseLeave={e => { e.currentTarget.style.background = 'transparent'; e.currentTarget.style.color = 'var(--text-muted)' }}
        >
          <div
            className="w-7 h-7 rounded-xl flex items-center justify-center"
            style={{
              background: isDark
                ? 'linear-gradient(135deg,#fbbf24,#f59e0b)'
                : 'linear-gradient(135deg,#6366f1,#4f46e5)',
              boxShadow: isDark
                ? '0 3px 10px rgba(251,191,36,0.4)'
                : '0 3px 10px rgba(99,102,241,0.4)',
            }}
          >
            {isDark ? <Sun size={13} className="text-white" /> : <Moon size={13} className="text-white" />}
          </div>
          {!collapsed && (
            <span className="text-sm font-medium">{isDark ? 'Light Mode' : 'Dark Mode'}</span>
          )}
        </button>

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
