import { Check, Clock, CircleDashed, ArrowRight } from 'lucide-react'

/**
 * Registration status for a vendor portal.
 *
 * Reports where the vendor's registration has got to. It offers NO admin action
 * (approving/holding/sending back is the account team's job) — but it MAY carry a
 * single self-service CTA that points the vendor at what THEY do next (e.g. "Start
 * submitting your documents"), supplied by the caller via actionLabel/onAction.
 * Without those props it stays inert, exactly as before.
 *
 * Module-agnostic: TPV and Purchase both pass their own already-resolved steps, so
 * neither module imports the other.
 */

/* Status palette, fixed. Each state carries an icon AND a word — never colour alone. */
const DONE    = '#0ca30c'
const PENDING = '#fab219'

export default function RegistrationStatusCard({ steps = [], title = 'Registration Status', note, actionLabel, onAction }) {
  if (!steps.length) return null

  const done = steps.filter(s => s.done).length
  const pct = Math.round((done / steps.length) * 100)
  const allDone = done === steps.length

  return (
    <div style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 14, padding: '18px 20px' }}>
      <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', gap: 12, marginBottom: 14 }}>
        <div>
          <h3 style={{ margin: 0, fontSize: 14, fontWeight: 700, color: 'var(--text-h)' }}>{title}</h3>
          <p style={{ margin: '3px 0 0', fontSize: 11.5, color: 'var(--text-muted)' }}>
            {note || 'For your information — your account team manages these steps.'}
          </p>
        </div>
        <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-muted)', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' }}>
          {done} of {steps.length}
        </span>
      </div>

      {/* Progress rail — one bar, no per-step numbers competing with the list below. */}
      <div style={{ height: 5, borderRadius: 999, background: 'var(--bg-input)', overflow: 'hidden', marginBottom: 16 }}>
        <div style={{ width: `${pct}%`, height: '100%', borderRadius: 999, background: DONE, transition: 'width .3s' }} />
      </div>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
        {steps.map((s, i) => {
          const Icon = s.done ? Check : (s.current ? Clock : CircleDashed)
          const tone = s.done ? DONE : (s.current ? PENDING : 'var(--text-muted)')
          return (
            <div key={s.key || i} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '7px 0' }}>
              <span style={{
                width: 18, height: 18, borderRadius: 999, flexShrink: 0,
                display: 'flex', alignItems: 'center', justifyContent: 'center',
                background: s.done ? `color-mix(in srgb, ${DONE} 14%, transparent)` : 'transparent',
                border: s.done ? 'none' : `1px solid var(--border)`,
              }}>
                <Icon size={11} strokeWidth={3} style={{ color: tone }} />
              </span>
              <span style={{
                fontSize: 12.5, flex: 1,
                fontWeight: s.current ? 700 : 500,
                color: s.done || s.current ? 'var(--text-h)' : 'var(--text-muted)',
              }}>{s.label}</span>
              <span style={{ fontSize: 10.5, fontWeight: 700, color: tone, whiteSpace: 'nowrap' }}>
                {s.done ? 'Completed' : (s.current ? 'In review' : 'Pending')}
              </span>
            </div>
          )
        })}
      </div>

      {/* Optional self-service CTA — only what the vendor does next, never an admin action. */}
      {actionLabel && onAction && !allDone && (
        <button
          onClick={onAction}
          style={{
            marginTop: 16, width: '100%', display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
            gap: 8, padding: '10px 14px', borderRadius: 10, border: 'none', cursor: 'pointer',
            background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, fontSize: 13,
          }}
        >
          {actionLabel} <ArrowRight size={15} />
        </button>
      )}
    </div>
  )
}
