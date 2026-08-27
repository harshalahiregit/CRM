import { useEffect, useState } from 'react'
import { Loader2, Plus, X, Send, HardHat, AlertTriangle } from 'lucide-react'
import { portalApi } from '@/services/portalApi'

/**
 * TPV portal — Compliance & HSSE writes. `view="ptw"` lets the vendor request a
 * Permit To Work and see its own permits; `view="incidents"` lets it report an
 * incident and see its own. Both land for the HSSE team (admin approves permits /
 * investigates incidents). A Serious/Fatal incident report triggers a safety hold
 * on site access — flagged in the form.
 */
export default function MyHsse({ view }) {
  return view === 'incidents' ? <Incidents /> : <Ptw />
}

const label = v => String(v ?? '—').replace(/_/g, ' ')
const date = v => (v ? String(v).slice(0, 10) : '—')

const STATUS_TONE = {
  approved: 'ok', active: 'ok', closed: 'muted', requested: 'warn', reported: 'warn',
  investigating: 'info', rejected: 'bad', expired: 'bad',
}
function Pill({ value }) {
  const tone = STATUS_TONE[String(value ?? '').toLowerCase()] || 'muted'
  const bg = { ok: 'rgba(34,197,94,0.15)', info: 'rgba(59,130,246,0.15)', warn: 'rgba(245,158,11,0.15)', bad: 'rgba(239,68,68,0.15)', muted: 'rgba(148,163,184,0.15)' }[tone]
  const fg = { ok: '#22c55e', info: '#3b82f6', warn: '#f59e0b', bad: '#ef4444', muted: '#94a3b8' }[tone]
  return <span style={{ padding: '2px 10px', borderRadius: 999, fontSize: 11, fontWeight: 700, textTransform: 'capitalize', background: bg, color: fg }}>{label(value)}</span>
}

/* ── PTW ──────────────────────────────────────────────────────────────────── */
function Ptw() {
  const [data, setData] = useState(null)
  const [adding, setAdding] = useState(false)
  const reload = () => portalApi.hsse.permits().then(setData).catch(() => setData({ data: [], types: [] }))
  useEffect(() => { reload() }, [])

  const rows = data?.data || []
  return (
    <Wrap>
      <style>{CSS}</style>
      <Head title="Permits To Work" cta="Request Permit" onCta={() => setAdding(true)} />
      {data === null ? <Center><Loader2 className="hz-spin" size={22} /></Center>
        : rows.length === 0 ? <Empty icon={HardHat} text="No permits yet. Request one before starting permit-controlled work." />
        : (
          <Table head={['Ref', 'Type', 'Title', 'Valid', 'Status']}>
            {rows.map(p => (
              <tr key={p.id}>
                <td style={{ fontWeight: 700, color: 'var(--text-h)' }}>{p.reference || '—'}</td>
                <td>{label(p.type)}</td><td>{p.title || '—'}</td>
                <td>{date(p.valid_from)} → {date(p.valid_to)}</td>
                <td><Pill value={p.status} /></td>
              </tr>
            ))}
          </Table>
        )}
      {adding && <PermitForm types={data?.types || []} onClose={() => setAdding(false)} onDone={() => { setAdding(false); reload() }} />}
    </Wrap>
  )
}

function PermitForm({ types, onClose, onDone }) {
  const [f, setF] = useState({ type: types[0] || 'Other', title: '', location: '', description: '', hazards: '', precautions: '', valid_from: '', valid_to: '' })
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const set = (k, v) => setF(s => ({ ...s, [k]: v }))
  const submit = async () => {
    setError('')
    if (!f.title.trim()) { setError('A title is required.'); return }
    setSaving(true)
    try { await portalApi.hsse.requestPermit(f); onDone() }
    catch (e) { setError(e?.response?.data?.message || 'Could not submit the request.') }
    finally { setSaving(false) }
  }
  return (
    <Modal title="Request Permit To Work" onClose={onClose}>
      <div className="hz-grid">
        <Select label="Type *" value={f.type} onChange={v => set('type', v)} options={types} />
        <Field label="Title *" value={f.title} onChange={v => set('title', v)} />
        <Field label="Location" value={f.location} onChange={v => set('location', v)} />
        <Field label="Valid from" type="date" value={f.valid_from} onChange={v => set('valid_from', v)} />
        <Field label="Valid to" type="date" value={f.valid_to} onChange={v => set('valid_to', v)} />
        <Area label="Description" value={f.description} onChange={v => set('description', v)} />
        <Area label="Hazards" value={f.hazards} onChange={v => set('hazards', v)} />
        <Area label="Precautions" value={f.precautions} onChange={v => set('precautions', v)} />
      </div>
      <FormFoot error={error} saving={saving} onClose={onClose} onSubmit={submit} submitLabel="Submit Request" />
    </Modal>
  )
}

/* ── Incidents ────────────────────────────────────────────────────────────── */
function Incidents() {
  const [data, setData] = useState(null)
  const [adding, setAdding] = useState(false)
  const reload = () => portalApi.hsse.incidents().then(setData).catch(() => setData({ data: [], types: [], severities: [] }))
  useEffect(() => { reload() }, [])

  const rows = data?.data || []
  return (
    <Wrap>
      <style>{CSS}</style>
      <Head title="HSSE Incidents" cta="Report Incident" onCta={() => setAdding(true)} />
      {data === null ? <Center><Loader2 className="hz-spin" size={22} /></Center>
        : rows.length === 0 ? <Empty icon={AlertTriangle} text="No incidents reported. Report anything unsafe promptly." />
        : (
          <Table head={['Ref', 'Type', 'Severity', 'Title', 'When', 'Status']}>
            {rows.map(i => (
              <tr key={i.id}>
                <td style={{ fontWeight: 700, color: 'var(--text-h)' }}>{i.reference || '—'}</td>
                <td>{label(i.type)}</td><td>{i.severity}</td><td>{i.title || '—'}</td>
                <td>{date(i.occurred_at)}</td><td><Pill value={i.status} /></td>
              </tr>
            ))}
          </Table>
        )}
      {adding && <IncidentForm types={data?.types || []} severities={data?.severities || []} onClose={() => setAdding(false)} onDone={() => { setAdding(false); reload() }} />}
    </Wrap>
  )
}

function IncidentForm({ types, severities, onClose, onDone }) {
  const [f, setF] = useState({ title: '', type: types[0] || 'Other', severity: severities[0] || 'Minor', occurred_at: '', location: '', description: '', immediate_action: '' })
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const set = (k, v) => setF(s => ({ ...s, [k]: v }))
  const grave = ['Serious', 'Fatal'].includes(f.severity)
  const submit = async () => {
    setError('')
    if (!f.title.trim()) { setError('A title is required.'); return }
    setSaving(true)
    try { await portalApi.hsse.reportIncident(f); onDone() }
    catch (e) { setError(e?.response?.data?.message || 'Could not submit the report.') }
    finally { setSaving(false) }
  }
  return (
    <Modal title="Report an Incident" onClose={onClose}>
      <div className="hz-grid">
        <Field label="Title *" value={f.title} onChange={v => set('title', v)} />
        <Select label="Type *" value={f.type} onChange={v => set('type', v)} options={types} />
        <Select label="Severity *" value={f.severity} onChange={v => set('severity', v)} options={severities} />
        <Field label="Occurred at" type="datetime-local" value={f.occurred_at} onChange={v => set('occurred_at', v)} />
        <Field label="Location" value={f.location} onChange={v => set('location', v)} />
        <Area label="What happened" value={f.description} onChange={v => set('description', v)} />
        <Area label="Immediate action taken" value={f.immediate_action} onChange={v => set('immediate_action', v)} />
      </div>
      {grave && (
        <div style={{ margin: '4px 0 0', padding: '10px 12px', borderRadius: 8, background: 'rgba(239,68,68,0.1)', color: '#f87171', fontSize: 12.5, display: 'flex', gap: 8 }}>
          <AlertTriangle size={16} style={{ flexShrink: 0, marginTop: 1 }} />
          A {f.severity} incident triggers an immediate safety review and may pause your site access until cleared.
        </div>
      )}
      <FormFoot error={error} saving={saving} onClose={onClose} onSubmit={submit} submitLabel="Submit Report" />
    </Modal>
  )
}

/* ── shared bits ──────────────────────────────────────────────────────────── */
function Wrap({ children }) { return <div style={{ maxWidth: 900, margin: '0 auto' }}>{children}</div> }
function Center({ children }) { return <div style={{ display: 'flex', justifyContent: 'center', padding: 60 }}>{children}</div> }
function Head({ title, cta, onCta }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, gap: 12, flexWrap: 'wrap' }}>
      <h2 style={{ fontSize: 18, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>{title}</h2>
      <button className="hz-btn hz-btn-primary" onClick={onCta}><Plus size={15} /> {cta}</button>
    </div>
  )
}
function Empty({ icon: Icon, text }) { return <div className="hz-card" style={{ display: 'flex', alignItems: 'center', gap: 10, color: 'var(--text-muted)', fontSize: 14 }}><Icon size={22} style={{ opacity: 0.6 }} /> {text}</div> }
function Table({ head, children }) {
  return (
    <div className="hz-card" style={{ padding: '6px 4px' }}>
      <div style={{ overflowX: 'auto' }}>
        <table className="hz-table">
          <thead><tr>{head.map(h => <th key={h}>{h}</th>)}</tr></thead>
          <tbody>{children}</tbody>
        </table>
      </div>
    </div>
  )
}
function Modal({ title, onClose, children }) {
  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.55)', zIndex: 60, display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '5vh 16px', overflowY: 'auto' }}>
      <div onClick={e => e.stopPropagation()} style={{ width: '100%', maxWidth: 640, background: 'var(--bg-card,#14161c)', border: '1px solid var(--border,rgba(255,255,255,0.1))', borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '14px 18px', borderBottom: '1px solid var(--border,rgba(255,255,255,0.08))' }}>
          <strong style={{ color: 'var(--text-h)', flex: 1 }}>{title}</strong>
          <button onClick={onClose} className="hz-icon"><X size={16} /></button>
        </div>
        <div style={{ padding: 18 }}>{children}</div>
      </div>
    </div>
  )
}
function FormFoot({ error, saving, onClose, onSubmit, submitLabel }) {
  return (
    <>
      {error && <div style={{ marginTop: 12, color: '#ef4444', fontSize: 13 }}>{error}</div>}
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 18 }}>
        <button onClick={onClose} className="hz-btn">Cancel</button>
        <button onClick={onSubmit} disabled={saving} className="hz-btn hz-btn-primary">{saving ? <Loader2 className="hz-spin" size={14} /> : <Send size={14} />} {submitLabel}</button>
      </div>
    </>
  )
}
function Field({ label, value, onChange, type = 'text' }) { return <label className="hz-lbl">{label}<input type={type} value={value} onChange={e => onChange(e.target.value)} className="hz-input" /></label> }
function Area({ label, value, onChange }) { return <label className="hz-lbl" style={{ gridColumn: '1 / -1' }}>{label}<textarea value={value} onChange={e => onChange(e.target.value)} className="hz-input" rows={2} style={{ resize: 'vertical' }} /></label> }
function Select({ label, value, onChange, options }) {
  return <label className="hz-lbl">{label}<select value={value} onChange={e => onChange(e.target.value)} className="hz-input">{options.map(o => <option key={o} value={o}>{String(o).replace(/_/g, ' ')}</option>)}</select></label>
}

const CSS = `
.hz-card { background: var(--bg-card, rgba(255,255,255,0.02)); border: 1px solid var(--border, rgba(255,255,255,0.08)); border-radius: 14px; padding: 18px; }
.hz-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.hz-table th { text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); padding: 10px 14px; border-bottom: 1px solid var(--border, rgba(255,255,255,0.08)); white-space: nowrap; }
.hz-table td { padding: 11px 14px; border-bottom: 1px solid var(--border, rgba(255,255,255,0.05)); color: var(--text-body, #cbd5e1); }
.hz-table tbody tr:last-child td { border-bottom: none; }
.hz-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
.hz-lbl { font-size: 12px; color: var(--text-muted); display: flex; flex-direction: column; gap: 4px; }
.hz-input { width: 100%; background: var(--bg-input, rgba(255,255,255,0.05)); border: 1px solid var(--border, rgba(255,255,255,0.12)); border-radius: 8px; padding: 7px 9px; color: var(--text-h); font-size: 13px; font-family: inherit; }
.hz-input:focus { outline: none; border-color: var(--portal-purple, #7c3aed); }
.hz-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 9px; font-size: 13px; font-weight: 700; cursor: pointer; border: 1px solid var(--border, rgba(255,255,255,0.14)); background: transparent; color: var(--text-h); }
.hz-btn:hover { background: var(--bg-input, rgba(255,255,255,0.05)); }
.hz-btn-primary { background: var(--portal-purple, #7c3aed); border-color: var(--portal-purple, #7c3aed); color: #fff; }
.hz-btn-primary:disabled { opacity: 0.6; cursor: default; }
.hz-icon { background: transparent; border: none; color: var(--text-muted); cursor: pointer; padding: 4px; border-radius: 6px; }
.hz-icon:hover { color: var(--text-h); }
.hz-spin { animation: hz-spin 0.9s linear infinite; }
@keyframes hz-spin { to { transform: rotate(360deg); } }
`
