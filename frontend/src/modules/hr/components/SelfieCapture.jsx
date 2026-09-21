import { useEffect, useRef, useState } from 'react'
import { Camera, X, RotateCcw, Check, CameraOff } from 'lucide-react'
// The shared brand gradient. Inline copies of this value are guarded against
// by BannedPatternsTest: 274 of them exist and none can be restyled centrally.
import { GRAD } from '@/components/ui/brand'

/**
 * Take a photo for a punch — or say plainly that you cannot.
 *
 * "Continue without a photo" is a first-class button here, not a hidden escape.
 * Plenty of office laptops have no working camera, and the alternative to this
 * button is somebody unable to record that they came to work. The punch is
 * allowed either way; what differs is that the record carries the reason, so HR
 * sees the handful without a photo instead of every punch looking the same.
 *
 * onDone({ blob }) — a photo was taken
 * onDone({ blob: null, reason }) — no photo, and this is why
 */
export default function SelfieCapture({ onDone, onCancel, title = 'Photo for your clock-in' }) {
  const videoRef = useRef(null)
  const streamRef = useRef(null)
  const [shot, setShot] = useState(null)      // object URL of the still
  const [blob, setBlob] = useState(null)
  const [error, setError] = useState(null)
  const [starting, setStarting] = useState(true)

  useEffect(() => {
    let cancelled = false

    ;(async () => {
      try {
        const stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
          audio: false,
        })
        if (cancelled) { stream.getTracks().forEach(t => t.stop()); return }
        streamRef.current = stream
        if (videoRef.current) videoRef.current.srcObject = stream
      } catch (e) {
        // Tell these apart: refusing is a choice, a missing camera is not.
        setError(
          e?.name === 'NotAllowedError' ? 'camera declined'
            : e?.name === 'NotFoundError' ? 'no camera on this device'
              : 'camera unavailable',
        )
      } finally {
        if (!cancelled) setStarting(false)
      }
    })()

    return () => {
      cancelled = true
      streamRef.current?.getTracks().forEach(t => t.stop())
    }
  }, [])

  const stop = () => streamRef.current?.getTracks().forEach(t => t.stop())

  const capture = () => {
    const v = videoRef.current
    if (!v) return
    const canvas = document.createElement('canvas')
    canvas.width = v.videoWidth || 640
    canvas.height = v.videoHeight || 480
    const ctx = canvas.getContext('2d')
    // Mirrored, so the preview matches what people expect of themselves.
    ctx.translate(canvas.width, 0)
    ctx.scale(-1, 1)
    ctx.drawImage(v, 0, 0, canvas.width, canvas.height)
    canvas.toBlob((b) => {
      if (!b) { setError('could not capture'); return }
      setBlob(b)
      setShot(URL.createObjectURL(b))
    }, 'image/jpeg', 0.82)
  }

  const retake = () => { if (shot) URL.revokeObjectURL(shot); setShot(null); setBlob(null) }

  const confirm = () => { stop(); onDone({ blob }) }
  const skip = () => { stop(); onDone({ blob: null, reason: error || 'photo skipped' }) }

  return (
    <div className="fixed inset-0 z-[60] flex items-center justify-center p-4" style={{ background: 'rgba(0,0,0,0.7)' }}>
      <div className="rounded-2xl w-full max-w-sm overflow-hidden" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-sm font-black" style={{ color: 'var(--text-h)' }}>{title}</h2>
          <button onClick={() => { stop(); onCancel?.() }} className="p-1.5 rounded-lg" aria-label="Close">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        <div className="p-5">
          <div className="rounded-xl overflow-hidden flex items-center justify-center"
            style={{ background: 'var(--bg-input)', aspectRatio: '4 / 3' }}>
            {error ? (
              <div className="text-center px-6">
                <CameraOff size={28} style={{ color: 'var(--text-muted)' }} className="mx-auto mb-2" />
                <p className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>No photo available</p>
                <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>
                  {error}. You can still clock in — it will be recorded without a photo.
                </p>
              </div>
            ) : shot ? (
              <img src={shot} alt="Your photo" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
            ) : (
              <video ref={videoRef} autoPlay playsInline muted
                style={{ width: '100%', height: '100%', objectFit: 'cover', transform: 'scaleX(-1)' }} />
            )}
          </div>

          <div className="flex gap-2 mt-4">
            {error ? (
              <button onClick={skip} className="flex-1 py-2.5 rounded-xl text-xs font-bold text-white"
                style={{ background: GRAD }}>
                Clock in without a photo
              </button>
            ) : shot ? (
              <>
                <button onClick={retake} className="flex-1 py-2.5 rounded-xl text-xs font-bold flex items-center justify-center gap-1.5"
                  style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
                  <RotateCcw size={13} /> Retake
                </button>
                <button onClick={confirm} className="flex-[2] py-2.5 rounded-xl text-xs font-bold text-white flex items-center justify-center gap-1.5"
                  style={{ background: 'linear-gradient(135deg,#10b981,#059669)' }}>
                  <Check size={14} /> Use this photo
                </button>
              </>
            ) : (
              <>
                <button onClick={skip} className="flex-1 py-2.5 rounded-xl text-xs font-bold"
                  style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
                  Skip
                </button>
                <button onClick={capture} disabled={starting}
                  className="flex-[2] py-2.5 rounded-xl text-xs font-bold text-white flex items-center justify-center gap-1.5 disabled:opacity-60"
                  style={{ background: GRAD }}>
                  <Camera size={14} /> {starting ? 'Starting camera…' : 'Take photo'}
                </button>
              </>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
