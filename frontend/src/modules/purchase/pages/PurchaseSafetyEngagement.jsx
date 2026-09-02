import { useState, useEffect, useCallback, useMemo } from 'react'
import {
  Eye, Users, Plus, RefreshCw, Loader2, CheckCircle, Clock, UserCheck,
} from 'lucide-react'
import api from '@/lib/api'
import LoadError from '@/components/ui/LoadError'
import { Empty } from './PurchaseGateLog'
import {
  KIT3D_STYLE, inputStyle, Overlay, ModalFooter, InfoBox,
  Field, TextInput, SelectInput, StatusBadge,
} from '@/components/ui/kit3d'

/**
 * Safety Engagement — behaviour-based observations and toolbox talks.
 *
 * These are the SITE's leading indicators, not Purchase's. The tables behind
 * them (safety_observations, toolbox_talks) carry no module prefix and are
 * scoped by TENANT: /purchase/observations and /tpv/observations are aliases
 * onto one SafetyEngagementController reading one set of rows. An observation
 * logged here is the same observation TPV shows, by design — a near miss is a
 * near miss whichever module the person raising it happened to be standing in,
 * and two half-registers would each look complete while neither was.
 *
 * So nothing on this page claims the records are Purchase's own.
 */

// purchaseApi has no safety namespace yet, so the calls live here in exactly
// the shape it uses (`api.<verb>(…).then(r => r.data)`) — they lift into
// services/purchaseApi.js unchanged the moment that namespace lands.
const safetyApi = {
  observations:      (params = {}) => api.get('/purchase/observations', { params }).then(r => r.data),
  createObservation: (data)        => api.post('/purchase/observations', data).then(r => r.data),
  // action_taken is optional: the controller keeps whatever was already recorded
  // when it is omitted, so closing never blanks a note somebody else wrote.
  closeObservation:  (id, data = {}) => api.post(`/purchase/observations/${id}/close`, data).then(r => r.data),
  talks:             (params = {}) => api.get('/purchase/toolbox-talks', { params }).then(r => r.data),
  createTalk:        (data)        => api.post('/purchase/toolbox-talks', data).then(r => r.data),
}

/**
 * Why there is no vendor picker on the forms.
 *
 * safety_observations.vendor_id and toolbox_talks.vendor_id belong to the
 * SHARED vendor master (App\Models\Vendor\Vendor, table `vendors`). The only
 * vendor list Purchase owns is /purchase/vendors — a different table
 * (`purchase_vendors`) whose ids are unrelated. The controller validates
 * vendor_id as `nullable|integer` with no `exists` rule, so posting a Purchase
 * vendor id would not fail: it would silently attach the observation to
 * whichever shared vendor happens to hold that number, and nothing downstream
 * would ever flag it.
 *
 * There is no /purchase endpoint that lists shared vendors, so the field is
 * left unset here rather than guessed at. A vendor attached by TPV is still
 * DISPLAYED — reading a name back is safe, writing the wrong one is not.
 */

// SafetyObservation::CATEGORIES / ::SEVERITIES — the exact strings Rule::in
// validates against, humanised only for display.
const CATEGORIES = ['Unsafe_Act', 'Unsafe_Condition', 'Positive', 'Near_Miss']
const SEVERITIES = ['Low', 'Medium', 'High']

const CATEGORY_CONFIG = {
  Unsafe_Act:       { label: 'Unsafe Act',       color: '#f97316', bg: 'rgba(249,115,22,0.15)' },
  Unsafe_Condition: { label: 'Unsafe Condition', color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Positive:         { label: 'Positive',         color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Near_Miss:        { label: 'Near Miss',        color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
const SEVERITY_CONFIG = {
  Low:    { label: 'Low',    color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Medium: { label: 'Medium', color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  High:   { label: 'High',   color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
// The controller only ever writes 'Open' on create and 'Closed' on close, so
// these two are the whole vocabulary — anything else is a row we did not write.
const STATUS_CONFIG = {
  Open:   { label: 'Open',   color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Closed: { label: 'Closed', color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
}
const pretty = (s) => (s || '').replace(/_/g, ' ')
const catCfg = (c) => CATEGORY_CONFIG[c] || { label: pretty(c) || 'Uncategorised', color: '#94a3b8', bg: 'rgba(148,163,184,0.12)' }
const sevCfg = (s) => SEVERITY_CONFIG[s] || { label: s || 'Not rated', color: '#94a3b8', bg: 'rgba(148,163,184,0.12)' }
const statusCfg = (s) => STATUS_CONFIG[s] || { label: s || 'Unknown', color: '#94a3b8', bg: 'rgba(148,163,184,0.12)' }

// `rfq-spin` is NOT defined globally — PurchaseRfqDetail injects its own copy,
// so every other page using that class renders a spinner that does not spin.
// This one brings its own keyframes rather than inherit a dead class.
const SPIN_STYLE = '@keyframes prSafetySpin{to{transform:rotate(360deg)}}.pr-safety-spin{animation:prSafetySpin .9s linear infinite}'

// Both list endpoints answer a { data: [] } envelope, but the same normalisation
// the permit and gate screens use is kept so a future unwrap in purchaseApi
// cannot turn this page blank.
const asArray = (r) => (Array.isArray(r?.data ?? r) ? (r.data ?? r) : [])

const fmtDateTime = (d) => (d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }) : '—')

// The validation caps, mirrored so an over-long entry is caught before the round
// trip; the server still validates authoritatively.
const MAX_ATTENDEES = 5000
const MAX_MINUTES   = 600

const apiError = (e, fallback) => {
  const errors = e?.response?.data?.errors
  if (errors) return Object.values(errors).flat().join(' ')
  return e?.response?.data?.message || fallback
}

const TABS = [
  { key: 'observations', label: 'Observations',  icon: Eye },
  { key: 'talks',        label: 'Toolbox Talks', icon: Users },
]

export default function PurchaseSafetyEngagement() {
  const [tab, setTab] = useState('observations')

  return (
    <div style={{ padding: '24px 32px' }}>
      <style>{KIT3D_STYLE}{SPIN_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 16 }}>
        <Eye size={22} style={{ color: '#7C3AED' }} />
        <div>
          <h1 style={{ margin: 0, fontSize: 22, fontWeight: 900, color: 'var(--text-h)', letterSpacing: '-0.02em' }}>Safety Engagement</h1>
          {/* Say whose register this is up front. Somebody logging a near miss
              here should know it lands on the site's record, not a Purchase copy. */}
          <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--text-muted)' }}>
            Observations and toolbox talks — the site-wide safety register, shared with TPV.
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

      {tab === 'observations' ? <Observations /> : <Talks />}
    </div>
  )
}

/* ── Observations ─────────────────────────────────────────────────────────── */
function Observations() {
  const [rows, setRows]       = useState([])
  const [loading, setLoading] = useState(true)
  const [loadError, setError] = useState(null)
  const [status, setStatus]   = useState('')
  const [creating, setCreating] = useState(false)
  // Which observation is being closed. Closing collects a note, so it is a
  // modal rather than the browser prompt() TPV uses — a prompt cannot be
  // cancelled cleanly, cannot show what is being closed, and is unstyled.
  const [closing, setClosing] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      // `status` is a server-side filter on the endpoint, so the rows and any
      // count of them always agree. Blank means "no filter", not status ''.
      setRows(asArray(await safetyApi.observations(status ? { status } : {})))
      setError(null)
    } catch (e) { setRows([]); setError(e) }
    finally { setLoading(false) }
  }, [status])
  useEffect(() => { load() }, [load])

  // No stats endpoint exists for these registers, so the counters are folded
  // from the rows on screen. They therefore describe the CURRENT filter — which
  // is why the filter is not one of the things they can be clicked to change.
  const stats = useMemo(() => ({
    total:    rows.length,
    open:     rows.filter(o => o.status === 'Open').length,
    closed:   rows.filter(o => o.status === 'Closed').length,
    highOpen: rows.filter(o => o.status === 'Open' && o.severity === 'High').length,
  }), [rows])

  const th = { textAlign: 'left', padding: '10px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
  const td = { padding: '11px 12px', borderBottom: '1px solid var(--border)', fontSize: 12.5, verticalAlign: 'middle' }

  return (
    <>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 12, marginBottom: 18 }}>
        {[
          { label: 'Observations',     value: stats.total,    color: '#7C3AED' },
          { label: 'Open',             value: stats.open,     color: '#f59e0b' },
          { label: 'Closed',           value: stats.closed,   color: '#10b981' },
          { label: 'High Sev. Open',   value: stats.highOpen, color: '#ef4444' },
        ].map(s => (
          <div key={s.label} className="pr-kpi" style={{ textAlign: 'center', cursor: 'default' }}>
            <div style={{ fontSize: 24, fontWeight: 900, color: s.color }}>{s.value || 0}</div>
            <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 600, marginTop: 3 }}>{s.label}</div>
          </div>
        ))}
      </div>

      <div className="pr-glass" style={{ padding: '14px 18px', marginBottom: 18, display: 'flex', gap: 12, alignItems: 'center', flexWrap: 'wrap', borderRadius: 14 }}>
        <select value={status} onChange={e => setStatus(e.target.value)} style={{ ...inputStyle, width: 'auto', cursor: 'pointer' }}>
          <option value="">All observations</option>
          <option value="Open">Open</option>
          <option value="Closed">Closed</option>
        </select>
        <span style={{ marginLeft: 'auto', display: 'flex', gap: 10, alignItems: 'center' }}>
          <button onClick={load} style={ghostBtn}><RefreshCw size={14} /></button>
          <button onClick={() => setCreating(true)} style={primaryBtn}><Plus size={15} /> Log Observation</button>
        </span>
      </div>

      {loadError ? (
        <LoadError error={loadError} onRetry={load} title="Could not load observations" />
      ) : loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}><Loader2 size={18} className="pr-safety-spin" /> Loading observations…</div>
      ) : rows.length === 0 ? (
        <Empty icon={Eye} title="No observations logged"
          hint="A safety observation is a leading indicator — what was seen, before it becomes an incident. Log one and it joins the site's register." />
      ) : (
        <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead><tr>{['Observation', 'Category', 'Severity', 'Where', 'Observed', 'Status', ''].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
              <tbody>
                {rows.map(o => (
                  <tr key={o.id} className="pr-li-row">
                    <td style={{ ...td, color: 'var(--text-h)', fontWeight: 600, maxWidth: 380 }}>
                      {o.description}
                      {/* The action, once there is one — on a closed row this is
                          the whole point of the record. */}
                      {o.action_taken && (
                        <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 500, marginTop: 2 }}>
                          <strong style={{ color: '#a78bfa' }}>Action:</strong> {o.action_taken}
                        </div>
                      )}
                    </td>
                    <td style={td}><StatusBadge cfg={catCfg(o.category)} /></td>
                    <td style={td}><StatusBadge cfg={sevCfg(o.severity)} /></td>
                    <td style={{ ...td, color: 'var(--text-muted)' }}>
                      {o.location || '—'}
                      {/* vendor_id points at the SHARED vendor master, so it is
                          shown when a record carries one but never invented here
                          — see the create modal for why. */}
                      {o.vendor?.company_name && <div style={{ fontSize: 11 }}>{o.vendor.company_name}</div>}
                    </td>
                    <td style={{ ...td, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>
                      {fmtDateTime(o.observed_at)}
                      {o.observer?.name && (
                        <div style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11 }}>
                          <UserCheck size={11} /> {o.observer.name}
                        </div>
                      )}
                    </td>
                    <td style={td}>
                      <StatusBadge cfg={statusCfg(o.status)} />
                      {o.closed_at && <div style={{ fontSize: 10.5, color: 'var(--text-muted)', marginTop: 2, whiteSpace: 'nowrap' }}>{fmtDateTime(o.closed_at)}</div>}
                    </td>
                    <td style={{ ...td, textAlign: 'right' }}>
                      {o.status === 'Open' && (
                        <button onClick={() => setClosing(o)} style={{ ...ghostBtn, padding: '6px 12px', fontSize: 12 }}>
                          <CheckCircle size={13} /> Close
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

      {creating && <ObservationModal onClose={() => setCreating(false)} onSaved={() => { setCreating(false); load() }} />}
      {closing && <CloseModal observation={closing} onClose={() => setClosing(null)} onSaved={() => { setClosing(null); load() }} />}
    </>
  )
}

/* ── Toolbox talks ────────────────────────────────────────────────────────── */
function Talks() {
  const [rows, setRows]       = useState([])
  const [loading, setLoading] = useState(true)
  const [loadError, setError] = useState(null)
  const [creating, setCreating] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try { setRows(asArray(await safetyApi.talks())); setError(null) }
    catch (e) { setRows([]); setError(e) }
    finally { setLoading(false) }
  }, [])
  useEffect(() => { load() }, [load])

  const stats = useMemo(() => {
    const withDuration = rows.filter(t => t.duration_minutes)
    return {
      total:     rows.length,
      attendees: rows.reduce((sum, t) => sum + (t.attendee_count || 0), 0),
      // Averaged over the talks that actually recorded a duration — counting the
      // blanks as zero would report every register with a few skipped fields as
      // half as long as it was.
      avgMins:   withDuration.length ? Math.round(withDuration.reduce((s, t) => s + t.duration_minutes, 0) / withDuration.length) : 0,
    }
  }, [rows])

  const th = { textAlign: 'left', padding: '10px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
  const td = { padding: '11px 12px', borderBottom: '1px solid var(--border)', fontSize: 12.5, verticalAlign: 'middle' }

  return (
    <>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 12, marginBottom: 18 }}>
        {[
          { label: 'Talks Held',      value: stats.total,     color: '#7C3AED' },
          { label: 'Total Attendees', value: stats.attendees, color: '#0ea5e9' },
          { label: 'Avg Minutes',     value: stats.avgMins,   color: '#10b981' },
        ].map(s => (
          <div key={s.label} className="pr-kpi" style={{ textAlign: 'center', cursor: 'default' }}>
            <div style={{ fontSize: 24, fontWeight: 900, color: s.color }}>{s.value || 0}</div>
            <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 600, marginTop: 3 }}>{s.label}</div>
          </div>
        ))}
      </div>

      <div className="pr-glass" style={{ padding: '14px 18px', marginBottom: 18, display: 'flex', gap: 10, alignItems: 'center', justifyContent: 'flex-end', borderRadius: 14 }}>
        <button onClick={load} style={ghostBtn}><RefreshCw size={14} /></button>
        <button onClick={() => setCreating(true)} style={primaryBtn}><Plus size={15} /> Record Talk</button>
      </div>

      {loadError ? (
        <LoadError error={loadError} onRetry={load} title="Could not load toolbox talks" />
      ) : loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}><Loader2 size={18} className="pr-safety-spin" /> Loading toolbox talks…</div>
      ) : rows.length === 0 ? (
        <Empty icon={Users} title="No toolbox talks recorded"
          hint="A toolbox talk is the briefing before the work — record the topic and who was there, and it joins the site's register." />
      ) : (
        <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead><tr>{['Topic', 'Where', 'Held', 'Attendees', 'Duration'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
              <tbody>
                {rows.map(t => (
                  <tr key={t.id} className="pr-li-row">
                    <td style={{ ...td, color: 'var(--text-h)', fontWeight: 700, maxWidth: 360 }}>
                      {t.topic}
                      {t.notes && <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 500, marginTop: 2 }}>{t.notes}</div>}
                    </td>
                    <td style={{ ...td, color: 'var(--text-muted)' }}>
                      {t.location || '—'}
                      {t.vendor?.company_name && <div style={{ fontSize: 11 }}>{t.vendor.company_name}</div>}
                    </td>
                    <td style={{ ...td, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>
                      {fmtDateTime(t.held_at)}
                      {t.conductor?.name && (
                        <div style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11 }}>
                          <UserCheck size={11} /> {t.conductor.name}
                        </div>
                      )}
                    </td>
                    <td style={{ ...td, color: 'var(--text-h)', fontWeight: 700 }}>{t.attendee_count ?? 0}</td>
                    <td style={{ ...td, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>
                      {t.duration_minutes ? <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><Clock size={12} /> {t.duration_minutes} min</span> : '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {creating && <TalkModal onClose={() => setCreating(false)} onSaved={() => { setCreating(false); load() }} />}
    </>
  )
}

/* ── Log an observation ───────────────────────────────────────────────────── */
function ObservationModal({ onClose, onSaved }) {
  const [f, setF] = useState({
    category: 'Unsafe_Act', severity: 'Low', observed_at: '',
    location: '', description: '', action_taken: '',
  })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))

  const save = async () => {
    if (!f.description.trim()) { setErr('A description is required — the observation IS the description.'); return }
    setBusy(true); setErr('')
    try {
      // Blank optional fields are dropped rather than posted as '' — the rules
      // are nullable, and an empty string is not "not supplied". observed_at
      // omitted means the controller stamps now().
      await safetyApi.createObservation(Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== null)))
      onSaved()
    } catch (e) { setErr(apiError(e, 'Could not save the observation.')) }
    finally { setBusy(false) }
  }

  return (
    <Overlay onClose={() => !busy && onClose()} width={620}>
      <h2 style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>
        <Eye size={18} style={{ color: '#7C3AED' }} /> Log Observation
      </h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 16px' }}>
        What was seen, and what was done about it. Left open until somebody closes it.
      </p>

      {/* The one thing a Purchase user could reasonably get wrong: assuming this
          is a private log. It is not, and pretending otherwise would let somebody
          write something here they would not write on the site record. */}
      <InfoBox>
        This is the site-wide safety register. The observation is stored against the tenant, not this module —
        TPV shows the same row.
      </InfoBox>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <Field label="Category *">
          <SelectInput value={f.category} onChange={set('category')} pairs options={CATEGORIES.map(c => [c, pretty(c)])} />
        </Field>
        <Field label="Severity">
          <SelectInput value={f.severity} onChange={set('severity')} options={SEVERITIES} />
        </Field>
        {/* The list column renders observed_at, and the controller accepts it —
            without a field here every observation would be stamped "now", which
            is wrong for anything written up at the end of a shift. */}
        <Field label="Observed at">
          <TextInput type="datetime-local" value={f.observed_at} onChange={set('observed_at')} />
        </Field>
        <Field label="Location">
          <TextInput value={f.location} onChange={set('location')} maxLength={200} placeholder="e.g. Block B, Level 3" />
        </Field>
        <Field label="Description *" full>
          <textarea value={f.description} onChange={set('description')} rows={3} style={{ ...inputStyle, resize: 'vertical' }}
            placeholder="What was observed — plainly enough that somebody who was not there can act on it." />
        </Field>
        <Field label="Action taken" full>
          <textarea value={f.action_taken} onChange={set('action_taken')} rows={2} style={{ ...inputStyle, resize: 'vertical' }}
            placeholder="Optional now — it can also be recorded when the observation is closed." />
        </Field>
      </div>

      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '12px 0 0' }}>{err}</p>}
      <ModalFooter onClose={onClose} onConfirm={save} loading={busy}
        disabled={!f.description.trim()} confirmLabel="Save Observation" />
    </Overlay>
  )
}

/**
 * Close an observation.
 *
 * The endpoint takes an optional action_taken and KEEPS the existing note when
 * none is sent, so the box is seeded with whatever is already recorded: leaving
 * it untouched changes nothing, and clearing it deliberately is the one case
 * that would otherwise be indistinguishable from not typing.
 */
function CloseModal({ observation, onClose, onSaved }) {
  const [action, setAction] = useState(observation.action_taken || '')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const save = async () => {
    setBusy(true); setErr('')
    try {
      // Unchanged text is not resent — the server would only write back what it
      // already holds, and the round trip says nothing.
      const changed = action.trim() !== (observation.action_taken || '').trim()
      await safetyApi.closeObservation(observation.id, changed ? { action_taken: action.trim() || null } : {})
      onSaved()
    } catch (e) { setErr(apiError(e, 'Could not close the observation.')) }
    finally { setBusy(false) }
  }

  return (
    <Overlay onClose={() => !busy && onClose()} width={520}>
      <h2 style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--text-h)', margin: '0 0 10px', fontSize: 18, fontWeight: 800 }}>
        <CheckCircle size={18} style={{ color: '#10b981' }} /> Close Observation
      </h2>

      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 10 }}>
        <StatusBadge cfg={catCfg(observation.category)} />
        <StatusBadge cfg={sevCfg(observation.severity)} />
      </div>
      <p style={{ fontSize: 13, color: 'var(--text-h)', fontWeight: 600, margin: '0 0 4px' }}>{observation.description}</p>
      <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '0 0 16px' }}>
        {observation.location || 'No location'} · {fmtDateTime(observation.observed_at)}
      </p>

      <Field label="Action taken" full>
        <textarea value={action} onChange={e => setAction(e.target.value)} rows={3} autoFocus
          style={{ ...inputStyle, resize: 'vertical' }}
          placeholder="What was done. Optional — closing without a note keeps whatever is already recorded." />
      </Field>

      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '12px 0 0' }}>{err}</p>}
      <ModalFooter onClose={onClose} onConfirm={save} loading={busy} confirmLabel="Close Observation" color="#10b981" />
    </Overlay>
  )
}

/* ── Record a toolbox talk ────────────────────────────────────────────────── */
function TalkModal({ onClose, onSaved }) {
  const [f, setF] = useState({
    topic: '', held_at: '', location: '', attendee_count: '', duration_minutes: '', notes: '',
  })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))

  const save = async () => {
    if (!f.topic.trim()) { setErr('A topic is required.'); return }
    // Mirrored from the controller's integer bounds so the common mistakes are
    // caught before a round trip; the server still validates authoritatively.
    if (f.attendee_count !== '' && (Number(f.attendee_count) < 0 || Number(f.attendee_count) > MAX_ATTENDEES)) {
      setErr(`Attendees must be between 0 and ${MAX_ATTENDEES}.`); return
    }
    if (f.duration_minutes !== '' && (Number(f.duration_minutes) < 0 || Number(f.duration_minutes) > MAX_MINUTES)) {
      setErr(`Duration must be between 0 and ${MAX_MINUTES} minutes.`); return
    }
    setBusy(true); setErr('')
    try {
      await safetyApi.createTalk(Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== null)))
      onSaved()
    } catch (e) { setErr(apiError(e, 'Could not save the talk.')) }
    finally { setBusy(false) }
  }

  return (
    <Overlay onClose={() => !busy && onClose()} width={620}>
      <h2 style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>
        <Users size={18} style={{ color: '#7C3AED' }} /> Record Toolbox Talk
      </h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 16px' }}>
        The pre-work briefing, written down — topic, when, and how many heard it.
      </p>

      <InfoBox>
        This is the site-wide safety register. The talk is stored against the tenant, not this module — TPV shows
        the same row.
      </InfoBox>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <Field label="Topic *" full>
          <TextInput value={f.topic} onChange={set('topic')} maxLength={200} placeholder="What was briefed" />
        </Field>
        {/* Omitted means the controller stamps now(); a talk written up later is
            the normal case, so the field is offered rather than assumed. */}
        <Field label="Held at"><TextInput type="datetime-local" value={f.held_at} onChange={set('held_at')} /></Field>
        <Field label="Location"><TextInput value={f.location} onChange={set('location')} maxLength={200} /></Field>
        <Field label="Attendees">
          <TextInput type="number" min={0} max={MAX_ATTENDEES} value={f.attendee_count} onChange={set('attendee_count')} placeholder="0" />
        </Field>
        <Field label="Duration (minutes)">
          <TextInput type="number" min={0} max={MAX_MINUTES} value={f.duration_minutes} onChange={set('duration_minutes')} />
        </Field>
        <Field label="Notes" full>
          <textarea value={f.notes} onChange={set('notes')} rows={2} style={{ ...inputStyle, resize: 'vertical' }} />
        </Field>
      </div>

      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '12px 0 0' }}>{err}</p>}
      <ModalFooter onClose={onClose} onConfirm={save} loading={busy}
        disabled={!f.topic.trim()} confirmLabel="Save Talk" />
    </Overlay>
  )
}

/* ── shared bits ── */
const primaryBtn = { display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 16px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }
const ghostBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12.5, fontWeight: 700 }
