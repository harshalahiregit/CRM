import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, CalendarDays, Clock, MapPin, Users, CheckCircle2, XCircle,
  Send, Upload, AlertTriangle, Loader2, FileText, ShieldCheck, History,
  Sparkles, Eye, Download, Video, RotateCcw, FileCheck2,
  ListChecks, Plus, Paperclip, Trash2, UserCheck, AlertOctagon, ArrowUpRight, Gavel,
  ListOrdered, LayoutTemplate, CopyPlus,
} from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { useToast } from '@/components/ui/Toast'
import {
  PK_STATUS, pkStatusCfg, pkNextStatuses, pkModeLabel, fmtDateTime, fmtDate,
  PK_MOM_STATUS, pkMomCfg, pkMomDistributable, pkMomAwaitingDecision,
  PK_ACTION_STATUS, pkActionCfg, pkActionNext, PK_ACTION_PRIORITIES,
  pkIssueCfg, pkIssueNext, PK_ISSUE_SEVERITIES, PK_ISSUE_CATEGORIES,
  pkDecisionCfg, PK_DECISION_STATUSES,
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
    } catch (e) { setErr(e?.response?.data?.message || 'Could not send the minutes.') }
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
          </div>
          {m.vendor?.company_name && <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '5px 0 0' }}>Purchase Vendor: <strong style={{ color: 'var(--text-h)' }}>{m.vendor.company_name}</strong></p>}
        </div>
        {/* Transition actions the server will actually accept */}
        <div style={{ display: 'flex', gap: 8, flexShrink: 0, flexWrap: 'wrap', justifyContent: 'flex-end' }}>
          {nexts.map(to => {
            const tc = pkStatusCfg(to)
            const isPublish = m.status === PK_STATUS.DRAFT && to === PK_STATUS.SCHEDULED
            const primary = to === PK_STATUS.COMPLETED || isPublish
            return (
              <button key={to} onClick={() => setAction({ to })}
                style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '8px 13px', borderRadius: 10, cursor: 'pointer', fontSize: 12.5, fontWeight: 700,
                  background: primary ? 'linear-gradient(145deg,#34d399,#10b981)' : 'var(--bg-card)',
                  border: primary ? 'none' : `1px solid ${tc.color}55`,
                  color: primary ? '#fff' : tc.color,
                  boxShadow: primary ? '0 8px 20px -6px #10b98188' : 'none' }}>
                {(to === PK_STATUS.COMPLETED || isPublish) && (isPublish ? <Send size={14} /> : <CheckCircle2 size={14} />)}
                {actionLabel(m.status, to)}
              </button>
            )
          })}
        </div>
      </div>

      {err && <Banner tone="#ef4444" icon={AlertTriangle}>{err}</Banner>}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 16, alignItems: 'start' }}>
        {/* Left column */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          {/* Schedule */}
          <div className="pr-glass" style={{ padding: 20 }}>
            <SectionTitle icon={CalendarDays}>Schedule</SectionTitle>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 14, marginTop: 12 }}>
              {m.meeting_no && <Detail icon={FileText} label="Meeting No." value={m.meeting_no} />}
              <Detail icon={Clock} label="Date & time" value={fmtDateTime(m.scheduled_at)} />
              {m.end_at && <Detail icon={Clock} label="End time" value={fmtDateTime(m.end_at)} />}
              <Detail icon={Clock} label="Duration" value={m.duration_minutes ? `${m.duration_minutes} min` : '—'} />
              <Detail icon={MapPin} label={m.mode === 'online' ? 'Meeting link' : 'Location'} value={m.location || '—'} />
              <Detail icon={CalendarDays} label="Mode" value={pkModeLabel(m.mode)} />
              {m.priority && <Detail icon={AlertTriangle} label="Priority" value={m.priority} />}
              {m.confidentiality && <Detail icon={ShieldCheck} label="Confidentiality" value={m.confidentiality} />}
              {m.chairperson && <Detail icon={UserCheck} label="Chairperson" value={m.chairperson} />}
              {m.coordinator && <Detail icon={UserCheck} label="Coordinator" value={m.coordinator} />}
              {m.organizer && <Detail icon={UserCheck} label="Organizer" value={m.organizer} />}
              {m.department && <Detail icon={Users} label="Department" value={m.department} />}
              {m.client_name && <Detail icon={FileText} label="Client" value={m.client_name} />}
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

          {/* Previous-MOM continuity + Agenda builder */}
          <PreviousSummaryCard m={m} onError={setErr} onChanged={load} />
          <AgendaCard m={m} onError={setErr} />

          {/* Online meeting details (read-only, shown when mode = 'online' and a link exists) */}
          {m.mode === 'online' && (m.meeting_link || m.meeting_platform) && (
            <div className="pr-glass" style={{ padding: 20 }}>
              <SectionTitle icon={Video}>Online meeting</SectionTitle>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 14, marginTop: 12 }}>
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

          {/* Action items (MOM action engine) */}
          <ActionItemsCard m={m} onError={setErr} />

          {/* Issue register (MOM issues → NCR/CAPA) */}
          <IssueRegisterCard m={m} onError={setErr} />

          {/* Decision register (MOM decisions) */}
          <DecisionRegisterCard m={m} onError={setErr} />
        </div>

        {/* Right column */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          {/* Minutes document */}
          <MomCard m={m} onUpdated={setM} onError={setErr} />

          {/* MOM approval workflow */}
          <MomApprovalCard m={m} onUpdated={setM} onError={setErr} />

          {/* Send minutes to the vendor (acknowledgement removed — the vendor
              reads the approved minutes in their portal). */}
          <div className="pr-glass" style={{ padding: 20 }}>
            <SectionTitle icon={Send}>Send minutes to vendor</SectionTitle>
            {m.status !== PK_STATUS.COMPLETED ? (
              <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '12px 0 0', lineHeight: 1.5 }}>
                Complete the meeting first — the minutes are shared once finalised and approved.
              </p>
            ) : m.mom_status === PK_MOM_STATUS.DISTRIBUTED ? (
              <div style={{ marginTop: 12, padding: '13px', borderRadius: 12, background: 'rgba(16,185,129,0.08)', border: '1px solid rgba(16,185,129,0.32)' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 7, fontSize: 13, fontWeight: 800, color: '#10b981' }}><CheckCircle2 size={15} /> Minutes sent to the vendor</div>
                <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '6px 0 0', lineHeight: 1.5 }}>Available in their portal · notified by e-mail.</p>
                <button onClick={publish} style={{ ...solidBtn, marginTop: 10, justifyContent: 'center' }}><Send size={14} /> Resend notification</button>
              </div>
            ) : (
              <div style={{ marginTop: 12 }}>
                <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '0 0 12px', lineHeight: 1.5 }}>
                  Send the approved minutes to the vendor. They read the full minutes in their portal and are notified by e-mail.
                </p>
                <button onClick={publish} disabled={!canSend} style={{ ...solidBtn, width: '100%', justifyContent: 'center', opacity: canSend ? 1 : 0.6, cursor: canSend ? 'pointer' : 'not-allowed' }}>
                  <Send size={15} /> Send minutes to vendor
                </button>
                {!hasMom
                  ? <p style={{ fontSize: 11, color: 'var(--text-muted)', margin: '8px 0 0' }}>Generate or upload the MOM document first.</p>
                  : !momApproved && <p style={{ fontSize: 11, color: '#f59e0b', margin: '8px 0 0' }}>The minutes must be approved before they can be sent.</p>}
              </div>
            )}
          </div>

          {/* Labelled supporting documents — multiple upload */}
          <PkDocumentsCard meetingId={m.id} onError={setErr} />

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
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 8, marginTop: 8 }}>
              <MomBtn onClick={() => { setShowReturn(false); setNote('') }} icon={XCircle} tone="#94a3b8">Cancel</MomBtn>
              <MomBtn onClick={doReturn} busy={busy === 'return'} icon={RotateCcw} tone="#f59e0b">Return for revision</MomBtn>
            </div>
          </div>
        ) : st === PK_MOM_STATUS.DRAFT ? (
          <MomBtn onClick={submit} busy={busy === 'submit'} icon={Send} tone="#7C3AED" full>Submit for approval</MomBtn>
        ) : awaiting ? (
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 8 }}>
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

/* ── Action items card (MOM action engine) ───────────────────────────────────
 * The register of actions a meeting produces — each with an owner (Rule 11) and,
 * at closure, evidence or a verification note (Rule 12). Mirrors
 * PurchaseMomActionStatus. */
function ActionItemsCard({ m, onError }) {
  const [rows, setRows] = useState(m.action_items || [])
  const [adding, setAdding] = useState(false)
  const [progress, setProgress] = useState(null) // { action, to }
  const [form, setForm] = useState({ description: '', responsible_participant_id: '', responsible_names: '', priority: '', target_date: '' })
  const [saving, setSaving] = useState(false)

  useEffect(() => { setRows(m.action_items || []) }, [m.action_items])

  const refresh = async () => {
    try { setRows(await purchaseApi.kickoff.actions.list(m.id)) } catch { /* keep stale */ }
  }

  const add = async () => {
    if (!form.description.trim()) { onError('An action needs a description.'); return }
    setSaving(true); onError(null)
    try {
      const payload = { description: form.description, priority: form.priority || undefined, target_date: form.target_date || undefined }
      if (form.responsible_participant_id) payload.responsible_participant_id = Number(form.responsible_participant_id)
      else payload.responsible_names = form.responsible_names
      await purchaseApi.kickoff.actions.create(m.id, payload)
      setForm({ description: '', responsible_participant_id: '', responsible_names: '', priority: '', target_date: '' })
      setAdding(false)
      await refresh()
    } catch (e) { onError(e?.response?.data?.message || 'Could not add the action.') }
    finally { setSaving(false) }
  }

  const del = async (a) => {
    onError(null)
    try { await purchaseApi.kickoff.actions.remove(m.id, a.id); await refresh() }
    catch (e) { onError(e?.response?.data?.message || 'Could not delete the action.') }
  }

  const viewEvidence = async (a) => {
    onError(null)
    try {
      const blob = await purchaseApi.kickoff.actions.evidenceBlob(m.id, a.id)
      const url = URL.createObjectURL(blob)
      window.open(url, '_blank', 'noopener')
      setTimeout(() => URL.revokeObjectURL(url), 60000)
    } catch (e) { onError(e?.response?.data?.message || 'Could not open the evidence.') }
  }

  const ownerLabel = (a) => a.responsible?.name || a.responsible_names || '—'
  const openCount = rows.filter(r => r.is_open).length

  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8 }}>
        <SectionTitle icon={ListChecks}>Action items <span style={{ fontWeight: 500, color: 'var(--text-muted)', fontSize: 12 }}>· {rows.length}{openCount ? ` · ${openCount} open` : ''}</span></SectionTitle>
        <button onClick={() => setAdding(v => !v)} style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 11px', borderRadius: 9, cursor: 'pointer', fontSize: 12, fontWeight: 700, color: '#a78bfa', background: 'rgba(124,58,237,0.12)', border: '1px solid rgba(124,58,237,0.4)' }}>
          <Plus size={13} /> Add
        </button>
      </div>

      {adding && (
        <div style={{ marginTop: 12, padding: 14, borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <textarea rows={2} value={form.description} onChange={e => setForm(f => ({ ...f, description: e.target.value }))} placeholder="What needs to be done?"
            style={{ width: '100%', padding: '9px 12px', background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, resize: 'vertical', boxSizing: 'border-box' }} />
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 8, marginTop: 8 }}>
            <div>
              <div style={labelSm}>Owner (Rule 11)</div>
              <select value={form.responsible_participant_id} onChange={e => setForm(f => ({ ...f, responsible_participant_id: e.target.value }))} style={selStyle}>
                <option value="">— someone else —</option>
                {(m.participants || []).map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            </div>
            <div>
              <div style={labelSm}>Priority</div>
              <select value={form.priority} onChange={e => setForm(f => ({ ...f, priority: e.target.value }))} style={selStyle}>
                <option value="">—</option>
                {PK_ACTION_PRIORITIES.map(p => <option key={p} value={p}>{p}</option>)}
              </select>
            </div>
            {!form.responsible_participant_id && (
              <div>
                <div style={labelSm}>Owner name</div>
                <TextInput value={form.responsible_names} onChange={e => setForm(f => ({ ...f, responsible_names: e.target.value }))} placeholder="Name of the owner" />
              </div>
            )}
            <div>
              <div style={labelSm}>Target date</div>
              <TextInput type="date" value={form.target_date} onChange={e => setForm(f => ({ ...f, target_date: e.target.value }))} />
            </div>
          </div>
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 10 }}>
            <MomBtn onClick={() => setAdding(false)} icon={XCircle} tone="#94a3b8">Cancel</MomBtn>
            <MomBtn onClick={add} busy={saving} icon={Plus} tone="#7C3AED">Add action</MomBtn>
          </div>
        </div>
      )}

      {rows.length === 0 ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '12px 0 0' }}>No action items yet.</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginTop: 12 }}>
          {rows.map(a => {
            const cfg = pkActionCfg(a.status)
            const nexts = pkActionNext(a.status)
            return (
              <div key={a.id} style={{ padding: '12px 13px', borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: 8 }}>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 7, flexWrap: 'wrap' }}>
                      {a.action_ref && <span style={{ fontSize: 10.5, fontWeight: 800, color: '#a78bfa', fontFamily: 'monospace' }}>{a.action_ref}</span>}
                      <span style={{ padding: '2px 8px', borderRadius: 999, background: cfg.bg, color: cfg.color, fontSize: 10.5, fontWeight: 800 }}>{a.status_label}</span>
                      {a.priority && <span style={{ fontSize: 10.5, fontWeight: 700, color: 'var(--text-muted)' }}>{a.priority}</span>}
                      {a.is_overdue && <span style={{ fontSize: 10.5, fontWeight: 800, color: '#ef4444' }}>OVERDUE</span>}
                    </div>
                    <div style={{ fontSize: 13, color: 'var(--text-h)', marginTop: 5, lineHeight: 1.4 }}>{a.description}</div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginTop: 5, fontSize: 11.5, color: 'var(--text-muted)' }}>
                      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><UserCheck size={12} /> {ownerLabel(a)}</span>
                      {a.target_date && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><CalendarDays size={12} /> {fmtDate(a.target_date)}</span>}
                      {a.has_evidence && <button onClick={() => viewEvidence(a)} style={{ display: 'inline-flex', alignItems: 'center', gap: 4, cursor: 'pointer', color: '#10b981', background: 'none', border: 'none', fontSize: 11.5, fontWeight: 700, padding: 0 }}><Paperclip size={12} /> Evidence</button>}
                    </div>
                    {a.verification_note && <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 4, fontStyle: 'italic' }}>“{a.verification_note}”</div>}
                  </div>
                  <button onClick={() => del(a)} title="Delete action" style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', flexShrink: 0, padding: 2 }}><Trash2 size={14} /></button>
                </div>
                {nexts.length > 0 && (
                  <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: 9 }}>
                    {nexts.map(to => {
                      const tc = pkActionCfg(to)
                      return (
                        <button key={to} onClick={() => setProgress({ action: a, to })}
                          style={{ padding: '5px 10px', borderRadius: 8, cursor: 'pointer', fontSize: 11, fontWeight: 700, color: tc.color, background: `${tc.color}12`, border: `1px solid ${tc.color}44` }}>
                          {tc.label}
                        </button>
                      )
                    })}
                  </div>
                )}
              </div>
            )
          })}
        </div>
      )}

      {progress && (
        <ActionProgressModal meetingId={m.id} action={progress.action} to={progress.to}
          onClose={() => setProgress(null)}
          onDone={async () => { setProgress(null); onError(null); await refresh() }}
          onError={onError} />
      )}
    </div>
  )
}

/* Progress an action to a new status, optionally attaching evidence / a note.
 * Closing requires evidence or a verification note (Rule 12) — enforced here and
 * on the server. */
function ActionProgressModal({ meetingId, action, to, onClose, onDone, onError }) {
  const [note, setNote] = useState('')
  const [file, setFile] = useState(null)
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)
  const tc = pkActionCfg(to)
  const closing = to === PK_ACTION_STATUS.CLOSED
  const needsProof = closing && !note.trim() && !file

  const save = async () => {
    if (needsProof) { setErr('Closing needs evidence or a verification note (Rule 12).'); return }
    setSaving(true); setErr(null)
    try {
      const data = { status: to }
      if (note.trim()) data.verification_note = note
      await purchaseApi.kickoff.actions.progress(meetingId, action.id, data, file)
      await onDone()
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not update the action.')
      setSaving(false)
    }
  }

  return (
    <Overlay onClose={onClose} width={440}>
      <div style={{ padding: '20px 22px 6px' }}>
        <h2 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>Move to {tc.label}</h2>
        <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>{action.action_ref ? `${action.action_ref} · ` : ''}{action.description}</p>
      </div>
      <div style={{ padding: '10px 22px' }}>
        <Field label={closing ? 'Verification note (evidence or a note is required)' : 'Note (optional)'} full>
          <textarea rows={3} value={note} onChange={e => setNote(e.target.value)} placeholder="How was this action verified / progressed?"
            style={{ width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, resize: 'vertical', boxSizing: 'border-box' }} />
        </Field>
        <label style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 4, padding: '9px 12px', borderRadius: 10, cursor: 'pointer', background: 'var(--bg-input)', border: '1px dashed var(--border)' }}>
          <Paperclip size={14} style={{ color: '#a78bfa' }} />
          <span style={{ fontSize: 12.5, color: file ? 'var(--text-h)' : 'var(--text-muted)', flex: 1 }}>{file ? file.name : 'Attach evidence (optional)'}</span>
          <input type="file" onChange={e => setFile(e.target.files?.[0] || null)} style={{ display: 'none' }} />
        </label>
        {err && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 12px', borderRadius: 10, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)', marginTop: 8 }}>
            <AlertTriangle size={14} style={{ color: '#ef4444', flexShrink: 0 }} />
            <span style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{err}</span>
          </div>
        )}
      </div>
      <ModalFooter onClose={onClose} onConfirm={save} loading={saving} confirmLabel={`Move to ${tc.label}`} color={tc.color === '#94a3b8' ? '#7C3AED' : tc.color} />
    </Overlay>
  )
}

const labelSm = { fontSize: 10.5, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 3 }
const selStyle = { width: '100%', padding: '8px 10px', background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, boxSizing: 'border-box' }

/* ── Issue register card (MOM issues) ─────────────────────────────────────────
 * Issues raised in the meeting, tracked to resolution and escalatable to an NCR
 * or a CAPA. Mirrors PurchaseMomIssueStatus; conversion routes into the existing
 * Purchase NCR / CAPA registers. */
function IssueRegisterCard({ m, onError }) {
  const [rows, setRows] = useState(m.mom_issues || [])
  const [adding, setAdding] = useState(false)
  const [busyId, setBusyId] = useState(null)
  const [form, setForm] = useState({ title: '', description: '', category: '', severity: '', owner_participant_id: '', due_date: '' })
  const [saving, setSaving] = useState(false)

  useEffect(() => { setRows(m.mom_issues || []) }, [m.mom_issues])

  const refresh = async () => {
    try { setRows(await purchaseApi.kickoff.issues.list(m.id)) } catch { /* keep stale */ }
  }

  const add = async () => {
    if (!form.title.trim()) { onError('An issue needs a title.'); return }
    setSaving(true); onError(null)
    try {
      const payload = {
        title: form.title, description: form.description || undefined,
        category: form.category || undefined, severity: form.severity || undefined,
        due_date: form.due_date || undefined,
      }
      if (form.owner_participant_id) payload.owner_participant_id = Number(form.owner_participant_id)
      await purchaseApi.kickoff.issues.create(m.id, payload)
      setForm({ title: '', description: '', category: '', severity: '', owner_participant_id: '', due_date: '' })
      setAdding(false)
      await refresh()
    } catch (e) { onError(e?.response?.data?.message || 'Could not add the issue.') }
    finally { setSaving(false) }
  }

  const progress = async (i, status) => {
    setBusyId(i.id); onError(null)
    try { await purchaseApi.kickoff.issues.progress(m.id, i.id, status); await refresh() }
    catch (e) { onError(e?.response?.data?.message || 'Could not update the issue.') }
    finally { setBusyId(null) }
  }

  const convert = async (i, target) => {
    setBusyId(i.id); onError(null)
    try { await purchaseApi.kickoff.issues.convert(m.id, i.id, target); await refresh() }
    catch (e) { onError(e?.response?.data?.message || `Could not convert to ${target.toUpperCase()}.`) }
    finally { setBusyId(null) }
  }

  const del = async (i) => {
    onError(null)
    try { await purchaseApi.kickoff.issues.remove(m.id, i.id); await refresh() }
    catch (e) { onError(e?.response?.data?.message || 'Could not delete the issue.') }
  }

  const ownerLabel = (i) => i.owner?.name || i.owner_names || '—'
  const openCount = rows.filter(r => r.is_open).length
  const sevColor = (s) => ({ Critical: '#ef4444', High: '#f59e0b', Medium: '#0ea5e9', Low: '#94a3b8' }[s] || 'var(--text-muted)')

  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8 }}>
        <SectionTitle icon={AlertOctagon}>Issues <span style={{ fontWeight: 500, color: 'var(--text-muted)', fontSize: 12 }}>· {rows.length}{openCount ? ` · ${openCount} open` : ''}</span></SectionTitle>
        <button onClick={() => setAdding(v => !v)} style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 11px', borderRadius: 9, cursor: 'pointer', fontSize: 12, fontWeight: 700, color: '#a78bfa', background: 'rgba(124,58,237,0.12)', border: '1px solid rgba(124,58,237,0.4)' }}>
          <Plus size={13} /> Add
        </button>
      </div>

      {adding && (
        <div style={{ marginTop: 12, padding: 14, borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <TextInput value={form.title} onChange={e => setForm(f => ({ ...f, title: e.target.value }))} placeholder="Issue title" />
          <textarea rows={2} value={form.description} onChange={e => setForm(f => ({ ...f, description: e.target.value }))} placeholder="Describe the issue (optional)"
            style={{ width: '100%', marginTop: 8, padding: '9px 12px', background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, resize: 'vertical', boxSizing: 'border-box' }} />
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 8, marginTop: 8 }}>
            <div>
              <div style={labelSm}>Category</div>
              <select value={form.category} onChange={e => setForm(f => ({ ...f, category: e.target.value }))} style={selStyle}>
                <option value="">—</option>
                {PK_ISSUE_CATEGORIES.map(c => <option key={c} value={c}>{c}</option>)}
              </select>
            </div>
            <div>
              <div style={labelSm}>Severity</div>
              <select value={form.severity} onChange={e => setForm(f => ({ ...f, severity: e.target.value }))} style={selStyle}>
                <option value="">—</option>
                {PK_ISSUE_SEVERITIES.map(s => <option key={s} value={s}>{s}</option>)}
              </select>
            </div>
            <div>
              <div style={labelSm}>Owner</div>
              <select value={form.owner_participant_id} onChange={e => setForm(f => ({ ...f, owner_participant_id: e.target.value }))} style={selStyle}>
                <option value="">— unassigned —</option>
                {(m.participants || []).map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            </div>
            <div>
              <div style={labelSm}>Due date</div>
              <TextInput type="date" value={form.due_date} onChange={e => setForm(f => ({ ...f, due_date: e.target.value }))} />
            </div>
          </div>
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 10 }}>
            <MomBtn onClick={() => setAdding(false)} icon={XCircle} tone="#94a3b8">Cancel</MomBtn>
            <MomBtn onClick={add} busy={saving} icon={Plus} tone="#7C3AED">Add issue</MomBtn>
          </div>
        </div>
      )}

      {rows.length === 0 ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '12px 0 0' }}>No issues raised.</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginTop: 12 }}>
          {rows.map(i => {
            const cfg = pkIssueCfg(i.status)
            const nexts = pkIssueNext(i.status)
            return (
              <div key={i.id} style={{ padding: '12px 13px', borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: 8 }}>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 7, flexWrap: 'wrap' }}>
                      {i.issue_ref && <span style={{ fontSize: 10.5, fontWeight: 800, color: '#a78bfa', fontFamily: 'monospace' }}>{i.issue_ref}</span>}
                      <span style={{ padding: '2px 8px', borderRadius: 999, background: cfg.bg, color: cfg.color, fontSize: 10.5, fontWeight: 800 }}>{i.status_label}</span>
                      {i.severity && <span style={{ fontSize: 10.5, fontWeight: 800, color: sevColor(i.severity) }}>{i.severity}</span>}
                      {i.category && <span style={{ fontSize: 10.5, fontWeight: 700, color: 'var(--text-muted)' }}>{i.category}</span>}
                      {i.is_overdue && <span style={{ fontSize: 10.5, fontWeight: 800, color: '#ef4444' }}>OVERDUE</span>}
                      {i.is_converted && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3, fontSize: 10.5, fontWeight: 800, color: '#10b981' }}><ArrowUpRight size={11} /> {i.converted_to} {i.converted_ref}</span>}
                    </div>
                    <div style={{ fontSize: 13, fontWeight: 600, color: 'var(--text-h)', marginTop: 5 }}>{i.title}</div>
                    {i.description && <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 3, lineHeight: 1.4 }}>{i.description}</div>}
                    <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginTop: 5, fontSize: 11.5, color: 'var(--text-muted)' }}>
                      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><UserCheck size={12} /> {ownerLabel(i)}</span>
                      {i.due_date && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><CalendarDays size={12} /> {fmtDate(i.due_date)}</span>}
                    </div>
                  </div>
                  <button onClick={() => del(i)} title="Delete issue" style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', flexShrink: 0, padding: 2 }}><Trash2 size={14} /></button>
                </div>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: 9 }}>
                  {nexts.map(to => {
                    const tc = pkIssueCfg(to)
                    return (
                      <button key={to} disabled={busyId === i.id} onClick={() => progress(i, to)}
                        style={{ padding: '5px 10px', borderRadius: 8, cursor: busyId === i.id ? 'wait' : 'pointer', fontSize: 11, fontWeight: 700, color: tc.color, background: `${tc.color}12`, border: `1px solid ${tc.color}44` }}>
                        {tc.label}
                      </button>
                    )
                  })}
                  {!i.is_converted && (
                    <>
                      <button disabled={busyId === i.id} onClick={() => convert(i, 'ncr')}
                        style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '5px 10px', borderRadius: 8, cursor: busyId === i.id ? 'wait' : 'pointer', fontSize: 11, fontWeight: 700, color: '#ef4444', background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)' }}>
                        <ArrowUpRight size={12} /> To NCR
                      </button>
                      <button disabled={busyId === i.id} onClick={() => convert(i, 'capa')}
                        style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '5px 10px', borderRadius: 8, cursor: busyId === i.id ? 'wait' : 'pointer', fontSize: 11, fontWeight: 700, color: '#f59e0b', background: 'rgba(245,158,11,0.1)', border: '1px solid rgba(245,158,11,0.4)' }}>
                        <ArrowUpRight size={12} /> To CAPA
                      </button>
                      <button disabled={busyId === i.id} onClick={() => convert(i, 'approval')}
                        style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '5px 10px', borderRadius: 8, cursor: busyId === i.id ? 'wait' : 'pointer', fontSize: 11, fontWeight: 700, color: '#0ea5e9', background: 'rgba(14,165,233,0.1)', border: '1px solid rgba(14,165,233,0.4)' }}>
                        <ArrowUpRight size={12} /> To Approval
                      </button>
                    </>
                  )}
                </div>
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}

/* ── Decision register card (MOM decisions) ───────────────────────────────────
 * The durable record of decisions taken in the meeting — each Active until
 * Superseded or Rescinded. Mirrors PurchaseMomDecisionStatus. */
function DecisionRegisterCard({ m, onError }) {
  const [rows, setRows] = useState(m.mom_decisions || [])
  const [adding, setAdding] = useState(false)
  const [busyId, setBusyId] = useState(null)
  const [form, setForm] = useState({ decision: '', decided_by_participant_id: '', impact: '', effective_date: '' })
  const [saving, setSaving] = useState(false)

  useEffect(() => { setRows(m.mom_decisions || []) }, [m.mom_decisions])

  const refresh = async () => {
    try { setRows(await purchaseApi.kickoff.decisions.list(m.id)) } catch { /* keep stale */ }
  }

  const add = async () => {
    if (!form.decision.trim()) { onError('A decision needs a description.'); return }
    setSaving(true); onError(null)
    try {
      const payload = { decision: form.decision, impact: form.impact || undefined, effective_date: form.effective_date || undefined }
      if (form.decided_by_participant_id) payload.decided_by_participant_id = Number(form.decided_by_participant_id)
      await purchaseApi.kickoff.decisions.create(m.id, payload)
      setForm({ decision: '', decided_by_participant_id: '', impact: '', effective_date: '' })
      setAdding(false)
      await refresh()
    } catch (e) { onError(e?.response?.data?.message || 'Could not record the decision.') }
    finally { setSaving(false) }
  }

  const setStatus = async (d, status) => {
    setBusyId(d.id); onError(null)
    try { await purchaseApi.kickoff.decisions.update(m.id, d.id, { status }); await refresh() }
    catch (e) { onError(e?.response?.data?.message || 'Could not update the decision.') }
    finally { setBusyId(null) }
  }

  const del = async (d) => {
    onError(null)
    try { await purchaseApi.kickoff.decisions.remove(m.id, d.id); await refresh() }
    catch (e) { onError(e?.response?.data?.message || 'Could not delete the decision.') }
  }

  const madeBy = (d) => d.decided_by?.name || d.decided_by_names || '—'

  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8 }}>
        <SectionTitle icon={Gavel}>Decisions <span style={{ fontWeight: 500, color: 'var(--text-muted)', fontSize: 12 }}>· {rows.length}</span></SectionTitle>
        <button onClick={() => setAdding(v => !v)} style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 11px', borderRadius: 9, cursor: 'pointer', fontSize: 12, fontWeight: 700, color: '#a78bfa', background: 'rgba(124,58,237,0.12)', border: '1px solid rgba(124,58,237,0.4)' }}>
          <Plus size={13} /> Add
        </button>
      </div>

      {adding && (
        <div style={{ marginTop: 12, padding: 14, borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <textarea rows={2} value={form.decision} onChange={e => setForm(f => ({ ...f, decision: e.target.value }))} placeholder="What was decided?"
            style={{ width: '100%', padding: '9px 12px', background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, resize: 'vertical', boxSizing: 'border-box' }} />
          <textarea rows={2} value={form.impact} onChange={e => setForm(f => ({ ...f, impact: e.target.value }))} placeholder="Impact (optional)"
            style={{ width: '100%', marginTop: 8, padding: '9px 12px', background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, resize: 'vertical', boxSizing: 'border-box' }} />
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 8, marginTop: 8 }}>
            <div>
              <div style={labelSm}>Decided by</div>
              <select value={form.decided_by_participant_id} onChange={e => setForm(f => ({ ...f, decided_by_participant_id: e.target.value }))} style={selStyle}>
                <option value="">— unassigned —</option>
                {(m.participants || []).map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            </div>
            <div>
              <div style={labelSm}>Effective date</div>
              <TextInput type="date" value={form.effective_date} onChange={e => setForm(f => ({ ...f, effective_date: e.target.value }))} />
            </div>
          </div>
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 10 }}>
            <MomBtn onClick={() => setAdding(false)} icon={XCircle} tone="#94a3b8">Cancel</MomBtn>
            <MomBtn onClick={add} busy={saving} icon={Plus} tone="#7C3AED">Record decision</MomBtn>
          </div>
        </div>
      )}

      {rows.length === 0 ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '12px 0 0' }}>No decisions recorded.</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginTop: 12 }}>
          {rows.map(d => {
            const cfg = pkDecisionCfg(d.status)
            return (
              <div key={d.id} style={{ padding: '12px 13px', borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: 8 }}>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 7, flexWrap: 'wrap' }}>
                      {d.decision_ref && <span style={{ fontSize: 10.5, fontWeight: 800, color: '#a78bfa', fontFamily: 'monospace' }}>{d.decision_ref}</span>}
                      <span style={{ padding: '2px 8px', borderRadius: 999, background: cfg.bg, color: cfg.color, fontSize: 10.5, fontWeight: 800 }}>{d.status_label}</span>
                    </div>
                    <div style={{ fontSize: 13, color: 'var(--text-h)', marginTop: 5, lineHeight: 1.4 }}>{d.decision}</div>
                    {d.impact && <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 3, lineHeight: 1.4 }}>Impact: {d.impact}</div>}
                    <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginTop: 5, fontSize: 11.5, color: 'var(--text-muted)' }}>
                      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><UserCheck size={12} /> {madeBy(d)}</span>
                      {d.effective_date && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><CalendarDays size={12} /> {fmtDate(d.effective_date)}</span>}
                    </div>
                  </div>
                  <button onClick={() => del(d)} title="Delete decision" style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', flexShrink: 0, padding: 2 }}><Trash2 size={14} /></button>
                </div>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, marginTop: 9 }}>
                  {PK_DECISION_STATUSES.filter(s => s !== d.status).map(s => {
                    const sc = pkDecisionCfg(s)
                    return (
                      <button key={s} disabled={busyId === d.id} onClick={() => setStatus(d, s)}
                        style={{ padding: '5px 10px', borderRadius: 8, cursor: busyId === d.id ? 'wait' : 'pointer', fontSize: 11, fontWeight: 700, color: sc.color, background: `${sc.color}12`, border: `1px solid ${sc.color}44` }}>
                        Mark {sc.label}
                      </button>
                    )
                  })}
                </div>
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}

/* ── Previous-MOM continuity card (Meeting.docx §11) ─────────────────────────── */
function PreviousSummaryCard({ m, onError, onChanged }) {
  const toast = useToast()
  const [sum, setSum] = useState(undefined)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    purchaseApi.kickoff.previousSummary(m.id).then(setSum).catch(() => setSum(null))
  }, [m.id])

  if (sum === undefined || !sum?.previous) return null

  const carry = async () => {
    setBusy(true); onError(null)
    try {
      const r = await purchaseApi.kickoff.carryForward(m.id)
      await onChanged()
      toast.success(`Carried forward ${r.actions} action(s) and ${r.issues} issue(s) from ${r.from}.`)
    } catch (e) { onError(e?.response?.data?.message || 'Could not carry forward.') }
    finally { setBusy(false) }
  }

  const a = sum.actions || {}
  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <SectionTitle icon={History}>Previous meeting</SectionTitle>
      <div style={{ fontSize: 12.5, color: 'var(--text-h)', marginTop: 10 }}>
        <strong>{sum.previous.meeting_no || sum.previous.reference}</strong> — {sum.previous.title}
      </div>
      <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap', marginTop: 10, fontSize: 12 }}>
        <span>Actions: <strong style={{ color: 'var(--text-h)' }}>{a.total || 0}</strong></span>
        <span style={{ color: '#10b981' }}>{a.closed || 0} closed</span>
        <span style={{ color: '#0ea5e9' }}>{a.open || 0} open</span>
        <span style={{ color: '#ef4444' }}>{a.overdue || 0} overdue</span>
        <span>Issues open: <strong style={{ color: 'var(--text-h)' }}>{sum.issues?.open || 0}</strong></span>
      </div>
      {((a.open || 0) > 0 || (sum.issues?.open || 0) > 0) && (
        <button onClick={carry} disabled={busy} style={{ ...solidBtn, marginTop: 12, background: 'linear-gradient(145deg,#34d399,#10b981)', boxShadow: 'none' }}>
          <CopyPlus size={15} /> {busy ? 'Carrying…' : 'Carry forward open items'}
        </button>
      )}
    </div>
  )
}

/* ── Agenda builder card (Meeting.docx §3/§4) ─────────────────────────────────── */
function AgendaCard({ m, onError }) {
  const [rows, setRows] = useState(m.agenda_items || [])
  const [adding, setAdding] = useState(false)
  const [busy, setBusy] = useState(null)
  const [form, setForm] = useState({ item: '', owner_names: '', duration_minutes: '', priority: '' })
  const [saving, setSaving] = useState(false)

  useEffect(() => { setRows(m.agenda_items || []) }, [m.agenda_items])
  const refresh = async () => { try { setRows(await purchaseApi.kickoff.agenda.list(m.id)) } catch { /* keep */ } }

  const add = async () => {
    if (!form.item.trim()) { onError('An agenda item needs a title.'); return }
    setSaving(true); onError(null)
    try {
      await purchaseApi.kickoff.agenda.create(m.id, {
        item: form.item, owner_names: form.owner_names || undefined,
        duration_minutes: form.duration_minutes ? Number(form.duration_minutes) : undefined,
        priority: form.priority || undefined,
      })
      setForm({ item: '', owner_names: '', duration_minutes: '', priority: '' }); setAdding(false); await refresh()
    } catch (e) { onError(e?.response?.data?.message || 'Could not add the agenda item.') }
    finally { setSaving(false) }
  }
  const del = async (a) => { onError(null); try { await purchaseApi.kickoff.agenda.remove(m.id, a.id); await refresh() } catch (e) { onError(e?.response?.data?.message || 'Could not delete.') } }
  const loadTemplate = async () => { setBusy('t'); onError(null); try { setRows(await purchaseApi.kickoff.agenda.loadTemplate(m.id)) } catch (e) { onError(e?.response?.data?.message || 'No template for this type.') } finally { setBusy(null) } }
  const copyPrev = async () => { setBusy('c'); onError(null); try { setRows(await purchaseApi.kickoff.agenda.copyPrevious(m.id)) } catch (e) { onError(e?.response?.data?.message || 'No previous agenda.') } finally { setBusy(null) } }

  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8, flexWrap: 'wrap' }}>
        <SectionTitle icon={ListOrdered}>Agenda <span style={{ fontWeight: 500, color: 'var(--text-muted)', fontSize: 12 }}>· {rows.length}</span></SectionTitle>
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
          <MomBtn onClick={loadTemplate} busy={busy === 't'} icon={LayoutTemplate} tone="#0ea5e9">Template</MomBtn>
          <MomBtn onClick={copyPrev} busy={busy === 'c'} icon={CopyPlus} tone="#10b981">Copy previous</MomBtn>
          <MomBtn onClick={() => setAdding(v => !v)} icon={Plus} tone="#7C3AED">Add</MomBtn>
        </div>
      </div>

      {adding && (
        <div style={{ marginTop: 12, padding: 14, borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <TextInput value={form.item} onChange={e => setForm(f => ({ ...f, item: e.target.value }))} placeholder="Agenda item" />
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 8, marginTop: 8 }}>
            <TextInput value={form.owner_names} onChange={e => setForm(f => ({ ...f, owner_names: e.target.value }))} placeholder="Owner" />
            <TextInput type="number" value={form.duration_minutes} onChange={e => setForm(f => ({ ...f, duration_minutes: e.target.value }))} placeholder="Min" />
            <select value={form.priority} onChange={e => setForm(f => ({ ...f, priority: e.target.value }))} style={selStyle}>
              <option value="">Priority</option>{['Low', 'Medium', 'High'].map(p => <option key={p} value={p}>{p}</option>)}
            </select>
          </div>
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 10 }}>
            <MomBtn onClick={() => setAdding(false)} icon={XCircle} tone="#94a3b8">Cancel</MomBtn>
            <MomBtn onClick={add} busy={saving} icon={Plus} tone="#7C3AED">Add item</MomBtn>
          </div>
        </div>
      )}

      {rows.length === 0 ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '12px 0 0' }}>No agenda yet. Add items, load the template, or copy the previous meeting's agenda.</p>
      ) : (
        <div style={{ marginTop: 12 }}>
          {rows.map((a, idx) => (
            <div key={a.id} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '9px 0', borderTop: idx ? '1px solid var(--border)' : 'none' }}>
              <span style={{ fontSize: 12, fontWeight: 800, color: '#a78bfa', width: 20 }}>{idx + 1}</span>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontSize: 13, color: 'var(--text-h)' }}>{a.item}</div>
                <div style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{[a.owner?.name || a.owner_names, a.duration_minutes ? `${a.duration_minutes} min` : null, a.priority].filter(Boolean).join(' · ') || '—'}</div>
              </div>
              <button onClick={() => del(a)} title="Delete" style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', padding: 2 }}><Trash2 size={14} /></button>
            </div>
          ))}
        </div>
      )}
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
  if (to === PK_STATUS.CANCELLED) return from === PK_STATUS.DRAFT ? 'Discard draft' : 'Cancel meeting'
  if (to === PK_STATUS.SCHEDULED) {
    if (from === PK_STATUS.DRAFT) return 'Publish Meeting'
    return from === PK_STATUS.CANCELLED ? 'Reopen' : 'Reschedule'
  }
  return to
}

/* ── Labelled supporting documents (multiple upload) ───────────────────────── */
function PkDocumentsCard({ meetingId, onError }) {
  const [docs, setDocs]     = useState([])
  const [staged, setStaged] = useState([])
  const [busy, setBusy]     = useState(false)

  const load = () => purchaseApi.kickoff.documents(meetingId).then(d => setDocs(d ?? [])).catch(() => {})
  useEffect(() => { load() }, [meetingId]) // eslint-disable-line react-hooks/exhaustive-deps

  const stage = (e) => {
    const files = Array.from(e.target.files || [])
    setStaged(prev => [...prev, ...files.map(f => ({ file: f, label: f.name.replace(/\.[^.]+$/, '') }))])
    e.target.value = ''
  }
  const upload = async () => {
    if (!staged.length) return
    setBusy(true); onError(null)
    try {
      await purchaseApi.kickoff.uploadDocuments(meetingId, staged.map(s => s.file), staged.map(s => s.label))
      setStaged([]); await load()
    } catch (e) { onError(e?.response?.data?.message || 'Could not upload the documents.') }
    finally { setBusy(false) }
  }
  const download = async (doc) => {
    try {
      const blob = await purchaseApi.kickoff.documentBlob(meetingId, doc.id)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a'); a.href = url; a.download = doc.original_name || doc.label
      document.body.appendChild(a); a.click(); a.remove()
      setTimeout(() => URL.revokeObjectURL(url), 60000)
    } catch { onError('Could not download the document.') }
  }
  const remove = async (doc) => {
    try { await purchaseApi.kickoff.deleteDocument(meetingId, doc.id); await load() }
    catch { onError('Could not remove the document.') }
  }

  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <SectionTitle icon={FileText}>Documents</SectionTitle>
      <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '4px 0 12px', lineHeight: 1.5 }}>
        Attach any number of files — name each one. Shared with the vendor once the minutes are sent.
      </p>
      {docs.length > 0 && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 7, marginBottom: 12 }}>
          {docs.map(doc => (
            <div key={doc.id} style={{ display: 'flex', alignItems: 'center', gap: 9, padding: '9px 11px', borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
              <FileText size={15} style={{ color: '#a78bfa', flexShrink: 0 }} />
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{doc.label}</div>
                <div style={{ fontSize: 10.5, color: 'var(--text-muted)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{doc.original_name}</div>
              </div>
              <button onClick={() => download(doc)} title="Download" style={{ width: 30, height: 30, borderRadius: 8, cursor: 'pointer', background: 'rgba(14,165,233,0.1)', border: '1px solid rgba(14,165,233,0.35)', color: '#0ea5e9', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}><Download size={14} /></button>
              <button onClick={() => remove(doc)} title="Remove" style={{ width: 30, height: 30, borderRadius: 8, cursor: 'pointer', background: 'rgba(239,68,68,0.08)', border: '1px solid rgba(239,68,68,0.3)', color: '#ef4444', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}><Trash2 size={14} /></button>
            </div>
          ))}
        </div>
      )}
      {staged.length > 0 && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 7, marginBottom: 10 }}>
          {staged.map((s, i) => (
            <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 10px', borderRadius: 10, background: 'rgba(124,58,237,0.06)', border: '1px dashed rgba(124,58,237,0.4)' }}>
              <TextInput value={s.label} onChange={e => setStaged(prev => prev.map((x, idx) => idx === i ? { ...x, label: e.target.value } : x))} placeholder="Name this document" />
              <button onClick={() => setStaged(prev => prev.filter((_, idx) => idx !== i))} style={{ width: 28, height: 28, borderRadius: 8, cursor: 'pointer', background: 'transparent', border: '1px solid var(--border)', color: 'var(--text-muted)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}><XCircle size={14} /></button>
            </div>
          ))}
        </div>
      )}
      <div style={{ display: 'flex', gap: 8 }}>
        <label style={{ flex: staged.length ? 'unset' : 1, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 6, padding: '9px 12px', borderRadius: 10, cursor: 'pointer', fontSize: 12.5, fontWeight: 700, color: '#a78bfa', background: 'rgba(124,58,237,0.1)', border: '1px dashed rgba(124,58,237,0.4)' }}>
          <Upload size={14} /> Add files
          <input type="file" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg" onChange={stage} style={{ display: 'none' }} />
        </label>
        {staged.length > 0 && (
          <button onClick={upload} disabled={busy} style={{ flex: 1, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 6, padding: '9px 12px', borderRadius: 10, cursor: busy ? 'wait' : 'pointer', fontSize: 12.5, fontWeight: 800, color: '#fff', border: 'none', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)' }}>
            {busy ? <Loader2 size={14} className="pk-spin" /> : <Upload size={14} />} {busy ? 'Uploading…' : `Upload ${staged.length}`}
          </button>
        )}
      </div>
      {docs.length === 0 && staged.length === 0 && (
        <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '10px 0 0', textAlign: 'center' }}>No documents attached yet.</p>
      )}
    </div>
  )
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
