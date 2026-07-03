const STATUS_MAP = {
  Open:             { bg: 'rgba(59,130,246,0.1)',  color: '#3b82f6' },
  Sent:             { bg: 'rgba(124,58,237,0.1)',   color: '#a78bfa' },
  Accepted:         { bg: 'rgba(16,185,129,0.1)',   color: '#10b981' },
  Declined:         { bg: 'rgba(239,68,68,0.1)',    color: '#f87171' },
  Expired:          { bg: 'rgba(245,158,11,0.1)',   color: '#fbbf24' },
  Draft:            { bg: 'rgba(100,116,139,0.1)',  color: '#94a3b8' },
  Unpaid:           { bg: 'rgba(124,58,237,0.1)',   color: '#a78bfa' },
  Paid:             { bg: 'rgba(16,185,129,0.1)',   color: '#10b981' },
  'Partially Paid': { bg: 'rgba(245,158,11,0.1)',   color: '#fbbf24' },
  Overdue:          { bg: 'rgba(239,68,68,0.1)',    color: '#f87171' },
  Cancelled:        { bg: 'rgba(100,116,139,0.08)', color: '#64748b' },
  Delivered:        { bg: 'rgba(16,185,129,0.1)',   color: '#10b981' },
  Void:             { bg: 'rgba(100,116,139,0.1)',  color: '#94a3b8' },
}

export default function StatusBadge({ status, size = 'sm' }) {
  const s = STATUS_MAP[status] || { bg: 'rgba(124,58,237,0.1)', color: '#a78bfa' }
  const p = size === 'lg' ? 'px-3 py-1 text-xs' : 'px-2.5 py-0.5 text-[10px]'
  return (
    <span className={`${p} rounded-xl font-bold inline-block`} style={{ background: s.bg, color: s.color }}>
      {status}
    </span>
  )
}
