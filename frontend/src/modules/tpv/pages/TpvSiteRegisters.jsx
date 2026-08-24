import { useState, useEffect, useCallback } from 'react'
import { Flame, Users, Truck, Plus, RefreshCw, X, Loader2, LogOut } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import LoadError from '@/components/ui/LoadError'

const DRILL_TYPES = ['Fire', 'Evacuation', 'Medical', 'Spill', 'Other']

/** Site safety registers (Doc_4 Phase 5/6): emergency drills, visitors, vehicles. */
export default function TpvSiteRegisters() {
  const [tab, setTab] = useState('drills')
  return (
    <div style={{ padding: 24, maxWidth: 1050, margin: '0 auto' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 16 }}>
        <Truck size={22} style={{ color: '#7C3AED' }} />
        <h1 style={{ margin: 0, fontSize: 20, fontWeight: 800, color: 'var(--text-h)' }}>Site Registers</h1>
      </div>
      <div style={{ display: 'flex', gap: 4, marginBottom: 18, borderBottom: '1px solid var(--border)' }}>
        {[['drills', 'Emergency Drills', Flame], ['visitors', 'Visitors', Users], ['vehicles', 'Vehicles', Truck]].map(([k, label, Icon]) => (
          <button key={k} onClick={() => setTab(k)} style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 16px', border: 'none', borderBottom: `2px solid ${tab === k ? '#7C3AED' : 'transparent'}`, background: 'none', color: tab === k ? 'var(--text-h)' : 'var(--text-muted)', fontWeight: 700, fontSize: 13, cursor: 'pointer' }}>
            <Icon size={15} /> {label}
          </button>
        ))}
      </div>
      {tab === 'drills' ? <Drills /> : tab === 'visitors' ? <Visitors /> : <Vehicles />}
    </div>
  )
}

function useReg(fetcher) {
  const [rows, setRows] = useState([]); const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState(null)
  const load = useCallback(() => { setLoadError(null); setLoading(true); fetcher().then(r => setRows(Array.isArray(r) ? r : [])).catch(e => { setRows([]); setLoadError(e) }).finally(() => setLoading(false)) }, [fetcher])
  useEffect(() => { load() }, [load])
  return { rows, loading, load, loadError }
}

function Drills() {
  const { rows, loading, load, loadError } = useReg(tpvApi.registers.drills)
  const [adding, setAdding] = useState(false)
  return (
    <>
      <Toolbar onRefresh={load} onAdd={() => setAdding(true)} addLabel="Record Drill" />
      {loading ? <Loading /> : loadError ? <LoadError error={loadError} onRetry={load} /> : rows.length === 0 ? <Empty text="No drills recorded." /> : rows.map(d => (
        <div key={d.id} style={row}>
          <Flame size={16} style={{ color: '#f97316', flexShrink: 0 }} />
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{d.drill_type} drill</div>
            <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{d.location || '—'} · {d.conducted_at ? new Date(d.conducted_at).toLocaleString() : '—'} · {d.participants} participant(s){d.evacuation_seconds ? ` · evac ${d.evacuation_seconds}s` : ''}{d.findings ? ` · ${d.findings}` : ''}</div>
          </div>
        </div>
      ))}
      {adding && <FormModal title="Record Emergency Drill" icon={Flame} onClose={() => setAdding(false)} onSaved={() => { setAdding(false); load() }}
        fields={[
          { k: 'drill_type', label: 'Type', type: 'select', options: DRILL_TYPES, required: true },
          { k: 'conducted_at', label: 'Conducted at', type: 'datetime-local' },
          { k: 'location', label: 'Location' },
          { k: 'participants', label: 'Participants', type: 'number' },
          { k: 'evacuation_seconds', label: 'Evacuation (sec)', type: 'number' },
          { k: 'findings', label: 'Findings', type: 'textarea', full: true },
        ]} submit={tpvApi.registers.createDrill} />}
    </>
  )
}

function Visitors() {
  const { rows, loading, load, loadError } = useReg(tpvApi.registers.visitors)
  const [adding, setAdding] = useState(false)
  const checkout = async (v) => { try { await tpvApi.registers.checkoutVisitor(v.id); load() } catch { /* ignore */ } }
  return (
    <>
      <Toolbar onRefresh={load} onAdd={() => setAdding(true)} addLabel="Check In Visitor" />
      {loading ? <Loading /> : loadError ? <LoadError error={loadError} onRetry={load} /> : rows.length === 0 ? <Empty text="No visitors logged." /> : rows.map(v => (
        <div key={v.id} style={row}>
          <Users size={16} style={{ color: '#a78bfa', flexShrink: 0 }} />
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{v.visitor_name}{v.company ? ` · ${v.company}` : ''}</div>
            <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{v.purpose || '—'} · host {v.host || '—'} · in {v.check_in_at ? new Date(v.check_in_at).toLocaleTimeString() : '—'}{v.check_out_at ? ` · out ${new Date(v.check_out_at).toLocaleTimeString()}` : ''}</div>
          </div>
          {v.check_out_at ? <Badge text="Checked out" color="#94a3b8" /> : <button onClick={() => checkout(v)} style={ghostSm}><LogOut size={13} /> Check out</button>}
        </div>
      ))}
      {adding && <FormModal title="Check In Visitor" icon={Users} onClose={() => setAdding(false)} onSaved={() => { setAdding(false); load() }}
        fields={[
          { k: 'visitor_name', label: 'Visitor name', required: true },
          { k: 'company', label: 'Company' },
          { k: 'purpose', label: 'Purpose' },
          { k: 'host', label: 'Host' },
          { k: 'contact', label: 'Contact' },
          { k: 'badge_number', label: 'Badge #' },
        ]} submit={tpvApi.registers.createVisitor} />}
    </>
  )
}

function Vehicles() {
  const { rows, loading, load, loadError } = useReg(tpvApi.registers.vehicles)
  const [adding, setAdding] = useState(false)
  const checkout = async (v) => { try { await tpvApi.registers.checkoutVehicle(v.id); load() } catch { /* ignore */ } }
  return (
    <>
      <Toolbar onRefresh={load} onAdd={() => setAdding(true)} addLabel="Check In Vehicle" />
      {loading ? <Loading /> : loadError ? <LoadError error={loadError} onRetry={load} /> : rows.length === 0 ? <Empty text="No vehicles logged." /> : rows.map(v => (
        <div key={v.id} style={row}>
          <Truck size={16} style={{ color: v.fitness_valid ? '#10b981' : '#ef4444', flexShrink: 0 }} />
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{v.vehicle_number}{v.vehicle_type ? ` · ${v.vehicle_type}` : ''}</div>
            <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{v.driver_name || '—'} · {v.vendor?.company_name || 'No vendor'} · {v.purpose || '—'}{!v.fitness_valid ? ' · ⚠ fitness invalid' : ''}</div>
          </div>
          {v.check_out_at ? <Badge text="Exited" color="#94a3b8" /> : <button onClick={() => checkout(v)} style={ghostSm}><LogOut size={13} /> Exit</button>}
        </div>
      ))}
      {adding && <FormModal title="Check In Vehicle" icon={Truck} onClose={() => setAdding(false)} onSaved={() => { setAdding(false); load() }}
        fields={[
          { k: 'vehicle_number', label: 'Vehicle number', required: true },
          { k: 'vehicle_type', label: 'Type' },
          { k: 'driver_name', label: 'Driver' },
          { k: 'purpose', label: 'Purpose' },
        ]} submit={tpvApi.registers.createVehicle} />}
    </>
  )
}

/* ── generic form modal ── */
function FormModal({ title, icon: Icon, fields, submit, onClose, onSaved }) {
  const [f, setF] = useState(() => Object.fromEntries(fields.map(x => [x.k, x.type === 'select' ? (x.options[0]) : ''])))
  const [busy, setBusy] = useState(false); const [err, setErr] = useState('')
  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))
  const save = async () => {
    const missing = fields.find(x => x.required && !String(f[x.k] ?? '').trim())
    if (missing) { setErr(`${missing.label} is required.`); return }
    setBusy(true); setErr('')
    try { const payload = Object.fromEntries(Object.entries(f).map(([k, v]) => [k, v === '' ? null : v])); await submit(payload); onSaved() }
    catch (e) { setErr(e?.response?.data?.message || 'Could not save.') }
    finally { setBusy(false) }
  }
  return (
    <Overlay onClose={onClose} title={title} icon={Icon}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        {fields.map(x => (
          <label key={x.k} style={{ display: 'block', gridColumn: x.full ? '1/-1' : undefined }}>
            <span style={{ display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 4 }}>{x.label}{x.required && ' *'}</span>
            {x.type === 'select' ? <select value={f[x.k]} onChange={set(x.k)} style={input}>{x.options.map(o => <option key={o} value={o}>{o}</option>)}</select>
              : x.type === 'textarea' ? <textarea value={f[x.k]} onChange={set(x.k)} rows={2} style={{ ...input, resize: 'vertical' }} />
              : <input type={x.type || 'text'} value={f[x.k]} onChange={set(x.k)} style={input} />}
          </label>
        ))}
      </div>
      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '10px 0 0' }}>{err}</p>}
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 18 }}>
        <button onClick={onClose} style={ghost}>Cancel</button>
        <button onClick={save} disabled={busy} style={primary}>{busy ? <Loader2 size={14} className="rfq-spin" /> : null} Save</button>
      </div>
    </Overlay>
  )
}

/* ── bits ── */
const input = { width: '100%', padding: '8px 10px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 13 }
const row = { display: 'flex', alignItems: 'center', gap: 12, padding: '12px 14px', borderRadius: 11, background: 'var(--bg-card)', border: '1px solid var(--border)', marginBottom: 9 }
const ghost = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 12px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12.5, fontWeight: 700 }
const ghostSm = { ...ghost, padding: '5px 10px', fontSize: 11.5 }
const primary = { display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 16px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }
function Toolbar({ onRefresh, onAdd, addLabel }) { return <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginBottom: 14 }}><button onClick={onRefresh} style={ghost}><RefreshCw size={14} /></button><button onClick={onAdd} style={primary}><Plus size={15} /> {addLabel}</button></div> }
function Loading() { return <div style={{ textAlign: 'center', padding: 40, color: 'var(--text-muted)' }}><Loader2 size={18} className="rfq-spin" /></div> }
function Empty({ text }) { return <div style={{ textAlign: 'center', padding: 40, color: 'var(--text-muted)', fontSize: 13 }}>{text}</div> }
function Badge({ text, color }) { return <span style={{ padding: '3px 10px', borderRadius: 999, background: `${color}22`, color, fontSize: 11, fontWeight: 700, whiteSpace: 'nowrap' }}>{text}</span> }
function Overlay({ children, onClose, title, icon: Icon }) {
  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', zIndex: 60, display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '8vh 16px 16px', backdropFilter: 'blur(2px)', overflowY: 'auto' }}>
      <div onClick={e => e.stopPropagation()} style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 16, width: '100%', maxWidth: 520, boxShadow: '0 20px 60px rgba(0,0,0,0.4)' }}>
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
