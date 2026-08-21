import { useState, useEffect, useCallback } from 'react'
import { LogOut, Plus, RefreshCw, X, ChevronDown, ChevronRight, Trash2, CheckCircle2, Circle } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { useAuth } from '@/context/AuthContext'
import { canApproveTpv } from '../constants'
import { KIT3D_STYLE as TPV_STYLE } from '@/components/ui/kit3d'

// Sangoe TPV §29 — Offboarding / Closure. A controlled exit checklist; completion
// applies the final status (Closed / Replaced / Suspended / Blacklisted).
const STATUS_TONE = { In_Progress: '#0ea5e9', Completed: '#10b981' }
const FINAL_TONE = { Closed: '#6b7280', Replaced: '#f97316', Suspended: '#f59e0b', Blacklisted: '#ef4444' }
const fmt = (s) => String(s || '').replace(/_/g, ' ')

export default function TpvOffboarding() {
  const { user } = useAuth()
  const admin = canApproveTpv(user)
  const [rows, setRows] = useState(null)
  const [finals, setFinals] = useState([])
  const [vendors, setVendors] = useState([])
  const [expanded, setExpanded] = useState(null)
  const [initModal, setInitModal] = useState(false)

  const load = useCallback(() => {
    tpvApi.offboardings.list().then(d => { setRows(d?.data ?? []); setFinals(d?.final_statuses ?? []) }).catch(() => setRows([]))
  }, [])
  useEffect(() => { load() }, [load])
  useEffect(() => { tpvApi.vendors.list().then(d => setVendors(Array.isArray(d) ? d : (d?.data ?? []))).catch(() => setVendors([])) }, [])

  const remove = async (id) => { if (window.confirm('Delete this offboarding?')) { await tpvApi.offboardings.delete(id); load() } }

  return (
    <div style={{ padding: 4 }}>
      <style>{TPV_STYLE}</style>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>PERFORMANCE</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0' }}>Offboarding &amp; Closure</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>A controlled exit — work the checklist, then close, replace, suspend or blacklist.</p>
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
              {rows === null ? <tr><td colSpan={7} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
                : rows.length === 0 ? <tr><td colSpan={7} style={{ padding: 18, color: 'var(--text-muted)' }}>No offboardings.</td></tr>
                : rows.map(o => (
                  <OffRow key={o.id} o={o} finals={finals} admin={admin} expanded={expanded === o.id}
                    onToggle={() => setExpanded(expanded === o.id ? null : o.id)} onDelete={() => remove(o.id)} onChanged={load} />
                ))}
            </tbody>
          </table>
        </div>
      </div>

      {initModal && <InitiateModal vendors={vendors} onClose={() => setInitModal(false)} onSaved={() => { setInitModal(false); load() }} />}
    </div>
  )
}

function OffRow({ o, finals, admin, expanded, onToggle, onDelete, onChanged }) {
  return (
    <>
      <tr style={{ borderTop: '1px solid var(--border)' }}>
        <td style={{ padding: '10px 14px' }}><button onClick={onToggle} style={iconBtn}>{expanded ? <ChevronDown size={15} /> : <ChevronRight size={15} />}</button></td>
        <td style={{ padding: '10px 14px', fontWeight: 700, color: '#a78bfa' }}>{o.reference}</td>
        <td style={{ padding: '10px 14px', color: 'var(--text-h)' }}>{o.vendor?.company_name || '—'}</td>
        <td style={{ padding: '10px 14px', width: 160 }}>
          <div className="pr-bar" style={{ height: 8 }}>
            <span style={{ width: `${o.progress ?? 0}%`, background: 'linear-gradient(90deg,#8b5cf6bb,#7C3AED)' }} />
          </div>
          <div style={{ fontSize: 10.5, color: 'var(--text-muted)', marginTop: 3 }}>{o.progress ?? 0}%</div>
        </td>
        <td style={{ padding: '10px 14px' }}><Pill tone={STATUS_TONE[o.status]} text={fmt(o.status)} /></td>
        <td style={{ padding: '10px 14px' }}>{o.final_status ? <Pill tone={FINAL_TONE[o.final_status]} text={o.final_status} /> : <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>—</span>}</td>
        <td style={{ padding: '10px 14px' }}><button onClick={onDelete} style={iconBtn}><Trash2 size={14} /></button></td>
      </tr>
      {expanded && (
        <tr><td colSpan={7} style={{ padding: '0 14px 14px', background: 'var(--bg-input,rgba(124,58,237,0.03))' }}>
          <ChecklistPanel offboarding={o} finals={finals} admin={admin} onChanged={onChanged} />
        </td></tr>
      )}
    </>
  )
}

function ChecklistPanel({ offboarding, finals, admin, onChanged }) {
  const [detail, setDetail] = useState(null)
  const load = useCallback(() => { tpvApi.offboardings.get(offboarding.id).then(setDetail).catch(() => setDetail(null)) }, [offboarding.id])
  useEffect(load, [load])
  const [finalStatus, setFinalStatus] = useState(finals[0] || 'Closed')
  const [lessons, setLessons] = useState('')
  const done = offboarding.status === 'Completed'

  const toggle = async (idx) => {
    const cl = detail.checklist.map((it, i) => i === idx ? { ...it, done: !it.done } : it)
    setDetail({ ...detail, checklist: cl })
    await tpvApi.offboardings.updateChecklist(offboarding.id, cl); onChanged?.()
  }
  const complete = async () => {
    if (!window.confirm(`Complete offboarding as "${finalStatus}"? This changes the vendor status.`)) return
    try { await tpvApi.offboardings.complete(offboarding.id, { final_status: finalStatus, lessons_learned: lessons || null }); onChanged?.(); load() }
    catch (e) { alert(e?.response?.data?.message || 'Could not complete.') }
  }

  if (!detail) return <div style={{ padding: 12, color: 'var(--text-muted)', fontSize: 12.5 }}>Loading checklist…</div>
  const allDone = (detail.checklist || []).every(i => i.done)

  return (
    <div style={{ paddingTop: 12 }}>
      {offboarding.reason && <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '0 0 10px' }}>Reason: {offboarding.reason}</p>}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(230px,1fr))', gap: 6, marginBottom: 12 }}>
        {(detail.checklist || []).map((it, i) => (
          <button key={it.key} disabled={done} onClick={() => toggle(i)}
            style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 10px', borderRadius: 9, background: 'var(--bg-card)', border: '1px solid var(--border)', cursor: done ? 'default' : 'pointer', textAlign: 'left' }}>
            {it.done ? <CheckCircle2 size={16} style={{ color: '#10b981', flexShrink: 0 }} /> : <Circle size={16} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />}
            <span style={{ fontSize: 12.5, color: 'var(--text-h)', textDecoration: it.done ? 'line-through' : 'none', opacity: it.done ? 0.7 : 1 }}>{it.label}</span>
          </button>
        ))}
      </div>
      {done
        ? <div style={{ fontSize: 12.5, color: '#10b981', fontWeight: 700 }}>Completed as {detail.final_status}{detail.lessons_learned ? ` · Lessons: ${detail.lessons_learned}` : ''}</div>
        : admin && (
          <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
            <select value={finalStatus} onChange={e => setFinalStatus(e.target.value)} style={{ ...inp, width: 'auto' }}>
              {finals.map(f => <option key={f} value={f}>{f}</option>)}
            </select>
            <input value={lessons} onChange={e => setLessons(e.target.value)} placeholder="Lessons learned (optional)" style={{ ...inp, flex: 1, minWidth: 200 }} />
            <button onClick={complete} disabled={!allDone} style={{ ...btnPrimary, opacity: allDone ? 1 : 0.5 }} title={allDone ? '' : 'Complete all checklist items first'}>
              <LogOut size={14} /> Complete Offboarding
            </button>
          </div>
        )}
    </div>
  )
}

function Pill({ tone, text }) {
  const c = tone || '#94a3b8'
  return <span style={{ display: 'inline-block', padding: '3px 9px', borderRadius: 999, background: `${c}1f`, color: c, fontSize: 11, fontWeight: 700 }}>{text}</span>
}

function InitiateModal({ vendors, onClose, onSaved }) {
  const [form, setForm] = useState({ vendor_id: '', reason: '' })
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)
  const set = (k) => (e) => setForm(p => ({ ...p, [k]: e.target.value }))

  const save = async () => {
    setSaving(true); setErr(null)
    try { await tpvApi.offboardings.initiate({ vendor_id: form.vendor_id, reason: form.reason || null }); onSaved() }
    catch (e) { setErr(e?.response?.data?.message || 'Could not start.') } finally { setSaving(false) }
  }

  return (
    <div style={overlay} onClick={onClose}>
      <div className="pr-glass" style={sheet} onClick={e => e.stopPropagation()}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
          <h2 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>Start Offboarding</h2>
          <button onClick={onClose} style={iconBtn}><X size={18} /></button>
        </div>
        <label style={{ ...lbl, marginBottom: 10 }}>Vendor *
          <select value={form.vendor_id} onChange={set('vendor_id')} style={inp}>
            <option value="">Select vendor…</option>
            {vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}</option>)}
          </select>
        </label>
        <label style={lbl}>Reason
          <textarea value={form.reason} onChange={set('reason')} rows={3} style={{ ...inp, resize: 'vertical' }} />
        </label>
        {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '10px 0 0' }}>{err}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
          <button onClick={onClose} style={btnGhost}>Cancel</button>
          <button onClick={save} disabled={saving || !form.vendor_id} style={{ ...btnPrimary, opacity: (saving || !form.vendor_id) ? 0.6 : 1 }}>{saving ? 'Starting…' : 'Start'}</button>
        </div>
      </div>
    </div>
  )
}

const btnPrimary = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: 'none', background: 'linear-gradient(145deg,#8b5cf6,#7C3AED)', color: '#fff', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const btnGhost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }
const iconBtn = { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', padding: 6, borderRadius: 8, border: 'none', background: 'transparent', color: 'var(--text-muted)', cursor: 'pointer' }
const overlay = { position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '5vh 16px', zIndex: 50, overflowY: 'auto' }
const sheet = { width: '100%', maxWidth: 520, padding: 22, borderRadius: 16 }
const lbl = { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 11.5, fontWeight: 600, color: 'var(--text-muted)' }
const inp = { width: '100%', padding: '7px 9px', borderRadius: 8, fontSize: 12.5, background: 'var(--bg-input,var(--bg-card))', color: 'var(--text-h)', border: '1px solid var(--border)' }
