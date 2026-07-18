import { Loader2 } from 'lucide-react'

/**
 * Shared HR loading + empty states (UX-only). Gives every recruitment screen a
 * consistent spinner and icon-based empty view. Presentational only — no data,
 * no side effects.
 */
export function HrLoading({ label = 'Loading…' }) {
  return (
    <div className="flex flex-col items-center justify-center gap-3 py-16" style={{ color: 'var(--text-muted)' }}>
      <Loader2 size={22} className="animate-spin" style={{ color: '#a78bfa' }} />
      <p className="text-sm font-medium">{label}</p>
    </div>
  )
}

export function HrEmpty({ icon: Icon, title = 'Nothing here yet', hint, action }) {
  return (
    <div className="card-3d flex flex-col items-center justify-center text-center gap-2" style={{ padding: '44px 24px' }}>
      {Icon && (
        <div className="w-14 h-14 rounded-2xl flex items-center justify-center mb-1"
          style={{ background: 'rgba(124,58,237,0.1)', border: '1px solid rgba(124,58,237,0.2)' }}>
          <Icon size={24} style={{ color: '#a78bfa' }} />
        </div>
      )}
      <p className="text-sm font-black" style={{ color: 'var(--text-h)' }}>{title}</p>
      {hint && <p className="text-xs" style={{ color: 'var(--text-muted)', maxWidth: 340 }}>{hint}</p>}
      {action && <div className="mt-1.5">{action}</div>}
    </div>
  )
}
