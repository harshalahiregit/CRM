import { useEffect, useMemo, useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import {
  Stethoscope, LayoutDashboard, ClipboardPlus, FileText, UserCog, LogOut,
  AlertTriangle, Menu, X, HardHat, Package, Users, Building2, UserPlus,
} from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import { medicalApi } from '@/services/medicalApi'
import { KIT3D_STYLE as TPV_STYLE } from '@/components/ui/kit3d'

/**
 * The doctor portal shell.
 *
 * ── One left panel, not a top menu ──────────────────────────────────────
 * Everything used to sit along the top: the brand, the audience switch, four
 * navigation tabs and the sign-out button, all competing for one row. On a
 * tablet — which is what the teams on site actually hold — that row wrapped
 * into two or three, and the switch a doctor uses on every single examination
 * was the thing pushed off the end.
 *
 * A left panel has room for the audience list to be a list, gives the top back
 * to the screen's own title, and is what every other part of this system
 * already does, so a doctor moving between them is not learning a new shape.
 *
 * ── Who the portal serves ───────────────────────────────────────────────
 * Five audiences, not two. The vendor sides keep their own registers and their
 * vendor-then-worker flow; the internal team, clients and site visitors have no
 * vendor above them and are one flat list each. The switch names them all so a
 * doctor never has to know which register a person happens to live in.
 *
 * ── Tablets ─────────────────────────────────────────────────────────────
 * The panel is fixed and slides in below 1024px, with a hamburger in a compact
 * top bar; hit targets are sized for a finger rather than a cursor. Above that
 * width it is simply always open.
 */
export default function DoctorPortalShell() {
  const { logout } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [me, setMe] = useState(null)
  const [module, setModule] = useState(() => localStorage.getItem('doctor.module') || 'tpv')
  const [navOpen, setNavOpen] = useState(false)

  useEffect(() => {
    medicalApi.doctor.me().then(d => {
      setMe(d)
      // A doctor limited to one vendor side should land on it, not on a 404.
      const allowed = audiencesFor(d).map(a => a.key)
      if (allowed.length && !allowed.includes(module)) pick(allowed[0])
    }).catch(() => setMe(null))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  /**
   * Switch which audience is being examined.
   *
   * Leaving an open examination form is deliberate. The form and the group
   * comparison are both ABOUT specific people, and those people belong to the
   * audience that was selected when they were chosen — staying put after a
   * switch would leave a TPV worker's form on screen under the heading "Site
   * visitors", with a save button that writes to the wrong register.
   *
   * Any draft typed so far survives the move: it is stored against the person,
   * not against the page, so coming back to them restores it.
   */
  const pick = (m) => {
    setModule(m)
    localStorage.setItem('doctor.module', m)
    setNavOpen(false)

    if (location.pathname.startsWith('/doctor-portal/examine/')) {
      navigate('/doctor-portal/examine')
    }
  }

  const onLogout = async () => { try { await logout() } finally { navigate('/auth/login') } }

  const audiences = useMemo(() => audiencesFor(me), [me])
  const current = audiences.find(a => a.key === module) ?? audiences[0]

  return (
    <div style={{ minHeight: '100vh', background: 'var(--bg-app, #0b1020)' }}>
      <style>{TPV_STYLE}</style>
      <style>{RESPONSIVE}</style>

      {/* Compact bar — only on tablets and phones, where the panel is hidden. */}
      <div className="dp-topbar">
        <button onClick={() => setNavOpen(true)} aria-label="Open menu" className="dp-iconbtn">
          <Menu size={20} />
        </button>
        <div style={{ fontSize: 14, fontWeight: 900, color: 'var(--text-h)' }}>Doctor Portal</div>
        <div style={{ marginLeft: 'auto', fontSize: 12, color: 'var(--text-muted)' }}>{current?.label}</div>
      </div>

      {/* Backdrop, so a tap outside the panel closes it on a tablet. */}
      {navOpen && <div className="dp-backdrop" onClick={() => setNavOpen(false)} />}

      <aside className={`dp-panel${navOpen ? ' dp-panel-open' : ''}`}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '4px 4px 14px' }}>
          <div style={{ width: 34, height: 34, borderRadius: 10, background: '#7C3AED22', color: '#a78bfa', display: 'grid', placeItems: 'center', flexShrink: 0 }}>
            <Stethoscope size={18} />
          </div>
          <div style={{ minWidth: 0 }}>
            <div style={{ fontSize: 14, fontWeight: 900, color: 'var(--text-h)' }}>Doctor Portal</div>
            <div style={{ fontSize: 11, color: 'var(--text-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
              {me?.name || '—'}
            </div>
          </div>
          <button onClick={() => setNavOpen(false)} aria-label="Close menu" className="dp-iconbtn dp-closebtn">
            <X size={18} />
          </button>
        </div>

        <PanelLabel>Examining</PanelLabel>
        {audiences.map(a => (
          <button key={a.key} onClick={() => pick(a.key)} className="dp-item"
            style={module === a.key ? ACTIVE : undefined}>
            <a.icon size={16} /> <span>{a.label}</span>
          </button>
        ))}

        <PanelLabel>Portal</PanelLabel>
        <Item to="/doctor-portal/dashboard" icon={LayoutDashboard} label="Dashboard" onGo={() => setNavOpen(false)} />
        <Item to="/doctor-portal/examine" icon={ClipboardPlus} label="New examination" onGo={() => setNavOpen(false)} />
        <Item to="/doctor-portal/examinations" icon={FileText} label="My examinations" onGo={() => setNavOpen(false)} />
        <Item to="/doctor-portal/profile" icon={UserCog} label="My profile" onGo={() => setNavOpen(false)} />

        <button onClick={onLogout} className="dp-item" style={{ marginTop: 'auto' }}>
          <LogOut size={16} /> <span>Sign out</span>
        </button>
      </aside>

      <div className="dp-main">
        {/* Without a licence a doctor can examine but not issue — say so once,
            at the top, rather than letting them fill a form that is refused. */}
        {me && !me.is_signable && (
          <div style={{
            display: 'flex', alignItems: 'center', gap: 8, padding: '10px 16px', borderRadius: 12,
            background: '#f59e0b18', color: '#f59e0b', fontSize: 12.5, fontWeight: 600, marginBottom: 14,
          }}>
            <AlertTriangle size={15} style={{ flexShrink: 0 }} />
            Add your licence number in My profile before recording an examination — a certificate cannot be issued without one.
          </div>
        )}

        <Outlet context={{
          module,
          audience: current,
          me,
          refreshMe: () => medicalApi.doctor.me().then(setMe),
        }} />
      </div>
    </div>
  )
}

/* ── Who this doctor may examine ─────────────────────────────────────────── */

/**
 * The vendor sides come from the doctor's own profile — a doctor assigned to
 * Purchase only never sees TPV. The other three are people of this company
 * rather than of a vendor, so any doctor of the workspace may examine them.
 */
function audiencesFor(me) {
  const vendorSides = me?.modules ?? ['tpv', 'purchase']

  return [
    ...(vendorSides.includes('tpv') ? [{ key: 'tpv', label: 'TPV workers', icon: HardHat, kind: 'vendor' }] : []),
    ...(vendorSides.includes('purchase') ? [{ key: 'purchase', label: 'Purchase workers', icon: Package, kind: 'vendor' }] : []),
    { key: 'internal', label: 'Internal team', icon: Users, kind: 'general' },
    { key: 'client', label: 'Client contacts', icon: Building2, kind: 'general' },
    { key: 'visitor', label: 'Site visitors', icon: UserPlus, kind: 'general' },
  ]
}

/* ── Pieces ──────────────────────────────────────────────────────────────── */

const ACTIVE = { background: '#7C3AED', color: '#fff' }

const PanelLabel = ({ children }) => (
  <div style={{
    fontSize: 10, fontWeight: 900, letterSpacing: '0.08em', textTransform: 'uppercase',
    color: 'var(--text-muted)', padding: '12px 10px 5px',
  }}>{children}</div>
)

function Item({ to, icon: Icon, label, onGo }) {
  return (
    <NavLink to={to} onClick={onGo} className="dp-item"
      style={({ isActive }) => (isActive ? ACTIVE : undefined)}>
      <Icon size={16} /> <span>{label}</span>
    </NavLink>
  )
}

/**
 * One place for the layout rules, because they are all about width and a style
 * object cannot express a media query.
 *
 * 44px minimum touch height throughout: that is the size a finger reliably hits
 * on a tablet, and this portal is used standing up with gloves on more often
 * than it is used at a desk.
 */
const RESPONSIVE = `
.dp-panel {
  position: fixed; top: 0; left: 0; bottom: 0; width: 244px; z-index: 60;
  display: flex; flex-direction: column; padding: 16px 12px;
  background: var(--bg-card); border-right: 1px solid var(--border);
  overflow-y: auto; transition: transform .2s ease;
}
.dp-main { padding: 20px; margin-left: 244px; min-height: 100vh; }
.dp-topbar, .dp-backdrop, .dp-closebtn { display: none; }

.dp-item {
  display: flex; align-items: center; gap: 10px; width: 100%;
  min-height: 44px; padding: 10px 12px; margin-bottom: 3px;
  border: none; border-radius: 11px; background: transparent;
  color: var(--text-body, #c8c3dd); font-size: 13.5px; font-weight: 700;
  cursor: pointer; text-align: left; text-decoration: none;
}
.dp-item:hover { background: rgba(124,58,237,0.10); }

.dp-iconbtn {
  width: 40px; height: 40px; border-radius: 11px; display: grid; place-items: center;
  border: 1px solid var(--border); background: var(--bg-card);
  color: var(--text-muted); cursor: pointer; flex-shrink: 0;
}

/* Tablets and phones: the panel slides over the page instead of taking a
   quarter of a screen that is only 768px wide to begin with. */
@media (max-width: 1023px) {
  .dp-panel { transform: translateX(-100%); box-shadow: 0 0 40px rgba(0,0,0,.45); }
  .dp-panel-open { transform: translateX(0); }
  .dp-main { margin-left: 0; padding: 14px; }
  .dp-closebtn { display: grid; margin-left: auto; }
  .dp-topbar {
    display: flex; align-items: center; gap: 12px; padding: 10px 14px;
    background: var(--bg-card); border-bottom: 1px solid var(--border);
    position: sticky; top: 0; z-index: 50;
  }
  .dp-backdrop { display: block; position: fixed; inset: 0; z-index: 55; background: rgba(0,0,0,.5); }
}

/* Portrait tablets and phones: forms go to one column rather than squeezing
   two into 700px, and inputs stay at 16px so iOS does not zoom on focus. */
@media (max-width: 820px) {
  .dp-main input, .dp-main select, .dp-main textarea { font-size: 16px; }
}
`
