// Emoji reactions for one message. Drop it inside a message row that is
// `group relative`: a quick-react bar fades in at the top-right on hover (like
// the reference chat), and any existing reactions show as chips below the text.
// Data + toggle come from the thread-level useReactions hook so the whole thread
// costs one request, not one per message.
import { useState, useRef, useLayoutEffect, useEffect } from 'react'
import { createPortal } from 'react-dom'
import { SmilePlus } from 'lucide-react'
import { REACTION_EMOJIS } from '@/services/reactionApi'

export default function MessageReactions({ summary = [], onToggle, accent = 'var(--color-primary-500)' }) {
  const [pickerOpen, setPickerOpen] = useState(false)
  const [pos, setPos] = useState(null)
  const btnRef = useRef(null)
  const menuRef = useRef(null)

  // Position the full-palette picker in a PORTAL so an `overflow-hidden` message
  // card can't clip it (that was the cut-off emoji picker bug).
  useLayoutEffect(() => {
    if (!pickerOpen || !btnRef.current) return
    const place = () => {
      const r = btnRef.current.getBoundingClientRect()
      const width = 160
      const left = Math.max(8, Math.min(r.right - width, window.innerWidth - width - 8))
      setPos({ left, top: r.bottom + 6 })
    }
    place()
    window.addEventListener('resize', place)
    window.addEventListener('scroll', place, true)
    return () => { window.removeEventListener('resize', place); window.removeEventListener('scroll', place, true) }
  }, [pickerOpen])

  useEffect(() => {
    if (!pickerOpen) return
    const onDown = (e) => {
      if (menuRef.current?.contains(e.target) || btnRef.current?.contains(e.target)) return
      setPickerOpen(false)
    }
    document.addEventListener('mousedown', onDown)
    return () => document.removeEventListener('mousedown', onDown)
  }, [pickerOpen])

  return (
    <>
      {/* Hover quick-react bar (top-right of the message) */}
      <div className="absolute -top-3 right-2 flex items-center gap-0.5 rounded-full px-1.5 py-1 opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity z-10"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        {REACTION_EMOJIS.slice(0, 6).map(e => (
          <button key={e} type="button" onClick={() => onToggle(e)} title={`React ${e}`}
            className="text-sm leading-none p-0.5 rounded-md hover:scale-125 transition-transform">{e}</button>
        ))}
        <button ref={btnRef} type="button" onClick={() => setPickerOpen(v => !v)} title="More reactions"
          className="p-0.5 rounded-md" style={{ color: 'var(--text-muted)' }}><SmilePlus size={14} /></button>
      </div>

      {/* Full palette — portalled so it never gets clipped */}
      {pickerOpen && pos && createPortal(
        <div ref={menuRef} className="fixed z-[80] grid grid-cols-4 gap-0.5 rounded-xl p-1.5"
          style={{ left: pos.left, top: pos.top, background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          {REACTION_EMOJIS.map(e => (
            <button key={e} type="button" onClick={() => { onToggle(e); setPickerOpen(false) }}
              className="text-base leading-none p-1 rounded-md hover:scale-110 transition-transform">{e}</button>
          ))}
        </div>,
        document.body,
      )}

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
