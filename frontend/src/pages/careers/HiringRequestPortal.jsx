import { useState, useEffect } from 'react'
import { useParams } from 'react-router-dom'
import { hrApi } from '@/services/hrApi'

const unwrap = r => r?.data ?? r
const box = { width: '100%', padding: '11px 14px', background: '#fff', border: '1px solid #e5e7eb', borderRadius: 10, fontSize: 14, outline: 'none', boxSizing: 'border-box' }
const lbl = { display: 'block', fontSize: 12, fontWeight: 700, color: '#374151', marginBottom: 6 }

/**
 * Public hiring-request submission (no auth). An external company reaches this
 * via its token link and submits a requirement — which lands in Recruitment
 * Services as a "Portal" hiring request for HR to review.
 */
export default function HiringRequestPortal() {
  const { token } = useParams()
  const [company, setCompany] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [done, setDone] = useState(false)
  const [form, setForm] = useState({ job_title: '', department: '', employment_type: 'Full-time', work_mode: 'Onsite', number_of_positions: 1, experience_required: '', location: '', salary_min: '', salary_max: '', required_skills: '', job_description: '', priority: 'Medium', submitter_name: '', submitter_email: '' })

  useEffect(() => {
    hrApi.hiringPortal.company(token)
      .then(r => setCompany(unwrap(r)))
      .catch(() => setError('This hiring-request link is invalid or inactive.'))
      .finally(() => setLoading(false))
  }, [token])

  const set = k => e => setForm(f => ({ ...f, [k]: e.target.value }))

  const submit = async () => {
    if (!form.job_title.trim()) { alert('Job title is required'); return }
    if (form.salary_min && form.salary_max && Number(form.salary_min) > Number(form.salary_max)) { alert('Salary Max cannot be less than Salary Min'); return }
    setSubmitting(true)
    try {
      await hrApi.hiringPortal.submit(token, {
        ...form,
        number_of_positions: Number(form.number_of_positions) || 1,
        salary_min: form.salary_min === '' ? null : Number(form.salary_min),
        salary_max: form.salary_max === '' ? null : Number(form.salary_max),
        required_skills: form.required_skills ? form.required_skills.split(',').map(s => s.trim()).filter(Boolean) : [],
      })
      setDone(true)
    } catch (e) { alert(e.response?.data?.message || 'Submission failed. Please try again.') }
    finally { setSubmitting(false) }
  }

  const wrap = { minHeight: '100vh', background: 'linear-gradient(135deg,#f5f3ff,#eef2ff)', display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: 24 }
  if (loading) return <div style={{ ...wrap, alignItems: 'center', color: '#6b7280' }}>Loading…</div>
  if (error) return <div style={{ ...wrap, alignItems: 'center' }}><div style={{ background: '#fff', padding: 32, borderRadius: 16, color: '#ef4444', fontWeight: 600 }}>{error}</div></div>
  if (done) return (
    <div style={{ ...wrap, alignItems: 'center' }}>
      <div style={{ background: '#fff', padding: 40, borderRadius: 18, textAlign: 'center', maxWidth: 440, boxShadow: '0 20px 60px rgba(0,0,0,0.1)' }}>
        <div style={{ width: 56, height: 56, borderRadius: 16, background: 'rgba(16,185,129,0.12)', color: '#10b981', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 28, margin: '0 auto 16px' }}>✓</div>
        <h2 style={{ margin: 0, fontSize: 20, fontWeight: 800, color: '#111827' }}>Request Submitted</h2>
        <p style={{ marginTop: 8, color: '#6b7280', fontSize: 14 }}>Thank you. Your hiring request has been sent to the recruitment team for review.</p>
      </div>
    </div>
  )

  return (
    <div style={wrap}>
      <div style={{ background: '#fff', width: '100%', maxWidth: 720, borderRadius: 18, boxShadow: '0 20px 60px rgba(0,0,0,0.08)', overflow: 'hidden' }}>
        <div style={{ padding: '24px 28px', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff' }}>
          <p style={{ margin: 0, fontSize: 12, fontWeight: 700, opacity: 0.85, textTransform: 'uppercase', letterSpacing: '0.06em' }}>Hiring Request</p>
          <h1 style={{ margin: '4px 0 0', fontSize: 22, fontWeight: 800 }}>{company?.company_name}</h1>
          <p style={{ margin: '6px 0 0', fontSize: 13, opacity: 0.9 }}>Tell us about the role you're hiring for. Our recruitment team will take it from here.</p>
        </div>
        <div style={{ padding: 28, display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(160px,1fr))', gap: 16 }}>
          <div style={{ gridColumn: '1 / -1' }}><label style={lbl}>Job Title *</label><input value={form.job_title} onChange={set('job_title')} style={box} placeholder="e.g. Site Engineer" /></div>
          <div><label style={lbl}>Department</label><input value={form.department} onChange={set('department')} style={box} /></div>
          <div><label style={lbl}>Positions</label><input type="number" min="1" value={form.number_of_positions} onChange={set('number_of_positions')} style={box} /></div>
          <div><label style={lbl}>Employment Type</label><select value={form.employment_type} onChange={set('employment_type')} style={box}>{['Full-time', 'Part-time', 'Contract', 'Internship'].map(t => <option key={t}>{t}</option>)}</select></div>
          <div><label style={lbl}>Work Mode</label><select value={form.work_mode} onChange={set('work_mode')} style={box}>{['Onsite', 'Remote', 'Hybrid'].map(t => <option key={t}>{t}</option>)}</select></div>
          <div><label style={lbl}>Experience</label><input value={form.experience_required} onChange={set('experience_required')} style={box} placeholder="e.g. 3-5 years" /></div>
          <div><label style={lbl}>Location</label><input value={form.location} onChange={set('location')} style={box} /></div>
          <div><label style={lbl}>Salary Min (₹/yr)</label><input type="number" value={form.salary_min} onChange={set('salary_min')} style={box} /></div>
          <div><label style={lbl}>Salary Max (₹/yr)</label><input type="number" value={form.salary_max} onChange={set('salary_max')} style={box} /></div>
          <div style={{ gridColumn: '1 / -1' }}><label style={lbl}>Required Skills (comma-separated)</label><input value={form.required_skills} onChange={set('required_skills')} style={box} placeholder="e.g. AutoCAD, Surveying" /></div>
          <div style={{ gridColumn: '1 / -1' }}><label style={lbl}>Job Description</label><textarea rows={3} value={form.job_description} onChange={set('job_description')} style={{ ...box, resize: 'vertical' }} /></div>
          <div><label style={lbl}>Your Name</label><input value={form.submitter_name} onChange={set('submitter_name')} style={box} /></div>
          <div><label style={lbl}>Your Email</label><input type="email" value={form.submitter_email} onChange={set('submitter_email')} style={box} /></div>
          <div style={{ gridColumn: '1 / -1', marginTop: 4 }}>
            <button onClick={submit} disabled={submitting} style={{ width: '100%', padding: '13px 0', borderRadius: 12, border: 'none', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontWeight: 800, fontSize: 15, cursor: 'pointer', opacity: submitting ? 0.7 : 1 }}>
              {submitting ? 'Submitting…' : 'Submit Hiring Request'}
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}
