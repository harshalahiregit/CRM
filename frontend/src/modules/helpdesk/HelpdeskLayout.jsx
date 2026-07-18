import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useTheme } from '@/context/ThemeContext'
import { helpdeskApi } from '@/services/helpdeskApi'
import {
  LifeBuoy, BookOpen, BarChart3,
  PenSquare, Code2, Settings, ChevronRight, ArrowLeft, Headphones
} from 'lucide-react'

// Settings / Widget / KB Admin are admin-surface tools — only admins and
// department managers see them (managerOnly). Regular agents keep Analytics,
// Tickets and the public Knowledge Base.
const HELPDESK_NAV = [
  { label: 'Analytics',      path: '/app/helpdesk/analytics',      icon: BarChart3 },
  { label: 'Tickets',        path: '/app/helpdesk/tickets',        icon: LifeBuoy },
  { label: 'Knowledge Base', path: '/app/helpdesk/knowledge-base', icon: BookOpen },
  { label: 'KB Admin',       path: '/app/helpdesk/kb-admin',       icon: PenSquare,  managerOnly: true },
  { label: 'Widget',         path: '/app/helpdesk/widget',         icon: Code2,      managerOnly: true },
  { label: 'Settings',       path: '/app/helpdesk/settings',       icon: Settings,   managerOnly: true },
]

export default function HelpdeskLayout() {
  const { isDark } = useTheme()
  const navigate = useNavigate()

  // Role flags gate the admin-only tabs. Fail safe: while loading or on error,
  // isManager stays false so admin surfaces never leak to regular agents.
  const { data: me } = useQuery({
    queryKey: ['helpdesk-me'],
    queryFn: () => helpdeskApi.me(),
    staleTime: 5 * 60 * 1000,
    retry: false,
  })
  const isManager = me?.is_manager === true
  const navItems = HELPDESK_NAV.filter(item => !item.managerOnly || isManager)

  return (
    <div style={{ margin: '-1rem -1.5rem', minHeight: '100vh' }}>

      {/* ── Module Header ─────────────────────────────────────── */}
      <div
        className="relative overflow-hidden"
        style={{
          background: isDark
            ? 'linear-gradient(135deg, rgba(6,182,212,0.18) 0%, rgba(14,116,144,0.08) 50%, transparent 100%)'
            : 'linear-gradient(135deg, rgba(6,182,212,0.12) 0%, rgba(34,211,238,0.05) 50%, transparent 100%)',
          borderBottom: '1px solid var(--border)',
        }}
      >
        {/* Decorative orb */}
        <div
          className="absolute pointer-events-none"
          style={{
            top: -60, right: -60,
            width: 200, height: 200,
            borderRadius: '50%',
            background: isDark
              ? 'radial-gradient(circle, rgba(6,182,212,0.15) 0%, transparent 70%)'
              : 'radial-gradient(circle, rgba(6,182,212,0.10) 0%, transparent 70%)',
          }}
        />

        <div className="px-4 md:px-6 pt-4 pb-0 relative z-10">
          {/* Breadcrumb row */}
          <div className="flex items-center gap-2 mb-3">
            <button
              onClick={() => navigate('/app/modules')}
              className="flex items-center gap-1.5 text-xs font-semibold transition-all duration-200 hover:opacity-80"
              style={{ color: 'var(--text-muted)' }}
            >
              <ArrowLeft size={12} />
              Modules
            </button>
            <ChevronRight size={11} style={{ color: 'var(--text-muted)', opacity: 0.5 }} />

            {/* Module badge */}
            <div className="flex items-center gap-2">
              <div
                className="w-6 h-6 rounded-lg flex items-center justify-center"
                style={{
                  background: 'linear-gradient(135deg, #06b6d4, #0e7490)',
                  boxShadow: '0 3px 10px rgba(6,182,212,0.45)',
                }}
              >
                <Headphones size={13} className="text-white" />
              </div>
              <span className="text-xs font-black tracking-tight" style={{ color: '#22d3ee' }}>
                Helpdesk &amp; Support
              </span>
            </div>
          </div>

          {/* Sub-navigation tabs */}
          <nav className="flex gap-0.5 overflow-x-auto scrollbar-hide">
            {navItems.map(({ label, path, icon: Icon }) => (
              <NavLink key={path} to={path} end={path === '/app/helpdesk/analytics'}>
                {({ isActive }) => (
                  <div
                    className="group flex items-center gap-1.5 px-3.5 py-2.5 whitespace-nowrap text-xs font-semibold transition-all duration-200 cursor-pointer select-none"
                    style={{
                      borderRadius: '10px 10px 0 0',
                      background: isActive
                        ? isDark ? 'rgba(6,182,212,0.12)' : 'rgba(6,182,212,0.08)'
                        : 'transparent',
                      color: isActive ? '#22d3ee' : 'var(--text-muted)',
                      borderTop: isActive ? '1px solid rgba(6,182,212,0.35)' : '1px solid transparent',
                      borderLeft: isActive ? '1px solid rgba(6,182,212,0.2)' : '1px solid transparent',
                      borderRight: isActive ? '1px solid rgba(6,182,212,0.2)' : '1px solid transparent',
                      borderBottom: isActive ? `2px solid #22d3ee` : '2px solid transparent',
                      boxShadow: isActive ? '0 -2px 12px rgba(6,182,212,0.1)' : 'none',
                    }}
                  >
                    <Icon size={13} style={{ opacity: isActive ? 1 : 0.7 }} />
                    <span>{label}</span>
                    {isActive && (
                      <span
                        className="ml-0.5 w-1.5 h-1.5 rounded-full"
                        style={{ background: '#22d3ee', boxShadow: '0 0 6px #22d3ee' }}
                      />
                    )}
                  </div>
                )}
              </NavLink>
            ))}
          </nav>
        </div>
      </div>

      {/* ── Page Content ─────────────────────────────────────── */}
      <div className="p-4 md:p-6 animate-fade-in">
        <Outlet />
      </div>
    </div>
  )
}
