import {
  CheckCircle, XCircle, Send, CornerUpLeft, FilePlus, Briefcase,
  Rocket, PlayCircle, Lock, Circle, Mail, MessageCircle, Bell, CalendarClock, FileText,
} from 'lucide-react'

// ── Action → visual mapping (keyword based, reusable across entities) ─────────
function styleFor(action = '') {
  const a = action.toLowerCase()
  // Communication events (SPK-1) — surfaced when comm history merges into the timeline.
  if (a.includes('email'))             return { color: '#a78bfa', icon: Mail }
  if (a.includes('whatsapp'))          return { color: '#25D366', icon: MessageCircle }
  if (a.includes('reminder'))          return { color: '#f59e0b', icon: Bell }
  if (a.includes('interview'))         return { color: '#f59e0b', icon: CalendarClock }
  if (a.includes('offer'))             return { color: '#10b981', icon: FileText }
  if (a.includes('onboard'))           return { color: '#7C3AED', icon: Rocket }
  if (a.includes('reject'))            return { color: '#ef4444', icon: XCircle }
  if (a.includes('sent back') || a.includes('send back')) return { color: '#f59e0b', icon: CornerUpLeft }
  if (a.includes('approv'))            return { color: '#10b981', icon: CheckCircle }
  if (a.includes('submit'))            return { color: '#8b5cf6', icon: Send }
  if (a.includes('creat'))             return { color: '#6b7280', icon: FilePlus }
  if (a.includes('convert') || a.includes('jd')) return { color: '#6366f1', icon: Briefcase }
  if (a.includes('post') || a.includes('publish'))  return { color: '#10b981', icon: Rocket }
  if (a.includes('hiring'))            return { color: '#14b8a6', icon: PlayCircle }
  if (a.includes('clos'))              return { color: '#64748b', icon: Lock }
  return { color: '#8b85a8', icon: Circle }
}

const prettyRole = (r) => !r ? '' : r.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())

function fmt(ts) {
  if (!ts) return ''
  const d = new Date(ts)
  return d.toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

/**
 * Reusable audit/approval timeline.
 *
 * @param {Array} entries  audit_logs from the API: { action, actor_name,
 *   actor_role, comment, created_at, metadata:{ level } }
 * @param {boolean} newestFirst  order (default false = chronological, oldest→newest)
 */
export default function AuditTimeline({ entries = [], newestFirst = false }) {
  if (!entries?.length) {
    return <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>No activity recorded yet.</p>
  }
  const rows = newestFirst ? entries : [...entries].reverse()

  return (
    <div style={{ position: 'relative', paddingLeft: 8 }}>
      {rows.map((e, i) => {
        const { color, icon: Icon } = styleFor(e.action)
        const level = e.metadata?.level
        const isLast = i === rows.length - 1
        return (
          <div key={e.id ?? i} style={{ position: 'relative', paddingLeft: 30, paddingBottom: isLast ? 0 : 18 }}>
            {/* connector line */}
            {!isLast && <span style={{ position: 'absolute', left: 12, top: 24, bottom: -2, width: 2, background: 'var(--border)' }} />}
            {/* node */}
            <span style={{ position: 'absolute', left: 0, top: 2, width: 25, height: 25, borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', background: `${color}1f`, border: `1.5px solid ${color}`, color }}>
              <Icon size={13} />
            </span>
            {/* content */}
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
              <span style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{e.action}</span>
              {level && <span style={{ fontSize: 10, fontWeight: 700, padding: '1px 6px', borderRadius: 6, background: `${color}1a`, color, border: `1px solid ${color}40` }}>{level}</span>}
              <span style={{ fontSize: 11, color: 'var(--text-muted)', marginLeft: 'auto' }}>{fmt(e.created_at)}</span>
            </div>
            <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>
              <strong style={{ color: 'var(--text-body, var(--text-h))' }}>{e.actor_name || 'System'}</strong>
              {e.actor_role && <span> · {prettyRole(e.actor_role)}</span>}
            </div>
            {e.comment && (
              <div style={{ fontSize: 12, color: 'var(--text-h)', marginTop: 6, padding: '7px 11px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, lineHeight: 1.45 }}>
                “{e.comment}”
              </div>
            )}
          </div>
        )
      })}
    </div>
  )
}
