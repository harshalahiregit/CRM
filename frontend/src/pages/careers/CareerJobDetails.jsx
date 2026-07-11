import { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, MapPin, Building2, Briefcase, Users, Calendar, IndianRupee,
  CheckCircle2, Upload, X, Clock, Video, Link2, Download, FileText, Search,
} from 'lucide-react'
import { careersApi } from '@/services/careersApi'

const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const fmtDateTime = (d) => d ? new Date(d).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' }) : '—'
const fmtSalary = (f, t) => (!f && !t) ? null : `₹${f ? (f / 100000).toFixed(1) : '0'}–${t ? (t / 100000).toFixed(1) : '0'} LPA`
const fmtCTC = (v) => v == null || v === '' ? null : `₹${(Number(v) / 100000).toFixed(1)} LPA`

// Candidate-facing labels for each pipeline stage.
const STAGE_META = {
  Applied:    { label: 'Application Submitted', color: '#2563EB' },
  Screening:  { label: 'Under Screening',       color: '#d97706' },
  Assessment: { label: 'Assessment Stage',      color: '#7c3aed' },
  Interview:  { label: 'Interview Stage',        color: '#4f46e5' },
  Offer:      { label: 'Offer Extended',         color: '#059669' },
  Hired:      { label: 'Hired',                   color: '#047857' },
  Rejected:   { label: 'Not Selected',            color: '#dc2626' },
}
const stageMeta = (s) => STAGE_META[s] || { label: s || 'Submitted', color: '#2563EB' }

// One application identity per (portal, job), remembered on this browser.
const lsKey = (slug, id) => `career_app::${slug}::${id}`
const savedIdentity = (slug, id) => { try { return JSON.parse(localStorage.getItem(lsKey(slug, id)) || 'null') } catch { return null } }
const saveIdentity = (slug, id, email) => { try { localStorage.setItem(lsKey(slug, id), JSON.stringify({ email })) } catch { /* ignore */ } }

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
  const [showTrack, setShowTrack] = useState(false)
  const [application, setApplication] = useState(null) // status payload once known
  const [appEmail, setAppEmail] = useState(null)        // the identity we're tracking

  const accent = tenant?.branding_color || '#2563EB'

  const refreshStatus = useCallback(async (email) => {
    if (!email) return null
    try {
      const s = await careersApi.status(slug, id, { email })
      if (s?.applied) { setApplication(s); setAppEmail(email); return s }
      return s
    } catch { return null }
  }, [slug, id])

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [t, j] = await Promise.all([careersApi.tenant(slug), careersApi.job(slug, id)])
      setTenant(t); setJob(j)
      const saved = savedIdentity(slug, id)
      if (saved?.email) await refreshStatus(saved.email)
    } catch (e) { setNotFound(true) }
    finally { setLoading(false) }
  }, [slug, id, refreshStatus])
  useEffect(() => { load() }, [load])

  // Called when an application is confirmed (fresh apply OR "already applied").
  const onApplied = async (email) => {
    saveIdentity(slug, id, email)
    await refreshStatus(email)
    setShowApply(false)
  }

  if (loading) return <div style={{ minHeight: '100vh', background: '#f8fafc', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#94a3b8' }}>Loading…</div>
  if (notFound || !job) return (
    <div style={{ minHeight: '100vh', background: '#f8fafc', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 10, textAlign: 'center', padding: 24, fontFamily: 'system-ui,sans-serif' }}>
      <h1 style={{ fontSize: 22, fontWeight: 800, color: '#0f172a' }}>Position no longer available</h1>
      <button onClick={() => navigate(`/careers/${slug}`)} style={{ color: '#2563EB', background: 'none', border: 'none', cursor: 'pointer', fontSize: 14 }}>← Back to all jobs</button>
    </div>
  )

  const applied = application?.applied
  const facts = [
    [Building2, 'Department', job.department],
    [MapPin, 'Location', job.location],
    [Briefcase, 'Employment Type', job.job_type],
    [Users, 'Openings', job.number_of_openings],
    [IndianRupee, 'Salary', fmtSalary(job.salary_from, job.salary_to)],
    [Calendar, 'Apply By', fmtDate(job.closing_date)],
  ].filter(([, , v]) => v)

  // The Apply / Applied CTA block (reused in two places).
  const cta = applied ? (
    <div style={{ marginTop: 22, display: 'inline-flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '10px 18px', borderRadius: 10, background: '#ecfdf5', color: '#047857', fontWeight: 700, fontSize: 14, border: '1px solid #a7f3d0' }}>
        <CheckCircle2 size={16} /> Application Submitted
      </span>
      <a href="#track" style={{ padding: '10px 18px', borderRadius: 10, background: accent, color: '#fff', fontWeight: 700, fontSize: 14, textDecoration: 'none' }}>View / Track Application</a>
    </div>
  ) : (
    <div style={{ marginTop: 22, display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'center' }}>
      <button onClick={() => setShowApply(true)} style={{ padding: '12px 28px', borderRadius: 10, background: accent, color: '#fff', border: 'none', cursor: 'pointer', fontWeight: 700, fontSize: 15 }}>Apply Now</button>
      <button onClick={() => setShowTrack(true)} style={{ padding: '12px 8px', background: 'none', border: 'none', color: '#475569', cursor: 'pointer', fontSize: 13.5, display: 'inline-flex', alignItems: 'center', gap: 6 }}><Search size={15} /> Already applied? Track your application</button>
    </div>
  )

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
          {cta}
        </div>

        {/* Application tracking card */}
        {applied && <TrackingCard app={application} slug={slug} jobId={id} email={appEmail} accent={accent} onChange={setApplication} />}

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
          {!applied && <button onClick={() => setShowApply(true)} style={{ marginTop: 22, padding: '12px 28px', borderRadius: 10, background: accent, color: '#fff', border: 'none', cursor: 'pointer', fontWeight: 700, fontSize: 15 }}>Apply for this role</button>}
        </div>
      </div>

      {showApply && <ApplyModal slug={slug} jobId={id} jobTitle={job.title} accent={accent} onApplied={onApplied} onClose={() => setShowApply(false)} />}
      {showTrack && <TrackModal accent={accent} onClose={() => setShowTrack(false)} onTrack={async (email) => {
        const s = await careersApi.status(slug, id, { email })
        if (s?.applied) { saveIdentity(slug, id, email); setApplication(s); setAppEmail(email); setShowTrack(false); return true }
        return false
      }} />}
    </div>
  )
}

const Section = ({ title, text }) => (
  <div style={{ marginBottom: 22 }}>
    <h2 style={{ fontSize: 16, fontWeight: 800, margin: '0 0 10px' }}>{title}</h2>
    <p style={{ fontSize: 14.5, lineHeight: 1.7, color: '#334155', margin: 0, whiteSpace: 'pre-wrap' }}>{text}</p>
  </div>
)

// ── Application status / tracking card ───────────────────────────────────────
function TrackingCard({ app, slug, jobId, email, accent, onChange }) {
  const [busy, setBusy] = useState(false)
  const meta = stageMeta(app.stage)
  const iv = app.interview
  const offer = app.offer

  const respond = async (action) => {
    let reason = null
    if (action === 'decline') { reason = window.prompt('Optionally, let us know why you are declining:') ; if (reason === null) return }
    setBusy(true)
    try {
      const s = await careersApi.respondOffer(slug, jobId, { email, action, reason: reason || undefined })
      onChange(s)
    } catch (e) { alert(e?.response?.data?.message || 'Could not update the offer. Please try again.') }
    finally { setBusy(false) }
  }

  return (
    <div id="track" style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 16, padding: 24, marginTop: 16 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 10 }}>
        <div>
          <div style={{ fontSize: 11, color: '#94a3b8', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.05em' }}>Your Application</div>
          <div style={{ fontSize: 18, fontWeight: 800, marginTop: 2 }}>Application Submitted</div>
        </div>
        <span style={{ padding: '6px 14px', borderRadius: 999, background: `${meta.color}15`, color: meta.color, fontWeight: 700, fontSize: 13 }}>{meta.label}</span>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(160px,1fr))', gap: 12, marginTop: 16 }}>
        <Info label="Reference" value={app.reference} />
        <Info label="Applied Date" value={fmtDate(app.applied_at)} />
        <Info label="Current Status" value={meta.label} />
        {app.final_decision && app.final_decision !== 'Pending' && <Info label="Decision" value={app.final_decision} />}
      </div>

      {/* Interview */}
      {iv && (
        <div style={{ marginTop: 18, padding: 16, borderRadius: 12, background: '#f8fafc', border: '1px solid #e2e8f0' }}>
          <div style={{ fontSize: 13, fontWeight: 800, display: 'flex', alignItems: 'center', gap: 7, marginBottom: 10 }}><Clock size={15} style={{ color: accent }} /> Interview Scheduled — {iv.round_name}</div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))', gap: 12 }}>
            <Info label="Date & Time" value={fmtDateTime(iv.scheduled_at)} />
            <Info label="Mode" value={iv.mode === 'offline' ? 'In-person (Offline)' : 'Online'} />
            {iv.mode === 'offline' && iv.venue && <Info label="Venue" value={iv.venue} icon={<MapPin size={12} />} />}
          </div>
          {iv.mode === 'online' && iv.meet_link && (
            <a href={iv.meet_link} target="_blank" rel="noreferrer" style={{ marginTop: 12, display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 16px', borderRadius: 9, background: accent, color: '#fff', textDecoration: 'none', fontWeight: 700, fontSize: 13 }}><Video size={14} /> Join Meeting</a>
          )}
        </div>
      )}

      {/* Offer */}
      {offer && (
        <div style={{ marginTop: 18, padding: 16, borderRadius: 12, background: '#ecfdf5', border: '1px solid #a7f3d0' }}>
          <div style={{ fontSize: 13, fontWeight: 800, display: 'flex', alignItems: 'center', gap: 7, marginBottom: 10, color: '#047857' }}><FileText size={15} /> Offer — {offer.status}</div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))', gap: 12 }}>
            {offer.position && <Info label="Position" value={offer.position} />}
            {fmtCTC(offer.offered_ctc) && <Info label="Offered CTC" value={fmtCTC(offer.offered_ctc)} />}
            {offer.joining_date && <Info label="Joining Date" value={fmtDate(offer.joining_date)} />}
          </div>
          <div style={{ display: 'flex', gap: 10, marginTop: 14, flexWrap: 'wrap' }}>
            {offer.can_download && (
              <a href={careersApi.offerLetterUrl(slug, jobId, email)} target="_blank" rel="noreferrer" style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 16px', borderRadius: 9, background: '#fff', border: '1px solid #a7f3d0', color: '#047857', textDecoration: 'none', fontWeight: 700, fontSize: 13 }}><Download size={14} /> Download Offer</a>
            )}
            {offer.can_respond && (
              <>
                <button onClick={() => respond('accept')} disabled={busy} style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 18px', borderRadius: 9, background: '#059669', color: '#fff', border: 'none', cursor: busy ? 'not-allowed' : 'pointer', fontWeight: 700, fontSize: 13 }}><CheckCircle2 size={14} /> Accept</button>
                <button onClick={() => respond('decline')} disabled={busy} style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 18px', borderRadius: 9, background: '#fff', border: '1px solid #fecaca', color: '#b91c1c', cursor: busy ? 'not-allowed' : 'pointer', fontWeight: 700, fontSize: 13 }}><X size={14} /> Decline</button>
              </>
            )}
            {offer.status === 'Accepted' && <span style={{ color: '#047857', fontWeight: 700, fontSize: 13 }}>🎉 You accepted this offer.</span>}
            {offer.status === 'Rejected' && <span style={{ color: '#b91c1c', fontWeight: 700, fontSize: 13 }}>You declined this offer.</span>}
          </div>
        </div>
      )}
    </div>
  )
}

const Info = ({ label, value, icon }) => (
  <div>
    <div style={{ fontSize: 10.5, color: '#94a3b8', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.04em', marginBottom: 3 }}>{label}</div>
    <div style={{ fontSize: 14, fontWeight: 600, display: 'flex', alignItems: 'center', gap: 5 }}>{icon}{value || '—'}</div>
  </div>
)

// ── Track-an-existing-application modal (email lookup) ────────────────────────
function TrackModal({ accent, onClose, onTrack }) {
  const [email, setEmail] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const submit = async () => {
    if (!email) { setErr('Enter the email you applied with.'); return }
    setBusy(true); setErr('')
    try {
      const ok = await onTrack(email)
      if (!ok) setErr('No application found for this email on this role.')
    } catch { setErr('Something went wrong. Please try again.') }
    finally { setBusy(false) }
  }

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.55)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }} onClick={e => e.target === e.currentTarget && !busy && onClose()}>
      <div style={{ background: '#fff', borderRadius: 16, width: '100%', maxWidth: 440, padding: 26 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
          <h2 style={{ fontSize: 18, fontWeight: 800, margin: 0 }}>Track your application</h2>
          <button onClick={() => !busy && onClose()} style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#94a3b8' }}><X size={20} /></button>
        </div>
        <p style={{ color: '#64748b', fontSize: 13.5, margin: '4px 0 14px' }}>Enter the email you applied with to see your status.</p>
        {err && <div style={{ background: '#fef2f2', color: '#b91c1c', border: '1px solid #fecaca', borderRadius: 10, padding: '9px 13px', fontSize: 13, marginBottom: 12 }}>{err}</div>}
        <input type="email" value={email} onChange={e => setEmail(e.target.value)} placeholder="you@email.com" style={inputStyle} />
        <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end', marginTop: 18 }}>
          <button onClick={() => !busy && onClose()} style={{ padding: '10px 20px', borderRadius: 10, background: '#fff', border: '1px solid #e2e8f0', color: '#475569', cursor: 'pointer', fontSize: 14 }}>Cancel</button>
          <button onClick={submit} disabled={busy} style={{ padding: '10px 24px', borderRadius: 10, background: busy ? '#94a3b8' : accent, color: '#fff', border: 'none', cursor: busy ? 'not-allowed' : 'pointer', fontWeight: 700, fontSize: 14 }}>{busy ? 'Checking…' : 'Track'}</button>
        </div>
      </div>
    </div>
  )
}

// ── Application form modal ───────────────────────────────────────────────────
function ApplyModal({ slug, jobId, jobTitle, accent, onApplied, onClose }) {
  const [form, setForm] = useState(EMPTY)
  const [resume, setResume] = useState(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [duplicate, setDuplicate] = useState(false)

  const set = (k) => (e) => setForm(p => ({ ...p, [k]: e.target.value }))

  const submit = async () => {
    setError(''); setDuplicate(false)
    if (!form.name || !form.email || !form.phone) { setError('Name, email and phone are required.'); return }
    if (!resume) { setError('Please attach your resume (PDF/DOC/DOCX).'); return }
    setBusy(true)
    try {
      const fd = new FormData()
      Object.entries(form).forEach(([k, v]) => { if (v !== '' && v != null) fd.append(k, v) })
      fd.append('resume', resume)
      await careersApi.apply(slug, jobId, fd)
      await onApplied(form.email) // saves identity + loads the tracking card
    } catch (e) {
      // Already applied → surface the tracking card instead of an error.
      if (e?.response?.status === 409) { setDuplicate(true); await onApplied(form.email); return }
      const errs = e?.response?.data?.errors
      setError(errs ? Object.values(errs)[0][0] : (e?.response?.data?.message || 'Something went wrong. Please try again.'))
    } finally { setBusy(false) }
  }

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.55)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }}
      onClick={e => e.target === e.currentTarget && !busy && onClose()}>
      <div style={{ background: '#fff', borderRadius: 16, width: '100%', maxWidth: 620, maxHeight: '92vh', overflowY: 'auto', padding: 28 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 6 }}>
          <div>
            <h2 style={{ fontSize: 20, fontWeight: 800, margin: 0 }}>Apply for this role</h2>
            <p style={{ color: '#64748b', fontSize: 13.5, margin: '4px 0 0' }}>{jobTitle}</p>
          </div>
          <button onClick={() => !busy && onClose()} style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#94a3b8' }}><X size={20} /></button>
        </div>

        {duplicate && <div style={{ background: '#eff6ff', color: '#1d4ed8', border: '1px solid #bfdbfe', borderRadius: 10, padding: '10px 14px', fontSize: 13, margin: '14px 0' }}>You've already applied for this position — showing your application status.</div>}
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
