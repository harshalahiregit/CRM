import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useTheme } from '@/context/ThemeContext'
import {
  LayoutDashboard, ClipboardList, Briefcase, Users,
  Calendar, FileText, Rocket, Building2, ChevronRight, ArrowLeft
} from 'lucide-react'

const HR_NAV = [
  { label: 'Dashboard',      path: '/app/hr/dashboard',         icon: LayoutDashboard },
  { label: 'Manpower Req.',  path: '/app/hr/manpower-requests', icon: ClipboardList   },
  { label: 'Job Postings',   path: '/app/hr/jobs',              icon: Briefcase       },
  { label: 'Candidates',     path: '/app/hr/candidates',        icon: Users           },
  { label: 'Interviews',     path: '/app/hr/interviews',        icon: Calendar        },
  { label: 'Offer Letters',  path: '/app/hr/offers',            icon: FileText        },
  { label: 'Onboarding',     path: '/app/hr/onboarding',        icon: Rocket          },
  { label: 'Employees',      path: '/app/hr/employees',         icon: Building2       },
]

export default function HRLayout() {
  const { isDark } = useTheme()
  const navigate = useNavigate()

  return (
    <div className="space-y-0 -m-4 md:-m-6">

      {/* ── HR Module Header ────────────────────────────── */}
      <div
        className="relative overflow-hidden px-4 md:px-6 pt-5 pb-0"
        style={{
          background: isDark
            ? 'linear-gradient(135deg,rgba(124,58,237,0.2),rgba(91,33,182,0.1),transparent)'
            : 'linear-gradient(135deg,rgba(124,58,237,0.1),rgba(167,139,250,0.06),transparent)',
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
              style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow: '0 3px 8px rgba(124,58,237,0.4)' }}
            >
              👥
            </div>
            <span className="text-xs font-bold" style={{ color: '#a78bfa' }}>HR & Recruitment</span>
          </div>
        </div>

        {/* Sub-navigation tabs */}
        <nav className="flex gap-0.5 overflow-x-auto scrollbar-hide pb-0">
          {HR_NAV.map(({ label, path, icon: Icon }) => (
            <NavLink key={path} to={path}>
              {({ isActive }) => (
                <div
                  className="flex items-center gap-1.5 px-3.5 py-2.5 rounded-t-xl text-xs font-semibold whitespace-nowrap transition-all duration-150 cursor-pointer"
                  style={{
                    background: isActive
                      ? isDark ? 'var(--bg-card)' : '#ffffff'
                      : 'transparent',
                    color: isActive ? '#a78bfa' : 'var(--text-muted)',
                    borderTop: isActive ? '1px solid var(--border-purple)' : '1px solid transparent',
                    borderLeft: isActive ? '1px solid var(--border-purple)' : '1px solid transparent',
                    borderRight: isActive ? '1px solid var(--border-purple)' : '1px solid transparent',
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

      {/* ── HR Page Content ─────────────────────────────── */}
      <div className="p-4 md:p-6">
        <Outlet />
      </div>
    </div>
  )
}
