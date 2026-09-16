/**
 * The Active / Inactive switch on a vendor row.
 *
 * Lifted out of TpvVendors so the Purchase list can show the same control
 * rather than a second one drawn slightly differently. Two of these is how one
 * of them ends up with a different label, a different colour, or a different
 * idea of which way is "on".
 *
 * It reports the state in words as well as position: a switch alone is
 * ambiguous at a glance — people disagree about whether left means off — and
 * this one decides whether somebody can sign in.
 */
export default function ToggleSwitch({ on, disabled, onChange, busy = false }) {
  const title = busy
    ? 'Saving…'
    : on ? 'Active — click to deactivate' : 'Inactive — click to activate'

  return (
    <button
      type="button"
      onClick={disabled || busy ? undefined : onChange}
      disabled={disabled || busy}
      aria-pressed={on}
      title={title}
      style={{
        display: 'inline-flex', alignItems: 'center', gap: 7,
        cursor: disabled || busy ? 'default' : 'pointer',
        background: 'none', border: 'none', padding: 0,
        opacity: busy ? 0.55 : 1,
      }}>
      <span style={{
        width: 34, height: 19, borderRadius: 999,
        background: on ? '#10b981' : 'var(--border)',
        position: 'relative', transition: 'background .18s', flexShrink: 0,
      }}>
        <span style={{
          position: 'absolute', top: 2, left: on ? 17 : 2, width: 15, height: 15,
          borderRadius: '50%', background: '#fff', transition: 'left .18s',
          boxShadow: '0 1px 3px rgba(0,0,0,.3)',
        }} />
      </span>
      <span style={{ fontSize: 11.5, fontWeight: 800, color: on ? '#10b981' : 'var(--text-muted)' }}>
        {on ? 'Active' : 'Inactive'}
      </span>
    </button>
  )
}
