import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  IndianRupee, Wallet, ShoppingBag, TrendingUp, RefreshCw, ArrowRight,
  ClipboardList, PackageCheck, FileText, AlertTriangle, Clock, Truck, Plus,
} from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { pinvStatusCfg, fmtMoney, fmtMoneyShort, fmtDate } from '../constants'
import { KIT3D_STYLE as PURCHASE_STYLE, StatusBadge as StatusPill } from '@/components/ui/kit3d'
import RaiseTicketButton from '@/components/RaiseTicketButton'

// ── Validated palette (see dataviz validator run) ────────────────────────────
// Categorical hues carry direct labels/legends throughout, so identity is never
// colour-alone (the secondary-encoding the validator requires).
const C = {
  ordered: '#7C3AED', paid: '#10b981',          // monthly 2-series (CVD ΔE 29.6)
  stages: { requests: '#8b5cf6', orders: '#0ea5e9', received: '#f59e0b', invoiced: '#10b981' },
  vendor: '#7C3AED',
}

// ── Main ─────────────────────────────────────────────────────────────────────
export default function PurchaseDashboard() {
  const navigate = useNavigate()
  const [data, setData]     = useState(null)
  const [loading, setLoad]  = useState(true)

  const load = () => { setLoad(true); purchaseApi.dashboard.get().then(d => { setData(d?.data ?? d); setLoad(false) }).catch(() => setLoad(false)) }
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
      <style>{PURCHASE_STYLE}</style>

      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>PROCURE-TO-PAY</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 24, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>Purchase Overview</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Requests → Orders → Receipts → Invoices, unified.</p>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <RaiseTicketButton source="purchase" />
          <button onClick={load} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>
            <RefreshCw size={14} /> Refresh
          </button>
        </div>
      </div>

      {/* KPI tiles */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 14, marginBottom: 16 }}>
        <KpiTile label="Total Paid" value={fmtMoney(k.total_paid)} sub={`${fmtMoneyShort(k.paid_mtd)} this month`} icon={IndianRupee} grad="linear-gradient(145deg,#34d399,#10b981)" glow="#10b981" />
        <KpiTile label="Outstanding" value={fmtMoney(k.outstanding)} sub="approved & unpaid" icon={Wallet} grad="linear-gradient(145deg,#fbbf24,#f59e0b)" glow="#f59e0b" onClick={() => navigate('/app/purchase/invoices')} />
        <KpiTile label="Open PO Value" value={fmtMoney(k.open_po_value)} sub={`${k.po_open || 0} live orders`} icon={ShoppingBag} grad="linear-gradient(145deg,#38bdf8,#0ea5e9)" glow="#0ea5e9" onClick={() => navigate('/app/purchase/orders')} />
        <KpiTile label="Paid This Month" value={fmtMoney(k.paid_mtd)} sub="cash out — MTD" icon={TrendingUp} grad="linear-gradient(145deg,#a78bfa,#7C3AED)" glow="#7C3AED" />
      </div>

      {/* Attention strip */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 14, marginBottom: 22 }}>
        <MiniStat label="Requests awaiting approval" value={k.pr_pending || 0} icon={Clock} color="#f59e0b" onClick={() => navigate('/app/purchase/requests')} />
        <MiniStat label="Orders awaiting receipt" value={k.po_open || 0} icon={Truck} color="#0ea5e9" onClick={() => navigate('/app/purchase/orders')} />
        <MiniStat label="Overdue invoices" value={k.overdue_invoices || 0} icon={AlertTriangle} color="#ef4444" onClick={() => navigate('/app/purchase/invoices')} danger={k.overdue_invoices > 0} />
      </div>

      {/* Procure-to-pay funnel — the unifying centrepiece */}
      <Funnel stages={data.funnel || []} onGo={navigate} />

      {/* Charts row */}
      <div style={{ display: 'grid', gridTemplateColumns: '1.9fr 1fr', gap: 16, marginBottom: 16, alignItems: 'stretch' }}>
        <MonthlyChart data={data.monthly || []} />
        <StatusDonut data={data.invoice_status || []} />
      </div>

      {/* Vendors + recent */}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1.3fr', gap: 16 }}>
        <TopVendors data={data.by_vendor || []} />
        <RecentInvoices rows={data.recent_invoices || []} onGo={navigate} />
      </div>
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
    <div className="pr-glass pr-lift" onClick={onClick} style={{ padding: '14px 16px', display: 'flex', alignItems: 'center', gap: 12, cursor: 'pointer', borderRadius: 14, outline: danger ? '1.5px solid rgba(239,68,68,0.4)' : 'none' }}>
      <div style={{ width: 38, height: 38, borderRadius: 12, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `${color}1f`, flexShrink: 0 }}><Icon size={18} style={{ color }} /></div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontSize: 22, fontWeight: 900, color: 'var(--text-h)', lineHeight: 1 }}>{value}</div>
        <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 3 }}>{label}</div>
      </div>
      <ArrowRight size={15} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
    </div>
  )
}

// ── Procure-to-pay funnel ────────────────────────────────────────────────────
function Funnel({ stages, onGo }) {
  const ICONS = { requests: ClipboardList, orders: ShoppingBag, received: PackageCheck, invoiced: FileText }
  const PATHS = { requests: '/app/purchase/requests', orders: '/app/purchase/orders', received: '/app/purchase/orders', invoiced: '/app/purchase/invoices' }
  // Bars are sized against the largest stage (a magnitude comparison), not
  // stage 1 — later stages legitimately exceed requests when POs/invoices are
  // raised directly, so a strict "% of requests" funnel would read >100%.
  const peak = Math.max(1, ...stages.map(s => s.value))
  return (
    <div className="pr-glass" style={{ padding: 20, marginBottom: 22 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 16 }}>
        <h2 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Procure-to-Pay Pipeline</h2>
        <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>count · value at each stage</span>
      </div>
      <div style={{ display: 'flex', alignItems: 'stretch', gap: 0 }}>
        {stages.map((s, i) => {
          const color = C.stages[s.key] || '#7C3AED'
          const Icon = ICONS[s.key] || FileText
          const pct = Math.round((s.value / peak) * 100)
          return (
            <div key={s.key} style={{ display: 'flex', alignItems: 'center', flex: 1, minWidth: 0 }}>
              <button onClick={() => onGo(PATHS[s.key])} className="pr-node" title={`Open ${s.label}`}
                style={{ flex: 1, textAlign: 'left', cursor: 'pointer', padding: 16, borderRadius: 16, minWidth: 0,
                  background: `linear-gradient(150deg, ${color}22, ${color}0a)`, border: `1.5px solid ${color}44`,
                  boxShadow: `inset 0 1px 0 rgba(255,255,255,.12)` }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 9, marginBottom: 10 }}>
                  <span style={{ width: 34, height: 34, borderRadius: 11, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `linear-gradient(145deg, ${color}, ${color}bb)`, color: '#fff', boxShadow: `0 6px 14px -3px ${color}aa, inset 0 1px 0 rgba(255,255,255,.4)`, flexShrink: 0 }}>
                    <Icon size={17} />
                  </span>
                  <div style={{ minWidth: 0 }}>
                    <div style={{ fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)' }}>{s.label}</div>
                    <div style={{ fontSize: 10, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.05em', color }}>{s.count} item{s.count === 1 ? '' : 's'}</div>
                  </div>
                </div>
                <div style={{ fontSize: 19, fontWeight: 900, color: 'var(--text-h)', letterSpacing: '-0.02em' }}>{fmtMoneyShort(s.value)}</div>
                {/* proportional bar vs stage 1 */}
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
    </div>
  )
}

// ── Monthly grouped-bar chart (SVG, 2 series, hover tooltip) ─────────────────
function niceMax(v) {
  if (v <= 0) return 100
  const pow = Math.pow(10, Math.floor(Math.log10(v)))
  const n = v / pow
  // Finer steps than 1/2/5 so the axis top hugs the data (e.g. 2.24 → 2.5, not 5).
  const step = n <= 1 ? 1 : n <= 1.5 ? 1.5 : n <= 2 ? 2 : n <= 2.5 ? 2.5
    : n <= 3 ? 3 : n <= 4 ? 4 : n <= 5 ? 5 : n <= 7.5 ? 7.5 : 10
  return step * pow
}
function MonthlyChart({ data }) {
  const [hover, setHover] = useState(null)
  const W = 640, H = 260, P = { l: 52, r: 14, t: 14, b: 34 }
  const pw = W - P.l - P.r, ph = H - P.t - P.b
  const max = niceMax(Math.max(1, ...data.flatMap(d => [d.ordered, d.paid])))
  const groups = data.length || 1
  const gw = pw / groups
  const barW = Math.min(22, (gw - 14) / 2)
  const y = (v) => P.t + ph - (v / max) * ph
  const ticks = [0, 0.25, 0.5, 0.75, 1].map(f => f * max)

  return (
    <div className="pr-glass" style={{ padding: 20, display: 'flex', flexDirection: 'column' }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6 }}>
        <div>
          <h2 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Monthly Spend</h2>
          <p style={{ margin: '2px 0 0', fontSize: 11.5, color: 'var(--text-muted)' }}>Ordered vs paid · last 6 months</p>
        </div>
        <div style={{ display: 'flex', gap: 14 }}>
          {[['Ordered', C.ordered], ['Paid', C.paid]].map(([l, c]) => (
            <span key={l} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12, color: 'var(--text-muted)', fontWeight: 600 }}>
              <span style={{ width: 11, height: 11, borderRadius: 3, background: c, boxShadow: `0 2px 5px -1px ${c}` }} /> {l}
            </span>
          ))}
        </div>
      </div>

      <div style={{ position: 'relative', flex: 1 }}>
        <svg viewBox={`0 0 ${W} ${H}`} width="100%" style={{ display: 'block', overflow: 'visible' }}>
          <defs>
            <filter id="barShadow" x="-30%" y="-30%" width="160%" height="160%">
              <feDropShadow dx="0" dy="3" stdDeviation="2.5" floodOpacity="0.28" />
            </filter>
            <linearGradient id="gOrdered" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stopColor="#a78bfa" /><stop offset="1" stopColor={C.ordered} /></linearGradient>
            <linearGradient id="gPaid" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stopColor="#34d399" /><stop offset="1" stopColor={C.paid} /></linearGradient>
          </defs>
          {/* gridlines + y labels */}
          {ticks.map((t, i) => (
            <g key={i}>
              <line x1={P.l} y1={y(t)} x2={W - P.r} y2={y(t)} stroke="var(--border)" strokeWidth="1" />
              <text x={P.l - 8} y={y(t) + 3.5} textAnchor="end" fontSize="10" fill="var(--text-muted)" style={{ fontVariantNumeric: 'tabular-nums' }}>{fmtMoneyShort(t)}</text>
            </g>
          ))}
          {/* bars */}
          {data.map((d, i) => {
            const cx = P.l + i * gw + gw / 2
            const x1 = cx - barW - 1, x2 = cx + 1
            const on = hover === i
            return (
              <g key={i} onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)} style={{ cursor: 'pointer' }}>
                <rect x={P.l + i * gw} y={P.t} width={gw} height={ph} fill={on ? 'var(--bg-input)' : 'transparent'} opacity="0.5" rx="6" />
                <rect x={x1} y={y(d.ordered)} width={barW} height={Math.max(0, ph - (y(d.ordered) - P.t))} rx="4" fill="url(#gOrdered)" filter="url(#barShadow)" />
                <rect x={x2} y={y(d.paid)} width={barW} height={Math.max(0, ph - (y(d.paid) - P.t))} rx="4" fill="url(#gPaid)" filter="url(#barShadow)" />
                <text x={cx} y={H - P.b + 16} textAnchor="middle" fontSize="11" fontWeight="600" fill="var(--text-muted)">{d.month}</text>
              </g>
            )
          })}
          {/* baseline */}
          <line x1={P.l} y1={P.t + ph} x2={W - P.r} y2={P.t + ph} stroke="var(--border-purple, var(--border))" strokeWidth="1.5" />
        </svg>
        {/* tooltip */}
        {hover != null && data[hover] && (
          <div style={{ position: 'absolute', top: 0, left: `${(P.l + hover * gw + gw / 2) / W * 100}%`, transform: 'translateX(-50%)', pointerEvents: 'none' }}>
            <div className="pr-glass" style={{ padding: '8px 12px', borderRadius: 10, whiteSpace: 'nowrap', boxShadow: '0 12px 30px -8px rgba(0,0,0,.5)' }}>
              <div style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-h)', marginBottom: 4 }}>{data[hover].month}</div>
              <div style={{ fontSize: 11, color: 'var(--text-muted)' }}><span style={{ color: C.ordered, fontWeight: 700 }}>Ordered</span> {fmtMoney(data[hover].ordered)}</div>
              <div style={{ fontSize: 11, color: 'var(--text-muted)' }}><span style={{ color: C.paid, fontWeight: 700 }}>Paid</span> {fmtMoney(data[hover].paid)}</div>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}

// ── Invoice status donut (SVG) ───────────────────────────────────────────────
function StatusDonut({ data }) {
  const total = data.reduce((s, d) => s + d.count, 0)
  const R = 54, SW = 20, C0 = 2 * Math.PI * R
  let acc = 0
  const segs = data.map(d => {
    const frac = total > 0 ? d.count / total : 0
    const seg = { ...d, frac, offset: acc, cfg: pinvStatusCfg(d.status) }
    acc += frac
    return seg
  })
  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <h2 style={{ margin: '0 0 2px', fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Invoices by Status</h2>
      <p style={{ margin: '0 0 12px', fontSize: 11.5, color: 'var(--text-muted)' }}>{total} invoice{total === 1 ? '' : 's'} total</p>
      {total === 0 ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 13, padding: '20px 0' }}>No invoices yet.</p>
      ) : (
        <div style={{ display: 'flex', alignItems: 'center', gap: 18, flexWrap: 'wrap' }}>
          <svg viewBox="0 0 140 140" width="140" height="140" style={{ flexShrink: 0 }}>
            <defs><filter id="donutShadow" x="-20%" y="-20%" width="140%" height="140%"><feDropShadow dx="0" dy="2" stdDeviation="2" floodOpacity="0.3" /></filter></defs>
            <g transform="rotate(-90 70 70)">
              <circle cx="70" cy="70" r={R} fill="none" stroke="var(--bg-input)" strokeWidth={SW} />
              {segs.map((s, i) => (
                <circle key={i} cx="70" cy="70" r={R} fill="none" stroke={s.cfg.color} strokeWidth={SW}
                  strokeDasharray={`${Math.max(0, s.frac * C0 - 3)} ${C0 - Math.max(0, s.frac * C0 - 3)}`}
                  strokeDashoffset={-s.offset * C0} strokeLinecap="round" filter="url(#donutShadow)" />
              ))}
            </g>
            <text x="70" y="66" textAnchor="middle" fontSize="26" fontWeight="900" fill="var(--text-h)">{total}</text>
            <text x="70" y="84" textAnchor="middle" fontSize="10" fill="var(--text-muted)" style={{ textTransform: 'uppercase', letterSpacing: '0.08em' }}>invoices</text>
          </svg>
          <div style={{ flex: 1, minWidth: 140, display: 'flex', flexDirection: 'column', gap: 7 }}>
            {segs.map(s => (
              <div key={s.status} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <span style={{ width: 10, height: 10, borderRadius: 3, background: s.cfg.color, flexShrink: 0, boxShadow: `0 2px 5px -1px ${s.cfg.color}` }} />
                <span style={{ fontSize: 12, color: 'var(--text-h)', fontWeight: 600, flex: 1 }}>{s.label}</span>
                <span style={{ fontSize: 12, color: 'var(--text-muted)', fontWeight: 700, fontVariantNumeric: 'tabular-nums' }}>{s.count}</span>
                <span style={{ fontSize: 11, color: 'var(--text-muted)', minWidth: 52, textAlign: 'right' }}>{fmtMoneyShort(s.value)}</span>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

// ── Top vendors (horizontal bars, single hue = magnitude) ────────────────────
function TopVendors({ data }) {
  const max = Math.max(1, ...data.map(d => d.spend))
  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <h2 style={{ margin: '0 0 2px', fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Top Vendors by Spend</h2>
      <p style={{ margin: '0 0 14px', fontSize: 11.5, color: 'var(--text-muted)' }}>By total invoiced</p>
      {data.length === 0 ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>No vendor spend yet.</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          {data.map((v, i) => {
            const pct = Math.round((v.spend / max) * 100)
            return (
              <div key={v.name + i}>
                <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 5 }}>
                  <span style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>{v.name} <span style={{ fontWeight: 500, color: 'var(--text-muted)' }}>· {v.invoices} inv</span></span>
                  <span style={{ fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>{fmtMoney(v.spend)}</span>
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

// ── Recent invoices ──────────────────────────────────────────────────────────
function RecentInvoices({ rows, onGo }) {
  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 }}>
        <h2 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Recent Invoices</h2>
        <button onClick={() => onGo('/app/purchase/invoices')} style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 12, fontWeight: 700, color: '#a78bfa', background: 'none', border: 'none', cursor: 'pointer' }}>View all <ArrowRight size={12} /></button>
      </div>
      {rows.length === 0 ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>No invoices yet.</p>
      ) : (
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Invoice', 'Vendor', 'Total', 'Balance', 'Status'].map((h, i) => (
            <th key={h} style={{ textAlign: i > 1 && i < 4 ? 'right' : 'left', padding: '6px 8px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', borderBottom: '1px solid var(--border)' }}>{h}</th>
          ))}</tr></thead>
          <tbody>
            {rows.map(r => (
              <tr key={r.id} onClick={() => onGo('/app/purchase/invoices')} style={{ cursor: 'pointer' }}
                onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'} onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                <td style={{ padding: '9px 8px', fontSize: 12, fontWeight: 700, color: '#a78bfa', borderBottom: '1px solid var(--border)', whiteSpace: 'nowrap' }}>{r.invoice_number}</td>
                <td style={{ padding: '9px 8px', fontSize: 12, color: 'var(--text-h)', borderBottom: '1px solid var(--border)' }}>{r.vendor || '—'}</td>
                <td style={{ padding: '9px 8px', fontSize: 12, fontWeight: 700, color: 'var(--text-h)', textAlign: 'right', borderBottom: '1px solid var(--border)', fontVariantNumeric: 'tabular-nums' }}>{fmtMoney(r.total)}</td>
                <td style={{ padding: '9px 8px', fontSize: 12, fontWeight: 700, color: Number(r.balance) > 0 ? '#f59e0b' : 'var(--text-muted)', textAlign: 'right', borderBottom: '1px solid var(--border)', fontVariantNumeric: 'tabular-nums' }}>{fmtMoney(r.balance)}</td>
                <td style={{ padding: '9px 8px', borderBottom: '1px solid var(--border)' }}>
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                    <StatusPill cfg={pinvStatusCfg(r.status)} />
                    {r.is_overdue && <AlertTriangle size={12} style={{ color: '#ef4444' }} title="Overdue" />}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}
