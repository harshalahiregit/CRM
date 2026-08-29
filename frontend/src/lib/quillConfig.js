// Shared rich-text ("notepad") config for every ReactQuill editor in the app.
//
// Quill defaults to CLASS-based formatting (ql-align-center, ql-size-large, …),
// but our server-side HtmlSanitizer strips class attributes — so alignment, size
// and colour would silently vanish on save. Registering the STYLE attributors
// makes Quill emit inline `style="…"` instead, which the sanitizer's allowlist
// (color, background-color, font-size, text-align, …) keeps. Registered once here
// and imported wherever an editor lives, so every editor behaves the same.
import { Quill } from 'react-quill'
import { compressImage } from './imageCompress'

const SizeStyle = Quill.import('attributors/style/size')
SizeStyle.whitelist = ['12px', '14px', '16px', '18px', '24px', '32px']

const AlignStyle = Quill.import('attributors/style/align')
const ColorStyle = Quill.import('attributors/style/color')
const BackgroundStyle = Quill.import('attributors/style/background')

Quill.register(SizeStyle, true)
Quill.register(AlignStyle, true)
Quill.register(ColorStyle, true)
Quill.register(BackgroundStyle, true)

// Quill's snow theme only labels its BUILT-IN size values (small/large/huge); our
// px whitelist has no matching CSS, so every size option fell back to the default
// "Normal" label (that's the "Normal ×5" dropdown). Inject the labels once, in a
// design-token-free way, so every rich editor across the app shows real sizes.
if (typeof document !== 'undefined' && !document.getElementById('quill-size-labels')) {
  const el = document.createElement('style')
  el.id = 'quill-size-labels'
  el.textContent = [
    // the picker's own label (collapsed state) and each item in the open list
    ...SizeStyle.whitelist.map((v) => {
      const n = v.replace('px', '')
      return `.ql-snow .ql-picker.ql-size .ql-picker-label[data-value="${v}"]::before,` +
             `.ql-snow .ql-picker.ql-size .ql-picker-item[data-value="${v}"]::before{content:"${n}"}`
    }),
    // the no-value default reads "Normal" (the app's base body size)
    `.ql-snow .ql-picker.ql-size .ql-picker-label:not([data-value])::before,` +
    `.ql-snow .ql-picker.ql-size .ql-picker-item:not([data-value])::before{content:"Normal"}`,
  ].join('\n')
  document.head.appendChild(el)
}

// Quill 1.3.7 does NOT insert images pasted (Ctrl+V a screenshot) or dragged in
// — they're silently dropped. This module adds that, and compresses on the way
// in, so pasted/dropped images work AND stay small. Registered globally and
// enabled per-editor via `imageCompressor: true`.
class ImageCompressor {
  constructor(quill) {
    this.quill = quill
    quill.root.addEventListener('paste', (e) => this.onFiles(e, e.clipboardData), true)
    quill.root.addEventListener('drop', (e) => this.onFiles(e, e.dataTransfer), true)
  }

  async onFiles(e, transfer) {
    const files = transfer && transfer.files
      ? Array.from(transfer.files).filter((f) => f.type && f.type.startsWith('image/'))
      : []
    if (!files.length) return
    // We're inserting these ourselves — stop Quill/the browser from also acting.
    e.preventDefault()
    e.stopPropagation()
    for (const file of files) {
      try {
        const dataUrl = await compressImage(file, { maxDim: 1600, quality: 0.82 })
        if (!dataUrl) continue
        const range = this.quill.getSelection(true) || { index: this.quill.getLength() }
        this.quill.insertEmbed(range.index, 'image', dataUrl, 'user')
        this.quill.setSelection(range.index + 1, 0, 'user')
      } catch { /* ignore a single bad file */ }
    }
  }
}
Quill.register('modules/imageCompressor', ImageCompressor)

// Insert-image size choice — mirrors the TPV RichTextEditor "notepad": on insert
// the user picks Small / Medium / Full and the image embeds at that width. The
// values are percentages the server HtmlSanitizer keeps (img `width` attribute,
// pattern \d{1,4}%?), so the size survives save + reload.
const IMAGE_WIDTHS = { Small: '33%', Medium: '66%', Full: '100%' }

function resolveImageWidth(choice) {
  const key = Object.keys(IMAGE_WIDTHS).find((k) => k.toLowerCase() === String(choice || '').trim().toLowerCase())
  return IMAGE_WIDTHS[key || 'Full']
}

// Custom image button: read the picked file, ASK THE SIZE, DOWNSCALE + RECOMPRESS
// it, then embed the (now small) result at the chosen width. Quill's default
// handler embeds the raw file as base64 at full width — a few phone photos would
// bloat the stored HTML into megabytes and there'd be no way to shrink one. Bound
// as a toolbar handler so `this` is the toolbar module and `this.quill` is the
// editor it belongs to (shared safely across every editor using RICH_MODULES).
export function quillImageHandler() {
  const quill = this.quill
  const input = document.createElement('input')
  input.setAttribute('type', 'file')
  input.setAttribute('accept', 'image/*')
  input.click()
  input.onchange = async () => {
    const file = input.files && input.files[0]
    if (!file) return
    // Ask the size the same way the TPV notepad does (Small / Medium / Full).
    const width = resolveImageWidth(window.prompt('Image size — Small / Medium / Full', 'Full') || 'Full')
    try {
      const dataUrl = await compressImage(file, { maxDim: 1600, quality: 0.82 })
      if (!dataUrl) return
      const range = quill.getSelection(true) || { index: quill.getLength() }
      // Paste as HTML so the width attribute rides along on the <img> — the blot
      // keeps `width` (in RICH_FORMATS) and the sanitizer keeps the attribute.
      quill.clipboard.dangerouslyPasteHTML(range.index, `<img src="${dataUrl}" width="${width}">`, 'user')
      quill.setSelection(range.index + 1, 0, 'user')
    } catch { /* ignore — a failed compress just means no insert */ }
  }
}

// A full "notepad" toolbar: headings, sizes, weight/style, colour + highlight,
// lists, alignment, quote/code, links and inline images.
export const RICH_MODULES = {
  toolbar: {
    container: [
      [{ header: [2, 3, false] }],
      [{ size: SizeStyle.whitelist }],
      ['bold', 'italic', 'underline', 'strike'],
      [{ color: [] }, { background: [] }],
      [{ list: 'ordered' }, { list: 'bullet' }],
      [{ align: [] }],
      ['blockquote', 'code-block'],
      ['link', 'image'],
      ['clean'],
    ],
    handlers: { image: quillImageHandler },
  },
  imageCompressor: true,
}

export const RICH_FORMATS = [
  'header', 'size', 'bold', 'italic', 'underline', 'strike',
  'color', 'background', 'list', 'bullet', 'indent', 'align',
  'blockquote', 'code-block', 'link', 'image',
  // Keep the inserted image's chosen size (Small/Medium/Full) — without these in
  // the whitelist Quill strips the width/height off the <img> on the next update.
  'width', 'height',
]
