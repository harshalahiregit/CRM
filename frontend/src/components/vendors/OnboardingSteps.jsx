import { Link } from 'react-router-dom'

/**
 * The onboarding steps, in order, with the current one marked — and clickable.
 *
 * ONE COMPONENT, THREE PLACES. Purchase's decision panel had a step strip,
 * added for SIR-000006; TPV never got one and showed only "Step 3 of 7", which
 * says where a vendor is but not what the steps are or which one is next — the
 * exact complaint that strip was built to answer, still live in the other
 * workspace. Rather than copy it across and have two of them drift, it lives
 * here and all three callers render the same thing: both decision panels and
 * the screen a locked section shows.
 *
 * AND THEY GO SOMEWHERE. The strip used to be a picture of progress: it told an
 * admin that step 1 was outstanding and left them to work out which of forty
 * sidebar entries does step 1. Each step now links to the section that
 * completes it, so the answer to "what do I do next" is a click rather than a
 * hunt.
 *
 * Every step carries the server's own one-line detail ("3/7 uploaded",
 * "2 rejected"), because "incomplete" on its own does not tell anybody what to
 * chase.
 */

/**
 * Which section completes each step.
 *
 * Neutral names, translated by each workspace: Purchase addresses its sections
 * by URL segment ('contacts'), TPV by a slugged tab label ('contact'). Every
 * value here must stay inside PRE_ONBOARDING_SECTIONS in workspaceLock.js, or
 * the strip would offer a link into a section that refuses to open.
 */
export const STEP_SECTION = {
  contacts: 'contact',
  kickoff: 'meeting',
  profile: 'profile',
  documents: 'documents',
  // Review and confirmation are both done against the uploaded documents.
  review: 'documents',
  confirmation: 'documents',
  // The decision itself lives on the panel this strip sits in.
  submission: 'overview',
}

export default function OnboardingSteps({ steps = [], hrefFor = null, compact = false }) {
  const list = Array.isArray(steps) ? steps : []
  if (list.length === 0) return null

  const nextStep = list.find((s) => !s.complete)?.step ?? null

  return (
    <div
      style={{
        display: 'grid',
        gridTemplateColumns: `repeat(auto-fit,minmax(${compact ? 140 : 150}px,1fr))`,
        gap: 8,
        margin: compact ? '14px 0 0' : '0 0 14px',
        textAlign: 'left',
      }}
    >
      {list.map((s) => {
        const isNext = s.step === nextStep
        const tone = s.complete ? '#0ca30c' : isNext ? '#7C3AED' : 'var(--border)'
        const href = hrefFor ? hrefFor(STEP_SECTION[s.key], s) : null

        const body = (
          <>
            <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 3 }}>
              <span
                style={{
                  width: 17, height: 17, borderRadius: '50%', flexShrink: 0,
                  display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
                  fontSize: 9.5, fontWeight: 800,
                  background: s.complete ? '#0ca30c' : isNext ? '#7C3AED' : 'var(--bg-input)',
                  color: s.complete || isNext ? '#fff' : 'var(--text-muted)',
                  border: s.complete || isNext ? 'none' : '1px solid var(--border)',
                }}
              >
                {s.complete ? '✓' : s.step}
              </span>
              <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-h)' }}>{s.label}</span>
            </div>

            <div style={{ fontSize: 11, color: isNext ? '#a78bfa' : 'var(--text-muted)', fontWeight: isNext ? 700 : 500 }}>
              {isNext ? 'NEXT STEP' : s.detail}
            </div>
          </>
        )

        const box = {
          display: 'block',
          padding: '9px 11px',
          borderRadius: 10,
          textDecoration: 'none',
          border: `1px solid color-mix(in srgb, ${tone} 45%, var(--border))`,
          background: s.complete || isNext ? `color-mix(in srgb, ${tone} 7%, transparent)` : 'transparent',
        }

        // A step with nowhere to go stays a plain box rather than pretending to
        // be a button — an admin-only step on the vendor's own screen, or a
        // caller that did not pass a destination.
        return href
          ? <Link key={s.step} to={href} style={{ ...box, cursor: 'pointer' }} title={`Go to ${s.label}`}>{body}</Link>
          : <div key={s.step} style={box}>{body}</div>
      })}
    </div>
  )
}
