import { Sparkles } from 'lucide-react'

/**
 * Reusable placeholder for Vendor Detail tabs whose backend isn't built yet.
 * Every unfinished section renders this — no duplicated markup, no data calls.
 */
export default function ComingSoonSection({ name }) {
  return (
    <div className="pr-glass" style={{ padding: '52px 24px', textAlign: 'center', borderRadius: 16, maxWidth: 760 }}>
      <div style={{ width: 62, height: 62, borderRadius: '50%', margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.12)' }}>
        <Sparkles size={26} style={{ color: '#a78bfa' }} />
      </div>
      <h3 style={{ margin: 0, fontSize: 17, fontWeight: 800, color: 'var(--text-h)' }}>{name}</h3>
      <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '10px 0 2px' }}>This section is planned for a future release.</p>
      <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>No data is available yet.</p>
    </div>
  )
}
