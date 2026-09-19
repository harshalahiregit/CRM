import { useState, useEffect, useCallback } from 'react'
import { Lock, CheckCircle2, XCircle, HelpCircle, Loader2, Info } from 'lucide-react'
import { useToast } from '@/components/ui/Toast'
import { transportJourneyApi } from '@/services/transportApi'
import { fmtDateTime } from '../constants'

/**
 * Close the trip — STT-012, API-009, BR-P0-017, FRS TRP-P0-014.
 *
 * ── THE CONTROLS ARE THE PANEL. THE BUTTON IS AN AFTERTHOUGHT ───────────
 * TRP-P0-014's acceptance criterion is "User sees exactly why a trip is
 * blocked", and its rule is "no silent closure with unresolved critical
 * exceptions". So this panel is a list of five controls with a sentence each,
 * and a button underneath it.
 *
 * Three states per control, and the third is the one that matters:
 *
 *   PASSED       checked, and satisfied.
 *   FAILED       checked, and not satisfied. This blocks closure.
 *   NOT CHECKED  we could not evaluate it at all, because the thing it
 *                inspects does not exist yet. It does NOT block closure — it
 *                would be a wall with no door — but it is shown in its own
 *                colour with its own reason, because "we did not look" and
 *                "we looked and it was fine" are not the same statement and a
 *                screen that renders them alike is lying by omission.
 *
 * ── THE WAIVER LINE IS CAREFULLY WORDED ─────────────────────────────────
 * BR-P0-017 says a failed control MAY be waived by an Owner. We have not built
 * that. So the panel says the waiver is not built yet — never that no waiver
 * exists. Telling a user "this cannot be waived" when their own rule book says
 * it can is misleading them about their own business.
 *
 * ── AND THE PART THAT IS HARDEST TO SAY OUT LOUD ────────────────────────
 * Closure is built and cannot be reached. Nothing can move a trip into
 * `collection_pending`, because marking a bill invoiced has no route yet
 * (D-106, Person 3's). `readiness.reachable` carries that from the server, and
 * the panel prints the reason rather than showing a dead button or, worse,
 * pretending the stage does not exist.
 */
export default function ClosurePanel({ trip, canClose, onChanged }) {
  const toast = useToast()
  const [state, setState] = useState(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [reason, setReason] = useState('')
  const [refusal, setRefusal] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setState(await transportJourneyApi.closure(trip.id))
    } finally {
      setLoading(false)
    }
  }, [trip.id])

  useEffect(() => { load() }, [load])

  const submit = async () => {
    setBusy(true)
    setRefusal(null)
    try {
      const res = await transportJourneyApi.close(trip.id, reason)
      if (!res.ok) {
        setRefusal(res.message)
        return
      }
      toast.success('Trip closed.')
      setReason('')
      onChanged?.()
      load()
    } catch {
      toast.error('That did not go through. Try again.')
    } finally {
      setBusy(false)
    }
  }

  if (loading) {
    return (
      <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '12px 0 0', display: 'flex', gap: 7, alignItems: 'center' }}>
        <Loader2 size={13} className="animate-spin" /> Checking what is outstanding…
      </p>
    )
  }

  const r = state?.readiness
  if (!r) {
    return <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '12px 0 0' }}>Nothing to show yet.</p>
  }

  const tone = {
    passed:      { icon: CheckCircle2, color: '#34d399', label: 'Passed' },
    failed:      { icon: XCircle,      color: '#f87171', label: 'Outstanding' },
    not_checked: { icon: HelpCircle,   color: '#fbbf24', label: 'Not checked' },
  }

  return (
    <div style={{ marginTop: 12, display: 'grid', gap: 14 }}>
      {trip.closed_at && (
        <div style={{
          display: 'flex', gap: 8, alignItems: 'flex-start', padding: '10px 12px',
          borderRadius: 10, background: 'rgba(52,211,153,0.10)', border: '1px solid rgba(52,211,153,0.28)',
        }}>
          <Lock size={14} style={{ color: '#34d399', flexShrink: 0, marginTop: 1 }} />
          <div>
            <p style={{ margin: 0, fontSize: 12, fontWeight: 800, color: 'var(--text-h)' }}>
              Closed on {fmtDateTime(trip.closed_at)}
            </p>
            <p style={{ margin: '3px 0 0', fontSize: 11.5, color: 'var(--text-b)', lineHeight: 1.5 }}>
              {trip.closure_reason}
            </p>
            <p style={{ margin: '5px 0 0', fontSize: 11, color: 'var(--text-muted)' }}>
              A closed trip cannot be reopened.
            </p>
          </div>
        </div>
      )}

      {/* The controls. Always shown, closed or not — after closure this is the
          record of what was and was not verified at the time. */}
      <div style={{ display: 'grid', gap: 9 }}>
        {(r.controls || []).map((c) => {
          const t = tone[c.state] || tone.not_checked
          const Icon = t.icon

          return (
            <div key={c.key} style={{ display: 'flex', gap: 9, alignItems: 'flex-start' }}>
              <Icon size={14} style={{ color: t.color, flexShrink: 0, marginTop: 2 }} />
              <div style={{ minWidth: 0 }}>
                <p style={{ margin: 0, fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>
                  {c.label}
                  <span style={{ marginLeft: 8, fontSize: 11, fontWeight: 800, color: t.color }}>{t.label}</span>
                </p>
                <p style={{ margin: '2px 0 0', fontSize: 11.5, color: 'var(--text-muted)', lineHeight: 1.5 }}>
                  {c.message}
                </p>
              </div>
            </div>
          )
        })}
      </div>

      {/* D-106, on screen. Not a dead button and not a hidden section — the
          reason, in the words the server gives. */}
      {!r.reachable && !trip.closed_at && (
        <div style={{
          display: 'flex', gap: 8, alignItems: 'flex-start', padding: '10px 12px',
          borderRadius: 10, background: 'rgba(251,191,36,0.10)', border: '1px solid rgba(251,191,36,0.28)',
        }}>
          <Info size={14} style={{ color: '#fbbf24', flexShrink: 0, marginTop: 1 }} />
          <p style={{ margin: 0, fontSize: 11.5, color: 'var(--text-b)', lineHeight: 1.5 }}>
            No trip can reach this stage yet. A trip becomes closable once its invoice has been
            posted and the payment collected, and posting an invoice is not wired up in Sangoé
            yet. This panel is ready for the day it is.
          </p>
        </div>
      )}

      {r.at_the_door && canClose && !trip.closed_at && (
        <div style={{ display: 'grid', gap: 10, paddingTop: 2 }}>
          <label style={{ display: 'grid', gap: 5 }}>
            <span style={{ fontSize: 11.5, fontWeight: 700, color: 'var(--text-muted)' }}>
              Why is this trip being closed?
            </span>
            <textarea
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              rows={2}
              placeholder="Settled in full, written off, superseded…"
              style={{
                padding: '8px 10px', borderRadius: 8, fontSize: 12.5, resize: 'vertical',
                border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-b)',
              }}
            />
            <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>
              A closed trip cannot be reopened, so this is the only record of why it ended.
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
            onClick={submit}
            disabled={busy || reason.trim().length < 12}
            style={{
              padding: '9px 16px', borderRadius: 9, border: 'none',
              cursor: busy || reason.trim().length < 12 ? 'not-allowed' : 'pointer',
              background: '#7C3AED', color: '#fff', fontSize: 12.5, fontWeight: 800,
              display: 'inline-flex', alignItems: 'center', gap: 7, justifySelf: 'start',
              opacity: busy || reason.trim().length < 12 ? 0.55 : 1,
            }}
          >
            {busy ? <Loader2 size={13} className="animate-spin" /> : <Lock size={13} />}
            Close this trip
          </button>
        </div>
      )}

      {/* BR-P0-017, worded so a user is not misled about their own rule book. */}
      {!trip.closed_at && (
        <p style={{
          margin: 0, fontSize: 11, color: 'var(--text-muted)', lineHeight: 1.5,
          paddingTop: 8, borderTop: '1px solid var(--border)',
        }}>
          {r.waiver}
        </p>
      )}
    </div>
  )
}
