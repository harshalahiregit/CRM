import { useState, useEffect, useCallback, useMemo, useRef } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, RefreshCw, UserCheck, HeartPulse, GraduationCap, HardHat, QrCode,
  Check, AlertTriangle, ShieldCheck, Loader,
} from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { useAuth } from '@/context/AuthContext'
import AuditTimeline from '@/components/ui/AuditTimeline'
import { canApprovePR, canManagePR, fmtDate } from '../constants'
import {
  KIT3D_STYLE, labelStyle, inputStyle, Overlay, ModalFooter, InfoBox,
  Field, TextInput, SelectInput, StatusBadge as StatusPill,
} from '@/components/ui/kit3d'

const STEP_ICONS  = { profile: UserCheck, medical: HeartPulse, induction: GraduationCap, ppe: HardHat, badge: QrCode }
const STEP_COLORS = { profile: '#0ea5e9', medical: '#ec4899', induction: '#8b5cf6', ppe: '#f59e0b', badge: '#10b981' }

// ── Purchase workforce enums ─────────────────────────────────────────────────
// Kept local to this screen rather than in ../constants.js: these mirror the
// strings PurchaseWorkforceService writes, and nothing else in the module reads
// them yet. 'Pending' is Purchase's draft — the worker is registered but not
// badged, and it is the only state the profile stays editable in.
const WORKER_STATUS = {
  PENDING:    'Pending',
  ACTIVE:     'Active',
  SUSPENDED:  'Suspended',
  TERMINATED: 'Terminated',
  INACTIVE:   'Inactive',
}
const WORKER_STATUS_CONFIG = {
  [WORKER_STATUS.PENDING]:    { label: 'Pending',    color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  [WORKER_STATUS.ACTIVE]:     { label: 'Active',     color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  [WORKER_STATUS.SUSPENDED]:  { label: 'Suspended',  color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  [WORKER_STATUS.TERMINATED]: { label: 'Terminated', color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
  [WORKER_STATUS.INACTIVE]:   { label: 'Inactive',   color: '#64748b', bg: 'rgba(100,116,139,0.15)' },
}
const workerStatusCfg = (s) => WORKER_STATUS_CONFIG[s] || WORKER_STATUS_CONFIG[WORKER_STATUS.PENDING]
// Once a badge has been issued the profile is the site's record, not a draft.
const isWorkerEditable = (s) => s === WORKER_STATUS.PENDING || s === WORKER_STATUS.INACTIVE

// App\Support\Purchase\PurchaseMedicalFitness — Fit and Fit-with-restrictions
// both PASS the badge gate; Unfit is a hard stop.
const FITNESS = [
  ['Fit', 'Fit'],
  ['Fit_With_Restrictions', 'Fit with Restrictions'],
  ['Unfit', 'Unfit'],
]
const GENDERS = ['Male', 'Female', 'Other', 'Prefer not to say']
const ID_PROOF_TYPES = ['Aadhaar', 'PAN', 'Voter ID', 'Driving Licence', 'Passport', 'Other']

// ── Main ─────────────────────────────────────────────────────────────────────
export default function PurchaseWorkerWizard() {
  const { id: routeId } = useParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const admin  = canApprovePR(user)   // activation is role:admin server-side
  const manage = canManagePR(user)
  const backHref = '/app/purchase/workers'

  // `workers/new` opens the wizard with nothing registered yet — Step 1 creates
  // the worker, and every later step addresses it by the id the API hands back.
  const [workerId, setWorkerId] = useState(routeId && routeId !== 'new' ? routeId : null)
  const [worker, setWorker]       = useState(null)
  const [readiness, setReadiness] = useState(null)
  const [badge, setBadge]         = useState(null)
  const [loading, setLoading]     = useState(!!(routeId && routeId !== 'new'))
  const [active, setActive]       = useState(1)

  // A reload must never yank the user off the step they just finished, so a
  // pinned step wins over the server's resume point for exactly one load.
  const pinnedStep = useRef(null)

  const load = useCallback(async (keepStep = false) => {
    if (!workerId) { setLoading(false); return }
    try {
      const res = await purchaseApi.workforce.worker(workerId)
      const w = res?.worker ?? res?.data?.worker
      setWorker(w)
      setReadiness(res?.readiness ?? res?.data?.readiness ?? null)
      setBadge(res?.badge ?? res?.data?.badge ?? null)
      if (pinnedStep.current != null) { setActive(pinnedStep.current); pinnedStep.current = null }
      // current_step is the HIGHEST step CLEARED (see PurchaseWorkforceService),
      // so resuming on it lands the user on the last thing they completed — the
      // same convention the review screen uses.
      else if (!keepStep) setActive(Math.min(Math.max(Number(w?.current_step || 1), 1), 5))
    } catch (e) { console.error('Failed to load worker', e) }
    finally { setLoading(false) }
  }, [workerId])
  useEffect(() => { load() }, [load])
  const refresh = () => load(true)

  // Step 1 registers the worker; everything after it addresses a real id.
  const onCreated = (created) => { pinnedStep.current = 2; setWorkerId(String(created.id)) }

  const progress = useMemo(() => buildProgress(worker, readiness), [worker, readiness])

  if (loading) {
    return <div style={{ padding: 24, color: 'var(--text-muted)' }}>Loading worker…</div>
  }

  // Registration mode — no worker exists yet, so only Step 1 can do anything.
  if (!workerId) {
    return (
      <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
        <style>{KIT3D_STYLE}</style>
        <Header title="Register Worker" sub="Step 1 creates the worker against its vendor; the remaining steps unlock straight after."
          onBack={() => navigate(backHref)} />
        <Stepper steps={progress.steps} active={1} onGo={() => {}} />
        <div style={{ marginTop: 18 }}>
          <StepProfile worker={null} editable onCreated={onCreated} onSaved={refresh} onNext={() => setActive(2)} />
        </div>
      </div>
    )
  }

  if (!worker) {
    return <div style={{ padding: 24, color: 'var(--text-muted)' }}>Worker not found.</div>
  }

  const editable = isWorkerEditable(worker.status)
  const steps = progress.steps

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>

      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 16, flexWrap: 'wrap' }}>
        <button onClick={() => navigate(backHref)}
          style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 12px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12.5 }}>
          <ArrowLeft size={14} /> Back
        </button>
        <div>
          <h1 style={{ color: 'var(--text-h)', fontSize: 20, fontWeight: 800, margin: 0 }}>
            {worker.full_name} <span style={{ color: '#a78bfa', fontSize: 13, fontWeight: 700 }}>{worker.worker_code}</span>
          </h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '3px 0 0' }}>
            {worker.designation || 'Worker'}
            {ageOf(worker.dob) != null && ` · ${ageOf(worker.dob)} yrs`}
            {worker.vendor?.company_name && ` · ${worker.vendor.company_name}`}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 12, marginLeft: 'auto', alignItems: 'center' }}>
          {/* Labelled so the pill can never be read as the vendor's status. */}
          <LabelledPill label="Worker" cfg={workerStatusCfg(worker.status)} />
          <button onClick={refresh} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 12px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12.5 }}>
            <RefreshCw size={13} /> Refresh
          </button>
        </div>
      </div>

      {/* Live gate blockers — the same list activateBadge() refuses on */}
      {progress.blockers.length > 0 && worker.status === WORKER_STATUS.PENDING && (
        <div className="pr-glass" style={{ padding: '12px 16px', marginBottom: 14, border: '1px solid rgba(245,158,11,0.35)' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 7, marginBottom: 6 }}>
            <AlertTriangle size={14} style={{ color: '#f59e0b' }} />
            <span style={{ fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)' }}>
              {progress.blockers.length} item{progress.blockers.length === 1 ? '' : 's'} blocking the entry badge
            </span>
          </div>
          <ul style={{ margin: 0, paddingLeft: 28, color: '#f59e0b', fontSize: 12, lineHeight: 1.7 }}>
            {progress.blockers.map((b, i) => <li key={i}>{b}</li>)}
          </ul>
        </div>
      )}
      {worker.status === WORKER_STATUS.TERMINATED && worker.notes && (
        <InfoBox tone="danger"><strong>Terminated:</strong> {lastNoteLine(worker.notes)}</InfoBox>
      )}

      <Stepper steps={steps} active={active} onGo={setActive} />

      <div style={{ marginTop: 18 }}>
        {active === 1 && <StepProfile worker={worker} editable={editable} onSaved={refresh} onNext={() => setActive(2)} />}
        {active === 2 && <Step2Medical worker={worker} editable={editable} onSaved={refresh} onNext={() => setActive(3)} />}
        {active === 3 && <StepInduction worker={worker} readiness={readiness} editable={editable} onSaved={refresh} onNext={() => setActive(4)} />}
        {active === 4 && <StepPpe worker={worker} manage={manage} onChanged={refresh} onNext={() => setActive(5)} />}
        {active === 5 && <StepBadge worker={worker} badge={badge} progress={progress} admin={admin} onChanged={refresh} />}
      </div>

      <div className="pr-glass" style={{ padding: 20, marginTop: 16 }}>
        <label style={labelStyle}>Audit Trail</label>
        {worker.audit_logs === undefined
          ? <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>Timeline loads with the record.</p>
          : <AuditTimeline entries={worker.audit_logs} />}
      </div>
    </div>
  )
}

/**
 * The stepper + blocker list, derived from what the API actually serves.
 *
 * Purchase hands the client `readiness` — the five gate flags — rather than a
 * ready-made progress payload, so the shape the stepper wants is built here from
 * readiness plus the worker's persisted current_step. Every blocker line below
 * is one the activateBadge() endpoint refuses on, in the same order, so this
 * screen and the badge gate can never disagree about what is outstanding.
 */
function buildProgress(worker, readiness) {
  const r = readiness || {}
  const step = Number(worker?.current_step || 0)
  const trained = !!r.training_ok && !!r.induction_ok

  const steps = [
    { key: 'profile',   step: 1, label: 'Profile',   complete: !!worker,
      detail: worker ? (r.documents_ok ? 'Documents on file' : 'No documents yet') : 'Not registered' },
    { key: 'medical',   step: 2, label: 'Medical',   complete: !!r.medical_ok,
      detail: r.medical_ok ? 'Fitness current' : 'Not cleared' },
    { key: 'induction', step: 3, label: 'Induction', complete: trained,
      detail: trained ? 'Training & induction done' : r.induction_ok ? 'Training outstanding' : 'Not completed' },
    { key: 'ppe',       step: 4, label: 'PPE',       complete: step >= 4,
      detail: step >= 4 ? 'Kit issued' : 'Not issued' },
    { key: 'badge',     step: 5, label: 'Badge',     complete: !!worker?.badge_number,
      detail: worker?.badge_number || 'Not issued' },
  ]

  const blockers = []
  if (worker) {
    if (!r.documents_ok)  blockers.push('No documents are on file for this worker.')
    if (!r.medical_ok)    blockers.push('No current medical fitness certificate on record.')
    if (!r.training_ok)   blockers.push('No completed, unexpired training on record.')
    if (!r.induction_ok)  blockers.push('Site induction has not been completed.')
    if (!r.competency_ok) {
      const missing = (r.missing_competencies || []).join(', ')
      blockers.push(missing ? `Required competencies missing: ${missing}.` : 'Required competencies are missing.')
    }
    if (step < 4)         blockers.push('PPE must be issued before a badge can be activated.')
  }

  return { steps, blockers, readiness: r }
}

// Age is not a column on purchase_workers — it is derived from the date of birth
// the same way the TPV worker accessor does, so both modules read the same.
const ageOf = (dob) => (dob ? Math.floor((Date.now() - new Date(dob)) / 31557600000) : null)

// suspend/terminate append a dated line to `notes`; the newest is the reason.
const lastNoteLine = (notes) => String(notes || '').trim().split('\n').filter(Boolean).slice(-1)[0] || ''

// ── Stepper ──────────────────────────────────────────────────────────────────
function Stepper({ steps, active, onGo }) {
  return (
    <div className="pr-glass" style={{ padding: 16, overflowX: 'auto' }}>
      <div style={{ display: 'flex', alignItems: 'center', width: 'max-content', minWidth: '100%', gap: 0 }}>
        {steps.map((s, i) => {
          const Icon = STEP_ICONS[s.key] || UserCheck
          const color = STEP_COLORS[s.key] || '#7C3AED'
          const isActive = s.step === active
          const lit = s.complete || isActive
          return (
            <div key={s.key} style={{ display: 'flex', alignItems: 'center', flex: 1, minWidth: 150 }}>
              <button type="button" onClick={() => onGo(s.step)} title={s.detail}
                className="pr-node" style={{
                  flex: 1, display: 'flex', alignItems: 'center', gap: 10, padding: '11px 14px', borderRadius: 15, cursor: 'pointer',
                  background: lit ? `linear-gradient(135deg, ${color}26, ${color}0f)` : 'var(--bg-input)',
                  border: `1.5px solid ${isActive ? color : s.complete ? color + '55' : 'var(--border)'}`,
                  opacity: lit ? 1 : 0.6,
                  boxShadow: isActive ? `0 10px 26px -8px ${color}88, inset 0 1px 0 rgba(255,255,255,.14)` : 'inset 0 1px 0 var(--card-shine)',
                }}>
                <span style={{ position: 'relative', width: 36, height: 36, borderRadius: 12, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `linear-gradient(145deg, ${color}, ${color}aa)`, color: '#fff', boxShadow: lit ? `0 6px 14px -3px ${color}99, inset 0 1px 0 rgba(255,255,255,.4)` : 'none', flexShrink: 0 }}>
                  <Icon size={16} />
                  {s.complete && (
                    <span style={{ position: 'absolute', right: -4, bottom: -4, width: 15, height: 15, borderRadius: '50%', background: '#10b981', border: '2px solid var(--bg-card)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                      <Check size={8} color="#fff" strokeWidth={4} />
                    </span>
                  )}
                </span>
                <span style={{ display: 'flex', flexDirection: 'column', lineHeight: 1.2, textAlign: 'left', minWidth: 0 }}>
                  <span style={{ fontSize: 8.5, fontWeight: 800, letterSpacing: '0.06em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>Step {s.step}</span>
                  <span style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', whiteSpace: 'nowrap' }}>{s.label}</span>
                  <span style={{ fontSize: 9.5, fontWeight: 700, color, whiteSpace: 'nowrap' }}>{s.detail}</span>
                </span>
              </button>
              {i < steps.length - 1 && (
                <div className={`pr-flow${s.complete ? '' : ' pr-flow-dim'}`} style={{ width: 22, height: 3, borderRadius: 4, margin: '0 4px', flexShrink: 0, background: `linear-gradient(90deg, ${color}, ${STEP_COLORS[steps[i + 1].key] || '#7C3AED'})` }} />
              )}
            </div>
          )
        })}
      </div>
    </div>
  )
}

/** Registration-mode header — there is no worker to name yet. */
const Header = ({ title, sub, onBack }) => (
  <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 16, flexWrap: 'wrap' }}>
    <button onClick={onBack}
      style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 12px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12.5 }}>
      <ArrowLeft size={14} /> Back
    </button>
    <div>
      <h1 style={{ color: 'var(--text-h)', fontSize: 20, fontWeight: 800, margin: 0 }}>{title}</h1>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '3px 0 0' }}>{sub}</p>
    </div>
  </div>
)

/** A status pill with its subject named, so stacked pills can't be confused. */
const LabelledPill = ({ label, cfg }) => (
  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}>
    <span style={{ fontSize: 9.5, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.06em', color: 'var(--text-muted)' }}>{label}</span>
    <StatusPill cfg={cfg} />
  </span>
)

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

const SaveBtn = ({ onClick, saving, saved, label = 'Save' }) => (
  <button onClick={onClick} disabled={saving}
    style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 16px', borderRadius: 9, border: 'none', background: saved ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontWeight: 700, fontSize: 12.5, cursor: 'pointer', opacity: saving ? 0.7 : 1 }}>
    {saving ? <Loader size={13} /> : saved ? <Check size={13} /> : null} {saving ? 'Saving…' : saved ? 'Saved' : label}
  </button>
)

/** Flatten a Laravel validation bag so every rejected field is shown at once. */
const apiError = (e, fallback) => {
  const errObj = e?.response?.data?.errors
  return errObj ? Object.values(errObj).flat().join('\n') : (e?.response?.data?.message || fallback)
}

// ── Step 1 — Profile ─────────────────────────────────────────────────────────
function StepProfile({ worker, editable, onCreated, onSaved, onNext }) {
  const creating = !worker
  const [f, setF] = useState({
    vendor_id: worker?.purchase_vendor_id ? String(worker.purchase_vendor_id) : '',
    full_name: worker?.full_name || '', dob: worker?.dob?.slice(0, 10) || '', gender: worker?.gender || '',
    designation: worker?.designation || '', phone: worker?.phone || '', email: worker?.email || '',
    id_proof_type: worker?.id_proof_type || '', id_proof_number: worker?.id_proof_number || '',
    address: worker?.address || '', city: worker?.city || '', state: worker?.state || '',
    pincode: worker?.pincode || '', notes: worker?.notes || '',
  })
  const [saving, setSaving] = useState(false)
  const [saved, setSaved]   = useState(false)
  const set = (k) => (e) => { setF(p => ({ ...p, [k]: e.target.value })); setSaved(false) }

  // The vendor the worker is supplied by. Only needed while registering — the FK
  // is fixed at creation and the API refuses to move a worker between vendors.
  const [vendors, setVendors] = useState([])
  useEffect(() => {
    if (!creating) return
    purchaseApi.vendors.list({ per_page: 200 })
      .then(d => setVendors(d?.data ?? d ?? []))
      .catch(() => setVendors([]))
  }, [creating])

  // Site work has a statutory floor; surface it before the profile is saved.
  const age = ageOf(f.dob)
  const underage = age !== null && age < 18
  // Only an Aadhaar number has the fixed 12-digit shape worth checking.
  const aadhaarish = f.id_proof_type === 'Aadhaar'
  const badAadhaar = aadhaarish && f.id_proof_number && !/^\d{12}$/.test(f.id_proof_number)

  const save = async () => {
    if (creating && !f.vendor_id) { alert('Select the vendor this worker is supplied by.'); return }
    if (!f.full_name.trim())      { alert('Full Name is required.'); return }

    setSaving(true)
    try {
      // '' means "not answered", and the API validates types rather than empty
      // strings — send null so a cleared field actually clears.
      const payload = Object.fromEntries(Object.entries(f).map(([k, v]) => [k, v === '' ? null : v]))
      if (creating) {
        payload.vendor_id = Number(f.vendor_id)
        const created = await purchaseApi.workforce.createWorker(payload)
        setSaved(true)
        onCreated(created?.worker ?? created)
        return
      }
      delete payload.vendor_id   // create-only; update() rejects nothing but never reads it
      await purchaseApi.workforce.updateWorker(worker.id, payload)
      setSaved(true); onSaved()
    } catch (e) {
      alert(apiError(e, creating ? 'Failed to register worker' : 'Failed to save profile'))
    }
    finally { setSaving(false) }
  }

  return (
    <Panel title="Worker Profile" sub="Identity, contact and vendor assignment"
      actions={
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          {editable && <SaveBtn onClick={save} saving={saving} saved={saved} label={creating ? 'Register Worker' : 'Save Profile'} />}
          {!creating && (
            <button type="button" onClick={onNext}
              style={{
                padding: '8px 16px', borderRadius: 9, border: 'none',
                background: 'linear-gradient(135deg, #0284c7, #0369a1)', color: '#fff',
                fontWeight: 800, fontSize: 12.5, cursor: 'pointer',
                display: 'inline-flex', alignItems: 'center', gap: 6
              }}>
              Continue → Step 2 (Medical)
            </button>
          )}
        </div>
      }>
      {!editable && <InfoBox>This worker is no longer editable — the profile is read-only.</InfoBox>}
      {underage && <InfoBox tone="danger">This worker is {age} — under 18. A site badge must not be issued.</InfoBox>}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 14 }}>
        {creating && (
          <Field label="Vendor *">
            <SelectInput value={f.vendor_id} onChange={set('vendor_id')} pairs
              options={[['', 'Select vendor…'], ...vendors.map(v => [String(v.id), v.purchase_vendor_code ? `${v.purchase_vendor_code} · ${v.company_name}` : v.company_name])]} />
            <span style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 600 }}>Fixed at registration — a worker cannot be moved between vendors.</span>
          </Field>
        )}
        {!creating && (
          <Field label="Vendor">
            <TextInput value={worker.vendor?.company_name || '—'} disabled />
          </Field>
        )}
        <Field label="Full Name *"><TextInput value={f.full_name} onChange={set('full_name')} disabled={!editable} /></Field>
        <Field label="Date of Birth *">
          <TextInput type="date" value={f.dob} onChange={set('dob')} disabled={!editable}
            style={underage ? { ...inputStyle, borderColor: '#ef4444' } : undefined} />
          {age !== null && <span style={{ fontSize: 11, color: underage ? '#ef4444' : 'var(--text-muted)', fontWeight: 600 }}>Age {age}</span>}
        </Field>
        <Field label="Gender"><SelectInput value={f.gender} onChange={set('gender')} disabled={!editable} options={['', ...GENDERS]} /></Field>
        <Field label="Designation *"><TextInput value={f.designation} onChange={set('designation')} disabled={!editable} placeholder="e.g. Fitter" /></Field>
        <Field label="ID Proof Type"><SelectInput value={f.id_proof_type} onChange={set('id_proof_type')} disabled={!editable} options={['', ...ID_PROOF_TYPES]} /></Field>
        <Field label="ID Proof Number *">
          <TextInput value={f.id_proof_number} onChange={set('id_proof_number')} disabled={!editable}
            placeholder={aadhaarish ? '12-digit ID' : 'Document number'}
            inputMode={aadhaarish ? 'numeric' : undefined} maxLength={aadhaarish ? 12 : 80}
            style={badAadhaar ? { ...inputStyle, borderColor: '#ef4444' } : undefined} />
          {badAadhaar && <span style={{ fontSize: 11, color: '#ef4444', fontWeight: 600 }}>Must be exactly 12 digits</span>}
        </Field>
        <Field label="Mobile *"><TextInput value={f.phone} onChange={set('phone')} disabled={!editable} placeholder="10-digit mobile" inputMode="numeric" maxLength={10} /></Field>
        <Field label="Email"><TextInput type="email" value={f.email} onChange={set('email')} disabled={!editable} placeholder="name@example.com" /></Field>
        <Field label="City"><TextInput value={f.city} onChange={set('city')} disabled={!editable} /></Field>
        <Field label="State"><TextInput value={f.state} onChange={set('state')} disabled={!editable} /></Field>
        <Field label="Pincode"><TextInput value={f.pincode} onChange={set('pincode')} disabled={!editable} inputMode="numeric" maxLength={20} /></Field>
        <Field label="Address" full><textarea value={f.address} onChange={set('address')} disabled={!editable} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
        {/* The lifecycle appends its suspend/terminate reasons here, so the box is
            the worker's running record — not a scratch pad. */}
        <Field label="Notes" full><textarea value={f.notes} onChange={set('notes')} disabled={!editable} rows={2} placeholder="e.g. Safety Star – Aug 2026" style={{ ...inputStyle, resize: 'vertical' }} /></Field>
      </div>

      <div style={{ marginTop: 24, paddingTop: 16, borderTop: '1px solid var(--border)', display: 'flex', justifyContent: 'flex-end', gap: 12 }}>
        <button type="button" onClick={async () => { await save(); if (!creating) onNext() }} disabled={saving}
          style={{
            padding: '10px 20px', borderRadius: 10, border: 'none',
            background: 'linear-gradient(135deg, #0284c7, #0369a1)', color: '#fff',
            fontWeight: 800, fontSize: 13, cursor: 'pointer',
            display: 'inline-flex', alignItems: 'center', gap: 6
          }}>
          Save &amp; Continue → Step 2 (Medical)
        </button>
      </div>
    </Panel>
  )
}

// ── Step 2 — Medical + screening ─────────────────────────────────────────────
/**
 * Purchase keeps medicals as a HISTORY table (purchase_worker_medicals), so the
 * form pre-fills from the LATEST examination and every save records a new one —
 * a re-test never overwrites the exam it supersedes.
 *
 * The endpoint's columns are narrow (exam_date, valid_until, fitness_status,
 * provider, remarks) while the examination itself produces vitals, a computed
 * BMI verdict and a mental-health screening. Everything the columns cannot hold
 * is folded into `remarks` as a readable block rather than dropped, so the
 * record on file still says how the verdict was reached. fitness_status is what
 * the badge gate reads, so it carries the truthful outcome and nothing else.
 */
function Step2Medical({ worker, editable, onSaved, onNext }) {
  // Newest first — the top row is the current fitness the readiness gate reads.
  const history = useMemo(() => sortMedicals(worker.medicals), [worker.medicals])
  const m = history[0] || {}

  const [f, setF] = useState({
    medical_type: '',
    doctor_name: m.examiner_name || '',
    organization_name: 'Hospital / Clinic',
    doctor_registration: '',
    designation: worker.designation || 'Worker',
    dob: worker.dob?.slice(0, 10) || '',
    gender: worker.gender || 'Male',
    blood_group: m.blood_group || 'O+',
    eyesight: '6/6',
    height: '',
    weight: '',
    blood_pressure: '120/80',
    height_phobia: 'no',
    heart_disease: 'no',
    habits: 'none',
    handicapped: 'no',
    doctor_comments: m.remarks || '',
    valid_until: m.expiry_date?.slice(0, 10) || '',
    external_doctor_name: '',
    // External exam: the fitness the examiner certified on their own report.
    external_fitness: 'Fit',
  })

  const [saving, setSaving] = useState(false)
  const [saved, setSaved]   = useState(false)
  const [mhVer, setMhVer]   = useState(1)
  const [mhAnswers, setMhAnswers] = useState({})

  const set = (k) => (e) => { setF(p => ({ ...p, [k]: e.target.value })); setSaved(false) }

  const age = ageOf(f.dob) ?? ageOf(worker.dob)
  const bmiVal = (f.height && f.weight) ? (Number(f.weight) / Math.pow(Number(f.height) / 100, 2)).toFixed(1) : null
  const bmiLabel = bmiVal ? (bmiVal < 18.5 ? ' (Underweight)' : bmiVal <= 24.9 ? ' (Normal)' : bmiVal <= 29.9 ? ' (Overweight)' : ' (Obese)') : ''
  const physicalScore = bmiVal ? (bmiVal >= 18.5 && bmiVal <= 24.9 ? '9 / 10' : bmiVal >= 16 && bmiVal <= 30 ? '7 / 10' : '5 / 10') : '—'
  const medicalResult = bmiVal ? (Number(physicalScore[0]) >= 7 ? 'Medically Fit' : 'Medically Unfit') : 'Pending Vitals'

  const MH_QUESTIONS = {
    1: [
      { id: 'mh_q1', q: 'क्या आप इन दिनों मानसिक रूप से तनाव महसूस करते हैं?', label_en: 'Do you feel mentally stressed these days?', flag: false },
      { id: 'mh_q2', q: 'क्या आप ठीक से सो पाते हैं?', label_en: 'Are you sleeping properly?', flag: false },
      { id: 'mh_q3', q: 'क्या आप अधिकतर दिनों में बहुत थका हुआ महसूस करते हैं?', label_en: 'Do you feel physically very tired most days?', flag: false },
      { id: 'mh_q4', q: 'क्या आप नौकरी, पैसे या परिवार की समस्याओं से परेशान हैं?', label_en: 'Worried about job, money, or family issues?', flag: false },
      { id: 'mh_q5', q: 'क्या आपने हाल ही में कोई गंभीर घटना या आघात अनुभव किया है?', label_en: 'Experienced any serious incident or trauma recently?', flag: true, flagVal: 2, flagNote: 'Trauma reported' },
      { id: 'mh_q6', q: 'क्या आप तनाव कम करने के लिए प्रतिदिन शराब/तम्बाकू का सेवन करते हैं?', label_en: 'Use alcohol/tobacco daily to reduce stress?', flag: true, flagVal: 2, flagNote: 'Alcohol/substance dependency' },
    ],
    2: [
      { id: 'mh_q1', q: 'Do you currently feel mentally exhausted?', label_en: 'Do you currently feel mentally exhausted?', flag: false },
      { id: 'mh_q2', q: 'Are you facing significant stress outside work?', label_en: 'Facing significant stress outside work?', flag: false },
      { id: 'mh_q3', q: 'Are you sleeping less than 6 hours regularly?', label_en: 'Sleeping less than 6 hours regularly?', flag: false },
      { id: 'mh_q4', q: 'Do you feel anxious about job performance?', label_en: 'Feel anxious about job performance?', flag: false },
      { id: 'mh_q5', q: 'Do you feel emotionally low recently?', label_en: 'Feel emotionally low recently?', flag: false },
      { id: 'mh_q6', q: 'Are you under any medication for stress/depression?', label_en: 'Under medication for stress/depression?', flag: true, flagVal: 2, flagNote: 'Active medication for mental health' },
    ]
  }

  const qs = MH_QUESTIONS[mhVer] || []
  let totalMhScore = 0
  let allMhAnswered = true
  let mhFlags = []
  qs.forEach(q => {
    const val = mhAnswers[q.id]
    if (val === undefined) allMhAnswered = false
    else {
      totalMhScore += val
      if (q.flag && val >= q.flagVal) mhFlags.push(q.flagNote)
    }
  })

  let mhRisk = '—'
  let mhAction = 'Answer all 6 questions above to see action.'
  let mhBg = '#f8f9fa'
  let mhColor = '#555'

  if (allMhAnswered) {
    if (totalMhScore <= 3) {
      mhRisk = 'Green'; mhColor = '#155724'; mhBg = '#d4edda'
      mhAction = '✔ Normal induction — proceed as standard.'
    } else if (totalMhScore <= 7) {
      mhRisk = 'Yellow'; mhColor = '#7d4a00'; mhBg = '#fff3cd'
      mhAction = '👁 Supervisor to observe for 2 weeks post-deployment.'
    } else {
      mhRisk = 'Red'; mhColor = '#721c24'; mhBg = '#f8d7da'
      mhAction = '🛡 HR private discussion required before site deployment.'
    }
  }

  const certNo = `MFC-${(worker.worker_code || 'W000').toUpperCase()}-${new Date().getFullYear()}-8492`

  const printPrescription = () => {
    const drugs = []
    const bmiNum = Number(bmiVal)
    if (bmiNum && bmiNum < 18.5) {
      drugs.push(['1', 'Protein Supplement (Whey Protein)', '1 scoop/milk', '30 days', 'Morning'])
      drugs.push(['2', 'Vitamin B-Complex Tablet', '1 tablet', '30 days', 'After breakfast'])
    }
    if (bmiNum && bmiNum > 27) {
      drugs.push(['1', 'Dietary guidance: reduce refined carbs', '—', 'Ongoing', 'Daily'])
      drugs.push(['2', 'Physical activity: 30 min brisk walk', '—', 'Ongoing', 'Morning'])
    }
    if (['alcohol', 'tobacco', 'smoking', 'drugs'].includes(f.habits)) {
      drugs.push(['—', 'De-addiction Counselling / Referral', '—', 'Immediate', 'ASAP'])
    }
    if (f.heart_disease === 'yes') {
      drugs.push(['—', '⚠ Cardiology review MANDATORY before site deployment', '—', 'Before joining', 'Mandatory'])
    }
    if (f.height_phobia === 'yes') {
      drugs.push(['—', '⚠ Restrict: NO height-based work (scaffolding/towers)', '—', 'Permanent', 'Standing order'])
    }
    if (mhRisk === 'Red') {
      drugs.push(['—', '⚠ MANDATORY HR consultation before deployment', '—', 'Immediate', 'Before joining'])
    }
    if (drugs.length === 0) {
      drugs.push(['1', 'No specific medication required at this time', '—', '—', '—'])
      drugs.push(['2', 'Balanced diet & adequate hydration (2.5 L water/day)', '—', 'Ongoing', 'Daily'])
      drugs.push(['3', 'Adequate sleep (7–8 hours/night) recommended', '—', 'Ongoing', 'Nightly'])
    }

    const drugsHtml = drugs.map(d => `<tr><td>${d[0]}</td><td>${d[1]}</td><td>${d[2]}</td><td>${d[3]}</td><td>${d[4]}</td></tr>`).join('')
    const win = window.open('', '_blank')
    win.document.write(`
      <!DOCTYPE html><html><head>
      <title>Medical Certificate — ${worker.full_name}</title>
      <style>
        body { font-family: Georgia, serif; padding: 20px; background: #fff; color: #222; }
        .rx-outer { border: 2px solid #1565c0; border-radius: 10px; overflow: hidden; }
        .rx-letterhead { background: linear-gradient(135deg,#0d47a1,#1976d2); padding: 14px 22px; display: flex; justify-content: space-between; color: #fff; }
        .rx-patient-bar { background: #e3f2fd; padding: 8px 22px; display: flex; flex-wrap: wrap; gap: 20px; border-bottom: 1px solid #90caf9; }
        .rx-vitals { display: flex; border-bottom: 1px solid #e0e0e0; }
        .rx-vital-cell { flex: 1; padding: 7px 14px; border-right: 1px solid #e0e0e0; }
        .rx-drugs { width: 100%; border-collapse: collapse; font-size: 11px; margin-top: 10px; }
        .rx-drugs th { background: #0d47a1; color: #fff; padding: 6px 10px; text-align: left; }
        .rx-drugs td { padding: 5px 10px; border-bottom: 1px solid #e8edf5; }
        .rx-advice { background: #fff8e1; border-left: 4px solid #ffc107; padding: 10px; margin-top: 10px; font-style: italic; font-size: 12px; }
        .rx-footer { border-top: 1px solid #e0e0e0; padding: 12px 22px; display: flex; justify-content: space-between; align-items: flex-end; }
      </style></head>
      <body>
        <div class="rx-outer">
          <div class="rx-letterhead">
            <div>
              <div style="font-size:18px;font-weight:900;letter-spacing:2px;">${(f.organization_name || 'HOSPITAL').toUpperCase()}</div>
              <div style="font-size:11px;opacity:.8;">MEDICAL FITNESS CERTIFICATION — HSSE DIVISION</div>
            </div>
            <div style="text-align:right;">
              <div style="font-size:15px;font-weight:900;">Dr. ${f.doctor_name || '___________'}</div>
              <div style="font-size:10px;">Reg No: ${f.doctor_registration || '___________'}</div>
            </div>
          </div>
          <div class="rx-patient-bar">
            <div><small>WORKER CODE</small><br><strong>${worker.worker_code || '—'}</strong></div>
            <div><small>PATIENT NAME</small><br><strong>${worker.full_name}</strong></div>
            <div><small>AGE/GENDER</small><br><strong>${age || '—'} yrs / ${f.gender}</strong></div>
            <div><small>CERTIFICATE NO.</small><br><strong>${certNo}</strong></div>
          </div>
          <div class="rx-vitals">
            <div class="rx-vital-cell"><small>HEIGHT</small><br><strong>${f.height ? f.height + ' cm' : '—'}</strong></div>
            <div class="rx-vital-cell"><small>WEIGHT</small><br><strong>${f.weight ? f.weight + ' kg' : '—'}</strong></div>
            <div class="rx-vital-cell"><small>BMI</small><br><strong>${bmiVal || '—'}</strong></div>
            <div class="rx-vital-cell"><small>BP</small><br><strong>${f.blood_pressure || '—'}</strong></div>
            <div class="rx-vital-cell"><small>EYESIGHT</small><br><strong>${f.eyesight || '—'}</strong></div>
          </div>
          <div style="padding:16px;">
            <div style="font-size:36px;font-weight:900;color:#0d47a1;">℞</div>
            <table class="rx-drugs">
              <thead><tr><th>#</th><th>Recommendation / Medicine</th><th>Dose</th><th>Duration</th><th>Timing</th></tr></thead>
              <tbody>${drugsHtml}</tbody>
            </table>
            <div class="rx-advice">Worker <strong>${worker.full_name}</strong> (${worker.worker_code}) is certified <strong>${medicalResult}</strong>. Standard HSSE protocols apply.</div>
          </div>
          <div class="rx-footer">
            <div style="font-size:10px;color:#666;">Generated: ${new Date().toLocaleString()} IST<br>Ref: ${certNo}</div>
            <div style="border-top:1.5px solid #333;width:180px;text-align:center;padding-top:4px;font-size:10px;">
              Dr. ${f.doctor_name || '___________'}<br>Reg. No: ${f.doctor_registration || '___________'}
            </div>
          </div>
        </div>
      </body></html>
    `)
    win.document.close()
    setTimeout(() => win.print(), 400)
  }

  const saveMedical = async () => {
    if (f.medical_type === 'internal') {
      if (!f.doctor_name.trim()) { alert('Doctor Name is required.'); return }
      if (!f.organization_name.trim()) { alert('Hospital/Organisation Name is required.'); return }
      if (!f.height || Number(f.height) < 100) { alert('Valid height (100-250 cm) is required.'); return }
      if (!f.weight || Number(f.weight) < 30) { alert('Valid weight (30-200 kg) is required.'); return }
      if (medicalResult === 'Pending Vitals') { alert('Enter height and weight so a fitness result can be determined.'); return }
    } else if (f.medical_type === 'external') {
      if (!f.external_doctor_name.trim()) { alert('External Doctor Name is required.'); return }
    }

    setSaving(true)
    try {
      const isExternal = f.medical_type === 'external'
      // For an internal exam the fitness is the computed physical result; for an
      // external one it is the outcome the examiner certified. Never assumed Fit —
      // fitness_status is exactly what readiness() and the gate read.
      const fitness = isExternal
        ? f.external_fitness
        : (medicalResult === 'Medically Fit' ? 'Fit' : 'Unfit')

      const lines = [
        `Examination: ${isExternal ? 'External doctor' : 'Internal (on-site)'}`,
        `Examiner: Dr. ${(isExternal ? f.external_doctor_name : f.doctor_name) || '—'}${f.doctor_registration ? ` (Reg. ${f.doctor_registration})` : ''}`,
        `Clinic: ${f.organization_name || '—'}`,
      ]
      if (!isExternal) {
        lines.push(
          `Vitals: height ${f.height || '—'} cm · weight ${f.weight || '—'} kg · BMI ${bmiVal || '—'}${bmiLabel} · BP ${f.blood_pressure || '—'} · vision ${f.eyesight || '—'} · blood group ${f.blood_group || '—'}`,
          `Physical health score: ${physicalScore} → ${medicalResult}`,
          `History: height phobia ${f.height_phobia} · heart disease ${f.heart_disease} · habits ${f.habits} · handicapped ${f.handicapped}${f.handicapped === 'yes' ? ' (sitting jobs only)' : ''}`,
        )
        if (allMhAnswered) lines.push(`Mental-health screening (${mhVer === 1 ? 'construction' : 'office/technical'}): ${totalMhScore}/12 · ${mhRisk} · ${mhAction}`)
        if (mhFlags.length) lines.push(`ESCALATION: ${mhFlags.join('; ')} — do not deploy without HR clearance.`)
      }
      if (f.doctor_comments.trim()) lines.push(`Doctor comments: ${f.doctor_comments.trim()}`)

      await purchaseApi.workforce.saveMedical(worker.id, {
        exam_date: new Date().toISOString().slice(0, 10),
        valid_until: f.valid_until || null,
        fitness_status: fitness,
        provider: f.organization_name || null,
        // The endpoint keeps five columns; the rest of the examination rides here
        // so the record still explains the verdict rather than losing it.
        remarks: lines.join('\n').slice(0, 2000),
      })
      setSaved(true)
      onSaved()
      if (onNext) onNext()
    } catch (e) {
      alert(apiError(e, 'Failed to save medical details'))
    } finally {
      setSaving(false)
    }
  }

  const markSkip = async () => {
    // No medical record is created on skip, so the badge gate stays blocked until
    // one is recorded — advancing is a UI convenience, not a persisted state.
    if (!window.confirm('Skip the medical examination for now? No medical record will be created, and a site badge cannot be issued until one is.')) return
    if (onNext) onNext()
  }

  return (
    <Panel title="Step 2 — Medical Fitness Examination" sub="Physical vitals, mental health screening & prescription certificate"
      actions={
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          {editable && f.medical_type && f.medical_type !== 'skip' && (
            <SaveBtn onClick={saveMedical} saving={saving} saved={saved} label="Save Medical Examination" />
          )}
          <button type="button" onClick={onNext}
            style={{
              padding: '8px 16px', borderRadius: 9, border: 'none',
              background: 'linear-gradient(135deg, #0284c7, #0369a1)', color: '#fff',
              fontWeight: 800, fontSize: 12.5, cursor: 'pointer',
              display: 'inline-flex', alignItems: 'center', gap: 6
            }}>
            Continue → Step 3 (Induction)
          </button>
        </div>
      }>

      {/* Prefill Strip */}
      <div style={{ padding: '12px 18px', borderRadius: 10, background: 'linear-gradient(135deg, #f0f9ff, #e0f2fe)', border: '1.5px solid #7dd3fc', marginBottom: 18, display: 'flex', flexWrap: 'wrap', gap: 18, alignItems: 'center' }}>
        <div><span style={{ fontSize: 9, fontWeight: 800, color: '#0369a1', textTransform: 'uppercase' }}>Worker Code</span><br /><strong style={{ color: '#0c4a6e', fontSize: 13 }}>{worker.worker_code || '—'}</strong></div>
        <div><span style={{ fontSize: 9, fontWeight: 800, color: '#0369a1', textTransform: 'uppercase' }}>Full Name</span><br /><strong style={{ color: '#0c4a6e', fontSize: 13 }}>{worker.full_name}</strong></div>
        <div><span style={{ fontSize: 9, fontWeight: 800, color: '#0369a1', textTransform: 'uppercase' }}>Age</span><br /><strong style={{ color: '#0c4a6e', fontSize: 13 }}>{age ? `${age} yrs` : '—'}</strong></div>
        <div><span style={{ fontSize: 9, fontWeight: 800, color: '#0369a1', textTransform: 'uppercase' }}>Gender</span><br /><strong style={{ color: '#0c4a6e', fontSize: 13 }}>{f.gender}</strong></div>
        <div><span style={{ fontSize: 9, fontWeight: 800, color: '#0369a1', textTransform: 'uppercase' }}>Blood Group</span><br /><strong style={{ color: '#0c4a6e', fontSize: 13 }}>{f.blood_group}</strong></div>
        <div><span style={{ fontSize: 9, fontWeight: 800, color: '#0369a1', textTransform: 'uppercase' }}>Designation</span><br /><strong style={{ color: '#0c4a6e', fontSize: 13 }}>{f.designation}</strong></div>
        <div style={{ marginLeft: 'auto', background: '#dcfce7', color: '#15803d', border: '1px solid #86efac', padding: '4px 12px', borderRadius: 20, fontSize: 11, fontWeight: 800 }}>
          ✨ Auto Pre-filled from Step 1
        </div>
      </div>

      {/* Medical History — every past exam, newest first. Purchase stores these
          one-to-many, so re-tests accumulate; the top row is the current fitness
          the readiness gate reads. */}
      {history.length > 0 && (
        <div style={{ marginBottom: 18, border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden' }}>
          <div style={{ padding: '8px 14px', background: 'var(--bg-input)', fontSize: 12, fontWeight: 800, color: 'var(--text-h)' }}>
            🩺 Medical History ({history.length})
          </div>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
              <thead>
                <tr style={{ textAlign: 'left', color: 'var(--text-muted)' }}>
                  <th style={{ padding: '6px 14px', fontWeight: 700 }}>Date</th>
                  <th style={{ padding: '6px 14px', fontWeight: 700 }}>Examiner</th>
                  <th style={{ padding: '6px 14px', fontWeight: 700 }}>Fitness</th>
                  <th style={{ padding: '6px 14px', fontWeight: 700 }}>Restrictions</th>
                  <th style={{ padding: '6px 14px', fontWeight: 700 }}>Valid Until</th>
                </tr>
              </thead>
              <tbody>
                {history.map((row, i) => (
                  <tr key={row.id} style={{ borderTop: '1px solid var(--border)', background: i === 0 ? 'rgba(16,185,129,0.06)' : 'transparent' }}>
                    <td style={{ padding: '6px 14px' }}>
                      {row.exam_date ? new Date(row.exam_date).toLocaleDateString() : '—'}
                      {i === 0 && <span style={{ marginLeft: 6, fontSize: 9, fontWeight: 800, color: '#15803d' }}>CURRENT</span>}
                    </td>
                    <td style={{ padding: '6px 14px' }}>{row.examiner_name || '—'}</td>
                    <td style={{ padding: '6px 14px', fontWeight: 700, color: row.is_passing ? '#15803d' : '#b91c1c' }}>
                      {row.fitness_label || String(row.fitness_status || '—').replace(/_/g, ' ')}
                    </td>
                    <td style={{ padding: '6px 14px' }}>{row.restrictions || '—'}</td>
                    <td style={{ padding: '6px 14px' }}>
                      {row.expiry_date ? new Date(row.expiry_date).toLocaleDateString() : '—'}
                      {row.is_expired && <span style={{ marginLeft: 6, fontSize: 9, fontWeight: 800, color: '#b91c1c' }}>EXPIRED</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Type Selector */}
      {!f.medical_type ? (
        <div style={{ padding: '40px 20px', textAlign: 'center', background: 'var(--bg-input)', borderRadius: 16, border: '1px dashed var(--border)' }}>
          <p style={{ color: 'var(--text-muted)', fontSize: 14, fontWeight: 700, marginBottom: 20 }}>Select medical examination type:</p>
          <div style={{ display: 'flex', justifyContent: 'center', gap: 16, flexWrap: 'wrap' }}>
            <button type="button" onClick={() => setF(p => ({ ...p, medical_type: 'internal' }))} style={{ padding: '16px 24px', borderRadius: 12, background: 'linear-gradient(135deg, #0284c7, #0369a1)', color: '#fff', border: 'none', cursor: 'pointer', fontWeight: 800, textAlign: 'center' }}>
              👨‍⚕️ Internal Doctor
              <div style={{ fontSize: 11, opacity: 0.8, marginTop: 4 }}>On-site examination with pre-filled details</div>
            </button>
            <button type="button" onClick={() => setF(p => ({ ...p, medical_type: 'external' }))} style={{ padding: '16px 24px', borderRadius: 12, background: 'linear-gradient(135deg, #10b981, #047857)', color: '#fff', border: 'none', cursor: 'pointer', fontWeight: 800, textAlign: 'center' }}>
              🏥 External Doctor
              <div style={{ fontSize: 11, opacity: 0.8, marginTop: 4 }}>Record an outside examiner's certified outcome</div>
            </button>
            <button type="button" onClick={markSkip} style={{ padding: '16px 24px', borderRadius: 12, background: 'linear-gradient(135deg, #f59e0b, #b45309)', color: '#fff', border: 'none', cursor: 'pointer', fontWeight: 800, textAlign: 'center' }}>
              ⏩ Skip Medical
              <div style={{ fontSize: 11, opacity: 0.8, marginTop: 4 }}>Mark medical as skipped</div>
            </button>
          </div>
        </div>
      ) : f.medical_type === 'internal' ? (
        <div>
          {/* Doctor Details */}
          <h3 style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)', marginBottom: 12, borderBottom: '1px solid var(--border)', paddingBottom: 6 }}>👨‍⚕️ Doctor Details</h3>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 14, marginBottom: 20 }}>
            <Field label="Doctor Name *"><TextInput value={f.doctor_name} onChange={set('doctor_name')} placeholder="Dr. Full Name" /></Field>
            <Field label="Hospital / Organisation *"><TextInput value={f.organization_name} onChange={set('organization_name')} placeholder="Hospital or Clinic" /></Field>
            <Field label="Registration No *"><TextInput value={f.doctor_registration} onChange={set('doctor_registration')} placeholder="Medical Reg. Number" /></Field>
          </div>

          {/* Physical Vitals */}
          <h3 style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)', marginBottom: 12, borderBottom: '1px solid var(--border)', paddingBottom: 6 }}>🩺 Worker Physical Vitals & Medical Details</h3>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr 1fr', gap: 14, marginBottom: 16 }}>
            <Field label="Designation"><TextInput value={f.designation} onChange={set('designation')} /></Field>
            <Field label="Date of Birth"><TextInput type="date" value={f.dob} onChange={set('dob')} /></Field>
            <Field label="Gender *"><SelectInput value={f.gender} onChange={set('gender')} pairs options={[['Male', 'Male'], ['Female', 'Female'], ['Transgender', 'Transgender']]} /></Field>
            <Field label="Blood Group"><SelectInput value={f.blood_group} onChange={set('blood_group')} pairs options={[['A+', 'A+'], ['A-', 'A-'], ['B+', 'B+'], ['B-', 'B-'], ['O+', 'O+'], ['O-', 'O-'], ['AB+', 'AB+'], ['AB-', 'AB-']]} /></Field>
            <Field label="Eyesight"><TextInput value={f.eyesight} onChange={set('eyesight')} placeholder="e.g. 6/6" /></Field>
            <Field label="Height (cm) *"><TextInput type="number" min="100" max="250" value={f.height} onChange={set('height')} placeholder="170" /></Field>
            <Field label="Weight (kg) *"><TextInput type="number" min="30" max="200" value={f.weight} onChange={set('weight')} placeholder="65" /></Field>
            <Field label="Blood Pressure"><TextInput value={f.blood_pressure} onChange={set('blood_pressure')} placeholder="120/80" /></Field>
            <Field label="Height Phobia"><SelectInput value={f.height_phobia} onChange={set('height_phobia')} pairs options={[['no', 'No'], ['yes', 'Yes']]} /></Field>
            <Field label="Heart Disease"><SelectInput value={f.heart_disease} onChange={set('heart_disease')} pairs options={[['no', 'No'], ['yes', 'Yes']]} /></Field>
            <Field label="Habits"><SelectInput value={f.habits} onChange={set('habits')} pairs options={[['none', 'None'], ['smoking', 'Smoking'], ['tobacco', 'Tobacco'], ['alcohol', 'Alcohol'], ['gutkha', 'Gutkha'], ['paan', 'Paan'], ['drugs', 'Drugs']]} /></Field>
            <Field label="Handicapped"><SelectInput value={f.handicapped} onChange={set('handicapped')} pairs options={[['no', 'No'], ['yes', 'Yes']]} /></Field>
          </div>

          {f.handicapped === 'yes' && (
            <div style={{ padding: '10px 14px', borderRadius: 8, background: '#fef3c7', border: '1px solid #fde68a', color: '#92400e', fontWeight: 700, fontSize: 12, marginBottom: 16 }}>
              ⚠ Worker allowed for <strong>Sitting Jobs only</strong>
            </div>
          )}

          {/* System Health Analysis */}
          <div style={{ padding: 14, borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)', marginBottom: 20 }}>
            <h4 style={{ margin: '0 0 10px', fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>📊 System Health Analysis (Auto-Calculated)</h4>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 14 }}>
              <div><span style={{ fontSize: 11, color: 'var(--text-muted)' }}>BMI</span><br /><strong style={{ fontSize: 16, color: '#a78bfa' }}>{bmiVal || '—'} {bmiLabel}</strong></div>
              <div><span style={{ fontSize: 11, color: 'var(--text-muted)' }}>Physical Health Score</span><br /><strong style={{ fontSize: 16, color: '#10b981' }}>{physicalScore}</strong></div>
              <div><span style={{ fontSize: 11, color: 'var(--text-muted)' }}>Medical Result</span><br /><strong style={{ fontSize: 16, color: medicalResult === 'Medically Fit' ? '#10b981' : '#ef4444' }}>{medicalResult}</strong></div>
            </div>
          </div>

          {/* Mental Health Screening Engine */}
          <div style={{ borderRadius: 12, border: '1.5px solid #a78bfa', overflow: 'hidden', marginBottom: 20 }}>
            <div style={{ padding: '10px 16px', background: 'linear-gradient(135deg, #7c3aed, #6d28d9)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
              <span style={{ fontWeight: 800, fontSize: 13 }}>🧠 Mental Health Screening Engine</span>
              <div style={{ display: 'flex', gap: 6 }}>
                <button type="button" onClick={() => setMhVer(1)} style={{ padding: '4px 12px', borderRadius: 20, border: 'none', background: mhVer === 1 ? '#fff' : 'rgba(255,255,255,0.2)', color: mhVer === 1 ? '#7c3aed' : '#fff', fontWeight: 800, fontSize: 11, cursor: 'pointer' }}>🏗️ Construction</button>
                <button type="button" onClick={() => setMhVer(2)} style={{ padding: '4px 12px', borderRadius: 20, border: 'none', background: mhVer === 2 ? '#fff' : 'rgba(255,255,255,0.2)', color: mhVer === 2 ? '#7c3aed' : '#fff', fontWeight: 800, fontSize: 11, cursor: 'pointer' }}>💻 Office/Technical</button>
              </div>
            </div>
            <div style={{ padding: 16, background: 'var(--bg-card)' }}>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginBottom: 14 }}>
                {qs.map((q, idx) => (
                  <div key={q.id} style={{ padding: 10, borderRadius: 8, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                    <div style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-h)', marginBottom: 6 }}>
                      <span style={{ background: '#7c3aed', color: '#fff', padding: '1px 5px', borderRadius: 4, fontSize: 10, marginRight: 6 }}>Q{idx + 1}</span>
                      {q.q} {q.flag && <span style={{ background: '#ef4444', color: '#fff', fontSize: 9, padding: '1px 5px', borderRadius: 10, marginLeft: 4 }}>FLAG</span>}
                    </div>
                    <div style={{ display: 'flex', gap: 6 }}>
                      {[0, 1, 2].map(v => (
                        <button type="button" key={v} onClick={() => setMhAnswers(p => ({ ...p, [q.id]: v }))} style={{ flex: 1, padding: '5px 0', borderRadius: 6, border: '1px solid', fontSize: 11, fontWeight: 800, cursor: 'pointer', textAlign: 'center', background: mhAnswers[q.id] === v ? (v === 0 ? '#10b981' : v === 1 ? '#f59e0b' : '#ef4444') : 'transparent', color: mhAnswers[q.id] === v ? '#fff' : 'var(--text-muted)', borderColor: mhAnswers[q.id] === v ? 'transparent' : 'var(--border)' }}>
                          {v === 0 ? 'No' : v === 1 ? 'Sometimes' : 'Yes'}
                        </button>
                      ))}
                    </div>
                  </div>
                ))}
              </div>

              {/* Risk Summary */}
              <div style={{ padding: 12, borderRadius: 10, background: mhBg, color: mhColor, border: `1px solid ${mhColor}`, display: 'flex', alignItems: 'center', gap: 16 }}>
                <div><span style={{ fontSize: 10, fontWeight: 800, textTransform: 'uppercase' }}>Score</span><br /><strong style={{ fontSize: 20 }}>{totalMhScore} / 12</strong></div>
                <div><span style={{ fontSize: 10, fontWeight: 800, textTransform: 'uppercase' }}>Risk Level</span><br /><strong style={{ fontSize: 14 }}>● {mhRisk}</strong></div>
                <div style={{ flex: 1 }}><span style={{ fontSize: 10, fontWeight: 800, textTransform: 'uppercase' }}>Action Required</span><br /><span style={{ fontSize: 12.5, fontWeight: 700 }}>{mhAction}</span></div>
              </div>

              {mhFlags.length > 0 && (
                <div style={{ padding: '10px 14px', borderRadius: 8, background: '#fef2f2', border: '1.5px solid #ef4444', color: '#991b1b', fontSize: 12, fontWeight: 700, marginTop: 10 }}>
                  ⚠ <strong>IMMEDIATE ESCALATION REQUIRED:</strong> {mhFlags.join('; ')} — do not deploy without HR clearance.
                </div>
              )}
            </div>
          </div>

          {/* Doctor Comments */}
          <div style={{ marginBottom: 20 }}>
            <Field label="Doctor Comments *">
              <textarea rows={3} value={f.doctor_comments} onChange={set('doctor_comments')} placeholder="Enter medical remarks and observations" style={{ ...inputStyle, resize: 'vertical' }} />
            </Field>
          </div>

          {/* ℞ Prescription Generator Banner */}
          <div style={{ padding: 14, borderRadius: 12, background: 'linear-gradient(135deg, #0d47a1, #1565c0)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, marginBottom: 20 }}>
            <div>
              <strong style={{ fontSize: 14, display: 'block' }}>℞ Medical Fitness Certificate & Prescription</strong>
              <span style={{ fontSize: 11.5, opacity: 0.8 }}>Generate official doctor prescription and printable fitness certificate.</span>
            </div>
            <button type="button" onClick={printPrescription} style={{ padding: '8px 18px', borderRadius: 8, background: '#ffd54f', color: '#0d47a1', border: 'none', cursor: 'pointer', fontWeight: 900, fontSize: 12.5, display: 'inline-flex', alignItems: 'center', gap: 6 }}>
              🖨️ Print Certificate (℞)
            </button>
          </div>

          <button type="button" onClick={() => setF(p => ({ ...p, medical_type: '' }))} style={{ padding: '8px 16px', borderRadius: 8, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)', fontWeight: 800, cursor: 'pointer' }}>← Change Medical Type</button>
        </div>
      ) : (
        /* External Doctor — the certified outcome, recorded against this worker */
        <div>
          <h3 style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)', marginBottom: 12 }}>🏥 Record External Doctor Medical Result</h3>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14, marginBottom: 14 }}>
            <Field label="External Doctor Name *"><TextInput value={f.external_doctor_name} onChange={set('external_doctor_name')} placeholder="Dr. Full Name" /></Field>
            <Field label="Certified Fitness Outcome *">
              <SelectInput value={f.external_fitness} onChange={set('external_fitness')} pairs options={FITNESS} />
            </Field>
          </div>
          <Field label="Examiner Notes">
            <textarea rows={3} value={f.doctor_comments} onChange={set('doctor_comments')} placeholder="Findings, restrictions and any follow-up the report records" style={{ ...inputStyle, resize: 'vertical' }} />
          </Field>
          <div style={{ marginTop: 14 }}>
            <button type="button" onClick={() => setF(p => ({ ...p, medical_type: '' }))} style={{ padding: '8px 16px', borderRadius: 8, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)', fontWeight: 800, cursor: 'pointer' }}>← Change Medical Type</button>
          </div>
        </div>
      )}

      {/* Certificate currency — an expired medical fails readiness even when the
          verdict was Fit, so the window is captured with the examination. */}
      {f.medical_type && f.medical_type !== 'skip' && (
        <div style={{ marginTop: 18, padding: '12px 14px', border: '1px solid var(--border)', borderRadius: 10, background: 'var(--bg-input)' }}>
          <div style={{ fontSize: 11.5, fontWeight: 800, color: 'var(--text-h)', marginBottom: 8 }}>📅 Certificate Validity</div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14, alignItems: 'end' }}>
            <Field label="Valid Until">
              <TextInput type="date" value={f.valid_until} onChange={set('valid_until')} />
            </Field>
            <div style={{ fontSize: 11, color: 'var(--text-muted)', lineHeight: 1.5 }}>
              Leave blank for a certificate with no stated expiry. Once this date passes the worker fails the medical gate and the badge stops admitting them.
            </div>
          </div>
        </div>
      )}

      <div style={{ marginTop: 24, paddingTop: 16, borderTop: '1px solid var(--border)', display: 'flex', justifyContent: 'flex-end', gap: 12 }}>
        <button type="button" onClick={async () => {
          if (f.medical_type && f.medical_type !== 'skip') {
            await saveMedical()
          } else {
            if (onNext) onNext()
          }
        }} disabled={saving}
          style={{
            padding: '10px 20px', borderRadius: 10, border: 'none',
            background: 'linear-gradient(135deg, #0284c7, #0369a1)', color: '#fff',
            fontWeight: 800, fontSize: 13, cursor: 'pointer',
            display: 'inline-flex', alignItems: 'center', gap: 6
          }}>
          Save &amp; Continue → Step 3 (Induction)
        </button>
      </div>
    </Panel>
  )
}

/** Newest examination first — `medicals` arrives in insertion order. */
function sortMedicals(rows) {
  return [...(rows || [])].sort((a, b) => {
    const da = a.exam_date ? new Date(a.exam_date).getTime() : 0
    const db = b.exam_date ? new Date(b.exam_date).getTime() : 0
    return db - da || (b.id - a.id)
  })
}

// ── Step 3 — HSSE induction ──────────────────────────────────────────────────
const INDUCTION_TYPES = [
  ['General Safety', 'General Safety'],
  ['Activity Specific', 'Activity Specific'],
  ['Site Specific', 'Site Specific'],
  ['Client Specific', 'Client Specific'],
  ['Emergency & Evacuation', 'Emergency & Evacuation'],
  ['Fire Safety', 'Fire Safety'],
  ['PPE Usage', 'PPE Usage'],
  ['Toolbox Talk', 'Toolbox Talk'],
]
const TRAINER_PRESETS = [
  { group: 'Safety Team', items: ['Safety Officer – Rahul Sharma', 'Safety Supervisor – Priya Patel', 'HSE Lead – Amit Verma', 'HSSE Manager – Neha Singh', 'Safety Inspector – Ravi Kumar'] },
  { group: 'HR Team', items: ['HR Manager – Sunita Joshi', 'HR Executive – Deepak Nair', 'HR Coordinator – Anjali Mehta'] },
  { group: 'Site Management', items: ['Site Engineer – Vikram Rao', 'Project Manager – Suresh Pillai', 'Site Supervisor – Mohan Das'] },
  { group: 'Custom', items: ['Other / Custom Trainer...'] },
]
const INDUCTION_TOPICS = ['Site Safety Rules', 'PPE Usage', 'Work at Height', 'Emergency Response', 'Fire Safety', 'First Aid', 'Manual Handling', 'Permit to Work']

/**
 * Purchase records inductions one-to-many too, so this pre-fills from the latest
 * session and each save adds another. Readiness clears on a 'Completed' row, so
 * finishing the session is what writes that status — nothing else does.
 *
 * Note that Purchase's step 3 gate covers training AND induction, and the
 * admin API only writes inductions: a worker's training is recorded by the
 * vendor in its own portal, so the gate can still hold after this step is done.
 */
function StepInduction({ worker, readiness, editable, onSaved, onNext }) {
  const history = useMemo(() => sortInductions(worker.inductions), [worker.inductions])
  const ind = history[0] || {}

  const [f, setF] = useState({
    induction_type: 'General Safety',
    trainer: ind.conducted_by || 'Safety Officer – Rahul Sharma',
    custom_trainer: '',
    location: 'Site Office',
    time_mode: 'auto', // 'auto' | 'manual'
    start_time: '',
    end_time: '',
    duration_minutes: '',
    m_start_date: new Date().toISOString().slice(0, 10),
    m_start_time: new Date().toTimeString().slice(0, 5),
    m_end_date: new Date().toISOString().slice(0, 10),
    m_end_time: new Date().toTimeString().slice(0, 5),
  })

  const [topics, setTopics] = useState(['Site Safety Rules', 'PPE Usage', 'Emergency Response'])
  const [saving, setSaving] = useState(false)
  const [saved, setSaved]   = useState(false)
  const [sessionActive, setSessionActive] = useState(false)

  const set = (k) => (e) => { setF(p => ({ ...p, [k]: e.target.value })); setSaved(false) }
  const toggleTopic = (t) => { setTopics(p => p.includes(t) ? p.filter(x => x !== t) : [...p, t]); setSaved(false) }

  // Timing handlers
  const startSession = () => {
    if (!f.induction_type || !f.location.trim()) { alert('Induction Type and Location are required.'); return }
    const now = new Date()
    setF(p => ({
      ...p,
      start_time: now.toISOString(),
      display_start: now.toLocaleString('en-IN')
    }))
    setSessionActive(true)
  }

  const calcManualDuration = () => {
    if (!f.m_start_date || !f.m_start_time || !f.m_end_date || !f.m_end_time) {
      alert('Please fill all manual start and end dates/times.'); return
    }
    const start = new Date(`${f.m_start_date}T${f.m_start_time}`)
    const end   = new Date(`${f.m_end_date}T${f.m_end_time}`)
    if (end <= start) { alert('End time must be after start time.'); return }
    const dur = Math.round((end - start) / 60000)
    setF(p => ({
      ...p,
      start_time: start.toISOString(),
      end_time: end.toISOString(),
      duration_minutes: dur
    }))
    alert(`Calculated Duration: ${dur} minutes`)
  }

  const saveInduction = async () => {
    const finalTrainer = f.trainer === 'Other / Custom Trainer...' ? f.custom_trainer : f.trainer
    if (!finalTrainer.trim()) { alert('Trainer Name is required.'); return }
    if (!f.location.trim()) { alert('Induction Location is required.'); return }

    setSaving(true)
    try {
      const now = new Date()
      const dur = f.time_mode === 'auto'
        ? (f.start_time ? Math.round((now - new Date(f.start_time)) / 60000) : 15)
        : f.duration_minutes

      await purchaseApi.workforce.saveInduction(worker.id, buildInductionPayload({
        type: f.induction_type, trainer: finalTrainer, location: f.location, duration: dur, topics,
      }))
      setSaved(true)
      onSaved()
      if (onNext) onNext()
    } catch (e) {
      alert(apiError(e, 'Failed to save induction'))
    } finally {
      setSaving(false)
    }
  }

  const markSkip = async () => {
    // No induction record is created on skip, so the badge gate stays blocked
    // until one is recorded — advancing is a UI convenience, not persisted state.
    if (!window.confirm('Skip HSSE induction for now? No induction record will be created, and a site badge cannot be issued until one is.')) return
    if (onNext) onNext()
  }

  const [groupModalOpen, setGroupModalOpen] = useState(false)
  const [vendorWorkers, setVendorWorkers]   = useState([])
  const [loadingWorkers, setLoadingWorkers] = useState(false)

  const openGroupModal = async () => {
    setLoadingWorkers(true)
    try {
      const res = await purchaseApi.workforce.workers(worker.purchase_vendor_id ? { vendor_id: worker.purchase_vendor_id } : {})
      const list = res?.data ?? res ?? []
      setVendorWorkers(Array.isArray(list) ? list : [])
      setGroupModalOpen(true)
    } catch (e) {
      alert('Failed to load vendor workers list')
    } finally {
      setLoadingWorkers(false)
    }
  }

  return (
    <Panel title="Step 3 — HSSE Induction Verification" sub="Site safety induction session, topics covered and trainer sign-off"
      actions={
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <button type="button" onClick={openGroupModal} disabled={loadingWorkers} style={{ padding: '8px 14px', borderRadius: 8, background: 'linear-gradient(135deg, #7c3aed, #6d28d9)', color: '#fff', border: 'none', cursor: 'pointer', fontWeight: 800, fontSize: 12 }}>
            {loadingWorkers ? 'Loading...' : '👥 Group Induction (Multiple Workers)'}
          </button>
          {editable && (
            <>
              <button type="button" onClick={markSkip} style={{ padding: '8px 14px', borderRadius: 8, background: '#f59e0b', color: '#fff', border: 'none', cursor: 'pointer', fontWeight: 800, fontSize: 12 }}>
                ⏩ Skip Induction
              </button>
              <SaveBtn onClick={saveInduction} saving={saving} saved={saved} label="Complete & Save Induction" />
            </>
          )}
          <button type="button" onClick={onNext}
            style={{
              padding: '8px 16px', borderRadius: 9, border: 'none',
              background: 'linear-gradient(135deg, #0284c7, #0369a1)', color: '#fff',
              fontWeight: 800, fontSize: 12.5, cursor: 'pointer',
              display: 'inline-flex', alignItems: 'center', gap: 6
            }}>
            Continue → Step 4 (PPE)
          </button>
        </div>
      }>

      {/* Worker Prefill Strip */}
      <div style={{ padding: '12px 18px', borderRadius: 10, background: 'linear-gradient(135deg, #f0fdf4, #dcfce7)', border: '1.5px solid #86efac', marginBottom: 18, display: 'flex', flexWrap: 'wrap', gap: 18, alignItems: 'center' }}>
        <div><span style={{ fontSize: 9, fontWeight: 800, color: '#15803d', textTransform: 'uppercase' }}>Worker Code</span><br /><strong style={{ color: '#14532d', fontSize: 13 }}>{worker.worker_code || '—'}</strong></div>
        <div><span style={{ fontSize: 9, fontWeight: 800, color: '#15803d', textTransform: 'uppercase' }}>Full Name</span><br /><strong style={{ color: '#14532d', fontSize: 13 }}>{worker.full_name}</strong></div>
        <div><span style={{ fontSize: 9, fontWeight: 800, color: '#15803d', textTransform: 'uppercase' }}>Age</span><br /><strong style={{ color: '#14532d', fontSize: 13 }}>{ageOf(worker.dob) != null ? `${ageOf(worker.dob)} yrs` : '—'}</strong></div>
        <div><span style={{ fontSize: 9, fontWeight: 800, color: '#15803d', textTransform: 'uppercase' }}>Gender</span><br /><strong style={{ color: '#14532d', fontSize: 13 }}>{worker.gender || '—'}</strong></div>
        <div><span style={{ fontSize: 9, fontWeight: 800, color: '#15803d', textTransform: 'uppercase' }}>Designation</span><br /><strong style={{ color: '#14532d', fontSize: 13 }}>{worker.designation || '—'}</strong></div>
        <div style={{ marginLeft: 'auto', background: '#e0f2fe', color: '#0369a1', border: '1px solid #7dd3fc', padding: '4px 12px', borderRadius: 20, fontSize: 11, fontWeight: 800 }}>
          📋 Induction Session
        </div>
      </div>

      {/* The gate reads training AND induction; only the vendor can record the
          former, so say so rather than letting the badge refuse silently. */}
      {readiness && !readiness.training_ok && (
        <InfoBox tone="danger">
          This worker has no completed, unexpired <strong>training</strong> on record. Training is recorded by the vendor in its own portal — the entry badge stays blocked until it is, however this induction is completed.
        </InfoBox>
      )}

      {/* Induction History — one row per session, newest first. */}
      {history.length > 0 && (
        <div style={{ marginBottom: 18, border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden' }}>
          <div style={{ padding: '8px 14px', background: 'var(--bg-input)', fontSize: 12, fontWeight: 800, color: 'var(--text-h)' }}>
            📋 Induction History ({history.length})
          </div>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
              <thead>
                <tr style={{ textAlign: 'left', color: 'var(--text-muted)' }}>
                  <th style={{ padding: '6px 14px', fontWeight: 700 }}>Date</th>
                  <th style={{ padding: '6px 14px', fontWeight: 700 }}>Status</th>
                  <th style={{ padding: '6px 14px', fontWeight: 700 }}>Conducted By</th>
                  <th style={{ padding: '6px 14px', fontWeight: 700 }}>Session</th>
                </tr>
              </thead>
              <tbody>
                {history.map((row, i) => (
                  <tr key={row.id} style={{ borderTop: '1px solid var(--border)', background: i === 0 ? 'rgba(16,185,129,0.06)' : 'transparent' }}>
                    <td style={{ padding: '6px 14px' }}>
                      {row.induction_date ? new Date(row.induction_date).toLocaleDateString() : '—'}
                      {i === 0 && <span style={{ marginLeft: 6, fontSize: 9, fontWeight: 800, color: '#15803d' }}>LATEST</span>}
                    </td>
                    <td style={{ padding: '6px 14px', fontWeight: 700, color: row.status === 'Completed' ? '#15803d' : '#b45309' }}>{row.status || '—'}</td>
                    <td style={{ padding: '6px 14px' }}>{row.conducted_by || '—'}</td>
                    <td style={{ padding: '6px 14px', whiteSpace: 'pre-line' }}>{row.remarks || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Session Details */}
      <h3 style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)', marginBottom: 12, borderBottom: '1px solid var(--border)', paddingBottom: 6 }}>📋 Session Details</h3>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 14, marginBottom: 16 }}>
        <Field label="Induction Type *">
          <SelectInput value={f.induction_type} onChange={set('induction_type')} pairs options={INDUCTION_TYPES} />
        </Field>

        <Field label="Trainer *">
          <select value={f.trainer} onChange={set('trainer')} style={inputStyle}>
            {TRAINER_PRESETS.map(grp => (
              <optgroup key={grp.group} label={grp.group}>
                {grp.items.map(item => <option key={item} value={item}>{item}</option>)}
              </optgroup>
            ))}
          </select>
          {f.trainer === 'Other / Custom Trainer...' && (
            <input type="text" value={f.custom_trainer} onChange={set('custom_trainer')} placeholder="Enter trainer full name..." style={{ ...inputStyle, marginTop: 6 }} />
          )}
        </Field>

        <Field label="Location *">
          <TextInput value={f.location} onChange={set('location')} placeholder="e.g. Site Office, Gate 2, Training Hall" />
        </Field>
      </div>

      {/* Timing Mode Section */}
      <div style={{ padding: 14, borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)', marginBottom: 20 }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 }}>
          <strong style={{ fontSize: 12.5, color: '#0284c7' }}>⏱ Session Timing</strong>
          <div style={{ display: 'flex', gap: 6 }}>
            <button type="button" onClick={() => setF(p => ({ ...p, time_mode: 'auto' }))} style={{ padding: '4px 12px', borderRadius: 6, border: 'none', background: f.time_mode === 'auto' ? '#0284c7' : 'transparent', color: f.time_mode === 'auto' ? '#fff' : 'var(--text-muted)', fontWeight: 800, fontSize: 11, cursor: 'pointer' }}>⚡ Auto (Live)</button>
            <button type="button" onClick={() => setF(p => ({ ...p, time_mode: 'manual' }))} style={{ padding: '4px 12px', borderRadius: 6, border: 'none', background: f.time_mode === 'manual' ? '#0284c7' : 'transparent', color: f.time_mode === 'manual' ? '#fff' : 'var(--text-muted)', fontWeight: 800, fontSize: 11, cursor: 'pointer' }}>⌨ Manual Entry</button>
          </div>
        </div>

        {f.time_mode === 'auto' ? (
          <div style={{ display: 'flex', gap: 14, alignItems: 'center' }}>
            {!sessionActive ? (
              <button type="button" onClick={startSession} style={{ padding: '10px 20px', borderRadius: 8, background: '#10b981', color: '#fff', fontWeight: 900, border: 'none', cursor: 'pointer' }}>▶ Start Live Induction Timer</button>
            ) : (
              <div style={{ display: 'flex', gap: 16, alignItems: 'center' }}>
                <span style={{ fontSize: 12, fontWeight: 700, color: '#10b981' }}>⏱ Session Started: {f.display_start || 'Just now'}</span>
                <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>(End time and duration auto-stamped on save)</span>
              </div>
            )}
          </div>
        ) : (
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr 1fr auto', gap: 10, alignItems: 'flex-end' }}>
            <Field label="Start Date"><TextInput type="date" value={f.m_start_date} onChange={set('m_start_date')} /></Field>
            <Field label="Start Time"><TextInput type="time" value={f.m_start_time} onChange={set('m_start_time')} /></Field>
            <Field label="End Date"><TextInput type="date" value={f.m_end_date} onChange={set('m_end_date')} /></Field>
            <Field label="End Time"><TextInput type="time" value={f.m_end_time} onChange={set('m_end_time')} /></Field>
            <button type="button" onClick={calcManualDuration} style={{ padding: '8px 14px', borderRadius: 8, background: '#0284c7', color: '#fff', border: 'none', cursor: 'pointer', fontWeight: 800, fontSize: 12, height: 38 }}>Calculate Duration</button>
          </div>
        )}
      </div>

      {/* Topics Covered */}
      <h3 style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)', marginBottom: 10 }}>📚 Topics Covered in Session</h3>
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginBottom: 20 }}>
        {INDUCTION_TOPICS.map(t => (
          <button type="button" key={t} onClick={() => toggleTopic(t)} style={{ padding: '6px 14px', borderRadius: 20, border: '1.5px solid', fontSize: 11.5, fontWeight: 800, cursor: 'pointer', background: topics.includes(t) ? '#7c3aed' : 'var(--bg-input)', color: topics.includes(t) ? '#fff' : 'var(--text-muted)', borderColor: topics.includes(t) ? '#7c3aed' : 'var(--border)' }}>
            {topics.includes(t) ? '✓ ' : '+ '}{t}
          </button>
        ))}
      </div>

      {/* Bottom Navigation */}
      <div style={{ marginTop: 24, paddingTop: 16, borderTop: '1px solid var(--border)', display: 'flex', justifyContent: 'flex-end', gap: 12 }}>
        <button type="button" onClick={saveInduction} disabled={saving}
          style={{
            padding: '10px 20px', borderRadius: 10, border: 'none',
            background: 'linear-gradient(135deg, #0284c7, #0369a1)', color: '#fff',
            fontWeight: 800, fontSize: 13, cursor: 'pointer',
            display: 'inline-flex', alignItems: 'center', gap: 6
          }}>
          Save &amp; Continue → Step 4 (PPE)
        </button>
      </div>

      {groupModalOpen && (
        <WizardGroupInductionModal
          workers={vendorWorkers.length > 0 ? vendorWorkers : [worker]}
          onClose={() => setGroupModalOpen(false)}
          onCompleted={() => { setGroupModalOpen(false); onSaved() }}
        />
      )}
    </Panel>
  )
}

/** Newest session first — `inductions` arrives in insertion order. */
function sortInductions(rows) {
  return [...(rows || [])].sort((a, b) => {
    const da = a.induction_date ? new Date(a.induction_date).getTime() : 0
    const db = b.induction_date ? new Date(b.induction_date).getTime() : 0
    return db - da || (b.id - a.id)
  })
}

/**
 * One induction payload, from the session the user just ran.
 *
 * purchase_worker_inductions keeps four fields, so the session detail (type,
 * location, duration, topics) rides in `remarks`. status is 'Completed' because
 * that is the exact string readiness() clears on — a half-finished session must
 * never write it.
 */
function buildInductionPayload({ type, trainer, location, duration, topics }) {
  return {
    induction_date: new Date().toISOString().slice(0, 10),
    status: 'Completed',
    conducted_by: trainer,
    remarks: [
      `Type: ${type}`,
      `Location: ${location}`,
      `Duration: ${duration || 15} min`,
      `Topics: ${topics.length ? topics.join(', ') : '—'}`,
    ].join('\n').slice(0, 2000),
  }
}

/** One session, many workers — the same induction saved against each in turn. */
function WizardGroupInductionModal({ workers, onClose, onCompleted }) {
  const [selectedIds, setSelectedIds] = useState(workers.map(w => w.id))
  const [f, setF] = useState({
    induction_type: 'General Safety',
    trainer: 'Safety Officer – Rahul Sharma',
    custom_trainer: '',
    location: 'Site Office',
  })
  const [topics, setTopics] = useState(['Site Safety Rules', 'PPE Usage', 'Emergency Response'])
  const [saving, setSaving] = useState(false)
  const [progressMsg, setProgressMsg] = useState('')

  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))
  const toggleTopic = (t) => setTopics(p => p.includes(t) ? p.filter(x => x !== t) : [...p, t])

  const activeWorkers = workers.filter(w => selectedIds.includes(w.id))

  const saveGroupInduction = async () => {
    const finalTrainer = f.trainer === 'Other / Custom Trainer...' ? f.custom_trainer : f.trainer
    if (!finalTrainer.trim()) { alert('Trainer Name is required.'); return }
    if (!f.location.trim()) { alert('Location is required.'); return }
    if (activeWorkers.length === 0) { alert('Select at least one worker.'); return }

    setSaving(true)
    try {
      // One request per worker: the endpoint records a single worker's induction,
      // so the loop is the batch — and a failure part-way leaves the workers
      // already saved genuinely inducted rather than rolling them back.
      let count = 0
      for (const w of activeWorkers) {
        count++
        setProgressMsg(`Saving worker ${count}/${activeWorkers.length}: ${w.full_name}...`)
        await purchaseApi.workforce.saveInduction(w.id, buildInductionPayload({
          type: f.induction_type, trainer: finalTrainer, location: f.location, duration: 15, topics,
        }))
      }
      alert(`Group induction completed for ${activeWorkers.length} workers!`)
      onCompleted()
    } catch (e) {
      alert(apiError(e, 'Group induction save failed'))
    } finally {
      setSaving(false)
      setProgressMsg('')
    }
  }

  return (
    <Overlay onClose={() => !saving && onClose()} width={820}>
      <h2 style={{ fontSize: 18, fontWeight: 900, color: 'var(--text-h)', margin: '0 0 14px' }}>
        👥 Group Induction Session ({activeWorkers.length} Selected)
      </h2>

      {/* Worker Checkbox Selector Strip */}
      <div style={{ padding: 10, borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)', marginBottom: 14, display: 'flex', flexWrap: 'wrap', gap: 10, maxHeight: 100, overflowY: 'auto' }}>
        {workers.map(w => (
          <label key={w.id} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '4px 10px', borderRadius: 20, background: selectedIds.includes(w.id) ? '#e0f2fe' : 'var(--bg-card)', border: '1px solid var(--border)', fontSize: 11.5, cursor: 'pointer', fontWeight: selectedIds.includes(w.id) ? 800 : 500 }}>
            <input type="checkbox" checked={selectedIds.includes(w.id)} onChange={e => {
              const checked = e.target.checked
              setSelectedIds(p => checked ? [...p, w.id] : p.filter(x => x !== w.id))
            }} style={{ width: 14, height: 14 }} />
            {w.full_name} ({w.worker_code})
          </label>
        ))}
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 12, marginBottom: 14 }}>
        <Field label="Induction Type *">
          <SelectInput value={f.induction_type} onChange={set('induction_type')} pairs options={INDUCTION_TYPES} />
        </Field>
        <Field label="Trainer *">
          <select value={f.trainer} onChange={set('trainer')} style={inputStyle}>
            {TRAINER_PRESETS.map(grp => (
              <optgroup key={grp.group} label={grp.group}>
                {grp.items.map(item => <option key={item} value={item}>{item}</option>)}
              </optgroup>
            ))}
          </select>
          {f.trainer === 'Other / Custom Trainer...' && (
            <input type="text" value={f.custom_trainer} onChange={set('custom_trainer')} placeholder="Enter trainer full name..." style={{ ...inputStyle, marginTop: 6 }} />
          )}
        </Field>
        <Field label="Location *"><TextInput value={f.location} onChange={set('location')} placeholder="e.g. Site Office" /></Field>
      </div>

      <h3 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 8px' }}>📚 Topics Covered</h3>
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginBottom: 14 }}>
        {INDUCTION_TOPICS.map(t => (
          <button type="button" key={t} onClick={() => toggleTopic(t)} style={{ padding: '5px 12px', borderRadius: 20, border: '1.5px solid', fontSize: 11, fontWeight: 800, cursor: 'pointer', background: topics.includes(t) ? '#7c3aed' : 'var(--bg-input)', color: topics.includes(t) ? '#fff' : 'var(--text-muted)', borderColor: topics.includes(t) ? '#7c3aed' : 'var(--border)' }}>
            {topics.includes(t) ? '✓ ' : '+ '}{t}
          </button>
        ))}
      </div>

      {progressMsg && <div style={{ padding: '6px 12px', background: '#e0f2fe', color: '#0369a1', fontSize: 12, fontWeight: 700, marginBottom: 10 }}>⏳ {progressMsg}</div>}
      <ModalFooter onClose={onClose} onConfirm={saveGroupInduction} loading={saving} disabled={activeWorkers.length === 0} confirmLabel={`Save Group Induction (${activeWorkers.length} Workers)`} />
    </Overlay>
  )
}

// ── Step 4 — PPE, issued from Inventory ──────────────────────────────────────
/**
 * The worker's kit, read from the central PPE ledger.
 *
 * Nothing here computes a quantity or a status: the issues, what is still held
 * and the compliance verdict all come from PurchasePpeService, which moves real
 * Inventory stock. Issuing is done by the vendor from its own portal — the admin
 * surface only reads the kit and records what comes back.
 *
 * Returns are gated on `manage`, not on the profile being editable: kit comes
 * back at the gate long after the worker was badged, which is exactly when the
 * profile has stopped being a draft.
 */
function StepPpe({ worker, manage, onChanged, onNext }) {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [acting, setActing] = useState(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)

  const load = useCallback(() => {
    setLoading(true)
    purchaseApi.workforce.ppe(worker.id)
      .then(setData)
      .catch(() => setData(null))
      .finally(() => setLoading(false))
  }, [worker.id])
  useEffect(() => { load() }, [load])

  const giveBack = async (issueId, condition, qty) => {
    setBusy(true); setErr(null)
    try {
      await purchaseApi.workforce.returnPpe(issueId, { condition, qty })
      setActing(null)
      load()
      // Handing everything back drops the worker out of "PPE issued".
      onChanged()
    } catch (e) {
      setErr(apiError(e, 'Could not record the return.'))
    } finally { setBusy(false) }
  }

  const issues = data?.issues ?? []
  const compliance = data?.compliance ?? null
  // current_step 4 is written by the PPE service when a kit is issued — the same
  // flag activateBadge() checks, so the banner cannot flatter the gate.
  const issued = Number(worker.current_step || 0) >= 4 || (compliance?.held_count ?? 0) > 0

  const banner = issued
    ? { bg: '#dcfce7', border: '#86efac', pill: '#10b981', label: '✓ PPE Issued' }
    : { bg: '#e0f2fe', border: '#7dd3fc', pill: '#0284c7', label: 'Pending PPE' }

  return (
    <Panel title="Step 4 — Statutory PPE Kit" sub="Issuance and returns, read from and written to Inventory"
      actions={
        <button type="button" onClick={onNext}
          style={{
            padding: '8px 16px', borderRadius: 9, border: 'none',
            background: 'linear-gradient(135deg, #0284c7, #0369a1)', color: '#fff',
            fontWeight: 800, fontSize: 12.5, cursor: 'pointer',
            display: 'inline-flex', alignItems: 'center', gap: 6,
          }}>
          Continue → Step 5 (Card Status)
        </button>
      }>

      <div style={{ padding: '12px 18px', borderRadius: 10, background: banner.bg, border: `1.5px solid ${banner.border}`, marginBottom: 18, display: 'flex', flexWrap: 'wrap', gap: 18, alignItems: 'center' }}>
        <div><span style={{ fontSize: 9, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase' }}>Worker</span><br /><strong style={{ color: 'var(--text-h)', fontSize: 13 }}>{worker.full_name} ({worker.worker_code || '—'})</strong></div>
        <div><span style={{ fontSize: 9, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase' }}>Designation</span><br /><strong style={{ color: 'var(--text-h)', fontSize: 13 }}>{worker.designation || 'Worker'}</strong></div>
        <div style={{ marginLeft: 'auto' }}>
          <span style={{ fontSize: 11, fontWeight: 800, padding: '4px 12px', borderRadius: 20, background: banner.pill, color: '#fff' }}>{banner.label}</span>
        </div>
      </div>

      {err && <InfoBox tone="danger">{err}</InfoBox>}

      {/* What the worker is currently holding — the list the gate's PPE check reads. */}
      {compliance && (
        <div style={{ padding: '12px 16px', borderRadius: 10, marginBottom: 18, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <p style={{ margin: '0 0 8px', fontSize: 10.5, fontWeight: 800, letterSpacing: '.04em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>
            Kit currently held{compliance.designation ? ` · ${compliance.designation}` : ''}
          </p>
          {compliance.items?.length ? (
            <ul style={{ margin: 0, padding: 0, listStyle: 'none', display: 'grid', gap: 5 }}>
              {compliance.items.map(i => (
                <li key={i.issue_id} style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12.5, fontWeight: 600, color: '#0ca30c' }}>
                  <span style={{ fontWeight: 900 }}>✓</span>
                  <span style={{ color: 'var(--text-h)' }}>{i.name}</span>
                  {i.qty > 1 && <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>×{i.qty}</span>}
                  {i.size && <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>· {i.size}</span>}
                  {i.issued_on && <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>· {fmtDate(i.issued_on)}</span>}
                </li>
              ))}
            </ul>
          ) : (
            <p style={{ margin: 0, fontSize: 12.5, fontWeight: 600, color: '#d03b3b' }}>
              ✗ Nothing issued — the gate refuses, or warns, on a worker with no PPE.
            </p>
          )}
        </div>
      )}

      {/* Every hand-out, with the partial return / lost / damaged actions. */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
        <HardHat size={15} style={{ color: '#f59e0b' }} />
        <h3 style={{ margin: 0, fontSize: 14, fontWeight: 700, color: 'var(--text-h)' }}>Assigned PPE</h3>
      </div>

      {loading ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>Loading PPE…</p>
      ) : issues.length === 0 ? (
        <p style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>No PPE issued to this worker yet.</p>
      ) : (
        <div style={{ borderRadius: 12, border: '1px solid var(--border)', overflow: 'hidden' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
            <thead>
              <tr style={{ background: 'var(--bg-input)' }}>
                {['Item', 'Qty', 'Outstanding', 'Issue Date', 'Status', ''].map(h => (
                  <th key={h} style={{ padding: '8px 12px', textAlign: 'left', fontSize: 9.5, fontWeight: 700, letterSpacing: '.05em', textTransform: 'uppercase', color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {issues.map(r => {
                const outstanding = Number(r.qty || 0) - Number(r.returned_qty || 0)
                // A part-returned line is still 'issued' server-side; label it honestly.
                const partial = r.status === 'issued' && Number(r.returned_qty || 0) > 0
                const cfg = partial ? PPE_TONE.partial : (PPE_TONE[r.status] || PPE_TONE.issued)
                return (
                  <tr key={r.id} style={{ borderTop: '1px solid var(--border)' }}>
                    <td style={{ padding: '9px 12px', fontWeight: 650, color: 'var(--text-h)' }}>
                      {r.item}{r.product?.sku && <span style={{ color: 'var(--text-muted)', fontWeight: 400 }}> · {r.product.sku}</span>}
                    </td>
                    <td style={{ padding: '9px 12px', fontVariantNumeric: 'tabular-nums', color: 'var(--text-muted)' }}>
                      {r.qty}{Number(r.returned_qty || 0) > 0 && <span> ({r.returned_qty} back)</span>}
                    </td>
                    <td style={{ padding: '9px 12px', fontVariantNumeric: 'tabular-nums', color: 'var(--text-muted)' }}>{outstanding}</td>
                    <td style={{ padding: '9px 12px', color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{r.issued_date ? fmtDate(r.issued_date) : '—'}</td>
                    <td style={{ padding: '9px 12px' }}>
                      <span style={{ fontSize: 10.5, fontWeight: 700, padding: '3px 8px', borderRadius: 7, color: cfg.tone, background: `${cfg.tone}1f`, whiteSpace: 'nowrap' }}>{cfg.label}</span>
                    </td>
                    <td style={{ padding: '9px 12px', textAlign: 'right' }}>
                      {manage && outstanding > 0 && (
                        <button onClick={() => { setErr(null); setActing({ row: r, outstanding }) }}
                          style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '5px 10px', borderRadius: 7, border: '1px solid var(--border)', background: 'transparent', color: 'var(--text-muted)', fontSize: 11.5, fontWeight: 700, cursor: 'pointer' }}>
                          Return
                        </button>
                      )}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '12px 0 0', lineHeight: 1.6 }}>
        A genuine return puts stock back in the warehouse it left. Lost and damaged do not —
        those items already left inventory when they were issued. New kit is issued by the vendor
        from its own portal, so the ledger has a single writer.
      </p>

      {acting && (
        <PpeReturnDialog {...acting} busy={busy} onClose={() => setActing(null)} onConfirm={giveBack} />
      )}
    </Panel>
  )
}

const PPE_TONE = {
  issued:   { label: 'Issued',         tone: '#fab219' },
  returned: { label: 'Returned',       tone: '#0ca30c' },
  lost:     { label: 'Lost',           tone: '#d03b3b' },
  damaged:  { label: 'Damaged',        tone: '#ec835a' },
  partial:  { label: 'Partial Return', tone: '#2a78d6' },
}

/** Return / lost / damaged, with a quantity so partial returns are possible. */
function PpeReturnDialog({ row, outstanding, busy, onClose, onConfirm }) {
  const [qty, setQty] = useState(outstanding)
  const [condition, setCondition] = useState('returned')

  const n = Number(qty) || 0
  const valid = n > 0 && n <= outstanding

  return (
    <Overlay onClose={onClose} width={400}>
      <h3 style={{ margin: 0, fontSize: 15.5, fontWeight: 800, color: 'var(--text-h)', paddingRight: 24 }}>Return {row.item}</h3>
      <p style={{ margin: '4px 0 14px', fontSize: 12, color: 'var(--text-muted)' }}>{outstanding} outstanding</p>

      <Field label="Quantity">
        <TextInput type="number" min="1" max={outstanding} value={qty} onChange={e => setQty(e.target.value)} />
      </Field>
      <div style={{ height: 12 }} />
      <Field label="Condition">
        <SelectInput value={condition} onChange={e => setCondition(e.target.value)} pairs options={[
          ['returned', 'Returned — back into stock'],
          ['damaged', 'Damaged — written off'],
          ['lost', 'Lost — written off'],
        ]} />
      </Field>

      <p style={{ margin: '10px 0 0', fontSize: 11.5, color: 'var(--text-muted)', lineHeight: 1.5 }}>
        {condition === 'returned'
          ? 'Stock returns to Inventory immediately.'
          : 'These items left Inventory when they were issued, so stock does not change — the write-off is recorded against this issue.'}
      </p>

      <ModalFooter onClose={onClose} onConfirm={() => onConfirm(row.id, condition, n)} loading={busy} disabled={!valid} confirmLabel="Confirm" color="#f59e0b" />
    </Overlay>
  )
}

// ── Step 5 — Access Control 3D Pass & Card Status ────────────────────────────
function StepBadge({ worker, badge, progress, admin, onChanged }) {
  const [isFlipped, setIsFlipped] = useState(false)

  const [editModalOpen, setEditModalOpen] = useState(false)
  const [editData, setEditData] = useState({
    full_name: worker.full_name || '',
    designation: worker.designation || '',
    phone: worker.phone || '',
    email: worker.email || '',
    gender: worker.gender || '',
  })
  const [savingEdit, setSavingEdit] = useState(false)

  // ── Real status-driven state ──
  const isActive     = worker.status === WORKER_STATUS.ACTIVE
  const isSuspended  = worker.status === WORKER_STATUS.SUSPENDED
  const isTerminated = worker.status === WORKER_STATUS.TERMINATED
  const hasBadge     = !!(badge?.activated || worker.badge_number)
  const blockers     = progress?.blockers || []
  const canActivate  = admin && !hasBadge && blockers.length === 0

  // Real badge fields from the server (no hardcoded validity).
  const validTo    = worker.badge_valid_until ? fmtDate(worker.badge_valid_until) : '—'
  const badgeNo    = worker.badge_number || badge?.badge_number || '—'
  const workerCode = worker.worker_code || `PW-${String(worker.id).padStart(5, '0')}`
  const age        = ageOf(worker.dob)
  // Blood group lives on the medical record, not the worker row.
  const bloodGroup = sortMedicals(worker.medicals)[0]?.blood_group || '-'
  const isPpeIssued = Number(worker.current_step || 0) >= 4

  // ── Badge issuance (admin) ──
  const [activating, setActivating] = useState(false)
  const [validUntil, setValidUntil] = useState('')

  const handleActivate = async () => {
    setActivating(true)
    try {
      await purchaseApi.workforce.activate(worker.id, validUntil ? { valid_until: validUntil } : {})
      onChanged()
    } catch (e) {
      alert(apiError(e, 'Could not issue the entry badge.'))
    } finally {
      setActivating(false)
    }
  }

  // ── Lifecycle (admin). Purchase has no punch ladder: status alone gates the
  // gate, so suspending or terminating withholds entry immediately. ──
  const [busy, setBusy] = useState(false)
  const suspendWorker = async () => {
    const reason = window.prompt('Reason for suspending this worker (optional):') ?? ''
    setBusy(true)
    try { await purchaseApi.workforce.suspend(worker.id, reason.trim() || null); onChanged() }
    catch (e) { alert(apiError(e, 'Could not suspend this worker.')) }
    finally { setBusy(false) }
  }
  const reinstateWorker = async () => {
    setBusy(true)
    try { await purchaseApi.workforce.reinstate(worker.id); onChanged() }
    catch (e) { alert(apiError(e, 'Could not reinstate this worker.')) }
    finally { setBusy(false) }
  }
  const terminateWorker = async () => {
    if (!window.confirm('Terminate this worker? This is permanent and stops their badge scanning at the gate.')) return
    const reason = window.prompt('Reason for termination (optional):') ?? ''
    setBusy(true)
    try { await purchaseApi.workforce.terminate(worker.id, reason.trim() || null); onChanged() }
    catch (e) { alert(apiError(e, 'Could not terminate this worker.')) }
    finally { setBusy(false) }
  }

  const handleSaveEdit = async () => {
    setSavingEdit(true)
    try {
      await purchaseApi.workforce.updateWorker(worker.id, editData)
      setEditModalOpen(false)
      onChanged()
    } catch (e) {
      alert(apiError(e, 'Failed to update worker info'))
    } finally {
      setSavingEdit(false)
    }
  }

  // The lifecycle stamps its reasons onto `notes`, dated — that IS the worker's
  // activity trail, so it is read rather than reconstructed.
  const activityLog = String(worker.notes || '').trim().split('\n').filter(Boolean).reverse()

  const printCard = () => {
    const win = window.open('', '_blank', 'width=600,height=700')
    win.document.write(`
      <html>
        <head><title>Print Access Pass - ${worker.full_name}</title></head>
        <body style="font-family:sans-serif; text-align:center; padding:30px; background:#0b1622; color:#fff;">
          <h2 style="color:#d4af37;">HSSE ACCESS CONTROL CARD</h2>
          <div style="border:2px solid #d4af37; border-radius:16px; padding:20px; max-width:380px; margin:0 auto; background:#162740;">
            <h3 style="margin:0 0 6px;">${worker.full_name}</h3>
            <p style="color:#00d4ff; font-weight:bold; margin:0 0 4px;">${worker.designation || 'Worker'} (${workerCode})</p>
            <p style="color:#a78bfa; font-size:12px; margin:0 0 12px;">Badge: ${badgeNo}</p>
            <p style="font-size:12px; color:#a78bfa; margin-top:12px;">VALID UNTIL: ${validTo}</p>
          </div>
          <script>window.onload = function() { window.print(); window.close(); }<\/script>
        </body>
      </html>
    `)
  }

  const sendWhatsApp = () => {
    const text = `🪪 *HSSE ACCESS CONTROL PASS*\n\n`
      + `*Worker Name:* ${worker.full_name}\n`
      + `*Worker Code:* ${workerCode}\n`
      + `*Badge No:* ${badgeNo}\n`
      + `*Designation:* ${worker.designation || 'Worker'}\n`
      + `*Valid Until:* ${validTo}\n`
      + `*Status:* ${isTerminated ? '⛔ Terminated (Access Revoked)' : isSuspended ? '⏸ Suspended' : '✓ Active Access'}`
    window.open(`https://wa.me/?text=${encodeURIComponent(text)}`, '_blank')
  }

  // ── No badge yet: show the issuance panel, not a fake pass ──
  if (!hasBadge) {
    return (
      <Panel title="Step 5 — Entry Badge" sub="Issue the site-access badge once every readiness gate is cleared">
        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 14, padding: '20px 16px', textAlign: 'center' }}>
          <div style={{ width: 60, height: 60, borderRadius: 18, display: 'flex', alignItems: 'center', justifyContent: 'center', background: canActivate ? 'linear-gradient(145deg,#10b981,#059669)' : 'var(--bg-input)', color: canActivate ? '#fff' : 'var(--text-muted)' }}>
            <QrCode size={28} />
          </div>
          {canActivate ? (
            <>
              <div style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Ready to issue the entry badge</div>
              <p style={{ fontSize: 12.5, color: 'var(--text-muted)', maxWidth: 420, margin: 0, lineHeight: 1.6 }}>
                All readiness gates are cleared. Issuing the badge activates the worker for site access and generates their gate credential.
              </p>
              <div style={{ width: 220 }}>
                <Field label="Badge Valid Until (optional)">
                  <TextInput type="date" value={validUntil} onChange={e => setValidUntil(e.target.value)} />
                </Field>
              </div>
              <button type="button" onClick={handleActivate} disabled={activating}
                style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '11px 22px', borderRadius: 11, background: 'linear-gradient(135deg,#10b981,#059669)', color: '#fff', border: 'none', fontWeight: 800, fontSize: 13.5, cursor: activating ? 'wait' : 'pointer', opacity: activating ? 0.7 : 1 }}>
                {activating ? <Loader size={16} className="rfq-spin" /> : <ShieldCheck size={16} />}
                {activating ? 'Issuing badge…' : 'Issue Entry Badge'}
              </button>
            </>
          ) : admin ? (
            <>
              <div style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Badge cannot be issued yet</div>
              <p style={{ fontSize: 12.5, color: 'var(--text-muted)', maxWidth: 420, margin: 0, lineHeight: 1.6 }}>
                Resolve the {blockers.length} blocking item{blockers.length === 1 ? '' : 's'} listed at the top of this page (documents, medical, training, induction, competency, PPE) before the entry badge can be issued.
              </p>
            </>
          ) : (
            <>
              {/* Activation is role:admin server-side — staff review, they do not admit. */}
              <div style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Awaiting badge issuance</div>
              <p style={{ fontSize: 12.5, color: 'var(--text-muted)', maxWidth: 420, margin: 0, lineHeight: 1.6 }}>
                {blockers.length > 0
                  ? `Complete the ${blockers.length} remaining item${blockers.length === 1 ? '' : 's'} above. Once ready, your site administrator will issue the entry badge.`
                  : 'All steps are complete. Your site administrator will now issue the entry badge for this worker.'}
              </p>
            </>
          )}
        </div>
      </Panel>
    )
  }

  return (
    <Panel title="Step 5 — Access Control Pass & Credentials" sub="Site entry pass · badge validity · worker lifecycle"
      actions={
        <div style={{ display: 'flex', gap: 8 }}>
          <button type="button" onClick={sendWhatsApp} style={{ padding: '7px 12px', borderRadius: 8, background: '#10b981', color: '#fff', border: 'none', cursor: 'pointer', fontWeight: 800, fontSize: 11.5, display: 'inline-flex', alignItems: 'center', gap: 5 }}>
            💬 Send WhatsApp Pass
          </button>
          <button type="button" onClick={printCard} style={{ padding: '7px 12px', borderRadius: 8, background: 'var(--bg-card)', color: 'var(--text-h)', border: '1px solid var(--border)', cursor: 'pointer', fontWeight: 800, fontSize: 11.5, display: 'inline-flex', alignItems: 'center', gap: 5 }}>
            🖨️ Print Pass
          </button>
        </div>
      }>

      {/* 3D Card Scene */}
      <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 16, marginBottom: 24 }}>
        <div onClick={() => setIsFlipped(!isFlipped)}
          style={{
            width: 430, height: 272, perspective: 1100, cursor: 'pointer', position: 'relative', userSelect: 'none'
          }}>
          <div style={{
            width: '100%', height: '100%', position: 'relative', transformStyle: 'preserve-3d', transition: 'transform 0.72s cubic-bezier(0.23,1,0.32,1)',
            transform: isFlipped ? 'rotateY(180deg)' : 'rotateY(0deg)'
          }}>

            {/* FRONT FACE */}
            <div style={{
              position: 'absolute', width: '100%', height: '100%', borderRadius: 20, backfaceVisibility: 'hidden', overflow: 'hidden',
              background: 'linear-gradient(145deg, #0d1b2a 0%, #162740 55%, #1c3355 100%)',
              boxShadow: '0 30px 70px rgba(0,0,0,0.6), 0 0 0 1px rgba(212,175,55,0.28)', padding: 16
            }}>

              {/* Terminated Overlay Stamp */}
              {isTerminated && (
                <div style={{ position: 'absolute', inset: 0, borderRadius: 20, background: 'rgba(0,0,0,0.72)', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', zIndex: 10, backdropFilter: 'blur(2px)' }}>
                  <div style={{ border: '4px solid #dc2626', borderRadius: 8, padding: '8px 20px', color: '#dc2626', fontFamily: 'sans-serif', fontSize: 24, fontWeight: 900, letterSpacing: 6, transform: 'rotate(-12deg)', boxShadow: '0 0 28px rgba(220,38,38,0.5)' }}>TERMINATED</div>
                  <div style={{ marginTop: 8, color: 'rgba(255,255,255,0.6)', fontSize: 10, letterSpacing: 2, fontFamily: 'monospace' }}>ACCESS REVOKED — DO NOT ALLOW ENTRY</div>
                </div>
              )}

              {/* Top Header Strip */}
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 12 }}>
                <div>
                  <div style={{ fontFamily: 'sans-serif', fontWeight: 900, fontSize: 13, color: '#d4af37', letterSpacing: 1.5, textTransform: 'uppercase' }}>HSSE ACCESS PASS</div>
                  <div style={{ fontSize: 8, color: 'rgba(255,255,255,0.4)', letterSpacing: 2 }}>SITE ENTRY CONTROL CARD</div>
                </div>

                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  {/* activateBadge() refuses below current_step 4, so a badged
                      worker was equipped — read the flag rather than assume it. */}
                  <span style={{ fontSize: 9, fontWeight: 800, padding: '3px 8px', borderRadius: 4, background: isPpeIssued ? 'rgba(34,197,94,0.3)' : 'rgba(239,68,68,0.3)', color: isPpeIssued ? '#4ade80' : '#fca5a5', border: `1.5px solid ${isPpeIssued ? '#22c55e' : '#ef4444'}` }}>
                    {isPpeIssued ? '✓ PPE ISSUED' : '✕ NO PPE'}
                  </span>
                  <div style={{ width: 36, height: 26, background: 'linear-gradient(135deg,#e8c96a,#c9a53e)', borderRadius: 4, boxShadow: '0 2px 8px rgba(0,0,0,0.4)' }}></div>
                </div>
              </div>

              {/* Mid Info Section */}
              <div style={{ display: 'flex', gap: 14, alignItems: 'flex-start', marginBottom: 10 }}>
                <div style={{ width: 64, height: 72, borderRadius: 10, border: '2px solid rgba(212,175,55,0.45)', background: 'linear-gradient(145deg,#1a2e45,#203756)', overflow: 'hidden', flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'rgba(255,255,255,0.3)', fontSize: 24 }}>
                  {worker.photo_url ? <img src={worker.photo_url} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} /> : '👤'}
                </div>

                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontWeight: 900, fontSize: 18, color: '#fff', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', marginBottom: 2 }}>{worker.full_name}</div>
                  <div style={{ fontSize: 10, color: '#00d4ff', fontWeight: 800, letterSpacing: 1.5, textTransform: 'uppercase', marginBottom: 4 }}>{worker.designation || 'Site Worker'}</div>
                  <div style={{ fontFamily: 'monospace', fontSize: 11, color: '#d4af37', letterSpacing: 1 }}>{workerCode} · {badgeNo}</div>

                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: '2px 8px', marginTop: 4, fontSize: 9, color: 'rgba(255,255,255,0.4)' }}>
                    <div>DOB <strong style={{ color: '#fff' }}>{worker.dob ? fmtDate(worker.dob) : '-'}</strong></div>
                    <div>Age <strong style={{ color: '#fff' }}>{age != null ? age : '-'}</strong></div>
                    <div>Gender <strong style={{ color: '#fff' }}>{worker.gender || '-'}</strong></div>
                    <div>Blood <strong style={{ color: '#fff' }}>{bloodGroup}</strong></div>
                    <div style={{ gridColumn: 'span 2' }}>Valid To <strong style={{ color: '#fff' }}>{validTo}</strong></div>
                  </div>
                </div>
              </div>

              {/* Status Banner — driven by the real worker status */}
              {(() => {
                const tone = isTerminated ? ['rgba(220,38,38,0.15)', '#dc2626', 'rgba(220,38,38,0.4)']
                  : isSuspended ? ['rgba(245,158,11,0.15)', '#f59e0b', 'rgba(245,158,11,0.4)']
                  : ['rgba(34,197,94,0.15)', '#22c55e', 'rgba(34,197,94,0.4)']
                const label = isTerminated ? '⛔ Access Terminated' : isSuspended ? '⏸ Access Suspended' : '✓ Access Active'
                return (
                  <div style={{ padding: '4px 0', borderRadius: 6, textAlign: 'center', fontSize: 9.5, fontWeight: 900, letterSpacing: 1.5, textTransform: 'uppercase', background: tone[0], color: tone[1], border: `1px solid ${tone[2]}` }}>
                    {label}
                  </div>
                )
              })()}
            </div>

            {/* BACK FACE — the gate credential itself is never handed to a browser
                (qr_token is hidden on the model), so the back carries the badge
                identifiers rather than a QR the gate could not resolve. */}
            <div style={{
              position: 'absolute', width: '100%', height: '100%', borderRadius: 20, backfaceVisibility: 'hidden', overflow: 'hidden',
              background: 'linear-gradient(145deg, #07101a 0%, #0e1b2b 100%)', transform: 'rotateY(180deg)',
              boxShadow: '0 30px 70px rgba(0,0,0,0.6), 0 0 0 1px rgba(212,175,55,0.18)',
              display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 6, padding: 16
            }}>
              <div style={{ fontSize: 10, fontWeight: 700, color: 'rgba(255,255,255,0.4)', letterSpacing: 2, textTransform: 'uppercase' }}>Present this pass at the gate</div>
              <div style={{ width: 136, height: 136, borderRadius: 10, border: '1px dashed rgba(255,255,255,0.25)', display: 'flex', alignItems: 'center', justifyContent: 'center', textAlign: 'center', padding: 10, fontSize: 9.5, color: 'rgba(255,255,255,0.45)', lineHeight: 1.5 }}>
                The entry QR credential is held by the gate system and is never exposed to this screen
              </div>
              <div style={{ fontFamily: 'monospace', fontSize: 12, color: '#d4af37', letterSpacing: 2 }}>{badgeNo}</div>
              <div style={{ fontSize: 8.5, color: 'rgba(255,255,255,0.3)', letterSpacing: 1 }}>VALID UNTIL {validTo}</div>
            </div>

          </div>
        </div>

        <div style={{ fontSize: 11.5, color: 'var(--text-muted)', display: 'flex', alignItems: 'center', gap: 5 }}>
          🔄 Click card to flip and view badge credentials
        </div>
      </div>

      {/* Pass Actions Bar */}
      <div style={{ display: 'flex', gap: 10, justifyContent: 'center', marginBottom: 24, flexWrap: 'wrap' }}>
        {admin && !isTerminated && (
          <>
            {isActive && (
              <button type="button" onClick={suspendWorker} disabled={busy}
                style={{ padding: '8px 18px', borderRadius: 9, background: '#f59e0b', color: '#fff', border: 'none', cursor: 'pointer', fontWeight: 800, fontSize: 12, display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                ⏸ Suspend Access
              </button>
            )}
            {isSuspended && (
              <button type="button" onClick={reinstateWorker} disabled={busy}
                style={{ padding: '8px 18px', borderRadius: 9, background: '#10b981', color: '#fff', border: 'none', cursor: 'pointer', fontWeight: 800, fontSize: 12, display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                ▶ Reinstate Access
              </button>
            )}
            <button type="button" onClick={terminateWorker} disabled={busy}
              style={{ padding: '8px 18px', borderRadius: 9, background: '#ef4444', color: '#fff', border: 'none', cursor: 'pointer', fontWeight: 800, fontSize: 12, display: 'inline-flex', alignItems: 'center', gap: 6 }}>
              ⛔ Terminate
            </button>
          </>
        )}
        {isTerminated && (
          <span style={{ padding: '8px 18px', borderRadius: 9, background: '#ef4444', color: '#fff', fontWeight: 900, fontSize: 12 }}>
            ⛔ Access Revoked (Terminated)
          </span>
        )}

        <button type="button" onClick={() => setEditModalOpen(true)}
          style={{ padding: '8px 16px', borderRadius: 9, background: 'var(--bg-input)', color: 'var(--text-h)', border: '1px solid var(--border)', cursor: 'pointer', fontWeight: 800, fontSize: 12, display: 'inline-flex', alignItems: 'center', gap: 6 }}>
          ✏ Edit Worker Info
        </button>
      </div>

      {/* Activity Log */}
      <div style={{ padding: 16, borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
        <h4 style={{ fontSize: 12, fontWeight: 900, color: 'var(--text-h)', textTransform: 'uppercase', letterSpacing: 1.5, margin: '0 0 10px', display: 'flex', alignItems: 'center', gap: 6 }}>
          📜 Worker Activity Log
        </h4>

        {activityLog.length === 0 ? (
          <div style={{ fontSize: 12, color: 'var(--text-muted)', fontStyle: 'italic' }}>No lifecycle events recorded — clean record ✓</div>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            {activityLog.map((line, idx) => (
              <div key={idx} style={{ padding: '8px 12px', borderRadius: 8, background: 'var(--bg-card)', border: '1px solid var(--border)', fontSize: 12, color: 'var(--text-h)' }}>
                {line}
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Edit Worker Modal */}
      {editModalOpen && (
        <Overlay onClose={() => setEditModalOpen(false)} width={460}>
          <h3 style={{ fontSize: 16, fontWeight: 900, color: 'var(--text-h)', margin: '0 0 14px' }}>✏ Edit Worker Details</h3>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            <Field label="Full Name *">
              <TextInput value={editData.full_name} onChange={e => setEditData({ ...editData, full_name: e.target.value })} />
            </Field>
            <Field label="Designation">
              <TextInput value={editData.designation} onChange={e => setEditData({ ...editData, designation: e.target.value })} />
            </Field>
            <Field label="Mobile Number">
              <TextInput value={editData.phone} onChange={e => setEditData({ ...editData, phone: e.target.value })} />
            </Field>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
              <Field label="Email">
                <TextInput type="email" value={editData.email} onChange={e => setEditData({ ...editData, email: e.target.value })} />
              </Field>
              <Field label="Gender">
                <SelectInput value={editData.gender} onChange={e => setEditData({ ...editData, gender: e.target.value })} options={['', ...GENDERS]} />
              </Field>
            </div>
          </div>
          <ModalFooter onClose={() => setEditModalOpen(false)} onConfirm={handleSaveEdit} loading={savingEdit} confirmLabel="Save Changes" />
        </Overlay>
      )}
    </Panel>
  )
}
