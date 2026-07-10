import { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, MapPin, Building2, Briefcase, Users, Calendar, IndianRupee,
  CheckCircle2, Upload, X,
} from 'lucide-react'
import { careersApi } from '@/services/careersApi'

const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const fmtSalary = (f, t) => (!f && !t) ? null : `₹${f ? (f / 100000).toFixed(1) : '0'}–${t ? (t / 100000).toFixed(1) : '0'} LPA`

const EMPTY = {
  name: '', email: '', phone: '', experience_years: '', current_company: '', location: '',
  skills: '', current_ctc: '', expected_ctc: '', notice_period: '', linkedin_url: '', cover_note: '',
}

export default function CareerJobDetails() {
  const { slug, id } = useParams()
  const navigate = useNavigate()
  const [tenant, setTenant] = useState(null)
  const [job, setJob] = useState(null)
  const [loading, setLoading] = useState(true)
  const [notFound, setNotFound] = useState(false)
  const [showApply, setShowApply] = useState(false)

  const accent = tenant?.branding_color || '#2563EB'

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [t, j] = await Promise.all([careersApi.tenant(slug), careersApi.job(slug, id)])
      setTenant(t); setJob(j)
    } catch (e) { setNotFound(true) }
    finally { setLoading(false) }
  }, [slug, id])
  useEffect(() => { load() }, [load])

  if (loading) return <div style={{ minHeight: '100vh', background: '#f8fafc', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#94a3b8' }}>Loading…</div>
  if (notFound || !job) return (
    <div style={{ minHeight: '100vh', background: '#f8fafc', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 10, textAlign: 'center', padding: 24, fontFamily: 'system-ui,sans-serif' }}>
      <h1 style={{ fontSize: 22, fontWeight: 800, color: '#0f172a' }}>Position no longer available</h1>
      <button onClick={() => navigate(`/careers/${slug}`)} style={{ color: '#2563EB', background: 'none', border: 'none', cursor: 'pointer', fontSize: 14 }}>← Back to all jobs</button>
    </div>
  )

  const facts = [
    [Building2, 'Department', job.department],
    [MapPin, 'Location', job.location],
    [Briefcase, 'Employment Type', job.job_type],
    [Users, 'Openings', job.number_of_openings],
    [IndianRupee, 'Salary', fmtSalary(job.salary_from, job.salary_to)],
    [Calendar, 'Apply By', fmtDate(job.closing_date)],
  ].filter(([, , v]) => v)

  return (
    <div style={{ minHeight: '100vh', background: '#f8fafc', color: '#0f172a', fontFamily: 'system-ui, -apple-system, sans-serif' }}>
      <header style={{ background: '#fff', borderBottom: '1px solid #e2e8f0' }}>
        <div style={{ maxWidth: 900, margin: '0 auto', padding: '14px 24px', display: 'flex', alignItems: 'center', gap: 10 }}>
          <button onClick={() => navigate(`/careers/${slug}`)} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, background: 'none', border: 'none', color: '#475569', cursor: 'pointer', fontSize: 13.5 }}><ArrowLeft size={16} /> {tenant?.name || 'Careers'}</button>
        </div>
      </header>

      <div style={{ maxWidth: 900, margin: '0 auto', padding: '32px 24px 60px' }}>
        {/* Title card */}
        <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 16, padding: 28 }}>
          <h1 style={{ fontSize: 28, fontWeight: 900, letterSpacing: '-0.02em', margin: 0 }}>{job.title}</h1>
          <div style={{ display: 'flex', gap: 10, marginTop: 12, flexWrap: 'wrap', color: '#475569', fontSize: 14 }}>
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><Building2 size={15} /> {job.department}</span>
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><MapPin size={15} /> {job.location}</span>
            <span style={{ padding: '2px 10px', borderRadius: 8, fontSize: 12.5, fontWeight: 600, background: `${accent}15`, color: accent }}>{job.job_type}</span>
          </div>
          <button onClick={() => setShowApply(true)} style={{ marginTop: 22, padding: '12px 28px', borderRadius: 10, background: accent, color: '#fff', border: 'none', cursor: 'pointer', fontWeight: 700, fontSize: 15 }}>Apply Now</button>
        </div>

        {/* Facts */}
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))', gap: 12, margin: '16px 0' }}>
          {facts.map(([Icon, k, v]) => (
            <div key={k} style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12, padding: '12px 14px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 5, fontSize: 11, color: '#94a3b8', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.04em', marginBottom: 4 }}><Icon size={13} /> {k}</div>
              <div style={{ fontSize: 14, fontWeight: 600 }}>{v}</div>
            </div>
          ))}
        </div>

        {/* JD */}
        <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 16, padding: 28 }}>
          {job.description && <Section title="Job Description" text={job.description} />}
          {job.requirements && <Section title="Requirements" text={job.requirements} />}
          {!job.description && !job.requirements && <p style={{ color: '#94a3b8' }}>Full job description available on request.</p>}
          <button onClick={() => setShowApply(true)} style={{ marginTop: 22, padding: '12px 28px', borderRadius: 10, background: accent, color: '#fff', border: 'none', cursor: 'pointer', fontWeight: 700, fontSize: 15 }}>Apply for this role</button>
        </div>
      </div>

      {showApply && <ApplyModal slug={slug} jobId={id} jobTitle={job.title} accent={accent} onClose={() => setShowApply(false)} />}
    </div>
  )
}

const Section = ({ title, text }) => (
  <div style={{ marginBottom: 22 }}>
    <h2 style={{ fontSize: 16, fontWeight: 800, margin: '0 0 10px' }}>{title}</h2>
    <p style={{ fontSize: 14.5, lineHeight: 1.7, color: '#334155', margin: 0, whiteSpace: 'pre-wrap' }}>{text}</p>
  </div>
)

// ── Application form modal ───────────────────────────────────────────────────
function ApplyModal({ slug, jobId, jobTitle, accent, onClose }) {
  const [form, setForm] = useState(EMPTY)
  const [resume, setResume] = useState(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [done, setDone] = useState(null) // { reference }

  const set = (k) => (e) => setForm(p => ({ ...p, [k]: e.target.value }))

  const submit = async () => {
    setError('')
    if (!form.name || !form.email || !form.phone) { setError('Name, email and phone are required.'); return }
    if (!resume) { setError('Please attach your resume (PDF/DOC/DOCX).'); return }
    setBusy(true)
    try {
      const fd = new FormData()
      Object.entries(form).forEach(([k, v]) => { if (v !== '' && v != null) fd.append(k, v) })
      fd.append('resume', resume)
      const r = await careersApi.apply(slug, jobId, fd)
      setDone({ reference: r?.reference })
    } catch (e) {
      const errs = e?.response?.data?.errors
      setError(errs ? Object.values(errs)[0][0] : (e?.response?.data?.message || 'Something went wrong. Please try again.'))
    } finally { setBusy(false) }
  }

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.55)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }}
      onClick={e => e.target === e.currentTarget && !busy && onClose()}>
      <div style={{ background: '#fff', borderRadius: 16, width: '100%', maxWidth: 620, maxHeight: '92vh', overflowY: 'auto', padding: 28 }}>
        {done ? (
          <div style={{ textAlign: 'center', padding: '20px 10px' }}>
            <CheckCircle2 size={54} style={{ color: '#10b981', marginBottom: 14 }} />
            <h2 style={{ fontSize: 22, fontWeight: 800, margin: 0 }}>Application submitted!</h2>
            <p style={{ color: '#475569', margin: '10px 0 4px', fontSize: 14.5 }}>Thanks for applying to <strong>{jobTitle}</strong>. Our team will review your application and get in touch.</p>
            {done.reference && <p style={{ color: '#94a3b8', fontSize: 13 }}>Reference: <strong>{done.reference}</strong></p>}
            <button onClick={onClose} style={{ marginTop: 18, padding: '11px 28px', borderRadius: 10, background: accent, color: '#fff', border: 'none', cursor: 'pointer', fontWeight: 700, fontSize: 14 }}>Done</button>
          </div>
        ) : (
          <>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 6 }}>
              <div>
                <h2 style={{ fontSize: 20, fontWeight: 800, margin: 0 }}>Apply for this role</h2>
                <p style={{ color: '#64748b', fontSize: 13.5, margin: '4px 0 0' }}>{jobTitle}</p>
              </div>
              <button onClick={() => !busy && onClose()} style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#94a3b8' }}><X size={20} /></button>
            </div>

            {error && <div style={{ background: '#fef2f2', color: '#b91c1c', border: '1px solid #fecaca', borderRadius: 10, padding: '10px 14px', fontSize: 13, margin: '14px 0' }}>{error}</div>}

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14, marginTop: 14 }}>
              <Field label="Full Name *" full><Input value={form.name} onChange={set('name')} placeholder="Your name" /></Field>
              <Field label="Email *"><Input type="email" value={form.email} onChange={set('email')} placeholder="you@email.com" /></Field>
              <Field label="Phone *"><Input value={form.phone} onChange={set('phone')} placeholder="Mobile number" /></Field>
              <Field label="Total Experience (years)"><Input type="number" min="0" step="0.5" value={form.experience_years} onChange={set('experience_years')} placeholder="e.g. 4" /></Field>
              <Field label="Current Company"><Input value={form.current_company} onChange={set('current_company')} placeholder="Company" /></Field>
              <Field label="Current Location"><Input value={form.location} onChange={set('location')} placeholder="City" /></Field>
              <Field label="Notice Period"><Input value={form.notice_period} onChange={set('notice_period')} placeholder="e.g. 30 days / Immediate" /></Field>
              <Field label="Current CTC (₹)"><Input type="number" min="0" value={form.current_ctc} onChange={set('current_ctc')} placeholder="e.g. 1200000" /></Field>
              <Field label="Expected CTC (₹)"><Input type="number" min="0" value={form.expected_ctc} onChange={set('expected_ctc')} placeholder="e.g. 1800000" /></Field>
              <Field label="Skills (comma-separated)" full><Input value={form.skills} onChange={set('skills')} placeholder="e.g. React, Node.js, SQL" /></Field>
              <Field label="LinkedIn URL" full><Input value={form.linkedin_url} onChange={set('linkedin_url')} placeholder="https://linkedin.com/in/…" /></Field>
              <Field label="Resume (PDF/DOC/DOCX) *" full>
                <label style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '11px 14px', border: `1px dashed ${resume ? accent : '#cbd5e1'}`, borderRadius: 10, cursor: 'pointer', background: resume ? `${accent}0d` : '#f8fafc' }}>
                  <Upload size={16} style={{ color: accent }} />
                  <span style={{ fontSize: 13.5, color: resume ? '#0f172a' : '#64748b' }}>{resume ? resume.name : 'Click to upload your resume (max 5 MB)'}</span>
                  <input type="file" accept=".pdf,.doc,.docx" onChange={e => setResume(e.target.files?.[0] || null)} style={{ display: 'none' }} />
                </label>
              </Field>
              <Field label="Cover Note (optional)" full><textarea value={form.cover_note} onChange={set('cover_note')} rows={3} placeholder="Why are you a great fit?" style={{ ...inputStyle, resize: 'vertical' }} /></Field>
            </div>

            <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end', marginTop: 22 }}>
              <button onClick={() => !busy && onClose()} style={{ padding: '11px 22px', borderRadius: 10, background: '#fff', border: '1px solid #e2e8f0', color: '#475569', cursor: 'pointer', fontSize: 14 }}>Cancel</button>
              <button onClick={submit} disabled={busy} style={{ padding: '11px 28px', borderRadius: 10, background: busy ? '#94a3b8' : accent, color: '#fff', border: 'none', cursor: busy ? 'not-allowed' : 'pointer', fontWeight: 700, fontSize: 14 }}>{busy ? 'Submitting…' : 'Submit Application'}</button>
            </div>
          </>
        )}
      </div>
    </div>
  )
}

const inputStyle = { width: '100%', padding: '10px 12px', border: '1px solid #e2e8f0', borderRadius: 9, fontSize: 13.5, outline: 'none', boxSizing: 'border-box', color: '#0f172a', background: '#fff' }
const Input = (props) => <input {...props} style={inputStyle} />
const Field = ({ label, children, full }) => (
  <div style={full ? { gridColumn: '1/-1' } : undefined}>
    <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#475569', marginBottom: 6 }}>{label}</label>
    {children}
  </div>
)
