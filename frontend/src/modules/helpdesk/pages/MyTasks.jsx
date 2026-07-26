import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import {
  CheckSquare, LifeBuoy, ListTodo,
  Calendar, Inbox, ArrowRight
} from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

const PRIORITY = {
  urgent: { bg: 'rgba(220,38,38,0.12)', color: '#ef4444', border: 'rgba(220,38,38,0.25)' },
  high:   { bg: 'rgba(239,68,68,0.09)',  color: '#f87171', border: 'rgba(239,68,68,0.2)'  },
  medium: { bg: 'rgba(245,158,11,0.09)', color: '#fbbf24', border: 'rgba(245,158,11,0.2)' },
  low:    { bg: 'rgba(16,185,129,0.09)', color: '#10b981', border: 'rgba(16,185,129,0.2)' },
}

const fmtDate = d =>
  d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short' }) : '—'

export default function MyTasks() {
  const navigate = useNavigate()
  const { data: items = [], isLoading } = useQuery({
    queryKey: ['helpdesk-my-tasks'],
    queryFn: helpdeskApi.myTasks,
  })

  const tickets = items.filter(i => i.source === 'ticket').length
  const tasks   = items.filter(i => i.source === 'task').length

  return (
    <div className="space-y-5 animate-[tiltIn_0.35s_ease_forwards]">

      {/* Header */}
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <p className="label-caps mb-0.5">Helpdesk</p>
          <h1
            className="font-black"
            style={{ fontSize: 'clamp(1.3rem,2.2vw,1.7rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}
          >
            My <span className="text-gradient">Work</span>
          </h1>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
            Everything assigned to you
          </p>
        </div>

        {/* Summary pills */}
        {!isLoading && (
          <div className="flex items-center gap-2 mt-1">
            <div
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold"
              style={{ background: 'rgba(34,211,238,0.1)', color: '#22d3ee' }}
            >
              <LifeBuoy size={11} />
              {tickets} ticket{tickets !== 1 ? 's' : ''}
            </div>
            <div
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold"
              style={{ background: 'rgba(236,72,153,0.1)', color: '#f9a8d4' }}
            >
              <ListTodo size={11} />
              {tasks} task{tasks !== 1 ? 's' : ''}
            </div>
          </div>
        )}
      </div>

      {/* Loading */}
      {isLoading && (
        <div className="space-y-2">
          {[1, 2, 3, 4].map(i => (
            <div
              key={i}
              className="h-16 rounded-2xl animate-pulse"
              style={{ background: 'var(--border)' }}
            />
          ))}
        </div>
      )}

      {/* Empty state */}
      {!isLoading && items.length === 0 && (
        <div
          className="flex flex-col items-center justify-center py-16 gap-4 rounded-2xl"
          style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}
        >
          <div
            className="w-16 h-16 rounded-2xl flex items-center justify-center"
            style={{ background: 'rgba(34,211,238,0.08)' }}
          >
            <CheckSquare size={30} style={{ color: '#22d3ee', opacity: 0.7 }} />
          </div>
          <div className="text-center">
            <p className="font-bold" style={{ color: 'var(--text-h)' }}>All caught up! 🎉</p>
            <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>
              Nothing assigned to you right now
            </p>
          </div>
        </div>
      )}

      {/* Items list */}
      <div className="space-y-2">
        {items.map(item => {
          const p = PRIORITY[item.priority] || PRIORITY.low
          const isTicket = item.source === 'ticket'
          return (
            <button
              key={`${item.source}-${item.id}`}
              onClick={() => navigate(item.link)}
              className="w-full flex items-center gap-3 px-4 py-3.5 rounded-2xl text-left transition-all duration-200 hover:-translate-y-0.5 group"
              style={{
                background: 'var(--bg-card)',
                border: '1px solid var(--border)',
                boxShadow: 'var(--shadow-card)',
              }}
            >
              {/* Source badge */}
              <div
                className="flex items-center gap-1 shrink-0 text-[10px] font-black px-2 py-1 rounded-lg"
                style={isTicket
                  ? { background: 'rgba(34,211,238,0.1)', color: '#22d3ee' }
                  : { background: 'rgba(236,72,153,0.1)', color: '#f9a8d4' }
                }
              >
                {isTicket ? <LifeBuoy size={10} /> : <ListTodo size={10} />}
                {item.source}
              </div>

              {/* Title */}
              <span className="flex-1 text-sm font-semibold truncate" style={{ color: 'var(--text-h)' }}>
                {item.title}
              </span>

              {/* Priority chip */}
              <span
                className="shrink-0 text-[10px] font-black px-2 py-0.5 rounded-full capitalize"
                style={{ background: p.bg, color: p.color, border: `1px solid ${p.border}` }}
              >
                {item.priority}
              </span>

              {/* Due date */}
              <span
                className="shrink-0 flex items-center gap-1 text-xs"
                style={{ color: 'var(--text-muted)' }}
              >
                <Calendar size={11} />
                {fmtDate(item.due_date)}
              </span>

              {/* Status */}
              <span
                className="shrink-0 text-[10px] px-2 py-0.5 rounded-full capitalize font-semibold"
                style={{ background: 'rgba(59,130,246,0.1)', color: '#3b82f6' }}
              >
                {String(item.status).replace('_', ' ')}
              </span>

              <ArrowRight
                size={13}
                className="shrink-0 opacity-0 group-hover:opacity-100 transition-opacity"
                style={{ color: 'var(--text-muted)' }}
              />
            </button>
          )
        })}
      </div>
    </div>
  )
}
