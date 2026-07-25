import { useState, useEffect } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { LayoutDashboard, FileCheck, LogOut, Building2, Sun, Moon } from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import { portalApi } from '@/services/portalApi'
import { KIT3D_STYLE } from '@/components/ui/kit3d'

/**
 * Dedicated chrome for the vendor portal — deliberately NOT the internal AppShell.
 * A vendor must never see the staff sidebar (Modules, Deals, Vendors master…);
 * they get their own minimal top bar and a two-tab rail scoped to what they own.
 */
const NAV = [
  { to: '/vendor-portal/dashboard', label: 'Dashboard', icon: LayoutDashboard },
  { to: '/vendor-portal/documents', label: 'Documents', icon: FileCheck },
]

export default function VendorPortalShell() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [company, setCompany] = useState(null)
  const [theme, setTheme] = useState(() => localStorage.getItem('crm_theme') || 'dark')

  useEffect(() => {
    // The vendor's company name for the header — cheap and confirms the portal
    // is scoped to them.
    portalApi.me().then(d => setCompany(d?.vendor?.company_name)).catch(() => {})
  }, [])

  useEffect(() => {
    document.documentElement.classList.toggle('light', theme === 'light')
    localStorage.setItem('crm_theme', theme)
  }, [theme])

  const doLogout = async () => { try { await logout() } finally { navigate('/auth/login') } }

  return (
    <div style={{ minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>

      {/* Top bar */}
      <header style={{ position: 'sticky', top: 0, zIndex: 20, display: 'flex', alignItems: 'center', gap: 16, padding: '12px 24px',
        background: 'var(--bg-card)', borderBottom: '1px solid var(--border)', backdropFilter: 'blur(10px)' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <span style={{ width: 38, height: 38, borderRadius: 12, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 8px 20px -4px #7C3AED88, inset 0 1px 0 rgba(255,255,255,.35)' }}>
            <Building2 size={19} color="#fff" />
          </span>
          <div style={{ minWidth: 0 }}>
            <div style={{ fontSize: 14.5, fontWeight: 900, color: 'var(--text-h)', letterSpacing: '-0.01em', lineHeight: 1.1 }}>Vendor Portal</div>
            <div style={{ fontSize: 11.5, color: 'var(--text-muted)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', maxWidth: 240 }}>{company || user?.name || 'Signed in'}</div>
          </div>
        </div>

        {/* Nav tabs */}
        <nav style={{ display: 'flex', gap: 6, marginLeft: 12 }}>
          {NAV.map(({ to, label, icon: Icon }) => (
            <NavLink key={to} to={to}
              style={({ isActive }) => ({
                display: 'inline-flex', alignItems: 'center', gap: 7, padding: '8px 14px', borderRadius: 10, fontSize: 13, fontWeight: 700,
                textDecoration: 'none',
                background: isActive ? 'linear-gradient(145deg,#a78bfa,#7C3AED)' : 'transparent',
                color: isActive ? '#fff' : 'var(--text-muted)',
                boxShadow: isActive ? '0 6px 16px -6px rgba(124,58,237,.6)' : 'none',
              })}>
              <Icon size={15} /> {label}
            </NavLink>
          ))}
        </nav>

        <div style={{ marginLeft: 'auto', display: 'flex', alignItems: 'center', gap: 8 }}>
          <button onClick={() => setTheme(t => t === 'dark' ? 'light' : 'dark')} title="Toggle theme"
            style={{ width: 36, height: 36, borderRadius: 10, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
            {theme === 'dark' ? <Sun size={16} /> : <Moon size={16} />}
          </button>
          <button onClick={doLogout}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 700, background: 'var(--bg-input)', border: '1px solid var(--border)', color: '#f87171' }}>
            <LogOut size={15} /> Sign out
          </button>
        </div>
      </header>

      <main style={{ maxWidth: 1200, margin: '0 auto', padding: '24px' }}>
        <Outlet />
      </main>
    </div>
  )
}
