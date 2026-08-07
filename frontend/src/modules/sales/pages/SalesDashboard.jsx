import { useState, useEffect, useMemo, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  IndianRupee, Receipt, AlertTriangle, TrendingUp, TrendingDown,
  Percent, ArrowRight, FileSignature, CreditCard, UserPlus, Flame, Wallet,
} from 'lucide-react'
import { salesApi } from '@/services/salesApi'
import { leadApi } from '@/services/leadApi'
import { useMoneyFmt, MoneyToggle } from '@/components/ui/Money'
import GoalSummary from '../components/GoalSummary'
import RaiseTicketButton from '@/components/RaiseTicketButton'

/**
 * Sales overview.
 *
 * The previous version was twelve identically-weighted KPI tiles in three rows
 * (including two different things both labelled "Conversion Rate") and a month
 * series drawn as horizontal bars. Nothing said what to look at first, and a
 * trend read top-to-bottom instead of left-to-right.
 *
 * Now: one hero figure carries the headline, a short KPI row carries the
 * exceptions worth acting on, and the twelve-month trend is an area chart with a
 * hover crosshair. Everything below is supporting detail.
 *
 * Money runs through useMoneyFmt so the global hide-amounts toggle applies here
 * as it does everywhere else — the old page formatted with a local helper and
 * leaked figures while the rest of the app was masked.
 */

const compact = (n) => {
  const v = Math.abs(Number(n) || 0)
  if (v >= 1e7) return `${(n / 1e7).toFixed(1)}Cr`
  if (v >= 1e5) return `${(n / 1e5).toFixed(1)}L`
  if (v >= 1e3) return `${Math.round(n / 1e3)}k`
  return String(Math.round(n || 0))
}

const STATUS = {
  Paid:             { bg: 'rgba(16,185,129,0.12)',  color: 'var(--color-success-500)' },
  Overdue:          { bg: 'rgba(239,68,68,0.12)',   color: 'var(--color-danger-500)' },
  'Partially Paid': { bg: 'rgba(245,158,11,0.12)',  color: 'var(--color-warning-500)' },
  Unpaid:           { bg: 'rgba(124,58,237,0.12)',  color: 'var(--accent)' },
  Draft:            { bg: 'rgba(100,116,139,0.12)', color: 'var(--text-muted)' },
}
const statusStyle = (s) => STATUS[s] ?? STATUS.Draft

export default function SalesDashboard() {
  const navigate = useNavigate()
  const money = useMoneyFmt()
  const [data, setData] = useState(null)
  const [leads, setLeads] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    salesApi.dashboard.get().then(d => { setData(d); setLoading(false) }).catch(() => setLoading(false))
    leadApi.summary().then(setLeads).catch(() => {})
  }, [])

  if (loading) {
    return (
      <div className="space-y-6 animate-fade-in">
        <div className="skeleton h-12 w-64 rounded-xl" style={{ background: 'var(--border)' }} />
        <div className="skeleton h-40 rounded-2xl" style={{ background: 'var(--border)' }} />
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
          {[1, 2, 3, 4].map(i => <div key={i} className="skeleton h-28 rounded-2xl" style={{ background: 'var(--border)' }} />)}
        </div>
      </div>
    )
  }
  if (!data) {
    return <p className="text-sm py-16 text-center" style={{ color: 'var(--text-muted)' }}>Couldn’t load the dashboard. Refresh to try again.</p>
  }

  const k = data.kpis
  const trend = data.revenue_by_month ?? []

  return (
    <div className="space-y-6 animate-fade-in">
      {/* ── Header ─────────────────────────────────────────────── */}
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <p className="label-caps mb-1">Sales &amp; Revenue</p>
          <h1 className="font-black" style={{ fontSize: 'clamp(1.4rem,2.5vw,1.9rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
            Sales <span className="text-gradient">Overview</span>
          </h1>
          <p className="text-sm mt-0.5" style={{ color: 'var(--text-muted)' }}>
            {data.period?.month ? `${data.period.month} · ` : ''}revenue, exceptions and pipeline
          </p>
        </div>
        <div className="flex items-center gap-2">
          <MoneyToggle />
          <RaiseTicketButton source="sales" />
        </div>
      </div>

      {/* ── Hero: the one number the page leads with ───────────── */}
      <div className="card-3d" style={{ padding: 'clamp(20px,3vw,28px)' }}>
        <div className="grid gap-6 lg:grid-cols-[minmax(220px,1fr)_2fr] items-center">
          <div>
            <p className="label-caps" style={{ color: 'var(--text-muted)' }}>Revenue received · this month</p>
            <p className="font-black leading-none mt-2"
              style={{ fontSize: 'clamp(2.2rem,5vw,3rem)', color: 'var(--text-h)', letterSpacing: '-0.035em' }}>
              {money(k.total_revenue)}
            </p>
            <DeltaChip pct={k.revenue_delta_pct} prev={k.revenue_prev_month} money={money} />
            <TargetMeter value={k.total_revenue} target={k.monthly_target} money={money} />
          </div>

          {/* 12-month trend — a time series, so it reads left→right */}
          <TrendArea points={trend} money={money} />
        </div>
      </div>

      {/* ── Exceptions worth acting on ─────────────────────────── */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <Stat label="Outstanding" value={money(k.outstanding)} icon={Wallet}
          tone="warning" sub={`${k.open_invoices} unpaid invoice${k.open_invoices === 1 ? '' : 's'}`}
          onClick={() => navigate('/app/sales/invoices')} />
        <Stat label="Overdue" value={money(k.overdue_value)} icon={AlertTriangle}
          tone={k.overdue_payments > 0 ? 'danger' : 'muted'}
          sub={`${k.overdue_payments} invoice${k.overdue_payments === 1 ? '' : 's'} past due`}
          onClick={() => navigate('/app/sales/invoices')} />
        <Stat label="Pending Proposals" value={k.pending_proposals} icon={FileSignature}
          tone="accent" sub="awaiting a client decision"
          onClick={() => navigate('/app/sales/proposals')} />
        <Stat label="Proposal → Paid" value={`${k.conversion_rate}%`} icon={Percent}
          tone="success" sub="proposals that became paid invoices" />
      </div>

      {/* ── Pipeline + leads ───────────────────────────────────── */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <div className="card-3d lg:col-span-2" style={{ padding: '22px' }}>
          <h2 className="font-bold text-base mb-1" style={{ color: 'var(--text-h)' }}>Pipeline</h2>
          <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>Value at each stage — widest is where the money sits</p>
          <PipelineBars stages={data.pipeline ?? []} money={money} />
        </div>

        <div className="space-y-4">
          {leads && (
            <div className="card-3d" style={{ padding: '20px' }}>
              <h2 className="font-bold text-sm mb-3" style={{ color: 'var(--text-h)' }}>Leads</h2>
              <div className="grid grid-cols-2 gap-3">
                <MiniStat label="Total" value={leads.total} icon={UserPlus} onClick={() => navigate('/app/sales/leads')} />
                <MiniStat label="Hot" value={leads.hot} icon={Flame} tone="danger" onClick={() => navigate('/app/sales/leads')} />
              </div>
              <div className="mt-3 pt-3" style={{ borderTop: '1px solid var(--border)' }}>
                <Row label="Pipeline value" value={money(leads.pipeline_value)} />
                <Row label="Lead → won" value={`${leads.conversion_rate}%`} />
              </div>
            </div>
          )}

          <GoalSummary limit={3} compact />
        </div>
      </div>

      {/* ── Recent invoices + top clients ──────────────────────── */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <div className="card-3d lg:col-span-2" style={{ padding: '22px' }}>
          <div className="flex items-center justify-between mb-4">
            <h2 className="font-bold text-base" style={{ color: 'var(--text-h)' }}>Recent invoices</h2>
            <button onClick={() => navigate('/app/sales/invoices')} className="text-xs font-semibold flex items-center gap-1" style={{ color: 'var(--accent)' }}>
              View all <ArrowRight size={11} />
            </button>
          </div>
          {!data.recent_invoices?.length ? (
            <Empty text="No invoices yet." />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead><tr style={{ borderBottom: '1px solid var(--border)' }}>
                  {['Invoice', 'Client', 'Amount', 'Status'].map((h, i) => (
                    <th key={h} className={`pb-2 pr-3 label-caps ${i === 2 ? 'text-right' : 'text-left'}`}>{h}</th>
                  ))}
                </tr></thead>
                <tbody>
                  {data.recent_invoices.map(inv => {
                    const s = statusStyle(inv.status)
                    return (
                      <tr key={inv.id} className="cursor-pointer" onClick={() => navigate(`/app/sales/invoices/${inv.id}`)}
                        style={{ borderBottom: '1px solid var(--border)' }}
                        onMouseEnter={e => (e.currentTarget.style.background = 'rgba(124,58,237,0.04)')}
                        onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                        <td className="py-2.5 pr-3 font-bold" style={{ color: 'var(--accent)' }}>{inv.number}</td>
                        <td className="py-2.5 pr-3" style={{ color: 'var(--text-h)' }}>{inv.client}</td>
                        <td className="py-2.5 pr-3 font-bold text-right" style={{ color: 'var(--text-h)' }}>{money(inv.amount)}</td>
                        <td className="py-2.5">
                          <span className="px-2 py-0.5 rounded-lg text-[10px] font-bold" style={{ background: s.bg, color: s.color }}>{inv.status}</span>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>

        <div className="card-3d" style={{ padding: '20px' }}>
          <h2 className="font-bold text-sm mb-1" style={{ color: 'var(--text-h)' }}>Top clients</h2>
          <p className="text-[11px] mb-3" style={{ color: 'var(--text-muted)' }}>By payments received</p>
          {!data.top_clients?.length ? (
            <Empty text="No payments recorded yet." />
          ) : (
            <div className="space-y-2.5">
              {data.top_clients.map(c => (
                <div key={c.name} className="flex items-center gap-2.5 p-2 rounded-xl" style={{ background: 'var(--bg-input)' }}>
                  <div className="w-8 h-8 rounded-xl flex items-center justify-center text-[10px] font-black text-white flex-shrink-0"
                    style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
                    {c.name.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase()}
                  </div>
                  <p className="text-xs font-bold truncate flex-1 min-w-0" style={{ color: 'var(--text-h)' }}>{c.name}</p>
                  <span className="text-xs font-black flex-shrink-0" style={{ color: 'var(--accent)' }}>{money(c.revenue)}</span>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

/* ── Pieces ───────────────────────────────────────────────────── */

const TONES = {
  accent:  'var(--accent)',
  success: 'var(--color-success-500)',
  warning: 'var(--color-warning-500)',
  danger:  'var(--color-danger-500)',
  muted:   'var(--text-muted)',
}

/**
 * Stat tile. The colour lives on the icon only — the value and label stay in
 * text ink, so the figure is legible regardless of the accent behind it, and a
 * tile is never identified by colour alone.
 */
function Stat({ label, value, icon: Icon, tone = 'accent', sub, onClick }) {
  const c = TONES[tone] ?? TONES.accent
  return (
    <div className={`kpi-3d ${onClick ? 'cursor-pointer transition-transform hover:-translate-y-0.5' : ''}`} onClick={onClick}>
      <div className="flex items-center gap-2 mb-2">
        <span className="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0" style={{ background: `color-mix(in srgb, ${c} 15%, transparent)` }}>
          <Icon size={14} style={{ color: c }} />
        </span>
        <p className="text-[11px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>{label}</p>
      </div>
      <p className="text-xl font-black" style={{ color: 'var(--text-h)', letterSpacing: '-0.02em' }}>{value}</p>
      {sub && <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{sub}</p>}
    </div>
  )
}

function MiniStat({ label, value, icon: Icon, tone = 'accent', onClick }) {
  const c = TONES[tone] ?? TONES.accent
  return (
    <button onClick={onClick} className="p-2.5 rounded-xl text-left w-full" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
      <span className="flex items-center gap-1.5 text-[10px] font-bold uppercase" style={{ color: 'var(--text-muted)' }}>
        <Icon size={11} style={{ color: c }} /> {label}
      </span>
      <p className="text-lg font-black mt-0.5" style={{ color: 'var(--text-h)' }}>{value}</p>
    </button>
  )
}

const Row = ({ label, value }) => (
  <div className="flex justify-between text-[11px] py-1">
    <span style={{ color: 'var(--text-muted)' }}>{label}</span>
    <span className="font-bold" style={{ color: 'var(--text-h)' }}>{value}</span>
  </div>
)

const Meter = ({ pct }) => (
  <div className="h-1.5 rounded-full overflow-hidden" style={{ background: 'var(--border)' }}>
    <div className="h-full rounded-full" style={{ width: `${pct}%`, background: 'linear-gradient(90deg,#a78bfa,#7C3AED)' }} />
  </div>
)

const Empty = ({ text }) => (
  <p className="text-xs text-center py-8" style={{ color: 'var(--text-muted)' }}>{text}</p>
)

/** Month-on-month change. Renders nothing when there's no baseline to compare to. */
function DeltaChip({ pct, prev, money }) {
  if (pct === null || pct === undefined) {
    return <p className="text-xs mt-2" style={{ color: 'var(--text-muted)' }}>No revenue last month to compare against</p>
  }
  const up = pct >= 0
  const c = up ? 'var(--color-success-500)' : 'var(--color-danger-500)'
  const Icon = up ? TrendingUp : TrendingDown
  return (
    <div className="flex items-center gap-2 mt-2 flex-wrap">
      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-xs font-bold"
        style={{ background: `color-mix(in srgb, ${c} 14%, transparent)`, color: c }}>
        <Icon size={12} /> {up ? '+' : ''}{pct}%
      </span>
      <span className="text-xs" style={{ color: 'var(--text-muted)' }}>vs {money(prev)} last month</span>
    </div>
  )
}

/**
 * Progress toward the monthly target. Hidden entirely when no target is
 * configured — the previous page hardcoded ₹10,00,000 and reported a percentage
 * against a number nobody had set.
 */
function TargetMeter({ value, target, money }) {
  if (!target) return null
  const pct = Math.min(100, Math.round((value / target) * 100))
  return (
    <div className="mt-4">
      <div className="flex justify-between text-[11px] mb-1">
        <span style={{ color: 'var(--text-muted)' }}>Monthly target · {money(target)}</span>
        <span className="font-bold" style={{ color: 'var(--text-h)' }}>{pct}%</span>
      </div>
      <Meter pct={pct} />
    </div>
  )
}

/**
 * Twelve-month revenue as an area chart with a hover crosshair.
 *
 * One series, so it takes the accent hue and needs no legend — the heading names
 * it. Only the peak is direct-labelled; the rest are read off the tooltip, which
 * keeps the plot free of a number on every point.
 */
function TrendArea({ points, money }) {
  const [hover, setHover] = useState(null)
  const wrapRef = useRef(null)

  const { path, area, max, peakIdx } = useMemo(() => {
    const vals = points.map(p => p.amount || 0)
    const max = Math.max(1, ...vals)
    const n = Math.max(points.length - 1, 1)
    const xy = points.map((p, i) => [ (i / n) * 100, 34 - ((p.amount || 0) / max) * 30 ])
    const path = xy.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(2)},${y.toFixed(2)}`).join(' ')
    const area = `${path} L100,34 L0,34 Z`
    return { path, area, max, peakIdx: vals.indexOf(Math.max(...vals)) }
  }, [points])

  if (!points.length) return <Empty text="No revenue recorded yet." />

  const onMove = (e) => {
    const r = wrapRef.current?.getBoundingClientRect()
    if (!r) return
    const ratio = Math.min(1, Math.max(0, (e.clientX - r.left) / r.width))
    setHover(Math.round(ratio * (points.length - 1)))
  }
  const hp = hover !== null ? points[hover] : null

  return (
    <div>
      <div className="flex items-baseline justify-between mb-1">
        <p className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>Revenue received · last 12 months</p>
        <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>peak {money(points[peakIdx]?.amount)}</p>
      </div>

      <div ref={wrapRef} className="relative" onMouseMove={onMove} onMouseLeave={() => setHover(null)}>
        <svg viewBox="0 0 100 38" preserveAspectRatio="none" className="w-full" style={{ height: 132, display: 'block', overflow: 'visible' }}>
          <defs>
            <linearGradient id="revfill" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor="var(--accent)" stopOpacity="0.30" />
              <stop offset="100%" stopColor="var(--accent)" stopOpacity="0.02" />
            </linearGradient>
          </defs>
          {/* recessive baseline */}
          <line x1="0" y1="34" x2="100" y2="34" stroke="var(--border)" strokeWidth="0.4" vectorEffect="non-scaling-stroke" />
          <path d={area} fill="url(#revfill)" />
          <path d={path} fill="none" stroke="var(--accent)" strokeWidth="2" vectorEffect="non-scaling-stroke"
            strokeLinejoin="round" strokeLinecap="round" />
          {hp && (
            <line x1={(hover / Math.max(points.length - 1, 1)) * 100} y1="0"
              x2={(hover / Math.max(points.length - 1, 1)) * 100} y2="34"
              stroke="var(--accent)" strokeWidth="1" strokeDasharray="2 2" vectorEffect="non-scaling-stroke" opacity="0.6" />
          )}
        </svg>

        {/* marker sits outside the squeezed viewBox so it stays a circle */}
        {hp && (
          <span className="absolute rounded-full pointer-events-none"
            style={{
              left: `${(hover / Math.max(points.length - 1, 1)) * 100}%`,
              top: `${((34 - ((hp.amount || 0) / max) * 30) / 38) * 132}px`,
              width: 9, height: 9, transform: 'translate(-50%,-50%)',
              background: 'var(--accent)', boxShadow: '0 0 0 2px var(--bg-card)',
            }} />
        )}

        {hp && (
          <div className="absolute pointer-events-none px-2.5 py-1.5 rounded-lg text-[11px] whitespace-nowrap"
            style={{
              left: `${(hover / Math.max(points.length - 1, 1)) * 100}%`,
              top: -6, transform: 'translate(-50%,-100%)',
              background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: '0 6px 18px rgba(0,0,0,0.18)',
            }}>
            <span style={{ color: 'var(--text-muted)' }}>{hp.label} · </span>
            <span className="font-black" style={{ color: 'var(--text-h)' }}>{money(hp.amount)}</span>
          </div>
        )}
      </div>

      {/* Sparse axis — every third month, so labels never collide */}
      <div className="flex justify-between mt-1">
        {points.map((p, i) => (
          <span key={p.key} className="text-[9px]" style={{ color: 'var(--text-muted)', opacity: i % 3 === 0 || i === points.length - 1 ? 1 : 0 }}>
            {p.month}
          </span>
        ))}
      </div>
    </div>
  )
}

/**
 * Pipeline stages. Ordered magnitude, so a single hue at descending opacity —
 * sequential, not four arbitrary colours competing for attention. Each bar is
 * directly labelled, so the encoding never rests on colour alone.
 */
function PipelineBars({ stages, money }) {
  if (!stages.length) return <Empty text="Nothing in the pipeline yet." />
  const max = Math.max(1, ...stages.map(s => s.value || 0))

  return (
    <div className="space-y-2.5">
      {stages.map((s, i) => {
        const pct = Math.max(1.5, ((s.value || 0) / max) * 100)
        return (
          <div key={s.stage}>
            <div className="flex items-baseline justify-between text-xs mb-1">
              <span className="font-semibold" style={{ color: 'var(--text-h)' }}>
                {s.stage} <span style={{ color: 'var(--text-muted)' }}>· {s.count}</span>
              </span>
              <span className="font-bold" style={{ color: 'var(--text-h)' }}>{money(s.value)}</span>
            </div>
            <div className="h-2.5 rounded-full overflow-hidden" style={{ background: 'var(--bg-input)' }}>
              <div className="h-full rounded-full"
                style={{ width: `${pct}%`, background: 'var(--accent)', opacity: 1 - i * 0.18 }}
                title={`${s.stage}: ${s.count} · ${compact(s.value)}`} />
            </div>
          </div>
        )
      })}
    </div>
  )
}
