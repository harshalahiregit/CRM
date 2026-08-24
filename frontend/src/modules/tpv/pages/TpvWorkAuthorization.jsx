import { useState, useEffect, useCallback } from 'react'
import { ShieldCheck, ShieldAlert, RefreshCw, ChevronDown, ChevronRight, Check, X, Minus } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import LoadError from '@/components/ui/LoadError'
import { KIT3D_STYLE as TPV_STYLE } from '@/components/ui/kit3d'

// Sangoe TPV §19 — Unified Work Authorization. "A vendor being Active should NOT
// automatically mean all work is authorized." One verdict over Vendor + Compliance
// + Medical + Induction + PPE + Competency + Permit + Work Package. Read-only; the
// gate/badge enforcement is unchanged — this is the composite picture.
export default function TpvWorkAuthorization() {
  const [rows, setRows] = useState(null)
  const [expanded, setExpanded] = useState(null)

  const [loadError, setLoadError] = useState(null)
  const load = useCallback(() => {
      setLoadError(null)
    tpvApi.workAuthorization.roster().then(d => setRows(Array.isArray(d) ? d : [])).catch(e => { setRows([]); setLoadError(e) })
  }, [])
  useEffect(() => { load() }, [load])

  const authed = (rows || []).filter(r => r.authorized).length

  return (
    <div style={{ padding: 4 }}>
      <style>{TPV_STYLE}</style>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>WORK CONTROL</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0' }}>Work Authorization</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Vendor + Compliance + Medical + Induction + PPE + Competency + Permit + Work Package — one verdict.</p>
        </div>
        <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
          {rows && <span style={{ fontSize: 12.5, color: 'var(--text-muted)' }}><strong style={{ color: '#10b981' }}>{authed}</strong> / {rows.length} authorized</span>}
          <button onClick={load} style={btnGhost}><RefreshCw size={14} /> Refresh</button>
        </div>
      </div>

      <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                {['', 'Worker', 'Vendor', 'Authorization', 'Blockers'].map((h, i) => <th key={i} style={{ padding: '11px 14px' }}>{h}</th>)}
              </tr>
            </thead>
            <tbody>
              {loadError ? <tr><td colSpan={5} style={{ padding: 8 }}><LoadError error={loadError} onRetry={load} /></td></tr>
                : rows === null ? <tr><td colSpan={5} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
                : rows.length === 0 ? <tr><td colSpan={5} style={{ padding: 18, color: 'var(--text-muted)' }}>No active workers.</td></tr>
                : rows.map(r => (
                  <AuthRow key={r.id} r={r} expanded={expanded === r.id} onToggle={() => setExpanded(expanded === r.id ? null : r.id)} />
                ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}

function AuthRow({ r, expanded, onToggle }) {
  const [detail, setDetail] = useState(null)
  useEffect(() => {
    if (expanded && !detail) tpvApi.workAuthorization.worker(r.id).then(setDetail).catch(() => setDetail({ checks: [] }))
  }, [expanded, detail, r.id])

  return (
    <>
      <tr style={{ borderTop: '1px solid var(--border)' }}>
        <td style={{ padding: '10px 14px' }}><button onClick={onToggle} style={iconBtn}>{expanded ? <ChevronDown size={15} /> : <ChevronRight size={15} />}</button></td>
        <td style={{ padding: '10px 14px' }}>
          <div style={{ fontWeight: 700, color: 'var(--text-h)' }}>{r.name}</div>
          <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{r.worker_code}</div>
        </td>
        <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>{r.vendor || '—'}</td>
        <td style={{ padding: '10px 14px' }}>
          {r.authorized
            ? <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '3px 10px', borderRadius: 999, background: 'rgba(16,185,129,0.14)', color: '#10b981', fontSize: 11.5, fontWeight: 700 }}><ShieldCheck size={13} /> Authorized</span>
            : <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '3px 10px', borderRadius: 999, background: 'rgba(239,68,68,0.14)', color: '#ef4444', fontSize: 11.5, fontWeight: 700 }}><ShieldAlert size={13} /> Not authorized</span>}
        </td>
        <td style={{ padding: '10px 14px', color: 'var(--text-muted)', fontSize: 12 }}>{(r.blockers || []).join(' · ') || '—'}</td>
      </tr>
      {expanded && (
        <tr><td colSpan={5} style={{ padding: '0 14px 14px', background: 'var(--bg-input,rgba(124,58,237,0.03))' }}>
          {!detail ? <div style={{ padding: 12, color: 'var(--text-muted)', fontSize: 12.5 }}>Loading checks…</div>
            : (
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(240px,1fr))', gap: 8, paddingTop: 12 }}>
                {(detail.checks || []).map(c => (
                  <div key={c.key} style={{ display: 'flex', alignItems: 'flex-start', gap: 9, padding: '9px 11px', borderRadius: 10, background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
                    <span style={{ marginTop: 1, flexShrink: 0 }}>
                      {c.passed ? <Check size={15} style={{ color: '#10b981' }} /> : c.required ? <X size={15} style={{ color: '#ef4444' }} /> : <Minus size={15} style={{ color: '#94a3b8' }} />}
                    </span>
                    <div>
                      <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>{c.label}{!c.required && <span style={{ fontWeight: 400, color: 'var(--text-muted)', fontSize: 10.5 }}> · advisory</span>}</div>
                      <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{c.detail}</div>
                    </div>
                  </div>
                ))}
              </div>
            )}
        </td></tr>
      )}
    </>
  )
}

const btnGhost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }
const iconBtn = { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', padding: 6, borderRadius: 8, border: 'none', background: 'transparent', color: 'var(--text-muted)', cursor: 'pointer' }
