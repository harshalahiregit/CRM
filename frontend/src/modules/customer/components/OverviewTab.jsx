import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  AlertTriangle, AlertCircle, Info, FolderKanban, CheckSquare,
  LifeBuoy, FileSignature, Wallet, Truck, ArrowUpRight, UserCircle2,
  Receipt, CreditCard, StickyNote, Paperclip, Activity,
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
