import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { ArrowLeft, ShoppingBag, Clock, Calendar, PackageCheck, AlertTriangle } from 'lucide-react'
import { portalApi } from '@/services/portalApi'
import { poStatusCfg, fmtMoney, fmtDate } from './portalConstants'
import { KIT3D_STYLE } from '@/components/ui/kit3d'

/**
 * Read-only purchase-order detail for the vendor. Shows ordered vs received per
 * line so the vendor can see exactly what's been booked in against their order.
 * A guessed/foreign id returns 404 from the portal middleware → we show a clean
 * "not found", never another vendor's data.
 */
export default function PortalOrderDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [po, setPo] = useState(null)
  const [loading, setLoad] = useState(true)
  const [notFound, setNotFound] = useState(false)

  useEffect(() => {
    portalApi.order(id)
      .then(d => { setPo(d?.data ?? d); setLoad(false) })
      .catch(e => { if (e?.response?.status === 404) setNotFound(true); setLoad(false) })
  }, [id])

  if (loading) return <Loading />
  if (notFound || !po) return <NotFound onBack={() => navigate('/vendor-portal/dashboard')} label="Purchase order" />

  const cfg = poStatusCfg(po.status)
  const items = po.items || []

  return (
    <div>
      <style>{KIT3D_STYLE}</style>

      <BackHeader onBack={() => navigate('/vendor-portal/dashboard')}
        eyebrow="PURCHASE ORDER" title={po.po_number} subtitle={po.title} statusCfg={cfg} />

      {/* Meta */}
      <div className="pr-glass" style={{ padding: 18, marginBottom: 16, display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(160px,1fr))', gap: 14 }}>
        <Meta icon={Calendar} label="Order date" value={fmtDate(po.order_date)} />
        <Meta icon={Clock} label="Expected delivery" value={fmtDate(po.expected_delivery_date)} />
        <Meta icon={ShoppingBag} label="Order value" value={fmtMoney(po.total, po.currency)} />
      </div>

      {/* Line items — ordered vs received */}
      <div className="pr-glass" style={{ padding: 20 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 14 }}>
          <PackageCheck size={16} style={{ color: '#a78bfa' }} />
          <h2 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Line Items</h2>
          <span style={{ marginLeft: 'auto', fontSize: 11.5, color: 'var(--text-muted)' }}>ordered vs received</span>
        </div>

        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 560 }}>
            <thead><tr>{['Item', 'Ordered', 'Received', 'Rate', 'Amount'].map((h, i) => (
              <th key={h} style={{ textAlign: i === 0 ? 'left' : 'right', padding: '8px 10px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', borderBottom: '1px solid var(--border)' }}>{h}</th>
            ))}</tr></thead>
            <tbody>
              {items.map((it, i) => {
                const ordered = Number(it.qty) || 0
                const received = Number(it.received_qty) || 0
                const pct = ordered > 0 ? Math.min(100, Math.round((received / ordered) * 100)) : 0
                const full = received >= ordered && ordered > 0
                return (
                  <tr key={i}>
                    <td style={{ padding: '10px', fontSize: 12.5, color: 'var(--text-h)', verticalAlign: 'top' }}>
                      {it.description}{it.unit ? <span style={{ color: 'var(--text-muted)' }}> · {it.unit}</span> : ''}
                      {/* Per-line received progress — the vendor sees fulfilment at a glance. */}
                      <div className="pr-bar" style={{ height: 6, marginTop: 6, maxWidth: 220 }}>
                        <span style={{ width: `${pct}%`, background: full ? 'linear-gradient(90deg,#34d399,#10b981)' : 'linear-gradient(90deg,#a78bfa,#7C3AED)' }} />
                      </div>
                    </td>
                    <td style={{ padding: '10px', fontSize: 12.5, textAlign: 'right', color: 'var(--text-muted)', fontVariantNumeric: 'tabular-nums', verticalAlign: 'top' }}>{ordered}</td>
                    <td style={{ padding: '10px', fontSize: 12.5, textAlign: 'right', fontWeight: 700, color: full ? '#10b981' : 'var(--text-h)', fontVariantNumeric: 'tabular-nums', verticalAlign: 'top' }}>
                      {received}{full && ' ✓'}
                    </td>
                    <td style={{ padding: '10px', fontSize: 12.5, textAlign: 'right', color: 'var(--text-muted)', fontVariantNumeric: 'tabular-nums', verticalAlign: 'top' }}>{fmtMoney(it.rate, po.currency)}</td>
                    <td style={{ padding: '10px', fontSize: 12.5, textAlign: 'right', fontWeight: 700, color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums', verticalAlign: 'top' }}>{fmtMoney(it.amount, po.currency)}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>

        <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 16 }}>
          <div style={{ minWidth: 220, display: 'flex', flexDirection: 'column', gap: 6 }}>
            <TotalRow label="Subtotal" value={fmtMoney(po.subtotal, po.currency)} />
            <TotalRow label="Tax" value={fmtMoney(po.tax_total, po.currency)} />
            <div style={{ height: 1, background: 'var(--border)', margin: '2px 0' }} />
            <TotalRow label="Total" value={fmtMoney(po.total, po.currency)} strong />
          </div>
        </div>
      </div>
    </div>
  )
}

/* shared bits (also used by the invoice detail) */
export function BackHeader({ onBack, eyebrow, title, subtitle, statusCfg, extra }) {
  return (
    <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12, marginBottom: 18 }}>
      <button onClick={onBack} style={{ width: 36, height: 36, borderRadius: 10, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)', marginTop: 2, flexShrink: 0 }}>
        <ArrowLeft size={16} />
      </button>
      <div style={{ flex: 1, minWidth: 0 }}>
        <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>{eyebrow}</p>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>{title}</h1>
          {statusCfg && <span style={{ padding: '4px 11px', borderRadius: 999, background: statusCfg.bg, color: statusCfg.color, fontSize: 11.5, fontWeight: 800 }}>{statusCfg.label}</span>}
          {extra}
        </div>
        {subtitle && <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '4px 0 0' }}>{subtitle}</p>}
      </div>
    </div>
  )
}

export const Meta = ({ icon: Icon, label, value }) => (
  <div>
    <div style={{ display: 'flex', alignItems: 'center', gap: 5, fontSize: 10.5, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 3 }}>
      <Icon size={11} /> {label}
    </div>
    <div style={{ fontSize: 13.5, fontWeight: 600, color: 'var(--text-h)' }}>{value}</div>
  </div>
)

export const TotalRow = ({ label, value, strong }) => (
  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
    <span style={{ fontSize: strong ? 13 : 12, color: strong ? 'var(--text-h)' : 'var(--text-muted)', fontWeight: strong ? 800 : 500 }}>{label}</span>
    <span style={{ fontSize: strong ? 15 : 12.5, fontWeight: strong ? 900 : 600, color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>{value}</span>
  </div>
)

export function Loading() {
  return <div><style>{KIT3D_STYLE}</style>
    <div className="skeleton" style={{ height: 44, width: 260, borderRadius: 12, background: 'var(--border)', marginBottom: 16 }} />
    <div className="skeleton" style={{ height: 200, borderRadius: 16, background: 'var(--border)' }} />
  </div>
}

export function NotFound({ onBack, label }) {
  return (
    <div><style>{KIT3D_STYLE}</style>
      <div className="pr-glass" style={{ padding: '48px 24px', textAlign: 'center', maxWidth: 460, margin: '40px auto' }}>
        <div style={{ width: 60, height: 60, borderRadius: '50%', margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(148,163,184,0.14)' }}>
          <AlertTriangle size={26} style={{ color: '#94a3b8' }} />
        </div>
        <h3 style={{ margin: 0, fontSize: 17, fontWeight: 800, color: 'var(--text-h)' }}>{label} not found</h3>
        <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '6px 0 18px' }}>It may not exist, or it isn't part of your account.</p>
        <button onClick={onBack} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 700, color: '#fff', border: 'none', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)' }}>
          <ArrowLeft size={15} /> Back to dashboard
        </button>
      </div>
    </div>
  )
}
