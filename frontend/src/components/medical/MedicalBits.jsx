/**
 * The small, shared vocabulary of the Medical module's UI.
 *
 * Kept in one place because the same badge means the same thing in four
 * different screens (the doctor portal, both admin registers, both vendor
 * portals) — and a "Hold" that looks like a warning in one and a failure in
 * another would teach people to distrust the colour.
 */

const FITNESS_TONE = {
  Fit: '#10b981',
  Fit_With_Restrictions: '#f59e0b',
  Pending: '#6366f1',
  Unfit: '#ef4444',
  Expired: '#ef4444',
}

const QC_TONE = {
  Approved: '#10b981',
  Pending: '#6366f1',
  Hold: '#f59e0b',     // amber — fixable, and it is coming back
  Rejected: '#ef4444', // red — terminal, a re-examination is the remedy
}

/** Clearance states, as the server names them in `clearance.status`. */
const CLEARANCE_TONE = {
  approved: '#10b981',
  not_applicable: '#64748b',
  pending: '#6366f1',
  hold: '#f59e0b',
  missing: '#f59e0b',
  expired: '#ef4444',
  unfit: '#ef4444',
  rejected: '#ef4444',
}

export const humanise = (s) => (s || '—').replace(/_/g, ' ')

export function Pill({ tone = '#6b7280', children, title }) {
  return (
    <span
      title={title}
      style={{
        display: 'inline-block', padding: '3px 9px', borderRadius: 999,
        background: tone + '22', color: tone, fontSize: 11.5, fontWeight: 700,
        whiteSpace: 'nowrap',
      }}
    >
      {children}
    </span>
  )
}

export function FitnessPill({ status }) {
  return <Pill tone={FITNESS_TONE[status] || '#6b7280'}>{humanise(status)}</Pill>
}

export function QcPill({ status }) {
  if (!status) return <Pill tone="#64748b">Not reviewed</Pill>
  return <Pill tone={QC_TONE[status] || '#6b7280'}>{humanise(status)}</Pill>
}

/** The prerequisite verdict, as one badge. */
export function ClearancePill({ clearance }) {
  if (!clearance) return null
  const tone = CLEARANCE_TONE[clearance.status] || '#6b7280'
  const text = clearance.bypassed ? 'Not applicable'
    : clearance.cleared ? 'Cleared'
    : humanise(clearance.status)

  return <Pill tone={tone} title={clearance.message}>{text}</Pill>
}

/**
 * The health score, out of ten.
 *
 * Shown with its denominator always — a bare "7" invites being read as a
 * percentage, and the difference matters on a worker's profile.
 */
export function HealthScore({ score, band, scale = 10, size = 'md', note }) {
  if (score === null || score === undefined) {
    return <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>Not scored</span>
  }

  const value = Number(score)
  const tone = value >= 8.5 ? '#10b981' : value >= 7 ? '#22c55e' : value >= 5 ? '#f59e0b' : '#ef4444'
  const big = size === 'lg'

  return (
    <div style={{ display: 'flex', alignItems: 'baseline', gap: 6 }} title={note || undefined}>
      <span style={{ fontSize: big ? 30 : 17, fontWeight: 900, color: tone, lineHeight: 1 }}>
        {value.toFixed(1)}
      </span>
      <span style={{ fontSize: big ? 13 : 11, color: 'var(--text-muted)', fontWeight: 700 }}>/{scale}</span>
      {band && <span style={{ fontSize: big ? 12.5 : 11, color: tone, fontWeight: 700, marginLeft: 2 }}>{band}</span>}
    </div>
  )
}

/** A summary tile, matching the register strips elsewhere in the module. */
export function Stat({ label, value, tone = '#7C3AED', onClick, active }) {
  return (
    <div
      className="pr-glass"
      onClick={onClick}
      style={{
        padding: '10px 16px', borderRadius: 12, minWidth: 104,
        cursor: onClick ? 'pointer' : 'default',
        outline: active ? `2px solid ${tone}` : 'none',
      }}
    >
      <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.04em' }}>{label}</div>
      <div style={{ fontSize: 22, fontWeight: 900, color: tone }}>{value ?? 0}</div>
    </div>
  )
}

/* ── Shared inline styles ───────────────────────────────────────────────── */

export const S = {
  btn: {
    display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px',
    borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)',
    color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13,
  },
  btnPrimary: {
    display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 16px',
    borderRadius: 10, border: 'none', background: '#7C3AED', color: '#fff',
    cursor: 'pointer', fontSize: 13, fontWeight: 700,
  },
  input: {
    width: '100%', padding: '8px 12px', borderRadius: 10,
    border: '1px solid var(--border)', background: 'var(--bg-card)',
    color: 'var(--text-h)', fontSize: 13,
  },
  select: {
    padding: '8px 12px', borderRadius: 10, border: '1px solid var(--border)',
    background: 'var(--bg-card)', color: 'var(--text-h)', fontSize: 13,
  },
  th: { padding: '11px 14px', textAlign: 'left' },
  td: { padding: '10px 14px' },
  label: {
    display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-muted)',
    textTransform: 'uppercase', letterSpacing: '0.04em', marginBottom: 4,
  },
}

export const TONES = { FITNESS_TONE, QC_TONE, CLEARANCE_TONE }
