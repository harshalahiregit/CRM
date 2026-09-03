import { useEffect, useRef, useState } from 'react'
import { Camera, PenLine, MapPin, RotateCcw, Check } from 'lucide-react'
import { S } from './MedicalBits'

/**
 * The three captures that make a prescription verifiable: the doctor's drawn
 * signature, a photo taken by the camera at sign-time, and where the device was
 * standing.
 *
 * All three are optional at the field level and captured together here, because
 * they only mean anything as a set — a signature with no place and no moment is
 * just a picture of a name.
 *
 * The camera is best-effort: browsers refuse it without HTTPS and without the
 * user's consent, and a doctor in a clinic with no camera still has to be able
 * to finish the form. So a refusal falls back to picking a photo from the
 * device rather than blocking the examination.
 */
export default function CaptureBlock({ value, onChange }) {
  const { signature_data, capture_photo, geo_location } = value

  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(230px, 1fr))', gap: 14 }}>
      <SignaturePad value={signature_data} onChange={v => onChange({ ...value, signature_data: v })} />
      <CameraCapture value={capture_photo} onChange={v => onChange({ ...value, capture_photo: v })} />
      <GeoCapture value={geo_location} onChange={v => onChange({ ...value, geo_location: v })} />
    </div>
  )
}

/* ── Signature ───────────────────────────────────────────────────────────── */

function SignaturePad({ value, onChange }) {
  const canvasRef = useRef(null)
  const drawing = useRef(false)

  const point = (e) => {
    const canvas = canvasRef.current
    const rect = canvas.getBoundingClientRect()
    const touch = e.touches?.[0]
    return {
      x: ((touch?.clientX ?? e.clientX) - rect.left) * (canvas.width / rect.width),
      y: ((touch?.clientY ?? e.clientY) - rect.top) * (canvas.height / rect.height),
    }
  }

  const start = (e) => {
    e.preventDefault()
    drawing.current = true
    const ctx = canvasRef.current.getContext('2d')
    const { x, y } = point(e)
    ctx.beginPath()
    ctx.moveTo(x, y)
  }

  const move = (e) => {
    if (!drawing.current) return
    e.preventDefault()
    const ctx = canvasRef.current.getContext('2d')
    const { x, y } = point(e)
    ctx.lineWidth = 2
    ctx.lineCap = 'round'
    ctx.strokeStyle = '#111827'
    ctx.lineTo(x, y)
    ctx.stroke()
  }

  const end = () => {
    if (!drawing.current) return
    drawing.current = false
    onChange(canvasRef.current.toDataURL('image/png'))
  }

  const clear = () => {
    const canvas = canvasRef.current
    canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height)
    onChange('')
  }

  return (
    <Panel title="Signature" icon={PenLine} done={!!value}>
      <canvas
        ref={canvasRef}
        width={520} height={170}
        onMouseDown={start} onMouseMove={move} onMouseUp={end} onMouseLeave={end}
        onTouchStart={start} onTouchMove={move} onTouchEnd={end}
        style={{
          width: '100%', height: 120, background: '#fff', borderRadius: 8,
          border: '1px dashed var(--border)', cursor: 'crosshair', touchAction: 'none',
        }}
      />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 6 }}>
        <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>Sign inside the box</span>
        <button type="button" onClick={clear} style={{ ...S.btn, padding: '4px 10px', fontSize: 11.5 }}>
          <RotateCcw size={12} /> Clear
        </button>
      </div>
    </Panel>
  )
}

/* ── Camera ──────────────────────────────────────────────────────────────── */

function CameraCapture({ value, onChange }) {
  const videoRef = useRef(null)
  const streamRef = useRef(null)
  const [live, setLive] = useState(false)
  const [denied, setDenied] = useState(false)

  // A live camera left running after the form closes is a light on someone's
  // laptop nobody asked for — stop the tracks on the way out, always.
  useEffect(() => () => stop(), [])

  const stop = () => {
    streamRef.current?.getTracks?.().forEach(t => t.stop())
    streamRef.current = null
    setLive(false)
  }

  const start = async () => {
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false })
      streamRef.current = stream
      setLive(true)
      setDenied(false)
      requestAnimationFrame(() => { if (videoRef.current) videoRef.current.srcObject = stream })
    } catch {
      // No camera, no permission, or no secure context — say so and offer the
      // fallback rather than leaving a dead button.
      setDenied(true)
    }
  }

  const snap = () => {
    const video = videoRef.current
    if (!video) return
    const canvas = document.createElement('canvas')
    canvas.width = video.videoWidth || 480
    canvas.height = video.videoHeight || 360
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height)
    onChange(canvas.toDataURL('image/png'))
    stop()
  }

  const fromFile = (file) => {
    if (!file) return
    const reader = new FileReader()
    reader.onload = ev => onChange(ev.target.result)
    reader.readAsDataURL(file)
  }

  return (
    <Panel title="Photo at sign-time" icon={Camera} done={!!value}>
      <div style={{
        height: 120, borderRadius: 8, overflow: 'hidden', background: '#0b1020',
        display: 'grid', placeItems: 'center', border: '1px dashed var(--border)',
      }}>
        {value ? (
          <img src={value} alt="capture" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
        ) : live ? (
          <video ref={videoRef} autoPlay playsInline muted style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
        ) : (
          <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
            {denied ? 'Camera unavailable — attach a photo instead' : 'No photo yet'}
          </span>
        )}
      </div>

      <div style={{ display: 'flex', gap: 6, marginTop: 6, flexWrap: 'wrap' }}>
        {value ? (
          <button type="button" onClick={() => onChange('')} style={{ ...S.btn, padding: '4px 10px', fontSize: 11.5 }}>
            <RotateCcw size={12} /> Retake
          </button>
        ) : live ? (
          <>
            <button type="button" onClick={snap} style={{ ...S.btnPrimary, padding: '4px 12px', fontSize: 11.5 }}>
              <Camera size={12} /> Capture
            </button>
            <button type="button" onClick={stop} style={{ ...S.btn, padding: '4px 10px', fontSize: 11.5 }}>Cancel</button>
          </>
        ) : (
          <>
            <button type="button" onClick={start} style={{ ...S.btn, padding: '4px 10px', fontSize: 11.5 }}>
              <Camera size={12} /> Open camera
            </button>
            <label style={{ ...S.btn, padding: '4px 10px', fontSize: 11.5 }}>
              Attach
              <input type="file" accept="image/*" capture="user" onChange={e => fromFile(e.target.files?.[0])} style={{ display: 'none' }} />
            </label>
          </>
        )}
      </div>
    </Panel>
  )
}

/* ── Location ────────────────────────────────────────────────────────────── */

function GeoCapture({ value, onChange }) {
  const [state, setState] = useState('idle')

  // Asked for once, on open: the location that matters is where the examination
  // happened, and a doctor should not have to remember to press a button for the
  // certificate to be verifiable.
  useEffect(() => { if (!value) locate() }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const locate = () => {
    if (!navigator.geolocation) { setState('unavailable'); return }
    setState('locating')
    navigator.geolocation.getCurrentPosition(
      pos => {
        onChange(`${pos.coords.latitude.toFixed(6)},${pos.coords.longitude.toFixed(6)}`)
        setState('done')
      },
      () => setState('denied'),
      { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 },
    )
  }

  return (
    <Panel title="Location" icon={MapPin} done={!!value}>
      <div style={{
        height: 120, borderRadius: 8, border: '1px dashed var(--border)',
        display: 'grid', placeItems: 'center', padding: 10, textAlign: 'center',
      }}>
        {value ? (
          <div>
            <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>{value}</div>
            <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 4 }}>
              The certificate will name the area this resolves to.
            </div>
          </div>
        ) : (
          <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
            {state === 'locating' ? 'Locating…'
              : state === 'denied' ? 'Location permission refused'
              : state === 'unavailable' ? 'Location not available on this device'
              : 'Not captured'}
          </span>
        )}
      </div>
      <button type="button" onClick={locate} style={{ ...S.btn, padding: '4px 10px', fontSize: 11.5, marginTop: 6 }}>
        <MapPin size={12} /> {value ? 'Update' : 'Capture location'}
      </button>
    </Panel>
  )
}

/* ── Chrome ──────────────────────────────────────────────────────────────── */

function Panel({ title, icon: Icon, done, children }) {
  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 6 }}>
        <Icon size={13} color={done ? '#10b981' : 'var(--text-muted)'} />
        <span style={{ ...S.label, marginBottom: 0 }}>{title}</span>
        {done && <Check size={13} color="#10b981" />}
      </div>
      {children}
    </div>
  )
}
