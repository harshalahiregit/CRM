import {
  LayoutDashboard, Users, Briefcase, CheckSquare,
  FolderOpen, Receipt, Truck, LifeBuoy,
  BarChart2, Settings, ChevronLeft, ChevronRight,
  LogOut, User, Moon, Sun, Building2
} from 'lucide-react'
import { NavLink, useNavigate } from 'react-router-dom'
import { useAuth } from '@/context/AuthContext'
import { useTheme } from '@/context/ThemeContext'
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

export default function Sidebar({ collapsed, onToggle }) {
  const { user, tenant, logout } = useAuth()
  const { isDark, toggleTheme } = useTheme()
  const navigate = useNavigate()

  const handleLogout = async () => {
    await logout()
    navigate('/auth/login')
  }

  return (
    <aside
      className={clsx(
        'sidebar hidden md:flex flex-col',
        collapsed && 'sidebar-collapsed'
      )}
    >
      {/* Logo */}
      <div className="flex items-center gap-3 px-4 py-5 border-b border-white/10 min-h-[64px]">
        <div className="w-8 h-8 rounded-lg bg-primary-600 flex items-center justify-center flex-shrink-0">
          <Building2 size={18} className="text-white" />
        </div>
        {!collapsed && (
          <div className="overflow-hidden">
            <p className="text-sm font-bold text-white truncate">
              {tenant?.name || 'MLA CRM'}
            </p>
            <p className="text-xs text-gray-500 truncate">{tenant?.subdomain}.app.com</p>
          </div>
        )}
      </div>

      {/* Navigation */}
      <nav className="flex-1 py-3 overflow-y-auto scrollbar-hide">
        {NAV_ITEMS.map(({ label, icon: Icon, path }) => (
          <NavLink key={path} to={path}>
            {({ isActive }) => (
              <div
                title={collapsed ? label : ''}
                className={clsx(isActive ? 'nav-item-active' : 'nav-item', 'mb-0.5')}
              >
                <Icon size={18} className="flex-shrink-0" />
                {!collapsed && <span className="truncate">{label}</span>}
              </div>
            )}
          </NavLink>
        ))}
      </nav>

      {/* User + Controls */}
      <div className="border-t border-white/10 p-3 space-y-1">
        {/* Theme toggle */}
        <button
          onClick={toggleTheme}
          title="Toggle theme"
          className={clsx('nav-item w-full', collapsed && 'justify-center')}
        >
          {isDark ? <Sun size={18} /> : <Moon size={18} />}
          {!collapsed && <span>{isDark ? 'Light Mode' : 'Dark Mode'}</span>}
        </button>

        {/* User profile */}
        {!collapsed && (
          <div className="flex items-center gap-2 px-3 py-2 rounded-lg">
            <div className="w-7 h-7 rounded-full bg-primary-600/40 flex items-center justify-center flex-shrink-0">
              <User size={14} className="text-primary-400" />
            </div>
            <div className="flex-1 overflow-hidden">
              <p className="text-xs font-medium text-white truncate">{user?.name}</p>
              <p className="text-xs text-gray-500 truncate capitalize">{user?.role}</p>
            </div>
          </div>
        )}

        {/* Logout */}
        <button
          onClick={handleLogout}
          title="Logout"
          className={clsx('nav-item w-full text-danger hover:bg-danger/10', collapsed && 'justify-center')}
        >
          <LogOut size={18} />
          {!collapsed && <span>Logout</span>}
        </button>
      </div>

      {/* Collapse toggle button */}
      <button
        onClick={onToggle}
        className="absolute -right-3 top-20 w-6 h-6 rounded-full bg-gray-700 border border-white/20
                   flex items-center justify-center text-gray-400 hover:text-white
                   hover:bg-primary-600 transition-all duration-150 z-50"
      >
        {collapsed ? <ChevronRight size={12} /> : <ChevronLeft size={12} />}
      </button>
    </aside>
  )
}
