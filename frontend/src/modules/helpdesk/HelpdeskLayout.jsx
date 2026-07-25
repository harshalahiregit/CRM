import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useTheme } from '@/context/ThemeContext'
import { LifeBuoy, BookOpen, BarChart3, CheckSquare, PenSquare, Code2, ChevronRight, ArrowLeft } from 'lucide-react'

const HELPDESK_NAV = [
  { label: 'Analytics',      path: '/app/helpdesk/analytics',      icon: BarChart3 },
  { label: 'My Tasks',       path: '/app/helpdesk/my-tasks',       icon: CheckSquare },
  { label: 'Tickets',        path: '/app/helpdesk/tickets',        icon: LifeBuoy },
  { label: 'Knowledge Base', path: '/app/helpdesk/knowledge-base', icon: BookOpen },
  { label: 'KB Admin',       path: '/app/helpdesk/kb-admin',       icon: PenSquare },
  { label: 'Widget',         path: '/app/helpdesk/widget',         icon: Code2 },
]

export default function HelpdeskLayout() {
  const { isDark } = useTheme()
  const navigate = useNavigate()

  return (
    <div className="space-y-0 -m-4 md:-m-6">

      {/* ── Helpdesk Module Header ──────────────────────── */}
      <div
        className="relative overflow-hidden px-4 md:px-6 pt-5 pb-0"
        style={{
          background: isDark
            ? 'linear-gradient(135deg,rgba(6,182,212,0.2),rgba(14,116,144,0.1),transparent)'
            : 'linear-gradient(135deg,rgba(6,182,212,0.1),rgba(34,211,238,0.06),transparent)',
          borderBottom: '1px solid var(--border)',
        }}
      >
        {/* Module badge */}
        <div className="flex items-center gap-3 mb-4">
          <button
            onClick={() => navigate('/app/modules')}
            className="flex items-center gap-1.5 text-xs font-semibold transition-all duration-150"
            style={{ color: 'var(--text-muted)' }}
            onMouseEnter={e => e.currentTarget.style.color = 'var(--text-h)'}
            onMouseLeave={e => e.currentTarget.style.color = 'var(--text-muted)'}
          >
            <ArrowLeft size={12} /> Modules
          </button>
          <ChevronRight size={12} style={{ color: 'var(--text-muted)' }} />
          <div className="flex items-center gap-2">
            <div
              className="w-6 h-6 rounded-lg flex items-center justify-center text-xs"
              style={{ background: 'linear-gradient(135deg,#06b6d4,#0e7490)', boxShadow: '0 3px 8px rgba(6,182,212,0.4)' }}
            >
              🎧
            </div>
            <span className="text-xs font-bold" style={{ color: '#22d3ee' }}>Helpdesk & Support</span>
          </div>
        </div>

        {/* Sub-navigation tabs */}
        <nav className="flex gap-0.5 overflow-x-auto scrollbar-hide pb-0">
          {HELPDESK_NAV.map(({ label, path, icon: Icon }) => (
            <NavLink key={path} to={path}>
              {({ isActive }) => (
                <div
                  className="flex items-center gap-1.5 px-3.5 py-2.5 rounded-t-xl text-xs font-semibold whitespace-nowrap transition-all duration-150 cursor-pointer"
                  style={{
                    background: isActive
                      ? isDark ? 'var(--bg-card)' : '#ffffff'
                      : 'transparent',
                    color: isActive ? '#22d3ee' : 'var(--text-muted)',
                    borderTop: isActive ? '1px solid rgba(6,182,212,0.4)' : '1px solid transparent',
                    borderLeft: isActive ? '1px solid rgba(6,182,212,0.4)' : '1px solid transparent',
                    borderRight: isActive ? '1px solid rgba(6,182,212,0.4)' : '1px solid transparent',
                    borderBottom: isActive ? `2px solid ${isDark ? 'var(--bg-card)' : '#ffffff'}` : '1px solid transparent',
                    marginBottom: isActive ? '-1px' : '0',
                  }}
                >
                  <Icon size={13} />
                  {label}
                </div>
              )}
            </NavLink>
          ))}
        </nav>
      </div>

      {/* ── Helpdesk Page Content ───────────────────────── */}
      <div className="p-4 md:p-6">
        <Outlet />
      </div>
    </div>
  )
}
