import { useState, useEffect } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import {
  LayoutDashboard, ClipboardList, BarChart3, Building2, Settings, LogOut, Sun, Moon,
} from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import { companyPortalApi } from '@/services/companyPortalApi'
import { KIT3D_STYLE } from '@/components/ui/kit3d'

/**
 * Dedicated chrome for the external company portal — never the internal AppShell.
 * A company client must never see the staff sidebar; they get their own top bar
 * and a nav scoped to what they own (Candidates/Interviews/Offers/Documents/
 * Messages live inside each hiring request). Nav is gated by role permissions.
 */
const NAV = [
  { to: '/company-portal/dashboard',        label: 'Dashboard',        icon: LayoutDashboard },
  { to: '/company-portal/hiring-requests',  label: 'Hiring Requests',  icon: ClipboardList },
  { to: '/company-portal/reports',          label: 'Reports',          icon: BarChart3, area: 'reports' },
  { to: '/company-portal/profile',          label: 'Profile',          icon: Building2 },
  { to: '/company-portal/settings',         label: 'Settings',         icon: Settings, area: 'settings' },
]

export default function CompanyPortalShell() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [me, setMe] = useState(null)
  const [theme, setTheme] = useState(() => localStorage.getItem('crm_theme') || 'dark')

  useEffect(() => {
    companyPortalApi.me().then(d => setMe(d?.data || null)).catch(() => {})
  }, [])

  const company = me?.company
  const perms = me?.user?.permissions || {}
  const nav = NAV.filter(n => !n.area || perms[n.area]?.view)

  useEffect(() => {
    document.documentElement.classList.toggle('light', theme === 'light')
    localStorage.setItem('crm_theme', theme)
  }, [theme])

  const doLogout = async () => { try { await logout() } finally { navigate('/auth/login') } }

  return (
    <div style={{ minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>

      <header style={{ position: 'sticky', top: 0, zIndex: 20, display: 'flex', alignItems: 'center', gap: 16, padding: '12px 24px',
        background: 'var(--bg-card)', borderBottom: '1px solid var(--border)', backdropFilter: 'blur(10px)', flexWrap: 'wrap' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <span style={{ width: 38, height: 38, borderRadius: 12, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 8px 20px -4px #7C3AED88, inset 0 1px 0 rgba(255,255,255,.35)' }}>
            <Building2 size={19} color="#fff" />
          </span>
          <div style={{ minWidth: 0 }}>
            <div style={{ fontSize: 14.5, fontWeight: 900, color: 'var(--text-h)', lineHeight: 1.1 }}>Company Portal</div>
            <div style={{ fontSize: 11.5, color: 'var(--text-muted)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', maxWidth: 260 }}>
              {company ? `${company.name} · ${company.company_code}` : (user?.name || 'Signed in')}
              {me?.user?.role_label ? ` · ${me.user.role_label}` : ''}
            </div>
          </div>
        </div>

        <nav style={{ display: 'flex', gap: 4, marginLeft: 12, flexWrap: 'wrap', overflowX: 'auto' }}>
          {nav.map(({ to, label, icon: Icon }) => (
            <NavLink key={to} to={to}
              style={({ isActive }) => ({
                display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 12px', borderRadius: 10, fontSize: 12.5, fontWeight: 700,
                textDecoration: 'none', whiteSpace: 'nowrap',
                background: isActive ? 'linear-gradient(145deg,#a78bfa,#7C3AED)' : 'transparent',
                color: isActive ? '#fff' : 'var(--text-muted)',
                boxShadow: isActive ? '0 6px 16px -6px rgba(124,58,237,.6)' : 'none',
              })}>
              <Icon size={14} /> {label}
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
        <Outlet context={{ company, user: me?.user, perms }} />
      </main>
    </div>
  )
}
