import { ArrowRight, Loader2, ShieldAlert, CheckCircle2, Undo2 } from 'lucide-react'
import { fmtDate, fmtDateTime } from '../constants'

/**
 * The one thing to do next, at the top, impossible to miss.
 *
 * ── THE PROBLEM THIS SOLVES ──────────────────────────────────────────────
 * A trip has twelve reachable states and the page used to show every stage of
 * all of them at once. At any moment a dispatcher needs three things: where is
 * this trip, what is the ONE next thing I do, and the details if I want them.
 * This is the second one, and it is the first thing on the page.
 *
 * ── IT INVENTS NOTHING ───────────────────────────────────────────────────
 * Every sentence below is derived from the trip's own state and the grants the
 * capability endpoint returned. No rule, gate or refusal changed to build this:
 * where the next step is blocked, the wording is the same sentence the API
 * would have refused with, and where a role may not act, the page says who can
 * rather than offering a button that would 403.
 *
 * `scrollTo` moves the page to the step that performs the action rather than
 * duplicating its controls here. One button, one place it takes you.
 */
export default function TripNextAction({ trip, grants, readiness, busy, onSubmitViability, onApprove, onReject, onGoToStep }) {
  const a = resolve(trip, grants, readiness)

  const accent = a.blocked ? 'var(--color-warning-500)' : 'var(--accent)'
  const Icon = a.blocked ? ShieldAlert : a.done ? CheckCircle2 : ArrowRight

  return (
    <div
      className="pr-glass"
      style={{
        padding: '18px 20px', marginBottom: 16,
        borderColor: a.done ? 'var(--color-success-500)' : accent,
        background: a.done
          ? 'rgba(52,211,153,0.06)'
          : a.blocked ? 'rgba(245,158,11,0.06)' : 'rgba(124,58,237,0.06)',
      }}
    >
      <div style={{ display: 'flex', gap: 14, alignItems: 'flex-start', flexWrap: 'wrap' }}>
        <span style={{
          width: 32, height: 32, borderRadius: 999, display: 'grid', placeItems: 'center', flexShrink: 0,
          background: a.done ? 'rgba(52,211,153,0.16)' : a.blocked ? 'rgba(245,158,11,0.16)' : 'rgba(124,58,237,0.16)',
          color: a.done ? 'var(--color-success-500)' : accent,
        }}>
          <Icon size={16} />
        </span>

        <div style={{ flex: 1, minWidth: 220 }}>
          <p style={{
            margin: 0, fontSize: 10.5, fontWeight: 900, letterSpacing: '.07em',
            color: 'var(--text-muted)', textTransform: 'uppercase',
          }}>
            {a.done ? 'Nothing to do' : a.blocked ? 'Waiting on someone else' : 'Next step'}
          </p>
          <p style={{ margin: '4px 0 0', fontSize: 16, fontWeight: 800, color: 'var(--text-h)', lineHeight: 1.35 }}>
            {a.headline}
          </p>
          {a.detail && (
            <p style={{ margin: '5px 0 0', fontSize: 12.5, color: 'var(--text-p)', lineHeight: 1.5 }}>
              {a.detail}
            </p>
          )}
        </div>

        {(a.cta || a.secondary) && (
          <div style={{ display: 'flex', gap: 8, flexShrink: 0, flexWrap: 'wrap', alignItems: 'center' }}>
            {a.secondary && (
              <button disabled={busy} onClick={a.secondary.run}
                style={ghostBtn}>
                <Undo2 size={14} /> {a.secondary.label}
              </button>
            )}
            {a.cta && (
              <button disabled={busy} onClick={a.cta.run} style={primaryBtn}>
                {busy ? <Loader2 size={14} className="animate-spin" /> : null}
                {a.cta.label}
                {!busy && <ArrowRight size={14} />}
              </button>
            )}
          </div>
        )}
      </div>
    </div>
  )

  /* ── the whole decision, in one place ───────────────────────────────── */
  function resolve(t, g, r) {
    const step = (key, label) => ({ label, run: () => onGoToStep(key) })

    switch (t.status) {
      case 'draft':
        return {
          headline: 'This trip is still a draft.',
          detail: 'Check the route and the agreed price, then send it for viability.',
          cta: { label: 'Submit for viability', run: onSubmitViability },
        }

      case 'viability_pending':
        // PERM-003 excludes the Dispatcher. Say who can, rather than showing a
        // button the API would refuse.
        if (!g['transport.trip.approve']) {
          return {
            blocked: true,
            headline: 'This trip is waiting for approval.',
            detail: 'Your role cannot approve trips. Operations, Accounts, an Approver or the Owner can.',
          }
        }
        return {
          headline: 'This trip is waiting for your approval.',
          detail: 'Approve it to let a vehicle and driver be assigned, or send it back saying what to change.',
          cta: { label: 'Approve trip', run: onApprove },
          secondary: { label: 'Send back', run: onReject },
        }

      case 'approved':
        if (!g['transport.trip.assign']) {
          return {
            blocked: true,
            headline: 'This trip needs a vehicle and a driver.',
            detail: 'Your role cannot assign them. A Dispatcher, Operations or the Owner can.',
          }
        }
        return {
          headline: 'This trip needs a vehicle and a driver.',
          detail: 'Only vehicles and drivers that are free and have valid papers are offered.',
          cta: step('crew', 'Assign'),
        }

      case 'allocated': {
        const done = r?.completed ?? 0
        const total = r?.total ?? 0
        return {
          headline: total
            ? `Pre-trip checks not done — ${done} of ${total}.`
            : 'Pre-trip checks have not been started.',
          detail: 'Confirm the order, the driver’s papers and the vehicle’s papers before it can leave.',
          // "Continue" only once somebody has confirmed one. A checklist that
          // exists but has nothing ticked has not been started.
          cta: step('checks', done > 0 ? 'Continue checks' : 'Start checks'),
        }
      }

      case 'pretrip_ok':
        return {
          headline: 'Ready to dispatch.',
          detail: 'Release the trip and record when it should leave and arrive.',
          cta: step('dispatch', 'Dispatch'),
        }

      case 'dispatched':
        return {
          headline: 'Released, but not recorded as having left.',
          detail: 'Record the departure when the vehicle actually rolls.',
          cta: step('road', 'Record departure'),
        }

      case 'in_transit':
        return {
          headline: t.departed_at
            ? `On the road since ${fmtDate(t.departed_at)}${t.planned_arrival_at ? `, due ${fmtDate(t.planned_arrival_at)}` : ''}.`
            : 'On the road.',
          detail: 'Record the delivery when it arrives at the destination.',
          cta: step('road', 'Record delivery'),
        }

      case 'delivered':
        return {
          headline: `Delivered${t.delivered_at ? ` ${fmtDate(t.delivered_at)}` : ''}. Proof of delivery is needed.`,
          detail: 'A trip cannot be billed until its POD has been verified, unless an exception waives it.',
          cta: step('paperwork', 'Go to paperwork'),
        }

      case 'pod_verified':
        return {
          headline: 'Proof of delivery is verified.',
          detail: 'Hand the trip to Accounts so they can raise the invoice.',
          cta: step('billing', 'Prepare billing'),
        }

      case 'billable':
        return {
          blocked: true,
          headline: 'Ready to invoice — with Accounts.',
          detail: 'Transport has done its part. Accounts raise the invoice; this module never posts one.',
        }

      case 'billed':
        return {
          headline: 'Invoiced. Payment is now being tracked.',
          detail: 'Open the receivable to record what comes in and chase what does not.',
          cta: step('paid', 'Open receivable'),
        }

      case 'collection_pending':
        return {
          headline: 'Waiting for payment.',
          detail: 'Once the balance is settled the trip can be closed for good.',
          cta: step('close', 'Review closing'),
        }

      case 'closed':
        return {
          done: true,
          headline: 'This trip is closed.',
          detail: t.closure_reason || 'Settled and closed. A closed trip cannot be reopened.',
        }

      default:
        return { headline: 'Nothing is waiting on this trip.' }
    }
  }
}

const primaryBtn = {
  display: 'inline-flex', alignItems: 'center', gap: 7, padding: '10px 18px', borderRadius: 10,
  border: 'none', background: 'var(--accent)', color: '#fff', fontSize: 13, fontWeight: 800, cursor: 'pointer',
}

const ghostBtn = {
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10,
  border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-p)',
  fontSize: 12.5, fontWeight: 700, cursor: 'pointer',
}
