import { useState, useEffect } from 'react'
import { Landmark, Loader2, Check } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'

const AUTH_COLOR = { PMC: '#7C3AED', Safety: '#ef4444', Accounts: '#0ea5e9', Admin: '#f59e0b' }

/**
 * HSSE Authority Matrix (Doc 1). The named authorities — PMC, Safety, Accounts,
 * Admin — and the governance actions each owns. The single reference for "who
 * signs off what", driven from config/authority.php.
 */
export default function TpvAuthorityMatrix() {
  const [d, setD] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => { tpvApi.governance.authorityMatrix().then(setD).catch(() => setD(null)).finally(() => setLoading(false)) }, [])

  if (loading) return <div style={{ padding: 48, textAlign: 'center', color: 'var(--text-muted)' }}><Loader2 size={20} className="rfq-spin" /></div>
  if (!d) return <div style={{ padding: 48, textAlign: 'center', color: 'var(--text-muted)' }}>Could not load the authority matrix.</div>

  const auth = d.authorities || {}
  const keys = Object.keys(auth)

  return (
    <div style={{ padding: 24, maxWidth: 1050, margin: '0 auto' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6 }}>
        <Landmark size={22} style={{ color: '#7C3AED' }} />
        <h1 style={{ margin: 0, fontSize: 20, fontWeight: 800, color: 'var(--text-h)' }}>Authority Matrix</h1>
      </div>
      <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '0 0 20px' }}>The named authorities and who signs off each governance action (Doc 1).</p>

      {/* Authority cards */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(230px, 1fr))', gap: 12, marginBottom: 24 }}>
        {keys.map(k => (
          <div key={k} className="pr-glass" style={{ borderRadius: 14, padding: 16, borderTop: `3px solid ${AUTH_COLOR[k] || '#94a3b8'}` }}>
            <div style={{ fontSize: 14, fontWeight: 800, color: AUTH_COLOR[k] || 'var(--text-h)' }}>{k}</div>
            <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginBottom: 8 }}>{auth[k].label}</div>
            <ul style={{ margin: 0, paddingLeft: 16, fontSize: 12, color: 'var(--text-body)', lineHeight: 1.6 }}>
              {(auth[k].responsibilities || []).map((r, i) => <li key={i}>{r}</li>)}
            </ul>
          </div>
        ))}
      </div>

      {/* Matrix table */}
      <div className="pr-glass" style={{ borderRadius: 14, padding: 6, overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
          <thead>
            <tr>
              <th style={{ ...th, textAlign: 'left', minWidth: 220 }}>Governance Action</th>
              <th style={{ ...th, textAlign: 'left' }}>Gate</th>
              {keys.map(k => <th key={k} style={{ ...th, color: AUTH_COLOR[k] }}>{k}</th>)}
            </tr>
          </thead>
          <tbody>
            {(d.matrix || []).map((row, i) => (
              <tr key={i}>
                <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)' }}>{row.action}</td>
                <td style={{ ...td, color: 'var(--text-muted)' }}>{row.gate}</td>
                {keys.map(k => (
                  <td key={k} style={{ ...td, textAlign: 'center' }}>
                    {(row.authorities || []).includes(k) ? <Check size={16} style={{ color: AUTH_COLOR[k] }} /> : <span style={{ color: 'var(--border)' }}>·</span>}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

const th = { padding: '10px 12px', fontSize: 10.5, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--text-muted)', borderBottom: '1px solid var(--border)', textAlign: 'center', whiteSpace: 'nowrap' }
const td = { padding: '10px 12px', borderBottom: '1px solid var(--border)' }
