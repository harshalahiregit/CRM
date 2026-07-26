/**
 * Shared UI primitives for the candidate onboarding portal.
 *
 * This module exists to break a circular import: OnboardingPortal renders
 * OnboardingFormTab, and OnboardingFormTab needs the same primitives. Importing
 * them back out of OnboardingPortal made the two modules mutually dependent, so a
 * module-level read of `accent` inside OnboardingFormTab hit the temporal dead
 * zone and threw at runtime.
 *
 * Dependency direction is now strictly one-way:
 *
 *     OnboardingPortal ─┐
 *                       ├─▶ OnboardingShared
 *     OnboardingFormTab ┘
 *
 * Nothing in here may import from OnboardingPortal or OnboardingFormTab.
 */

export const accent = '#7C3AED'

export const inputStyle = {
  width: '100%', padding: '10px 12px', border: '1px solid #e2e8f0', borderRadius: 9,
  fontSize: 13.5, outline: 'none', boxSizing: 'border-box', color: '#0f172a', background: '#fff',
}

export const Card = ({ title, action, children }) => (
  <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 16, padding: 22 }}>
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
      <h2 style={{ fontSize: 15.5, fontWeight: 800, margin: 0 }}>{title}</h2>{action}
    </div>
    {children}
  </div>
)

export const ProgressBar = ({ percent }) => (
  <div style={{ height: 10, borderRadius: 999, background: '#f1f5f9', overflow: 'hidden' }}>
    <div style={{ width: `${percent}%`, height: '100%', background: `linear-gradient(90deg,#a78bfa,${accent})`, transition: 'width .3s' }} />
  </div>
)

export const Empty = ({ children }) => <p style={{ fontSize: 13, color: '#94a3b8', margin: 0 }}>{children}</p>

export const Link = ({ onClick, children }) => (
  <button onClick={onClick} style={{ background: 'none', border: 'none', color: accent, cursor: 'pointer', fontSize: 12.5, fontWeight: 700 }}>{children}</button>
)

export const Grid = ({ children }) => (
  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>{children}</div>
)

export const Input = (p) => <input {...p} style={inputStyle} />

export const Sel = ({ opts, ...p }) => (
  <select {...p} style={inputStyle}>{opts.map(o => <option key={o} value={o}>{o || 'Select…'}</option>)}</select>
)

export const Field = ({ label, children, full }) => (
  <div style={full ? { gridColumn: '1/-1' } : undefined}>
    <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#475569', marginBottom: 6 }}>{label}</label>
    {children}
  </div>
)
