import { useState, useEffect, useCallback } from 'react'
import { useTheme } from '@/context/ThemeContext'
import {
  Plus, Video, Mail, MessageCircle, X, Star, MapPin, Users, CalendarClock, Ban,
  History, ChevronDown, Trash2, Edit3, Loader2, Link2,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import AuditTimeline from '@/components/ui/AuditTimeline'
import {
  INTERVIEW_ROUNDS, roundColor, INTERVIEW_MODES, INTERVIEW_RESULTS, RECOMMENDATIONS,
  RECOMMENDATION_COLORS, INTERVIEW_STATUS_COLORS, INTERVIEW_RESULT_COLORS, canManageHrQueue,
} from '@/modules/hr/constants'

const currentUser = () => { try { return JSON.parse(localStorage.getItem('crm_user') || 'null') } catch { return null } }
const fmtTime = dt => dt ? new Date(dt).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' }) : '—'
const statusStyle = s => ({ color: INTERVIEW_STATUS_COLORS[s] || '#6b7280', bg: `${INTERVIEW_STATUS_COLORS[s] || '#6b7280'}20` })
const resultStyle = r => ({ color: INTERVIEW_RESULT_COLORS[r] || '#6b7280', bg: `${INTERVIEW_RESULT_COLORS[r] || '#6b7280'}20` })

const EMPTY_FORM = { candidate_id: '', round_name: 'HR Screening', mode: 'online', interviewer_name: '', interviewers: [], scheduled_at: '', venue: '', meet_link: '' }
const EMPTY_FB   = { result: 'Passed', recommendation: 'Hire', rating: 0, technical_score: '', communication_score: '', problem_solving_score: '', notes: '' }

const StarRating = ({ value, onChange, readOnly = false }) => (
  <div className="flex gap-1">
    {[1, 2, 3, 4, 5].map(n => (
      <button key={n} type="button" disabled={readOnly} onClick={() => onChange?.(n)} style={{ cursor: readOnly ? 'default' : 'pointer' }}>
        <Star size={readOnly ? 13 : 20} style={{ color: n <= value ? '#fbbf24' : 'var(--border)', fill: n <= value ? '#fbbf24' : 'none' }} />
      </button>
    ))}
  </div>
)

export default function Interviews() {
  const { isDark } = useTheme()
  const [interviews, setInterviews] = useState([])
  const [stats, setStats]           = useState({ today: 0, upcoming: 0, completed: 0, pending_feedback: 0 })
  const [candidates, setCandidates] = useState([])
  const [loading, setLoading]       = useState(true)
  const [tab, setTab]               = useState('All')
  const [toast, setToast]           = useState(null)
  const [saving, setSaving]         = useState(false)
  const [modal, setModal]           = useState(null) // 'schedule' | 'reschedule' | 'feedback'
  const [current, setCurrent]       = useState(null) // interview being acted on
  const [form, setForm]             = useState(EMPTY_FORM)
  const [fb, setFb]                 = useState(EMPTY_FB)
  const [expanded, setExpanded]     = useState(null)  // interview id whose timeline is open
  const [timelines, setTimelines]   = useState({})    // id -> audit_logs

  const user = currentUser()
  const canManage = canManageHrQueue(user)
  const showToast = (msg, type = 'success') => { setToast({ msg, type }); setTimeout(() => setToast(null), 3000) }

  const fetchData = useCallback(async () => {
    setLoading(true)
    try {
      // Always load the full set; the status tabs filter client-side so the
      // "Awaiting Scheduling" view can see every candidate's real round state.
      const [ivs, st, cands] = await Promise.all([
        hrApi.interviews.list({}),
        hrApi.interviews.stats(),
        hrApi.candidates.list(),
      ])
      setInterviews(ivs); setStats(st); setCandidates(cands)
    } catch { showToast('Failed to load interviews', 'error') }
    finally { setLoading(false) }
  }, [])

  useEffect(() => { fetchData() }, [fetchData])

  const patch = (id, partial) => setInterviews(prev => prev.map(iv => iv.id === id ? { ...iv, ...partial } : iv))
  const refreshStats = () => hrApi.interviews.stats().then(setStats).catch(() => {})

  // Awaiting Scheduling = candidate.stage === 'Interview' AND zero ACTIVE
  // (non-cancelled) interview rounds. candidate_id arrives as a string from the
  // schedule response (echoed form value) but as an int from the candidates
  // list, so both sides are coerced with Number() — a mismatch here would leave
  // a just-scheduled candidate stuck in the banner.
  const candidatesWithActiveRounds = new Set(
    interviews.filter(iv => iv.status !== 'Cancelled').map(iv => Number(iv.candidate_id))
  )
  const awaitingScheduling = candidates.filter(
    c => c.stage === 'Interview' && !candidatesWithActiveRounds.has(Number(c.id))
  )

  // ── Schedule / Reschedule ─────────────────────────────
  const openSchedule = () => { setForm(EMPTY_FORM); setCurrent(null); setModal('schedule') }
  const openScheduleFor = (cand) => { setForm({ ...EMPTY_FORM, candidate_id: String(cand.id) }); setCurrent(null); setModal('schedule') }
  const openReschedule = (iv) => {
    setCurrent(iv)
    setForm({
      candidate_id: iv.candidate_id, round_name: iv.round_name || 'HR Screening', mode: iv.mode || 'online',
      interviewer_name: iv.interviewer_name || '', interviewers: iv.interviewers || [],
      scheduled_at: iv.scheduled_at ? iv.scheduled_at.slice(0, 16) : '', venue: iv.venue || '', meet_link: iv.meet_link || '',
    })
    setModal('reschedule')
  }
  const addPanelist    = () => setForm(f => ({ ...f, interviewers: [...f.interviewers, { name: '', email: '' }] }))
  const setPanelist    = (i, k, v) => setForm(f => ({ ...f, interviewers: f.interviewers.map((p, idx) => idx === i ? { ...p, [k]: v } : p) }))
  const removePanelist = (i) => setForm(f => ({ ...f, interviewers: f.interviewers.filter((_, idx) => idx !== i) }))

  const submitSchedule = async () => {
    if (!form.candidate_id || !form.scheduled_at) return showToast('Candidate and date/time are required', 'error')
    if (form.mode === 'offline' && !form.venue.trim()) return showToast('Venue is required for offline interviews', 'error')
    setSaving(true)
    try {
      const payload = { ...form, interviewers: form.interviewers.filter(p => p.name?.trim()) }
      if (modal === 'reschedule' && current) {
        const updated = await hrApi.interviews.update(current.id, payload)
        patch(current.id, updated); showToast('Interview rescheduled')
      } else {
        const iv = await hrApi.interviews.schedule(payload)
        setInterviews(prev => [iv, ...prev]); showToast('Interview scheduled!')
      }
      setModal(null); refreshStats()
    } catch (e) { showToast(e.response?.data?.message || 'Failed', 'error') }
    finally { setSaving(false) }
  }

  // ── Feedback ──────────────────────────────────────────
  const openFeedback = (iv) => {
    setCurrent(iv)
    setFb({
      result: iv.result && iv.result !== 'Pending' ? iv.result : 'Passed',
      recommendation: iv.recommendation || 'Hire', rating: iv.rating || 0,
      technical_score: iv.technical_score ?? '', communication_score: iv.communication_score ?? '',
      problem_solving_score: iv.problem_solving_score ?? '', notes: iv.notes || '',
    })
    setModal('feedback')
  }
  const submitFeedback = async () => {
    if (!current) return
    setSaving(true)
    try {
      const payload = {
        result: fb.result, recommendation: fb.recommendation, rating: fb.rating || undefined, notes: fb.notes, status: 'Completed',
        technical_score: fb.technical_score ? Number(fb.technical_score) : undefined,
        communication_score: fb.communication_score ? Number(fb.communication_score) : undefined,
        problem_solving_score: fb.problem_solving_score ? Number(fb.problem_solving_score) : undefined,
      }
      const updated = await hrApi.interviews.recordFeedback(current.id, payload)
      patch(current.id, updated); setModal(null); refreshStats()
      showToast('Feedback submitted!')
    } catch (e) { showToast(e.response?.data?.message || 'Failed', 'error') }
    finally { setSaving(false) }
  }

  // ── Row actions ───────────────────────────────────────
  const generateMeet = async (id) => {
    try { const r = await hrApi.interviews.generateMeetLink(id); patch(id, { meet_link: r.meet_link }); showToast('Meet link generated!') }
    catch { showToast('Failed', 'error') }
  }
  const notify = async (id, type) => {
    try { await hrApi.interviews.sendNotification(id, type); showToast(`${type.replace('_', ' ')} sent!`) }
    catch { showToast('Failed', 'error') }
  }
  const cancelInterview = async (iv) => {
    const reason = window.prompt('Cancel this interview? Optional reason:')
    if (reason === null) return
    try { const u = await hrApi.interviews.cancel(iv.id, reason || null); patch(iv.id, u); refreshStats(); showToast('Interview cancelled') }
    catch { showToast('Failed', 'error') }
  }
  const removeInterview = async (iv) => {
    if (!window.confirm('Delete this interview permanently?')) return
    try { await hrApi.interviews.delete(iv.id); setInterviews(prev => prev.filter(x => x.id !== iv.id)); refreshStats(); showToast('Interview deleted') }
    catch { showToast('Failed', 'error') }
  }
  const toggleTimeline = async (iv) => {
    if (expanded === iv.id) return setExpanded(null)
    setExpanded(iv.id)
    if (!timelines[iv.id]) {
      try { const full = await hrApi.interviews.get(iv.id); setTimelines(t => ({ ...t, [iv.id]: full.audit_logs || [] })) }
      catch { setTimelines(t => ({ ...t, [iv.id]: [] })) }
    }
  }

  const overallPreview = (() => {
    const vals = [fb.technical_score, fb.communication_score, fb.problem_solving_score].filter(Boolean).map(Number)
    return vals.length ? Math.round((vals.reduce((a, b) => a + b, 0) / vals.length) * 10) : null
  })()

  return (
    <div className="space-y-5 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl" style={{ background: toast.type === 'success' ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <p className="label-caps mb-1">HR Module</p>
          <h1 className="font-black" style={{ fontSize: 'clamp(1.3rem,2vw,1.7rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
            Interview <span className="text-gradient">Pipeline</span>
          </h1>
        </div>
        {canManage && (
          <button onClick={openSchedule} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow: '0 4px 14px rgba(124,58,237,0.4)' }}>
            <Plus size={15} /> Schedule Interview
          </button>
        )}
      </div>

      {/* Dashboard widgets (server-computed) */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {[
          { l: "Today's", v: stats.today, c: '#f59e0b' },
          { l: 'Upcoming', v: stats.upcoming, c: '#7C3AED' },
          { l: 'Completed', v: stats.completed, c: '#10b981' },
          { l: 'Pending Feedback', v: stats.pending_feedback, c: '#f87171' },
        ].map(k => (
          <div key={k.l} className="kpi-3d"><p className="text-2xl font-black" style={{ color: k.c }}>{k.v}</p><p className="text-sm font-medium mt-1" style={{ color: 'var(--text-muted)' }}>{k.l}</p></div>
        ))}
      </div>

      {/* Tabs */}
      {/* Awaiting Scheduling — Interview-stage candidates with no upcoming interview */}
      {!loading && awaitingScheduling.length > 0 && (
        <div className="rounded-2xl p-4" style={{ background: 'rgba(245,158,11,0.08)', border: '1px solid rgba(245,158,11,0.25)' }}>
          <p className="text-xs font-bold mb-3 flex items-center gap-2" style={{ color: '#f59e0b' }}>
            <CalendarClock size={13} /> Awaiting Scheduling · {awaitingScheduling.length}
            <span className="font-medium" style={{ color: 'var(--text-muted)' }}>— in the Interview stage but no interview scheduled yet</span>
          </p>
          <div className="flex flex-wrap gap-2">
            {awaitingScheduling.map(c => (
              <div key={c.id} className="flex items-center gap-2 px-3 py-1.5 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <span className="text-xs font-semibold" style={{ color: 'var(--text-h)' }}>{c.name}</span>
                {c.job_posting?.title && <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>· {c.job_posting.title}</span>}
                {canManage && (
                  <button onClick={() => openScheduleFor(c)} className="text-[10px] font-bold px-2 py-1 rounded-lg text-white flex items-center gap-1" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
                    <Plus size={9} /> Schedule
                  </button>
                )}
              </div>
            ))}
          </div>
        </div>
      )}

      <div className="flex gap-2 flex-wrap">
        {['All', 'Scheduled', 'Completed', 'Cancelled'].map(t => (
          <button key={t} onClick={() => setTab(t)} className="px-3 py-1.5 rounded-xl text-xs font-bold transition-all" style={{ background: tab === t ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)', color: tab === t ? '#fff' : 'var(--text-muted)', border: `1px solid ${tab === t ? 'transparent' : 'var(--border)'}` }}>{t}</button>
        ))}
      </div>

      {loading ? <div className="text-center py-12" style={{ color: 'var(--text-muted)' }}>Loading…</div> : (
        <div className="space-y-3">
          {interviews.filter(iv => tab === 'All' || iv.status === tab).map(iv => {
            const rc = roundColor(iv.round_name)
            const ss = statusStyle(iv.status)
            const cand = iv.candidate || {}
            const panel = Array.isArray(iv.interviewers) ? iv.interviewers.filter(p => p?.name) : []
            const done = iv.status === 'Completed'
            const cancelled = iv.status === 'Cancelled'
            return (
              <div key={iv.id} className="card-3d" style={{ padding: '18px', opacity: cancelled ? 0.65 : 1 }}>
                <div className="flex items-start gap-3 flex-wrap">
                  <div className="w-10 h-10 rounded-xl flex items-center justify-center text-sm font-black text-white flex-shrink-0" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
                    {(cand.name || '?').split(' ').map(n => n[0]).join('').toUpperCase()}
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                      <p className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{cand.name || '—'}</p>
                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background: `${rc}20`, color: rc }}>{iv.round_name}</span>
                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background: ss.bg, color: ss.color }}>{iv.status}</span>
                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg flex items-center gap-1" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
                        {iv.mode === 'offline' ? <><MapPin size={9} /> Offline</> : <><Video size={9} /> Online</>}
                      </span>
                      {done && iv.result && iv.result !== 'Pending' && <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background: resultStyle(iv.result).bg, color: resultStyle(iv.result).color }}>{iv.result}</span>}
                      {iv.recommendation && <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background: `${RECOMMENDATION_COLORS[iv.recommendation]}18`, color: RECOMMENDATION_COLORS[iv.recommendation] }}>{iv.recommendation}</span>}
                    </div>
                    <p className="text-xs mt-1 flex items-center gap-1.5 flex-wrap" style={{ color: 'var(--text-muted)' }}>
                      <CalendarClock size={11} /> {fmtTime(iv.scheduled_at)}
                      {iv.mode === 'offline' && iv.venue && <span className="flex items-center gap-1"><MapPin size={11} /> {iv.venue}</span>}
                    </p>
                    <p className="text-xs mt-1 flex items-center gap-1.5 flex-wrap" style={{ color: 'var(--text-muted)' }}>
                      <Users size={11} /> {[iv.interviewer_name, ...panel.map(p => p.name)].filter(Boolean).join(', ') || 'No interviewer assigned'}
                    </p>
                    {done && (iv.overall_score || iv.rating) && (
                      <div className="flex items-center gap-3 mt-1.5">
                        {iv.overall_score ? <span className="text-[11px] font-black" style={{ color: '#a78bfa' }}>Score {iv.overall_score}%</span> : null}
                        {iv.rating ? <StarRating value={iv.rating} readOnly /> : null}
                      </div>
                    )}
                  </div>

                  <div className="flex gap-1.5 flex-shrink-0 flex-wrap justify-end">
                    {iv.mode === 'online' && (iv.meet_link ? (
                      <a href={iv.meet_link} target="_blank" rel="noreferrer" className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl text-[10px] font-bold text-white" style={{ background: 'linear-gradient(135deg,#3b82f6,#1d4ed8)' }}><Video size={10} /> Join</a>
                    ) : canManage && !cancelled && (
                      <button onClick={() => generateMeet(iv.id)} className="flex items-center gap-1 px-2.5 py-1.5 rounded-xl text-[10px] font-bold" style={{ background: 'rgba(59,130,246,0.1)', color: '#3b82f6', border: '1px solid rgba(59,130,246,0.2)' }}><Link2 size={10} /> Gen Link</button>
                    ))}
                    {canManage && !cancelled && <button onClick={() => notify(iv.id, 'email_candidate')} title="Email candidate" className="p-1.5 rounded-xl" style={{ background: 'rgba(124,58,237,0.1)', color: '#a78bfa' }}><Mail size={12} /></button>}
                    {canManage && !cancelled && <button onClick={() => notify(iv.id, 'whatsapp')} title="WhatsApp" className="p-1.5 rounded-xl" style={{ background: 'rgba(37,211,102,0.1)', color: '#25D366' }}><MessageCircle size={12} /></button>}
                    {canManage && !done && !cancelled && <button onClick={() => openFeedback(iv)} className="flex items-center gap-1 px-2.5 py-1.5 rounded-xl text-[10px] font-bold" style={{ background: 'rgba(245,158,11,0.1)', color: '#fbbf24', border: '1px solid rgba(245,158,11,0.2)' }}><Star size={10} /> Feedback</button>}
                    {canManage && !cancelled && <button onClick={() => openReschedule(iv)} title="Reschedule" className="p-1.5 rounded-xl" style={{ background: 'rgba(139,92,246,0.1)', color: '#8b5cf6' }}><Edit3 size={12} /></button>}
                    {canManage && !done && !cancelled && <button onClick={() => cancelInterview(iv)} title="Cancel" className="p-1.5 rounded-xl" style={{ background: 'rgba(239,68,68,0.08)', color: '#f87171' }}><Ban size={12} /></button>}
                    <button onClick={() => toggleTimeline(iv)} title="Timeline" className="p-1.5 rounded-xl" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}><History size={12} /></button>
                    {canManage && <button onClick={() => removeInterview(iv)} title="Delete" className="p-1.5 rounded-xl" style={{ background: 'rgba(239,68,68,0.06)', color: '#f87171' }}><Trash2 size={12} /></button>}
                  </div>
                </div>

                {/* Notes */}
                {iv.notes && done && <p className="text-xs mt-3 italic px-3 py-2 rounded-xl" style={{ color: 'var(--text-muted)', background: 'var(--bg-input)' }}>"{iv.notes}"</p>}

                {/* Per-interview audit timeline */}
                {expanded === iv.id && (
                  <div className="mt-3 pt-3" style={{ borderTop: '1px solid var(--border)' }}>
                    <p className="label-caps mb-3 flex items-center gap-1.5"><History size={11} /> Interview Timeline</p>
                    <AuditTimeline entries={timelines[iv.id] || []} />
                  </div>
                )}
              </div>
            )
          })}
          {interviews.filter(iv => tab === 'All' || iv.status === tab).length === 0 && <p className="text-center py-10" style={{ color: 'var(--text-muted)' }}>No interviews found.</p>}
        </div>
      )}

      {/* Schedule / Reschedule Modal */}
      {(modal === 'schedule' || modal === 'reschedule') && (
        <div className="modal-backdrop" onClick={() => setModal(null)}>
          <div className="modal-box max-w-lg" onClick={e => e.stopPropagation()} style={{ maxHeight: '92vh', overflowY: 'auto' }}>
            <div className="flex items-center justify-between mb-5">
              <h2 className="font-black text-lg" style={{ color: 'var(--text-h)' }}>{modal === 'reschedule' ? 'Reschedule Interview' : 'Schedule Interview'}</h2>
              <button onClick={() => setModal(null)} style={{ color: 'var(--text-muted)' }}><X size={18} /></button>
            </div>
            <div className="space-y-3">
              {modal === 'schedule' && (
                <div>
                  <label className="label">Candidate *</label>
                  <select className="input-3d text-sm" value={form.candidate_id} onChange={e => setForm({ ...form, candidate_id: e.target.value })}>
                    <option value="">Select candidate...</option>
                    {candidates.filter(c => !['Hired', 'Rejected'].includes(c.stage)).map(c => <option key={c.id} value={c.id}>{c.name} — {c.stage}</option>)}
                  </select>
                </div>
              )}
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="label">Round</label>
                  <select className="input-3d text-sm" value={form.round_name} onChange={e => setForm({ ...form, round_name: e.target.value })}>
                    {INTERVIEW_ROUNDS.map(r => <option key={r}>{r}</option>)}
                    {!INTERVIEW_ROUNDS.includes(form.round_name) && <option>{form.round_name}</option>}
                  </select>
                </div>
                <div>
                  <label className="label">Mode</label>
                  <div className="flex rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
                    {INTERVIEW_MODES.map(m => (
                      <button key={m} type="button" onClick={() => setForm({ ...form, mode: m })} className="flex-1 py-2 text-xs font-bold capitalize" style={{ background: form.mode === m ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)', color: form.mode === m ? '#fff' : 'var(--text-muted)' }}>{m}</button>
                    ))}
                  </div>
                </div>
              </div>
              <div><label className="label">Lead Interviewer</label><input className="input-3d text-sm" placeholder="e.g. Vikram Singh" value={form.interviewer_name} onChange={e => setForm({ ...form, interviewer_name: e.target.value })} /></div>

              {/* Panel */}
              <div>
                <div className="flex items-center justify-between mb-1">
                  <label className="label" style={{ margin: 0 }}>Additional Interviewers (Panel)</label>
                  <button type="button" onClick={addPanelist} className="text-[11px] font-bold flex items-center gap-1" style={{ color: '#a78bfa' }}><Plus size={11} /> Add</button>
                </div>
                {form.interviewers.length === 0 && <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>Add panel members for multi-interviewer rounds.</p>}
                <div className="space-y-2">
                  {form.interviewers.map((p, i) => (
                    <div key={i} className="flex gap-2">
                      <input className="input-3d text-sm flex-1" placeholder="Name" value={p.name} onChange={e => setPanelist(i, 'name', e.target.value)} />
                      <input className="input-3d text-sm flex-1" placeholder="Email (optional)" value={p.email || ''} onChange={e => setPanelist(i, 'email', e.target.value)} />
                      <button type="button" onClick={() => removePanelist(i)} className="p-2 rounded-xl flex-shrink-0" style={{ color: '#f87171' }}><X size={14} /></button>
                    </div>
                  ))}
                </div>
              </div>

              {form.mode === 'offline'
                ? <div><label className="label">Venue *</label><input className="input-3d text-sm" placeholder="e.g. Board Room A, 3rd Floor" value={form.venue} onChange={e => setForm({ ...form, venue: e.target.value })} /></div>
                : <div><label className="label">Meeting Link (optional)</label><input className="input-3d text-sm" placeholder="Auto-generated Google Meet if left blank" value={form.meet_link} onChange={e => setForm({ ...form, meet_link: e.target.value })} /></div>}

              <div><label className="label">Date & Time *</label><input type="datetime-local" className="input-3d text-sm" value={form.scheduled_at} onChange={e => setForm({ ...form, scheduled_at: e.target.value })} /></div>

              <div className="flex gap-3 pt-1">
                <button onClick={() => setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
                <button onClick={submitSchedule} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity: saving ? 0.7 : 1 }}>
                  {saving && <Loader2 size={14} className="animate-spin" />}{modal === 'reschedule' ? 'Reschedule' : 'Schedule'}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Feedback Modal */}
      {modal === 'feedback' && current && (
        <div className="modal-backdrop" onClick={() => setModal(null)}>
          <div className="modal-box max-w-md" onClick={e => e.stopPropagation()} style={{ maxHeight: '92vh', overflowY: 'auto' }}>
            <div className="flex items-center justify-between mb-1">
              <h2 className="font-black text-lg" style={{ color: 'var(--text-h)' }}>Interview Feedback</h2>
              <button onClick={() => setModal(null)} style={{ color: 'var(--text-muted)' }}><X size={18} /></button>
            </div>
            <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>{current.candidate?.name} · {current.round_name}</p>
            <div className="space-y-3">
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="label">Decision *</label>
                  <select className="input-3d text-sm" value={fb.result} onChange={e => setFb(f => ({ ...f, result: e.target.value }))}>
                    {INTERVIEW_RESULTS.filter(r => r !== 'Pending').map(r => <option key={r}>{r}</option>)}
                  </select>
                </div>
                <div>
                  <label className="label">Recommendation</label>
                  <select className="input-3d text-sm" value={fb.recommendation} onChange={e => setFb(f => ({ ...f, recommendation: e.target.value }))}>
                    {RECOMMENDATIONS.map(r => <option key={r}>{r}</option>)}
                  </select>
                </div>
              </div>
              <div>
                <label className="label">Overall Rating</label>
                <StarRating value={fb.rating} onChange={n => setFb(f => ({ ...f, rating: n }))} />
              </div>
              <p className="text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>Scores (out of 10)</p>
              <div className="grid grid-cols-3 gap-2">
                {[{ k: 'technical_score', l: 'Technical' }, { k: 'communication_score', l: 'Communication' }, { k: 'problem_solving_score', l: 'Problem Solving' }].map(s => (
                  <div key={s.k}><label className="label">{s.l}</label><input type="number" min="0" max="10" className="input-3d text-sm" placeholder="0-10" value={fb[s.k]} onChange={e => setFb(f => ({ ...f, [s.k]: e.target.value }))} /></div>
                ))}
              </div>
              {overallPreview !== null && (
                <div className="px-3 py-2 rounded-xl text-center" style={{ background: 'rgba(124,58,237,0.1)' }}>
                  <span className="text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>Overall Score: </span>
                  <span className="font-black text-sm" style={{ color: '#a78bfa' }}>{overallPreview}%</span>
                </div>
              )}
              <div><label className="label">Comments</label><textarea rows={2} className="input-3d text-sm resize-none" value={fb.notes} onChange={e => setFb(f => ({ ...f, notes: e.target.value }))} /></div>
              {fb.result === 'Passed' && (
                <p className="text-[10px]" style={{ color: '#34d399' }}>✓ Marking <b>Passed</b> selects the candidate — congratulations are sent and <b>Onboarding starts automatically</b>. Use <b>Next Round</b> if more interviews are needed.</p>
              )}
              {fb.result === 'Next Round' && (
                <p className="text-[10px]" style={{ color: '#60a5fa' }}>Candidate stays in the Interview stage — schedule the next round.</p>
              )}
              <div className="flex gap-3 pt-1">
                <button onClick={() => setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
                <button onClick={submitFeedback} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity: saving ? 0.7 : 1 }}>{saving && <Loader2 size={14} className="animate-spin" />}Submit Feedback</button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
