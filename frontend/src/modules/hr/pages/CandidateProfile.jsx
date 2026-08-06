import { useState, useEffect, useRef, useCallback, useMemo } from 'react'
import { useTheme } from '@/context/ThemeContext'
import { useNavigate, useParams } from 'react-router-dom'
import {
  ArrowLeft, ArrowRight, CheckCircle, XCircle, Clock, Mail, Phone, MessageCircle, Brain, Upload, Download,
  Trash2, FileText, Eye, History, StickyNote, FolderOpen, Activity as ActivityIcon, LayoutGrid,
  Send, UserCircle2, CalendarDays, IndianRupee, Briefcase, Hash, Loader2,
  GitBranch, ChevronRight, ChevronDown, CalendarPlus, Rocket, Building2, ExternalLink, Bell, MessageSquare, RefreshCw,
  Sparkles,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import WorkflowProgress from '@/components/ui/WorkflowProgress'
import CandidateInterviewWorkspace from '@/modules/hr/components/CandidateInterviewWorkspace'
import GenerateOfferDrawer from '@/modules/hr/components/GenerateOfferDrawer'
import AuditTimeline from '@/components/ui/AuditTimeline'
import { HrLoading } from '@/components/ui/HrState'
import CandidateQuickActions from '@/modules/hr/components/CandidateQuickActions'
import { formatCTC, DOCUMENT_TYPES, documentTypeLabel, aiRecommendationStyle, aiDimensionColor, candidateScore, canManageHrQueue, ONBOARDING_DOC_ITEMS, ONBOARDING_DOC_LABELS, computeOnboardingChecklist } from '@/modules/hr/constants'

const resultColor = r => r==='Passed'?{c:'#10b981',bg:'rgba(16,185,129,0.12)'}:r==='Pending'?{c:'#f59e0b',bg:'rgba(245,158,11,0.12)'}:{c:'#f87171',bg:'rgba(239,68,68,0.1)'}
const DECISION_COLORS = { Selected:{c:'#10b981',bg:'rgba(16,185,129,0.1)'}, Hold:{c:'#fbbf24',bg:'rgba(245,158,11,0.1)'}, Rejected:{c:'#f87171',bg:'rgba(239,68,68,0.1)'} }
const currentUser = () => { try { return JSON.parse(localStorage.getItem('crm_user') || 'null') } catch { return null } }
const fmtDateTime = ts => ts ? new Date(ts).toLocaleString('en-IN',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : ''
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—'

// Stage duration (SPK-1). 0 days is a same-day transition, not "no time".
const fmtDuration = (d) => d === 0 ? 'same day' : d === 1 ? '1 day' : `${d} days`

// REC_STYLE lived here: a fifth set of labels (Proceed / Hold / Reject /
// Recommended with Training) that did not even share a vocabulary with aiBand()
// rendered directly above it, so one card could show two different verdicts for the
// same candidate. Styling now comes from aiRecommendationStyle(), keyed on the ONE
// vocabulary the backend RecommendationEngine produces.

// `reason` (SPK-1) explains WHY the score came out this way — supplied by the
// heuristic in CandidateService, shown under the bar rather than in a tooltip so
// HR can read every explanation at a glance.
const ScoreBar = ({ label, value, color, reason }) => (
  <div>
    <div className="flex justify-between mb-1">
      <span className="text-xs font-semibold" style={{ color:'var(--text-muted)' }}>{label}</span>
      <span className="text-xs font-black" style={{ color }}>{value}%</span>
    </div>
    <div className="h-2 rounded-full" style={{ background:'var(--bg-input)' }}>
      <div className="h-full rounded-full transition-all" style={{ width:`${value}%`, background:`linear-gradient(90deg,${color}80,${color})` }}/>
    </div>
    {reason && <p className="text-[10px] mt-1 leading-snug" style={{ color:'var(--text-muted)' }}>{reason}</p>}
  </div>
)

const Fact = ({ icon, label, value }) => value ? (
  <div className="flex items-center gap-1.5 text-xs" style={{ color:'var(--text-muted)' }}>
    {icon}<span style={{ color:'var(--text-h)', fontWeight:600 }}>{value}</span><span className="text-[10px]">{label}</span>
  </div>
) : null

// Smart "Next Action" — the single valid next step for the candidate's stage.
// Reuses existing module routes only; no business logic here.
const nextActionFor = (j) => {
  if (!j) return null
  const done = k => j.stages.find(s => s.key === k)?.status === 'completed'
  const stage = j.summary.current_stage
  if (done('employee_created')) return { label: 'Open Employee Profile', to: `/app/hr/employees/${j.summary.employee_id}` }
  if (done('offer_accepted'))   return { label: 'Confirm Joining',        to: '/app/hr/offers' }
  if (done('offer_sent'))       return { label: 'Open Offer',             to: '/app/hr/offers' }
  if (done('offer_generated'))  return { label: 'Send Offer',             to: '/app/hr/offers' }
  if (done('onb_verified'))     return { label: 'Open Offer Letter',      to: '/app/hr/offers' }
  if (done('onb_submitted'))    return { label: 'Verify Documents',       to: '/app/hr/onboarding' }
  if (done('onb_invited') || done('selected')) return { label: 'Open Onboarding', to: '/app/hr/onboarding' }
  if (stage === 'Interview')    return { label: 'Schedule Interview',     to: '/app/hr/interviews', schedule: true }
  if (['Screening', 'Assessment'].includes(stage)) return { label: 'Schedule Interview', to: '/app/hr/interviews', schedule: true }
  if (stage === 'Applied')      return { label: 'Schedule Screening',     to: '/app/hr/interviews', schedule: true }
  return null
}

// 7 stage badges derived from journey completion (current highlighted, done green, pending gray).
const STAGE_BADGES = ['Applied', 'Screening', 'Interview', 'Selected', 'Onboarding', 'Offer', 'Employee']
const stageBadgeStates = (j) => {
  if (!j) return []
  const done = k => j.stages.find(s => s.key === k)?.status === 'completed'
  const stage = j.summary.current_stage
  const hasScreening = ['Screening', 'Assessment', 'Interview', 'Offer', 'Hired'].includes(stage) || done('selected') || j.stages.some(s => s.key.startsWith('interview_'))
  const interviewDone = done('selected') || j.stages.some(s => s.key.startsWith('interview_') && s.status === 'completed')
  const flags = [done('applied'), hasScreening, interviewDone, done('selected'), done('onb_invited'), done('offer_generated'), done('employee_created')]
  const currentIdx = flags.reduce((acc, f, i) => f ? i : acc, 0)
  return STAGE_BADGES.map((label, i) => ({ label, state: i < currentIdx ? 'done' : i === currentIdx ? 'current' : 'pending' }))
}

// SPK-1: name the previous / current / next stage explicitly, derived from the
// same badge states above so the two can never disagree.
const stageTriad = (badges) => {
  const i = badges.findIndex(b => b.state === 'current')
  if (i < 0) return null
  return { previous: badges[i - 1]?.label || null, current: badges[i].label, next: badges[i + 1]?.label || null }
}

// #15 — the API returns raw column names in `auto_filled`; the toast has to say
// what a recruiter calls them, not what the database does.
const FIELD_LABELS = (f) => ({
  current_department:      'Department',
  current_designation:     'Designation',
  current_company:         'Present Company',
  referred_by_name:        'Reference',
  professional_references: 'References',
}[f] || f)

export default function CandidateProfile() {
  const { isDark } = useTheme()
  const navigate   = useNavigate()
  const { id }     = useParams()

  const [candidate, setCandidate]           = useState(null)
  const [loading, setLoading]               = useState(true)
  const [toast, setToast]                   = useState(null)
  const [savingDecision, setSavingDecision] = useState(false)
  const [activeTab, setActiveTab]           = useState('overview')
  const [offerDrawer, setOfferDrawer]       = useState(false)
  const [recruiters, setRecruiters]         = useState([])
  const [journey, setJourney]               = useState(null)
  const [aiScore, setAiScore]               = useState(null)
  const [aiLoading, setAiLoading]           = useState(false)
  const [expandedStage, setExpandedStage]   = useState(null)  // journey stage key (SPK-1)
  const [journeyLoading, setJourneyLoading] = useState(false)
  // Notes
  const [notes, setNotes]       = useState([])
  const [newNote, setNewNote]   = useState('')
  const [addingNote, setAddingNote] = useState(false)
  // Documents
  const [documents, setDocuments]     = useState([])
  const [docType, setDocType]         = useState('other')
  const [docUploading, setDocUploading] = useState(false)
  const docInputRef = useRef(null)
  // Resume
  const [resumeUploading, setResumeUploading] = useState(false)
  const [resumeProgress, setResumeProgress]   = useState(0)
  const [dragOver, setDragOver]               = useState(false)
  const [showPreview, setShowPreview]         = useState(false)
  const [extracting, setExtracting]           = useState(false)   // #15
  const fileInputRef = useRef(null)

  const user      = currentUser()
  const canManage = canManageHrQueue(user)
  const showToast = (msg, type='success') => { setToast({msg,type}); setTimeout(()=>setToast(null),3000) }

  const loadCandidate = useCallback(async () => {
    if (!id) { setCandidate(null); setLoading(false); return }
    // This workspace stays mounted when :id changes (Previous/Next only swaps the
    // param), so the previous candidate's data must be cleared and `loading`
    // re-armed. Without this the outgoing candidate's AI score, breakdown and
    // recommendation render under the incoming candidate's name and URL.
    setLoading(true)
    setCandidate(null)
    setNotes([])
    setDocuments([])
    try {
      const data = await hrApi.candidates.get(id)
      setCandidate(data)
      setNotes(data.candidate_notes || [])
      setDocuments(data.documents || [])
    } catch {
      showToast('Failed to load candidate','error')
      setCandidate(null)
    } finally { setLoading(false) }
  }, [id])

  useEffect(() => { loadCandidate() }, [loadCandidate])
  useEffect(() => { hrApi.candidates.recruiters().then(setRecruiters).catch(() => {}) }, [])

  // Sibling candidates (SPK-1) — reuses the existing list API so Previous/Next can
  // move between candidates in the SAME pipeline order without ever returning to
  // the list. Fetched once; navigating a sibling only changes the :id param, so
  // this workspace stays mounted (same layout, same active tab — only data changes).
  const [siblings, setSiblings] = useState([])
  useEffect(() => { hrApi.candidates.list().then(l => setSiblings(Array.isArray(l) ? l : [])).catch(() => {}) }, [])
  const sibIdx   = siblings.findIndex(s => Number(s.id) === Number(id))
  const prevCand = sibIdx > 0 ? siblings[sibIdx - 1] : null
  const nextCand = sibIdx >= 0 && sibIdx < siblings.length - 1 ? siblings[sibIdx + 1] : null

  // Journey (SPK-1) — aggregation over existing relations + audit logs. Loaded on
  // mount so the header, Smart Action Panel, badges and summary always have data.
  useEffect(() => {
    if (!id) return
    // Clear first: the summary panel renders on `journey` being truthy, so a
    // stale object would show the previous candidate's AI and interview scores.
    setJourney(null)
    setJourneyLoading(true)
    hrApi.candidates.journey(id).then(setJourney).catch(() => {}).finally(() => setJourneyLoading(false))
  }, [id])

  // AI job-fit score — the one endpoint. Everything shown below (overall,
  // confidence, recommendation, per-dimension scores and their reasons) comes from
  // this payload; this page computes no part of it. Cleared on :id change for the
  // same reason as `journey` — the workspace stays mounted between candidates.
  useEffect(() => {
    if (!id) return
    setAiScore(null)
    setAiLoading(true)
    hrApi.candidates.score(id).then(setAiScore).catch(() => {}).finally(() => setAiLoading(false))
  }, [id])

  // Click a journey stage → open the underlying record (reuses existing pages).
  const openStageRecord = (link) => {
    if (!link) return
    const to = {
      manpower:   '/app/hr/manpower-requests',
      job:        `/app/hr/jobs/${link.id}`,
      candidate:  `/app/hr/candidates/${link.id}`,
      interview:  '/app/hr/interviews',
      onboarding: '/app/hr/onboarding',
      offer:      '/app/hr/offers',
      employee:   `/app/hr/employees/${link.id}`,
    }[link.type]
    if (to) navigate(to)
  }

  // Merge a change and silently refresh so the timeline reflects new audit rows.
  const applyChange = (partial) => { setCandidate(prev => ({ ...prev, ...partial })); loadCandidate() }

  const handleDecision = async (decision) => {
    if (!candidate?.id || candidate.id === 'demo') return
    setSavingDecision(true)
    try {
      const updated = await hrApi.candidates.updateDecision(candidate.id, decision)
      setCandidate(prev => ({...prev, final_decision: updated.final_decision, stage: updated.stage ?? prev.stage}))
      loadCandidate()
      showToast(`Candidate marked as ${decision}!`)
    } catch { showToast('Failed to update decision','error') }
    finally { setSavingDecision(false) }
  }

  // ── Notes ──────────────────────────────────────────────
  const addNote = async () => {
    if (!newNote.trim() || !candidate?.id || candidate.id === 'demo') return
    setAddingNote(true)
    try {
      const note = await hrApi.candidates.notes.add(candidate.id, newNote.trim())
      setNotes(prev => [note, ...prev]); setNewNote('')
      showToast('Note added')
    } catch (e) { showToast(e.response?.data?.message || 'Failed to add note','error') }
    finally { setAddingNote(false) }
  }
  const deleteNote = async (noteId) => {
    if (!window.confirm('Delete this note?')) return
    try { await hrApi.candidates.notes.delete(candidate.id, noteId); setNotes(prev => prev.filter(n => n.id !== noteId)); showToast('Note deleted') }
    catch { showToast('Failed to delete note','error') }
  }

  // ── Documents ──────────────────────────────────────────
  const uploadDocument = async (file) => {
    if (!file || !candidate?.id || candidate.id === 'demo') return
    setDocUploading(true)
    try {
      const doc = await hrApi.candidates.documents.upload(candidate.id, file, docType)
      setDocuments(prev => [doc, ...prev]); showToast('Document uploaded')
    } catch (e) { showToast(e.response?.data?.message || 'Upload failed','error') }
    finally { setDocUploading(false); if (docInputRef.current) docInputRef.current.value = '' }
  }
  const downloadDocument = async (doc) => {
    try {
      const blob = await hrApi.candidates.documents.blob(candidate.id, doc.id)
      const url = URL.createObjectURL(blob)
      const a = window.document.createElement('a'); a.href = url; a.download = doc.original_name; a.click()
      setTimeout(() => URL.revokeObjectURL(url), 1500)
    } catch { showToast('Failed to download','error') }
  }
  // Documents Timeline preview/download (SPK-1). Routes to whichever existing
  // blob endpoint owns the file — candidate uploads vs onboarding uploads.
  const timelineBlob = async (r) => {
    if (r.source === 'candidate') return hrApi.candidates.documents.blob(candidate.id, r.raw.id)
    if (r.source === 'onboarding') return hrApi.onboarding.documentBlob(candidate.onboarding.id, r.raw.id)
    return null
  }
  const downloadTimelineDoc = async (r) => {
    try {
      const blob = await timelineBlob(r)
      if (!blob) return showToast('This entry has no downloadable file', 'error')
      const url = URL.createObjectURL(blob)
      const a = window.document.createElement('a'); a.href = url; a.download = r.name; a.click()
      setTimeout(() => URL.revokeObjectURL(url), 1500)
    } catch { showToast('Failed to download', 'error') }
  }
  const viewTimelineDoc = async (r) => {
    try {
      const blob = await timelineBlob(r)
      if (!blob) return showToast('This entry has no previewable file', 'error')
      const url = URL.createObjectURL(blob)
      window.open(url, '_blank', 'noopener')
      setTimeout(() => URL.revokeObjectURL(url), 60000)
    } catch { showToast('Failed to open document', 'error') }
  }

  const deleteDocument = async (docId) => {
    if (!window.confirm('Delete this document?')) return
    try { await hrApi.candidates.documents.delete(candidate.id, docId); setDocuments(prev => prev.filter(d => d.id !== docId)); showToast('Document deleted') }
    catch { showToast('Failed to delete document','error') }
  }

  // ── Resume ─────────────────────────────────────────────
  const handleResumeUpload = useCallback(async (file) => {
    if (!file || !candidate?.id || candidate.id === 'demo') return
    const ext = file.name.split('.').pop().toLowerCase()
    if (!['pdf','doc','docx'].includes(ext)) return showToast('Only PDF, DOC, DOCX allowed','error')
    if (file.size > 5 * 1024 * 1024) return showToast('File must be under 5 MB','error')
    setResumeUploading(true); setResumeProgress(0)
    const ticker = setInterval(() => setResumeProgress(p => Math.min(p + 15, 85)), 200)
    try {
      const result = await hrApi.candidates.uploadResume(candidate.id, file)
      clearInterval(ticker); setResumeProgress(100)
      setCandidate(prev => ({...prev, resume_path: result.resume_path}))
      showToast(`Resume uploaded — ${result.filename} (${result.size_kb} KB)`)
      // #15 — the upload may have auto-filled Dept / Designation / Present Co. /
      // Reference. Name the fields rather than silently changing the record, and
      // reload so the panels below show the new values instead of stale blanks.
      if (result.auto_filled?.length) {
        loadCandidate()
        showToast(`Auto-filled from resume: ${result.auto_filled.map(FIELD_LABELS).join(', ')}`)
      }
      setTimeout(() => setResumeProgress(0), 1000)
    } catch (e) {
      clearInterval(ticker); showToast(e.response?.data?.message || 'Upload failed','error'); setResumeProgress(0)
    } finally { setResumeUploading(false) }
  }, [candidate, loadCandidate])

  // #15 — for resumes already on disk from before auto-fetch existed, or after a
  // recruiter clears a field and wants the CV's value back.
  const handleResumeExtract = async () => {
    if (!candidate?.id || candidate.id === 'demo') return
    setExtracting(true)
    try {
      const res = await hrApi.candidates.extractResume(candidate.id)
      if (res.auto_filled?.length) loadCandidate()
      showToast(res.auto_filled?.length
        ? `Auto-filled from resume: ${res.auto_filled.map(FIELD_LABELS).join(', ')}`
        : res.message, res.auto_filled?.length ? 'success' : 'error')
    } catch (e) { showToast(e.response?.data?.message || 'Could not read the resume','error') }
    finally { setExtracting(false) }
  }

  const handleResumeDelete = async () => {
    if (!candidate?.id || candidate.id === 'demo') return
    if (!window.confirm('Delete this resume? This cannot be undone.')) return
    try { await hrApi.candidates.deleteResume(candidate.id); setCandidate(prev => ({...prev, resume_path: null})); setShowPreview(false); showToast('Resume deleted') }
    catch { showToast('Failed to delete resume','error') }
  }
  const onDrop = useCallback((e) => { e.preventDefault(); setDragOver(false); const file = e.dataTransfer.files?.[0]; if (file) handleResumeUpload(file) }, [handleResumeUpload])

  if (loading) return <HrLoading label="Loading candidate…" />

  if (!candidate) return (
    <div className="text-center py-20 space-y-4">
      <p style={{ color:'var(--text-muted)' }}>Candidate not found.</p>
      <button onClick={()=>navigate('/app/hr/candidates')} className="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>
        <ArrowLeft size={12}/> Back to Candidates
      </button>
    </div>
  )

  const c = candidate
  // The engine's verdict, straight from GET /candidates/{id}/score. This page
  // derives nothing: no thresholds, no band arithmetic, no colour rules keyed off
  // a number. `overall_score === null` is a real state — the backend withholds a
  // score built on too little evidence — so it must render as "—", never as 0%.
  const ai       = aiScore || {}
  const isScored = ai.is_scored === true && ai.overall_score != null
  const aiValue  = isScored ? ai.overall_score : null
  const band     = {
    label: ai.recommendation || (aiLoading ? 'Loading…' : 'Not scored'),
    ...aiRecommendationStyle(ai.recommendation),
  }

  // Per-score explanations (SPK-1). Absent on candidates scored before this
  // change — the bars simply render without a reason line.
  // Scored vs not-measured, split once. `score === null` means the dimension had no
  // input — never a zero, so it must not render as a 0% bar.
  const allDimensions = Array.isArray(ai.dimensions) ? ai.dimensions : []
  const aiDimensions  = allDimensions.filter(d => d.score !== null && d.score !== undefined)
  const aiUnmeasured  = allDimensions.filter(d => d.score === null || d.score === undefined)
  const skills  = Array.isArray(c.skills) ? c.skills : []
  const rounds  = c.interview_rounds || []
  const ctcC = formatCTC(c.current_ctc), ctcE = formatCTC(c.expected_ctc)
  const isDemo = false

  // One workspace, every section of THIS candidate's journey as a tab — nothing
  // here navigates back to the list. Keys are kept stable ('activity' renders the
  // interview rounds, 'timeline' renders the audit trail); only labels align with
  // the SPK-1 wording, and the offer/onboarding/employee/AI tabs render data the
  // candidate payload already carries.
  // "Generate Offer" must reach the real Offer workflow, not just switch tabs.
  //  • offer already exists -> open it (never create a duplicate)
  //  • otherwise            -> open the EXISTING Generate Offer form, prefilled.
  // The offer itself is created by OfferService, which is also what moves the
  // candidate stage to Offer — we never fabricate CTC or joining date here.
  const openOrCreateOffer = () => {
    if (c.offer) { setActiveTab('offer'); return }   // already exists -> open it, never duplicate
    setOfferDrawer(true)                             // launches the shared Generate Offer drawer
  }

  // One workspace, 8 surfaces. Journey / AI / Activity now render inside Overview and
  // Resume inside Documents, so the recruiter has fewer places to look.
  const showIvWorkspace = rounds.length > 0 || ['Screening','Assessment','Interview'].includes(c.stage)
  const TABS = [
    { key:'overview',   label:'Overview',      icon:LayoutGrid },
    { key:'comm',       label:'Communications',icon:MessageSquare },
    { key:'offer',      label:'Offer',         icon:FileText, count: c.offer ? 1 : 0 },
    { key:'onboarding', label:'Onboarding',    icon:UserCircle2, count: c.onboarding ? 1 : 0 },
    { key:'employee',   label:'Employee',      icon:Briefcase, count: c.employee ? 1 : 0 },
    { key:'documents',  label:'Documents',     icon:FolderOpen, count:documents.length },
    { key:'notes',      label:'Notes',         icon:StickyNote, count:notes.length },
  ]

  return (
    <div className="space-y-5 animate-[tiltIn_0.35s_ease_forwards]">
      {/* #14 — where this hire has reached, and what comes next. */}
      <div className="card-3d" style={{ padding:'14px 16px' }}>
        <WorkflowProgress kind="candidate" record={c} />
      </div>

      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl" style={{ background:toast.type==='success'?'linear-gradient(135deg,#10b981,#059669)':'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      {/* Workspace navigation (SPK-1): breadcrumb · prev/next candidate · single Back */}
      <div className="flex items-center gap-3 flex-wrap">
        {/* Breadcrumb — HR › Candidates › {name}. Only "Candidates" leaves the workspace. */}
        <nav className="flex items-center gap-1.5 text-xs" style={{ color:'var(--text-muted)' }}>
          <span>HR</span>
          <ChevronRight size={12}/>
          <button onClick={()=>navigate('/app/hr/candidates')} className="hover:underline" style={{ background:'none', border:'none', cursor:'pointer', color:'var(--text-muted)', padding:0 }}>Candidates</button>
          <ChevronRight size={12}/>
          <span className="font-bold" style={{ color:'var(--text-h)' }}>{c.name}</span>
        </nav>

        <div className="ml-auto flex items-center gap-2 flex-wrap">
          {/* Previous / Next candidate — stays inside the workspace, only data changes */}
          <div className="flex rounded-xl overflow-hidden" style={{ border:'1px solid var(--border)' }}>
            <button onClick={()=>prevCand && navigate(`/app/hr/candidates/${prevCand.id}`)} disabled={!prevCand}
              title={prevCand ? `Previous: ${prevCand.name}` : 'No previous candidate'}
              className="flex items-center gap-1 px-3 py-2 text-xs font-bold transition-all"
              style={{ background:'var(--bg-input)', color: prevCand ? 'var(--text-h)' : 'var(--text-muted)', opacity: prevCand ? 1 : 0.5, cursor: prevCand ? 'pointer' : 'not-allowed' }}>
              <ArrowLeft size={12}/> Previous
            </button>
            <button onClick={()=>nextCand && navigate(`/app/hr/candidates/${nextCand.id}`)} disabled={!nextCand}
              title={nextCand ? `Next: ${nextCand.name}` : 'No next candidate'}
              className="flex items-center gap-1 px-3 py-2 text-xs font-bold transition-all"
              style={{ background:'var(--bg-input)', color: nextCand ? 'var(--text-h)' : 'var(--text-muted)', opacity: nextCand ? 1 : 0.5, cursor: nextCand ? 'pointer' : 'not-allowed', borderLeft:'1px solid var(--border)' }}>
              Next <ArrowRight size={12}/>
            </button>
          </div>

          <span className="text-xs font-mono px-2.5 py-1 rounded-lg flex items-center gap-1" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><Hash size={11}/>CAND-{String(c.id).padStart(4,'0')}</span>
          {!isDemo && (
            <div className="p-1 rounded-lg" style={{ border:'1px solid var(--border)' }}>
              <CandidateQuickActions candidate={c} recruiters={recruiters} onChanged={applyChange} onToast={showToast} hideView/>
            </div>
          )}
          {/* The single control that returns to the list */}
          <button onClick={()=>navigate('/app/hr/candidates')} className="flex items-center gap-1.5 text-xs font-semibold px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>
            <ArrowLeft size={12}/> Back to Candidates
          </button>
        </div>
      </div>

      {/* Header card */}
      <div className="card-3d" style={{ padding:'24px' }}>
        <div className="flex items-start gap-4 flex-wrap">
          <div className="w-16 h-16 rounded-2xl flex items-center justify-center text-xl font-black text-white flex-shrink-0" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
            {(c.name||'?').split(' ').slice(0,2).map(n=>n[0]).join('').toUpperCase()}
          </div>
          <div className="flex-1 min-w-0">
            <h1 className="font-black text-xl" style={{ color:'var(--text-h)', letterSpacing:'-0.02em' }}>{c.name}</h1>
            <p className="text-sm mt-0.5" style={{ color:'var(--text-muted)' }}>{c.current_company || 'Unknown Company'} · {c.experience_years || '—'} yrs exp</p>
            <div className="flex items-center gap-2 flex-wrap mt-2">
              <span className="text-[10px] font-bold px-2.5 py-1 rounded-xl" style={{ background:'rgba(124,58,237,0.12)', color:'#a78bfa' }}>{c.stage}</span>
              <span className="text-[10px] font-bold px-2.5 py-1 rounded-xl" style={{ background:'rgba(59,130,246,0.12)', color:'#60a5fa' }}>{c.source}</span>
              {c.location && <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>📍 {c.location}</span>}
              <span className="text-[10px] font-bold px-2.5 py-1 rounded-xl flex items-center gap-1" style={{ background:c.assigned_recruiter?'rgba(16,185,129,0.12)':'var(--bg-input)', color:c.assigned_recruiter?'#34d399':'var(--text-muted)' }}>
                <UserCircle2 size={11}/>{c.assigned_recruiter ? c.assigned_recruiter.name : 'Unassigned'}
              </span>
            </div>
          </div>
          <div className="flex-shrink-0 text-center px-4 py-3 rounded-2xl" style={{ background:band.bg, border:`1px solid ${band.color}40` }}>
            <p className="text-3xl font-black" style={{ color:band.color }}>{isScored ? `${aiValue}%` : '—'}</p>
            <p className="text-[10px] font-bold mt-0.5" style={{ color:band.color }}>AI Match</p>
            {ai.confidence != null && <p className="text-[9px] mt-0.5" style={{ color:'var(--text-muted)' }}>{ai.confidence}% confidence</p>}
          </div>
        </div>
        {/* Key facts */}
        <div className="flex items-center gap-x-5 gap-y-2 mt-4 pt-4 flex-wrap" style={{ borderTop:'1px solid var(--border)' }}>
          <Fact icon={<Briefcase size={12}/>}  label="applied for" value={c.job_posting?.title}/>
          <Fact icon={<Building2 size={12}/>}  label="department" value={c.job_posting?.department}/>
          <Fact icon={<CalendarDays size={12}/>} label="applied" value={fmtDate(c.applied_at || c.created_at)}/>
          {(ctcC||ctcE) && <Fact icon={<IndianRupee size={12}/>} label="CTC" value={`${ctcC||'—'} → ${ctcE||'—'}`}/>}
          <Fact icon={<Clock size={12}/>} label="notice" value={c.notice_period}/>
        </div>
        {/* Contact row */}
        <div className="flex items-center gap-4 mt-3 flex-wrap">
          {c.email && <a href={`mailto:${c.email}`} className="flex items-center gap-1.5 text-xs" style={{ color:'var(--text-muted)' }}><Mail size={12}/>{c.email}</a>}
          {c.phone && <a href={`tel:${c.phone}`} className="flex items-center gap-1.5 text-xs" style={{ color:'var(--text-muted)' }}><Phone size={12}/>{c.phone}</a>}
          {c.linkedin_url && <a href={c.linkedin_url} target="_blank" rel="noreferrer" className="flex items-center gap-1.5 text-xs font-semibold" style={{ color:'#0a66c2' }}><MessageCircle size={12}/>LinkedIn Profile</a>}
        </div>
      </div>

      {/* ── Smart Action Panel (SPK-1): stage badges · progress · Next Action · module nav ── */}
      {journey && (() => {
        const j = journey
        const badges = stageBadgeStates(j)
        const triad = stageTriad(badges)
        const next = nextActionFor(j)
        const pct = j.summary.progress_pct ?? 0
        const linkOf = (type) => j.stages.find(s => s.link?.type === type)?.link
        // Stay inside the candidate: these switch tabs rather than navigate away.
        // Job is the only one without a candidate-scoped tab, so it still links out.
        const modules = [
          linkOf('job') && ['Job', () => navigate(`/app/hr/jobs/${linkOf('job').id}`)],
          j.stages.some(s => s.key.startsWith('interview_')) && ['Interview', () => setActiveTab('activity')],
          linkOf('onboarding') && ['Onboarding', () => setActiveTab('onboarding')],
          linkOf('offer') && ['Offer', () => setActiveTab('offer')],
          linkOf('employee') && ['Employee', () => setActiveTab('employee')],
        ].filter(Boolean)
        return (
          <div className="card-3d" style={{ padding:'18px' }}>
            {/* Stage badges */}
            <div className="flex items-center gap-1.5 flex-wrap mb-4">
              {badges.map((b, i) => (
                <div key={b.label} className="flex items-center gap-1.5">
                  <span className="text-[10px] font-bold px-2.5 py-1 rounded-lg" style={{
                    background: b.state==='done' ? 'rgba(16,185,129,0.12)' : b.state==='current' ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)',
                    color: b.state==='done' ? '#10b981' : b.state==='current' ? '#fff' : 'var(--text-muted)',
                    border: `1px solid ${b.state==='done' ? 'rgba(16,185,129,0.3)' : b.state==='current' ? 'transparent' : 'var(--border)'}` }}>
                    {b.state==='done' ? '✓ ' : ''}{b.label}
                  </span>
                  {i < badges.length-1 && <ChevronRight size={11} style={{ color:'var(--text-muted)' }}/>}
                </div>
              ))}
            </div>

            {/* Previous · Current · Next stage (SPK-1) */}
            {triad && (
              <div className="flex items-center gap-2 flex-wrap mb-4">
                {[['Previous', triad.previous, 'var(--text-muted)'], ['Current', triad.current, '#a78bfa'], ['Next', triad.next, '#10b981']].map(([cap, val, col]) => (
                  <div key={cap} className="flex-1 min-w-[110px] px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                    <div className="text-[9px] font-bold uppercase tracking-wide" style={{ color:'var(--text-muted)' }}>{cap} Stage</div>
                    <div className="text-xs font-black mt-0.5" style={{ color: val ? col : 'var(--text-muted)' }}>{val || '—'}</div>
                  </div>
                ))}
              </div>
            )}

            <div className="flex items-center gap-4 flex-wrap">
              {/* Progress */}
              <div className="flex-1 min-w-[200px]">
                <div className="flex justify-between mb-1">
                  <span className="text-xs font-semibold" style={{ color:'var(--text-muted)' }}>Recruitment Progress · <b style={{ color:'var(--text-h)' }}>{j.summary.current_status}</b></span>
                  <span className="text-xs font-black" style={{ color:'#a78bfa' }}>{pct}%</span>
                </div>
                <div className="h-2 rounded-full" style={{ background:'var(--bg-input)' }}>
                  <div className="h-full rounded-full transition-all" style={{ width:`${pct}%`, background:'linear-gradient(90deg,#7C3AED,#a78bfa)' }}/>
                </div>
              </div>
              {/* Next Action */}
              {next && canManage && !showIvWorkspace && (
                <button onClick={() => navigate(next.to)} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white flex-shrink-0" style={{ background:'linear-gradient(135deg,#10b981,#059669)', boxShadow:'0 4px 14px rgba(16,185,129,0.35)' }}>
                  <Rocket size={14}/> Next: {next.label}
                </button>
              )}
            </div>

          </div>
        )
      })()}

      {/* Tab bar */}
      <div className="flex gap-1 overflow-x-auto scrollbar-hide p-1 rounded-2xl" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
        {TABS.map(t => {
          const active = activeTab === t.key
          return (
            <button key={t.key} onClick={()=>setActiveTab(t.key)} className="flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold whitespace-nowrap transition-all flex-shrink-0"
              style={{ background:active?'linear-gradient(135deg,#7C3AED,#5b21b6)':'transparent', color:active?'#fff':'var(--text-muted)' }}>
              <t.icon size={13}/> {t.label}
              {t.count>0 && <span className="text-[9px] font-black px-1.5 py-0.5 rounded-full" style={{ background:active?'rgba(255,255,255,0.25)':'var(--bg-card,rgba(124,58,237,0.15))', color:active?'#fff':'#a78bfa' }}>{t.count}</span>}
            </button>
          )
        })}
      </div>

      {/* ── OVERVIEW ── */}
      {/* ── INTERVIEW WORKSPACE — the primary surface: the whole interview
             lifecycle happens here, in place, without leaving the candidate. ── */}
      {/* The ONE offer form — same component the Offers page mounts. Eligibility is
          the candidate's own onboarding, which mirrors the OfferService gate. */}
      <GenerateOfferDrawer
        open={offerDrawer}
        eligible={c.onboarding && c.onboarding.verification_status === 'Approved' && !c.offer
          ? [{ ...c.onboarding, candidate_id: c.id, candidate_name: c.name }] : []}
        candidateId={c.id}
        candidateName={c.name}
        onboarding={c.onboarding || null}
        onClose={() => setOfferDrawer(false)}
        onCreated={() => { setOfferDrawer(false); setActiveTab('offer'); loadCandidate() }}
        showToast={showToast}
      />

      {activeTab==='overview' && showIvWorkspace && (
        <CandidateInterviewWorkspace
          candidate={c}
          rounds={rounds}
          canManage={canManage}
          showToast={showToast}
          onChanged={loadCandidate}
          onOpenOffer={openOrCreateOffer}
        />
      )}

      {activeTab==='overview' && (
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
          <div className="lg:col-span-2 space-y-5">
            {/* Recruitment Summary (SPK-1) */}
            {journey && (
              <div className="card-3d" style={{ padding:'20px' }}>
                <h3 className="font-bold text-sm mb-4 flex items-center gap-2" style={{ color:'var(--text-h)' }}><Briefcase size={14} style={{ color:'#a78bfa' }}/> Recruitment Summary</h3>
                <div className="grid grid-cols-2 md:grid-cols-3 gap-2.5">
                  {[
                    ['Applied Date', fmtDate(journey.summary.applied_at)],
                    ['Current Recruiter', journey.summary.recruiter || 'Unassigned'],
                    ['Interview Count', String(journey.summary.interview_count ?? 0)],
                    ['Offer Status', journey.summary.offer_status],
                    ['Joining Status', journey.summary.joining_status],
                    ['Employee ID', journey.summary.employee_code || '—'],
                  ].map(([k,v]) => (
                    <div key={k} className="px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}>
                      <p className="text-[10px] font-bold uppercase" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>{k}</p>
                      <p className="text-sm font-semibold mt-0.5" style={{ color:'var(--text-h)', wordBreak:'break-word' }}>{v || '—'}</p>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* #15 — Present employment + who referred them. These are the fields the
                resume auto-fetch and the LinkedIn parse both populate; without this
                panel the values were stored and never shown. Rendered whenever any
                one of them is known, with blanks called out so a recruiter can see
                what is still missing rather than what simply isn't displayed. */}
            {(c.current_company || c.current_designation || c.current_department || c.referred_by_name) && (
              <div className="card-3d" style={{ padding:'20px' }}>
                <h3 className="font-bold text-sm mb-4 flex items-center gap-2" style={{ color:'var(--text-h)' }}>
                  <Building2 size={14} style={{ color:'#a78bfa' }}/> Present Employment &amp; Reference
                </h3>
                <div className="grid grid-cols-2 md:grid-cols-4 gap-2.5">
                  {[
                    ['Present Company', c.current_company],
                    ['Designation',     c.current_designation],
                    ['Department',      c.current_department],
                    ['Reference',       c.referred_by_name],
                  ].map(([k, v]) => (
                    <div key={k} className="px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}>
                      <p className="text-[10px] font-bold uppercase" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>{k}</p>
                      <p className="text-sm font-semibold mt-0.5" style={{ color: v ? 'var(--text-h)' : 'var(--text-muted)', wordBreak:'break-word' }}>{v || '—'}</p>
                    </div>
                  ))}
                </div>
                {/* An internal referral is a link to a real employee; an external one
                    is just a name. Saying which is which matters for referral payouts. */}
                {c.referred_by_name && (
                  <p className="text-[10px] mt-2.5" style={{ color:'var(--text-muted)' }}>
                    {c.referred_by_id
                      ? 'Referred by an employee of this organisation.'
                      : 'External reference — not matched to an employee record.'}
                  </p>
                )}
              </div>
            )}

            {/* Recent Activity (SPK-1) — reuse audit logs, newest first */}
            {(c.audit_logs || []).length > 0 && (
              <div className="card-3d" style={{ padding:'20px' }}>
                <div className="flex items-center justify-between mb-4">
                  <h3 className="font-bold text-sm flex items-center gap-2" style={{ color:'var(--text-h)' }}><ActivityIcon size={14} style={{ color:'#a78bfa' }}/> Recent Activity</h3>
                  <button onClick={()=>setActiveTab('timeline')} className="text-[11px] font-bold flex items-center gap-1" style={{ color:'#a78bfa' }}>View all <ChevronRight size={11}/></button>
                </div>
                <div className="space-y-2">
                  {(c.audit_logs || []).slice(0, 6).map((a, i) => (
                    <div key={a.id || i} className="flex items-start gap-2.5 px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}>
                      <div className="w-6 h-6 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5" style={{ background:'rgba(124,58,237,0.12)' }}><History size={12} style={{ color:'#a78bfa' }}/></div>
                      <div className="flex-1 min-w-0">
                        <p className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{a.action}</p>
                        <p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}>{fmtDateTime(a.created_at)}{a.actor_name ? ` · ${a.actor_name}` : ''}</p>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {skills.length>0 && (
              <div className="card-3d" style={{ padding:'20px' }}>
                <h3 className="font-bold text-sm mb-4" style={{ color:'var(--text-h)' }}>🎯 Skills</h3>
                <div className="flex flex-wrap gap-2">
                  {skills.map(s => <span key={s} className="px-3 py-1.5 rounded-xl text-xs font-semibold" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa', border:'1px solid rgba(124,58,237,0.2)' }}>{s}</span>)}
                </div>
              </div>
            )}

            {/* Personal details (Doc 3 Module 3) — DOB, address, education, certs, languages, references */}
            {(c.dob || c.address || c.education?.length || c.certifications?.length || c.languages?.length || c.professional_references?.length) && (
              <div className="card-3d" style={{ padding:'20px' }}>
                <h3 className="font-bold text-sm mb-4" style={{ color:'var(--text-h)' }}>🗂️ Personal Details</h3>
                <div className="grid grid-cols-2 gap-3 mb-3">
                  {c.dob && <div className="px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>Date of Birth</p><p className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{fmtDate(c.dob)}</p></div>}
                  {c.address && <div className="px-3 py-2 rounded-xl col-span-2" style={{ background:'var(--bg-input)' }}><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>Address</p><p className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{c.address}</p></div>}
                </div>
                {[['Education', c.education], ['Certifications', c.certifications], ['Languages', c.languages]].map(([label, list]) => (
                  Array.isArray(list) && list.length > 0 && (
                    <div key={label} className="mb-2.5">
                      <p className="text-[10px] font-bold uppercase mb-1.5" style={{ color:'var(--text-muted)' }}>{label}</p>
                      <div className="flex flex-wrap gap-1.5">
                        {list.map((x, i) => <span key={i} className="px-2.5 py-1 rounded-lg text-[11px] font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-h)', border:'1px solid var(--border)' }}>{typeof x === 'string' ? x : [x.degree, x.institution, x.year].filter(Boolean).join(' · ')}</span>)}
                      </div>
                    </div>
                  )
                ))}
                {Array.isArray(c.professional_references) && c.professional_references.length > 0 && (
                  <div>
                    <p className="text-[10px] font-bold uppercase mb-1.5" style={{ color:'var(--text-muted)' }}>References</p>
                    <div className="space-y-1">
                      {c.professional_references.map((r, i) => (
                        <p key={i} className="text-[11px]" style={{ color:'var(--text-muted)' }}>{typeof r === 'string' ? r : [r.name, r.designation, r.company, r.phone, r.email].filter(Boolean).join(' · ')}</p>
                      ))}
                    </div>
                  </div>
                )}
              </div>
            )}
            {c.notes && (
              <div className="card-3d" style={{ padding:'20px' }}>
                <h3 className="font-bold text-sm mb-2" style={{ color:'var(--text-h)' }}>📝 Summary Note</h3>
                <p className="text-sm" style={{ color:'var(--text-muted)' }}>{c.notes}</p>
              </div>
            )}
            {c.linkedin_data && (
              <div className="card-3d" style={{ padding:'18px' }}>
                <h3 className="font-bold text-sm mb-3" style={{ color:'var(--text-h)' }}>🔗 LinkedIn Data</h3>
                <div className="space-y-1.5">
                  {c.linkedin_data.title    && <p className="text-xs" style={{ color:'var(--text-muted)' }}>Title: <b style={{ color:'var(--text-h)' }}>{c.linkedin_data.title}</b></p>}
                  {c.linkedin_data.company  && <p className="text-xs" style={{ color:'var(--text-muted)' }}>Company: <b style={{ color:'var(--text-h)' }}>{c.linkedin_data.company}</b></p>}
                  {c.linkedin_data.location && <p className="text-xs" style={{ color:'var(--text-muted)' }}>Location: <b style={{ color:'var(--text-h)' }}>{c.linkedin_data.location}</b></p>}
                </div>
              </div>
            )}
          </div>
          <div className="space-y-5">
            {/* Employee Status (SPK-1) — only once the candidate has joined */}
            <EmployeeStatus employee={c.employee} navigate={navigate}/>
            {/* AI Assessment */}
            <div className="card-3d" style={{ padding:'20px' }}>
              <div className="flex items-center gap-2 mb-4"><Brain size={16} style={{ color:'#a78bfa' }}/><h3 className="font-bold text-sm" style={{ color:'var(--text-h)' }}>AI Assessment</h3></div>
              <div className="text-center py-3 rounded-2xl mb-4" style={{ background:band.bg }}>
                <p className="text-3xl font-black" style={{ color:band.color }}>{isScored ? `${aiValue}%` : '—'}</p>
                <p className="text-xs font-bold mt-1" style={{ color:band.color }}>{band.label}</p>
                {ai.confidence != null && <p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}>Based on {ai.confidence}% of the scoring weight</p>}
              </div>
              {allDimensions.length>0 && (
                <div className="space-y-3">
                  {/* Straight from score.dimensions: name, score and reason are the
                      engine's own. Unscored dimensions are listed rather than hidden,
                      so it is visible WHY confidence is what it is. */}
                  {aiDimensions.map(d => (
                    <ScoreBar key={d.key} label={d.name} value={d.score} color={aiDimensionColor(d.key)} reason={d.reason}/>
                  ))}
                  {aiUnmeasured.length > 0 && (
                    <p className="text-[10px] leading-snug" style={{ color:'var(--text-muted)' }}>
                      Not measured: {aiUnmeasured.map(d => d.name).join(', ')}.
                    </p>
                  )}
                </div>
              )}

              {/* Strengths / Weaknesses / Recommendation — explain the score */}
              {(ai.strengths?.length || ai.weaknesses?.length || ai.recommendation) && (
                <div className="mt-4 pt-3 space-y-3" style={{ borderTop:'1px solid var(--border)' }}>
                  {ai.strengths?.length > 0 && (
                    <div>
                      <p className="text-[11px] font-bold mb-1.5" style={{ color:'#10b981' }}>✓ Strengths</p>
                      <ul className="space-y-1">{ai.strengths.map((s,i)=><li key={i} className="text-[11px] flex items-start gap-1.5" style={{ color:'var(--text-muted)' }}><span style={{ color:'#10b981' }}>•</span> {s}</li>)}</ul>
                    </div>
                  )}
                  {ai.weaknesses?.length > 0 && (
                    <div>
                      <p className="text-[11px] font-bold mb-1.5" style={{ color:'#f87171' }}>△ Weaknesses</p>
                      <ul className="space-y-1">{ai.weaknesses.map((s,i)=><li key={i} className="text-[11px] flex items-start gap-1.5" style={{ color:'var(--text-muted)' }}><span style={{ color:'#f87171' }}>•</span> {s}</li>)}</ul>
                    </div>
                  )}
                  {ai.recommendation && (() => {
                    // The label itself is already shown above as band.label. Repeating
                    // it here is what used to produce two DIFFERENT verdicts in one
                    // card; now there is one vocabulary, so only the basis is added.
                    const rc = aiRecommendationStyle(ai.recommendation)
                    return (
                      <div className="text-center py-2.5 px-3 rounded-xl" style={{ background:rc.bg }}>
                        <p className="text-[10px] font-semibold" style={{ color:'var(--text-muted)' }}>Why this recommendation</p>
                        {ai.summary && (
                          <p className="text-[10px] mt-1.5 leading-snug" style={{ color:'var(--text-muted)' }}>{ai.summary}</p>
                        )}
                        {ai.risk_flags?.length ? (
                          <ul className="mt-2 space-y-0.5">{ai.risk_flags.map((f,i)=>(
                            <li key={i} className="text-[10px]" style={{ color:'#fbbf24' }}>⚠ {f.label}: {f.detail}</li>
                          ))}</ul>
                        ) : null}
                      </div>
                    )
                  })()}
                </div>
              )}
              <p className="text-[10px] mt-4 pt-3" style={{ color:'var(--text-muted)', borderTop:'1px solid var(--border)' }}>AIR OS ready — scoring will be replaced by the Prediction Engine.</p>
            </div>
            {/* Final Decision */}
            <div className="card-3d" style={{ padding:'20px' }}>
              <h3 className="font-bold text-sm mb-4" style={{ color:'var(--text-h)' }}>⚖️ Final Decision</h3>
              {c.final_decision ? (
                <div className="text-center py-3 rounded-xl" style={{ background:DECISION_COLORS[c.final_decision]?.bg||'var(--bg-input)' }}>
                  <p className="font-black text-lg" style={{ color:DECISION_COLORS[c.final_decision]?.c||'var(--text-h)' }}>{c.final_decision}</p>
                </div>
              ) : canManage ? (
                <div className="space-y-2">
                  <button onClick={()=>handleDecision('Selected')} disabled={savingDecision} className="w-full py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:'linear-gradient(135deg,#10b981,#059669)' }}><CheckCircle size={14}/> Select Candidate</button>
                  <button onClick={()=>handleDecision('Hold')} disabled={savingDecision} className="w-full py-2.5 rounded-xl text-sm font-bold" style={{ background:'rgba(245,158,11,0.1)', color:'#fbbf24', border:'1px solid rgba(245,158,11,0.2)' }}><Clock size={14} className="inline mr-1.5"/> Hold</button>
                  <button onClick={()=>handleDecision('Rejected')} disabled={savingDecision} className="w-full py-2.5 rounded-xl text-sm font-bold" style={{ background:'rgba(239,68,68,0.1)', color:'#f87171', border:'1px solid rgba(239,68,68,0.2)' }}><XCircle size={14} className="inline mr-1.5"/> Reject</button>
                </div>
              ) : <p className="text-xs" style={{ color:'var(--text-muted)' }}>Pending — an HR manager will record the decision.</p>}
            </div>
          </div>
        </div>
      )}

      {/* ── JOURNEY (SPK-1) ── */}
      {activeTab==='overview' && (
        journeyLoading || !journey ? (
          <div className="card-3d text-center py-12" style={{ color:'var(--text-muted)' }}>Loading candidate journey…</div>
        ) : (
          <div className="space-y-5">
            {/* Candidate summary */}
            <div className="card-3d" style={{ padding:'20px' }}>
              <h3 className="font-bold text-sm mb-4 flex items-center gap-2" style={{ color:'var(--text-h)' }}><GitBranch size={14} style={{ color:'#a78bfa' }}/> Candidate Summary</h3>
              <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
                {[
                  ['Candidate', journey.summary.name],
                  ['Applied Job', journey.summary.applied_job],
                  ['Department', journey.summary.department],
                  ['Current Stage', journey.summary.current_stage],
                  ['Recruiter', journey.summary.recruiter],
                  ['Overall AI Score', journey.summary.ai_score != null ? `${journey.summary.ai_score}%` : '—'],  // backend already suppresses pre-engine literals
                  ['Interview Score', journey.summary.interview_score != null ? `${journey.summary.interview_score}%` : '—'],
                  ['Offer Status', journey.summary.offer_status],
                  ['Joining Status', journey.summary.joining_status],
                ].map(([k,v]) => (
                  <div key={k} className="px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}>
                    <p className="text-[10px] font-bold uppercase" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>{k}</p>
                    <p className="text-sm font-semibold mt-0.5" style={{ color:'var(--text-h)', wordBreak:'break-word' }}>{v || '—'}</p>
                  </div>
                ))}
              </div>
            </div>

            {/* Recruitment progress (auto-calculated) */}
            <div className="card-3d" style={{ padding:'20px' }}>
              <h3 className="font-bold text-sm mb-4 flex items-center gap-2" style={{ color:'var(--text-h)' }}><ActivityIcon size={14} style={{ color:'#a78bfa' }}/> Recruitment Progress</h3>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-3">
                <ScoreBar label="Application" value={journey.progress.application} color="#60a5fa"/>
                <ScoreBar label="Interview"   value={journey.progress.interview}   color="#a78bfa"/>
                <ScoreBar label="Onboarding"  value={journey.progress.onboarding}  color="#fbbf24"/>
                <ScoreBar label="Offer"       value={journey.progress.offer}       color="#f97316"/>
                <ScoreBar label="Employee"    value={journey.progress.employee}    color="#10b981"/>
              </div>
            </div>


            {/* Journey stages */}
            <div className="card-3d" style={{ padding:'22px' }}>
              <h3 className="font-bold text-sm mb-4 flex items-center gap-2" style={{ color:'var(--text-h)' }}><CheckCircle size={14} style={{ color:'#10b981' }}/> Recruitment Journey</h3>
              <div className="space-y-1.5">
                {journey.stages.map((s, i) => {
                  // SPK-1: Completed / Current / Pending. `current` is the one stage
                  // the candidate is sitting on right now — highlighted so it is
                  // readable at a glance.
                  const doneS = s.status === 'completed'
                  const isCurrent = s.status === 'current'
                  const st = doneS
                    ? { c:'#10b981', bg:'rgba(16,185,129,0.06)', bd:'rgba(16,185,129,0.2)', chipBg:'rgba(16,185,129,0.12)', label:'Completed' }
                    : isCurrent
                    ? { c:'#a78bfa', bg:'rgba(124,58,237,0.08)', bd:'rgba(124,58,237,0.45)', chipBg:'rgba(124,58,237,0.15)', label:'Current' }
                    : { c:'var(--text-muted)', bg:'var(--bg-input)', bd:'var(--border)', chipBg:'var(--bg-card)', label:'Pending' }
                  const clickable = !!s.link
                  const open = expandedStage === s.key
                  return (
                    <div key={s.key} className="rounded-xl transition-all"
                      style={{ background: st.bg, border:`1px solid ${st.bd}`, boxShadow: isCurrent ? '0 0 0 1px rgba(124,58,237,0.25)' : 'none' }}>
                    <div onClick={() => setExpandedStage(open ? null : s.key)}
                      className="flex items-center gap-3 px-3 py-2.5" style={{ cursor:'pointer' }}>
                      <div className="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0" style={{ background: doneS ? 'rgba(16,185,129,0.15)' : isCurrent ? 'rgba(124,58,237,0.15)' : 'var(--bg-card)', border:`1px solid ${doneS ? '#10b981' : isCurrent ? '#a78bfa' : 'var(--border)'}` }}>
                        {doneS ? <CheckCircle size={13} style={{ color:'#10b981' }}/> : <Clock size={12} style={{ color: isCurrent ? '#a78bfa' : 'var(--text-muted)' }}/>}
                      </div>
                      <div className="flex-1 min-w-0">
                        <p className="text-sm font-semibold" style={{ color: doneS || isCurrent ? 'var(--text-h)' : 'var(--text-muted)' }}>{s.label}</p>
                        {/* Owner · completed date, or the action that unblocks it */}
                        {(s.at || s.owner) && (
                          <p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}>
                            {s.at ? fmtDateTime(s.at) : ''}{s.at && s.owner ? ' · ' : ''}{s.owner ? `${doneS ? 'by' : 'owner:'} ${s.owner}` : ''}
                            {s.duration_days !== null && s.duration_days !== undefined && (
                              <span style={{ opacity:0.85 }}> · {fmtDuration(s.duration_days)}{isCurrent ? ' so far' : ''}</span>
                            )}
                          </p>
                        )}
                        {s.pending_action && (
                          <p className="text-[10px] mt-0.5 font-semibold" style={{ color: isCurrent ? '#a78bfa' : 'var(--text-muted)' }}>
                            → {s.pending_action}
                          </p>
                        )}
                      </div>
                      <span className="text-[9px] font-bold px-2 py-0.5 rounded-lg flex-shrink-0" style={{ background: st.chipBg, color: st.c }}>{st.label}</span>
                      {/* Open the underlying record — kept separate from expand */}
                      {clickable && (
                        <button onClick={(e) => { e.stopPropagation(); openStageRecord(s.link) }} title="Open record"
                          style={{ background:'none', border:'none', cursor:'pointer', padding:2, color:'var(--text-muted)', flexShrink:0 }}>
                          <ExternalLink size={12}/>
                        </button>
                      )}
                      <ChevronDown size={13} style={{ color:'var(--text-muted)', flexShrink:0, transform: open ? 'rotate(180deg)' : 'none', transition:'transform .15s' }}/>
                    </div>
                    {open && (
                      <div className="px-3 pb-3 pt-1" style={{ borderTop:'1px dashed var(--border)', marginTop:2 }}>
                        <StageDetails stage={s} candidate={c} rounds={rounds}/>
                        <StageContext stage={s} candidate={c} documents={documents} notes={notes}/>
                      </div>
                    )}
                    </div>
                  )
                })}
              </div>
            </div>

            {/* Timeline (reuse existing audit_logs — newest first) */}
            <div className="card-3d" style={{ padding:'22px' }}>
              <h3 className="font-bold text-sm mb-4 flex items-center gap-2" style={{ color:'var(--text-h)' }}><History size={14} style={{ color:'#a78bfa' }}/> Timeline</h3>
              <AuditTimeline entries={c.audit_logs || []} newestFirst />
            </div>
          </div>
        )
      )}

      {/* ── COMMUNICATION (SPK-1) ── */}
      {activeTab==='comm' && <CommTab candidateId={id} canManage={canManage} showToast={showToast} onSent={loadCandidate} />}

      {/* ── RESUME ── */}
      {activeTab==='documents' && (
        <div className="card-3d" style={{ padding:'20px' }}>
          <div className="flex items-center justify-between mb-4">
            <h3 className="font-bold text-sm flex items-center gap-2" style={{ color:'var(--text-h)' }}><FileText size={14} style={{ color:'#a78bfa' }}/> Resume</h3>
            {c.resume_path && (
              <div className="flex items-center gap-2">
                <a href={`${hrApi.candidates.resumeUrl(c.id)}?token=${localStorage.getItem('crm_token')}`} target="_blank" rel="noreferrer" className="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-xl" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa', border:'1px solid rgba(124,58,237,0.2)' }}><Download size={11}/> Download</a>
                <button onClick={()=>setShowPreview(p=>!p)} className="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-xl" style={{ background:'rgba(59,130,246,0.1)', color:'#60a5fa', border:'1px solid rgba(59,130,246,0.2)' }}><Eye size={11}/> {showPreview?'Hide':'Preview'}</button>
                {/* #15 — auto-fetch Dept / Designation / Present Co. / Reference.
                    Runs on upload already; this is for resumes already on file. */}
                {canManage && (
                  <button onClick={handleResumeExtract} disabled={extracting}
                    title="Read Department, Designation, Present Company and Reference from this resume. Fields you have already filled in are never overwritten."
                    className="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-xl"
                    style={{ background:'rgba(16,185,129,0.1)', color:'#34d399', border:'1px solid rgba(16,185,129,0.2)', opacity: extracting ? 0.6 : 1 }}>
                    <Sparkles size={11}/> {extracting ? 'Reading…' : 'Auto-fill Details'}
                  </button>
                )}
                {canManage && <button onClick={handleResumeDelete} className="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-xl" style={{ background:'rgba(239,68,68,0.08)', color:'#f87171', border:'1px solid rgba(239,68,68,0.15)' }}><Trash2 size={11}/></button>}
              </div>
            )}
          </div>
          {c.resume_path && showPreview && (
            <div className="mb-4 rounded-xl overflow-hidden" style={{ border:'1px solid var(--border)' }}>
              <iframe src={`${hrApi.candidates.resumeUrl(c.id)}?token=${localStorage.getItem('crm_token')}`} title="Resume Preview" className="w-full" style={{ height:'520px', border:'none', background:'#fff' }}/>
            </div>
          )}
          {c.resume_path ? (
            <div className="flex items-center gap-3 px-3 py-2.5 rounded-xl" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
              <div className="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0" style={{ background:'rgba(239,68,68,0.12)' }}><FileText size={16} style={{ color:'#f87171' }}/></div>
              <div className="flex-1 min-w-0"><p className="text-xs font-bold truncate" style={{ color:'var(--text-h)' }}>{c.resume_path.split('/').pop()}</p><p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}>Click Preview to view • Download to save</p></div>
            </div>
          ) : <p className="text-xs mb-3" style={{ color:'var(--text-muted)' }}>No resume uploaded yet.</p>}
          {resumeUploading && (
            <div className="mt-3"><div className="flex justify-between mb-1"><span className="text-xs" style={{ color:'var(--text-muted)' }}>Uploading…</span><span className="text-xs font-bold" style={{ color:'#a78bfa' }}>{resumeProgress}%</span></div><div className="h-2 rounded-full" style={{ background:'var(--bg-input)' }}><div className="h-full rounded-full transition-all" style={{ width:`${resumeProgress}%`, background:'linear-gradient(90deg,#7C3AED,#a78bfa)' }}/></div></div>
          )}
          {!resumeUploading && canManage && (
            <div onDragOver={e=>{e.preventDefault();setDragOver(true)}} onDragLeave={()=>setDragOver(false)} onDrop={onDrop} onClick={()=>fileInputRef.current?.click()} className="mt-3 flex flex-col items-center justify-center gap-2 py-5 rounded-xl cursor-pointer transition-all" style={{ border:`2px dashed ${dragOver?'#7C3AED':'var(--border)'}`, background: dragOver?'rgba(124,58,237,0.06)':'transparent' }}>
              <Upload size={20} style={{ color: dragOver?'#7C3AED':'var(--text-muted)' }}/>
              <p className="text-xs font-semibold" style={{ color: dragOver?'#a78bfa':'var(--text-muted)' }}>{c.resume_path ? 'Drop to replace resume' : 'Drop resume here or click to browse'}</p>
              <p className="text-[10px]" style={{ color:'var(--text-muted)' }}>PDF, DOC, DOCX · Max 5 MB</p>
              <input ref={fileInputRef} type="file" accept=".pdf,.doc,.docx" className="hidden" onChange={e=>handleResumeUpload(e.target.files?.[0])}/>
            </div>
          )}
        </div>
      )}

      {/* ── TIMELINE ── */}
      {/* ── AI EVALUATION (reuses the loaded ai_breakdown) ── */}
      {activeTab==='overview' && (
        <div className="card-3d" style={{ padding:'22px' }}>
          <div className="flex items-center gap-2 mb-4"><Brain size={16} style={{ color:'#a78bfa' }}/><h3 className="font-bold text-sm" style={{ color:'var(--text-h)' }}>AI Evaluation</h3>{isScored && <span className="ml-auto text-xl font-black" style={{ color:band.color }}>{aiValue}%</span>}</div>
          {!isScored ? (
            <p className="text-xs" style={{ color:'var(--text-muted)' }}>
              {aiLoading ? 'Loading…' : (ai.summary || 'This candidate has not been scored yet.')}
            </p>
          ) : (
            <>
              <div className="space-y-3">
                {aiDimensions.map(d => (
                  <ScoreBar key={d.key} label={d.name} value={d.score} color={aiDimensionColor(d.key)} reason={d.reason}/>
                ))}
                {aiUnmeasured.length > 0 && (
                  <p className="text-[10px] leading-snug" style={{ color:'var(--text-muted)' }}>
                    Not measured: {aiUnmeasured.map(d => d.name).join(', ')}.
                  </p>
                )}
              </div>
              {(ai.strengths?.length || ai.weaknesses?.length) && (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                  {ai.strengths?.length ? <div><p className="text-[10px] font-bold uppercase mb-1" style={{ color:'#10b981' }}>Strengths</p><ul className="space-y-1">{ai.strengths.map((s,i)=><li key={i} className="text-[11px] flex items-start gap-1.5" style={{ color:'var(--text-muted)' }}><span style={{ color:'#10b981' }}>•</span> {s}</li>)}</ul></div> : null}
                  {ai.weaknesses?.length ? <div><p className="text-[10px] font-bold uppercase mb-1" style={{ color:'#f87171' }}>Weaknesses</p><ul className="space-y-1">{ai.weaknesses.map((s,i)=><li key={i} className="text-[11px] flex items-start gap-1.5" style={{ color:'var(--text-muted)' }}><span style={{ color:'#f87171' }}>•</span> {s}</li>)}</ul></div> : null}
                </div>
              )}
              {ai.recommendation && (() => { const rc = aiRecommendationStyle(ai.recommendation); return (
                <div className="text-center py-2.5 px-3 rounded-xl mt-4" style={{ background:rc.bg }}>
                  <p className="text-[10px] font-semibold" style={{ color:'var(--text-muted)' }}>AI Recommendation</p>
                  <p className="font-black text-base" style={{ color:rc.c }}>{ai.recommendation}</p>
                  {ai.summary && <p className="text-[10px] mt-1.5 leading-snug" style={{ color:'var(--text-muted)' }}>{ai.summary}</p>}
                  {ai.confidence != null && <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>{ai.confidence}% confidence</p>}
                </div>
              )})()}
            </>
          )}
        </div>
      )}

      {/* ── OFFER (reuses the loaded offer relation) ── */}
      {activeTab==='offer' && (
        <div className="card-3d" style={{ padding:'22px' }}>
          <h3 className="font-bold text-sm mb-4 flex items-center gap-2" style={{ color:'var(--text-h)' }}><FileText size={14} style={{ color:'#a78bfa' }}/> Offer Letter</h3>
          {!c.offer ? <p className="text-xs" style={{ color:'var(--text-muted)' }}>No offer has been generated for this candidate yet.</p> : (() => {
            const o = c.offer
            const offerNo = `OFR-${String(o.id).padStart(4,'0')}`
            // The SAME stored file the candidate downloaded and signed — never regenerated.
            const apiBase = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'
            const letterUrl = o.access_token ? `${apiBase}/offer/${o.access_token}/letter` : null
            const fileName = o.letter_path ? String(o.letter_path).split('/').pop() : null
            const signed = !!o.accepted_at

            return (
              <>
                {/* Commercial terms */}
                <div className="grid grid-cols-2 md:grid-cols-3 gap-x-4 gap-y-3">
                  {[
                    ['Offer Number', offerNo],
                    ['Position', o.position],
                    ['Salary (CTC)', formatCTC(o.offered_ctc)],
                    ['Joining Date', fmtDate(o.joining_date)],
                    ['Status', o.status],
                    ['Generated', fmtDateTime(o.generated_at)],
                  ].map(([k,v]) => <div key={k}><p className="text-[9px] font-bold uppercase tracking-wide" style={{ color:'var(--text-muted)' }}>{k}</p><p className="text-xs font-semibold mt-0.5" style={{ color:'var(--text-h)' }}>{v || '—'}</p></div>)}
                </div>

                {/* Offer lifecycle — every stage from the stored timestamps */}
                <div className="mt-4 pt-4" style={{ borderTop:'1px solid var(--border)' }}>
                  <p className="text-[10px] font-bold uppercase tracking-wide mb-3" style={{ color:'var(--text-muted)' }}>Offer Timeline</p>
                  <div className="flex flex-wrap gap-2">
                    {[
                      ['Generated', o.generated_at],
                      ['Sent', o.sent_at],
                      ['Viewed', o.viewed_at],
                      ['Accepted', o.accepted_at],
                      ['Confirmed Joining', o.joining_confirmed_at],
                    ].map(([label, ts]) => (
                      <div key={label} className="flex-1 min-w-[132px] px-3 py-2 rounded-xl" style={{ background: ts ? 'rgba(16,185,129,0.08)' : 'var(--bg-input)', border:`1px solid ${ts ? 'rgba(16,185,129,0.25)' : 'var(--border)'}` }}>
                        <p className="text-[9px] font-bold uppercase tracking-wide flex items-center gap-1" style={{ color: ts ? '#10b981' : 'var(--text-muted)' }}>
                          <span>{ts ? '●' : '○'}</span> {label}
                        </p>
                        <p className="text-[10.5px] font-semibold mt-0.5" style={{ color: ts ? 'var(--text-h)' : 'var(--text-muted)' }}>
                          {ts ? fmtDateTime(ts) : 'Pending'}
                        </p>
                      </div>
                    ))}
                  </div>
                </div>

                {/* Acceptance metadata — captured at signing, shown as stored */}
                {signed && (
                  <div className="mt-4 pt-4" style={{ borderTop:'1px solid var(--border)' }}>
                    <p className="text-[10px] font-bold uppercase tracking-wide mb-2" style={{ color:'#10b981' }}>✓ Offer Accepted</p>
                    <div className="grid grid-cols-2 md:grid-cols-3 gap-x-4 gap-y-3">
                      {[
                        ['Accepted At', fmtDateTime(o.accepted_at)],
                        ['Signed Name', o.accepted_name],
                        ['Accepted IP', o.accepted_ip],
                        ['Device', o.accepted_device],
                        ['Browser', o.accepted_browser],
                        ['Signature', o.accepted_signature ? 'Captured' : 'Typed acceptance'],
                      ].map(([k,v]) => <div key={k}><p className="text-[9px] font-bold uppercase tracking-wide" style={{ color:'var(--text-muted)' }}>{k}</p><p className="text-xs font-semibold mt-0.5" style={{ color:'var(--text-h)' }}>{v || '—'}</p></div>)}
                    </div>
                    <div className="mt-3">
                      <p className="text-[9px] font-bold uppercase tracking-wide mb-1" style={{ color:'var(--text-muted)' }}>Signature</p>
                      <SignatureBlock src={o.accepted_signature}/>
                    </div>
                  </div>
                )}

                {/* The stored signed PDF — reused, never regenerated */}
                <div className="mt-4 pt-4" style={{ borderTop:'1px solid var(--border)' }}>
                  {!o.letter_path ? (
                    <p className="text-xs" style={{ color:'var(--text-muted)' }}>No signed offer letter available.</p>
                  ) : (
                    <>
                      <div className="flex items-center justify-between gap-3 flex-wrap mb-3">
                        <div className="min-w-0">
                          <p className="text-xs font-bold truncate" style={{ color:'var(--text-h)' }}>{fileName}</p>
                          <p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}>
                            Generated {fmtDateTime(o.generated_at)}{signed ? ` · Accepted ${fmtDateTime(o.accepted_at)}` : ''}
                          </p>
                        </div>
                        {letterUrl && (
                          <div className="flex gap-2 flex-shrink-0">
                            <a href={letterUrl} target="_blank" rel="noreferrer" className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-[11px] font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>
                              <ExternalLink size={12}/> Open in new tab
                            </a>
                            <a href={letterUrl} download={fileName} className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-[11px] font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
                              <Download size={12}/> Download
                            </a>
                          </div>
                        )}
                      </div>
                      {letterUrl && <OfferPdfPreview url={letterUrl} fileName={fileName}/>}
                    </>
                  )}
                </div>

                <button onClick={()=>navigate('/app/hr/offers')} className="mt-4 text-[11px] font-bold flex items-center gap-1" style={{ color:'#a78bfa', background:'none', border:'none', cursor:'pointer', padding:0 }}><ExternalLink size={11}/> Manage in Offer Letters</button>
              </>
            )
          })()}
        </div>
      )}

      {/* ── ONBOARDING (reuses the loaded onboarding relation + shared checklist) ── */}
      {activeTab==='onboarding' && (
        <div className="card-3d" style={{ padding:'22px' }}>
          <h3 className="font-bold text-sm mb-4 flex items-center gap-2" style={{ color:'var(--text-h)' }}><UserCircle2 size={14} style={{ color:'#a78bfa' }}/> Onboarding</h3>
          {!c.onboarding ? <p className="text-xs" style={{ color:'var(--text-muted)' }}>Onboarding has not started for this candidate yet.</p> : (() => {
            const ob = c.onboarding
            const cl = computeOnboardingChecklist(ob, c.offer)
            const items = ONBOARDING_DOC_ITEMS.map(k => ({ key:k, label:ONBOARDING_DOC_LABELS[k], ok:!!cl[k] }))
            const pct = Math.round(items.filter(i=>i.ok).length / items.length * 100)
            return (
              <>
                <div className="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3 mb-4">
                  {[['Status', ob.status], ['Verification', ob.verification_status], ['Invited', ob.invited_at?fmtDate(ob.invited_at):'—'], ['Joining Date', fmtDate(ob.joining_date)]].map(([k,v]) => <div key={k}><p className="text-[9px] font-bold uppercase tracking-wide" style={{ color:'var(--text-muted)' }}>{k}</p><p className="text-xs font-semibold mt-0.5" style={{ color:'var(--text-h)' }}>{v || '—'}</p></div>)}
                </div>
                <p className="text-[10px] font-bold uppercase mb-1" style={{ color:'var(--text-muted)' }}>Document Checklist · {pct}%</p>
                <div className="h-1.5 rounded-full mb-3" style={{ background:'var(--bg-input)' }}><div className="h-full rounded-full" style={{ width:`${pct}%`, background:'linear-gradient(90deg,#10b981,#34d399)' }}/></div>
                {items.map(d => <div key={d.key} className="flex items-center gap-1.5 text-[11px] py-0.5"><span style={{ color: d.ok?'#10b981':'var(--text-muted)' }}>{d.ok?'✓':'○'}</span><span style={{ color: d.ok?'var(--text-h)':'var(--text-muted)' }}>{d.label}</span></div>)}
                <button onClick={()=>navigate('/app/hr/onboarding')} className="mt-4 text-[11px] font-bold flex items-center gap-1" style={{ color:'#a78bfa', background:'none', border:'none', cursor:'pointer', padding:0 }}><ExternalLink size={11}/> Manage in Onboarding</button>
              </>
            )
          })()}
        </div>
      )}

      {/* ── EMPLOYEE (reuses the existing EmployeeStatus component) ── */}
      {activeTab==='employee' && (
        c.employee ? <EmployeeStatus employee={c.employee} navigate={navigate}/> :
        <div className="card-3d" style={{ padding:'22px' }}><h3 className="font-bold text-sm mb-2 flex items-center gap-2" style={{ color:'var(--text-h)' }}><Briefcase size={14} style={{ color:'#a78bfa' }}/> Employee</h3><p className="text-xs" style={{ color:'var(--text-muted)' }}>This candidate has not been converted to an employee yet.</p></div>
      )}

      {activeTab==='overview' && (
        <div className="card-3d" style={{ padding:'22px' }}>
          <h3 className="font-bold text-sm mb-4 flex items-center gap-2" style={{ color:'var(--text-h)' }}><History size={14} style={{ color:'#a78bfa' }}/> Activity Timeline (Audit History)</h3>
          <AuditTimeline entries={c.audit_logs || []} />
        </div>
      )}

      {/* ── NOTES ── */}
      {activeTab==='notes' && (
        <div className="card-3d" style={{ padding:'22px' }}>
          <h3 className="font-bold text-sm mb-4 flex items-center gap-2" style={{ color:'var(--text-h)' }}><StickyNote size={14} style={{ color:'#a78bfa' }}/> Notes</h3>
          {canManage && !isDemo && (
            <div className="flex gap-2 mb-5">
              <textarea className="input-3d text-sm flex-1" rows={2} placeholder="Add a note for the team…" value={newNote} onChange={e=>setNewNote(e.target.value)}/>
              <button onClick={addNote} disabled={addingNote||!newNote.trim()} className="px-4 rounded-xl text-sm font-bold text-white flex items-center gap-2 self-stretch" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity:(addingNote||!newNote.trim())?0.6:1 }}>{addingNote?<Loader2 size={14} className="animate-spin"/>:<Send size={14}/>}</button>
            </div>
          )}
          {notes.length===0 ? <p className="text-xs" style={{ color:'var(--text-muted)' }}>No notes yet.</p> : (
            <div className="space-y-3">
              {notes.map(n => (
                <div key={n.id} className="p-3 rounded-xl" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                  <div className="flex items-center justify-between mb-1">
                    <span className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{n.user?.name || 'HR Team'}</span>
                    <div className="flex items-center gap-2">
                      <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>{fmtDateTime(n.created_at)}</span>
                      {canManage && <button onClick={()=>deleteNote(n.id)} style={{ color:'#f87171' }}><Trash2 size={12}/></button>}
                    </div>
                  </div>
                  <p className="text-sm" style={{ color:'var(--text-muted)', whiteSpace:'pre-wrap' }}>{n.body}</p>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* ── DOCUMENTS ── */}
      {activeTab==='documents' && (
        <div className="space-y-5">
        {/* Documents Timeline (SPK-1) — every document source in hiring order */}
        <div className="card-3d" style={{ padding:'22px' }}>
          <h3 className="font-bold text-sm mb-4 flex items-center gap-2" style={{ color:'var(--text-h)' }}><History size={14} style={{ color:'#a78bfa' }}/> Documents Timeline</h3>
          <DocumentsTimeline candidate={c} documents={documents} onView={viewTimelineDoc} onDownload={downloadTimelineDoc}/>
        </div>
        <div className="card-3d" style={{ padding:'22px' }}>
          <h3 className="font-bold text-sm mb-4 flex items-center gap-2" style={{ color:'var(--text-h)' }}><FolderOpen size={14} style={{ color:'#a78bfa' }}/> Documents</h3>
          {canManage && !isDemo && (
            <div className="flex flex-wrap items-center gap-2 mb-5 p-3 rounded-xl" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
              <select className="input-3d text-sm" style={{ maxWidth:170 }} value={docType} onChange={e=>setDocType(e.target.value)}>
                {DOCUMENT_TYPES.map(d=><option key={d.key} value={d.key}>{d.label}</option>)}
              </select>
              <button onClick={()=>docInputRef.current?.click()} disabled={docUploading} className="px-4 py-2 rounded-xl text-sm font-bold text-white flex items-center gap-2" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity:docUploading?0.6:1 }}>{docUploading?<Loader2 size={14} className="animate-spin"/>:<Upload size={14}/>} Upload</button>
              <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>PDF, DOC, DOCX, JPG, PNG · Max 10 MB</span>
              <input ref={docInputRef} type="file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" className="hidden" onChange={e=>uploadDocument(e.target.files?.[0])}/>
            </div>
          )}
          {documents.length===0 ? <p className="text-xs" style={{ color:'var(--text-muted)' }}>No documents uploaded yet.</p> : (
            <div className="space-y-2">
              {documents.map(d => (
                <div key={d.id} className="flex items-center gap-3 px-3 py-2.5 rounded-xl" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                  <div className="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0" style={{ background:'rgba(124,58,237,0.12)' }}><FileText size={16} style={{ color:'#a78bfa' }}/></div>
                  <div className="flex-1 min-w-0">
                    <p className="text-xs font-bold truncate" style={{ color:'var(--text-h)' }}>{d.original_name}</p>
                    <p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}><span className="font-semibold" style={{ color:'#a78bfa' }}>{documentTypeLabel(d.type)}</span> · {d.size_kb} KB · {fmtDate(d.created_at)}</p>
                  </div>
                  <button onClick={()=>downloadDocument(d)} className="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-xl" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa', border:'1px solid rgba(124,58,237,0.2)' }}><Download size={11}/> Download</button>
                  {canManage && <button onClick={()=>deleteDocument(d.id)} className="flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1.5 rounded-xl" style={{ background:'rgba(239,68,68,0.08)', color:'#f87171', border:'1px solid rgba(239,68,68,0.15)' }}><Trash2 size={11}/></button>}
                </div>
              ))}
            </div>
          )}
        </div>
        </div>
      )}

    </div>
  )
}


/**
 * Candidate signature. A stored value can be truncated or non-renderable, so the
 * image is replaced by a neutral placeholder rather than a broken-image icon.
 */
function SignatureBlock({ src }) {
  const [broken, setBroken] = useState(false)
  const usable = !!src && /^data:image\//.test(String(src)) && !broken
  if (usable) {
    return <img src={src} alt="Candidate signature" onError={()=>setBroken(true)}
      style={{ maxHeight:64, background:'#fff', border:'1px solid var(--border)', borderRadius:8, padding:6 }}/>
  }
  return (
    <div className="flex items-center justify-center rounded-lg" style={{ height:64, maxWidth:220, background:'var(--bg-input)', border:'1px dashed var(--border)' }}>
      <span className="text-[10.5px] font-semibold" style={{ color:'var(--text-muted)' }}>No signature captured</span>
    </div>
  )
}

/**
 * Inline preview of the ALREADY-STORED offer letter. If the browser cannot render
 * it inline, the preview is swapped for direct actions — the PDF is never
 * regenerated, both actions point at the same stored file.
 */
function OfferPdfPreview({ url, fileName }) {
  const [failed, setFailed] = useState(false)
  if (failed) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 rounded-xl" style={{ height:200, background:'var(--bg-input)', border:'1px dashed var(--border)' }}>
        <p className="text-xs" style={{ color:'var(--text-muted)' }}>Inline preview isn’t available in this browser.</p>
        <div className="flex gap-2">
          <a href={url} target="_blank" rel="noreferrer" className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-[11px] font-bold" style={{ background:'var(--bg-card,transparent)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><ExternalLink size={12}/> Open PDF</a>
          <a href={url} download={fileName} className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-[11px] font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)' }}><Download size={12}/> Download PDF</a>
        </div>
      </div>
    )
  }
  return (
    <object data={url} type="application/pdf" className="w-full" style={{ height:520, border:'1px solid var(--border)', borderRadius:10, background:'#fff' }}>
      {/* Rendered by the browser when inline PDF display is unsupported */}
      <iframe src={url} title="Signed Offer Letter" onError={()=>setFailed(true)} className="w-full" style={{ height:518, border:'none' }}/>
    </object>
  )
}

// ── Communication Center tab (SPK-1) ──
const COMM_EVENTS = [
  ['application_received', 'Application Received'], ['interview_scheduled', 'Interview Scheduled'],
  ['interview_reminder', 'Interview Reminder'], ['selected', 'Selected'],
  ['onboarding_invite', 'Onboarding Invite'], ['offer_released', 'Offer Released'],
  ['offer_reminder', 'Offer Reminder'], ['joining_reminder', 'Joining Reminder'],
]
const commIcon = (channel) => ({ Email: Mail, WhatsApp: MessageCircle, Reminder: Bell, Notification: Bell }[channel] || MessageSquare)
const commColor = (channel) => ({ Email: '#a78bfa', WhatsApp: '#25D366', Reminder: '#f59e0b', Notification: '#60a5fa' }[channel] || '#94a3b8')
const commFmt = ts => ts ? new Date(ts).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'

// SPK-1 delivery states — Sent / Pending / Failed / Delivered each read distinctly,
// so a queued message is never shown as if it arrived.
const DELIVERY_STYLE = {
  Delivered:           { c: '#10b981', bg: 'rgba(16,185,129,0.12)', mark: '✓✓' },
  Sent:                { c: '#0ea5e9', bg: 'rgba(14,165,233,0.12)', mark: '✓'  },
  Read:                { c: '#10b981', bg: 'rgba(16,185,129,0.12)', mark: '✓✓' },
  Pending:             { c: '#f59e0b', bg: 'rgba(245,158,11,0.12)', mark: '◐'  },
  Queued:              { c: '#f59e0b', bg: 'rgba(245,158,11,0.12)', mark: '◐'  },
  Scheduled:           { c: '#a78bfa', bg: 'rgba(124,58,237,0.12)', mark: '⏱'  },
  'Reminder Scheduled': { c: '#a78bfa', bg: 'rgba(124,58,237,0.12)', mark: '⏱'  },
  'Reminder Sent':      { c: '#0ea5e9', bg: 'rgba(14,165,233,0.12)', mark: '🔔' },
  Failed:              { c: '#f87171', bg: 'rgba(239,68,68,0.1)',   mark: '✕'  },
}
const deliveryStyle = (d) => DELIVERY_STYLE[d] || { c: 'var(--text-muted)', bg: 'var(--bg-card,transparent)', mark: '•' }

function CommTab({ candidateId, canManage, showToast, onSent }) {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [event, setEvent] = useState('interview_scheduled')
  const [remType, setRemType] = useState('interview')
  const [remDays, setRemDays] = useState(1)
  const [remBusy, setRemBusy] = useState(false)
  const [retrying, setRetrying] = useState(null)   // failed-comm retry key (SPK-1)
  const [modal, setModal] = useState(null) // { channel, event, subject, body, loading, sending }

  const load = useCallback(() => {
    setLoading(true)
    hrApi.candidates.communications(candidateId).then(setData).catch(() => {}).finally(() => setLoading(false))
  }, [candidateId])
  useEffect(() => { load() }, [load])

  const openSend = async (channel) => {
    setModal({ channel, event, subject: '', body: '', loading: true, sending: false })
    try {
      const p = await hrApi.candidates.commPreview(candidateId, channel, event)
      setModal(m => m && ({ ...m, subject: p.subject || '', body: p.body || '', loading: false }))
    } catch { setModal(m => m && ({ ...m, loading: false })); showToast?.('Failed to load template', 'error') }
  }
  const doSend = async () => {
    setModal(m => ({ ...m, sending: true }))
    try {
      const r = await hrApi.candidates.communicate(candidateId, { channel: modal.channel, event: modal.event, subject: modal.subject, body: modal.body })
      setModal(null); showToast?.(`${modal.channel === 'email' ? 'Email' : 'WhatsApp'} ${r.status?.toLowerCase() || 'sent'}`)
      load(); onSent?.()
    } catch (e) { setModal(m => ({ ...m, sending: false })); showToast?.(e.response?.data?.message || 'Failed to send', 'error') }
  }
  // Retry a failed send (SPK-1). Re-renders the template for the original
  // channel/event and posts through the existing communicate endpoint — no new
  // API and no duplicated send logic.
  const retry = async (h) => {
    if (!h.event_key || !h.channel_key) return
    const key = h.event_key + h.at
    setRetrying(key)
    try {
      const p = await hrApi.candidates.commPreview(candidateId, h.channel_key, h.event_key)
      const r = await hrApi.candidates.communicate(candidateId, {
        channel: h.channel_key, event: h.event_key, subject: p.subject || '', body: p.body || '',
      })
      const st = (r.status || '').toLowerCase()
      // The endpoint reports the outcome; a retry that fails again must say so.
      if (st === 'failed' || st.includes('no email') || st.includes('no number')) {
        showToast?.(`Retry failed: ${r.status}`, 'error')
      } else {
        showToast?.(`Retry ${st || 'sent'}`)
      }
      load(); onSent?.()
    } catch (e) { showToast?.(e.response?.data?.message || 'Retry failed', 'error') }
    finally { setRetrying(null) }
  }

  const scheduleReminder = async () => {
    setRemBusy(true)
    try { await hrApi.candidates.scheduleReminder(candidateId, remType, Number(remDays)); showToast?.('Reminder scheduled'); load(); onSent?.() }
    catch (e) { showToast?.(e.response?.data?.message || 'Failed', 'error') }
    finally { setRemBusy(false) }
  }

  if (loading || !data) return <div className="card-3d text-center py-12" style={{ color:'var(--text-muted)' }}>Loading communication history…</div>
  const d = data.delivery || {}

  return (
    <div className="space-y-5">
      {/* Delivery status */}
      <div className="card-3d" style={{ padding:'18px' }}>
        <h3 className="font-bold text-sm mb-3 flex items-center gap-2" style={{ color:'var(--text-h)' }}><MessageSquare size={14} style={{ color:'#a78bfa' }}/> Delivery Status</h3>
        <div className="flex flex-wrap gap-2">
          {[['Email', d.email, 'Sent'], ['WhatsApp', d.whatsapp, 'Sent'], ['Meeting Link', d.meeting_link, 'Generated'], ['Reminder', d.reminder, 'Scheduled']].map(([k, ok, verb]) => (
            <span key={k} className="text-[11px] font-bold px-2.5 py-1 rounded-lg" style={{ background: ok ? 'rgba(16,185,129,0.12)' : 'var(--bg-input)', color: ok ? '#10b981' : 'var(--text-muted)', border:`1px solid ${ok ? 'rgba(16,185,129,0.25)' : 'var(--border)'}` }}>{ok ? '✓' : '○'} {k} {ok ? verb : '—'}</span>
          ))}
        </div>
      </div>

      {canManage && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {/* Send communication */}
          <div className="card-3d" style={{ padding:'18px' }}>
            <h3 className="font-bold text-sm mb-3 flex items-center gap-2" style={{ color:'var(--text-h)' }}><Send size={14} style={{ color:'#a78bfa' }}/> Send Communication</h3>
            <label className="label">Stage / Event</label>
            <select className="input-3d text-sm mb-3" value={event} onChange={e => setEvent(e.target.value)}>
              {COMM_EVENTS.map(([k, l]) => <option key={k} value={k}>{l}</option>)}
            </select>
            <div className="flex gap-2">
              <button onClick={() => openSend('email')} className="flex-1 flex items-center justify-center gap-1.5 py-2 rounded-xl text-xs font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)' }}><Mail size={13}/> Email Preview</button>
              <button onClick={() => openSend('whatsapp')} className="flex-1 flex items-center justify-center gap-1.5 py-2 rounded-xl text-xs font-bold text-white" style={{ background:'linear-gradient(135deg,#25D366,#128C7E)' }}><MessageCircle size={13}/> WhatsApp Preview</button>
            </div>
          </div>

          {/* Reminder automation */}
          <div className="card-3d" style={{ padding:'18px' }}>
            <h3 className="font-bold text-sm mb-3 flex items-center gap-2" style={{ color:'var(--text-h)' }}><Bell size={14} style={{ color:'#f59e0b' }}/> Reminder Automation</h3>
            <div className="grid grid-cols-2 gap-2 mb-3">
              <div><label className="label">Type</label><select className="input-3d text-sm" value={remType} onChange={e => setRemType(e.target.value)}>{['interview','offer','joining'].map(t => <option key={t} value={t}>{t.slice(0,1).toUpperCase()+t.slice(1)}</option>)}</select></div>
              <div><label className="label">Before</label><select className="input-3d text-sm" value={remDays} onChange={e => setRemDays(e.target.value)}>{[['1','1 Day'],['3','3 Days'],['7','7 Days'],['0','Custom']].map(([v,l]) => <option key={v} value={v}>{l}</option>)}</select></div>
            </div>
            {String(remDays) === '0' && <input type="number" min="1" className="input-3d text-sm mb-3" placeholder="Days before" onChange={e => setRemDays(e.target.value)} />}
            <button onClick={scheduleReminder} disabled={remBusy} className="w-full py-2 rounded-xl text-xs font-bold text-white flex items-center justify-center gap-2" style={{ background:'linear-gradient(135deg,#f59e0b,#d97706)', opacity:remBusy?0.7:1 }}>{remBusy && <Loader2 size={13} className="animate-spin"/>}Schedule Reminder</button>
            <p className="text-[10px] mt-2" style={{ color:'var(--text-muted)' }}>Interview reminders reuse the existing scheduler; offer/joining reminders are logged to the timeline.</p>
          </div>
        </div>
      )}

      {/* History */}
      <div className="card-3d" style={{ padding:'20px' }}>
        <h3 className="font-bold text-sm mb-4 flex items-center gap-2" style={{ color:'var(--text-h)' }}><History size={14} style={{ color:'#a78bfa' }}/> Communication History</h3>
        {(!data.history || data.history.length === 0) ? <p className="text-xs" style={{ color:'var(--text-muted)' }}>No communication yet.</p> : (
          <div className="space-y-2">
            {data.history.map((h, i) => {
              const Ico = commIcon(h.channel); const col = commColor(h.channel)
              return (
                <div key={i} className="flex items-start gap-3 px-3 py-2.5 rounded-xl" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                  <div className="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5" style={{ background:`${col}20` }}><Ico size={13} style={{ color:col }}/></div>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                      <span className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{h.event}</span>
                      <span className="text-[9px] font-bold px-1.5 py-0.5 rounded" style={{ background:`${col}18`, color:col }}>{h.channel}</span>
                      {(() => { const ds = deliveryStyle(h.delivery); return (
                        <span className="text-[9px] font-bold px-1.5 py-0.5 rounded" style={{ background: ds.bg, color: ds.c }}>{ds.mark} {h.delivery}</span>
                      ) })()}
                      {/* Open tracking is not implemented — say so rather than
                          rendering an "unopened" state we cannot actually know. */}
                      <span className="text-[9px] font-semibold px-1.5 py-0.5 rounded" title="No open-tracking is configured for this channel"
                        style={{ background:'var(--bg-card,transparent)', color:'var(--text-muted)', border:'1px dashed var(--border)' }}>
                        {h.opened == null ? 'Opened: Tracking Not Available' : `Opened ${commFmt(h.opened)}`}
                      </span>
                      {h.can_retry && (
                        <button onClick={() => retry(h)} disabled={retrying === h.event_key + h.at}
                          className="text-[9px] font-bold px-2 py-0.5 rounded flex items-center gap-1"
                          style={{ background:'rgba(124,58,237,0.12)', color:'#a78bfa', border:'1px solid rgba(124,58,237,0.3)', cursor:'pointer' }}>
                          {retrying === h.event_key + h.at ? <Loader2 size={9} className="animate-spin"/> : <RefreshCw size={9}/>} Retry
                        </button>
                      )}
                    </div>
                    <p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}>{commFmt(h.at)} · by {h.sent_by}</p>
                  </div>
                </div>
              )
            })}
          </div>
        )}
      </div>

      {/* Preview + edit + send modal */}
      {modal && (
        <div className="modal-backdrop" onClick={() => !modal.sending && setModal(null)}>
          <div className="modal-box max-w-lg" onClick={e => e.stopPropagation()} style={{ maxHeight:'90vh', overflowY:'auto' }}>
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-black text-lg flex items-center gap-2" style={{ color:'var(--text-h)' }}>{modal.channel === 'email' ? <Mail size={18} style={{ color:'#a78bfa' }}/> : <MessageCircle size={18} style={{ color:'#25D366' }}/>} {modal.channel === 'email' ? 'Email' : 'WhatsApp'} Preview</h2>
              <button onClick={() => setModal(null)} style={{ color:'var(--text-muted)' }}><XCircle size={18}/></button>
            </div>
            {modal.loading ? <p className="text-sm py-6 text-center" style={{ color:'var(--text-muted)' }}>Loading template…</p> : (
              <div className="space-y-3">
                {modal.channel === 'email' && <div><label className="label">Subject</label><input className="input-3d text-sm" value={modal.subject} onChange={e => setModal(m => ({ ...m, subject: e.target.value }))}/></div>}
                <div><label className="label">Message (variables already replaced — edit freely)</label><textarea rows={9} className="input-3d text-sm resize-none" value={modal.body} onChange={e => setModal(m => ({ ...m, body: e.target.value }))}/></div>
                <div className="flex gap-3">
                  <button onClick={() => setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
                  <button onClick={doSend} disabled={modal.sending || !modal.body.trim()} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity:(modal.sending||!modal.body.trim())?0.6:1 }}>{modal.sending && <Loader2 size={14} className="animate-spin"/>}Send {modal.channel === 'email' ? 'Email' : 'WhatsApp'}</button>
                </div>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  )
}

// ── Expandable stage details (SPK-1) ─────────────────────────────────────────
// Renders from data the profile already loaded (interview_rounds / offer /
// onboarding / documents) — no extra request when a stage is expanded.
const DetailRow = ({ k, v }) => (v === null || v === undefined || v === '') ? null : (
  <div className="flex gap-2 text-[11px] py-0.5">
    <span style={{ color:'var(--text-muted)', minWidth:104, fontWeight:600 }}>{k}</span>
    <span style={{ color:'var(--text-h)', flex:1, wordBreak:'break-word' }}>{v}</span>
  </div>
)

// Which document sources belong to which stage, so each stage shows only its own
// attachments instead of the whole document set.
const STAGE_DOC_SCOPE = {
  applied:       { cand: ['resume', 'other'], onb: [] },
  ai_screening:  { cand: ['resume'], onb: [] },
  onb_submitted: { cand: [], onb: '*' },
  onb_verified:  { cand: [], onb: '*' },
  offer_generated: { cand: ['offer'], onb: [] },
  offer_accepted:  { cand: ['offer'], onb: [] },
}

function StageDetails({ stage, candidate, rounds }) {
  const fmt  = (d) => d ? new Date(d).toLocaleString('en-IN', { dateStyle:'medium', timeStyle:'short' }) : null
  const fmtD = (d) => d ? new Date(d).toLocaleDateString('en-IN', { day:'2-digit', month:'short', year:'numeric' }) : null

  // ── Interview round ──
  if (stage.key.startsWith('interview_')) {
    const id = Number(stage.key.replace('interview_', ''))
    const r = (rounds || []).find(x => Number(x.id) === id)
    if (!r) return <Empty>Round details are no longer available.</Empty>
    const panel = Array.isArray(r.interviewers) && r.interviewers.length
      ? r.interviewers.map(p => p.name).filter(Boolean).join(', ')
      : r.interviewer_name
    return (
      <div>
        <DetailRow k="Round" v={r.round_name} />
        <DetailRow k="Type" v={r.mode === 'offline' ? `Offline${r.venue ? ` · ${r.venue}` : ''}` : 'Online'} />
        <DetailRow k="Panel" v={panel} />
        <DetailRow k="Date" v={fmt(r.scheduled_at)} />
        <DetailRow k="Status" v={r.status} />
        <DetailRow k="Decision" v={r.result && r.result !== 'Pending' ? r.result : null} />
        <DetailRow k="Recommendation" v={r.recommendation} />
        <DetailRow k="Rating" v={r.rating ? `${r.rating} / 5` : null} />
        <DetailRow k="Overall Score" v={r.overall_score != null ? `${r.overall_score} / 10` : null} />
        <DetailRow k="Strengths" v={r.strengths} />
        <DetailRow k="Concerns" v={r.concerns} />
        <DetailRow k="Feedback" v={r.notes} />
        {!r.notes && !r.strengths && r.status !== 'Completed' && <Empty>Feedback is recorded once the round is completed.</Empty>}
      </div>
    )
  }

  // ── Offer ──
  if (stage.key.startsWith('offer_')) {
    const o = candidate.offer
    if (!o) return <Empty>No offer has been generated yet.</Empty>
    return (
      <div>
        <DetailRow k="Offer Number" v={`OFR-${String(o.id).padStart(4, '0')}`} />
        <DetailRow k="Position" v={o.position} />
        <DetailRow k="Salary (CTC)" v={formatCTC(o.offered_ctc)} />
        <DetailRow k="Joining Date" v={fmtD(o.joining_date)} />
        <DetailRow k="Status" v={o.status} />
        <DetailRow k="Sent" v={fmt(o.sent_at)} />
        <DetailRow k="Accepted" v={fmt(o.accepted_at)} />
        {/* Digital signature — captured at acceptance on the public offer portal */}
        <DetailRow k="Signed By" v={o.accepted_name} />
        <DetailRow k="Signature" v={o.accepted_signature ? 'Captured' : (o.accepted_at ? 'Typed acceptance' : null)} />
        <DetailRow k="Accepted From" v={o.accepted_ip ? `${o.accepted_ip}${o.accepted_device ? ` · ${o.accepted_device}` : ''}` : null} />
      </div>
    )
  }

  // ── Onboarding ──
  if (stage.key.startsWith('onb_')) {
    const ob = candidate.onboarding
    if (!ob) return <Empty>Onboarding has not started yet.</Empty>
    // Derived from verified documents + offer status — the same computation the
    // Onboarding page performs. The stored document_checklist column is NOT used:
    // it lags reality and reads 0% on onboardings that are fully approved.
    const cl = computeOnboardingChecklist(ob, candidate.offer)
    const checklist = ONBOARDING_DOC_ITEMS.map(k => ({ key: k, label: ONBOARDING_DOC_LABELS[k], ok: !!cl[k] }))
    const verified = checklist.filter(d => d.ok).length
    const pct = checklist.length ? Math.round(verified / checklist.length * 100) : 0
    return (
      <div>
        <DetailRow k="Status" v={ob.status} />
        <DetailRow k="Verification" v={ob.verification_status} />
        <DetailRow k="Invited" v={fmt(ob.invited_at)} />
        <DetailRow k="Submitted" v={fmt(ob.submitted_at)} />
        <DetailRow k="Verified" v={fmt(ob.verified_at)} />
        <DetailRow k="Joining Date" v={fmtD(ob.joining_date)} />
        <DetailRow k="Completion" v={`${pct}%`} />
        <div className="h-1.5 rounded-full mt-1.5 mb-2" style={{ background:'var(--bg-card)' }}>
          <div className="h-full rounded-full" style={{ width:`${pct}%`, background:'linear-gradient(90deg,#10b981,#34d399)' }}/>
        </div>
        {checklist.length > 0 && (
          <div className="mt-1">
            <p className="text-[10px] font-bold uppercase mb-1" style={{ color:'var(--text-muted)' }}>Checklist</p>
            {checklist.map(d => (
              <div key={d.key} className="flex items-center gap-1.5 text-[11px] py-0.5">
                <span style={{ color: d.ok ? '#10b981' : 'var(--text-muted)' }}>{d.ok ? '✓' : '○'}</span>
                <span style={{ color: d.ok ? 'var(--text-h)' : 'var(--text-muted)' }}>{d.label}</span>
              </div>
            ))}
          </div>
        )}
      </div>
    )
  }

  // ── Application / AI screening ──
  if (stage.key === 'applied' || stage.key === 'ai_screening') {
    // candidateScore() applies the same engine-stamp guard as the lists, so this
    // panel cannot disagree with the score shown at the top of the page. `Basis` now
    // reads the engine's summary — recommendation_reason is not part of the contract.
    const ai = candidateScore(candidate)
    const b  = candidate.ai_breakdown || {}
    return (
      <div>
        <DetailRow k="Source" v={candidate.source} />
        <DetailRow k="Applied" v={fmt(candidate.applied_at || candidate.created_at)} />
        <DetailRow k="AI Score" v={ai.isScored ? ai.display : null} />
        <DetailRow k="Recommendation" v={ai.recommendation} />
        <DetailRow k="Confidence" v={ai.confidence != null ? `${ai.confidence}%` : null} />
        <DetailRow k="Basis" v={b.summary} />
        {!ai.isScreened && <Empty>This candidate has not been scored yet.</Empty>}
      </div>
    )
  }

  return <Empty>No further detail recorded for this stage.</Empty>
}

const Empty = ({ children }) => <p className="text-[11px]" style={{ color:'var(--text-muted)', margin:0 }}>{children}</p>

// ── Per-stage Attachments · Notes · Activity (SPK-1) ─────────────────────────
// Reuses the documents / notes / audit_logs the profile already loaded and
// filters them to this stage. No duplicate storage, no extra request.
function StageContext({ stage, candidate, documents = [], notes = [] }) {
  const scope = STAGE_DOC_SCOPE[stage.key] || null
  const isInterview = stage.key.startsWith('interview_')

  const docs = useMemo(() => {
    if (!scope) return []
    const out = []
    if (scope.cand?.length) out.push(...(documents || []).filter(d => scope.cand.includes(d.type)).map(d => ({ id: `c${d.id}`, name: d.original_name, at: d.created_at })))
    if (scope.onb === '*') out.push(...((candidate.onboarding?.documents) || []).map(d => ({ id: `o${d.id}`, name: d.original_name, at: d.created_at, status: d.status })))
    return out
  }, [scope, documents, candidate])

  // Audit entries belonging to this stage — matched on the action text the
  // recorders already write (e.g. "Interview Scheduled: Technical Round").
  const activity = useMemo(() => {
    const all = candidate.audit_logs || []
    const m = {
      applied: /applied|application/i, ai_screening: /ai |screen/i,
      selected: /decision|selected/i,
      onb_invited: /onboarding (invitation|invite|started)/i,
      onb_submitted: /submitted|document uploaded/i,
      onb_verified: /document (verified|rejected)|verification/i,
      offer_generated: /offer (generated|created)/i,
      offer_sent: /offer sent/i,
      offer_accepted: /offer accepted/i,
      employee_created: /employee (created|record)|joining confirmed/i,
      mr_created: /manpower/i, mr_approved: /approv/i, job_posted: /job (posted|published)/i,
    }
    const re = isInterview ? /interview/i : m[stage.key]
    if (!re) return []
    return all.filter(a => re.test(a.action || '')).slice(0, 5)
  }, [candidate, stage, isInterview])

  // Internal notes are not stage-scoped in the schema; show them on the stage
  // where HR records them (screening/selection) rather than inventing a link.
  const stageNotes = ['ai_screening', 'selected'].includes(stage.key) ? (notes || []).slice(0, 3) : []

  if (!docs.length && !activity.length && !stageNotes.length) return null
  const fmt = (d) => d ? new Date(d).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' }) : '—'

  return (
    <div className="mt-2 pt-2 space-y-2" style={{ borderTop: '1px dashed var(--border)' }}>
      {docs.length > 0 && (
        <div>
          <p className="text-[9px] font-bold uppercase mb-1" style={{ color: 'var(--text-muted)' }}>Attachments</p>
          {docs.map(d => (
            <div key={d.id} className="flex items-center gap-1.5 text-[10.5px] py-0.5">
              <FileText size={10} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
              <span className="truncate" style={{ color: 'var(--text-h)' }}>{d.name}</span>
              {d.status && <span style={{ color: d.status === 'Verified' ? '#10b981' : 'var(--text-muted)' }}>· {d.status}</span>}
            </div>
          ))}
        </div>
      )}
      {stageNotes.length > 0 && (
        <div>
          <p className="text-[9px] font-bold uppercase mb-1" style={{ color: 'var(--text-muted)' }}>Internal Notes</p>
          {stageNotes.map(n => (
            <p key={n.id} className="text-[10.5px] py-0.5" style={{ color: 'var(--text-muted)' }}>
              <span style={{ color: 'var(--text-h)' }}>{n.body}</span>
              {` — ${n.user?.name || 'HR Team'}`}
            </p>
          ))}
        </div>
      )}
      {activity.length > 0 && (
        <div>
          <p className="text-[9px] font-bold uppercase mb-1" style={{ color: 'var(--text-muted)' }}>Activity</p>
          {activity.map(a => (
            <p key={a.id} className="text-[10.5px] py-0.5" style={{ color: 'var(--text-muted)' }}>
              <span style={{ color: 'var(--text-h)' }}>{a.action}</span> · {fmt(a.created_at)}{a.actor_name ? ` · by ${a.actor_name}` : ''}
            </p>
          ))}
        </div>
      )}
    </div>
  )
}

// ── Interview Next Action (SPK-1) ────────────────────────────────────────────
// Derived from the round's own status/result plus what follows it in the SPK-1
// sequence — no new column, no stored state to drift out of sync.
const ROUND_SEQ = ['HR Round', 'Technical Round', 'Manager Round', 'Client Round', 'Final Round']
const canonRound = (r) => {
  if (!r) return r
  if (ROUND_SEQ.includes(r)) return r
  const s = String(r).toLowerCase()
  if (s.includes('final')) return 'Final Round'
  if (s.includes('client')) return 'Client Round'
  if (s.includes('manager') || s.includes('managerial')) return 'Manager Round'
  if (s.includes('technical') || s.includes('tech')) return 'Technical Round'
  if (s.includes('hr') || s.includes('screening')) return 'HR Round'
  return r
}
const nextRoundAfter = (name) => {
  const i = ROUND_SEQ.indexOf(canonRound(name))
  return (i >= 0 && i < ROUND_SEQ.length - 1) ? ROUND_SEQ[i + 1] : null
}
const interviewNextAction = (r, candidate) => {
  if (!r) return null
  if (r.status === 'Cancelled') return { label: 'Cancelled', tone: 'muted' }
  if (r.status !== 'Completed') {
    return r.mode === 'offline' || r.scheduled_at
      ? { label: 'Waiting Candidate', tone: 'warn' }
      : { label: 'Scheduled', tone: 'warn' }
  }
  // Completed → the decision drives what happens next.
  if (r.result === 'Failed')   return { label: 'Reject Candidate', tone: 'bad' }
  if (r.result === 'On Hold')  return { label: 'Hold', tone: 'warn' }
  if (candidate?.offer)        return { label: 'Completed', tone: 'good' }
  if (candidate?.final_decision === 'Selected') return { label: 'Send Offer', tone: 'good' }
  if (r.result === 'Passed' || r.result === 'Next Round') {
    const next = nextRoundAfter(r.round_name)
    if (next === 'Client Round') return { label: 'Waiting Client', tone: 'warn' }
    if (next) return { label: `Schedule ${next.replace(' Round', '')} Round`, tone: 'info' }
    return { label: 'Send Offer', tone: 'good' }
  }
  return { label: 'Completed', tone: 'good' }
}
const TONE = {
  good: { c: '#10b981', bg: 'rgba(16,185,129,0.12)' },
  info: { c: '#60a5fa', bg: 'rgba(96,165,250,0.12)' },
  warn: { c: '#fbbf24', bg: 'rgba(245,158,11,0.12)' },
  bad:  { c: '#f87171', bg: 'rgba(239,68,68,0.1)'   },
  muted:{ c: 'var(--text-muted)', bg: 'var(--bg-input)' },
}

// ── Employee Status (SPK-1) — reuses the hr_employees record ─────────────────
function EmployeeStatus({ employee, navigate }) {
  if (!employee) return null
  const st = employee.status === 'Active' ? { c: '#10b981', bg: 'rgba(16,185,129,0.12)' }
    : employee.status === 'Probation' ? { c: '#fbbf24', bg: 'rgba(245,158,11,0.12)' }
    : { c: 'var(--text-muted)', bg: 'var(--bg-input)' }
  const fmtD = (d) => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
  return (
    <div className="card-3d" style={{ padding: '18px' }}>
      <div className="flex items-center justify-between mb-3">
        <h3 className="font-bold text-sm flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
          <UserCircle2 size={14} style={{ color: '#10b981' }} /> Employee Status
        </h3>
        <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background: st.bg, color: st.c }}>{employee.status || '—'}</span>
      </div>
      <div className="grid grid-cols-2 gap-x-4 gap-y-2">
        {[
          ['Employee ID', employee.employee_code],
          ['Department', employee.department],
          ['Designation', employee.designation],
          ['Reporting Manager', employee.reporting_manager_name],
          ['Joining Date', fmtD(employee.joining_date)],
          ['Employment Status', employee.status],
          ['Confirmation', employee.confirmation_date ? `Confirmed ${fmtD(employee.confirmation_date)}`
            : employee.probation_end_date ? `Probation ends ${fmtD(employee.probation_end_date)}` : '—'],
        ].map(([k, v]) => (
          <div key={k}>
            <p className="text-[9px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>{k}</p>
            <p className="text-xs font-semibold mt-0.5" style={{ color: 'var(--text-h)' }}>{v || '—'}</p>
          </div>
        ))}
      </div>
      <button onClick={() => navigate(`/app/hr/employees/${employee.id}`)}
        className="w-full mt-4 py-2 rounded-xl text-xs font-bold text-white flex items-center justify-center gap-2"
        style={{ background: 'linear-gradient(135deg,#10b981,#059669)' }}>
        <ExternalLink size={13} /> Open Employee Profile
      </button>
    </div>
  )
}

// ── Documents Timeline (SPK-1) ───────────────────────────────────────────────
// One ordered timeline across every document source the candidate already has:
// candidate uploads, onboarding uploads and the generated offer letter. Nothing
// is copied or re-stored — each row points at its existing record.
const DOC_STAGES = [
  { key: 'resume',     label: 'Resume' },
  { key: 'certs',      label: 'Certificates' },
  { key: 'govt_id',    label: 'Government IDs' },
  { key: 'interview',  label: 'Interview Documents' },
  { key: 'offer',      label: 'Offer Letter' },
  { key: 'signed',     label: 'Signed Offer' },
  { key: 'joining',    label: 'Joining Documents' },
  { key: 'employee',   label: 'Employee Documents' },
]
// Onboarding document type → timeline stage. Keys mirror ONBOARDING_DOCS in
// @/config/onboardingDocs — every key there is mapped, so nothing silently
// falls through to the default.
const ONB_TYPE_STAGE = {
  resume: 'resume',
  aadhaar: 'govt_id', pan: 'govt_id', address_proof: 'govt_id',
  educational_certificate: 'certs', experience_document: 'certs',
  photo: 'joining', medical: 'joining', vaccination: 'joining',
  cancelled_cheque: 'joining', other: 'joining',
  company_document: 'employee',
}
// Candidate-side type → stage. Keys mirror DOCUMENT_TYPES in the HR constants.
const CAND_TYPE_STAGE = { resume: 'resume', certificate: 'certs', id_proof: 'govt_id', offer: 'offer', other: 'interview' }

const DOC_ST_STYLE = (s) => s === 'Verified' ? { c: '#10b981', bg: 'rgba(16,185,129,0.12)' }
  : s === 'Rejected' ? { c: '#f87171', bg: 'rgba(239,68,68,0.1)' }
  : s === 'Generated' || s === 'Accepted' ? { c: '#a78bfa', bg: 'rgba(124,58,237,0.12)' }
  : { c: '#fbbf24', bg: 'rgba(245,158,11,0.12)' }

function DocumentsTimeline({ candidate, documents, onView, onDownload }) {
  const rows = useMemo(() => {
    const out = []
    // Who verified a document — recorded on the audit trail (metadata.document_id),
    // the only place this is captured.
    const verifierOf = {}
    ;(candidate.audit_logs || []).forEach(a => {
      const id = a.metadata?.document_id
      if (id && /^Document (Verified|Rejected)/.test(a.action || '')) {
        if (!verifierOf[id]) verifierOf[id] = a.actor_name || a.actor?.name || null
      }
    })

    // 1) Candidate-side uploads (resume + any documents tab uploads)
    ;(documents || []).forEach(d => out.push({
      id: `c${d.id}`, stage: CAND_TYPE_STAGE[d.type] || 'interview',
      name: d.original_name, at: d.created_at,
      by: d.uploader?.name || 'Candidate', status: 'Uploaded',
      verifiedBy: null, source: 'candidate', raw: d,
    }))
    if (candidate.resume_path && !(documents || []).some(d => d.type === 'resume')) {
      out.push({ id: 'resume', stage: 'resume', name: String(candidate.resume_path).split('/').pop(),
        at: candidate.applied_at || candidate.created_at, by: candidate.source || 'Candidate',
        status: 'Uploaded', verifiedBy: null, source: 'resume', raw: null })
    }

    // 2) Onboarding uploads — these carry a real verification status
    ;(candidate.onboarding?.documents || []).forEach(d => out.push({
      id: `o${d.id}`, stage: ONB_TYPE_STAGE[d.type] || 'joining',
      name: d.original_name, at: d.created_at, by: 'Candidate',
      status: d.status || (d.verified ? 'Verified' : 'Pending'),
      verifiedBy: verifierOf[d.id] || null, source: 'onboarding', raw: d,
    }))

    // 3) The generated offer letter + its signed acceptance
    const o = candidate.offer
    if (o?.letter_path || o?.generated_at) {
      out.push({ id: `offer${o.id}`, stage: 'offer', name: `Offer Letter · OFR-${String(o.id).padStart(4, '0')}`,
        at: o.generated_at || o.created_at, by: 'HR', status: 'Generated', verifiedBy: null, source: 'offer', raw: o })
    }
    if (o?.accepted_at) {
      out.push({ id: `signed${o.id}`, stage: 'signed', name: `Signed Offer · ${o.accepted_name || candidate.name}`,
        at: o.accepted_at, by: o.accepted_name || candidate.name, status: 'Accepted', verifiedBy: null, source: 'signed', raw: o })
    }
    return out
  }, [candidate, documents])

  const byStage = Object.fromEntries(DOC_STAGES.map(s => [s.key, rows.filter(r => r.stage === s.key)]))
  const fmt = (d) => d ? new Date(d).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' }) : '—'

  if (!rows.length) return <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No documents on file for this candidate yet.</p>

  return (
    <div className="space-y-2">
      {DOC_STAGES.map((s, si) => {
        const list = byStage[s.key] || []
        const done = list.length > 0
        return (
          <div key={s.key} className="flex gap-3">
            {/* rail */}
            <div className="flex flex-col items-center flex-shrink-0" style={{ width: 18 }}>
              <div className="rounded-full flex items-center justify-center" style={{ width: 16, height: 16, background: done ? 'rgba(16,185,129,0.15)' : 'var(--bg-input)', border: `1px solid ${done ? '#10b981' : 'var(--border)'}` }}>
                {done && <CheckCircle size={9} style={{ color: '#10b981' }} />}
              </div>
              {si < DOC_STAGES.length - 1 && <div style={{ width: 1, flex: 1, minHeight: 14, background: 'var(--border)' }} />}
            </div>
            <div className="flex-1 min-w-0 pb-2">
              <div className="flex items-center gap-2">
                <p className="text-xs font-bold" style={{ color: done ? 'var(--text-h)' : 'var(--text-muted)' }}>{s.label}</p>
                {done && <span className="text-[9px] font-bold px-1.5 py-0.5 rounded" style={{ background: 'rgba(124,58,237,0.12)', color: '#a78bfa' }}>{list.length}</span>}
              </div>
              {!done && <p className="text-[10px] mt-0.5" style={{ color: 'var(--text-muted)', opacity: 0.7 }}>Not uploaded</p>}
              <div className="space-y-1.5 mt-1.5">
                {list.map(r => {
                  const st = DOC_ST_STYLE(r.status)
                  const canFile = r.source === 'onboarding' || r.source === 'candidate'
                  return (
                    <div key={r.id} className="flex items-center gap-2 px-2.5 py-2 rounded-lg" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                      <FileText size={12} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
                      <div className="flex-1 min-w-0">
                        <p className="text-[11px] font-semibold truncate" style={{ color: 'var(--text-h)' }}>{r.name}</p>
                        <p className="text-[9.5px]" style={{ color: 'var(--text-muted)' }}>
                          {fmt(r.at)} · by {r.by}
                          {r.verifiedBy && <span style={{ color: '#10b981' }}> · verified by {r.verifiedBy}</span>}
                          {!r.verifiedBy && r.status === 'Verified' && <span> · verifier not recorded</span>}
                        </p>
                      </div>
                      <span className="text-[9px] font-bold px-1.5 py-0.5 rounded flex-shrink-0" style={{ background: st.bg, color: st.c }}>{r.status}</span>
                      {canFile && (
                        <>
                          <button onClick={() => onView?.(r)} title="Preview" style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', padding: 2 }}><Eye size={12} /></button>
                          <button onClick={() => onDownload?.(r)} title="Download" style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)', padding: 2 }}><Download size={12} /></button>
                        </>
                      )}
                    </div>
                  )
                })}
              </div>
            </div>
          </div>
        )
      })}
    </div>
  )
}
