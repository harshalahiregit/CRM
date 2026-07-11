import { useState, useEffect, useRef, useCallback } from 'react'
import { useTheme } from '@/context/ThemeContext'
import { useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, CheckCircle, XCircle, Clock, Mail, Phone, MessageCircle, Brain, Upload, Download, Trash2, FileText, Eye } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { getToken } from '@/lib/authStorage'

const resultColor = r => r==='Passed'?{c:'#10b981',bg:'rgba(16,185,129,0.12)'}:r==='Pending'?{c:'#f59e0b',bg:'rgba(245,158,11,0.12)'}:{c:'#f87171',bg:'rgba(239,68,68,0.1)'}

const AI_BAND = score => {
  if (score >= 90) return { label:'Highly Recommended', color:'#10b981', bg:'rgba(16,185,129,0.12)' }
  if (score >= 70) return { label:'Recommended',        color:'#a78bfa', bg:'rgba(124,58,237,0.12)' }
  if (score >= 50) return { label:'Consider',           color:'#fbbf24', bg:'rgba(245,158,11,0.12)' }
  return              { label:'Not Recommended',        color:'#f87171', bg:'rgba(239,68,68,0.1)' }
}

const DECISION_COLORS = { Selected:{c:'#10b981',bg:'rgba(16,185,129,0.1)'}, Hold:{c:'#fbbf24',bg:'rgba(245,158,11,0.1)'}, Rejected:{c:'#f87171',bg:'rgba(239,68,68,0.1)'} }

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

export default function CandidateProfile() {
  const { isDark } = useTheme()
  const navigate   = useNavigate()
  const { id }     = useParams()

  const [candidate, setCandidate]           = useState(null)
  const [loading, setLoading]               = useState(true)
  const [toast, setToast]                   = useState(null)
  const [savingDecision, setSavingDecision] = useState(false)
  // Resume state
  const [resumeUploading, setResumeUploading] = useState(false)
  const [resumeProgress, setResumeProgress]   = useState(0)
  const [dragOver, setDragOver]               = useState(false)
  const [showPreview, setShowPreview]         = useState(false)
  const fileInputRef = useRef(null)

  const showToast = (msg, type='success') => { setToast({msg,type}); setTimeout(()=>setToast(null),3000) }

  useEffect(() => {
    const load = async () => {
      // If no id in URL, show a static profile for demo
      if (!id) {
        setCandidate(DEMO_CANDIDATE)
        setLoading(false)
        return
      }
      try {
        const data = await hrApi.candidates.get(id)
        setCandidate(data)
      } catch {
        showToast('Failed to load candidate','error')
        setCandidate(DEMO_CANDIDATE)
      } finally {
        setLoading(false)
      }
    }
    load()
  }, [id])

  const handleDecision = async (decision) => {
    if (!candidate?.id || candidate.id === 'demo') return
    setSavingDecision(true)
    try {
      const updated = await hrApi.candidates.updateDecision(candidate.id, decision)
      setCandidate(prev => ({...prev, final_decision: updated.final_decision}))
      showToast(`Candidate marked as ${decision}!`)
    } catch { showToast('Failed to update decision','error') }
    finally { setSavingDecision(false) }
  }

  // Resume handlers
  const handleResumeUpload = useCallback(async (file) => {
    if (!file || !candidate?.id || candidate.id === 'demo') return
    const ext = file.name.split('.').pop().toLowerCase()
    if (!['pdf','doc','docx'].includes(ext)) return showToast('Only PDF, DOC, DOCX allowed','error')
    if (file.size > 5 * 1024 * 1024) return showToast('File must be under 5 MB','error')
    setResumeUploading(true)
    setResumeProgress(0)
    // Simulate progress (actual upload has no progress event in this setup)
    const ticker = setInterval(() => setResumeProgress(p => Math.min(p + 15, 85)), 200)
    try {
      const result = await hrApi.candidates.uploadResume(candidate.id, file)
      clearInterval(ticker)
      setResumeProgress(100)
      setCandidate(prev => ({...prev, resume_path: result.resume_path}))
      showToast(`Resume uploaded — ${result.filename} (${result.size_kb} KB)`)
      setTimeout(() => setResumeProgress(0), 1000)
    } catch (e) {
      clearInterval(ticker)
      showToast(e.response?.data?.message || 'Upload failed','error')
      setResumeProgress(0)
    } finally { setResumeUploading(false) }
  }, [candidate])

  const handleResumeDelete = async () => {
    if (!candidate?.id || candidate.id === 'demo') return
    if (!window.confirm('Delete this resume? This cannot be undone.')) return
    try {
      await hrApi.candidates.deleteResume(candidate.id)
      setCandidate(prev => ({...prev, resume_path: null}))
      setShowPreview(false)
      showToast('Resume deleted')
    } catch { showToast('Failed to delete resume','error') }
  }

  const onDrop = useCallback((e) => {
    e.preventDefault(); setDragOver(false)
    const file = e.dataTransfer.files?.[0]
    if (file) handleResumeUpload(file)
  }, [handleResumeUpload])

  if (loading) return <div className="text-center py-20" style={{ color:'var(--text-muted)' }}>Loading…</div>

  const c = candidate || DEMO_CANDIDATE
  const aiScore = c.ai_score || 0
  const aiBand  = AI_BAND(aiScore)
  const aiBreak = c.ai_breakdown || {}
  const skills  = Array.isArray(c.skills) ? c.skills : []
  const rounds  = c.interview_rounds || []

  return (
    <div className="space-y-5 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl" style={{ background:toast.type==='success'?'linear-gradient(135deg,#10b981,#059669)':'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      {/* Back button */}
      <div className="flex items-center gap-3">
        <button onClick={()=>navigate('/app/hr/candidates')} className="flex items-center gap-1.5 text-xs font-semibold px-3 py-2 rounded-xl transition-all" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>
          <ArrowLeft size={12}/> Back to Pipeline
        </button>
        <span className="text-xs" style={{ color:'var(--text-muted)' }}>Candidate Profile</span>
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
            </div>
          </div>
          {/* AI Score badge */}
          <div className="flex-shrink-0 text-center px-4 py-3 rounded-2xl" style={{ background:aiBand.bg, border:`1px solid ${aiBand.color}40` }}>
            <p className="text-3xl font-black" style={{ color:aiBand.color }}>{aiScore}%</p>
            <p className="text-[10px] font-bold mt-0.5" style={{ color:aiBand.color }}>AI Match</p>
          </div>
        </div>
        {/* Contact row */}
        <div className="flex items-center gap-4 mt-4 pt-4 flex-wrap" style={{ borderTop:'1px solid var(--border)' }}>
          {c.email && <a href={`mailto:${c.email}`} className="flex items-center gap-1.5 text-xs" style={{ color:'var(--text-muted)' }}><Mail size={12}/>{c.email}</a>}
          {c.phone && <a href={`tel:${c.phone}`} className="flex items-center gap-1.5 text-xs" style={{ color:'var(--text-muted)' }}><Phone size={12}/>{c.phone}</a>}
          {c.linkedin_url && <a href={c.linkedin_url} target="_blank" rel="noreferrer" className="flex items-center gap-1.5 text-xs font-semibold" style={{ color:'#0a66c2' }}><MessageCircle size={12}/>LinkedIn Profile</a>}
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
        {/* Left — main info */}
        <div className="lg:col-span-2 space-y-5">

          {/* Resume Section */}
          <div className="card-3d" style={{ padding:'20px' }}>
            <div className="flex items-center justify-between mb-4">
              <h3 className="font-bold text-sm flex items-center gap-2" style={{ color:'var(--text-h)' }}>
                <FileText size={14} style={{ color:'#a78bfa' }}/> Resume
              </h3>
              {c.resume_path && (
                <div className="flex items-center gap-2">
                  <a
                    href={`${hrApi.candidates.resumeUrl(c.id)}?token=${getToken()}`}
                    target="_blank" rel="noreferrer"
                    className="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-xl"
                    style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa', border:'1px solid rgba(124,58,237,0.2)' }}
                  >
                    <Download size={11}/> Download
                  </a>
                  <button onClick={()=>setShowPreview(p=>!p)} className="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-xl" style={{ background:'rgba(59,130,246,0.1)', color:'#60a5fa', border:'1px solid rgba(59,130,246,0.2)' }}>
                    <Eye size={11}/> {showPreview?'Hide':'Preview'}
                  </button>
                  <button onClick={handleResumeDelete} className="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-xl" style={{ background:'rgba(239,68,68,0.08)', color:'#f87171', border:'1px solid rgba(239,68,68,0.15)' }}>
                    <Trash2 size={11}/>
                  </button>
                </div>
              )}
            </div>

            {/* PDF Preview iframe */}
            {c.resume_path && showPreview && (
              <div className="mb-4 rounded-xl overflow-hidden" style={{ border:'1px solid var(--border)' }}>
                <iframe
                  src={`${hrApi.candidates.resumeUrl(c.id)}`}
                  title="Resume Preview"
                  className="w-full"
                  style={{ height:'420px', border:'none', background:'#fff' }}
                />
              </div>
            )}

            {/* Resume info or empty state */}
            {c.resume_path ? (
              <div className="flex items-center gap-3 px-3 py-2.5 rounded-xl" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                <div className="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0" style={{ background:'rgba(239,68,68,0.12)' }}>
                  <FileText size={16} style={{ color:'#f87171' }}/>
                </div>
                <div className="flex-1 min-w-0">
                  <p className="text-xs font-bold truncate" style={{ color:'var(--text-h)' }}>{c.resume_path.split('/').pop()}</p>
                  <p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}>Click Preview to view • Download to save</p>
                </div>
              </div>
            ) : (
              <p className="text-xs mb-3" style={{ color:'var(--text-muted)' }}>No resume uploaded yet.</p>
            )}

            {/* Upload progress bar */}
            {resumeUploading && (
              <div className="mt-3">
                <div className="flex justify-between mb-1">
                  <span className="text-xs" style={{ color:'var(--text-muted)' }}>Uploading…</span>
                  <span className="text-xs font-bold" style={{ color:'#a78bfa' }}>{resumeProgress}%</span>
                </div>
                <div className="h-2 rounded-full" style={{ background:'var(--bg-input)' }}>
                  <div className="h-full rounded-full transition-all" style={{ width:`${resumeProgress}%`, background:'linear-gradient(90deg,#7C3AED,#a78bfa)' }}/>
                </div>
              </div>
            )}

            {/* Drag-and-drop upload zone */}
            {!resumeUploading && (
              <div
                onDragOver={e=>{e.preventDefault();setDragOver(true)}}
                onDragLeave={()=>setDragOver(false)}
                onDrop={onDrop}
                onClick={()=>fileInputRef.current?.click()}
                className="mt-3 flex flex-col items-center justify-center gap-2 py-5 rounded-xl cursor-pointer transition-all"
                style={{
                  border:`2px dashed ${dragOver?'#7C3AED':'var(--border)'}`,
                  background: dragOver?'rgba(124,58,237,0.06)':'transparent',
                }}
              >
                <Upload size={20} style={{ color: dragOver?'#7C3AED':'var(--text-muted)' }}/>
                <p className="text-xs font-semibold" style={{ color: dragOver?'#a78bfa':'var(--text-muted)' }}>
                  {c.resume_path ? 'Drop to replace resume' : 'Drop resume here or click to browse'}
                </p>
                <p className="text-[10px]" style={{ color:'var(--text-muted)' }}>PDF, DOC, DOCX · Max 5 MB</p>
                <input
                  ref={fileInputRef}
                  type="file"
                  accept=".pdf,.doc,.docx"
                  className="hidden"
                  onChange={e=>handleResumeUpload(e.target.files?.[0])}
                />
              </div>
            )}
          </div>

          {/* Skills */}
          {skills.length > 0 && (
            <div className="card-3d" style={{ padding:'20px' }}>
              <h3 className="font-bold text-sm mb-4" style={{ color:'var(--text-h)' }}>🎯 Skills</h3>
              <div className="flex flex-wrap gap-2">
                {skills.map(s => (
                  <span key={s} className="px-3 py-1.5 rounded-xl text-xs font-semibold" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa', border:'1px solid rgba(124,58,237,0.2)' }}>{s}</span>
                ))}
              </div>
            </div>
          )}

          {/* Interview Rounds */}
          {rounds.length > 0 && (
            <div className="card-3d" style={{ padding:'20px' }}>
              <h3 className="font-bold text-sm mb-4" style={{ color:'var(--text-h)' }}>🎤 Interview Rounds</h3>
              <div className="space-y-3">
                {rounds.map((r, i) => {
                  const rc = resultColor(r.result)
                  return (
                    <div key={r.id||i} className="p-3 rounded-xl" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                      <div className="flex items-center justify-between flex-wrap gap-2">
                        <div>
                          <p className="text-sm font-bold" style={{ color:'var(--text-h)' }}>{r.round_name}</p>
                          <p className="text-xs mt-0.5" style={{ color:'var(--text-muted)' }}>Interviewer: {r.interviewer_name || '—'}</p>
                        </div>
                        <div className="flex items-center gap-2">
                          {r.overall_score && <span className="text-xs font-black" style={{ color:'#a78bfa' }}>{r.overall_score}%</span>}
                          <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:rc.bg, color:rc.c }}>{r.result}</span>
                        </div>
                      </div>
                      {(r.technical_score||r.communication_score||r.problem_solving_score) && (
                        <div className="flex gap-3 mt-2">
                          {r.technical_score      && <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>Tech: <b style={{ color:'var(--text-h)' }}>{r.technical_score}/10</b></span>}
                          {r.communication_score  && <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>Comm: <b style={{ color:'var(--text-h)' }}>{r.communication_score}/10</b></span>}
                          {r.problem_solving_score&& <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>Prob: <b style={{ color:'var(--text-h)' }}>{r.problem_solving_score}/10</b></span>}
                        </div>
                      )}
                      {r.notes && r.notes !== '—' && <p className="text-xs mt-1.5 italic" style={{ color:'var(--text-muted)' }}>"{r.notes}"</p>}
                    </div>
                  )
                })}
              </div>
            </div>
          )}

          {/* Notes */}
          {c.notes && (
            <div className="card-3d" style={{ padding:'20px' }}>
              <h3 className="font-bold text-sm mb-2" style={{ color:'var(--text-h)' }}>📝 Notes</h3>
              <p className="text-sm" style={{ color:'var(--text-muted)' }}>{c.notes}</p>
            </div>
          )}
        </div>

        {/* Right — AI Summary panel */}
        <div className="space-y-5">
          {/* AI Recommendation */}
          <div className="card-3d" style={{ padding:'20px' }}>
            <div className="flex items-center gap-2 mb-4">
              <Brain size={16} style={{ color:'#a78bfa' }}/>
              <h3 className="font-bold text-sm" style={{ color:'var(--text-h)' }}>AI Assessment</h3>
            </div>
            <div className="text-center py-3 rounded-2xl mb-4" style={{ background:aiBand.bg }}>
              <p className="text-3xl font-black" style={{ color:aiBand.color }}>{aiScore}%</p>
              <p className="text-xs font-bold mt-1" style={{ color:aiBand.color }}>{aiBand.label}</p>
            </div>

            {/* Breakdown bars */}
            {Object.keys(aiBreak).length > 0 && (
              <div className="space-y-3">
                {aiBreak.skills_match    !== undefined && <ScoreBar label="Skills Match"    value={aiBreak.skills_match}    color="#a78bfa"/>}
                {aiBreak.exp_match       !== undefined && <ScoreBar label="Experience"      value={aiBreak.exp_match}       color="#60a5fa"/>}
                {aiBreak.location_match  !== undefined && <ScoreBar label="Location Fit"    value={aiBreak.location_match}  color="#34d399"/>}
                {aiBreak.education       !== undefined && <ScoreBar label="Education"       value={aiBreak.education}       color="#fbbf24"/>}
                {aiBreak.overall_fit     !== undefined && <ScoreBar label="Overall Fit"     value={aiBreak.overall_fit}     color="#f97316"/>}
              </div>
            )}
          </div>

          {/* Final Decision */}
          <div className="card-3d" style={{ padding:'20px' }}>
            <h3 className="font-bold text-sm mb-4" style={{ color:'var(--text-h)' }}>⚖️ Final Decision</h3>
            {c.final_decision ? (
              <div className="text-center py-3 rounded-xl" style={{ background:DECISION_COLORS[c.final_decision]?.bg||'var(--bg-input)' }}>
                <p className="font-black text-lg" style={{ color:DECISION_COLORS[c.final_decision]?.c||'var(--text-h)' }}>{c.final_decision}</p>
              </div>
            ) : (
              <div className="space-y-2">
                <button onClick={()=>handleDecision('Selected')} disabled={savingDecision} className="w-full py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:'linear-gradient(135deg,#10b981,#059669)' }}>
                  <CheckCircle size={14}/> Select Candidate
                </button>
                <button onClick={()=>handleDecision('Hold')} disabled={savingDecision} className="w-full py-2.5 rounded-xl text-sm font-bold" style={{ background:'rgba(245,158,11,0.1)', color:'#fbbf24', border:'1px solid rgba(245,158,11,0.2)' }}>
                  <Clock size={14} className="inline mr-1.5"/> Hold
                </button>
                <button onClick={()=>handleDecision('Rejected')} disabled={savingDecision} className="w-full py-2.5 rounded-xl text-sm font-bold" style={{ background:'rgba(239,68,68,0.1)', color:'#f87171', border:'1px solid rgba(239,68,68,0.2)' }}>
                  <XCircle size={14} className="inline mr-1.5"/> Reject
                </button>
              </div>
            )}
          </div>

          {/* LinkedIn data if available */}
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
      </div>
    </div>
  )
}

// Demo fallback when no id in URL
const DEMO_CANDIDATE = {
  id: 'demo',
  name: 'Arjun Sharma',
  current_company: 'Infosys',
  experience_years: 5,
  stage: 'Interview',
  source: 'LinkedIn',
  location: 'Bangalore, Karnataka',
  email: 'arjun.sharma@gmail.com',
  phone: '+91 98765 43210',
  linkedin_url: '',
  skills: ['React.js','TypeScript','Node.js','GraphQL','AWS','Docker'],
  ai_score: 87,
  ai_breakdown: { skills_match:92, exp_match:85, location_match:100, education:70, overall_fit:88 },
  final_decision: null,
  notes: 'Strong React & TypeScript expertise. Excellent communication throughout the process.',
  interview_rounds: [
    { id:1, round_name:'HR Telephonic', interviewer_name:'Sunita Rao',   result:'Passed', notes:'Good communication, culturally fit.', technical_score:null, communication_score:8, problem_solving_score:null, overall_score:null },
    { id:2, round_name:'Technical L1',  interviewer_name:'Vikram Singh', result:'Passed', notes:'Strong in React, excellent problem solving.', technical_score:9, communication_score:8, problem_solving_score:8, overall_score:83 },
    { id:3, round_name:'Manager L2',    interviewer_name:'Deepak Iyer',  result:'Passed', notes:'Leadership potential observed.', technical_score:null, communication_score:null, problem_solving_score:null, overall_score:null },
    { id:4, round_name:'Final HR L3',   interviewer_name:'Sonal Mehta',  result:'Pending', notes:'—', technical_score:null, communication_score:null, problem_solving_score:null, overall_score:null },
  ],
}
