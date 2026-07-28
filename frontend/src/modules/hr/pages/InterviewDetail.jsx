import { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, Video, MapPin, CalendarClock, Users, Star,
  Link2, FileText, Eye, Download, History, ExternalLink,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import AuditTimeline from '@/components/ui/AuditTimeline'
import { HrLoading } from '@/components/ui/HrState'
import {
  roundColor, RECOMMENDATION_COLORS, INTERVIEW_STATUS_COLORS, INTERVIEW_RESULT_COLORS,
  canManageHrQueue, candidateScore,
} from '@/modules/hr/constants'

const currentUser = () => { try { return JSON.parse(localStorage.getItem('crm_user') || 'null') } catch { return null } }
const initials = n => (n || '?').split(' ').map(x => x[0]).join('').slice(0, 2).toUpperCase()
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const fmtTime = d => d ? new Date(d).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' }) : '—'

// The 6 headline ratings the feedback form records (0–10 each).
const RATINGS = [
  ['technical_score', 'Technical'], ['communication_score', 'Communication'],
  ['problem_solving_score', 'Problem Solving'], ['culture_fit_score', 'Culture Fit'],
  ['leadership_score', 'Leadership'], ['overall_score', 'Overall'],
]

const Chip = ({ color, bg, children, icon: Icon }) => (
  <span className="inline-flex items-center gap-1 text-[11px] font-bold px-2.5 py-1 rounded-lg" style={{ background: bg, color }}>
    {Icon && <Icon size={11} />}{children}
  </span>
)

const Field = ({ label, children }) => (
  <div>
    <p className="text-[9px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>{label}</p>
    <div className="text-xs font-semibold mt-0.5" style={{ color: 'var(--text-h)' }}>{children ?? '—'}</div>
  </div>
)

const Card = ({ title, icon: Icon, children, right }) => (
  <div className="card-3d" style={{ padding: 18 }}>
    <div className="flex items-center justify-between mb-4 flex-wrap gap-2">
      <h3 className="font-bold text-sm flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
        {Icon && <Icon size={14} style={{ color: '#a78bfa' }} />}{title}
      </h3>
      {right}
    </div>
    {children}
  </div>
)

export default function InterviewDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const user = currentUser()
  const canManage = canManageHrQueue(user)

  const [iv, setIv] = useState(null)
  const [loading, setLoading] = useState(true)
  const [toast, setToast] = useState(null)

  const showToast = (msg, type = 'success') => { setToast({ msg, type }); setTimeout(() => setToast(null), 3000) }

  const load = useCallback(async () => {
    setLoading(true)
    try { setIv(await hrApi.interviews.get(id)) }
    catch { showToast('Failed to load interview', 'error') }
    finally { setLoading(false) }
  }, [id])
  useEffect(() => { load() }, [load])

  if (loading) return <HrLoading label="Loading interview…" />
  if (!iv) return (
    <div style={{ padding: 24, color: 'var(--text-muted)' }}>
      Interview not found. <button onClick={() => navigate('/app/hr/interviews')} style={{ color: '#a78bfa', background: 'none', border: 'none', cursor: 'pointer' }}>Back to Interviews</button>
    </div>
  )

  const cand = iv.candidate || {}
  const rc = roundColor(iv.round_name)
  const done = iv.status === 'Completed'
  const cancelled = iv.status === 'Cancelled'
  const online = iv.mode !== 'offline'
  const panel = [
    ...(iv.interviewer_name ? [{ name: iv.interviewer_name, role: 'Lead Interviewer', _lead: true }] : []),
    ...(Array.isArray(iv.interviewers) ? iv.interviewers.filter(p => p?.name) : []),
  ]
  const hasFeedback = done || RATINGS.some(([k]) => iv[k] != null) || iv.strengths || iv.concerns || iv.notes
  const docs = cand.documents || []
  const resumeAsDoc = cand.resume_path && !docs.some(d => d.type === 'resume')
    ? [{ id: 'resume', original_name: String(cand.resume_path).split('/').pop(), type: 'resume', _resume: true }]
    : []
  const allDocs = [...resumeAsDoc, ...docs]

  const docFile = async (d, download) => {
    try {
      const blob = d._resume
        ? await hrApi.candidates.resumeBlob?.(cand.id) ?? await hrApi.candidates.documents.blob(cand.id, d.id)
        : await hrApi.candidates.documents.blob(cand.id, d.id)
      const url = URL.createObjectURL(blob)
      if (download) { const a = document.createElement('a'); a.href = url; a.download = d.original_name; a.click() }
      else window.open(url, '_blank', 'noopener')
      setTimeout(() => URL.revokeObjectURL(url), 60000)
    } catch { showToast('Could not open file', 'error') }
  }

  return (
    <div className="space-y-5 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl" style={{ background: toast.type === 'success' ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      <button onClick={() => navigate('/app/hr/interviews')} className="flex items-center gap-1.5 text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={13} /> Back to Interviews
      </button>

      {/* Header line */}
      <div className="flex items-center gap-3 flex-wrap">
        <h1 className="font-black" style={{ fontSize: 'clamp(1.2rem,2vw,1.6rem)', color: 'var(--text-h)' }}>Interview <span className="text-gradient">Details</span></h1>
        <span className="text-xs font-mono font-bold px-2 py-1 rounded-lg" style={{ background: 'rgba(124,58,237,0.1)', color: '#a78bfa' }}>IV-{String(iv.id).padStart(4, '0')}</span>
        <Chip color={rc} bg={`${rc}20`}>{iv.round_name}</Chip>
        <Chip color={INTERVIEW_STATUS_COLORS[iv.status] || '#6b7280'} bg={`${INTERVIEW_STATUS_COLORS[iv.status] || '#6b7280'}20`}>{iv.status}</Chip>
        {done && iv.result && iv.result !== 'Pending' && <Chip color={INTERVIEW_RESULT_COLORS[iv.result] || '#6b7280'} bg={`${INTERVIEW_RESULT_COLORS[iv.result] || '#6b7280'}20`}>{iv.result}</Chip>}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <div className="lg:col-span-2 space-y-5">
          {/* ── Candidate Summary ── */}
          <Card title="Candidate Summary" icon={Users}
            right={<button onClick={() => navigate(`/app/hr/candidates/${cand.id}`)} className="text-[11px] font-bold flex items-center gap-1" style={{ color: '#a78bfa' }}><ExternalLink size={11} /> Full Profile</button>}>
            <div className="flex items-start gap-4 flex-wrap">
              <div className="w-14 h-14 rounded-2xl flex items-center justify-center text-lg font-black text-white flex-shrink-0" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>{initials(cand.name)}</div>
              <div className="flex-1 min-w-0">
                <button onClick={() => navigate(`/app/hr/candidates/${cand.id}`)} className="text-base font-black hover:underline" style={{ color: 'var(--text-h)', background: 'none', border: 'none', cursor: 'pointer', padding: 0 }}>{cand.name || '—'}</button>
                <p className="text-[11px] font-mono mt-0.5" style={{ color: 'var(--text-muted)' }}>CAND-{String(cand.id).padStart(4, '0')}</p>
                <div className="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 mt-3">
                  <Field label="Job Applied">{cand.job_posting?.title || 'General'}</Field>
                  {/* Score AND verdict come from the engine; this file derives neither. */}
                  <Field label="AI Score">{(() => { const ai = candidateScore(cand); return ai.isScored
                    ? <span style={{ color: ai.style.color, fontWeight: 800 }}>{ai.display}{ai.recommendation ? ` · ${ai.recommendation}` : ''}</span>
                    : <span style={{ color: 'var(--text-muted)' }}>Not scored</span> })()}</Field>
                  <Field label="Current Stage">{cand.stage || '—'}</Field>
                  <Field label="Recruiter">{cand.assigned_recruiter?.name || 'Unassigned'}</Field>
                </div>
              </div>
            </div>
          </Card>

          {/* ── Interview Information ── */}
          <Card title="Interview Information" icon={CalendarClock}>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-4">
              <Field label="Round">{iv.round_name}</Field>
              <Field label="Interview Type">{iv.round_name?.replace(' Round', '') || '—'}</Field>
              <Field label="Mode">{online ? <span className="inline-flex items-center gap-1"><Video size={11} /> Online</span> : <span className="inline-flex items-center gap-1"><MapPin size={11} /> Offline</span>}</Field>
              <Field label="Status">{iv.status}</Field>
              <Field label="Date">{fmtDate(iv.scheduled_at)}</Field>
              <Field label="Time">{fmtTime(iv.scheduled_at)}</Field>
              <Field label="Result">{iv.result && iv.result !== 'Pending' ? iv.result : (done ? 'Pending' : '—')}</Field>
              <Field label="Reminder">{iv.reminder_sent_at ? `Sent ${fmtDate(iv.reminder_sent_at)}` : iv.reminder_minutes > 0 ? 'Scheduled' : 'None'}</Field>
              {online && <Field label="Meeting Link">{iv.meet_link ? <a href={iv.meet_link} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1" style={{ color: '#3b82f6' }}><Link2 size={11} /> Join link</a> : 'Not generated'}</Field>}
              {!online && <Field label="Location">{iv.venue || '—'}</Field>}
            </div>
          </Card>

          {/* ── Feedback ── */}
          {hasFeedback && (
            <Card title="Feedback" icon={Star} right={iv.recommendation && <Chip color={RECOMMENDATION_COLORS[iv.recommendation] || '#a78bfa'} bg={`${RECOMMENDATION_COLORS[iv.recommendation] || '#a78bfa'}18`}>{iv.recommendation}</Chip>}>
              <div className="grid grid-cols-2 md:grid-cols-3 gap-3 mb-4">
                {RATINGS.filter(([k]) => iv[k] != null).map(([k, l]) => (
                  <div key={k} className="px-3 py-2 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                    <p className="text-[9px] font-bold uppercase" style={{ color: 'var(--text-muted)' }}>{l}</p>
                    <p className="text-sm font-black mt-0.5" style={{ color: k === 'overall_score' ? '#a78bfa' : 'var(--text-h)' }}>{iv[k]}{k === 'overall_score' ? '%' : ' / 10'}</p>
                  </div>
                ))}
              </div>
              {iv.strengths && <div className="mb-2"><p className="text-[10px] font-bold uppercase mb-1" style={{ color: '#10b981' }}>Strengths</p><p className="text-xs" style={{ color: 'var(--text-muted)' }}>{iv.strengths}</p></div>}
              {iv.concerns && <div className="mb-2"><p className="text-[10px] font-bold uppercase mb-1" style={{ color: '#f87171' }}>Concerns</p><p className="text-xs" style={{ color: 'var(--text-muted)' }}>{iv.concerns}</p></div>}
              {iv.notes && <div><p className="text-[10px] font-bold uppercase mb-1" style={{ color: 'var(--text-muted)' }}>Comments</p><p className="text-xs" style={{ color: 'var(--text-muted)' }}>{iv.notes}</p></div>}
            </Card>
          )}

          {/* ── Timeline (reuse audit logs) ── */}
          <Card title="Interview Timeline" icon={History}>
            <AuditTimeline entries={iv.audit_logs || []} />
          </Card>
        </div>

        {/* ── Sidebar ── */}
        <div className="space-y-5">
          {/* Read-only record — every action lives in the candidate workspace */}
          <Card title="Record">
            <div className="flex flex-wrap gap-2">
              {online && iv.meet_link && <a href={iv.meet_link} target="_blank" rel="noreferrer" className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold text-white" style={{ background: 'linear-gradient(135deg,#3b82f6,#1d4ed8)' }}><Video size={13} /> Join Meeting</a>}
              {cand.id && (
                <button onClick={() => navigate(`/app/hr/candidates/${cand.id}`)} className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
                  <ExternalLink size={13} /> Open Candidate Workspace
                </button>
              )}
            </div>
            <p className="text-[10.5px] mt-3 leading-relaxed" style={{ color: 'var(--text-muted)' }}>
              <b style={{ color: 'var(--text-h)' }}>This record is managed from the Candidate Workspace.</b><br />
              This page exists for audit, history, deep links and notifications only — scheduling,
              rescheduling, feedback and cancellation all happen in the candidate workspace.
            </p>
            {cancelled && <p className="text-[11px] mt-2" style={{ color: '#f87171' }}>This interview was cancelled.</p>}
          </Card>

          {/* Panel Members */}
          <Card title="Panel Members" icon={Users}>
            {panel.length === 0 ? <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No panel members recorded.</p> : (
              <div className="space-y-2">
                {panel.map((p, i) => (
                  <div key={i} className="flex items-center gap-2 px-3 py-2 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                    <div className="w-8 h-8 rounded-lg flex items-center justify-center text-[10px] font-black text-white flex-shrink-0" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>{initials(p.name)}</div>
                    <div className="flex-1 min-w-0">
                      <p className="text-xs font-bold truncate" style={{ color: 'var(--text-h)' }}>{p.name}</p>
                      <p className="text-[10px] truncate" style={{ color: 'var(--text-muted)' }}>
                        {[p.role, p.department].filter(Boolean).join(' · ') || '—'}{p.email ? ` · ${p.email}` : ''}
                      </p>
                    </div>
                    {p.status && <span className="text-[9px] font-bold px-1.5 py-0.5 rounded" style={{ background: 'rgba(16,185,129,0.12)', color: '#10b981' }}>{p.status}</span>}
                    {p._lead && <span className="text-[9px] font-bold px-1.5 py-0.5 rounded" style={{ background: 'rgba(124,58,237,0.12)', color: '#a78bfa' }}>Lead</span>}
                  </div>
                ))}
              </div>
            )}
          </Card>

          {/* Attachments */}
          <Card title="Attachments" icon={FileText}>
            {allDocs.length === 0 ? <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No attachments on file.</p> : (
              <div className="space-y-2">
                {allDocs.map(d => (
                  <div key={d.id} className="flex items-center gap-2 px-3 py-2 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                    <FileText size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
                    <div className="flex-1 min-w-0">
                      <p className="text-[11px] font-semibold truncate" style={{ color: 'var(--text-h)' }}>{d.original_name}</p>
                      <p className="text-[9px] uppercase" style={{ color: 'var(--text-muted)' }}>{d.type || 'file'}</p>
                    </div>
                    <button onClick={() => docFile(d, false)} title="Preview" style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', padding: 2 }}><Eye size={13} /></button>
                    <button onClick={() => docFile(d, true)} title="Download" style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', padding: 2 }}><Download size={13} /></button>
                  </div>
                ))}
              </div>
            )}
          </Card>
        </div>
      </div>
    </div>
  )
}
