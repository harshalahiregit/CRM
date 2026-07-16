import {
  CheckCircle, Send, Eye, FileText, CreditCard, Clock,
  StickyNote, Bell, ListChecks, FileSignature, RefreshCw,
  ArrowRightLeft, UserPlus, XCircle, Trash2, Pencil,
} from 'lucide-react'

const ICONS = {
  created: FileText, sent: Send, viewed: Eye, accepted: CheckCircle,
  declined: Clock, paid: CreditCard, payment: CreditCard,
  // Extended cross-entity event types (leads, tasks, contracts, follow-ups):
  updated: Pencil, status_changed: ArrowRightLeft, assigned: UserPlus,
  note_added: StickyNote, note: StickyNote, converted: RefreshCw,
  lost: XCircle, junk: Trash2, restored: RefreshCw,
  contact: Bell, follow_up: Bell, reminder: Bell,
  task: ListChecks, contract: FileSignature, renewed: RefreshCw,
  proposal_sent: Send, questionnaire_submitted: FileText,
}

const fmtDate = d => d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : ''

export default function ActivityTimeline({ events = [] }) {
  if (!events.length) return (
    <p className="text-xs text-center py-6" style={{ color: 'var(--text-muted)' }}>No activity yet.</p>
  )

  return (
    <div className="space-y-3">
      {events.map((ev, i) => {
        const Icon = ICONS[ev.type] || FileText
        const isLast = i === events.length - 1
        const colors = {
          created:  { bg: 'rgba(124,58,237,0.15)', color: '#a78bfa' },
          sent:     { bg: 'rgba(59,130,246,0.15)', color: '#3b82f6' },
          viewed:   { bg: 'rgba(100,116,139,0.15)', color: '#94a3b8' },
          accepted: { bg: 'rgba(16,185,129,0.15)', color: '#10b981' },
          declined: { bg: 'rgba(239,68,68,0.15)',  color: '#f87171' },
          paid:     { bg: 'rgba(16,185,129,0.15)', color: '#10b981' },
          payment:  { bg: 'rgba(16,185,129,0.15)', color: '#10b981' },
          updated:        { bg: 'rgba(100,116,139,0.15)', color: '#94a3b8' },
          status_changed: { bg: 'rgba(59,130,246,0.15)',  color: '#3b82f6' },
          assigned:       { bg: 'rgba(124,58,237,0.15)',  color: '#a78bfa' },
          note_added:     { bg: 'rgba(245,158,11,0.15)',  color: '#f59e0b' },
          note:           { bg: 'rgba(245,158,11,0.15)',  color: '#f59e0b' },
          converted:      { bg: 'rgba(16,185,129,0.15)',  color: '#10b981' },
          lost:           { bg: 'rgba(239,68,68,0.15)',   color: '#f87171' },
          junk:           { bg: 'rgba(100,116,139,0.15)', color: '#94a3b8' },
          restored:       { bg: 'rgba(16,185,129,0.15)',  color: '#10b981' },
          contact:        { bg: 'rgba(245,158,11,0.15)',  color: '#f59e0b' },
          follow_up:      { bg: 'rgba(245,158,11,0.15)',  color: '#f59e0b' },
          reminder:       { bg: 'rgba(245,158,11,0.15)',  color: '#f59e0b' },
          task:           { bg: 'rgba(124,58,237,0.15)',  color: '#a78bfa' },
          contract:       { bg: 'rgba(59,130,246,0.15)',  color: '#3b82f6' },
          renewed:        { bg: 'rgba(16,185,129,0.15)',  color: '#10b981' },
        }
        const c = colors[ev.type] || colors.created
        return (
          <div key={i} className="flex gap-3 items-start">
            <div className="flex flex-col items-center flex-shrink-0">
              <div className="w-8 h-8 rounded-xl flex items-center justify-center" style={{ background: c.bg }}>
                <Icon size={14} style={{ color: c.color }} />
              </div>
              {!isLast && <div className="w-px flex-1 mt-1 min-h-[16px]" style={{ background: 'var(--border)' }} />}
            </div>
            <div className="pt-1 pb-3">
              <p className="text-xs font-semibold" style={{ color: 'var(--text-h)' }}>{ev.label}</p>
              {ev.detail && <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{ev.detail}</p>}
              <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>{fmtDate(ev.date)}</p>
            </div>
          </div>
        )
      })}
    </div>
  )
}
