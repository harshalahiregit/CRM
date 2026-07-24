import { useRef, useEffect, useCallback, useState } from 'react'
import {
  Bold, Italic, Underline, List, ListOrdered, Link, AlignLeft,
  AlignCenter, AlignRight, Undo2, Redo2,
} from 'lucide-react'

/**
 * RichTextEditor — self-contained contenteditable rich text editor.
 *
 * Zero external dependencies. Uses document.execCommand (supported in all
 * modern browsers) for formatting. Designed to match the existing kit3d
 * design system: dark bg-input, var(--border), var(--text-h) text.
 *
 * Props:
 *   value        {string}   — HTML string (controlled)
 *   onChange     {fn}       — called with new HTML string on every input
 *   placeholder  {string}   — shown when editor is empty
 *   minHeight    {number}   — editor min-height in px (default 120)
 *   disabled     {bool}
 */
export default function RichTextEditor({
  value = '',
  onChange,
  placeholder = 'Type here…',
  minHeight = 120,
  disabled = false,
}) {
  const editorRef  = useRef(null)
  const skipSync   = useRef(false)   // prevent cursor-reset during external value sync
  const [focused, setFocused] = useState(false)

  // ── Sync external value into DOM (only when it differs to avoid cursor jump) ──
  useEffect(() => {
    const el = editorRef.current
    if (!el || skipSync.current) return
    // Only overwrite DOM if value actually changed (prevents cursor reset on every keystroke)
    if (el.innerHTML !== value) {
      el.innerHTML = value || ''
    }
  }, [value])

  // ── Report changes upward ───────────────────────────────────────────────────
  const handleInput = useCallback(() => {
    skipSync.current = true
    onChange?.(editorRef.current?.innerHTML ?? '')
    // Allow next external-prop sync after a tick
    requestAnimationFrame(() => { skipSync.current = false })
  }, [onChange])

  // ── execCommand helper ──────────────────────────────────────────────────────
  const exec = useCallback((cmd, value = null) => {
    editorRef.current?.focus()
    document.execCommand(cmd, false, value)
    handleInput()
  }, [handleInput])

  // ── Insert link ─────────────────────────────────────────────────────────────
  const insertLink = useCallback(() => {
    const sel = window.getSelection()
    const selectedText = sel?.toString() || ''
    const url = window.prompt('Enter URL:', 'https://')
    if (!url) return
    if (selectedText) {
      exec('createLink', url)
    } else {
      exec('insertHTML', `<a href="${url}" target="_blank" rel="noopener">${url}</a>`)
    }
  }, [exec])

  // ── Keyboard shortcuts ──────────────────────────────────────────────────────
  const handleKeyDown = useCallback((e) => {
    if (!e.ctrlKey && !e.metaKey) return
    const map = { b: 'bold', i: 'italic', u: 'underline', z: 'undo', y: 'redo' }
    const cmd = map[e.key.toLowerCase()]
    if (cmd) { e.preventDefault(); exec(cmd) }
    // Ctrl+Shift+Z → redo
    if (e.key.toLowerCase() === 'z' && e.shiftKey) { e.preventDefault(); exec('redo') }
  }, [exec])

  // ── Toolbar buttons config ──────────────────────────────────────────────────
  const groups = [
    [
      { icon: Bold,         title: 'Bold (Ctrl+B)',         cmd: 'bold' },
      { icon: Italic,       title: 'Italic (Ctrl+I)',       cmd: 'italic' },
      { icon: Underline,    title: 'Underline (Ctrl+U)',    cmd: 'underline' },
    ],
    [
      { icon: List,         title: 'Bullet list',           cmd: 'insertUnorderedList' },
      { icon: ListOrdered,  title: 'Numbered list',         cmd: 'insertOrderedList' },
    ],
    [
      { icon: AlignLeft,    title: 'Align left',            cmd: 'justifyLeft' },
      { icon: AlignCenter,  title: 'Align center',          cmd: 'justifyCenter' },
      { icon: AlignRight,   title: 'Align right',           cmd: 'justifyRight' },
    ],
    [
      { icon: Link,         title: 'Insert link',           action: insertLink },
    ],
    [
      { icon: Undo2,        title: 'Undo (Ctrl+Z)',         cmd: 'undo' },
      { icon: Redo2,        title: 'Redo (Ctrl+Y)',         cmd: 'redo' },
    ],
  ]

  return (
    <div style={{
      border: `1.5px solid ${focused ? 'rgba(124,58,237,0.6)' : 'var(--border)'}`,
      borderRadius: 10,
      overflow: 'hidden',
      background: 'var(--bg-input)',
      opacity: disabled ? 0.55 : 1,
      transition: 'border-color .15s ease',
      boxShadow: focused ? '0 0 0 3px rgba(124,58,237,0.12)' : 'none',
    }}>

      {/* Toolbar */}
      <div style={{
        display: 'flex',
        alignItems: 'center',
        gap: 2,
        padding: '6px 8px',
        borderBottom: '1px solid var(--border)',
        background: 'var(--bg-card)',
        flexWrap: 'wrap',
      }}>
        {groups.map((group, gi) => (
          <div key={gi} style={{ display: 'flex', alignItems: 'center', gap: 1, paddingRight: gi < groups.length - 1 ? 8 : 0, borderRight: gi < groups.length - 1 ? '1px solid var(--border)' : 'none', marginRight: gi < groups.length - 1 ? 6 : 0 }}>
            {group.map(({ icon: Icon, title, cmd, action }) => (
              <button
                key={title}
                type="button"
                title={title}
                disabled={disabled}
                onMouseDown={(e) => {
                  // Prevent blur before exec runs
                  e.preventDefault()
                  action ? action() : exec(cmd)
                }}
                style={{
                  width: 28, height: 28, borderRadius: 6, border: 'none',
                  background: 'transparent', cursor: disabled ? 'not-allowed' : 'pointer',
                  display: 'flex', alignItems: 'center', justifyContent: 'center',
                  color: 'var(--text-muted)',
                  transition: 'background .12s, color .12s',
                }}
                onMouseEnter={e => { if (!disabled) { e.currentTarget.style.background = 'rgba(124,58,237,0.12)'; e.currentTarget.style.color = '#a78bfa' } }}
                onMouseLeave={e => { e.currentTarget.style.background = 'transparent'; e.currentTarget.style.color = 'var(--text-muted)' }}
              >
                <Icon size={14} />
              </button>
            ))}
          </div>
        ))}
      </div>

      {/* Editable area */}
      <div style={{ position: 'relative' }}>
        {/* Placeholder */}
        {!value && !focused && (
          <div style={{
            position: 'absolute', top: 10, left: 12, pointerEvents: 'none',
            color: 'var(--text-muted)', fontSize: 13, opacity: 0.6, userSelect: 'none',
          }}>
            {placeholder}
          </div>
        )}
        <div
          ref={editorRef}
          contentEditable={!disabled}
          suppressContentEditableWarning
          onInput={handleInput}
          onKeyDown={handleKeyDown}
          onFocus={() => setFocused(true)}
          onBlur={() => setFocused(false)}
          style={{
            minHeight, padding: '10px 12px',
            color: 'var(--text-h)', fontSize: 13, lineHeight: 1.6,
            outline: 'none', wordBreak: 'break-word',
            // basic prose styles
          }}
        />
      </div>

      {/* Inline prose styles — scoped to avoid global leak */}
      <style>{`
        [contenteditable] ul { list-style: disc; padding-left: 20px; margin: 4px 0; }
        [contenteditable] ol { list-style: decimal; padding-left: 20px; margin: 4px 0; }
        [contenteditable] a  { color: #a78bfa; text-decoration: underline; }
        [contenteditable] strong, [contenteditable] b { font-weight: 700; }
        [contenteditable] em, [contenteditable] i { font-style: italic; }
        [contenteditable] u  { text-decoration: underline; }
      `}</style>
    </div>
  )
}
