import { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import QRCode from 'qrcode'
import {
  ArrowLeft, RefreshCw, UserCheck, HeartPulse, GraduationCap, HardHat, QrCode,
  Check, AlertTriangle, ShieldCheck, Ban, RotateCcw, Trash2, Plus, Loader, Printer,
} from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { useAuth } from '@/context/AuthContext'
import AuditTimeline from '@/components/ui/AuditTimeline'
import {
  WORKER_STATUS, workerStatusCfg, vendorStatusCfg, fitnessCfg, FITNESS, BAND_COLORS,
  PPE_ITEMS, PPE_MANDATORY, ppeLabel, SKILL_CATEGORIES, GENDERS,
  isWorkerEditable, canApproveTpv, canManageTpv, fmtDate,
} from '../constants'
import {
  KIT3D_STYLE, labelStyle, inputStyle, Overlay, ModalFooter, InfoBox,
  Field, TextInput, SelectInput, StatusBadge as StatusPill,
} from '@/components/ui/kit3d'

const STEP_ICONS  = { profile: UserCheck, medical: HeartPulse, induction: GraduationCap, ppe: HardHat, badge: QrCode }
const STEP_COLORS = { profile: '#0ea5e9', medical: '#ec4899', induction: '#8b5cf6', ppe: '#f59e0b', badge: '#10b981' }

// ── Main ─────────────────────────────────────────────────────────────────────
export default function TpvWorkerWizard() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const admin  = canApproveTpv(user)
  const manage = canManageTpv(user)

  const [worker, setWorker]     = useState(null)
  const [progress, setProgress] = useState(null)
  const [loading, setLoading]   = useState(true)
  const [active, setActive]     = useState(1)

  const load = useCallback(async (keepStep = false) => {
    try {
      const res = await tpvApi.workers.get(id)
      const w = res?.worker ?? res?.data?.worker
      const p = res?.progress ?? res?.data?.progress
      setWorker(w); setProgress(p)
      if (!keepStep) setActive(w?.current_step || 1)
    } catch (e) { console.error('Failed to load worker', e) }
    finally { setLoading(false) }
  }, [id])
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
        <button onClick={() => navigate('/app/tpv/workforce')}
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
        </div>
      )}
      {worker.status === WORKER_STATUS.TERMINATED && worker.remarks && (
        <InfoBox tone="danger"><strong>Terminated:</strong> {worker.remarks}</InfoBox>
      )}

      <Stepper steps={steps} active={active} onGo={setActive} />

      <div style={{ marginTop: 18 }}>
        {active === 1 && <StepProfile worker={worker} editable={editable} onSaved={refresh} />}
        {active === 2 && <StepMedical worker={worker} editable={editable} onSaved={refresh} />}
        {active === 3 && <StepInduction worker={worker} editable={editable} onSaved={refresh} />}
        {active === 4 && <StepPpe worker={worker} editable={editable} manage={manage} onChanged={refresh} />}
        {active === 5 && <StepBadge worker={worker} progress={progress} admin={admin} onChanged={refresh} />}
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
    <div className="pr-glass" style={{ padding: 16 }}>
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
function StepProfile({ worker, editable, onSaved }) {
  const [f, setF] = useState({
    name: worker.name || '', dob: worker.dob?.slice(0, 10) || '', gender: worker.gender || '',
    designation: worker.designation || '', skill_category: worker.skill_category || '',
    aadhar_number: worker.aadhar_number || '', mobile: worker.mobile || '', blood_group: worker.blood_group || '',
    address: worker.address || '', emergency_contact: worker.emergency_contact || '', emergency_phone: worker.emergency_phone || '',
  })
  const [saving, setSaving] = useState(false)
  const [saved, setSaved]   = useState(false)
  const set = (k) => (e) => { setF(p => ({ ...p, [k]: e.target.value })); setSaved(false) }

  // Mirror the backend's statutory floor so the user sees it before saving.
  const age = f.dob ? Math.floor((Date.now() - new Date(f.dob)) / 31557600000) : null
  const underage = age !== null && age < 18

  const save = async () => {
    setSaving(true)
    try {
      const payload = Object.fromEntries(Object.entries(f).map(([k, v]) => [k, v === '' ? null : v]))
      await tpvApi.workers.update(worker.id, payload)
      setSaved(true); onSaved()
    } catch (e) { alert(e?.response?.data?.message || 'Failed to save profile') }
    finally { setSaving(false) }
  }

  return (
    <Panel title="Worker Profile" sub="Identity, contact and skill classification"
      actions={editable && <SaveBtn onClick={save} saving={saving} saved={saved} label="Save Profile" />}>
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
        <Field label="Skill Category"><SelectInput value={f.skill_category} onChange={set('skill_category')} disabled={!editable} pairs options={[['', 'Select…'], ...SKILL_CATEGORIES]} /></Field>
        <Field label="Blood Group"><TextInput value={f.blood_group} onChange={set('blood_group')} disabled={!editable} placeholder="e.g. B+" /></Field>
        <Field label="Aadhar Number *"><TextInput value={f.aadhar_number} onChange={set('aadhar_number')} disabled={!editable} maxLength={12} placeholder="12 digits" /></Field>
        <Field label="Mobile *"><TextInput value={f.mobile} onChange={set('mobile')} disabled={!editable} /></Field>
        <Field label="Emergency Contact"><TextInput value={f.emergency_contact} onChange={set('emergency_contact')} disabled={!editable} placeholder="Name" /></Field>
        <Field label="Emergency Phone"><TextInput value={f.emergency_phone} onChange={set('emergency_phone')} disabled={!editable} /></Field>
        <Field label="Address" full><textarea value={f.address} onChange={set('address')} disabled={!editable} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></Field>
      </div>
    </Panel>
  )
}

// ── Step 2 — Medical + screening ─────────────────────────────────────────────
function StepMedical({ worker, editable, onSaved }) {
  const m = worker.medical || {}
  const [f, setF] = useState({
    exam_type: m.exam_type || 'internal', exam_date: m.exam_date?.slice(0, 10) || '', examiner_name: m.examiner_name || '',
    clinic_name: m.clinic_name || '', height_cm: m.height_cm ?? '', weight_kg: m.weight_kg ?? '',
    bp_systolic: m.bp_systolic ?? '', bp_diastolic: m.bp_diastolic ?? '', vision: m.vision || '',
    screening_score: m.screening_score ?? '', fitness_status: m.fitness_status || '', restrictions: m.restrictions || '',
  })
  const [saving, setSaving] = useState(false)
  const [saved, setSaved]   = useState(false)
  const set = (k) => (e) => { setF(p => ({ ...p, [k]: e.target.value })); setSaved(false) }

  // BMI mirrors the server's derivation (never stored).
  const bmi = (f.height_cm && f.weight_kg) ? (Number(f.weight_kg) / Math.pow(Number(f.height_cm) / 100, 2)).toFixed(1) : null
  // The band is authoritative from the server; this preview mirrors bandForScore().
  const score = f.screening_score === '' ? null : Number(f.screening_score)
  const band = score === null ? null : score >= 10 ? 'High' : score >= 5 ? 'Moderate' : 'Low'

  const save = async () => {
    if (!f.fitness_status) { alert('Record a fitness outcome.'); return }
    setSaving(true)
    try {
      const payload = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== null))
      await tpvApi.workers.saveMedical(worker.id, payload)
      setSaved(true); onSaved()
    } catch (e) { alert(e?.response?.data?.message || 'Failed to save medical') }
    finally { setSaving(false) }
  }

  return (
    <Panel title="Medical Examination" sub="Fitness assessment, physical measurements and wellbeing screening"
      actions={editable && <SaveBtn onClick={save} saving={saving} saved={saved} label="Save Medical" />}>
      {!editable && <InfoBox>Read-only — this worker is no longer editable.</InfoBox>}
      {f.fitness_status === 'Unfit' && <InfoBox tone="danger">An <strong>Unfit</strong> outcome permanently blocks the entry badge until re-examined.</InfoBox>}

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr 1fr', gap: 14, marginBottom: 16 }}>
        <Field label="Exam Type"><SelectInput value={f.exam_type} onChange={set('exam_type')} disabled={!editable} pairs options={[['internal', 'Internal'], ['external', 'External']]} /></Field>
        <Field label="Exam Date"><TextInput type="date" value={f.exam_date} onChange={set('exam_date')} disabled={!editable} /></Field>
        <Field label="Examiner"><TextInput value={f.examiner_name} onChange={set('examiner_name')} disabled={!editable} placeholder="Dr. name" /></Field>
        <Field label="Clinic"><TextInput value={f.clinic_name} onChange={set('clinic_name')} disabled={!editable} /></Field>
      </div>

      <label style={labelStyle}>Physical Measurements</label>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr 1fr 1fr', gap: 14, marginBottom: 8 }}>
        <Field label="Height (cm)"><TextInput type="number" value={f.height_cm} onChange={set('height_cm')} disabled={!editable} /></Field>
        <Field label="Weight (kg)"><TextInput type="number" value={f.weight_kg} onChange={set('weight_kg')} disabled={!editable} /></Field>
        <Field label="BP Systolic"><TextInput type="number" value={f.bp_systolic} onChange={set('bp_systolic')} disabled={!editable} /></Field>
        <Field label="BP Diastolic"><TextInput type="number" value={f.bp_diastolic} onChange={set('bp_diastolic')} disabled={!editable} /></Field>
        <Field label="Vision"><TextInput value={f.vision} onChange={set('vision')} disabled={!editable} placeholder="6/6" /></Field>
      </div>
      {bmi && (
        <div style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '5px 12px', borderRadius: 999, background: 'rgba(124,58,237,0.1)', border: '1px solid var(--border-purple)', marginBottom: 16 }}>
          <span style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 600 }}>BMI</span>
          <span style={{ fontSize: 13, fontWeight: 800, color: '#a78bfa' }}>{bmi}</span>
        </div>
      )}

      <label style={{ ...labelStyle, marginTop: 8 }}>Wellbeing Screening</label>
      <div style={{ display: 'flex', gap: 14, alignItems: 'flex-end', marginBottom: 16 }}>
        <div style={{ width: 200 }}>
          <Field label="Questionnaire Score (0–60)"><TextInput type="number" min="0" max="60" value={f.screening_score} onChange={set('screening_score')} disabled={!editable} /></Field>
        </div>
        {band && (
          <div style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '8px 14px', borderRadius: 10, background: `${BAND_COLORS[band]}18`, border: `1px solid ${BAND_COLORS[band]}55`, marginBottom: 1 }}>
            <span style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 600 }}>Risk band</span>
            <span style={{ fontSize: 13, fontWeight: 800, color: BAND_COLORS[band] }}>{band}</span>
            <span style={{ fontSize: 10.5, color: 'var(--text-muted)' }}>· banded server-side</span>
          </div>
        )}
      </div>

      <label style={labelStyle}>Outcome</label>
      <div style={{ display: 'grid', gridTemplateColumns: '260px 1fr', gap: 14 }}>
        <Field label="Fitness Status *"><SelectInput value={f.fitness_status} onChange={set('fitness_status')} disabled={!editable} pairs options={[['', 'Select outcome…'], ...FITNESS]} /></Field>
        <Field label="Restrictions"><TextInput value={f.restrictions} onChange={set('restrictions')} disabled={!editable} placeholder="e.g. No work at height" /></Field>
      </div>
      {m.fitness_status && (
        <div style={{ marginTop: 14, display: 'flex', alignItems: 'center', gap: 8 }}>
          <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>Recorded outcome:</span>
          <StatusPill cfg={fitnessCfg(m.fitness_status)} />
          {m.bmi && <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>BMI {m.bmi}</span>}
          {m.recorder?.name && <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>by {m.recorder.name}</span>}
        </div>
      )}
    </Panel>
  )
}

// ── Step 3 — HSSE induction ──────────────────────────────────────────────────
const TOPIC_PRESETS = ['Site Safety Rules', 'PPE Usage', 'Work at Height', 'Emergency Response', 'Fire Safety', 'First Aid', 'Manual Handling', 'Permit to Work']

function StepInduction({ worker, editable, onSaved }) {
  const ind = worker.induction || {}
  const [f, setF] = useState({
    trainer_name: ind.trainer_name || '', training_date: ind.training_date?.slice(0, 10) || '',
    duration_minutes: ind.duration_minutes ?? '', score: ind.score ?? '', passed: !!ind.passed,
  })
  const [topics, setTopics] = useState(ind.topics || [])
  const [saving, setSaving] = useState(false)
  const [saved, setSaved]   = useState(false)
  const set = (k) => (e) => { setF(p => ({ ...p, [k]: e.target.value })); setSaved(false) }
  const toggleTopic = (t) => { setTopics(p => p.includes(t) ? p.filter(x => x !== t) : [...p, t]); setSaved(false) }

  const save = async () => {
    setSaving(true)
    try {
      const payload = {
        ...Object.fromEntries(Object.entries(f).filter(([k, v]) => k !== 'passed' && v !== '' && v !== null)),
        topics, passed: !!f.passed,
      }
      await tpvApi.workers.saveInduction(worker.id, payload)
      setSaved(true); onSaved()
    } catch (e) { alert(e?.response?.data?.message || 'Failed to save induction') }
    finally { setSaving(false) }
  }

  return (
    <Panel title="HSSE Induction" sub="Site safety training record — the badge requires a pass"
      actions={editable && <SaveBtn onClick={save} saving={saving} saved={saved} label="Save Induction" />}>
      {!editable && <InfoBox>Read-only — this worker is no longer editable.</InfoBox>}

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr 1fr', gap: 14, marginBottom: 16 }}>
        <Field label="Trainer"><TextInput value={f.trainer_name} onChange={set('trainer_name')} disabled={!editable} /></Field>
        <Field label="Training Date"><TextInput type="date" value={f.training_date} onChange={set('training_date')} disabled={!editable} /></Field>
        <Field label="Duration (minutes)"><TextInput type="number" min="1" value={f.duration_minutes} onChange={set('duration_minutes')} disabled={!editable} /></Field>
        <Field label="Score (0–100)"><TextInput type="number" min="0" max="100" value={f.score} onChange={set('score')} disabled={!editable} /></Field>
      </div>

      <label style={labelStyle}>Topics Covered</label>
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginBottom: 18 }}>
        {TOPIC_PRESETS.map(t => {
          const on = topics.includes(t)
          return (
            <button key={t} onClick={() => editable && toggleTopic(t)} disabled={!editable}
              style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 12px', borderRadius: 999, fontSize: 11.5, fontWeight: 700, cursor: editable ? 'pointer' : 'default',
                background: on ? 'rgba(139,92,246,0.18)' : 'var(--bg-input)', color: on ? '#a78bfa' : 'var(--text-muted)',
                border: `1px solid ${on ? 'rgba(139,92,246,0.5)' : 'var(--border)'}` }}>
              {on && <Check size={11} />} {t}
            </button>
          )
        })}
      </div>

      {/* Pass/fail — the gate */}
      <label style={labelStyle}>Result</label>
      <div style={{ display: 'flex', gap: 10 }}>
        {[[true, 'Passed', '#10b981'], [false, 'Not Passed', '#ef4444']].map(([val, lbl, c]) => (
          <button key={lbl} onClick={() => editable && (setF(p => ({ ...p, passed: val })), setSaved(false))} disabled={!editable}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '10px 20px', borderRadius: 10, fontSize: 13, fontWeight: 800, cursor: editable ? 'pointer' : 'default',
              background: f.passed === val ? `linear-gradient(135deg, ${c}, ${c}bb)` : 'var(--bg-input)',
              color: f.passed === val ? '#fff' : 'var(--text-muted)',
              border: `1.5px solid ${f.passed === val ? c : 'var(--border)'}`,
              boxShadow: f.passed === val ? `0 8px 20px -6px ${c}88` : 'none' }}>
            {val ? <Check size={14} /> : <Ban size={14} />} {lbl}
          </button>
        ))}
      </div>
      {ind.recorder?.name && <p style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 12 }}>Recorded by {ind.recorder.name} · {fmtDate(ind.training_date)}</p>}
    </Panel>
  )
}

// ── Step 4 — PPE grid ────────────────────────────────────────────────────────
function StepPpe({ worker, editable, manage, onChanged }) {
  const issued = worker.ppe_issues || []
  const byItem = Object.fromEntries(issued.map(i => [i.item, i]))
  const [busy, setBusy] = useState(null)
  const [sizes, setSizes] = useState({})

  const issue = async (item) => {
    setBusy(item)
    try { await tpvApi.workers.issuePpe(worker.id, { item, size: sizes[item] || null }); onChanged() }
    catch (e) { alert(e?.response?.data?.message || 'Failed to issue PPE') }
    finally { setBusy(null) }
  }
  const remove = async (rec) => {
    if (!confirm(`Remove issued ${ppeLabel(rec.item)}?`)) return
    setBusy(rec.item)
    try { await tpvApi.workers.removePpe(worker.id, rec.id); onChanged() }
    catch (e) { alert(e?.response?.data?.message || 'Failed to remove') }
    finally { setBusy(null) }
  }

  const mandatoryIssued = PPE_MANDATORY.filter(i => byItem[i]).length

  return (
    <Panel title="PPE Issuance" sub="Mandatory items must be issued before the entry badge"
      actions={
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '5px 12px', borderRadius: 999, fontSize: 11.5, fontWeight: 700,
          background: mandatoryIssued === PPE_MANDATORY.length ? 'rgba(16,185,129,0.15)' : 'rgba(245,158,11,0.15)',
          color: mandatoryIssued === PPE_MANDATORY.length ? '#10b981' : '#f59e0b',
          border: `1px solid ${mandatoryIssued === PPE_MANDATORY.length ? 'rgba(16,185,129,0.4)' : 'rgba(245,158,11,0.4)'}` }}>
          {mandatoryIssued === PPE_MANDATORY.length ? <Check size={12} /> : <AlertTriangle size={12} />}
          {mandatoryIssued}/{PPE_MANDATORY.length} mandatory · {issued.length} issued
        </span>
      }>
      {!editable && <InfoBox>Read-only — this worker is no longer editable.</InfoBox>}
      <InfoBox>
        Stock is not decremented yet — the Inventory module is still being built, so each issue records an
        <code> inventory_item_id</code> placeholder for wiring later.
      </InfoBox>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 12 }}>
        {PPE_ITEMS.map(([item, label]) => {
          const rec = byItem[item]
          const mandatory = PPE_MANDATORY.includes(item)
          const on = !!rec
          const isBusy = busy === item
          return (
            <div key={item} className="pr-glass" style={{ padding: 14, borderRadius: 12, border: `1px solid ${on ? 'rgba(16,185,129,0.35)' : mandatory ? 'rgba(245,158,11,0.35)' : 'var(--border)'}` }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 9, marginBottom: 10 }}>
                <span style={{ width: 34, height: 34, borderRadius: 11, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0,
                  background: on ? 'linear-gradient(145deg,#34d399,#10b981)' : 'var(--bg-input)',
                  border: on ? 'none' : '1px solid var(--border)',
                  boxShadow: on ? '0 6px 14px -3px rgba(16,185,129,.6)' : 'none' }}>
                  <HardHat size={15} style={{ color: on ? '#fff' : 'var(--text-muted)' }} />
                </span>
                <div style={{ minWidth: 0, flex: 1 }}>
                  <div style={{ fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)' }}>{label}</div>
                  {mandatory && <span style={{ fontSize: 9.5, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.05em', color: '#f59e0b' }}>Mandatory</span>}
                </div>
                {on && <Check size={15} style={{ color: '#10b981', flexShrink: 0 }} />}
              </div>

              {on ? (
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <span style={{ fontSize: 11.5, color: 'var(--text-muted)', flex: 1 }}>
                    {rec.size ? `Size ${rec.size} · ` : ''}{fmtDate(rec.issued_date)}
                  </span>
                  {editable && manage && (
                    <button onClick={() => remove(rec)} disabled={isBusy} title="Remove"
                      style={{ display: 'inline-flex', alignItems: 'center', padding: '4px 8px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--bg-card)', color: '#f87171', cursor: 'pointer' }}>
                      <Trash2 size={11} />
                    </button>
                  )}
                </div>
              ) : editable && manage ? (
                <div style={{ display: 'flex', gap: 6 }}>
                  <input value={sizes[item] || ''} onChange={e => setSizes(p => ({ ...p, [item]: e.target.value }))}
                    placeholder="Size" style={{ ...inputStyle, padding: '6px 9px', width: 70, fontSize: 12 }} />
                  <button onClick={() => issue(item)} disabled={isBusy}
                    style={{ flex: 1, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 5, padding: '6px 10px', borderRadius: 8, border: 'none', background: 'rgba(124,58,237,0.16)', color: '#a78bfa', fontWeight: 700, fontSize: 11.5, cursor: 'pointer' }}>
                    {isBusy ? <Loader size={11} /> : <Plus size={11} />} Issue
                  </button>
                </div>
              ) : (
                <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>Not issued</span>
              )}
            </div>
          )
        })}
      </div>
    </Panel>
  )
}

// ── Step 5 — Badge + QR ──────────────────────────────────────────────────────
function StepBadge({ worker, progress, admin, onChanged }) {
  const [modal, setModal]   = useState(null)   // 'activate' | 'suspend' | 'terminate'
  const [remarks, setRemarks] = useState('')
  const [validUntil, setValid] = useState('')
  const [loading, setLoading] = useState(false)
  const [badge, setBadge]     = useState(null)  // { badge_number, qr_token, ... }
  const [qrUrl, setQrUrl]     = useState(null)
  const [qrErr, setQrErr]     = useState(null)

  const isActive = worker.status === WORKER_STATUS.ACTIVE
  const canActivate = progress.can_activate && admin

  // The QR token is hidden on the model, so the badge credential is fetched from
  // the dedicated admin endpoint (which audits every disclosure).
  useEffect(() => {
    if (!isActive || !admin) { setBadge(null); setQrUrl(null); return }
    let cancelled = false
    tpvApi.workers.badge(worker.id)
      .then(async (b) => {
        if (cancelled) return
        setBadge(b)
        // The QR encodes the public gate-scan URL the scanner will open.
        const scanUrl = `${window.location.origin}/scan/${b.qr_token}`
        try {
          const url = await QRCode.toDataURL(scanUrl, { width: 320, margin: 1, errorCorrectionLevel: 'M', color: { dark: '#0b0a12', light: '#ffffff' } })
          if (!cancelled) setQrUrl(url)
        } catch { if (!cancelled) setQrErr('Could not render the QR code.') }
      })
      .catch(() => { if (!cancelled) setQrErr('Could not load the badge credential.') })
    return () => { cancelled = true }
  }, [isActive, admin, worker.id, worker.badge_issued_at])

  const run = async () => {
    setLoading(true)
    try {
      if (modal === 'activate')      await tpvApi.workers.activate(worker.id, validUntil || null)
      else if (modal === 'suspend')  await tpvApi.workers.suspend(worker.id, remarks)
      else if (modal === 'terminate') await tpvApi.workers.terminate(worker.id, remarks)
      else if (modal === 'reinstate') await tpvApi.workers.reinstate(worker.id)
      setModal(null); setRemarks(''); setValid(''); onChanged()
    } catch (e) { alert(e?.response?.data?.message || 'Action failed') }
    finally { setLoading(false) }
  }

  return (
    <Panel title="Entry Badge" sub="The site access card — issued only when every gate passes">
      {/* Not yet issued */}
      {!isActive && worker.status === WORKER_STATUS.DRAFT && (
        <>
          {canActivate
            ? <InfoBox>All gates pass. Issuing generates the badge number and a QR credential for the site gate.</InfoBox>
            : <InfoBox tone="danger">
                {progress.blockers?.length
                  ? <>Resolve {progress.blockers.length} blocker{progress.blockers.length === 1 ? '' : 's'} before a badge can be issued — see the banner above.</>
                  : <>Only an admin can issue the entry badge.</>}
              </InfoBox>}
          {admin && (
            <div style={{ display: 'flex', alignItems: 'flex-end', gap: 12, justifyContent: 'flex-end' }}>
              <div style={{ width: 200 }}>
                <Field label="Valid Until (optional)"><TextInput type="date" value={validUntil} onChange={e => setValid(e.target.value)} /></Field>
              </div>
              <button onClick={() => setModal('activate')} disabled={!canActivate}
                style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '10px 22px', borderRadius: 10, border: 'none', marginBottom: 1,
                  background: canActivate ? 'linear-gradient(135deg,#10b981,#059669)' : 'rgba(16,185,129,0.3)',
                  color: '#fff', fontWeight: 800, fontSize: 13, cursor: canActivate ? 'pointer' : 'not-allowed',
                  boxShadow: canActivate ? '0 10px 24px -8px rgba(16,185,129,.7)' : 'none' }}>
                <ShieldCheck size={14} /> Issue Entry Badge
              </button>
            </div>
          )}
        </>
      )}

      {/* Issued — the printable badge */}
      {isActive && (
        <div style={{ display: 'flex', gap: 20, flexWrap: 'wrap', alignItems: 'flex-start' }}>
          {/* The card */}
          <div className="pr-glass" style={{ padding: 20, borderRadius: 16, width: 300, border: '1px solid rgba(16,185,129,0.4)' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 14 }}>
              <span style={{ width: 30, height: 30, borderRadius: 9, background: 'linear-gradient(145deg,#34d399,#10b981)', display: 'flex', alignItems: 'center', justifyContent: 'center', boxShadow: '0 6px 14px -3px rgba(16,185,129,.6)' }}>
                <QrCode size={15} color="#fff" />
              </span>
              <div>
                <div style={{ fontSize: 13, fontWeight: 900, color: 'var(--text-h)', letterSpacing: '-0.01em' }}>SITE ENTRY PASS</div>
                <div style={{ fontSize: 10, color: 'var(--text-muted)' }}>{worker.vendor?.company_name}</div>
              </div>
            </div>

            {/* QR */}
            <div style={{ background: '#fff', borderRadius: 12, padding: 10, display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: 180 }}>
              {qrUrl
                ? <img src={qrUrl} alt="Badge QR code" style={{ width: '100%', display: 'block' }} />
                : qrErr
                  ? <span style={{ fontSize: 11.5, color: '#ef4444', textAlign: 'center', padding: 12 }}>{qrErr}</span>
                  : <span style={{ fontSize: 11.5, color: '#64748b' }}>Rendering QR…</span>}
            </div>

            <div style={{ marginTop: 12 }}>
              <div style={{ fontSize: 15, fontWeight: 900, color: 'var(--text-h)' }}>{worker.name}</div>
              <div style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{worker.designation} · {worker.worker_code}</div>
            </div>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 10, paddingTop: 10, borderTop: '1px solid var(--border)' }}>
              <div>
                <div style={{ fontSize: 9, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'var(--text-muted)', fontWeight: 800 }}>Badge</div>
                <div style={{ fontSize: 12, fontWeight: 800, color: '#a78bfa' }}>{worker.badge_number}</div>
              </div>
              <div style={{ textAlign: 'right' }}>
                <div style={{ fontSize: 9, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'var(--text-muted)', fontWeight: 800 }}>Valid until</div>
                <div style={{ fontSize: 12, fontWeight: 800, color: worker.badge_valid ? '#10b981' : '#ef4444' }}>{fmtDate(worker.badge_valid_until)}</div>
              </div>
            </div>
          </div>

          {/* Details + actions */}
          <div style={{ flex: 1, minWidth: 260 }}>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px 20px', marginBottom: 16 }}>
              {[['Issued', fmtDate(worker.badge_issued_at)], ['Badge Number', worker.badge_number],
                ['Blood Group', worker.blood_group], ['Emergency', worker.emergency_phone]]
                .filter(([, v]) => v).map(([k, v]) => (
                  <div key={k} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, borderBottom: '1px solid var(--border)', paddingBottom: 6 }}>
                    <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>{k}</span>
                    <span style={{ color: 'var(--text-h)', fontSize: 12, fontWeight: 600 }}>{v}</span>
                  </div>
                ))}
            </div>
            <InfoBox>
              The QR encodes the public gate-scan URL. The scan screen itself is the next slice — until then the
              badge is issued and valid, but nothing consumes the scan yet.
            </InfoBox>
            {admin && (
              <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
                <button onClick={() => window.print()} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', fontWeight: 700, fontSize: 12.5, cursor: 'pointer' }}>
                  <Printer size={13} /> Print
                </button>
                <button onClick={() => { setModal('suspend'); setRemarks('') }} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: '#f59e0b', fontWeight: 700, fontSize: 12.5, cursor: 'pointer' }}>
                  <Ban size={13} /> Suspend
                </button>
                <button onClick={() => { setModal('terminate'); setRemarks('') }} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: '#f87171', fontWeight: 700, fontSize: 12.5, cursor: 'pointer' }}>
                  <Trash2 size={13} /> Terminate
                </button>
              </div>
            )}
          </div>
        </div>
      )}

      {/* Suspended / terminated */}
      {(worker.status === WORKER_STATUS.SUSPENDED || worker.status === WORKER_STATUS.TERMINATED) && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '16px 18px', borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <div style={{ width: 44, height: 44, borderRadius: 14, display: 'flex', alignItems: 'center', justifyContent: 'center', background: worker.status === WORKER_STATUS.TERMINATED ? 'linear-gradient(145deg,#f87171,#ef4444)' : 'linear-gradient(145deg,#fbbf24,#f59e0b)' }}>
            <Ban size={20} color="#fff" />
          </div>
          <div style={{ flex: 1 }}>
            <div style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>{workerStatusCfg(worker.status).label}</div>
            <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>
              {worker.status === WORKER_STATUS.TERMINATED
                ? 'Site access permanently revoked — the QR credential has been destroyed.'
                : 'Site access paused — the badge will not admit at the gate.'}
            </div>
          </div>
          {admin && worker.status === WORKER_STATUS.SUSPENDED && (
            <button onClick={() => setModal('reinstate')} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 9, border: 'none', background: 'linear-gradient(135deg,#10b981,#059669)', color: '#fff', fontWeight: 700, fontSize: 12.5, cursor: 'pointer' }}>
              <RotateCcw size={13} /> Reinstate
            </button>
          )}
        </div>
      )}

      {modal && (
        <Overlay onClose={() => !loading && setModal(null)} width={460}>
          <h3 style={{ color: 'var(--text-h)', margin: '0 0 12px', fontSize: 16, fontWeight: 800 }}>
            {{ activate: 'Issue Entry Badge', suspend: 'Suspend Site Access', terminate: 'Terminate Worker', reinstate: 'Reinstate Worker' }[modal]}
          </h3>
          <p style={{ color: 'var(--text-muted)', fontSize: 13, marginBottom: 12 }}>
            <strong style={{ color: 'var(--text-h)' }}>{worker.name}</strong> · {worker.worker_code}
          </p>
          {modal === 'activate' && <InfoBox>This grants site access and generates the QR credential.</InfoBox>}
          {modal === 'terminate' && <InfoBox tone="danger">Permanent. The QR credential is destroyed so a retained card can never resolve at the gate.</InfoBox>}
          {modal === 'suspend' && <InfoBox tone="danger">The badge stops admitting until reinstated.</InfoBox>}
          {modal === 'reinstate' && <InfoBox>Gates are re-checked on reinstatement — a lapsed medical will block it.</InfoBox>}
          {(modal === 'suspend' || modal === 'terminate') && (
            <>
              <label style={labelStyle}>{modal === 'terminate' ? 'Reason *' : 'Remarks (optional)'}</label>
              <textarea value={remarks} onChange={e => setRemarks(e.target.value)} rows={3}
                style={{ ...inputStyle, resize: 'vertical', borderColor: modal === 'terminate' && !remarks ? '#ef444480' : 'var(--border)' }} />
            </>
          )}
          <ModalFooter onClose={() => setModal(null)} onConfirm={run} loading={loading}
            disabled={modal === 'terminate' && !remarks}
            confirmLabel="Confirm"
            color={modal === 'activate' || modal === 'reinstate' ? '#10b981' : modal === 'terminate' ? '#ef4444' : '#f59e0b'} />
        </Overlay>
      )}
    </Panel>
  )
}
