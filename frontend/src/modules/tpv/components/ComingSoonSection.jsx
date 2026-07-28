import { Sparkles } from 'lucide-react'

/**
 * Placeholder for a Vendor Detail tab that has nothing to read.
 *
 * Two honest cases, never "planned for a future release":
 *  · the module genuinely does not exist in this system → "This module is not
 *    available." (default)
 *  · the module exists but this vendor has no rows → pass `reason`, e.g.
 *    "No Medical Records".
 *
 * Renders no data call of its own — the caller decides which case applies.
 */
export default function ComingSoonSection({ name, reason = 'This module is not available.' }) {
  return (
    <div className="pr-glass" style={{ padding: '52px 24px', textAlign: 'center', borderRadius: 16, maxWidth: 760 }}>
      <div style={{ width: 62, height: 62, borderRadius: '50%', margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.12)' }}>
        <Sparkles size={26} style={{ color: '#a78bfa' }} />
      </div>
      <h3 style={{ margin: 0, fontSize: 17, fontWeight: 800, color: 'var(--text-h)' }}>{name}</h3>
      <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '10px 0 0' }}>{reason}</p>
    </div>
  )
}
