// One poll rendered with live results. Clicking an option casts (or, for
// single-choice, switches) the current user's vote; multi-choice toggles.
// Results bars always show so people see the tally as they vote.
import { Check, Lock, Trash2, Users } from 'lucide-react'

const fmtCloses = (iso) => {
  if (!iso) return null
  const d = new Date(iso)
  return d.toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}

export default function PollCard({ poll, accent = 'var(--color-primary-500)', onVote, onClose, onDelete, busy }) {
  const mine = new Set(poll.my_votes || [])
  const closed = poll.is_closed

  const toggle = (optId) => {
    if (closed || busy) return
    if (poll.allow_multiple) {
      const next = new Set(mine)
      next.has(optId) ? next.delete(optId) : next.add(optId)
      onVote(Array.from(next))
    } else {
      // Single-choice: clicking your current pick clears it, else switch to it.
      onVote(mine.has(optId) ? [] : [optId])
    }
  }

  return (
    <div className="rounded-xl p-3" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
      <div className="flex items-start gap-2 mb-2">
        <div className="flex-1 min-w-0">
          <p className="text-xs font-bold break-words" style={{ color: 'var(--text-h)' }}>{poll.question}</p>
          <p className="text-[10px] mt-0.5" style={{ color: 'var(--text-muted)' }}>
            {poll.created_by_name || 'Someone'}
            {poll.allow_multiple && ' · multiple choice'}
            {poll.closes_at && ` · ${closed ? 'closed' : 'closes'} ${fmtCloses(poll.closes_at)}`}
          </p>
        </div>
        {poll.can_manage && (
          <div className="flex items-center gap-1.5 shrink-0">
            {!closed && (
              <button onClick={onClose} disabled={busy} title="Close voting" aria-label="Close voting"
                className="hover:opacity-70"><Lock size={13} style={{ color: 'var(--text-muted)' }} /></button>
            )}
            <button onClick={onDelete} disabled={busy} title="Delete poll" aria-label="Delete poll"
              className="hover:opacity-70"><Trash2 size={13} style={{ color: 'var(--text-muted)' }} /></button>
          </div>
        )}
      </div>

      <div className="space-y-1.5">
        {poll.options.map(opt => {
          const picked = mine.has(opt.id)
          return (
            <button key={opt.id} onClick={() => toggle(opt.id)} disabled={closed || busy}
              className="relative w-full text-left rounded-lg overflow-hidden disabled:cursor-default"
              style={{ border: `1px solid ${picked ? accent : 'var(--border)'}`, background: 'var(--bg-card)' }}>
              {/* results bar */}
              <div className="absolute inset-y-0 left-0" style={{ width: `${opt.pct}%`, background: `color-mix(in srgb, ${accent} 16%, transparent)`, transition: 'width .3s' }} />
              <div className="relative flex items-center gap-2 px-2.5 py-1.5">
                <span className="flex items-center justify-center rounded-full shrink-0"
                  style={{ width: 15, height: 15, border: `1.5px solid ${picked ? accent : 'var(--border)'}`, background: picked ? accent : 'transparent' }}>
                  {picked && <Check size={10} style={{ color: '#fff' }} />}
                </span>
                <span className="flex-1 text-xs font-semibold truncate" style={{ color: 'var(--text-h)' }}>{opt.label}</span>
                <span className="text-[11px] font-bold shrink-0" style={{ color: 'var(--text-muted)' }}>{opt.pct}% · {opt.votes}</span>
              </div>
            </button>
          )
        })}
      </div>

      <p className="flex items-center gap-1 text-[10px] mt-2" style={{ color: 'var(--text-muted)' }}>
        <Users size={11} /> {poll.total_voters} {poll.total_voters === 1 ? 'voter' : 'voters'}
        {closed && ' · voting closed'}
      </p>
    </div>
  )
}
