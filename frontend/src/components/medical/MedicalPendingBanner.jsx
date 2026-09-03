import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { AlertTriangle, ArrowRight } from 'lucide-react'
import { medicalApi } from '@/services/medicalApi'

/**
 * "Medical Report is Pending", on the vendor's dashboard.
 *
 * The prerequisite is enforced server-side whatever the screen says — but a
 * vendor who finds out their crew is blocked only when a trainer refuses them at
 * an induction has been failed by the software. So the dashboard says it first,
 * names how many workers, and links to the one place that can fix it.
 *
 * Renders nothing when there is nothing to say: a permanent banner is furniture,
 * and furniture is ignored.
 */
export default function MedicalPendingBanner({ base = '/portal', to = '/vendor-portal/medical' }) {
  const [summary, setSummary] = useState(null)

  useEffect(() => {
    let alive = true
    medicalApi.portal.list(base)
      .then(d => { if (alive) setSummary(d?.summary ?? null) })
      .catch(() => {})   // a dashboard must not break over one panel
    return () => { alive = false }
  }, [base])

  const blocked = summary?.blocked_workers ?? 0
  const held    = summary?.on_hold ?? 0
  if (!blocked && !held) return null

  return (
    <Link
      to={to}
      style={{
        display: 'flex', alignItems: 'center', gap: 12, textDecoration: 'none',
        padding: '12px 16px', borderRadius: 12, marginBottom: 16,
        background: '#f59e0b18', border: '1px solid #f59e0b44',
      }}
    >
      <AlertTriangle size={18} color="#f59e0b" style={{ flexShrink: 0 }} />
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontSize: 13, fontWeight: 800, color: '#f59e0b' }}>Medical Report is Pending</div>
        <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>
          {blocked > 0 && `${blocked} worker${blocked > 1 ? 's' : ''} cannot start safety induction until their medical clears.`}
          {blocked > 0 && held > 0 && ' '}
          {held > 0 && `${held} certificate${held > 1 ? 's are' : ' is'} waiting on your reply.`}
        </div>
      </div>
      <ArrowRight size={16} color="#f59e0b" />
    </Link>
  )
}
