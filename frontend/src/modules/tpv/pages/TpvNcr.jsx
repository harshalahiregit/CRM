import { useState, useEffect, useCallback } from 'react'
import { FileWarning, Plus, RefreshCw, X, Pencil, Trash2, ArrowRight } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { useAuth } from '@/context/AuthContext'
import { canApproveTpv } from '../constants'
import { KIT3D_STYLE as TPV_STYLE } from '@/components/ui/kit3d'

// Sangoe TPV §24 — Non-Conformance Reports. Raised → Assigned → Response →
// Corrective Action → Verification → Closed.
const SEV_TONE = { Minor: '#0ea5e9', Major: '#f59e0b', Critical: '#ef4444' }
const STATUS_TONE = {
  Raised: '#f59e0b', Assigned: '#0ea5e9', Response: '#8b5cf6',
  Corrective_Action: '#8b5cf6', Verification: '#22c55e', Closed: '#6b7280',
}
const fmt = (s) => String(s || '').replace(/_/g, ' ')
const date = (d) => (d ? new Date(d).toLocaleDateString() : '—')

export default function TpvNcr() {
  const { user } = useAuth()
  const admin = canApproveTpv(user)
  const [rows, setRows] = useState(null)
  const [meta, setMeta] = useState({ severities: [], statuses: [] })
  const [vendors, setVendors] = useState([])
  const [statusF, setStatusF] = useState('')
  const [modal, setModal] = useState(null)

  const load = useCallback(() => {
    tpvApi.ncrs.list(statusF ? { status: statusF } : {})
      .then(d => { setRows(d?.data ?? []); setMeta({ severities: d?.severities ?? [], statuses: d?.statuses ?? [] }) })
      .catch(() => setRows([]))
  }, [statusF])
  useEffect(() => { load() }, [load])
  useEffect(() => { tpvApi.vendors.list().then(d => setVendors(Array.isArray(d) ? d : (d?.data ?? []))).catch(() => setVendors([])) }, [])

  const advance = async (row, status) => {
    try { await tpvApi.ncrs.transition(row.id, { status }); load() }
    catch (e) { alert(e?.response?.data?.message || 'Could not update.') }
  }
  const remove = async (id) => { if (window.confirm('Delete this NCR?')) { await tpvApi.ncrs.delete(id); load() } }

  return (
    <div style={{ padding: 4 }}>
      <style>{TPV_STYLE}</style>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>COMPLIANCE</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0' }}>Non-Conformance Reports</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Raised → Assigned → Response → Corrective Action → Verification → Closed.</p>
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <select value={statusF} onChange={e => setStatusF(e.target.value)} style={inp}>
            <option value="">All statuses</option>
            {(meta.statuses || []).map(s => <option key={s} value={s}>{fmt(s)}</option>)}
          </select>
          <button onClick={load} style={btnGhost}><RefreshCw size={14} /> Refresh</button>
          <button onClick={() => setModal({})} style={btnPrimary}><Plus size={15} /> Raise NCR</button>
        </div>
      </div>

      <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                {['Reference', 'Title', 'Vendor', 'Severity', 'Due', 'Status', ''].map(h => <th key={h} style={{ padding: '11px 14px' }}>{h}</th>)}
              </tr>
            </thead>
            <tbody>
              {rows === null ? <tr><td colSpan={7} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
                : rows.length === 0 ? <tr><td colSpan={7} style={{ padding: 18, color: 'var(--text-muted)' }}>No NCRs.</td></tr>
                : rows.map(n => {
                  const idx = (meta.statuses || []).indexOf(n.status)
                  const next = idx >= 0 && idx < (meta.statuses.length - 1) ? meta.statuses[idx + 1] : null
                  return (
                    <tr key={n.id} style={{ borderTop: '1px solid var(--border)' }}>
                      <td style={{ padding: '10px 14px', fontWeight: 700, color: '#a78bfa' }}>{n.reference}</td>
                      <td style={{ padding: '10px 14px', color: 'var(--text-h)' }}>{n.title}</td>
                      <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>{n.vendor?.company_name || '—'}</td>
                      <td style={{ padding: '10px 14px' }}><Pill tone={SEV_TONE[n.severity]} text={n.severity} /></td>
                      <td style={{ padding: '10px 14px', color: n.is_overdue ? '#ef4444' : 'var(--text-muted)', fontSize: 12, fontWeight: n.is_overdue ? 700 : 400 }}>{date(n.due_date)}{n.is_overdue ? ' · overdue' : ''}</td>
                      <td style={{ padding: '10px 14px' }}><Pill tone={STATUS_TONE[n.status]} text={fmt(n.status)} /></td>
                      <td style={{ padding: '10px 14px', whiteSpace: 'nowrap' }}>
                        {admin && next && (
                          <button onClick={() => advance(n, next)} style={{ ...miniBtn }} title={`Advance to ${fmt(next)}`}>{fmt(next)} <ArrowRight size={12} /></button>
                        )}
                        <button onClick={() => setModal(n)} style={iconBtn} title="Edit"><Pencil size={14} /></button>
                        <button onClick={() => remove(n.id)} style={iconBtn} title="Delete"><Trash2 size={14} /></button>
                      </td>
                    </tr>
                  )
                })}
            </tbody>
          </table>
        </div>
      </div>

      {modal && <NcrModal row={modal.id ? modal : null} severities={meta.severities} vendors={vendors} onClose={() => setModal(null)} onSaved={() => { setModal(null); load() }} />}
    </div>
  )
}

function Pill({ tone, text }) {
  const c = tone || '#94a3b8'
  return <span style={{ display: 'inline-block', padding: '3px 9px', borderRadius: 999, background: `${c}1f`, color: c, fontSize: 11, fontWeight: 700 }}>{text}</span>
}

function NcrModal({ row, severities, vendors, onClose, onSaved }) {
  const fields = [
    { k: 'title', label: 'Title', req: true },
    { k: 'requirement', label: 'Requirement breached', area: true },
    { k: 'finding', label: 'Finding (non-conformance)', area: true },
    { k: 'response', label: 'Vendor response', area: true },
    { k: 'corrective_action', label: 'Corrective action', area: true },
    { k: 'notes', label: 'Notes', area: true },
  ]
  const seed = () => {
    const b = { vendor_id: row?.vendor_id || '', severity: row?.severity || 'Major', due_date: row?.due_date ? String(row.due_date).slice(0, 10) : '' }
    fields.forEach(f => { b[f.k] = row?.[f.k] ?? '' })
    return b
  }
  const [form, setForm] = useState(seed)
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)
  const set = (k) => (e) => setForm(p => ({ ...p, [k]: e.target.value }))

  const save = async () => {
    setSaving(true); setErr(null)
    try {
      const payload = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v !== null))
      if (row) await tpvApi.ncrs.update(row.id, payload)
      else await tpvApi.ncrs.create(payload)
      onSaved()
    } catch (e) { setErr(e?.response?.data?.message || 'Could not save.') } finally { setSaving(false) }
  }

  return (
    <div style={overlay} onClick={onClose}>
      <div className="pr-glass" style={sheet} onClick={e => e.stopPropagation()}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
          <h2 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>{row ? `Edit ${row.reference}` : 'Raise NCR'}</h2>
          <button onClick={onClose} style={iconBtn}><X size={18} /></button>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2,1fr)', gap: 10 }}>
          <label style={lbl}>Vendor
            <select value={form.vendor_id} onChange={set('vendor_id')} style={inp}>
              <option value="">None</option>
              {vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}</option>)}
            </select>
          </label>
          <label style={lbl}>Severity
            <select value={form.severity} onChange={set('severity')} style={inp}>
              {(severities.length ? severities : ['Minor', 'Major', 'Critical']).map(s => <option key={s} value={s}>{s}</option>)}
            </select>
          </label>
          <label style={lbl}>Due date
            <input type="date" value={form.due_date} onChange={set('due_date')} style={inp} />
          </label>
          <div />
          {fields.map(f => (
            <label key={f.k} style={{ ...lbl, gridColumn: f.area ? '1 / -1' : 'auto' }}>
              {f.label}{f.req ? ' *' : ''}
              {f.area ? <textarea value={form[f.k]} onChange={set(f.k)} rows={2} style={{ ...inp, resize: 'vertical' }} />
                : <input value={form[f.k]} onChange={set(f.k)} style={inp} />}
            </label>
          ))}
        </div>
        {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '10px 0 0' }}>{err}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
          <button onClick={onClose} style={btnGhost}>Cancel</button>
          <button onClick={save} disabled={saving || !form.title} style={{ ...btnPrimary, opacity: (saving || !form.title) ? 0.6 : 1 }}>{saving ? 'Saving…' : row ? 'Save changes' : 'Raise'}</button>
        </div>
      </div>
    </div>
  )
}

const btnPrimary = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: 'none', background: 'linear-gradient(145deg,#8b5cf6,#7C3AED)', color: '#fff', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const btnGhost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }
const miniBtn = { display: 'inline-flex', alignItems: 'center', gap: 4, padding: '5px 10px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-card)', color: '#a78bfa', cursor: 'pointer', fontSize: 11.5, fontWeight: 700, marginRight: 4 }
const iconBtn = { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', padding: 6, borderRadius: 8, border: 'none', background: 'transparent', color: 'var(--text-muted)', cursor: 'pointer' }
const overlay = { position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '5vh 16px', zIndex: 50, overflowY: 'auto' }
const sheet = { width: '100%', maxWidth: 600, padding: 22, borderRadius: 16 }
const lbl = { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 11.5, fontWeight: 600, color: 'var(--text-muted)' }
const inp = { width: '100%', padding: '7px 9px', borderRadius: 8, fontSize: 12.5, background: 'var(--bg-input,var(--bg-card))', color: 'var(--text-h)', border: '1px solid var(--border)' }
