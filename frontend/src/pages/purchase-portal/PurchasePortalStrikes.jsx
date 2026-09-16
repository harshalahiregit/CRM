import { useCallback, useEffect, useState } from 'react'
import { AlertOctagon, RefreshCw } from 'lucide-react'
import { purchasePortalApi } from '@/services/purchasePortalApi'
import LoadError from '@/components/ui/LoadError'

/**
 * Safety strikes against this vendor's own workers — read-only.
 *
 * A vendor cannot issue or void a strike: three of them terminate a worker's
 * site access, so the authority to hand them out sits with the site, not with
 * the company being struck. Seeing them is the whole point — a vendor who
 * cannot see the first two strikes cannot do anything about the third.
 *
 * Purchase had no strikes engine at all until now, so this screen has nothing
 * to replace; TPV's equivalent is TpvStrikes, which carries the admin controls
 * as well because it serves both sides.
 */

const TONE = {
  Minor:    '#f59e0b',
  Major:    '#f97316',
  Critical: '#ef4444',
}

const fmtDate = (d) => {
  if (!d) return '—'
  const at = new Date(d)
  return Number.isNaN(at.getTime()) ? '—' : at.toLocaleDateString()
}

export default function PurchasePortalStrikes() {
  const [rows, setRows] = useState(null)
  const [error, setError] = useState(null)
  const [onlyActive, setOnlyActive] = useState(false)

  const load = useCallback(() => {
    setError(null)
    purchasePortalApi.strikes.list(onlyActive ? { active: 1 } : {})
      .then(d => setRows(Array.isArray(d) ? d : (d?.data ?? [])))
      .catch(e => { setRows([]); setError(e) })
  }, [onlyActive])

  useEffect(() => { load() }, [load])

  const active = (rows ?? []).filter(r => !r.voided_at).length

  return (
    <div>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', flexWrap: 'wrap', gap: 12, marginBottom: 14 }}>
        <div>
          <h1 style={{ margin: 0, fontSize: 20, fontWeight: 900, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 8 }}>
            <AlertOctagon size={19} /> Safety Strikes
          </h1>
          <p style={{ margin: '4px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>
            Issued by the site against your workers. Three active strikes, or one Critical, ends a worker's site access.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12.5, color: 'var(--text-muted)', cursor: 'pointer' }}>
            <input type="checkbox" checked={onlyActive} onChange={e => setOnlyActive(e.target.checked)}
              style={{ width: 15, height: 15, accentColor: '#7C3AED' }} />
            Active only
          </label>
          <button onClick={load} style={BTN}><RefreshCw size={14} /> Refresh</button>
        </div>
      </header>

      {rows !== null && active > 0 && (
        <div style={{
          display: 'flex', alignItems: 'center', gap: 9, padding: '10px 13px', borderRadius: 12, marginBottom: 14,
          background: 'rgba(239,68,68,0.10)', border: '1px solid rgba(239,68,68,0.30)',
        }}>
          <AlertOctagon size={15} style={{ color: '#ef4444', flexShrink: 0 }} />
          <span style={{ fontSize: 12.5, color: 'var(--text-body, #c8c3dd)', fontWeight: 600 }}>
            {active} active {active === 1 ? 'strike' : 'strikes'} across your workers.
          </span>
        </div>
      )}

      {error ? (
        <LoadError error={error} onRetry={load} />
      ) : (
        <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
              <thead>
                <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.04em' }}>
                  {['Worker', 'Severity', 'Reason', 'When', 'Where', 'State'].map(h => <th key={h} style={TH}>{h}</th>)}
                </tr>
              </thead>
              <tbody>
                {rows === null ? (
                  <tr><td colSpan={6} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
                ) : rows.length === 0 ? (
                  <tr><td colSpan={6} style={{ padding: 18, color: '#10b981' }}>
                    No safety strikes against your workers.
                  </td></tr>
                ) : rows.map(r => {
                  const tone = TONE[r.severity] || '#6b7280'
                  return (
                    <tr key={r.id} style={{ borderTop: '1px solid var(--border)', opacity: r.voided_at ? 0.55 : 1 }}>
                      <td style={{ ...TD, color: 'var(--text-h)', fontWeight: 700 }}>
                        {r.worker?.full_name || '—'}
                        <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 500 }}>{r.worker?.worker_code}</div>
                      </td>
                      <td style={TD}>
                        <span style={{ fontSize: 11, fontWeight: 800, padding: '3px 9px', borderRadius: 999, color: tone, background: `${tone}22` }}>
                          {r.severity_label || r.severity}
                        </span>
                      </td>
                      <td style={{ ...TD, whiteSpace: 'normal', maxWidth: 320 }}>{r.reason || '—'}</td>
                      <td style={TD}>{fmtDate(r.occurred_at)}</td>
                      <td style={TD}>{r.location || '—'}</td>
                      <td style={TD}>
                        {r.voided_at
                          ? <span title={r.void_reason || undefined} style={{ color: 'var(--text-muted)' }}>Voided</span>
                          : <span style={{ color: '#ef4444', fontWeight: 700 }}>Active</span>}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}

const BTN = {
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 13px', borderRadius: 9,
  background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)',
  cursor: 'pointer', fontSize: 12.5, fontWeight: 700,
}

const TH = { textAlign: 'left', padding: '10px 12px', fontWeight: 700, whiteSpace: 'nowrap' }
const TD = { padding: '10px 12px', color: 'var(--text-muted)', whiteSpace: 'nowrap' }
