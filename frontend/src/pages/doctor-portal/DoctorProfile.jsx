import { useEffect, useRef, useState } from 'react'
import { useOutletContext } from 'react-router-dom'
import { UserCog, RotateCcw, Save, Camera } from 'lucide-react'
import { useToast } from '@/hooks/useToast'
import { medicalApi } from '@/services/medicalApi'
import { S } from '@/components/medical/MedicalBits'

/**
 * The doctor's practising identity — what gets stamped onto every certificate
 * they sign.
 *
 * The licence number is the field that matters: without it the portal refuses to
 * issue, because a prescription nobody can trace back to a registered doctor is
 * not worth the paper. The signature is drawn once here and reused, rather than
 * being re-drawn on every examination.
 */
/**
 * Read a chosen image into a data URL.
 *
 * The same shape the signature canvas produces, so the server decodes both
 * through one path rather than growing a second upload endpoint for one field.
 */
function readImage(file, onDone) {
  if (!file) return
  const reader = new FileReader()
  reader.onload = () => onDone(String(reader.result || ''))
  reader.readAsDataURL(file)
}

export default function DoctorProfile() {
  const { me, refreshMe } = useOutletContext()
  const toast = useToast()
  const canvasRef = useRef(null)
  const drawing = useRef(false)

  const [form, setForm] = useState({
    license_no: '', council: '', qualification: '', designation: '',
    clinic_name: '', clinic_address: '', phone: '',
  })
  const [signature, setSignature] = useState('')
  // The doctor's own photograph. The column existed from the start but
  // nothing could set it, so a profile could never actually be completed.
  const [photo, setPhoto] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    const p = me?.profile
    if (!p) return
    setForm({
      license_no: p.license_no || '', council: p.council || '',
      qualification: p.qualification || '', designation: p.designation || '',
      clinic_name: p.clinic_name || '', clinic_address: p.clinic_address || '',
      phone: p.phone || '',
    })
  }, [me])

  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))

  /* ── Signature pad ──────────────────────────────────────────────────── */

  const point = (e) => {
    const canvas = canvasRef.current
    const rect = canvas.getBoundingClientRect()
    const touch = e.touches?.[0]
    return {
      x: ((touch?.clientX ?? e.clientX) - rect.left) * (canvas.width / rect.width),
      y: ((touch?.clientY ?? e.clientY) - rect.top) * (canvas.height / rect.height),
    }
  }
  const start = (e) => { e.preventDefault(); drawing.current = true; const c = canvasRef.current.getContext('2d'); const { x, y } = point(e); c.beginPath(); c.moveTo(x, y) }
  const move  = (e) => {
    if (!drawing.current) return
    e.preventDefault()
    const c = canvasRef.current.getContext('2d')
    const { x, y } = point(e)
    c.lineWidth = 2; c.lineCap = 'round'; c.strokeStyle = '#111827'
    c.lineTo(x, y); c.stroke()
  }
  const end = () => { if (!drawing.current) return; drawing.current = false; setSignature(canvasRef.current.toDataURL('image/png')) }
  const clear = () => {
    const c = canvasRef.current
    c.getContext('2d').clearRect(0, 0, c.width, c.height)
    setSignature('')
  }

  const save = async () => {
    setBusy(true)
    try {
      await medicalApi.doctor.updateProfile({
        ...form,
        signature_data: signature || undefined,
        photo_data: photo || undefined,
      })
      await refreshMe?.()
      toast.success('Profile saved.')
    } catch (e) { toast.error(e) } finally { setBusy(false) }
  }

  const stored = me?.profile?.signature_path
  const storedPhoto = me?.profile?.photo_path

  return (
    <div style={{ maxWidth: 780 }}>
      <header style={{ marginBottom: 16 }}>
        <h1 style={{ margin: 0, fontSize: 22, fontWeight: 900, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 8 }}>
          <UserCog size={20} /> My profile
        </h1>
        <p style={{ margin: '4px 0 0', color: 'var(--text-muted)', fontSize: 12.5 }}>
          These details are printed on every certificate you sign.
        </p>
      </header>

      <section className="pr-glass" style={{ padding: 16, borderRadius: 14, marginBottom: 14 }}>
        <Row>
          <Field label="Licence number" hint="Required before you can issue">
            <input value={form.license_no} onChange={e => set('license_no', e.target.value)} placeholder="MH-123456" style={S.input} />
          </Field>
          <Field label="Council">
            <input value={form.council} onChange={e => set('council', e.target.value)} placeholder="Maharashtra Medical Council" style={S.input} />
          </Field>
        </Row>
        <Row>
          <Field label="Qualification">
            <input value={form.qualification} onChange={e => set('qualification', e.target.value)} placeholder="MBBS, AFIH" style={S.input} />
          </Field>
          <Field label="Designation">
            <input value={form.designation} onChange={e => set('designation', e.target.value)} placeholder="Occupational Health Physician" style={S.input} />
          </Field>
        </Row>
        <Row>
          <Field label="Clinic / hospital">
            <input value={form.clinic_name} onChange={e => set('clinic_name', e.target.value)} style={S.input} />
          </Field>
          <Field label="Phone">
            <input value={form.phone} onChange={e => set('phone', e.target.value)} style={S.input} />
          </Field>
        </Row>
        <Field label="Clinic address">
          <input value={form.clinic_address} onChange={e => set('clinic_address', e.target.value)} style={S.input} />
        </Field>
      </section>

      <section className="pr-glass" style={{ padding: 16, borderRadius: 14, marginBottom: 14 }}>
        <h2 style={{ margin: '0 0 8px', fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
          Signature
        </h2>
        {stored && !signature && (
          <div style={{ marginBottom: 10 }}>
            <div style={S.label}>Current</div>
            <img src={`/storage/${stored}`} alt="signature on file" style={{ height: 60, background: '#fff', borderRadius: 8, padding: 4 }} />
          </div>
        )}
        <canvas
          ref={canvasRef}
          width={640} height={180}
          onMouseDown={start} onMouseMove={move} onMouseUp={end} onMouseLeave={end}
          onTouchStart={start} onTouchMove={move} onTouchEnd={end}
          style={{ width: '100%', height: 130, background: '#fff', borderRadius: 8, border: '1px dashed var(--border)', cursor: 'crosshair', touchAction: 'none' }}
        />
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 6 }}>
          <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>Draw a new signature to replace the one on file.</span>
          <button type="button" onClick={clear} style={{ ...S.btn, padding: '4px 10px', fontSize: 11.5 }}>
            <RotateCcw size={12} /> Clear
          </button>
        </div>
      </section>

      <section className="pr-glass" style={{ padding: 16, borderRadius: 14, marginBottom: 14 }}>
        <h2 style={{ margin: '0 0 4px', fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
          Photograph
        </h2>
        <p style={{ margin: '0 0 10px', fontSize: 11.5, color: 'var(--text-muted)', lineHeight: 1.5 }}>
          Your own photograph, shown beside your name on the certificates you issue.
          Not to be confused with the camera capture taken during an examination, which is of the patient.
        </p>

        <div style={{ display: 'flex', gap: 14, alignItems: 'flex-start', flexWrap: 'wrap' }}>
          {(photo || storedPhoto) && (
            <img
              src={photo || `/storage/${storedPhoto}`}
              alt="doctor"
              style={{ width: 96, height: 96, objectFit: 'cover', borderRadius: 14, border: '1px solid var(--border)', background: '#fff' }}
            />
          )}
          <div style={{ flex: '1 1 220px', minWidth: 0 }}>
            <label style={{ ...S.btn, minHeight: 44, cursor: 'pointer', display: 'inline-flex' }}>
              <Camera size={14} /> {(photo || storedPhoto) ? 'Replace photo' : 'Choose a photo'}
              <input
                type="file"
                accept="image/*"
                onChange={e => readImage(e.target.files?.[0], setPhoto)}
                style={{ display: 'none' }}
              />
            </label>
            {photo && (
              <button type="button" onClick={() => setPhoto('')}
                style={{ ...S.btn, minHeight: 44, marginLeft: 8 }}>
                <RotateCcw size={13} /> Undo
              </button>
            )}
            <p style={{ margin: '8px 0 0', fontSize: 11, color: 'var(--text-muted)' }}>
              A head-and-shoulders photo works best. Saved when you press Save profile.
            </p>
          </div>
        </div>
      </section>

      <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
        <button onClick={save} disabled={busy} style={{ ...S.btnPrimary, padding: '10px 22px', minHeight: 46, opacity: busy ? 0.5 : 1 }}>
          <Save size={15} /> {busy ? 'Saving…' : 'Save profile'}
        </button>
      </div>
    </div>
  )
}

function Row({ children }) {
  return <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 12, marginBottom: 12 }}>{children}</div>
}

function Field({ label, hint, children }) {
  return (
    <div>
      <label style={S.label}>{label}</label>
      {children}
      {hint && <div style={{ fontSize: 10.5, color: 'var(--text-muted)', marginTop: 3 }}>{hint}</div>}
    </div>
  )
}
