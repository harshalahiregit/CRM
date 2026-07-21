import { useState, useEffect, useRef, useCallback } from 'react'
import { Plus, Trash2 } from 'lucide-react'
import { onboardingApi } from '@/services/onboardingApi'
import {
  accent, Card, ProgressBar, Empty, Link, Grid, Input, Sel, Field, inputStyle,
} from '@/pages/careers/OnboardingShared'

/**
 * Employee Onboarding Form — 18-section wizard for the candidate portal.
 *
 * Every write goes to the existing EmployeeOnboardingService through the public
 * token endpoints. Section status, progress and audit are ALL produced server-side;
 * nothing here is recomputed locally. Documents reuse the portal's existing
 * Documents tab, so there is no second upload component.
 */

const YN = ['', 'Yes', 'No']

// 1:1 profile steps. `apis` lets one UI step write to more than one backend section.
const P_STEPS = {
  application: { title: 'Application', apis: [{ section: 'application', fields: [
    ['applied_for_company', 'Applied for which Co.?'], ['designation_applied_for', 'Designation Applied For']] }] },
  identity: { title: 'Personal Information', apis: [{ section: 'identity', fields: [
    ['full_name', 'Full Name'], ['dob', 'Birth Date', 'date'], ['blood_group', 'Blood Group'],
    ['marital_status', 'Marital Status'], ['complete_address_with_pin', 'Complete Address with Pin Code', 'area']] }] },
  contact: { title: 'Contact Information', apis: [
    { section: 'contact', fields: [['mobile_phone', 'Mobile No.'], ['alternate_phone', 'Alternate Mobile No.'], ['personal_email', 'Email id']] },
    { section: 'emergency', fields: [['emergency_phone', 'Emergency Mobile No.']] }] },
  health: { title: 'Health Information', apis: [{ section: 'health', fields: [
    ['covid_vaccinated', 'Are You fully Vaccinated (Covid-19)?', 'yn'],
    ['physical_fitness_level', 'What is Your Physical Fitness Level'],
    ['mental_health_stress_level', 'What is Your Mental Health and Stress Level']] }] },
  joining: { title: 'Experience & Joining', apis: [{ section: 'joining', fields: [
    ['core_experience', 'Core Experience', 'area'], ['expected_joining_date', 'What will be Your Joining Date Here', 'date'],
    ['salary_expected_or_confirmed', 'Salary Desired or Confirmed During Interview'],
    ['worked_here_before', 'Have You Worked Here Before?', 'yn'], ['applied_here_before', 'Have You Applied Here Before?', 'yn']] }] },
  skills: { title: 'Skills', apis: [{ section: 'skills', fields: [
    ['skills_to_manage_job', 'What All Skills You Have to Manage Your Job', 'area']] }] },
  bond: { title: 'Bond & Statutory', apis: [{ section: 'bond', fields: [
    ['ready_to_sign_bond', 'Are You Ready to Sign a Bond to Stay with the Company?', 'yn'],
    ['has_pf_esic_uan', 'Do You Have PF/ESIC (UAN) No.?', 'yn']] }] },
}

// Fixed education blocks — one row each, identified by `level`.
const EDU = [
  { key: 'edu_high', title: 'High School', level: 'High School', fields: [
    ['institution', 'High School'], ['duration_attended', 'Number of Years Attended'], ['completion_status', 'Completed']] },
  { key: 'edu_inter', title: 'Intermediate', level: 'Intermediate', fields: [
    ['institution', 'Intermediate School/College Name (11th & 12th)'], ['duration_attended', 'Number of Years Attended'],
    ['specialization', 'Area of Study/Degree'], ['completion_status', 'Completed']] },
  { key: 'edu_grad', title: 'Graduation', level: 'Graduate', fields: [
    ['institution', 'Name of Graduate College Attended'], ['specialization', 'Area of Study/Degree'], ['completion_status', 'Graduated']] },
  { key: 'edu_pg', title: 'Post Graduation', level: 'Post Graduate', fields: [
    ['institution', 'Post Graduation College Name'], ['specialization', 'Area of Study/PG'], ['completion_status', 'Post Graduated?']] },
  { key: 'edu_trade', title: 'Trade / Certification', level: 'Trade', fields: [
    ['institution', 'Trade School/Certification/Other'], ['duration_attended', 'Number of Days Attended'],
    ['specialization', 'Area of Study/Certificate'], ['completion_status', 'Completed?']] },
]

const EMP = [
  { key: 'emp_current', title: 'Current Employment', current: true, fields: [
    ['company_name', 'Current Employer'], ['designation', 'Current Position'], ['last_ctc', 'Current Salary'],
    ['reason_for_leaving', 'Reason for Leaving?'], ['from_date', 'When You Started Here', 'date']] },
  { key: 'emp_prev', title: 'Previous Employment', current: false, fields: [
    ['company_name', 'Previous Employment - Employer Name'], ['from_date', 'Start Date', 'date'],
    ['to_date', 'Your Last Working Day', 'date'], ['reason_for_leaving', 'Reason for Leaving']] },
]

const REF_FIELDS = [['reference_name', 'Reference 1'], ['relationship', 'Relationship'], ['phone', 'Phone no.'], ['email', 'Email id']]

const btnPrimary = { padding: '9px 16px', borderRadius: 9, border: 'none', background: accent, color: '#fff', fontSize: 13, fontWeight: 700, cursor: 'pointer' }
const btnGhost = { padding: '9px 16px', borderRadius: 9, border: '1px solid #e2e8f0', background: '#fff', color: '#475569', fontSize: 13, fontWeight: 700, cursor: 'pointer' }

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const PHONE_RE = /^[0-9]{10}$/

export default function OnboardingFormTab({ token, data, onChanged, onGoDocs }) {
  const form = data.form || {}
  const readOnly = data.verification_status === 'Approved'
  const joined = !!form?.onboarding?.employee_id
  const progress = form?.onboarding?.progress_percent ?? 0
  const profile = form.profile || {}

  const steps = [
    { key: 'application', ...P_STEPS.application },
    { key: 'identity', ...P_STEPS.identity },
    { key: 'contact', ...P_STEPS.contact },
    { key: 'health', ...P_STEPS.health },
    { key: 'joining', ...P_STEPS.joining },
    ...EDU,
    { key: 'skills', ...P_STEPS.skills },
    ...EMP,
    { key: 'references', title: 'References' },
    { key: 'bond', ...P_STEPS.bond },
    { key: 'documents', title: 'Documents' },
    { key: 'declaration', title: 'Declaration' },
    // Hidden entirely until the backend says the employee has joined.
    ...(joined ? [{ key: 'orientation', title: 'Orientation Feedback' }] : []),
  ]

  // Resume — the open step survives a refresh.
  const [idx, setIdx] = useState(() => {
    const n = Number(sessionStorage.getItem(`onb_step_${token}`))
    return Number.isInteger(n) && n >= 0 ? n : 0
  })
  useEffect(() => { sessionStorage.setItem(`onb_step_${token}`, String(idx)) }, [idx, token])
  const step = steps[Math.min(idx, steps.length - 1)]

  const [draft, setDraft] = useState({})
  const [save, setSave] = useState('idle')     // idle | unsaved | saving | saved
  const [errors, setErrors] = useState({})
  const timer = useRef(null)

  const eduRow = (level) => (form.education || []).find(r => r.level === level) || {}
  const empRow = (cur) => (form.experience || []).find(r => !!r.is_current === cur) || {}

  const serverVal = (n) => {
    if (step.level) return eduRow(step.level)[n] ?? ''
    if (step.current !== undefined) return empRow(step.current)[n] ?? ''
    return profile[n] ?? ''
  }
  const val = (n) => (draft[n] !== undefined ? draft[n] : serverVal(n)) ?? ''

  // A verified section is locked; a rejected one is re-opened for correction only.
  const sectionKey = (st) => st.key.startsWith('edu_') ? 'education' : st.key.startsWith('emp_') ? 'experience' : st.key
  const stepRow = (() => {
    const ss = form.section_status, k = sectionKey(step)
    return (Array.isArray(ss) ? ss.find(x => x.section === k) : ss?.[k]) || {}
  })()
  const statusRemarks = stepRow.remarks
  const stepStatus = stepRow.status || 'Draft'
  const needsFix = stepStatus === 'Rejected' || stepStatus === 'Correction Requested'
  const locked = readOnly || stepStatus === 'Verified'

  useEffect(() => { setDraft({}); setErrors({}); setSave('idle') }, [step.key])


  const statusOf = (key) => {
    const ss = form.section_status
    const row = Array.isArray(ss) ? ss.find(x => x.section === key) : ss?.[key]
    return row?.status || 'Draft'
  }

  const validate = (payload) => {
    const e = {}
    Object.entries(payload).forEach(([k, v]) => {
      if (!v) return
      if (k.includes('email') && !EMAIL_RE.test(String(v))) e[k] = 'Enter a valid email address.'
      if ((k === 'mobile_phone' || k === 'alternate_phone' || k === 'emergency_phone' || k === 'phone') && !PHONE_RE.test(String(v).replace(/\D/g, '')))
        e[k] = 'Enter a 10-digit mobile number.'
    })
    return e
  }

  const flush = useCallback(async (payload) => {
    if (locked || !payload || !Object.keys(payload).length) return true
    const bad = validate(payload)
    if (Object.keys(bad).length) { setErrors(bad); setSave('unsaved'); return false }
    setSave('saving')
    try {
      if (step.level) {
        const row = eduRow(step.level)
        await onboardingApi.saveChild(token, 'education', { ...row, ...payload, id: row.id, level: step.level })
      } else if (step.current !== undefined) {
        const row = empRow(step.current)
        await onboardingApi.saveChild(token, 'experience', { ...row, ...payload, id: row.id, is_current: step.current })
      } else {
        for (const grp of step.apis || []) {
          const slice = {}
          grp.fields.forEach(([n]) => { if (payload[n] !== undefined) slice[n] = payload[n] })
          if (Object.keys(slice).length) await onboardingApi.saveSection(token, grp.section, slice)
        }
      }
      setSave('saved'); setDraft({}); await onChanged()
      return true
    } catch (err) {
      setSave('unsaved')
      setErrors({ _form: err.response?.data?.message || 'Could not save. Please try again.' })
      return false
    }
  }, [step, token, readOnly, form]) // eslint-disable-line react-hooks/exhaustive-deps

  // Debounced autosave — one request per pause, never one per keystroke.
  const onField = (n, v) => {
    const next = { ...draft, [n]: v }
    setDraft(next); setSave('unsaved')
    setErrors(e => ({ ...e, [n]: undefined, _form: undefined }))
    clearTimeout(timer.current)
    timer.current = setTimeout(() => flush(next), 1200)
  }
  const saveNow = () => { clearTimeout(timer.current); return flush(draft) }
  useEffect(() => () => clearTimeout(timer.current), [])

  const chip = { saving: ['Saving…', '#d97706'], saved: ['Saved', '#059669'], unsaved: ['Unsaved changes', '#dc2626'] }[save]

  const renderField = ([n, label, type]) => (
    <Field key={n} label={label} full={type === 'area'}>
      {type === 'yn'
        ? <Sel opts={YN} value={val(n)} disabled={locked} onChange={e => onField(n, e.target.value)} />
        : type === 'area'
          ? <textarea rows={3} value={val(n)} disabled={locked} onChange={e => onField(n, e.target.value)} style={{ ...inputStyle, resize: 'vertical' }} />
          : <Input type={type === 'date' ? 'date' : 'text'} value={val(n) || ''} disabled={locked} onChange={e => onField(n, e.target.value)} />}
      {errors[n] && <p style={{ color: '#dc2626', fontSize: 11.5, margin: '4px 0 0' }}>{errors[n]}</p>}
    </Field>
  )

  const fieldsOf = (s) => s.fields || (s.apis || []).flatMap(a => a.fields)

  return (
    <div style={{ display: 'grid', gap: 14 }}>
      <Card title="Onboarding Form" action={<span style={{ fontSize: 12.5, fontWeight: 800, color: accent }}>{progress}% complete</span>}>
        <ProgressBar percent={progress} />
        <p style={{ fontSize: 12, color: '#64748b', margin: '10px 0 0' }}>
          {readOnly
            ? 'Your form has been approved by HR and is now read-only.'
            : 'Your answers save automatically. You can close this page and resume any time.'}
        </p>
      </Card>

      <Card title="Sections">
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
          {steps.map((s, i) => {
            const st = statusOf(s.key.startsWith('edu_') ? 'education' : s.key.startsWith('emp_') ? 'experience' : s.key)
            const on = i === idx
            const done = st === 'Verified' || st === 'Submitted'
            return (
              <button key={s.key} onClick={() => setIdx(i)}
                style={{ textAlign: 'left', padding: '7px 10px', borderRadius: 8, fontSize: 12.5, fontWeight: 700, cursor: 'pointer',
                  border: `1px solid ${on ? accent : '#e2e8f0'}`, background: on ? '#f5f3ff' : '#fff', color: on ? accent : '#475569' }}>
                {done ? '✓ ' : ''}{i + 1}. {s.title}
              </button>
            )
          })}
        </div>
      </Card>

      <Card title={`${idx + 1}. ${step.title}`} action={chip ? <span style={{ fontSize: 12, fontWeight: 700, color: chip[1] }}>{chip[0]}</span> : null}>
        {errors._form && <p style={{ color: '#dc2626', fontSize: 12.5, margin: '0 0 10px' }}>{errors._form}</p>}

        {needsFix && (
          <div style={{ padding: '10px 12px', borderRadius: 9, background: '#fffbeb', border: '1px solid #fde68a', marginBottom: 12 }}>
            <p style={{ margin: 0, fontSize: 12.5, fontWeight: 700, color: '#b45309' }}>Correction Requested</p>
            {statusRemarks && <p style={{ margin: '4px 0 0', fontSize: 12, color: '#78350f' }}>{statusRemarks}</p>}
          </div>
        )}
        {stepStatus === 'Verified' && (
          <p style={{ fontSize: 12.5, fontWeight: 700, color: '#059669', margin: '0 0 10px' }}>✓ Verified by HR — this section is now read-only.</p>
        )}

        {step.key === 'documents' ? (
          <div>
            <Empty>All required documents are uploaded and verified in the Documents section.</Empty>
            <div style={{ marginTop: 10 }}><Link onClick={onGoDocs}>Go to Documents →</Link></div>
          </div>
        ) : step.key === 'references' ? (
          <RefEditor token={token} rows={form.references || []} readOnly={readOnly} onChanged={onChanged} />
        ) : step.key === 'declaration' ? (
          <Declaration token={token} profile={profile} readOnly={readOnly} onChanged={onChanged} />
        ) : step.key === 'orientation' ? (
          <Empty>Orientation feedback is collected by HR once your joining formalities are complete.</Empty>
        ) : (
          <>
            <Grid>{fieldsOf(step).map(renderField)}</Grid>
            {!locked && (
              <div style={{ display: 'flex', gap: 8, marginTop: 14, flexWrap: 'wrap' }}>
                <button onClick={saveNow} style={btnGhost}>Save Draft</button>
                <button onClick={async () => { if (await saveNow()) setIdx(i => Math.min(i + 1, steps.length - 1)) }} style={btnPrimary}>Submit Section</button>
              </div>
            )}
          </>
        )}

        <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, marginTop: 16, borderTop: '1px solid #f1f5f9', paddingTop: 12 }}>
          <button onClick={() => setIdx(i => Math.max(i - 1, 0))} disabled={idx === 0} style={{ ...btnGhost, opacity: idx === 0 ? 0.5 : 1 }}>← Previous</button>
          <button onClick={() => setIdx(i => Math.min(i + 1, steps.length - 1))} disabled={idx >= steps.length - 1} style={{ ...btnGhost, opacity: idx >= steps.length - 1 ? 0.5 : 1 }}>Next →</button>
        </div>
      </Card>
    </div>
  )
}

/* References — repeatable, reuses the backend collection API */
function RefEditor({ token, rows, readOnly, onChanged }) {
  const [row, setRow] = useState({})
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)

  const add = async () => {
    if (row.email && !EMAIL_RE.test(row.email)) return setErr('Enter a valid email address.')
    setBusy(true); setErr(null)
    try { await onboardingApi.saveChild(token, 'references', row); setRow({}); await onChanged() }
    catch (e) { setErr(e.response?.data?.message || 'Could not add the reference.') }
    finally { setBusy(false) }
  }
  const del = async (id) => {
    setBusy(true)
    try { await onboardingApi.deleteChild(token, 'references', id); await onChanged() }
    catch (e) { setErr(e.response?.data?.message || 'Could not remove the reference.') }
    finally { setBusy(false) }
  }

  return (
    <div>
      {rows.length === 0 && <Empty>No references added yet.</Empty>}
      {rows.map(r => (
        <div key={r.id} style={{ display: 'flex', gap: 10, alignItems: 'center', padding: '8px 0', borderBottom: '1px solid #f1f5f9', flexWrap: 'wrap' }}>
          <span style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>{r.reference_name}</span>
          <span style={{ fontSize: 12, color: '#64748b' }}>{[r.relationship, r.phone, r.email].filter(Boolean).join(' · ')}</span>
          {!readOnly && <button onClick={() => del(r.id)} disabled={busy} style={{ marginLeft: 'auto', background: 'none', border: 'none', cursor: 'pointer', color: '#f87171' }}><Trash2 size={15} /></button>}
        </div>
      ))}
      {err && <p style={{ color: '#dc2626', fontSize: 12, margin: '10px 0 0' }}>{err}</p>}
      {!readOnly && (
        <>
          <div style={{ marginTop: 12 }}>
            <Grid>{REF_FIELDS.map(([n, l]) => (
              <Field key={n} label={l}><Input value={row[n] || ''} onChange={e => setRow({ ...row, [n]: e.target.value })} /></Field>
            ))}</Grid>
          </div>
          <button onClick={add} disabled={busy || !row.reference_name} style={{ ...btnPrimary, marginTop: 12, opacity: (busy || !row.reference_name) ? 0.5 : 1 }}>
            <Plus size={14} style={{ verticalAlign: -2 }} /> Add Reference
          </button>
        </>
      )}
    </div>
  )
}

/* Declaration — Final Submit stays disabled until the box is ticked */
function Declaration({ token, profile, readOnly, onChanged }) {
  const done = !!profile.declaration_accepted
  const [ok, setOk] = useState(done)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)

  if (done) return <p style={{ fontSize: 13, color: '#059669', fontWeight: 700, margin: 0 }}>✓ Declaration accepted — your form is submitted for HR verification.</p>

  const submit = async () => {
    setBusy(true); setErr(null)
    try {
      await onboardingApi.saveSection(token, 'declaration', {
        declaration_accepted: true,
        declaration_accepted_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
      })
      await onChanged()
    } catch (e) { setErr(e.response?.data?.message || 'Could not submit. Please try again.') }
    finally { setBusy(false) }
  }

  return (
    <div>
      <label style={{ display: 'flex', gap: 10, alignItems: 'flex-start', fontSize: 13, color: '#334155', cursor: readOnly ? 'default' : 'pointer' }}>
        <input type="checkbox" checked={ok} disabled={readOnly} onChange={e => setOk(e.target.checked)} style={{ marginTop: 3 }} />
        <span>Declaration: I have filled above inputs correct</span>
      </label>
      {err && <p style={{ color: '#dc2626', fontSize: 12, margin: '10px 0 0' }}>{err}</p>}
      {!readOnly && (
        <button onClick={submit} disabled={!ok || busy}
          style={{ ...btnPrimary, marginTop: 14, opacity: (!ok || busy) ? 0.5 : 1, cursor: (!ok || busy) ? 'not-allowed' : 'pointer' }}>
          {busy ? 'Submitting…' : 'Final Submit'}
        </button>
      )}
    </div>
  )
}
