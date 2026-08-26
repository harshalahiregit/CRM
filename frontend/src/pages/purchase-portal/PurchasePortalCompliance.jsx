import { useState, useEffect } from 'react'
import { ShieldCheck, RefreshCw } from 'lucide-react'
import purchasePortalApi from '@/services/purchasePortalApi'

// §32 "View compliance" — the Purchase vendor's own compliance register (read-only).
const TONE = {
  Compliant: '#16a34a', Waived: '#0891b2', Expiring: '#d97706',
  Expired: '#dc2626', Non_Compliant: '#dc2626', Partially_Compliant: '#d97706',
  Under_Review: '#64748b',
}
const label = (s) => String(s || '').replace(/_/g, ' ')

export default function PurchasePortalCompliance() {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const load = () => {
    setLoading(true)
    purchasePortalApi.compliance.get()
      .then(d => { setData(d); setError(null) })
      .catch(() => setError('Could not load your compliance status.'))
      .finally(() => setLoading(false))
  }
  useEffect(() => { load() }, [])

  const score = data?.score || {}
  const rows = data?.matrix || []

  return (
    <div style={{ padding: 4 }}>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p style={{ color: '#0891b2', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em', textTransform: 'uppercase' }}>My Compliance</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0' }}>Compliance Status</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Your compliance across each category. Expiry drives status automatically.</p>
        </div>
        <button onClick={load} style={btnGhost}><RefreshCw size={14} /> Refresh</button>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(120px,1fr))', gap: 10, marginBottom: 16 }}>
        <Stat label="Overall" value={score.percent != null ? `${score.percent}%` : '—'} tone="#0891b2" icon={ShieldCheck} />
        <Stat label="In good standing" value={score.ok ?? 0} tone="#16a34a" />
        <Stat label="Problems" value={score.problems ?? 0} tone="#dc2626" />
        <Stat label="Expiring" value={score.expiring ?? 0} tone="#d97706" />
      </div>

      <div style={card}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                {['Category', 'Status', 'Valid until', 'Requirement'].map(h => <th key={h} style={{ padding: '10px 14px' }}>{h}</th>)}
              </tr>
            </thead>
            <tbody>
              {loading ? <tr><td colSpan={4} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
                : error ? <tr><td colSpan={4} style={{ padding: 18, color: '#dc2626' }}>{error}</td></tr>
                : rows.length === 0 ? <tr><td colSpan={4} style={{ padding: 18, color: 'var(--text-muted)' }}>No compliance categories yet.</td></tr>
                : rows.map(r => (
                  <tr key={r.category} style={{ borderTop: '1px solid var(--border)' }}>
                    <td style={{ padding: '10px 14px', color: 'var(--text-h)', fontWeight: 600 }}>{r.category_label || label(r.category)}</td>
                    <td style={{ padding: '10px 14px' }}>
                      <span style={{ display: 'inline-block', padding: '3px 9px', borderRadius: 999, fontSize: 11, fontWeight: 700, background: `${TONE[r.status] || '#64748b'}1f`, color: TONE[r.status] || '#64748b' }}>{label(r.status)}</span>
                    </td>
                    <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>{r.valid_until || '—'}</td>
                    <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>{r.requirement || '—'}</td>
                  </tr>
                ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}

function Stat({ label, value, tone, icon: Icon }) {
  return (
    <div style={{ ...card, padding: '12px 14px' }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <span style={{ fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>{label}</span>
        {Icon && <Icon size={14} style={{ color: tone }} />}
      </div>
      <div style={{ fontSize: 22, fontWeight: 900, color: tone, marginTop: 4 }}>{value}</div>
    </div>
  )
}

const card = { background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 14, padding: 0, overflow: 'hidden' }
const btnGhost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }
