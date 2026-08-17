import { Sparkles, Ban } from 'lucide-react'

/**
 * Placeholder for a Vendor Detail tab that has nothing to read.
 *
 * Three honest cases, never "planned for a future release":
 *  · the concept has no backing table yet → "This module is not available."
 *    (default) — a legitimate future feature, awaiting a business definition.
 *  · the module exists but this vendor has no rows → pass `reason`, e.g.
 *    "No Medical Records".
 *  · the concept belongs to a DIFFERENT module and never applies to a TPV
 *    vendor → pass notApplicable, e.g. Commercial documents, which key to
 *    purchase_vendors. This is a settled answer, not a gap, and it is styled
 *    differently so nobody reads it as unfinished work.
 *
 * Renders no data call of its own — the caller decides which case applies.
 */
export default function ComingSoonSection({ name, reason, notApplicable = false }) {
  const text = reason || (notApplicable
    ? 'This section does not apply to third-party vendors.'
    : 'This module is not available.')

  const Icon  = notApplicable ? Ban : Sparkles
  const tint  = notApplicable ? '#64748b' : '#a78bfa'
  const label = notApplicable ? 'Not applicable' : 'Coming soon'

  return (
    <div className="pr-glass" style={{ padding: '52px 24px', textAlign: 'center', borderRadius: 16, maxWidth: 760 }}>
      <div style={{ width: 62, height: 62, borderRadius: '50%', margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: `${tint}1f` }}>
        <Icon size={26} style={{ color: tint }} />
      </div>
      <h3 style={{ margin: 0, fontSize: 17, fontWeight: 800, color: 'var(--text-h)' }}>{name}</h3>
      <span style={{
        display: 'inline-block', margin: '8px 0 0', padding: '2px 10px', borderRadius: 20,
        fontSize: 11, fontWeight: 700, color: tint, background: `${tint}1a`,
      }}>{label}</span>
      <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '10px 0 0' }}>{text}</p>
    </div>
  )
}
