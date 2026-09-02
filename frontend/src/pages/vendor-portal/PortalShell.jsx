import { useState, useEffect } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import {
  LogOut, Building2, Sun, Moon, Bell, HelpCircle, Menu, ChevronRight,
} from 'lucide-react'
import { KIT3D_STYLE } from '@/components/ui/kit3d'
import { resolveNav } from './portalSections'
import PortalNotificationBell from './PortalNotificationBell'
import PortalNotificationToaster from './PortalNotificationToaster'
import { useNotificationFeed } from './useNotificationFeed'
import './portal.css'

/**
 * Shared chrome for BOTH vendor portals (TPV + Purchase). The whole difference
 * between the two is a small descriptor: base path, brand text, how to load the
 * vendor, how to log out, which sections are built (`builtRoutes`), plus any
 * portal-specific extra nav groups (Governance, Workforce, Support…).
 *
 * The nav tree itself comes from the canonical registry (portalSections.js) so
 * the two portals cannot drift out of parity — build a section once, list its
 * key in both portals' builtRoutes, and it lights up on both.
 */
export default function PortalShell({
  base,                 // '/vendor-portal' | '/purchase-portal'
  brandTitle,           // 'Vendor Portal' | 'Purchase Vendor Portal'
  loadVendor,           // () => Promise<vendor|null>
  onLogout,             // () => void   (wrapper owns navigation)
  builtRoutes = {},     // { sectionKey: 'route-segment' }
  extraGroups = [],     // [{ group, items: [{ key,label,icon,to,gate }] }]
  renderBanner,         // optional (vendor) => ReactNode  (e.g. temp-access countdown)
  notificationsApi,     // optional { list, markRead, markAllRead } — powers the bell
}) {
  const location = useLocation()
  // One shared notification feed for the bell + the on-screen toaster.
  const feed = useNotificationFeed(notificationsApi)
  const [vendor, setVendor] = useState(null)
  const [theme, setTheme] = useState(() => localStorage.getItem('crm_theme') || 'dark')
  const [sidebarOpen, setSidebarOpen] = useState(false)

  useEffect(() => { loadVendor?.().then(v => setVendor(v ?? null)).catch(() => {}) }, [])
  useEffect(() => {
    document.documentElement.classList.toggle('light', theme === 'light')
    localStorage.setItem('crm_theme', theme)
  }, [theme])
  useEffect(() => { setSidebarOpen(false) }, [location.pathname])

  // Canonical tree resolved for this portal, then any portal-specific extras.
  const groups = [
    ...resolveNav({ base, builtRoutes, vendor }),
    ...extraGroups.map(g => ({
      group: g.group,
      items: g.items
        .filter(it => !it.gate || it.gate(vendor))
        .map(it => ({ ...it, built: true, to: it.to.startsWith('/') ? it.to : `${base}/${it.to}` })),
    })),
  ]

  // Active-page title: the longest matching built route wins.
  const flat = groups.flatMap(g => g.items)
  const pageTitle = flat
    .filter(it => location.pathname.startsWith(it.to))
    .sort((a, b) => b.to.length - a.to.length)[0]?.label ?? 'Portal'

  const initials = (vendor?.company_name || brandTitle || 'VP')
    .split(' ').slice(0, 2).map(w => w[0]).join('').toUpperCase()

  return (
    <div className="portal-root">
      <style>{KIT3D_STYLE}</style>

      {sidebarOpen && (
        <div onClick={() => setSidebarOpen(false)}
          style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)', zIndex: 35, backdropFilter: 'blur(2px)' }} />
      )}

      {/* ── Sidebar ─────────────────────────────────────────────────── */}
      <aside className={`portal-sidebar${sidebarOpen ? ' open' : ''}`}>
        <div className="portal-sidebar-brand">
          <div className="portal-sidebar-logo"><Building2 size={18} color="#fff" /></div>
          <div style={{ minWidth: 0 }}>
            <div className="portal-sidebar-title">{brandTitle}</div>
            <div className="portal-sidebar-subtitle">{vendor?.company_name || 'Signed in'}</div>
          </div>
        </div>

        <nav className="portal-nav">
          {groups.map(({ group, items }) => items.length === 0 ? null : (
            <div key={group}>
              <div className="portal-nav-section">{group}</div>
              {items.map(({ key, label, icon: Icon, to, built }) => (
                <NavLink
                  key={key}
                  to={to}
                  end={to === `${base}/dashboard`}
                  className={({ isActive }) => `portal-nav-item${isActive ? ' active' : ''}${built ? '' : ' portal-nav-soon'}`}
                  title={built ? label : `${label} — coming soon`}
                >
                  <Icon size={16} className="portal-nav-icon" />
                  <span style={{ flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{label}</span>
                  {!built && <ChevronRight size={12} style={{ opacity: 0.4 }} />}
                </NavLink>
              ))}
            </div>
          ))}
        </nav>

        <div className="portal-sidebar-bottom">
          <button onClick={onLogout} className="portal-nav-item" style={{ color: '#f87171', width: '100%' }}>
            <LogOut size={16} /> Sign Out
          </button>
        </div>
      </aside>

      {/* ── Main Area ───────────────────────────────────────────────── */}
      <div className="portal-main">
        {renderBanner?.(vendor)}

        <header className="portal-header">
          <button onClick={() => setSidebarOpen(v => !v)} className="portal-icon-btn" style={{ display: 'none' }} id="portal-hamburger"><Menu size={17} /></button>
          <style>{`@media(max-width:768px){#portal-hamburger{display:flex!important}}`}</style>

          <div className="portal-header-title">{pageTitle}</div>

          <div className="portal-header-right">
            {notificationsApi
              ? <PortalNotificationBell feed={feed} />
              : <button className="portal-icon-btn" title="Notifications"><Bell size={16} /></button>}
            <button onClick={() => setTheme(t => t === 'dark' ? 'light' : 'dark')} className="portal-icon-btn" title="Toggle theme">
              {theme === 'dark' ? <Sun size={16} /> : <Moon size={16} />}
            </button>
            <div className="portal-user-chip">
              <div className="portal-avatar">{initials}</div>
              <span style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--text-h)', maxWidth: 120, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                {vendor?.company_name || 'Account'}
              </span>
            </div>
            <button onClick={onLogout} className="portal-icon-btn" title="Sign out" style={{ color: '#f87171' }}><LogOut size={16} /></button>
          </div>
        </header>

        <main className="portal-content"><Outlet /></main>
      </div>

      {/* On-screen notification pop-ups (persistent until the vendor reacts). */}
      {notificationsApi && <PortalNotificationToaster feed={feed} />}
    </div>
  )
}
