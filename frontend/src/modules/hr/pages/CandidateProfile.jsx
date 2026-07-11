import { useState, useEffect, useRef, useCallback } from 'react'
import { useTheme } from '@/context/ThemeContext'
import { useNavigate, useParams } from 'react-router-dom'
import {
  ArrowLeft, CheckCircle, XCircle, Clock, Mail, Phone, MessageCircle, Brain, Upload, Download,
  Trash2, FileText, Eye, History, StickyNote, FolderOpen, Activity as ActivityIcon, LayoutGrid,
  Send, UserCircle2, CalendarDays, IndianRupee, Briefcase, Hash, Loader2,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import AuditTimeline from '@/components/ui/AuditTimeline'
import CandidateQuickActions from '@/modules/hr/components/CandidateQuickActions'
import { formatCTC, DOCUMENT_TYPES, documentTypeLabel, aiBand, canManageHrQueue } from '@/modules/hr/constants'

const resultColor = r => r==='Passed'?{c:'#10b981',bg:'rgba(16,185,129,0.12)'}:r==='Pending'?{c:'#f59e0b',bg:'rgba(245,158,11,0.12)'}:{c:'#f87171',bg:'rgba(239,68,68,0.1)'}
const DECISION_COLORS = { Selected:{c:'#10b981',bg:'rgba(16,185,129,0.1)'}, Hold:{c:'#fbbf24',bg:'rgba(245,158,11,0.1)'}, Rejected:{c:'#f87171',bg:'rgba(239,68,68,0.1)'} }
const currentUser = () => { try { return JSON.parse(localStorage.getItem('crm_user') || 'null') } catch { return null } }
const fmtDateTime = ts => ts ? new Date(ts).toLocaleString('en-IN',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : ''
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—'

const ScoreBar = ({ label, value, color }) => (
  <div>
    <div className="flex justify-between mb-1">
      <span className="text-xs font-semibold" style={{ color:'var(--text-muted)' }}>{label}</span>
      <span className="text-xs font-black" style={{ color }}>{value}%</span>
    </div>
    <div className="h-2 rounded-full" style={{ background:'var(--bg-input)' }}>
      <div className="h-full rounded-full transition-all" style={{ width:`${value}%`, background:`linear-gradient(90deg,${color}80,${color})` }}/>
    </div>
  </div>
)

const Fact = ({ icon, label, value }) => value ? (
  <div className="flex items-center gap-1.5 text-xs" style={{ color:'var(--text-muted)' }}>
    {icon}<span style={{ color:'var(--text-h)', fontWeight:600 }}>{value}</span><span className="text-[10px]">{label}</span>
  </div>
) : null

export default function CandidateProfile() {
  const { isDark } = useTheme()
  const navigate   = useNavigate()
  const { id }     = useParams()

  const [candidate, setCandidate]           = useState(null)
  const [loading, setLoading]               = useState(true)
  const [toast, setToast]                   = useState(null)
  const [savingDecision, setSavingDecision] = useState(false)
  const [activeTab, setActiveTab]           = useState('overview')
  const [recruiters, setRecruiters]         = useState([])
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
  const fileInputRef = useRef(null)

  const user      = currentUser()
  const canManage = canManageHrQueue(user)
  const showToast = (msg, type='success') => { setToast({msg,type}); setTimeout(()=>setToast(null),3000) }

  const loadCandidate = useCallback(async () => {
    if (!id) { setCandidate(null); setLoading(false); return }
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
      setTimeout(() => setResumeProgress(0), 1000)
    } catch (e) {
      clearInterval(ticker); showToast(e.response?.data?.message || 'Upload failed','error'); setResumeProgress(0)
    } finally { setResumeUploading(false) }
  }, [candidate])

  const handleResumeDelete = async () => {
    if (!candidate?.id || candidate.id === 'demo') return
    if (!window.confirm('Delete this resume? This cannot be undone.')) return
    try { await hrApi.candidates.deleteResume(candidate.id); setCandidate(prev => ({...prev, resume_path: null})); setShowPreview(false); showToast('Resume deleted') }
    catch { showToast('Failed to delete resume','error') }
  }
  const onDrop = useCallback((e) => { e.preventDefault(); setDragOver(false); const file = e.dataTransfer.files?.[0]; if (file) handleResumeUpload(file) }, [handleResumeUpload])

  if (loading) return <div className="text-center py-20" style={{ color:'var(--text-muted)' }}>Loading…</div>

  if (!candidate) return (
    <div className="text-center py-20 space-y-4">
      <p style={{ color:'var(--text-muted)' }}>Candidate not found.</p>
      <button onClick={()=>navigate('/app/hr/candidates')} className="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>
        <ArrowLeft size={12}/> Back to Pipeline
      </button>
    </div>
  )

  const c = candidate
  const aiScore = c.ai_score || 0
  const band    = aiBand(aiScore)
  const aiBreak = c.ai_breakdown || {}
  const skills  = Array.isArray(c.skills) ? c.skills : []
  const rounds  = c.interview_rounds || []
  const ctcC = formatCTC(c.current_ctc), ctcE = formatCTC(c.expected_ctc)
  const isDemo = false

  const TABS = [
    { key:'overview',  label:'Overview',  icon:LayoutGrid },
    { key:'resume',    label:'Resume',    icon:FileText },
    { key:'timeline',  label:'Timeline',  icon:History },
    { key:'notes',     label:'Notes',     icon:StickyNote, count:notes.length },
    { key:'documents', label:'Documents', icon:FolderOpen, count:documents.length },
    { key:'activity',  label:'Activity',  icon:ActivityIcon, count:rounds.length },
  ]

  return (
    <div className="space-y-5 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl" style={{ background:toast.type==='success'?'linear-gradient(135deg,#10b981,#059669)':'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      {/* Back + quick actions */}
      <div className="flex items-center gap-3 flex-wrap">
        <button onClick={()=>navigate('/app/hr/candidates')} className="flex items-center gap-1.5 text-xs font-semibold px-3 py-2 rounded-xl transition-all" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>
          <ArrowLeft size={12}/> Back to Pipeline
        </button>
        <span className="text-xs" style={{ color:'var(--text-muted)' }}>Candidate Profile</span>
        {!isDemo && (
          <div className="ml-auto flex items-center gap-2">
            <span className="text-xs font-mono px-2.5 py-1 rounded-lg flex items-center gap-1" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><Hash size={11}/>CAND-{String(c.id).padStart(4,'0')}</span>
            <div className="p-1 rounded-lg" style={{ border:'1px solid var(--border)' }}>
              <CandidateQuickActions candidate={c} recruiters={recruiters} onChanged={applyChange} onToast={showToast} hideView/>
            </div>
          </div>
        )}
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
            <p className="text-3xl font-black" style={{ color:band.color }}>{aiScore}%</p>
            <p className="text-[10px] font-bold mt-0.5" style={{ color:band.color }}>AI Match</p>
          </div>
        </div>
        {/* Key facts */}
        <div className="flex items-center gap-x-5 gap-y-2 mt-4 pt-4 flex-wrap" style={{ borderTop:'1px solid var(--border)' }}>
          <Fact icon={<Briefcase size={12}/>}  label="applied for" value={c.job_posting?.title}/>
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
      {activeTab==='overview' && (
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
          <div className="lg:col-span-2 space-y-5">
            {skills.length>0 && (
              <div className="card-3d" style={{ padding:'20px' }}>
                <h3 className="font-bold text-sm mb-4" style={{ color:'var(--text-h)' }}>🎯 Skills</h3>
                <div className="flex flex-wrap gap-2">
                  {skills.map(s => <span key={s} className="px-3 py-1.5 rounded-xl text-xs font-semibold" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa', border:'1px solid rgba(124,58,237,0.2)' }}>{s}</span>)}
                </div>
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
            {/* AI Assessment */}
            <div className="card-3d" style={{ padding:'20px' }}>
              <div className="flex items-center gap-2 mb-4"><Brain size={16} style={{ color:'#a78bfa' }}/><h3 className="font-bold text-sm" style={{ color:'var(--text-h)' }}>AI Assessment</h3></div>
              <div className="text-center py-3 rounded-2xl mb-4" style={{ background:band.bg }}>
                <p className="text-3xl font-black" style={{ color:band.color }}>{aiScore}%</p>
                <p className="text-xs font-bold mt-1" style={{ color:band.color }}>{band.label}</p>
              </div>
              {Object.keys(aiBreak).length>0 && (
                <div className="space-y-3">
                  {aiBreak.skills_match   !== undefined && <ScoreBar label="Skills Match" value={aiBreak.skills_match} color="#a78bfa"/>}
                  {aiBreak.exp_match      !== undefined && <ScoreBar label="Experience"   value={aiBreak.exp_match}    color="#60a5fa"/>}
                  {aiBreak.location_match !== undefined && <ScoreBar label="Location Fit"  value={aiBreak.location_match} color="#34d399"/>}
                  {aiBreak.education      !== undefined && <ScoreBar label="Education"     value={aiBreak.education}    color="#fbbf24"/>}
                  {aiBreak.overall_fit    !== undefined && <ScoreBar label="Overall Fit"   value={aiBreak.overall_fit}  color="#f97316"/>}
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

      {/* ── RESUME ── */}
      {activeTab==='resume' && (
        <div className="card-3d" style={{ padding:'20px' }}>
          <div className="flex items-center justify-between mb-4">
            <h3 className="font-bold text-sm flex items-center gap-2" style={{ color:'var(--text-h)' }}><FileText size={14} style={{ color:'#a78bfa' }}/> Resume</h3>
            {c.resume_path && (
              <div className="flex items-center gap-2">
                <a href={`${hrApi.candidates.resumeUrl(c.id)}?token=${localStorage.getItem('crm_token')}`} target="_blank" rel="noreferrer" className="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-xl" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa', border:'1px solid rgba(124,58,237,0.2)' }}><Download size={11}/> Download</a>
                <button onClick={()=>setShowPreview(p=>!p)} className="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-xl" style={{ background:'rgba(59,130,246,0.1)', color:'#60a5fa', border:'1px solid rgba(59,130,246,0.2)' }}><Eye size={11}/> {showPreview?'Hide':'Preview'}</button>
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
      {activeTab==='timeline' && (
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
      )}

      {/* ── ACTIVITY (interview rounds) ── */}
      {activeTab==='activity' && (
        <div className="card-3d" style={{ padding:'22px' }}>
          <h3 className="font-bold text-sm mb-4 flex items-center gap-2" style={{ color:'var(--text-h)' }}><ActivityIcon size={14} style={{ color:'#a78bfa' }}/> Interview Rounds &amp; Activity</h3>
          {rounds.length===0 ? (
            <p className="text-xs" style={{ color:'var(--text-muted)' }}>No interviews scheduled yet. Use <b>Quick Actions → Schedule Interview</b>.</p>
          ) : (
            <div className="space-y-3">
              {rounds.map((r,i) => {
                const rc = resultColor(r.result)
                return (
                  <div key={r.id||i} className="p-3 rounded-xl" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                    <div className="flex items-center justify-between flex-wrap gap-2">
                      <div>
                        <div className="flex items-center gap-2 flex-wrap">
                          <p className="text-sm font-bold" style={{ color:'var(--text-h)' }}>{r.round_name}</p>
                          <span className="text-[9px] font-bold px-1.5 py-0.5 rounded-md" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>{r.mode === 'offline' ? '📍 Offline' : '🎥 Online'}</span>
                          {r.recommendation && <span className="text-[9px] font-bold px-1.5 py-0.5 rounded-md" style={{ background:'rgba(124,58,237,0.12)', color:'#a78bfa' }}>{r.recommendation}</span>}
                        </div>
                        <p className="text-xs mt-0.5" style={{ color:'var(--text-muted)' }}>Interviewer: {r.interviewer_name || '—'}{r.scheduled_at?` · ${fmtDateTime(r.scheduled_at)}`:''}</p>
                        {r.mode === 'offline' && r.venue && <p className="text-[11px] mt-0.5" style={{ color:'var(--text-muted)' }}>📍 {r.venue}</p>}
                        {r.rating ? <div className="flex gap-0.5 mt-1">{[1,2,3,4,5].map(n=><span key={n} style={{ color:n<=r.rating?'#fbbf24':'var(--border)' }}>★</span>)}</div> : null}
                      </div>
                      <div className="flex items-center gap-2">
                        {r.overall_score && <span className="text-xs font-black" style={{ color:'#a78bfa' }}>{r.overall_score}%</span>}
                        <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:rc.bg, color:rc.c }}>{r.result || r.status || 'Scheduled'}</span>
                      </div>
                    </div>
                    {(r.technical_score||r.communication_score||r.problem_solving_score) && (
                      <div className="flex gap-3 mt-2">
                        {r.technical_score      && <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>Tech: <b style={{ color:'var(--text-h)' }}>{r.technical_score}/10</b></span>}
                        {r.communication_score  && <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>Comm: <b style={{ color:'var(--text-h)' }}>{r.communication_score}/10</b></span>}
                        {r.problem_solving_score&& <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>Prob: <b style={{ color:'var(--text-h)' }}>{r.problem_solving_score}/10</b></span>}
                      </div>
                    )}
                    {r.meet_link && <a href={r.meet_link} target="_blank" rel="noreferrer" className="text-[11px] font-semibold mt-1.5 inline-block" style={{ color:'#60a5fa' }}>🔗 Join Meeting</a>}
                    {r.notes && r.notes !== '—' && <p className="text-xs mt-1.5 italic" style={{ color:'var(--text-muted)' }}>"{r.notes}"</p>}
                  </div>
                )
              })}
            </div>
          )}
        </div>
      )}
    </div>
  )
}
