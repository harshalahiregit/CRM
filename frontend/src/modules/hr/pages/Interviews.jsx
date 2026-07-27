import { useState, useEffect, useCallback } from 'react'
import { useNavigate, useLocation } from 'react-router-dom'
import { useTheme } from '@/context/ThemeContext'
import {
  Plus, Video, Mail, MessageCircle, X, Star, MapPin, Users, CalendarClock, Ban,
  History, ChevronDown, ChevronRight, Trash2, Edit3, Loader2, Link2, LayoutGrid, List, Eye, Rocket,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import AuditTimeline from '@/components/ui/AuditTimeline'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import StarRating from '@/modules/hr/components/StarRating'
import ScheduleInterviewDrawer from '@/modules/hr/components/ScheduleInterviewDrawer'
import InterviewFeedbackDrawer from '@/modules/hr/components/InterviewFeedbackDrawer'
import {
  INTERVIEW_ROUNDS, roundColor, canonicalRound, INTERVIEW_MODES,
  RECOMMENDATION_COLORS, INTERVIEW_STATUS_COLORS, INTERVIEW_RESULT_COLORS, canManageHrQueue,
} from '@/modules/hr/constants'

const currentUser = () => { try { return JSON.parse(localStorage.getItem('crm_user') || 'null') } catch { return null } }
const fmtTime = dt => dt ? new Date(dt).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' }) : '—'
const statusStyle = s => ({ color: INTERVIEW_STATUS_COLORS[s] || '#6b7280', bg: `${INTERVIEW_STATUS_COLORS[s] || '#6b7280'}20` })
const resultStyle = r => ({ color: INTERVIEW_RESULT_COLORS[r] || '#6b7280', bg: `${INTERVIEW_RESULT_COLORS[r] || '#6b7280'}20` })

// Delivery-status pill for the interview card (feature 4): shows `label` in an
// emerald "done" style when `ok`, otherwise `pending` in a muted style.
const NotifChip = ({ ok, label, pending }) => (
  <span
    className="text-[10px] font-bold px-2 py-0.5 rounded-lg inline-flex items-center gap-1"
    style={ok
      ? { background: 'rgba(16,185,129,0.15)', color: '#10b981' }
      : { background: 'var(--bg-input)', color: 'var(--text-muted)' }}
  >
    <span className="w-1.5 h-1.5 rounded-full" style={{ background: ok ? '#10b981' : 'var(--text-muted)' }} />
    {ok ? label : pending}
  </span>
)


export default function Interviews() {
  const { isDark } = useTheme()
  const navigate = useNavigate()
  const location = useLocation()
  // Card / List view (SPK-1) — mirrors the Job Postings toggle, persisted per user.
  const [view, setView] = useState(() => localStorage.getItem('hr_interviews_view') || 'card')
  const changeView = (v) => { setView(v); localStorage.setItem('hr_interviews_view', v) }
  // Row click opens the PRIMARY workspace (the candidate), never Interview Details.
  const openDetail = (iv) => navigate(`/app/hr/candidates/${iv.candidate_id}`)
  const [interviews, setInterviews] = useState([])
  const [stats, setStats]           = useState({ today: 0, upcoming: 0, completed: 0, pending_feedback: 0 })
  const [candidates, setCandidates] = useState([])
  const [loading, setLoading]       = useState(true)
  const [tab, setTab]               = useState('All')
  const [toast, setToast]           = useState(null)
  const [saving, setSaving]         = useState(false)
  const [sched, setSched]           = useState(null) // { mode, interview, candidateId, defaultRound }
  const [fbIv, setFbIv]             = useState(null) // interview being given feedback
  const [expanded, setExpanded]     = useState(null)  // interview id whose timeline is open
  const [timelines, setTimelines]   = useState({})    // id -> audit_logs
  const [emailModal, setEmailModal] = useState(null)  // { iv, type, subject, body, loading, sending, showPreview }
  const [selectionEmail, setSelectionEmail] = useState(null) // { candidateId, name, subject, body, loading, sending }

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
  // ── Recruiter work queue buckets (this page is a queue, nothing else) ──
  const QUEUE_TABS = ['All', 'Today', 'Upcoming', 'Pending Feedback', 'Completed', 'Cancelled']
  const inTab = (iv) => {
    if (tab === 'All') return true
    if (tab === 'Cancelled') return iv.status === 'Cancelled'
    if (tab === 'Completed') return iv.status === 'Completed'
    if (iv.status !== 'Scheduled') return false
    const d = iv.scheduled_at ? new Date(iv.scheduled_at) : null
    if (!d) return tab === 'Upcoming'
    const now = new Date()
    const isToday = d.toDateString() === now.toDateString()
    if (tab === 'Today') return isToday
    if (tab === 'Upcoming') return d > now && !isToday
    if (tab === 'Pending Feedback') return d <= now      // happened, outcome not recorded
    return false
  }

  const awaitingScheduling = candidates.filter(
    c => c.stage === 'Interview' && !candidatesWithActiveRounds.has(Number(c.id))
  )

  // ── Schedule / Feedback — shared drawers, opened in place (no navigation) ──
  const openSchedule    = () => setSched({ mode: 'schedule' })
  const openScheduleFor = (cand, roundName) => setSched({ mode: 'schedule', candidateId: cand.id, defaultRound: roundName || null })
  const openReschedule  = (iv) => setSched({ mode: 'reschedule', interview: iv })
  const openFeedback    = (iv) => setFbIv(iv)


  // Persist whatever the shared drawers saved back into the list.
  // Persist whatever the shared drawers saved back into the list — the recruiter
  // stays in the queue; we never navigate anywhere after a save.
  const onScheduleSaved = (iv, mode) => {
    if (mode === 'reschedule') patch(iv.id, iv)
    else setInterviews(prev => [iv, ...prev])
    refreshStats()
  }
  const onFeedbackSaved = (updated) => { if (fbIv) patch(fbIv.id, updated); refreshStats() }

  // ── Selection email (predefined template → edit → send) ───────────────
  const openSelectionEmail = async (iv) => {
    const cid = iv.candidate_id
    if (!cid) return
    setSelectionEmail({ candidateId: cid, name: iv.candidate?.name, subject: '', body: '', loading: true, sending: false })
    try {
      const p = await hrApi.candidates.commPreview(cid, 'email', 'selected')
      setSelectionEmail(m => m && ({ ...m, subject: p.subject || '', body: p.body || '', loading: false }))
    } catch { setSelectionEmail(m => m && ({ ...m, loading: false })); showToast('Failed to load selection template', 'error') }
  }
  const sendSelectionEmail = async () => {
    if (!selectionEmail) return
    setSelectionEmail(m => ({ ...m, sending: true }))
    try {
      await hrApi.candidates.communicate(selectionEmail.candidateId, { channel: 'email', event: 'selected', subject: selectionEmail.subject, body: selectionEmail.body })
      setSelectionEmail(null); showToast('Selection email sent!')
    } catch (e) { setSelectionEmail(m => ({ ...m, sending: false })); showToast(e.response?.data?.message || 'Failed to send', 'error') }
  }

  // ── Row actions ───────────────────────────────────────
  const generateMeet = async (id) => {
    try { const r = await hrApi.interviews.generateMeetLink(id); patch(id, { meet_link: r.meet_link }); showToast('Meet link generated!') }
    catch { showToast('Failed', 'error') }
  }
  const notify = async (id, type) => {
    try {
      await hrApi.interviews.sendNotification(id, type)
      if (type === 'whatsapp') patch(id, { whatsapp_sent: true })
      showToast(`${type.replace('_', ' ')} sent!`)
    }
    catch { showToast('Failed', 'error') }
  }

  // Email Preview popup (feature 6): load the existing template, edit, then send.
  const openEmail = async (iv, type = 'email_candidate') => {
    setEmailModal({ iv, type, subject: '', body: '', loading: true, sending: false, showPreview: false })
    try {
      const p = await hrApi.interviews.emailPreview(iv.id, type === 'email_interviewer' ? 'interviewer' : 'candidate')
      setEmailModal(m => m && ({ ...m, subject: p.subject, body: p.body, loading: false }))
    } catch { setEmailModal(m => m && ({ ...m, loading: false })); showToast('Failed to load email template', 'error') }
  }
  const sendEmailNow = async () => {
    if (!emailModal) return
    setEmailModal(m => ({ ...m, sending: true }))
    try {
      await hrApi.interviews.sendNotification(emailModal.iv.id, emailModal.type, { subject: emailModal.subject, body: emailModal.body })
      patch(emailModal.iv.id, { email_sent_candidate: true })
      setEmailModal(null); showToast('Email sent!')
    } catch (e) { setEmailModal(m => ({ ...m, sending: false })); showToast(e.response?.data?.message || 'Failed to send', 'error') }
  }
  const cancelInterview = async (iv) => {
    const reason = window.prompt('Cancel this interview? Reason (required):')
    if (reason === null) return
    if (!reason.trim()) return showToast('A reason is required to cancel an interview.', 'error')
    try { const u = await hrApi.interviews.cancel(iv.id, reason.trim()); patch(iv.id, u); refreshStats(); showToast('Interview cancelled') }
    catch (e) { showToast(e.response?.data?.message || 'Failed', 'error') }
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
        <div className="flex items-center gap-2">
          {/* Card / List view toggle (SPK-1) — same control as Job Postings */}
          <div className="flex rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
            {[['card', LayoutGrid, 'Card'], ['list', List, 'List']].map(([v, Icon, label]) => (
              <button key={v} onClick={() => changeView(v)} title={`${label} view`}
                className="flex items-center gap-1.5 px-3 py-2 text-xs font-bold transition-all"
                style={{ background: view === v ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)', color: view === v ? '#fff' : 'var(--text-muted)' }}>
                <Icon size={13} /> {label}
              </button>
            ))}
          </div>
          {canManage && (
            <button onClick={openSchedule} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow: '0 4px 14px rgba(124,58,237,0.4)' }}>
              <Plus size={15} /> Schedule Interview
            </button>
          )}
        </div>
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
        {QUEUE_TABS.map(t => (
          <button key={t} onClick={() => setTab(t)} className="px-3 py-1.5 rounded-xl text-xs font-bold transition-all" style={{ background: tab === t ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)', color: tab === t ? '#fff' : 'var(--text-muted)', border: `1px solid ${tab === t ? 'transparent' : 'var(--border)'}` }}>{t}</button>
        ))}
      </div>

      {loading ? <HrLoading label="Loading interviews…" /> : view === 'list' ? (
        <InterviewListView
          rows={interviews.filter(inTab)}
          onOpen={openDetail}
        />
      ) : (
        <div className="space-y-3">
          {interviews.filter(inTab).map(iv => {
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
                      {/* Candidate name → Interview Detail page (not the edit form) */}
                      <button onClick={() => openDetail(iv)} className="text-sm font-bold hover:underline" style={{ color: 'var(--text-h)', background: 'none', border: 'none', cursor: 'pointer', padding: 0 }}>{cand.name || '—'}</button>
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
                    {/* Notification delivery status (feature 4) — from existing flags */}
                    {!cancelled && (
                      <div className="flex items-center gap-1.5 mt-2 flex-wrap">
                        <NotifChip ok={!!iv.meet_link || !!iv.calendar_event_created} label="Meet Generated" pending="Meet Link" />
                        <NotifChip ok={!!iv.email_sent_candidate} label="Email Sent" pending="Email" />
                        <NotifChip ok={!!iv.whatsapp_sent} label="WhatsApp Sent" pending="WhatsApp" />
                        <NotifChip ok={!!iv.reminder_sent_at || iv.reminder_minutes > 0} label="Reminder Scheduled" pending="No Reminder" />
                      </div>
                    )}
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
                    {canManage && !cancelled && <button onClick={() => openEmail(iv, 'email_candidate')} title="Email candidate (preview & edit)" className="p-1.5 rounded-xl" style={{ background: 'rgba(124,58,237,0.1)', color: '#a78bfa' }}><Mail size={12} /></button>}
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
          {interviews.filter(inTab).length === 0 && <HrEmpty icon={CalendarClock} title={tab === 'All' ? 'No interviews yet' : `Nothing in ${tab}`} hint="Schedule an interview from a candidate in the Interview stage — it will appear here." />}
        </div>
      )}

      {/* Schedule / Reschedule — shared drawer (also mounted by Candidate Detail) */}
      <ScheduleInterviewDrawer
        open={!!sched}
        mode={sched?.mode || 'schedule'}
        interview={sched?.interview || null}
        candidateId={sched?.candidateId ?? null}
        defaultRound={sched?.defaultRound || null}
        candidates={candidates}
        interviews={interviews}
        onClose={() => setSched(null)}
        onSaved={onScheduleSaved}
        onOpenFeedback={(iv) => { setSched(null); setFbIv(iv) }}
        onResume={(iv) => setSched({ mode: 'reschedule', interview: iv })}
        onSkipped={() => { setSched(null); fetchData() }}
        showToast={showToast}
      />

      {/* Interview Feedback — shared drawer (also mounted by Candidate Detail) */}
      <InterviewFeedbackDrawer
        open={!!fbIv}
        interview={fbIv}
        onClose={() => setFbIv(null)}
        onSaved={onFeedbackSaved}
        onSchedule={(round) => { const cid = fbIv?.candidate_id; setFbIv(null); if (cid) openScheduleFor({ id: cid }, round) }}
        onOffer={() => { const iv = fbIv; setFbIv(null); if (iv) openSelectionEmail(iv) }}
        showToast={showToast}
      />

      {/* SPK-1: Selection email — opens automatically when a candidate is marked Selected */}
      {selectionEmail && (
        <div className="modal-backdrop" onClick={() => !selectionEmail.sending && setSelectionEmail(null)}>
          <div className="modal-box max-w-lg" onClick={e => e.stopPropagation()} style={{ maxHeight: '90vh', overflowY: 'auto' }}>
            <div className="flex items-center justify-between mb-2">
              <h2 className="font-black text-lg flex items-center gap-2" style={{ color: 'var(--text-h)' }}><Mail size={18} style={{ color: '#10b981' }} /> Candidate Selected</h2>
              <button onClick={() => setSelectionEmail(null)} style={{ color: 'var(--text-muted)' }}><X size={18} /></button>
            </div>
            <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>Send the selection email to <b style={{ color: 'var(--text-h)' }}>{selectionEmail.name}</b> — the template is pre-filled and editable.</p>
            {selectionEmail.loading ? <p className="text-sm py-6 text-center" style={{ color: 'var(--text-muted)' }}>Loading template…</p> : (
              <div className="space-y-3">
                <div><label className="label">Subject</label><input className="input-3d text-sm" value={selectionEmail.subject} onChange={e => setSelectionEmail(m => ({ ...m, subject: e.target.value }))} /></div>
                <div><label className="label">Message</label><textarea rows={9} className="input-3d text-sm resize-none" value={selectionEmail.body} onChange={e => setSelectionEmail(m => ({ ...m, body: e.target.value }))} /></div>
                <div className="flex gap-3">
                  <button onClick={() => setSelectionEmail(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Skip</button>
                  <button onClick={sendSelectionEmail} disabled={selectionEmail.sending || !selectionEmail.body.trim()} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background: 'linear-gradient(135deg,#10b981,#059669)', opacity: (selectionEmail.sending || !selectionEmail.body.trim()) ? 0.6 : 1 }}>{selectionEmail.sending && <Loader2 size={14} className="animate-spin" />}Send Selection Email</button>
                </div>
              </div>
            )}
          </div>
        </div>
      )}

      {/* Email Preview popup (feature 6) — edit the predefined template, preview, then send */}
      {emailModal && (
        <div className="modal-backdrop" onClick={() => !emailModal.sending && setEmailModal(null)}>
          <div className="modal-box max-w-2xl" onClick={e => e.stopPropagation()} style={{ maxHeight: '92vh', overflowY: 'auto' }}>
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-black text-lg flex items-center gap-2" style={{ color: 'var(--text-h)' }}><Mail size={18} style={{ color: '#a78bfa' }} /> Email Preview</h2>
              <button onClick={() => setEmailModal(null)} style={{ color: 'var(--text-muted)' }}><X size={18} /></button>
            </div>
            <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>To: <b style={{ color: 'var(--text-h)' }}>{emailModal.iv.candidate?.name}</b> · edit the template below, preview, then send.</p>
            {emailModal.loading ? <p className="text-sm py-6 text-center" style={{ color: 'var(--text-muted)' }}>Loading template…</p> : (
              <div className="space-y-3">
                <div><label className="label">Subject</label><input className="input-3d text-sm" value={emailModal.subject} onChange={e => setEmailModal(m => ({ ...m, subject: e.target.value }))} /></div>
                <div>
                  <div className="flex items-center justify-between mb-1">
                    <label className="label" style={{ margin: 0 }}>Body (HTML)</label>
                    <button type="button" onClick={() => setEmailModal(m => ({ ...m, showPreview: !m.showPreview }))} className="text-[11px] font-bold" style={{ color: '#a78bfa' }}>{emailModal.showPreview ? 'Edit' : 'Preview'}</button>
                  </div>
                  {emailModal.showPreview
                    ? <div className="rounded-xl overflow-auto" style={{ border: '1px solid var(--border)', background: '#fff', maxHeight: '46vh' }}><iframe title="Email preview" srcDoc={emailModal.body} style={{ width: '100%', height: '46vh', border: 'none' }} /></div>
                    : <textarea rows={12} className="input-3d text-xs font-mono resize-none" value={emailModal.body} onChange={e => setEmailModal(m => ({ ...m, body: e.target.value }))} />}
                </div>
                <div className="flex gap-3 pt-1">
                  <button onClick={() => setEmailModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
                  <button onClick={sendEmailNow} disabled={emailModal.sending} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity: emailModal.sending ? 0.7 : 1 }}>{emailModal.sending && <Loader2 size={14} className="animate-spin" />}Send Email</button>
                </div>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  )
}

// ── List View (SPK-1) — professional data table, reuses the same interview data ──
// as the card view (no new API). Candidate name and the row open the Interview
// Detail page; every column is derived from the existing round + candidate fields.
const fmtDay  = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const fmtClock = d => d ? new Date(d).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' }) : '—'
const wTh = { textAlign: 'left', padding: '10px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', borderBottom: '1px solid var(--border)', whiteSpace: 'nowrap' }
const wTd = { padding: '11px 12px', borderBottom: '1px solid var(--border)', verticalAlign: 'middle', fontSize: 12.5, whiteSpace: 'nowrap' }

function InterviewListView({ rows, onOpen }) {
  if (!rows.length) return <HrEmpty title="No interviews" subtitle="Scheduled interviews will appear here." />
  return (
    <div className="card-3d" style={{ padding: 0, overflowX: 'auto' }}>
      <table style={{ width: '100%', borderCollapse: 'collapse' }}>
        <thead>
          <tr>
            {['Interview ID', 'Candidate', 'Job Title', 'Round', 'Type', 'Interviewer', 'Date', 'Time', 'Status', 'Result', 'Action'].map(h => (
              <th key={h} style={h === 'Action' ? { ...wTh, textAlign: 'right' } : wTh}>{h}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map(iv => {
            const cand = iv.candidate || {}
            const rc = roundColor(iv.round_name)
            const ss = statusStyle(iv.status)
            const panel = Array.isArray(iv.interviewers) ? iv.interviewers.filter(p => p?.name).map(p => p.name) : []
            const interviewer = [iv.interviewer_name, ...panel].filter(Boolean).join(', ') || '—'
            const showResult = iv.status === 'Completed' && iv.result && iv.result !== 'Pending'
            return (
              <tr key={iv.id} style={{ cursor: 'pointer' }} onClick={() => onOpen(iv)}
                onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'}
                onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                <td style={{ ...wTd, fontFamily: 'ui-monospace,monospace', fontWeight: 700, color: '#a78bfa' }}>IV-{String(iv.id).padStart(4, '0')}</td>
                <td style={{ ...wTd, fontWeight: 700, color: 'var(--text-h)' }}>
                  <span className="hover:underline">{cand.name || '—'}</span>
                </td>
                <td style={{ ...wTd, color: 'var(--text-muted)' }}>{cand.job_posting?.title || '—'}</td>
                <td style={wTd}><span style={{ fontSize: 10.5, fontWeight: 700, padding: '2px 8px', borderRadius: 8, background: `${rc}20`, color: rc }}>{iv.round_name}</span></td>
                <td style={{ ...wTd, color: 'var(--text-muted)' }}>{iv.mode === 'offline' ? '📍 Offline' : '🎥 Online'}</td>
                <td style={{ ...wTd, color: 'var(--text-muted)', maxWidth: 180, overflow: 'hidden', textOverflow: 'ellipsis' }} title={interviewer}>{interviewer}</td>
                <td style={{ ...wTd, color: 'var(--text-muted)' }}>{fmtDay(iv.scheduled_at)}</td>
                <td style={{ ...wTd, color: 'var(--text-muted)' }}>{fmtClock(iv.scheduled_at)}</td>
                <td style={wTd}><span style={{ fontSize: 10.5, fontWeight: 700, padding: '2px 8px', borderRadius: 8, background: ss.bg, color: ss.color }}>{iv.status}</span></td>
                <td style={wTd}>{showResult ? <span style={{ fontSize: 10.5, fontWeight: 700, padding: '2px 8px', borderRadius: 8, background: resultStyle(iv.result).bg, color: resultStyle(iv.result).color }}>{iv.result}</span> : <span style={{ color: 'var(--text-muted)' }}>—</span>}</td>
                <td style={{ ...wTd, textAlign: 'right' }}>
                  <button onClick={e => { e.stopPropagation(); onOpen(iv) }} title="View details"
                    className="inline-flex items-center gap-1 text-[11px] font-bold px-2.5 py-1.5 rounded-lg" style={{ background: 'rgba(124,58,237,0.1)', color: '#a78bfa', border: '1px solid rgba(124,58,237,0.2)' }}>
                    <Eye size={12} /> View
                  </button>
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
