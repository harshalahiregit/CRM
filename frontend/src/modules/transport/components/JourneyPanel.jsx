import { useState } from 'react'
import { MapPin, Flag, Loader2, CheckCircle2, Clock, Info } from 'lucide-react'
import { useToast } from '@/components/ui/Toast'
import { transportDispatchApi, transportJourneyApi } from '@/services/transportApi'
import { toLocalInput, fromLocalInput, fmtDateTime } from '../constants'

/**
 * On the road — STT-006 and STT-007, on one panel.
 *
 * RTM STOS-REQ-OPS-009 "Track trip status" and STOS-REQ-OPS-010 "Record
 * delivery", both P0.
 *
 * ── TWO ACTS, ONE PANEL, AND THAT IS DELIBERATE ─────────────────────────
 * Departure and delivery are separate transitions with separate permissions,
 * but to the person doing the work they are one phase: the trip is out. Two
 * panels would put a heading between "it left" and "it arrived" and make the
 * page longer without making it clearer.
 *
 * ── WHY THESE ARE TYPED IN BY A PERSON ──────────────────────────────────
 * FRS TRP-P0-011 wants geofence, idle and route-deviation rules driven by GPS.
 * There is no GPS (SNG-TRN-020), and that same requirement's own rule line
 * grants a "manual update fallback" — so this is the specified path with its
 * automatic half missing, not a substitute for it. The panel says so, because a
 * dispatcher who thinks the system is tracking the truck will stop asking the
 * driver where it is.
 *
 * ── BACKDATING IS OFFERED, NOT GRUDGINGLY ALLOWED ───────────────────────
 * The time defaults to now and can be changed. Recording at 11:00 that the
 * truck left at 09:30 is the normal case for a manual fallback, and a form that
 * fought it would collect worse data, not better.
 *
 * The server refuses only what asserts something false — a time in the future,
 * a departure before the release, an arrival before the departure — and the
 * refusal names which. UX §35: never merely show "Blocked".
 *
 * ── UX §150 ─────────────────────────────────────────────────────────────
 * `canDepart` and `canDeliver` come from the capability endpoint. No button
 * appears that the API would refuse.
 */
/**
 * @param {'departure'|'delivery'|undefined} phase
 *   Which half of the journey this instance owns. The panel used to live in one
 *   drawer titled "On the road" that held both actions — and that drawer was the
 *   only one on the page covering two tracker positions, so its outcome line
 *   read "Delivered 19 Sept" under a heading named after the previous stage.
 *   One drawer per action is the rule everywhere else; this prop is what lets
 *   this panel follow it. Omitted renders both, as before.
 */
export default function JourneyPanel({ trip, canDepart, canDeliver, onChanged, phase }) {
  const toast = useToast()
  const [busy, setBusy] = useState(false)
  const [when, setWhen] = useState(toLocalInput(new Date()))
  // Whether the person actually chose a time, or is accepting "now".
  //
  // If they have not touched it we send NOTHING and let the server stamp its
  // own clock. Sending our idea of "now" instead means a browser running even a
  // few seconds ahead of the API gets "that is in the future" on the default
  // click — which is exactly how this panel shipped broken.
  const [edited, setEdited] = useState(false)
  const [refusal, setRefusal] = useState(null)

  const departed = !!trip.departed_at
  const delivered = !!trip.delivered_at
  const atDispatch = trip.status === 'dispatched'
  const onTheRoad = trip.status === 'in_transit'

  const act = async (kind) => {
    setBusy(true)
    setRefusal(null)
    try {
      const at = edited ? fromLocalInput(when) : null
      const res = kind === 'depart'
        ? await transportDispatchApi.depart(trip.id, at)
        : await transportJourneyApi.deliver(trip.id, at)

      if (!res.ok) {
        // The server's sentence, verbatim. Paraphrasing a refusal is how a
        // screen ends up explaining a rule it does not implement.
        setRefusal(res.message)
        return
      }

      toast.success(kind === 'depart'
        ? 'Departure recorded — the trip is on the road.'
        : 'Delivery recorded. Proof of delivery is now required before billing.')
      onChanged?.()
    } catch {
      toast.error('That did not go through. Try again.')
    } finally {
      setBusy(false)
    }
  }

  /* ── Before the trip is released ───────────────────────────────────── */
  if (!departed && !atDispatch && !onTheRoad && !delivered) {
    return (
      <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '12px 0 0' }}>
        Once the trip is released you can record when it leaves and when it arrives.
      </p>
    )
  }

  const Milestone = ({ icon: Icon, label, at, tone }) => (
    <div style={{ display: 'flex', gap: 10, alignItems: 'flex-start' }}>
      <div style={{
        width: 26, height: 26, borderRadius: 999, display: 'grid', placeItems: 'center', flexShrink: 0,
        background: tone.bg, color: tone.color,
      }}>
        <Icon size={13} />
      </div>
      <div style={{ minWidth: 0 }}>
        <p style={{ margin: 0, fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)' }}>{label}</p>
        <p style={{ margin: '2px 0 0', fontSize: 11.5, color: 'var(--text-muted)' }}>
          {at ? fmtDateTime(at) : 'Not recorded yet'}
        </p>
      </div>
    </div>
  )

  const done = { bg: 'rgba(52,211,153,0.16)', color: '#34d399' }
  const todo = { bg: 'var(--bg-input)', color: 'var(--text-muted)' }

  return (
    <div style={{ marginTop: 12, display: 'grid', gap: 14 }}>
      <div style={{ display: 'grid', gap: 12 }}>
        {phase !== 'delivery' && (
          <Milestone icon={MapPin} label="Left the pickup point" at={trip.departed_at} tone={departed ? done : todo} />
        )}
        {phase !== 'departure' && (
          <Milestone icon={Flag} label="Arrived at the destination" at={trip.delivered_at} tone={delivered ? done : todo} />
        )}
      </div>

      {delivered && phase !== 'departure' && (
        <div style={{
          display: 'flex', gap: 8, alignItems: 'flex-start', padding: '10px 12px',
          borderRadius: 10, background: 'rgba(52,211,153,0.10)', border: '1px solid rgba(52,211,153,0.28)',
        }}>
          <CheckCircle2 size={14} style={{ color: '#34d399', flexShrink: 0, marginTop: 1 }} />
          <p style={{ margin: 0, fontSize: 11.5, color: 'var(--text-b)', lineHeight: 1.5 }}>
            This trip is delivered. Proof of delivery is now required before it can be billed —
            upload and verify it under <strong>Paperwork and proof of delivery</strong> below.
          </p>
        </div>
      )}

      {/* The form appears in the drawer that owns the action, so a reader is
          never offered "record departure" under a heading about delivery. */}
      {(atDispatch || onTheRoad) && (canDepart || canDeliver)
        && (!phase || (atDispatch ? phase === 'departure' : phase === 'delivery')) && (
        <div style={{ display: 'grid', gap: 10, paddingTop: 2 }}>
          <label style={{ display: 'grid', gap: 5 }}>
            <span style={{ fontSize: 11.5, fontWeight: 700, color: 'var(--text-muted)' }}>
              {atDispatch ? 'When did it leave?' : 'When did it arrive?'}
            </span>
            <input
              type="datetime-local"
              value={when}
              onChange={(e) => { setWhen(e.target.value); setEdited(true) }}
              style={{
                padding: '8px 10px', borderRadius: 8, fontSize: 12.5,
                border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-b)',
              }}
            />
            <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>
              {edited
                ? 'Recorded at the time you have set.'
                : 'Leave this as it is to use the time you press the button. Change it if you are recording this after the fact.'}
            </span>
          </label>

          {refusal && (
            <div style={{
              display: 'flex', gap: 8, alignItems: 'flex-start', padding: '10px 12px',
              borderRadius: 10, background: 'rgba(248,113,113,0.10)', border: '1px solid rgba(248,113,113,0.30)',
            }}>
              <Info size={14} style={{ color: '#f87171', flexShrink: 0, marginTop: 1 }} />
              <p style={{ margin: 0, fontSize: 11.5, color: 'var(--text-b)', lineHeight: 1.5 }}>{refusal}</p>
            </div>
          )}

          <button
            onClick={() => act(atDispatch ? 'depart' : 'deliver')}
            disabled={busy || (atDispatch ? !canDepart : !canDeliver)}
            style={{
              padding: '9px 16px', borderRadius: 9, border: 'none', cursor: busy ? 'wait' : 'pointer',
              background: '#7C3AED', color: '#fff', fontSize: 12.5, fontWeight: 800,
              display: 'inline-flex', alignItems: 'center', gap: 7, justifySelf: 'start',
              opacity: busy ? 0.7 : 1,
            }}
          >
            {busy ? <Loader2 size={13} className="animate-spin" /> : <Clock size={13} />}
            {atDispatch ? 'Record departure' : 'Record delivery'}
          </button>
        </div>
      )}

      {/* The honesty note. It is driven by nothing conditional because nothing
          about it is conditional yet: there is no telemetry at all. When
          SNG-TRN-020 lands this becomes a real status line. */}
      <p style={{
        margin: 0, fontSize: 11, color: 'var(--text-muted)', lineHeight: 1.5,
        paddingTop: 8, borderTop: '1px solid var(--border)',
      }}>
        These times are recorded by hand. There is no live vehicle tracking yet, so this page knows
        only what someone tells it.
      </p>
    </div>
  )
}
