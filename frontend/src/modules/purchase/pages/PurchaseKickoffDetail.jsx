import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, CalendarDays, Clock, MapPin, Users, CheckCircle2, XCircle,
  Send, Upload, AlertTriangle, Loader2, FileText, ShieldCheck, History,
  Sparkles, Eye, Download, Video, RotateCcw, FileCheck2,
} from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import {
  PK_STATUS, pkStatusCfg, pkNextStatuses, pkModeLabel, fmtDateTime, fmtDate,
  PK_MOM_STATUS, pkMomCfg, pkMomDistributable, pkMomAwaitingDecision,
} from '@/modules/purchase/kickoffConstants'
import { KIT3D_STYLE, Overlay, ModalFooter, Field, TextInput } from '@/components/ui/kit3d'

/**
 * Purchase kickoff meeting detail — schedule, participant registry, status
 * transitions, minutes (MOM) generate/upload, and vendor acknowledgement.
 * Consumes ONLY purchaseApi.kickoff (/api/purchase/kickoff). Purchase-owned: no
 * TPV imports, no shared kickoff page/component.
 */
export default function PurchaseKickoffDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [m, setM]         = useState(null)
  const [loading, setLoad] = useState(true)
  const [err, setErr]     = useState(null)
  const [action, setAction] = useState(null)   // { to } transition modal
  const [published, setPublished] = useState(false)

  const load = () => purchaseApi.kickoff.get(id).then(d => {
    setM(d?.data ?? d)
    setLoad(false)
  }).catch(() => { setErr('Could not load this meeting.'); setLoad(false) })
  useEffect(() => { load() }, [id]) // eslint-disable-line react-hooks/exhaustive-deps

  const publish = async () => {
    setErr(null)
    try {
      const res = await purchaseApi.kickoff.publish(id)
      setM(res?.data ?? res)
      setPublished(true)
    } catch (e) { setErr(e?.response?.data?.message || 'Could not send for acknowledgement.') }
  }

  if (loading) return <div style={{ padding: 24 }}><style>{KIT3D_STYLE}</style><div className="skeleton" style={{ height: 44, width: 280, borderRadius: 12, background: 'var(--border)' }} /></div>
  if (err && !m) return <div style={{ padding: 24, color: 'var(--text-muted)' }}><style>{KIT3D_STYLE}</style>{err}</div>

  const cfg = pkStatusCfg(m.status)
  const nexts = pkNextStatuses(m.status)
  const hasMom = !!m.current_mom
  const momApproved = pkMomDistributable(m.mom_status)
  const canSend = hasMom && momApproved

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>
      <style>{`@keyframes pkSpin{to{transform:rotate(360deg)}}.pk-spin{animation:pkSpin .9s linear infinite}`}</style>

      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12, marginBottom: 18 }}>
        <button onClick={() => navigate('/app/purchase/kickoff')} style={{ width: 34, height: 34, borderRadius: 10, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)', marginTop: 3, flexShrink: 0 }}>
          <ArrowLeft size={16} />
        </button>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
            <h1 style={{ color: 'var(--text-h)', fontSize: 23, fontWeight: 900, margin: 0, letterSpacing: '-0.02em' }}>{m.title}</h1>
            <span style={{ padding: '4px 11px', borderRadius: 999, background: cfg.bg, color: cfg.color, fontSize: 12, fontWeight: 800 }}>{cfg.label}</span>
            {m.is_acknowledged && (
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11.5, fontWeight: 700, color: '#10b981' }}>
                <ShieldCheck size={13} /> Acknowledged by {m.acknowledged_by_name}
              </span>
            )}
          </div>
          {m.vendor?.company_name && <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '5px 0 0' }}>Purchase Vendor: <strong style={{ color: 'var(--text-h)' }}>{m.vendor.company_name}</strong></p>}
        </div>
        {/* Transition actions the server will actually accept */}
        <div style={{ display: 'flex', gap: 8, flexShrink: 0, flexWrap: 'wrap', justifyContent: 'flex-end' }}>
          {nexts.map(to => {
            const tc = pkStatusCfg(to)
            return (
              <button key={to} onClick={() => setAction({ to })}
                style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '8px 13px', borderRadius: 10, cursor: 'pointer', fontSize: 12.5, fontWeight: 700,
                  background: to === PK_STATUS.COMPLETED ? 'linear-gradient(145deg,#34d399,#10b981)' : 'var(--bg-card)',
                  border: to === PK_STATUS.COMPLETED ? 'none' : `1px solid ${tc.color}55`,
                  color: to === PK_STATUS.COMPLETED ? '#fff' : tc.color,
                  boxShadow: to === PK_STATUS.COMPLETED ? '0 8px 20px -6px #10b98188' : 'none' }}>
                {to === PK_STATUS.COMPLETED && <CheckCircle2 size={14} />}
                {actionLabel(m.status, to)}
              </button>
            )
          })}
        </div>
      </div>

      {err && <Banner tone="#ef4444" icon={AlertTriangle}>{err}</Banner>}

      <div style={{ display: 'grid', gridTemplateColumns: '1.6fr 1fr', gap: 16, alignItems: 'start' }}>
        {/* Left column */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          {/* Schedule */}
          <div className="pr-glass" style={{ padding: 20 }}>
            <SectionTitle icon={CalendarDays}>Schedule</SectionTitle>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14, marginTop: 12 }}>
              <Detail icon={Clock} label="Date & time" value={fmtDateTime(m.scheduled_at)} />
              <Detail icon={Clock} label="Duration" value={m.duration_minutes ? `${m.duration_minutes} min` : '—'} />
              <Detail icon={MapPin} label={m.mode === 'online' ? 'Meeting link' : 'Location'} value={m.location || '—'} />
              <Detail icon={CalendarDays} label="Mode" value={pkModeLabel(m.mode)} />
            </div>
            {m.status === PK_STATUS.DELAYED && m.delay_reason && (
              <div style={{ marginTop: 14, padding: '11px 13px', borderRadius: 11, background: 'rgba(245,158,11,0.08)', border: '1px solid rgba(245,158,11,0.32)' }}>
                <div style={{ fontSize: 11, fontWeight: 800, color: '#f59e0b', marginBottom: 3 }}>DELAYED · originally {fmtDate(m.original_scheduled_at)}</div>
                <div style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{m.delay_reason}</div>
              </div>
            )}
            {m.agenda && (
              <div style={{ marginTop: 14 }}>
                <div style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 5 }}>Agenda</div>
                <p style={{ fontSize: 13, color: 'var(--text-h)', margin: 0, lineHeight: 1.55, whiteSpace: 'pre-wrap' }}>{m.agenda}</p>
              </div>
            )}
          </div>

          {/* Online meeting details (read-only, shown when mode = 'online' and a link exists) */}
          {m.mode === 'online' && (m.meeting_link || m.meeting_platform) && (
            <div className="pr-glass" style={{ padding: 20 }}>
              <SectionTitle icon={Video}>Online meeting</SectionTitle>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14, marginTop: 12 }}>
                {m.meeting_platform && <Detail icon={Video} label="Platform" value={m.meeting_platform} />}
                {m.meeting_id && <Detail icon={FileText} label="Meeting ID" value={m.meeting_id} />}
                {m.meeting_passcode && <Detail icon={ShieldCheck} label="Passcode" value={m.meeting_passcode} />}
                {m.meeting_link && (
                  <div style={{ gridColumn: '1 / -1' }}>
                    <div style={{ fontSize: 10.5, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 3 }}>Link</div>
                    <a href={m.meeting_link} target="_blank" rel="noopener noreferrer" style={{ fontSize: 13, color: '#a78bfa', wordBreak: 'break-all' }}>{m.meeting_link}</a>
                  </div>
                )}
              </div>
            </div>
          )}

          {/* Participants */}
          <div className="pr-glass" style={{ padding: 20 }}>
            <SectionTitle icon={Users}>Participants <span style={{ fontWeight: 500, color: 'var(--text-muted)', fontSize: 12 }}>· {(m.participants || []).length}</span></SectionTitle>
            {(m.participants || []).length === 0 ? (
              <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '12px 0 0' }}>No participants recorded.</p>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginTop: 12 }}>
                {m.participants.map(a => (
                  <div key={a.id} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '10px 12px', borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                    <div style={{ width: 34, height: 34, borderRadius: '50%', flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.14)', color: '#a78bfa', fontWeight: 800, fontSize: 13 }}>
                      {(a.name || '?').charAt(0).toUpperCase()}
                    </div>
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{a.name}
                        {a.purchase_contact_id && <span title="Linked to a vendor contact" style={{ marginLeft: 6, fontSize: 10, fontWeight: 700, color: '#10b981' }}>● linked</span>}
                      </div>
                      <div style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{[a.role, a.organisation].filter(Boolean).join(' · ') || '—'}</div>
                    </div>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11, fontWeight: 700, color: a.attended ? '#10b981' : 'var(--text-muted)' }}>
                      {a.attended ? <><CheckCircle2 size={13} /> Attended</> : <><XCircle size={13} /> Absent</>}
                    </span>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Minutes text */}
          {m.minutes && (
            <div className="pr-glass" style={{ padding: 20 }}>
              <SectionTitle icon={FileText}>Minutes</SectionTitle>
              <p style={{ fontSize: 13, color: 'var(--text-h)', margin: '12px 0 0', lineHeight: 1.55, whiteSpace: 'pre-wrap' }}>{m.minutes}</p>
            </div>
          )}
        </div>

        {/* Right column */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          {/* Minutes document */}
          <MomCard m={m} onUpdated={setM} onError={setErr} />

          {/* MOM approval workflow */}
          <MomApprovalCard m={m} onUpdated={setM} onError={setErr} />

          {/* Acknowledgement */}
          <div className="pr-glass" style={{ padding: 20 }}>
            <SectionTitle icon={ShieldCheck}>Vendor acknowledgement</SectionTitle>
            {m.is_acknowledged ? (
              <div style={{ marginTop: 12, padding: '14px', borderRadius: 12, background: 'rgba(16,185,129,0.08)', border: '1px solid rgba(16,185,129,0.32)', textAlign: 'center' }}>
                <CheckCircle2 size={26} style={{ color: '#10b981' }} />
                <div style={{ fontSize: 13.5, fontWeight: 800, color: '#10b981', marginTop: 6 }}>Acknowledged</div>
                <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>by {m.acknowledged_by_name} · {fmtDateTime(m.acknowledged_at)}</div>
              </div>
            ) : m.status !== PK_STATUS.COMPLETED ? (
              <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '12px 0 0', lineHeight: 1.5 }}>
                Complete the meeting first — the vendor acknowledges the minutes once they're finalised.
              </p>
            ) : published ? (
              <div style={{ marginTop: 12, padding: '13px', borderRadius: 12, background: 'rgba(124,58,237,0.08)', border: '1px solid rgba(124,58,237,0.32)' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 7, fontSize: 13, fontWeight: 800, color: '#a78bfa' }}><Send size={15} /> Sent for acknowledgement</div>
                <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '6px 0 0', lineHeight: 1.5 }}>
                  The vendor has been notified. They acknowledge the minutes from the Purchase Vendor Portal (onboarding Step 1).
                </p>
              </div>
            ) : (
              <div style={{ marginTop: 12 }}>
                <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '0 0 12px', lineHeight: 1.5 }}>
                  Send the minutes to the vendor for acknowledgement. The MOM must be generated and approved first.
                </p>
                <button onClick={publish} disabled={!canSend} style={{ ...solidBtn, width: '100%', justifyContent: 'center', opacity: canSend ? 1 : 0.6, cursor: canSend ? 'pointer' : 'not-allowed' }}>
                  <Send size={15} /> Send for acknowledgement
                </button>
                {!hasMom
                  ? <p style={{ fontSize: 11, color: 'var(--text-muted)', margin: '8px 0 0' }}>Generate or upload the MOM PDF first.</p>
                  : !momApproved && <p style={{ fontSize: 11, color: '#f59e0b', margin: '8px 0 0' }}>The minutes must be approved before they can be sent.</p>}
              </div>
            )}
          </div>

          {/* Audit trail */}
          {(m.audit_logs || []).length > 0 && (
            <div className="pr-glass" style={{ padding: 20 }}>
              <SectionTitle icon={History}>Activity</SectionTitle>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 12, marginTop: 12 }}>
                {m.audit_logs.map(a => (
                  <div key={a.id} style={{ display: 'flex', gap: 10 }}>
                    <div style={{ width: 8, height: 8, borderRadius: '50%', background: '#a78bfa', marginTop: 5, flexShrink: 0 }} />
                    <div style={{ minWidth: 0 }}>
                      <div style={{ fontSize: 12.5, color: 'var(--text-h)', lineHeight: 1.4 }}>{a.comment}</div>
                      <div style={{ fontSize: 10.5, color: 'var(--text-muted)', marginTop: 1 }}>{fmtDateTime(a.created_at)}</div>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      </div>

      {action && <TransitionModal m={m} to={action.to} onClose={() => setAction(null)} onDone={(updated) => { setAction(null); setM(updated); setErr(null) }} />}
    </div>
  )
}

/* ── MOM document card ────────────────────────────────────────────────────────
 * Generate a PDF from the meeting's data, or upload a document. Either fills the
 * single MOM slot; regenerating/re-uploading replaces the previous file. */
function MomCard({ m, onUpdated, onError }) {
  const [busy, setBusy] = useState(null) // 'upload' | 'gen' | 'view' | 'dl'
  const hasMom = !!m.current_mom

  const pick = async (e) => {
    const f = e.target.files?.[0]
    if (!f) return
    setBusy('upload'); onError(null)
    try {
      const res = await purchaseApi.kickoff.uploadMom(m.id, f)
      onUpdated(res?.data ?? res)
    } catch (err) {
      onError(err?.response?.data?.message || 'Could not upload the document.')
    } finally { setBusy(null); e.target.value = '' }
  }

  const generate = async () => {
    setBusy('gen'); onError(null)
    try {
      const res = await purchaseApi.kickoff.generateMom(m.id)
      onUpdated(res?.data ?? res)
    } catch (err) {
      onError(err?.response?.data?.message || 'Could not generate the MOM PDF.')
    } finally { setBusy(null) }
  }

  const openPdf = async (download) => {
    setBusy(download ? 'dl' : 'view'); onError(null)
    try {
      let blob
      try {
        blob = await purchaseApi.kickoff.momBlob(m.id)
      } catch {
        await purchaseApi.kickoff.generateMom(m.id)
        blob = await purchaseApi.kickoff.momBlob(m.id)
      }
      const url = URL.createObjectURL(blob)
      if (download) {
        const a = document.createElement('a')
        a.href = url; a.download = `Purchase-MOM-${m.id}.pdf`
        document.body.appendChild(a); a.click(); a.remove()
      } else {
        window.open(url, '_blank', 'noopener')
      }
      setTimeout(() => URL.revokeObjectURL(url), 60000)
    } catch (err) {
      onError(err?.response?.data?.message || 'Could not open the MOM PDF.')
    } finally { setBusy(null) }
  }

  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <SectionTitle icon={FileText}>Minutes document</SectionTitle>

      {hasMom ? (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 12, padding: '11px 13px', borderRadius: 11, background: 'rgba(16,185,129,0.08)', border: '1px solid rgba(16,185,129,0.3)' }}>
          <FileText size={16} style={{ color: '#10b981', flexShrink: 0 }} />
          <span style={{ fontSize: 12.5, color: 'var(--text-h)', flex: 1 }}>MOM document ready</span>
          <label style={{ fontSize: 11.5, fontWeight: 700, color: '#a78bfa', cursor: 'pointer' }}>
            Replace<input type="file" accept=".pdf" onChange={pick} style={{ display: 'none' }} />
          </label>
        </div>
      ) : (
        <label style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 7, marginTop: 12, padding: '18px', borderRadius: 12, cursor: 'pointer',
          background: 'linear-gradient(150deg, rgba(124,58,237,.1), rgba(124,58,237,.03))', border: '1.5px dashed rgba(124,58,237,.4)' }}>
          {busy === 'upload' ? <Loader2 size={22} style={{ color: '#a78bfa' }} className="pk-spin" /> : <Upload size={22} style={{ color: '#a78bfa' }} />}
          <span style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>{busy === 'upload' ? 'Uploading…' : 'Upload minutes (PDF)'}</span>
          <input type="file" accept=".pdf" onChange={pick} disabled={busy === 'upload'} style={{ display: 'none' }} />
        </label>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: hasMom ? '1fr 1fr' : '1fr', gap: 8, marginTop: 10 }}>
        <MomBtn onClick={generate} busy={busy === 'gen'} icon={Sparkles} tone="#7C3AED">{hasMom ? 'Regenerate PDF' : 'Generate PDF'}</MomBtn>
        {hasMom && <MomBtn onClick={() => openPdf(false)} busy={busy === 'view'} icon={Eye} tone="#10b981">View PDF</MomBtn>}
      </div>
      {hasMom && (
        <MomBtn onClick={() => openPdf(true)} busy={busy === 'dl'} icon={Download} tone="#0ea5e9" full>Download PDF</MomBtn>
      )}
    </div>
  )
}

function MomBtn({ onClick, busy, icon: Icon, tone, full, children }) {
  return (
    <button onClick={onClick} disabled={busy}
      style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 6, padding: '9px 12px', borderRadius: 10, cursor: busy ? 'wait' : 'pointer',
        width: full ? '100%' : undefined, marginTop: full ? 8 : 0,
        fontSize: 12.5, fontWeight: 700, color: tone, background: `${tone}14`, border: `1px solid ${tone}44` }}>
      {busy ? <Loader2 size={14} className="pk-spin" /> : <Icon size={14} />} {children}
    </button>
  )
}

/* ── MOM approval card ────────────────────────────────────────────────────────
 * The two-level MOM approval lifecycle (Sangoe TPV §9): Draft → Pending Organizer
 * → Pending Chairperson → Approved → Distributed. Only an approved MOM may be sent
 * to the vendor for acknowledgement. Mirrors PurchaseMomApprovalStatus. */
function MomApprovalCard({ m, onUpdated, onError }) {
  const [busy, setBusy] = useState(null)      // 'submit' | 'approve' | 'return' | 'revise'
  const [showReturn, setShowReturn] = useState(false)
  const [note, setNote] = useState('')

  const st = m.mom_status || PK_MOM_STATUS.DRAFT
  const cfg = pkMomCfg(st)
  const completed = m.status === PK_STATUS.COMPLETED
  const awaiting = pkMomAwaitingDecision(st)
  const distributable = pkMomDistributable(st)

  const run = async (fn) => {
    onError(null)
    try {
      const res = await fn()
      onUpdated(res?.data ?? res)
      setShowReturn(false); setNote('')
    } catch (err) {
      onError(err?.response?.data?.message || 'Could not update the minutes.')
    } finally { setBusy(null) }
  }

  const submit  = () => { setBusy('submit');  run(() => purchaseApi.kickoff.momSubmit(m.id)) }
  const approve = () => { setBusy('approve'); run(() => purchaseApi.kickoff.momDecide(m.id, { decision: 'approve' })) }
  const doReturn = () => { setBusy('return'); run(() => purchaseApi.kickoff.momDecide(m.id, { decision: 'return', note })) }
  const revise  = () => { setBusy('revise');  run(() => purchaseApi.kickoff.momRevise(m.id)) }

  const approveLabel = st === PK_MOM_STATUS.PENDING ? 'Approve (Organizer)' : 'Approve (Chairperson)'

  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8 }}>
        <SectionTitle icon={FileCheck2}>MOM approval</SectionTitle>
        <span style={{ padding: '3px 10px', borderRadius: 999, background: cfg.bg, color: cfg.color, fontSize: 11.5, fontWeight: 800 }}>{cfg.label}</span>
      </div>

      {/* Approval trail */}
      <div style={{ display: 'flex', flexDirection: 'column', gap: 6, marginTop: 12 }}>
        <TrailRow ok={!!m.mom_submitted_at} label="Submitted" who={m.mom_submitter?.name} when={m.mom_submitted_at} />
        <TrailRow ok={!!m.mom_organizer_approved_at} label="Organizer approved" who={m.mom_organizer_approver?.name} when={m.mom_organizer_approved_at} />
        <TrailRow ok={!!m.mom_approved_at} label="Chairperson approved" who={m.mom_approver?.name} when={m.mom_approved_at} />
        <TrailRow ok={!!m.mom_distributed_at} label="Distributed" who={m.mom_distributor?.name} when={m.mom_distributed_at} />
      </div>

      {/* Return-for-revision note (shown after a return) */}
      {st === PK_MOM_STATUS.DRAFT && m.mom_approval_note && (
        <div style={{ marginTop: 12, padding: '10px 12px', borderRadius: 11, background: 'rgba(245,158,11,0.08)', border: '1px solid rgba(245,158,11,0.32)' }}>
          <div style={{ fontSize: 10.5, fontWeight: 800, color: '#f59e0b', marginBottom: 3 }}>RETURNED FOR REVISION</div>
          <div style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{m.mom_approval_note}</div>
        </div>
      )}

      {/* Actions */}
      <div style={{ marginTop: 14 }}>
        {!completed ? (
          <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: 0, lineHeight: 1.5 }}>
            Complete the meeting and finalise its minutes before submitting them for approval.
          </p>
        ) : showReturn ? (
          <div>
            <textarea rows={3} value={note} onChange={e => setNote(e.target.value)} placeholder="What needs to change before approval?"
              style={{ width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, resize: 'vertical', boxSizing: 'border-box' }} />
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8, marginTop: 8 }}>
              <MomBtn onClick={() => { setShowReturn(false); setNote('') }} icon={XCircle} tone="#94a3b8">Cancel</MomBtn>
              <MomBtn onClick={doReturn} busy={busy === 'return'} icon={RotateCcw} tone="#f59e0b">Return for revision</MomBtn>
            </div>
          </div>
        ) : st === PK_MOM_STATUS.DRAFT ? (
          <MomBtn onClick={submit} busy={busy === 'submit'} icon={Send} tone="#7C3AED" full>Submit for approval</MomBtn>
        ) : awaiting ? (
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
            <MomBtn onClick={approve} busy={busy === 'approve'} icon={CheckCircle2} tone="#10b981">{approveLabel}</MomBtn>
            <MomBtn onClick={() => setShowReturn(true)} icon={RotateCcw} tone="#f59e0b">Return</MomBtn>
          </div>
        ) : distributable ? (
          <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 7, fontSize: 12.5, fontWeight: 700, color: cfg.color, marginBottom: 8 }}>
              <CheckCircle2 size={15} /> {st === PK_MOM_STATUS.DISTRIBUTED ? 'Approved & distributed' : 'Approved — ready to send'}
            </div>
            <MomBtn onClick={revise} busy={busy === 'revise'} icon={RotateCcw} tone="#94a3b8" full>Pull back for revision</MomBtn>
          </div>
        ) : null}
      </div>
    </div>
  )
}

function TrailRow({ ok, label, who, when }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
      {ok
        ? <CheckCircle2 size={14} style={{ color: '#10b981', flexShrink: 0 }} />
        : <div style={{ width: 14, height: 14, borderRadius: '50%', border: '1.5px solid var(--border)', flexShrink: 0 }} />}
      <span style={{ fontSize: 12, color: ok ? 'var(--text-h)' : 'var(--text-muted)', flex: 1 }}>{label}</span>
      {ok && <span style={{ fontSize: 10.5, color: 'var(--text-muted)' }}>{[who, fmtDateTime(when)].filter(Boolean).join(' · ')}</span>}
    </div>
  )
}

/* ── Transition modal ─────────────────────────────────────────────────────── */
function TransitionModal({ m, to, onClose, onDone }) {
  const [form, setForm] = useState({ delay_reason: '', scheduled_at: '', minutes: '' })
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)
  const tc = pkStatusCfg(to)

  const save = async () => {
    setSaving(true); setErr(null)
    try {
      const payload = { status: to }
      if (to === PK_STATUS.DELAYED) { payload.delay_reason = form.delay_reason; if (form.scheduled_at) payload.scheduled_at = form.scheduled_at }
      if (to === PK_STATUS.SCHEDULED && form.scheduled_at) payload.scheduled_at = form.scheduled_at
      if (to === PK_STATUS.COMPLETED && form.minutes) payload.minutes = form.minutes
      const updated = await purchaseApi.kickoff.transition(m.id, payload)
      onDone(updated?.data ?? updated)
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not update the meeting.')
      setSaving(false)
    }
  }

  return (
    <Overlay onClose={onClose} width={460}>
      <div style={{ padding: '20px 22px 6px' }}>
        <h2 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>{actionLabel(m.status, to)}</h2>
        <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>Move this meeting to <span style={{ color: tc.color, fontWeight: 700 }}>{tc.label}</span>.</p>
      </div>
      <div style={{ padding: '10px 22px' }}>
        {to === PK_STATUS.DELAYED && (
          <>
            <Field label="Why is it delayed? (required)" full>
              <TextInput value={form.delay_reason} onChange={e => setForm(f => ({ ...f, delay_reason: e.target.value }))} placeholder="Vendor representative travelling" />
            </Field>
            <Field label="New date & time (optional)" full>
              <TextInput type="datetime-local" value={form.scheduled_at} onChange={e => setForm(f => ({ ...f, scheduled_at: e.target.value }))} />
            </Field>
          </>
        )}
        {to === PK_STATUS.SCHEDULED && (
          <Field label="New date & time" full>
            <TextInput type="datetime-local" value={form.scheduled_at} onChange={e => setForm(f => ({ ...f, scheduled_at: e.target.value }))} />
          </Field>
        )}
        {to === PK_STATUS.COMPLETED && (
          <Field label="Minutes (optional — a document can be attached after)" full>
            <textarea rows={4} value={form.minutes} onChange={e => setForm(f => ({ ...f, minutes: e.target.value }))}
              placeholder="Key decisions, actions, and owners…"
              style={{ width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, resize: 'vertical', boxSizing: 'border-box' }} />
          </Field>
        )}
        {to === PK_STATUS.CANCELLED && (
          <p style={{ fontSize: 13, color: 'var(--text-muted)', lineHeight: 1.5 }}>This meeting will be marked cancelled. You can reopen it later if it goes ahead.</p>
        )}
        {err && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 12px', borderRadius: 10, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)', marginTop: 8 }}>
            <AlertTriangle size={14} style={{ color: '#ef4444', flexShrink: 0 }} />
            <span style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{err}</span>
          </div>
        )}
      </div>
      <ModalFooter onClose={onClose} onConfirm={save} loading={saving} confirmLabel={actionLabel(m.status, to)} color={tc.color === '#94a3b8' ? '#7C3AED' : tc.color} />
    </Overlay>
  )
}

/* ── bits ─────────────────────────────────────────────────────────────────── */
function actionLabel(from, to) {
  if (to === PK_STATUS.COMPLETED) return 'Mark completed'
  if (to === PK_STATUS.DELAYED) return 'Mark delayed'
  if (to === PK_STATUS.CANCELLED) return 'Cancel meeting'
  if (to === PK_STATUS.SCHEDULED) return from === PK_STATUS.CANCELLED ? 'Reopen' : 'Reschedule'
  return to
}

const SectionTitle = ({ icon: Icon, children }) => (
  <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
    <Icon size={15} style={{ color: '#a78bfa' }} />
    <h2 style={{ margin: 0, fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>{children}</h2>
  </div>
)

const Detail = ({ icon: Icon, label, value }) => (
  <div>
    <div style={{ display: 'flex', alignItems: 'center', gap: 5, fontSize: 10.5, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 3 }}>
      <Icon size={11} /> {label}
    </div>
    <div style={{ fontSize: 13.5, fontWeight: 600, color: 'var(--text-h)' }}>{value}</div>
  </div>
)

const Banner = ({ tone, icon: Icon, children }) => (
  <div style={{ display: 'flex', alignItems: 'center', gap: 9, padding: '11px 14px', borderRadius: 12, marginBottom: 16, background: `${tone}12`, border: `1px solid ${tone}55` }}>
    <Icon size={15} style={{ color: tone, flexShrink: 0 }} />
    <span style={{ fontSize: 13, color: 'var(--text-h)' }}>{children}</span>
  </div>
)

const solidBtn = {
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '10px 16px', borderRadius: 10, cursor: 'pointer',
  fontSize: 13, fontWeight: 700, color: '#fff', border: 'none',
  background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)',
}
