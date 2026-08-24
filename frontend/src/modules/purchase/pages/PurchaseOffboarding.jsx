import { useState, useEffect, useCallback } from 'react'
import { LogOut, Plus, RefreshCw, X, Trash2, ChevronDown, ChevronRight, CheckCircle2 } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import LoadError from '@/components/ui/LoadError'
import { useAuth } from '@/context/AuthContext'
import { KIT3D_STYLE as PURCHASE_STYLE } from '@/components/ui/kit3d'

// Purchase Offboarding / Closure — the Purchase-side mirror of the TPV engine
// (parity). A controlled exit checklist that, once every item is done, applies a
// final vendor status (Closed / Replaced / Suspended / Blacklisted).
const STATUS_TONE = { In_Progress: '#0ea5e9', Completed: '#22c55e' }
const fmt = (s) => String(s || '').replace(/_/g, ' ')

export default function PurchaseOffboarding() {
  const { user } = useAuth()
  const admin = user?.role === 'admin'
  const [rows, setRows] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [finals, setFinals] = useState([])
  const [vendors, setVendors] = useState([])
  const [expanded, setExpanded] = useState(null)
  const [initModal, setInitModal] = useState(false)

  const load = useCallback(() => {
    purchaseApi.offboardings.list().then(d => { setLoadError(null); setRows(d?.data ?? []); setFinals(d?.final_statuses ?? []) }).catch(e => { setRows([]); setLoadError(e) })
  }, [])
  useEffect(() => { load() }, [load])
  useEffect(() => { purchaseApi.vendors.list().then(d => setVendors(Array.isArray(d) ? d : (d?.data ?? []))).catch(() => setVendors([])) }, [])

  const remove = async (id) => { if (window.confirm('Delete this offboarding?')) { await purchaseApi.offboardings.delete(id); load() } }

  return (
    <div style={{ padding: 4 }}>
      <style>{PURCHASE_STYLE}</style>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#38bdf8', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>PERFORMANCE</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0' }}>Offboarding &amp; Closure</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Work the exit checklist, then close with a final status.</p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button onClick={load} style={btnGhost}><RefreshCw size={14} /> Refresh</button>
          <button onClick={() => setInitModal(true)} style={btnPrimary}><Plus size={15} /> Start Offboarding</button>
        </div>
      </div>

      <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                {['', 'Reference', 'Vendor', 'Progress', 'Status', 'Final', ''].map((h, i) => <th key={i} style={{ padding: '11px 14px' }}>{h}</th>)}
              </tr>
            </thead>
            <tbody>
              {loadError ? <tr><td colSpan={7} style={{ padding: 8 }}><LoadError error={loadError} onRetry={load} /></td></tr>
                : rows === null ? <tr><td colSpan={7} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
                : rows.length === 0 ? <tr><td colSpan={7} style={{ padding: 18, color: 'var(--text-muted)' }}>No offboardings.</td></tr>
                : rows.map(o => (
                  <OffRow key={o.id} o={o} finals={finals} admin={admin} expanded={expanded === o.id}
                    onToggle={() => setExpanded(expanded === o.id ? null : o.id)} onDelete={() => remove(o.id)} onChanged={load} />
                ))}
            </tbody>
          </table>
        </div>
      </div>

      {initModal && <InitModal vendors={vendors} onClose={() => setInitModal(false)} onSaved={() => { setInitModal(false); load() }} />}
    </div>
  )
}

function Pill({ tone, text }) {
  const c = tone || '#94a3b8'
  return <span style={{ display: 'inline-block', padding: '3px 9px', borderRadius: 999, background: `${c}1f`, color: c, fontSize: 11, fontWeight: 700 }}>{text}</span>
}

function OffRow({ o, finals, admin, expanded, onToggle, onDelete, onChanged }) {
  const tone = o.progress >= 100 ? '#22c55e' : o.progress >= 50 ? '#f59e0b' : '#ef4444'
  return (
    <>
      <tr style={{ borderTop: '1px solid var(--border)' }}>
        <td style={{ padding: '10px 14px' }}><button onClick={onToggle} style={iconBtn}>{expanded ? <ChevronDown size={15} /> : <ChevronRight size={15} />}</button></td>
        <td style={{ padding: '10px 14px', fontWeight: 700, color: '#38bdf8' }}>{o.reference}</td>
        <td style={{ padding: '10px 14px', color: 'var(--text-h)' }}>{o.vendor?.company_name || '—'}</td>
        <td style={{ padding: '10px 14px', width: 150 }}>
          <div className="pr-bar" style={{ height: 7 }}><span style={{ width: `${o.progress}%`, background: `linear-gradient(90deg,${tone}bb,${tone})` }} /></div>
          <div style={{ fontSize: 10.5, color: 'var(--text-muted)', marginTop: 3 }}>{o.progress}%</div>
        </td>
        <td style={{ padding: '10px 14px' }}><Pill tone={STATUS_TONE[o.status]} text={fmt(o.status)} /></td>
        <td style={{ padding: '10px 14px', color: 'var(--text-h)' }}>{o.final_status ? fmt(o.final_status) : '—'}</td>
        <td style={{ padding: '10px 14px', whiteSpace: 'nowrap' }}>
          <button onClick={onDelete} style={iconBtn}><Trash2 size={14} /></button>
        </td>
      </tr>
      {expanded && (
        <tr><td colSpan={7} style={{ padding: '0 14px 14px', background: 'var(--bg-input,rgba(56,189,248,0.03))' }}>
          <Checklist offboarding={o} finals={finals} admin={admin} onChanged={onChanged} />
        </td></tr>
      )}
    </>
  )
}

function Checklist({ offboarding, finals, admin, onChanged }) {
  const [items, setItems] = useState(offboarding.checklist || [])
  const [saving, setSaving] = useState(false)
  const [final, setFinal] = useState(finals[0] || 'Closed')
  const [lessons, setLessons] = useState('')
  const done = offboarding.status === 'Completed'

  const toggle = (i) => setItems(items.map((it, idx) => idx === i ? { ...it, done: !it.done } : it))
  const saveChecklist = async () => {
    setSaving(true)
    try { await purchaseApi.offboardings.updateChecklist(offboarding.id, items); onChanged?.() }
    finally { setSaving(false) }
  }
  const complete = async () => {
    if (!window.confirm(`Complete offboarding as "${fmt(final)}"? This changes the vendor's status.`)) return
    try { await purchaseApi.offboardings.complete(offboarding.id, { final_status: final, lessons_learned: lessons || undefined }); onChanged?.() }
    catch (e) { alert(e?.response?.data?.message || 'Could not complete.') }
  }

  const allDone = items.length > 0 && items.every(i => i.done)

  return (
    <div style={{ paddingTop: 12 }}>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(260px,1fr))', gap: 6, marginBottom: 10 }}>
        {items.map((it, i) => (
          <label key={it.key} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 11px', borderRadius: 9, background: 'var(--bg-card)', border: '1px solid var(--border)', cursor: done ? 'default' : 'pointer', fontSize: 12.5 }}>
            <input type="checkbox" checked={!!it.done} disabled={done} onChange={() => toggle(i)} />
            <span style={{ color: it.done ? 'var(--text-muted)' : 'var(--text-h)', textDecoration: it.done ? 'line-through' : 'none' }}>{it.label}</span>
          </label>
        ))}
      </div>
      {!done && (
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
          <button onClick={saveChecklist} disabled={saving} style={btnGhost}>{saving ? 'Saving…' : 'Save checklist'}</button>
          {admin && (
            <>
              <select value={final} onChange={e => setFinal(e.target.value)} style={{ ...inp, width: 'auto' }}>
                {(finals.length ? finals : ['Closed']).map(f => <option key={f} value={f}>{fmt(f)}</option>)}
              </select>
              <input value={lessons} onChange={e => setLessons(e.target.value)} placeholder="Lessons learned (optional)" style={{ ...inp, flex: 1, minWidth: 160 }} />
              <button onClick={complete} disabled={!allDone} style={{ ...btnPrimary, opacity: allDone ? 1 : 0.5, cursor: allDone ? 'pointer' : 'not-allowed' }} title={allDone ? 'Complete offboarding' : 'Finish all checklist items first'}><CheckCircle2 size={14} /> Complete</button>
            </>
          )}
        </div>
      )}
      {done && <p style={{ fontSize: 12, color: '#22c55e', margin: 0 }}>Completed as {fmt(offboarding.final_status)}.</p>}
    </div>
  )
}

function InitModal({ vendors, onClose, onSaved }) {
  const [form, setForm] = useState({ purchase_vendor_id: '', reason: '' })
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)
  const set = (k) => (e) => setForm(p => ({ ...p, [k]: e.target.value }))

  const save = async () => {
    setSaving(true); setErr(null)
    try {
      const payload = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''))
      await purchaseApi.offboardings.initiate(payload); onSaved()
    } catch (e) { setErr(e?.response?.data?.message || 'Could not start.') } finally { setSaving(false) }
  }

  return (
    <div style={overlay} onClick={onClose}>
      <div className="pr-glass" style={sheet} onClick={e => e.stopPropagation()}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
          <h2 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>Start Offboarding</h2>
          <button onClick={onClose} style={iconBtn}><X size={18} /></button>
        </div>
        <label style={lbl}>Vendor *
          <select value={form.purchase_vendor_id} onChange={set('purchase_vendor_id')} style={inp}>
            <option value="">Select vendor…</option>
            {vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}</option>)}
          </select>
        </label>
        <label style={{ ...lbl, marginTop: 10 }}>Reason
          <textarea value={form.reason} onChange={set('reason')} rows={3} style={{ ...inp, resize: 'vertical' }} />
        </label>
        {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '10px 0 0' }}>{err}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
          <button onClick={onClose} style={btnGhost}>Cancel</button>
          <button onClick={save} disabled={saving || !form.purchase_vendor_id} style={{ ...btnPrimary, opacity: (saving || !form.purchase_vendor_id) ? 0.6 : 1 }}>{saving ? 'Starting…' : 'Start'}</button>
        </div>
      </div>
    </div>
  )
}

const btnPrimary = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: 'none', background: 'linear-gradient(145deg,#0ea5e9,#0284c7)', color: '#fff', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const btnGhost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }
const iconBtn = { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', padding: 6, borderRadius: 8, border: 'none', background: 'transparent', color: 'var(--text-muted)', cursor: 'pointer' }
const overlay = { position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '5vh 16px', zIndex: 50, overflowY: 'auto' }
const sheet = { width: '100%', maxWidth: 520, padding: 22, borderRadius: 16 }
const lbl = { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 11.5, fontWeight: 600, color: 'var(--text-muted)' }
const inp = { width: '100%', padding: '7px 9px', borderRadius: 8, fontSize: 12.5, background: 'var(--bg-input,var(--bg-card))', color: 'var(--text-h)', border: '1px solid var(--border)' }
