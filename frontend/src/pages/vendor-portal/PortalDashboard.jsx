import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  ShoppingBag, FileText, Wallet, Rocket, CheckCircle2, Clock, ArrowRight,
  AlertTriangle, RefreshCw, TrendingUp,
} from 'lucide-react'
import { portalApi } from '@/services/portalApi'
import {
  obStatusCfg, poStatusCfg, invStatusCfg, fmtMoney, fmtDate,
} from './portalConstants'
import { KIT3D_STYLE } from '@/components/ui/kit3d'

/**
 * Vendor portal landing — everything the vendor owns, at a glance:
 * onboarding progress, live purchase orders, approved invoices, outstanding.
 */
export default function PortalDashboard() {
  const navigate = useNavigate()
  const [data, setData] = useState({ me: null, onboarding: null, orders: [], invoices: [] })
  const [loading, setLoad] = useState(true)

  const load = () => {
    setLoad(true)
    Promise.all([
      portalApi.me().catch(() => null),
      portalApi.onboarding().catch(() => ({ onboarding: null, progress: null })),
      portalApi.orders().catch(() => []),
      portalApi.invoices().catch(() => []),
    ]).then(([me, ob, orders, invoices]) => {
      setData({ me, onboarding: ob, orders: orders?.data ?? orders ?? [], invoices: invoices?.data ?? invoices ?? [] })
      setLoad(false)
    })
  }
  useEffect(() => { load() }, [])

  if (loading) {
    return (
      <div>
        <style>{KIT3D_STYLE}</style>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 14 }}>
          {[1, 2, 3].map(i => <div key={i} className="skeleton" style={{ height: 108, borderRadius: 16, background: 'var(--border)' }} />)}
        </div>
      </div>
    )
  }

  const summary = data.me?.summary || {}
  const orders = data.orders
  const invoices = data.invoices
  const onboarding = data.onboarding?.onboarding
  // stepStatus returns { current_step, documents, steps:[…] } — the step list
  // lives under .steps, not at the top level.
  const steps = data.onboarding?.progress?.steps || []

  return (
    <div>
      <style>{KIT3D_STYLE}</style>

      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>WELCOME BACK</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 24, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>{data.me?.vendor?.company_name || 'Your account'}</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Your onboarding, orders and invoices with us.</p>
        </div>
        <button onClick={load} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>
          <RefreshCw size={14} /> Refresh
        </button>
      </div>

      {/* KPI tiles */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 14, marginBottom: 16 }}>
        <Kpi label="Active Orders" value={summary.open_orders ?? 0} icon={ShoppingBag} grad="linear-gradient(145deg,#38bdf8,#0ea5e9)" glow="#0ea5e9" />
        <Kpi label="Unpaid Invoices" value={summary.unpaid_invoices ?? 0} icon={FileText} grad="linear-gradient(145deg,#fbbf24,#f59e0b)" glow="#f59e0b" />
        <Kpi label="Outstanding" value={fmtMoney(summary.outstanding_balance)} icon={Wallet} grad="linear-gradient(145deg,#a78bfa,#7C3AED)" glow="#7C3AED" />
      </div>

      {/* Onboarding progress — the centrepiece when present */}
      {onboarding && <OnboardingCard onboarding={onboarding} progress={steps} onGo={() => navigate('/vendor-portal/documents')} />}

      {/* Orders + invoices */}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, marginTop: onboarding ? 16 : 0, alignItems: 'start' }}>
        <ListCard title="Purchase Orders" icon={ShoppingBag} empty="No purchase orders yet."
          rows={orders} render={(o) => (
            <Row key={o.id} left={o.po_number} sub={o.title} right={fmtMoney(o.total, o.currency)}
              badge={poStatusCfg(o.status)} meta={o.expected_delivery_date ? `Due ${fmtDate(o.expected_delivery_date)}` : fmtDate(o.order_date)}
              onClick={() => navigate(`/vendor-portal/orders/${o.id}`)} />
          )} />
        <ListCard title="Invoices" icon={FileText} empty="No invoices yet."
          rows={invoices} render={(inv) => (
            <Row key={inv.id} left={inv.invoice_number} sub={inv.title} right={fmtMoney(inv.balance, inv.currency)} rightSub="balance"
              badge={invStatusCfg(inv.status)} meta={inv.due_date ? `Due ${fmtDate(inv.due_date)}` : fmtDate(inv.invoice_date)}
              onClick={() => navigate(`/vendor-portal/invoices/${inv.id}`)} />
          )} />
      </div>
    </div>
  )
}

function Kpi({ label, value, icon: Icon, grad, glow }) {
  return (
    <div className="pr-kpi" style={{ padding: 18 }}>
      <div style={{ position: 'absolute', top: -14, right: -14, width: 72, height: 72, borderRadius: '50%', opacity: 0.1, background: grad }} />
      <div style={{ width: 42, height: 42, borderRadius: 14, display: 'flex', alignItems: 'center', justifyContent: 'center', background: grad, boxShadow: `0 8px 20px -4px ${glow}88, inset 0 1px 0 rgba(255,255,255,.3)` }}>
        <Icon size={20} color="#fff" />
      </div>
      <div style={{ fontSize: 22, fontWeight: 900, color: 'var(--text-h)', marginTop: 12, letterSpacing: '-0.02em' }}>{value}</div>
      <div style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--text-muted)', marginTop: 1 }}>{label}</div>
    </div>
  )
}

function OnboardingCard({ onboarding, progress, onGo }) {
  const cfg = obStatusCfg(onboarding.status)
  const done = progress.filter(s => s.complete).length
  const total = progress.length || 1
  const pct = Math.round((done / total) * 100)

  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14, flexWrap: 'wrap' }}>
        <span style={{ width: 38, height: 38, borderRadius: 12, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.14)', flexShrink: 0 }}>
          <Rocket size={18} style={{ color: '#a78bfa' }} />
        </span>
        <div>
          <h2 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Onboarding Progress</h2>
          <p style={{ margin: '2px 0 0', fontSize: 11.5, color: 'var(--text-muted)' }}>{done} of {total} steps complete</p>
        </div>
        <span style={{ marginLeft: 'auto', padding: '4px 11px', borderRadius: 999, background: cfg.bg, color: cfg.color, fontSize: 11.5, fontWeight: 800 }}>{cfg.label}</span>
      </div>

      <div className="pr-bar" style={{ height: 10, marginBottom: 16 }}>
        <span style={{ width: `${pct}%` }} />
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))', gap: 10 }}>
        {progress.map(s => (
          <div key={s.key || s.step} style={{ display: 'flex', alignItems: 'center', gap: 9, padding: '10px 12px', borderRadius: 12,
            background: s.complete ? 'rgba(16,185,129,0.08)' : 'var(--bg-input)', border: `1px solid ${s.complete ? 'rgba(16,185,129,0.3)' : 'var(--border)'}` }}>
            {s.complete
              ? <CheckCircle2 size={16} style={{ color: '#10b981', flexShrink: 0 }} />
              : <Clock size={16} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />}
            <div style={{ minWidth: 0 }}>
              <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{s.label}</div>
              {s.detail && <div style={{ fontSize: 10.5, color: 'var(--text-muted)' }}>{s.detail}</div>}
            </div>
          </div>
        ))}
      </div>

      <button onClick={onGo} className="pr-node" style={{ marginTop: 16, width: '100%', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 7, padding: '11px', borderRadius: 12, cursor: 'pointer', fontSize: 13.5, fontWeight: 800, color: '#fff', border: 'none', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 10px 24px -6px rgba(124,58,237,.6)' }}>
        <FileText size={16} /> Manage compliance documents <ArrowRight size={15} />
      </button>
    </div>
  )
}

function ListCard({ title, icon: Icon, rows, render, empty }) {
  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 14 }}>
        <Icon size={16} style={{ color: '#a78bfa' }} />
        <h2 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>{title}</h2>
        <span style={{ marginLeft: 'auto', fontSize: 11.5, color: 'var(--text-muted)', fontWeight: 700 }}>{rows.length}</span>
      </div>
      {rows.length === 0 ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0, padding: '8px 0' }}>{empty}</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>{rows.map(render)}</div>
      )}
    </div>
  )
}

function Row({ left, sub, right, rightSub, badge, meta, onClick }) {
  return (
    <div onClick={onClick} className={onClick ? 'pr-li-row' : undefined}
      style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '11px 13px', borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)', cursor: onClick ? 'pointer' : 'default' }}>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <span style={{ fontSize: 13, fontWeight: 800, color: '#a78bfa' }}>{left}</span>
          {badge && <span style={{ padding: '2px 8px', borderRadius: 999, background: badge.bg, color: badge.color, fontSize: 10, fontWeight: 800 }}>{badge.label}</span>}
        </div>
        <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 2, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
          {sub || '—'}{meta ? ` · ${meta}` : ''}
        </div>
      </div>
      <div style={{ textAlign: 'right', flexShrink: 0 }}>
        <div style={{ fontSize: 13.5, fontWeight: 800, color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>{right}</div>
        {rightSub && <div style={{ fontSize: 10, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>{rightSub}</div>}
      </div>
    </div>
  )
}
