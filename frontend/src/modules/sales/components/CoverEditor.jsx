import { useRef } from 'react'
import { Image as ImageIcon, X, LayoutTemplate } from 'lucide-react'

/**
 * Cover page (Page 1) editor — a main image, a title and a heading shown
 * before the proposal's content pages. The image is stored inline as a base64
 * data URL (≤1MB, PDF-friendly); title/heading are plain text. The server
 * re-validates the image scheme and strips any markup from the text.
 */
const MAX_COVER_IMAGE = 1024 * 1024

const BLANK = { enabled: true, image: '', title: '', heading: '' }

export default function CoverEditor({ value, onChange }) {
  const cover = value || BLANK
  const fileRef = useRef(null)
  const set = (patch) => onChange({ ...cover, ...patch })

  const onFile = (e) => {
    const f = e.target.files?.[0]
    e.target.value = ''
    if (!f) return
    if (!f.type.startsWith('image/')) return alert('Only images are allowed')
    if (f.size > MAX_COVER_IMAGE) return alert('Image too large — max 1 MB (compress it first)')
    const r = new FileReader()
    r.onload = () => set({ image: r.result })
    r.readAsDataURL(f)
  }

  return (
    <div className="rounded-2xl overflow-hidden mb-5" style={{ border: '1px solid var(--border)' }}>
      <div className="flex items-center justify-between px-4 py-3" style={{ background: 'rgba(124,58,237,0.05)', borderBottom: cover.enabled ? '1px solid var(--border)' : 'none' }}>
        <div className="flex items-center gap-2">
          <LayoutTemplate size={15} style={{ color: 'var(--accent, #a78bfa)' }} />
          <div>
            <p className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>Cover Page</p>
            <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>The first page the client sees — before your content</p>
          </div>
        </div>
        <label className="flex items-center gap-2 text-xs font-bold cursor-pointer" style={{ color: cover.enabled ? '#10b981' : 'var(--text-muted)' }}>
          <input type="checkbox" checked={!!cover.enabled} onChange={e => set({ enabled: e.target.checked })} />
          {cover.enabled ? 'On' : 'Off'}
        </label>
      </div>

      {cover.enabled && (
        <div className="p-4 grid gap-4 md:grid-cols-2">
          {/* Image */}
          <div>
            <label className="label">Main Image</label>
            {cover.image ? (
              <div className="relative rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
                <img src={cover.image} alt="cover" style={{ width: '100%', maxHeight: 200, objectFit: 'cover' }} />
                <button type="button" onClick={() => set({ image: '' })}
                  className="absolute top-2 right-2 w-7 h-7 rounded-lg flex items-center justify-center"
                  style={{ background: 'rgba(0,0,0,0.55)', color: '#fff' }}>
                  <X size={14} />
                </button>
              </div>
            ) : (
              <button type="button" onClick={() => fileRef.current?.click()}
                className="w-full rounded-xl flex flex-col items-center justify-center gap-2 py-8 transition-colors hover:bg-[rgba(124,58,237,0.04)]"
                style={{ border: '1.5px dashed var(--border-purple, var(--border))', color: 'var(--text-muted)' }}>
                <ImageIcon size={22} style={{ color: 'var(--accent, #a78bfa)' }} />
                <span className="text-xs font-semibold">Upload cover image (≤1 MB)</span>
              </button>
            )}
            <input ref={fileRef} type="file" accept="image/*" className="hidden" onChange={onFile} />
          </div>

          {/* Title + heading */}
          <div className="space-y-3">
            <div>
              <label className="label">Title</label>
              <input className="input-3d text-sm" placeholder="e.g. Proposal" value={cover.title || ''} onChange={e => set({ title: e.target.value })} />
            </div>
            <div>
              <label className="label">Heading</label>
              <input className="input-3d text-sm" placeholder='e.g. "Proposal for Construction Project"' value={cover.heading || ''} onChange={e => set({ heading: e.target.value })} />
            </div>
            <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>The title sits small above the big heading on the cover.</p>
          </div>
        </div>
      )}
    </div>
  )
}
