import { ShieldCheck, ShieldAlert, CalendarClock, Lock } from 'lucide-react'
import { toneOf, DOC_STATE_TONE, fmtWhen } from '@/services/stosApi'
import HealthChip from './HealthChip'

/**
 * The pre-dispatch gate, shown as the checklist it actually is.
 *
 * Five documents, five issuers, five dates. A single "compliance: expired" chip
 * tells a user they are blocked but not which certificate to go and renew, so
 * this lists every one with its own verdict and the days remaining.
 *
 * The verdict is computed server-side (ComplianceService) and never re-derived
 * here — two places deciding what "expired" means is how a screen ends up
 * disagreeing with the gate that blocks the truck.
 */
export default function CompliancePanel({ compliance, vehicle }) {
  if (!compliance) return null

  const { status, documents = [], expired = [], expiring = [] } = compliance
  const held = Boolean(vehicle?.compliance_hold)

  const tone = status === 'compliant' ? 'green' : (status === 'expiring' ? 'amber' : 'red')

  return (
    <div className="space-y-3">
      <div className="flex items-center gap-2 flex-wrap">
        <HealthChip tone={tone}>{String(status).replace('_', ' ')}</HealthChip>
        {expired.length > 0 && (
          <span className="text-[11px] font-semibold" style={{ color: 'var(--color-danger-500)' }}>
            Dispatch blocked — {expired.join(', ')} {expired.length === 1 ? 'has' : 'have'} expired
          </span>
        )}
        {expired.length === 0 && expiring.length > 0 && (
          <span className="text-[11px] font-semibold" style={{ color: 'var(--color-warning-500, #f59e0b)' }}>
            {expiring.join(', ')} due for renewal
          </span>
        )}
      </div>

      {/* A human hold outranks every date — say so, and say why. */}
      {held && (
        <div className="flex items-start gap-2 rounded-xl px-3 py-2"
          style={{
            background: 'color-mix(in srgb, var(--color-danger-500) 10%, transparent)',
            border: '1px solid var(--color-danger-500)',
          }}>
          <Lock size={13} className="shrink-0 mt-0.5" style={{ color: 'var(--color-danger-500)' }} />
          <div>
            <p className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>Manual compliance hold</p>
            <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
              {vehicle.compliance_hold_reason || 'No reason recorded.'} This overrides the dates below and only a
              person can lift it.
            </p>
          </div>
        </div>
      )}

      <div className="space-y-1.5">
        {documents.map((doc) => {
          const docTone = toneOf(DOC_STATE_TONE[doc.state] || 'amber')
          const unknown = doc.state === 'unknown'

          return (
            <div key={doc.field} className="flex items-center gap-2 rounded-xl px-3 py-2"
              style={{ background: 'var(--bg-input)' }}>
              {doc.state === 'expired'
                ? <ShieldAlert size={13} className="shrink-0" style={{ color: docTone.dot }} />
                : <ShieldCheck size={13} className="shrink-0" style={{ color: docTone.dot }} />}

              <span className="text-[11px] font-bold flex-1 truncate" style={{ color: 'var(--text-h)' }}>
                {doc.label}
              </span>

              <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                {unknown ? 'not recorded' : fmtWhen(doc.date).split(',')[0]}
              </span>

              <span className="text-[10px] font-bold px-1.5 py-0.5 rounded shrink-0"
                style={{ background: `color-mix(in srgb, ${docTone.dot} 14%, transparent)`, color: docTone.dot }}>
                {unknown ? 'unknown'
                  : doc.days_left < 0 ? `${Math.abs(doc.days_left)}d overdue`
                  : doc.days_left === 0 ? 'expires today'
                  : `${doc.days_left}d left`}
              </span>
            </div>
          )
        })}
      </div>

      <p className="flex items-start gap-1.5 text-[10px]" style={{ color: 'var(--text-muted)' }}>
        <CalendarClock size={11} className="shrink-0 mt-0.5" />
        A document is valid through its expiry date. Verdicts are recomputed nightly, so a certificate that lapses
        overnight blocks dispatch the same morning without anyone editing the record. Dates are edited from
        &ldquo;Edit vehicle&rdquo;.
      </p>
    </div>
  )
}
