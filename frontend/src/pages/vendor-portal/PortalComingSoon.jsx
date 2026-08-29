import { useParams } from 'react-router-dom'
import { Hammer } from 'lucide-react'
import { SECTION_INDEX } from './portalSections'

/**
 * Placeholder for a nav section that is on the roadmap but not built yet. The
 * whole nav tree is always visible (so vendors and admins can see what is coming
 * and where it will live); an unbuilt destination lands here instead of 404.
 */
export default function PortalComingSoon() {
  const { key } = useParams()
  const meta = SECTION_INDEX[key] || { label: 'This section', group: '' }

  return (
    <div style={{ maxWidth: 560, margin: '48px auto', padding: '0 20px', textAlign: 'center' }}>
      <div style={{
        width: 64, height: 64, borderRadius: 16, margin: '0 auto 20px',
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        background: 'var(--surface-2, rgba(255,255,255,0.05))', border: '1px solid var(--border, rgba(255,255,255,0.1))',
      }}>
        <Hammer size={26} style={{ opacity: 0.7 }} />
      </div>
      <h2 style={{ fontSize: 20, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 6px' }}>
        {meta.label}
      </h2>
      {meta.group && (
        <div style={{ fontSize: 12, textTransform: 'uppercase', letterSpacing: '0.06em', color: 'var(--text-muted)', marginBottom: 14 }}>
          {meta.group}
        </div>
      )}
      <p style={{ color: 'var(--text-muted)', fontSize: 14, lineHeight: 1.6 }}>
        This section is part of the vendor portal roadmap and is being built. It
        will appear here — and in the admin Vendor section — as soon as it ships.
      </p>
    </div>
  )
}
