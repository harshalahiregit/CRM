// Shared rich-text ("notepad") config for every ReactQuill editor in the app.
//
// Quill defaults to CLASS-based formatting (ql-align-center, ql-size-large, …),
// but our server-side HtmlSanitizer strips class attributes — so alignment, size
// and colour would silently vanish on save. Registering the STYLE attributors
// makes Quill emit inline `style="…"` instead, which the sanitizer's allowlist
// (color, background-color, font-size, text-align, …) keeps. Registered once here
// and imported wherever an editor lives, so every editor behaves the same.
import { Quill } from 'react-quill'

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

// A full "notepad" toolbar: headings, sizes, weight/style, colour + highlight,
// lists, alignment, quote/code, links and inline images.
export const RICH_MODULES = {
  toolbar: [
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
}

export const RICH_FORMATS = [
  'header', 'size', 'bold', 'italic', 'underline', 'strike',
  'color', 'background', 'list', 'bullet', 'align',
  'blockquote', 'code-block', 'link', 'image',
]
