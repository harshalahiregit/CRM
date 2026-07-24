/**
 * PortalStepProgress — horizontal animated step indicator for the onboarding wizard.
 *
 * Props:
 *   steps      — array of { key, label, icon? } (one per wizard step)
 *   current    — 1-based index of the active step
 *   total      — total step count
 *
 * Design: completed = purple filled + checkmark, current = pulsing purple,
 *         pending = gray. Purple animated connectors between steps.
 * No framer-motion dependency — uses CSS animations defined in portal.css.
 */
import { Check } from 'lucide-react'
// portal.css is imported by the host page (TpvOnboardingWizard)

export default function PortalStepProgress({ steps = [], current = 1, total }) {
  const count = total || steps.length || 6
  const pct = Math.round(((current - 1) / Math.max(count - 1, 1)) * 100)

  return (
    <div className="portal-stepper-wrap">
      {/* Meta row — title + percentage */}
      <div className="portal-stepper-meta">
        <div>
          <span style={{ fontSize: 13.5, fontWeight: 700, color: 'var(--text-h)' }}>
            Onboarding Progress
          </span>
          <span style={{ fontSize: 12.5, color: 'var(--text-muted)', marginLeft: 10 }}>
            Step {current} of {count}
          </span>
        </div>
        <span className="portal-pct-badge">{pct}% Complete</span>
      </div>

      {/* Step track */}
      <div className="portal-stepper">
        {steps.map((step, idx) => {
          const stepNum = idx + 1
          const isDone    = stepNum < current
          const isCurrent = stepNum === current
          const isPending  = stepNum > current
          const StepIcon  = step.icon

          return (
            <div key={step.key || stepNum} className="portal-step-item">
              {/* Connector (drawn before each step except the first) */}
              {idx > 0 && (
                <div
                  className={`portal-step-connector${
                    idx < current ? ' done' : idx === current - 1 ? ' animating' : ''
                  }`}
                />
              )}

              {/* Circle */}
              <div
                className={`portal-step-circle ${isDone ? 'done' : isCurrent ? 'current' : 'pending'}`}
              >
                {isDone ? (
                  <Check size={15} strokeWidth={3} />
                ) : StepIcon ? (
                  <StepIcon size={15} />
                ) : (
                  <span>{stepNum}</span>
                )}
              </div>

              {/* Label */}
              <div
                className={`portal-step-label ${isDone ? 'done' : isCurrent ? 'current' : ''}`}
              >
                {step.label}
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}
