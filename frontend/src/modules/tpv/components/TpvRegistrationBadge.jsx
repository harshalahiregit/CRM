/**
 * TPV registration-type badge — Long-Term TPV (blue) / Temporary TPV (orange).
 *
 * Reads the stored `registration_type` only; it never infers the type from
 * vendor_type, is_temporary or the access window. Records predating the column
 * come back from the API already defaulted to long_term_tpv.
 *
 * TPV-owned: Purchase has its own PurchaseRegistrationBadge. Neither imports
 * the other.
 */
const TPV_TYPES = {
  long_term_tpv: { label: 'Long-Term TPV', color: '#3b82f6', bg: 'rgba(59,130,246,0.15)', ring: 'rgba(59,130,246,0.35)' },
  temporary_tpv: { label: 'Temporary TPV', color: '#f97316', bg: 'rgba(249,115,22,0.15)', ring: 'rgba(249,115,22,0.35)' },
}

export const tpvRegistrationCfg = (type) => TPV_TYPES[type] || TPV_TYPES.long_term_tpv

export default function TpvRegistrationBadge({ type, label, size = 'sm' }) {
  const cfg = tpvRegistrationCfg(type)
  const small = size === 'sm'

  return (
    <span
      title={`Registration type: ${label || cfg.label}`}
      style={{
        display: 'inline-flex', alignItems: 'center', whiteSpace: 'nowrap',
        padding: small ? '3px 9px' : '4px 12px',
        borderRadius: 999, fontSize: small ? 10.5 : 12, fontWeight: 800,
        color: cfg.color, background: cfg.bg, border: `1px solid ${cfg.ring}`,
      }}
    >
      {label || cfg.label}
    </span>
  )
}
