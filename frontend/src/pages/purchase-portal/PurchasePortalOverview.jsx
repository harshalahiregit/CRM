import { useEffect, useState } from 'react'
import { Loader2, Building2, ShoppingCart, Receipt, FileSignature, Wallet } from 'lucide-react'
import { purchasePortalApi } from '@/services/purchasePortalApi'

/**
 * Purchase portal — General › Overview. Mirrors the TPV portal's Overview: the
 * company header + account status + key counts, from the rich dashboard payload.
 */
const STATUS_TONE = { active: '#22c55e', pending_approval: '#f59e0b', on_hold: '#f59e0b', inactive: '#94a3b8', rejected: '#ef4444', blacklisted: '#ef4444', draft: '#94a3b8' }
const num = v => (v && typeof v === 'object' ? (v.count ?? v.total ?? 0) : (v ?? 0))

export default function PurchasePortalOverview() {
  const [d, setD] = useState(null)
  useEffect(() => { purchasePortalApi.dashboard().then(setD).catch(() => setD({})) }, [])
  if (d === null) return <div style={{ display: 'flex', justifyContent: 'center', padding: 60 }}><Loader2 className="pov-spin" size={22} /></div>

  const v = d?.vendor || {}
  const status = v.status || '—'
  const tone = STATUS_TONE[String(status).toLowerCase()] || '#94a3b8'
  const c = d?.commercial || d || {}
  const cards = [
    { label: 'Orders', value: num(c.orders), icon: ShoppingCart },
    { label: 'Invoices', value: num(c.invoices), icon: Receipt },
    { label: 'Contracts', value: num(c.contracts), icon: FileSignature },
    { label: 'Payments', value: num(c.payments), icon: Wallet },
  ]

  return (
    <div style={{ maxWidth: 900, margin: '0 auto' }}>
      <style>{CSS}</style>
      <div className="pov-hero">
        <div className="pov-logo"><Building2 size={22} color="#fff" /></div>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ fontSize: 20, fontWeight: 800, color: 'var(--text-h)' }}>{v.company_name || 'My Company'}</div>
          <div style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>{v.vendor_code || ''}{v.category ? ` · ${v.category}` : ''}</div>
        </div>
        <span style={{ padding: '4px 12px', borderRadius: 999, fontSize: 12, fontWeight: 800, textTransform: 'capitalize', background: 'rgba(148,163,184,0.15)', color: tone }}>{String(status).replace(/_/g, ' ')}</span>
      </div>

      {d?.onboarding && (
        <div className="pov-card" style={{ marginBottom: 16 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12.5, marginBottom: 6 }}>
            <span style={{ color: 'var(--text-muted)' }}>Onboarding · {d.onboarding.status_label || d.onboarding.status}</span>
            <span style={{ fontWeight: 700, color: 'var(--text-h)' }}>{d.onboarding.percent ?? 0}%</span>
          </div>
          <div style={{ height: 8, borderRadius: 999, background: 'var(--bg-input,rgba(255,255,255,0.06))', overflow: 'hidden' }}>
            <div style={{ width: `${d.onboarding.percent ?? 0}%`, height: '100%', background: '#7c3aed' }} />
          </div>
        </div>
      )}

      <div className="pov-grid">
        {cards.map(card => (
          <div key={card.label} className="pov-card">
            <card.icon size={18} style={{ color: 'var(--portal-purple,#7c3aed)' }} />
            <div style={{ fontSize: 26, fontWeight: 800, color: 'var(--text-h)', marginTop: 8 }}>{card.value}</div>
            <div style={{ fontSize: 11.5, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'var(--text-muted)' }}>{card.label}</div>
          </div>
        ))}
      </div>
    </div>
  )
}

const CSS = `
.pov-hero { display: flex; align-items: center; gap: 14px; background: var(--bg-card, rgba(255,255,255,0.02)); border: 1px solid var(--border, rgba(255,255,255,0.08)); border-radius: 14px; padding: 18px; margin-bottom: 16px; }
.pov-logo { width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg,#7c3aed,#a78bfa); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.pov-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
.pov-card { background: var(--bg-card, rgba(255,255,255,0.02)); border: 1px solid var(--border, rgba(255,255,255,0.08)); border-radius: 14px; padding: 16px; }
.pov-spin { animation: pov-spin 0.9s linear infinite; }
@keyframes pov-spin { to { transform: rotate(360deg); } }
`
