import { useState, useEffect, useCallback } from 'react'
import { FileCheck2, Plus, RefreshCw, X, Loader2, CheckCircle, XCircle, PlayCircle, ClipboardList, Clock } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import LoadError from '@/components/ui/LoadError'

const TYPES = ['Hot_Work', 'Work_At_Height', 'Confined_Space', 'Electrical', 'Excavation', 'Lifting', 'General']
const RISKS = ['Low', 'Medium', 'High']
const statusColor = (s) => ({ Requested: '#f59e0b', Approved: '#0ea5e9', Active: '#10b981', Closed: '#94a3b8', Rejected: '#ef4444', Expired: '#f97316' }[s] || '#94a3b8')
const riskColor = (r) => ({ Low: '#10b981', Medium: '#f59e0b', High: '#ef4444' }[r] || '#94a3b8')
const pretty = (s) => (s || '').replace(/_/g, ' ')

/**
 * Permit-to-Work + JSA (Doc_4 Phase 5). Request a permit, build its Job Safety
 * Analysis, then approve → activate → close. The backend refuses to approve a
 * permit without a JSA or for a suspended vendor, and expires lapsed windows.
 */
export default function TpvPermits() {
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [creating, setCreating] = useState(false)
  const [openId, setOpenId] = useState(null)

  const [loadError, setLoadError] = useState(null)
  const load = useCallback(() => {
      setLoadError(null)
    setLoading(true)
    tpvApi.permits.list().then(r => setRows(Array.isArray(r) ? r : [])).catch(e => { setRows([]); setLoadError(e) }).finally(() => setLoading(false))
  }, [])
  useEffect(() => { load() }, [load])

  return (
    <div style={{ padding: 24, maxWidth: 1100, margin: '0 auto' }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 18, flexWrap: 'wrap', gap: 12 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <FileCheck2 size={22} style={{ color: '#7C3AED' }} />
          <div>
            <h1 style={{ margin: 0, fontSize: 20, fontWeight: 800, color: 'var(--text-h)' }}>Permit-to-Work</h1>
            <p style={{ margin: '2px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>Request → JSA → approve → activate → close</p>
          </div>
        </div>
        <div style={{ display: 'flex', gap: 10 }}>
          <button onClick={load} style={ghost}><RefreshCw size={14} /></button>
          <button onClick={() => setCreating(true)} style={primary}><Plus size={15} /> Request Permit</button>
        </div>
      </div>

      {loading ? <div style={{ textAlign: 'center', padding: 48, color: 'var(--text-muted)' }}><Loader2 size={18} className="rfq-spin" /></div>
        : loadError ? <LoadError error={loadError} onRetry={load} />
        : rows.length === 0 ? <div style={{ textAlign: 'center', padding: 48, color: 'var(--text-muted)' }}>No permits yet.</div>
        : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            {rows.map(p => (
              <div key={p.id} onClick={() => setOpenId(p.id)} style={{ display: 'flex', alignItems: 'center', gap: 14, padding: '14px 16px', borderRadius: 12, background: 'var(--bg-card)', border: '1px solid var(--border)', cursor: 'pointer' }}>
                <FileCheck2 size={16} style={{ color: '#a78bfa', flexShrink: 0 }} />
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                    <span style={{ fontWeight: 700, color: 'var(--text-h)', fontSize: 14 }}>{p.title}</span>
                    <span style={{ fontSize: 11, color: '#a78bfa', fontWeight: 700 }}>{p.reference}</span>
                    {p.is_expired && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3, fontSize: 10.5, fontWeight: 700, color: '#f97316' }}><Clock size={11} /> Window lapsed</span>}
                  </div>
                  <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 2 }}>
                    {pretty(p.type)} · {p.vendor?.company_name || 'No vendor'} · {p.location || '—'} · {(p.jsa_steps || []).length} JSA step(s)
                  </div>
                </div>
                <Badge text={pretty(p.status)} color={statusColor(p.status)} />
              </div>
            ))}
          </div>
        )}

      {creating && <CreateModal onClose={() => setCreating(false)} onSaved={id => { setCreating(false); load(); setOpenId(id) }} />}
      {openId && <DetailModal id={openId} onClose={() => setOpenId(null)} onChanged={load} />}
    </div>
  )
}

function CreateModal({ onClose, onSaved }) {
  const [vendors, setVendors] = useState([])
  const [f, setF] = useState({ title: '', type: 'Hot_Work', vendor_id: '', location: '', description: '', hazards: '', precautions: '', valid_from: '', valid_to: '' })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  useEffect(() => { tpvApi.vendors.list().then(r => setVendors(Array.isArray(r?.data ?? r) ? (r.data ?? r) : [])).catch(() => {}) }, [])
  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))
  const save = async () => {
    if (!f.title.trim()) { setErr('A title is required.'); return }
    setBusy(true); setErr('')
    try { const p = await tpvApi.permits.create({ ...f, vendor_id: f.vendor_id || null, valid_from: f.valid_from || null, valid_to: f.valid_to || null }); onSaved(p.id) }
    catch (e) { setErr(e?.response?.data?.message || 'Could not create the permit.') }
    finally { setBusy(false) }
  }
  return (
    <Overlay onClose={onClose} title="Request Permit">
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <Field label="Title *" full><input value={f.title} onChange={set('title')} style={input} placeholder="Work summary" /></Field>
        <Field label="Type"><select value={f.type} onChange={set('type')} style={input}>{TYPES.map(t => <option key={t} value={t}>{pretty(t)}</option>)}</select></Field>
        <Field label="Vendor"><select value={f.vendor_id} onChange={set('vendor_id')} style={input}><option value="">— None —</option>{vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}</option>)}</select></Field>
        <Field label="Valid from"><input type="datetime-local" value={f.valid_from} onChange={set('valid_from')} style={input} /></Field>
        <Field label="Valid to"><input type="datetime-local" value={f.valid_to} onChange={set('valid_to')} style={input} /></Field>
        <Field label="Location" full><input value={f.location} onChange={set('location')} style={input} placeholder="e.g. Block B, Level 3" /></Field>
        <Field label="Hazards" full><textarea value={f.hazards} onChange={set('hazards')} rows={2} style={{ ...input, resize: 'vertical' }} /></Field>
        <Field label="Precautions" full><textarea value={f.precautions} onChange={set('precautions')} rows={2} style={{ ...input, resize: 'vertical' }} /></Field>
      </div>
      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '10px 0 0' }}>{err}</p>}
      <Footer onClose={onClose} onConfirm={save} busy={busy} label="Create Permit" />
    </Overlay>
  )
}

function DetailModal({ id, onClose, onChanged }) {
  const [p, setP] = useState(null)
  const [step, setStep] = useState({ activity: '', hazard: '', control: '', residual_risk: 'Low' })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [loadError, setLoadError] = useState(null)
  const load = useCallback(() => { setLoadError(null); tpvApi.permits.get(id).then(setP).catch(() => {}) }, [id])
  useEffect(() => { load() }, [load])
  const act = async (fn) => { setBusy(true); setErr(''); try { await fn(); load(); onChanged() } catch (e) { setErr(e?.response?.data?.message || 'Action failed.') } finally { setBusy(false) } }
  const addStep = () => { if (!step.activity.trim()) { setErr('An activity is required.'); return } act(async () => { await tpvApi.permits.addStep(id, step); setStep({ activity: '', hazard: '', control: '', residual_risk: 'Low' }) }) }
  const approve = () => act(() => tpvApi.permits.approve(id))
  const reject = () => { const r = window.prompt('Reason for rejection:'); if (!r?.trim()) return; act(() => tpvApi.permits.reject(id, r.trim())) }
  const activate = () => act(() => tpvApi.permits.activate(id))
  const close = () => act(() => tpvApi.permits.close(id))

  if (!p) return <Overlay onClose={onClose} title="Permit"><div style={{ padding: 20, textAlign: 'center', color: 'var(--text-muted)' }}><Loader2 size={18} className="rfq-spin" /></div></Overlay>
  const steps = p.jsa_steps || []
  const editable = !['Closed', 'Rejected', 'Expired'].includes(p.status)

  return (
    <Overlay onClose={onClose} title={`${p.reference} — ${p.title}`} wide>
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 12 }}>
        <Badge text={pretty(p.status)} color={statusColor(p.status)} />
        <Badge text={pretty(p.type)} color="#818cf8" />
        {p.is_expired && <Badge text="Window lapsed" color="#f97316" />}
      </div>
      <div style={{ fontSize: 12.5, color: 'var(--text-muted)', marginBottom: 4 }}>
        {p.vendor?.company_name || 'No vendor'} · {p.location || '—'} · {p.valid_from ? new Date(p.valid_from).toLocaleString() : '—'} → {p.valid_to ? new Date(p.valid_to).toLocaleString() : '—'}
      </div>
      {p.hazards && <p style={{ fontSize: 12.5, color: 'var(--text-body)', margin: '6px 0' }}><strong>Hazards:</strong> {p.hazards}</p>}
      {p.precautions && <p style={{ fontSize: 12.5, color: 'var(--text-body)', margin: '6px 0' }}><strong>Precautions:</strong> {p.precautions}</p>}

      <Section title="Job Safety Analysis" />
      {steps.length === 0 && <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '0 0 8px' }}>No JSA steps yet — at least one is required to approve.</p>}
      <div style={{ display: 'flex', flexDirection: 'column', gap: 6, marginBottom: 10 }}>
        {steps.map((s, i) => (
          <div key={s.id} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '8px 11px', borderRadius: 9, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            <span style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', width: 20 }}>{i + 1}</span>
            <div style={{ flex: 1, minWidth: 0, fontSize: 12.5 }}>
              <span style={{ color: 'var(--text-h)', fontWeight: 700 }}>{s.activity}</span>
              <span style={{ color: 'var(--text-muted)' }}>{s.hazard ? ` · ${s.hazard}` : ''}{s.control ? ` → ${s.control}` : ''}</span>
            </div>
            <Badge text={s.residual_risk} color={riskColor(s.residual_risk)} small />
          </div>
        ))}
      </div>
      {editable && (
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr auto auto', gap: 6, alignItems: 'end', marginBottom: 14 }}>
          <input value={step.activity} onChange={e => setStep(p => ({ ...p, activity: e.target.value }))} placeholder="Activity" style={input} />
          <input value={step.hazard} onChange={e => setStep(p => ({ ...p, hazard: e.target.value }))} placeholder="Hazard → control" style={input} onKeyDown={e => { if (e.key === 'Enter') { const [h, c] = e.target.value.split('→'); setStep(p => ({ ...p, hazard: h?.trim() || '', control: c?.trim() || '' })) } }} />
          <select value={step.residual_risk} onChange={e => setStep(p => ({ ...p, residual_risk: e.target.value }))} style={{ ...input, width: 'auto' }}>{RISKS.map(r => <option key={r}>{r}</option>)}</select>
          <button onClick={addStep} disabled={busy} style={ghost}><Plus size={14} /> Add</button>
        </div>
      )}
      {step.control && <p style={{ fontSize: 11, color: 'var(--text-muted)', margin: '-8px 0 12px' }}>Control: {step.control}</p>}

      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '0 0 10px' }}>{err}</p>}

      <div style={{ display: 'flex', gap: 8, borderTop: '1px solid var(--border)', paddingTop: 14, flexWrap: 'wrap' }}>
        {p.status === 'Requested' && <><button onClick={approve} disabled={busy} style={{ ...primary, background: 'linear-gradient(135deg,#10b981,#059669)' }}><CheckCircle size={15} /> Approve</button>
          <button onClick={reject} disabled={busy} style={{ ...ghost, color: '#ef4444', borderColor: 'rgba(239,68,68,0.4)' }}><XCircle size={15} /> Reject</button></>}
        {p.status === 'Approved' && <button onClick={activate} disabled={busy} style={{ ...primary, background: 'linear-gradient(135deg,#0ea5e9,#0284c7)' }}><PlayCircle size={15} /> Activate</button>}
        {['Approved', 'Active'].includes(p.status) && <button onClick={close} disabled={busy} style={ghost}><CheckCircle size={15} /> Close</button>}
        {['Closed', 'Rejected', 'Expired'].includes(p.status) && <span style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>This permit is {pretty(p.status).toLowerCase()}.</span>}
      </div>
    </Overlay>
  )
}

/* ── shared bits ── */
const input = { width: '100%', padding: '8px 10px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 13 }
const ghost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 12px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12.5, fontWeight: 700 }
const primary = { display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 16px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }
function Badge({ text, color, small }) { return <span style={{ padding: small ? '2px 8px' : '3px 10px', borderRadius: 999, background: `${color}22`, color, fontSize: small ? 10.5 : 11, fontWeight: 700, whiteSpace: 'nowrap' }}>{text}</span> }
function Field({ label, children, full }) { return <label style={{ display: 'block', gridColumn: full ? '1/-1' : undefined }}><span style={{ display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 4 }}>{label}</span>{children}</label> }
function Section({ title }) { return <div style={{ display: 'flex', alignItems: 'center', gap: 8, margin: '14px 0 8px' }}><ClipboardList size={14} style={{ color: '#a78bfa' }} /><span style={{ fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>{title}</span><span style={{ flex: 1, height: 1, background: 'var(--border)' }} /></div> }
function Footer({ onClose, onConfirm, busy, label }) { return <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 18 }}><button onClick={onClose} style={ghost}>Cancel</button><button onClick={onConfirm} disabled={busy} style={primary}>{busy ? <Loader2 size={14} className="rfq-spin" /> : null} {label}</button></div> }
function Overlay({ children, onClose, title, wide }) {
  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', zIndex: 60, display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '6vh 16px 16px', backdropFilter: 'blur(2px)', overflowY: 'auto' }}>
      <div onClick={e => e.stopPropagation()} style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 16, width: '100%', maxWidth: wide ? 720 : 560, boxShadow: '0 20px 60px rgba(0,0,0,0.4)' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '16px 20px', borderBottom: '1px solid var(--border)' }}>
          <FileCheck2 size={18} style={{ color: '#7C3AED' }} />
          <h2 style={{ flex: 1, margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>{title}</h2>
          <button onClick={onClose} style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', display: 'flex' }}><X size={18} /></button>
        </div>
        <div style={{ padding: 20 }}>{children}</div>
      </div>
    </div>
  )
}
