import { useRef, useEffect, useCallback } from 'react'
import {
  Bold, Italic, Underline, Heading2, Heading3,
  List, ListOrdered, Link2, Table, Image as ImageIcon,
} from 'lucide-react'

/**
 * Dependency-free rich text editor (contenteditable) shared by customer notes,
 * proposal/contract pages and email bodies. Output is HTML; the server runs
 * every save through App\Support\HtmlSanitizer — client output is convenience,
 * the sanitizer is the security boundary.
 *
 * execCommand is deprecated but universally supported; it keeps us free of a
 * heavy editor dependency for the toolbar we actually need.
 */
const MAX_IMAGE_BYTES = 500 * 1024 // keep pages PDF-friendly

export default function RichTextEditor({ value = '', onChange, placeholder = 'Write here…', minHeight = 160 }) {
  const ref = useRef(null)
  const fileRef = useRef(null)

  // Only push external value in when it actually differs — otherwise typing
  // would reset the caret on every keystroke.
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

  const insertLink = () => {
    const url = window.prompt('Link URL (https://…)')
    if (!url) return
    if (!/^https?:\/\//i.test(url)) return window.alert('Only http(s) links are allowed')
    exec('createLink', url)
  }

  const insertTable = () => {
    const html =
      '<table style="width:100%"><tbody>' +
      '<tr><th>Column 1</th><th>Column 2</th></tr>' +
      '<tr><td><br></td><td><br></td></tr>' +
      '<tr><td><br></td><td><br></td></tr>' +
      '</tbody></table><p><br></p>'
    exec('insertHTML', html)
  }

  const pickImage = () => fileRef.current?.click()

  const insertImage = (e) => {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file) return
    if (!file.type.startsWith('image/')) return window.alert('Only images are allowed')
    if (file.size > MAX_IMAGE_BYTES) return window.alert('Image too large — max 500 KB (resize/compress it first)')
    const reader = new FileReader()
    reader.onload = () => exec('insertHTML', `<img src="${reader.result}" alt="" style="max-width:100%" /><p><br></p>`)
    reader.readAsDataURL(file)
  }

  const TOOLS = [
    { icon: Bold, title: 'Bold', run: () => exec('bold') },
    { icon: Italic, title: 'Italic', run: () => exec('italic') },
    { icon: Underline, title: 'Underline', run: () => exec('underline') },
    { icon: Heading2, title: 'Heading', run: () => exec('formatBlock', 'h2') },
    { icon: Heading3, title: 'Sub-heading', run: () => exec('formatBlock', 'h3') },
    { icon: List, title: 'Bullet list', run: () => exec('insertUnorderedList') },
    { icon: ListOrdered, title: 'Numbered list', run: () => exec('insertOrderedList') },
    { icon: Link2, title: 'Insert link', run: insertLink },
    { icon: Table, title: 'Insert table', run: insertTable },
    { icon: ImageIcon, title: 'Insert image (≤500 KB)', run: pickImage },
  ]

  return (
    <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border-input)', background: 'var(--bg-input)' }}>
      <div className="flex items-center gap-0.5 flex-wrap px-2 py-1.5" style={{ borderBottom: '1px solid var(--border)' }}>
        {TOOLS.map(({ icon: Icon, title, run }) => (
          <button key={title} type="button" title={title}
            onMouseDown={e => e.preventDefault() /* keep selection */}
            onClick={run}
            className="w-7 h-7 rounded-lg flex items-center justify-center hover:bg-[rgba(124,58,237,0.1)] transition-colors">
            <Icon size={13} style={{ color: 'var(--text-muted)' }} />
          </button>
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
