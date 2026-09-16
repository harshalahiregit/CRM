import { useState } from 'react'
import { X, Upload, Download, FileSpreadsheet, AlertTriangle } from 'lucide-react'
import Modal from '@/components/ui/Modal'
import { useToast } from '@/hooks/useToast'
import { S } from './MedicalBits'

/**
 * The External Medical Flow's two intake paths.
 *
 * Both are here together because they are the same task at two sizes: filing
 * certificates a third-party doctor signed. One worker, or a sheet of them.
 *
 * Neither modal closes on a backdrop click — they are data-entry forms, and a
 * stray click discarding a filled certificate form is the kind of small betrayal
 * people stop trusting software over.
 */

const FITNESS = ['Fit', 'Fit_With_Restrictions', 'Unfit', 'Pending']

/* ── One certificate ─────────────────────────────────────────────────────── */

export function SingleCertificateModal({ open, onClose, workers = [], onSubmit, presetWorkerId }) {
  const toast = useToast()
  const [busy, setBusy] = useState(false)
  const [form, setForm] = useState({
    worker_id: presetWorkerId || '',
    fitness_status: 'Fit',
    exam_date: new Date().toISOString().slice(0, 10),
    valid_until: '',
    examiner_name: '',
    doctor_license_no: '',
    clinic_name: '',
    blood_group: '',
    restrictions: '',
    doctor_remarks: '',
    report_file: null,
  })

  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))

  const submit = async () => {
    if (!form.worker_id) return toast.error('Pick the worker this certificate belongs to.')
    if (!form.report_file) return toast.error('Attach the certificate — a record without it cannot be reviewed.')

    setBusy(true)
    try {
      const { worker_id, ...payload } = form
      await onSubmit(worker_id, payload)
      toast.success('Certificate uploaded for quality check.')
      onClose?.()
    } catch (e) { toast.error(e) } finally { setBusy(false) }
  }

  return (
    <Modal open={open} onClose={onClose} style={{ width: 'min(620px, 96vw)' }}>
      <Header title="Upload an external certificate" onClose={onClose} />

      <div style={{ padding: 16, display: 'grid', gap: 12, maxHeight: '70vh', overflowY: 'auto' }}>
        {!presetWorkerId && (
          <Field label="Worker">
            <select value={form.worker_id} onChange={e => set('worker_id', e.target.value)} style={{ ...S.select, width: '100%' }}>
              <option value="">Select a worker…</option>
              {workers.map(w => (
                <option key={w.id} value={w.id}>{w.name} {w.worker_code ? `· ${w.worker_code}` : ''}</option>
              ))}
            </select>
          </Field>
        )}

        <Row>
          <Field label="Outcome">
            <select value={form.fitness_status} onChange={e => set('fitness_status', e.target.value)} style={{ ...S.select, width: '100%' }}>
              {FITNESS.map(f => <option key={f} value={f}>{f.replace(/_/g, ' ')}</option>)}
            </select>
          </Field>
          <Field label="Examined on">
            <input type="date" value={form.exam_date} onChange={e => set('exam_date', e.target.value)} style={S.input} />
          </Field>
          <Field label="Valid until" hint="Defaults to a year after the exam">
            <input type="date" value={form.valid_until} onChange={e => set('valid_until', e.target.value)} style={S.input} />
          </Field>
        </Row>

        <Row>
          <Field label="Doctor's name">
            <input value={form.examiner_name} onChange={e => set('examiner_name', e.target.value)} placeholder="Dr A. Sharma" style={S.input} />
          </Field>
          <Field label="Licence no">
            <input value={form.doctor_license_no} onChange={e => set('doctor_license_no', e.target.value)} placeholder="MH-123456" style={S.input} />
          </Field>
          <Field label="Clinic">
            <input value={form.clinic_name} onChange={e => set('clinic_name', e.target.value)} style={S.input} />
          </Field>
        </Row>

        <Row>
          <Field label="Blood group">
            <input value={form.blood_group} onChange={e => set('blood_group', e.target.value)} placeholder="B+" style={S.input} />
          </Field>
          <Field label="Restrictions">
            <input value={form.restrictions} onChange={e => set('restrictions', e.target.value)} placeholder="No work at height" style={S.input} />
          </Field>
        </Row>

        <Field label="Certificate file (required)" hint="PDF or image, up to 10 MB">
          <input type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={e => set('report_file', e.target.files?.[0] ?? null)} style={{ ...S.input, padding: 6 }} />
        </Field>

        <Field label="Remarks">
          <textarea value={form.doctor_remarks} onChange={e => set('doctor_remarks', e.target.value)} rows={2} style={{ ...S.input, resize: 'vertical' }} />
        </Field>
      </div>

      <Footer onClose={onClose} onSubmit={submit} busy={busy} label="Upload for review" />
    </Modal>
  )
}

/* ── A sheet of certificates ─────────────────────────────────────────────── */

export function BulkCertificateModal({ open, onClose, template, onDownloadTemplate, onSubmit }) {
  const toast = useToast()
  const [busy, setBusy] = useState(false)
  const [file, setFile] = useState(null)
  const [certificates, setCertificates] = useState([])
  const [result, setResult] = useState(null)

  const close = () => { setFile(null); setCertificates([]); setResult(null); onClose?.() }

  const submit = async () => {
    if (!file) return toast.error('Choose the filled-in sheet first.')
    setBusy(true)
    try {
      const res = await onSubmit({ file, certificates })
      setResult(res?.data ?? res)
      toast.success(res?.message || 'Sheet processed.')
    } catch (e) { toast.error(e) } finally { setBusy(false) }
  }

  return (
    <Modal open={open} onClose={close} style={{ width: 'min(720px, 96vw)' }}>
      <Header title="Upload a sheet of certificates" onClose={close} />

      <div style={{ padding: 16, display: 'grid', gap: 14, maxHeight: '70vh', overflowY: 'auto' }}>
        {/* Step 1 — the template. The columns the import reads ARE the template,
            so there is no guessing about what the file should look like. */}
        <section>
          <StepTitle n={1} text="Download the template" />
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 8 }}>
            <button onClick={() => onDownloadTemplate('csv')} style={S.btn}><Download size={14} /> CSV</button>
            <button onClick={() => onDownloadTemplate('xlsx')} style={S.btn}><Download size={14} /> Excel</button>
          </div>
          {template?.notes?.length > 0 && (
            <ul style={{ margin: 0, paddingLeft: 18, color: 'var(--text-muted)', fontSize: 12, lineHeight: 1.7 }}>
              {template.notes.map((n, i) => <li key={i}>{n}</li>)}
            </ul>
          )}
        </section>

        {/* Step 2 — the filled sheet + the certificate files. */}
        <section>
          <StepTitle n={2} text="Upload the filled sheet" />
          <input type="file" accept=".csv,.xlsx,.txt" onChange={e => setFile(e.target.files?.[0] ?? null)} style={{ ...S.input, padding: 6 }} />
        </section>

        <section>
          <StepTitle n={3} text="Attach the certificate files (optional)" />
          <p style={{ margin: '0 0 6px', fontSize: 12, color: 'var(--text-muted)' }}>
            Name each file after its worker code — <code>WRK-0001.pdf</code> — and it is filed against that row.
          </p>
          <input
            type="file" multiple accept=".pdf,.jpg,.jpeg,.png"
            onChange={e => setCertificates(Array.from(e.target.files || []))}
            style={{ ...S.input, padding: 6 }}
          />
          {certificates.length > 0 && (
            <p style={{ margin: '6px 0 0', fontSize: 12, color: 'var(--text-muted)' }}>{certificates.length} file(s) selected.</p>
          )}
        </section>

        {/* The result — and, more usefully, exactly which rows did not land. */}
        {result && (
          <section className="pr-glass" style={{ padding: 12, borderRadius: 12 }}>
            <div style={{ display: 'flex', gap: 16, marginBottom: result.failed_count ? 10 : 0 }}>
              <Counted label="Imported" value={result.created_count} tone="#10b981" />
              <Counted label="Rejected" value={result.failed_count} tone={result.failed_count ? '#ef4444' : '#64748b'} />
            </div>

            {result.failed_count > 0 && (
              <>
                <div style={{ display: 'flex', alignItems: 'center', gap: 6, color: '#f59e0b', fontSize: 12, fontWeight: 700, marginBottom: 6 }}>
                  <AlertTriangle size={14} /> Fix these rows and upload them again — the rest are already in.
                </div>
                <div style={{ maxHeight: 180, overflowY: 'auto' }}>
                  <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
                    <thead>
                      <tr style={{ color: 'var(--text-muted)', textAlign: 'left' }}>
                        <th style={{ padding: '4px 8px' }}>Row</th>
                        <th style={{ padding: '4px 8px' }}>Worker</th>
                        <th style={{ padding: '4px 8px' }}>Why</th>
                      </tr>
                    </thead>
                    <tbody>
                      {(result.errors || []).map((e, i) => (
                        <tr key={i} style={{ borderTop: '1px solid var(--border)' }}>
                          <td style={{ padding: '4px 8px', color: 'var(--text-muted)' }}>{e.row}</td>
                          <td style={{ padding: '4px 8px', fontWeight: 600 }}>{e.worker_code}</td>
                          <td style={{ padding: '4px 8px', color: '#ef4444' }}>{e.error}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </>
            )}
          </section>
        )}
      </div>

      <Footer
        onClose={close}
        onSubmit={submit}
        busy={busy}
        label={result ? 'Upload another' : 'Import certificates'}
        icon={FileSpreadsheet}
      />
    </Modal>
  )
}

/* ── Shared chrome ───────────────────────────────────────────────────────── */

function Header({ title, onClose }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '14px 16px', borderBottom: '1px solid var(--border)' }}>
      <h2 style={{ margin: 0, fontSize: 15, fontWeight: 900, color: 'var(--text-h)' }}>{title}</h2>
      <button onClick={onClose} className="btn-icon" aria-label="Close"><X size={18} /></button>
    </div>
  )
}

function Footer({ onClose, onSubmit, busy, label, icon: Icon = Upload }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, padding: '12px 16px', borderTop: '1px solid var(--border)' }}>
      <button onClick={onClose} style={S.btn}>Cancel</button>
      <button onClick={onSubmit} disabled={busy} style={{ ...S.btnPrimary, opacity: busy ? 0.5 : 1 }}>
        <Icon size={14} /> {busy ? 'Working…' : label}
      </button>
    </div>
  )
}

function Row({ children }) {
  return <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 10 }}>{children}</div>
}

function Field({ label, hint, children }) {
  return (
    <div>
      <label style={S.label}>{label}</label>
      {children}
      {hint && <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 3 }}>{hint}</div>}
    </div>
  )
}

function StepTitle({ n, text }) {
  return (
    <h3 style={{ margin: '0 0 8px', fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)' }}>
      <span style={{ color: '#7C3AED' }}>{n}.</span> {text}
    </h3>
  )
}

function Counted({ label, value, tone }) {
  return (
    <div>
      <div style={S.label}>{label}</div>
      <div style={{ fontSize: 20, fontWeight: 900, color: tone }}>{value ?? 0}</div>
    </div>
  )
}
