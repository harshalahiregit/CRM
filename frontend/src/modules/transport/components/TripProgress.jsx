import { Check } from 'lucide-react'
import { TRIP_JOURNEY, tripJourneyState } from '../constants'

/**
 * Where this trip is, as five steps across the top of the page.
 *
 * The trip's 16-state machine is correct but it is not walkable: a person being
 * shown this page for the first time cannot tell from "Allocated" what has
 * happened, what is happening now, or what happens next. Five steps with one
 * highlighted answer all three at a glance, and the panels below repeat the same
 * numbers so the page reads top to bottom in the order the work is done.
 *
 * ── DONE / CURRENT ARE SHAPES, NOT ONLY COLOURS (UX §129) ────────────────
 * A finished step is a tick, the current step is a filled number, a future step
 * is an outlined number. Someone who cannot separate green from grey still reads
 * the row correctly.
 *
 * ── THE FIFTH STEP IS HONEST, NOT DECORATION ────────────────────────────
 * "On the road" is dashed and labelled "Not built yet". Stopping the tracker at
 * Dispatch would imply a dispatched trip is a finished trip; showing the step
 * greyed with no label would imply this trip is simply behind. Neither is true —
 * tracking, delivery and POD are later work, and the row says so.
 */

const tone = {
  done:    { ring: '#34d399', fill: 'rgba(52,211,153,0.16)', text: '#34d399', line: '#34d399' },
  current: { ring: '#7C3AED', fill: '#7C3AED',               text: '#a78bfa', line: 'var(--border)' },
  todo:    { ring: 'var(--border)', fill: 'transparent',     text: 'var(--text-muted)', line: 'var(--border)' },
  later:   { ring: 'var(--border)', fill: 'transparent',     text: 'var(--text-muted)', line: 'var(--border)' },
}

export default function TripProgress({ status }) {
  const steps = TRIP_JOURNEY.map((s) => ({ ...s, state: tripJourneyState(s, status) }))

  return (
    <div className="pr-glass" style={{ padding: '16px 20px', marginBottom: 16 }}>
      <div style={{ display: 'flex', gap: 0, flexWrap: 'wrap' }}>
        {steps.map((s, i) => {
          const t = tone[s.state]
          const isLast = i === steps.length - 1

          return (
            <div key={s.key} style={{ flex: '1 1 150px', minWidth: 150, display: 'flex', gap: 10 }}>
              <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', flexShrink: 0 }}>
                <div style={{
                  width: 26, height: 26, borderRadius: 999, display: 'grid', placeItems: 'center',
                  border: `2px ${s.state === 'later' ? 'dashed' : 'solid'} ${t.ring}`,
                  background: t.fill,
                  color: s.state === 'current' ? '#fff' : t.text,
                  fontSize: 12, fontWeight: 800,
                }}>
                  {s.state === 'done' ? <Check size={13} strokeWidth={3} /> : i + 1}
                </div>
              </div>

              <div style={{ minWidth: 0, paddingRight: 20, position: 'relative', flex: 1, marginTop: 6 }}>
                <p style={{
                  margin: 0, fontSize: 12.5, fontWeight: 800,
                  color: s.state === 'todo' || s.state === 'later' ? 'var(--text-muted)' : 'var(--text-h)',
                }}>
                  {s.label}
                </p>
                <p style={{ margin: '2px 0 0', fontSize: 11, color: 'var(--text-muted)', lineHeight: 1.45 }}>
                  {s.blurb}
                </p>

                {s.state === 'current' && (
                  <p style={{ margin: '4px 0 0', fontSize: 10.5, fontWeight: 800, letterSpacing: '.05em', textTransform: 'uppercase', color: '#a78bfa' }}>
                    You are here
                  </p>
                )}
                {s.state === 'later' && (
                  <p style={{ margin: '4px 0 0', fontSize: 10.5, fontWeight: 800, letterSpacing: '.05em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>
                    Not built yet
                  </p>
                )}

                {!isLast && (
                  <div style={{
                    position: 'absolute', left: 0, right: 0, top: -19, height: 2,
                    background: t.line, opacity: s.state === 'done' ? 0.5 : 0.35,
                  }} />
                )}
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}
