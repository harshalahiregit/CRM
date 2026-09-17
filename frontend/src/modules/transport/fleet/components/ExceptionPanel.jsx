import { Link } from 'react-router-dom'
import { ArrowRight, UserRound, AlertCircle } from 'lucide-react'
import { toneOf, nextActionTo } from '@/services/stosApi'
import HealthChip from './HealthChip'

/**
 * A blocked vehicle, explained.
 *
 * The rule this exists to enforce: the UI never shows a generic error. Every
 * exception states WHY it is blocked, WHAT is missing, WHO owns it, and WHAT
 * happens next — and the "what next" is a real link, not a label, so no screen
 * is a dead end.
 *
 * Renders nothing when there is nothing wrong. A healthy vehicle should not
 * carry an empty "no issues" box around.
 */
export default function ExceptionPanel({ issues = [], vehicleId, compact = false }) {
  if (!issues.length) return null

  return (
    <div className={compact ? 'space-y-1.5' : 'space-y-2'}>
      {issues.map((issue) => {
        const t = toneOf(issue.tone)

        return (
          <div
            key={issue.code}
            className="rounded-xl p-3"
            style={{
              background: `color-mix(in srgb, ${t.dot} 7%, transparent)`,
              border: `1px solid color-mix(in srgb, ${t.dot} 25%, transparent)`,
            }}
          >
            <div className="flex items-start gap-2">
              <AlertCircle size={14} className="shrink-0 mt-0.5" style={{ color: t.dot }} />
              <div className="min-w-0 flex-1">
                <p className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>{issue.why}</p>

                {!compact && (
                  <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>
                    <span className="font-semibold">Missing: </span>{issue.missing}
                  </p>
                )}

                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1.5">
                  <span className="inline-flex items-center gap-1 text-[10px]" style={{ color: 'var(--text-muted)' }}>
                    <UserRound size={10} /> {issue.owner}
                  </span>

                  {vehicleId && issue.next && (
                    <Link
                      to={nextActionTo(vehicleId, issue.next.action)}
                      className="inline-flex items-center gap-1 text-[10px] font-bold"
                      style={{ color: t.dot }}
                    >
                      {issue.next.label} <ArrowRight size={10} />
                    </Link>
                  )}
                </div>
              </div>

              <HealthChip tone={issue.tone} size="sm" />
            </div>
          </div>
        )
      })}
    </div>
  )
}
