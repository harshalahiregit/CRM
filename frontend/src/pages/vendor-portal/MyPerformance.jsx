import { useEffect, useState } from 'react'
import { Loader2, ShieldAlert, Gavel } from 'lucide-react'
import { portalApi } from '@/services/portalApi'

/**
 * TPV portal — Performance section (read-only). `view="risk"` shows the vendor's
 * own risk score/tier + factor breakdown; `view="penalty"` shows its violations
 * and running penalty points. Vendors can see these but never change them —
 * assessment and violations are admin authority.
 */
export default function MyPerformance({ view }) {
  return view === 'penalty' ? <Penalty /> : <Risk />
}

const TIER_TONE = { critical: '#ef4444', high: '#f59e0b', medium: '#3b82f6', low: '#22c55e' }

function Risk() {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  useEffect(() => { portalApi.performance.risk().then(setData).finally(() => setLoading(false)) }, [])

  if (loading) return <Center><Loader2 className="mp-spin" size={22} /></Center>

  const assessed = data?.assessed && data?.level
  const tone = TIER_TONE[String(data?.level || '').toLowerCase()] || '#94a3b8'
  const score = Number(data?.score ?? 0)
  const breakdown = Array.isArray(data?.breakdown) ? data.breakdown : []

  return (
    <Wrap>
      <style>{CSS}</style>
      <h2 className="mp-h2">Risk Score</h2>
      {!assessed ? (
        <div className="mp-card mp-empty"><ShieldAlert size={22} style={{ opacity: 0.6 }} /> Your risk profile has not been assessed yet. It will appear here once the review team completes it.</div>
      ) : (
        <>
          <div className="mp-card" style={{ display: 'flex', gap: 24, alignItems: 'center', flexWrap: 'wrap' }}>
            <Gauge score={score} tone={tone} />
            <div style={{ flex: 1, minWidth: 180 }}>
              <div style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.06em', color: 'var(--text-muted)' }}>Risk Tier</div>
              <div style={{ fontSize: 26, fontWeight: 800, color: tone, textTransform: 'capitalize' }}>{data.level}</div>
              {data.monitoring && <div style={{ fontSize: 13, color: 'var(--text-muted)', marginTop: 4 }}>Monitoring: <strong style={{ color: 'var(--text-h)' }}>{data.monitoring}</strong></div>}
              {data.assessed_at && <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>Assessed {String(data.assessed_at).slice(0, 10)}</div>}
            </div>
          </div>

          {breakdown.length > 0 && (
            <div className="mp-card" style={{ marginTop: 14 }}>
              <div className="mp-cardhead">Factors considered</div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                {breakdown.map((b, i) => (
                  <div key={i} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, fontSize: 13 }}>
                    <span style={{ color: 'var(--text-body,#cbd5e1)' }}>{b.label || b.factor || b.name || `Factor ${i + 1}`}</span>
                    <span style={{ color: 'var(--text-h)', fontWeight: 700 }}>{b.points ?? b.score ?? b.value ?? ''}</span>
                  </div>
                ))}
              </div>
            </div>
          )}
        </>
      )}
    </Wrap>
  )
}

function Gauge({ score, tone }) {
  const pct = Math.max(0, Math.min(100, score))
  const deg = pct * 3.6
  return (
    <div style={{ width: 130, height: 130, borderRadius: '50%', background: `conic-gradient(${tone} ${deg}deg, var(--bg-input,rgba(255,255,255,0.06)) ${deg}deg)`, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
      <div style={{ width: 100, height: 100, borderRadius: '50%', background: 'var(--bg-card,#14161c)', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center' }}>
        <div style={{ fontSize: 30, fontWeight: 800, color: 'var(--text-h)' }}>{pct}</div>
        <div style={{ fontSize: 10, textTransform: 'uppercase', color: 'var(--text-muted)' }}>/ 100</div>
      </div>
    </div>
  )
}

const SEV_TONE = { critical: '#ef4444', major: '#f59e0b', minor: '#3b82f6', low: '#22c55e' }
function Penalty() {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  useEffect(() => { portalApi.performance.violations().then(setData).finally(() => setLoading(false)) }, [])
  if (loading) return <Center><Loader2 className="mp-spin" size={22} /></Center>

  const rows = data?.data || []
  return (
    <Wrap>
      <style>{CSS}</style>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 8 }}>
        <h2 className="mp-h2" style={{ margin: 0 }}>Penalty & Violations</h2>
        <div style={{ display: 'flex', gap: 18 }}>
          <Stat label="Penalty Points" value={data?.total_points ?? 0} />
          <Stat label="Open" value={data?.open_count ?? 0} />
        </div>
      </div>
      {rows.length === 0 ? (
        <div className="mp-card mp-empty" style={{ marginTop: 14 }}><Gavel size={22} style={{ opacity: 0.6 }} /> No violations on record. Keep it up.</div>
      ) : (
        <div className="mp-card" style={{ marginTop: 14, padding: '6px 4px' }}>
          <div style={{ overflowX: 'auto' }}>
            <table className="mp-table">
              <thead><tr><th>Ref</th><th>Type</th><th>Severity</th><th>Date</th><th style={{ textAlign: 'right' }}>Points</th><th>Status</th></tr></thead>
              <tbody>
                {rows.map(v => (
                  <tr key={v.id}>
                    <td style={{ fontWeight: 700, color: 'var(--text-h)' }}>{v.reference || '—'}</td>
                    <td>{v.type || '—'}</td>
                    <td><span style={{ color: SEV_TONE[String(v.severity || '').toLowerCase()] || '#94a3b8', fontWeight: 700 }}>{v.severity || '—'}</span></td>
                    <td>{v.occurred_at ? String(v.occurred_at).slice(0, 10) : '—'}</td>
                    <td style={{ textAlign: 'right', fontWeight: 700 }}>{v.points ?? 0}</td>
                    <td>{v.status || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </Wrap>
  )
}

function Stat({ label, value }) {
  return (
    <div style={{ textAlign: 'right' }}>
      <div style={{ fontSize: 10.5, textTransform: 'uppercase', color: 'var(--text-muted)' }}>{label}</div>
      <div style={{ fontSize: 20, fontWeight: 800, color: 'var(--text-h)' }}>{value}</div>
    </div>
  )
}
function Wrap({ children }) { return <div style={{ maxWidth: 760, margin: '0 auto' }}>{children}</div> }
function Center({ children }) { return <div style={{ display: 'flex', justifyContent: 'center', padding: 60 }}>{children}</div> }

const CSS = `
.mp-h2 { font-size: 18px; font-weight: 800; color: var(--text-h); margin: 0 0 16px; }
.mp-card { background: var(--bg-card, rgba(255,255,255,0.02)); border: 1px solid var(--border, rgba(255,255,255,0.08)); border-radius: 14px; padding: 18px; }
.mp-cardhead { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 12px; }
.mp-empty { display: flex; align-items: center; gap: 10px; color: var(--text-muted); font-size: 14px; }
.mp-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.mp-table th { text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); padding: 10px 14px; border-bottom: 1px solid var(--border, rgba(255,255,255,0.08)); white-space: nowrap; }
.mp-table td { padding: 11px 14px; border-bottom: 1px solid var(--border, rgba(255,255,255,0.05)); color: var(--text-body, #cbd5e1); }
.mp-table tbody tr:last-child td { border-bottom: none; }
.mp-spin { animation: mp-spin 0.9s linear infinite; }
@keyframes mp-spin { to { transform: rotate(360deg); } }
`
