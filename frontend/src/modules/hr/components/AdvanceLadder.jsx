/**
 * Where an advance has reached: manager, then accounts, then director.
 *
 * The SangoeTrack screen approves "without choosing a stage", so there was
 * nothing to show and nothing to see — a request was pending or it was not. The
 * point of drawing the rungs is that "whose desk is this on" stops being a
 * question somebody has to ask in a group chat.
 *
 * The stages come from the server, which owns the order. Hardcoding three names
 * here would mean a fourth tier one day changes the policy in one place and the
 * picture in another.
 */

import { Check } from 'lucide-react'

const TIER_LABEL = {
  manager:  'Manager',
  accounts: 'Accounts',
  director: 'Director',
}

export default function AdvanceLadder({ ladder = [], compact = false }) {
  if (!ladder.length) return null

  return (
    <ol className="flex items-center gap-1 flex-wrap" style={{ listStyle: 'none', margin: 0, padding: 0 }}>
      {ladder.map((step, i) => {
        const tone = step.done ? '#34d399' : step.current ? '#fbbf24' : 'var(--text-muted)'

        return (
          <li key={step.tier} className="flex items-center gap-1">
            <span className="flex items-center gap-1 rounded-md font-bold"
              style={{
                padding: compact ? '2px 6px' : '4px 9px',
                fontSize: compact ? 9 : 10,
                textTransform: 'uppercase',
                letterSpacing: '0.04em',
                color: tone,
                background: step.done
                  ? 'rgba(52,211,153,0.12)'
                  : step.current ? 'rgba(251,191,36,0.14)' : 'var(--bg-input)',
                // Only the rung awaiting somebody is outlined — everything else
                // recedes, so the eye lands on the one that needs a person.
                border: `1px solid ${step.current ? 'rgba(251,191,36,0.4)' : 'transparent'}`,
              }}>
              {step.done && <Check size={compact ? 8 : 10} />}
              {TIER_LABEL[step.tier] || step.tier}
            </span>

            {i < ladder.length - 1 && (
              <span aria-hidden="true" style={{ color: 'var(--text-muted)', opacity: 0.5, fontSize: 10 }}>›</span>
            )}
          </li>
        )
      })}
    </ol>
  )
}
