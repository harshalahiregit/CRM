import { useState, useEffect } from 'react'
import { NavLink, Outlet, useNavigate, useLocation } from 'react-router-dom'
import {
  LayoutDashboard, ClipboardList, HardHat, LogOut, Building2,
  Sun, Moon, Bell, User, FileText, HelpCircle, ChevronRight, Menu, X,
} from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import { portalApi } from '@/services/portalApi'
import { KIT3D_STYLE } from '@/components/ui/kit3d'
import TemporaryAccessBanner from '@/modules/tpv/components/TemporaryAccessBanner'
import './portal.css'

/**
 * Dedicated chrome for the vendor portal — deliberately NOT the internal AppShell.
 * Fixed 260 px left sidebar + sticky top header.
 * Navigation is dynamic based on vendor status (same logic as before, UI only changed).
 */
export default function VendorPortalShell() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [vendor, setVendor] = useState(null)
  const [theme, setTheme] = useState(() => localStorage.getItem('crm_theme') || 'dark')
  const [sidebarOpen, setSidebarOpen] = useState(false)

  useEffect(() => {
    portalApi.me().then(d => setVendor(d?.vendor ?? null)).catch(() => {})
  }, [])

  useEffect(() => {
    document.documentElement.classList.toggle('light', theme === 'light')
    localStorage.setItem('crm_theme', theme)
  }, [theme])

  // Close mobile sidebar on navigation
  useEffect(() => { setSidebarOpen(false) }, [location.pathname])

  const doLogout = async () => { try { await logout() } finally { navigate('/auth/login') } }

  // Derive page title from current route
  const pageTitles = {
    '/vendor-portal/dashboard': 'Dashboard',
    '/vendor-portal/onboarding': 'Onboarding',
    '/vendor-portal/documents': 'Documents',
    '/vendor-portal/workforce': 'Workforce',
    '/vendor-portal/workforce/dashboard': 'Workforce Dashboard',
    '/vendor-portal/workforce/workers': 'Workers',
    '/vendor-portal/workforce/gate-log': 'Gate Log',
    '/vendor-portal/workforce/attendance': 'Attendance',
    '/vendor-portal/workforce/strikes': 'Safety Strikes',
  }
  const pageTitle = Object.entries(pageTitles).reverse()
    .find(([path]) => location.pathname.startsWith(path))?.[1] ?? 'Portal'

  // Workforce tab appears automatically once admin activates the vendor (same logic, unchanged)
  const mainNavItems = [
    { to: '/vendor-portal/dashboard',  label: 'Dashboard',   icon: LayoutDashboard },
    { to: '/vendor-portal/onboarding', label: 'Onboarding',  icon: ClipboardList },
    { to: '/vendor-portal/documents',  label: 'Documents',   icon: FileText },
    ...(vendor?.status === 'Active'
      ? [{ to: '/vendor-portal/workforce', label: 'Workforce', icon: HardHat }]
      : []),
  ]

  const bottomNavItems = [
    { to: '/vendor-portal/dashboard', label: 'Profile', icon: User, exact: false },
  ]

  // Get user initials for avatar
  const initials = (vendor?.company_name || user?.name || 'VP')
    .split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase()

  return (
    <div className="portal-root">
      <style>{KIT3D_STYLE}</style>

      {/* Mobile overlay */}
      {sidebarOpen && (
        <div
          onClick={() => setSidebarOpen(false)}
          style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)', zIndex: 35, backdropFilter: 'blur(2px)' }}
        />
      )}

      {/* ── Sidebar ─────────────────────────────────────────────────── */}
      <aside className={`portal-sidebar${sidebarOpen ? ' open' : ''}`}>
        {/* Brand */}
        <div className="portal-sidebar-brand">
          <div className="portal-sidebar-logo">
            <Building2 size={18} color="#fff" />
          </div>
          <div style={{ minWidth: 0 }}>
            <div className="portal-sidebar-title">Vendor Portal</div>
            <div className="portal-sidebar-subtitle">{vendor?.company_name || user?.name || 'Signed in'}</div>
          </div>
        </div>

        {/* Main Navigation */}
        <nav className="portal-nav">
          <div className="portal-nav-section">Main Menu</div>

          {mainNavItems.map(({ to, label, icon: Icon }) => (
            <NavLink
              key={to}
              to={to}
              end={to === '/vendor-portal/dashboard'}
              className={({ isActive }) => `portal-nav-item${isActive ? ' active' : ''}`}
            >
              <Icon size={16} className="portal-nav-icon" />
              {label}
            </NavLink>
          ))}

          <div className="portal-nav-section" style={{ marginTop: 8 }}>Account</div>

          <NavLink
            to="/vendor-portal/dashboard"
            className={({ isActive }) => `portal-nav-item${isActive ? '' : ''}`}
            style={{ color: 'var(--text-muted)' }}
            onClick={e => e.preventDefault()}
          >
            <User size={16} />
            Profile
          </NavLink>

          <a
            href="mailto:support@company.com"
            className="portal-nav-item"
          >
            <HelpCircle size={16} />
            Support
          </a>
        </nav>

        {/* Sidebar Bottom — Logout */}
        <div className="portal-sidebar-bottom">
          <button onClick={doLogout} className="portal-nav-item" style={{ color: '#f87171', width: '100%' }}>
            <LogOut size={16} />
            Sign Out
          </button>
        </div>
      </aside>

      {/* ── Main Area ───────────────────────────────────────────────── */}
      <div className="portal-main">

        {/* Temporary access countdown (same as before) */}
        <TemporaryAccessBanner vendor={vendor} />

        {/* Top Header */}
        <header className="portal-header">
          {/* Mobile hamburger */}
          <button
            onClick={() => setSidebarOpen(v => !v)}
            className="portal-icon-btn"
            style={{ display: 'none' }}
            id="portal-hamburger"
          >
            <Menu size={17} />
          </button>
          <style>{`@media(max-width:768px){#portal-hamburger{display:flex!important}}`}</style>

          <div className="portal-header-title">{pageTitle}</div>

          <div className="portal-header-right">
            {/* Notifications */}
            <button className="portal-icon-btn" title="Notifications">
              <Bell size={16} />
              <span className="notif-dot" />
            </button>

            {/* Dark mode toggle */}
            <button
              onClick={() => setTheme(t => t === 'dark' ? 'light' : 'dark')}
              className="portal-icon-btn"
              title="Toggle theme"
            >
              {theme === 'dark' ? <Sun size={16} /> : <Moon size={16} />}
            </button>

            {/* User chip */}
            <div className="portal-user-chip">
              <div className="portal-avatar">{initials}</div>
              <span style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--text-h)', maxWidth: 120, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                {vendor?.company_name || user?.name || 'Account'}
              </span>
            </div>

            {/* Sign out (header) */}
            <button
              onClick={doLogout}
              className="portal-icon-btn"
              title="Sign out"
              style={{ color: '#f87171' }}
            >
              <LogOut size={16} />
            </button>
          </div>
        </header>

        {/* Page Content */}
        <main className="portal-content">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
