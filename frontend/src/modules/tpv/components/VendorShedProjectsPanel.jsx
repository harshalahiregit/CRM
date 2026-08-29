import { useState, useEffect, useCallback } from 'react'
import { Warehouse, Plus, Loader2, Inbox, Pencil, Trash2, Ruler } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { Overlay, ModalFooter, Field, TextInput } from '@/components/ui/kit3d'

/**
 * TPV-local vendor↔project engagements (§35) carrying the SHED REQUIREMENT — the
 * business builds industrial sheds, so each vendor project records the shed's
 * spec (site location, size, height, purpose and the yes/no scope items). This is
 * TPV-owned data (tpv_vendor_projects), not the shared Project module.
 */
const STATUS_TONE = { Active: '#16a34a', Completed: '#0891b2', On_Hold: '#d97706', Terminated: '#dc2626' }
const label = (s) => String(s || '').replace(/_/g, ' ')
const yn = (v) => (v === true || v === 1 ? 'Yes' : v === false || v === 0 ? 'No' : '—')
const asDate = (v) => (v ? new Date(v).toLocaleDateString() : '—')

export function VendorShedProjects({ vendorId, vendorName, manage = false, api = tpvApi }) {
  const [rows, setRows] = useState(null)
  const [error, setError] = useState('')
  const [editing, setEditing] = useState(null)   // row being edited, or {} for new

  const load = useCallback(() => {
    setError('')
    api.vendors.shedProjects.list(vendorId)
      .then(d => setRows(d?.data ?? []))
      .catch(e => setError(e?.response?.data?.message || 'Could not load projects.'))
  }, [vendorId, api])

  useEffect(() => { load() }, [load])

  const remove = async (row) => {
    if (!window.confirm(`Remove project “${row.project}” from ${vendorName || 'this vendor'}?`)) return
    try { await api.vendors.shedProjects.remove(vendorId, row.id); load() }
    catch (e) { setError(e?.response?.data?.message || 'Could not remove the project.') }
  }

  return (
    <div className="card-3d" style={{ padding: 18 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 14, gap: 10 }}>
        <h2 style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-h)', margin: 0, display: 'inline-flex', alignItems: 'center', gap: 8 }}>
          <Warehouse size={16} style={{ color: '#a78bfa' }} /> Shed Projects
          {rows && <span style={{ color: 'var(--text-muted)', fontWeight: 600 }}>· {rows.length}</span>}
        </h2>
        {manage && <button onClick={() => setEditing({})} style={primaryBtn}><Plus size={14} /> Add Project</button>}
      </div>

      {error && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '0 0 10px' }}>{error}</p>}

      {rows === null ? (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--text-muted)', padding: '18px 0' }}>
          <Loader2 size={15} className="rfq-spin" /> Loading…
        </div>
      ) : rows.length === 0 ? (
        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 8, padding: '34px 0', color: 'var(--text-muted)' }}>
          <Inbox size={26} style={{ opacity: 0.6 }} />
          <span style={{ fontSize: 13 }}>No shed projects for this vendor yet.</span>
        </div>
      ) : (
        <div style={{ display: 'grid', gap: 12 }}>
          {rows.map(r => (
            <div key={r.id} style={{ border: '1px solid var(--border)', borderRadius: 12, padding: 14 }}>
              <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
                <div style={{ minWidth: 0 }}>
                  <div style={{ color: 'var(--text-h)', fontWeight: 800, fontSize: 14 }}>{r.project}</div>
                  <div style={{ color: 'var(--text-muted)', fontSize: 12, marginTop: 2 }}>
                    {[r.site, r.role, `${asDate(r.start_date)} → ${asDate(r.end_date)}`].filter(Boolean).join(' · ')}
                  </div>
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <span style={{ padding: '2px 9px', borderRadius: 999, fontSize: 11, fontWeight: 700, color: STATUS_TONE[r.status] || '#64748b', background: `${STATUS_TONE[r.status] || '#64748b'}1f` }}>{label(r.status)}</span>
                  {manage && <>
                    <button onClick={() => setEditing(r)} title="Edit" style={iconBtn}><Pencil size={14} /></button>
                    <button onClick={() => remove(r)} title="Remove" style={{ ...iconBtn, color: '#ef4444' }}><Trash2 size={14} /></button>
                  </>}
                </div>
              </div>

              <ShedSummary row={r} />
            </div>
          ))}
        </div>
      )}

      {editing && (
        <ShedProjectModal
          api={api} vendorId={vendorId} vendorName={vendorName} row={editing}
          onClose={() => setEditing(null)}
          onDone={() => { setEditing(null); load() }}
        />
      )}
    </div>
  )
}

function ShedSummary({ row }) {
  const hasSpec = ['shed_site_location', 'shed_length', 'shed_width', 'shed_height', 'shed_purpose',
    'shed_gate_shutter_size'].some(k => row[k] != null && row[k] !== '')
    || [row.shed_side_wall, row.shed_flooring, row.shed_footing_done, row.shed_office_toilet].some(v => v != null)
  if (!hasSpec) return null

  const size = (row.shed_length || row.shed_width)
    ? `${row.shed_length ?? '?'} × ${row.shed_width ?? '?'} m` : null

  const items = [
    ['Site', row.shed_site_location],
    ['Size (L×W)', size],
    ['Height', row.shed_height],
    ['Purpose', row.shed_purpose],
    ['Side wall', row.shed_side_wall != null ? yn(row.shed_side_wall) : null],
    ['Flooring', row.shed_flooring != null ? yn(row.shed_flooring) : null],
    ['Gate/shutter', row.shed_gate_shutter_size],
    ['Footing done', row.shed_footing_done != null ? yn(row.shed_footing_done) : null],
    ['Office/toilet', row.shed_office_toilet != null ? yn(row.shed_office_toilet) : null],
  ].filter(([, v]) => v != null && v !== '')

  return (
    <div style={{ marginTop: 12, paddingTop: 12, borderTop: '1px dashed var(--border)' }}>
      <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 10.5, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'var(--text-muted)', marginBottom: 8 }}>
        <Ruler size={12} /> Shed Requirement
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))', gap: '8px 16px' }}>
        {items.map(([k, v]) => (
          <div key={k}>
            <div style={{ fontSize: 10.5, color: 'var(--text-muted)' }}>{k}</div>
            <div style={{ fontSize: 12.5, color: 'var(--text-h)', fontWeight: 600 }}>{v}</div>
          </div>
        ))}
      </div>
    </div>
  )
}

const STATUSES = ['Active', 'Completed', 'On_Hold', 'Terminated']
const TRISTATE = [['', 'Not specified'], ['true', 'Yes'], ['false', 'No']]

function ShedProjectModal({ api, vendorId, vendorName, row, onClose, onDone }) {
  const init = {
    project: '', site: '', role: '', status: 'Active', start_date: '', end_date: '', notes: '',
    shed_site_location: '', shed_length: '', shed_width: '', shed_height: '', shed_purpose: '',
    shed_side_wall: '', shed_flooring: '', shed_gate_shutter_size: '', shed_footing_done: '', shed_office_toilet: '',
    ...normalize(row),
  }
  const [form, setForm] = useState(init)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))
  const isEdit = !!row?.id

  const save = async () => {
    if (!form.project.trim()) { setErr('Project name is required.'); return }
    setBusy(true); setErr('')
    const payload = serialize(form)
    try {
      if (isEdit) await api.vendors.shedProjects.update(vendorId, row.id, payload)
      else await api.vendors.shedProjects.create(vendorId, payload)
      onDone()
    } catch (e) { setErr(e?.response?.data?.message || 'Could not save the project.'); setBusy(false) }
  }

  const triSelect = (k) => (
    <select value={form[k]} onChange={e => set(k, e.target.value)} style={selectStyle}>
      {TRISTATE.map(([val, lbl]) => <option key={val} value={val}>{lbl}</option>)}
    </select>
  )

  return (
    <Overlay onClose={() => !busy && onClose()} width={640}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 17, fontWeight: 800 }}>{isEdit ? 'Edit' : 'Add'} Shed Project</h2>
      <p style={{ margin: '0 0 14px', fontSize: 12, color: 'var(--text-muted)' }}>
        Engagement for <strong style={{ color: 'var(--text-h)' }}>{vendorName || 'this vendor'}</strong>, with its shed requirement.
      </p>
      {err && <div style={{ padding: '9px 12px', borderRadius: 10, marginBottom: 12, fontSize: 12.5, color: '#ef4444', background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.35)' }}>{err}</div>}

      <div style={{ maxHeight: '60vh', overflowY: 'auto', paddingRight: 4 }}>
        {/* Engagement */}
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <Field label="Project *"><TextInput value={form.project} onChange={e => set('project', e.target.value)} placeholder="Project name" autoFocus /></Field>
          <Field label="Site"><TextInput value={form.site} onChange={e => set('site', e.target.value)} placeholder="Site" /></Field>
          <Field label="Role"><TextInput value={form.role} onChange={e => set('role', e.target.value)} placeholder="e.g. Main contractor" /></Field>
          <Field label="Status">
            <select value={form.status} onChange={e => set('status', e.target.value)} style={selectStyle}>
              {STATUSES.map(s => <option key={s} value={s}>{label(s)}</option>)}
            </select>
          </Field>
          <Field label="Start date"><TextInput type="date" value={form.start_date} onChange={e => set('start_date', e.target.value)} /></Field>
          <Field label="End date"><TextInput type="date" value={form.end_date} onChange={e => set('end_date', e.target.value)} /></Field>
        </div>

        {/* Shed requirement */}
        <div style={{ margin: '16px 0 10px', display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 11, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.05em', color: '#a78bfa' }}>
          <Ruler size={13} /> Shed Requirement
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <div style={{ gridColumn: '1 / -1' }}>
            <Field label="Site location"><TextInput value={form.shed_site_location} onChange={e => set('shed_site_location', e.target.value)} placeholder="Where the shed will be built" /></Field>
          </div>
          <Field label="Required length (m)"><TextInput type="number" value={form.shed_length} onChange={e => set('shed_length', e.target.value)} placeholder="Length" /></Field>
          <Field label="Required width (m)"><TextInput type="number" value={form.shed_width} onChange={e => set('shed_width', e.target.value)} placeholder="Width" /></Field>
          <Field label="Required height"><TextInput value={form.shed_height} onChange={e => set('shed_height', e.target.value)} placeholder="e.g. 19 Meter" /></Field>
          <Field label="Purpose of shed"><TextInput value={form.shed_purpose} onChange={e => set('shed_purpose', e.target.value)} placeholder="e.g. Industrial Plant" /></Field>
          <Field label="Gate / shutter size"><TextInput value={form.shed_gate_shutter_size} onChange={e => set('shed_gate_shutter_size', e.target.value)} placeholder="e.g. 12 × 14 ft" /></Field>
          <div />
          <Field label="Side wall required?">{triSelect('shed_side_wall')}</Field>
          <Field label="Flooring required?">{triSelect('shed_flooring')}</Field>
          <Field label="Footing done?">{triSelect('shed_footing_done')}</Field>
          <Field label="Office / toilet required?">{triSelect('shed_office_toilet')}</Field>
        </div>

        <div style={{ marginTop: 12 }}>
          <Field label="Notes"><TextInput value={form.notes} onChange={e => set('notes', e.target.value)} placeholder="Any other detail" /></Field>
        </div>
      </div>

      <ModalFooter onClose={onClose} onConfirm={save} loading={busy} disabled={!form.project.trim()} confirmLabel={isEdit ? 'Save' : 'Add Project'} />
    </Overlay>
  )
}

/* A stored row → form values: booleans become '' | 'true' | 'false'; nulls → ''. */
function normalize(row) {
  if (!row || !row.id) return {}
  const out = {}
  for (const [k, v] of Object.entries(row)) {
    if (['shed_side_wall', 'shed_flooring', 'shed_footing_done', 'shed_office_toilet'].includes(k)) {
      out[k] = v === true || v === 1 ? 'true' : v === false || v === 0 ? 'false' : ''
    } else if (['start_date', 'end_date'].includes(k)) {
      out[k] = v ? String(v).slice(0, 10) : ''
    } else {
      out[k] = v ?? ''
    }
  }
  return out
}

/* Form values → API payload: '' → null; tri-state strings → real booleans. */
function serialize(form) {
  const bool = (v) => (v === 'true' ? true : v === 'false' ? false : null)
  const str = (v) => (v === '' || v == null ? null : v)
  return {
    project: form.project.trim(),
    site: str(form.site), role: str(form.role), status: form.status,
    start_date: str(form.start_date), end_date: str(form.end_date), notes: str(form.notes),
    shed_site_location: str(form.shed_site_location),
    shed_length: form.shed_length === '' ? null : Number(form.shed_length),
    shed_width: form.shed_width === '' ? null : Number(form.shed_width),
    shed_height: str(form.shed_height), shed_purpose: str(form.shed_purpose),
    shed_side_wall: bool(form.shed_side_wall), shed_flooring: bool(form.shed_flooring),
    shed_gate_shutter_size: str(form.shed_gate_shutter_size),
    shed_footing_done: bool(form.shed_footing_done), shed_office_toilet: bool(form.shed_office_toilet),
  }
}

const primaryBtn = {
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 9,
  border: 'none', background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, fontSize: 12.5, cursor: 'pointer',
}
const iconBtn = { display: 'inline-flex', padding: 6, borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-muted)', cursor: 'pointer' }
const selectStyle = { width: '100%', padding: '9px 12px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 13 }
