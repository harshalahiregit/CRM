import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { LifeBuoy } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

// Color-coded status badges (values match the API enum: open/in-progress/closed).
const STATUS_MAP = {
  open:          { bg: 'rgba(59,130,246,0.1)',  color: '#3b82f6', label: 'Open' },
  'in-progress': { bg: 'rgba(245,158,11,0.1)',  color: '#fbbf24', label: 'In Progress' },
  closed:        { bg: 'rgba(16,185,129,0.1)',  color: '#10b981', label: 'Closed' },
}

const PRIORITY_MAP = {
  high:   { bg: 'rgba(239,68,68,0.1)',  color: '#f87171' },
  medium: { bg: 'rgba(245,158,11,0.1)', color: '#fbbf24' },
  low:    { bg: 'rgba(16,185,129,0.1)', color: '#10b981' },
}

const Badge = ({ map, value, fallback }) => {
  const s = map[value] || fallback
  return (
    <span className="px-2.5 py-0.5 rounded-xl text-[10px] font-bold inline-block capitalize"
      style={{ background: s.bg, color: s.color }}>
      {s.label || value}
    </span>
  )
}

export default function TicketGrid() {
  const navigate = useNavigate()

  const { data: tickets = [], isLoading, isError, error } = useQuery({
    queryKey: ['helpdesk-tickets'],
    queryFn: () => helpdeskApi.tickets.list(),
  })

  return (
    <div className="text-slate-200">
      <header className="flex items-center gap-2 mb-5">
        <LifeBuoy size={20} style={{ color: '#22d3ee' }} />
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Support Tickets</h1>
        {!isLoading && !isError && <span className="ml-2 text-xs" style={{ color: 'var(--text-muted)' }}>{tickets.length} tickets</span>}
      </header>

      {isError && (
        <div className="p-6 rounded-2xl border" style={{ borderColor: 'rgba(239,68,68,0.3)', background: 'rgba(239,68,68,0.06)' }}>
          <p className="font-semibold text-red-400">Couldn’t load tickets</p>
          <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>{error?.message}</p>
        </div>
      )}

      {!isError && (
        <div className="overflow-x-auto rounded-2xl border" style={{ borderColor: 'var(--border)', background: 'var(--bg-card)' }}>
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-[11px] uppercase tracking-wide border-b" style={{ color: 'var(--text-muted)', borderColor: 'var(--border)' }}>
                <th className="px-4 py-3 font-semibold">ID</th>
                <th className="px-4 py-3 font-semibold">Subject</th>
                <th className="px-4 py-3 font-semibold">Status</th>
                <th className="px-4 py-3 font-semibold">Priority</th>
                <th className="px-4 py-3 font-semibold">Assignee</th>
              </tr>
            </thead>
            <tbody>
              {isLoading && [1, 2, 3, 4, 5].map(i => (
                <tr key={i} className="border-b" style={{ borderColor: 'var(--border)' }}>
                  {[...Array(5)].map((_, j) => (
                    <td key={j} className="px-4 py-3"><div className="skeleton h-4 rounded" style={{ background: 'var(--border)' }} /></td>
                  ))}
                </tr>
              ))}

              {!isLoading && tickets.length === 0 && (
                <tr><td colSpan={5} className="px-4 py-10 text-center" style={{ color: 'var(--text-muted)' }}>No tickets yet.</td></tr>
              )}

              {/* THE LOOP: one row per ticket from the API */}
              {!isLoading && tickets.map(ticket => (
                <tr
                  key={ticket.id}
                  onClick={() => navigate(`/app/helpdesk/tickets/${ticket.id}`)}
                  className="border-b last:border-0 hover:bg-white/[0.03] transition-colors cursor-pointer"
                  style={{ borderColor: 'var(--border)' }}
                >
                  <td className="px-4 py-3 font-mono text-xs" style={{ color: '#22d3ee' }}>#{ticket.id}</td>
                  <td className="px-4 py-3" style={{ color: 'var(--text-h)' }}>{ticket.subject}</td>
                  <td className="px-4 py-3"><Badge map={STATUS_MAP} value={ticket.status} fallback={{ bg: 'rgba(100,116,139,0.1)', color: '#94a3b8' }} /></td>
                  <td className="px-4 py-3"><Badge map={PRIORITY_MAP} value={ticket.priority} fallback={{ bg: 'rgba(100,116,139,0.1)', color: '#94a3b8' }} /></td>
                  <td className="px-4 py-3" style={{ color: 'var(--text-muted)' }}>{ticket.assignee?.name || 'Unassigned'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
