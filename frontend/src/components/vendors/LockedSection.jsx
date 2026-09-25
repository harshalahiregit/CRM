import { Lock, ArrowRight, CheckCircle2, Circle } from 'lucide-react'
import { Link } from 'react-router-dom'

/**
 * What a section shows when the vendor has not been onboarded yet.
 *
 * WHY THIS EXISTS. The workspace already hid locked sections from the sidebar,
 * and the file that does it says so plainly: "Everything below is still routed
 * and still reachable by URL; the lock decides what the sidebar offers, not
 * what exists." That is the whole bug. A hidden button is not a lock — the
 * section still opened from a bookmark, from a pasted link, and from the
 * module's own registers, which is how it was found: Purchase → Prequalification
 * lists every vendor, and picking one walked straight into a workspace that was
 * supposed to be shut.
 *
 * So the section itself now refuses. Same rule, applied where the work happens
 * rather than where the menu is drawn.
 *
 * AND IT TEACHES. A locked page that only says "no" leaves somebody stuck on a
 * vendor they were told to set up. This names the next step and links to it,
 * which is the other half of what was asked for: show a new user how to onboard
 * and show nothing else.
 */
export default function LockedSection({ label, overviewHref, steps = null, notice = null }) {
  const list = Array.isArray(steps) ? steps : []
  const next = list.find((s) => !s.complete) || null

  return (
    <div
      className="pr-glass"
      style={{ padding: 24, maxWidth: 640, margin: '8px auto', textAlign: 'center' }}
    >
      <div
        style={{
          width: 52, height: 52, borderRadius: 16, margin: '0 auto 14px',
          display: 'flex', alignItems: 'center', justifyContent: 'center',
          background: 'rgba(245,158,11,0.12)', border: '1px solid rgba(245,158,11,0.28)',
        }}
      >
        <Lock size={22} style={{ color: '#f59e0b' }} />
      </div>

      <h2 style={{ margin: 0, fontSize: 17, fontWeight: 800, color: 'var(--text-h)' }}>
        {label ? `${label} opens after onboarding` : 'This section opens after onboarding'}
      </h2>

      <p style={{ margin: '8px auto 0', maxWidth: 460, fontSize: 13, lineHeight: 1.65, color: 'var(--text-muted)' }}>
        {notice?.reason || 'This vendor has not finished onboarding.'}{' '}
        Until they are approved there is nothing here to show — no orders, no workers,
        no documents against a company nobody has cleared yet.
      </p>

      {/* The steps, so the page that says "not yet" also says "here is how". */}
      {list.length > 0 && (
        <ol
          style={{
            listStyle: 'none', margin: '18px 0 0', padding: 0,
            display: 'flex', flexDirection: 'column', gap: 6, textAlign: 'left',
          }}
        >
          {list.map((s) => {
            const isNext = next && s.step === next.step

            return (
              <li
                key={s.step}
                style={{
                  display: 'flex', alignItems: 'center', gap: 10, padding: '8px 12px', borderRadius: 10,
                  background: isNext ? 'rgba(124,58,237,0.10)' : 'var(--bg-input)',
                  border: `1px solid ${isNext ? 'rgba(124,58,237,0.35)' : 'var(--border)'}`,
                }}
              >
                {s.complete
                  ? <CheckCircle2 size={15} style={{ color: '#0ca30c', flexShrink: 0 }} />
                  : <Circle size={15} style={{ color: isNext ? '#a78bfa' : 'var(--text-faint)', flexShrink: 0 }} />}

                <span style={{ fontSize: 12.5, fontWeight: isNext ? 800 : 600, color: 'var(--text-h)' }}>
                  {s.step}. {s.label}
                </span>

                <span style={{ marginLeft: 'auto', fontSize: 11.5, color: 'var(--text-muted)' }}>
                  {isNext ? 'Next step' : s.detail}
                </span>
              </li>
            )
          })}
        </ol>
      )}

      <Link
        to={overviewHref}
        className="inline-flex items-center gap-2"
        style={{
          marginTop: 18, padding: '9px 16px', borderRadius: 10, fontSize: 12.5, fontWeight: 800,
          background: 'linear-gradient(135deg,#7c3aed,#6d28d9)', color: '#fff', textDecoration: 'none',
        }}
      >
        {next ? `Go to ${next.label}` : 'Go to onboarding'} <ArrowRight size={14} />
      </Link>
    </div>
  )
}
