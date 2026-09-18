import { Check, ChevronDown, ChevronRight, Lock } from 'lucide-react'

/**
 * One stage of a trip, collapsed unless it is the stage you are on.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
 * The trip page used to render fourteen panels, all expanded, each with a
 * paragraph underneath explaining what it was for. The owner opened it and
 * could not work out what to do. That is a failure of the screen: nothing on it
 * said which of the fourteen mattered right now.
 *
 * A step is in one of three states and each answers a different question:
 *
 *   DONE       "that happened, here is the outcome and when" — one line, a
 *              tick, openable if somebody wants the detail.
 *   CURRENT    "this is where you are" — open, and the only one that is.
 *   LATER      "this is coming" — collapsed, greyed and locked, so the shape of
 *              the journey is visible without asking anyone to act on it.
 *   AVAILABLE  "you may, but nothing is waiting on it" — advances and costs are
 *              not stages of the journey; they can happen any time after
 *              approval and never become "done". Collapsed, not greyed, no tick
 *              and no lock, because all three would be lies.
 *
 * ── THE TEACHING TEXT ONLY APPEARS WHEN THE STEP IS OPEN ────────────────
 * The one-line explanation is useful the first time somebody meets a step and
 * noise every time after. A closed step shows its outcome, not its purpose.
 *
 * Nothing here changes a rule, a gate or a refusal. It is layout.
 */
export default function TripStep({
  id,
  icon: Icon,
  n,
  title,
  /** 'done' | 'current' | 'later' | 'available' */
  state = 'later',
  /** One line shown when DONE, in place of the explanation. */
  outcome,
  /** One line shown only when the step is OPEN. */
  hint,
  children,
  /** Controlled by the page, so the next-action button can open a step. */
  open = false,
  onToggle,
}) {

  const tone = {
    done:      { ring: 'var(--color-success-500)', fill: 'rgba(52,211,153,0.16)', text: 'var(--color-success-500)' },
    current:   { ring: 'var(--accent)', fill: 'var(--accent)', text: '#fff' },
    later:     { ring: 'var(--border)', fill: 'transparent', text: 'var(--text-muted)' },
    available: { ring: 'var(--border)', fill: 'transparent', text: 'var(--text-p)' },
  }[state]

  const Chevron = open ? ChevronDown : ChevronRight

  return (
    <div
      id={id}
      className="pr-glass"
      style={{
        padding: 0,
        overflow: 'hidden',
        // The current step is the only one that draws attention to itself.
        borderColor: state === 'current' ? 'var(--accent)' : undefined,
        opacity: state === 'later' ? 0.7 : 1,
      }}
    >
      <button
        onClick={onToggle}
        style={{
          width: '100%', display: 'flex', alignItems: 'center', gap: 10,
          padding: '13px 16px', background: 'transparent', border: 'none',
          cursor: 'pointer', textAlign: 'left',
        }}
      >
        <span style={{
          width: 22, height: 22, borderRadius: 999, display: 'grid', placeItems: 'center', flexShrink: 0,
          border: `2px ${state === 'later' || state === 'available' ? 'dashed' : 'solid'} ${tone.ring}`,
          background: tone.fill, color: tone.text, fontSize: 11, fontWeight: 900,
        }}>
          {state === 'done' ? <Check size={12} strokeWidth={3} /> : n}
        </span>

        <Icon size={14} style={{ color: state === 'later' ? 'var(--text-muted)' : 'var(--accent)', flexShrink: 0 }} />

        <span style={{ minWidth: 0, flex: 1 }}>
          <span style={{
            display: 'block', fontSize: 12.5, fontWeight: 800,
            color: state === 'later' ? 'var(--text-muted)' : 'var(--text-h)',
            textTransform: 'uppercase', letterSpacing: '.03em',
          }}>
            {title}
          </span>
          {/* A done step says what happened. A later step says nothing at all —
              an explanation of something you cannot do yet is the noise this
              rebuild exists to remove. */}
          {state === 'done' && outcome && (
            <span style={{ display: 'block', fontSize: 11.5, color: 'var(--text-muted)', marginTop: 2, fontWeight: 500 }}>
              {outcome}
            </span>
          )}
        </span>

        {/* No "YOU ARE HERE" chip here: the tracker above already answers
            "where is this trip", and the current stage is the only open one
            with an accent border. Saying it twice on one screen is the kind of
            repetition this rebuild exists to remove. */}
        {state === 'later' && <Lock size={12} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />}

        <Chevron size={15} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
      </button>

      {open && (
        <div style={{ padding: '0 16px 16px' }}>
          {hint && (
            <p style={{ margin: '0 0 10px', fontSize: 11.5, color: 'var(--text-muted)', lineHeight: 1.5 }}>
              {hint}
            </p>
          )}
          {children}
        </div>
      )}
    </div>
  )
}
