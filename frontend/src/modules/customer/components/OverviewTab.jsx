import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  AlertTriangle, AlertCircle, Info, FolderKanban, CheckSquare,
  LifeBuoy, FileSignature, Wallet, Truck, ArrowUpRight, UserCircle2,
  Receipt, CreditCard, StickyNote, Paperclip, Activity, HeartPulse, ShieldAlert,
} from 'lucide-react'
import { customerApi } from '@/services/customerApi'
import { useMoneyFmt } from '@/components/ui/Money'

/**
 * Customer 360 — the landing view for a customer.
 *
 * Deliberately shows counts, not lists. Every tile is a number owned by another
 * module beside a link into it, so this page never becomes a second place to
 * work on invoices, tickets or projects. Nothing here is stored; it is read live
 * each time the page opens.
 */

const KPI_ICON = {
  projects: FolderKanban,
  tasks: CheckSquare,
  tickets: LifeBuoy,
  contracts: FileSignature,
  outstanding: Wallet,
  shipments: Truck,
}

const EVENT_ICON = {
  invoice: Receipt,
  payment: CreditCard,
  shipment: Truck,
  contract: FileSignature,
  note: StickyNote,
  file: Paperclip,
  ticket: LifeBuoy,
}

/** "3 days ago" reads better than a date on a feed this short. */
function ago(iso) {
  const s = Math.floor((Date.now() - new Date(iso).getTime()) / 1000)
  if (s < 60) return 'just now'
  const m = Math.floor(s / 60); if (m < 60) return `${m}m ago`
  const h = Math.floor(m / 60); if (h < 24) return `${h}h ago`
  const d = Math.floor(h / 24); if (d < 30) return `${d}d ago`
  return new Date(iso).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })
}

/** §8's bands, plus the provisional state for a score built on too little. */
const HEALTH_TONE = {
  good:     { color: '#10b981', bg: 'rgba(16,185,129,0.10)',  border: 'rgba(16,185,129,0.35)' },
  warning:  { color: '#eab308', bg: 'rgba(234,179,8,0.10)',   border: 'rgba(234,179,8,0.35)' },
  serious:  { color: '#f97316', bg: 'rgba(249,115,22,0.10)',  border: 'rgba(249,115,22,0.35)' },
  critical: { color: '#ef4444', bg: 'rgba(239,68,68,0.10)',   border: 'rgba(239,68,68,0.35)' },
  unknown:  { color: '#9ca3af', bg: 'rgba(156,163,175,0.08)', border: 'rgba(156,163,175,0.28)' },
}

const RISK_COLOR = { High: '#ef4444', Medium: '#f59e0b', Low: '#10b981', Unknown: '#6b7280' }

const SEVERITY = {
  critical: { icon: AlertCircle, color: '#ef4444', bg: 'rgba(239,68,68,0.10)', border: 'rgba(239,68,68,0.35)' },
  warning: { icon: AlertTriangle, color: '#f59e0b', bg: 'rgba(245,158,11,0.10)', border: 'rgba(245,158,11,0.32)' },
  info: { icon: Info, color: '#3b82f6', bg: 'rgba(59,130,246,0.10)', border: 'rgba(59,130,246,0.30)' },
}

export default function OverviewTab({ id, client, toast }) {
  const navigate = useNavigate()
  const money = useMoneyFmt()
  const [data, setData] = useState(null)
  const [err, setErr] = useState('')

  useEffect(() => {
    let alive = true
    setData(null); setErr('')
    customerApi.overview(id)
      .then(d => { if (alive) setData(d) })
      .catch(e => { if (alive) { setErr(e.message || 'Could not load the overview.'); toast?.error?.(e.message) } })
    return () => { alive = false }
  }, [id])

  if (err) {
    return (
      <div className="card-3d" style={{ padding: 22 }}>
        <p className="text-sm" style={{ color: '#ef4444', margin: 0 }}>{err}</p>
      </div>
    )
  }

  const kpis = data?.kpis ?? []
  const alerts = data?.alerts ?? []
  const recent = data?.recent ?? []
  const owner = data?.owner
  const health = data?.health
  const risk = data?.risk

  return (
    <div className="space-y-4">

      {/* Ownership — who is accountable for this account */}
      <div className="card-3d" style={{ padding: '16px 20px', display: 'flex', alignItems: 'center', gap: 14, flexWrap: 'wrap' }}>
        <div className="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
          style={{ background: 'rgba(124,58,237,0.12)' }}>
          <UserCircle2 size={20} style={{ color: '#a78bfa' }} />
        </div>
        <div style={{ minWidth: 0 }}>
          <p className="label-caps" style={{ margin: 0 }}>Account Owner</p>
          <p className="text-sm font-bold" style={{ color: 'var(--text-h)', margin: '2px 0 0' }}>
            {owner?.account_owner || 'Not assigned'}
          </p>
          {owner?.also_assigned?.length > 0 && (
            <p className="text-[11px]" style={{ color: 'var(--text-muted)', margin: '2px 0 0' }}>
              also assigned: {owner.also_assigned.join(', ')}
            </p>
          )}
        </div>
        <div style={{ marginLeft: 'auto', textAlign: 'right' }}>
          <p className="label-caps" style={{ margin: 0 }}>Customer</p>
          <p className="text-sm font-bold" style={{ color: 'var(--text-h)', margin: '2px 0 0' }}>
            {client?.company || '—'}
          </p>
        </div>
      </div>

      {/* KPIs — a count and a way through to the module that owns it */}
      {/* minmax(230px) so six tiles land as two rows of three rather than a
          row of five and one orphan; it still collapses to 2 then 1 when narrow. */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(230px, 1fr))', gap: 12 }}>
        {(data ? kpis : Array.from({ length: 6 })).map((k, i) => {
          if (!data) {
            return <div key={i} className="card-3d" style={{ padding: 18, height: 96, opacity: 0.5 }} />
          }
          const Icon = KPI_ICON[k.key] ?? FolderKanban
          const clickable = Boolean(k.link)
          return (
            <button
              key={k.key}
              type="button"
              onClick={() => k.link && navigate(k.link)}
              disabled={!clickable}
              className="card-3d"
              style={{
                padding: 18, textAlign: 'left', border: '1px solid var(--border)',
                cursor: clickable ? 'pointer' : 'default', width: '100%',
                display: 'flex', flexDirection: 'column', gap: 10,
              }}
              title={clickable ? `Open in ${k.label}` : undefined}
            >
              <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <Icon size={15} style={{ color: '#a78bfa' }} />
                <span className="label-caps" style={{ margin: 0 }}>{k.label}</span>
                {clickable && <ArrowUpRight size={13} style={{ color: 'var(--text-faint)', marginLeft: 'auto' }} />}
              </div>
              <span
                className="font-black"
                style={{ fontSize: 26, lineHeight: 1, color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}
              >
                {k.money ? money(k.value) : k.value}
              </span>
            </button>
          )
        })}
      </div>

      {/* Health and Risk (§8/§9).
          The score is the weighted average of the parameters that had data, so
          each measurable one is worth an equal share of 100. What matters as
          much as the number is how much of the picture it saw: a 100 from one
          signal is not the claim a 100 from nine is, so a thin score says
          "Provisional" and shows its coverage rather than a reassuring band. */}
      {health && (
        <div className="card-3d" style={{ padding: 20 }}>
          <div style={{ display: 'flex', alignItems: 'flex-start', gap: 16, flexWrap: 'wrap' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 14, minWidth: 210 }}>
              {(() => {
                const t = HEALTH_TONE[health.tone] ?? HEALTH_TONE.unknown
                return (
                  <>
                    <div style={{ width: 58, height: 58, borderRadius: 16, display: 'grid', placeItems: 'center',
                      background: t.bg, border: `1px solid ${t.border}` }}>
                      <span style={{ fontSize: 20, fontWeight: 900, color: t.color, fontVariantNumeric: 'tabular-nums' }}>
                        {health.score ?? '—'}
                      </span>
                    </div>
                    <div>
                      <p className="label-caps" style={{ margin: 0 }}>Customer Health</p>
                      <p className="text-sm font-bold" style={{ color: t.color, margin: '2px 0 0' }}>
                        {health.status}
                        {health.provisional && health.band && (
                          <span style={{ color: 'var(--text-muted)', fontWeight: 600 }}> · trending {health.band}</span>
                        )}
                      </p>
                      <p className="text-[11px]" style={{ color: 'var(--text-muted)', margin: '2px 0 0' }}>
                        Based on {health.measured} of {health.of} signals
                      </p>
                    </div>
                  </>
                )
              })()}
            </div>

            {risk && (
              <div style={{ display: 'flex', alignItems: 'center', gap: 14, minWidth: 190 }}>
                <div style={{ width: 40, height: 40, borderRadius: 12, display: 'grid', placeItems: 'center', background: 'rgba(124,58,237,0.10)' }}>
                  <ShieldAlert size={18} style={{ color: RISK_COLOR[risk.overall] ?? '#9ca3af' }} />
                </div>
                <div>
                  <p className="label-caps" style={{ margin: 0 }}>Risk</p>
                  <p className="text-sm font-bold" style={{ color: RISK_COLOR[risk.overall] ?? '#9ca3af', margin: '2px 0 0' }}>{risk.overall}</p>
                  <p className="text-[11px]" style={{ color: 'var(--text-muted)', margin: '2px 0 0' }}>
                    {Object.entries({ ...risk.derived, ...risk.manual })
                      .filter(([, v]) => v).map(([k, v]) => `${k} ${v}`).join(' · ') || 'Nothing assessed yet'}
                  </p>
                </div>
              </div>
            )}
          </div>

          {/* Each signal's own score and the share of 100 it carries. Unmeasured
              ones are listed too — knowing what could NOT be seen is the point. */}
          <div style={{ marginTop: 16, display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(215px,1fr))', gap: 8 }}>
            {(health.breakdown ?? []).map(b => (
              <div key={b.key} style={{ padding: '9px 12px', borderRadius: 10,
                background: b.available ? 'var(--bg-input)' : 'transparent',
                border: `1px solid ${b.available ? 'var(--border)' : 'transparent'}`,
                opacity: b.available ? 1 : 0.45 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                  <HeartPulse size={11} style={{ color: b.available ? '#a78bfa' : 'var(--text-faint)' }} />
                  <span className="text-[11px] font-bold" style={{ color: 'var(--text-h)' }}>{b.label}</span>
                  {b.available && (
                    <span className="text-[11px] font-black" style={{ marginLeft: 'auto', color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>
                      {b.score}
                    </span>
                  )}
                </div>
                <p className="text-[10.5px]" style={{ color: 'var(--text-muted)', margin: '3px 0 0' }}>
                  {b.detail}{b.available && b.worth != null ? ` · worth ${b.worth}` : ''}
                </p>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Alerts — only things needing action. Nothing to show is a good outcome. */}
      <div className="card-3d" style={{ padding: 20 }}>
        <p className="label-caps" style={{ marginBottom: 12 }}>Alerts</p>
        {!data ? (
          <p className="text-xs" style={{ color: 'var(--text-muted)', margin: 0 }}>Loading…</p>
        ) : alerts.length === 0 ? (
          <p className="text-xs" style={{ color: 'var(--text-muted)', margin: 0 }}>
            Nothing needs attention on this account.
          </p>
        ) : (
          <div className="space-y-2">
            {alerts.map(a => {
              const cfg = SEVERITY[a.severity] ?? SEVERITY.info
              const Icon = cfg.icon
              return (
                <div
                  key={a.key}
                  style={{
                    display: 'flex', alignItems: 'center', gap: 10, padding: '10px 14px',
                    borderRadius: 12, background: cfg.bg, border: `1px solid ${cfg.border}`,
                  }}
                >
                  <Icon size={15} style={{ color: cfg.color, flexShrink: 0 }} />
                  <span className="text-xs font-semibold" style={{ color: 'var(--text-h)' }}>{a.message}</span>
                </div>
              )
            })}
          </div>
        )}
      </div>

      {/* Recent activity — the doc's third element of the Overview. A summary
          strip, not the full Timeline: newest ten, read live from each source. */}
      <div className="card-3d" style={{ padding: 20 }}>
        <p className="label-caps" style={{ marginBottom: 12 }}>Recent Activity</p>
        {!data ? (
          <p className="text-xs" style={{ color: 'var(--text-muted)', margin: 0 }}>Loading…</p>
        ) : recent.length === 0 ? (
          <p className="text-xs" style={{ color: 'var(--text-muted)', margin: 0 }}>
            Nothing has happened on this account yet.
          </p>
        ) : (
          <div className="space-y-1">
            {recent.map((e, i) => {
              const Icon = EVENT_ICON[e.type] ?? Activity
              return (
                <div key={`${e.type}-${e.at}-${i}`}
                  style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '8px 4px' }}>
                  <div className="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0"
                    style={{ background: 'rgba(124,58,237,0.10)' }}>
                    <Icon size={13} style={{ color: '#a78bfa' }} />
                  </div>
                  <span className="text-xs flex-1 min-w-0 truncate" style={{ color: 'var(--text-h)' }}>{e.label}</span>
                  <span className="text-[11px] flex-shrink-0" style={{ color: 'var(--text-muted)' }}>{ago(e.at)}</span>
                </div>
              )
            })}
          </div>
        )}
      </div>

    </div>
  )
}
