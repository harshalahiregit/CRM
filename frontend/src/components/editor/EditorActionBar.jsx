// Shared "composer actions" row for every rich-text editor across Tasks,
// Helpdesk and Projects (owner: Shivam). It gives each editor the same quick
// buttons the reference chat composer shows — emoji, @-mention and attach —
// by inserting into the SAME ReactQuill instance the editor already renders.
//
// Everything it inserts is plain text (an emoji character, an "@Name" token),
// so it survives the server-side HtmlSanitizer untouched. Buttons appear only
// when the surface can back them: emoji is always available; @-mention shows
// when a `people` list is supplied; attach shows when an `onAttach` handler is.
import { useState, useRef, useEffect, useMemo } from 'react'
import { Smile, AtSign, Paperclip, BarChart3, Plus, Video } from 'lucide-react'
import { meetingLinkApi } from '@/services/meetingLinkApi'

// A small, clean, work-appropriate set — enough to react without a heavy
// emoji-library dependency (there is no Node on the live host; we keep the
// bundle lean and ship plain Unicode that renders everywhere).
const EMOJIS = [
  '👍', '👎', '👏', '🙌', '🙏', '💪', '🤝', '👀', '✅', '❌',
  '🙂', '😄', '😁', '😉', '😊', '😍', '😎', '🤔', '😅', '😂',
  '🤣', '😇', '🥳', '😴', '😢', '😭', '😡', '😱', '🤯', '😬',
  '🔥', '⭐', '🎉', '🚀', '💡', '📌', '📎', '📅', '⏰', '📝',
  '⚠️', '❤️', '💯', '👌', '🤙', '🫡', '🆗', '🙈',
]

// Insert `text` at the editor's caret (or end if it never had focus), keeping
// the caret after what we inserted so the user can keep typing.
function insertIntoQuill(quillRef, text) {
  const q = quillRef?.current?.getEditor?.()
  if (!q) return
  const range = q.getSelection(true) || { index: q.getLength(), length: 0 }
  q.insertText(range.index, text, 'user')
  q.setSelection(range.index + text.length, 0, 'user')
  q.focus()
}

// A lightweight popover that closes on outside-click / Escape.
function Popover({ open, onClose, children, align = 'left' }) {
  const ref = useRef(null)
  useEffect(() => {
    if (!open) return
    const onDown = (e) => { if (ref.current && !ref.current.contains(e.target)) onClose() }
    const onKey = (e) => { if (e.key === 'Escape') onClose() }
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    return () => { document.removeEventListener('mousedown', onDown); document.removeEventListener('keydown', onKey) }
  }, [open, onClose])
  if (!open) return null
  return (
    <div ref={ref}
      className="absolute z-50 bottom-full mb-2 rounded-xl p-2 shadow-lg"
      style={{
        [align]: 0,
        background: 'var(--bg-card)', border: '1px solid var(--border)',
        boxShadow: 'var(--shadow-card)', minWidth: 220,
      }}>
      {children}
    </div>
  )
}

const btnCls = 'flex items-center gap-1 text-[11px] font-bold px-2 py-1 rounded-lg transition-colors'

// Insert into a controlled <textarea> at its caret, updating React state via
// `onChange`. Used for the lighter comment boxes that aren't ReactQuill.
function insertIntoTextarea(textareaRef, value, onChange, text) {
  const el = textareaRef?.current
  const start = el ? el.selectionStart : (value?.length ?? 0)
  const end = el ? el.selectionEnd : (value?.length ?? 0)
  const next = (value ?? '').slice(0, start) + text + (value ?? '').slice(end)
  onChange(next)
  // Restore the caret just after what we inserted, once React has re-rendered.
  if (el) requestAnimationFrame(() => { el.focus(); const pos = start + text.length; el.setSelectionRange(pos, pos) })
}

// The composer's "Meeting" menu — mirrors the reference (Zoom / Google Meet /
// Jitsi). Keys must match MeetingLinkService::PLATFORMS on the backend.
const MEET_PLATFORMS = [
  { key: 'google_meet', label: 'Google Meet' },
  { key: 'zoom', label: 'Zoom' },
  { key: 'jitsi', label: 'Jitsi' },
]

export default function EditorActionBar({
  quillRef, textareaRef, value, onChange,
  people = null, onAttach = null, onPoll = null, quickCreate = null, meeting = false,
  accent = 'var(--color-primary-500)', className = '',
}) {
  const [emojiOpen, setEmojiOpen] = useState(false)
  const [mentionOpen, setMentionOpen] = useState(false)
  const [quickOpen, setQuickOpen] = useState(false)
  const [meetingOpen, setMeetingOpen] = useState(false)
  const [meetingBusy, setMeetingBusy] = useState(null)
  const [q, setQ] = useState('')

  const filtered = useMemo(() => {
    const list = people || []
    const s = q.trim().toLowerCase()
    if (!s) return list.slice(0, 30)
    return list.filter(p => (p.name || p.label || '').toLowerCase().includes(s)).slice(0, 30)
  }, [people, q])

  // Route insertion to whichever editor this bar drives.
  const insert = (text) => {
    if (textareaRef) insertIntoTextarea(textareaRef, value, onChange, text)
    else insertIntoQuill(quillRef, text)
  }
  const pickEmoji = (e) => { insert(e); setEmojiOpen(false) }
  const pickPerson = (p) => {
    const name = (p.name || p.label || '').replace(/\s+/g, ' ').trim()
    if (name) insert(`@${name} `)
    setMentionOpen(false); setQ('')
  }

  // Insert a URL as a real clickable link (Quill) or plain URL text (textarea).
  const insertLink = (url) => {
    const q2 = quillRef?.current?.getEditor?.()
    if (q2 && !textareaRef) {
      const range = q2.getSelection(true) || { index: q2.getLength(), length: 0 }
      q2.insertText(range.index, url, { link: url }, 'user')
      q2.insertText(range.index + url.length, ' ', 'user')   // unlinked trailing space
      q2.setSelection(range.index + url.length + 1, 0, 'user')
      q2.focus()
    } else {
      insert(`${url} `)
    }
  }

  const pickMeeting = async (platform) => {
    if (meetingBusy) return
    setMeetingBusy(platform)
    try {
      const res = await meetingLinkApi.create(platform)
      if (res?.link) insertLink(res.link)
      setMeetingOpen(false)
    } catch { /* handleErr already surfaced the message */ }
    finally { setMeetingBusy(null) }
  }

  return (
    <div className={`flex items-center gap-1 ${className}`}>
      {/* Emoji — always available */}
      <div className="relative">
        <button type="button" onClick={() => { setEmojiOpen(v => !v); setMentionOpen(false) }}
          className={btnCls} style={{ color: emojiOpen ? accent : 'var(--text-muted)' }} title="Emoji" aria-label="Insert emoji">
          <Smile size={15} />
        </button>
        <Popover open={emojiOpen} onClose={() => setEmojiOpen(false)}>
          <div className="grid grid-cols-8 gap-0.5" style={{ maxWidth: 260 }}>
            {EMOJIS.map((e, i) => (
              <button key={i} type="button" onClick={() => pickEmoji(e)}
                className="text-lg leading-none p-1 rounded-md hover:scale-110 transition-transform"
                style={{ background: 'transparent' }}
                onMouseEnter={ev => ev.currentTarget.style.background = 'var(--bg-input)'}
                onMouseLeave={ev => ev.currentTarget.style.background = 'transparent'}>
                {e}
              </button>
            ))}
          </div>
        </Popover>
      </div>

      {/* @-mention — only where a people list is supplied */}
      {people && (
        <div className="relative">
          <button type="button" onClick={() => { setMentionOpen(v => !v); setEmojiOpen(false) }}
            className={btnCls} style={{ color: mentionOpen ? accent : 'var(--text-muted)' }} title="Mention someone" aria-label="Mention someone">
            <AtSign size={15} />
          </button>
          <Popover open={mentionOpen} onClose={() => { setMentionOpen(false); setQ('') }}>
            <input autoFocus value={q} onChange={e => setQ(e.target.value)} placeholder="Search people…"
              className="w-full text-xs px-2 py-1.5 rounded-lg outline-none mb-1"
              style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
            <ul className="max-h-52 overflow-y-auto">
              {filtered.map(p => (
                <li key={p.id ?? p.value ?? (p.name || p.label)}>
                  <button type="button" onClick={() => pickPerson(p)}
                    className="w-full text-left text-xs px-2 py-1.5 rounded-lg hover:opacity-90"
                    style={{ color: 'var(--text-body)' }}
                    onMouseEnter={ev => ev.currentTarget.style.background = 'var(--bg-input)'}
                    onMouseLeave={ev => ev.currentTarget.style.background = 'transparent'}>
                    <span className="font-semibold">@{p.name || p.label}</span>
                    {p.email && <span className="ml-1" style={{ color: 'var(--text-muted)' }}>· {p.email}</span>}
                  </button>
                </li>
              ))}
              {filtered.length === 0 && <li className="text-xs px-2 py-1.5" style={{ color: 'var(--text-muted)' }}>No matches</li>}
            </ul>
          </Popover>
        </div>
      )}

      {/* Attach — only where the editor can accept files */}
      {onAttach && (
        <button type="button" onClick={onAttach}
          className={btnCls} style={{ color: 'var(--text-muted)' }} title="Attach a file" aria-label="Attach a file">
          <Paperclip size={15} />
        </button>
      )}

      {/* Meeting — Zoom / Google Meet / Jitsi link into the message */}
      {meeting && (
        <div className="relative">
          <button type="button" onClick={() => { setMeetingOpen(v => !v); setEmojiOpen(false); setMentionOpen(false); setQuickOpen(false) }}
            className={btnCls} style={{ color: meetingOpen ? accent : 'var(--text-muted)' }} title="Add a meeting link" aria-label="Add a meeting link">
            <Video size={15} />
          </button>
          <Popover open={meetingOpen} onClose={() => setMeetingOpen(false)}>
            <ul className="min-w-[150px]">
              {MEET_PLATFORMS.map(p => (
                <li key={p.key}>
                  <button type="button" disabled={!!meetingBusy} onClick={() => pickMeeting(p.key)}
                    className="w-full flex items-center gap-2 text-left text-xs font-semibold px-2 py-1.5 rounded-lg disabled:opacity-50"
                    style={{ color: 'var(--text-body)' }}
                    onMouseEnter={ev => ev.currentTarget.style.background = 'var(--bg-input)'}
                    onMouseLeave={ev => ev.currentTarget.style.background = 'transparent'}>
                    <Video size={13} style={{ color: accent }} />
                    {p.label}
                    {meetingBusy === p.key && <span className="ml-auto text-[10px]" style={{ color: 'var(--text-muted)' }}>…</span>}
                  </button>
                </li>
              ))}
            </ul>
          </Popover>
        </div>
      )}

      {/* Poll — only where a poll context is available */}
      {onPoll && (
        <button type="button" onClick={onPoll}
          className={btnCls} style={{ color: 'var(--text-muted)' }} title="Create a poll" aria-label="Create a poll">
          <BarChart3 size={15} />
        </button>
      )}

      {/* Quick-create "+" — context-linked Task / Note / Topic actions */}
      {quickCreate && quickCreate.length > 0 && (
        <div className="relative">
          <button type="button" onClick={() => { setQuickOpen(v => !v); setEmojiOpen(false); setMentionOpen(false) }}
            className={btnCls} style={{ color: quickOpen ? accent : 'var(--text-muted)' }} title="Create" aria-label="Quick create">
            <Plus size={15} />
          </button>
          <Popover open={quickOpen} onClose={() => setQuickOpen(false)}>
            <ul className="min-w-[160px]">
              {quickCreate.map((item, i) => (
                <li key={i}>
                  <button type="button" onClick={() => { setQuickOpen(false); item.onClick?.() }}
                    className="w-full flex items-center gap-2 text-left text-xs font-semibold px-2 py-1.5 rounded-lg"
                    style={{ color: 'var(--text-body)' }}
                    onMouseEnter={ev => ev.currentTarget.style.background = 'var(--bg-input)'}
                    onMouseLeave={ev => ev.currentTarget.style.background = 'transparent'}>
                    {item.icon && <item.icon size={14} style={{ color: accent }} />}
                    {item.label}
                  </button>
                </li>
              ))}
            </ul>
          </Popover>
        </div>
      )}
    </div>
  )
}
