import { useRef, useEffect, useCallback } from 'react'
import {
  Bold, Italic, Underline, Strikethrough, Heading2, Heading3,
  List, ListOrdered, Link2, Table, Image as ImageIcon,
  AlignLeft, AlignCenter, AlignRight, Video, Music,
  Baseline, Highlighter, Eraser, Quote, Code,
} from 'lucide-react'
import { compressImage } from '@/lib/imageCompress'

// Word-like font controls. Families are web-safe so they render in the PDF too.
const FONT_FAMILIES = [
  { label: 'Default', value: '' },
  { label: 'Sans Serif', value: 'Arial, Helvetica, sans-serif' },
  { label: 'Serif', value: 'Georgia, "Times New Roman", serif' },
  { label: 'Mono', value: '"Courier New", monospace' },
]
// execCommand fontSize uses 1–7 HTML buckets. Map them to CSS keywords that the
// backend HtmlSanitizer explicitly whitelists.
const FONT_SIZES = [
  { label: 'Small',  value: '2' },
  { label: 'Normal', value: '3' },
  { label: 'Large',  value: '5' },
  { label: 'Huge',   value: '7' },
]
const FONT_SIZE_CSS = {
  '1': 'x-small', '2': 'small', '3': 'medium',
  '4': 'large',   '5': 'x-large', '6': 'xx-large', '7': 'xx-large',
}

/**
 * Chrome ignores styleWithCSS for execCommand('fontSize') and always emits
 * <font size="N"> tags. Our server HtmlSanitizer strips <font> (not in
 * ALLOWED_TAGS), so font sizes vanish after save. Walk the editor node after
 * every execCommand and replace any <font> elements with <span style>.
 */
function convertFontTags(root) {
  if (!root) return
  // First pass: size + optional face/color.
  root.querySelectorAll('font[size]').forEach(font => {
    const span = document.createElement('span')
    span.style.fontSize  = FONT_SIZE_CSS[font.getAttribute('size')] || 'medium'
    if (font.getAttribute('face'))  span.style.fontFamily = font.getAttribute('face')
    if (font.getAttribute('color')) span.style.color      = font.getAttribute('color')
    while (font.firstChild) span.appendChild(font.firstChild)
    font.replaceWith(span)
  })
  // Second pass: remaining <font> without size (face/color only).
  root.querySelectorAll('font').forEach(font => {
    const span = document.createElement('span')
    if (font.getAttribute('face'))  span.style.fontFamily = font.getAttribute('face')
    if (font.getAttribute('color')) span.style.color      = font.getAttribute('color')
    while (font.firstChild) span.appendChild(font.firstChild)
    font.replaceWith(span)
  })
}

/**
 * Dependency-free rich text editor (contenteditable) shared by customer notes,
 * proposal/contract pages and email bodies. Output is HTML; the server runs
 * every save through App\Support\HtmlSanitizer — client output is convenience,
 * the sanitizer is the security boundary. Anything the sanitizer would strip
 * (arbitrary iframes, scripts, unsafe styles) simply won't survive the round
 * trip, so the editor only needs to emit things the sanitizer keeps.
 *
 * execCommand is deprecated but universally supported; it keeps us free of a
 * heavy editor dependency for the toolbar we actually need.
 */

const IMAGE_SIZES = { Small: '33%', Medium: '66%', Full: '100%' }

// A fixed height + tight px-1.5 padding clips descenders in Firefox's native
// <select> rendering. Give the box a touch more height/padding and an explicit
// line-height so the label sits fully inside it, matching the icon buttons beside it.
const SELECT_STYLE = {
  background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)',
  height: 28, lineHeight: '26px', padding: '0 6px', maxWidth: 100, boxSizing: 'border-box',
}

// Turn a pasted YouTube/Vimeo watch URL into its embeddable form (the only
// hosts the sanitizer's iframe allowlist accepts). Returns null if unknown.
function toEmbedUrl(url) {
  try {
    const u = new URL(url)
    const host = u.hostname.replace(/^www\./, '')
    if (host === 'youtube.com' && u.searchParams.get('v')) return `https://www.youtube.com/embed/${u.searchParams.get('v')}`
    if (host === 'youtu.be') return `https://www.youtube.com/embed/${u.pathname.slice(1)}`
    if (host === 'youtube.com' && u.pathname.startsWith('/embed/')) return `https://www.youtube.com${u.pathname}`
    if (host === 'vimeo.com') { const id = u.pathname.split('/').filter(Boolean)[0]; return id ? `https://player.vimeo.com/video/${id}` : null }
    if (host === 'player.vimeo.com' && u.pathname.startsWith('/video/')) return `https://player.vimeo.com${u.pathname}`
    return null
  } catch { return null }
}

export default function RichTextEditor({ value = '', onChange, placeholder = 'Write here…', minHeight = 160 }) {
  const ref     = useRef(null)
  const fileRef = useRef(null)

  // ─── Focus guard ────────────────────────────────────────────────────────────
  // While the user is actively editing we NEVER touch innerHTML from outside.
  // Any prop update that arrives while the editor has focus is silently ignored;
  // the next time the editor blurs we emit the current content so the parent
  // catches up. This eliminates ALL "content reset on re-render" bugs caused by
  // browser HTML normalisation differences (trailing semicolons, attribute order,
  // rgb() vs hex, etc.) making string comparisons unreliable.
  const hasFocusRef = useRef(false)
  // Last value we WROTE into the DOM so we can skip redundant innerHTML sets.
  const lastSetRef  = useRef(null)

  useEffect(() => {
    if (!ref.current) return
    if (hasFocusRef.current) return        // user is editing — leave it alone
    const incoming = value || ''
    if (incoming === lastSetRef.current) return  // already showing this value
    lastSetRef.current = incoming
    ref.current.innerHTML = incoming
  }, [value])

  // ─── Selection save / restore ────────────────────────────────────────────────
  // Clicking a <select> or <input type="color"> in the toolbar steals focus from
  // the contenteditable, losing the user's text selection. We snapshot the range
  // before the element steals focus (onMouseDown) and replay it inside exec /
  // applyCss after refocusing the editor.
  const savedSelRef = useRef(null)

  const saveSelection = useCallback(() => {
    const sel = window.getSelection()
    if (sel?.rangeCount > 0 && ref.current?.contains(sel.anchorNode)) {
      savedSelRef.current = sel.getRangeAt(0).cloneRange()
    }
  }, [])

  const restoreSelection = () => {
    if (!savedSelRef.current) return
    const sel = window.getSelection()
    if (sel) { sel.removeAllRanges(); sel.addRange(savedSelRef.current) }
  }

  // ─── Emit ────────────────────────────────────────────────────────────────────
  // Always convert legacy <font> tags Chrome emits (execCommand fontSize ignores
  // styleWithCSS), then read innerHTML and push up to the parent. Also update
  // lastSetRef so the useEffect above treats the next re-render as a no-op.
  const emit = useCallback(() => {
    convertFontTags(ref.current)
    const html = ref.current?.innerHTML || ''
    lastSetRef.current = html   // mark: this is what we currently show
    onChange?.(html)
  }, [onChange])

  // ─── execCommand helpers ─────────────────────────────────────────────────────
  const exec = (cmd, arg = null) => {
    ref.current?.focus()
    restoreSelection()                          // replay selection lost to toolbar
    document.execCommand(cmd, false, arg)
    convertFontTags(ref.current)
    emit()
  }

  const insertHTML = (html) => exec('insertHTML', html)

  // Apply a CSS-based inline style. Chrome's execCommand('fontSize') ignores
  // styleWithCSS and always emits <font size>, so convertFontTags() cleans it up.
  const applyCss = (cmd, val) => {
    ref.current?.focus()
    restoreSelection()                          // replay selection lost to toolbar
    document.execCommand('styleWithCSS', false, true)
    document.execCommand(cmd, false, val)
    document.execCommand('styleWithCSS', false, false)
    convertFontTags(ref.current)
    emit()
  }

  const insertLink = () => {
    const url = window.prompt('Link URL (https://…)')
    if (!url) return
    if (!/^https?:\/\//i.test(url)) return window.alert('Only http(s) links are allowed')
    exec('createLink', url)
  }

  const insertTable = () => {
    const rows = Math.min(Math.max(parseInt(window.prompt('Number of rows?', '3'), 10) || 3, 1), 30)
    const cols = Math.min(Math.max(parseInt(window.prompt('Number of columns?', '2'), 10) || 2, 1), 10)
    const width = window.prompt('Table width — Full / Half / a number like 400px', 'Full') || 'Full'
    const w = /^\d+(px|%)?$/.test(width.trim()) ? width.trim() : (/half/i.test(width) ? '50%' : '100%')
    let html = `<table style="width:${w}" border="1" cellpadding="6"><tbody>`
    html += '<tr>' + Array.from({ length: cols }, (_, i) => `<th>Column ${i + 1}</th>`).join('') + '</tr>'
    for (let r = 1; r < rows; r++) html += '<tr>' + Array.from({ length: cols }, () => '<td><br></td>').join('') + '</tr>'
    html += '</tbody></table><p><br></p>'
    insertHTML(html)
  }

  const pickImage = () => fileRef.current?.click()

  const insertImage = async (e) => {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file) return
    if (!file.type.startsWith('image/')) return window.alert('Only images are allowed')
    const choice = (window.prompt('Image size — Small / Medium / Full', 'Full') || 'Full').trim()
    const key = Object.keys(IMAGE_SIZES).find(k => k.toLowerCase() === choice.toLowerCase()) || 'Full'
    // Auto-downscale + recompress so even a big phone photo embeds small — no
    // more "max 500 KB, resize it yourself" and no multi-MB base64 in the DB.
    try {
      const dataUrl = await compressImage(file, { maxDim: 1600, quality: 0.82 })
      if (!dataUrl) return
      insertHTML(`<img src="${dataUrl}" alt="" style="width:${IMAGE_SIZES[key]}" /><p><br></p>`)
    } catch {
      window.alert('Could not process that image — please try a different file.')
    }
  }

  const insertVideo = () => {
    const url = (window.prompt('Video URL — YouTube/Vimeo link, or a direct .mp4/.webm URL (https)') || '').trim()
    if (!url) return
    const embed = toEmbedUrl(url)
    if (embed) {
      insertHTML(`<iframe src="${embed}" width="560" height="315" allowfullscreen title="Embedded video"></iframe><p><br></p>`)
    } else if (/^https:\/\/.+\.(mp4|webm|ogg)(\?.*)?$/i.test(url)) {
      insertHTML(`<video controls src="${url}" style="width:100%"></video><p><br></p>`)
    } else {
      window.alert('Use a YouTube/Vimeo link, or a direct https .mp4/.webm/.ogg URL.')
    }
  }

  const insertAudio = () => {
    const url = (window.prompt('Audio URL — a direct .mp3/.ogg/.wav link (https)') || '').trim()
    if (!url) return
    if (!/^https:\/\/.+\.(mp3|ogg|wav|m4a)(\?.*)?$/i.test(url)) return window.alert('Use a direct https .mp3/.ogg/.wav URL.')
    insertHTML(`<audio controls src="${url}"></audio><p><br></p>`)
  }

  // Alignment: browser applies text-align to the containing block (sanitizer keeps it).
  const align = (dir) => exec('justify' + dir)

  /**
   * Quote / code block, matching the toolbar the Tasks and Helpdesk editors offer
   * so a note looks the same wherever it was written.
   *
   * formatBlock toggles: applying it to a block that already has that tag would
   * otherwise nest another one, and there'd be no way back to a plain paragraph.
   */
  const toggleBlock = (tag) => {
    const sel = window.getSelection()
    const node = sel?.anchorNode
    const el = node?.nodeType === 1 ? node : node?.parentElement
    const already = el?.closest?.(tag)
    // Keep the check inside the editor — closest() would otherwise walk out of it.
    exec('formatBlock', already && ref.current?.contains(already) ? 'p' : tag)
  }

  // <pre> alone renders monospace but isn't semantically a code block; wrapping
  // the text in <code> matches what Quill emits, and both tags are allowlisted.
  const insertCodeBlock = () => {
    const sel = window.getSelection()
    const picked = sel && !sel.isCollapsed ? String(sel) : ''
    const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    insertHTML(`<pre><code>${esc(picked) || 'code'}</code></pre><p><br></p>`)
  }

  const GROUPS = [
    [
      { icon: Bold,          title: 'Bold',          run: () => exec('bold') },
      { icon: Italic,        title: 'Italic',        run: () => exec('italic') },
      { icon: Underline,     title: 'Underline',     run: () => exec('underline') },
      { icon: Strikethrough, title: 'Strikethrough', run: () => exec('strikeThrough') },
    ],
    [
      { icon: Heading2,    title: 'Heading',       run: () => exec('formatBlock', 'h2') },
      { icon: Heading3,    title: 'Sub-heading',   run: () => exec('formatBlock', 'h3') },
      { icon: List,        title: 'Bullet list',   run: () => exec('insertUnorderedList') },
      { icon: ListOrdered, title: 'Numbered list', run: () => exec('insertOrderedList') },
      { icon: Quote, title: 'Quote', run: () => toggleBlock('blockquote') },
      { icon: Code, title: 'Code block', run: insertCodeBlock },
    ],
    [
      { icon: AlignLeft,   title: 'Align left',   run: () => align('Left') },
      { icon: AlignCenter, title: 'Align center',  run: () => align('Center') },
      { icon: AlignRight,  title: 'Align right',   run: () => align('Right') },
    ],
    [
      { icon: Link2,     title: 'Insert link',                          run: insertLink },
      { icon: Table,     title: 'Insert table (choose size)',            run: insertTable },
      { icon: ImageIcon, title: 'Insert image (auto-compressed, choose size)',   run: pickImage },
      { icon: Video,     title: 'Insert video (YouTube/Vimeo or direct URL)', run: insertVideo },
      { icon: Music,     title: 'Insert audio (direct URL)',             run: insertAudio },
    ],
  ]

  return (
    <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border-input)', background: 'var(--bg-input)' }}>
      <div className="flex items-center gap-0.5 flex-wrap px-2 py-1.5" style={{ borderBottom: '1px solid var(--border)' }}>
        {/* Font family + size + colours */}
        <div className="flex items-center gap-1">
          {/* onMouseDown saves the selection BEFORE the <select> steals focus */}
          <select title="Font"
            onMouseDown={saveSelection}
            onChange={e => applyCss('fontName', e.target.value)}
            className="text-xs rounded-lg outline-none" style={SELECT_STYLE}>
            {FONT_FAMILIES.map(f => <option key={f.label} value={f.value}>{f.label}</option>)}
          </select>
          <select title="Font size" defaultValue="3"
            onMouseDown={saveSelection}
            onChange={e => applyCss('fontSize', e.target.value)}
            className="text-xs rounded-lg outline-none" style={{ ...SELECT_STYLE, maxWidth: 78 }}>
            {FONT_SIZES.map(s => <option key={s.value} value={s.value}>{s.label}</option>)}
          </select>
          <label title="Text colour" onMouseDown={saveSelection}
            className="w-7 h-7 rounded-lg flex items-center justify-center cursor-pointer hover:bg-[rgba(124,58,237,0.1)] relative">
            <Baseline size={14} style={{ color: 'var(--text-muted)' }} />
            <input type="color" className="absolute inset-0 opacity-0 cursor-pointer"
              onChange={e => applyCss('foreColor', e.target.value)} />
          </label>
          <label title="Highlight" onMouseDown={saveSelection}
            className="w-7 h-7 rounded-lg flex items-center justify-center cursor-pointer hover:bg-[rgba(124,58,237,0.1)] relative">
            <Highlighter size={14} style={{ color: 'var(--text-muted)' }} />
            <input type="color" className="absolute inset-0 opacity-0 cursor-pointer"
              onChange={e => applyCss('hiliteColor', e.target.value)} />
          </label>
          <button type="button" title="Clear formatting" onMouseDown={e => e.preventDefault()} onClick={() => exec('removeFormat')}
            className="w-7 h-7 rounded-lg flex items-center justify-center hover:bg-[rgba(124,58,237,0.1)] transition-colors">
            <Eraser size={13} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>
        <span className="w-px h-4 mx-1" style={{ background: 'var(--border)' }} />
        {GROUPS.map((group, gi) => (
          <div key={gi} className="flex items-center gap-0.5">
            {gi > 0 && <span className="w-px h-4 mx-1" style={{ background: 'var(--border)' }} />}
            {group.map(({ icon: Icon, title, run }) => (
              <button key={title} type="button" title={title}
                onMouseDown={e => e.preventDefault() /* keep selection + focus */}
                onClick={run}
                className="w-7 h-7 rounded-lg flex items-center justify-center hover:bg-[rgba(124,58,237,0.1)] transition-colors">
                <Icon size={13} style={{ color: 'var(--text-muted)' }} />
              </button>
            ))}
          </div>
        ))}
      </div>
      <div
        ref={ref}
        contentEditable
        onFocus={() => { hasFocusRef.current = true }}
        onBlur={()  => { hasFocusRef.current = false; emit() }}
        onInput={emit}
        data-placeholder={placeholder}
        className="rte-body px-4 py-3 text-sm outline-none"
        style={{ minHeight, color: 'var(--text-h)' }}
        suppressContentEditableWarning
      />
      <input ref={fileRef} type="file" accept="image/*" className="hidden" onChange={insertImage} />
    </div>
  )
}
