/**
 * Shared chrome for the Purchase vendor portal's remaining auth pages —
 * registration, e-mail verification, forgot- and reset-password.
 *
 * These styles used to live in PurchaseVendorLogin.jsx and were imported from
 * it by its siblings. That page is gone: every identity now signs in at the one
 * login page (/auth/login?role=purchase_vendor), so the chrome moved here
 * rather than leaving a "Login" file that contains no login.
 */

export function AuthShell({ title, subtitle, children }) {
  return (
    <div style={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'var(--bg-app, #0f1117)', padding: 20 }}>
      <div style={{ width: 400, maxWidth: '94vw', background: 'var(--bg-card, #171923)', border: '1px solid var(--border, #2a2f3a)', borderRadius: 14, padding: 28, boxShadow: '0 12px 40px rgba(0,0,0,0.35)' }}>
        <div style={{ textAlign: 'center', marginBottom: 20 }}>
          <div style={{ fontSize: 20, fontWeight: 800, color: 'var(--text-h, #fff)' }}>{title}</div>
          <div style={{ fontSize: 13, color: 'var(--text-muted, #9ca3af)', marginTop: 4 }}>{subtitle}</div>
        </div>
        {children}
      </div>
    </div>
  )
}

export const lbl = { display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--text-muted, #9ca3af)', marginBottom: 5 }
export const inp = { width: '100%', padding: '10px 12px', background: 'var(--bg-input, #0f1117)', border: '1px solid var(--border, #2a2f3a)', borderRadius: 8, color: 'var(--text-h, #fff)', fontSize: 13, outline: 'none', boxSizing: 'border-box' }
export const primaryBtn = { width: '100%', padding: '11px', borderRadius: 8, background: '#7C3AED', color: '#fff', border: 'none', cursor: 'pointer', fontSize: 14, fontWeight: 700 }
export const linkStyle = { color: '#a78bfa', textDecoration: 'none', fontWeight: 600 }
export const errStyle = { color: '#ef4444', fontSize: 12.5, background: 'rgba(239,68,68,0.1)', padding: '8px 10px', borderRadius: 6 }
