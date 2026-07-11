import { useState, useEffect, useCallback } from 'react'
import { useParams } from 'react-router-dom'
import { CheckCircle2, Upload, Clock, XCircle, Plus, Trash2, ShieldCheck } from 'lucide-react'
import { onboardingApi } from '@/services/onboardingApi'

const DOC_FIELDS = [
  { key: 'aadhaar', label: 'Aadhaar Card' },
  { key: 'pan', label: 'PAN Card' },
  { key: 'resume', label: 'Resume' },
  { key: 'photo', label: 'Passport Photo' },
  { key: 'address_proof', label: 'Address Proof' },
  { key: 'company_document', label: 'Company Documents' },
]

const EMPTY = {
  personal_details: { dob: '', gender: '', father_name: '', marital_status: '', blood_group: '' },
  address: { current: '', permanent: '', city: '', state: '', pincode: '' },
  emergency_contact: { name: '', relation: '', phone: '' },
  education: [{ degree: '', institution: '', year: '' }],
  experience: [{ company: '', role: '', years: '' }],
  bank_details: { account_name: '', account_number: '', ifsc: '', bank_name: '' },
}

const accent = '#7C3AED'

export default function OnboardingPortal() {
  const { token } = useParams()
  const [info, setInfo] = useState(null)
  const [loading, setLoading] = useState(true)
  const [notFound, setNotFound] = useState(false)
  const [form, setForm] = useState(EMPTY)
  const [files, setFiles] = useState({})
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [done, setDone] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const data = await onboardingApi.get(token)
      setInfo(data)
      if (data.submission) setForm({ ...EMPTY, ...data.submission })
    } catch { setNotFound(true) }
    finally { setLoading(false) }
  }, [token])
  useEffect(() => { load() }, [load])

  const setSection = (sec, k, v) => setForm(f => ({ ...f, [sec]: { ...f[sec], [k]: v } }))
  const setRow = (sec, i, k, v) => setForm(f => ({ ...f, [sec]: f[sec].map((r, idx) => idx === i ? { ...r, [k]: v } : r) }))
  const addRow = (sec, tmpl) => setForm(f => ({ ...f, [sec]: [...f[sec], tmpl] }))
  const rmRow = (sec, i) => setForm(f => ({ ...f, [sec]: f[sec].filter((_, idx) => idx !== i) }))

  const submit = async () => {
    setError('')
    if (!form.personal_details.dob) { setError('Please fill your date of birth.'); return }
    if (!form.bank_details.account_number) { setError('Please provide your bank account details.'); return }
    setBusy(true)
    try {
      await onboardingApi.submit(token, form, files)
      setDone(true)
      window.scrollTo({ top: 0, behavior: 'smooth' })
      load()
    } catch (e) {
      const errs = e?.response?.data?.errors
      setError(errs ? Object.values(errs)[0][0] : (e?.response?.data?.message || 'Something went wrong. Please try again.'))
    } finally { setBusy(false) }
  }

  if (loading) return <Center>Loading…</Center>
  if (notFound || !info) return <Center><h1 style={{ fontSize: 22, fontWeight: 800, color: '#0f172a' }}>This onboarding link is invalid or has expired.</h1></Center>

  const status = info.verification_status
  const editable = info.editable && !done

  return (
    <div style={{ minHeight: '100vh', background: '#f8fafc', color: '#0f172a', fontFamily: 'system-ui,-apple-system,sans-serif' }}>
      <header style={{ background: '#fff', borderBottom: '1px solid #e2e8f0' }}>
        <div style={{ maxWidth: 780, margin: '0 auto', padding: '16px 24px', display: 'flex', alignItems: 'center', gap: 10 }}>
          <ShieldCheck size={20} style={{ color: accent }} />
          <strong style={{ fontSize: 15 }}>Candidate Onboarding</strong>
        </div>
      </header>

      <div style={{ maxWidth: 780, margin: '0 auto', padding: '28px 24px 60px' }}>
        {/* Welcome */}
        <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 16, padding: 24, marginBottom: 16 }}>
          <h1 style={{ fontSize: 24, fontWeight: 900, margin: 0 }}>🎉 Congratulations, {info.candidate_name}!</h1>
          <p style={{ color: '#475569', marginTop: 8, fontSize: 14.5 }}>
            You've been selected for <strong>{info.position}</strong>{info.department ? ` · ${info.department}` : ''}. Please complete your onboarding below so our HR team can verify your details.
          </p>
          <StatusBanner status={status} reason={info.rejection_reason} done={done} />
        </div>

        {editable ? (
          <>
            <Section title="1. Personal Details">
              <Grid>
                <Field label="Date of Birth *"><Input type="date" value={form.personal_details.dob} onChange={e => setSection('personal_details', 'dob', e.target.value)} /></Field>
                <Field label="Gender"><Select value={form.personal_details.gender} onChange={e => setSection('personal_details', 'gender', e.target.value)} opts={['', 'Male', 'Female', 'Other']} /></Field>
                <Field label="Father's / Guardian's Name"><Input value={form.personal_details.father_name} onChange={e => setSection('personal_details', 'father_name', e.target.value)} /></Field>
                <Field label="Marital Status"><Select value={form.personal_details.marital_status} onChange={e => setSection('personal_details', 'marital_status', e.target.value)} opts={['', 'Single', 'Married', 'Other']} /></Field>
                <Field label="Blood Group"><Input value={form.personal_details.blood_group} onChange={e => setSection('personal_details', 'blood_group', e.target.value)} /></Field>
              </Grid>
            </Section>

            <Section title="2. Address">
              <Grid>
                <Field label="Current Address" full><Input value={form.address.current} onChange={e => setSection('address', 'current', e.target.value)} /></Field>
                <Field label="Permanent Address" full><Input value={form.address.permanent} onChange={e => setSection('address', 'permanent', e.target.value)} /></Field>
                <Field label="City"><Input value={form.address.city} onChange={e => setSection('address', 'city', e.target.value)} /></Field>
                <Field label="State"><Input value={form.address.state} onChange={e => setSection('address', 'state', e.target.value)} /></Field>
                <Field label="Pincode"><Input value={form.address.pincode} onChange={e => setSection('address', 'pincode', e.target.value)} /></Field>
              </Grid>
            </Section>

            <Section title="3. Emergency Contact">
              <Grid>
                <Field label="Name"><Input value={form.emergency_contact.name} onChange={e => setSection('emergency_contact', 'name', e.target.value)} /></Field>
                <Field label="Relationship"><Input value={form.emergency_contact.relation} onChange={e => setSection('emergency_contact', 'relation', e.target.value)} /></Field>
                <Field label="Phone"><Input value={form.emergency_contact.phone} onChange={e => setSection('emergency_contact', 'phone', e.target.value)} /></Field>
              </Grid>
            </Section>

            <Section title="4. Education" action={<AddBtn onClick={() => addRow('education', { degree: '', institution: '', year: '' })} />}>
              {form.education.map((row, i) => (
                <RowGrid key={i} onRemove={form.education.length > 1 ? () => rmRow('education', i) : null}>
                  <Input placeholder="Degree" value={row.degree} onChange={e => setRow('education', i, 'degree', e.target.value)} />
                  <Input placeholder="Institution" value={row.institution} onChange={e => setRow('education', i, 'institution', e.target.value)} />
                  <Input placeholder="Year" value={row.year} onChange={e => setRow('education', i, 'year', e.target.value)} />
                </RowGrid>
              ))}
            </Section>

            <Section title="5. Experience" action={<AddBtn onClick={() => addRow('experience', { company: '', role: '', years: '' })} />}>
              {form.experience.map((row, i) => (
                <RowGrid key={i} onRemove={form.experience.length > 1 ? () => rmRow('experience', i) : null}>
                  <Input placeholder="Company" value={row.company} onChange={e => setRow('experience', i, 'company', e.target.value)} />
                  <Input placeholder="Role" value={row.role} onChange={e => setRow('experience', i, 'role', e.target.value)} />
                  <Input placeholder="Years" value={row.years} onChange={e => setRow('experience', i, 'years', e.target.value)} />
                </RowGrid>
              ))}
            </Section>

            <Section title="6. Bank Details">
              <Grid>
                <Field label="Account Holder Name"><Input value={form.bank_details.account_name} onChange={e => setSection('bank_details', 'account_name', e.target.value)} /></Field>
                <Field label="Account Number *"><Input value={form.bank_details.account_number} onChange={e => setSection('bank_details', 'account_number', e.target.value)} /></Field>
                <Field label="IFSC Code"><Input value={form.bank_details.ifsc} onChange={e => setSection('bank_details', 'ifsc', e.target.value)} /></Field>
                <Field label="Bank Name"><Input value={form.bank_details.bank_name} onChange={e => setSection('bank_details', 'bank_name', e.target.value)} /></Field>
              </Grid>
            </Section>

            <Section title="7. Documents">
              <Grid>
                {DOC_FIELDS.map(d => (
                  <Field key={d.key} label={d.label}>
                    <label style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '10px 12px', border: `1px dashed ${files[d.key] ? accent : '#cbd5e1'}`, borderRadius: 9, cursor: 'pointer', background: files[d.key] ? `${accent}0d` : '#f8fafc' }}>
                      <Upload size={15} style={{ color: accent }} />
                      <span style={{ fontSize: 12.5, color: files[d.key] ? '#0f172a' : '#64748b', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{files[d.key]?.name || 'Upload (PDF/JPG/PNG)'}</span>
                      <input type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" onChange={e => setFiles(f => ({ ...f, [d.key]: e.target.files?.[0] || null }))} style={{ display: 'none' }} />
                    </label>
                  </Field>
                ))}
              </Grid>
              <p style={{ fontSize: 12, color: '#94a3b8', marginTop: 10 }}>Existing uploaded: {info.documents?.length || 0} document(s). Re-uploading replaces on the next submit.</p>
            </Section>

            {error && <div style={{ background: '#fef2f2', color: '#b91c1c', border: '1px solid #fecaca', borderRadius: 10, padding: '11px 14px', fontSize: 13, margin: '4px 0 14px' }}>{error}</div>}

            <button onClick={submit} disabled={busy} style={{ width: '100%', padding: '13px', borderRadius: 10, background: busy ? '#94a3b8' : accent, color: '#fff', border: 'none', cursor: busy ? 'not-allowed' : 'pointer', fontWeight: 700, fontSize: 15 }}>
              {busy ? 'Submitting…' : status === 'Rejected' ? 'Re-submit Onboarding' : 'Submit Onboarding'}
            </button>
          </>
        ) : (
          <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 16, padding: 28, textAlign: 'center' }}>
            <CheckCircle2 size={48} style={{ color: '#10b981', marginBottom: 12 }} />
            <h2 style={{ fontSize: 20, fontWeight: 800, margin: 0 }}>{status === 'Approved' ? 'Onboarding Approved' : 'Onboarding Submitted'}</h2>
            <p style={{ color: '#475569', marginTop: 8, fontSize: 14 }}>
              {status === 'Approved' ? 'Your details have been verified. Your offer letter will follow shortly.' : 'Thank you! Our HR team is reviewing your submission and will get back to you.'}
            </p>
          </div>
        )}
      </div>
    </div>
  )
}

const StatusBanner = ({ status, reason, done }) => {
  if (done || status === 'Submitted') return <Banner icon={<Clock size={15} />} color="#d97706" bg="#fffbeb">Submitted — under HR review.</Banner>
  if (status === 'Approved') return <Banner icon={<CheckCircle2 size={15} />} color="#047857" bg="#ecfdf5">Verified & approved. Your offer will follow.</Banner>
  if (status === 'Rejected') return <Banner icon={<XCircle size={15} />} color="#b91c1c" bg="#fef2f2">Needs changes{reason ? `: ${reason}` : ''}. Please review and re-submit.</Banner>
  return null
}
const Banner = ({ icon, color, bg, children }) => (
  <div style={{ display: 'inline-flex', alignItems: 'center', gap: 7, marginTop: 14, padding: '8px 14px', borderRadius: 999, background: bg, color, fontWeight: 700, fontSize: 13 }}>{icon}{children}</div>
)
const Center = ({ children }) => <div style={{ minHeight: '100vh', background: '#f8fafc', display: 'flex', alignItems: 'center', justifyContent: 'center', textAlign: 'center', padding: 24, color: '#64748b', fontFamily: 'system-ui,sans-serif' }}>{children}</div>
const Section = ({ title, action, children }) => (
  <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 16, padding: 22, marginBottom: 16 }}>
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
      <h2 style={{ fontSize: 16, fontWeight: 800, margin: 0 }}>{title}</h2>{action}
    </div>
    {children}
  </div>
)
const Grid = ({ children }) => <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>{children}</div>
const RowGrid = ({ children, onRemove }) => (
  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr auto', gap: 10, marginBottom: 10, alignItems: 'center' }}>
    {children}
    {onRemove ? <button onClick={onRemove} style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#f87171' }}><Trash2 size={16} /></button> : <span />}
  </div>
)
const AddBtn = ({ onClick }) => <button onClick={onClick} style={{ display: 'inline-flex', alignItems: 'center', gap: 5, background: 'none', border: 'none', color: accent, cursor: 'pointer', fontSize: 13, fontWeight: 700 }}><Plus size={14} /> Add</button>
const inputStyle = { width: '100%', padding: '10px 12px', border: '1px solid #e2e8f0', borderRadius: 9, fontSize: 13.5, outline: 'none', boxSizing: 'border-box', color: '#0f172a', background: '#fff' }
const Input = (props) => <input {...props} style={inputStyle} />
const Select = ({ opts, ...props }) => <select {...props} style={inputStyle}>{opts.map(o => <option key={o} value={o}>{o || 'Select…'}</option>)}</select>
const Field = ({ label, children, full }) => (
  <div style={full ? { gridColumn: '1/-1' } : undefined}>
    <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#475569', marginBottom: 6 }}>{label}</label>
    {children}
  </div>
)
