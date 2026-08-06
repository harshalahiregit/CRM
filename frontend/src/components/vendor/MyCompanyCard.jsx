import { Building2 } from 'lucide-react'

/**
 * "This is YOUR company" banner for the vendor portal.
 *
 * The portal's biggest confusion was that its pages looked like the admin views they
 * are built from, so a vendor could not tell whether a list held their own workers
 * or everyone's. Naming the company once, at the top, settles that — and lets the
 * rows below drop the company name they were repeating on every line.
 *
 * Presentational and module-agnostic: TPV and Purchase each pass their own vendor.
 */
export default function MyCompanyCard({ vendor, stats = [], accent = '#7C3AED' }) {
  if (!vendor) return null

  const code = vendor.vendor_code || vendor.purchase_vendor_code || '—'
  const regType = vendor.registration_type_label
    || (vendor.registration_type ? vendor.registration_type.replace(/_/g, ' ') : null)
    || (vendor.vendor_type === 'temporary' ? 'Temporary' : 'Permanent')

  return (
    <div style={{
      background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 14,
      padding: '16px 18px', marginBottom: 16,
      display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap',
    }}>
      <div style={{
        width: 44, height: 44, borderRadius: 12, flexShrink: 0,
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        background: `color-mix(in srgb, ${accent} 12%, transparent)`,
      }}>
        <Building2 size={20} style={{ color: accent }} />
      </div>

      <div style={{ minWidth: 180 }}>
        <p style={{ margin: 0, fontSize: 9.5, fontWeight: 700, letterSpacing: '0.06em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>
          My Company
        </p>
        <p style={{ margin: '2px 0 0', fontSize: 16, fontWeight: 800, color: 'var(--text-h)', letterSpacing: '-0.01em' }}>
          {vendor.company_name || '—'}
        </p>
        <p style={{ margin: '2px 0 0', fontSize: 11.5, color: 'var(--text-muted)' }}>
          {code} · <span style={{ textTransform: 'capitalize' }}>{regType}</span>
        </p>
      </div>

      {stats.length > 0 && (
        <div style={{ display: 'flex', gap: 22, marginLeft: 'auto', flexWrap: 'wrap' }}>
          {stats.map(s => (
            <div key={s.label}>
              <p style={{ margin: 0, fontSize: 9.5, fontWeight: 700, letterSpacing: '0.05em', textTransform: 'uppercase', color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>
                {s.label}
              </p>
              <p style={{
                margin: '2px 0 0', fontSize: 20, fontWeight: 800, lineHeight: 1,
                fontVariantNumeric: 'tabular-nums',
                color: s.value ? 'var(--text-h)' : 'var(--text-muted)',
              }}>{s.value ?? 0}</p>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
