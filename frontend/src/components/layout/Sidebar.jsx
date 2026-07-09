import {
  LayoutDashboard, Users, Briefcase, CheckSquare,
  FolderOpen, Receipt, Truck, LifeBuoy,
  BarChart2, Settings, ChevronLeft, ChevronRight,
  LogOut, User, Moon, Sun, Sparkles, Zap, Package,
  UserCheck, CalendarDays, FileText, Rocket, Building2,
  ClipboardList, ChevronDown, Shield, UserCog
} from 'lucide-react'
import { NavLink, useNavigate } from 'react-router-dom'
import { useAuth } from '@/context/AuthContext'
import { useTheme } from '@/context/ThemeContext'
import { isModuleInstalled } from '@/modules/registry'
import { useState, useEffect } from 'react'
import clsx from 'clsx'

const NAV_ITEMS = [
  { label: 'Dashboard',  icon: LayoutDashboard, path: '/app/dashboard' },
  { label: 'Contacts',   icon: Users,            path: '/app/contacts'  },
  { label: 'Deals',      icon: Briefcase,        path: '/app/deals'     },
  { label: 'Tasks',      icon: CheckSquare,      path: '/app/tasks'     },
  { label: 'Projects',   icon: FolderOpen,       path: '/app/projects'  },
  { label: 'Invoices',   icon: Receipt,          path: '/app/invoices'  },
  { label: 'Vendors',    icon: Truck,            path: '/app/vendors'   },
  { label: 'Tickets',    icon: LifeBuoy,         path: '/app/tickets'   },
  { label: 'Reports',    icon: BarChart2,        path: '/app/reports'   },
  { label: 'Settings',   icon: Settings,         path: '/app/settings'  },
]

const HR_SUB_ITEMS = [
  { label: 'HR Dashboard',      path: '/app/hr/dashboard',         icon: LayoutDashboard },
  { label: 'Manpower Requests', path: '/app/hr/manpower-requests', icon: ClipboardList   },
  { label: 'Job Postings',      path: '/app/hr/jobs',              icon: Briefcase       },
  { label: 'Candidates',        path: '/app/hr/candidates',        icon: Users           },
  { label: 'Interviews',        path: '/app/hr/interviews',        icon: CalendarDays    },
  { label: 'Offer Letters',     path: '/app/hr/offers',            icon: FileText        },
  { label: 'Onboarding',        path: '/app/hr/onboarding',        icon: Rocket          },
  { label: 'Employees',         path: '/app/hr/employees',         icon: Building2       },
]

export default function Sidebar({ collapsed, onToggle }) {
  const { user, tenant, logout } = useAuth()
  const { isDark, toggleTheme } = useTheme()
  const navigate = useNavigate()
  const [hrExpanded, setHrExpanded] = useState(true)
  const hrInstalled = isModuleInstalled('hr')

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
              {user?.name?.split(' ').map(n => n[0]).join('').toUpperCase().slice(0,2) || 'U'}
            </div>
            <div className="flex-1 overflow-hidden">
              <p className="text-xs font-semibold truncate" style={{ color: 'var(--text-h)' }}>{user?.name}</p>
              <p className="text-[10px] truncate capitalize" style={{ color: 'var(--text-muted)' }}>
                {user?.role?.replace(/_/g,' ')}
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
