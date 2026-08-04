import { Check, ArrowRight, Circle, AlertTriangle } from 'lucide-react'
import { PHASES, phaseIndex, nextActionFor, resolvePhase } from '@/modules/hr/recruitmentPhases'

/**
 * Review comment #14 — "Highlight clearly to go to the next PHASE".
 *
 *   ✔ completed · ● current · ➜ next
 *
 * One component for all six recruitment screens, driven by
 * `recruitmentPhases.js`, which reads each module's EXISTING status. Nothing
 * here stores or advances a workflow — a second engine would eventually
 * disagree with the first.
 *
 * Responsive by construction: the strip is a horizontally scrollable flex row on
 * narrow screens (labels shorten, the scroll never reaches the page body) and
 * lays out fully from `sm` upward.
 */
export default function WorkflowProgress({ kind, record, compact = false, showNextAction = true }) {
  const resolved = resolvePhase(kind, record)
  const currentIdx = phaseIndex(resolved.key)

  // `complete` means this phase is done but the next has not begun, so the
  // NEXT one is what needs highlighting.
  const nextIdx = resolved.terminal
    ? -1
    : Math.min(currentIdx + 1, PHASES.length - 1)
  const highlightIdx = resolved.complete ? nextIdx : currentIdx

  const stateOf = (i) => {
    if (resolved.terminal && i > currentIdx) return 'blocked'
    if (i < currentIdx) return 'done'
    if (i === currentIdx) return resolved.complete ? 'done' : 'current'
    if (i === nextIdx) return 'next'
    return 'todo'
  }

  return (
    <div className="w-full">
      {/* overflow-x-auto on the strip itself, never on the page — a wide
          pipeline must not make the whole screen scroll sideways. */}
      <div className="overflow-x-auto pb-1" style={{ scrollbarWidth: 'thin' }}>
        <div className="flex items-stretch gap-1 sm:gap-1.5" style={{ minWidth: 'min-content' }}>
          {PHASES.map((phase, i) => {
            const state = stateOf(i)
            const s = STYLE[state]
            return (
              <div key={phase.key} className="flex items-center flex-shrink-0">
                <div className="rounded-xl px-2.5 sm:px-3 py-2 flex items-center gap-1.5 sm:gap-2"
                  style={{ background: s.bg, border: `1px solid ${s.border}`,
                           boxShadow: state === 'next' ? `0 0 0 2px ${s.border}55` : 'none' }}>
                  <span className="rounded-full flex items-center justify-center flex-shrink-0"
                    style={{ width: 18, height: 18, background: s.dot, color: '#fff' }}>
                    <Icon state={state} />
                  </span>
                  <span className="whitespace-nowrap" style={{ lineHeight: 1.15 }}>
                    <span className="block text-[10px] sm:text-[11px] font-bold" style={{ color: s.text }}>
                      {/* The short label is what keeps nine phases legible on a
                          phone; the full one appears from sm upward. */}
                      <span className="sm:hidden">{phase.short}</span>
                      <span className="hidden sm:inline">{phase.label}</span>
                    </span>
                    {!compact && state === 'next' && (
                      <span className="block text-[9px] font-semibold" style={{ color: s.text, opacity: 0.85 }}>
                        Next
                      </span>
                    )}
                    {!compact && state === 'current' && (
                      <span className="block text-[9px] font-semibold" style={{ color: s.text, opacity: 0.85 }}>
                        You are here
                      </span>
                    )}
                  </span>
                </div>

                {i < PHASES.length - 1 && (
                  <span className="px-0.5 flex-shrink-0" style={{ color: 'var(--text-muted)', opacity: 0.45 }}>
                    <ArrowRight size={11} />
                  </span>
                )}
              </div>
            )
          })}
        </div>
      </div>

      {showNextAction && !compact && (
        <NextAction resolved={resolved} nextIdx={nextIdx} highlightIdx={highlightIdx} />
      )}
    </div>
  )
}

function NextAction({ resolved, nextIdx, highlightIdx }) {
  // A stopped pipeline has no next phase, and saying "next: Offer" on a rejected
  // candidate would be actively misleading.
  if (resolved.terminal) {
    const label = { rejected: 'Rejected', closed: 'Closed', expired: 'Offer expired',
                    on_hold: 'On hold', cancelled: 'Cancelled' }[resolved.terminal] || 'Stopped'
    return (
      <p className="text-[11px] mt-2 flex items-center gap-1.5" style={{ color: '#f87171' }}>
        <AlertTriangle size={11} /> {label} — this hire is not progressing to the next phase.
      </p>
    )
  }

  const phase = PHASES[highlightIdx]
  const action = nextActionFor(phase?.key)
  if (!action || highlightIdx >= PHASES.length - 1) {
    return (
      <p className="text-[11px] mt-2 flex items-center gap-1.5" style={{ color: '#10b981' }}>
        <Check size={11} /> Hire complete.
      </p>
    )
  }

  return (
    <p className="text-[11px] mt-2 flex items-center gap-1.5 flex-wrap" style={{ color: 'var(--text-muted)' }}>
      <ArrowRight size={11} style={{ color: '#a78bfa' }} />
      <span>Next: <b style={{ color: 'var(--text-h)' }}>{PHASES[nextIdx]?.label || phase.label}</b></span>
      <span style={{ opacity: 0.6 }}>· {action}</span>
    </p>
  )
}

function Icon({ state }) {
  if (state === 'done') return <Check size={11} />
  if (state === 'next') return <ArrowRight size={11} />
  if (state === 'current') return <span style={{ width: 7, height: 7, borderRadius: 99, background: '#fff' }} />
  return <Circle size={7} />
}

const STYLE = {
  done:    { bg: 'rgba(16,185,129,0.10)',  border: 'rgba(16,185,129,0.35)',  dot: '#10b981', text: '#10b981' },
  current: { bg: 'rgba(124,58,237,0.14)',  border: 'rgba(124,58,237,0.45)',  dot: '#7C3AED', text: '#a78bfa' },
  next:    { bg: 'rgba(245,158,11,0.12)',  border: 'rgba(245,158,11,0.50)',  dot: '#f59e0b', text: '#f59e0b' },
  todo:    { bg: 'var(--bg-input)',        border: 'var(--border)',          dot: 'var(--border)', text: 'var(--text-muted)' },
  blocked: { bg: 'var(--bg-input)',        border: 'var(--border)',          dot: 'var(--border)', text: 'var(--text-muted)' },
}
