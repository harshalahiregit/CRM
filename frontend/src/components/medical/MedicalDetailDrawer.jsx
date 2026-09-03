import { useEffect, useState } from 'react'
import { FileText, Download, CheckCircle2, XCircle, PauseCircle, Upload } from 'lucide-react'
import Drawer from '@/components/ui/Drawer'
import { useToast } from '@/hooks/useToast'
import MedicalTimeline from './MedicalTimeline'
import { S, humanise, FitnessPill, QcPill, HealthScore } from './MedicalBits'

/**
 * One certificate, opened.
 *
 * The same drawer serves both sides of the conversation, because they are
 * looking at the same document and should see the same facts:
 *
 *  - `role="reviewer"` adds the verdict panel (Approve / Reject / Hold, with the
 *    reason the refusal is useless without).
 *  - `role="vendor"` adds the reply-and-resend panel, and only while the
 *    certificate is actually on hold.
 *
 * `source` is how it reads and writes: the admin API for a reviewer, the portal
 * API for a vendor. Nothing else differs.
 */
export default function MedicalDetailDrawer({
  open, onClose, medicalId, role = 'reviewer',
  source,            // { get, decide, comment, resubmit, certificate, document }
  vocabulary,        // { decisions, reasons } — from the register payload
  onChanged,
}) {
  const toast = useToast()
  const [data, setData] = useState(null)
  const [busy, setBusy] = useState(false)

  // Verdict form
  const [decision, setDecision] = useState('')
  const [reason, setReason] = useState('')
  const [note, setNote] = useState('')

  // Resubmission form
  const [resubmitFile, setResubmitFile] = useState(null)
  const [resubmitNote, setResubmitNote] = useState('')

  const load = () => {
    if (!medicalId) return
    setData(null)
    source.get(medicalId)
      .then(setData)
      .catch(e => { toast.error(e); onClose?.() })
  }

  useEffect(() => {
    if (open && medicalId) {
      load()
      setDecision(''); setReason(''); setNote('')
      setResubmitFile(null); setResubmitNote('')
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, medicalId])

  const medical  = data?.medical
  const timeline = data?.timeline
  // The detail response carries the vocabulary too, so a caller that has not
  // already loaded the register (a worker profile, say) still gets the real
  // reason catalogue rather than an empty dropdown.
  const vocab = vocabulary ?? data?.qc

  const reasons = (vocab?.reasons ?? []).filter(
    r => !decision || !r.applies_to || r.applies_to.includes(decision),
  )

  const decide = async () => {
    if (!decision) return
    setBusy(true)
    try {
      await source.decide(medicalId, { decision, reason_code: reason || undefined, note: note || undefined })
      toast.success(`Certificate ${decision.toLowerCase()}.`)
      onChanged?.()
      onClose?.()
    } catch (e) { toast.error(e) } finally { setBusy(false) }
  }

  const resubmit = async () => {
    setBusy(true)
    try {
      await source.resubmit(medicalId, { message: resubmitNote || undefined, report_file: resubmitFile || undefined })
      toast.success('Certificate resubmitted for review.')
      onChanged?.()
      onClose?.()
    } catch (e) { toast.error(e) } finally { setBusy(false) }
  }

  const comment = async ({ body, attachment }) => {
    setBusy(true)
    try {
      await source.comment(medicalId, { body, attachment: attachment || undefined })
      load()
      onChanged?.()
    } catch (e) { toast.error(e) } finally { setBusy(false) }
  }

  return (
    <Drawer
      open={open}
      onClose={onClose}
      width="min(760px, 96vw)"
      title={medical?.certificate_no || 'Medical certificate'}
      footer={
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
          <button onClick={onClose} style={S.btn}>Close</button>
        </div>
      }
    >
      {!data ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>Loading…</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>

          {/* ── The facts ─────────────────────────────────────────────── */}
          <section className="pr-glass" style={{ padding: 14, borderRadius: 12 }}>
            <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center', marginBottom: 10 }}>
              <FitnessPill status={medical.fitness_status} />
              <QcPill status={medical.qc_status} />
              {medical.is_expired && <span style={{ fontSize: 11.5, color: '#ef4444', fontWeight: 700 }}>Expired</span>}
              {medical.is_reexam && <span style={{ fontSize: 11.5, color: '#8b5cf6', fontWeight: 700 }}>Re-examination · attempt {medical.attempt_no}</span>}
              <div style={{ marginLeft: 'auto' }}>
                <HealthScore score={medical.health_score} band={medical.health_band} note={medical.health_score_note} />
              </div>
            </div>

            <Grid>
              <Field label="Worker" value={medical.worker?.name || medical.worker?.full_name} />
              <Field label="Worker code" value={medical.worker?.worker_code} />
              <Field label="Examined" value={medical.exam_date} />
              <Field label="Valid until" value={medical.valid_until || medical.expiry_date} />
              <Field label="Examiner" value={medical.examiner_name} />
              <Field label="Licence no" value={medical.doctor_license_no} />
              <Field label="Clinic" value={medical.clinic_name} />
              <Field label="Source" value={medical.origin_label} />
            </Grid>

            {(medical.restrictions || medical.doctor_remarks) && (
              <div style={{ marginTop: 10, fontSize: 12.5, color: 'var(--text-muted)' }}>
                {medical.restrictions && <div><strong style={{ color: 'var(--text-h)' }}>Restrictions:</strong> {medical.restrictions}</div>}
                {medical.doctor_remarks && <div><strong style={{ color: 'var(--text-h)' }}>Remarks:</strong> {medical.doctor_remarks}</div>}
              </div>
            )}

            {/* The legal capture — what makes the certificate checkable. */}
            {(medical.geo_place || medical.geo_location || medical.system_ip) && (
              <div style={{ marginTop: 10, fontSize: 11.5, color: 'var(--text-muted)' }}>
                Signed from {medical.geo_place || medical.geo_location || 'an unrecorded place'}
                {medical.system_ip ? ` · IP ${medical.system_ip}` : ''}
              </div>
            )}

            <div style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap' }}>
              <button onClick={() => source.certificate(medicalId)} style={S.btn}>
                <FileText size={14} /> Certificate PDF
              </button>
              {medical.document_path && source.document && (
                <button onClick={() => source.document(medicalId)} style={S.btn}>
                  <Download size={14} /> Uploaded report
                </button>
              )}
            </div>
          </section>

          {/* ── The verdict (reviewer) ────────────────────────────────── */}
          {role === 'reviewer' && medical.qc_status !== 'Rejected' && (
            <section className="pr-glass" style={{ padding: 14, borderRadius: 12 }}>
              <h3 style={{ margin: '0 0 10px', fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>Quality check</h3>

              <div style={{ display: 'flex', gap: 8, marginBottom: 10, flexWrap: 'wrap' }}>
                {(vocab?.decisions ?? ['Approved', 'Hold', 'Rejected']).map(d => {
                  const Icon = d === 'Approved' ? CheckCircle2 : d === 'Hold' ? PauseCircle : XCircle
                  const tone = d === 'Approved' ? '#10b981' : d === 'Hold' ? '#f59e0b' : '#ef4444'
                  const on = decision === d
                  return (
                    <button
                      key={d}
                      onClick={() => { setDecision(d); setReason('') }}
                      style={{
                        ...S.btn, fontWeight: 700,
                        color: on ? '#fff' : tone,
                        background: on ? tone : 'var(--bg-card)',
                        borderColor: tone + '66',
                      }}
                    >
                      <Icon size={14} /> {d}
                    </button>
                  )
                })}
              </div>

              {/* A rejection or hold without a reason is not a review — so the
                  reason field appears the moment one of those is chosen. */}
              {(decision === 'Rejected' || decision === 'Hold') && (
                <div style={{ display: 'grid', gap: 8, marginBottom: 10 }}>
                  <div>
                    <label style={S.label}>Reason (required)</label>
                    <select value={reason} onChange={e => setReason(e.target.value)} style={{ ...S.select, width: '100%' }}>
                      <option value="">Select a reason…</option>
                      {reasons.map(r => <option key={r.value} value={r.value}>{r.label}</option>)}
                    </select>
                  </div>
                  <div>
                    <label style={S.label}>Note {reason ? '(optional)' : '(required if no reason picked)'}</label>
                    <textarea
                      value={note}
                      onChange={e => setNote(e.target.value)}
                      rows={2}
                      placeholder={decision === 'Hold' ? 'What does the vendor need to fix?' : 'Why can this certificate not be accepted?'}
                      style={{ ...S.input, resize: 'vertical' }}
                    />
                  </div>
                  {decision === 'Rejected' && (
                    <p style={{ margin: 0, fontSize: 11.5, color: '#ef4444' }}>
                      A rejection is final — this worker will need a fresh examination, not a resubmission.
                    </p>
                  )}
                </div>
              )}

              <button
                onClick={decide}
                disabled={busy || !decision || ((decision === 'Rejected' || decision === 'Hold') && !reason && !note.trim())}
                style={{
                  ...S.btnPrimary,
                  opacity: busy || !decision || ((decision === 'Rejected' || decision === 'Hold') && !reason && !note.trim()) ? 0.5 : 1,
                }}
              >
                Record decision
              </button>
            </section>
          )}

          {/* ── The reply (vendor), only while it can come back ────────── */}
          {role === 'vendor' && medical.qc_status === 'Hold' && timeline?.can_resubmit && (
            <section className="pr-glass" style={{ padding: 14, borderRadius: 12 }}>
              <h3 style={{ margin: '0 0 4px', fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>Answer and resend</h3>
              <p style={{ margin: '0 0 10px', fontSize: 12, color: 'var(--text-muted)' }}>
                {medical.qc_reason_code ? `Held: ${humanise(medical.qc_reason_code)}. ` : ''}
                {medical.qc_note}
              </p>

              <label style={S.label}>Replacement certificate (optional)</label>
              <input
                type="file"
                accept=".pdf,.jpg,.jpeg,.png"
                onChange={e => setResubmitFile(e.target.files?.[0] ?? null)}
                style={{ ...S.input, padding: 6, marginBottom: 8 }}
              />

              <label style={S.label}>Message</label>
              <textarea
                value={resubmitNote}
                onChange={e => setResubmitNote(e.target.value)}
                rows={2}
                placeholder="What changed since the last submission?"
                style={{ ...S.input, resize: 'vertical', marginBottom: 10 }}
              />

              <button onClick={resubmit} disabled={busy} style={{ ...S.btnPrimary, opacity: busy ? 0.5 : 1 }}>
                <Upload size={14} /> Resubmit for review
              </button>
            </section>
          )}

          {role === 'vendor' && medical.qc_status === 'Rejected' && (
            <div style={{ padding: '10px 14px', borderRadius: 10, background: '#ef444418', color: '#ef4444', fontSize: 12.5 }}>
              This certificate was rejected{medical.qc_reason_code ? ` — ${humanise(medical.qc_reason_code)}` : ''}. A fresh medical
              examination is required; resubmitting this one is not possible.
            </div>
          )}

          {/* ── The thread ────────────────────────────────────────────── */}
          <section className="pr-glass" style={{ padding: 14, borderRadius: 12 }}>
            {/* Read-only for a doctor: they are told the verdict, but the
                conversation about a certificate is the vendor's and the quality
                team's to have. */}
            <MedicalTimeline timeline={timeline} onComment={source.comment ? comment : null} busy={busy} />
          </section>
        </div>
      )}
    </Drawer>
  )
}

function Grid({ children }) {
  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: '8px 14px' }}>
      {children}
    </div>
  )
}

function Field({ label, value }) {
  return (
    <div>
      <div style={S.label}>{label}</div>
      <div style={{ fontSize: 13, color: 'var(--text-h)', fontWeight: 600 }}>{value || '—'}</div>
    </div>
  )
}
