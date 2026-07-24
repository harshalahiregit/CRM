import { useState, useRef, useEffect, useCallback } from 'react'
import { ScanLine, Camera, X, Loader2 } from 'lucide-react'
import { INV_ACCENT } from '@/services/inventoryApi'

/**
 * The scanner input. Two ways in, one output.
 *
 * 1. HARDWARE SCANNERS (the common case). A USB or Bluetooth barcode gun is a
 *    keyboard — it types the code very fast and presses Enter. So a plain text
 *    input handles them with no driver, no permissions and no library, which is
 *    why this is the default and why the field auto-focuses and re-focuses after
 *    every scan: a storekeeper holding a box cannot click into a field first.
 *
 * 2. PHONE CAMERA, via the browser's native BarcodeDetector. No library and no
 *    upload — frames never leave the device. Not every browser has it (Safari
 *    notably), so the camera button only appears where it actually works rather
 *    than offering a feature that silently fails.
 *
 * Deliberately dumb: it emits a code string and nothing else. What a code MEANS
 * is the server's answer (ScanService), because the same sticker means different
 * things while picking, counting or receiving.
 */

const hasCamera = () => typeof window !== 'undefined' && 'BarcodeDetector' in window

export default function ScanInput({
  onScan,
  placeholder = 'Scan or type a code…',
  autoFocus = true,
  busy = false,
  hint = null,
}) {
  const [value, setValue] = useState('')
  const [camOpen, setCamOpen] = useState(false)
  const inputRef = useRef(null)

  const fire = useCallback((code) => {
    const t = String(code || '').trim()
    if (!t) return
    onScan?.(t)
    setValue('')
    // Straight back to ready. The next box is already on its way.
    setTimeout(() => inputRef.current?.focus(), 10)
  }, [onScan])

  useEffect(() => {
    if (autoFocus) setTimeout(() => inputRef.current?.focus(), 50)
  }, [autoFocus])

  return (
    <div>
      <div className="flex items-center gap-2">
        <div className="relative flex-1">
          <ScanLine size={15} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: INV_ACCENT }} />
          <input
            ref={inputRef}
            value={value}
            onChange={e => setValue(e.target.value)}
            onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); fire(value) } }}
            placeholder={placeholder}
            autoComplete="off" spellCheck={false}
            className="w-full rounded-xl outline-none font-mono"
            style={{
              padding: '10px 12px 10px 34px', fontSize: 14,
              background: 'var(--bg-input)', border: `1px solid ${INV_ACCENT}`, color: 'var(--text-h)',
            }} />
          {busy && (
            <Loader2 size={14} className="animate-spin"
              style={{ position: 'absolute', right: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          )}
        </div>

        {hasCamera() && (
          <button onClick={() => setCamOpen(true)} title="Scan with the camera"
            className="flex items-center gap-1.5 text-xs font-bold px-3 py-2.5 rounded-xl shrink-0"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
            <Camera size={14} /> Camera
          </button>
        )}
      </div>

      <p className="text-[10px] mt-1.5" style={{ color: 'var(--text-muted)' }}>
        {hint || 'A barcode gun types the code and presses Enter — just scan, the field is already focused.'}
      </p>

      {camOpen && <CameraScanner onClose={() => setCamOpen(false)} onScan={code => { setCamOpen(false); fire(code) }} />}
    </div>
  )
}

/**
 * Camera scanning through the browser's own BarcodeDetector. Frames are read in
 * memory and discarded; nothing is uploaded and no library is loaded.
 */
function CameraScanner({ onScan, onClose }) {
  const videoRef = useRef(null)
  const [error, setError] = useState('')

  useEffect(() => {
    let stream = null
    let timer = null
    let stopped = false

    const start = async () => {
      try {
        // The back camera is the one pointing at the shelf.
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
        if (stopped) { stream.getTracks().forEach(t => t.stop()); return }
        if (videoRef.current) {
          videoRef.current.srcObject = stream
          await videoRef.current.play()
        }

        const detector = new window.BarcodeDetector()
        const tick = async () => {
          if (stopped || !videoRef.current) return
          try {
            const found = await detector.detect(videoRef.current)
            if (found?.length) { onScan(found[0].rawValue); return }
          } catch { /* a frame that won't decode is normal — keep looking */ }
          timer = setTimeout(tick, 250)
        }
        tick()
      } catch (e) {
        setError(e?.name === 'NotAllowedError'
          ? 'Camera permission was refused. Use the scanner or type the code instead.'
          : 'This device has no usable camera. Use the scanner or type the code instead.')
      }
    }

    start()
    return () => {
      stopped = true
      if (timer) clearTimeout(timer)
      if (stream) stream.getTracks().forEach(t => t.stop())
    }
  }, [onScan])

  return (
    <div className="fixed inset-0 z-[70] flex items-center justify-center p-4 bg-black/70" onClick={onClose}>
      <div className="w-full max-w-md rounded-2xl overflow-hidden" style={{ background: 'var(--bg-card)' }} onClick={e => e.stopPropagation()}>
        <div className="flex items-center gap-2 px-4 py-3" style={{ borderBottom: '1px solid var(--border)' }}>
          <Camera size={15} style={{ color: INV_ACCENT }} />
          <p className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>Point at the barcode</p>
          <button onClick={onClose} className="ml-auto" aria-label="Close"><X size={16} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        {error
          ? <p className="text-xs p-6" style={{ color: 'var(--color-danger-500)' }}>{error}</p>
          : (
            <div className="relative" style={{ background: '#000' }}>
              <video ref={videoRef} muted playsInline style={{ width: '100%', display: 'block', maxHeight: '60vh' }} />
              {/* A frame to aim with — scanning is faster when people know where to point. */}
              <div style={{
                position: 'absolute', inset: '22% 12%', border: `2px solid ${INV_ACCENT}`,
                borderRadius: 12, boxShadow: '0 0 0 100vmax rgba(0,0,0,0.35)',
              }} />
            </div>
          )}
      </div>
    </div>
  )
}
