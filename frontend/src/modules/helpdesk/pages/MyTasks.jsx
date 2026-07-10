import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { CheckSquare, LifeBuoy, ListTodo } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

const PRIORITY = {
  urgent: { bg: 'rgba(220,38,38,0.15)', color: '#ef4444' },
  high:   { bg: 'rgba(239,68,68,0.1)',  color: '#f87171' },
  medium: { bg: 'rgba(245,158,11,0.1)', color: '#fbbf24' },
  low:    { bg: 'rgba(16,185,129,0.1)', color: '#10b981' },
}
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short' }) : '—'

export default function MyTasks() {
  const navigate = useNavigate()
  const { data: items = [], isLoading } = useQuery({ queryKey: ['helpdesk-my-tasks'], queryFn: helpdeskApi.myTasks })

  const tickets = items.filter(i => i.source === 'ticket').length
  const tasks = items.filter(i => i.source === 'task').length

  return (
    <div className="text-slate-200">
      <header className="flex items-center gap-2 mb-5">
        <CheckSquare size={20} style={{ color: '#22d3ee' }} />
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>My Work</h1>
        {!isLoading && <span className="ml-2 text-xs" style={{ color: 'var(--text-muted)' }}>{tickets} tickets · {tasks} tasks</span>}
      </header>

      {isLoading && <div className="space-y-2">{[1, 2, 3].map(i => <div key={i} className="skeleton h-14 rounded-xl" style={{ background: 'var(--border)' }} />)}</div>}

      {!isLoading && items.length === 0 && <p className="text-sm text-slate-500 py-10 text-center">Nothing assigned to you right now. 🎉</p>}

      <div className="space-y-2">
        {items.map(item => {
          const p = PRIORITY[item.priority] || PRIORITY.low
          const isTicket = item.source === 'ticket'
          return (
            <button key={`${item.source}-${item.id}`} onClick={() => navigate(item.link)}
              className="w-full flex items-center gap-3 p-3 rounded-xl border text-left hover:bg-white/[0.03]"
              style={{ borderColor: 'var(--border)', background: 'var(--bg-card)' }}>
              <span className="flex items-center gap-1 text-[10px] font-bold px-2 py-1 rounded-lg shrink-0"
                style={{ background: isTicket ? 'rgba(34,211,238,0.12)' : 'rgba(236,72,153,0.12)', color: isTicket ? '#22d3ee' : '#f9a8d4' }}>
                {isTicket ? <LifeBuoy size={11} /> : <ListTodo size={11} />}{item.source}
              </span>
              <span className="flex-1 truncate" style={{ color: 'var(--text-h)' }}>{item.title}</span>
              <span className="text-[10px] px-2 py-0.5 rounded-lg font-bold capitalize" style={{ background: p.bg, color: p.color }}>{item.priority}</span>
              <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>due {fmtDate(item.due_date)}</span>
              <span className="text-[10px] px-2 py-0.5 rounded-lg capitalize" style={{ background: 'rgba(59,130,246,0.1)', color: '#3b82f6' }}>{String(item.status).replace('_', ' ')}</span>
            </button>
          )
        })}
      </div>
    </div>
  )
}
