import { useRef, useEffect, useCallback } from 'react'
import {
  Bold, Italic, Underline, Heading2, Heading3,
  List, ListOrdered, Link2, Table, Image as ImageIcon,
  AlignLeft, AlignCenter, AlignRight, Video, Music,
} from 'lucide-react'

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
const MAX_IMAGE_BYTES = 500 * 1024 // keep pages PDF-friendly

const IMAGE_SIZES = { Small: '33%', Medium: '66%', Full: '100%' }

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
  const ref = useRef(null)
  const fileRef = useRef(null)

  useEffect(() => {
    if (ref.current && ref.current.innerHTML !== (value || '')) {
      ref.current.innerHTML = value || ''
    }
  }, [value])

  const emit = useCallback(() => onChange?.(ref.current?.innerHTML || ''), [onChange])

  const exec = (cmd, arg = null) => {
    ref.current?.focus()
    document.execCommand(cmd, false, arg)
    emit()
  }

  const insertHTML = (html) => exec('insertHTML', html)

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

  const insertImage = (e) => {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file) return
    if (!file.type.startsWith('image/')) return window.alert('Only images are allowed')
    if (file.size > MAX_IMAGE_BYTES) return window.alert('Image too large — max 500 KB (resize/compress it first)')
    const choice = (window.prompt('Image size — Small / Medium / Full', 'Full') || 'Full').trim()
    const key = Object.keys(IMAGE_SIZES).find(k => k.toLowerCase() === choice.toLowerCase()) || 'Full'
    const reader = new FileReader()
    reader.onload = () => insertHTML(`<img src="${reader.result}" alt="" style="width:${IMAGE_SIZES[key]}" /><p><br></p>`)
    reader.readAsDataURL(file)
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

  // Alignment works on the current block AND on a focused table cell (the
  // browser applies text-align to the containing block, which the sanitizer keeps).
  const align = (dir) => exec('justify' + dir)

  const GROUPS = [
    [
      { icon: Bold, title: 'Bold', run: () => exec('bold') },
      { icon: Italic, title: 'Italic', run: () => exec('italic') },
      { icon: Underline, title: 'Underline', run: () => exec('underline') },
    ],
    [
      { icon: Heading2, title: 'Heading', run: () => exec('formatBlock', 'h2') },
      { icon: Heading3, title: 'Sub-heading', run: () => exec('formatBlock', 'h3') },
      { icon: List, title: 'Bullet list', run: () => exec('insertUnorderedList') },
      { icon: ListOrdered, title: 'Numbered list', run: () => exec('insertOrderedList') },
    ],
    [
      { icon: AlignLeft, title: 'Align left', run: () => align('Left') },
      { icon: AlignCenter, title: 'Align center', run: () => align('Center') },
      { icon: AlignRight, title: 'Align right', run: () => align('Right') },
    ],
    [
      { icon: Link2, title: 'Insert link', run: insertLink },
      { icon: Table, title: 'Insert table (choose size)', run: insertTable },
      { icon: ImageIcon, title: 'Insert image (≤500 KB, choose size)', run: pickImage },
      { icon: Video, title: 'Insert video (YouTube/Vimeo or direct URL)', run: insertVideo },
      { icon: Music, title: 'Insert audio (direct URL)', run: insertAudio },
    ],
  ]

  return (
    <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border-input)', background: 'var(--bg-input)' }}>
      <div className="flex items-center gap-0.5 flex-wrap px-2 py-1.5" style={{ borderBottom: '1px solid var(--border)' }}>
        {GROUPS.map((group, gi) => (
          <div key={gi} className="flex items-center gap-0.5">
            {gi > 0 && <span className="w-px h-4 mx-1" style={{ background: 'var(--border)' }} />}
            {group.map(({ icon: Icon, title, run }) => (
              <button key={title} type="button" title={title}
                onMouseDown={e => e.preventDefault() /* keep selection */}
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
        onInput={emit}
        onBlur={emit}
        data-placeholder={placeholder}
        className="rte-body px-4 py-3 text-sm outline-none"
        style={{ minHeight, color: 'var(--text-h)' }}
        suppressContentEditableWarning
      />
      <input ref={fileRef} type="file" accept="image/*" className="hidden" onChange={insertImage} />
    </div>
  )
}
