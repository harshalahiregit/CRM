import { useState, useEffect } from 'react'
import { NavLink, Outlet, useNavigate, useLocation } from 'react-router-dom'
import {
  LayoutDashboard, ClipboardList, FileText, ShieldCheck, CalendarDays,
  LogOut, Building2, Sun, Moon, Bell, User, HelpCircle, Menu, HardHat,
} from 'lucide-react'
import { purchasePortalApi } from '@/services/purchasePortalApi'
import { purchaseVendorAuthApi } from '@/services/purchaseVendorAuthApi'
import { KIT3D_STYLE } from '@/components/ui/kit3d'
import '@/pages/vendor-portal/portal.css'

/**
 * Purchase Vendor Portal chrome — independent of the shared/TPV portal. Reuses
 * only the generic portal.css. The authenticated PurchaseVendor sees only their
 * own onboarding, documents, approval status and kickoff.
 */
export default function PurchasePortalShell() {
  const navigate = useNavigate()
  const location = useLocation()
  const [vendor, setVendor] = useState(null)
  const [theme, setTheme] = useState(() => localStorage.getItem('crm_theme') || 'dark')
  const [sidebarOpen, setSidebarOpen] = useState(false)

  useEffect(() => { purchasePortalApi.me().then(d => setVendor(d?.vendor ?? null)).catch(() => {}) }, [])
  useEffect(() => {
    document.documentElement.classList.toggle('light', theme === 'light')
    localStorage.setItem('crm_theme', theme)
  }, [theme])
  useEffect(() => { setSidebarOpen(false) }, [location.pathname])

  const doLogout = async () => { try { await purchaseVendorAuthApi.logout() } finally { navigate('/purchase-portal/login') } }

  const nav = [
    // The six onboarding steps are the vendor's own work — the company profile and
    // the final submission exist on no other screen. The DECISIONS (approve/reject/
    // hold) remain the admin's, on the admin pages. Approval here is read-only status.
    { to: '/purchase-portal/dashboard',  label: 'Dashboard',        icon: LayoutDashboard },
    { to: '/purchase-portal/onboarding', label: 'My Onboarding',    icon: ClipboardList },
    { to: '/purchase-portal/documents',  label: 'My Documents',     icon: FileText },
    { to: '/purchase-portal/kickoff',    label: 'Kickoff Meeting',  icon: CalendarDays },
    { to: '/purchase-portal/approval',   label: 'Approval Status',  icon: ShieldCheck },
    // Unlocked once the vendor is Active — the workforce only matters after the
    // company itself has been approved.
    ...(vendor?.status === 'Active'
      ? [{ to: '/purchase-portal/workforce', label: 'My Workforce', icon: HardHat }]
      : []),
    { to: '/purchase-portal/ppe',        label: 'PPE Stock',        icon: HardHat },
  ]
  const pageTitle = nav.slice().reverse().find(n => location.pathname.startsWith(n.to))?.label ?? 'Portal'
  const initials = (vendor?.company_name || 'PV').split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase()

  return (
    <div className="portal-root">
      <style>{KIT3D_STYLE}</style>
      {sidebarOpen && <div onClick={() => setSidebarOpen(false)} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)', zIndex: 35, backdropFilter: 'blur(2px)' }} />}

      <aside className={`portal-sidebar${sidebarOpen ? ' open' : ''}`}>
        <div className="portal-sidebar-brand">
          <div className="portal-sidebar-logo"><Building2 size={18} color="#fff" /></div>
          <div style={{ minWidth: 0 }}>
            <div className="portal-sidebar-title">Purchase Vendor Portal</div>
            <div className="portal-sidebar-subtitle">{vendor?.company_name || 'Signed in'}</div>
          </div>
        </div>

        <nav className="portal-nav">
          <div className="portal-nav-section">Main Menu</div>
          {nav.map(({ to, label, icon: Icon }) => (
            <NavLink key={to} to={to} end={to === '/purchase-portal/dashboard'}
              className={({ isActive }) => `portal-nav-item${isActive ? ' active' : ''}`}>
              <Icon size={16} className="portal-nav-icon" /> {label}
            </NavLink>
          ))}
          <div className="portal-nav-section" style={{ marginTop: 8 }}>Account</div>
          <a href="mailto:support@company.com" className="portal-nav-item"><HelpCircle size={16} /> Support</a>
        </nav>

        <div className="portal-sidebar-bottom">
          <button onClick={doLogout} className="portal-nav-item" style={{ color: '#f87171', width: '100%' }}><LogOut size={16} /> Sign Out</button>
        </div>
      </aside>

      <div className="portal-main">
        <header className="portal-header">
          <button onClick={() => setSidebarOpen(v => !v)} className="portal-icon-btn" style={{ display: 'none' }} id="pp-hamburger"><Menu size={17} /></button>
          <style>{`@media(max-width:768px){#pp-hamburger{display:flex!important}}`}</style>
          <div className="portal-header-title">{pageTitle}</div>
          <div className="portal-header-right">
            <button className="portal-icon-btn" title="Notifications"><Bell size={16} /><span className="notif-dot" /></button>
            <button onClick={() => setTheme(t => t === 'dark' ? 'light' : 'dark')} className="portal-icon-btn" title="Toggle theme">{theme === 'dark' ? <Sun size={16} /> : <Moon size={16} />}</button>
            <div className="portal-user-chip">
              <div className="portal-avatar">{initials}</div>
              <span style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--text-h)', maxWidth: 120, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{vendor?.company_name || 'Account'}</span>
            </div>
            <button onClick={doLogout} className="portal-icon-btn" title="Sign out" style={{ color: '#f87171' }}><LogOut size={16} /></button>
          </div>
        </header>
        <main className="portal-content"><Outlet /></main>
      </div>
    </div>
  )
}
