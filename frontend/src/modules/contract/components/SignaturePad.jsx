import { useEffect, useRef, useState } from 'react'
import { PenLine, Type, Upload, Stamp, Trash2 } from 'lucide-react'
import { Overlay, ModalFooter, labelStyle, inputStyle, InfoBox } from '@/components/ui/kit3d'

/**
 * Capturing a signature, four ways.
 *
 * Draw, type, upload an image, or apply the company stamp — the brief asks for
 * all four, and they are genuinely different: a customer on a phone draws, a
 * finance head types, somebody with a scanned signature uploads, and an
 * authorised company stamp is generated rather than drawn by a person.
 *
 * This module has its own rather than importing the Sales module's: reaching
 * across a module boundary is how one team's change breaks another team's
 * screen, and Sales' contract feature is deliberately untouched here.
 *
 * ── Location is asked for, never required ───────────────────────────────
 * The brief wants the signer's location stamped on the document. The browser
 * only gives it with permission, and a refusal must never block a signature —
 * so it is requested once, in the background, and whatever comes back (or does
 * not) is sent alongside. A contract that cannot be signed because somebody
 * declined a location prompt would be a worse outcome than a missing field.
 */
export default function SignaturePad({ open, onClose, onSign, saving, partyLabel = 'Signature', companyName = 'Company' }) {
  const [method, setMethod] = useState('draw')
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [image, setImage] = useState(null)
  const [err, setErr] = useState(null)
  const [geo, setGeo] = useState(null)

  const canvasRef = useRef(null)
  const drawing = useRef(false)
  const dirty = useRef(false)

  // Asked once when the pad opens, so the answer is already in hand by the time
  // they press Sign and the prompt is not racing the submit.
  useEffect(() => {
    if (!open || !navigator.geolocation) return
    navigator.geolocation.getCurrentPosition(
      (pos) => setGeo({ latitude: +pos.coords.latitude.toFixed(7), longitude: +pos.coords.longitude.toFixed(7) }),
      () => setGeo(null),
      { timeout: 8000, maximumAge: 300000 },
    )
  }, [open])

  /* ── The drawing canvas ─────────────────────────────────────── */

  useEffect(() => {
    if (!open || method !== 'draw') return
    const c = canvasRef.current
    if (!c) return

    // Match the backing store to the CSS size, or every stroke lands offset
    // from the pointer on a high-DPI screen.
    const ratio = window.devicePixelRatio || 1
    const rect = c.getBoundingClientRect()
    c.width = rect.width * ratio
    c.height = rect.height * ratio
    const ctx = c.getContext('2d')
    ctx.scale(ratio, ratio)
    ctx.lineWidth = 2
    ctx.lineCap = 'round'
    ctx.lineJoin = 'round'
    ctx.strokeStyle = '#111827'
    dirty.current = false
  }, [open, method])

  const pos = (e) => {
    const r = canvasRef.current.getBoundingClientRect()
    const p = e.touches ? e.touches[0] : e
    return { x: p.clientX - r.left, y: p.clientY - r.top }
  }

  const start = (e) => {
    e.preventDefault()
    drawing.current = true
    const ctx = canvasRef.current.getContext('2d')
    const { x, y } = pos(e)
    ctx.beginPath()
    ctx.moveTo(x, y)
  }

  const move = (e) => {
    if (!drawing.current) return
    e.preventDefault()
    const ctx = canvasRef.current.getContext('2d')
    const { x, y } = pos(e)
    ctx.lineTo(x, y)
    ctx.stroke()
    dirty.current = true
  }

  const end = () => { drawing.current = false }

  const clear = () => {
    const c = canvasRef.current
    c.getContext('2d').clearRect(0, 0, c.width, c.height)
    dirty.current = false
  }

  /* ── Upload ─────────────────────────────────────────────────── */

  const onFile = (e) => {
    const f = e.target.files?.[0]
    if (!f) return
    // 1MB, matching what the server accepts. Larger than this is a photograph
    // of a page, not a signature, and it would be embedded in every PDF.
    if (f.size > 1024 * 1024) { setErr('That image is larger than 1MB — please use a smaller one.'); return }
    const reader = new FileReader()
    reader.onload = () => { setImage(reader.result); setErr(null) }
    reader.readAsDataURL(f)
  }

  /* ── Submit ─────────────────────────────────────────────────── */

  const submit = () => {
    setErr(null)
    if (!name.trim()) { setErr('Please type the name of the person signing.'); return }

    let img = null
    if (method === 'draw') {
      if (!dirty.current) { setErr('Draw your signature in the box before signing.'); return }
      img = canvasRef.current.toDataURL('image/png')
    }
    if (method === 'upload') {
      if (!image) { setErr('Choose a signature image to upload.'); return }
      img = image
    }

    onSign({
      method,
      name: name.trim(),
      email: email.trim() || undefined,
      image: img || undefined,
      ...(geo || {}),
    })
  }

  if (!open) return null

  const tabs = [
    { key: 'draw', label: 'Draw', icon: PenLine },
    { key: 'type', label: 'Type', icon: Type },
    { key: 'upload', label: 'Upload', icon: Upload },
    { key: 'stamp', label: 'Company stamp', icon: Stamp },
  ]

  return (
    <Overlay onClose={onClose} width={520}>
      <div style={{ padding: '18px 20px', borderBottom: '1px solid var(--border)' }}>
        <h2 style={{ fontSize: 16, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>Sign — {partyLabel}</h2>
        <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '4px 0 0' }}>
          The time, your network address{geo ? ' and location' : ''} are recorded with the signature.
        </p>
      </div>

      <div style={{ padding: 20, display: 'flex', flexDirection: 'column', gap: 14 }}>
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
          {tabs.map(({ key, label, icon: Icon }) => (
            <button key={key} type="button" onClick={() => { setMethod(key); setErr(null) }}
              style={{
                display: 'flex', alignItems: 'center', gap: 6, padding: '6px 11px', borderRadius: 8,
                fontSize: 12, fontWeight: 600, cursor: 'pointer',
                border: `1px solid ${method === key ? '#7C3AED' : 'var(--border)'}`,
                background: method === key ? 'rgba(124,58,237,.10)' : 'var(--bg-input)',
                color: method === key ? '#7C3AED' : 'var(--text-muted)',
              }}>
              <Icon size={13} /> {label}
            </button>
          ))}
        </div>

        {method === 'draw' && (
          <div>
            <label style={labelStyle}>Draw your signature</label>
            <canvas
              ref={canvasRef}
              onMouseDown={start} onMouseMove={move} onMouseUp={end} onMouseLeave={end}
              onTouchStart={start} onTouchMove={move} onTouchEnd={end}
              style={{
                width: '100%', height: 150, borderRadius: 8, cursor: 'crosshair',
                background: '#fff', border: '1px dashed var(--border)', touchAction: 'none',
              }} />
            <button type="button" onClick={clear}
              style={{ marginTop: 6, display: 'flex', alignItems: 'center', gap: 5, background: 'transparent', border: 'none', color: 'var(--text-muted)', fontSize: 11.5, cursor: 'pointer', padding: 0 }}>
              <Trash2 size={12} /> Clear
            </button>
          </div>
        )}

        {method === 'type' && (
          <div>
            <label style={labelStyle}>Your signature will appear as</label>
            <div style={{
              padding: '14px 16px', borderRadius: 8, background: '#fff', color: '#111827',
              border: '1px dashed var(--border)', fontStyle: 'italic', fontSize: 26, minHeight: 40,
            }}>
              {name || <span style={{ color: '#9ca3af', fontSize: 14, fontStyle: 'normal' }}>Type your name below</span>}
            </div>
          </div>
        )}

        {method === 'upload' && (
          <div>
            <label style={labelStyle}>Signature image (PNG or JPG, max 1MB)</label>
            <input type="file" accept="image/png,image/jpeg" onChange={onFile} style={{ ...inputStyle, padding: 8 }} />
            {image && <img src={image} alt="Signature preview"
              style={{ marginTop: 10, maxHeight: 70, background: '#fff', borderRadius: 6, padding: 6 }} />}
          </div>
        )}

        {method === 'stamp' && (
          <InfoBox tone="info">
            The authorised company stamp for <strong>{companyName}</strong> will be applied to the
            document in place of a handwritten signature. Your name below is recorded as the
            person who applied it.
          </InfoBox>
        )}

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
          <div>
            <label style={labelStyle}>Full name <span style={{ color: '#ef4444' }}>*</span></label>
            <input value={name} onChange={e => setName(e.target.value)} style={inputStyle} placeholder="Priya Sharma" />
          </div>
          <div>
            <label style={labelStyle}>Email</label>
            <input value={email} onChange={e => setEmail(e.target.value)} style={inputStyle} placeholder="priya@company.com" />
          </div>
        </div>

        {err && <div style={{ color: '#ef4444', fontSize: 12 }}>{err}</div>}

        <p style={{ fontSize: 11, color: 'var(--text-muted)', margin: 0, lineHeight: 1.5 }}>
          By signing you agree to be bound by the terms of this contract. Your signature is
          recorded with a certificate number and cannot be changed afterwards.
        </p>
      </div>

      <ModalFooter onClose={onClose} onConfirm={submit} loading={saving} confirmLabel="Sign contract" />
    </Overlay>
  )
}
