import { useState, useEffect, useCallback } from 'react'
import { Siren, Plus, RefreshCw, X, AlertTriangle, CheckCircle, ShieldOff, Loader2, ClipboardList } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import LoadError from '@/components/ui/LoadError'

const SEVERITIES = ['Minor', 'Moderate', 'Serious', 'Fatal']
const TYPES = ['Injury', 'First_Aid', 'Medical_Treatment', 'LTI', 'Near_Miss', 'Property_Damage', 'Environmental', 'Fire', 'Security', 'Unsafe_Act', 'Unsafe_Condition', 'Fatality', 'Other']
const CAPA_STATUSES = ['Open', 'In_Progress', 'Done', 'Verified']

const sevColor = (s) => ({ Minor: '#10b981', Moderate: '#f59e0b', Serious: '#f97316', Fatal: '#ef4444' }[s] || '#94a3b8')
const statusColor = (s) => ({ Reported: '#0ea5e9', Investigating: '#f59e0b', Closed: '#10b981' }[s] || '#94a3b8')
const pretty = (s) => (s || '').replace(/_/g, ' ')
// A linked Purchase CAPA carries its description on `action` (unified register).
const capaText = (c) => c.action || c.title || c.description || ''

/**
 * Purchase HSSE Incidents → RCA → CAPA — the Purchase mirror of TpvIncidents.
 * Report an incident (a Serious/Fatal or stop-work one auto-suspends the vendor —
 * On_Hold), record its root cause, drive its corrective actions to Verified, then
 * close it — the backend refuses to close until the investigation is complete.
 */
export default function PurchaseIncidents() {
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [reporting, setReporting] = useState(false)
  const [openId, setOpenId] = useState(null)

  const [loadError, setLoadError] = useState(null)
  const load = useCallback(() => {
      setLoadError(null)
    setLoading(true)
    purchaseApi.incidents.list().then(r => setRows(Array.isArray(r) ? r : [])).catch(e => { setRows([]); setLoadError(e) }).finally(() => setLoading(false))
  }, [])
  useEffect(() => { load() }, [load])

  return (
    <div style={{ padding: 24, maxWidth: 1100, margin: '0 auto' }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 18, flexWrap: 'wrap', gap: 12 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <Siren size={22} style={{ color: '#ef4444' }} />
          <div>
            <h1 style={{ margin: 0, fontSize: 20, fontWeight: 800, color: 'var(--text-h)' }}>HSSE Incidents</h1>
            <p style={{ margin: '2px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>Report → investigate (RCA) → corrective actions (CAPA) → close</p>
          </div>
        </div>
        <div style={{ display: 'flex', gap: 10 }}>
          <button onClick={load} style={ghost}><RefreshCw size={14} /></button>
          <button onClick={() => setReporting(true)} style={primary}><Plus size={15} /> Report Incident</button>
        </div>
      </div>

      {loading ? (
        <div style={{ textAlign: 'center', padding: 48, color: 'var(--text-muted)' }}><Loader2 size={18} className="rfq-spin" /> Loading…</div>
      ) : loadError ? (
        <LoadError error={loadError} onRetry={load} />
      ) : rows.length === 0 ? (
        <div style={{ textAlign: 'center', padding: 48, color: 'var(--text-muted)' }}>No incidents reported. Stay safe.</div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {rows.map(inc => {
            const capas = inc.capas || []
            const verified = capas.filter(c => c.status === 'Verified').length
            return (
              <div key={inc.id} onClick={() => setOpenId(inc.id)}
                style={{ display: 'flex', alignItems: 'center', gap: 14, padding: '14px 16px', borderRadius: 12, background: 'var(--bg-card)', border: '1px solid var(--border)', cursor: 'pointer' }}>
                <span style={{ width: 10, height: 10, borderRadius: 999, background: sevColor(inc.severity), flexShrink: 0 }} />
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                    <span style={{ fontWeight: 700, color: 'var(--text-h)', fontSize: 14 }}>{inc.title}</span>
                    <span style={{ fontSize: 11, color: '#a78bfa', fontWeight: 700 }}>{inc.reference}</span>
                    {inc.triggered_suspension && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3, fontSize: 10.5, fontWeight: 700, color: '#f97316' }}><ShieldOff size={11} /> Vendor suspended</span>}
                  </div>
                  <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 2 }}>
                    {pretty(inc.type)} · {inc.vendor?.company_name || 'No vendor'} · {inc.location || 'No location'} · {inc.occurred_at ? new Date(inc.occurred_at).toLocaleDateString() : '—'}
                  </div>
                </div>
                <Badge text={inc.severity} color={sevColor(inc.severity)} />
                {capas.length > 0 && <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>CAPA {verified}/{capas.length}</span>}
                <Badge text={pretty(inc.status)} color={statusColor(inc.status)} />
              </div>
            )
          })}
        </div>
      )}

      {reporting && <ReportModal onClose={() => setReporting(false)} onSaved={() => { setReporting(false); load() }} />}
      {openId && <DetailModal id={openId} onClose={() => setOpenId(null)} onChanged={load} />}
    </div>
  )
}

function ReportModal({ onClose, onSaved }) {
  const [vendors, setVendors] = useState([])
  const [f, setF] = useState({ title: '', type: 'Injury', severity: 'Minor', purchase_vendor_id: '', location: '', occurred_at: '', description: '', immediate_action: '', stop_work: false })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => { purchaseApi.vendors.list().then(r => setVendors(Array.isArray(r?.data ?? r) ? (r.data ?? r) : [])).catch(() => {}) }, [])
  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }))

  const willSuspend = (f.severity === 'Serious' || f.severity === 'Fatal' || f.stop_work) && f.purchase_vendor_id

  const save = async () => {
    if (!f.title.trim()) { setErr('A title is required.'); return }
    if (!f.purchase_vendor_id) { setErr('A vendor is required.'); return }
    setBusy(true); setErr('')
    try {
      await purchaseApi.incidents.create({ ...f, occurred_at: f.occurred_at || null })
      onSaved()
    } catch (e) { setErr(e?.response?.data?.message || 'Could not report the incident.') }
    finally { setBusy(false) }
  }

  return (
    <Overlay onClose={onClose} title="Report Incident">
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <Field label="Title *" full><input value={f.title} onChange={set('title')} style={input} placeholder="Short summary" /></Field>
        <Field label="Type"><select value={f.type} onChange={set('type')} style={input}>{TYPES.map(t => <option key={t} value={t}>{pretty(t)}</option>)}</select></Field>
        <Field label="Severity"><select value={f.severity} onChange={set('severity')} style={input}>{SEVERITIES.map(s => <option key={s} value={s}>{s}</option>)}</select></Field>
        <Field label="Vendor *"><select value={f.purchase_vendor_id} onChange={set('purchase_vendor_id')} style={input}><option value="">— Select —</option>{vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}</option>)}</select></Field>
        <Field label="Occurred at"><input type="datetime-local" value={f.occurred_at} onChange={set('occurred_at')} style={input} /></Field>
        <Field label="Location" full><input value={f.location} onChange={set('location')} style={input} placeholder="e.g. Gate 3, Block B" /></Field>
        <Field label="Description" full><textarea value={f.description} onChange={set('description')} rows={2} style={{ ...input, resize: 'vertical' }} /></Field>
        <Field label="Immediate action taken" full><textarea value={f.immediate_action} onChange={set('immediate_action')} rows={2} style={{ ...input, resize: 'vertical' }} /></Field>
        <label style={{ gridColumn: '1/-1', display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: 'var(--text-h)', cursor: 'pointer' }}>
          <input type="checkbox" checked={f.stop_work} onChange={set('stop_work')} style={{ width: 15, height: 15 }} /> Issue a stop-work order
        </label>
      </div>
      {willSuspend && (
        <div style={{ marginTop: 12, padding: '9px 12px', borderRadius: 9, background: 'rgba(249,115,22,0.12)', border: '1px solid rgba(249,115,22,0.3)', fontSize: 12, color: '#f97316', display: 'flex', alignItems: 'center', gap: 8 }}>
          <AlertTriangle size={14} /> This will auto-suspend the selected vendor (On Hold) — site access is withheld until an admin reinstates them.
        </div>
      )}
      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '10px 0 0' }}>{err}</p>}
      <Footer onClose={onClose} onConfirm={save} busy={busy} label="Report Incident" />
    </Overlay>
  )
}

function DetailModal({ id, onClose, onChanged }) {
  const [inc, setInc] = useState(null)
  const [rca, setRca] = useState({ rca_method: '5-Whys', root_cause: '', contributing_factors: '' })
  const [capa, setCapa] = useState({ type: 'Corrective', description: '', due_date: '' })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const [loadError, setLoadError] = useState(null)
  const load = useCallback(() => {
      setLoadError(null)
    purchaseApi.incidents.get(id).then(d => {
      setInc(d)
      setRca({ rca_method: d.rca_method || '5-Whys', root_cause: d.root_cause || '', contributing_factors: d.contributing_factors || '' })
    }).catch(() => {})
  }, [id])
  useEffect(() => { load() }, [load])

  const act = async (fn) => { setBusy(true); setErr(''); try { await fn(); load(); onChanged() } catch (e) { setErr(e?.response?.data?.message || 'Action failed.') } finally { setBusy(false) } }
  const saveRca = () => { if (!rca.root_cause.trim()) { setErr('Root cause is required.'); return } act(() => purchaseApi.incidents.recordRca(id, rca)) }
  const addCapa = () => { if (!capa.description.trim()) { setErr('CAPA description is required.'); return } act(async () => { await purchaseApi.incidents.addCapa(id, { ...capa, due_date: capa.due_date || null }); setCapa({ type: 'Corrective', description: '', due_date: '' }) }) }
  const setCapaStatus = (c, status) => {
    // Verifying a CAPA is gated on closure evidence (Rule 12) — collect a reference.
    const payload = { status }
    if (status === 'Verified') {
      const ev = window.prompt('Closure evidence reference (required to verify this action):', c.evidence_path || '')
      if (!ev || !ev.trim()) { setErr('Closure evidence is required to verify a CAPA.'); return }
      payload.evidence_path = ev.trim()
    }
    act(() => purchaseApi.incidents.updateCapa(id, c.id, payload))
  }
  const close = () => act(() => purchaseApi.incidents.close(id))

  if (!inc) return <Overlay onClose={onClose} title="Incident"><div style={{ padding: 20, textAlign: 'center', color: 'var(--text-muted)' }}><Loader2 size={18} className="rfq-spin" /></div></Overlay>

  const capas = inc.capas || []
  const allVerified = capas.length > 0 && capas.every(c => c.status === 'Verified')
  const canClose = inc.status !== 'Closed' && inc.rca_done && (capas.length === 0 || allVerified)

  return (
    <Overlay onClose={onClose} title={`${inc.reference} — ${inc.title}`} wide>
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 14 }}>
        <Badge text={inc.severity} color={sevColor(inc.severity)} />
        <Badge text={pretty(inc.status)} color={statusColor(inc.status)} />
        <Badge text={pretty(inc.type)} color="#818cf8" />
        {inc.triggered_suspension && <Badge text="Vendor suspended" color="#f97316" />}
      </div>
      <div style={{ fontSize: 12.5, color: 'var(--text-muted)', marginBottom: 4 }}>{inc.vendor?.company_name || 'No vendor'} · {inc.location || 'No location'} · {inc.occurred_at ? new Date(inc.occurred_at).toLocaleString() : '—'}</div>
      {inc.description && <p style={{ fontSize: 13, color: 'var(--text-body)', margin: '8px 0' }}>{inc.description}</p>}

      {/* RCA */}
      <Section title="Root-Cause Analysis" done={inc.rca_done} />
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginBottom: 8 }}>
        <Field label="Method"><select value={rca.rca_method} onChange={e => setRca(p => ({ ...p, rca_method: e.target.value }))} disabled={inc.status === 'Closed'} style={input}>{['5-Whys', 'Fishbone', 'Fault-Tree', 'Other'].map(m => <option key={m}>{m}</option>)}</select></Field>
        <div />
        <Field label="Root cause *" full><textarea value={rca.root_cause} onChange={e => setRca(p => ({ ...p, root_cause: e.target.value }))} disabled={inc.status === 'Closed'} rows={2} style={{ ...input, resize: 'vertical' }} /></Field>
        <Field label="Contributing factors" full><textarea value={rca.contributing_factors} onChange={e => setRca(p => ({ ...p, contributing_factors: e.target.value }))} disabled={inc.status === 'Closed'} rows={2} style={{ ...input, resize: 'vertical' }} /></Field>
      </div>
      {inc.status !== 'Closed' && <button onClick={saveRca} disabled={busy} style={{ ...ghost, marginBottom: 16 }}>Save RCA</button>}

      {/* CAPA */}
      <Section title="Corrective & Preventive Actions" />
      {capas.length === 0 && <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '0 0 8px' }}>No actions yet.</p>}
      <div style={{ display: 'flex', flexDirection: 'column', gap: 7, marginBottom: 10 }}>
        {capas.map(c => (
          <div key={c.id} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '9px 11px', borderRadius: 9, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            <Badge text={c.type} color={c.type === 'Preventive' ? '#0ea5e9' : '#f59e0b'} small />
            <span style={{ flex: 1, minWidth: 0, fontSize: 12.5, color: 'var(--text-h)' }}>{capaText(c)}{c.due_date ? <span style={{ color: 'var(--text-muted)' }}> · due {c.due_date}</span> : ''}</span>
            {inc.status !== 'Closed' ? (
              <select value={c.status} onChange={e => setCapaStatus(c, e.target.value)} disabled={busy} style={{ ...input, width: 'auto', padding: '4px 8px', fontSize: 11.5 }}>
                {CAPA_STATUSES.map(s => <option key={s} value={s}>{pretty(s)}</option>)}
              </select>
            ) : <Badge text={pretty(c.status)} color={c.status === 'Verified' ? '#10b981' : '#94a3b8'} small />}
          </div>
        ))}
      </div>
      {inc.status !== 'Closed' && (
        <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end', marginBottom: 14 }}>
          <select value={capa.type} onChange={e => setCapa(p => ({ ...p, type: e.target.value }))} style={{ ...input, width: 'auto' }}>{['Corrective', 'Preventive'].map(t => <option key={t}>{t}</option>)}</select>
          <input value={capa.description} onChange={e => setCapa(p => ({ ...p, description: e.target.value }))} placeholder="New action…" style={{ ...input, flex: 1 }} />
          <input type="date" value={capa.due_date} onChange={e => setCapa(p => ({ ...p, due_date: e.target.value }))} style={{ ...input, width: 'auto' }} />
          <button onClick={addCapa} disabled={busy} style={ghost}><Plus size={14} /> Add</button>
        </div>
      )}

      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '0 0 10px' }}>{err}</p>}

      {inc.status !== 'Closed' && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, borderTop: '1px solid var(--border)', paddingTop: 14 }}>
          <button onClick={close} disabled={busy || !canClose}
            style={{ ...primary, background: canClose ? 'linear-gradient(135deg,#10b981,#059669)' : 'var(--bg-input)', color: canClose ? '#fff' : 'var(--text-muted)', cursor: canClose ? 'pointer' : 'not-allowed' }}>
            <CheckCircle size={15} /> Close Incident
          </button>
          {!canClose && <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{!inc.rca_done ? 'Record the RCA first.' : 'All CAPAs must be Verified to close.'}</span>}
        </div>
      )}
      {inc.status === 'Closed' && <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6, color: '#10b981', fontSize: 13, fontWeight: 700 }}><CheckCircle size={16} /> Closed {inc.closed_at ? new Date(inc.closed_at).toLocaleDateString() : ''}</div>}
    </Overlay>
  )
}

/* ── bits ── */
const input = { width: '100%', padding: '8px 10px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 13 }
const ghost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 12px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12.5, fontWeight: 700 }
const primary = { display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 16px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }

function Badge({ text, color, small }) {
  return <span style={{ padding: small ? '2px 8px' : '3px 10px', borderRadius: 999, background: `${color}22`, color, fontSize: small ? 10.5 : 11, fontWeight: 700, whiteSpace: 'nowrap' }}>{text}</span>
}
function Field({ label, children, full }) {
  return <label style={{ display: 'block', gridColumn: full ? '1/-1' : undefined }}><span style={{ display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 4 }}>{label}</span>{children}</label>
}
function Section({ title, done }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 8, margin: '14px 0 8px' }}>
      <ClipboardList size={14} style={{ color: '#a78bfa' }} />
      <span style={{ fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>{title}</span>
      {done && <CheckCircle size={14} style={{ color: '#10b981' }} />}
      <span style={{ flex: 1, height: 1, background: 'var(--border)' }} />
    </div>
  )
}
function Footer({ onClose, onConfirm, busy, label }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 18 }}>
      <button onClick={onClose} style={ghost}>Cancel</button>
      <button onClick={onConfirm} disabled={busy} style={primary}>{busy ? <Loader2 size={14} className="rfq-spin" /> : null} {label}</button>
    </div>
  )
}
// Popups close ONLY via the ✕/Cancel button — never a backdrop click (house rule).
function Overlay({ children, onClose, title, wide }) {
  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', zIndex: 60, display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '6vh 16px 16px', backdropFilter: 'blur(2px)', overflowY: 'auto' }}>
      <div onClick={e => e.stopPropagation()} style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 16, width: '100%', maxWidth: wide ? 720 : 560, boxShadow: '0 20px 60px rgba(0,0,0,0.4)' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '16px 20px', borderBottom: '1px solid var(--border)' }}>
          <Siren size={18} style={{ color: '#ef4444' }} />
          <h2 style={{ flex: 1, margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>{title}</h2>
          <button onClick={onClose} style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', display: 'flex' }}><X size={18} /></button>
        </div>
        <div style={{ padding: 20 }}>{children}</div>
      </div>
    </div>
  )
}
