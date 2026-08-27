import { useState, useEffect, useCallback } from 'react'
import { HeartPulse, RefreshCw } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import LoadError from '@/components/ui/LoadError'
import { KIT3D_STYLE as TPV_STYLE } from '@/components/ui/kit3d'

// Sangoe TPV §3/§16 — Medical Fitness register. A dedicated cross-workforce view
// of worker medical examinations (fitness verdict + certificate currency),
// previously reachable only inside the worker wizard.
const FITNESS_TONE = {
  Fit: '#10b981',
  Fit_With_Restrictions: '#f59e0b',
  Pending: '#6366f1',
  Unfit: '#ef4444',
  Expired: '#ef4444',
}
const label = (s) => (s || '—').replace(/_/g, ' ')

export default function TpvMedicalFitness() {
  const [rows, setRows] = useState(null)
  const [summary, setSummary] = useState(null)
  const [statuses, setStatuses] = useState([])
  const [loadError, setLoadError] = useState(null)
  const [filters, setFilters] = useState({ fitness_status: '', expiry: '' })

  const load = useCallback(() => {
    const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v))
    tpvApi.medical.list(params).then(d => {
      setLoadError(null)
      setRows(d?.data ?? [])
      setSummary(d?.summary ?? null)
      if (d?.statuses) setStatuses(d.statuses)
    }).catch(e => { setRows([]); setLoadError(e) })
  }, [filters])
  useEffect(() => { load() }, [load])

  return (
    <div style={{ padding: 4 }}>
      <style>{TPV_STYLE}</style>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>WORKFORCE</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0', display: 'flex', alignItems: 'center', gap: 8 }}>
            <HeartPulse size={20} /> Medical Fitness
          </h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Worker medical examinations — fitness verdict and certificate currency. Unfit or lapsed is a hard gate.</p>
        </div>
        <button onClick={load} style={btnGhost}><RefreshCw size={14} /> Refresh</button>
      </div>

      {/* Summary strip */}
      {summary && (
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 14 }}>
          <Stat label="Records" value={summary.total} tone="#7C3AED" />
          <Stat label="Fit" value={summary.fit} tone="#10b981" />
          <Stat label="Pending" value={summary.pending} tone="#6366f1" />
          <Stat label="Unfit" value={summary.unfit} tone="#ef4444" />
          <Stat label="Expired" value={summary.expired} tone="#ef4444" />
        </div>
      )}

      {/* Filters */}
      <div style={{ display: 'flex', gap: 10, marginBottom: 12, flexWrap: 'wrap' }}>
        <select value={filters.fitness_status} onChange={e => setFilters(f => ({ ...f, fitness_status: e.target.value }))} style={sel}>
          <option value="">All fitness</option>
          {statuses.map(s => <option key={s} value={s}>{label(s)}</option>)}
        </select>
        <select value={filters.expiry} onChange={e => setFilters(f => ({ ...f, expiry: e.target.value }))} style={sel}>
          <option value="">Any currency</option>
          <option value="expiring">Expiring (≤30d)</option>
          <option value="expired">Expired</option>
        </select>
      </div>

      <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                {['Worker', 'Vendor', 'Fitness', 'Exam date', 'Valid until', 'Examiner'].map((h, i) => <th key={i} style={{ padding: '11px 14px' }}>{h}</th>)}
              </tr>
            </thead>
            <tbody>
              {loadError ? <tr><td colSpan={6} style={{ padding: 8 }}><LoadError error={loadError} onRetry={load} /></td></tr>
                : rows === null ? <tr><td colSpan={6} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
                : rows.length === 0 ? <tr><td colSpan={6} style={{ padding: 18, color: 'var(--text-muted)' }}>No medical records yet.</td></tr>
                : rows.map(m => {
                  const tone = FITNESS_TONE[m.fitness_status] || '#6b7280'
                  return (
                    <tr key={m.id} style={{ borderTop: '1px solid var(--border)' }}>
                      <td style={{ padding: '10px 14px', fontWeight: 700, color: 'var(--text-h)' }}>{m.worker?.name || '—'}<div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 500 }}>{m.worker?.worker_code}</div></td>
                      <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>{m.worker?.vendor?.company_name || '—'}</td>
                      <td style={{ padding: '10px 14px' }}>
                        <span style={{ display: 'inline-block', padding: '3px 9px', borderRadius: 999, background: tone + '22', color: tone, fontSize: 11.5, fontWeight: 700 }}>{label(m.fitness_status)}</span>
                      </td>
                      <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>{m.exam_date || '—'}</td>
                      <td style={{ padding: '10px 14px', color: m.is_expired ? '#ef4444' : 'var(--text-muted)', fontWeight: m.is_expired ? 700 : 500 }}>
                        {m.valid_until || '—'}{m.is_expired ? ' · expired' : ''}
                      </td>
                      <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>{m.examiner_name || '—'}</td>
                    </tr>
                  )
                })}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}

function Stat({ label, value, tone }) {
  return (
    <div className="pr-glass" style={{ padding: '10px 16px', borderRadius: 12, minWidth: 96 }}>
      <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.04em' }}>{label}</div>
      <div style={{ fontSize: 22, fontWeight: 900, color: tone }}>{value ?? 0}</div>
    </div>
  )
}

const btnGhost = { display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }
const sel = { padding: '8px 12px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-h)', fontSize: 13 }
