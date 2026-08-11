import { useState, useEffect, useCallback, useRef } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, RefreshCw, Upload, RotateCcw, Eye, Trash2, CheckCircle, XCircle, PauseCircle,
  ShieldCheck, FileText, ClipboardList, UserCheck, Rocket, Send, Loader,
  AlertTriangle, Check, CornerUpLeft, CalendarDays, Clock, MapPin, Users, ArrowRight, Plus,
  Download, Printer, ZoomIn, ZoomOut, History, Info, HelpCircle, ChevronRight,
} from 'lucide-react'
import { useVendorModule } from '@/modules/tpv/useVendorModule'
import { kickoffApi } from '@/services/kickoffApi'
import { koStatusCfg, koModeLabel, fmtDateTime } from '@/modules/shared/kickoffConstants'
import { useAuth } from '@/context/AuthContext'
import AuditTimeline from '@/components/ui/AuditTimeline'
import TemporaryAccessBanner from '@/modules/tpv/components/TemporaryAccessBanner'
import {
  DOC_STATUS, docStatusCfg, obStatusCfg, vendorStatusCfg, isOnboardingEditable,
  canApproveTpv, canManageTpv, fmtDate,
} from '../constants'
import {
  KIT3D_STYLE, labelStyle, inputStyle, Overlay, ModalFooter, InfoBox,
  Field, TextInput, StatusBadge as StatusPill,
} from '@/components/ui/kit3d'
import PortalStepProgress from '@/components/ui/PortalStepProgress'
import '@/pages/vendor-portal/portal.css'

const STEP_ICONS = { kickoff: ClipboardList, profile: UserCheck, documents: FileText, review: ShieldCheck, confirmation: Check, submission: Rocket }
const STEP_COLORS = { kickoff: '#8b5cf6', profile: '#0ea5e9', documents: '#f59e0b', review: '#a855f7', confirmation: '#14b8a6', submission: '#10b981' }

const EMPTY_PROFILE = {
  // Personal Information
  full_name: '', dob: '', email: '', mobile: '', gender: '', alt_mobile: '', profile_photo: '',
  // Company Details
  company_name: '', legal_name: '', company_registration_number: '', registration_date: '', category: '', company_phone: '', website: '',
  // Social Media Profiles
  facebook: '', linkedin: '', twitter: '', instagram: '', youtube: '', portfolio: '',
  // Contact Details
  contact_person: '', designation: '', contact_email: '', contact_mobile: '', emergency_contact: '', emergency_phone: '',
  // Authorized Person
  authorized_name: '', authorized_designation: '', authorized_email: '', authorized_mobile: '', authorized_id_proof: '',
  // Bank Details
  bank_account_holder: '', bank_name: '', bank_account_number: '', bank_ifsc: '', bank_branch: '', bank_account_type: '',
  // GST & PAN
  gst_number: '', gst_state: '', pan_number: '',
  // Registered Address
  registered_address: '', city: '', state: '', country: '', pincode: '',
  // Engagement
  estimated_workforce: '', scope_of_work: '',
}

// Client-side format checks (server re-validates, incl. the GSTIN checksum).
const RE = {
  gstin: /^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/i,
  pan:   /^[A-Z]{5}[0-9]{4}[A-Z]$/i,
  ifsc:  /^[A-Z]{4}0[A-Z0-9]{6}$/i,
  acct:  /^[0-9]{9,18}$/,
  pin:   /^[0-9]{6}$/,
}

function validateProfile(f, acctConfirm) {
  const e = {}
  if (f.gst_number && !RE.gstin.test(f.gst_number)) e.gst_number = 'Invalid GSTIN format'
  if (f.pan_number && !RE.pan.test(f.pan_number)) e.pan_number = 'Invalid PAN (AAAAA9999A)'
  if (f.bank_account_number && !RE.acct.test(f.bank_account_number)) e.bank_account_number = '9–18 digits'
  if (f.bank_ifsc && !RE.ifsc.test(f.bank_ifsc)) e.bank_ifsc = 'Invalid IFSC'
  if (f.bank_account_number && !f.bank_ifsc) e.bank_ifsc = 'IFSC required with account number'
  if (f.bank_ifsc && !f.bank_account_number) e.bank_account_number = 'Account number required with IFSC'
  if (f.bank_account_number && acctConfirm !== f.bank_account_number) e.bank_account_confirm = 'Account numbers do not match'
  if (f.pincode && !RE.pin.test(f.pincode)) e.pincode = '6 digits'
  return e
}

// ── Main ─────────────────────────────────────────────────────────────────────
export default function TpvOnboardingWizard() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const cfg = useVendorModule()
  const admin  = cfg.canApprove(user)
  const manage = cfg.canManage(user)
  // Data source + routing resolved from the module context (TPV / Purchase / portals).
  const isPortal = cfg.portal
  const api = cfg.api
  const backHref = cfg.onboardingListPath

  const [onboarding, setOnboarding] = useState(null)
  const [progress, setProgress]     = useState(null)
  const [loading, setLoading]       = useState(true)
  const [active, setActive]         = useState(1)

  const load = useCallback(async (keepStep = false) => {
    try {
      const res = await api.onboarding.get(id)
      const ob = res?.onboarding ?? res?.data?.onboarding
      const pr = res?.progress ?? res?.data?.progress
      setOnboarding(ob); setProgress(pr)
      if (!keepStep) setActive(ob?.current_step || 1)
    } catch (e) { console.error('Failed to load onboarding', e) }
    finally { setLoading(false) }
  }, [id, api])
  useEffect(() => { load() }, [load])

  // Refresh only the derived state (after an upload/review) without losing the step.
  const refresh = () => load(true)

  const editable = isOnboardingEditable(onboarding?.status)

  const goStep = (step) => {
    setActive(step)
    if (editable) api.onboarding.setStep(id, step).catch(() => {})
  }

  if (loading || !onboarding || !progress) {
    return <div style={{ padding: 24, color: 'var(--text-muted)' }}>Loading onboarding…</div>
  }

  const vendor = onboarding.vendor || {}
  const steps  = progress.steps || []
  const activeStep = steps.find(s => s.step === active) || steps[0]

  // Map backend steps to PortalStepProgress shape (portal-only)
  const portalSteps = steps.map(s => ({ key: s.key, label: s.label }))

  return (
    <div style={{ padding: isPortal ? 0 : 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>

      {/* Temporary access countdown — shows across Steps 1–6 for a temporary vendor */}
      <TemporaryAccessBanner vendor={vendor} />

      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 16, flexWrap: 'wrap' }}>
        <button onClick={() => navigate(backHref)} title="Back to onboardings"
          style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 12px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12.5 }}>
          <ArrowLeft size={14} /> Back
        </button>
        <div>
          <h1 style={{ color: 'var(--text-h)', fontSize: 20, fontWeight: 800, margin: 0 }}>{vendor.company_name || 'Vendor'} <span style={{ color: '#a78bfa', fontSize: 13, fontWeight: 700 }}>{vendor.vendor_code}</span></h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '3px 0 0' }}>
            {progress.documents?.vendor_type === 'temporary' ? 'Temporary vendor · reduced document set' : 'Standard vendor · full statutory set'}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8, marginLeft: 'auto', alignItems: 'center' }}>
          <StatusPill cfg={vendorStatusCfg(vendor.status)} />
          <StatusPill cfg={obStatusCfg(onboarding.status)} />
          <button onClick={refresh} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 12px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12.5 }}>
            <RefreshCw size={13} /> Refresh
          </button>
        </div>
      </div>

      {onboarding.status === 'Resubmit_Required' && onboarding.remarks && (
        <InfoBox tone="danger"><strong>Sent back for revision:</strong> {onboarding.remarks}</InfoBox>
      )}

      {/* Portal users: modern horizontal step progress ABOVE the existing stepper */}
      {isPortal && portalSteps.length > 0 && (
        <PortalStepProgress steps={portalSteps} current={active} total={6} />
      )}

      {/* Stepper — existing 3D knob stepper (shown for admin; portal also shows it as secondary nav) */}
      <Stepper steps={steps} active={active} onGo={goStep} />

      {/* Step body — wrapped in a clean card for portal users */}
      <div style={isPortal ? {
        marginTop: 18,
        background: 'var(--bg-card)',
        border: '1px solid var(--border)',
        borderRadius: 20,
        padding: 32,
        boxShadow: '0 2px 16px rgba(0,0,0,0.08)',
      } : { marginTop: 18 }}>
        {active === 1 && <StepKickoff onboarding={onboarding} editable={editable} onAcknowledged={refresh} onContinue={() => goStep(2)} api={api} />}
        {active === 2 && <StepProfile onboarding={onboarding} editable={editable} onSaved={refresh} onBack={() => goStep(1)} onContinue={() => goStep(3)} api={api} user={user} />}
        {active === 3 && <StepDocuments checklist={progress.documents} vendorId={vendor.id} onboarding={onboarding} editable={editable} manage={manage} admin={false} onChanged={refresh} onBack={() => goStep(2)} onContinue={() => goStep(4)} api={api} user={user} />}
        {active === 4 && <StepDocuments checklist={progress.documents} vendorId={vendor.id} editable={editable} manage={manage} admin={admin} reviewMode onChanged={refresh} onContinue={() => goStep(5)} api={api} />}
        {active === 5 && <StepConfirmation onboarding={onboarding} progress={progress} editable={editable} onSaved={refresh} onBack={() => goStep(4)} onContinue={() => goStep(6)} onSubmitted={refresh} api={api} />}
        {active === 6 && <StepSubmission onboarding={onboarding} vendor={vendor} admin={admin} onChanged={refresh} onBack={() => goStep(5)} api={api} user={user} engagement={cfg.engagement} />}
      </div>

      {/* Audit */}
      <div className="pr-glass" style={{ padding: 20, marginTop: 16 }}>
        <label style={labelStyle}>Audit Trail</label>
        {onboarding.audit_logs === undefined
          ? <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>Timeline loads with the record.</p>
          : <AuditTimeline entries={onboarding.audit_logs} />}
      </div>
    </div>
  )
}

// ── Stepper — extruded 3D knobs with live completion ─────────────────────────
function Stepper({ steps, active, onGo }) {
  return (
    <div className="pr-glass" style={{ padding: 16 }}>
      <div style={{ display: 'flex', alignItems: 'center', width: 'max-content', minWidth: '100%', gap: 0 }}>
        {steps.map((s, i) => {
          const Icon = STEP_ICONS[s.key] || FileText
          const color = STEP_COLORS[s.key] || '#7C3AED'
          const isActive = s.step === active
          const lit = s.complete || isActive
          return (
            <div key={s.key} style={{ display: 'flex', alignItems: 'center', flex: 1, minWidth: 140 }}>
              <button type="button" onClick={() => onGo(s.step)} title={s.detail}
                className="pr-node" style={{
                  flex: 1, display: 'flex', alignItems: 'center', gap: 10, padding: '10px 13px', borderRadius: 15, cursor: 'pointer',
                  background: lit ? `linear-gradient(135deg, ${color}26, ${color}0f)` : 'var(--bg-input)',
                  border: `1.5px solid ${isActive ? color : s.complete ? color + '55' : 'var(--border)'}`,
                  opacity: lit ? 1 : 0.6,
                  boxShadow: isActive ? `0 10px 26px -8px ${color}88, inset 0 1px 0 rgba(255,255,255,.14)` : 'inset 0 1px 0 var(--card-shine)',
                }}>
                <span style={{ position: 'relative', width: 34, height: 34, borderRadius: 11, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `linear-gradient(145deg, ${color}, ${color}aa)`, color: '#fff', boxShadow: lit ? `0 6px 14px -3px ${color}99, inset 0 1px 0 rgba(255,255,255,.4)` : 'none', flexShrink: 0 }}>
                  <Icon size={15} />
                  {s.complete && (
                    <span style={{ position: 'absolute', right: -4, bottom: -4, width: 15, height: 15, borderRadius: '50%', background: '#10b981', border: '2px solid var(--bg-card)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                      <Check size={8} color="#fff" strokeWidth={4} />
                    </span>
                  )}
                </span>
                <span style={{ display: 'flex', flexDirection: 'column', lineHeight: 1.2, textAlign: 'left', minWidth: 0 }}>
                  <span style={{ fontSize: 8.5, fontWeight: 800, letterSpacing: '0.06em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>Step {s.step}</span>
                  <span style={{ fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)', whiteSpace: 'nowrap' }}>{s.label}</span>
                  <span style={{ fontSize: 9.5, fontWeight: 700, color, whiteSpace: 'nowrap' }}>{s.detail}</span>
                </span>
              </button>
              {i < steps.length - 1 && (
                <div className={`pr-flow${s.complete ? '' : ' pr-flow-dim'}`} style={{ width: 20, height: 3, borderRadius: 4, margin: '0 4px', flexShrink: 0, background: `linear-gradient(90deg, ${color}, ${STEP_COLORS[steps[i + 1].key] || '#7C3AED'})` }} />
              )}
            </div>
          )
        })}
      </div>
    </div>
  )
}

const Panel = ({ title, sub, children, actions }) => (
  <div className="pr-glass" style={{ padding: 22 }}>
    <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12, marginBottom: 16 }}>
      <div>
        <h2 style={{ margin: 0, fontSize: 15.5, fontWeight: 800, color: 'var(--text-h)' }}>{title}</h2>
        {sub && <p style={{ margin: '3px 0 0', fontSize: 12, color: 'var(--text-muted)' }}>{sub}</p>}
      </div>
      {actions}
    </div>
    {children}
  </div>
)

/**
 * The 48-hour acknowledgement window.
 *
 * Reads the state the backend already computes (acknowledgement_expired) rather
 * than comparing dates here — the server owns the clock, and a client whose
 * time is wrong must not be able to talk itself into an open window.
 */
function AckWindowBanner({ meeting }) {
  const expired  = !!meeting.acknowledgement_expired
  const deadline = meeting.acknowledgement_deadline
  const tone     = expired
    ? { bg: 'rgba(239,68,68,0.08)', bd: 'rgba(239,68,68,0.35)', fg: '#ef4444', Icon: AlertTriangle }
    : { bg: 'rgba(245,158,11,0.08)', bd: 'rgba(245,158,11,0.35)', fg: '#f59e0b', Icon: Clock }

  return (
    <div style={{ marginBottom: 16, padding: '14px 18px', borderRadius: 14, background: tone.bg, border: `1px solid ${tone.bd}` }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        <tone.Icon size={18} style={{ color: tone.fg }} />
        <span style={{ fontSize: 14, fontWeight: 800, color: tone.fg }}>
          {expired ? 'Acknowledgement window closed' : 'Acknowledgement pending'}
        </span>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 10, fontSize: 12, color: 'var(--text-h)', marginTop: 10 }}>
        <div><strong>MOM sent:</strong> {fmtDateTime(meeting.acknowledgement_sent_at)}</div>
        <div><strong>Deadline:</strong> {deadline ? fmtDateTime(deadline) : 'No deadline set'}</div>
      </div>
      <p style={{ margin: '10px 0 0', fontSize: 12.5, color: 'var(--text-muted)', lineHeight: 1.5 }}>
        {expired
          ? 'This acknowledgement link has expired. Please ask the coordinator to re-send the Minutes of Meeting so a fresh 48-hour window can be issued.'
          : deadline
            ? 'Please review and acknowledge the Minutes of Meeting within 48 hours of it being sent.'
            : 'Please review and acknowledge the Minutes of Meeting.'}
      </p>
    </div>
  )
}

// ── Step 1 — Kickoff ─────────────────────────────────────────────────────────
// Wired to the shared KickoffMeeting engine. The meeting attaches to the vendor
// (the onboarding's kickoff_meeting_id FK is synced by the backend on schedule),
// so we look it up by the vendor subject rather than assuming the FK is set.
function StepKickoff({ onboarding, editable, onAcknowledged, onContinue, api }) {
  const navigate = useNavigate()
  const { user } = useAuth()
  // TPV wizard: the portal viewer is a third_party_vendor User. A Purchase Vendor
  // has no User session and uses the Purchase portal's own onboarding pages.
  const isPortal = user?.role === 'third_party_vendor'
  const [meeting, setMeeting] = useState(null)
  const [loading, setLoad] = useState(true)
  const [scheduling, setScheduling] = useState(false)

  // PDF Preview State
  const [pdfUrl, setPdfUrl] = useState(null)
  const [pdfErr, setPdfErr] = useState(null)
  const [zoom, setZoom] = useState(100)
  const [checked, setChecked] = useState(!!onboarding.acknowledged)
  const [accepting, setAccepting] = useState(false)
  const [comment,   setComment]   = useState('')

  const loadMeeting = useCallback(() => {
    const vid = onboarding.vendor?.id
    // kickoffApi is the ADMIN kickoff workspace (role:admin,staff). A vendor got a
    // 403 here on every render, swallowed by the catch below — so `meeting` was
    // always null in the portal anyway. The step still works: readyForAck falls
    // back to `!!pdfUrl`, which comes from the vendor's own portal endpoint.
    if (isPortal || !vid) { setLoad(false); return }
    setLoad(true)
    kickoffApi.list({ subject_type: 'vendor', subject_id: vid })
      .then(r => {
        const rows = r?.data ?? r
        const valid = rows.find(m => m.status === 'Completed' && m.mom_path) || rows[0] || null
        setMeeting(valid)
        setLoad(false)
      })
      .catch(() => setLoad(false))
  }, [onboarding.vendor?.id, isPortal])

  useEffect(() => { loadMeeting() }, [loadMeeting])

  // Load MOM PDF
  useEffect(() => {
    let url
    api.onboarding.kickoffPdf(onboarding.id)
      .then(blob => {
        url = URL.createObjectURL(blob)
        setPdfUrl(url)
        setPdfErr(null)
        api.onboarding.logKickoffEvent(onboarding.id, 'viewed').catch(() => {})
      })
      .catch((e) => {
        setPdfErr(e?.response?.data?.message || 'Kickoff meeting is not completed yet.')
      })
    return () => { if (url) URL.revokeObjectURL(url) }
  }, [onboarding.id, meeting?.id, api])

  const doDownload = async () => {
    try {
      let blob
      if (meeting?.id) {
        blob = await kickoffApi.momBlob(meeting.id, true)
      } else {
        blob = await api.onboarding.kickoffPdf(onboarding.id)
      }
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a'); a.href = url; a.download = `MOM-${meeting?.id || onboarding.id}.pdf`
      document.body.appendChild(a); a.click(); a.remove()
      setTimeout(() => URL.revokeObjectURL(url), 30000)
      api.onboarding.logKickoffEvent(onboarding.id, 'downloaded').catch(() => {})
    } catch { /* non-fatal */ }
  }

  const doView = async () => {
    if (pdfUrl) {
      window.open(pdfUrl, '_blank', 'noopener')
      return
    }
    try {
      const blob = meeting?.id ? await kickoffApi.momBlob(meeting.id) : await api.onboarding.kickoffPdf(onboarding.id)
      const url = URL.createObjectURL(blob)
      window.open(url, '_blank', 'noopener')
    } catch { alert('Could not open the MOM PDF.') }
  }

  const handleContinue = async () => {
    if (!onboarding.acknowledged) {
      if (!checked) return
      setAccepting(true)
      try {
        await api.onboarding.acceptKickoff(onboarding.id, comment.trim() || undefined)
        onAcknowledged?.()
      } catch (e) {
        alert(e?.response?.data?.message || 'Could not record acknowledgement.')
        setAccepting(false)
        return
      }
      setAccepting(false)
    }
    onContinue?.()
  }

  const schedule = async () => {
    setScheduling(true)
    try {
      const m = await kickoffApi.schedule({ subject_type: 'vendor', subject_id: onboarding.vendor.id })
      navigate(`/app/tpv/kickoff/${m.id}`)
    } catch (e) { alert(e?.response?.data?.message || 'Could not schedule meeting.'); setScheduling(false) }
  }

  const acknowledged = !!onboarding.acknowledged
  const isCompleted = meeting?.status === 'Completed'
  const hasMom = !!meeting?.mom_path
  const readyForAck = (isCompleted && hasMom) || !!pdfUrl

  const tbBtn = { display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 11px', borderRadius: 8, cursor: 'pointer', fontSize: 12, fontWeight: 700, color: 'var(--text-muted)', background: 'var(--bg-input)', border: '1px solid var(--border)' }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
      {/* State 1: Kickoff Not Completed / MOM Not Sent */}
      {(!readyForAck && !acknowledged) ? (
        <Panel title="Kickoff MOM Review &amp; Acknowledgement" sub="Step 1 of 6 · Kickoff Meeting">
          <div style={{ padding: '24px 20px', borderRadius: 14, textAlign: 'center', background: 'rgba(239,68,68,0.06)', border: '1.5px dashed rgba(239,68,68,0.3)' }}>
            <AlertTriangle size={32} style={{ color: '#ef4444', marginBottom: 10, display: 'inline-block' }} />
            <h3 style={{ fontSize: 16, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 6px' }}>
              Kickoff meeting is not completed yet.
            </h3>
            <p style={{ fontSize: 13, color: 'var(--text-muted)', margin: 0, maxWidth: 520, marginInline: 'auto', lineHeight: 1.5 }}>
              The kickoff meeting must be completed, and the Minutes of Meeting (MOM) must be generated and sent by HR before you can review and acknowledge.
            </p>
            {!isPortal && editable && (
              <div style={{ marginTop: 16 }}>
                <button onClick={schedule} disabled={scheduling}
                  style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 700, color: '#fff', border: 'none', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)' }}>
                  {scheduling ? <Loader size={15} /> : <Plus size={15} />} Schedule Kickoff Meeting
                </button>
              </div>
            )}
          </div>

          {/* Render Meeting Details if scheduled */}
          {meeting && (
            <div style={{ marginTop: 18 }}>
              <KickoffDetailsCard meeting={meeting} vendorName={onboarding.vendor?.company_name} />
            </div>
          )}
        </Panel>
      ) : (
        /* State 2 & 3: Ready for Ack OR Already Acknowledged */
        <Panel
          title="Kickoff MOM Review &amp; Acknowledgement"
          sub="Step 1 of 6 · Review Minutes of Meeting"
          actions={acknowledged && (
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '5px 12px', borderRadius: 999, background: 'rgba(16,185,129,0.12)', color: '#10b981', fontSize: 12, fontWeight: 800, border: '1px solid rgba(16,185,129,0.3)' }}>
              <CheckCircle size={14} /> MOM Acknowledged
            </span>
          )}
        >
          {/* Acknowledgement Status Banner if Acknowledged */}
          {acknowledged && (
            <div style={{ marginBottom: 16, padding: '14px 18px', borderRadius: 14, background: 'rgba(16,185,129,0.08)', border: '1px solid rgba(16,185,129,0.3)' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 6 }}>
                <ShieldCheck size={18} style={{ color: '#10b981' }} />
                <span style={{ fontSize: 14, fontWeight: 800, color: '#10b981' }}>✓ MOM Acknowledged</span>
              </div>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 10, fontSize: 12, color: 'var(--text-h)', marginTop: 8 }}>
                <div><strong>Acknowledged On:</strong> {fmtDateTime(onboarding.acknowledged_at || meeting?.acknowledged_at)}</div>
                <div><strong>Acknowledged By:</strong> {onboarding.acknowledged_by || meeting?.acknowledged_by_name || onboarding.vendor?.company_name || 'Vendor Signatory'}</div>
                {onboarding.acknowledged_ip && <div><strong>IP Address:</strong> {onboarding.acknowledged_ip}</div>}
                {onboarding.acknowledged_device && <div><strong>Device:</strong> {onboarding.acknowledged_device}</div>}
              </div>
              {/* The vendor's response, read-only once submitted. */}
              {meeting?.acknowledgement_comment && (
                <div style={{ marginTop: 12, paddingTop: 12, borderTop: '1px solid rgba(16,185,129,0.25)' }}>
                  <div style={{ fontSize: 12, fontWeight: 800, color: '#10b981', marginBottom: 5 }}>Your response</div>
                  <p style={{ margin: 0, fontSize: 13, color: 'var(--text-h)', lineHeight: 1.55, whiteSpace: 'pre-wrap' }}>
                    {meeting.acknowledgement_comment}
                  </p>
                </div>
              )}
            </div>
          )}

          {/* Acknowledgement window — only once the MOM has actually been sent,
              and only while it still matters (an acknowledged MOM has its own
              banner above). A meeting published before the window existed has no
              deadline and is deliberately shown as open-ended rather than late. */}
          {!acknowledged && meeting?.acknowledgement_sent_at && (
            <AckWindowBanner meeting={meeting} />
          )}

          {/* Meeting Summary Details */}
          {meeting && (
            <div style={{ marginBottom: 18 }}>
              <KickoffDetailsCard meeting={meeting} vendorName={onboarding.vendor?.company_name} />
            </div>
          )}

          {/* MOM PDF Viewer */}
          <div style={{ marginTop: 14 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap', marginBottom: 10 }}>
              <span style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>Minutes of Meeting PDF</span>
              <div style={{ flex: 1 }} />
              <button style={tbBtn} onClick={() => setZoom(z => Math.max(60, z - 10))}><ZoomOut size={13} /> Zoom out</button>
              <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-h)', minWidth: 42, textAlign: 'center' }}>{zoom}%</span>
              <button style={tbBtn} onClick={() => setZoom(z => Math.min(200, z + 10))}><ZoomIn size={13} /> Zoom in</button>
              <button style={tbBtn} onClick={doView}><Eye size={13} /> View PDF</button>
              <button style={tbBtn} onClick={doDownload}><Download size={13} /> Download PDF</button>
            </div>

            <div style={{ height: 500, overflow: 'auto', border: '1px solid var(--border)', borderRadius: 12, background: '#525659' }}>
              {pdfUrl ? (
                <div style={{ width: `${zoom}%`, height: `${zoom}%`, transformOrigin: 'top left' }}>
                  <iframe title="Kickoff MOM Document" src={pdfUrl} style={{ width: '100%', height: 500 * (zoom / 100), border: 'none' }} />
                </div>
              ) : (
                <div style={{ height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#cbd5e1' }}>
                  <Loader size={20} /> <span style={{ marginLeft: 8, fontSize: 13 }}>Loading Minutes document…</span>
                </div>
              )}
            </div>
          </div>

          {/* Acknowledgement Controls or Navigation */}
          <div style={{ marginTop: 18, padding: '16px 18px', borderRadius: 14, background: acknowledged ? 'rgba(16,185,129,0.06)' : 'var(--bg-input)', border: `1px solid ${acknowledged ? 'rgba(16,185,129,0.3)' : 'var(--border)'}` }}>
            {acknowledged ? (
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12 }}>
                <span style={{ fontSize: 13, color: 'var(--text-muted)' }}>
                  Step 1 complete. Proceed to Company Profile in Step 2.
                </span>
                <button onClick={onContinue} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '10px 18px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 700, color: '#fff', border: 'none', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 6px 18px -4px rgba(124,58,237,.5)' }}>
                  Continue to Step 2 <ArrowRight size={15} />
                </button>
              </div>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                {/* Vendor response. Optional — acknowledging without a comment
                    is the normal case, so this never blocks the button. */}
                <div>
                  <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--text-h)', marginBottom: 6 }}>
                    Your response <span style={{ color: 'var(--text-muted)', fontWeight: 500 }}>(optional)</span>
                  </label>
                  <textarea
                    value={comment}
                    onChange={e => setComment(e.target.value)}
                    disabled={!editable}
                    rows={3}
                    maxLength={5000}
                    placeholder="Add your feedback or required changes"
                    style={{ width: '100%', padding: '9px 12px', fontSize: 13, borderRadius: 10, resize: 'vertical',
                      background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-body)' }}
                  />
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                <label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: editable ? 'pointer' : 'not-allowed', flex: 1, minWidth: 260 }}>
                  <input type="checkbox" checked={checked} disabled={!editable} onChange={e => setChecked(e.target.checked)} style={{ width: 18, height: 18, accentColor: '#7C3AED' }} />
                  <span style={{ fontSize: 13.5, color: 'var(--text-h)', fontWeight: 600 }}>
                    I have read and understood the Minutes of Meeting.
                  </span>
                </label>
                <button
                  onClick={handleContinue}
                  disabled={!checked || accepting || !editable}
                  style={{
                    display: 'inline-flex', alignItems: 'center', gap: 7, padding: '10px 20px', borderRadius: 11, cursor: (!checked || !editable) ? 'not-allowed' : 'pointer',
                    fontSize: 13.5, fontWeight: 800, color: '#fff', border: 'none',
                    background: (!checked || !editable) ? 'rgba(124,58,237,0.4)' : 'linear-gradient(145deg,#a78bfa,#7C3AED)',
                    boxShadow: (!checked || !editable) ? 'none' : '0 8px 22px -6px rgba(124,58,237,.6)',
                    opacity: (!checked || !editable) ? 0.7 : 1,
                  }}
                >
                  {accepting ? <Loader size={15} /> : <Check size={15} />} Submit Acknowledgement →
                </button>
                </div>
              </div>
            )}
          </div>
        </Panel>
      )}
    </div>
  )
}

function KickoffDetailsCard({ meeting, vendorName }) {
  return (
    <div style={{ padding: '16px 18px', borderRadius: 14, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
      <div style={{ fontSize: 11, fontWeight: 800, color: '#a78bfa', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: 10 }}>
        Meeting Details
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 12 }}>
        <div>
          <span style={{ fontSize: 11, color: 'var(--text-muted)', display: 'block' }}>Meeting Title</span>
          <span style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{meeting.title || 'Kickoff Meeting'}</span>
        </div>
        <div>
          <span style={{ fontSize: 11, color: 'var(--text-muted)', display: 'block' }}>Vendor Name</span>
          <span style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{vendorName || '—'}</span>
        </div>
        <div>
          <span style={{ fontSize: 11, color: 'var(--text-muted)', display: 'block' }}>Date &amp; Time</span>
          <span style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{fmtDateTime(meeting.scheduled_at)}</span>
        </div>
        <div>
          <span style={{ fontSize: 11, color: 'var(--text-muted)', display: 'block' }}>Mode</span>
          <span style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{koModeLabel(meeting.mode)}</span>
        </div>
      </div>

      {meeting.agenda && (
        <div style={{ marginTop: 12, paddingTop: 10, borderTop: '1px solid var(--border)' }}>
          <span style={{ fontSize: 11, color: 'var(--text-muted)', display: 'block', marginBottom: 3 }}>Agenda</span>
          <span style={{ fontSize: 12.5, color: 'var(--text-h)', lineHeight: 1.5, whiteSpace: 'pre-wrap' }}>{meeting.agenda}</span>
        </div>
      )}

      {meeting.attendees && meeting.attendees.length > 0 && (
        <div style={{ marginTop: 12, paddingTop: 10, borderTop: '1px solid var(--border)' }}>
          <span style={{ fontSize: 11, color: 'var(--text-muted)', display: 'block', marginBottom: 6 }}>Participants ({meeting.attendees.length})</span>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
            {meeting.attendees.map(a => (
              <span key={a.id || a.name} style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '3px 9px', borderRadius: 999, fontSize: 11.5, fontWeight: 600, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
                {a.name} {a.role ? `(${a.role})` : ''}
              </span>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

// Helper function to resolve media/image URLs cleanly
function getImageUrl(url) {
  if (!url) return ''
  if (url.startsWith('data:') || url.startsWith('http://') || url.startsWith('https://')) {
    return url
  }
  // Same env var as every other caller (VITE_API_BASE_URL was a typo and always
  // fell back to localhost, which broke this link on any deployed domain).
  const baseUrl = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'
  const cleanBase = baseUrl.replace(/\/api\/?$/, '')
  return `${cleanBase}${url.startsWith('/') ? '' : '/'}${url}`
}

// ── Step 2 — Profile form ────────────────────────────────────────────────────
function StepProfile({ onboarding, editable, onSaved, onBack, onContinue, api, user }) {
  const getInitialProfile = useCallback(() => {
    const p = onboarding?.profile || {}
    const v = onboarding?.vendor || {}
    const u = user || {}

    return {
      ...EMPTY_PROFILE,

      // Personal Information — Priority: 1. Saved profile -> 2. Vendor master -> 3. User master
      full_name: p.full_name || p.contact_person || v.vendor_name || v.company_name || u.name || '',
      dob: p.dob || v.dob || u.dob || '',
      email: p.email || p.contact_email || v.email || u.email || '',
      mobile: p.mobile || p.contact_mobile || v.phone || u.phone || '',
      gender: p.gender || v.gender || u.gender || '',
      alt_mobile: p.alt_mobile || p.emergency_phone || v.alt_mobile || '',
      profile_photo: p.profile_photo || v.profile_photo || u.avatar || '',

      // Company Details — Priority: 1. Saved profile -> 2. Vendor master -> 3. User master
      company_name: p.company_name || v.company_name || v.company || u.company || '',
      legal_name: p.legal_name || v.legal_name || '',
      company_registration_number: p.company_registration_number || p.registration_number || p.company_reg_no || v.registration_number || v.company_reg_no || '',
      registration_date: p.registration_date || p.company_reg_date || v.company_reg_date || (v.created_at ? v.created_at.split('T')[0] : ''),
      category: p.category || v.category || '',
      company_phone: p.company_phone || v.phone || u.phone || '',
      website: p.website || v.website || '',

      // Social Links
      facebook: p.facebook || v.facebook || '',
      linkedin: p.linkedin || v.linkedin || '',
      twitter: p.twitter || v.twitter || '',
      instagram: p.instagram || v.instagram || '',
      youtube: p.youtube || v.youtube || '',
      portfolio: p.portfolio || v.portfolio || '',

      // Contact Details & Authorized Person
      contact_person: p.contact_person || p.full_name || u.name || v.company_name || '',
      designation: p.designation || u.designation || '',
      contact_email: p.contact_email || p.email || v.email || u.email || '',
      contact_mobile: p.contact_mobile || p.mobile || v.phone || u.phone || '',
      emergency_contact: p.emergency_contact || '',
      emergency_phone: p.emergency_phone || p.alt_mobile || '',
      authorized_name: p.authorized_name || '',
      authorized_designation: p.authorized_designation || '',
      authorized_email: p.authorized_email || '',
      authorized_mobile: p.authorized_mobile || '',
      authorized_id_proof: p.authorized_id_proof || '',

      // Bank Details
      bank_account_holder: p.bank_account_holder || '',
      bank_name: p.bank_name || '',
      bank_account_number: p.bank_account_number || '',
      bank_ifsc: p.bank_ifsc || '',
      bank_branch: p.bank_branch || '',
      bank_account_type: p.bank_account_type || '',

      // GST & PAN
      gst_number: p.gst_number || v.gst_number || '',
      gst_state: p.gst_state || '',
      pan_number: p.pan_number || v.pan_number || '',

      // Registered Address
      registered_address: p.registered_address || v.address || '',
      city: p.city || v.city || '',
      state: p.state || v.state || '',
      country: p.country || v.country || '',
      pincode: p.pincode || v.pincode || '',

      // Engagement
      estimated_workforce: p.estimated_workforce || '',
      scope_of_work: p.scope_of_work || '',
    }
  }, [onboarding, user])

  const [f, setF] = useState(getInitialProfile)
  const [acctConfirm, setAcctConfirm] = useState(() => {
    const init = getInitialProfile()
    return init.bank_account_number || ''
  })
  const [errs, setErrs] = useState({})
  const [saving, setSaving] = useState(false)
  const [saved, setSaved]   = useState(false)

  useEffect(() => {
    const init = getInitialProfile()
    setF(init)
    if (init.bank_account_number) {
      setAcctConfirm(init.bank_account_number)
    }
  }, [getInitialProfile])

  const set = (k) => (e) => { setF(p => ({ ...p, [k]: e.target.value })); setErrs(x => ({ ...x, [k]: undefined })); setSaved(false) }

  const handlePhotoChange = (e) => {
    const file = e.target.files?.[0]
    if (!file) return

    const validTypes = ['image/jpeg', 'image/jpg', 'image/png']
    if (!validTypes.includes(file.type.toLowerCase())) {
      setErrs(p => ({ ...p, profile_photo: 'Only JPG and PNG images are allowed' }))
      return
    }

    if (file.size > 2 * 1024 * 1024) {
      setErrs(p => ({ ...p, profile_photo: 'Image size must be 2 MB or smaller' }))
      return
    }

    setErrs(p => ({ ...p, profile_photo: undefined }))

    const reader = new FileReader()
    reader.onload = (evt) => {
      setF(p => ({ ...p, profile_photo: evt.target.result }))
      setSaved(false)
    }
    reader.readAsDataURL(file)
  }

  const handleRemovePhoto = () => {
    setF(p => ({ ...p, profile_photo: '' }))
    setErrs(p => ({ ...p, profile_photo: undefined }))
    setSaved(false)
  }

  const save = async (navigateNext = false) => {
    const e = validateProfile(f, acctConfirm)
    setErrs(e)
    if (Object.keys(e).length > 0) return false

    setSaving(true)
    try {
      // Normalise identifiers, then drop empties so the stored profile stays clean.
      const normalised = { ...f,
        gst_number: f.gst_number ? f.gst_number.toUpperCase() : '',
        pan_number: f.pan_number ? f.pan_number.toUpperCase() : '',
        bank_ifsc:  f.bank_ifsc ? f.bank_ifsc.toUpperCase() : '' }
      const payload = Object.fromEntries(Object.entries(normalised).filter(([, v]) => v !== '' && v !== null))
      const res = await api.onboarding.saveProfile(onboarding.id, payload)
      
      const updatedProfile = res?.onboarding?.profile || res?.data?.onboarding?.profile || res?.profile
      if (updatedProfile?.profile_photo) {
        setF(p => ({ ...p, profile_photo: updatedProfile.profile_photo }))
      }

      setSaved(true)

      if (navigateNext && onContinue) {
        onContinue()
      }
      if (onSaved) onSaved()

      return true
    } catch (err) {
      if (err?.response?.data?.errors) {
        const backendErrs = {}
        Object.entries(err.response.data.errors).forEach(([k, v]) => {
          const key = k.replace(/^profile\./, '')
          backendErrs[key] = Array.isArray(v) ? v[0] : v
        })
        setErrs(backendErrs)
      } else {
        alert(err?.response?.data?.message || 'Failed to save profile')
      }
      return false
    } finally {
      setSaving(false)
    }
  }

  const F = (label, key, props = {}) => (
    <Field label={label}>
      <TextInput value={f[key]} onChange={set(key)} disabled={!editable}
        style={errs[key] ? { borderColor: '#ef4444' } : undefined} {...props} />
      {errs[key] && <div style={{ color: '#ef4444', fontSize: 11, marginTop: 3 }}>{errs[key]}</div>}
    </Field>
  )

  return (
    <Panel title="Company Profile" sub="Company, personal, contact, bank, GST, PAN and address"
      actions={editable && (
        <button onClick={() => save(false)} disabled={saving}
          style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 16px', borderRadius: 9, border: 'none', background: saved ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontWeight: 700, fontSize: 12.5, cursor: 'pointer', opacity: saving ? 0.7 : 1 }}>
          {saving ? <Loader size={13} /> : saved ? <Check size={13} /> : null} {saving ? 'Saving…' : saved ? 'Saved' : 'Save Draft'}
        </button>
      )}>
      {!editable && <InfoBox>This onboarding is no longer editable — the profile is shown read-only.</InfoBox>}

      <ProfileSection title="Personal Information">
        {/* Circular Avatar Preview */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 20, gridColumn: '1 / -1' }}>
          <div style={{
            width: 80, height: 80, borderRadius: '50%', overflow: 'hidden',
            border: '3px solid var(--border)', background: '#f3f4f6',
            display: 'flex', alignItems: 'center', justify: 'center', flexShrink: 0,
            boxShadow: '0 4px 12px rgba(0,0,0,0.08)', position: 'relative'
          }}>
            {f.profile_photo ? (
              <img src={getImageUrl(f.profile_photo)} alt="Profile Avatar" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
            ) : (
              <UserCheck size={38} style={{ color: '#9ca3af' }} />
            )}
          </div>

          {editable && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
              <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                <label style={{
                  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 14px',
                  borderRadius: 8, background: 'linear-gradient(135deg, #0ea5e9, #0284c7)', color: '#fff',
                  fontSize: 12, fontWeight: 600, cursor: 'pointer'
                }}>
                  <Upload size={13} /> {f.profile_photo ? 'Replace Photo' : 'Upload Photo'}
                  <input type="file" accept="image/jpeg,image/jpg,image/png" onChange={handlePhotoChange} style={{ display: 'none' }} />
                </label>

                {f.profile_photo && (
                  <button type="button" onClick={handleRemovePhoto} style={{
                    display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 14px',
                    borderRadius: 8, background: '#fef2f2', border: '1px solid #fecaca', color: '#dc2626',
                    fontSize: 12, fontWeight: 600, cursor: 'pointer'
                  }}>
                    <Trash2 size={13} /> Remove Photo
                  </button>
                )}
              </div>
              <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>JPG or PNG format only. Maximum file size 2 MB.</span>
              {errs.profile_photo && <span style={{ fontSize: 11, color: '#dc2626', fontWeight: 600 }}>{errs.profile_photo}</span>}
            </div>
          )}
        </div>

        {F('Full Name', 'full_name', { placeholder: 'Full Name' })}
        {F('Date of Birth', 'dob', { type: 'date' })}
        {F('Email Address', 'email', { type: 'email', placeholder: 'name@company.com' })}
        {F('Mobile Number', 'mobile', { placeholder: '10-digit mobile number' })}
        <Field label="Gender">
          <select value={f.gender} onChange={set('gender')} disabled={!editable} style={inputStyle}>
            <option value="">Select Gender…</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
            <option value="Other">Other</option>
          </select>
        </Field>
        {F('Alternate Mobile', 'alt_mobile', { placeholder: 'Alternate mobile number' })}
      </ProfileSection>

      <ProfileSection title="Company Details">
        {F('Company Name', 'company_name', { placeholder: 'Registered company name' })}
        {F('Legal Name', 'legal_name')}
        {F('Registration Number', 'company_registration_number')}
        {F('Registration Date', 'registration_date', { type: 'date' })}
        {F('Category / Industry', 'category', { placeholder: 'e.g. Construction' })}
        {F('Company Phone', 'company_phone')}
        {F('Company Website', 'website', { placeholder: 'https://' })}
      </ProfileSection>

      <ProfileSection title="Social Media Profiles">
        {F('Facebook', 'facebook', { placeholder: 'https://facebook.com/yourpage' })}
        {F('LinkedIn', 'linkedin', { placeholder: 'https://linkedin.com/company/yourcompany' })}
        {F('Twitter', 'twitter', { placeholder: 'https://twitter.com/yourhandle' })}
        {F('Instagram', 'instagram', { placeholder: 'https://instagram.com/yourhandle' })}
        {F('YouTube', 'youtube', { placeholder: 'https://youtube.com/channel/...' })}
        {F('Portfolio', 'portfolio', { placeholder: 'https://yourportfolio.com' })}
      </ProfileSection>

      <ProfileSection title="Contact Details">
        {F('Contact Person', 'contact_person', { placeholder: 'e.g. Ravi Menon' })}
        {F('Designation', 'designation')}
        {F('Email', 'contact_email', { type: 'email', placeholder: 'name@company.com' })}
        {F('Mobile', 'contact_mobile')}
        {F('Emergency Contact', 'emergency_contact', { placeholder: 'Name' })}
        {F('Emergency Phone', 'emergency_phone')}
      </ProfileSection>

      <ProfileSection title="Authorized Person">
        {F('Name', 'authorized_name')}
        {F('Designation', 'authorized_designation')}
        {F('Email', 'authorized_email', { type: 'email' })}
        {F('Mobile', 'authorized_mobile')}
        {F('ID Proof Reference', 'authorized_id_proof')}
      </ProfileSection>

      <ProfileSection title="Bank Details">
        {F('Account Holder Name', 'bank_account_holder')}
        {F('Bank Name', 'bank_name')}
        {F('Account Number', 'bank_account_number', { placeholder: '9–18 digits' })}
        <Field label="Confirm Account Number">
          <TextInput value={acctConfirm} onChange={e => { setAcctConfirm(e.target.value); setErrs(x => ({ ...x, bank_account_confirm: undefined })) }} disabled={!editable}
            style={errs.bank_account_confirm ? { borderColor: '#ef4444' } : undefined} />
          {errs.bank_account_confirm && <div style={{ color: '#ef4444', fontSize: 11, marginTop: 3 }}>{errs.bank_account_confirm}</div>}
        </Field>
        {F('IFSC', 'bank_ifsc', { placeholder: 'HDFC0001234' })}
        {F('Branch', 'bank_branch')}
        <Field label="Account Type">
          <select value={f.bank_account_type} onChange={set('bank_account_type')} disabled={!editable} style={inputStyle}>
            <option value="">Select…</option>
            <option value="Savings">Savings</option>
            <option value="Current">Current</option>
          </select>
        </Field>
      </ProfileSection>

      <ProfileSection title="GST & PAN">
        {F('GST Number', 'gst_number', { placeholder: '15-char GSTIN', maxLength: 15 })}
        {F('GST Registration State', 'gst_state')}
        {F('PAN Number', 'pan_number', { placeholder: 'AAAAA9999A', maxLength: 10 })}
      </ProfileSection>

      <ProfileSection title="Registered Address">
        <Field label="Registered Address" full>
          <textarea value={f.registered_address} onChange={set('registered_address')} disabled={!editable} rows={2} placeholder="Full registered address" style={{ ...inputStyle, resize: 'vertical' }} />
        </Field>
        {F('City', 'city')}
        {F('State', 'state')}
        {F('Country', 'country')}
        {F('Pincode', 'pincode', { placeholder: '6 digits', maxLength: 6 })}
      </ProfileSection>

      <ProfileSection title="Engagement">
        {F('Estimated Workforce', 'estimated_workforce', { type: 'number', min: '0', placeholder: 'e.g. 25' })}
        {F('Date of Birth', 'dob', { type: 'date' })}
        {F('LinkedIn', 'linkedin', { placeholder: 'Profile URL' })}
        <Field label="Scope of Work" full>
          <textarea value={f.scope_of_work} onChange={set('scope_of_work')} disabled={!editable} rows={2} placeholder="What work will this vendor perform on site?" style={{ ...inputStyle, resize: 'vertical' }} />
        </Field>
      </ProfileSection>

      {/* Step 2 Bottom Navigation Actions Bar */}
      <div style={{
        marginTop: 24, paddingTop: 16, borderTop: '1px solid var(--border)',
        display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12
      }}>
        <button
          type="button"
          onClick={onBack}
          style={{
            display: 'inline-flex', alignItems: 'center', gap: 6, padding: '10px 20px', borderRadius: 10,
            border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-h)',
            fontWeight: 700, fontSize: 13, cursor: 'pointer'
          }}
        >
          <ArrowLeft size={16} /> Back
        </button>

        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          {editable && (
            <button
              type="button"
              onClick={() => save(false)}
              disabled={saving}
              style={{
                display: 'inline-flex', alignItems: 'center', gap: 6, padding: '10px 20px', borderRadius: 10,
                border: '1px solid var(--border)', background: saved ? '#f0fdf4' : 'var(--bg-card)',
                color: saved ? '#166534' : 'var(--text-h)', fontWeight: 700, fontSize: 13, cursor: 'pointer'
              }}
            >
              {saving ? <Loader size={15} /> : saved ? <Check size={15} /> : null} {saving ? 'Saving…' : saved ? 'Saved' : 'Save Draft'}
            </button>
          )}

          <button
            type="button"
            onClick={() => {
              if (!editable) {
                if (onContinue) onContinue()
              } else {
                save(true)
              }
            }}
            disabled={saving}
            style={{
              display: 'inline-flex', alignItems: 'center', gap: 8, padding: '10px 24px', borderRadius: 10,
              border: 'none', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff',
              fontWeight: 800, fontSize: 13, cursor: 'pointer', boxShadow: '0 4px 14px rgba(124,58,237,0.3)'
            }}
          >
            {saving ? 'Saving…' : 'Continue'} <ArrowRight size={16} />
          </button>
        </div>
      </div>
    </Panel>
  )
}

function ProfileSection({ title, children }) {
  return (
    <div style={{ marginBottom: 16 }}>
      <div style={{ fontSize: 11, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.05em', color: '#a78bfa', margin: '4px 0 10px' }}>{title}</div>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 14 }}>{children}</div>
    </div>
  )
}

// ── Steps 3 & 4 — Document checklist grid ────────────────────────────────────
// ── Steps 3 & 4 — Document checklist grid ────────────────────────────────────
// ── Steps 3 & 4 — Document checklist grid ────────────────────────────────────
// ── Step 3 — Statutory Documents Upload (Enterprise Experience) ─────────────
const DOC_CATEGORIES = {
  company_registration: 'Company Documents',
  company_pan: 'Company Documents',
  gst: 'Company Documents',
  udyam_certificate: 'Company Documents',
  shop_act: 'Company Documents',

  insurance_wcp: 'Compliance Documents',
  pf_no: 'Compliance Documents',
  esic_no: 'Compliance Documents',
  bocw_registration: 'Compliance Documents',
  clr: 'Compliance Documents',
  mlwf: 'Compliance Documents',
  mscb: 'Compliance Documents',
  labour_license: 'Compliance Documents',

  loi_wo_po: 'Financial Documents',
  bank_proof: 'Financial Documents',
  cancelled_cheque: 'Financial Documents',

  subcontractor_decl: 'Other Documents',
  other: 'Other Documents',
}

const STANDARD_REQUIRED_DOCS = [
  { type: 'company_registration', label: 'Company Registration Certificate', required: true },
  { type: 'company_pan', label: 'Company PAN Card', required: true },
  { type: 'insurance_wcp', label: 'Insurance [WCP]', required: true },
  { type: 'gst', label: 'GST Certificate', required: true },
  { type: 'pf_no', label: 'PF Registration', required: true },
  { type: 'esic_no', label: 'ESIC Registration', required: true },
  { type: 'bocw_registration', label: 'BOCW Registration', required: true },
  { type: 'clr', label: 'CLR [Contract Labour Registration]', required: true },
  { type: 'mlwf', label: 'MLWF [Maharashtra Labour Welfare]', required: true },
  { type: 'mscb', label: 'MSCB Certificate', required: true },
  { type: 'udyam_certificate', label: 'Udyam Certificate', required: true },
  { type: 'other', label: 'Other Document (Optional)', required: false },
  { type: 'subcontractor_decl', label: 'Subcontractor Declaration (Optional)', required: false },
]

const COMPLIANCE_PROVIDERS = [
  {
    id: 'business_badhega',
    name: 'BusinessBadhega.com',
    desc: 'Registration & compliance experts',
    badge: 'Recommended',
    badgeTone: 'orange',
    logoBg: 'linear-gradient(135deg, #f97316, #ea580c)',
    logoText: 'BB',
  },
  {
    id: 'legaldesk',
    name: 'LegalDesk',
    desc: 'Legal documentation & CA services',
    badge: 'New',
    badgeTone: 'green',
    logoBg: 'linear-gradient(135deg, #10b981, #059669)',
    logoText: 'LD',
  },
  {
    id: 'vakilsearch',
    name: 'VakilSearch',
    desc: 'CA & CS assisted registrations',
    badge: 'Partner',
    badgeTone: 'purple',
    logoBg: 'linear-gradient(135deg, #7C3AED, #5b21b6)',
    logoText: 'VS',
  },
]

function getDocCategory(type) {
  return DOC_CATEGORIES[type] || 'Company Documents'
}

function StepDocuments({ checklist, vendorId, onboarding, editable, manage, admin, reviewMode, onChanged, onBack, onContinue, api, user }) {
  const [busy, setBusy]                 = useState(null)
  const [uploadProgress, setProgressVal]= useState(0)
  const [reviewing, setRev]             = useState(null)
  const [autoRefresh, setAutoRefresh]   = useState(true)
  const [historyDoc, setHistoryDoc]     = useState(null)
  const [previewDoc, setPreviewDoc]     = useState(null)
  const [selectedProviderReq, setSelectedProviderReq] = useState(null) // { provider, docRow }
  const [providerSections, setProviderSections]       = useState({}) // { [docType]: boolean }
  const [providerSearch, setProviderSearch]           = useState({}) // { [docType]: string }
  const [stagedFiles, setStagedFiles]     = useState({}) // { [docType]: string }
  const [submittedRequests, setSubmittedRequests]     = useState([])
  const [searchQuery, setSearchQuery]   = useState('')
  const [statusFilter, setStatusFilter] = useState('ALL')
  const [sortBy, setSortBy]             = useState('STATUS')
  const [openSections, setOpenSections] = useState({
    'Company Documents': true,
    'Compliance Documents': true,
    'Financial Documents': true,
    'Other Documents': true,
  })

  const inputs = useRef({})

  const s = checklist?.summary || {}
  const backendRows = checklist?.required || []
  const complete = !!checklist?.complete

  // Combine backend rows with standard documents list
  const rawRowsMap = new Map(backendRows.map(r => [r.type, r]))
  const rawRows = STANDARD_REQUIRED_DOCS.map(def => {
    const existing = rawRowsMap.get(def.type)
    if (existing) {
      return {
        ...existing,
        type_label: def.label,
        required: true,
      }
    }
    return {
      type: def.type,
      type_label: def.label,
      required: false, // Optional for this vendor type
      uploaded: false,
      status: 'missing',
      original_name: null,
      document_id: null,
    }
  })

  // Append any extra backend documents not in standard list
  backendRows.forEach(r => {
    if (!STANDARD_REQUIRED_DOCS.some(d => d.type === r.type)) {
      rawRows.push({ ...r, required: true })
    }
  })

  const totalRequired = s.required || rawRows.filter(r => r.required).length || 0
  const totalUploaded = s.uploaded || rawRows.filter(r => r.required && (r.uploaded || stagedFiles[r.type])).length || 0
  const totalApproved = s.approved || rawRows.filter(r => r.status === DOC_STATUS.APPROVED).length || 0
  const totalPending  = s.pending  || rawRows.filter(r => (r.uploaded || stagedFiles[r.type]) && r.status !== DOC_STATUS.APPROVED && r.status !== DOC_STATUS.REJECTED).length || 0
  const totalRejected = s.rejected || rawRows.filter(r => r.status === DOC_STATUS.REJECTED).length || 0
  const totalMissing  = Math.max(0, totalRequired - totalUploaded)
  const pct           = s.progress_percent ?? (totalRequired ? Math.round((totalApproved / totalRequired) * 100) : 0)

  useEffect(() => {
    if (!reviewMode || !autoRefresh) return undefined
    const id = setInterval(() => {
      if (document.hidden || busy || reviewing) return
      onChanged()
    }, 15000)
    return () => clearInterval(id)
  }, [reviewMode, autoRefresh, busy, reviewing]) // eslint-disable-line react-hooks/exhaustive-deps

  const pickFile = (type) => inputs.current[type]?.click()

  const onFile = async (row, file) => {
    if (!file) return
    const ext = (file.name || '').split('.').pop().toLowerCase()
    const allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx']
    if (!allowed.includes(ext)) {
      alert(`Invalid file format .${ext}. Allowed formats: PDF, JPG, JPEG, PNG, DOC, DOCX`)
      return
    }
    if (file.size > 10 * 1024 * 1024) {
      alert('File size exceeds the maximum limit of 10 MB.')
      return
    }

    setStagedFiles(prev => ({ ...prev, [row.type]: file.name }))
    setBusy(row.type)
    setProgressVal(30)
    const pTimer = setInterval(() => {
      setProgressVal(p => (p < 90 ? p + 20 : p))
    }, 200)

    try {
      if (row.document_id && row.status === DOC_STATUS.REJECTED) {
        await api.documents.resubmit(row.document_id, file)
      } else {
        await api.documents.upload(vendorId, row.type, file)
      }
      setProgressVal(100)
      onChanged()
    } catch (e) {
      alert(e?.response?.data?.message || 'Upload failed')
    } finally {
      clearInterval(pTimer)
      setTimeout(() => {
        setBusy(null)
        setProgressVal(0)
      }, 400)
    }
  }

  const handleDrop = (e, row) => {
    e.preventDefault()
    if (!editable || row.status === DOC_STATUS.APPROVED) return
    const file = e.dataTransfer?.files?.[0]
    if (file) onFile(row, file)
  }

  const viewPreview = async (row) => {
    try {
      const url = await api.documents.open(row.document_id)
      const ext = (row.original_name || '').split('.').pop().toLowerCase()
      if (['jpg', 'jpeg', 'png', 'webp'].includes(ext)) {
        setPreviewDoc({ url, name: row.type_label, ext, type: 'image' })
      } else if (ext === 'pdf') {
        setPreviewDoc({ url, name: row.type_label, ext, type: 'pdf' })
      } else {
        window.open(url, '_blank')
      }
    } catch {
      alert('Could not open document preview.')
    }
  }

  const del = async (row) => {
    if (!confirm(`Remove the uploaded ${row.type_label}?`)) return
    try {
      await api.documents.delete(row.document_id)
      onChanged()
    } catch (e) {
      alert(e?.response?.data?.message || 'Delete failed')
    }
  }

  const runReview = async (remarks) => {
    const { row, decision } = reviewing
    try {
      await api.documents.review(row.document_id, decision, remarks)
      setRev(null)
      onChanged()
    } catch (e) {
      alert(e?.response?.data?.message || 'Review failed')
    }
  }

  const handleDownloadSample = () => {
    const sampleContent = "Subcontractor Declaration Sample Document Content"
    const blob = new Blob([sampleContent], { type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = 'Subcontractor_Declaration_Sample.docx'
    a.click()
    URL.revokeObjectURL(url)
  }

  const toggleSection = (category) => {
    setOpenSections(prev => ({ ...prev, [category]: !prev[category] }))
  }

  const toggleProviderSection = (docType) => {
    setProviderSections(prev => ({ ...prev, [docType]: !prev[docType] }))
  }

  // Filter & Search Logic
  const filteredRows = rawRows.filter(row => {
    const matchesSearch = row.type_label.toLowerCase().includes(searchQuery.toLowerCase()) ||
      (row.original_name && row.original_name.toLowerCase().includes(searchQuery.toLowerCase()))

    let matchesStatus = true
    if (statusFilter === 'APPROVED') matchesStatus = row.status === DOC_STATUS.APPROVED
    else if (statusFilter === 'PENDING') matchesStatus = row.uploaded && row.status !== DOC_STATUS.APPROVED && row.status !== DOC_STATUS.REJECTED
    else if (statusFilter === 'REJECTED') matchesStatus = row.status === DOC_STATUS.REJECTED
    else if (statusFilter === 'MISSING') matchesStatus = !row.uploaded && row.status !== DOC_STATUS.APPROVED

    return matchesSearch && matchesStatus
  })

  // Sort Logic
  const sortedRows = [...filteredRows].sort((a, b) => {
    if (sortBy === 'NAME') return a.type_label.localeCompare(b.type_label)
    if (sortBy === 'STATUS') {
      const rank = (status, uploaded) => {
        if (status === DOC_STATUS.REJECTED) return 1
        if (!uploaded) return 2
        if (status !== DOC_STATUS.APPROVED) return 3
        return 4
      }
      return rank(a.status, a.uploaded) - rank(b.status, b.uploaded)
    }
    return 0
  })

  // Group by category
  const groupedCategories = ['Company Documents', 'Compliance Documents', 'Financial Documents', 'Other Documents'].reduce((acc, cat) => {
    acc[cat] = sortedRows.filter(r => getDocCategory(r.type) === cat)
    return acc
  }, {})

  return (
    <Panel
      title={reviewMode ? 'Document Verification Review' : 'Upload Legal Documents'}
      sub={reviewMode ? 'Approve or reject each submitted vendor compliance document' : `Upload each required document · ${rawRows.length} Documents`}
    >
      {reviewMode && !admin && <InfoBox>Only an admin can approve or reject documents. You can see live review status here.</InfoBox>}
      {!reviewMode && !editable && <InfoBox>This onboarding is locked — documents can no longer be changed.</InfoBox>}

      {/* Document Upload Guidelines Banner */}
      <div style={{
        background: 'linear-gradient(135deg, rgba(59,130,246,0.06), rgba(147,51,234,0.06))',
        border: '1px solid rgba(59,130,246,0.2)', borderRadius: 12, padding: 16, marginBottom: 20
      }}>
        <h4 style={{ margin: '0 0 6px', fontSize: 14, fontWeight: 800, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 6 }}>
          <Info size={16} style={{ color: '#3b82f6' }} /> Document Upload Guidelines
        </h4>
        <p style={{ margin: '0 0 4px', fontSize: 12.5, color: 'var(--text-muted)' }}>
          Accepted formats: <strong>PDF, JPG, JPEG, PNG</strong> — Max 10MB per file.
        </p>
        <p style={{ margin: 0, fontSize: 12, color: 'var(--text-muted)' }}>
          Fields marked <span style={{ color: '#ef4444', fontWeight: 800 }}>*</span> are mandatory. Don't have a document? Click <strong>"Don't have this document?"</strong> under any field to connect with a provider.
        </p>
      </div>

      {/* Live Upload Progress Notification */}
      {busy && (
        <div style={{ marginBottom: 16, padding: '12px 16px', borderRadius: 10, background: 'rgba(14,165,233,0.1)', border: '1px solid #0ea5e9', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <span style={{ fontSize: 13, fontWeight: 700, color: '#0284c7', display: 'inline-flex', alignItems: 'center', gap: 8 }}>
            <Loader size={15} /> Uploading documents... {uploadProgress}%
          </span>
          <div style={{ width: 140, height: 6, borderRadius: 999, background: 'rgba(14,165,233,0.2)', overflow: 'hidden' }}>
            <div style={{ width: `${uploadProgress}%`, height: '100%', background: '#0284c7', transition: 'width 0.2s' }} />
          </div>
        </div>
      )}

      {/* Top Enterprise Summary Card */}
      <div style={{
        background: 'linear-gradient(135deg, rgba(30,27,75,0.04), rgba(124,58,237,0.06))',
        border: '1px solid var(--border)', borderRadius: 16, padding: 20, marginBottom: 24,
        boxShadow: '0 4px 16px rgba(0,0,0,0.03)'
      }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 14, flexWrap: 'wrap', gap: 12 }}>
          <div>
            <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>Document Progress Overview</h3>
            <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>{totalApproved} of {totalRequired} Mandatory Documents Approved ({pct}%)</span>
          </div>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
            <ReviewStat label="Required" value={totalRequired} color="#7C3AED" />
            <ReviewStat label="Uploaded" value={totalUploaded} color="#0ea5e9" />
            <ReviewStat label="Approved" value={totalApproved} color="#10b981" />
            <ReviewStat label="Pending" value={totalPending} color="#f59e0b" />
            <ReviewStat label="Rejected" value={totalRejected} color="#ef4444" />
            <ReviewStat label="Missing" value={totalMissing} color="#6b7280" />
          </div>
        </div>

        {/* Progress Bar */}
        <div style={{ height: 10, borderRadius: 999, background: 'var(--bg-input)', overflow: 'hidden', border: '1px solid var(--border)' }}>
          <div style={{
            width: `${pct}%`, height: '100%',
            background: 'linear-gradient(90deg, #0ea5e9, #10b981)',
            borderRadius: 999, transition: 'width 0.4s ease'
          }} />
        </div>
      </div>

      {/* Search, Filter & Sort Controls */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, marginBottom: 20, flexWrap: 'wrap' }}>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flex: 1, minWidth: 260 }}>
          <input
            type="text"
            placeholder="Search documents by name or file..."
            value={searchQuery}
            onChange={e => setSearchQuery(e.target.value)}
            style={{
              padding: '8px 14px', borderRadius: 9, border: '1px solid var(--border)',
              background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 12.5, flex: 1, outline: 'none'
            }}
          />
        </div>

        {/* Status Filter Pills */}
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
          {['ALL', 'APPROVED', 'PENDING', 'REJECTED', 'MISSING'].map(st => (
            <button
              key={st}
              onClick={() => setStatusFilter(st)}
              style={{
                padding: '6px 12px', borderRadius: 8, fontSize: 11.5, fontWeight: 700,
                border: statusFilter === st ? 'none' : '1px solid var(--border)',
                background: statusFilter === st ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-card)',
                color: statusFilter === st ? '#fff' : 'var(--text-muted)', cursor: 'pointer'
              }}
            >
              {st.charAt(0) + st.slice(1).toLowerCase()}
            </button>
          ))}
        </div>

        {/* Sort Select */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <span style={{ fontSize: 12, color: 'var(--text-muted)', fontWeight: 600 }}>Sort:</span>
          <select
            value={sortBy}
            onChange={e => setSortBy(e.target.value)}
            style={{ padding: '6px 10px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-h)', fontSize: 12 }}
          >
            <option value="STATUS">Priority / Status</option>
            <option value="NAME">Document Name</option>
          </select>
        </div>
      </div>

      {/* Grouped Accordions */}
      {Object.entries(groupedCategories).map(([category, items]) => {
        if (items.length === 0) return null
        const isOpen = openSections[category]
        const catApproved = items.filter(i => i.status === DOC_STATUS.APPROVED).length

        return (
          <div key={category} style={{ marginBottom: 18, border: '1px solid var(--border)', borderRadius: 14, overflow: 'hidden', background: 'var(--bg-card)' }}>
            {/* Category Header */}
            <div
              onClick={() => toggleSection(category)}
              style={{
                display: 'flex', alignItems: 'center', justify: 'space-between', padding: '14px 18px',
                background: 'var(--bg-input)', cursor: 'pointer', userSelect: 'none'
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <FileText size={18} style={{ color: '#7C3AED' }} />
                <span style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>{category}</span>
                <span style={{ fontSize: 11.5, fontWeight: 700, background: 'rgba(124,58,237,0.12)', color: '#7C3AED', padding: '2px 8px', borderRadius: 12 }}>
                  {catApproved} / {items.length} Approved
                </span>
              </div>
              <span style={{ fontSize: 12, color: 'var(--text-muted)', fontWeight: 600 }}>
                {isOpen ? 'Collapse ▲' : 'Expand ▼'}
              </span>
            </div>

            {/* Category Body Card Items */}
            {isOpen && (
              <div style={{ padding: 14, display: 'flex', flexDirection: 'column', gap: 14 }}>
                {items.map(row => {
                  const fileNameDisplay = row.original_name || stagedFiles[row.type] || null
                  const isUploadedOrStaged = row.uploaded || !!stagedFiles[row.type]
                  const statusLabel = isUploadedOrStaged && (row.status === 'missing' || !row.status) ? DOC_STATUS.UPLOADED : row.status
                  const cfg = docStatusCfg(statusLabel)
                  const isBusy = busy === row.type
                  const approved = row.status === DOC_STATUS.APPROVED
                  const rejected = row.status === DOC_STATUS.REJECTED
                  const provReq  = submittedRequests.find(r => r.docType === row.type)

                  return (
                    <div
                      key={row.type}
                      style={{
                        padding: 18, borderRadius: 14, border: `1.5px solid ${rejected ? '#fecaca' : approved ? '#a7f3d0' : 'var(--border)'}`,
                        background: rejected ? '#fef2f2' : approved ? '#f0fdf4' : 'var(--bg-card)',
                        boxShadow: '0 2px 10px rgba(0,0,0,0.02)'
                      }}
                    >
                      <div style={{ display: 'flex', alignItems: 'flex-start', justify: 'space-between', flexWrap: 'wrap', gap: 12 }}>
                        {/* Title & Badges */}
                        <div style={{ flex: 1, minWidth: 260 }}>
                          <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                            <span style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>
                              {row.type_label} {row.required && <span style={{ color: '#ef4444', fontWeight: 800 }}>*</span>}
                            </span>
                            {row.required ? (
                              <span style={{ fontSize: 10, fontWeight: 800, color: '#ef4444', border: '1px solid #fca5a5', padding: '1px 6px', borderRadius: 6, background: '#fef2f2' }}>Required</span>
                            ) : (
                              <span style={{ fontSize: 10, fontWeight: 700, color: '#6b7280', border: '1px solid var(--border)', padding: '1px 6px', borderRadius: 6 }}>Optional</span>
                            )}
                            <StatusPill cfg={cfg} />
                          </div>

                          {/* Drag & Drop Upload Dropzone Box */}
                          {!reviewMode && editable && !approved && (
                            <div
                              onDragOver={e => e.preventDefault()}
                              onDrop={e => handleDrop(e, row)}
                              style={{
                                marginTop: 12, padding: '14px 16px', borderRadius: 10,
                                border: '2px dashed var(--border)', background: 'var(--bg-input)',
                                display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap'
                              }}
                            >
                              <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                                <Upload size={18} style={{ color: '#7C3AED' }} />
                                <div>
                                  <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>
                                    Drag &amp; Drop file here or
                                  </div>
                                  <div style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
                                    {fileNameDisplay ? `Selected: ${fileNameDisplay}` : 'No file selected (Max 10 MB — PDF, JPG, PNG)'}
                                  </div>
                                </div>
                              </div>

                              <button
                                type="button"
                                onClick={() => pickFile(row.type)}
                                disabled={isBusy}
                                style={{
                                  padding: '7px 14px', borderRadius: 8, background: '#7C3AED', color: '#fff',
                                  border: 'none', fontSize: 12, fontWeight: 700, cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 6
                                }}
                              >
                                {isBusy ? 'Uploading...' : 'Choose File'}
                              </button>
                            </div>
                          )}

                          {/* Uploaded File Metadata Display */}
                          {fileNameDisplay && (
                            <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 8, display: 'flex', alignItems: 'center', gap: 6 }}>
                              <FileText size={14} style={{ color: '#10b981' }} />
                              <span>Uploaded file: <strong>{fileNameDisplay}</strong></span>
                              <span style={{ fontSize: 11, background: '#e0f2fe', color: '#0369a1', padding: '1px 6px', borderRadius: 4, fontWeight: 700 }}>Status: Uploaded</span>
                            </div>
                          )}

                          {/* Provider Request Status Pill Banner */}
                          {provReq && (
                            <div style={{ marginTop: 8, padding: '8px 12px', borderRadius: 8, background: '#fff7ed', border: '1px solid #fed7aa', fontSize: 12, color: '#c2410c', display: 'flex', alignItems: 'center', gap: 6 }}>
                              <Clock size={14} /> Provider Callback Requested ({provReq.providerName}) — Status: Pending
                            </div>
                          )}

                          {/* Subcontractor Declaration Sample Download Box */}
                          {row.type === 'subcontractor_decl' && (
                            <div style={{ marginTop: 10, padding: '10px 14px', borderRadius: 8, background: 'rgba(124,58,237,0.06)', border: '1px dashed #a78bfa', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
                              <span style={{ fontSize: 12, color: 'var(--text-h)', fontWeight: 600 }}>Download the sample, fill it in, and upload the signed copy.</span>
                              <button
                                type="button"
                                onClick={handleDownloadSample}
                                style={{
                                  padding: '6px 14px', borderRadius: 8, background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff',
                                  border: 'none', fontSize: 11.5, fontWeight: 700, cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 6
                                }}
                              >
                                <Download size={13} /> Download Sample (.docx)
                              </button>
                            </div>
                          )}

                          {/* Don't have this document? Small Pill Button */}
                          {!approved && (
                            <div style={{ marginTop: 10 }}>
                              <button
                                type="button"
                                onClick={() => toggleProviderSection(row.type)}
                                style={{
                                  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '5px 12px',
                                  borderRadius: 20, border: '1px solid #cbd5e1', background: providerSections[row.type] ? '#eff6ff' : 'var(--bg-card)',
                                  color: providerSections[row.type] ? '#1d4ed8' : 'var(--text-muted)', fontSize: 11.5, fontWeight: 700, cursor: 'pointer'
                                }}
                              >
                                <HelpCircle size={13} /> Don't have this document? {providerSections[row.type] ? '▲' : '▼'}
                              </button>
                            </div>
                          )}
                        </div>

                        {/* Actions Toolbar */}
                        <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
                          <input
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                            style={{ display: 'none' }}
                            ref={el => { inputs.current[row.type] = el }}
                            onChange={e => { onFile(row, e.target.files?.[0]); e.target.value = '' }}
                          />

                          {/* Admin Review Action Buttons */}
                          {reviewMode && admin && row.uploaded && !approved && (
                            <>
                              <MiniBtn onClick={() => setRev({ row, decision: 'approve' })} color="#10b981" icon={CheckCircle}>Approve</MiniBtn>
                              <MiniBtn onClick={() => setRev({ row, decision: 'reject' })} color="#ef4444" icon={XCircle}>Reject</MiniBtn>
                            </>
                          )}

                          {/* Upload / Choose file Button */}
                          {!reviewMode && editable && !approved && (
                            <MiniBtn onClick={() => pickFile(row.type)} color={rejected ? '#f59e0b' : '#7C3AED'} icon={rejected ? RotateCcw : Upload} disabled={isBusy}>
                              {isBusy ? 'Uploading...' : rejected ? 'Upload New Version' : isUploadedOrStaged ? 'Replace File' : 'Browse File'}
                            </MiniBtn>
                          )}

                          {/* View Preview Button */}
                          {row.uploaded && (
                            <MiniBtn onClick={() => viewPreview(row)} color="var(--text-muted)" icon={Eye} border>View / Download</MiniBtn>
                          )}

                          {/* History Button */}
                          {row.uploaded && row.document_id && (
                            <MiniBtn onClick={() => setHistoryDoc(row.document_id)} color="var(--text-muted)" icon={History} border>History</MiniBtn>
                          )}

                          {/* Delete Button */}
                          {!reviewMode && editable && row.uploaded && !approved && (
                            <MiniBtn onClick={() => del(row)} color="#ef4444" icon={Trash2} border />
                          )}
                        </div>
                      </div>

                      {/* Rejected Banner Alert */}
                      {rejected && row.remarks && (
                        <div style={{ marginTop: 12, padding: 10, borderRadius: 8, background: '#fee2e2', border: '1px solid #fca5a5', display: 'flex', alignItems: 'flex-start', gap: 8 }}>
                          <AlertTriangle size={15} style={{ color: '#dc2626', flexShrink: 0, marginTop: 1 }} />
                          <div style={{ fontSize: 12, color: '#991b1b' }}>
                            <strong>Rejection Rationale:</strong> {row.remarks}
                          </div>
                        </div>
                      )}

                      {/* SERVICE PROVIDERS Expanded Section Card */}
                      {providerSections[row.type] && (
                        <div style={{
                          marginTop: 14, padding: 16, borderRadius: 12, border: '1px solid #e2e8f0',
                          background: '#f8fafc', boxShadow: 'inset 0 2px 4px rgba(0,0,0,0.02)'
                        }}>
                          {/* Header */}
                          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 }}>
                            <span style={{ fontSize: 12, fontWeight: 900, letterSpacing: '0.05em', color: '#334155' }}>SERVICE PROVIDERS</span>
                            <span style={{ fontSize: 10.5, fontWeight: 700, color: '#64748b', background: '#e2e8f0', padding: '2px 8px', borderRadius: 10 }}>powered by our network</span>
                          </div>

                          {/* Search Box */}
                          <div style={{ marginBottom: 12 }}>
                            <input
                              type="text"
                              placeholder="Search providers..."
                              value={providerSearch[row.type] || ''}
                              onChange={e => setProviderSearch(prev => ({ ...prev, [row.type]: e.target.value }))}
                              style={{
                                width: '100%', padding: '8px 12px', borderRadius: 8, border: '1px solid #cbd5e1',
                                background: '#ffffff', color: '#1e293b', fontSize: 12, outline: 'none'
                              }}
                            />
                          </div>

                          {/* Provider Cards List */}
                          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                            {COMPLIANCE_PROVIDERS.filter(pr => pr.name.toLowerCase().includes((providerSearch[row.type] || '').toLowerCase()) || pr.desc.toLowerCase().includes((providerSearch[row.type] || '').toLowerCase())).map(pr => (
                              <div
                                key={pr.id}
                                onClick={() => setSelectedProviderReq({ provider: pr, docRow: row })}
                                style={{
                                  display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '10px 14px',
                                  borderRadius: 10, border: '1px solid #e2e8f0', background: '#ffffff', cursor: 'pointer',
                                  transition: 'all 0.2s ease', boxShadow: '0 1px 3px rgba(0,0,0,0.03)'
                                }}
                              >
                                <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                                  <div style={{
                                    width: 38, height: 38, borderRadius: 10, background: pr.logoBg,
                                    display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fff',
                                    fontWeight: 900, fontSize: 14, flexShrink: 0
                                  }}>
                                    {pr.logoText}
                                  </div>
                                  <div>
                                    <div style={{ fontSize: 13, fontWeight: 800, color: '#0f172a' }}>{pr.name}</div>
                                    <div style={{ fontSize: 11.5, color: '#64748b' }}>{pr.desc}</div>
                                  </div>
                                </div>

                                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                  <span style={{
                                    fontSize: 10.5, fontWeight: 800, padding: '3px 8px', borderRadius: 6,
                                    background: pr.badgeTone === 'orange' ? '#fff7ed' : pr.badgeTone === 'green' ? '#f0fdf4' : '#faf5ff',
                                    color: pr.badgeTone === 'orange' ? '#c2410c' : pr.badgeTone === 'green' ? '#15803d' : '#6b21a8',
                                    border: `1px solid ${pr.badgeTone === 'orange' ? '#ffedd5' : pr.badgeTone === 'green' ? '#dcfce7' : '#f3e8ff'}`
                                  }}>
                                    {pr.badge}
                                  </span>
                                  <ChevronRight size={16} style={{ color: '#94a3b8' }} />
                                </div>
                              </div>
                            ))}
                          </div>

                          {/* Bottom Link */}
                          <div style={{ marginTop: 12, textAlign: 'center' }}>
                            <button
                              type="button"
                              onClick={() => alert('Suggest a Provider: Contact support@company.com to recommend a new compliance partner.')}
                              style={{ border: 'none', background: 'none', color: '#f97316', fontSize: 11.5, fontWeight: 700, cursor: 'pointer', textDecoration: 'underline' }}
                            >
                              Suggest / Request a New Provider
                            </button>
                          </div>
                        </div>
                      )}
                    </div>
                  )
                })}
              </div>
            )}
          </div>
        )
      })}

      {/* Step 3 Bottom Navigation Toolbar */}
      <div style={{
        marginTop: 24, paddingTop: 16, borderTop: '1px solid var(--border)',
        display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12
      }}>
        <button
          type="button"
          onClick={onBack}
          style={{
            display: 'inline-flex', alignItems: 'center', gap: 6, padding: '10px 20px', borderRadius: 10,
            border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-h)',
            fontWeight: 700, fontSize: 13, cursor: 'pointer'
          }}
        >
          <ArrowLeft size={16} /> Back
        </button>

        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <button
            type="button"
            onClick={onChanged}
            style={{
              display: 'inline-flex', alignItems: 'center', gap: 6, padding: '10px 20px', borderRadius: 10,
              border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-h)',
              fontWeight: 700, fontSize: 13, cursor: 'pointer'
            }}
          >
            Save Draft
          </button>

          <button
            type="button"
            onClick={onContinue}
            disabled={reviewMode && !complete}
            style={{
              display: 'inline-flex', alignItems: 'center', gap: 8, padding: '10px 24px', borderRadius: 10,
              border: 'none',
              background: (!reviewMode || complete) ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'rgba(124,58,237,0.35)',
              color: '#fff', fontWeight: 800, fontSize: 13,
              cursor: (!reviewMode || complete) ? 'pointer' : 'not-allowed',
              opacity: (!reviewMode || complete) ? 1 : 0.75,
              boxShadow: (!reviewMode || complete) ? '0 4px 14px rgba(124,58,237,0.3)' : 'none'
            }}
          >
            Continue <ArrowRight size={16} />
          </button>
        </div>
      </div>

      {/* Review Modal */}
      {reviewing && (
        <ReviewModal reviewing={reviewing} onClose={() => setRev(null)} onConfirm={runReview} />
      )}

      {/* Version History Drawer */}
      {historyDoc && (
        <VersionHistoryDrawer documentId={historyDoc} manage={manage} editable={editable} api={api}
          onClose={() => setHistoryDoc(null)} onRestored={() => { setHistoryDoc(null); onChanged() }} />
      )}

      {/* Modal Document Viewer */}
      {previewDoc && (
        <Overlay onClose={() => setPreviewDoc(null)} width={previewDoc.type === 'image' ? 680 : 850}>
          <div style={{ padding: '16px 20px', borderBottom: '1px solid var(--border)', display: 'flex', alignItems: 'center', justify: 'space-between' }}>
            <h3 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>{previewDoc.name}</h3>
            <button onClick={() => setPreviewDoc(null)} style={{ border: 'none', background: 'none', cursor: 'pointer', fontSize: 18 }}>✕</button>
          </div>
          <div style={{ padding: 20, textAlign: 'center', maxHeight: 600, overflowY: 'auto' }}>
            {previewDoc.type === 'image' ? (
              <img src={previewDoc.url} alt={previewDoc.name} style={{ maxWidth: '100%', maxHeight: 520, borderRadius: 8, objectFit: 'contain' }} />
            ) : (
              <iframe src={previewDoc.url} style={{ width: '100%', height: 500, border: 'none', borderRadius: 8 }} title={previewDoc.name} />
            )}
          </div>
          <ModalFooter>
            <a href={previewDoc.url} target="_blank" download style={{ padding: '8px 16px', borderRadius: 8, background: '#7C3AED', color: '#fff', textDecoration: 'none', fontSize: 12, fontWeight: 700 }}>Download File</a>
          </ModalFooter>
        </Overlay>
      )}

      {/* Provider Request Modal */}
      {selectedProviderReq && (
        <ProviderRequestModal
          provider={selectedProviderReq.provider}
          docRow={selectedProviderReq.docRow}
          onboarding={onboarding}
          user={user}
          onClose={() => setSelectedProviderReq(null)}
          onSubmitSuccess={(reqData) => {
            setSubmittedRequests(prev => [...prev, reqData])
          }}
        />
      )}
    </Panel>
  )
}

function ProviderRequestModal({ provider, docRow, onboarding, user, onClose, onSubmitSuccess }) {
  const p = onboarding?.profile || {}
  const v = onboarding?.vendor || {}
  const u = user || {}

  const initialFullName = p.full_name || p.contact_person || v.vendor_name || v.company_name || u.name || ''
  const initialEmail = p.email || p.contact_email || v.email || u.email || ''
  const initialMobile = p.mobile || p.contact_mobile || v.phone || u.phone || ''
  const initialCompany = p.company_name || v.company_name || v.company || ''

  const [fullName, setFullName] = useState(initialFullName)
  const [email, setEmail] = useState(initialEmail)
  const [mobile, setMobile] = useState(initialMobile)
  const [countryCode, setCountryCode] = useState('+91')
  const [companyName, setCompanyName] = useState(initialCompany)
  const [notes, setNotes] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [success, setSuccess] = useState(false)

  const handleSubmit = (e) => {
    e.preventDefault()
    setSubmitting(true)

    setTimeout(() => {
      setSubmitting(false)
      setSuccess(true)
      if (onSubmitSuccess) {
        onSubmitSuccess({
          vendorId: v.id || onboarding?.vendor_id,
          providerId: provider.id,
          providerName: provider.name,
          docType: docRow.type,
          docLabel: docRow.type_label,
          fullName,
          email,
          mobile: `${countryCode} ${mobile}`,
          companyName,
          notes,
          createdAt: new Date().toISOString(),
          status: 'Pending'
        })
      }
    }, 500)
  }

  return (
    <Overlay onClose={onClose} width={540}>
      {/* Orange Gradient Header */}
      <div style={{
        background: 'linear-gradient(135deg, #f97316, #ea580c)',
        padding: '24px 24px 20px', color: '#fff', borderTopLeftRadius: 16, borderTopRightRadius: 16,
        position: 'relative'
      }}>
        <button
          onClick={onClose}
          style={{
            position: 'absolute', top: 16, right: 16, border: 'none', background: 'rgba(255,255,255,0.2)',
            color: '#fff', width: 28, height: 28, borderRadius: '50%', cursor: 'pointer', fontSize: 16, fontWeight: 700
          }}
        >
          ✕
        </button>

        <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
          <div style={{
            width: 48, height: 48, borderRadius: 12, background: provider.logoBg,
            display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fff',
            fontWeight: 900, fontSize: 18, boxShadow: '0 4px 12px rgba(0,0,0,0.15)'
          }}>
            {provider.logoText}
          </div>
          <div>
            <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#fff' }}>{provider.name}</h3>
            <p style={{ margin: '2px 0 0', fontSize: 12.5, opacity: 0.9 }}>{provider.desc}</p>
          </div>
        </div>

        <div style={{ marginTop: 14 }}>
          <span style={{
            display: 'inline-block', padding: '4px 10px', borderRadius: 20,
            background: 'rgba(255,255,255,0.25)', color: '#fff', fontSize: 11.5, fontWeight: 700,
            backdropFilter: 'blur(4px)'
          }}>
            📄 {docRow.type_label}
          </span>
        </div>
      </div>

      <div style={{ padding: 24 }}>
        {success ? (
          <div style={{ padding: 20, borderRadius: 12, background: '#f0fdf4', border: '1px solid #bbf7d0', textAlign: 'center' }}>
            <CheckCircle size={36} style={{ color: '#16a34a', marginBottom: 10, margin: '0 auto' }} />
            <h4 style={{ margin: '8px 0 6px', fontSize: 16, fontWeight: 800, color: '#15803d' }}>Request Submitted Successfully</h4>
            <p style={{ margin: 0, fontSize: 13, color: '#166534' }}>
              Our partner <strong>{provider.name}</strong> will contact you shortly regarding your <strong>{docRow.type_label}</strong>.
            </p>
            <button
              onClick={onClose}
              style={{
                marginTop: 18, padding: '9px 24px', borderRadius: 8, background: '#16a34a', color: '#fff',
                border: 'none', fontWeight: 700, fontSize: 13, cursor: 'pointer'
              }}
            >
              Done
            </button>
          </div>
        ) : (
          <form onSubmit={handleSubmit}>
            {/* Information Banner */}
            <div style={{
              padding: '12px 14px', borderRadius: 10, background: '#fff7ed', border: '1px solid #fed7aa',
              fontSize: 12.5, color: '#9a3412', marginBottom: 18, lineHeight: 1.5
            }}>
              Fill in your details. <strong>{provider.name}</strong> will contact you within 24 hours to help obtain your <strong>{docRow.type_label}</strong>.
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14, marginBottom: 14 }}>
              <Field label="Full Name *">
                <TextInput value={fullName} onChange={e => setFullName(e.target.value)} required />
              </Field>
              <Field label="Email Address *">
                <TextInput type="email" value={email} onChange={e => setEmail(e.target.value)} required />
              </Field>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14, marginBottom: 14 }}>
              <Field label="Mobile Number *">
                <div style={{ display: 'flex', gap: 6 }}>
                  <select
                    value={countryCode}
                    onChange={e => setCountryCode(e.target.value)}
                    style={{ padding: '8px 10px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 12.5 }}
                  >
                    <option value="+91">🇮🇳 +91</option>
                    <option value="+1">🇺🇸 +1</option>
                    <option value="+44">🇬🇧 +44</option>
                    <option value="+971">🇦🇪 +971</option>
                  </select>
                  <TextInput value={mobile} onChange={e => setMobile(e.target.value)} placeholder="Mobile Number" required style={{ flex: 1 }} />
                </div>
              </Field>
              <Field label="Company Name">
                <TextInput value={companyName} onChange={e => setCompanyName(e.target.value)} />
              </Field>
            </div>

            <Field label="Notes (Optional)">
              <textarea
                value={notes}
                onChange={e => setNotes(e.target.value)}
                rows={3}
                placeholder="Specify any additional requirements or notes for the compliance provider..."
                style={{ ...inputStyle, resize: 'vertical' }}
              />
            </Field>

            <div style={{ marginTop: 20, display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12, paddingTop: 16, borderTop: '1px solid var(--border)' }}>
              <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
                Your details are shared only with the selected provider. <a href="#" onClick={e => { e.preventDefault(); alert('We share your details securely with compliance partners solely to assist with document acquisition.') }} style={{ color: '#f97316', textDecoration: 'underline' }}>Learn more</a>
              </span>

              <button
                type="submit"
                disabled={submitting}
                style={{
                  padding: '10px 22px', borderRadius: 10, border: 'none',
                  background: 'linear-gradient(135deg, #f97316, #ea580c)', color: '#fff',
                  fontWeight: 800, fontSize: 13, cursor: 'pointer', boxShadow: '0 4px 14px rgba(249,115,22,0.3)',
                  opacity: submitting ? 0.7 : 1
                }}
              >
                {submitting ? 'Sending Request…' : 'Send Request to Provider'}
              </button>
            </div>
          </form>
        )}
      </div>
    </Overlay>
  )
}

function VersionHistoryDrawer({ documentId, manage, editable, onClose, onRestored, api }) {
  const [versions, setVersions] = useState(null)
  const [busy, setBusy] = useState(null)

  const load = () => api.documents.versions(documentId).then(d => setVersions(d?.data ?? d ?? [])).catch(() => setVersions([]))
  useEffect(() => { load() }, [documentId]) // eslint-disable-line react-hooks/exhaustive-deps

  const download = async (v) => {
    setBusy(`d${v.id}`)
    try {
      const blob = await api.documents.downloadVersion(documentId, v.id)
      const url = URL.createObjectURL(blob); window.open(url, '_blank', 'noopener')
      setTimeout(() => URL.revokeObjectURL(url), 60000)
    } catch { alert('Could not download this version.') } finally { setBusy(null) }
  }

  const restore = async (v) => {
    if (!confirm(`Restore version ${v.version_no}? The document returns to Pending review.`)) return
    setBusy(`r${v.id}`)
    try { await api.documents.restoreVersion(documentId, v.id); onRestored() }
    catch (e) { alert(e?.response?.data?.message || 'Restore failed'); setBusy(null) }
  }

  return (
    <Overlay onClose={onClose} width={560}>
      <div style={{ padding: '20px 22px 6px' }}>
        <h2 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>Version History</h2>
        <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>Every upload, replace, resubmit and restore is kept.</p>
      </div>
      <div style={{ padding: '10px 22px 18px', maxHeight: 460, overflowY: 'auto' }}>
        {versions === null ? (
          <div style={{ padding: 20, textAlign: 'center' }}><Loader size={18} /></div>
        ) : versions.length === 0 ? (
          <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>No versions recorded yet.</p>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            {versions.map(v => (
              <div key={v.id} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '10px 12px', borderRadius: 12, background: 'var(--bg-input)', border: `1px solid ${v.is_current ? 'rgba(16,185,129,0.4)' : 'var(--border)'}` }}>
                <div style={{ width: 34, height: 34, borderRadius: 10, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.14)', color: '#a78bfa', fontWeight: 800, fontSize: 12 }}>v{v.version_no}</div>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 8 }}>
                    {v.original_name || `Version ${v.version_no}`}
                    {v.is_current && <span style={{ fontSize: 10, fontWeight: 800, color: '#10b981' }}>● Current</span>}
                    {v.restored_from_version_id && <span style={{ fontSize: 10, color: 'var(--text-muted)' }}>restored</span>}
                  </div>
                  <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{v.status_at_capture || '—'} · {fmtDate(v.created_at)}</div>
                </div>
                <MiniBtn onClick={() => download(v)} color="var(--text-muted)" icon={busy === `d${v.id}` ? Loader : Download} border>Download</MiniBtn>
                {manage && editable && !v.is_current && (
                  <MiniBtn onClick={() => restore(v)} color="#f59e0b" icon={busy === `r${v.id}` ? Loader : RotateCcw}>Restore</MiniBtn>
                )}
              </div>
            ))}
          </div>
        )}
      </div>
    </Overlay>
  )
}

const ReviewStat = ({ label, value, color }) => (
  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '5px 11px', borderRadius: 10, background: `${color}14`, border: `1px solid ${color}44` }}>
    <span style={{ fontSize: 14, fontWeight: 800, color }}>{value}</span>
    <span style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)' }}>{label}</span>
  </span>
)

const MiniBtn = ({ onClick, color, icon: Icon, border, disabled, children }) => (
  <button onClick={onClick} disabled={disabled}
    style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11, fontWeight: 700, padding: '5px 9px', borderRadius: 7,
      border: border ? '1px solid var(--border)' : 'none', background: border ? 'var(--bg-card)' : `${color}1f`, color,
      cursor: disabled ? 'not-allowed' : 'pointer', opacity: disabled ? 0.5 : 1 }}>
    <Icon size={11} /> {children}
  </button>
)

const DocSummary = ({ s, complete }) => (
  <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
    {[['Approved', s.approved, '#10b981'], ['Pending', s.pending, '#f59e0b'], ['Rejected', s.rejected, '#ef4444']].map(([l, n, c]) => (
      <span key={l} style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '4px 10px', borderRadius: 999, background: `${c}18`, border: `1px solid ${c}44`, fontSize: 11, fontWeight: 700, color: c }}>
        {n || 0} {l}
      </span>
    ))}
    {complete && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11, fontWeight: 800, color: '#10b981' }}><CheckCircle size={12} /> Complete</span>}
  </div>
)

function ReviewModal({ reviewing, onClose, onConfirm }) {
  const { row, decision } = reviewing
  const isReject = decision === 'reject'
  const [remarks, setRemarks] = useState('')
  const [loading, setLoading] = useState(false)
  const go = async () => { setLoading(true); await onConfirm(remarks); setLoading(false) }

  return (
    <Overlay onClose={() => !loading && onClose()} width={460}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14 }}>
        {isReject ? <XCircle size={22} color="#ef4444" /> : <CheckCircle size={22} color="#10b981" />}
        <h3 style={{ color: 'var(--text-h)', margin: 0, fontSize: 16, fontWeight: 800 }}>{isReject ? 'Reject' : 'Approve'} Document</h3>
      </div>
      <p style={{ color: 'var(--text-muted)', fontSize: 13, marginBottom: 14 }}>
        <strong style={{ color: 'var(--text-h)' }}>{row.type_label}</strong> — {row.original_name}
      </p>
      {isReject && <InfoBox tone="danger">The vendor will be able to resubmit this document.</InfoBox>}
      <label style={labelStyle}>{isReject ? 'Reason for rejection *' : 'Remarks (optional)'}</label>
      <textarea value={remarks} onChange={e => setRemarks(e.target.value)} rows={3}
        placeholder={isReject ? 'e.g. Illegible scan, please re-upload' : 'Add remarks…'}
        style={{ ...inputStyle, resize: 'vertical', borderColor: isReject && !remarks ? '#ef444480' : 'var(--border)' }} />
      <ModalFooter onClose={onClose} onConfirm={go} loading={loading} disabled={isReject && !remarks}
        confirmLabel={isReject ? 'Reject' : 'Approve'} color={isReject ? '#ef4444' : '#10b981'} />
    </Overlay>
  )
}

// ── Step 5 — Confirmation ────────────────────────────────────────────────────
// ── Step 5 — Confirmation ────────────────────────────────────────────────────
function StepConfirmation({ onboarding, progress, editable, onSaved, onBack, onContinue, onSubmitted, api }) {
  const [submitting, setSubmitting] = useState(false)
  const [declared, setDeclared]     = useState(false)
  const [err, setErr]               = useState(null)

  const steps = progress.steps || []
  const blockers = steps.filter(s => [2, 3].includes(s.step) && !s.complete)

  const handleContinue = () => {
    if (!declared) {
      setErr('Please accept the declaration before continuing to Step 6.')
      return
    }
    setErr(null)
    onContinue()
  }

  return (
    <Panel title="Final Confirmation" sub="Review your completion checklist and accept the declaration to proceed to Step 6">
      <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginBottom: 16 }}>
        {steps.slice(0, 4).map(s => (
          <div key={s.key} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '10px 14px', borderRadius: 10, background: 'var(--bg-input)', border: `1px solid ${s.complete ? 'rgba(16,185,129,0.3)' : 'var(--border)'}` }}>
            {s.complete
              ? <CheckCircle size={15} style={{ color: '#10b981', flexShrink: 0 }} />
              : <AlertTriangle size={15} style={{ color: '#f59e0b', flexShrink: 0 }} />}
            <span style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>{s.label}</span>
            <span style={{ marginLeft: 'auto', fontSize: 11.5, color: s.complete ? '#10b981' : '#f59e0b', fontWeight: 600 }}>{s.detail}</span>
          </div>
        ))}
      </div>

      {blockers.length > 0
        ? <InfoBox tone="danger">Please complete all required steps before proceeding: {blockers.map(b => b.label).join(', ')}.</InfoBox>
        : <InfoBox>All preliminary requirements met. Accept the declaration below to continue to Step 6 (Final Review &amp; Submission).</InfoBox>}

      <label style={{
        display: 'flex', alignItems: 'flex-start', gap: 10, marginTop: 14, padding: '13px 15px', borderRadius: 12,
        cursor: blockers.length ? 'not-allowed' : 'pointer',
        background: declared ? 'rgba(16,185,129,0.08)' : 'var(--bg-input)',
        border: `1px solid ${declared ? 'rgba(16,185,129,0.35)' : 'var(--border)'}`
      }}>
        <input
          type="checkbox"
          checked={declared}
          disabled={blockers.length > 0}
          onChange={e => { setDeclared(e.target.checked); if (e.target.checked) setErr(null) }}
          style={{ width: 17, height: 17, marginTop: 1, accentColor: '#7C3AED', flexShrink: 0 }}
        />
        <span style={{ fontSize: 12.5, color: 'var(--text-h)', lineHeight: 1.5 }}>
          I hereby declare that all information submitted is true and correct to the best of my knowledge.
        </span>
      </label>

      {err && (
        <div style={{ marginTop: 10, padding: '8px 12px', borderRadius: 8, background: '#fee2e2', border: '1px solid #fca5a5', color: '#dc2626', fontSize: 12, fontWeight: 700 }}>
          {err}
        </div>
      )}

      {/* Step 5 Bottom Navigation Bar */}
      <div style={{
        marginTop: 24, paddingTop: 16, borderTop: '1px solid var(--border)',
        display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12
      }}>
        <button
          type="button"
          onClick={onBack}
          style={{
            display: 'inline-flex', alignItems: 'center', gap: 6, padding: '10px 20px', borderRadius: 10,
            border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-h)',
            fontWeight: 700, fontSize: 13, cursor: 'pointer'
          }}
        >
          <ArrowLeft size={16} /> Back
        </button>

        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <button
            type="button"
            onClick={onSaved}
            style={{
              display: 'inline-flex', alignItems: 'center', gap: 6, padding: '10px 20px', borderRadius: 10,
              border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-h)',
              fontWeight: 700, fontSize: 13, cursor: 'pointer'
            }}
          >
            Save Draft
          </button>

          <button
            type="button"
            onClick={handleContinue}
            disabled={blockers.length > 0}
            style={{
              display: 'inline-flex', alignItems: 'center', gap: 8, padding: '10px 24px', borderRadius: 10,
              border: 'none', background: declared ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'rgba(124,58,237,0.4)',
              color: '#fff', fontWeight: 800, fontSize: 13, cursor: blockers.length > 0 ? 'not-allowed' : 'pointer',
              boxShadow: declared ? '0 4px 14px rgba(124,58,237,0.3)' : 'none'
            }}
          >
            Continue <ArrowRight size={16} />
          </button>
        </div>
      </div>
    </Panel>
  )
}

// ── Step 6 — Admin approval panel ────────────────────────────────────────────
function StepSubmission({ onboarding, vendor, admin, onChanged, onBack, api, user, engagement = 'tpv' }) {
  const navigate = useNavigate()
  const [modal, setModal]     = useState(null)  // 'approve' | 'reject' | 'hold' | 'resubmit'
  const [remarks, setRemarks] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [loading, setLoading]       = useState(false)

  const isApproved  = onboarding.status === 'Approved'
  const isHold      = onboarding.status === 'On_Hold'
  const isRejected  = onboarding.status === 'Rejected'
  const isResubmit  = onboarding.status === 'Resubmit_Required'
  const isSubmitted = ['Submitted', 'Under_Review', 'Pending_Approval'].includes(onboarding.status) && !isApproved
  const isEditable  = onboarding.is_editable ?? (!isSubmitted && !isApproved && !isHold && !isRejected)

  const vendorActive = (vendor?.status ?? onboarding.vendor?.status) === 'Active'
  const vendorId     = vendor?.id ?? onboarding.vendor?.id

  // Vendor Submit Action
  const handleSubmitOnboarding = async () => {
    setSubmitting(true)
    try {
      await api.onboarding.submit(onboarding.id, { declaration: true })
      onChanged()
    } catch (e) {
      alert(e?.response?.data?.message || 'Submission failed')
    } finally {
      setSubmitting(false)
    }
  }

  // Admin Decision Execution
  const runAdminDecision = async () => {
    if ((modal === 'reject' || modal === 'hold' || modal === 'resubmit') && !remarks.trim()) {
      alert('Mandatory remarks are required for this action.')
      return
    }
    setLoading(true)
    try {
      if (modal === 'approve') await api.onboarding.approve(onboarding.id, remarks)
      else if (modal === 'reject') await api.onboarding.reject(onboarding.id, remarks)
      else if (modal === 'hold') await api.onboarding.hold(onboarding.id, remarks)
      else if (modal === 'resubmit') await api.onboarding.requestResubmit(onboarding.id, remarks)
      setModal(null)
      setRemarks('')
      onChanged()
    } catch (e) {
      alert(e?.response?.data?.message || 'Decision execution failed')
    } finally {
      setLoading(false)
    }
  }

  return (
    <Panel title="Final Review & Submission" sub="Review your completed application and submit for administrator review">
      {/* ── ISSUE 1: APPROVED CONGRATULATIONS PAGE ──────────────── */}
      {isApproved && (
        <div style={{
          padding: 24, borderRadius: 16, position: 'relative', overflow: 'hidden',
          background: 'linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%)',
          border: '1px solid #6ee7b7', marginBottom: 24,
          boxShadow: '0 8px 24px -6px rgba(16,185,129,0.18)'
        }}>
          {/* Confetti Particles Animation Accent */}
          <div style={{ position: 'absolute', top: -10, right: 20, fontSize: 32, opacity: 0.85, userSelect: 'none', animation: 'bounce 2s infinite' }}>🎉</div>
          <div style={{ position: 'absolute', top: 12, right: 90, fontSize: 24, opacity: 0.75, userSelect: 'none' }}>✨</div>
          <div style={{ position: 'absolute', bottom: 10, right: 30, fontSize: 28, opacity: 0.75, userSelect: 'none' }}>🎊</div>

          <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 18 }}>
            <div style={{
              width: 56, height: 56, borderRadius: 16,
              background: 'linear-gradient(135deg,#10b981,#059669)',
              display: 'flex', alignItems: 'center', justifyContent: 'center',
              color: '#fff', boxShadow: '0 4px 14px rgba(16,185,129,0.4)', flexShrink: 0
            }}>
              <CheckCircle size={30} />
            </div>
            <div>
              <h2 style={{ margin: 0, fontSize: 20, fontWeight: 900, color: '#065f46', letterSpacing: '-0.02em' }}>
                🎉 Congratulations!
              </h2>
              <p style={{ margin: '3px 0 0', fontSize: 14, fontWeight: 800, color: '#047857' }}>
                Your onboarding has been successfully approved.
              </p>
              <p style={{ margin: '2px 0 0', fontSize: 12.5, color: '#065f46' }}>
                Your company has been successfully onboarded into the platform.
              </p>
            </div>
          </div>

          {/* Details Grid */}
          <div style={{
            display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))', gap: 12,
            padding: 16, borderRadius: 12, background: 'rgba(255,255,255,0.85)',
            border: '1px solid #a7f3d0', backdropFilter: 'blur(4px)', marginBottom: 18
          }}>
            <div>
              <span style={{ fontSize: 11, fontWeight: 800, color: '#047857', display: 'block', textTransform: 'uppercase', letterSpacing: '0.04em' }}>Registration Number</span>
              <strong style={{ fontSize: 13.5, color: '#065f46' }}>{onboarding.registration_number || 'Generated'}</strong>
            </div>
            <div>
              <span style={{ fontSize: 11, fontWeight: 800, color: '#047857', display: 'block', textTransform: 'uppercase', letterSpacing: '0.04em' }}>Vendor Code</span>
              <strong style={{ fontSize: 13.5, color: '#065f46' }}>{vendor?.vendor_code || onboarding.vendor?.vendor_code || `#${onboarding.vendor_id}`}</strong>
            </div>
            <div>
              <span style={{ fontSize: 11, fontWeight: 800, color: '#047857', display: 'block', textTransform: 'uppercase', letterSpacing: '0.04em' }}>Approved Date</span>
              <strong style={{ fontSize: 13.5, color: '#065f46' }}>{fmtDate(onboarding.approved_at)}</strong>
            </div>
            <div>
              <span style={{ fontSize: 11, fontWeight: 800, color: '#047857', display: 'block', textTransform: 'uppercase', letterSpacing: '0.04em' }}>Approved By</span>
              <strong style={{ fontSize: 13.5, color: '#065f46' }}>{onboarding.approver?.name || 'Administrator'}</strong>
            </div>
            <div>
              <span style={{ fontSize: 11, fontWeight: 800, color: '#047857', display: 'block', textTransform: 'uppercase', letterSpacing: '0.04em' }}>Status</span>
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '3px 12px', borderRadius: 999, background: '#10b981', color: '#fff', fontSize: 11.5, fontWeight: 800, marginTop: 2 }}>
                <CheckCircle size={12} /> Approved
              </span>
            </div>
          </div>

          {/* 🚀 START WORKFORCE CTA BUTTON — TPV-only (purchase vendors have no workforce) */}
          {engagement === 'tpv' && (
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12, paddingTop: 10, borderTop: '1px dashed #6ee7b7' }}>
            <div style={{ fontSize: 13, fontWeight: 700, color: '#065f46' }}>
              Your account is active and verified. You are ready to start onboarding site workers.
            </div>
            <button
              type="button"
              onClick={() => {
                if (admin && vendorId) navigate(`/app/tpv/workforce/vendor/${vendorId}/dashboard`)
                else navigate('/vendor-portal/workforce')
              }}
              style={{
                display: 'inline-flex', alignItems: 'center', gap: 8, padding: '12px 26px', borderRadius: 12,
                border: 'none', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff',
                fontWeight: 800, fontSize: 14, cursor: 'pointer',
                boxShadow: '0 6px 20px rgba(124,58,237,0.35)', transition: 'transform 0.15s, boxShadow 0.15s'
              }}
            >
              <Rocket size={18} /> Start Workforce
            </button>
          </div>
          )}
        </div>
      )}

      {/* ── AWAITING APPROVAL BANNER (NON-APPROVED) ──────────────── */}
      {isSubmitted && (
        <div style={{ padding: 20, borderRadius: 14, background: '#f0fdf4', border: '1px solid #bbf7d0', marginBottom: 20 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6 }}>
            <CheckCircle size={22} style={{ color: '#16a34a' }} />
            <h3 style={{ margin: 0, fontSize: 16, fontWeight: 900, color: '#15803d' }}>
              ✓ All onboarding steps completed.
            </h3>
          </div>
          <p style={{ margin: '0 0 10px', fontSize: 13, color: '#166534', lineHeight: 1.5 }}>
            Your onboarding has been submitted successfully and is currently under review by the administrator.
          </p>
          <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '5px 14px', borderRadius: 20, background: '#e0f2fe', border: '1px solid #bae6fd', color: '#0369a1', fontSize: 12, fontWeight: 800 }}>
            <Clock size={14} /> Current Status: Awaiting Approval
          </div>
        </div>
      )}

      {/* ── ISSUE 5: REJECTED BANNER ────────────────────────────── */}
      {isRejected && (
        <div style={{ padding: 20, borderRadius: 14, background: '#fef2f2', border: '1px solid #fca5a5', marginBottom: 20 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, color: '#b91c1c', fontWeight: 900, fontSize: 16, marginBottom: 6 }}>
            <XCircle size={22} /> Your onboarding has been rejected.
          </div>
          <p style={{ margin: '0 0 10px', fontSize: 13, color: '#991b1b', lineHeight: 1.5 }}>
            <strong>Reason / Admin Remarks:</strong> {onboarding.remarks || 'Your onboarding application was not approved.'}
          </p>
          <div style={{ fontSize: 12, fontWeight: 700, color: '#7f1d1d' }}>
            Please contact the Administrator for assistance.
          </div>
        </div>
      )}

      {/* ── ISSUE 5: ON HOLD BANNER ─────────────────────────────── */}
      {isHold && (
        <div style={{ padding: 20, borderRadius: 14, background: '#fffbeb', border: '1px solid #fde68a', marginBottom: 20 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, color: '#b45309', fontWeight: 900, fontSize: 16, marginBottom: 6 }}>
            <AlertTriangle size={22} /> Your onboarding has been placed On Hold.
          </div>
          <p style={{ margin: '0 0 10px', fontSize: 13, color: '#92400e', lineHeight: 1.5 }}>
            <strong>Reason / Admin Remarks:</strong> {onboarding.hold_reason || onboarding.remarks || 'Your onboarding has been placed on hold pending review.'}
          </p>
          <div style={{ fontSize: 12, fontWeight: 700, color: '#78350f' }}>
            Please contact the Administrator for further instructions.
          </div>
        </div>
      )}

      {/* ── RESUBMIT REQUIRED BANNER ────────────────────────────── */}
      {isResubmit && (
        <div style={{ padding: 18, borderRadius: 14, background: '#fef2f2', border: '1px solid #fca5a5', marginBottom: 20 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, color: '#b91c1c', fontWeight: 800, fontSize: 15, marginBottom: 4 }}>
            <CornerUpLeft size={20} /> Resubmission Requested
          </div>
          <p style={{ margin: 0, fontSize: 12.5, color: '#991b1b' }}>
            <strong>Admin Remarks:</strong> {onboarding.remarks || 'Please review the requested changes and update your details.'}
          </p>
        </div>
      )}

      {/* SUMMARY DATA CARD */}
      <div style={{ padding: 18, borderRadius: 14, background: 'var(--bg-input)', border: '1px solid var(--border)', marginBottom: 20 }}>
        <h4 style={{ margin: '0 0 12px', fontSize: 13.5, fontWeight: 800, color: 'var(--text-h)' }}>Application Summary</h4>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 12, fontSize: 12.5 }}>
          <div><span style={{ color: 'var(--text-muted)' }}>Company Name:</span> <strong>{onboarding.vendor?.company_name || onboarding.profile?.company_name || '—'}</strong></div>
          <div><span style={{ color: 'var(--text-muted)' }}>Kickoff MOM:</span> <strong style={{ color: '#10b981' }}>Accepted</strong></div>
          <div><span style={{ color: 'var(--text-muted)' }}>Company Profile:</span> <strong style={{ color: '#10b981' }}>Saved</strong></div>
          <div><span style={{ color: 'var(--text-muted)' }}>Declaration:</span> <strong style={{ color: '#10b981' }}>Accepted</strong></div>
        </div>
      </div>

      {/* VENDOR READY TO SUBMIT BUTTON */}
      {!admin && isEditable && !isSubmitted && !isApproved && (
        <div style={{ padding: 20, borderRadius: 14, background: 'rgba(124,58,237,0.06)', border: '1px dashed #a78bfa', textAlign: 'center', marginBottom: 20 }}>
          <h4 style={{ margin: '0 0 6px', fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Ready to Submit Your Onboarding?</h4>
          <p style={{ margin: '0 0 16px', fontSize: 12.5, color: 'var(--text-muted)' }}>
            Once submitted, your application will be locked and sent to the administrator for review and approval.
          </p>
          <button
            type="button"
            onClick={handleSubmitOnboarding}
            disabled={submitting}
            style={{
              padding: '12px 28px', borderRadius: 10, border: 'none',
              background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff',
              fontWeight: 800, fontSize: 14, cursor: 'pointer', boxShadow: '0 4px 14px rgba(124,58,237,0.3)',
              display: 'inline-flex', alignItems: 'center', gap: 8
            }}
          >
            <Send size={16} /> {submitting ? 'Submitting Application…' : 'Submit Onboarding'}
          </button>
        </div>
      )}

      {/* ADMIN DECISION TOOLBAR */}
      {admin && (
        <div style={{ marginTop: 20, paddingTop: 16, borderTop: '1px solid var(--border)', display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12 }}>
          <div style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>Admin Decision Control</div>

          <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
            <button
              onClick={() => { setModal('hold'); setRemarks('') }}
              style={{ padding: '8px 14px', borderRadius: 8, border: '1px solid #fde68a', background: '#fffbeb', color: '#b45309', fontWeight: 700, fontSize: 12, cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 4 }}
            >
              <PauseCircle size={14} /> Put On Hold
            </button>
            <button
              onClick={() => { setModal('resubmit'); setRemarks('') }}
              style={{ padding: '8px 14px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-card)', color: '#f59e0b', fontWeight: 700, fontSize: 12, cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 4 }}
            >
              <CornerUpLeft size={14} /> Send Back
            </button>
            <button
              onClick={() => { setModal('reject'); setRemarks('') }}
              style={{ padding: '8px 14px', borderRadius: 8, border: 'none', background: '#ef4444', color: '#fff', fontWeight: 700, fontSize: 12, cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 4 }}
            >
              <XCircle size={14} /> Reject
            </button>
            <button
              onClick={() => { setModal('approve'); setRemarks('') }}
              style={{ padding: '8px 18px', borderRadius: 8, border: 'none', background: 'linear-gradient(135deg,#10b981,#059669)', color: '#fff', fontWeight: 800, fontSize: 12.5, cursor: 'pointer', boxShadow: '0 4px 12px rgba(16,185,129,0.3)', display: 'inline-flex', alignItems: 'center', gap: 6 }}
            >
              <ShieldCheck size={15} /> Approve &amp; Activate Vendor
            </button>
          </div>
        </div>
      )}

      {/* BOTTOM TOOLBAR */}
      <div style={{ marginTop: 24, paddingTop: 16, borderTop: '1px solid var(--border)', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <button
          type="button"
          onClick={onBack}
          style={{
            display: 'inline-flex', alignItems: 'center', gap: 6, padding: '10px 20px', borderRadius: 10,
            border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-h)',
            fontWeight: 700, fontSize: 13, cursor: 'pointer'
          }}
        >
          <ArrowLeft size={16} /> Back
        </button>
      </div>

      {/* ADMIN DECISION MODAL */}
      {modal && (
        <Overlay onClose={() => !loading && setModal(null)} width={480}>
          <div style={{ padding: '18px 22px', borderBottom: '1px solid var(--border)', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 8 }}>
              {modal === 'approve' ? <CheckCircle size={18} style={{ color: '#10b981' }} /> : modal === 'hold' ? <PauseCircle size={18} style={{ color: '#b45309' }} /> : <XCircle size={18} style={{ color: '#ef4444' }} />}
              {modal === 'approve' ? 'Approve & Activate Vendor' : modal === 'hold' ? 'Put Onboarding On Hold' : modal === 'reject' ? 'Reject Onboarding' : 'Send Back for Revision'}
            </h3>
            <button onClick={() => setModal(null)} style={{ border: 'none', background: 'none', cursor: 'pointer', fontSize: 18 }}>✕</button>
          </div>

          <div style={{ padding: 22 }}>
            <p style={{ marginTop: 0, fontSize: 12.5, color: 'var(--text-muted)' }}>
              {modal === 'approve'
                ? 'Approving will generate the Registration Number, set the Vendor status to Active, log audit records, and dispatch Email & WhatsApp notifications.'
                : 'Please specify the mandatory rationale for this action.'}
            </p>

            <div style={{ marginBottom: 14 }}>
              <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--text-h)', marginBottom: 6 }}>
                {modal === 'approve' ? 'Remarks (Optional)' : 'Remarks / Reason *'}
              </label>
              <textarea
                value={remarks}
                onChange={e => setRemarks(e.target.value)}
                rows={3}
                placeholder={modal === 'approve' ? 'e.g. HSSE and compliance cleared...' : 'Enter mandatory remarks...'}
                style={{ width: '100%', padding: 10, borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 12.5, outline: 'none', resize: 'vertical' }}
              />
            </div>

            <ModalFooter onClose={() => setModal(null)} onConfirm={runAdminDecision} loading={loading}
              disabled={(modal === 'reject' || modal === 'hold' || modal === 'resubmit') && !remarks.trim()}
              confirmLabel={modal === 'approve' ? 'Approve & Activate' : modal === 'hold' ? 'Confirm Hold' : modal === 'reject' ? 'Confirm Rejection' : 'Send Back'}
              color={modal === 'approve' ? '#10b981' : modal === 'hold' ? '#f59e0b' : '#ef4444'} />
          </div>
        </Overlay>
      )}
    </Panel>
  )
}
