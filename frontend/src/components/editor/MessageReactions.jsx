// Emoji reactions for one message. Drop it inside a message row that is
// `group relative`: a quick-react bar fades in at the top-right on hover (like
// the reference chat), and any existing reactions show as chips below the text.
// Data + toggle come from the thread-level useReactions hook so the whole thread
// costs one request, not one per message.
import { useState } from 'react'
import { SmilePlus } from 'lucide-react'
import { REACTION_EMOJIS } from '@/services/reactionApi'

export default function MessageReactions({ summary = [], onToggle, accent = 'var(--color-primary-500)' }) {
  const [pickerOpen, setPickerOpen] = useState(false)

  return (
    <>
      {/* Hover quick-react bar (top-right of the message) */}
      <div className="absolute -top-3 right-2 flex items-center gap-0.5 rounded-full px-1.5 py-1 opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity z-10"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        {REACTION_EMOJIS.slice(0, 6).map(e => (
          <button key={e} type="button" onClick={() => onToggle(e)} title={`React ${e}`}
            className="text-sm leading-none p-0.5 rounded-md hover:scale-125 transition-transform">{e}</button>
        ))}
        <div className="relative">
          <button type="button" onClick={() => setPickerOpen(v => !v)} title="More reactions"
            className="p-0.5 rounded-md" style={{ color: 'var(--text-muted)' }}><SmilePlus size={14} /></button>
          {pickerOpen && (
            <div className="absolute right-0 top-full mt-1 grid grid-cols-4 gap-0.5 rounded-xl p-1.5 z-20"
              style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}
              onMouseLeave={() => setPickerOpen(false)}>
              {REACTION_EMOJIS.map(e => (
                <button key={e} type="button" onClick={() => { onToggle(e); setPickerOpen(false) }}
                  className="text-base leading-none p-1 rounded-md hover:scale-110 transition-transform">{e}</button>
              ))}
            </div>
          )}
        </div>
      </div>

      {/* Existing reactions as chips */}
      {summary.length > 0 && (
        <div className="flex flex-wrap items-center gap-1 mt-1.5">
          {summary.map(r => (
            <button key={r.emoji} type="button" onClick={() => onToggle(r.emoji)}
              className="flex items-center gap-1 text-[11px] font-bold px-1.5 py-0.5 rounded-full transition-colors"
              style={{
                background: r.mine ? `color-mix(in srgb, ${accent} 16%, transparent)` : 'var(--bg-input)',
                border: `1px solid ${r.mine ? accent : 'var(--border)'}`,
                color: 'var(--text-body)',
              }}
              title={r.mine ? 'You reacted — click to remove' : 'React'}>
              <span>{r.emoji}</span><span>{r.count}</span>
            </button>
          ))}
        </div>
      )}
    </>
  )
}
