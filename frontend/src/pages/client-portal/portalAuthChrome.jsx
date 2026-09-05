/**
 * Shared chrome for the customer portal's remaining auth pages — set-password,
 * forgot-password and the profile form.
 *
 * These styles used to live in ClientPortalLogin.jsx and were imported from it
 * by its siblings. That page is gone: every identity now signs in at the one
 * login page (/auth/login?role=client), so the chrome moved here rather than
 * leaving a "Login" file that contains no login.
 */

export function AuthShell({ title, subtitle, children }) {
  return (
    <div style={{ minHeight: '100vh', display: 'grid', placeItems: 'center', background: 'var(--bg-global, #0b0d12)', padding: 20 }}>
      <div style={{ width: '100%', maxWidth: 420 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 22 }}>
          <div style={{ width: 38, height: 38, borderRadius: 11, display: 'grid', placeItems: 'center', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
            <Building2 size={19} style={{ color: '#fff' }} />
          </div>
          <div>
            <div style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-h,#fff)' }}>Customer Portal</div>
            <div style={{ fontSize: 11.5, color: 'var(--text-muted,#9ca3af)' }}>Your invoices, projects and support in one place</div>
          </div>
        </div>
        <div style={{ background: 'var(--bg-card,#12141b)', border: '1px solid var(--border,#2a2f3a)', borderRadius: 16, padding: 26 }}>
          <h1 style={{ fontSize: 19, fontWeight: 800, color: 'var(--text-h,#fff)', margin: '0 0 4px' }}>{title}</h1>
          {subtitle && <p style={{ fontSize: 12.5, color: 'var(--text-muted,#9ca3af)', margin: '0 0 18px' }}>{subtitle}</p>}
          {children}
        </div>
      </div>
    </div>
  )
}

export const lbl = { display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--text-muted,#9ca3af)', marginBottom: 5 }
export const inp = { width: '100%', padding: '10px 12px', background: 'var(--bg-input,#0f1117)', border: '1px solid var(--border,#2a2f3a)', borderRadius: 8, color: 'var(--text-h,#fff)', fontSize: 13, outline: 'none', boxSizing: 'border-box' }
export const primaryBtn = { width: '100%', padding: '11px', borderRadius: 8, background: '#7C3AED', color: '#fff', border: 'none', cursor: 'pointer', fontSize: 14, fontWeight: 700 }
export const linkStyle = { color: '#a78bfa', textDecoration: 'none', fontWeight: 600 }
export const errStyle = { color: '#ef4444', fontSize: 12.5, background: 'rgba(239,68,68,0.1)', padding: '8px 10px', borderRadius: 6 }
export const okStyle = { color: '#10b981', fontSize: 12.5, background: 'rgba(16,185,129,0.1)', padding: '8px 10px', borderRadius: 6 }
