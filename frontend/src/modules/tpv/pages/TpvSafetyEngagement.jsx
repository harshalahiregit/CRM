import { useState, useEffect, useCallback } from 'react'
import { Eye, Users, Plus, RefreshCw, X, Loader2, CheckCircle } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import LoadError from '@/components/ui/LoadError'

const CATEGORIES = ['Unsafe_Act', 'Unsafe_Condition', 'Positive', 'Near_Miss']
const SEVERITIES = ['Low', 'Medium', 'High']
const catColor = (c) => ({ Unsafe_Act: '#f97316', Unsafe_Condition: '#f59e0b', Positive: '#10b981', Near_Miss: '#ef4444' }[c] || '#94a3b8')
const sevColor = (s) => ({ Low: '#10b981', Medium: '#f59e0b', High: '#ef4444' }[s] || '#94a3b8')
const pretty = (s) => (s || '').replace(/_/g, ' ')

/** Proactive safety engagement (Doc_4 Phase 5/6): observations + toolbox talks. */
export default function TpvSafetyEngagement() {
  const [tab, setTab] = useState('observations')
  return (
    <div style={{ padding: 24, maxWidth: 1050, margin: '0 auto' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 16 }}>
        <Eye size={22} style={{ color: '#7C3AED' }} />
        <h1 style={{ margin: 0, fontSize: 20, fontWeight: 800, color: 'var(--text-h)' }}>Safety Engagement</h1>
      </div>
      <div style={{ display: 'flex', gap: 6, marginBottom: 18, borderBottom: '1px solid var(--border)' }}>
        {[['observations', 'Observations', Eye], ['talks', 'Toolbox Talks', Users]].map(([k, label, Icon]) => (
          <button key={k} onClick={() => setTab(k)} style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 16px', border: 'none', borderBottom: `2px solid ${tab === k ? '#7C3AED' : 'transparent'}`, background: 'none', color: tab === k ? 'var(--text-h)' : 'var(--text-muted)', fontWeight: 700, fontSize: 13, cursor: 'pointer' }}>
            <Icon size={15} /> {label}
          </button>
        ))}
      </div>
      {tab === 'observations' ? <Observations /> : <Talks />}
    </div>
  )
}

function Observations() {
  const [rows, setRows] = useState([]); const [loading, setLoading] = useState(true); const [adding, setAdding] = useState(false)
  const [loadError, setLoadError] = useState(null)
  const load = useCallback(() => { setLoadError(null); setLoading(true); tpvApi.safety.observations().then(r => setRows(Array.isArray(r) ? r : [])).catch(e => { setRows([]); setLoadError(e) }).finally(() => setLoading(false)) }, [])
  useEffect(() => { load() }, [load])
  const close = async (o) => { const a = window.prompt('Action taken (optional):') ?? ''; try { await tpvApi.safety.closeObservation(o.id, { action_taken: a.trim() || null }); load() } catch { /* ignore */ } }
  return (
    <>
      <Toolbar onRefresh={load} onAdd={() => setAdding(true)} addLabel="Log Observation" />
      {loading ? <Loading /> : loadError ? <LoadError error={loadError} onRetry={load} /> : rows.length === 0 ? <Empty text="No observations logged." /> : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 9 }}>
          {rows.map(o => (
            <div key={o.id} style={row}>
              <span style={{ width: 10, height: 10, borderRadius: 999, background: catColor(o.category), flexShrink: 0 }} />
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                  <Badge text={pretty(o.category)} color={catColor(o.category)} small />
                  <Badge text={o.severity} color={sevColor(o.severity)} small />
                  <span style={{ fontSize: 12.5, color: 'var(--text-h)', fontWeight: 600 }}>{o.description}</span>
                </div>
                <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 2 }}>{o.vendor?.company_name || '—'} · {o.location || '—'} · {o.observed_at ? new Date(o.observed_at).toLocaleDateString() : '—'}{o.action_taken ? ` · ${o.action_taken}` : ''}</div>
              </div>
              {o.status === 'Open'
                ? <button onClick={() => close(o)} style={ghostSm}><CheckCircle size={13} /> Close</button>
                : <Badge text="Closed" color="#10b981" small />}
            </div>
          ))}
        </div>
      )}
      {adding && <ObservationModal onClose={() => setAdding(false)} onSaved={() => { setAdding(false); load() }} />}
    </>
  )
}

function Talks() {
  const [rows, setRows] = useState([]); const [loading, setLoading] = useState(true); const [adding, setAdding] = useState(false)
  const [loadError, setLoadError] = useState(null)
  const load = useCallback(() => { setLoadError(null); setLoading(true); tpvApi.safety.talks().then(r => setRows(Array.isArray(r) ? r : [])).catch(e => { setRows([]); setLoadError(e) }).finally(() => setLoading(false)) }, [])
  useEffect(() => { load() }, [load])
  return (
    <>
      <Toolbar onRefresh={load} onAdd={() => setAdding(true)} addLabel="Record Talk" />
      {loading ? <Loading /> : loadError ? <LoadError error={loadError} onRetry={load} /> : rows.length === 0 ? <Empty text="No toolbox talks recorded." /> : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 9 }}>
          {rows.map(t => (
            <div key={t.id} style={row}>
              <Users size={16} style={{ color: '#a78bfa', flexShrink: 0 }} />
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontSize: 13, color: 'var(--text-h)', fontWeight: 700 }}>{t.topic}</div>
                <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 2 }}>{t.vendor?.company_name || '—'} · {t.location || '—'} · {t.held_at ? new Date(t.held_at).toLocaleDateString() : '—'} · {t.attendee_count} attendee(s){t.duration_minutes ? ` · ${t.duration_minutes} min` : ''}</div>
              </div>
            </div>
          ))}
        </div>
      )}
      {adding && <TalkModal onClose={() => setAdding(false)} onSaved={() => { setAdding(false); load() }} />}
    </>
  )
}

function ObservationModal({ onClose, onSaved }) {
  const [vendors, setVendors] = useState([])
  const [f, setF] = useState({ category: 'Unsafe_Act', severity: 'Low', vendor_id: '', location: '', description: '', action_taken: '' })
  const [busy, setBusy] = useState(false); const [err, setErr] = useState('')
  useEffect(() => { tpvApi.vendors.list().then(r => setVendors(arr(r))).catch(() => {}) }, [])
  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))
  const save = async () => { if (!f.description.trim()) { setErr('A description is required.'); return } setBusy(true); setErr(''); try { await tpvApi.safety.createObservation({ ...f, vendor_id: f.vendor_id || null }); onSaved() } catch (e) { setErr(e?.response?.data?.message || 'Could not save.') } finally { setBusy(false) } }
  return (
    <Overlay onClose={onClose} title="Log Observation" icon={Eye}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <Field label="Category"><select value={f.category} onChange={set('category')} style={input}>{CATEGORIES.map(c => <option key={c} value={c}>{pretty(c)}</option>)}</select></Field>
        <Field label="Severity"><select value={f.severity} onChange={set('severity')} style={input}>{SEVERITIES.map(s => <option key={s}>{s}</option>)}</select></Field>
        <Field label="Vendor"><select value={f.vendor_id} onChange={set('vendor_id')} style={input}><option value="">— None —</option>{vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}</option>)}</select></Field>
        <Field label="Location"><input value={f.location} onChange={set('location')} style={input} /></Field>
        <Field label="Description *" full><textarea value={f.description} onChange={set('description')} rows={2} style={{ ...input, resize: 'vertical' }} /></Field>
        <Field label="Action taken" full><textarea value={f.action_taken} onChange={set('action_taken')} rows={2} style={{ ...input, resize: 'vertical' }} /></Field>
      </div>
      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '10px 0 0' }}>{err}</p>}
      <Footer onClose={onClose} onConfirm={save} busy={busy} label="Save Observation" />
    </Overlay>
  )
}

function TalkModal({ onClose, onSaved }) {
  const [vendors, setVendors] = useState([])
  const [f, setF] = useState({ topic: '', vendor_id: '', location: '', held_at: '', attendee_count: '', duration_minutes: '', notes: '' })
  const [busy, setBusy] = useState(false); const [err, setErr] = useState('')
  useEffect(() => { tpvApi.vendors.list().then(r => setVendors(arr(r))).catch(() => {}) }, [])
  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))
  const save = async () => { if (!f.topic.trim()) { setErr('A topic is required.'); return } setBusy(true); setErr(''); try { await tpvApi.safety.createTalk({ ...f, vendor_id: f.vendor_id || null, held_at: f.held_at || null, attendee_count: f.attendee_count || 0, duration_minutes: f.duration_minutes || null }); onSaved() } catch (e) { setErr(e?.response?.data?.message || 'Could not save.') } finally { setBusy(false) } }
  return (
    <Overlay onClose={onClose} title="Record Toolbox Talk" icon={Users}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <Field label="Topic *" full><input value={f.topic} onChange={set('topic')} style={input} /></Field>
        <Field label="Vendor"><select value={f.vendor_id} onChange={set('vendor_id')} style={input}><option value="">— None —</option>{vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}</option>)}</select></Field>
        <Field label="Held at"><input type="datetime-local" value={f.held_at} onChange={set('held_at')} style={input} /></Field>
        <Field label="Location"><input value={f.location} onChange={set('location')} style={input} /></Field>
        <Field label="Attendees"><input type="number" value={f.attendee_count} onChange={set('attendee_count')} style={input} /></Field>
        <Field label="Duration (min)"><input type="number" value={f.duration_minutes} onChange={set('duration_minutes')} style={input} /></Field>
        <Field label="Notes" full><textarea value={f.notes} onChange={set('notes')} rows={2} style={{ ...input, resize: 'vertical' }} /></Field>
      </div>
      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '10px 0 0' }}>{err}</p>}
      <Footer onClose={onClose} onConfirm={save} busy={busy} label="Save Talk" />
    </Overlay>
  )
}

/* ── bits ── */
const arr = (r) => Array.isArray(r?.data ?? r) ? (r.data ?? r) : []
const input = { width: '100%', padding: '8px 10px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 13 }
const row = { display: 'flex', alignItems: 'center', gap: 12, padding: '12px 14px', borderRadius: 11, background: 'var(--bg-card)', border: '1px solid var(--border)' }
const ghost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 12px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12.5, fontWeight: 700 }
const ghostSm = { ...ghost, padding: '5px 10px', fontSize: 11.5 }
const primary = { display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 16px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }
function Toolbar({ onRefresh, onAdd, addLabel }) { return <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginBottom: 14 }}><button onClick={onRefresh} style={ghost}><RefreshCw size={14} /></button><button onClick={onAdd} style={primary}><Plus size={15} /> {addLabel}</button></div> }
function Loading() { return <div style={{ textAlign: 'center', padding: 40, color: 'var(--text-muted)' }}><Loader2 size={18} className="rfq-spin" /></div> }
function Empty({ text }) { return <div style={{ textAlign: 'center', padding: 40, color: 'var(--text-muted)', fontSize: 13 }}>{text}</div> }
function Badge({ text, color, small }) { return <span style={{ padding: small ? '2px 8px' : '3px 10px', borderRadius: 999, background: `${color}22`, color, fontSize: small ? 10.5 : 11, fontWeight: 700, whiteSpace: 'nowrap' }}>{text}</span> }
function Field({ label, children, full }) { return <label style={{ display: 'block', gridColumn: full ? '1/-1' : undefined }}><span style={{ display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 4 }}>{label}</span>{children}</label> }
function Footer({ onClose, onConfirm, busy, label }) { return <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 18 }}><button onClick={onClose} style={ghost}>Cancel</button><button onClick={onConfirm} disabled={busy} style={primary}>{busy ? <Loader2 size={14} className="rfq-spin" /> : null} {label}</button></div> }
function Overlay({ children, onClose, title, icon: Icon }) {
  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', zIndex: 60, display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '8vh 16px 16px', backdropFilter: 'blur(2px)', overflowY: 'auto' }}>
      <div onClick={e => e.stopPropagation()} style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 16, width: '100%', maxWidth: 560, boxShadow: '0 20px 60px rgba(0,0,0,0.4)' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '16px 20px', borderBottom: '1px solid var(--border)' }}>
          <Icon size={18} style={{ color: '#7C3AED' }} />
          <h2 style={{ flex: 1, margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>{title}</h2>
          <button onClick={onClose} style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', display: 'flex' }}><X size={18} /></button>
        </div>
        <div style={{ padding: 20 }}>{children}</div>
      </div>
    </div>
  )
}
