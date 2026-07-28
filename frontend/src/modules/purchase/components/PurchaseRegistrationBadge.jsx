/**
 * Purchase Vendor registration-type badge — Standard Vendor (green) /
 * Temporary Vendor (amber).
 *
 * Reads the stored `registration_type` only; it never infers the type from
 * vendor_type. Records predating the column come back from the API already
 * defaulted to standard_vendor.
 *
 * Purchase-owned: TPV has its own TpvRegistrationBadge. Neither imports the
 * other.
 */
const PURCHASE_TYPES = {
  standard_vendor:  { label: 'Standard Vendor',  color: '#10b981', bg: 'rgba(16,185,129,0.15)', ring: 'rgba(16,185,129,0.35)' },
  temporary_vendor: { label: 'Temporary Vendor', color: '#f59e0b', bg: 'rgba(245,158,11,0.15)', ring: 'rgba(245,158,11,0.35)' },
}

export const purchaseRegistrationCfg = (type) => PURCHASE_TYPES[type] || PURCHASE_TYPES.standard_vendor

export default function PurchaseRegistrationBadge({ type, label, size = 'sm' }) {
  const cfg = purchaseRegistrationCfg(type)
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
