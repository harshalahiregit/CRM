import { useState, useEffect, useCallback } from 'react'
import { Plus, RefreshCw, X, ChevronDown, ChevronRight, Trash2, Pencil, FileWarning } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import LoadError from '@/components/ui/LoadError'
import { KIT3D_STYLE as PURCHASE_STYLE } from '@/components/ui/kit3d'

// Purchase Inspections & Audits — the Purchase-side mirror of the TPV register
// (parity rule). Plan → Inspect → Finding → Action → NCR → Close. Findings can
// escalate into a Purchase NCR.
const STATUS_TONE = { Planned: '#94a3b8', In_Progress: '#0ea5e9', Completed: '#22c55e', Closed: '#6b7280' }
const SEV_TONE = { Minor: '#0ea5e9', Major: '#f59e0b', Critical: '#ef4444' }
const CAT_TONE = { Observation: '#0ea5e9', Non_Conformance: '#ef4444', Positive: '#10b981' }
const fmt = (s) => String(s || '').replace(/_/g, ' ')
const date = (d) => (d ? new Date(d).toLocaleDateString() : '—')

export default function PurchaseInspections() {
  const [rows, setRows] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [meta, setMeta] = useState({ types: [], statuses: [], finding_categories: [], severities: [] })
  const [vendors, setVendors] = useState([])
  const [expanded, setExpanded] = useState(null)
  const [modal, setModal] = useState(null)

  const load = useCallback(() => {
    purchaseApi.inspections.list().then(d => { setLoadError(null);
      setRows(d?.data ?? [])
      setMeta({ types: d?.types ?? [], statuses: d?.statuses ?? [], finding_categories: d?.finding_categories ?? [], severities: d?.severities ?? [] })
    }).catch(e => { setRows([]); setLoadError(e) })
  }, [])
  useEffect(() => { load() }, [load])
  useEffect(() => { purchaseApi.vendors.list().then(d => setVendors(Array.isArray(d) ? d : (d?.data ?? []))).catch(() => setVendors([])) }, [])

  const remove = async (id) => { if (window.confirm('Delete this inspection and its findings?')) { await purchaseApi.inspections.delete(id); load() } }

  return (
    <div style={{ padding: 4 }}>
      <style>{PURCHASE_STYLE}</style>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#38bdf8', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>COMPLIANCE</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0' }}>Inspections &amp; Audits</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Plan → Inspect → Finding → Action → NCR → Close.</p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button onClick={load} style={btnGhost}><RefreshCw size={14} /> Refresh</button>
          <button onClick={() => setModal({})} style={btnPrimary}><Plus size={15} /> New Inspection</button>
        </div>
      </div>

      <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                {['', 'Reference', 'Type', 'Title', 'Vendor', 'Findings', 'Score', 'Status', ''].map((h, i) => <th key={i} style={{ padding: '11px 14px' }}>{h}</th>)}
              </tr>
            </thead>
            <tbody>
              {loadError ? <tr><td colSpan={9} style={{ padding: 8 }}><LoadError error={loadError} onRetry={load} /></td></tr>
                : rows === null ? <tr><td colSpan={9} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
                : rows.length === 0 ? <tr><td colSpan={9} style={{ padding: 18, color: 'var(--text-muted)' }}>No inspections yet.</td></tr>
                : rows.map(i => (
                  <InspRow key={i.id} i={i} meta={meta} expanded={expanded === i.id}
                    onToggle={() => setExpanded(expanded === i.id ? null : i.id)} onEdit={() => setModal(i)} onDelete={() => remove(i.id)} onChanged={load} />
                ))}
            </tbody>
          </table>
        </div>
      </div>

      {modal && <InspModal row={modal.id ? modal : null} meta={meta} vendors={vendors} onClose={() => setModal(null)} onSaved={() => { setModal(null); load() }} />}
    </div>
  )
}

function Pill({ tone, text }) {
  const c = tone || '#94a3b8'
  return <span style={{ display: 'inline-block', padding: '3px 9px', borderRadius: 999, background: `${c}1f`, color: c, fontSize: 11, fontWeight: 700 }}>{text}</span>
}

function InspRow({ i, meta, expanded, onToggle, onEdit, onDelete, onChanged }) {
  return (
    <>
      <tr style={{ borderTop: '1px solid var(--border)' }}>
        <td style={{ padding: '10px 14px' }}><button onClick={onToggle} style={iconBtn}>{expanded ? <ChevronDown size={15} /> : <ChevronRight size={15} />}</button></td>
        <td style={{ padding: '10px 14px', fontWeight: 700, color: '#38bdf8' }}>{i.reference}</td>
        <td style={{ padding: '10px 14px', color: 'var(--text-h)' }}>{i.type_label}</td>
        <td style={{ padding: '10px 14px', color: 'var(--text-h)' }}>{i.title}</td>
        <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>{i.vendor?.company_name || '—'}</td>
        <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>{i.findings_count ?? 0}{(i.open_findings_count ?? 0) > 0 ? ` · ${i.open_findings_count} open` : ''}</td>
        <td style={{ padding: '10px 14px', color: 'var(--text-muted)', fontVariantNumeric: 'tabular-nums' }}>{i.score != null ? `${i.score}` : '—'}</td>
        <td style={{ padding: '10px 14px' }}><Pill tone={STATUS_TONE[i.status]} text={fmt(i.status)} /></td>
        <td style={{ padding: '10px 14px', whiteSpace: 'nowrap' }}>
          <button onClick={onEdit} style={iconBtn}><Pencil size={14} /></button>
          <button onClick={onDelete} style={iconBtn}><Trash2 size={14} /></button>
        </td>
      </tr>
      {expanded && (
        <tr><td colSpan={9} style={{ padding: '0 14px 14px', background: 'var(--bg-input,rgba(56,189,248,0.03))' }}>
          <Findings inspection={i} meta={meta} onChanged={onChanged} />
        </td></tr>
      )}
    </>
  )
}

function Findings({ inspection, meta, onChanged }) {
  const [detail, setDetail] = useState(null)
  const load = useCallback(() => { purchaseApi.inspections.get(inspection.id).then(setDetail).catch(() => setDetail({ findings: [] })) }, [inspection.id])
  useEffect(load, [load])
  const [nf, setNf] = useState({ description: '', category: 'Observation', severity: 'Minor', due_date: '' })

  const add = async () => {
    if (!nf.description.trim()) return
    await purchaseApi.inspections.addFinding(inspection.id, { ...nf, due_date: nf.due_date || null })
    setNf({ description: '', category: 'Observation', severity: 'Minor', due_date: '' }); load(); onChanged?.()
  }
  const del = async (id) => { await purchaseApi.inspections.deleteFinding(id); load(); onChanged?.() }
  const escalate = async (id) => {
    try { await purchaseApi.inspections.escalateFinding(id); load() } catch (e) { alert(e?.response?.data?.message || 'Could not escalate.') }
  }

  if (!detail) return <div style={{ padding: 12, color: 'var(--text-muted)', fontSize: 12.5 }}>Loading findings…</div>

  return (
    <div style={{ paddingTop: 12 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 7, marginBottom: 8 }}><FileWarning size={14} style={{ color: '#38bdf8' }} /><strong style={{ fontSize: 12.5, color: 'var(--text-h)' }}>Findings</strong></div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 6, marginBottom: 10 }}>
        {(detail.findings || []).length === 0 && <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: 0 }}>No findings recorded.</p>}
        {(detail.findings || []).map(f => (
          <div key={f.id} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 11px', borderRadius: 9, background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
            <div style={{ flex: 1 }}>
              <div style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{f.description}</div>
              <div style={{ display: 'flex', gap: 6, marginTop: 4 }}>
                <Pill tone={CAT_TONE[f.category]} text={fmt(f.category)} />
                <Pill tone={SEV_TONE[f.severity]} text={f.severity} />
                {f.due_date && <span style={{ fontSize: 10.5, color: 'var(--text-muted)', alignSelf: 'center' }}>due {date(f.due_date)}</span>}
              </div>
            </div>
            {f.ncr
              ? <span style={{ fontSize: 11, fontWeight: 700, color: '#38bdf8' }}>→ {f.ncr.reference}</span>
              : <button onClick={() => escalate(f.id)} style={miniBtn}>Raise NCR</button>}
            <button onClick={() => del(f.id)} style={iconBtn}><Trash2 size={13} /></button>
          </div>
        ))}
      </div>
      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
        <input value={nf.description} onChange={e => setNf(p => ({ ...p, description: e.target.value }))} placeholder="Finding description" style={{ ...inp, flex: 3, minWidth: 160 }} />
        <select value={nf.category} onChange={e => setNf(p => ({ ...p, category: e.target.value }))} style={{ ...inp, minWidth: 120 }}>
          {(meta.finding_categories.length ? meta.finding_categories : ['Observation', 'Non_Conformance', 'Positive']).map(c => <option key={c} value={c}>{fmt(c)}</option>)}
        </select>
        <select value={nf.severity} onChange={e => setNf(p => ({ ...p, severity: e.target.value }))} style={{ ...inp, minWidth: 90 }}>
          {(meta.severities.length ? meta.severities : ['Minor', 'Major', 'Critical']).map(s => <option key={s} value={s}>{s}</option>)}
        </select>
        <input type="date" value={nf.due_date} onChange={e => setNf(p => ({ ...p, due_date: e.target.value }))} style={{ ...inp, minWidth: 130 }} />
        <button onClick={add} style={{ ...btnPrimary, padding: '7px 12px' }}><Plus size={14} /></button>
      </div>
    </div>
  )
}

function InspModal({ row, meta, vendors, onClose, onSaved }) {
  const fields = [
    { k: 'title', label: 'Title', req: true },
    { k: 'location', label: 'Location' },
    { k: 'scheduled_date', label: 'Scheduled date', type: 'date' },
    { k: 'conducted_date', label: 'Conducted date', type: 'date' },
    { k: 'score', label: 'Score (0-100)', type: 'number' },
    { k: 'summary', label: 'Summary', area: true },
  ]
  const seed = () => {
    const b = { type: row?.type || (meta.types[0] || 'Supplier_Audit'), purchase_vendor_id: row?.purchase_vendor_id || '', status: row?.status || 'Planned' }
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
      if (row) await purchaseApi.inspections.update(row.id, payload)
      else await purchaseApi.inspections.create(payload)
      onSaved()
    } catch (e) { setErr(e?.response?.data?.message || 'Could not save.') } finally { setSaving(false) }
  }

  return (
    <div style={overlay} onClick={onClose}>
      <div className="pr-glass" style={sheet} onClick={e => e.stopPropagation()}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
          <h2 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>{row ? `Edit ${row.reference}` : 'New Inspection'}</h2>
          <button onClick={onClose} style={iconBtn}><X size={18} /></button>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2,1fr)', gap: 10 }}>
          <label style={lbl}>Type *
            <select value={form.type} onChange={set('type')} style={inp}>
              {(meta.types.length ? meta.types : ['Supplier_Audit']).map(t => <option key={t} value={t}>{fmt(t)}</option>)}
            </select>
          </label>
          <label style={lbl}>Status
            <select value={form.status} onChange={set('status')} style={inp}>
              {(meta.statuses.length ? meta.statuses : ['Planned', 'In_Progress', 'Completed', 'Closed']).map(s => <option key={s} value={s}>{fmt(s)}</option>)}
            </select>
          </label>
          <label style={lbl}>Vendor
            <select value={form.purchase_vendor_id} onChange={set('purchase_vendor_id')} style={inp}>
              <option value="">None</option>
              {vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}</option>)}
            </select>
          </label>
          <div />
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
          <button onClick={save} disabled={saving || !form.title} style={{ ...btnPrimary, opacity: (saving || !form.title) ? 0.6 : 1 }}>{saving ? 'Saving…' : row ? 'Save changes' : 'Create'}</button>
        </div>
      </div>
    </div>
  )
}

const btnPrimary = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: 'none', background: 'linear-gradient(145deg,#0ea5e9,#0284c7)', color: '#fff', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const btnGhost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }
const miniBtn = { display: 'inline-flex', alignItems: 'center', gap: 4, padding: '5px 10px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-card)', color: '#ef4444', cursor: 'pointer', fontSize: 11, fontWeight: 700 }
const iconBtn = { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', padding: 6, borderRadius: 8, border: 'none', background: 'transparent', color: 'var(--text-muted)', cursor: 'pointer' }
const overlay = { position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '5vh 16px', zIndex: 50, overflowY: 'auto' }
const sheet = { width: '100%', maxWidth: 600, padding: 22, borderRadius: 16 }
const lbl = { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 11.5, fontWeight: 600, color: 'var(--text-muted)' }
const inp = { width: '100%', padding: '7px 9px', borderRadius: 8, fontSize: 12.5, background: 'var(--bg-input,var(--bg-card))', color: 'var(--text-h)', border: '1px solid var(--border)' }
