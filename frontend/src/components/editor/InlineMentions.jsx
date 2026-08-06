// Inline "@" autocomplete for the composers. Typing "@" (optionally followed by
// letters) pops a live list of people at the caret; Enter/Tab/click inserts the
// tag directly. Works on both a ReactQuill instance (via quillRef) and a plain
// controlled <textarea> (via textareaRef + value/onChange). Rendered by
// EditorActionBar wherever a `people` list exists, so every editor gets it.
import { useEffect, useMemo, useRef, useState } from 'react'

// The @-token immediately before the caret: "@", "@jo", "@jane.doe"…
const TOKEN_RE = /(^|\s)@([\p{L}\d._-]*)$/u

export default function InlineMentions({ quillRef, textareaRef, value, onChange, people = [], accent = 'var(--color-primary-500)' }) {
  const [query, setQuery] = useState(null)   // null = closed
  const [anchor, setAnchor] = useState(0)    // index of the '@'
  const [pos, setPos] = useState({ top: 0, left: 0 })
  const [active, setActive] = useState(0)

  const filtered = useMemo(() => {
    if (query == null) return []
    const s = query.trim().toLowerCase()
    const list = people || []
    return (s ? list.filter(p => (p.name || p.label || '').toLowerCase().includes(s)) : list).slice(0, 8)
  }, [people, query])

  // Refs so the (capture-phase) keydown handler always sees fresh values.
  const state = useRef({})
  state.current = { query, anchor, filtered, active }

  const close = () => setQuery(null)

  const insertPick = (person) => {
    const name = (person.name || person.label || '').replace(/\s+/g, ' ').trim()
    if (!name) return close()
    const q = state.current.query ?? ''
    const at = state.current.anchor

    const quill = quillRef?.current?.getEditor?.()
    if (quill && !textareaRef) {
      quill.deleteText(at, q.length + 1, 'user')     // remove "@query"
      quill.insertText(at, `@${name} `, 'user')
      quill.setSelection(at + name.length + 2, 0, 'user')
      quill.focus()
    } else if (textareaRef?.current) {
      const el = textareaRef.current
      const next = (value ?? '').slice(0, at) + `@${name} ` + (value ?? '').slice(at + q.length + 1)
      onChange(next)
      const caret = at + name.length + 2
      requestAnimationFrame(() => { el.focus(); el.setSelectionRange(caret, caret) })
    }
    close()
  }

  // ── Quill wiring ──────────────────────────────────────────────────────────
  useEffect(() => {
    const quill = quillRef?.current?.getEditor?.()
    if (!quill || textareaRef) return

    const recompute = () => {
      const sel = quill.getSelection()
      if (!sel) return close()
      const before = quill.getText(0, sel.index)
      const m = TOKEN_RE.exec(before)
      if (!m) return close()
      const at = sel.index - m[2].length - 1
      const b = quill.getBounds(at)
      const r = quill.root.getBoundingClientRect()
      setAnchor(at); setQuery(m[2]); setActive(0)
      setPos({ left: r.left + b.left, top: r.top + b.top + b.height + 2 })
    }

    const onKeyDown = (e) => {
      if (state.current.query == null) return
      const list = state.current.filtered
      if (e.key === 'ArrowDown') { e.preventDefault(); e.stopPropagation(); setActive(a => Math.min(a + 1, list.length - 1)) }
      else if (e.key === 'ArrowUp') { e.preventDefault(); e.stopPropagation(); setActive(a => Math.max(a - 1, 0)) }
      else if (e.key === 'Enter' || e.key === 'Tab') {
        if (list.length) { e.preventDefault(); e.stopPropagation(); insertPick(list[state.current.active] || list[0]) }
      } else if (e.key === 'Escape') { e.stopPropagation(); close() }
    }

    quill.on('editor-change', recompute)
    quill.root.addEventListener('keydown', onKeyDown, true)   // capture — beat Quill's Enter
    return () => { quill.off('editor-change', recompute); quill.root.removeEventListener('keydown', onKeyDown, true) }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [quillRef, textareaRef])

  // ── Textarea wiring ───────────────────────────────────────────────────────
  useEffect(() => {
    const el = textareaRef?.current
    if (!el) return

    const recompute = () => {
      const idx = el.selectionStart
      const before = (el.value ?? '').slice(0, idx)
      const m = TOKEN_RE.exec(before)
      if (!m) return close()
      const r = el.getBoundingClientRect()
      setAnchor(idx - m[2].length - 1); setQuery(m[2]); setActive(0)
      setPos({ left: r.left + 8, top: r.bottom + 2 })
    }

    const onKeyDown = (e) => {
      if (state.current.query == null) { if (e.key !== 'Escape') return }
      const list = state.current.filtered
      if (e.key === 'ArrowDown' && list.length) { e.preventDefault(); setActive(a => Math.min(a + 1, list.length - 1)) }
      else if (e.key === 'ArrowUp' && list.length) { e.preventDefault(); setActive(a => Math.max(a - 1, 0)) }
      else if ((e.key === 'Enter' || e.key === 'Tab') && state.current.query != null && list.length) { e.preventDefault(); insertPick(list[state.current.active] || list[0]) }
      else if (e.key === 'Escape') close()
    }

    el.addEventListener('keyup', recompute)
    el.addEventListener('click', recompute)
    el.addEventListener('keydown', onKeyDown)
    return () => { el.removeEventListener('keyup', recompute); el.removeEventListener('click', recompute); el.removeEventListener('keydown', onKeyDown) }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [textareaRef, value])

  if (query == null || filtered.length === 0) return null

  return (
    <div className="fixed z-[70] rounded-xl p-1 shadow-lg"
      style={{ top: pos.top, left: pos.left, minWidth: 200, maxWidth: 280, background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}
      // Keep the editor's focus/caret while clicking a suggestion.
      onMouseDown={e => e.preventDefault()}>
      <ul className="max-h-56 overflow-y-auto">
        {filtered.map((p, i) => (
          <li key={p.id ?? p.value ?? (p.name || p.label)}>
            <button type="button" onClick={() => insertPick(p)} onMouseEnter={() => setActive(i)}
              className="w-full text-left text-xs px-2 py-1.5 rounded-lg"
              style={{ background: i === active ? 'var(--bg-input)' : 'transparent', color: 'var(--text-body)' }}>
              <span className="font-semibold" style={{ color: 'var(--text-h)' }}>@{p.name || p.label}</span>
              {p.email && <span className="ml-1" style={{ color: 'var(--text-muted)' }}>· {p.email}</span>}
              {p.role && !p.email && <span className="ml-1" style={{ color: 'var(--text-muted)' }}>· {p.role}</span>}
            </button>
          </li>
        ))}
      </ul>
    </div>
  )
}
