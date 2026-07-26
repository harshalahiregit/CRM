import { useState, useEffect, useCallback } from 'react'
import { useParams } from 'react-router-dom'
import { hrApi } from '@/services/hrApi'

const unwrap = r => r?.data ?? r
const SUB_STATUS = {
  'Pending Client Review': { c: '#b45309', bg: '#fef3c7' },
  'Accepted':              { c: '#047857', bg: '#d1fae5' },
  'Rejected':              { c: '#b91c1c', bg: '#fee2e2' },
  'Need More Profiles':    { c: '#1d4ed8', bg: '#dbeafe' },
}

/**
 * Public client-tracking portal (no auth). A client follows a hiring request via
 * its tracking token: progress counts, status timeline, and the candidates
 * delivered to them — on which they can Accept / Reject / Request More Profiles.
 */
export default function ClientTrackingPortal() {
  const { token } = useParams()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [fb, setFb] = useState(null) // { submission, decision }
  const [comment, setComment] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    try {
      const r = await hrApi.clientTracking.show(token)
      setData(unwrap(r))
    } catch (e) { setError('This tracking link is invalid.') }
    finally { setLoading(false) }
  }, [token])
  useEffect(() => { load() }, [load])

  const sendFeedback = async () => {
    if (!fb) return
    setBusy(true)
    try {
      await hrApi.clientTracking.feedback(token, fb.submission.id, fb.decision, comment)
      setFb(null); setComment(''); await load()
    } catch (e) { alert(e.response?.data?.message || 'Could not record feedback') }
    finally { setBusy(false) }
  }

  const wrap = { minHeight: '100vh', background: 'linear-gradient(135deg,#f5f3ff,#eef2ff)', padding: 24 }
  if (loading) return <div style={{ ...wrap, display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#6b7280' }}>Loading…</div>
  if (error || !data || !data.request) return <div style={{ ...wrap, display: 'flex', alignItems: 'center', justifyContent: 'center' }}><div style={{ background: '#fff', padding: 32, borderRadius: 16, color: '#ef4444', fontWeight: 600 }}>{error || 'This tracking link is invalid.'}</div></div>

  const { request: req, company_name, recruiter, counts = {}, timeline = [], candidates = [], rating = null } = data
  const COUNTS = [
    ['Applications', counts.applications], ['Shortlisted', counts.shortlisted],
    ['Interviews', counts.interviews], ['Selected', counts.selected], ['Delivered', counts.delivered],
  ]
  const card = { background: '#fff', borderRadius: 16, boxShadow: '0 10px 40px rgba(0,0,0,0.06)', padding: 22, marginBottom: 18 }
  const lbl = { fontSize: 11, fontWeight: 700, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '0.05em' }

  return (
    <div style={wrap}>
      <div style={{ maxWidth: 860, margin: '0 auto' }}>
        {/* Header */}
        <div style={{ ...card, background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff' }}>
          <p style={{ margin: 0, fontSize: 12, fontWeight: 700, opacity: 0.85, textTransform: 'uppercase', letterSpacing: '0.06em' }}>Recruitment Tracking · {req.reference}</p>
          <h1 style={{ margin: '4px 0 0', fontSize: 24, fontWeight: 800 }}>{req.job_title}</h1>
          <p style={{ margin: '6px 0 0', fontSize: 13, opacity: 0.92 }}>
            {company_name}{req.department ? ` · ${req.department}` : ''}{req.location ? ` · ${req.location}` : ''} · {req.number_of_positions} position(s)
          </p>
          <div style={{ display: 'flex', gap: 16, marginTop: 12, flexWrap: 'wrap', fontSize: 12.5 }}>
            <span style={{ background: 'rgba(255,255,255,0.18)', padding: '4px 12px', borderRadius: 20, fontWeight: 700 }}>Status: {(req.status || '').replace(/_/g, ' ')}</span>
            <span style={{ opacity: 0.9 }}>Recruiter: {recruiter || 'Being assigned'}</span>
          </div>
        </div>

        {/* Counts */}
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(130px,1fr))', gap: 12, marginBottom: 18 }}>
          {COUNTS.map(([l, v]) => (
            <div key={l} style={{ background: '#fff', borderRadius: 14, boxShadow: '0 8px 30px rgba(0,0,0,0.05)', padding: '18px 12px', textAlign: 'center' }}>
              <div style={{ fontSize: 26, fontWeight: 900, color: '#5b21b6' }}>{v ?? 0}</div>
              <div style={{ ...lbl, marginTop: 4 }}>{l}</div>
            </div>
          ))}
        </div>

        {/* Delivered candidates */}
        <div style={card}>
          <h2 style={{ margin: '0 0 4px', fontSize: 17, fontWeight: 800, color: '#111827' }}>Candidates Submitted to You</h2>
          <p style={{ margin: '0 0 16px', fontSize: 12.5, color: '#6b7280' }}>Review each profile and share your decision.</p>
          {candidates.length === 0 ? (
            <p style={{ fontSize: 13, color: '#9ca3af' }}>No candidates have been submitted yet. You'll see them here as our team delivers shortlisted profiles.</p>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
              {candidates.map(c => {
                const st = SUB_STATUS[c.status] || {}
                const decided = c.status !== 'Pending Client Review'
                return (
                  <div key={c.id} style={{ border: '1px solid #e5e7eb', borderRadius: 12, padding: 16 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
                      <div>
                        <p style={{ margin: 0, fontWeight: 800, fontSize: 15, color: '#111827' }}>{c.name}</p>
                        <p style={{ margin: '3px 0 0', fontSize: 12.5, color: '#6b7280' }}>
                          {c.experience_years ? `${c.experience_years} yrs experience` : 'Experience N/A'}
                          {c.current_company ? ` · ${c.current_company}` : ''}{c.location ? ` · ${c.location}` : ''}
                        </p>
                        {Array.isArray(c.skills) && c.skills.length > 0 && (
                          <div style={{ display: 'flex', gap: 6, marginTop: 8, flexWrap: 'wrap' }}>
                            {c.skills.slice(0, 8).map((s, i) => <span key={i} style={{ fontSize: 11, background: '#f3f4f6', color: '#374151', padding: '3px 9px', borderRadius: 20 }}>{s}</span>)}
                          </div>
                        )}
                      </div>
                      <span style={{ fontSize: 11, fontWeight: 700, padding: '3px 10px', borderRadius: 20, background: st.bg, color: st.c, height: 'fit-content' }}>{c.status}</span>
                    </div>
                    {c.client_feedback && <p style={{ margin: '10px 0 0', fontSize: 12.5, color: '#374151', background: '#f9fafb', padding: '8px 12px', borderRadius: 8 }}>Your feedback: “{c.client_feedback}”</p>}
                    <div style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap' }}>
                      <button onClick={() => { setFb({ submission: c, decision: 'accept' }); setComment('') }} style={btn('#047857', '#d1fae5')}>Accept</button>
                      <button onClick={() => { setFb({ submission: c, decision: 'reject' }); setComment('') }} style={btn('#b91c1c', '#fee2e2')}>Reject</button>
                      <button onClick={() => { setFb({ submission: c, decision: 'more' }); setComment('') }} style={btn('#1d4ed8', '#dbeafe')}>Need More Profiles</button>
                      {c.resume_share_token && <a href={hrApi.clientTracking.resumeUrl(c.resume_share_token)} target="_blank" rel="noreferrer" style={{ ...btn('#5b21b6', '#ede9fe'), textDecoration: 'none' }}>↓ Download Resume</a>}
                      {decided && <span style={{ fontSize: 11.5, color: '#9ca3af', alignSelf: 'center' }}>You can update your decision anytime.</span>}
                    </div>
                  </div>
                )
              })}
            </div>
          )}
        </div>

        {/* Rating */}
        <div style={card}>
          <RatingBlock rating={rating} onSubmit={async (payload) => { await hrApi.clientTracking.rating(token, payload); await load() }} />
        </div>

        {/* Timeline */}
        <div style={card}>
          <h2 style={{ margin: '0 0 16px', fontSize: 17, fontWeight: 800, color: '#111827' }}>Status Timeline</h2>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 0 }}>
            {timeline.map((t, i) => (
              <div key={i} style={{ display: 'flex', gap: 12, paddingBottom: 14 }}>
                <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
                  <div style={{ width: 10, height: 10, borderRadius: '50%', background: t.by === 'Client' ? '#7C3AED' : '#10b981', marginTop: 4 }} />
                  {i < timeline.length - 1 && <div style={{ width: 2, flex: 1, background: '#e5e7eb', marginTop: 4 }} />}
                </div>
                <div style={{ paddingBottom: 2 }}>
                  <p style={{ margin: 0, fontSize: 13, fontWeight: 700, color: '#111827' }}>{t.action}</p>
                  {t.note && <p style={{ margin: '2px 0 0', fontSize: 12, color: '#6b7280' }}>{t.note}</p>}
                  <p style={{ margin: '2px 0 0', fontSize: 11, color: '#9ca3af' }}>{t.by} · {t.at ? new Date(t.at).toLocaleString() : ''}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* Feedback modal */}
      {fb && (
        <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20, zIndex: 1000 }} onClick={e => e.target === e.currentTarget && setFb(null)}>
          <div style={{ background: '#fff', borderRadius: 16, padding: 24, width: '100%', maxWidth: 440 }}>
            <h3 style={{ margin: '0 0 4px', fontSize: 17, fontWeight: 800, color: '#111827' }}>
              {fb.decision === 'accept' ? 'Accept' : fb.decision === 'reject' ? 'Reject' : 'Request More Profiles'}
            </h3>
            <p style={{ margin: '0 0 14px', fontSize: 13, color: '#6b7280' }}>{fb.submission.name}</p>
            <textarea rows={3} value={comment} onChange={e => setComment(e.target.value)} placeholder="Add a comment (optional)…" style={{ width: '100%', padding: '10px 12px', border: '1px solid #e5e7eb', borderRadius: 10, fontSize: 13, boxSizing: 'border-box', resize: 'vertical', outline: 'none' }} />
            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 16 }}>
              <button onClick={() => setFb(null)} style={{ padding: '9px 16px', borderRadius: 10, border: '1px solid #e5e7eb', background: '#fff', color: '#6b7280', fontWeight: 700, fontSize: 13, cursor: 'pointer' }}>Cancel</button>
              <button onClick={sendFeedback} disabled={busy} style={{ padding: '9px 18px', borderRadius: 10, border: 'none', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontWeight: 800, fontSize: 13, cursor: 'pointer', opacity: busy ? 0.7 : 1 }}>{busy ? 'Sending…' : 'Submit Feedback'}</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

const btn = (color, bg) => ({ padding: '7px 14px', borderRadius: 9, border: 'none', background: bg, color, fontWeight: 700, fontSize: 12.5, cursor: 'pointer' })

function StarRow({ value, onChange, readOnly }) {
  return (
    <span style={{ display: 'inline-flex', gap: 3 }}>
      {[1, 2, 3, 4, 5].map(i => (
        <span key={i} onClick={() => !readOnly && onChange?.(i)}
          style={{ cursor: readOnly ? 'default' : 'pointer', color: i <= Math.round(value) ? '#f59e0b' : '#d1d5db', fontSize: 22, lineHeight: 1 }}>★</span>
      ))}
    </span>
  )
}

function RatingBlock({ rating, onSubmit }) {
  const [form, setForm] = useState({ overall: 0, communication: 0, candidate_quality: 0, turnaround_time: 0, feedback: '' })
  const [busy, setBusy] = useState(false)
  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))

  if (rating) {
    return (
      <>
        <h2 style={{ margin: '0 0 12px', fontSize: 17, fontWeight: 800, color: '#111827' }}>Your Rating</h2>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 10 }}>
          <StarRow value={rating.overall} readOnly /><span style={{ fontWeight: 800, color: '#111827' }}>{rating.overall}/5</span>
        </div>
        <div style={{ display: 'flex', gap: 20, flexWrap: 'wrap', fontSize: 13, color: '#374151' }}>
          {[['Communication', rating.communication], ['Candidate Quality', rating.candidate_quality], ['Turnaround Time', rating.turnaround_time]].map(([l, v]) => v != null && (
            <span key={l} style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>{l} <StarRow value={v} readOnly /></span>
          ))}
        </div>
        {rating.feedback && <p style={{ marginTop: 12, fontSize: 13, color: '#374151', background: '#f9fafb', padding: '10px 14px', borderRadius: 8 }}>“{rating.feedback}”</p>}
        <p style={{ marginTop: 10, fontSize: 12, color: '#9ca3af' }}>Thank you for rating our service.</p>
      </>
    )
  }

  const submit = async () => {
    if (!form.overall) { alert('Please give an overall rating'); return }
    setBusy(true)
    try {
      await onSubmit({
        overall: form.overall,
        communication: form.communication || null,
        candidate_quality: form.candidate_quality || null,
        turnaround_time: form.turnaround_time || null,
        feedback: form.feedback || null,
      })
    } catch (e) { alert(e.response?.data?.message || 'Could not submit rating') }
    finally { setBusy(false) }
  }

  const Row = ({ l, k }) => (
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '6px 0' }}>
      <span style={{ fontSize: 13.5, color: '#374151', fontWeight: 600 }}>{l}</span>
      <StarRow value={form[k]} onChange={v => set(k, v)} />
    </div>
  )

  return (
    <>
      <h2 style={{ margin: '0 0 4px', fontSize: 17, fontWeight: 800, color: '#111827' }}>Rate Our Service</h2>
      <p style={{ margin: '0 0 12px', fontSize: 12.5, color: '#6b7280' }}>Your rating helps us improve. It's optional.</p>
      <Row l="Overall" k="overall" />
      <Row l="Communication" k="communication" />
      <Row l="Candidate Quality" k="candidate_quality" />
      <Row l="Turnaround Time" k="turnaround_time" />
      <textarea rows={2} value={form.feedback} onChange={e => set('feedback', e.target.value)} placeholder="Any comments (optional)…" style={{ width: '100%', marginTop: 10, padding: '10px 12px', border: '1px solid #e5e7eb', borderRadius: 10, fontSize: 13, boxSizing: 'border-box', resize: 'vertical', outline: 'none' }} />
      <button onClick={submit} disabled={busy} style={{ marginTop: 12, padding: '10px 20px', borderRadius: 10, border: 'none', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontWeight: 800, fontSize: 13.5, cursor: 'pointer', opacity: busy ? 0.7 : 1 }}>{busy ? 'Submitting…' : 'Submit Rating'}</button>
    </>
  )
}
