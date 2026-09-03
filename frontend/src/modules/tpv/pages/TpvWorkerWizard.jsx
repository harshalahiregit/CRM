import { useState, useEffect, useCallback, useRef } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import QRCode from 'qrcode'
import {
  ArrowLeft, RefreshCw, UserCheck, HeartPulse, GraduationCap, HardHat, QrCode,
  Check, AlertTriangle, ShieldCheck, Ban, RotateCcw, Trash2, Plus, Loader, Printer,
} from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { portalApi } from '@/services/portalApi'
import { useAuth } from '@/context/AuthContext'
import AuditTimeline from '@/components/ui/AuditTimeline'
import {
  WORKER_STATUS, workerStatusCfg, vendorStatusCfg, fitnessCfg, FITNESS, BAND_COLORS,
  SKILL_CATEGORIES, GENDERS,
  isWorkerEditable, canApproveTpv, canManageTpv, fmtDate,
} from '../constants'
import PpeCatalogue from '@/components/vendor/PpeCatalogue'
import WorkerPpePanel from '@/components/vendor/WorkerPpePanel'
import {
  KIT3D_STYLE, labelStyle, inputStyle, Overlay, ModalFooter, InfoBox,
  Field, TextInput, SelectInput, StatusBadge as StatusPill,
} from '@/components/ui/kit3d'

const STEP_ICONS  = { profile: UserCheck, medical: HeartPulse, induction: GraduationCap, ppe: HardHat, badge: QrCode }
const STEP_COLORS = { profile: '#0ea5e9', medical: '#ec4899', induction: '#8b5cf6', ppe: '#f59e0b', badge: '#10b981' }

// ── Main ─────────────────────────────────────────────────────────────────────
export default function TpvWorkerWizard() {
  const { id, vendorId } = useParams()       // vendorId present inside the admin workspace
  const navigate = useNavigate()
  const { user } = useAuth()
  const admin  = canApproveTpv(user)
  const manage = canManageTpv(user)
  // Portal vendors use portalApi; back-link resolves from role, not vendorId.
  const isPortal = user?.role === 'third_party_vendor'
  const api = isPortal ? portalApi : tpvApi
  const backHref = isPortal
    ? '/vendor-portal/workforce/workers'
    : (vendorId ? `/app/tpv/workforce/vendor/${vendorId}/workers` : '/app/tpv/workforce')

  const [worker, setWorker]     = useState(null)
  const [progress, setProgress] = useState(null)
  const [loading, setLoading]   = useState(true)
  const [active, setActive]     = useState(1)

  const load = useCallback(async (keepStep = false) => {
    try {
      const res = await api.workers.get(id)
      const w = res?.worker ?? res?.data?.worker
      const p = res?.progress ?? res?.data?.progress
      setWorker(w); setProgress(p)
      if (!keepStep) setActive(w?.current_step || 1)
    } catch (e) { console.error('Failed to load worker', e) }
    finally { setLoading(false) }
  }, [id, api])
  useEffect(() => { load() }, [load])
  const refresh = () => load(true)

  if (loading || !worker || !progress) {
    return <div style={{ padding: 24, color: 'var(--text-muted)' }}>Loading worker…</div>
  }

  const editable = isWorkerEditable(worker.status)
  const steps = progress.steps || []

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
            {worker.name} <span style={{ color: '#a78bfa', fontSize: 13, fontWeight: 700 }}>{worker.worker_code}</span>
          </h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '3px 0 0' }}>
            {worker.designation || 'Worker'}
            {worker.age != null && ` · ${worker.age} yrs`}
            {worker.vendor?.company_name && ` · ${worker.vendor.company_name}`}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 12, marginLeft: 'auto', alignItems: 'center' }}>
          {/* Both pills can read "Active" at once (vendor + worker), so each is
              labelled — otherwise the pair is ambiguous. */}
          {worker.vendor?.status && <LabelledPill label="Vendor" cfg={vendorStatusCfg(worker.vendor.status)} />}
          <LabelledPill label="Worker" cfg={workerStatusCfg(worker.status)} />
          <button onClick={refresh} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 12px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12.5 }}>
            <RefreshCw size={13} /> Refresh
          </button>
        </div>
      </div>

      {/* Live gate blockers — the same list the API refuses on */}
      {progress.blockers?.length > 0 && worker.status === WORKER_STATUS.DRAFT && (
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

          {/* The role's full PPE checklist, so "what's missing" is never a guess. */}
          {progress.ppe_compliance?.configured && !progress.ppe_compliance.compliant && (
            <PpeChecklist c={progress.ppe_compliance} />
          )}
        </div>
      )}
      {worker.status === WORKER_STATUS.TERMINATED && worker.remarks && (
        <InfoBox tone="danger"><strong>Terminated:</strong> {worker.remarks}</InfoBox>
      )}

      <Stepper steps={steps} active={active} onGo={setActive} />

      <div style={{ marginTop: 18 }}>
        {active === 1 && <StepProfile worker={worker} editable={editable} onSaved={refresh} onNext={() => setActive(2)} api={api} />}
        {active === 2 && <Step2Medical worker={worker} editable={editable} onSaved={refresh} onNext={() => setActive(3)} api={api} />}
        {active === 3 && <StepInduction worker={worker} editable={editable} onSaved={refresh} onNext={() => setActive(4)} api={api} />}
        {active === 4 && <StepPpe worker={worker} editable={editable} manage={manage} onChanged={refresh} onNext={() => setActive(5)} api={api} compliance={progress.ppe_compliance} />}
        {active === 5 && <StepBadge worker={worker} progress={progress} admin={admin} onChanged={refresh} api={api} />}
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

// ── Step 1 — Profile ─────────────────────────────────────────────────────────
function StepProfile({ worker, editable, onSaved, onNext, api }) {
  const [f, setF] = useState({
    name: worker.name || '', dob: worker.dob?.slice(0, 10) || '', gender: worker.gender || '',
    designation: worker.designation || '', skill_category: worker.skill_category || '',
    experience_years: worker.experience_years ?? '', joining_date: worker.joining_date?.slice(0, 10) || '', exit_date: worker.exit_date?.slice(0, 10) || '',
    work_package_id: worker.work_package_id ? String(worker.work_package_id) : '',
    aadhar_number: worker.aadhar_number || '', mobile: worker.mobile || '', blood_group: worker.blood_group || '',
    address: worker.address || '', emergency_contact: worker.emergency_contact || '', emergency_phone: worker.emergency_phone || '',
    awards: worker.awards || '', bocw_number: worker.bocw_number || '',
  })
  const [saving, setSaving] = useState(false)
  const [saved, setSaved]   = useState(false)
  const set = (k) => (e) => { setF(p => ({ ...p, [k]: e.target.value })); setSaved(false) }

  // Work packages this worker can be deployed on — their own vendor's, so the
  // competency gate (Rule 4) reads the right activities. Read-only if unbadgeable.
  const [wps, setWps] = useState([])
  useEffect(() => {
    // Guard: not every api surface exposes workPackages (e.g. a slimmer portal
    // api). Missing namespace must degrade to an empty list, never white-screen.
    if (!worker.vendor_id || typeof api.workPackages?.list !== 'function') { setWps([]); return }
    api.workPackages.list({ vendor_id: worker.vendor_id })
      .then(rows => setWps(Array.isArray(rows) ? rows : (rows?.data ?? [])))
      .catch(() => setWps([]))
  }, [worker.vendor_id])

  // Mirror the backend's statutory floor so the user sees it before saving.
  const age = f.dob ? Math.floor((Date.now() - new Date(f.dob)) / 31557600000) : null
  const underage = age !== null && age < 18

  const save = async () => {
    setSaving(true)
    try {
      const payload = Object.fromEntries(Object.entries(f).map(([k, v]) => [k, v === '' ? null : v]))
      await api.workers.update(worker.id, payload)
      setSaved(true); onSaved()
    } catch (e) {
      const errObj = e?.response?.data?.errors
      const errText = errObj ? Object.values(errObj).flat().join('\n') : (e?.response?.data?.message || 'Failed to save profile')
      alert(errText)
    }
    finally { setSaving(false) }
  }

  return (
    <Panel title="Worker Profile" sub="Identity, contact and skill classification"
      actions={
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          {editable && <SaveBtn onClick={save} saving={saving} saved={saved} label="Save Profile" />}
          <button type="button" onClick={onNext}
            style={{
              padding: '8px 16px', borderRadius: 9, border: 'none',
              background: 'linear-gradient(135deg, #0284c7, #0369a1)', color: '#fff',
              fontWeight: 800, fontSize: 12.5, cursor: 'pointer',
              display: 'inline-flex', alignItems: 'center', gap: 6
            }}>
            Continue → Step 2 (Medical)
          </button>
        </div>
      }>
      {!editable && <InfoBox>This worker is no longer editable — the profile is read-only.</InfoBox>}
      {underage && <InfoBox tone="danger">This worker is {age} — a badge cannot be issued below 18.</InfoBox>}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 14 }}>
        <Field label="Full Name *"><TextInput value={f.name} onChange={set('name')} disabled={!editable} /></Field>
        <Field label="Date of Birth *">
          <TextInput type="date" value={f.dob} onChange={set('dob')} disabled={!editable}
            style={underage ? { ...inputStyle, borderColor: '#ef4444' } : undefined} />
          {age !== null && <span style={{ fontSize: 11, color: underage ? '#ef4444' : 'var(--text-muted)', fontWeight: 600 }}>Age {age}</span>}
        </Field>
        <Field label="Gender"><SelectInput value={f.gender} onChange={set('gender')} disabled={!editable} options={['', ...GENDERS]} /></Field>
        <Field label="Designation *"><TextInput value={f.designation} onChange={set('designation')} disabled={!editable} placeholder="e.g. Fitter" /></Field>
        <Field label="Aadhaar Number *">
          <TextInput value={f.aadhar_number} onChange={set('aadhar_number')} disabled={!editable} placeholder="12-digit ID"
            inputMode="numeric" maxLength={12}
            style={f.aadhar_number && !/^\d{12}$/.test(f.aadhar_number) ? { ...inputStyle, borderColor: '#ef4444' } : undefined} />
          {f.aadhar_number && !/^\d{12}$/.test(f.aadhar_number) && <span style={{ fontSize: 11, color: '#ef4444', fontWeight: 600 }}>Must be exactly 12 digits</span>}
        </Field>
        <Field label="Mobile *"><TextInput value={f.mobile} onChange={set('mobile')} disabled={!editable} placeholder="10-digit mobile" inputMode="numeric" maxLength={10} /></Field>
        <Field label="Work Package">
          <SelectInput value={f.work_package_id} onChange={set('work_package_id')} disabled={!editable} pairs
            options={[['', 'Unassigned'], ...wps.map(w => [String(w.id), w.reference ? `${w.reference} · ${w.name}` : w.name])]} />
          <span style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 600 }}>Deploys the worker; their activities' required competencies gate the badge.</span>
        </Field>
        <Field label="Experience (years)"><TextInput type="number" min="0" step="0.5" value={f.experience_years} onChange={set('experience_years')} disabled={!editable} placeholder="e.g. 5" /></Field>
        <Field label="Joining Date"><TextInput type="date" value={f.joining_date} onChange={set('joining_date')} disabled={!editable} /></Field>
        <Field label="Exit Date"><TextInput type="date" value={f.exit_date} onChange={set('exit_date')} disabled={!editable} /></Field>
        <Field label="Emergency Contact"><TextInput value={f.emergency_contact} onChange={set('emergency_contact')} disabled={!editable} placeholder="Name" /></Field>
        <Field label="Emergency Phone"><TextInput value={f.emergency_phone} onChange={set('emergency_phone')} disabled={!editable} /></Field>
        <Field label="Address" full><textarea value={f.address} onChange={set('address')} disabled={!editable} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
        <Field label="BOCW Health Card No."><TextInput value={f.bocw_number} onChange={set('bocw_number')} disabled={!editable} placeholder="Welfare board card #" /></Field>
        <Field label="Awards / Recognition" full><textarea value={f.awards} onChange={set('awards')} disabled={!editable} rows={2} placeholder="e.g. Safety Star – Aug 2026" style={{ ...inputStyle, resize: 'vertical' }} /></Field>
      </div>

      <div style={{ marginTop: 24, paddingTop: 16, borderTop: '1px solid var(--border)', display: 'flex', justifyContent: 'flex-end', gap: 12 }}>
        <button type="button" onClick={async () => { await save(); onNext() }} disabled={saving}
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
function Step2Medical({ worker, editable, onSaved, onNext, api }) {
  const m = worker.medical || {}
  // Hydrate from the canonical tpv_worker_medicals columns (exam_type, examiner_name,
  // clinic_name, vision, height_cm, weight_kg, bp_systolic/diastolic, restrictions),
  // falling back to the legacy flat names so an old record still shows on re-open.
  const [f, setF] = useState({
    medical_type: m.exam_type || m.medical_type || '',
    doctor_name: m.examiner_name || m.doctor_name || '',
    organization_name: m.clinic_name || m.organization_name || 'Hospital / Clinic',
    doctor_registration: m.doctor_registration || '',
    designation: worker.designation || 'Worker',
    dob: worker.dob?.slice(0, 10) || '',
    gender: worker.gender || 'Male',
    blood_group: worker.blood_group || 'O+',
    eyesight: m.vision || m.eyesight || '6/6',
    height: m.height_cm || m.height || '',
    weight: m.weight_kg || m.weight || '',
    blood_pressure: (m.bp_systolic && m.bp_diastolic) ? `${m.bp_systolic}/${m.bp_diastolic}` : (m.blood_pressure || '120/80'),
    height_phobia: m.height_phobia || 'no',
    heart_disease: m.heart_disease || 'no',
    habits: m.habits || 'none',
    handicapped: m.handicapped || 'no',
    doctor_comments: m.restrictions || m.doctor_comments || '',
    external_doctor_name: (m.exam_type === 'external' ? m.examiner_name : '') || m.external_doctor_name || '',
    external_pdf: null,
    // External-doctor exam: the certified fitness outcome (no longer hardcoded to
    // Fit) + whether a report file is already on record.
    external_fitness: (m.exam_type === 'external' ? m.fitness_status : '') || 'Fit',
    has_report: !!m.document_path,
    // §16 legal capture — optional signer/scene photo (base64); IP + geolocation
    // are captured automatically at save.
    capture_photo: null,
    signature_data: '',
    stamp_data: '',
  })

  const [saving, setSaving] = useState(false)
  const [saved, setSaved]   = useState(false)
  const [mhVer, setMhVer]   = useState(1)
  const [mhAnswers, setMhAnswers] = useState(m.screening_responses && typeof m.screening_responses === 'object' ? m.screening_responses : {})
  const [sigTab, setSigTab] = useState('upload')
  const [sigPreview, setSigPreview] = useState(
    m.signature_path ? `/storage/${m.signature_path}` : (m.signature_file ? `/storage/${m.signature_file}` : null)
  )
  const [stampText, setStampText]   = useState('')
  const [stampFont, setStampFont]   = useState('bold 20px Arial')
  const [stampColor, setStampColor] = useState('#0d47a1')

  const sigCanvasRef   = useRef(null)
  const stampCanvasRef = useRef(null)
  const isSigDrawing   = useRef(false)

  const set = (k) => (e) => { setF(p => ({ ...p, [k]: e.target.value })); setSaved(false) }

  const age = f.dob ? Math.floor((Date.now() - new Date(f.dob)) / 31557600000) : (worker.age || null)
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

  const startSigDraw = (e) => {
    const canvas = sigCanvasRef.current; if (!canvas) return
    const ctx = canvas.getContext('2d')
    const rect = canvas.getBoundingClientRect()
    const x = (e.clientX || e.touches?.[0]?.clientX) - rect.left
    const y = (e.clientY || e.touches?.[0]?.clientY) - rect.top
    ctx.beginPath(); ctx.moveTo(x, y)
    isSigDrawing.current = true
  }

  const doSigDraw = (e) => {
    if (!isSigDrawing.current) return
    const canvas = sigCanvasRef.current; if (!canvas) return
    const ctx = canvas.getContext('2d')
    const rect = canvas.getBoundingClientRect()
    const x = (e.clientX || e.touches?.[0]?.clientX) - rect.left
    const y = (e.clientY || e.touches?.[0]?.clientY) - rect.top
    ctx.lineTo(x, y); ctx.stroke()
  }

  const stopSigDraw = () => {
    if (isSigDrawing.current && sigCanvasRef.current) {
      setF(p => ({ ...p, signature_data: sigCanvasRef.current.toDataURL('image/png') }))
    }
    isSigDrawing.current = false
  }

  const clearSigCanvas = () => {
    const canvas = sigCanvasRef.current; if (!canvas) return
    const ctx = canvas.getContext('2d')
    ctx.clearRect(0, 0, canvas.width, canvas.height)
    setF(p => ({ ...p, signature_data: '' }))
  }

  const renderStamp = () => {
    const canvas = stampCanvasRef.current; if (!canvas || !stampText.trim()) return
    const ctx = canvas.getContext('2d')
    ctx.clearRect(0, 0, canvas.width, canvas.height)
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height)
    ctx.strokeStyle = stampColor; ctx.lineWidth = 3; ctx.strokeRect(8, 8, canvas.width - 16, canvas.height - 16)
    ctx.font = stampFont; ctx.fillStyle = stampColor; ctx.textAlign = 'center'; ctx.textBaseline = 'middle'
    ctx.fillText(stampText, canvas.width / 2, canvas.height / 2)
    setF(p => ({ ...p, stamp_data: canvas.toDataURL('image/png') }))
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
      <title>Medical Certificate — ${worker.name}</title>
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
            <div><small>PATIENT NAME</small><br><strong>${worker.name}</strong></div>
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
            <div class="rx-advice">Worker <strong>${worker.name}</strong> (${worker.worker_code}) is certified <strong>${medicalResult}</strong>. Standard HSSE protocols apply.</div>
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

  // §16 — best-effort geolocation for the legal-capture trio. Resolves to
  // "lat,long" or null (never rejects) so a denied prompt can't block the save.
  function captureGeo() {
    return new Promise((resolve) => {
      if (!navigator.geolocation) return resolve(null)
      navigator.geolocation.getCurrentPosition(
        (p) => resolve(`${p.coords.latitude.toFixed(6)},${p.coords.longitude.toFixed(6)}`),
        () => resolve(null),
        { timeout: 6000, maximumAge: 60000 },
      )
    })
  }

  const onCapturePhoto = (e) => {
    const file = e.target.files?.[0]
    if (!file) { setF((p) => ({ ...p, capture_photo: null })); return }
    const reader = new FileReader()
    reader.onload = () => setF((p) => ({ ...p, capture_photo: reader.result }))
    reader.readAsDataURL(file)
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
      if (!f.external_pdf && !f.has_report) { alert('Upload the external medical report (PDF or image).'); return }
    }

    setSaving(true)
    try {
      const [sys, dia] = (f.blood_pressure || '').split('/').map(s => parseInt(s, 10))
      const isExternal = f.medical_type === 'external'
      // Map the form onto the canonical tpv_worker_medicals contract the API
      // validates (SaveWorkerMedicalRequest). For an internal exam fitness is the
      // computed physical result; for an external one it is the outcome the
      // examiner certified on the uploaded report (NO longer hardcoded to Fit).
      // fitness_status is what the badge gate reads, so it must be truthful.
      // §16 legal capture — best-effort browser geolocation (permission-gated);
      // the server stamps the IP. Denied/unavailable → recorded without geo.
      let geo = null
      try { geo = await captureGeo() } catch { /* denied or unavailable */ }

      const fitness = isExternal
        ? f.external_fitness
        : (medicalResult === 'Medically Fit' ? 'Fit' : 'Unfit')

      const fields = {
        exam_type: isExternal ? 'external' : 'internal',
        exam_date: new Date().toISOString().slice(0, 10),
        examiner_name: (isExternal ? f.external_doctor_name : f.doctor_name) || null,
        clinic_name: f.organization_name || null,
        height_cm: f.height ? Number(f.height) : null,
        weight_kg: f.weight ? Number(f.weight) : null,
        bp_systolic: Number.isFinite(sys) ? sys : null,
        bp_diastolic: Number.isFinite(dia) ? dia : null,
        vision: f.eyesight || null,
        fitness_status: fitness,
        restrictions: f.doctor_comments || null,
        geo_location: geo || undefined,
        capture_photo: f.capture_photo || undefined,
      }
      // Mental-health screening + the examiner signature belong to the internal exam.
      if (!isExternal) {
        fields.screening_responses = Object.keys(mhAnswers).length ? mhAnswers : null
        fields.screening_score = allMhAnswered ? totalMhScore : null
        if (f.signature_data) fields.signature_data = f.signature_data
      }

      // External report file → multipart, so the uploaded PDF is actually sent
      // and persisted (document_path) instead of being silently dropped.
      let payload = fields
      if (isExternal && f.external_pdf) {
        const fd = new FormData()
        Object.entries(fields).forEach(([k, v]) => {
          if (v === null || v === undefined) return
          fd.append(k, typeof v === 'object' ? JSON.stringify(v) : v)
        })
        fd.append('report_file', f.external_pdf)
        payload = fd
      }

      await api.workers.saveMedical(worker.id, payload)
      setSaved(true)
      onSaved()
      if (onNext) onNext()
    } catch (e) {
      alert(e?.response?.data?.message || 'Failed to save medical details')
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
    <Panel title="Step 2 — Medical Fitness Examination" sub="1:1 Reference CodeIgniter Implementation — Physical vitals, mental health screening & prescription certificate"
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
        <div><span style={{ fontSize: 9, fontWeight: 800, color: '#0369a1', textTransform: 'uppercase' }}>Full Name</span><br /><strong style={{ color: '#0c4a6e', fontSize: 13 }}>{worker.name}</strong></div>
        <div><span style={{ fontSize: 9, fontWeight: 800, color: '#0369a1', textTransform: 'uppercase' }}>Age</span><br /><strong style={{ color: '#0c4a6e', fontSize: 13 }}>{age ? `${age} yrs` : '—'}</strong></div>
        <div><span style={{ fontSize: 9, fontWeight: 800, color: '#0369a1', textTransform: 'uppercase' }}>Gender</span><br /><strong style={{ color: '#0c4a6e', fontSize: 13 }}>{f.gender}</strong></div>
        <div><span style={{ fontSize: 9, fontWeight: 800, color: '#0369a1', textTransform: 'uppercase' }}>Blood Group</span><br /><strong style={{ color: '#0c4a6e', fontSize: 13 }}>{f.blood_group}</strong></div>
        <div><span style={{ fontSize: 9, fontWeight: 800, color: '#0369a1', textTransform: 'uppercase' }}>Designation</span><br /><strong style={{ color: '#0c4a6e', fontSize: 13 }}>{f.designation}</strong></div>
        <div style={{ marginLeft: 'auto', background: '#dcfce7', color: '#15803d', border: '1px solid #86efac', padding: '4px 12px', borderRadius: 20, fontSize: 11, fontWeight: 800 }}>
          ✨ Auto Pre-filled from Step 1
        </div>
      </div>

      {/* Medical History — every past exam, newest first (P0-2). Re-tests over
          time accumulate here; the top row is the current fitness the gate reads. */}
      {Array.isArray(worker.medical_history) && worker.medical_history.length > 0 && (
        <div style={{ marginBottom: 18, border: '1px solid var(--border)', borderRadius: 10, overflow: 'hidden' }}>
          <div style={{ padding: '8px 14px', background: 'var(--bg-input)', fontSize: 12, fontWeight: 800, color: 'var(--text-h)' }}>
            🩺 Medical History ({worker.medical_history.length})
          </div>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
              <thead>
                <tr style={{ textAlign: 'left', color: 'var(--text-muted)' }}>
                  <th style={{ padding: '6px 14px', fontWeight: 700 }}>Date</th>
                  <th style={{ padding: '6px 14px', fontWeight: 700 }}>Type</th>
                  <th style={{ padding: '6px 14px', fontWeight: 700 }}>Examiner</th>
                  <th style={{ padding: '6px 14px', fontWeight: 700 }}>Fitness</th>
                  <th style={{ padding: '6px 14px', fontWeight: 700 }}>Valid Until</th>
                </tr>
              </thead>
              <tbody>
                {worker.medical_history.map((m, i) => (
                  <tr key={m.id} style={{ borderTop: '1px solid var(--border)', background: i === 0 ? 'rgba(16,185,129,0.06)' : 'transparent' }}>
                    <td style={{ padding: '6px 14px' }}>
                      {m.exam_date ? new Date(m.exam_date).toLocaleDateString() : '—'}
                      {i === 0 && <span style={{ marginLeft: 6, fontSize: 9, fontWeight: 800, color: '#15803d' }}>CURRENT</span>}
                    </td>
                    <td style={{ padding: '6px 14px', textTransform: 'capitalize' }}>{m.exam_type || '—'}</td>
                    <td style={{ padding: '6px 14px' }}>{m.examiner_name || '—'}</td>
                    <td style={{ padding: '6px 14px', fontWeight: 700, color: String(m.fitness_status || '').startsWith('Fit') ? '#15803d' : '#b91c1c' }}>
                      {String(m.fitness_status || '—').replace(/_/g, ' ')}
                    </td>
                    <td style={{ padding: '6px 14px' }}>{m.valid_until ? new Date(m.valid_until).toLocaleDateString() : '—'}</td>
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
              <div style={{ fontSize: 11, opacity: 0.8, marginTop: 4 }}>Upload external medical PDF report</div>
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
            <Field label="Doctor Comments *"><TextInput multiline rows={3} value={f.doctor_comments} onChange={set('doctor_comments')} placeholder="Enter medical remarks and observations" /></Field>
          </div>

          {/* Worker Signature & Stamp Tabs */}
          <h3 style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)', marginBottom: 12, borderBottom: '1px solid var(--border)', paddingBottom: 6 }}>✍ Worker Acknowledgement & Signature</h3>
          <div style={{ display: 'flex', gap: 8, marginBottom: 12 }}>
            <button type="button" onClick={() => setSigTab('upload')} style={{ padding: '8px 16px', borderRadius: 8, border: 'none', background: sigTab === 'upload' ? '#0284c7' : 'var(--bg-input)', color: sigTab === 'upload' ? '#fff' : 'var(--text-muted)', fontWeight: 800, cursor: 'pointer' }}>📁 Upload Signature</button>
            <button type="button" onClick={() => setSigTab('draw')} style={{ padding: '8px 16px', borderRadius: 8, border: 'none', background: sigTab === 'draw' ? '#0284c7' : 'var(--bg-input)', color: sigTab === 'draw' ? '#fff' : 'var(--text-muted)', fontWeight: 800, cursor: 'pointer' }}>🖊 Draw Signature</button>
            <button type="button" onClick={() => setSigTab('stamp')} style={{ padding: '8px 16px', borderRadius: 8, border: 'none', background: sigTab === 'stamp' ? '#0284c7' : 'var(--bg-input)', color: sigTab === 'stamp' ? '#fff' : 'var(--text-muted)', fontWeight: 800, cursor: 'pointer' }}>💮 Stamp / Text Generator</button>
          </div>

          <div style={{ padding: 16, borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)', marginBottom: 20 }}>
            {sigTab === 'upload' && (
              <div>
                <label style={{ fontSize: 12, fontWeight: 700, display: 'block', marginBottom: 8 }}>Upload Signature (JPG/PNG, max 2MB):</label>
                <input type="file" accept="image/jpeg,image/png" onChange={e => {
                  const file = e.target.files[0]
                  if (file) {
                    const reader = new FileReader()
                    reader.onload = ev => {
                      setSigPreview(ev.target.result)
                      setF(p => ({ ...p, signature_data: ev.target.result }))
                    }
                    reader.readAsDataURL(file)
                  }
                }} style={{ ...inputStyle, padding: 8 }} />
                {sigPreview && <img src={sigPreview} alt="Signature Preview" style={{ marginTop: 10, maxHeight: 100, borderRadius: 6, border: '1px solid var(--border)', padding: 4 }} />}
              </div>
            )}

            {sigTab === 'draw' && (
              <div>
                <label style={{ fontSize: 12, fontWeight: 700, display: 'block', marginBottom: 8 }}>Draw signature using mouse or touch:</label>
                <canvas ref={sigCanvasRef} width={500} height={150} onMouseDown={startSigDraw} onMouseMove={doSigDraw} onMouseUp={stopSigDraw} onMouseLeave={stopSigDraw} onTouchStart={startSigDraw} onTouchMove={doSigDraw} onTouchEnd={stopSigDraw} style={{ background: '#fff', border: '2px dashed var(--border)', borderRadius: 8, cursor: 'crosshair', display: 'block' }} />
                <button type="button" onClick={clearSigCanvas} style={{ marginTop: 8, padding: '4px 12px', borderRadius: 6, background: '#ef4444', color: '#fff', border: 'none', cursor: 'pointer', fontSize: 11, fontWeight: 800 }}>Clear Signature</button>
              </div>
            )}

            {sigTab === 'stamp' && (
              <div>
                <label style={{ fontSize: 12, fontWeight: 700, display: 'block', marginBottom: 8 }}>Type Stamp Text:</label>
                <div style={{ display: 'flex', gap: 10, marginBottom: 10, flexWrap: 'wrap' }}>
                  <input type="text" value={stampText} onChange={e => setStampText(e.target.value)} placeholder="Type stamp text..." style={{ ...inputStyle, flex: 1, minWidth: 160 }} />
                  <select value={stampFont} onChange={e => setStampFont(e.target.value)} style={{ ...inputStyle, width: 140 }}><option value="bold 20px Arial">Arial Bold</option><option value="italic bold 18px Georgia">Georgia Italic</option><option value="bold 18px Courier New">Courier</option></select>
                  <select value={stampColor} onChange={e => setStampColor(e.target.value)} style={{ ...inputStyle, width: 110 }}><option value="#0d47a1">Blue</option><option value="#1a7a3c">Green</option><option value="#b71c1c">Red</option><option value="#111">Black</option></select>
                  <button type="button" onClick={renderStamp} style={{ padding: '6px 14px', borderRadius: 8, background: '#0284c7', color: '#fff', fontWeight: 800, border: 'none', cursor: 'pointer' }}>💮 Stamp</button>
                </div>
                <canvas ref={stampCanvasRef} width={500} height={120} style={{ background: '#fff', border: '2px dashed var(--border)', borderRadius: 8, display: 'block' }} />
              </div>
            )}
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
        /* External Doctor PDF Upload Form */
        <div>
          <h3 style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)', marginBottom: 12 }}>🏥 Upload External Doctor Medical Report</h3>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14, marginBottom: 14 }}>
            <Field label="External Doctor Name *"><TextInput value={f.external_doctor_name} onChange={set('external_doctor_name')} placeholder="Dr. Full Name" /></Field>
            <Field label="Certified Fitness Outcome *">
              <select value={f.external_fitness} onChange={set('external_fitness')} style={inputStyle}>
                <option value="Fit">Fit</option>
                <option value="Fit_With_Restrictions">Fit With Restrictions</option>
                <option value="Unfit">Unfit</option>
              </select>
            </Field>
          </div>
          <Field label={`Attach Medical Report — PDF or image ${f.has_report ? '' : '*'}`}>
            <input type="file" accept="application/pdf,image/*" onChange={e => setF(p => ({ ...p, external_pdf: e.target.files[0] }))} style={{ ...inputStyle, padding: 8 }} />
          </Field>
          {f.has_report && !f.external_pdf && (
            <p style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 6 }}>✓ A report is already on file — upload a new file only to replace it.</p>
          )}
        </div>
      )}

      {/* §16 Legal-verification capture — optional photo; IP + location auto-recorded */}
      {f.medical_type && f.medical_type !== 'skip' && (
        <div style={{ marginTop: 18, padding: '12px 14px', border: '1px solid var(--border)', borderRadius: 10, background: 'var(--bg-input)' }}>
          <div style={{ fontSize: 11.5, fontWeight: 800, color: 'var(--text-h)', marginBottom: 8 }}>🔏 Legal Verification Capture</div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14, alignItems: 'end' }}>
            <Field label="Signer / Scene Photo (optional)">
              <input type="file" accept="image/*" capture="environment" onChange={onCapturePhoto} style={{ ...inputStyle, padding: 8 }} />
            </Field>
            <div style={{ fontSize: 11, color: 'var(--text-muted)', lineHeight: 1.5 }}>
              📍 Location &amp; 🌐 IP are recorded automatically with the signature for legal verification.
              {f.capture_photo && <span style={{ color: '#15803d', fontWeight: 700 }}> · Photo attached ✓</span>}
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

// ── Step 3 — HSSE induction ──────────────────────────────────────────────────
function StepInduction({ worker, editable, onSaved, onNext, api }) {
  const ind = worker.induction || {}
  const [f, setF] = useState({
    induction_type: ind.induction_type || 'General Safety',
    trainer: ind.trainer_name || ind.trainer || 'Safety Officer – Rahul Sharma',
    custom_trainer: '',
    location: ind.induction_location || 'Site Office',
    time_mode: 'auto', // 'auto' | 'manual'
    start_time: ind.induction_start || '',
    end_time: ind.induction_end || '',
    duration_minutes: ind.duration_minutes || ind.induction_duration || '',
    m_start_date: new Date().toISOString().slice(0, 10),
    m_start_time: new Date().toTimeString().slice(0, 5),
    m_end_date: new Date().toISOString().slice(0, 10),
    m_end_time: new Date().toTimeString().slice(0, 5),
    photo_data: '',
    proof_type: 'sig', // 'sig' | 'thumb'
    proof_data: '',
  })

  const [topics, setTopics] = useState((Array.isArray(ind.topics) && ind.topics.length ? ind.topics : null) || ['Site Safety Rules', 'PPE Usage', 'Emergency Response'])
  const [saving, setSaving] = useState(false)
  const [saved, setSaved]   = useState(false)
  const [sessionActive, setSessionActive] = useState(false)
  const [proofConfirmed, setProofConfirmed] = useState(!!(ind.passed || ind.induction_status))

  // Camera states
  const [camActive, setCamActive] = useState(false)
  const [photoPreview, setPhotoPreview] = useState((ind.photo_path || ind.induction_photo) ? `/storage/${ind.photo_path || ind.induction_photo}` : null)
  const videoRef = useRef(null)
  const photoCanvasRef = useRef(null)
  const streamRef = useRef(null)

  // Signature / Thumb Canvas Refs
  const canvasRef = useRef(null)
  const isDrawing = useRef(false)

  const set = (k) => (e) => { setF(p => ({ ...p, [k]: e.target.value })); setSaved(false) }
  const toggleTopic = (t) => { setTopics(p => p.includes(t) ? p.filter(x => x !== t) : [...p, t]); setSaved(false) }

  // Trainer presets
  const TRAINER_PRESETS = [
    { group: 'Safety Team', items: ['Safety Officer – Rahul Sharma', 'Safety Supervisor – Priya Patel', 'HSE Lead – Amit Verma', 'HSSE Manager – Neha Singh', 'Safety Inspector – Ravi Kumar'] },
    { group: 'HR Team', items: ['HR Manager – Sunita Joshi', 'HR Executive – Deepak Nair', 'HR Coordinator – Anjali Mehta'] },
    { group: 'Site Management', items: ['Site Engineer – Vikram Rao', 'Project Manager – Suresh Pillai', 'Site Supervisor – Mohan Das'] },
    { group: 'Custom', items: ['Other / Custom Trainer...'] },
  ]

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

  // Camera handlers
  const startCamera = async () => {
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } })
      streamRef.current = stream
      if (videoRef.current) videoRef.current.srcObject = stream
      setCamActive(true)
    } catch (e) {
      alert('Camera access error: ' + e.message)
    }
  }

  const capturePhoto = () => {
    const v = videoRef.current
    const c = photoCanvasRef.current
    if (!v || !c) return
    c.getContext('2d').drawImage(v, 0, 0, c.width, c.height)
    const url = c.toDataURL('image/png')
    setPhotoPreview(url)
    setF(p => ({ ...p, photo_data: url }))
    stopCamera()
  }

  const stopCamera = () => {
    if (streamRef.current) {
      streamRef.current.getTracks().forEach(t => t.stop())
      streamRef.current = null
    }
    setCamActive(false)
  }

  // Canvas Handlers
  const startCanvasDraw = (e) => {
    const canvas = canvasRef.current; if (!canvas) return
    const ctx = canvas.getContext('2d')
    const rect = canvas.getBoundingClientRect()
    const x = (e.clientX || e.touches?.[0]?.clientX) - rect.left
    const y = (e.clientY || e.touches?.[0]?.clientY) - rect.top
    ctx.beginPath(); ctx.moveTo(x, y)
    isDrawing.current = true
  }

  const doCanvasDraw = (e) => {
    if (!isDrawing.current) return
    const canvas = canvasRef.current; if (!canvas) return
    const ctx = canvas.getContext('2d')
    const rect = canvas.getBoundingClientRect()
    const x = (e.clientX || e.touches?.[0]?.clientX) - rect.left
    const y = (e.clientY || e.touches?.[0]?.clientY) - rect.top
    ctx.lineTo(x, y); ctx.stroke()
  }

  const stopCanvasDraw = () => {
    if (isDrawing.current && canvasRef.current) {
      setF(p => ({ ...p, proof_data: canvasRef.current.toDataURL('image/png') }))
    }
    isDrawing.current = false
  }

  const clearCanvas = () => {
    const canvas = canvasRef.current; if (!canvas) return
    const ctx = canvas.getContext('2d')
    ctx.clearRect(0, 0, canvas.width, canvas.height)
    setF(p => ({ ...p, proof_data: '' }))
    setProofConfirmed(false)
  }

  const confirmProof = () => {
    if (!f.proof_data) { alert('Please draw or upload signature / thumb impression first.'); return }
    setProofConfirmed(true)
  }

  const saveInduction = async () => {
    const finalTrainer = f.trainer === 'Other / Custom Trainer...' ? f.custom_trainer : f.trainer
    if (!finalTrainer.trim()) { alert('Trainer Name is required.'); return }
    if (!f.location.trim()) { alert('Induction Location is required.'); return }

    setSaving(true)
    try {
      const now = new Date()
      const endISO = f.time_mode === 'auto' ? now.toISOString() : f.end_time
      const dur = f.time_mode === 'auto' ? (f.start_time ? Math.round((now - new Date(f.start_time)) / 60000) : 15) : f.duration_minutes

      const payload = {
        induction_type: f.induction_type,
        // trainer_name is the persisted column; trainer kept for the legacy path.
        trainer: finalTrainer,
        trainer_name: finalTrainer,
        location: f.location,
        training_date: new Date().toISOString().slice(0, 10),
        start_time: f.start_time || new Date().toISOString(),
        end_time: endISO,
        duration_minutes: dur || 15,
        photo_data: f.photo_data,
        signature_data: f.proof_type === 'sig' ? f.proof_data : null,
        thumb_data: f.proof_type === 'thumb' ? f.proof_data : null,
        topics,
        // Completing the induction session marks it passed — this is the flag the
        // badge gate reads (blockers() → "HSSE induction not passed").
        passed: true,
      }

      await api.workers.saveInduction(worker.id, payload)
      setSaved(true)
      onSaved()
      if (onNext) onNext()
    } catch (e) {
      alert(e?.response?.data?.message || 'Failed to save induction')
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
      const res = await api.workers.list(worker.vendor_id ? { vendor_id: worker.vendor_id } : {})
      const list = Array.isArray(res?.data ?? res) ? (res.data ?? res) : []
      setVendorWorkers(list)
      setGroupModalOpen(true)
    } catch (e) {
      alert('Failed to load vendor workers list')
    } finally {
      setLoadingWorkers(false)
    }
  }

  return (
    <Panel title="Step 3 — HSSE Induction Verification" sub="1:1 Reference CodeIgniter Implementation — Site safety induction, live camera group photo & proof signature"
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
        <div><span style={{ fontSize: 9, fontWeight: 800, color: '#15803d', textTransform: 'uppercase' }}>Full Name</span><br /><strong style={{ color: '#14532d', fontSize: 13 }}>{worker.name}</strong></div>
        <div><span style={{ fontSize: 9, fontWeight: 800, color: '#15803d', textTransform: 'uppercase' }}>Age</span><br /><strong style={{ color: '#14532d', fontSize: 13 }}>{worker.age ? `${worker.age} yrs` : '—'}</strong></div>
        <div><span style={{ fontSize: 9, fontWeight: 800, color: '#15803d', textTransform: 'uppercase' }}>Gender</span><br /><strong style={{ color: '#14532d', fontSize: 13 }}>{worker.gender}</strong></div>
        <div><span style={{ fontSize: 9, fontWeight: 800, color: '#15803d', textTransform: 'uppercase' }}>Designation</span><br /><strong style={{ color: '#14532d', fontSize: 13 }}>{worker.designation}</strong></div>
        <div style={{ marginLeft: 'auto', background: '#e0f2fe', color: '#0369a1', border: '1px solid #7dd3fc', padding: '4px 12px', borderRadius: 20, fontSize: 11, fontWeight: 800 }}>
          📋 Induction Session
        </div>
      </div>

      {/* Session Details */}
      <h3 style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)', marginBottom: 12, borderBottom: '1px solid var(--border)', paddingBottom: 6 }}>📋 Session Details</h3>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 14, marginBottom: 16 }}>
        <Field label="Induction Type *">
          <SelectInput value={f.induction_type} onChange={set('induction_type')} pairs options={[
            ['General Safety', 'General Safety'],
            ['Activity Specific', 'Activity Specific'],
            ['Site Specific', 'Site Specific'],
            ['Client Specific', 'Client Specific'],
            ['Emergency & Evacuation', 'Emergency & Evacuation'],
            ['Fire Safety', 'Fire Safety'],
            ['PPE Usage', 'PPE Usage'],
            ['Toolbox Talk', 'Toolbox Talk'],
          ]} />
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
        {['Site Safety Rules', 'PPE Usage', 'Work at Height', 'Emergency Response', 'Fire Safety', 'First Aid', 'Manual Handling', 'Permit to Work'].map(t => (
          <button type="button" key={t} onClick={() => toggleTopic(t)} style={{ padding: '6px 14px', borderRadius: 20, border: '1.5px solid', fontSize: 11.5, fontWeight: 800, cursor: 'pointer', background: topics.includes(t) ? '#7c3aed' : 'var(--bg-input)', color: topics.includes(t) ? '#fff' : 'var(--text-muted)', borderColor: topics.includes(t) ? '#7c3aed' : 'var(--border)' }}>
            {topics.includes(t) ? '✓ ' : '+ '}{t}
          </button>
        ))}
      </div>

      {/* Live Group Photo Attachment */}
      <h3 style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)', marginBottom: 10 }}>📸 Session Group / Worker Photo</h3>
      <div style={{ padding: 14, borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)', marginBottom: 20, display: 'flex', gap: 16, alignItems: 'center', flexWrap: 'wrap' }}>
        {camActive ? (
          <div style={{ position: 'relative' }}>
            <video ref={videoRef} autoPlay playsInline style={{ width: 220, height: 140, borderRadius: 8, border: '2px solid #0284c7', objectFit: 'cover' }} />
            <canvas ref={photoCanvasRef} width={220} height={140} style={{ display: 'none' }} />
          </div>
        ) : photoPreview ? (
          <img src={photoPreview} alt="Captured" style={{ height: 120, borderRadius: 8, border: '2px solid #10b981' }} />
        ) : (
          <div style={{ width: 160, height: 100, border: '2px dashed var(--border)', borderRadius: 8, display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'var(--text-muted)', fontSize: 12 }}>No photo</div>
        )}

        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {!camActive ? (
            <button type="button" onClick={startCamera} style={{ padding: '8px 14px', borderRadius: 8, background: '#0284c7', color: '#fff', border: 'none', cursor: 'pointer', fontWeight: 800, fontSize: 12 }}>📷 Start Camera</button>
          ) : (
            <button type="button" onClick={capturePhoto} style={{ padding: '8px 14px', borderRadius: 8, background: '#10b981', color: '#fff', border: 'none', cursor: 'pointer', fontWeight: 800, fontSize: 12 }}>📸 Capture Photo</button>
          )}
          <label style={{ padding: '6px 12px', borderRadius: 8, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)', fontWeight: 800, fontSize: 11, cursor: 'pointer', textAlign: 'center' }}>
            📁 Upload File
            <input type="file" accept="image/*" onChange={e => {
              const file = e.target.files[0]
              if (file) {
                const reader = new FileReader()
                reader.onload = ev => {
                  setPhotoPreview(ev.target.result)
                  setF(p => ({ ...p, photo_data: ev.target.result }))
                }
                reader.readAsDataURL(file)
              }
            }} style={{ display: 'none' }} />
          </label>
        </div>
      </div>

      {/* Worker Proof & Signature Card */}
      <h3 style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)', marginBottom: 10 }}>✍ Worker Signature / Fingerprint Proof</h3>
      <div style={{ borderRadius: 10, border: proofConfirmed ? '2px solid #10b981' : '2px solid #7c3aed', overflow: 'hidden', marginBottom: 20 }}>
        <div style={{ padding: '10px 14px', background: proofConfirmed ? '#dcfce7' : '#f3e8ff', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <strong style={{ fontSize: 13, color: proofConfirmed ? '#15803d' : '#6b21a8' }}>
            {worker.name} ({worker.worker_code || 'W-0001'})
          </strong>
          <span style={{ fontSize: 11, fontWeight: 800, padding: '2px 10px', borderRadius: 20, background: proofConfirmed ? '#10b981' : '#f59e0b', color: '#fff' }}>
            {proofConfirmed ? '✓ Proof Confirmed' : 'Pending Proof'}
          </span>
        </div>

        <div style={{ padding: 14, background: 'var(--bg-card)' }}>
          <div style={{ display: 'flex', gap: 8, marginBottom: 10 }}>
            <button type="button" onClick={() => setF(p => ({ ...p, proof_type: 'sig' }))} style={{ padding: '4px 12px', borderRadius: 20, border: 'none', background: f.proof_type === 'sig' ? '#7c3aed' : 'var(--bg-input)', color: f.proof_type === 'sig' ? '#fff' : 'var(--text-muted)', fontWeight: 800, fontSize: 11, cursor: 'pointer' }}>✍ Signature Pad</button>
            <button type="button" onClick={() => setF(p => ({ ...p, proof_type: 'thumb' }))} style={{ padding: '4px 12px', borderRadius: 20, border: 'none', background: f.proof_type === 'thumb' ? '#7c3aed' : 'var(--bg-input)', color: f.proof_type === 'thumb' ? '#fff' : 'var(--text-muted)', fontWeight: 800, fontSize: 11, cursor: 'pointer' }}>👍 Thumb Impression</button>
          </div>

          <canvas ref={canvasRef} width={500} height={140} onMouseDown={startCanvasDraw} onMouseMove={doCanvasDraw} onMouseUp={stopCanvasDraw} onMouseLeave={stopCanvasDraw} onTouchStart={startCanvasDraw} onTouchMove={doCanvasDraw} onTouchEnd={stopCanvasDraw} style={{ background: '#fff', border: '2px dashed var(--border)', borderRadius: 8, cursor: 'crosshair', display: 'block', marginBottom: 10 }} />

          <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
            <button type="button" onClick={clearCanvas} style={{ padding: '6px 14px', borderRadius: 6, background: '#ef4444', color: '#fff', border: 'none', cursor: 'pointer', fontSize: 11, fontWeight: 800 }}>Clear</button>
            <button type="button" onClick={confirmProof} style={{ padding: '6px 14px', borderRadius: 6, background: '#10b981', color: '#fff', border: 'none', cursor: 'pointer', fontSize: 11, fontWeight: 800 }}>Confirm {f.proof_type === 'sig' ? 'Signature' : 'Thumb'}</button>
          </div>
        </div>
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
          api={api}
        />
      )}
    </Panel>
  )
}

function WizardGroupInductionModal({ workers, onClose, onCompleted, api }) {
  const [selectedIds, setSelectedIds] = useState(workers.map(w => w.id))
  const [f, setF] = useState({
    induction_type: 'General Safety',
    trainer: 'Safety Officer – Rahul Sharma',
    custom_trainer: '',
    location: 'Site Office',
  })

  const [workerProofs, setWorkerProofs] = useState(
    Object.fromEntries(workers.map(w => [w.id, { done: false, proofType: 'sig', sigData: '' }]))
  )
  const [activeWid, setActiveWid] = useState(workers[0]?.id || null)
  const [saving, setSaving] = useState(false)
  const [progressMsg, setProgressMsg] = useState('')

  const canvasRef = useRef(null)
  const isDrawing = useRef(false)

  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))

  const TRAINER_PRESETS = [
    { group: 'Safety Team', items: ['Safety Officer – Rahul Sharma', 'Safety Supervisor – Priya Patel', 'HSE Lead – Amit Verma', 'HSSE Manager – Neha Singh', 'Safety Inspector – Ravi Kumar'] },
    { group: 'HR Team', items: ['HR Manager – Sunita Joshi', 'HR Executive – Deepak Nair', 'HR Coordinator – Anjali Mehta'] },
    { group: 'Site Management', items: ['Site Engineer – Vikram Rao', 'Project Manager – Suresh Pillai', 'Site Supervisor – Mohan Das'] },
    { group: 'Custom', items: ['Other / Custom Trainer...'] },
  ]

  const startCanvasDraw = (e) => {
    const canvas = canvasRef.current; if (!canvas) return
    const ctx = canvas.getContext('2d')
    const rect = canvas.getBoundingClientRect()
    const x = (e.clientX || e.touches?.[0]?.clientX) - rect.left
    const y = (e.clientY || e.touches?.[0]?.clientY) - rect.top
    ctx.beginPath(); ctx.moveTo(x, y)
    isDrawing.current = true
  }

  const doCanvasDraw = (e) => {
    if (!isDrawing.current) return
    const canvas = canvasRef.current; if (!canvas) return
    const ctx = canvas.getContext('2d')
    const rect = canvas.getBoundingClientRect()
    const x = (e.clientX || e.touches?.[0]?.clientX) - rect.left
    const y = (e.clientY || e.touches?.[0]?.clientY) - rect.top
    ctx.lineTo(x, y); ctx.stroke()
  }

  const stopCanvasDraw = () => { isDrawing.current = false }
  const clearCanvas = () => {
    const canvas = canvasRef.current; if (!canvas) return
    canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height)
  }

  const confirmWorkerProof = (wid) => {
    const canvas = canvasRef.current
    if (!canvas) return
    const url = canvas.toDataURL('image/png')
    setWorkerProofs(p => ({ ...p, [wid]: { ...p[wid], sigData: url, done: true } }))
    clearCanvas()
    const pending = workers.find(w => selectedIds.includes(w.id) && w.id !== wid && !workerProofs[w.id]?.done)
    if (pending) setActiveWid(pending.id)
  }

  const activeWorkers = workers.filter(w => selectedIds.includes(w.id))
  const signedCount = activeWorkers.filter(w => workerProofs[w.id]?.done).length
  const allSigned = signedCount === activeWorkers.length && activeWorkers.length > 0

  const saveGroupInduction = async () => {
    const finalTrainer = f.trainer === 'Other / Custom Trainer...' ? f.custom_trainer : f.trainer
    if (!finalTrainer.trim()) { alert('Trainer Name is required.'); return }
    if (!f.location.trim()) { alert('Location is required.'); return }
    if (!allSigned) { alert('All selected workers must confirm signature first.'); return }

    setSaving(true)
    try {
      const now = new Date()
      let count = 0
      for (const w of activeWorkers) {
        count++
        setProgressMsg(`Saving worker ${count}/${activeWorkers.length}: ${w.name}...`)
        const proof = workerProofs[w.id]
        const payload = {
          induction_type: f.induction_type,
          trainer: finalTrainer,
          location: f.location,
          start_time: now.toISOString(),
          end_time: now.toISOString(),
          duration_minutes: 15,
          signature_data: proof?.proofType === 'sig' ? proof?.sigData : null,
          thumb_data: proof?.proofType === 'thumb' ? proof?.sigData : null,
          topics: ['Site Safety Rules', 'PPE Usage', 'Emergency Response'],
          passed: true,
        }
        await api.workers.saveInduction(w.id, payload)
      }
      alert(`Group induction completed for ${activeWorkers.length} workers!`)
      onCompleted()
    } catch (e) {
      alert(e?.response?.data?.message || 'Group induction save failed')
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
            {w.name} ({w.worker_code})
          </label>
        ))}
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 12, marginBottom: 14 }}>
        <Field label="Induction Type *">
          <SelectInput value={f.induction_type} onChange={set('induction_type')} pairs options={[
            ['General Safety', 'General Safety'],
            ['Activity Specific', 'Activity Specific'],
            ['Site Specific', 'Site Specific'],
            ['Client Specific', 'Client Specific'],
            ['Emergency & Evacuation', 'Emergency & Evacuation'],
            ['Fire Safety', 'Fire Safety'],
            ['PPE Usage', 'PPE Usage'],
            ['Toolbox Talk', 'Toolbox Talk'],
          ]} />
        </Field>
        <Field label="Trainer *">
          <select value={f.trainer} onChange={set('trainer')} style={inputStyle}>
            {TRAINER_PRESETS.map(grp => (
              <optgroup key={grp.group} label={grp.group}>
                {grp.items.map(item => <option key={item} value={item}>{item}</option>)}
              </optgroup>
            ))}
          </select>
        </Field>
        <Field label="Location *"><TextInput value={f.location} onChange={set('location')} placeholder="e.g. Site Office" /></Field>
      </div>

      {/* Progress */}
      <div style={{ padding: 10, borderRadius: 8, background: '#f3e8ff', marginBottom: 14, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <strong style={{ fontSize: 12, color: '#6b21a8' }}>✍ Signature Progress</strong>
        <strong style={{ fontSize: 12, color: '#6b21a8' }}>{signedCount} / {activeWorkers.length} Signed</strong>
      </div>

      {/* Cards */}
      <div style={{ maxHeight: 300, overflowY: 'auto', marginBottom: 14 }}>
        {activeWorkers.map(w => {
          const proof = workerProofs[w.id] || {}
          const isActive = activeWid === w.id
          return (
            <div key={w.id} style={{ borderRadius: 8, border: proof.done ? '2px solid #10b981' : isActive ? '2px solid #0284c7' : '1px solid var(--border)', marginBottom: 8, background: 'var(--bg-card)' }}>
              <div onClick={() => setActiveWid(isActive ? null : w.id)} style={{ padding: '8px 12px', background: proof.done ? '#dcfce7' : isActive ? '#e0f2fe' : 'var(--bg-input)', cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                <strong style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{w.name} ({w.worker_code})</strong>
                <span style={{ fontSize: 10.5, fontWeight: 800, padding: '2px 8px', borderRadius: 20, background: proof.done ? '#10b981' : '#f59e0b', color: '#fff' }}>
                  {proof.done ? '✓ Signed' : 'Pending'}
                </span>
              </div>
              {isActive && (
                <div style={{ padding: 12 }}>
                  <canvas ref={canvasRef} width={480} height={110} onMouseDown={startCanvasDraw} onMouseMove={doCanvasDraw} onMouseUp={stopCanvasDraw} onMouseLeave={stopCanvasDraw} onTouchStart={startCanvasDraw} onTouchMove={doCanvasDraw} onTouchEnd={stopCanvasDraw} style={{ background: '#fff', border: '2px dashed var(--border)', borderRadius: 8, cursor: 'crosshair', display: 'block', marginBottom: 8 }} />
                  <div style={{ display: 'flex', gap: 8 }}>
                    <button type="button" onClick={clearCanvas} style={{ padding: '4px 10px', borderRadius: 6, background: '#ef4444', color: '#fff', border: 'none', cursor: 'pointer', fontSize: 11, fontWeight: 800 }}>Clear</button>
                    <button type="button" onClick={() => confirmWorkerProof(w.id)} style={{ padding: '4px 12px', borderRadius: 6, background: '#10b981', color: '#fff', border: 'none', cursor: 'pointer', fontSize: 11, fontWeight: 800 }}>Confirm Signature</button>
                  </div>
                </div>
              )}
            </div>
          )
        })}
      </div>

      {progressMsg && <div style={{ padding: '6px 12px', background: '#e0f2fe', color: '#0369a1', fontSize: 12, fontWeight: 700, marginBottom: 10 }}>⏳ {progressMsg}</div>}
      <ModalFooter onClose={onClose} onConfirm={saveGroupInduction} loading={saving} disabled={!allSigned} confirmLabel={`Save Group Induction (${activeWorkers.length} Workers)`} />
    </Overlay>
  )
}

/**
 * The role's PPE requirements, ticked off against what the worker holds.
 *
 * Requirements come from the PPE matrix (role → items), and "issued" comes from
 * Inventory. Nothing here decides anything — it renders the same verdict the API
 * refuses the badge on.
 */
function PpeChecklist({ c }) {
  return (
    <div style={{ marginTop: 12, paddingTop: 12, borderTop: '1px solid rgba(245,158,11,0.25)' }}>
      <p style={{ margin: '0 0 8px', fontSize: 10.5, fontWeight: 800, letterSpacing: '.04em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>
        Missing Mandatory PPE{c.role ? ` · ${c.role}` : ''}
      </p>
      <ul style={{ margin: 0, padding: 0, listStyle: 'none', display: 'grid', gap: 5 }}>
        {c.items.map(i => (
          <li key={i.product_id} style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12.5, fontWeight: 600, color: i.issued ? '#0ca30c' : '#d03b3b' }}>
            <span style={{ fontWeight: 900 }}>{i.issued ? '✓' : '✗'}</span>
            <span style={{ color: 'var(--text-h)' }}>{i.name}</span>
            {i.qty > 1 && <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>×{i.qty}</span>}
          </li>
        ))}
      </ul>
    </div>
  )
}

// ── Step 4 — PPE, issued from Inventory ──────────────────────────────────────
/**
 * The catalogue and the worker's kit, both read from Inventory.
 *
 * This step used to carry its own hardcoded stock grid (Safety Helmet: 150, …)
 * and a "Restock" button that wrote to a cache — a second PPE inventory. Both are
 * gone: issuing goes through the PPE service, which checks and decrements real
 * Inventory stock and writes a movement, and the step's own status is derived
 * from the issues rather than set by hand.
 */
function StepPpe({ worker, editable, manage, onChanged, onNext, api, compliance }) {
  const ppeStatus = Number(worker.ppe_status || 0) // 0 pending · 1 issued · 2 skipped
  const [skipping, setSkipping] = useState(false)

  const skipPpe = async () => {
    if (!window.confirm('Skip PPE verification for this worker?')) return
    setSkipping(true)
    try {
      await api.workers.skipPpe(worker.id)
      onChanged()
      if (onNext) onNext()
    } catch (e) {
      alert(e?.response?.data?.message || 'Failed to skip PPE')
    } finally {
      setSkipping(false)
    }
  }

  const banner = ppeStatus === 1
    ? { bg: '#dcfce7', border: '#86efac', pill: '#10b981', label: '✓ PPE Issued' }
    : ppeStatus === 2
      ? { bg: '#fef3c7', border: '#fde68a', pill: '#f59e0b', label: '⏩ PPE Skipped' }
      : { bg: '#e0f2fe', border: '#7dd3fc', pill: '#0284c7', label: 'Pending PPE' }

  return (
    <Panel title="Step 4 — Statutory PPE Kit" sub="Stock, issuance and returns, read from and written to Inventory"
      actions={
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          {/* Skipping the step is an admin decision, not a stock action. */}
          {editable && manage && ppeStatus !== 1 && (
            <button type="button" onClick={skipPpe} disabled={skipping}
              style={{ padding: '8px 14px', borderRadius: 8, background: '#f59e0b', color: '#fff', border: 'none', cursor: skipping ? 'wait' : 'pointer', fontWeight: 800, fontSize: 12 }}>
              ⏩ Skip PPE
            </button>
          )}
          <button type="button" onClick={onNext}
            style={{
              padding: '8px 16px', borderRadius: 9, border: 'none',
              background: 'linear-gradient(135deg, #0284c7, #0369a1)', color: '#fff',
              fontWeight: 800, fontSize: 12.5, cursor: 'pointer',
              display: 'inline-flex', alignItems: 'center', gap: 6,
            }}>
            Continue → Step 5 (Card Status)
          </button>
        </div>
      }>

      <div style={{ padding: '12px 18px', borderRadius: 10, background: banner.bg, border: `1.5px solid ${banner.border}`, marginBottom: 18, display: 'flex', flexWrap: 'wrap', gap: 18, alignItems: 'center' }}>
        <div><span style={{ fontSize: 9, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase' }}>Worker</span><br /><strong style={{ color: 'var(--text-h)', fontSize: 13 }}>{worker.name} ({worker.worker_code || '—'})</strong></div>
        <div><span style={{ fontSize: 9, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase' }}>Designation</span><br /><strong style={{ color: 'var(--text-h)', fontSize: 13 }}>{worker.designation || 'Worker'}</strong></div>
        <div style={{ marginLeft: 'auto' }}>
          <span style={{ fontSize: 11, fontWeight: 800, padding: '4px 12px', borderRadius: 20, background: banner.pill, color: '#fff' }}>{banner.label}</span>
        </div>
      </div>

      {/* What the worker's ROLE requires, ticked off against what they hold. */}
      {compliance?.configured && (
        <div style={{ padding: '12px 16px', borderRadius: 10, marginBottom: 18, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <PpeChecklist c={compliance} />
        </div>
      )}

      {/* What this worker is holding, with partial return / lost / damaged. */}
      <div style={{ marginBottom: 22 }}>
        <WorkerPpePanel workerId={worker.id} api={api} accent="#f59e0b" canManage={editable} onChanged={onChanged} />
      </div>

      {/* Live Inventory stock — issuing from here moves it. */}
      <h3 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', margin: '0 0 12px' }}>Issue from Inventory</h3>
      <PpeCatalogue
        api={api}
        accent="#f59e0b"
        canIssue={editable}
        linkInventory={api === tpvApi}
        workers={[{ id: worker.id, name: worker.name, full_name: worker.name, worker_code: worker.worker_code }]}
        onIssued={onChanged}
      />
    </Panel>
  )
}

// ── Step 5 — Badge + QR ──────────────────────────────────────────────────────
// ── Step 5 — Access Control 3D Pass & Card Status ──────────────────────────────
function StepBadge({ worker, progress, admin, onChanged, api }) {
  const [isFlipped, setIsFlipped] = useState(false)
  const [punchModalOpen, setPunchModalOpen] = useState(false)
  const [punchReason, setPunchReason] = useState('')
  const [punchErr, setPunchErr] = useState('')
  const [savingPunch, setSavingPunch] = useState(false)

  const [editModalOpen, setEditModalOpen] = useState(false)
  const [editData, setEditData] = useState({
    name: worker.name || '',
    designation: worker.designation || '',
    mobile: worker.mobile || '',
    blood_group: worker.blood_group || '',
    gender: worker.gender || '',
  })
  const [savingEdit, setSavingEdit] = useState(false)

  // ── Real status-driven state (NOT punch-count) ──
  const isActive     = worker.status === WORKER_STATUS.ACTIVE
  const isSuspended  = worker.status === WORKER_STATUS.SUSPENDED
  const isTerminated = worker.status === WORKER_STATUS.TERMINATED
  const isDraft      = worker.status === WORKER_STATUS.DRAFT
  const hasBadge     = isActive || isSuspended || isTerminated   // a badge was issued
  const blockers     = progress?.blockers || []
  const canActivate  = admin && isDraft && blockers.length === 0

  const pc = parseInt(worker.punch_count || 0)
  const isPpeIssued = parseInt(worker.ppe_status || 0) >= 1

  // Real badge fields from the server (no more hardcoded validity).
  const validTo   = worker.badge_valid_until ? fmtDate(worker.badge_valid_until) : '—'
  const badgeNo   = worker.badge_number || '—'
  const workerCode = worker.worker_code || `W-${String(worker.id).padStart(5, '0')}`

  // ── Badge issuance (admin) + real QR from the server token ──
  const [activating, setActivating] = useState(false)
  const [qrToken, setQrToken]       = useState(null)   // revealed once, admin only
  const [qrImg, setQrImg]           = useState(null)   // locally-rendered QR image

  // Reveal the real badge token for an Active/badged worker (admin-only, audited
  // server-side). The vendor cannot reveal it — the printable QR is admin-issued.
  useEffect(() => {
    let alive = true
    if (hasBadge && admin && !qrToken) {
      api.workers.badge(worker.id)
        .then(b => { if (alive && b?.qr_token) setQrToken(b.qr_token) })
        .catch(() => {})
    }
    return () => { alive = false }
  }, [hasBadge, admin, worker.id]) // eslint-disable-line react-hooks/exhaustive-deps

  // Render the QR locally from the real token → the gate scan URL. No external
  // service, and it encodes the actual credential the gate resolves on.
  useEffect(() => {
    if (qrToken) {
      QRCode.toDataURL(`${window.location.origin}/scan/${qrToken}`, { width: 220, margin: 1 })
        .then(setQrImg).catch(() => {})
    } else {
      setQrImg(null)
    }
  }, [qrToken])

  const handleActivate = async () => {
    setActivating(true)
    try {
      const res = await api.workers.activate(worker.id)
      if (res?.qr_token) setQrToken(res.qr_token)   // surfaced once, at issue
      onChanged()
    } catch (e) {
      alert(e?.response?.data?.message || 'Could not issue the entry badge.')
    } finally {
      setActivating(false)
    }
  }

  // Build activity log from worker punch_log or default fields
  let punchLog = []
  try {
    if (worker.punch_log) punchLog = typeof worker.punch_log === 'string' ? JSON.parse(worker.punch_log) : worker.punch_log
  } catch (e) {}

  if (!punchLog || punchLog.length === 0) {
    for (let p = 1; p <= pc; p++) {
      punchLog.push({
        num: p,
        at: worker[`punch_${p}_at`] || new Date().toISOString(),
        reason: worker[`punch_${p}_reason`] || 'Safety non-compliance violation',
      })
    }
  }

  const handleConfirmPunch = async () => {
    if (!punchReason.trim()) {
      setPunchErr('Please enter a violation reason before confirming.')
      return
    }
    setPunchErr('')
    setSavingPunch(true)
    try {
      await api.workers.markPunch(worker.id, pc + 1, punchReason)
      setPunchModalOpen(false)
      setPunchReason('')
      onChanged()
    } catch (e) {
      alert(e?.response?.data?.message || 'Failed to record punch')
    } finally {
      setSavingPunch(false)
    }
  }

  const handleSaveEdit = async () => {
    setSavingEdit(true)
    try {
      await api.workers.update(worker.id, editData)
      setEditModalOpen(false)
      onChanged()
    } catch (e) {
      alert(e?.response?.data?.message || 'Failed to update worker info')
    } finally {
      setSavingEdit(false)
    }
  }

  const verifyUrl = qrToken ? `${window.location.origin}/scan/${qrToken}` : null

  const sendWhatsApp = () => {
    if (!verifyUrl) return
    const text = `🪪 *HSSE ACCESS CONTROL PASS*\n\n`
      + `*Worker Name:* ${worker.name}\n`
      + `*Worker Code:* ${workerCode}\n`
      + `*Badge No:* ${badgeNo}\n`
      + `*Designation:* ${worker.designation || 'Worker'}\n`
      + `*Valid Until:* ${validTo}\n`
      + `*Status:* ${isTerminated ? '⛔ Terminated (Access Revoked)' : isSuspended ? '⏸ Suspended' : '✓ Active Access'}\n`
      + `*Verify Pass:* ${verifyUrl}`
    window.open(`https://wa.me/?text=${encodeURIComponent(text)}`, '_blank')
  }

  const printCard = () => {
    if (!qrImg) return
    const win = window.open('', '_blank', 'width=600,height=700')
    win.document.write(`
      <html>
        <head><title>Print Access Pass - ${worker.name}</title></head>
        <body style="font-family:sans-serif; text-align:center; padding:30px; background:#0b1622; color:#fff;">
          <h2 style="color:#d4af37;">HSSE ACCESS CONTROL CARD</h2>
          <div style="border:2px solid #d4af37; border-radius:16px; padding:20px; max-width:380px; margin:0 auto; background:#162740;">
            <h3 style="margin:0 0 6px;">${worker.name}</h3>
            <p style="color:#00d4ff; font-weight:bold; margin:0 0 4px;">${worker.designation || 'Worker'} (${workerCode})</p>
            <p style="color:#a78bfa; font-size:12px; margin:0 0 12px;">Badge: ${badgeNo}</p>
            <img src="${qrImg}" width="160" style="background:#fff; padding:8px; border-radius:8px;" />
            <p style="font-size:12px; color:#a78bfa; margin-top:12px;">VALID UNTIL: ${validTo}</p>
          </div>
          <script>window.onload = function() { window.print(); window.close(); }</script>
        </body>
      </html>
    `)
  }

  // ── Draft worker: no badge yet — show issuance panel, not a fake pass ──
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
                All readiness gates are cleared. Issuing the badge activates the worker for site access and generates their QR access credential.
              </p>
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
                Resolve the {blockers.length} blocking item{blockers.length === 1 ? '' : 's'} listed at the top of this page (medical, induction, PPE, profile) before the entry badge can be issued.
              </p>
            </>
          ) : (
            <>
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
    <Panel title="Step 5 — Access Control Pass & Credentials" sub="Site entry pass · real QR access credential · 3-punch violation enforcement"
      actions={qrImg ? (
        <div style={{ display: 'flex', gap: 8 }}>
          <button type="button" onClick={sendWhatsApp} style={{ padding: '7px 12px', borderRadius: 8, background: '#10b981', color: '#fff', border: 'none', cursor: 'pointer', fontWeight: 800, fontSize: 11.5, display: 'inline-flex', alignItems: 'center', gap: 5 }}>
            💬 Send WhatsApp Pass
          </button>
          <button type="button" onClick={printCard} style={{ padding: '7px 12px', borderRadius: 8, background: 'var(--bg-card)', color: 'var(--text-h)', border: '1px solid var(--border)', cursor: 'pointer', fontWeight: 800, fontSize: 11.5, display: 'inline-flex', alignItems: 'center', gap: 5 }}>
            🖨️ Print Pass
          </button>
        </div>
      ) : null}>

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
                  <span style={{ fontSize: 9, fontWeight: 800, padding: '3px 8px', borderRadius: 4, background: isPpeIssued ? 'rgba(34,197,94,0.3)' : 'rgba(239,68,68,0.3)', color: isPpeIssued ? '#4ade80' : '#fca5a5', border: `1.5px solid ${isPpeIssued ? '#22c55e' : '#ef4444'}` }}>
                    {isPpeIssued ? '✓ PPE ISSUED' : '✕ NO PPE'}
                  </span>
                  <div style={{ width: 36, height: 26, background: 'linear-gradient(135deg,#e8c96a,#c9a53e)', borderRadius: 4, boxShadow: '0 2px 8px rgba(0,0,0,0.4)' }}></div>
                </div>
              </div>

              {/* Mid Info Section */}
              <div style={{ display: 'flex', gap: 14, alignItems: 'flex-start', marginBottom: 10 }}>
                <div style={{ width: 64, height: 72, borderRadius: 10, border: '2px solid rgba(212,175,55,0.45)', background: 'linear-gradient(145deg,#1a2e45,#203756)', overflow: 'hidden', flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', color: 'rgba(255,255,255,0.3)', fontSize: 24 }}>
                  {worker.photo ? <img src={worker.photo} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} /> : '👤'}
                </div>

                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontWeight: 900, fontSize: 18, color: '#fff', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', marginBottom: 2 }}>{worker.name}</div>
                  <div style={{ fontSize: 10, color: '#00d4ff', fontWeight: 800, letterSpacing: 1.5, textTransform: 'uppercase', marginBottom: 4 }}>{worker.designation || 'Site Worker'}</div>
                  <div style={{ fontFamily: 'monospace', fontSize: 11, color: '#d4af37', letterSpacing: 1 }}>{workerCode} · {badgeNo}</div>

                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: '2px 8px', marginTop: 4, fontSize: 9, color: 'rgba(255,255,255,0.4)' }}>
                    <div>DOB <strong style={{ color: '#fff' }}>{worker.dob ? fmtDate(worker.dob) : '-'}</strong></div>
                    <div>Age <strong style={{ color: '#fff' }}>{worker.age != null ? worker.age : '-'}</strong></div>
                    <div>Gender <strong style={{ color: '#fff' }}>{worker.gender || '-'}</strong></div>
                    <div>Blood <strong style={{ color: '#fff' }}>{worker.blood_group || '-'}</strong></div>
                    <div style={{ gridColumn: 'span 2' }}>Valid To <strong style={{ color: '#fff' }}>{validTo}</strong></div>
                  </div>
                </div>
              </div>

              {/* 3-Punch Violation Strip */}
              <div style={{ padding: '6px 12px', background: 'rgba(0,0,0,0.3)', borderRadius: 8, border: '1px solid rgba(255,255,255,0.07)', display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 }}>
                {[1, 2, 3].map(p => {
                  const filled = p <= pc
                  const color = p === 1 ? '#f5c518' : p === 2 ? '#f07030' : '#dc2626'
                  return (
                    <div key={p} style={{
                      width: 24, height: 24, borderRadius: '50%', border: `2px solid ${filled ? color : 'rgba(255,255,255,0.2)'}`,
                      background: filled ? color : 'transparent', color: filled ? (p === 1 ? '#000' : '#fff') : 'rgba(255,255,255,0.3)',
                      display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11, fontWeight: 900
                    }}>
                      {filled ? (p === 3 ? '✕' : '!') : p}
                    </div>
                  )
                })}
                <span style={{ fontSize: 9, color: 'rgba(255,255,255,0.4)', letterSpacing: 1, textTransform: 'uppercase', marginLeft: 4 }}>
                  {pc}/3 Safety Punches
                </span>
              </div>

              {/* Status Banner — driven by the real worker status */}
              {(() => {
                const tone = isTerminated ? ['rgba(220,38,38,0.15)', '#dc2626', 'rgba(220,38,38,0.4)']
                  : isSuspended ? ['rgba(245,158,11,0.15)', '#f59e0b', 'rgba(245,158,11,0.4)']
                  : pc === 2 ? ['rgba(240,112,48,0.15)', '#f07030', 'rgba(240,112,48,0.4)']
                  : pc === 1 ? ['rgba(245,197,24,0.15)', '#f5c518', 'rgba(245,197,24,0.4)']
                  : ['rgba(34,197,94,0.15)', '#22c55e', 'rgba(34,197,94,0.4)']
                const label = isTerminated ? '⛔ Access Terminated' : isSuspended ? '⏸ Access Suspended'
                  : pc === 2 ? '🚨 Final Warning — Last Chance' : pc === 1 ? '⚠ Punch 1 Recorded' : '✓ Access Active'
                return (
                  <div style={{ padding: '4px 0', borderRadius: 6, textAlign: 'center', fontSize: 9.5, fontWeight: 900, letterSpacing: 1.5, textTransform: 'uppercase', background: tone[0], color: tone[1], border: `1px solid ${tone[2]}` }}>
                    {label}
                  </div>
                )
              })()}
            </div>

            {/* BACK FACE (QR CODE) */}
            <div style={{
              position: 'absolute', width: '100%', height: '100%', borderRadius: 20, backfaceVisibility: 'hidden', overflow: 'hidden',
              background: 'linear-gradient(145deg, #07101a 0%, #0e1b2b 100%)', transform: 'rotateY(180deg)',
              boxShadow: '0 30px 70px rgba(0,0,0,0.6), 0 0 0 1px rgba(212,175,55,0.18)',
              display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 6, padding: 16
            }}>
              <div style={{ fontSize: 10, fontWeight: 700, color: 'rgba(255,255,255,0.4)', letterSpacing: 2, textTransform: 'uppercase' }}>Scan QR to Verify Access</div>
              {qrImg ? (
                <div style={{ background: '#fff', padding: 8, borderRadius: 10, boxShadow: '0 0 24px rgba(0,212,255,0.2)' }}>
                  <img src={qrImg} alt="QR Code" style={{ width: 120, height: 120, display: 'block' }} />
                </div>
              ) : (
                <div style={{ width: 136, height: 136, borderRadius: 10, border: '1px dashed rgba(255,255,255,0.25)', display: 'flex', alignItems: 'center', justifyContent: 'center', textAlign: 'center', padding: 10, fontSize: 9.5, color: 'rgba(255,255,255,0.45)', lineHeight: 1.5 }}>
                  Entry QR is issued by the site administrator
                </div>
              )}
              <div style={{ fontFamily: 'monospace', fontSize: 12, color: '#d4af37', letterSpacing: 2 }}>{badgeNo}</div>
              <div style={{ fontSize: 8.5, color: 'rgba(255,255,255,0.3)', letterSpacing: 1 }}>VALID UNTIL {validTo}</div>
            </div>

          </div>
        </div>

        <div style={{ fontSize: 11.5, color: 'var(--text-muted)', display: 'flex', alignItems: 'center', gap: 5 }}>
          🔄 Click card to flip and view QR Code
        </div>
      </div>

      {/* Pass Actions Bar */}
      <div style={{ display: 'flex', gap: 10, justifyContent: 'center', marginBottom: 24, flexWrap: 'wrap' }}>
        {isActive ? (
          <button type="button" onClick={() => { setPunchErr(''); setPunchModalOpen(true) }}
            style={{ padding: '8px 18px', borderRadius: 9, background: '#f59e0b', color: '#fff', border: 'none', cursor: 'pointer', fontWeight: 800, fontSize: 12, display: 'inline-flex', alignItems: 'center', gap: 6 }}>
            ✊ Record Punch {pc}/3
          </button>
        ) : isSuspended ? (
          <span style={{ padding: '8px 18px', borderRadius: 9, background: '#f59e0b', color: '#fff', fontWeight: 900, fontSize: 12 }}>
            ⏸ Access Suspended
          </span>
        ) : (
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

        {punchLog.length === 0 ? (
          <div style={{ fontSize: 12, color: 'var(--text-muted)', fontStyle: 'italic' }}>No safety violations recorded — clean record ✓</div>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            {punchLog.map((pl, idx) => (
              <div key={idx} style={{ padding: '8px 12px', borderRadius: 8, background: 'var(--bg-card)', border: '1px solid var(--border)', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                <div>
                  <strong style={{ fontSize: 12, color: pl.num === 3 ? '#ef4444' : pl.num === 2 ? '#f97316' : '#f59e0b' }}>
                    Punch {pl.num}{pl.num === 3 ? ' — Access Terminated' : ''}
                  </strong>
                  {pl.reason && <span style={{ fontSize: 11.5, color: 'var(--text-muted)', marginLeft: 8 }}>({pl.reason})</span>}
                </div>
                <span style={{ fontSize: 11, color: 'var(--text-muted)', fontFamily: 'monospace' }}>
                  {pl.at ? new Date(pl.at).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'Date not recorded'}
                </span>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Record Punch Modal */}
      {punchModalOpen && (
        <Overlay onClose={() => setPunchModalOpen(false)} width={420}>
          <h3 style={{ fontSize: 17, fontWeight: 900, color: '#f59e0b', margin: '0 0 6px' }}>✊ Record Safety Punch ({pc + 1}/3)</h3>
          <p style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 14 }}>
            Recording a punch logs a safety violation. Reaching 3 punches automatically revokes site access and terminates worker status.
          </p>

          <Field label="Violation Reason / Details *">
            <TextInput value={punchReason} onChange={e => setPunchReason(e.target.value)} placeholder="e.g. Failure to wear safety harness at height, unauthorized entry..." />
          </Field>
          {punchErr && <div style={{ fontSize: 11, color: '#ef4444', marginTop: 4 }}>{punchErr}</div>}

          <ModalFooter onClose={() => setPunchModalOpen(false)} onConfirm={handleConfirmPunch} loading={savingPunch} confirmLabel={`Confirm Punch ${pc + 1}/3`} />
        </Overlay>
      )}

      {/* Edit Worker Modal */}
      {editModalOpen && (
        <Overlay onClose={() => setEditModalOpen(false)} width={460}>
          <h3 style={{ fontSize: 16, fontWeight: 900, color: 'var(--text-h)', margin: '0 0 14px' }}>✏ Edit Worker Details</h3>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            <Field label="Full Name *">
              <TextInput value={editData.name} onChange={e => setEditData({ ...editData, name: e.target.value })} />
            </Field>
            <Field label="Designation">
              <TextInput value={editData.designation} onChange={e => setEditData({ ...editData, designation: e.target.value })} />
            </Field>
            <Field label="Mobile Number">
              <TextInput value={editData.mobile} onChange={e => setEditData({ ...editData, mobile: e.target.value })} />
            </Field>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
              <Field label="Blood Group">
                <TextInput value={editData.blood_group} onChange={e => setEditData({ ...editData, blood_group: e.target.value })} />
              </Field>
              <Field label="Gender">
                <TextInput value={editData.gender} onChange={e => setEditData({ ...editData, gender: e.target.value })} />
              </Field>
            </div>
          </div>
          <ModalFooter onClose={() => setEditModalOpen(false)} onConfirm={handleSaveEdit} loading={savingEdit} confirmLabel="Save Changes" />
        </Overlay>
      )}
    </Panel>
  )
}
