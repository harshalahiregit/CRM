import { useState, useEffect, useCallback } from 'react'
import { Plus, RefreshCw, X, Pencil, Trash2, ArrowRight, Paperclip } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import LoadError from '@/components/ui/LoadError'
import { useAuth } from '@/context/AuthContext'
import { KIT3D_STYLE as PURCHASE_STYLE } from '@/components/ui/kit3d'

// Purchase CAPA register — the Purchase-side mirror of the TPV register (parity).
// Corrective/Preventive actions from any governance source or standalone.
// Open → In_Progress → Done → Verified; no Verified without evidence (Rule 12).
const STATUS_TONE = { Open: '#f59e0b', In_Progress: '#0ea5e9', Done: '#8b5cf6', Verified: '#22c55e' }
const PRIO_TONE = { Low: '#6b7280', Medium: '#0ea5e9', High: '#f59e0b', Critical: '#ef4444' }
const KIND_TONE = { manual: '#6b7280', ncr: '#ef4444', inspection: '#0ea5e9', audit: '#0ea5e9', meeting: '#8b5cf6', renewal: '#22c55e' }
const fmt = (s) => String(s || '').replace(/_/g, ' ')
const date = (d) => (d ? new Date(d).toLocaleDateString() : '—')

export default function PurchaseCapaRegister() {
  const { user } = useAuth()
  const admin = ['admin', 'staff'].includes(user?.role)
  const [rows, setRows] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [meta, setMeta] = useState({ kinds: [], types: [], priorities: [], statuses: [], stats: {} })
  const [vendors, setVendors] = useState([])
  const [statusF, setStatusF] = useState('')
  const [kindF, setKindF] = useState('')
  const [modal, setModal] = useState(null)

  const load = useCallback(() => {
    const params = {}
    if (statusF) params.status = statusF
    if (kindF) params.source_kind = kindF
    purchaseApi.capas.list(params)
      .then(d => { setLoadError(null); setRows(d?.data ?? []); setMeta({ kinds: d?.kinds ?? [], types: d?.types ?? [], priorities: d?.priorities ?? [], statuses: d?.statuses ?? [], stats: d?.stats ?? {} }) })
      .catch(e => { setRows([]); setLoadError(e) })
  }, [statusF, kindF])
  useEffect(() => { load() }, [load])
  useEffect(() => { purchaseApi.vendors.list().then(d => setVendors(Array.isArray(d) ? d : (d?.data ?? []))).catch(() => setVendors([])) }, [])

  const advance = async (row, status) => {
    try { await purchaseApi.capas.transition(row.id, { status }); load() }
    catch (e) { alert(e?.response?.data?.message || 'Could not update.') }
  }
  const remove = async (id) => { if (window.confirm('Delete this CAPA?')) { await purchaseApi.capas.delete(id); load() } }

  const st = meta.stats || {}
  const cards = [
    { k: 'Total', v: st.total ?? 0, c: '#38bdf8' },
    { k: 'Open', v: st.open ?? 0, c: '#f59e0b' },
    { k: 'Done', v: st.done ?? 0, c: '#8b5cf6' },
    { k: 'Verified', v: st.verified ?? 0, c: '#22c55e' },
    { k: 'Overdue', v: st.overdue ?? 0, c: '#ef4444' },
  ]

  return (
    <div style={{ padding: 4 }}>
      <style>{PURCHASE_STYLE}</style>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#38bdf8', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>COMPLIANCE</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0' }}>CAPA Register</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Corrective &amp; Preventive Actions from any source — verified only with evidence.</p>
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
          <select value={kindF} onChange={e => setKindF(e.target.value)} style={inp}>
            <option value="">All sources</option>
            {(meta.kinds || []).map(k => <option key={k} value={k}>{fmt(k)}</option>)}
          </select>
          <select value={statusF} onChange={e => setStatusF(e.target.value)} style={inp}>
            <option value="">All statuses</option>
            {(meta.statuses || []).map(s => <option key={s} value={s}>{fmt(s)}</option>)}
          </select>
          <button onClick={load} style={btnGhost}><RefreshCw size={14} /> Refresh</button>
          <button onClick={() => setModal({})} style={btnPrimary}><Plus size={15} /> Raise CAPA</button>
        </div>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(120px,1fr))', gap: 10, marginBottom: 14 }}>
        {cards.map(c => (
          <div key={c.k} className="pr-glass" style={{ padding: '12px 14px', borderRadius: 12 }}>
            <div style={{ fontSize: 22, fontWeight: 900, color: c.c }}>{c.v}</div>
            <div style={{ fontSize: 11, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>{c.k}</div>
          </div>
        ))}
      </div>

      <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                {['Reference', 'Title', 'Source', 'Type', 'Vendor', 'Priority', 'Due', 'Status', ''].map(h => <th key={h} style={{ padding: '11px 14px' }}>{h}</th>)}
              </tr>
            </thead>
            <tbody>
              {loadError ? <tr><td colSpan={9} style={{ padding: 8 }}><LoadError error={loadError} onRetry={load} /></td></tr>
                : rows === null ? <tr><td colSpan={9} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
                : rows.length === 0 ? <tr><td colSpan={9} style={{ padding: 18, color: 'var(--text-muted)' }}>No CAPAs.</td></tr>
                : rows.map(c => {
                  const idx = (meta.statuses || []).indexOf(c.status)
                  const next = idx >= 0 && idx < (meta.statuses.length - 1) ? meta.statuses[idx + 1] : null
                  const needsEvidence = next === 'Verified' && !c.evidence_path
                  return (
                    <tr key={c.id} style={{ borderTop: '1px solid var(--border)' }}>
                      <td style={{ padding: '10px 14px', fontWeight: 700, color: '#38bdf8' }}>{c.reference}</td>
                      <td style={{ padding: '10px 14px', color: 'var(--text-h)' }}>
                        {c.title}
                        {c.evidence_path && <Paperclip size={12} style={{ marginLeft: 6, color: '#22c55e', verticalAlign: 'middle' }} />}
                      </td>
                      <td style={{ padding: '10px 14px' }}><Pill tone={KIND_TONE[c.source_kind]} text={c.source_label || fmt(c.source_kind)} /></td>
                      <td style={{ padding: '10px 14px', color: 'var(--text-muted)', fontSize: 12 }}>{c.type}</td>
                      <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>{c.vendor?.company_name || '—'}</td>
                      <td style={{ padding: '10px 14px' }}><Pill tone={PRIO_TONE[c.priority]} text={c.priority} /></td>
                      <td style={{ padding: '10px 14px', color: c.is_overdue ? '#ef4444' : 'var(--text-muted)', fontSize: 12, fontWeight: c.is_overdue ? 700 : 400 }}>{date(c.due_date)}{c.is_overdue ? ' · overdue' : ''}</td>
                      <td style={{ padding: '10px 14px' }}><Pill tone={STATUS_TONE[c.status]} text={fmt(c.status)} /></td>
                      <td style={{ padding: '10px 14px', whiteSpace: 'nowrap' }}>
                        {admin && next && (
                          <button onClick={() => advance(c, next)} disabled={needsEvidence} style={{ ...miniBtn, opacity: needsEvidence ? 0.4 : 1, cursor: needsEvidence ? 'not-allowed' : 'pointer' }} title={needsEvidence ? 'Attach evidence before verifying' : `Advance to ${fmt(next)}`}>{fmt(next)} <ArrowRight size={12} /></button>
                        )}
                        <button onClick={() => setModal(c)} style={iconBtn} title="Edit"><Pencil size={14} /></button>
                        <button onClick={() => remove(c.id)} style={iconBtn} title="Delete"><Trash2 size={14} /></button>
                      </td>
                    </tr>
                  )
                })}
            </tbody>
          </table>
        </div>
      </div>

      {modal && <CapaModal row={modal.id ? modal : null} meta={meta} vendors={vendors} onClose={() => setModal(null)} onSaved={() => { setModal(null); load() }} />}
    </div>
  )
}

function Pill({ tone, text }) {
  const c = tone || '#94a3b8'
  return <span style={{ display: 'inline-block', padding: '3px 9px', borderRadius: 999, background: `${c}1f`, color: c, fontSize: 11, fontWeight: 700 }}>{text}</span>
}

function CapaModal({ row, meta, vendors, onClose, onSaved }) {
  const fields = [
    { k: 'title', label: 'Title', req: true },
    { k: 'root_cause', label: 'Root cause', area: true },
    { k: 'action', label: 'Action to take', area: true },
    { k: 'evidence_path', label: 'Closure evidence (path/URL)' },
    { k: 'notes', label: 'Notes', area: true },
  ]
  const seed = () => {
    const b = {
      purchase_vendor_id: row?.purchase_vendor_id || '', source_kind: row?.source_kind || 'manual',
      source_id: row?.source_id || '', type: row?.type || 'Corrective',
      priority: row?.priority || 'Medium', due_date: row?.due_date ? String(row.due_date).slice(0, 10) : '',
    }
    fields.forEach(f => { b[f.k] = row?.[f.k] ?? '' })
    return b
  }
  const [form, setForm] = useState(seed)
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)
  const set = (k) => (e) => setForm(p => ({ ...p, [k]: e.target.value }))
  const linkable = form.source_kind && form.source_kind !== 'manual'

  const save = async () => {
    setSaving(true); setErr(null)
    try {
      const payload = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v !== null))
      if (row) await purchaseApi.capas.update(row.id, payload)
      else await purchaseApi.capas.create(payload)
      onSaved()
    } catch (e) { setErr(e?.response?.data?.message || 'Could not save.') } finally { setSaving(false) }
  }

  return (
    <div style={overlay} onClick={onClose}>
      <div className="pr-glass" style={sheet} onClick={e => e.stopPropagation()}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
          <h2 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>{row ? `Edit ${row.reference}` : 'Raise CAPA'}</h2>
          <button onClick={onClose} style={iconBtn}><X size={18} /></button>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2,1fr)', gap: 10 }}>
          <label style={lbl}>Source
            <select value={form.source_kind} onChange={set('source_kind')} style={inp}>
              {(meta.kinds?.length ? meta.kinds : ['manual']).map(k => <option key={k} value={k}>{fmt(k)}</option>)}
            </select>
          </label>
          <label style={lbl}>Source record ID{linkable ? '' : ' (n/a)'}
            <input value={form.source_id} onChange={set('source_id')} disabled={!linkable} placeholder={linkable ? 'e.g. NCR id' : '—'} style={{ ...inp, opacity: linkable ? 1 : 0.5 }} />
          </label>
          <label style={lbl}>Vendor
            <select value={form.purchase_vendor_id} onChange={set('purchase_vendor_id')} style={inp}>
              <option value="">None</option>
              {vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}</option>)}
            </select>
          </label>
          <label style={lbl}>Type
            <select value={form.type} onChange={set('type')} style={inp}>
              {(meta.types?.length ? meta.types : ['Corrective', 'Preventive']).map(t => <option key={t} value={t}>{t}</option>)}
            </select>
          </label>
          <label style={lbl}>Priority
            <select value={form.priority} onChange={set('priority')} style={inp}>
              {(meta.priorities?.length ? meta.priorities : ['Low', 'Medium', 'High', 'Critical']).map(p => <option key={p} value={p}>{p}</option>)}
            </select>
          </label>
          <label style={lbl}>Due date
            <input type="date" value={form.due_date} onChange={set('due_date')} style={inp} />
          </label>
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

const btnPrimary = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: 'none', background: 'linear-gradient(145deg,#0ea5e9,#0284c7)', color: '#fff', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const btnGhost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }
const miniBtn = { display: 'inline-flex', alignItems: 'center', gap: 4, padding: '5px 10px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-card)', color: '#38bdf8', fontSize: 11.5, fontWeight: 700, marginRight: 4 }
const iconBtn = { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', padding: 6, borderRadius: 8, border: 'none', background: 'transparent', color: 'var(--text-muted)', cursor: 'pointer' }
const overlay = { position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '5vh 16px', zIndex: 50, overflowY: 'auto' }
const sheet = { width: '100%', maxWidth: 600, padding: 22, borderRadius: 16 }
const lbl = { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 11.5, fontWeight: 600, color: 'var(--text-muted)' }
const inp = { width: '100%', padding: '7px 9px', borderRadius: 8, fontSize: 12.5, background: 'var(--bg-input,var(--bg-card))', color: 'var(--text-h)', border: '1px solid var(--border)' }
