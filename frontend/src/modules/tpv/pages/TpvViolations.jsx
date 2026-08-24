import { useState, useEffect, useCallback } from 'react'
import { AlertOctagon, Plus, RefreshCw, X, Trash2, Ban, PauseCircle, Check } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import LoadError from '@/components/ui/LoadError'
import { useAuth } from '@/context/AuthContext'
import { canApproveTpv } from '../constants'
import { KIT3D_STYLE as TPV_STYLE } from '@/components/ui/kit3d'

// Sangoe TPV §26 — Vendor Violations & Strike escalation. Warning → Strike 1/2/3
// → Suspension → Blacklist, from cumulative open-violation points.
const LADDER = ['None', 'Warning', 'Strike_1', 'Strike_2', 'Strike_3', 'Suspension', 'Blacklist']
const LEVEL_TONE = {
  None: '#94a3b8', Warning: '#f59e0b', Strike_1: '#f97316', Strike_2: '#ef4444',
  Strike_3: '#dc2626', Suspension: '#b91c1c', Blacklist: '#7f1d1d',
}
const SEV_TONE = { Minor: '#0ea5e9', Major: '#f59e0b', Critical: '#ef4444' }
const fmt = (s) => String(s || '').replace(/_/g, ' ')

export default function TpvViolations() {
  const { user } = useAuth()
  const admin = canApproveTpv(user)
  const [rows, setRows] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [esc, setEsc] = useState([])
  const [meta, setMeta] = useState({ types: [], severities: [] })
  const [vendors, setVendors] = useState([])
  const [modal, setModal] = useState(false)

  const load = useCallback(() => {
    tpvApi.violations.list().then(d => { setLoadError(null);
      setRows(d?.data ?? []); setEsc(d?.escalations ?? [])
      setMeta({ types: d?.types ?? [], severities: d?.severities ?? [] })
    }).catch(e => { setRows([]); setLoadError(e) })
  }, [])
  useEffect(() => { load() }, [load])
  useEffect(() => { tpvApi.vendors.list().then(d => setVendors(Array.isArray(d) ? d : (d?.data ?? []))).catch(() => setVendors([])) }, [])

  const remove = async (id) => { if (window.confirm('Delete this violation?')) { await tpvApi.violations.delete(id); load() } }
  const closeV = async (v) => { await tpvApi.violations.update(v.id, { status: 'Closed' }); load() }
  const enforce = async (row, action) => {
    if (!window.confirm(`${action === 'blacklist' ? 'Blacklist' : 'Suspend'} ${row.vendor}? This changes the vendor's status.`)) return
    const reason = window.prompt('Reason?') || undefined
    try { await tpvApi.violations.enforce(row.vendor_id, { action, reason }); load() }
    catch (e) { alert(e?.response?.data?.message || 'Could not enforce.') }
  }

  return (
    <div style={{ padding: 4 }}>
      <style>{TPV_STYLE}</style>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>COMPLIANCE</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0' }}>Violations &amp; Strikes</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Warning → Strike 1/2/3 → Suspension → Blacklist, from accumulated violations.</p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button onClick={load} style={btnGhost}><RefreshCw size={14} /> Refresh</button>
          <button onClick={() => setModal(true)} style={btnPrimary}><Plus size={15} /> Record Violation</button>
        </div>
      </div>

      {/* Escalation ladder per vendor */}
      {esc.length > 0 && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(300px,1fr))', gap: 12, marginBottom: 18 }}>
          {esc.map(e => (
            <div key={e.vendor_id} className="pr-glass" style={{ padding: 14, borderRadius: 13, outline: (e.level === 'Blacklist' || e.level === 'Suspension') ? `1.5px solid ${LEVEL_TONE[e.level]}66` : 'none' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                <div style={{ fontWeight: 700, color: 'var(--text-h)', fontSize: 13 }}>{e.vendor}</div>
                <span style={{ padding: '2px 9px', borderRadius: 999, background: `${LEVEL_TONE[e.level]}1f`, color: LEVEL_TONE[e.level], fontSize: 11, fontWeight: 800 }}>{e.level_label}</span>
              </div>
              {/* Ladder */}
              <div style={{ display: 'flex', gap: 3, marginBottom: 8 }}>
                {LADDER.slice(1).map(step => {
                  const reached = LADDER.indexOf(step) <= LADDER.indexOf(e.level)
                  return <div key={step} title={fmt(step)} style={{ flex: 1, height: 6, borderRadius: 3, background: reached ? LEVEL_TONE[step] : 'var(--border)' }} />
                })}
              </div>
              <div style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{e.open_count} open · {e.open_points} points · status {fmt(e.vendor_status)}</div>
              {admin && (e.level === 'Suspension' || e.level === 'Blacklist') && e.vendor_status !== 'Blacklisted' && (
                <div style={{ display: 'flex', gap: 6, marginTop: 10 }}>
                  {e.vendor_status !== 'Suspended' && <button onClick={() => enforce(e, 'suspend')} style={{ ...miniBtn, color: '#f59e0b' }}><PauseCircle size={13} /> Suspend</button>}
                  <button onClick={() => enforce(e, 'blacklist')} style={{ ...miniBtn, color: '#ef4444' }}><Ban size={13} /> Blacklist</button>
                </div>
              )}
            </div>
          ))}
        </div>
      )}

      <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                {['Reference', 'Vendor', 'Type', 'Severity', 'Points', 'Status', ''].map(h => <th key={h} style={{ padding: '11px 14px' }}>{h}</th>)}
              </tr>
            </thead>
            <tbody>
              {loadError ? <tr><td colSpan={7} style={{ padding: 8 }}><LoadError error={loadError} onRetry={load} /></td></tr>
                : rows === null ? <tr><td colSpan={7} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
                : rows.length === 0 ? <tr><td colSpan={7} style={{ padding: 18, color: 'var(--text-muted)' }}>No violations recorded.</td></tr>
                : rows.map(v => (
                  <tr key={v.id} style={{ borderTop: '1px solid var(--border)' }}>
                    <td style={{ padding: '10px 14px', fontWeight: 700, color: '#a78bfa' }}>{v.reference}</td>
                    <td style={{ padding: '10px 14px', color: 'var(--text-h)' }}>{v.vendor?.company_name || '—'}</td>
                    <td style={{ padding: '10px 14px', color: 'var(--text-h)' }}>{v.type_label}</td>
                    <td style={{ padding: '10px 14px' }}><Pill tone={SEV_TONE[v.severity]} text={v.severity} /></td>
                    <td style={{ padding: '10px 14px', color: 'var(--text-muted)', fontVariantNumeric: 'tabular-nums' }}>{v.points}</td>
                    <td style={{ padding: '10px 14px' }}><Pill tone={v.status === 'Open' ? '#f59e0b' : '#6b7280'} text={v.status} /></td>
                    <td style={{ padding: '10px 14px', whiteSpace: 'nowrap' }}>
                      {v.status === 'Open' && <button onClick={() => closeV(v)} style={{ ...miniBtn, color: '#10b981' }}><Check size={13} /> Close</button>}
                      <button onClick={() => remove(v.id)} style={iconBtn}><Trash2 size={14} /></button>
                    </td>
                  </tr>
                ))}
            </tbody>
          </table>
        </div>
      </div>

      {modal && <RecordModal types={meta.types} severities={meta.severities} vendors={vendors} onClose={() => setModal(false)} onSaved={() => { setModal(false); load() }} />}
    </div>
  )
}

function Pill({ tone, text }) {
  const c = tone || '#94a3b8'
  return <span style={{ display: 'inline-block', padding: '3px 9px', borderRadius: 999, background: `${c}1f`, color: c, fontSize: 11, fontWeight: 700 }}>{text}</span>
}

function RecordModal({ types, severities, vendors, onClose, onSaved }) {
  const [form, setForm] = useState({ vendor_id: '', type: types[0] || 'PPE_Violation', severity: 'Minor', occurred_at: '', description: '' })
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)
  const set = (k) => (e) => setForm(p => ({ ...p, [k]: e.target.value }))

  const save = async () => {
    setSaving(true); setErr(null)
    try {
      const payload = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''))
      await tpvApi.violations.record(payload); onSaved()
    } catch (e) { setErr(e?.response?.data?.message || 'Could not save.') } finally { setSaving(false) }
  }

  return (
    <div style={overlay} onClick={onClose}>
      <div className="pr-glass" style={sheet} onClick={e => e.stopPropagation()}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
          <h2 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>Record Violation</h2>
          <button onClick={onClose} style={iconBtn}><X size={18} /></button>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2,1fr)', gap: 10 }}>
          <label style={lbl}>Vendor *
            <select value={form.vendor_id} onChange={set('vendor_id')} style={inp}>
              <option value="">Select vendor…</option>
              {vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}</option>)}
            </select>
          </label>
          <label style={lbl}>Type *
            <select value={form.type} onChange={set('type')} style={inp}>
              {(types.length ? types : ['PPE_Violation']).map(t => <option key={t} value={t}>{fmt(t)}</option>)}
            </select>
          </label>
          <label style={lbl}>Severity
            <select value={form.severity} onChange={set('severity')} style={inp}>
              {(severities.length ? severities : ['Minor', 'Major', 'Critical']).map(s => <option key={s} value={s}>{s}</option>)}
            </select>
          </label>
          <label style={lbl}>Occurred on
            <input type="date" value={form.occurred_at} onChange={set('occurred_at')} style={inp} />
          </label>
          <label style={{ ...lbl, gridColumn: '1 / -1' }}>Description
            <textarea value={form.description} onChange={set('description')} rows={3} style={{ ...inp, resize: 'vertical' }} />
          </label>
        </div>
        {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '10px 0 0' }}>{err}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
          <button onClick={onClose} style={btnGhost}>Cancel</button>
          <button onClick={save} disabled={saving || !form.vendor_id} style={{ ...btnPrimary, opacity: (saving || !form.vendor_id) ? 0.6 : 1 }}>{saving ? 'Saving…' : 'Record'}</button>
        </div>
      </div>
    </div>
  )
}

const btnPrimary = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: 'none', background: 'linear-gradient(145deg,#8b5cf6,#7C3AED)', color: '#fff', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const btnGhost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }
const miniBtn = { display: 'inline-flex', alignItems: 'center', gap: 4, padding: '5px 10px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-card)', cursor: 'pointer', fontSize: 11.5, fontWeight: 700, marginRight: 4 }
const iconBtn = { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', padding: 6, borderRadius: 8, border: 'none', background: 'transparent', color: 'var(--text-muted)', cursor: 'pointer' }
const overlay = { position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '5vh 16px', zIndex: 50, overflowY: 'auto' }
const sheet = { width: '100%', maxWidth: 560, padding: 22, borderRadius: 16 }
const lbl = { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 11.5, fontWeight: 600, color: 'var(--text-muted)' }
const inp = { width: '100%', padding: '7px 9px', borderRadius: 8, fontSize: 12.5, background: 'var(--bg-input,var(--bg-card))', color: 'var(--text-h)', border: '1px solid var(--border)' }
