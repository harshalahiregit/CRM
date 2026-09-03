import { useEffect, useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { Stethoscope, LayoutDashboard, ClipboardPlus, FileText, UserCog, LogOut, AlertTriangle } from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import { medicalApi } from '@/services/medicalApi'
import { KIT3D_STYLE as TPV_STYLE } from '@/components/ui/kit3d'

/**
 * The doctor portal shell.
 *
 * One login covers both vendor sides, so the module switch lives here in the
 * chrome rather than being asked again on every screen — the doctor picks TPV or
 * Purchase once and the whole portal follows. A doctor whose profile names only
 * one side never sees the switch at all.
 *
 * The context is shared downward through the Outlet rather than a provider: the
 * portal is four screens, and a context for that would be ceremony.
 */
export default function DoctorPortalShell() {
  const { logout } = useAuth()
  const navigate = useNavigate()
  const [me, setMe] = useState(null)
  const [module, setModule] = useState(() => localStorage.getItem('doctor.module') || 'tpv')

  useEffect(() => {
    medicalApi.doctor.me().then(d => {
      setMe(d)
      // A doctor limited to one side should land on it, not on a 404.
      if (d?.modules?.length && !d.modules.includes(module)) pick(d.modules[0])
    }).catch(() => setMe(null))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const pick = (m) => { setModule(m); localStorage.setItem('doctor.module', m) }

  const onLogout = async () => { try { await logout() } finally { navigate('/auth/login') } }

  const modules = me?.modules ?? ['tpv', 'purchase']

  return (
    <div style={{ minHeight: '100vh', background: 'var(--bg-app, #0b1020)' }}>
      <style>{TPV_STYLE}</style>

      <header style={{
        display: 'flex', alignItems: 'center', gap: 16, padding: '12px 20px',
        borderBottom: '1px solid var(--border)', background: 'var(--bg-card)', flexWrap: 'wrap',
      }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <div style={{ width: 32, height: 32, borderRadius: 10, background: '#7C3AED22', color: '#a78bfa', display: 'grid', placeItems: 'center' }}>
            <Stethoscope size={17} />
          </div>
          <div>
            <div style={{ fontSize: 14, fontWeight: 900, color: 'var(--text-h)' }}>Doctor Portal</div>
            <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{me?.name || '—'}</div>
          </div>
        </div>

        {/* The module switch — one login, two vendor registers. */}
        {modules.length > 1 && (
          <div style={{ display: 'flex', gap: 4, padding: 3, borderRadius: 10, background: 'var(--bg-app, #0b1020)' }}>
            {modules.map(m => (
              <button
                key={m}
                onClick={() => pick(m)}
                style={{
                  padding: '5px 14px', borderRadius: 8, border: 'none', cursor: 'pointer',
                  fontSize: 12, fontWeight: 700,
                  background: module === m ? '#7C3AED' : 'transparent',
                  color: module === m ? '#fff' : 'var(--text-muted)',
                }}
              >
                {m === 'tpv' ? 'TPV vendors' : 'Purchase vendors'}
              </button>
            ))}
          </div>
        )}

        <nav style={{ display: 'flex', gap: 4, marginLeft: 'auto', flexWrap: 'wrap' }}>
          <Tab to="/doctor-portal/dashboard" icon={LayoutDashboard} label="Dashboard" />
          <Tab to="/doctor-portal/examine" icon={ClipboardPlus} label="New examination" />
          <Tab to="/doctor-portal/examinations" icon={FileText} label="My examinations" />
          <Tab to="/doctor-portal/profile" icon={UserCog} label="Profile" />
          <button onClick={onLogout} style={{
            display: 'flex', alignItems: 'center', gap: 6, padding: '7px 12px', borderRadius: 9,
            border: '1px solid var(--border)', background: 'transparent', color: 'var(--text-muted)',
            cursor: 'pointer', fontSize: 12.5,
          }}>
            <LogOut size={14} /> Sign out
          </button>
        </nav>
      </header>

      {/* Without a licence a doctor can examine but not issue — say so once, at
          the top, rather than letting them fill a form that will be refused. */}
      {me && !me.is_signable && (
        <div style={{
          display: 'flex', alignItems: 'center', gap: 8, padding: '10px 20px',
          background: '#f59e0b18', color: '#f59e0b', fontSize: 12.5, fontWeight: 600,
        }}>
          <AlertTriangle size={15} />
          Add your licence number in Profile before recording an examination — a certificate cannot be issued without one.
        </div>
      )}

      <main style={{ padding: 20 }}>
        <Outlet context={{ module, me, refreshMe: () => medicalApi.doctor.me().then(setMe) }} />
      </main>
    </div>
  )
}

function Tab({ to, icon: Icon, label }) {
  return (
    <NavLink
      to={to}
      style={({ isActive }) => ({
        display: 'flex', alignItems: 'center', gap: 6, padding: '7px 12px', borderRadius: 9,
        textDecoration: 'none', fontSize: 12.5, fontWeight: 700,
        background: isActive ? '#7C3AED22' : 'transparent',
        color: isActive ? '#a78bfa' : 'var(--text-muted)',
      })}
    >
      <Icon size={14} /> {label}
    </NavLink>
  )
}
