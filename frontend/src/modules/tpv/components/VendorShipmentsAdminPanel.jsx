import { useEffect, useState } from 'react'
import { Loader2, Truck, Package } from 'lucide-react'

/**
 * Admin-side mirror of the vendor portal's Pre-Alert / Packages / Shipping — the
 * dispatch notices a vendor sent, with their packages. Read-only for admin (the
 * vendor owns and advances its shipments). Takes { vendorId, api }.
 */
const STATUS_TONE = { 'pre-alert': '#f59e0b', dispatched: '#3b82f6', 'in-transit': '#3b82f6', delivered: '#22c55e', cancelled: '#ef4444' }
const date = v => (v ? String(v).slice(0, 10) : '—')

export function VendorShipmentsAdminPanel({ vendorId, api }) {
  const [rows, setRows] = useState(null)
  useEffect(() => { api.vendors.shipments(vendorId).then(d => setRows(d?.data || [])).catch(() => setRows([])) }, [vendorId]) // eslint-disable-line react-hooks/exhaustive-deps

  if (rows === null) return <div style={{ display: 'flex', justifyContent: 'center', padding: 48 }}><Loader2 className="tpv-spin" size={22} /></div>

  return (
    <div>
      <h3 style={{ fontSize: 16, fontWeight: 800, color: 'var(--text-h, #e5e7eb)', margin: '0 0 14px' }}>Shipments (Pre-Alert / Packages / Shipping)</h3>
      {rows.length === 0 ? (
        <div style={{ background: 'var(--bg-card, rgba(255,255,255,0.03))', border: '1px solid var(--border, rgba(148,163,184,0.2))', borderRadius: 12, padding: 16, color: 'var(--text-muted, #9ca3af)', fontSize: 14, display: 'flex', alignItems: 'center', gap: 10 }}>
          <Truck size={20} style={{ opacity: 0.6 }} /> No shipments from this vendor yet.
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          {rows.map(s => (
            <div key={s.id} style={{ background: 'var(--bg-card, rgba(255,255,255,0.03))', border: '1px solid var(--border, rgba(148,163,184,0.2))', borderRadius: 12, padding: 16 }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                <strong style={{ color: 'var(--text-h,#e5e7eb)' }}>{s.reference}</strong>
                <span style={{ padding: '2px 10px', borderRadius: 999, fontSize: 11, fontWeight: 700, background: 'rgba(148,163,184,0.15)', color: STATUS_TONE[String(s.status || '').toLowerCase()] || '#94a3b8' }}>{s.status}</span>
                <span style={{ marginLeft: 'auto', fontSize: 12.5, color: 'var(--text-muted,#9ca3af)' }}>
                  {s.courier || '—'}{s.tracking_number ? ` · ${s.tracking_number}` : ''}
                </span>
              </div>
              <div style={{ fontSize: 12.5, color: 'var(--text-muted,#9ca3af)', marginTop: 6 }}>
                Expected {date(s.expected_date)} · Dispatched {date(s.dispatched_on)} · Delivered {date(s.delivered_on)}
              </div>
              {(s.packages || []).length > 0 && (
                <div style={{ marginTop: 10, borderTop: '1px solid var(--border, rgba(148,163,184,0.15))', paddingTop: 10 }}>
                  {s.packages.map(p => (
                    <div key={p.id} style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12.5, color: 'var(--text-body,#cbd5e1)', padding: '3px 0' }}>
                      <Package size={13} style={{ opacity: 0.6 }} />
                      <span style={{ flex: 1 }}>{p.description}</span>
                      <span>×{p.qty}</span>
                      {p.weight && <span style={{ color: 'var(--text-muted,#9ca3af)' }}>{p.weight}</span>}
                      {p.dimensions && <span style={{ color: 'var(--text-muted,#9ca3af)' }}>{p.dimensions}</span>}
                    </div>
                  ))}
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
