import { useState, useEffect, useCallback } from 'react'
import {
  Send, ShieldAlert, AlertTriangle, CheckCircle2, Loader2, Lock,
  Pencil, History, Clock, Link2Off,
} from 'lucide-react'
import { useToast } from '@/components/ui/Toast'
import { transportDispatchApi } from '@/services/transportApi'
import { Chip } from './MasterFormFields'
import {
  DISPATCH_FIELDS, DISPATCH_FIELD_LABEL, dispatchVersionCfg, fmtTurnaround,
  toLocalInput, fromLocalInput, pretripReadinessCfg, fmtDateTime,
} from '../constants'

/**
 * Dispatch panel on the trip detail page — RTM STOS-REQ-OPS-008, FRS TRP-P0-006.
 *
 * NO TICKET OWNS THIS. Step 12's register has no dispatch ticket (D-18); the
 * owner authorised the bounded scope in writing on 2026-09-10. See DispatchScope
 * on the server, where the whole ruling is recorded.
 *
 * Same design language as AllocationPanel and PretripPanel, deliberately — the
 * three sit on one page and a dispatcher should not have to learn three
 * vocabularies to read them.
 *
 * ── THE PANEL HAS THREE STATES, AND THEY ARE DIFFERENT PROBLEMS ──────────
 *   BLOCKED     the trip may not leave. Why, and what to do — never just the word.
 *   READY       the five fields TRP-P0-006 freezes, and one button.
 *   DISPATCHED  what was frozen, at which version, and every change since.
 *
 * ── UX §35: NEVER MERELY SHOW "BLOCKED" ─────────────────────────────────
 * The GET carries `readiness`, re-derived live by the same method the gate uses
 * (BRW-046, owner's ruling 2026-09-10). So the reason is on screen BEFORE the
 * button is clicked, not discovered by clicking it — which is the difference
 * between an explanation and an error.
 *
 * `lapsed` is rendered separately from `blockers` because they are different
 * situations. A blocker that was always there means the checklist was never
 * satisfied. A LAPSED check passed at pre-trip and has since stopped being true —
 * a licence that expired overnight — and only that distinction explains why a
 * screen which read READY yesterday is refusing today.
 *
 * ── THE FLEET BOUNDARY IS SHOWN, NOT HIDDEN ─────────────────────────────
 * BRW-050 says a dispatched trip sets Vehicle = In Operation and Driver = On
 * Trip. Those are Developer A's tables and the owner ruled Trip side must not
 * write them, so the shipped gateway records the intent and does nothing.
 *
 * The panel says so. A dispatcher who is told a trip is released will reasonably
 * assume the vehicle is now marked out; letting them assume it would make this
 * screen lie about the state of the fleet. The note goes away by itself when the
 * gateway starts applying — it is driven by `fleet_state_applied`, not hardcoded.
 *
 * ── UX §150: AN ACTION THE USER CANNOT PERFORM IS NOT SHOWN ─────────────
 * `canDispatch` comes from the capability endpoint. Reading this panel needs
 * transport.trip.view; releasing needs transport.trip.dispatch, and the server
 * enforces both independently.
 */

const btn = {
  base: { padding: '8px 13px', borderRadius: 9, fontSize: 12.5, fontWeight: 700, display: 'inline-flex', alignItems: 'center', gap: 6, cursor: 'pointer', border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-p)' },
  go: { padding: '8px 14px', borderRadius: 9, fontSize: 12.5, fontWeight: 800, display: 'inline-flex', alignItems: 'center', gap: 6, cursor: 'pointer', border: '1px solid #059669', background: '#059669', color: '#fff' },
  disabled: { padding: '8px 14px', borderRadius: 9, fontSize: 12.5, fontWeight: 800, display: 'inline-flex', alignItems: 'center', gap: 6, cursor: 'not-allowed', border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-muted)', opacity: 0.75 },
}

const input = {
  width: '100%', padding: '8px 10px', borderRadius: 8, fontSize: 12.5,
  border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-p)',
}

/** One editable dispatch field. Same shape whether releasing or amending. */
function FieldInput({ field, value, onChange, disabled }) {
  return (
    <div>
      <label style={{ display: 'block', fontSize: 11.5, fontWeight: 700, color: 'var(--text-h)', marginBottom: 4 }}>
        {field.label}
      </label>
      {field.type === 'textarea' ? (
        <textarea
          value={value || ''} rows={3} disabled={disabled}
          onChange={(e) => onChange(field.key, e.target.value)}
          style={{ ...input, resize: 'vertical' }}
        />
      ) : (
        <input
          type={field.type} value={value || ''} disabled={disabled}
          onChange={(e) => onChange(field.key, e.target.value)}
          style={input}
        />
      )}
      <p style={{ margin: '3px 0 0', fontSize: 11, color: 'var(--text-muted)' }}>{field.hint}</p>
    </div>
  )
}

/** One version. The reason is the point of the entry, so it is not an aside. */
function VersionRow({ entry }) {
  const cfg = dispatchVersionCfg(entry.type, entry.version)

  return (
    <div style={{ borderLeft: '2px solid var(--border)', padding: '0 0 14px 12px', position: 'relative' }}>
      <span style={{
        position: 'absolute', left: -5, top: 3, width: 8, height: 8, borderRadius: 999,
        background: cfg.color,
      }} />
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
        <Chip cfg={cfg} size={11} />
        <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>{fmtDateTime(entry.occurred_at)}</span>
        {entry.actor_name && (
          <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>by {entry.actor_name}</span>
        )}
      </div>

      {entry.reason && (
        <p style={{ margin: '4px 0 0', fontSize: 12, color: 'var(--text-p)' }}>{entry.reason}</p>
      )}

      {/* What actually changed, both sides. An amendment without its before is
          not a version history, it is a log line. */}
      {entry.type === 'amendment' && (entry.fields || []).map((f) => (
        <p key={f} style={{ margin: '3px 0 0', fontSize: 11.5, color: 'var(--text-muted)' }}>
          <strong style={{ color: 'var(--text-p)' }}>{DISPATCH_FIELD_LABEL[f] || f}</strong>
          {': '}
          <span style={{ textDecoration: 'line-through' }}>{entry.before?.[f] || '—'}</span>
          {' → '}
          <span style={{ color: 'var(--text-p)' }}>{entry.after?.[f] || '—'}</span>
        </p>
      ))}

      {/* Whoever reads the history is exactly who needs to know Fleet did not move. */}
      {entry.type === 'release' && entry.fleet_state_applied === false && (
        <p style={{ margin: '4px 0 0', fontSize: 11, color: '#fbbf24', display: 'flex', gap: 5, alignItems: 'flex-start' }}>
          <Link2Off size={11} style={{ marginTop: 2, flexShrink: 0 }} />
          Vehicle and driver status were not updated by this release.
        </p>
      )}
    </div>
  )
}

export default function DispatchPanel({ trip, canDispatch, onChanged }) {
  const toast = useToast()

  const [state, setState] = useState(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [refusal, setRefusal] = useState(null)
  const [form, setForm] = useState({})
  const [amending, setAmending] = useState(false)
  const [reason, setReason] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const data = await transportDispatchApi.get(trip.id)
      setState(data)
      // Seed the form from whatever is stored, so an amendment starts from the
      // current values rather than from blank — a blank field would read as an
      // instruction to clear it.
      const seed = {}
      for (const f of DISPATCH_FIELDS) {
        const v = data?.dispatch?.[f.key]
        seed[f.key] = f.type === 'datetime-local' ? toLocalInput(v) : (v || '')
      }
      setForm(seed)
    } catch (e) {
      toast.error(e?.message || 'Could not load dispatch state.')
    } finally {
      setLoading(false)
    }
  }, [trip.id])

  useEffect(() => { load() }, [load])

  const setField = (key, value) => setForm((f) => ({ ...f, [key]: value }))

  /** Only the fields with a value; the API treats absent as "not set". */
  const payload = () => {
    const out = {}
    for (const f of DISPATCH_FIELDS) {
      const v = form[f.key]
      if (v === '' || v === null || v === undefined) continue
      out[f.key] = f.type === 'datetime-local' ? fromLocalInput(v) : v
    }
    return out
  }

  const release = async () => {
    setBusy(true); setRefusal(null)
    try {
      const res = await transportDispatchApi.confirm(trip.id, payload())
      if (res.ok) {
        toast.success('Trip dispatched.')
        onChanged?.()
        load()
      } else {
        // BRW-048 — the refusal is a verdict, shown in place with its reason.
        setRefusal(res.message)
        if (res.readiness !== undefined) setState((s) => ({ ...s, readiness: res.readiness }))
        toast.error(res.message)
      }
    } catch (e) {
      toast.error(e?.message || 'The trip could not be dispatched.')
    } finally {
      setBusy(false)
    }
  }

  const amend = async () => {
    if (!reason.trim()) { toast.error('Give a reason for the change.'); return }
    setBusy(true); setRefusal(null)
    try {
      const res = await transportDispatchApi.amend(trip.id, payload(), reason.trim())
      if (res.ok) {
        toast.success('Dispatch amended.')
        setAmending(false); setReason('')
        onChanged?.()
        load()
      } else {
        setRefusal(res.message)
        toast.error(res.message)
      }
    } catch (e) {
      toast.error(e?.message || 'The change could not be recorded.')
    } finally {
      setBusy(false)
    }
  }

  if (loading) {
    return <div style={{ padding: 22, textAlign: 'center' }}><Loader2 size={18} className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
  }

  const dispatched = !!state?.dispatched
  const readiness = state?.readiness
  // `blocking`, not `status`. BRW-052: a critical failure blocks; an incomplete
  // checklist does not. The two can legitimately disagree after a policy change
  // — a check enabled after this trip passed has nobody's confirmation on it but
  // still passes — and treating "not READY" as "may not leave" would hold a
  // roadworthy vehicle in the yard. See PretripService::revalidate().
  const blocking = !!readiness?.blocking
  const ready = !blocking
  const history = state?.history || []
  const lapsed = readiness?.lapsed || []
  const latestRelease = history.find((h) => h.type === 'release')

  return (
    <>
      {/* Header — what this step can do right now. The trip's own status chip
          has gone (page header and tracker carry it); what belongs here is
          whether the trip may leave, re-checked at this moment. */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap', marginTop: 12 }}>
        {!dispatched && readiness && (
          <>
            <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>Checked just now</span>
            <Chip cfg={pretripReadinessCfg(readiness.status)} />
          </>
        )}
        {dispatched && (
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 11.5, color: 'var(--text-muted)' }}>
            <Lock size={12} /> Locked · change {state.version}
          </span>
        )}
      </div>

      {/* ── BLOCKED ─────────────────────────────────────────────────────
          UX §35 asks four things of a blocked state: why, what is missing,
          who fixes it, what happens after. Three are answered here; the
          fourth (an owner per requirement) lives in CMP §12's Compliance
          Requirement Master, which no ticket in the register builds. Same
          recorded gap the allocation and pre-trip panels carry. */}
      {!dispatched && readiness && !ready && (
        <div style={{
          marginTop: 12, padding: '11px 13px', borderRadius: 9,
          background: readiness.status === 'blocked' ? 'rgba(248,113,113,0.12)' : 'rgba(251,191,36,0.10)',
          border: `1px solid ${readiness.status === 'blocked' ? 'rgba(248,113,113,0.35)' : 'rgba(251,191,36,0.30)'}`,
        }}>
          <p style={{
            margin: 0, fontSize: 12.5, display: 'flex', gap: 8, alignItems: 'flex-start',
            color: readiness.status === 'blocked' ? '#f87171' : '#fbbf24',
          }}>
            <ShieldAlert size={15} style={{ flexShrink: 0, marginTop: 1 }} />
            <span>{readiness.message}</span>
          </p>

          {/* The case worth calling out on its own: it passed, and it does not
              pass any more. Without this, a dispatcher sees a trip marked
              "pre-trip passed" being refused and has no way to reconcile the two. */}
          {lapsed.length > 0 && (
            <div style={{ marginTop: 9, paddingTop: 9, borderTop: '1px solid rgba(248,113,113,0.25)' }}>
              <p style={{ margin: '0 0 5px', fontSize: 11, fontWeight: 800, letterSpacing: '.04em', textTransform: 'uppercase', color: '#f87171' }}>
                Passed at pre-trip, not passing now
              </p>
              {lapsed.map((l) => (
                <p key={l.key} style={{ margin: '0 0 3px', fontSize: 12, color: 'var(--text-p)' }}>
                  <strong>{l.label}</strong> — {l.detail}
                </p>
              ))}
              <p style={{ margin: '6px 0 0', fontSize: 11.5, color: 'var(--text-muted)' }}>
                Resolve it, then re-run the pre-trip checklist above. The trip stays ready to dispatch once it passes again.
              </p>
            </div>
          )}
        </div>
      )}

      {/* Not blocking, but not a clean bill of health either. UX §36: a warning
          is not a block, so this is a note beside an enabled button rather than
          a refusal that looks like one. */}
      {!dispatched && readiness && !blocking && readiness.status !== 'ready' && (
        <p style={{ marginTop: 10, fontSize: 11.5, color: 'var(--text-muted)' }}>
          The checklist reads <strong>{pretripReadinessCfg(readiness.status).label.toLowerCase()}</strong> under the
          current policy, but nothing on it is failing. This trip can be dispatched.
        </p>
      )}

      {refusal && (
        <div style={{ marginTop: 10, padding: '10px 12px', borderRadius: 9, background: 'rgba(248,113,113,0.12)', border: '1px solid rgba(248,113,113,0.35)', color: '#f87171', fontSize: 12.5, display: 'flex', gap: 8 }}>
          <AlertTriangle size={15} style={{ flexShrink: 0, marginTop: 1 }} /> {refusal}
        </div>
      )}

      {/* ── DISPATCHED ──────────────────────────────────────────────────── */}
      {dispatched && (
        <>
          <div style={{ marginTop: 12, padding: '10px 12px', borderRadius: 9, background: 'rgba(52,211,153,0.10)', border: '1px solid rgba(52,211,153,0.30)', color: '#34d399', fontSize: 12.5, display: 'flex', gap: 8 }}>
            <CheckCircle2 size={15} style={{ flexShrink: 0, marginTop: 1 }} />
            <span>
              Dispatched {fmtDateTime(state.trip?.dispatched_at)}.
              {' '}The trip is released and the details below are now locked. Tracking it on the road is not part of the system yet.
            </span>
          </div>

          {/* BRW-050's side effects that did not happen. Driven by the response,
              so it disappears on its own once Developer A's gateway applies. */}
          {latestRelease?.fleet_state_applied === false && (
            <div style={{ marginTop: 9, padding: '9px 12px', borderRadius: 9, background: 'rgba(251,191,36,0.10)', border: '1px solid rgba(251,191,36,0.28)', fontSize: 12, color: '#fbbf24', display: 'flex', gap: 8 }}>
              <Link2Off size={14} style={{ flexShrink: 0, marginTop: 1 }} />
              <span>
                The vehicle and driver are <strong>not</strong> marked as on trip. Fleet status is owned by the Fleet
                section and is not updated from here yet — check availability with Fleet before assigning them elsewhere.
              </span>
            </div>
          )}
        </>
      )}

      {/* ── THE FIVE FROZEN FIELDS ──────────────────────────────────────── */}
      {(dispatched || ready) && (
        <div style={{ marginTop: 14 }}>
          {dispatched && !amending ? (
            <div style={{ display: 'grid', gap: 10 }}>
              {DISPATCH_FIELDS.map((f) => (
                <div key={f.key} style={{ display: 'flex', gap: 10, alignItems: 'baseline', flexWrap: 'wrap' }}>
                  <span style={{ fontSize: 11.5, color: 'var(--text-muted)', minWidth: 168 }}>{f.label}</span>
                  <span style={{ fontSize: 12.5, color: 'var(--text-p)', fontWeight: 600 }}>
                    {f.type === 'datetime-local'
                      ? fmtDateTime(state.dispatch?.[f.key])
                      : (state.dispatch?.[f.key] || '—')}
                  </span>
                </div>
              ))}

              {/* TAT — derived from ETD→ETA, never stored. TRP-P0-006 writes
                  "ETA/TAT" as one field and defines TAT nowhere; see DispatchScope. */}
              <div style={{ display: 'flex', gap: 10, alignItems: 'baseline', flexWrap: 'wrap' }}>
                <span style={{ fontSize: 11.5, color: 'var(--text-muted)', minWidth: 168 }}>Turnaround (ETD → ETA)</span>
                <span style={{ fontSize: 12.5, color: 'var(--text-p)', fontWeight: 600, display: 'inline-flex', alignItems: 'center', gap: 5 }}>
                  <Clock size={12} style={{ color: 'var(--text-muted)' }} />
                  {fmtTurnaround(state.turnaround_hours)}
                </span>
              </div>
            </div>
          ) : (
            <div style={{ display: 'grid', gap: 12 }}>
              {DISPATCH_FIELDS.map((f) => (
                <FieldInput key={f.key} field={f} value={form[f.key]} onChange={setField} disabled={busy} />
              ))}

              {amending && (
                <div>
                  <label style={{ display: 'block', fontSize: 11.5, fontWeight: 700, color: 'var(--text-h)', marginBottom: 4 }}>
                    Reason for the change
                  </label>
                  <input
                    value={reason} disabled={busy}
                    onChange={(e) => setReason(e.target.value)}
                    placeholder="Why is this being changed?"
                    style={input}
                  />
                  <p style={{ margin: '3px 0 0', fontSize: 11, color: 'var(--text-muted)' }}>
                    Required. It is saved with the change and cannot be edited afterwards.
                  </p>
                </div>
              )}
            </div>
          )}
        </div>
      )}

      {/* ── ACTIONS. UX §150 — never shown to someone who cannot perform them. ── */}
      {canDispatch && (
        <div style={{ marginTop: 14, display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {!dispatched && (
            <button
              onClick={release}
              disabled={busy || !ready}
              style={busy || !ready ? btn.disabled : btn.go}
              title={ready ? undefined : 'This trip cannot be dispatched yet — see the reason above.'}
            >
              {busy ? <Loader2 size={13} className="animate-spin" /> : <Send size={13} />} Confirm dispatch
            </button>
          )}

          {dispatched && !amending && (
            <button onClick={() => setAmending(true)} style={btn.base}>
              <Pencil size={13} /> Change these details
            </button>
          )}

          {dispatched && amending && (
            <>
              <button onClick={amend} disabled={busy} style={busy ? btn.disabled : btn.go}>
                {busy ? <Loader2 size={13} className="animate-spin" /> : <CheckCircle2 size={13} />} Save change {state.version + 1}
              </button>
              <button onClick={() => { setAmending(false); setReason(''); load() }} disabled={busy} style={btn.base}>
                Cancel
              </button>
            </>
          )}
        </div>
      )}

      {/* Someone who may watch but not release should be told so, rather than
          left wondering where the button is. */}
      {!canDispatch && !dispatched && (
        <p style={{ marginTop: 12, fontSize: 11.5, color: 'var(--text-muted)' }}>
          You can see this trip's dispatch readiness but not release it.
        </p>
      )}

      {/* ── VERSION HISTORY — TRP-P0-006's acceptance criterion ─────────── */}
      {history.length > 0 && (
        <div style={{ marginTop: 18 }}>
          <p style={{ margin: '0 0 10px', fontSize: 11.5, fontWeight: 800, letterSpacing: '.04em', textTransform: 'uppercase', color: 'var(--text-muted)', display: 'flex', alignItems: 'center', gap: 6 }}>
            <History size={13} /> Changes to this dispatch
          </p>
          {history.map((h) => <VersionRow key={`${h.type}-${h.version}`} entry={h} />)}

          {/* Stated rather than implied, so nobody reads the absence of an
              approver as an approval. TRP-P0-006 asks for change approval after
              release; no approval entity exists anywhere in the register. */}
          {history.some((h) => h.type === 'amendment') && (
            <p style={{ margin: '2px 0 0', fontSize: 11, color: 'var(--text-muted)' }}>
              Every change is recorded with a reason. Nobody has to approve it — sign-off is not part of the system yet.
            </p>
          )}
        </div>
      )}
    </>
  )
}
