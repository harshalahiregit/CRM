import { useState, useRef, useEffect } from 'react'
import { createPortal } from 'react-dom'
import { X, PenLine, Type, Upload, Check } from 'lucide-react'

const TABS = [
  { key: 'draw', label: 'Draw', icon: PenLine },
  { key: 'type', label: 'Type', icon: Type },
  { key: 'upload', label: 'Upload', icon: Upload },
]

/**
 * Draw / Type / Upload signature capture (contracts — internal + portal).
 * onSign({ method, image, name }) — image is a PNG data URL (null for pure
 * typed signatures rendered from the name, which we still rasterize).
 */
export default function SignatureModal({ title = 'Sign Contract', defaultName = '', onSign, onClose, busy = false }) {
  const [tab, setTab] = useState('draw')
  const [name, setName] = useState(defaultName)
  const [email, setEmail] = useState('')
  const [typed, setTyped] = useState('')
  const [uploaded, setUploaded] = useState(null)
  const [hasDrawn, setHasDrawn] = useState(false)
  const canvasRef = useRef(null)
  const drawing = useRef(false)

  // Canvas setup (DPR-scaled so strokes stay crisp)
  useEffect(() => {
    if (tab !== 'draw') return
    const c = canvasRef.current
    if (!c) return
    const dpr = window.devicePixelRatio || 1
    const rect = c.getBoundingClientRect()
    c.width = rect.width * dpr
    c.height = rect.height * dpr
    const ctx = c.getContext('2d')
    ctx.scale(dpr, dpr)
    ctx.strokeStyle = '#1e293b'
    ctx.lineWidth = 2
    ctx.lineCap = 'round'
    ctx.lineJoin = 'round'
  }, [tab])

  const pos = (e) => {
    const rect = canvasRef.current.getBoundingClientRect()
    return { x: e.clientX - rect.left, y: e.clientY - rect.top }
  }
  const start = (e) => { drawing.current = true; const ctx = canvasRef.current.getContext('2d'); const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y) }
  const move = (e) => { if (!drawing.current) return; const ctx = canvasRef.current.getContext('2d'); const p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); setHasDrawn(true) }
  const end = () => { drawing.current = false }
  const clear = () => { const c = canvasRef.current; c.getContext('2d').clearRect(0, 0, c.width, c.height); setHasDrawn(false) }

  const onFile = (e) => {
    const f = e.target.files?.[0]
    if (!f) return
    if (f.size > 1024 * 1024) return alert('Signature image must be under 1MB')
    const reader = new FileReader()
    reader.onload = () => setUploaded(reader.result)
    reader.readAsDataURL(f)
  }

  const typedToImage = () => {
    const c = document.createElement('canvas')
    c.width = 500; c.height = 140
    const ctx = c.getContext('2d')
    ctx.fillStyle = '#1e293b'
    ctx.font = 'italic 48px "Segoe Script", "Brush Script MT", cursive'
    ctx.textBaseline = 'middle'
    ctx.fillText(typed || name, 24, 70)
    return c.toDataURL('image/png')
  }

  const submit = () => {
    if (!name.trim()) return alert('Please enter the signer name')
    let image = null
    if (tab === 'draw') {
      if (!hasDrawn) return alert('Please draw your signature')
      image = canvasRef.current.toDataURL('image/png')
    } else if (tab === 'type') {
      if (!(typed || name).trim()) return alert('Type your signature')
      image = typedToImage()
    } else {
      if (!uploaded) return alert('Upload a signature image')
      image = uploaded
    }
    onSign({ method: tab, image, name: name.trim(), email: email.trim() || undefined })
  }

  return createPortal(
    <>
      <div className="drawer-backdrop" />
      <div className="drawer-panel" style={{ width: 'min(520px, 96vw)' }}>
        <div className="drawer-header">
          <h2 className="font-black text-lg" style={{ color: 'var(--text-h)' }}>{title}</h2>
          <button onClick={onClose} className="w-9 h-9 rounded-xl flex items-center justify-center hover:bg-[rgba(239,68,68,0.08)]" style={{ border: '1px solid var(--border)' }}>
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        <div className="drawer-body space-y-4">
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Signer Full Name *</label><input className="input-3d text-sm" value={name} onChange={e => setName(e.target.value)} /></div>
            <div><label className="label">Signer Email</label><input className="input-3d text-sm" placeholder="for the audit trail" value={email} onChange={e => setEmail(e.target.value)} /></div>
          </div>

          <div className="flex rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
            {TABS.map(({ key, label, icon: Icon }) => (
              <button key={key} onClick={() => setTab(key)} className="flex-1 flex items-center justify-center gap-1.5 py-2 text-xs font-bold transition-colors"
                style={{ background: tab === key ? 'rgba(124,58,237,0.12)' : 'transparent', color: tab === key ? 'var(--accent)' : 'var(--text-muted)' }}>
                <Icon size={13} /> {label}
              </button>
            ))}
          </div>

          {tab === 'draw' && (
            <div>
              <canvas
                ref={canvasRef}
                className="w-full rounded-xl touch-none"
                style={{ height: 160, background: '#fff', border: '1px dashed var(--border-purple)', cursor: 'crosshair' }}
                onPointerDown={start} onPointerMove={move} onPointerUp={end} onPointerLeave={end}
              />
              <button onClick={clear} className="text-xs font-bold mt-1.5" style={{ color: 'var(--accent)' }}>Clear</button>
            </div>
          )}

          {tab === 'type' && (
            <div>
              <input className="input-3d text-sm" placeholder="Type your signature (defaults to the name above)" value={typed} onChange={e => setTyped(e.target.value)} />
              <div className="mt-2 rounded-xl flex items-center px-6" style={{ height: 100, background: '#fff', border: '1px dashed var(--border-purple)' }}>
                <span style={{ fontFamily: '"Segoe Script","Brush Script MT",cursive', fontSize: 34, fontStyle: 'italic', color: '#1e293b' }}>{typed || name || 'Signature preview'}</span>
              </div>
            </div>
          )}

          {tab === 'upload' && (
            <div>
              <input type="file" accept="image/png,image/jpeg" onChange={onFile} className="text-xs" style={{ color: 'var(--text-muted)' }} />
              {uploaded && <img src={uploaded} alt="signature" className="mt-2 rounded-xl" style={{ maxHeight: 110, background: '#fff', border: '1px dashed var(--border-purple)', padding: 8 }} />}
              <p className="text-[11px] mt-1.5" style={{ color: 'var(--text-muted)' }}>PNG/JPG, max 1MB. A photo of your handwritten signature works well.</p>
            </div>
          )}
        </div>

        <div className="drawer-footer">
          <button onClick={onClose} className="flex-1 py-3 rounded-2xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
          <button onClick={submit} disabled={busy} className="flex-[2] py-3 rounded-2xl text-sm font-bold text-white flex items-center justify-center gap-2 disabled:opacity-60" style={{ background: 'linear-gradient(135deg,#10b981,#059669)' }}>
            <Check size={14} /> {busy ? 'Signing…' : 'Apply Signature'}
          </button>
        </div>
      </div>
    </>,
    document.body,
  )
}
