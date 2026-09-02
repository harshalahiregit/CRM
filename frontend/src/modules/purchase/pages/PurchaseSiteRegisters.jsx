import { useState, useEffect, useCallback, useMemo } from 'react'
import {
  Flame, Users, Truck, Plus, RefreshCw, Loader2, LogOut, AlertTriangle, Timer, UserCheck,
} from 'lucide-react'
import api from '@/lib/api'
import LoadError from '@/components/ui/LoadError'
import { Empty } from './PurchaseGateLog'
import {
  KIT3D_STYLE, inputStyle, Overlay, ModalFooter, InfoBox,
  Field, TextInput, SelectInput, StatusBadge,
} from '@/components/ui/kit3d'

/**
 * Site Registers — emergency drills, and the visitor and vehicle gate books.
 *
 * These are the SITE's registers, not Purchase's. The tables behind them
 * (emergency_drills, site_visitors, site_vehicles) carry no module prefix and
 * are scoped by TENANT: /purchase/drills and /tpv/drills are aliases onto one
 * SiteRegisterController reading one set of rows. A fire drill recorded here is
 * the same drill TPV shows, by design — a site has one evacuation record, and
 * two half-registers would each look complete while neither was.
 *
 * So nothing on this page claims the records are Purchase's own.
 */

// purchaseApi has no registers namespace yet, so the calls live here in exactly
// the shape it uses (`api.<verb>(…).then(r => r.data)`) — they lift into
// services/purchaseApi.js unchanged the moment that namespace lands.
const registersApi = {
  drills:          ()     => api.get('/purchase/drills').then(r => r.data),
  createDrill:     (data) => api.post('/purchase/drills', data).then(r => r.data),
  visitors:        ()     => api.get('/purchase/visitors').then(r => r.data),
  createVisitor:   (data) => api.post('/purchase/visitors', data).then(r => r.data),
  // Checkout takes no body — the controller stamps check_out_at with now().
  checkoutVisitor: (id)   => api.post(`/purchase/visitors/${id}/checkout`).then(r => r.data),
  vehicles:        ()     => api.get('/purchase/site-vehicles').then(r => r.data),
  createVehicle:   (data) => api.post('/purchase/site-vehicles', data).then(r => r.data),
  checkoutVehicle: (id)   => api.post(`/purchase/site-vehicles/${id}/checkout`).then(r => r.data),
}

/**
 * Why the vehicle form has no vendor picker.
 *
 * site_vehicles.vendor_id belongs to the SHARED vendor master
 * (App\Models\Vendor\Vendor, table `vendors`). The only vendor list Purchase
 * owns is /purchase/vendors — a different table (`purchase_vendors`) whose ids
 * are unrelated. The controller validates vendor_id as `nullable|integer` with
 * no `exists` rule, so posting a Purchase vendor id would not fail: it would
 * silently attach the vehicle to whichever shared vendor happens to hold that
 * number, and nothing downstream would ever flag it.
 *
 * There is no /purchase endpoint that lists shared vendors, so the field is
 * left unset here rather than guessed at. A vendor attached by TPV is still
 * DISPLAYED — reading a name back is safe, writing the wrong one is not.
 */

// EmergencyDrill::TYPES — the exact strings Rule::in validates against.
const DRILL_TYPES = ['Fire', 'Evacuation', 'Medical', 'Spill', 'Other']

const DRILL_CONFIG = {
  Fire:       { label: 'Fire',       color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
  Evacuation: { label: 'Evacuation', color: '#f97316', bg: 'rgba(249,115,22,0.15)' },
  Medical:    { label: 'Medical',    color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' },
  Spill:      { label: 'Spill',      color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Other:      { label: 'Other',      color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
}
const drillCfg = (t) => DRILL_CONFIG[t] || { label: t || 'Unspecified', color: '#94a3b8', bg: 'rgba(148,163,184,0.12)' }

// A closed entry. The visitor book and the vehicle book use different words for
// the same fact, because a guard reading either expects the one it uses.
const LEFT   = { label: 'Checked out', color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' }
const EXITED = { label: 'Exited',      color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' }

// `rfq-spin` is NOT defined globally — PurchaseRfqDetail injects its own copy,
// so every other page using that class renders a spinner that does not spin.
// This one brings its own keyframes rather than inherit a dead class.
const SPIN_STYLE = '@keyframes prRegSpin{to{transform:rotate(360deg)}}.pr-reg-spin{animation:prRegSpin .9s linear infinite}'

// All three list endpoints answer a { data: [] } envelope, but the same
// normalisation the permit and gate screens use is kept so a future unwrap in
// purchaseApi cannot turn this page blank.
const asArray = (r) => (Array.isArray(r?.data ?? r) ? (r.data ?? r) : [])

const fmtDateTime = (d) => (d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }) : '—')
const fmtTime     = (d) => (d ? new Date(d).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: false }) : '—')

// Evacuation time is stored in seconds; minutes are what a drill report quotes.
const fmtSeconds = (s) => {
  if (s == null || s === '') return '—'
  const n = Number(s)
  return n < 60 ? `${n}s` : `${Math.floor(n / 60)}m ${n % 60}s`
}

// The validation caps, mirrored so an over-long entry is caught before the
// round trip; the server still validates authoritatively.
const MAX_PARTICIPANTS = 100000
const MAX_EVAC_SECONDS = 100000

const apiError = (e, fallback) => {
  const errors = e?.response?.data?.errors
  if (errors) return Object.values(errors).flat().join(' ')
  return e?.response?.data?.message || fallback
}

const TABS = [
  { key: 'drills',   label: 'Emergency Drills', icon: Flame },
  { key: 'visitors', label: 'Visitors',         icon: Users },
  { key: 'vehicles', label: 'Vehicles',         icon: Truck },
]

/**
 * One register's rows, its spinner, and its error.
 *
 * `fetcher` is a dependency of the load callback, so every caller passes a
 * STABLE reference — the module-level registersApi methods, never an inline
 * arrow, which would rebuild the callback each render and re-fetch forever.
 */
function useRegister(fetcher) {
  const [rows, setRows]       = useState([])
  const [loading, setLoading] = useState(true)
  const [loadError, setError] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try { setRows(asArray(await fetcher())); setError(null) }
    catch (e) { setRows([]); setError(e) }
    finally { setLoading(false) }
  }, [fetcher])
  useEffect(() => { load() }, [load])

  return { rows, loading, loadError, load }
}

export default function PurchaseSiteRegisters() {
  const [tab, setTab] = useState('drills')

  return (
    <div style={{ padding: '24px 32px' }}>
      <style>{KIT3D_STYLE}{SPIN_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 16 }}>
        <Truck size={22} style={{ color: '#7C3AED' }} />
        <div>
          <h1 style={{ margin: 0, fontSize: 22, fontWeight: 900, color: 'var(--text-h)', letterSpacing: '-0.02em' }}>Site Registers</h1>
          {/* Say whose register this is up front. Somebody checking a visitor in
              here should know it lands on the site's gate book, not a copy. */}
          <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--text-muted)' }}>
            Emergency drills, visitors and vehicles — the site-wide gate registers, shared with TPV.
          </p>
        </div>
      </div>

      <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
        {TABS.map(t => {
          const on = tab === t.key
          return (
            <button key={t.key} onClick={() => setTab(t.key)}
              style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '8px 16px', borderRadius: 10, fontSize: 13, fontWeight: 700, cursor: 'pointer',
                border: `1px solid ${on ? 'rgba(124,58,237,0.5)' : 'var(--border)'}`,
                background: on ? 'rgba(124,58,237,0.15)' : 'var(--bg-card)',
                color: on ? '#a78bfa' : 'var(--text-muted)' }}>
              <t.icon size={14} /> {t.label}
            </button>
          )
        })}
      </div>

      {/* Each register unmounts with its tab, so switching back re-reads it —
          a gate book that is minutes stale is worse than a moment's spinner. */}
      {tab === 'drills' ? <Drills /> : tab === 'visitors' ? <Visitors /> : <Vehicles />}
    </div>
  )
}

/* ── Emergency drills ─────────────────────────────────────────────────────── */
function Drills() {
  const { rows, loading, loadError, load } = useRegister(registersApi.drills)
  const [creating, setCreating] = useState(false)

  // No stats endpoint exists for these registers, so the counters are folded
  // from the rows on screen.
  const stats = useMemo(() => {
    const timed = rows.filter(d => d.evacuation_seconds)
    return {
      total:        rows.length,
      participants: rows.reduce((sum, d) => sum + (d.participants || 0), 0),
      // Averaged over the drills that actually recorded a time — counting the
      // blanks as zero would report a site as evacuating faster than it does.
      avgEvac:      timed.length ? Math.round(timed.reduce((s, d) => s + d.evacuation_seconds, 0) / timed.length) : null,
    }
  }, [rows])

  const th = { textAlign: 'left', padding: '10px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
  const td = { padding: '11px 12px', borderBottom: '1px solid var(--border)', fontSize: 12.5, verticalAlign: 'middle' }

  return (
    <>
      <KpiRow cards={[
        { label: 'Drills Recorded',   value: stats.total,        color: '#7C3AED' },
        { label: 'Total Participants', value: stats.participants, color: '#0ea5e9' },
        { label: 'Avg Evacuation',    value: stats.avgEvac == null ? '—' : fmtSeconds(stats.avgEvac), color: '#10b981' },
      ]} />

      <Toolbar onRefresh={load} onAdd={() => setCreating(true)} addLabel="Record Drill" />

      {loadError ? (
        <LoadError error={loadError} onRetry={load} title="Could not load drills" />
      ) : loading ? (
        <Loading text="Loading drills…" />
      ) : rows.length === 0 ? (
        <Empty icon={Flame} title="No drills recorded"
          hint="An emergency drill is the only evidence the site can actually be cleared — record one and it joins the site's register." />
      ) : (
        <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead><tr>{['Type', 'Conducted', 'Location', 'Participants', 'Evacuation', 'Findings'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
              <tbody>
                {rows.map(d => (
                  <tr key={d.id} className="pr-li-row">
                    <td style={td}><StatusBadge cfg={drillCfg(d.drill_type)} /></td>
                    <td style={{ ...td, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>
                      {fmtDateTime(d.conducted_at)}
                      {d.conductor?.name && (
                        <div style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11 }}>
                          <UserCheck size={11} /> {d.conductor.name}
                        </div>
                      )}
                    </td>
                    <td style={{ ...td, color: 'var(--text-muted)' }}>{d.location || '—'}</td>
                    <td style={{ ...td, color: 'var(--text-h)', fontWeight: 700 }}>{d.participants ?? 0}</td>
                    <td style={{ ...td, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>
                      {d.evacuation_seconds
                        ? <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><Timer size={12} /> {fmtSeconds(d.evacuation_seconds)}</span>
                        : '—'}
                    </td>
                    <td style={{ ...td, color: 'var(--text-muted)', maxWidth: 320 }}>{d.findings || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {creating && <DrillModal onClose={() => setCreating(false)} onSaved={() => { setCreating(false); load() }} />}
    </>
  )
}

/* ── Visitor register ─────────────────────────────────────────────────────── */
function Visitors() {
  const { rows, loading, loadError, load } = useRegister(registersApi.visitors)
  const [creating, setCreating] = useState(false)
  // Which row's checkout is in flight. Held per-id rather than as one flag so a
  // second guard checking somebody else out is not blocked by the first.
  const [busyId, setBusyId] = useState(null)
  const [err, setErr] = useState('')

  const checkout = async (v) => {
    setBusyId(v.id); setErr('')
    try { await registersApi.checkoutVisitor(v.id); await load() }
    catch (e) { setErr(apiError(e, `Could not check out ${v.visitor_name}.`)) }
    finally { setBusyId(null) }
  }

  const stats = useMemo(() => {
    const inside = rows.filter(v => !v.check_out_at).length
    return { total: rows.length, inside, departed: rows.length - inside }
  }, [rows])

  const th = { textAlign: 'left', padding: '10px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
  const td = { padding: '11px 12px', borderBottom: '1px solid var(--border)', fontSize: 12.5, verticalAlign: 'middle' }

  return (
    <>
      <KpiRow cards={[
        { label: 'Visitors Logged', value: stats.total,    color: '#7C3AED' },
        { label: 'On Site Now',     value: stats.inside,   color: '#10b981' },
        { label: 'Checked Out',     value: stats.departed, color: '#94a3b8' },
      ]} />

      <Toolbar onRefresh={load} onAdd={() => setCreating(true)} addLabel="Check In Visitor" />

      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '0 0 12px' }}>{err}</p>}

      {loadError ? (
        <LoadError error={loadError} onRetry={load} title="Could not load the visitor register" />
      ) : loading ? (
        <Loading text="Loading visitors…" />
      ) : rows.length === 0 ? (
        <Empty icon={Users} title="No visitors logged"
          hint="The visitor book answers one question in an evacuation — who is on site who does not work here. Check somebody in to start it." />
      ) : (
        <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead><tr>{['Visitor', 'Purpose', 'Host', 'Badge', 'In', 'Out', ''].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
              <tbody>
                {rows.map(v => (
                  <tr key={v.id} className="pr-li-row">
                    <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)' }}>
                      {v.visitor_name}
                      {v.company && <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 500 }}>{v.company}</div>}
                      {v.contact && <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 500 }}>{v.contact}</div>}
                    </td>
                    <td style={{ ...td, color: 'var(--text-muted)', maxWidth: 240 }}>{v.purpose || '—'}</td>
                    <td style={{ ...td, color: 'var(--text-muted)' }}>{v.host || '—'}</td>
                    <td style={{ ...td, color: '#a78bfa', fontWeight: 700, whiteSpace: 'nowrap' }}>{v.badge_number || '—'}</td>
                    <td style={{ ...td, color: 'var(--text-h)', fontWeight: 600, whiteSpace: 'nowrap' }} title={fmtDateTime(v.check_in_at)}>{fmtTime(v.check_in_at)}</td>
                    <td style={{ ...td, color: v.check_out_at ? 'var(--text-h)' : 'var(--text-muted)', fontWeight: 600, whiteSpace: 'nowrap' }} title={v.check_out_at ? fmtDateTime(v.check_out_at) : undefined}>
                      {v.check_out_at ? fmtTime(v.check_out_at) : '—'}
                    </td>
                    <td style={{ ...td, textAlign: 'right' }}>
                      {v.check_out_at
                        ? <StatusBadge cfg={LEFT} />
                        : (
                          <button onClick={() => checkout(v)} disabled={busyId === v.id}
                            style={{ ...ghostBtn, padding: '6px 12px', fontSize: 12, cursor: busyId === v.id ? 'not-allowed' : 'pointer', opacity: busyId === v.id ? 0.6 : 1 }}>
                            {busyId === v.id ? <Loader2 size={13} className="pr-reg-spin" /> : <LogOut size={13} />} Check out
                          </button>
                        )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {creating && <VisitorModal onClose={() => setCreating(false)} onSaved={() => { setCreating(false); load() }} />}
    </>
  )
}

/* ── Vehicle register ─────────────────────────────────────────────────────── */
function Vehicles() {
  const { rows, loading, loadError, load } = useRegister(registersApi.vehicles)
  const [creating, setCreating] = useState(false)
  const [busyId, setBusyId] = useState(null)
  const [err, setErr] = useState('')

  const checkout = async (v) => {
    setBusyId(v.id); setErr('')
    try { await registersApi.checkoutVehicle(v.id); await load() }
    catch (e) { setErr(apiError(e, `Could not record the exit of ${v.vehicle_number}.`)) }
    finally { setBusyId(null) }
  }

  const stats = useMemo(() => {
    const inside = rows.filter(v => !v.check_out_at).length
    return {
      total:   rows.length,
      inside,
      // A vehicle whose fitness has lapsed and is still INSIDE is the one thing
      // this count is for — the ones that have already left cannot be acted on.
      unfit:   rows.filter(v => !v.check_out_at && v.fitness_valid === false).length,
    }
  }, [rows])

  const th = { textAlign: 'left', padding: '10px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
  const td = { padding: '11px 12px', borderBottom: '1px solid var(--border)', fontSize: 12.5, verticalAlign: 'middle' }

  return (
    <>
      <KpiRow cards={[
        { label: 'Vehicles Logged',   value: stats.total,  color: '#7C3AED' },
        { label: 'On Site Now',       value: stats.inside, color: '#10b981' },
        { label: 'Unfit On Site',     value: stats.unfit,  color: '#ef4444' },
      ]} />

      <Toolbar onRefresh={load} onAdd={() => setCreating(true)} addLabel="Check In Vehicle" />

      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '0 0 12px' }}>{err}</p>}

      {loadError ? (
        <LoadError error={loadError} onRetry={load} title="Could not load the vehicle register" />
      ) : loading ? (
        <Loading text="Loading vehicles…" />
      ) : rows.length === 0 ? (
        <Empty icon={Truck} title="No vehicles logged"
          hint="Every vehicle through the gate, its driver, and whether its fitness certificate stands. Check one in to start the book." />
      ) : (
        <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead><tr>{['Vehicle', 'Driver', 'Vendor', 'Purpose', 'Fitness', 'In', 'Out', ''].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
              <tbody>
                {rows.map(v => (
                  <tr key={v.id} className="pr-li-row">
                    <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)', whiteSpace: 'nowrap' }}>
                      {v.vehicle_number}
                      {v.vehicle_type && <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 500 }}>{v.vehicle_type}</div>}
                    </td>
                    <td style={{ ...td, color: 'var(--text-muted)' }}>{v.driver_name || '—'}</td>
                    {/* vendor_id points at the SHARED vendor master — shown when a
                        record carries one, never set from here. See the note above. */}
                    <td style={{ ...td, color: 'var(--text-muted)' }}>{v.vendor?.company_name || '—'}</td>
                    <td style={{ ...td, color: 'var(--text-muted)', maxWidth: 220 }}>{v.purpose || '—'}</td>
                    <td style={td}>
                      {/* fitness_valid defaults to TRUE in the column, so a false
                          here was recorded deliberately and is worth shouting. */}
                      {v.fitness_valid === false
                        ? <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '3px 10px', borderRadius: 20, fontSize: 11, fontWeight: 700, background: 'rgba(239,68,68,0.15)', color: '#ef4444', border: '1px solid rgba(239,68,68,0.4)', whiteSpace: 'nowrap' }}>
                            <AlertTriangle size={11} /> Invalid
                          </span>
                        : <StatusBadge cfg={{ label: 'Valid', color: '#10b981', bg: 'rgba(16,185,129,0.15)' }} />}
                    </td>
                    <td style={{ ...td, color: 'var(--text-h)', fontWeight: 600, whiteSpace: 'nowrap' }} title={fmtDateTime(v.check_in_at)}>{fmtTime(v.check_in_at)}</td>
                    <td style={{ ...td, color: v.check_out_at ? 'var(--text-h)' : 'var(--text-muted)', fontWeight: 600, whiteSpace: 'nowrap' }} title={v.check_out_at ? fmtDateTime(v.check_out_at) : undefined}>
                      {v.check_out_at ? fmtTime(v.check_out_at) : '—'}
                    </td>
                    <td style={{ ...td, textAlign: 'right' }}>
                      {v.check_out_at
                        ? <StatusBadge cfg={EXITED} />
                        : (
                          <button onClick={() => checkout(v)} disabled={busyId === v.id}
                            style={{ ...ghostBtn, padding: '6px 12px', fontSize: 12, cursor: busyId === v.id ? 'not-allowed' : 'pointer', opacity: busyId === v.id ? 0.6 : 1 }}>
                            {busyId === v.id ? <Loader2 size={13} className="pr-reg-spin" /> : <LogOut size={13} />} Exit
                          </button>
                        )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {creating && <VehicleModal onClose={() => setCreating(false)} onSaved={() => { setCreating(false); load() }} />}
    </>
  )
}

/* ── Record a drill ───────────────────────────────────────────────────────── */
function DrillModal({ onClose, onSaved }) {
  const [f, setF] = useState({
    drill_type: 'Fire', conducted_at: '', location: '',
    participants: '', evacuation_seconds: '', findings: '',
  })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))

  const save = async () => {
    if (!f.drill_type) { setErr('A drill type is required.'); return }
    if (f.participants !== '' && (Number(f.participants) < 0 || Number(f.participants) > MAX_PARTICIPANTS)) {
      setErr(`Participants must be between 0 and ${MAX_PARTICIPANTS}.`); return
    }
    if (f.evacuation_seconds !== '' && (Number(f.evacuation_seconds) < 0 || Number(f.evacuation_seconds) > MAX_EVAC_SECONDS)) {
      setErr(`Evacuation time must be between 0 and ${MAX_EVAC_SECONDS} seconds.`); return
    }
    setBusy(true); setErr('')
    try {
      // Blank optional fields are dropped rather than posted as '' — the rules
      // are nullable, and an empty string is not "not supplied". conducted_at
      // omitted means the controller stamps now().
      await registersApi.createDrill(Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== null)))
      onSaved()
    } catch (e) { setErr(apiError(e, 'Could not save the drill.')) }
    finally { setBusy(false) }
  }

  return (
    <Overlay onClose={() => !busy && onClose()} width={620}>
      <ModalHeading icon={Flame} title="Record Emergency Drill"
        sub="What was practised, how many took part, and how long the site took to clear." />

      <InfoBox>
        This is the site-wide drill register. The record is stored against the tenant, not this module — TPV shows
        the same row.
      </InfoBox>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <Field label="Drill type *">
          <SelectInput value={f.drill_type} onChange={set('drill_type')} options={DRILL_TYPES} />
        </Field>
        <Field label="Conducted at">
          <TextInput type="datetime-local" value={f.conducted_at} onChange={set('conducted_at')} />
        </Field>
        <Field label="Location"><TextInput value={f.location} onChange={set('location')} maxLength={200} /></Field>
        <Field label="Participants">
          <TextInput type="number" min={0} max={MAX_PARTICIPANTS} value={f.participants} onChange={set('participants')} placeholder="0" />
        </Field>
        {/* Seconds, because the column is seconds — a drill measured in minutes
            and typed here as "3" would be recorded as three seconds. */}
        <Field label="Evacuation time (seconds)">
          <TextInput type="number" min={0} max={MAX_EVAC_SECONDS} value={f.evacuation_seconds} onChange={set('evacuation_seconds')} />
        </Field>
        <Field label="Findings" full>
          <textarea value={f.findings} onChange={set('findings')} rows={3} style={{ ...inputStyle, resize: 'vertical' }}
            placeholder="What went wrong, what has to change before the next one." />
        </Field>
      </div>

      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '12px 0 0' }}>{err}</p>}
      <ModalFooter onClose={onClose} onConfirm={save} loading={busy} disabled={!f.drill_type} confirmLabel="Save Drill" />
    </Overlay>
  )
}

/* ── Check in a visitor ───────────────────────────────────────────────────── */
function VisitorModal({ onClose, onSaved }) {
  const [f, setF] = useState({ visitor_name: '', company: '', purpose: '', host: '', contact: '', badge_number: '' })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))

  const save = async () => {
    if (!f.visitor_name.trim()) { setErr('A visitor name is required.'); return }
    setBusy(true); setErr('')
    try {
      await registersApi.createVisitor(Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== null)))
      onSaved()
    } catch (e) { setErr(apiError(e, 'Could not check the visitor in.')) }
    finally { setBusy(false) }
  }

  return (
    <Overlay onClose={() => !busy && onClose()} width={620}>
      <ModalHeading icon={Users} title="Check In Visitor"
        sub="Recorded as arriving now — the gate book is written at the gate." />

      {/* check_in_at is stamped server-side with now() and is not a request
          field, so there is deliberately no time input: an arrival typed in
          after the fact could not be trusted anyway. */}
      <InfoBox>
        The arrival time is stamped now, and this is the site-wide visitor book — TPV shows the same row.
      </InfoBox>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <Field label="Visitor name *" full>
          <TextInput value={f.visitor_name} onChange={set('visitor_name')} maxLength={160} />
        </Field>
        <Field label="Company"><TextInput value={f.company} onChange={set('company')} maxLength={160} /></Field>
        <Field label="Host"><TextInput value={f.host} onChange={set('host')} maxLength={160} placeholder="Who they are here to see" /></Field>
        <Field label="Contact"><TextInput value={f.contact} onChange={set('contact')} maxLength={60} placeholder="Phone or email" /></Field>
        <Field label="Badge #"><TextInput value={f.badge_number} onChange={set('badge_number')} maxLength={60} /></Field>
        <Field label="Purpose" full><TextInput value={f.purpose} onChange={set('purpose')} maxLength={200} /></Field>
      </div>

      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '12px 0 0' }}>{err}</p>}
      <ModalFooter onClose={onClose} onConfirm={save} loading={busy}
        disabled={!f.visitor_name.trim()} confirmLabel="Check In" />
    </Overlay>
  )
}

/* ── Check in a vehicle ───────────────────────────────────────────────────── */
function VehicleModal({ onClose, onSaved }) {
  // fitness_valid is a real boolean on the request (`sometimes|boolean`) and the
  // column defaults to TRUE, so it starts checked and is sent explicitly — the
  // register's red "Invalid" badge is unreachable without this field, which is
  // why the TPV form's omission left it as decoration.
  const [f, setF] = useState({ vehicle_number: '', vehicle_type: '', driver_name: '', purpose: '', fitness_valid: true })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))

  const save = async () => {
    if (!f.vehicle_number.trim()) { setErr('A vehicle number is required.'); return }
    setBusy(true); setErr('')
    try {
      // The boolean is kept whatever its value — false is a deliberate answer,
      // not a blank, so it must survive the drop-empties pass below.
      const { fitness_valid, ...rest } = f
      await registersApi.createVehicle({
        ...Object.fromEntries(Object.entries(rest).filter(([, v]) => v !== '' && v !== null)),
        fitness_valid,
      })
      onSaved()
    } catch (e) { setErr(apiError(e, 'Could not check the vehicle in.')) }
    finally { setBusy(false) }
  }

  return (
    <Overlay onClose={() => !busy && onClose()} width={620}>
      <ModalHeading icon={Truck} title="Check In Vehicle"
        sub="Recorded as entering now — the gate book is written at the gate." />

      <InfoBox>
        The entry time is stamped now, and this is the site-wide vehicle book — TPV shows the same row.
      </InfoBox>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <Field label="Vehicle number *">
          <TextInput value={f.vehicle_number} onChange={set('vehicle_number')} maxLength={40} placeholder="Registration plate" />
        </Field>
        <Field label="Type"><TextInput value={f.vehicle_type} onChange={set('vehicle_type')} maxLength={60} placeholder="e.g. Tipper, Crane, Van" /></Field>
        <Field label="Driver"><TextInput value={f.driver_name} onChange={set('driver_name')} maxLength={160} /></Field>
        <Field label="Purpose"><TextInput value={f.purpose} onChange={set('purpose')} maxLength={200} /></Field>
        <Field label="Fitness certificate" full>
          <label style={{ display: 'inline-flex', alignItems: 'center', gap: 8, fontSize: 13, color: 'var(--text-h)', cursor: 'pointer' }}>
            <input type="checkbox" checked={f.fitness_valid}
              onChange={e => setF(p => ({ ...p, fitness_valid: e.target.checked }))}
              style={{ width: 16, height: 16, accentColor: '#7C3AED', cursor: 'pointer' }} />
            Fitness certificate is valid
          </label>
          {!f.fitness_valid && (
            <p style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 11.5, color: '#f59e0b', margin: '8px 0 0' }}>
              <AlertTriangle size={12} /> This vehicle will be flagged in the register for as long as it is on site.
            </p>
          )}
        </Field>
      </div>

      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '12px 0 0' }}>{err}</p>}
      <ModalFooter onClose={onClose} onConfirm={save} loading={busy}
        disabled={!f.vehicle_number.trim()} confirmLabel="Check In" />
    </Overlay>
  )
}

/* ── shared bits ── */
const primaryBtn = { display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 16px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }
const ghostBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12.5, fontWeight: 700 }

// The counters are folded from the rows on screen, so they are never clickable
// filters — there is nothing behind them the page has not already loaded.
function KpiRow({ cards }) {
  return (
    <div style={{ display: 'grid', gridTemplateColumns: `repeat(${cards.length},1fr)`, gap: 12, marginBottom: 18 }}>
      {cards.map(s => (
        <div key={s.label} className="pr-kpi" style={{ textAlign: 'center', cursor: 'default' }}>
          <div style={{ fontSize: 24, fontWeight: 900, color: s.color }}>{s.value === 0 ? 0 : (s.value || '—')}</div>
          <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 600, marginTop: 3 }}>{s.label}</div>
        </div>
      ))}
    </div>
  )
}

function Toolbar({ onRefresh, onAdd, addLabel }) {
  return (
    <div className="pr-glass" style={{ padding: '14px 18px', marginBottom: 18, display: 'flex', gap: 10, alignItems: 'center', justifyContent: 'flex-end', borderRadius: 14 }}>
      <button onClick={onRefresh} style={ghostBtn}><RefreshCw size={14} /></button>
      <button onClick={onAdd} style={primaryBtn}><Plus size={15} /> {addLabel}</button>
    </div>
  )
}

function Loading({ text }) {
  return <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}><Loader2 size={18} className="pr-reg-spin" /> {text}</div>
}

function ModalHeading({ icon: Icon, title, sub }) {
  return (
    <>
      <h2 style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>
        <Icon size={18} style={{ color: '#7C3AED' }} /> {title}
      </h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 16px' }}>{sub}</p>
    </>
  )
}
