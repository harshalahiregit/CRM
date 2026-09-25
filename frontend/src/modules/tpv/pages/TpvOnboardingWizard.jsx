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
import WorkStartLetterCard from '@/components/portal/WorkStartLetterCard'
import KickoffMomReview from '@/components/portal/KickoffMomReview'
import VendorDocumentsPanel from '@/components/vendor/VendorDocumentsPanel'
import { TPV_DOC_CATALOG } from '@/components/vendor/documentCatalog'
import { readFieldErrors, prettyField } from '@/services/apiError'
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
import '@/pages/vendor-portal/portal.css'

const STEP_ICONS = { kickoff: ClipboardList, profile: UserCheck, documents: FileText, review: ShieldCheck, confirmation: Check, submission: Rocket }

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

/**
 * One line naming what is wrong, for the top of a long form.
 *
 * `validateProfile` writes terse hints meant to sit beside a box ("6 digits"),
 * which say nothing on their own — so they are paired with the field's name.
 */
function summarise(errs) {
  const entries = Object.entries(errs).filter(([, v]) => v)
  if (entries.length === 0) return null
  if (entries.length === 1) {
    const [k, v] = entries[0]
    return `${prettyField(k)}: ${v}`
  }
  return `Please correct ${entries.length} fields — they are marked in red below.`
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
      // Open editable onboardings at the step the vendor left off on, but a
      // submitted/decided one (Approved/Rejected/On_Hold/Submitted/Under_Review)
      // jumps straight to Step 6 — its outcome — instead of a stale, locked
      // mid-wizard screen (e.g. an approved vendor whose current_step was still 3
      // would otherwise land on a read-only "upload documents" panel that looks
      // broken). The stepper still lets them browse the earlier steps.
      if (!keepStep) setActive(isOnboardingEditable(ob?.status) ? (ob?.current_step || 1) : 6)
    } catch (e) { console.error('Failed to load onboarding', e) }
    finally { setLoading(false) }
  }, [id, api])
  useEffect(() => { load() }, [load])

  // Refresh only the derived state (after an upload/review) without losing the step.
  const refresh = () => load(true)

  const editable = isOnboardingEditable(onboarding?.status)

  /**
   * A step with a form registers how to persist it, so leaving the step keeps
   * what was typed.
   *
   * Moving through the wizard used to switch the panel and nothing else: a
   * vendor who filled in half the profile and pressed the next step lost every
   * word of it, with no warning and nothing to go back to. The server accepts a
   * partial profile — every field on it is nullable — so what has been entered
   * is stored as a draft on the way past, and the strict check stays where it
   * belongs, on Save & Continue and on submission.
   */
  const flushRef = useRef(null)
  const registerFlush = useCallback((fn) => { flushRef.current = fn }, [])

  const goStep = async (step) => {
    // Never trap somebody on a step: a draft that will not save is a reason to
    // say so, not a reason to refuse to move.
    let flushed = false
    try { flushed = await flushRef.current?.() } catch { /* the step reports its own error */ }
    flushRef.current = null

    setActive(step)
    if (editable) api.onboarding.setStep(id, step).catch(() => {})

    // Each step seeds itself from `onboarding`. Without this refetch it seeds
    // from the copy loaded when the page opened — so a draft that WAS stored
    // came back as an empty form, and saving that form then wrote the stale
    // values back over the good ones.
    if (flushed) load(true)
  }

  if (loading || !onboarding || !progress) {
    return <div style={{ padding: 24, color: 'var(--text-muted)' }}>Loading onboarding…</div>
  }

  const vendor = onboarding.vendor || {}
  const steps  = progress.steps || []
  const activeStep = steps.find(s => s.step === active) || steps[0]


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

      {/* One progress indicator, the Purchase one. The portal used to stack a
          second horizontal bar on top of this, so the two engines disagreed
          about what the top of the same wizard looked like. */}
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
        {active === 2 && <StepProfile onboarding={onboarding} editable={editable} onSaved={refresh} onBack={() => goStep(1)} onContinue={() => goStep(3)} registerFlush={registerFlush} api={api} user={user} />}
        {active === 3 && <StepDocuments checklist={progress.documents} vendorId={vendor.id} onboarding={onboarding} editable={editable} manage={manage} admin={false} onChanged={refresh} onBack={() => goStep(2)} onContinue={() => goStep(4)} api={api} user={user} />}
        {active === 4 && <StepDocuments checklist={progress.documents} vendorId={vendor.id} editable={editable} manage={manage} admin={admin} reviewMode onChanged={refresh} onContinue={() => goStep(5)} api={api} />}
        {active === 5 && <StepConfirmation onboarding={onboarding} progress={progress} editable={editable} onSaved={refresh} onBack={() => goStep(4)} onContinue={() => goStep(6)} onSubmitted={refresh} api={api} />}
        {active === 6 && <StepSubmission onboarding={onboarding} vendor={vendor} admin={admin} onChanged={refresh} onBack={() => goStep(5)} api={api} user={user} engagement={cfg.engagement} isPortal={isPortal} />}
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

/**
 * Step tracker — the Purchase presentation, shared.
 *
 * TPV drew each step in its own colour with a third line of detail and stretched
 * the row to fill the width; Purchase draws one accent, fixed-width knobs and a
 * connector, and puts the detail in the tooltip. Same wizard, two looks — so
 * this is now Purchase's, and the detail still reaches the reader on hover.
 */
function Stepper({ steps, active, onGo }) {
  return (
    <div className="pr-glass" style={{ padding: 14, marginBottom: 16, overflowX: 'auto' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 0, minWidth: 'max-content' }}>
        {steps.map((s, i) => {
          const Icon = STEP_ICONS[s.key] || FileText
          const on = s.step === active
          const lit = s.complete || on
          return (
            <div key={s.key} style={{ display: 'flex', alignItems: 'center' }}>
              <button type="button" onClick={() => onGo(s.step)} title={s.detail}
                style={{ display: 'flex', alignItems: 'center', gap: 9, padding: '9px 13px', borderRadius: 13, cursor: 'pointer', minWidth: 150,
                  background: lit ? 'linear-gradient(135deg, rgba(124,58,237,.2), rgba(124,58,237,.06))' : 'var(--bg-input)',
                  border: `1.5px solid ${on ? '#7C3AED' : s.complete ? 'rgba(124,58,237,0.4)' : 'var(--border)'}`, opacity: lit ? 1 : 0.65 }}>
                <span style={{ position: 'relative', width: 32, height: 32, borderRadius: 10, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', color: '#fff', flexShrink: 0 }}>
                  <Icon size={15} />
                  {s.complete && (
                    <span style={{ position: 'absolute', right: -4, bottom: -4, width: 15, height: 15, borderRadius: '50%', background: '#10b981', border: '2px solid var(--bg-card)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                      <Check size={8} color="#fff" strokeWidth={4} />
                    </span>
                  )}
                </span>
                <span style={{ textAlign: 'left', lineHeight: 1.2 }}>
                  <span style={{ display: 'block', fontSize: 8.5, fontWeight: 800, letterSpacing: '0.06em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>Step {s.step}</span>
                  <span style={{ display: 'block', fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)', whiteSpace: 'nowrap' }}>{s.label}</span>
                </span>
              </button>
              {i < steps.length - 1 && (
                <div style={{ width: 18, height: 3, borderRadius: 4, margin: '0 4px', flexShrink: 0, background: s.complete ? '#7C3AED' : 'var(--border)' }} />
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
  // The minutes as data. The PDF stays for View/Download; this is what the
  // vendor actually reads.
  const [mom, setMom] = useState(null)
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

  /*
   * The minutes, fetched on their own — and from the ONBOARDING, not a meeting
   * this screen picked for itself.
   *
   * Two earlier mistakes, both worth naming. First this hung off the PDF
   * download's `.then()`, so the text waited on a blob and never ran at all on
   * the portal (loadMeeting bails there — kickoffApi is the admin workspace and
   * answers a vendor 403), leaving "Loading the minutes…" forever. Then it
   * resolved its own meeting out of the governance list while the PDF resolved a
   * different one through findKickoffMeeting(), so a populated document sat
   * beside empty sections.
   *
   * `kickoffData` uses the SAME server-side resolver as `kickoffPdf`. One
   * resolver, one meeting — the only arrangement in which the screen and the
   * document cannot disagree.
   */
  useEffect(() => {
    let alive = true
    api.onboarding.kickoffData(onboarding.id)
      .then(d => { if (alive) setMom(d?.meeting ? d : {}) })
      .catch(() => { if (alive) setMom({}) })   // always resolves, never spins

    return () => { alive = false }
  }, [onboarding.id, api])

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
  // A terminally decided onboarding (approved/rejected) can no longer be
  // acknowledged — signing is moot and the backend rejects it. Mirror that here.
  const decided = ['Approved', 'Rejected'].includes(onboarding?.status)
  // Acknowledging the MINUTES is separate from editing onboarding DATA — the MOM
  // often arrives after the vendor has already submitted (onboarding no longer
  // "editable"), yet Step 1 still needs sign-off. So the tick + submit depend on
  // the MOM being ready and unsigned, not on isOnboardingEditable. The vendor
  // (portal) can always sign; an admin only when the record is still editable.
  const canAck = readyForAck && !acknowledged && !decided && (isPortal || editable)

  const tbBtn = { display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 11px', borderRadius: 8, cursor: 'pointer', fontSize: 12, fontWeight: 700, color: 'var(--text-muted)', background: 'var(--bg-input)', border: '1px solid var(--border)' }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
      {/* State 1: Kickoff Not Completed / MOM Not Sent */}
      {(!readyForAck && !acknowledged) ? (
        <Panel title="Kickoff MOM Review &amp; Acknowledgement" sub="Step 2 of 7 · Kickoff Meeting">
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
          sub="Step 2 of 7 · Review Minutes of Meeting"
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
              <button style={tbBtn} onClick={doView}><Eye size={13} /> View PDF</button>
              <button style={tbBtn} onClick={doDownload}><Download size={13} /> Download PDF</button>
            </div>

            {/* The minutes as INFORMATION, the way the Purchase portal shows them.
                This was an embedded PDF in a zoomable grey viewer — a page image
                that does not reflow on a phone and cannot be searched by the
                person who has to act on it. The PDF is still one click away, for
                the two things a PDF is actually for. */}
            <div style={{ padding: '4px 2px' }}>
              <KickoffMomReview mom={mom} />
            </div>
          </div>

          {/* Acknowledgement Controls or Navigation */}
          <div style={{ marginTop: 18, padding: '16px 18px', borderRadius: 14, background: acknowledged ? 'rgba(16,185,129,0.06)' : 'var(--bg-input)', border: `1px solid ${acknowledged ? 'rgba(16,185,129,0.3)' : 'var(--border)'}` }}>
            {!acknowledged && decided ? (
              /* Approved/rejected before the minutes were signed — signing is moot
                 now, so show the outcome instead of a button that the server rejects. */
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12 }}>
                <span style={{ fontSize: 13, color: 'var(--text-muted)' }}>
                  This onboarding is already <strong style={{ color: 'var(--text-h)' }}>{obStatusCfg(onboarding.status).label}</strong> — the kickoff minutes no longer need acknowledgement.
                </span>
                <button onClick={onContinue} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '10px 18px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 700, color: '#fff', border: 'none', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 6px 18px -4px rgba(124,58,237,.5)' }}>
                  Continue <ArrowRight size={15} />
                </button>
              </div>
            ) : acknowledged ? (
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
                    disabled={!canAck}
                    rows={3}
                    maxLength={5000}
                    placeholder="Add your feedback or required changes"
                    style={{ width: '100%', padding: '9px 12px', fontSize: 13, borderRadius: 10, resize: 'vertical',
                      background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-body)' }}
                  />
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                <label style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: canAck ? 'pointer' : 'not-allowed', flex: 1, minWidth: 260 }}>
                  <input type="checkbox" checked={checked} disabled={!canAck} onChange={e => setChecked(e.target.checked)} style={{ width: 18, height: 18, accentColor: '#7C3AED' }} />
                  <span style={{ fontSize: 13.5, color: 'var(--text-h)', fontWeight: 600 }}>
                    I have read and understood the Minutes of Meeting.
                  </span>
                </label>
                <button
                  onClick={handleContinue}
                  disabled={!checked || accepting || !canAck}
                  style={{
                    display: 'inline-flex', alignItems: 'center', gap: 7, padding: '10px 20px', borderRadius: 11, cursor: (!checked || !canAck) ? 'not-allowed' : 'pointer',
                    fontSize: 13.5, fontWeight: 800, color: '#fff', border: 'none',
                    background: (!checked || !canAck) ? 'rgba(124,58,237,0.4)' : 'linear-gradient(145deg,#a78bfa,#7C3AED)',
                    boxShadow: (!checked || !canAck) ? 'none' : '0 8px 22px -6px rgba(124,58,237,.6)',
                    opacity: (!checked || !canAck) ? 0.7 : 1,
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
function StepProfile({ onboarding, editable, onSaved, onBack, onContinue, registerFlush, api, user }) {
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
  // One line at the top of a long form saying what is wrong, so a red border on
  // a box three screens down is not the only signal that the button did nothing.
  const [summary, setSummary] = useState(null)
  // What a draft save could not store yet. Not a failure — a note.
  const [skipped, setSkipped] = useState({})

  useEffect(() => {
    const init = getInitialProfile()
    setF(init)
    if (init.bank_account_number) {
      setAcctConfirm(init.bank_account_number)
    }
  }, [getInitialProfile])

  // Has anything been typed since the last successful save? Read by the flush
  // below, which runs from a callback registered once — so it must be a ref,
  // not state it would have closed over stale.
  const dirty = useRef(false)
  const markDirty = () => { dirty.current = true; setSaved(false) }

  const set = (k) => (e) => { setF(p => ({ ...p, [k]: e.target.value })); setErrs(x => ({ ...x, [k]: undefined })); markDirty() }

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
      markDirty()
    }
    reader.readAsDataURL(file)
  }

  const handleRemovePhoto = () => {
    setF(p => ({ ...p, profile_photo: '' }))
    setErrs(p => ({ ...p, profile_photo: undefined }))
    markDirty()
  }

  /**
   * Send whatever has been entered. No client-side gate — see saveDraft.
   *
   * A cleared box is sent as null rather than dropped. The server MERGES what
   * arrives onto the stored profile, so a dropped key reads as "leave it as it
   * was" — which made deleting a value impossible: it came back on the next
   * load. Every rule on this form is `nullable`, so null says "empty" honestly.
   */
  const persist = async (draft = false) => {
    const normalised = { ...f,
      gst_number: f.gst_number ? f.gst_number.toUpperCase() : '',
      pan_number: f.pan_number ? f.pan_number.toUpperCase() : '',
      bank_ifsc:  f.bank_ifsc ? f.bank_ifsc.toUpperCase() : '' }
    const payload = Object.fromEntries(
      Object.entries(normalised).map(([k, v]) => [k, v === '' || v === undefined ? null : v]),
    )
    const res = await api.onboarding.saveProfile(onboarding.id, payload, draft)
    dirty.current = false
    setSkipped(res?.skipped || {})

    const updatedProfile = res?.onboarding?.profile || res?.data?.onboarding?.profile || res?.profile
    if (updatedProfile?.profile_photo) {
      setF(p => ({ ...p, profile_photo: updatedProfile.profile_photo }))
    }
    return res
  }

  /**
   * Keep the half-filled form when the vendor moves to another step.
   *
   * Deliberately WITHOUT validateProfile: a half-filled form is exactly what
   * this is for, and refusing to store it because it is half-filled is the
   * behaviour being fixed.
   *
   * Sent as a DRAFT, which is the part that was missing: the profile's bank block
   * carries `required_with` both ways, so an account number typed without its
   * IFSC made the whole save a 422 — swallowed right here, so the vendor came
   * back to a step with everything else they had typed gone. On the draft path
   * the server stores every field that stands on its own and names the rest.
   */
  const saveDraft = async () => {
    if (!editable || !dirty.current) return false
    try {
      await persist(true)
      return true
    } catch {
      // Still not a reason to trap somebody on a step; the strict save reports it.
      return false
    }
  }

  // Registered once, and read through refs, so the flush the wizard calls is
  // always looking at what is on screen now.
  const draftRef = useRef(saveDraft)
  draftRef.current = saveDraft
  useEffect(() => {
    registerFlush?.(() => draftRef.current())
    return () => registerFlush?.(null)
  }, [registerFlush])

  const save = async (navigateNext = false) => {
    const e = validateProfile(f, acctConfirm)
    setErrs(e)
    setSummary(null)
    if (Object.keys(e).length > 0) {
      // This form is long enough that the offending box is usually scrolled off
      // screen. Marking it red and saying nothing else looked like the button
      // simply did nothing, so what is wrong is also said at the top.
      setSummary(summarise(e))
      return false
    }

    setSaving(true)
    try {
      await persist()
      setSaved(true)

      if (navigateNext && onContinue) {
        onContinue()
      }
      if (onSaved) onSaved()

      return true
    } catch (err) {
      // The server's headline for a 422 is always the words "Validation failed",
      // which name nothing. The per-field detail is read first and shown against
      // the boxes themselves, with a summary at the top of the form.
      const { map, list, summary: line } = readFieldErrors(err, 'profile.')
      if (list.length) {
        setErrs(map)
        setSummary(summarise(map))
      } else {
        setSummary(line)
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
      {!errs[key] && skipped[key] && (
        <div style={{ color: '#d97706', fontSize: 11, marginTop: 3 }}>Not saved yet — {skipped[key]}</div>
      )}
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

      {/* Why the save did not go through, at the top, where it is read. */}
      {summary && (
        <div role="alert" style={{ margin: '0 0 14px', padding: '10px 12px', borderRadius: 9, border: '1px solid #ef4444', background: 'rgba(239,68,68,.08)', color: '#ef4444', fontSize: 12.5, fontWeight: 600 }}>
          {summary}
          {Object.values(errs).filter(Boolean).length > 1 && (
            <ul style={{ margin: '6px 0 0', paddingLeft: 18, fontWeight: 500 }}>
              {Object.entries(errs).filter(([, v]) => v).map(([k, v]) => (
                <li key={k}>{prettyField(k)}: {v}</li>
              ))}
            </ul>
          )}
        </div>
      )}

      {/* A draft kept what stood on its own; these still need finishing. */}
      {!summary && Object.keys(skipped).length > 0 && (
        <div role="status" style={{ margin: '0 0 14px', padding: '10px 12px', borderRadius: 9, border: '1px solid #d97706', background: 'rgba(217,119,6,.08)', color: '#b45309', fontSize: 12.5, fontWeight: 600 }}>
          Your draft was saved. {Object.keys(skipped).length === 1 ? 'One field is' : `${Object.keys(skipped).length} fields are`} still unfinished and {Object.keys(skipped).length === 1 ? 'was' : 'were'} not stored:
          <ul style={{ margin: '6px 0 0', paddingLeft: 18, fontWeight: 500 }}>
            {Object.entries(skipped).map(([k, v]) => <li key={k}>{v}</li>)}
          </ul>
        </div>
      )}

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

// ── Steps 3 & 4 — Statutory documents ────────────────────────────

/**
 * Step 3 (vendor uploads) and step 4 (admin reviews) — one panel, both engines.
 *
 * This step used to be a thousand lines living here, and Purchase had its own
 * flat list somewhere else. Two implementations of the same screen is how every
 * Purchase document defect this month started, so the whole thing now lives in
 * VendorDocumentsPanel and the engine is expressed as data: an api and a
 * catalog. What TPV shows and what Purchase shows cannot drift again, because
 * there is only one of them.
 *
 * Nothing was dropped in the move — grouping, search, status filters, sorting,
 * drag-and-drop, preview, version history, delete, the rejection rationale, the
 * sample download and the provider directory all came across. Two things were
 * fixed on the way: approved and rejected rows had opaque light backgrounds
 * (#f0fdf4 / #fef2f2) under var(--text-h), which in dark mode is #edeaf8 — so
 * the rows a vendor most needed to read were the two they could not; and errors
 * were reported through alert() rather than on the page.
 */
function StepDocuments({ checklist, vendorId, onboarding, editable, manage, admin, reviewMode, onChanged, onBack, onContinue, api, user }) {
  return (
    <Panel
      title={reviewMode ? 'Document Verification Review' : 'Upload Legal Documents'}
      sub={reviewMode
        ? 'Approve or reject each submitted vendor compliance document'
        : 'Upload each required document — they are reviewed one by one'}
    >
      <VendorDocumentsPanel
        api={api.documents}
        catalog={TPV_DOC_CATALOG}
        vendorId={vendorId}
        checklist={checklist}
        onboarding={onboarding}
        user={user}
        editable={editable}
        manage={manage}
        admin={admin}
        reviewMode={reviewMode}
        onChanged={onChanged}
        footer={({ complete }) => (
          <div style={{
            marginTop: 22, paddingTop: 16, borderTop: '1px solid var(--border)',
            display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12,
          }}>
            {onBack
              ? <button type="button" onClick={onBack} style={wizardGhostBtn}><ArrowLeft size={15} /> Back</button>
              : <span />}
            <button
              type="button"
              onClick={onContinue}
              disabled={reviewMode && !complete}
              style={{
                display: 'inline-flex', alignItems: 'center', gap: 8, padding: '10px 24px', borderRadius: 10,
                border: 'none', color: '#fff', fontWeight: 800, fontSize: 13,
                background: (!reviewMode || complete) ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'rgba(124,58,237,0.35)',
                cursor: (!reviewMode || complete) ? 'pointer' : 'not-allowed',
                opacity: (!reviewMode || complete) ? 1 : 0.75,
              }}
            >
              Continue <ArrowRight size={15} />
            </button>
          </div>
        )}
      />
    </Panel>
  )
}

const wizardGhostBtn = {
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '10px 20px', borderRadius: 10,
  border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-h)',
  fontWeight: 700, fontSize: 13, cursor: 'pointer',
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
function StepSubmission({ onboarding, vendor, admin, onChanged, onBack, api, user, engagement = 'tpv', isPortal = false }) {
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

          {/* The HSSE clearance to commence work — the document, not the status.
              It used to sit below the Approved pill behind `engagement === 'tpv'`,
              whose comment claimed Purchase issued its own. Purchase issued it and
              showed it to nobody, so the gate came out and the card is shared. */}
          <WorkStartLetterCard api={api} onboardingId={onboarding.id}
            company={onboarding.vendor?.company_name} />

          {/* Start Workforce. This was TPV-only on the grounds that "purchase
              vendors have no workforce" — they do, and now have the same rail,
              so the CTA points at whichever engine the vendor belongs to. */}
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12, paddingTop: 10, borderTop: '1px dashed #6ee7b7' }}>
            <div style={{ fontSize: 13, fontWeight: 700, color: '#065f46' }}>
              Your account is active and verified. You are ready to start onboarding site workers.
            </div>
            <button
              type="button"
              onClick={() => {
                if (engagement === 'purchase') {
                  navigate(isPortal ? '/purchase-portal/workforce' : '/app/purchase/workers')
                } else if (admin && vendorId) {
                  navigate(`/app/tpv/workforce/vendor/${vendorId}/dashboard`)
                } else {
                  navigate('/vendor-portal/workforce')
                }
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
        <Overlay onClose={() => !loading && setModal(null)} width={480} showClose={false}>
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
