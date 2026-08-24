import { useState, useEffect, useCallback } from 'react'
import { Boxes, Plus, RefreshCw, X, Pencil, Trash2, ChevronDown, ChevronRight, ListTree, Users } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import LoadError from '@/components/ui/LoadError'
import { KIT3D_STYLE as TPV_STYLE } from '@/components/ui/kit3d'

// Sangoe TPV §13 — Work Packages & Activities. The Vendor→Project→WorkPackage→
// Activity→Workforce spine. List + create/edit + expandable activity management.
const WP_STATUSES = ['Planned', 'Active', 'On_Hold', 'Completed', 'Closed']
const ACT_STATUSES = ['Not_Started', 'In_Progress', 'Completed', 'On_Hold']
const TONE = {
  Planned: '#94a3b8', Active: '#10b981', In_Progress: '#0ea5e9', Not_Started: '#94a3b8',
  On_Hold: '#f59e0b', Completed: '#22c55e', Closed: '#6b7280',
}
const date = (d) => (d ? new Date(d).toLocaleDateString() : '—')

export default function TpvWorkPackages() {
  const [rows, setRows] = useState(null)
  const [vendors, setVendors] = useState([])
  const [modal, setModal] = useState(null)
  const [expanded, setExpanded] = useState(null)

  const [loadError, setLoadError] = useState(null)
  const load = useCallback(() => {
      setLoadError(null)
    tpvApi.workPackages.list().then(d => setRows(Array.isArray(d) ? d : [])).catch(e => { setRows([]); setLoadError(e) })
  }, [])
  useEffect(() => {
    load()
    tpvApi.vendors.list().then(d => setVendors(Array.isArray(d) ? d : (d?.data ?? []))).catch(() => setVendors([]))
  }, [load])

  const remove = async (id) => {
    if (!window.confirm('Delete this work package and its activities?')) return
    await tpvApi.workPackages.delete(id); load()
  }

  return (
    <div style={{ padding: 4 }}>
      <style>{TPV_STYLE}</style>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>MOBILISATION</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0' }}>Work Packages</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Vendor → Project → Work Package → Activity → Workforce.</p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button onClick={load} style={btnGhost}><RefreshCw size={14} /> Refresh</button>
          <button onClick={() => setModal({})} style={btnPrimary}><Plus size={15} /> New Work Package</button>
        </div>
      </div>

      <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                {['', 'Reference', 'Vendor', 'Name', 'Activities', 'Workers', 'Period', 'Status', ''].map((h, i) => <th key={i} style={{ padding: '11px 14px' }}>{h}</th>)}
              </tr>
            </thead>
            <tbody>
              {loadError ? <tr><td colSpan={9} style={{ padding: 8 }}><LoadError error={loadError} onRetry={load} /></td></tr>
                : rows === null ? <tr><td colSpan={9} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
                : rows.length === 0 ? <tr><td colSpan={9} style={{ padding: 18, color: 'var(--text-muted)' }}>No work packages yet.</td></tr>
                : rows.map(wp => (
                  <WpRow key={wp.id} wp={wp} expanded={expanded === wp.id}
                    onToggle={() => setExpanded(expanded === wp.id ? null : wp.id)}
                    onEdit={() => setModal(wp)} onDelete={() => remove(wp.id)} onChanged={load} />
                ))}
            </tbody>
          </table>
        </div>
      </div>

      {modal && <WpModal row={modal.id ? modal : null} vendors={vendors} onClose={() => setModal(null)} onSaved={() => { setModal(null); load() }} />}
    </div>
  )
}

function StatusPill({ status }) {
  const tone = TONE[status] || '#94a3b8'
  return <span style={{ display: 'inline-block', padding: '3px 9px', borderRadius: 999, background: `${tone}1f`, color: tone, fontSize: 11, fontWeight: 700 }}>{String(status || '').replace(/_/g, ' ')}</span>
}

function WpRow({ wp, expanded, onToggle, onEdit, onDelete, onChanged }) {
  return (
    <>
      <tr style={{ borderTop: '1px solid var(--border)' }}>
        <td style={{ padding: '10px 14px' }}>
          <button onClick={onToggle} style={iconBtn}>{expanded ? <ChevronDown size={15} /> : <ChevronRight size={15} />}</button>
        </td>
        <td style={{ padding: '10px 14px', fontWeight: 700, color: '#a78bfa' }}>{wp.reference}</td>
        <td style={{ padding: '10px 14px', color: 'var(--text-h)' }}>{wp.vendor?.company_name || '—'}</td>
        <td style={{ padding: '10px 14px', color: 'var(--text-h)' }}>{wp.name}</td>
        <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>{wp.activities_count ?? 0}</td>
        <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>{wp.workers_count ?? 0}</td>
        <td style={{ padding: '10px 14px', color: 'var(--text-muted)', fontSize: 12 }}>{date(wp.start_date)} → {date(wp.end_date)}</td>
        <td style={{ padding: '10px 14px' }}><StatusPill status={wp.status} /></td>
        <td style={{ padding: '10px 14px', whiteSpace: 'nowrap' }}>
          <button onClick={onEdit} style={iconBtn} title="Edit"><Pencil size={14} /></button>
          <button onClick={onDelete} style={iconBtn} title="Delete"><Trash2 size={14} /></button>
        </td>
      </tr>
      {expanded && (
        <tr>
          <td colSpan={9} style={{ padding: '0 14px 14px', background: 'var(--bg-input,rgba(124,58,237,0.03))' }}>
            <ActivitiesPanel wp={wp} onChanged={onChanged} />
          </td>
        </tr>
      )}
    </>
  )
}

function ActivitiesPanel({ wp, onChanged }) {
  const [detail, setDetail] = useState(null)
  const [newAct, setNewAct] = useState({ name: '', required_competency: '', status: 'Not_Started' })
  const [loadError, setLoadError] = useState(null)
  const load = useCallback(() => { setLoadError(null); tpvApi.workPackages.get(wp.id).then(setDetail).catch(() => setDetail({ activities: [], workers: [] })) }, [wp.id])
  useEffect(load, [load])

  const add = async () => {
    if (!newAct.name.trim()) return
    await tpvApi.workPackages.addActivity(wp.id, newAct)
    setNewAct({ name: '', required_competency: '', status: 'Not_Started' })
    load(); onChanged?.()
  }
  const del = async (id) => { await tpvApi.workPackages.deleteActivity(id); load(); onChanged?.() }
  const setStatus = async (a, status) => { await tpvApi.workPackages.updateActivity(a.id, { status }); load() }

  if (!detail) return <div style={{ padding: 12, color: 'var(--text-muted)', fontSize: 12.5 }}>Loading activities…</div>

  return (
    <div style={{ display: 'grid', gridTemplateColumns: '1.6fr 1fr', gap: 16, paddingTop: 12 }}>
      <div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 7, marginBottom: 8 }}>
          <ListTree size={14} style={{ color: '#a78bfa' }} />
          <strong style={{ fontSize: 12.5, color: 'var(--text-h)' }}>Activities</strong>
        </div>
        {(detail.activities || []).length === 0 && <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '0 0 8px' }}>No activities yet.</p>}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 6, marginBottom: 10 }}>
          {(detail.activities || []).map(a => (
            <div key={a.id} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '7px 10px', borderRadius: 9, background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
              <div style={{ flex: 1 }}>
                <div style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--text-h)' }}>{a.name}</div>
                {a.required_competency && <div style={{ fontSize: 10.5, color: 'var(--text-muted)' }}>needs: {a.required_competency}</div>}
              </div>
              <select value={a.status} onChange={e => setStatus(a, e.target.value)} style={{ ...inp, width: 'auto', padding: '4px 6px', fontSize: 11 }}>
                {ACT_STATUSES.map(s => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
              </select>
              <button onClick={() => del(a.id)} style={iconBtn} title="Delete"><Trash2 size={13} /></button>
            </div>
          ))}
        </div>
        <div style={{ display: 'flex', gap: 6 }}>
          <input value={newAct.name} onChange={e => setNewAct(p => ({ ...p, name: e.target.value }))} placeholder="Activity name" style={{ ...inp, flex: 2 }} />
          <input value={newAct.required_competency} onChange={e => setNewAct(p => ({ ...p, required_competency: e.target.value }))} placeholder="Required competency" style={{ ...inp, flex: 2 }} />
          <button onClick={add} style={{ ...btnPrimary, padding: '7px 12px' }}><Plus size={14} /></button>
        </div>
      </div>
      <div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 7, marginBottom: 8 }}>
          <Users size={14} style={{ color: '#a78bfa' }} />
          <strong style={{ fontSize: 12.5, color: 'var(--text-h)' }}>Deployed workers</strong>
        </div>
        {(detail.workers || []).length === 0
          ? <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: 0 }}>No workers assigned to this package yet.</p>
          : (detail.workers || []).map(w => (
            <div key={w.id} style={{ fontSize: 12.5, color: 'var(--text-h)', padding: '5px 0', borderBottom: '1px solid var(--border)' }}>
              {w.name} <span style={{ color: 'var(--text-muted)', fontSize: 11 }}>· {w.worker_code}</span>
            </div>
          ))}
      </div>
    </div>
  )
}

function WpModal({ row, vendors, onClose, onSaved }) {
  const fields = [
    { k: 'name', label: 'Name', req: true },
    { k: 'location', label: 'Location' },
    { k: 'start_date', label: 'Start date', type: 'date' },
    { k: 'end_date', label: 'End date', type: 'date' },
    { k: 'description', label: 'Description', area: true },
    { k: 'scope', label: 'Scope', area: true },
    { k: 'notes', label: 'Notes', area: true },
  ]
  const seed = () => {
    const b = { vendor_id: row?.vendor_id || '', status: row?.status || 'Planned' }
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
      if (row) await tpvApi.workPackages.update(row.id, payload)
      else await tpvApi.workPackages.create(payload)
      onSaved()
    } catch (e) { setErr(e?.response?.data?.message || 'Could not save.') } finally { setSaving(false) }
  }

  return (
    <div style={overlay} onClick={onClose}>
      <div className="pr-glass" style={sheet} onClick={e => e.stopPropagation()}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
          <h2 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>{row ? `Edit ${row.reference}` : 'New Work Package'}</h2>
          <button onClick={onClose} style={iconBtn}><X size={18} /></button>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2,1fr)', gap: 10 }}>
          <label style={lbl}>Vendor *
            <select value={form.vendor_id} onChange={set('vendor_id')} style={inp}>
              <option value="">Select vendor…</option>
              {vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}</option>)}
            </select>
          </label>
          <label style={lbl}>Status
            <select value={form.status} onChange={set('status')} style={inp}>
              {WP_STATUSES.map(s => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
            </select>
          </label>
          {fields.map(f => (
            <label key={f.k} style={{ ...lbl, gridColumn: f.area ? '1 / -1' : 'auto' }}>
              {f.label}{f.req ? ' *' : ''}
              {f.area ? <textarea value={form[f.k]} onChange={set(f.k)} rows={2} style={{ ...inp, resize: 'vertical' }} />
                : <input type={f.type || 'text'} value={form[f.k]} onChange={set(f.k)} style={inp} />}
            </label>
          ))}
        </div>
        {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '10px 0 0' }}>{err}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
          <button onClick={onClose} style={btnGhost}>Cancel</button>
          <button onClick={save} disabled={saving || !form.vendor_id || !form.name} style={{ ...btnPrimary, opacity: (saving || !form.vendor_id || !form.name) ? 0.6 : 1 }}>
            {saving ? 'Saving…' : row ? 'Save changes' : 'Create'}
          </button>
        </div>
      </div>
    </div>
  )
}

const btnPrimary = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: 'none', background: 'linear-gradient(145deg,#8b5cf6,#7C3AED)', color: '#fff', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const btnGhost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }
const iconBtn = { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', padding: 6, borderRadius: 8, border: 'none', background: 'transparent', color: 'var(--text-muted)', cursor: 'pointer' }
const overlay = { position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '5vh 16px', zIndex: 50, overflowY: 'auto' }
const sheet = { width: '100%', maxWidth: 600, padding: 22, borderRadius: 16 }
const lbl = { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 11.5, fontWeight: 600, color: 'var(--text-muted)' }
const inp = { width: '100%', padding: '7px 9px', borderRadius: 8, fontSize: 12.5, background: 'var(--bg-input,var(--bg-card))', color: 'var(--text-h)', border: '1px solid var(--border)' }
