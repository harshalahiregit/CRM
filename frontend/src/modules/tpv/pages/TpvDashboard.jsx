import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  HardHat, Users, ShieldAlert, ScanLine, RefreshCw, ArrowRight, Rocket,
  FileCheck, BadgeCheck, AlertTriangle, Clock, UserX, ShieldX,
} from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { severityCfg, obStatusCfg, STRIKE_LIMIT, fmtDateTime } from '../constants'
import { KIT3D_STYLE as TPV_STYLE } from '@/components/ui/kit3d'

// ── Palette ─────────────────────────────────────────────────────────────────
// Colour is load-bearing in exactly ONE chart here — the gate-activity grouped
// bars, where series identity is carried by a legend rather than an inline
// label. That pair is therefore the maximally-separated option the dataviz
// validator offered: CVD ΔE 23.8 (deutan) / 34.6 (normal), well clear of the
// floor. Sky-vs-red also beats the obvious green-vs-red (ΔE 8.1, barely legal):
// green and red both shout, whereas a neutral baseline lets refusals — the
// thing you actually scan this chart for — pop as the exception.
//
// Everywhere else the marks are directly labelled, so hue is reinforcement and
// severity keeps the module's shared SEVERITY_CONFIG (see SeverityBars).
const C = {
  admitted: '#0ea5e9', refused: '#ef4444',
  stages: { started: '#8b5cf6', submitted: '#0ea5e9', approved: '#10b981' },
  vendor: '#7C3AED',
}

// ── Main ─────────────────────────────────────────────────────────────────────
export default function TpvDashboard() {
  const navigate = useNavigate()
  const [data, setData]    = useState(null)
  const [loading, setLoad] = useState(true)

  const load = () => { setLoad(true); tpvApi.dashboard.get().then(d => { setData(d?.data ?? d); setLoad(false) }).catch(() => setLoad(false)) }
  useEffect(() => { load() }, [])

  if (loading || !data) {
    return (
      <div style={{ padding: 24 }}>
        <div className="skeleton" style={{ height: 40, width: 280, borderRadius: 12, background: 'var(--border)', marginBottom: 24 }} />
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 14 }}>
          {[1, 2, 3, 4].map(i => <div key={i} className="skeleton" style={{ height: 110, borderRadius: 16, background: 'var(--border)' }} />)}
        </div>
      </div>
    )
  }

  const k = data.kpis || {}
  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{TPV_STYLE}</style>

      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>HSSE &amp; WORKFORCE</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 24, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>Vendor Overview</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Onboarding → Workforce → Badge → Gate → Strikes, unified.</p>
        </div>
        <button onClick={load} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>
          <RefreshCw size={14} /> Refresh
        </button>
      </div>

      {/* KPI tiles */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 14, marginBottom: 16 }}>
        <KpiTile label="On Site Now" value={k.on_site_now ?? 0} sub={`${k.checked_in_today ?? 0} checked in today`} icon={HardHat} grad="linear-gradient(145deg,#34d399,#10b981)" glow="#10b981" onClick={() => navigate('/app/tpv/gate-log')} />
        <KpiTile label="Active Workforce" value={k.active_workers ?? 0} sub="badged & admittable" icon={Users} grad="linear-gradient(145deg,#38bdf8,#0ea5e9)" glow="#0ea5e9" onClick={() => navigate('/app/tpv/workforce')} />
        <KpiTile label="Awaiting Review" value={k.awaiting_review ?? 0} sub={`${k.approved_vendors ?? 0} vendors approved`} icon={FileCheck} grad="linear-gradient(145deg,#fbbf24,#f59e0b)" glow="#f59e0b" onClick={() => navigate('/app/tpv/onboarding')} />
        <KpiTile label="Active Strikes" value={k.active_strikes ?? 0} sub={`${k.terminations ?? 0} termination${k.terminations === 1 ? '' : 's'} to date`} icon={ShieldAlert} grad="linear-gradient(145deg,#a78bfa,#7C3AED)" glow="#7C3AED" onClick={() => navigate('/app/tpv/strikes')} />
      </div>

      {/* Attention strip */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 14, marginBottom: 22 }}>
        <MiniStat label={`Workers one strike from removal`} value={k.at_risk ?? 0} icon={AlertTriangle} color="#f59e0b" onClick={() => navigate('/app/tpv/strikes')} danger={k.at_risk > 0} />
        <MiniStat label="Refused at the gate today" value={k.denied_today ?? 0} icon={ShieldX} color="#ef4444" onClick={() => navigate('/app/tpv/gate-log')} danger={k.denied_today > 0} />
        <MiniStat label="Badges expiring in 30 days" value={k.badges_expiring ?? 0} icon={Clock} color="#0ea5e9" onClick={() => navigate('/app/tpv/workforce')} />
      </div>

      {/* Onboarding funnel — the unifying centrepiece */}
      <OnboardingFunnel stages={data.onboarding_funnel || []} statuses={data.onboarding_status || []} onGo={navigate} />

      {/* Charts row */}
      <div style={{ display: 'grid', gridTemplateColumns: '1.9fr 1fr', gap: 16, marginBottom: 16, alignItems: 'stretch' }}>
        <GateActivityChart data={data.gate_activity || []} />
        <SeverityBars data={data.strike_severity || []} onGo={navigate} />
      </div>

      {/* Watch list + vendors */}
      <div style={{ display: 'grid', gridTemplateColumns: '1.3fr 1fr', gap: 16, marginBottom: 16 }}>
        <AtRiskPanel rows={data.at_risk || []} onGo={navigate} />
        <WorkforceByVendor data={data.workforce_by_vendor || []} />
      </div>

      <RecentDenials rows={data.recent_denials || []} onGo={navigate} />
    </div>
  )
}

// ── KPI tile ─────────────────────────────────────────────────────────────────
function KpiTile({ label, value, sub, icon: Icon, grad, glow, onClick }) {
  return (
    <div className="pr-kpi" onClick={onClick} style={{ padding: 18, cursor: onClick ? 'pointer' : 'default' }}>
      <div style={{ position: 'absolute', top: -14, right: -14, width: 72, height: 72, borderRadius: '50%', opacity: 0.1, background: grad }} />
      <div style={{ width: 42, height: 42, borderRadius: 14, display: 'flex', alignItems: 'center', justifyContent: 'center', background: grad, boxShadow: `0 8px 20px -4px ${glow}88, inset 0 1px 0 rgba(255,255,255,.3)` }}>
        <Icon size={20} color="#fff" />
      </div>
      <div style={{ fontSize: 22, fontWeight: 900, color: 'var(--text-h)', marginTop: 12, letterSpacing: '-0.02em' }}>{value}</div>
      <div style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--text-muted)', marginTop: 1 }}>{label}</div>
      {sub && <div style={{ fontSize: 10.5, color: 'var(--text-faint, var(--text-muted))', marginTop: 3 }}>{sub}</div>}
    </div>
  )
}

function MiniStat({ label, value, icon: Icon, color, onClick, danger }) {
  return (
    <div className="pr-glass pr-lift" onClick={onClick} style={{ padding: '14px 16px', display: 'flex', alignItems: 'center', gap: 12, cursor: 'pointer', borderRadius: 14, outline: danger ? `1.5px solid ${color}66` : 'none' }}>
      <div style={{ width: 38, height: 38, borderRadius: 12, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `${color}1f`, flexShrink: 0 }}><Icon size={18} style={{ color }} /></div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontSize: 22, fontWeight: 900, color: 'var(--text-h)', lineHeight: 1 }}>{value}</div>
        <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 3 }}>{label}</div>
      </div>
      <ArrowRight size={15} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
    </div>
  )
}

// ── Onboarding funnel ────────────────────────────────────────────────────────
// Unlike the purchase funnel, these stages are strict subsets of one another
// (built from submitted_at / approved_at), so "% of started" can never exceed
// 100% and is an honest conversion figure.
function OnboardingFunnel({ stages, statuses, onGo }) {
  const ICONS = { started: Rocket, submitted: FileCheck, approved: BadgeCheck }
  const started = stages[0]?.count || 0
  return (
    <div className="pr-glass" style={{ padding: 20, marginBottom: 22 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 16, flexWrap: 'wrap' }}>
        <h2 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Onboarding Pipeline</h2>
        <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>vendors reaching each stage</span>
        <div style={{ marginLeft: 'auto', display: 'flex', gap: 6, flexWrap: 'wrap' }}>
          {statuses.map(s => {
            const cfg = obStatusCfg(s.status)
            return (
              <span key={s.status} title={`${s.count} currently ${cfg.label}`}
                style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '3px 8px', borderRadius: 999, background: cfg.bg, fontSize: 11, fontWeight: 700, color: cfg.color }}>
                {cfg.label} <strong style={{ fontVariantNumeric: 'tabular-nums' }}>{s.count}</strong>
              </span>
            )
          })}
        </div>
      </div>
      {started === 0 ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>No vendor onboardings started yet.</p>
      ) : (
        <div style={{ display: 'flex', alignItems: 'stretch', gap: 0 }}>
          {stages.map((s, i) => {
            const color = C.stages[s.key] || '#7C3AED'
            const Icon = ICONS[s.key] || FileCheck
            const pct = started > 0 ? Math.round((s.count / started) * 100) : 0
            return (
              <div key={s.key} style={{ display: 'flex', alignItems: 'center', flex: 1, minWidth: 0 }}>
                <button onClick={() => onGo('/app/tpv/onboarding')} className="pr-node" title={`Open onboarding — ${s.label}`}
                  style={{ flex: 1, textAlign: 'left', cursor: 'pointer', padding: 16, borderRadius: 16, minWidth: 0,
                    background: `linear-gradient(150deg, ${color}22, ${color}0a)`, border: `1.5px solid ${color}44`,
                    boxShadow: 'inset 0 1px 0 rgba(255,255,255,.12)' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 9, marginBottom: 10 }}>
                    <span style={{ width: 34, height: 34, borderRadius: 11, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `linear-gradient(145deg, ${color}, ${color}bb)`, color: '#fff', boxShadow: `0 6px 14px -3px ${color}aa, inset 0 1px 0 rgba(255,255,255,.4)`, flexShrink: 0 }}>
                      <Icon size={17} />
                    </span>
                    <div style={{ minWidth: 0 }}>
                      <div style={{ fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)' }}>{s.label}</div>
                      <div style={{ fontSize: 10, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.05em', color }}>
                        {i === 0 ? 'all vendors' : `${pct}% of started`}
                      </div>
                    </div>
                  </div>
                  <div style={{ fontSize: 19, fontWeight: 900, color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
                    {s.count} <span style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-muted)' }}>vendor{s.count === 1 ? '' : 's'}</span>
                  </div>
                  <div className="pr-bar" style={{ marginTop: 9, background: 'rgba(0,0,0,0.12)' }}>
                    <span style={{ width: `${Math.max(4, pct)}%`, background: `linear-gradient(90deg, ${color}, ${color}cc)` }} />
                  </div>
                </button>
                {i < stages.length - 1 && (
                  <div style={{ flexShrink: 0, padding: '0 6px', color: 'var(--text-muted)' }}><ArrowRight size={18} /></div>
                )}
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}

// ── Gate activity (SVG, 2 series, hover tooltip) ─────────────────────────────
// Scans are whole people — the axis must never offer "2.5 scans", so ticks are
// forced to integers rather than reusing the money chart's fractional steps.
function intAxis(maxVal) {
  const m = Math.max(1, maxVal)
  const raw = m / 4
  const pow = Math.pow(10, Math.floor(Math.log10(Math.max(1, raw))))
  const step = Math.max(1, [1, 2, 2.5, 5, 10].map(x => x * pow).find(s => s >= raw && Number.isInteger(s)) ?? 10 * pow)
  const top = Math.ceil(m / step) * step
  const ticks = []
  for (let v = 0; v <= top; v += step) ticks.push(v)
  return { top, ticks }
}

function GateActivityChart({ data }) {
  const [hover, setHover] = useState(null)
  const W = 640, H = 260, P = { l: 42, r: 14, t: 14, b: 34 }
  const pw = W - P.l - P.r, ph = H - P.t - P.b
  const { top: max, ticks } = intAxis(Math.max(...data.flatMap(d => [d.admitted, d.refused]), 0))
  const groups = data.length || 1
  const gw = pw / groups
  const barW = Math.min(22, (gw - 14) / 2)
  const y = (v) => P.t + ph - (v / max) * ph
  const totalScans = data.reduce((s, d) => s + d.admitted + d.refused, 0)

  return (
    <div className="pr-glass" style={{ padding: 20, display: 'flex', flexDirection: 'column' }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6, flexWrap: 'wrap', gap: 8 }}>
        <div>
          <h2 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Gate Activity</h2>
          <p style={{ margin: '2px 0 0', fontSize: 11.5, color: 'var(--text-muted)' }}>Admitted vs refused · last 7 days</p>
        </div>
        <div style={{ display: 'flex', gap: 14 }}>
          {[['Admitted', C.admitted], ['Refused', C.refused]].map(([l, c]) => (
            <span key={l} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12, color: 'var(--text-muted)', fontWeight: 600 }}>
              <span style={{ width: 11, height: 11, borderRadius: 3, background: c, boxShadow: `0 2px 5px -1px ${c}` }} /> {l}
            </span>
          ))}
        </div>
      </div>

      <div style={{ position: 'relative', flex: 1 }}>
        <svg viewBox={`0 0 ${W} ${H}`} width="100%" style={{ display: 'block', overflow: 'visible' }}>
          <defs>
            <filter id="gateBarShadow" x="-30%" y="-30%" width="160%" height="160%">
              <feDropShadow dx="0" dy="3" stdDeviation="2.5" floodOpacity="0.28" />
            </filter>
            <linearGradient id="gAdmitted" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stopColor="#38bdf8" /><stop offset="1" stopColor={C.admitted} /></linearGradient>
            <linearGradient id="gRefused" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stopColor="#f87171" /><stop offset="1" stopColor={C.refused} /></linearGradient>
          </defs>
          {ticks.map((t, i) => (
            <g key={i}>
              <line x1={P.l} y1={y(t)} x2={W - P.r} y2={y(t)} stroke="var(--border)" strokeWidth="1" />
              <text x={P.l - 8} y={y(t) + 3.5} textAnchor="end" fontSize="10" fill="var(--text-muted)" style={{ fontVariantNumeric: 'tabular-nums' }}>{t}</text>
            </g>
          ))}
          {data.map((d, i) => {
            const cx = P.l + i * gw + gw / 2
            const x1 = cx - barW - 1, x2 = cx + 1
            const on = hover === i
            return (
              <g key={i} onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)} style={{ cursor: 'pointer' }}>
                <rect x={P.l + i * gw} y={P.t} width={gw} height={ph} fill={on ? 'var(--bg-input)' : 'transparent'} opacity="0.5" rx="6" />
                <rect x={x1} y={y(d.admitted)} width={barW} height={Math.max(0, ph - (y(d.admitted) - P.t))} rx="4" fill="url(#gAdmitted)" filter="url(#gateBarShadow)" />
                <rect x={x2} y={y(d.refused)} width={barW} height={Math.max(0, ph - (y(d.refused) - P.t))} rx="4" fill="url(#gRefused)" filter="url(#gateBarShadow)" />
                <text x={cx} y={H - P.b + 16} textAnchor="middle" fontSize="11" fontWeight="600" fill="var(--text-muted)">{d.day}</text>
              </g>
            )
          })}
          <line x1={P.l} y1={P.t + ph} x2={W - P.r} y2={P.t + ph} stroke="var(--border-purple, var(--border))" strokeWidth="1.5" />
        </svg>
        {/* An all-zero week draws an empty grid, which reads as "broken" rather
            than "quiet" — say so instead of leaving the reader guessing. */}
        {totalScans === 0 && (
          <div style={{ position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', pointerEvents: 'none' }}>
            <span style={{ fontSize: 12.5, color: 'var(--text-muted)', background: 'var(--bg-card)', padding: '5px 12px', borderRadius: 8, border: '1px solid var(--border)' }}>No gate traffic in the last 7 days</span>
          </div>
        )}
        {hover != null && data[hover] && totalScans > 0 && (
          <div style={{ position: 'absolute', top: 0, left: `${(P.l + hover * gw + gw / 2) / W * 100}%`, transform: 'translateX(-50%)', pointerEvents: 'none' }}>
            <div className="pr-glass" style={{ padding: '8px 12px', borderRadius: 10, whiteSpace: 'nowrap', boxShadow: '0 12px 30px -8px rgba(0,0,0,.5)' }}>
              <div style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-h)', marginBottom: 4 }}>{data[hover].day} · {data[hover].date?.slice(5)}</div>
              <div style={{ fontSize: 11, color: 'var(--text-muted)' }}><span style={{ color: C.admitted, fontWeight: 700 }}>Admitted</span> {data[hover].admitted}</div>
              <div style={{ fontSize: 11, color: 'var(--text-muted)' }}><span style={{ color: C.refused, fontWeight: 700 }}>Refused</span> {data[hover].refused}</div>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}

// ── Active strikes by severity ───────────────────────────────────────────────
// Directly-labelled bars, deliberately NOT a donut. The severity hues are an
// adjacent warm ramp (amber/orange/red) that the dataviz validator rejects as a
// categorical palette — ΔE 9.6, below the normal-vision floor — so matching an
// unlabelled arc back to a legend swatch would be guesswork. Giving every bar
// its own label and count makes text the identity carrier and hue mere
// reinforcement, which keeps this consistent with the SEVERITY_CONFIG pills
// used on the Strike Ledger instead of inventing a second severity palette.
function SeverityBars({ data, onGo }) {
  const total = data.reduce((s, d) => s + d.count, 0)
  const max = Math.max(1, ...data.map(d => d.count))
  return (
    <div className="pr-glass" style={{ padding: 20, display: 'flex', flexDirection: 'column' }}>
      <h2 style={{ margin: '0 0 2px', fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Active Strikes</h2>
      <p style={{ margin: '0 0 14px', fontSize: 11.5, color: 'var(--text-muted)' }}>
        {total === 0 ? 'None outstanding' : `${total} outstanding · access removed at ${STRIKE_LIMIT}`}
      </p>
      {total === 0 ? (
        <div style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 8, padding: '10px 0' }}>
          <div style={{ width: 44, height: 44, borderRadius: 14, background: 'rgba(16,185,129,0.12)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
            <ShieldAlert size={20} style={{ color: '#10b981' }} />
          </div>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: 0, textAlign: 'center' }}>No active safety strikes.</p>
        </div>
      ) : (
        /* Spread the rows down the card rather than centring them: this panel is
           stretched to the height of the chart beside it, and a tight cluster
           with 100px of void above it reads as missing content, not as calm. */
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14, flex: 1, justifyContent: 'space-evenly' }}>
          {data.map(s => {
            const cfg = severityCfg(s.severity)
            const pct = Math.round((s.count / max) * 100)
            return (
              <div key={s.severity} onClick={() => onGo('/app/tpv/strikes')} style={{ cursor: 'pointer' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginBottom: 5 }}>
                  <span style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>
                    {cfg.label}
                    {s.severity === 'Critical' && <span style={{ fontSize: 10.5, fontWeight: 600, color: 'var(--text-muted)' }}> · removes access alone</span>}
                  </span>
                  <span style={{ fontSize: 15, fontWeight: 900, color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>{s.count}</span>
                </div>
                <div className="pr-bar" style={{ height: 10 }}>
                  <span style={{ width: s.count === 0 ? '0%' : `${Math.max(4, pct)}%`, background: `linear-gradient(90deg, ${cfg.color}bb, ${cfg.color})`, boxShadow: `0 2px 8px -1px ${cfg.color}88` }} />
                </div>
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}

// ── At-risk watch list ───────────────────────────────────────────────────────
function AtRiskPanel({ rows, onGo }) {
  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 4 }}>
        <h2 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>At-Risk Watch List</h2>
        <button onClick={() => onGo('/app/tpv/strikes')} style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 12, fontWeight: 700, color: '#a78bfa', background: 'none', border: 'none', cursor: 'pointer' }}>Strike ledger <ArrowRight size={12} /></button>
      </div>
      <p style={{ margin: '0 0 14px', fontSize: 11.5, color: 'var(--text-muted)' }}>One more strike removes site access</p>
      {rows.length === 0 ? (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '14px 0' }}>
          <div style={{ width: 38, height: 38, borderRadius: 12, background: 'rgba(16,185,129,0.12)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
            <BadgeCheck size={18} style={{ color: '#10b981' }} />
          </div>
          <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>No workers are close to removal.</p>
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {rows.map(w => (
            <div key={w.id} onClick={() => onGo(`/app/tpv/workforce/${w.id}`)} className="pr-lift"
              style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '10px 12px', borderRadius: 12, cursor: 'pointer', background: 'rgba(245,158,11,0.07)', border: '1px solid rgba(245,158,11,0.28)' }}>
              <div style={{ width: 34, height: 34, borderRadius: 11, background: 'rgba(245,158,11,0.16)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                <UserX size={16} style={{ color: '#f59e0b' }} />
              </div>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{w.name}</div>
                <div style={{ fontSize: 11, color: 'var(--text-muted)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                  {w.badge_number || w.worker_code}{w.vendor ? ` · ${w.vendor}` : ''}
                </div>
              </div>
              {/* Count in dots AND in words — the meter is never colour-alone. */}
              <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexShrink: 0 }}>
                <span style={{ display: 'flex', gap: 3 }}>
                  {Array.from({ length: w.limit || STRIKE_LIMIT }).map((_, i) => (
                    <span key={i} style={{ width: 8, height: 8, borderRadius: '50%', background: i < w.active_strikes ? '#f59e0b' : 'var(--border)', boxShadow: i < w.active_strikes ? '0 1px 4px -1px #f59e0b' : 'none' }} />
                  ))}
                </span>
                <span style={{ fontSize: 12, fontWeight: 800, color: '#f59e0b', fontVariantNumeric: 'tabular-nums' }}>{w.active_strikes}/{w.limit || STRIKE_LIMIT}</span>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

// ── Workforce by vendor (single hue = magnitude) ──────────────────────────────
function WorkforceByVendor({ data }) {
  const max = Math.max(1, ...data.map(d => d.workers))
  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <h2 style={{ margin: '0 0 2px', fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Workforce by Vendor</h2>
      <p style={{ margin: '0 0 14px', fontSize: 11.5, color: 'var(--text-muted)' }}>Active headcount · on site now</p>
      {data.length === 0 ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>No active workforce yet.</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          {data.map((v, i) => {
            const pct = Math.round((v.workers / max) * 100)
            return (
              <div key={v.name + i}>
                <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, marginBottom: 5 }}>
                  <span style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{v.name}</span>
                  <span style={{ fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums', flexShrink: 0 }}>
                    {v.workers} <span style={{ fontWeight: 600, color: 'var(--text-muted)', fontSize: 11 }}>· {v.on_site} on site</span>
                  </span>
                </div>
                <div className="pr-bar" style={{ height: 10 }}>
                  <span style={{ width: `${Math.max(3, pct)}%`, background: `linear-gradient(90deg, ${C.vendor}bb, ${C.vendor})`, boxShadow: `0 2px 8px -1px ${C.vendor}88` }} />
                </div>
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}

// ── Recent refusals ──────────────────────────────────────────────────────────
function RecentDenials({ rows, onGo }) {
  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 }}>
        <div>
          <h2 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Recent Refusals</h2>
          <p style={{ margin: '2px 0 0', fontSize: 11.5, color: 'var(--text-muted)' }}>Who the gate turned away, and why</p>
        </div>
        <button onClick={() => onGo('/app/tpv/gate-log')} style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 12, fontWeight: 700, color: '#a78bfa', background: 'none', border: 'none', cursor: 'pointer' }}>Gate log <ArrowRight size={12} /></button>
      </div>
      {rows.length === 0 ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>No one has been refused entry.</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {rows.map(r => (
            <div key={r.id} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '10px 12px', borderRadius: 12, background: 'rgba(239,68,68,0.06)', border: '1px solid rgba(239,68,68,0.22)' }}>
              <div style={{ width: 32, height: 32, borderRadius: 10, background: 'rgba(239,68,68,0.15)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                <ScanLine size={15} style={{ color: '#ef4444' }} />
              </div>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>
                  {r.worker} {r.worker_code && <span style={{ fontWeight: 500, color: 'var(--text-muted)' }}>· {r.worker_code}</span>}
                </div>
                {/* The reasons are the whole point of this panel — show them all,
                    not just the first, since a badge can fail several gates at once. */}
                <div style={{ fontSize: 11, color: '#f87171', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                  {(r.reasons || []).join(' · ') || 'No reason recorded'}
                </div>
              </div>
              <div style={{ fontSize: 11, color: 'var(--text-muted)', flexShrink: 0, textAlign: 'right' }}>
                <div>{fmtDateTime(r.scanned_at)}</div>
                {r.gate && <div style={{ fontSize: 10.5 }}>{r.gate}</div>}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
