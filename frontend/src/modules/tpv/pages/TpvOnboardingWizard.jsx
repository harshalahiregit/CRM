import { useState, useEffect, useCallback, useRef } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, RefreshCw, Upload, RotateCcw, Eye, Trash2, CheckCircle, XCircle,
  ShieldCheck, FileText, ClipboardList, UserCheck, Rocket, Send, Loader,
  AlertTriangle, Check, CornerUpLeft, CalendarDays, Clock, MapPin, Users, ArrowRight, Plus,
} from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { kickoffApi } from '@/services/kickoffApi'
import { koStatusCfg, fmtDateTime } from '@/modules/shared/kickoffConstants'
import { useAuth } from '@/context/AuthContext'
import AuditTimeline from '@/components/ui/AuditTimeline'
import {
  DOC_STATUS, docStatusCfg, obStatusCfg, vendorStatusCfg, isOnboardingEditable,
  canApproveTpv, canManageTpv, fmtDate,
} from '../constants'
import {
  KIT3D_STYLE, labelStyle, inputStyle, Overlay, ModalFooter, InfoBox,
  Field, TextInput, StatusBadge as StatusPill,
} from '@/components/ui/kit3d'

const STEP_ICONS = { kickoff: ClipboardList, profile: UserCheck, documents: FileText, review: ShieldCheck, confirmation: Check, submission: Rocket }
const STEP_COLORS = { kickoff: '#8b5cf6', profile: '#0ea5e9', documents: '#f59e0b', review: '#a855f7', confirmation: '#14b8a6', submission: '#10b981' }

const EMPTY_PROFILE = {
  contact_person: '', designation: '', dob: '', emergency_contact: '', emergency_phone: '',
  registered_address: '', website: '', linkedin: '', estimated_workforce: '', scope_of_work: '',
}

// ── Main ─────────────────────────────────────────────────────────────────────
export default function TpvOnboardingWizard() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const admin  = canApproveTpv(user)
  const manage = canManageTpv(user)

  const [onboarding, setOnboarding] = useState(null)
  const [progress, setProgress]     = useState(null)
  const [loading, setLoading]       = useState(true)
  const [active, setActive]         = useState(1)

  const load = useCallback(async (keepStep = false) => {
    try {
      const res = await tpvApi.onboarding.get(id)
      const ob = res?.onboarding ?? res?.data?.onboarding
      const pr = res?.progress ?? res?.data?.progress
      setOnboarding(ob); setProgress(pr)
      if (!keepStep) setActive(ob?.current_step || 1)
    } catch (e) { console.error('Failed to load onboarding', e) }
    finally { setLoading(false) }
  }, [id])
  useEffect(() => { load() }, [load])

  // Refresh only the derived state (after an upload/review) without losing the step.
  const refresh = () => load(true)

  const editable = isOnboardingEditable(onboarding?.status)

  const goStep = (step) => {
    setActive(step)
    // Persist the wizard pointer when the record still accepts edits; a
    // submitted/approved onboarding is view-only so the API would reject it.
    if (editable) tpvApi.onboarding.setStep(id, step).catch(() => {})
  }

  if (loading || !onboarding || !progress) {
    return <div style={{ padding: 24, color: 'var(--text-muted)' }}>Loading onboarding…</div>
  }

  const vendor = onboarding.vendor || {}
  const steps  = progress.steps || []
  const activeStep = steps.find(s => s.step === active) || steps[0]

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>

      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 16, flexWrap: 'wrap' }}>
        <button onClick={() => navigate('/app/tpv/onboarding')} title="Back to onboardings"
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

      {/* Stepper */}
      <Stepper steps={steps} active={active} onGo={goStep} />

      {/* Step body */}
      <div style={{ marginTop: 18 }}>
        {active === 1 && <StepKickoff onboarding={onboarding} editable={editable} />}
        {active === 2 && <StepProfile onboarding={onboarding} editable={editable} onSaved={refresh} />}
        {active === 3 && <StepDocuments checklist={progress.documents} vendorId={vendor.id} editable={editable} manage={manage} admin={false} onChanged={refresh} />}
        {active === 4 && <StepDocuments checklist={progress.documents} vendorId={vendor.id} editable={editable} manage={manage} admin={admin} reviewMode onChanged={refresh} />}
        {active === 5 && <StepConfirmation onboarding={onboarding} progress={progress} editable={editable} onSubmitted={refresh} />}
        {active === 6 && <StepSubmission onboarding={onboarding} admin={admin} onChanged={refresh} />}
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

// ── Step 1 — Kickoff ─────────────────────────────────────────────────────────
// Wired to the shared KickoffMeeting engine. The meeting attaches to the vendor
// (the onboarding's kickoff_meeting_id FK is synced by the backend on schedule),
// so we look it up by the vendor subject rather than assuming the FK is set.
function StepKickoff({ onboarding, editable }) {
  const navigate = useNavigate()
  const [meeting, setMeeting] = useState(null)
  const [loading, setLoad] = useState(true)
  const [scheduling, setScheduling] = useState(false)

  const load = useCallback(() => {
    const vid = onboarding.vendor?.id
    if (!vid) { setLoad(false); return }
    setLoad(true)
    kickoffApi.list({ subject_type: 'vendor', subject_id: vid })
      .then(r => { const rows = r?.data ?? r; setMeeting(rows[0] || null); setLoad(false) })
      .catch(() => setLoad(false))
  }, [onboarding.vendor?.id])
  useEffect(() => { load() }, [load])

  const schedule = async () => {
    setScheduling(true)
    try {
      // A stub the coordinator fills in on the detail screen — scheduling from
      // the wizard shouldn't demand every field up front.
      const m = await kickoffApi.schedule({ subject_type: 'vendor', subject_id: onboarding.vendor.id })
      navigate(`/app/tpv/kickoff/${m.id}`)
    } catch (e) { alert(e?.response?.data?.message || 'Could not schedule the meeting.'); setScheduling(false) }
  }

  return (
    <Panel title="Kickoff Briefing" sub="Pre-onboarding meeting record and acknowledgement">
      {loading ? (
        <div className="skeleton" style={{ height: 90, borderRadius: 14, background: 'var(--border)' }} />
      ) : meeting ? (
        <KickoffCard meeting={meeting} onOpen={() => navigate(`/app/tpv/kickoff/${meeting.id}`)} />
      ) : (
        <div style={{ padding: '22px 20px', borderRadius: 14, textAlign: 'center', background: 'linear-gradient(150deg, rgba(124,58,237,.08), rgba(124,58,237,.02))', border: '1.5px dashed rgba(124,58,237,.4)' }}>
          <div style={{ width: 48, height: 48, borderRadius: '50%', margin: '0 auto 12px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.14)' }}>
            <CalendarDays size={22} style={{ color: '#a78bfa' }} />
          </div>
          <div style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>No kickoff meeting yet</div>
          <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '5px 0 14px' }}>
            Schedule a pre-onboarding meeting with {onboarding.vendor?.company_name || 'the vendor'}.
          </p>
          {editable && (
            <button onClick={schedule} disabled={scheduling}
              style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 700, color: '#fff', border: 'none', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)' }}>
              {scheduling ? <Loader size={15} /> : <Plus size={15} />} Schedule kickoff
            </button>
          )}
        </div>
      )}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px 20px', marginTop: 16 }}>
        {[['Vendor', onboarding.vendor?.company_name], ['Vendor Code', onboarding.vendor?.vendor_code],
          ['Started', fmtDate(onboarding.created_at)],
          ['Kickoff Meeting', meeting ? koStatusCfg(meeting.status).label : 'Not linked']]
          .map(([k, v]) => (
            <div key={k} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, borderBottom: '1px solid var(--border)', paddingBottom: 6 }}>
              <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>{k}</span>
              <span style={{ color: 'var(--text-h)', fontSize: 12, fontWeight: 600 }}>{v || '—'}</span>
            </div>
          ))}
      </div>
    </Panel>
  )
}

function KickoffCard({ meeting, onOpen }) {
  const cfg = koStatusCfg(meeting.status)
  return (
    <div onClick={onOpen} className="pr-node" style={{ cursor: 'pointer', padding: 16, borderRadius: 14, display: 'flex', alignItems: 'center', gap: 14,
      background: `linear-gradient(150deg, ${cfg.color}18, ${cfg.color}06)`, border: `1.5px solid ${cfg.color}44` }}>
      <div style={{ width: 44, height: 44, borderRadius: 13, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `${cfg.color}22` }}>
        <CalendarDays size={20} style={{ color: cfg.color }} />
      </div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <span style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>{meeting.title}</span>
          {meeting.is_acknowledged && <span title="Acknowledged" style={{ display: 'inline-flex', alignItems: 'center', gap: 3, fontSize: 10.5, fontWeight: 700, color: '#10b981' }}><CheckCircle size={11} /> Acknowledged</span>}
        </div>
        <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap', fontSize: 11.5, color: 'var(--text-muted)', marginTop: 3 }}>
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><Clock size={12} /> {fmtDateTime(meeting.scheduled_at)}</span>
          {meeting.location && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><MapPin size={12} /> {meeting.location}</span>}
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><Users size={12} /> {meeting.attendees_count ?? 0}</span>
        </div>
      </div>
      <span style={{ padding: '4px 11px', borderRadius: 999, background: cfg.bg, color: cfg.color, fontSize: 11.5, fontWeight: 800, flexShrink: 0 }}>{cfg.label}</span>
      <ArrowRight size={16} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
    </div>
  )
}

// ── Step 2 — Profile form ────────────────────────────────────────────────────
function StepProfile({ onboarding, editable, onSaved }) {
  const [f, setF] = useState({ ...EMPTY_PROFILE, ...(onboarding.profile || {}) })
  const [saving, setSaving] = useState(false)
  const [saved, setSaved]   = useState(false)
  const set = (k) => (e) => { setF(p => ({ ...p, [k]: e.target.value })); setSaved(false) }

  const save = async () => {
    setSaving(true)
    try {
      // Drop empties so the stored profile stays clean.
      const payload = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== null))
      await tpvApi.onboarding.saveProfile(onboarding.id, payload)
      setSaved(true); onSaved()
    } catch (e) { alert(e?.response?.data?.message || 'Failed to save profile') }
    finally { setSaving(false) }
  }

  return (
    <Panel title="Company Profile" sub="Contact, address and engagement details for this vendor"
      actions={editable && (
        <button onClick={save} disabled={saving}
          style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 16px', borderRadius: 9, border: 'none', background: saved ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontWeight: 700, fontSize: 12.5, cursor: 'pointer', opacity: saving ? 0.7 : 1 }}>
          {saving ? <Loader size={13} /> : saved ? <Check size={13} /> : null} {saving ? 'Saving…' : saved ? 'Saved' : 'Save Profile'}
        </button>
      )}>
      {!editable && <InfoBox>This onboarding is no longer editable — the profile is shown read-only.</InfoBox>}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 14 }}>
        <Field label="Contact Person"><TextInput value={f.contact_person} onChange={set('contact_person')} disabled={!editable} placeholder="e.g. Ravi Menon" /></Field>
        <Field label="Designation"><TextInput value={f.designation} onChange={set('designation')} disabled={!editable} placeholder="e.g. Director" /></Field>
        <Field label="Date of Birth"><TextInput type="date" value={f.dob} onChange={set('dob')} disabled={!editable} /></Field>
        <Field label="Emergency Contact"><TextInput value={f.emergency_contact} onChange={set('emergency_contact')} disabled={!editable} placeholder="Name" /></Field>
        <Field label="Emergency Phone"><TextInput value={f.emergency_phone} onChange={set('emergency_phone')} disabled={!editable} placeholder="Phone" /></Field>
        <Field label="Estimated Workforce"><TextInput type="number" min="0" value={f.estimated_workforce} onChange={set('estimated_workforce')} disabled={!editable} placeholder="e.g. 25" /></Field>
        <Field label="Registered Address" full><textarea value={f.registered_address} onChange={set('registered_address')} disabled={!editable} rows={2} placeholder="Full registered address" style={{ ...inputStyle, resize: 'vertical' }} /></Field>
        <Field label="Website"><TextInput value={f.website} onChange={set('website')} disabled={!editable} placeholder="https://" /></Field>
        <Field label="LinkedIn"><TextInput value={f.linkedin} onChange={set('linkedin')} disabled={!editable} placeholder="Profile URL" /></Field>
        <Field label="Scope of Work" full><textarea value={f.scope_of_work} onChange={set('scope_of_work')} disabled={!editable} rows={2} placeholder="What work will this vendor perform on site?" style={{ ...inputStyle, resize: 'vertical' }} /></Field>
      </div>
    </Panel>
  )
}

// ── Steps 3 & 4 — Document checklist grid ────────────────────────────────────
function StepDocuments({ checklist, vendorId, editable, manage, admin, reviewMode, onChanged }) {
  const [busy, setBusy]       = useState(null)   // type currently uploading
  const [reviewing, setRev]   = useState(null)   // { row, decision }
  const inputs = useRef({})

  const s = checklist?.summary || {}
  const rows = checklist?.required || []

  const pickFile = (type) => inputs.current[type]?.click()

  const onFile = async (row, file) => {
    if (!file) return
    setBusy(row.type)
    try {
      // A rejected document is replaced via resubmit (keeps its identity);
      // anything else is a fresh upload for that type.
      if (row.document_id && row.status === DOC_STATUS.REJECTED) {
        await tpvApi.documents.resubmit(row.document_id, file)
      } else {
        await tpvApi.documents.upload(vendorId, row.type, file)
      }
      onChanged()
    } catch (e) { alert(e?.response?.data?.message || 'Upload failed') }
    finally { setBusy(null) }
  }

  const view = async (row) => {
    try { window.open(await tpvApi.documents.open(row.document_id), '_blank') }
    catch { alert('Could not open the document.') }
  }

  const del = async (row) => {
    if (!confirm(`Remove the uploaded ${row.type_label}?`)) return
    try { await tpvApi.documents.delete(row.document_id); onChanged() }
    catch (e) { alert(e?.response?.data?.message || 'Delete failed') }
  }

  const runReview = async (remarks) => {
    const { row, decision } = reviewing
    try {
      await tpvApi.documents.review(row.document_id, decision, remarks)
      setRev(null); onChanged()
    } catch (e) { alert(e?.response?.data?.message || 'Review failed') }
  }

  const th = { textAlign: 'left', padding: '9px 12px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
  const td = { padding: '10px 12px', borderBottom: '1px solid var(--border)', fontSize: 12.5, verticalAlign: 'middle' }

  return (
    <Panel
      title={reviewMode ? 'Document Review' : 'Statutory Documents'}
      sub={reviewMode ? 'Approve or reject each submitted document' : `Upload each required document · ${s.uploaded || 0}/${s.required || 0} uploaded`}
      actions={<DocSummary s={s} complete={checklist?.complete} />}
    >
      {reviewMode && !admin && <InfoBox>Only an admin can approve or reject documents. You can see live review status here.</InfoBox>}
      {!reviewMode && !editable && <InfoBox>This onboarding is locked — documents can no longer be changed.</InfoBox>}

      <div className="pr-glass" style={{ padding: 0, borderRadius: 12, overflow: 'hidden' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>
            <th style={th}>Document</th><th style={th}>Status</th><th style={th}>File</th>
            {reviewMode && <th style={th}>Remarks</th>}
            <th style={{ ...th, textAlign: 'right' }}>Actions</th>
          </tr></thead>
          <tbody>
            {rows.map(row => {
              const cfg = docStatusCfg(row.status)
              const isBusy = busy === row.type
              const approved = row.status === DOC_STATUS.APPROVED
              const rejected = row.status === DOC_STATUS.REJECTED
              return (
                <tr key={row.type} className="pr-li-row">
                  <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)' }}>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                      <span style={{ width: 7, height: 7, borderRadius: '50%', background: cfg.color, flexShrink: 0 }} />
                      {row.type_label}
                    </span>
                    {/* On the upload step the reviewer's reason must sit next to the
                        Resubmit button — otherwise the vendor can't know what to fix.
                        (Review mode has its own Remarks column.) */}
                    {!reviewMode && rejected && row.remarks && (
                      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 5, marginTop: 5, fontSize: 11, fontWeight: 500, color: '#ef4444', maxWidth: 320 }}>
                        <AlertTriangle size={11} style={{ flexShrink: 0, marginTop: 1 }} />
                        <span>{row.remarks}</span>
                      </div>
                    )}
                  </td>
                  <td style={td}>
                    {isBusy
                      ? <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 11.5, fontWeight: 700, color: '#a78bfa' }}><Loader size={11} /> Uploading…</span>
                      : <StatusPill cfg={cfg} />}
                  </td>
                  <td style={{ ...td, color: 'var(--text-muted)', maxWidth: 200, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                    {row.original_name || <span style={{ opacity: 0.6 }}>—</span>}
                  </td>
                  {reviewMode && (
                    <td style={{ ...td, color: rejected ? '#ef4444' : 'var(--text-muted)', maxWidth: 220 }}>
                      {row.remarks || <span style={{ opacity: 0.5 }}>—</span>}
                    </td>
                  )}
                  <td style={{ ...td, textAlign: 'right', whiteSpace: 'nowrap' }}>
                    <input type="file" accept=".pdf,.jpg,.jpeg,.png" style={{ display: 'none' }}
                      ref={el => { inputs.current[row.type] = el }}
                      onChange={e => { onFile(row, e.target.files?.[0]); e.target.value = '' }} />
                    <div style={{ display: 'inline-flex', gap: 6, justifyContent: 'flex-end' }}>
                      {/* Admin review actions (step 4) */}
                      {reviewMode && admin && row.uploaded && !approved && (
                        <>
                          <MiniBtn onClick={() => setRev({ row, decision: 'approve' })} color="#10b981" icon={CheckCircle}>Approve</MiniBtn>
                          <MiniBtn onClick={() => setRev({ row, decision: 'reject' })} color="#ef4444" icon={XCircle}>Reject</MiniBtn>
                        </>
                      )}
                      {/* Upload / resubmit (step 3) */}
                      {!reviewMode && manage && editable && !approved && (
                        <MiniBtn onClick={() => pickFile(row.type)} color={rejected ? '#f59e0b' : '#a78bfa'} icon={rejected ? RotateCcw : Upload} disabled={isBusy}>
                          {rejected ? 'Resubmit' : row.uploaded ? 'Replace' : 'Upload'}
                        </MiniBtn>
                      )}
                      {row.uploaded && <MiniBtn onClick={() => view(row)} color="var(--text-muted)" icon={Eye} border>View</MiniBtn>}
                      {!reviewMode && manage && editable && row.uploaded && !approved && (
                        <MiniBtn onClick={() => del(row)} color="#f87171" icon={Trash2} border />
                      )}
                    </div>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      {checklist?.extras?.length > 0 && (
        <p style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 10 }}>
          {checklist.extras.length} additional document{checklist.extras.length === 1 ? '' : 's'} uploaded outside the required set.
        </p>
      )}

      {reviewing && (
        <ReviewModal reviewing={reviewing} onClose={() => setRev(null)} onConfirm={runReview} />
      )}
    </Panel>
  )
}

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
function StepConfirmation({ onboarding, progress, editable, onSubmitted }) {
  const [submitting, setSubmitting] = useState(false)
  const steps = progress.steps || []
  const blockers = steps.filter(s => [2, 3, 5].includes(s.step) && !s.complete)
  const canSubmit = editable && blockers.length === 0

  const submit = async () => {
    setSubmitting(true)
    try { await tpvApi.onboarding.submit(onboarding.id); onSubmitted() }
    catch (e) { alert(e?.response?.data?.message || 'Submit failed') }
    finally { setSubmitting(false) }
  }

  return (
    <Panel title="Final Confirmation" sub="Review everything, then submit for admin approval">
      <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginBottom: 16 }}>
        {steps.slice(0, 5).map(s => (
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
        ? <InfoBox tone="danger">Resolve before submitting: {blockers.map(b => b.label).join(', ')}.</InfoBox>
        : <InfoBox>All requirements met. Submitting locks the record and sends it for admin approval.</InfoBox>}

      {!editable && <InfoBox>Already submitted — see the Admin Approval step.</InfoBox>}

      {editable && (
        <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 6 }}>
          <button onClick={submit} disabled={!canSubmit || submitting}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '10px 22px', borderRadius: 10, border: 'none',
              background: canSubmit ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'rgba(124,58,237,0.35)',
              color: '#fff', fontWeight: 800, fontSize: 13, cursor: canSubmit ? 'pointer' : 'not-allowed', opacity: submitting ? 0.7 : 1,
              boxShadow: canSubmit ? '0 10px 24px -8px rgba(124,58,237,.7)' : 'none' }}>
            <Send size={14} /> {submitting ? 'Submitting…' : 'Submit for Approval'}
          </button>
        </div>
      )}
    </Panel>
  )
}

// ── Step 6 — Admin approval panel ────────────────────────────────────────────
function StepSubmission({ onboarding, admin, onChanged }) {
  const [modal, setModal] = useState(null)  // 'approve' | 'resubmit'
  const [remarks, setRemarks] = useState('')
  const [loading, setLoading] = useState(false)
  const awaiting = ['Submitted', 'Under_Review'].includes(onboarding.status)
  const approved = onboarding.status === 'Approved'

  const run = async () => {
    setLoading(true)
    try {
      if (modal === 'approve') await tpvApi.onboarding.approve(onboarding.id, remarks)
      else await tpvApi.onboarding.requestResubmit(onboarding.id, remarks)
      setModal(null); setRemarks(''); onChanged()
    } catch (e) { alert(e?.response?.data?.message || 'Action failed') }
    finally { setLoading(false) }
  }

  return (
    <Panel title="Admin Approval" sub="Final gate — approving activates the vendor for site access">
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '16px 18px', borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)', marginBottom: 14 }}>
        <div style={{ width: 44, height: 44, borderRadius: 14, display: 'flex', alignItems: 'center', justifyContent: 'center', background: approved ? 'linear-gradient(145deg,#34d399,#10b981)' : awaiting ? 'linear-gradient(145deg,#fbbf24,#f59e0b)' : 'linear-gradient(145deg,#94a3b8,#64748b)', boxShadow: '0 8px 20px -6px rgba(0,0,0,.4)' }}>
          {approved ? <CheckCircle size={20} color="#fff" /> : <Rocket size={20} color="#fff" />}
        </div>
        <div>
          <div style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>{obStatusCfg(onboarding.status).label}</div>
          <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>
            {approved ? `Approved ${fmtDate(onboarding.approved_at)}${onboarding.approver?.name ? ` by ${onboarding.approver.name}` : ''}`
              : awaiting ? `Submitted ${fmtDate(onboarding.submitted_at)} — awaiting admin decision`
              : 'Not yet submitted'}
          </div>
        </div>
      </div>

      {approved && (
        <InfoBox>
          Vendor is <strong>Active</strong> for site access. The branded HSSE Work-Start Letter PDF is not generated
          yet — the field is reserved on the record.
        </InfoBox>
      )}
      {onboarding.remarks && <InfoBox tone={onboarding.status === 'Resubmit_Required' ? 'danger' : undefined}><strong>Remarks:</strong> {onboarding.remarks}</InfoBox>}

      {admin && awaiting && (
        <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
          <button onClick={() => { setModal('resubmit'); setRemarks('') }}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: '#f59e0b', fontWeight: 700, fontSize: 12.5, cursor: 'pointer' }}>
            <CornerUpLeft size={13} /> Send Back
          </button>
          <button onClick={() => { setModal('approve'); setRemarks('') }}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 18px', borderRadius: 9, border: 'none', background: 'linear-gradient(135deg,#10b981,#059669)', color: '#fff', fontWeight: 800, fontSize: 12.5, cursor: 'pointer', boxShadow: '0 8px 20px -6px rgba(16,185,129,.6)' }}>
            <ShieldCheck size={14} /> Approve &amp; Activate
          </button>
        </div>
      )}
      {!admin && awaiting && <InfoBox>Awaiting an admin decision — only an admin can approve or send this back.</InfoBox>}

      {modal && (
        <Overlay onClose={() => !loading && setModal(null)} width={460}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14 }}>
            {modal === 'approve' ? <CheckCircle size={22} color="#10b981" /> : <CornerUpLeft size={22} color="#f59e0b" />}
            <h3 style={{ color: 'var(--text-h)', margin: 0, fontSize: 16, fontWeight: 800 }}>{modal === 'approve' ? 'Approve & Activate Vendor' : 'Send Back for Revision'}</h3>
          </div>
          {modal === 'approve'
            ? <InfoBox>This sets the vendor <strong>Active</strong> and permits site access.</InfoBox>
            : <InfoBox tone="danger">The vendor will be able to edit and resubmit their onboarding.</InfoBox>}
          <label style={labelStyle}>{modal === 'approve' ? 'Remarks (optional)' : 'Reason to send back *'}</label>
          <textarea value={remarks} onChange={e => setRemarks(e.target.value)} rows={3}
            placeholder={modal === 'approve' ? 'e.g. HSSE cleared' : 'What needs to be corrected?'}
            style={{ ...inputStyle, resize: 'vertical', borderColor: modal === 'resubmit' && !remarks ? '#ef444480' : 'var(--border)' }} />
          <ModalFooter onClose={() => setModal(null)} onConfirm={run} loading={loading}
            disabled={modal === 'resubmit' && !remarks}
            confirmLabel={modal === 'approve' ? 'Approve & Activate' : 'Send Back'}
            color={modal === 'approve' ? '#10b981' : '#f59e0b'} />
        </Overlay>
      )}
    </Panel>
  )
}
