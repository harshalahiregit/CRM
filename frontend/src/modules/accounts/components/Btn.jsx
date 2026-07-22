/**
 * Secondary / ghost button, styled to match the app's ConfirmDialog cancel look.
 * Kept module-local so the Accounts module stays self-contained (no global CSS edits).
 */
export function GhostButton({ children, className = '', ...props }) {
  return (
    <button
      className={`px-4 py-2.5 rounded-xl text-sm font-bold transition-all ${className}`}
      style={{ background: 'var(--bg-hover)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}
      {...props}
    >
      {children}
    </button>
  )
}

export default GhostButton
