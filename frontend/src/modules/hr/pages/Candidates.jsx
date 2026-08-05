import { useState, useEffect, useCallback, useRef } from 'react'
import { useTheme } from '@/context/ThemeContext'
import { useNavigate } from 'react-router-dom'
import { Plus, Search, X, Linkedin, Loader2, Upload, FileText, Briefcase, Clock, CalendarDays, IndianRupee, UserCircle2, Hash } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { useMasterData } from '@/modules/hr/useMasterData'
import { HrLoading } from '@/components/ui/HrState'
import { formatCTC, candidateScore } from '@/modules/hr/constants'
import CandidateQuickActions from '@/modules/hr/components/CandidateQuickActions'

const STAGES = ['Applied','Screening','Assessment','Interview','Offer','Hired','Rejected']
const STAGE_COLORS = { Applied:'#3b82f6', Screening:'#f59e0b', Assessment:'#a855f7', Interview:'#6366f1', Offer:'#10b981', Hired:'#059669', Rejected:'#ef4444' }
const SOURCE_COLORS = { LinkedIn:'#0077b5', Naukri:'#f97316', 'Career Page':'#7C3AED', 'Internal Portal':'#3b82f6', 'Employee Referral':'#10b981', 'Walk-in':'#6b7280', Direct:'#8b5cf6' }
const initials = n => (n||'').split(' ').slice(0,2).map(x=>x[0]).join('').toUpperCase()
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day:'2-digit', month:'short', year:'numeric' }) : null

const EMPTY_FORM = { name:'', email:'', phone:'', dob:'', location:'', address:'', current_company:'',
  // #15 — present designation/department and references, auto-filled where the
  // profile provides them.
  current_designation:'', current_department:'', professional_references:[],
  // #15 — the reference: an employee id, or a free-text external name.
  referred_by_id:'', referred_by_name:'',
  experience_years:'', source:'LinkedIn', stage:'Applied', job_posting_id:'', linkedin_url:'', skills:[], certifications:[], languages:[], notes:'' }

export default function Candidates() {
  const { isDark } = useTheme()
  const navigate = useNavigate()
  const [view, setView]           = useState('kanban')
  const [candidates, setCands]    = useState([])
  const [jobs, setJobs]           = useState([])
  const [recruiters, setRecruiters] = useState([])
  const [loading, setLoading]     = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [form, setForm]           = useState(EMPTY_FORM)
  const [saving, setSaving]       = useState(false)
  const [toast, setToast]         = useState(null)
  const [stageF, setStageF]       = useState('All')
  const [desigF, setDesigF]       = useState('All')   // designation master filter
  const { masters } = useMasterData()                 // shared session-cached masters
  // #3 — hiring manager, inherited from the job posting the candidate applied to.
  const [mgrF, setMgrF] = useState('All')
  const [employees, setEmployees] = useState([])
  const [search, setSearch]       = useState('')
  // LinkedIn extractor
  const [liUrl, setLiUrl]         = useState('')
  const [liLoading, setLiLoading] = useState(false)
  const [liNote, setLiNote]       = useState('')
  // Resume for new candidate
  const [resumeFile, setResumeFile] = useState(null)
  const [resumeDrag, setResumeDrag] = useState(false)
  const resumeInputRef = useRef(null)
  // Kanban drag & drop (SPK-1)
  const [dragging, setDragging]         = useState(null)  // candidate being dragged
  const [dragOverStage, setDragOverStage] = useState(null)
  const [moving, setMoving]             = useState(null)  // candidate id mid-save
  const dragRef = useRef(null)                            // synchronous drag source

  const showToast = (msg, type='success') => { setToast({msg,type}); setTimeout(()=>setToast(null),3000) }

  const fetchData = useCallback(async () => {
    setLoading(true)
    try {
      const params = {}
      if (stageF !== 'All') params.stage = stageF
      // Sent as the master id; the API resolves it to the stored job-posting title.
      if (desigF !== 'All') params.designation_id = desigF
      if (search) params.search = search
      if (mgrF !== 'All') params.hiring_manager_id = mgrF
      const [cands, jbs] = await Promise.all([hrApi.candidates.list(params), hrApi.jobs.list()])
      setCands(cands); setJobs(jbs)
    } catch { showToast('Failed to load candidates','error') }
    finally { setLoading(false) }
  }, [stageF, desigF, search, mgrF])

  // Assignable recruiters — fetched once, shared by every quick-actions menu.
  useEffect(() => { hrApi.candidates.recruiters().then(setRecruiters).catch(() => {}) }, [])
  // #15 — the employee master backs the "referred by" picker. Reused, not a new endpoint.
  useEffect(() => { hrApi.employees.list().then(r => setEmployees(Array.isArray(r) ? r : (r?.data ?? []))).catch(() => setEmployees([])) }, [])

  // Merge a quick-action result into the candidate in local state.
  const patchCandidate = (id, partial) => setCands(prev => prev.map(c => c.id === id ? { ...c, ...partial } : c))

  useEffect(()=>{ fetchData() },[fetchData])

  // LinkedIn extractor
  const handleLinkedInExtract = async () => {
    if (!liUrl) return
    setLiLoading(true); setLiNote('')
    try {
      const res = await hrApi.candidates.linkedinParse(liUrl)
      if (res.success) {
        // #15 — designation, department and skills were being parsed and then
        // thrown away here. Everything the parser returns is now applied, and
        // only where the recruiter has not already typed something.
        setForm(prev => ({
          ...prev,
          name:                res.data.name || prev.name,
          current_company:     res.data.current_company || prev.current_company,
          current_designation: res.data.current_designation || prev.current_designation,
          current_department:  res.data.current_department || prev.current_department,
          location:            res.data.location || prev.location,
          skills:              prev.skills?.length ? prev.skills : (res.data.skills || []),
          linkedin_url:        liUrl,
        }))
        setLiNote(res.note || (res.source === 'scraped' ? '✅ Profile extracted successfully!' : '⚠️ '+res.note))
      }
    } catch (e) {
      setLiNote('❌ Could not extract profile. Please fill manually.')
    } finally { setLiLoading(false) }
  }

  const handleCreate = async () => {
    if (!form.name) return showToast('Name is required','error')
    setSaving(true)
    try {
      const payload = { ...form, linkedin_url: liUrl || form.linkedin_url }
      const cand = await hrApi.candidates.create(payload)
      // Auto-upload resume if one was selected
      if (resumeFile) {
        try { await hrApi.candidates.uploadResume(cand.id, resumeFile) } catch {}
      }
      setCands(prev => [cand, ...prev])
      setShowModal(false); setForm(EMPTY_FORM); setLiUrl(''); setLiNote(''); setResumeFile(null)
      showToast('Candidate added!')
    } catch (e) {
      showToast(e.response?.data?.message || 'Failed to add','error')
    } finally { setSaving(false) }
  }

  const handleStageMove = async (id, newStage) => {
    try {
      const updated = await hrApi.candidates.updateStage(id, newStage)
      setCands(prev => prev.map(c => c.id === id ? {...c, stage: updated.stage} : c))
    } catch { showToast('Failed to move stage','error') }
  }

  const filtered = candidates.filter(c =>
    c.name?.toLowerCase().includes(search.toLowerCase()) ||
    c.email?.toLowerCase().includes(search.toLowerCase())
  )

  // Rejected is the last column and must stay reachable — it is a real drop
  // target (rejection is allowed from any stage), so it can't be filtered out.
  const kanbanStages = STAGES

  // ── Drag & drop validation (SPK-1) ─────────────────────────────────────────
  // dropCheck is a complete client mirror of the backend gate
  // (CandidateService::updateStage + assertTransitionAllowed) so a move can be
  // validated BEFORE the drop — no card is moved and no request is sent for a
  // transition the backend would reject. The backend is still the authority and
  // re-validates every real move; this only spares the user a round-trip and an
  // after-the-fact revert. Messages match the server's so both read identically.
  //   • Offer / Hired   — system-controlled, never a manual drop target.
  //   • Rejected        — allowed from any stage.
  //   • backward / skip — blocked (forward, one step at a time).
  //   • Assessment      — requires the AI screening to have RUN. A withheld
  //                       (low-confidence) score still counts as screened.
  const STAGE_ORDER = { Applied: 0, Screening: 1, Assessment: 2, Interview: 3, Offer: 4, Hired: 5, Rejected: 6 }
  const dropCheck = (c, toStage) => {
    if (!c || c.stage === toStage) return { ok: false, reason: null }
    if (toStage === 'Offer' || toStage === 'Hired') {
      return { ok: false, reason: `${toStage} is managed automatically by the recruitment workflow.` }
    }
    if (toStage === 'Rejected') return { ok: true, reason: null }

    const from = STAGE_ORDER[c.stage] ?? -1
    const to   = STAGE_ORDER[toStage] ?? -1
    if (to < from) return { ok: false, reason: 'Stage can only move forward in the pipeline.' }
    if (to > from + 1) {
      const next = Object.keys(STAGE_ORDER).find(k => STAGE_ORDER[k] === from + 1)
      return { ok: false, reason: `Move to ${next} first — stages cannot be skipped.` }
    }
    if (toStage === 'Assessment' && !candidateScore(c).isScreened) {
      return { ok: false, reason: 'AI screening has not completed for this candidate yet — no AI score on record.' }
    }
    return { ok: true, reason: null }
  }

  const onDragStart = (e, c) => {
    // dragRef is the source of truth for the handlers: setDragging() is async, and
    // the first dragover fires before React re-renders — reading state there would
    // skip preventDefault() and the drop would silently never happen.
    dragRef.current = c
    setDragging(c)
    e.dataTransfer.effectAllowed = 'move'
    e.dataTransfer.setData('text/plain', String(c.id))
  }
  const onDragEnd = () => { dragRef.current = null; setDragging(null); setDragOverStage(null) }

  const onDragOver = (e, stage) => {
    const d = dragRef.current
    if (!d || d.stage === stage) return
    // preventDefault lets the drop fire so onDropStage can validate and, when the
    // move is not allowed, show the reason. The column's own styling conveys
    // whether the drop will be accepted, so the cursor staying "move" is fine.
    e.preventDefault()
    e.dataTransfer.dropEffect = 'move'
    if (dragOverStage !== stage) setDragOverStage(stage)
  }

  // Transactional drop (SPK-1): validate → (invalid: stop, no move, no request) →
  // (valid: API first, await success, THEN move the card + refresh every count).
  // The card is never moved optimistically, so UI and DB can never disagree:
  //   invalid  → card stays, reason shown, zero server calls.
  //   API ok   → merge the server's authoritative row (recounts columns + stats).
  //   API fail → card never moved (nothing to roll back), backend error shown.
  const onDropStage = async (e, stage) => {
    e.preventDefault()
    const c = dragRef.current
    dragRef.current = null
    setDragging(null); setDragOverStage(null)
    if (!c || c.stage === stage) return

    // 1) VALIDATE BEFORE THE DROP — no move, no API for a rejected transition.
    const { ok, reason } = dropCheck(c, stage)
    if (!ok) {
      if (reason) showToast(reason, 'error')
      return
    }

    // 2) BACKEND FIRST — the card only moves once the server confirms.
    setMoving(c.id)
    try {
      const updated = await hrApi.candidates.updateStage(c.id, stage)
      patchCandidate(c.id, updated)          // 3) authoritative row → card + all counts update together
      showToast(`${c.name} moved to ${stage}`)
    } catch (err) {
      // 4) Card never moved, so there is nothing to roll back — surface the reason.
      showToast(err.response?.data?.message || `Could not move ${c.name} to ${stage}`, 'error')
    } finally { setMoving(null) }
  }

  return (
    <div className="space-y-5 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && (
        <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl"
          style={{ background: toast.type==='success'?'linear-gradient(135deg,#10b981,#059669)':'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>
      )}

      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <p className="label-caps mb-1">HR Module</p>
          <h1 className="font-black" style={{ fontSize:'clamp(1.3rem,2vw,1.7rem)', color:'var(--text-h)', letterSpacing:'-0.02em' }}>
            Candidate <span className="text-gradient">Pipeline</span>
          </h1>
        </div>
        <div className="flex gap-2">
          {/* View toggle */}
          <div className="flex rounded-xl overflow-hidden" style={{ border:'1px solid var(--border)' }}>
            {['kanban','list'].map(v=>(
              <button key={v} onClick={()=>setView(v)} className="px-4 py-2 text-xs font-bold capitalize transition-all"
                style={{ background:view===v?'linear-gradient(135deg,#7C3AED,#5b21b6)':'var(--bg-input)', color:view===v?'#fff':'var(--text-muted)' }}>{v}</button>
            ))}
          </div>
          <button onClick={()=>setShowModal(true)} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white"
            style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow:'0 4px 14px rgba(124,58,237,0.4)' }}>
            <Plus size={15}/> Add Candidate
          </button>
        </div>
      </div>

      {/* Stats bar */}
      <div className="flex gap-3 overflow-x-auto pb-1 scrollbar-hide">
        {STAGES.map(s=>{
          const cnt = candidates.filter(c=>c.stage===s).length
          const color = STAGE_COLORS[s]
          return(
            <div key={s} onClick={()=>setStageF(stageF===s?'All':s)} className="flex-shrink-0 px-4 py-3 rounded-2xl cursor-pointer transition-all" style={{ background:stageF===s?`${color}20`:'var(--bg-input)', border:`1px solid ${stageF===s?color:'var(--border)'}` }}>
              <p className="text-xl font-black" style={{ color }}>{cnt}</p>
              <p className="text-[10px] font-semibold mt-0.5" style={{ color:'var(--text-muted)' }}>{s}</p>
            </div>
          )
        })}
      </div>

      {/* Search + Designation filter */}
      <div className="flex flex-wrap items-center gap-3">
        <div className="relative max-w-sm flex-1 min-w-[200px]">
          <Search size={13} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color:'var(--text-muted)' }}/>
          <input className="input-3d pl-9 text-sm" placeholder="Search candidates..." value={search} onChange={e=>setSearch(e.target.value)}/>
        </div>
        <select className="input-3d text-sm" style={{ maxWidth: 220 }} value={desigF} onChange={e=>setDesigF(e.target.value)}>
          <option value="All">All Designations</option>
          {(masters.designations || []).map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
        </select>
        <select className="input-3d text-sm" style={{ maxWidth: 220 }} value={mgrF} onChange={e=>setMgrF(e.target.value)}>
          <option value="All">All Hiring Managers</option>
          {(masters.managers || []).map(m => <option key={m.id} value={m.id}>{m.name}</option>)}
        </select>
        {(desigF !== 'All' || mgrF !== 'All') && (
          <button onClick={()=>{ setDesigF('All'); setMgrF('All') }} className="text-xs font-bold" style={{ color:'var(--text-muted)', background:'none', border:'none', cursor:'pointer' }}>Clear</button>
        )}
      </div>

      {loading ? (
        <HrLoading label="Loading candidates…" />
      ) : view === 'kanban' ? (
        // ── Kanban View ──
        // scrollbar-hide removed: the board is wider than the viewport, so the
        // horizontal scrollbar is the only affordance telling HR the later
        // columns (through Rejected) exist. Column width stays fixed at w-64.
        <div className="flex gap-4 overflow-x-auto overflow-y-visible pb-3 hr-kanban-scroll"
          style={{ scrollBehavior:'smooth', overscrollBehaviorX:'contain' }}>
          {kanbanStages.map(stage => {
            const stageCands = filtered.filter(c => c.stage === stage)
            const color = STAGE_COLORS[stage]
            const isTarget  = dragOverStage === stage
            // Reflect the SAME validation the drop uses: columns this card cannot
            // move into (backward, skipped, system-controlled, or Assessment with
            // no AI score) are dimmed during the drag.
            const droppable = !!dragging && dropCheck(dragging, stage).ok
            return (
              <div key={stage}
                onDragOver={e => onDragOver(e, stage)}
                onDragLeave={() => dragOverStage === stage && setDragOverStage(null)}
                onDrop={e => onDropStage(e, stage)}
                className="flex-shrink-0 w-64 rounded-2xl transition-all"
                style={{
                  background:'var(--bg-input)',
                  // Green highlight only when hovering a VALID target; invalid
                  // columns never light up. No layout shift, resting state intact.
                  border:`1px solid ${isTarget && droppable ? color : 'var(--border)'}`,
                  boxShadow: isTarget && droppable ? `0 0 0 2px ${color}55` : 'none',
                  // Dim the columns this card cannot move into during a drag.
                  opacity: dragging && dragging.stage !== stage && !droppable ? 0.5 : 1,
                }}>
                <div className="flex items-center justify-between px-4 py-3" style={{ borderBottom:'1px solid var(--border)' }}>
                  <span className="text-xs font-bold" style={{ color }}>{stage}</span>
                  <span className="text-xs font-black w-6 h-6 rounded-full flex items-center justify-center" style={{ background:`${color}20`, color }}>{stageCands.length}</span>
                </div>
                <div className="p-3 space-y-2 max-h-[60vh] overflow-y-auto scrollbar-hide">
                  {stageCands.map(c => {
                    const ctcC = formatCTC(c.current_ctc), ctcE = formatCTC(c.expected_ctc)
                    const applied = fmtDate(c.applied_at || c.created_at)
                    return (
                    <div key={c.id}
                      draggable
                      onDragStart={e=>onDragStart(e,c)}
                      onDragEnd={onDragEnd}
                      onClick={()=>navigate(`/app/hr/candidates/${c.id}`)}
                      className="rounded-xl cursor-pointer transition-all card-3d" style={{
                        padding:'12px',
                        opacity: dragging?.id === c.id ? 0.4 : moving === c.id ? 0.6 : 1,
                        cursor: dragging?.id === c.id ? 'grabbing' : 'pointer',
                        // Lock the card while its stage change is saving (SPK-1 #13).
                        pointerEvents: moving === c.id ? 'none' : 'auto',
                      }}
                      onMouseEnter={e=>e.currentTarget.style.transform='translateY(-2px)'}
                      onMouseLeave={e=>e.currentTarget.style.transform='translateY(0)'}>
                      {/* header */}
                      <div className="flex items-start gap-2 mb-2">
                        <div className="w-8 h-8 rounded-xl flex items-center justify-center text-xs font-black text-white flex-shrink-0" style={{ background:`linear-gradient(135deg,${color}cc,${color})` }}>{initials(c.name)}</div>
                        <div className="min-w-0 flex-1">
                          <p className="text-xs font-bold truncate" style={{ color:'var(--text-h)' }}>{c.name}</p>
                          <p className="text-[9px] font-mono flex items-center gap-0.5" style={{ color:'var(--text-muted)' }}><Hash size={8}/>CAND-{String(c.id).padStart(4,'0')}</p>
                        </div>
                        {(() => { const ai = candidateScore(c); return ai.isScored ? <span className="text-[10px] font-black flex-shrink-0" style={{ color: ai.style.color }} title={ai.recommendation || undefined}>AI {ai.display}</span> : null })()}
                        <div onClick={e=>e.stopPropagation()}><CandidateQuickActions candidate={c} recruiters={recruiters} onChanged={p=>patchCandidate(c.id,p)} onToast={showToast}/></div>
                      </div>
                      {/* applied job */}
                      <p className="text-[10px] truncate flex items-center gap-1 mb-2" style={{ color:'var(--text-muted)' }}><Briefcase size={9}/>{c.job_posting?.title || 'General Application'}</p>
                      {/* meta grid */}
                      <div className="grid grid-cols-2 gap-1.5 mb-2">
                        {c.experience_years != null && c.experience_years !== '' && <span className="text-[9px] flex items-center gap-1" style={{ color:'var(--text-muted)' }}><Clock size={8}/>{c.experience_years} yrs exp</span>}
                        {applied && <span className="text-[9px] flex items-center gap-1" style={{ color:'var(--text-muted)' }}><CalendarDays size={8}/>{applied}</span>}
                        {(ctcC || ctcE) && <span className="text-[9px] flex items-center gap-1" style={{ color:'var(--text-muted)' }}><IndianRupee size={8}/>{ctcC||'—'}{ctcE?` → ${ctcE}`:''}</span>}
                        {c.notice_period && <span className="text-[9px] flex items-center gap-1" style={{ color:'var(--text-muted)' }}>⏳ {c.notice_period}</span>}
                      </div>
                      {/* footer: source + recruiter */}
                      <div className="flex items-center justify-between gap-1">
                        <span className="text-[9px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${SOURCE_COLORS[c.source]||'#7C3AED'}20`, color:SOURCE_COLORS[c.source]||'#7C3AED' }}>{c.source}</span>
                        {c.assigned_recruiter
                          ? <span className="text-[9px] flex items-center gap-1 truncate" style={{ color:'#34d399' }} title={`Recruiter: ${c.assigned_recruiter.name}`}><UserCircle2 size={9}/>{c.assigned_recruiter.name.split(' ')[0]}</span>
                          : <span className="text-[9px] italic" style={{ color:'var(--text-muted)' }}>Unassigned</span>}
                      </div>
                    </div>
                  )})}
                  {stageCands.length === 0 && <p className="text-[10px] text-center py-4" style={{ color:'var(--text-muted)' }}>No candidates</p>}
                </div>
              </div>
            )
          })}
        </div>
      ) : (
        // ── List View ──
        <div className="card-3d overflow-x-auto" style={{ padding:0 }}>
          <table className="w-full text-sm" style={{ minWidth:920 }}>
            <thead>
              <tr style={{ borderBottom:'1px solid var(--border)' }}>
                {['ID','Candidate','Applied For','Exp','CTC (Cur → Exp)','Notice','Source','Stage','Applied','Recruiter','AI','Actions'].map(h=>(
                  <th key={h} className="label-caps px-3 py-3 text-left whitespace-nowrap">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {filtered.map(c=>{
                const sc = STAGE_COLORS[c.stage]||'#7C3AED'
                const ctcC = formatCTC(c.current_ctc), ctcE = formatCTC(c.expected_ctc)
                return(
                  <tr key={c.id} style={{ borderBottom:'1px solid var(--border)' }}>
                    <td className="px-3 py-3 text-[10px] font-mono whitespace-nowrap" style={{ color:'var(--text-muted)' }}>CAND-{String(c.id).padStart(4,'0')}</td>
                    <td className="px-3 py-3">
                      <div className="flex items-center gap-2">
                        <div className="w-8 h-8 rounded-xl flex items-center justify-center text-xs font-black text-white flex-shrink-0" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>{initials(c.name)}</div>
                        <div className="min-w-0">
                          <p className="font-semibold truncate" style={{ color:'var(--text-h)' }}>{c.name}</p>
                          <p className="text-[10px] truncate" style={{ color:'var(--text-muted)' }}>{c.email||'—'}</p>
                        </div>
                      </div>
                    </td>
                    <td className="px-3 py-3 text-xs whitespace-nowrap" style={{ color:'var(--text-muted)' }}>{c.job_posting?.title||'General'}</td>
                    <td className="px-3 py-3 text-xs whitespace-nowrap" style={{ color:'var(--text-muted)' }}>{c.experience_years!=null&&c.experience_years!==''?`${c.experience_years} yrs`:'—'}</td>
                    <td className="px-3 py-3 text-xs whitespace-nowrap" style={{ color:'var(--text-muted)' }}>{ctcC||ctcE?`${ctcC||'—'} → ${ctcE||'—'}`:'—'}</td>
                    <td className="px-3 py-3 text-xs whitespace-nowrap" style={{ color:'var(--text-muted)' }}>{c.notice_period||'—'}</td>
                    <td className="px-3 py-3"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg whitespace-nowrap" style={{ background:`${SOURCE_COLORS[c.source]||'#7C3AED'}20`, color:SOURCE_COLORS[c.source]||'#7C3AED' }}>{c.source}</span></td>
                    <td className="px-3 py-3"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg whitespace-nowrap" style={{ background:`${sc}15`, color:sc }}>{c.stage}</span></td>
                    <td className="px-3 py-3 text-[11px] whitespace-nowrap" style={{ color:'var(--text-muted)' }}>{fmtDate(c.applied_at||c.created_at)||'—'}</td>
                    <td className="px-3 py-3 text-[11px] whitespace-nowrap" style={{ color:c.assigned_recruiter?'#34d399':'var(--text-muted)' }}>{c.assigned_recruiter?.name||'Unassigned'}</td>
                    {/* `!= null`, not truthiness: an honest 0% is a score, and an unscored
                        candidate must read as — rather than as a bad one. */}
                    <td className="px-3 py-3 font-black text-sm whitespace-nowrap">{(() => { const ai = candidateScore(c); return <span style={{ color: ai.isScored ? ai.style.color : 'var(--text-muted)' }} title={ai.recommendation || undefined}>{ai.display}</span> })()}</td>
                    <td className="px-3 py-3">
                      <div className="flex items-center gap-1">
                        <button onClick={()=>navigate(`/app/hr/candidates/${c.id}`)} className="px-3 py-1.5 rounded-xl text-[11px] font-bold whitespace-nowrap" style={{ background:'rgba(124,58,237,0.12)', color:'#a78bfa' }}>View</button>
                        <CandidateQuickActions candidate={c} recruiters={recruiters} onChanged={p=>patchCandidate(c.id,p)} onToast={showToast} hideView/>
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      {/* Add Candidate Modal */}
      {showModal && (
        <div className="modal-backdrop" onClick={()=>setShowModal(false)}>
          <div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()} style={{ maxHeight:'90vh', overflowY:'auto' }}>
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Add Candidate</h2>
              <button onClick={()=>setShowModal(false)} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
            </div>

            {/* LinkedIn Extractor */}
            <div className="mb-4 p-4 rounded-2xl" style={{ background:'rgba(0,119,181,0.08)', border:'1px solid rgba(0,119,181,0.2)' }}>
              <p className="text-xs font-bold mb-2 flex items-center gap-1.5" style={{ color:'#0077b5' }}><Linkedin size={13}/> LinkedIn Profile Extractor</p>
              <div className="flex gap-2">
                <input className="input-3d text-sm flex-1" placeholder="https://linkedin.com/in/username" value={liUrl} onChange={e=>setLiUrl(e.target.value)}/>
                <button onClick={handleLinkedInExtract} disabled={liLoading||!liUrl} className="px-3 py-2 rounded-xl text-xs font-bold text-white flex items-center gap-1.5 flex-shrink-0" style={{ background:'linear-gradient(135deg,#0077b5,#005885)', opacity:!liUrl||liLoading?0.6:1 }}>
                  {liLoading ? <Loader2 size={12} className="animate-spin"/> : <Linkedin size={12}/>} Extract
                </button>
              </div>
              {liNote && <p className="text-[10px] mt-2" style={{ color:liNote.startsWith('✅')?'#10b981':liNote.startsWith('⚠️')?'#f59e0b':'#f87171' }}>{liNote}</p>}
            </div>

            <div className="space-y-3">
              <div>
                <label className="label">Full Name *</label>
                <input className="input-3d text-sm" placeholder="Arjun Sharma" value={form.name} onChange={e=>setForm({...form,name:e.target.value})}/>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Email</label><input className="input-3d text-sm" type="email" placeholder="arjun@gmail.com" value={form.email} onChange={e=>setForm({...form,email:e.target.value})}/></div>
                <div><label className="label">Phone</label><input className="input-3d text-sm" placeholder="+91 98765 43210" value={form.phone} onChange={e=>setForm({...form,phone:e.target.value})}/></div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Date of Birth</label><input type="date" className="input-3d text-sm" value={form.dob} onChange={e=>setForm({...form,dob:e.target.value})}/></div>
                <div><label className="label">Location</label><input className="input-3d text-sm" placeholder="City" value={form.location} onChange={e=>setForm({...form,location:e.target.value})}/></div>
              </div>
              <div><label className="label">Address</label><textarea rows={2} className="input-3d text-sm resize-none" placeholder="Full postal address" value={form.address} onChange={e=>setForm({...form,address:e.target.value})}/></div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Current Company</label><input className="input-3d text-sm" value={form.current_company} onChange={e=>setForm({...form,current_company:e.target.value})}/></div>
                <div><label className="label">Experience (yrs)</label><input type="number" step="0.5" className="input-3d text-sm" value={form.experience_years} onChange={e=>setForm({...form,experience_years:e.target.value})}/></div>
              </div>
              {/* #15 — present designation and department. Auto-filled from the
                  LinkedIn parse above and editable, because a headline is written
                  by a person and does not always split cleanly. */}
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Current Designation</label><input className="input-3d text-sm" placeholder="e.g. Senior Engineer" value={form.current_designation} onChange={e=>setForm({...form,current_designation:e.target.value})}/></div>
                <div><label className="label">Current Department</label><input className="input-3d text-sm" placeholder="e.g. Platform Engineering" value={form.current_department} onChange={e=>setForm({...form,current_department:e.target.value})}/></div>
              </div>
              {/* #15 — REFERENCE. professional_references already existed on the
                  model and was captured nowhere; one per line keeps it simple. */}
              {/* #15 — the REFERENCE: who sent this candidate to us. Picked from
                  the employee master so it resolves to a real person; the name is
                  then filled in server-side and stays correct if they are renamed.
                  Shown for referral sources, where a referrer actually exists. */}
              {/Referral|Internal Portal|Walk-in|Direct/i.test(form.source || '') && (
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="label">Referred By (employee)</label>
                    <select className="input-3d text-sm" value={form.referred_by_id}
                      onChange={e=>setForm({...form, referred_by_id: e.target.value, referred_by_name: e.target.value ? '' : form.referred_by_name})}>
                      <option value="">Not an employee referral</option>
                      {employees.map(emp => (
                        <option key={emp.id} value={emp.id}>{emp.name}{emp.employee_code ? ` · ${emp.employee_code}` : ''}</option>
                      ))}
                    </select>
                  </div>
                  <div>
                    <label className="label">Or referred by (external)</label>
                    <input className="input-3d text-sm" placeholder="Name of the person who referred them"
                      disabled={!!form.referred_by_id}
                      value={form.referred_by_name}
                      onChange={e=>setForm({...form, referred_by_name: e.target.value})}/>
                  </div>
                </div>
              )}
              <div>
                <label className="label">References</label>
                <textarea rows={2} className="input-3d text-sm resize-none"
                  placeholder="One per line — name, relationship, contact"
                  value={(form.professional_references || []).join('\n')}
                  onChange={e=>setForm({...form, professional_references: e.target.value.split('\n').map(s=>s.trim()).filter(Boolean)})}/>
                <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>
                  Background-check references — separate from who referred them above.
                </p>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="label">Source</label>
                  <select className="input-3d text-sm" value={form.source} onChange={e=>setForm({...form,source:e.target.value})}>
                    {['LinkedIn','Naukri','Career Page','Internal Portal','Employee Referral','Walk-in','Direct'].map(s=><option key={s}>{s}</option>)}
                  </select>
                </div>
                <div>
                  <label className="label">Applying For</label>
                  <select className="input-3d text-sm" value={form.job_posting_id} onChange={e=>setForm({...form,job_posting_id:e.target.value})}>
                    <option value="">Select job...</option>
                    {jobs.filter(j=>j.status==='Active').map(j=><option key={j.id} value={j.id}>{j.title}</option>)}
                  </select>
                </div>
              </div>
              <div>
                <label className="label">Skills (comma separated)</label>
                <input className="input-3d text-sm" placeholder="React.js, Node.js, AWS" value={(form.skills||[]).join(', ')} onChange={e=>setForm({...form,skills:e.target.value.split(',').map(s=>s.trim()).filter(Boolean)})}/>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="label">Certifications (comma separated)</label>
                  <input className="input-3d text-sm" placeholder="AWS SA, PMP" value={(form.certifications||[]).join(', ')} onChange={e=>setForm({...form,certifications:e.target.value.split(',').map(s=>s.trim()).filter(Boolean)})}/>
                </div>
                <div>
                  <label className="label">Languages (comma separated)</label>
                  <input className="input-3d text-sm" placeholder="English, Hindi" value={(form.languages||[]).join(', ')} onChange={e=>setForm({...form,languages:e.target.value.split(',').map(s=>s.trim()).filter(Boolean)})}/>
                </div>
              </div>
              {/* Resume Upload */}
              <div>
                <label className="label">Resume (optional)</label>
                <div
                  onDragOver={e=>{e.preventDefault();setResumeDrag(true)}}
                  onDragLeave={()=>setResumeDrag(false)}
                  onDrop={e=>{e.preventDefault();setResumeDrag(false);const f=e.dataTransfer.files?.[0];if(f)setResumeFile(f)}}
                  onClick={()=>resumeInputRef.current?.click()}
                  className="flex items-center gap-3 px-4 py-3 rounded-xl cursor-pointer transition-all"
                  style={{ border:`2px dashed ${resumeDrag?'#7C3AED':'var(--border)'}`, background:resumeDrag?'rgba(124,58,237,0.06)':'transparent' }}
                >
                  {resumeFile ? (
                    <>
                      <div className="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" style={{ background:'rgba(239,68,68,0.12)' }}>
                        <FileText size={14} style={{ color:'#f87171' }}/>
                      </div>
                      <div className="flex-1 min-w-0">
                        <p className="text-xs font-bold truncate" style={{ color:'var(--text-h)' }}>{resumeFile.name}</p>
                        <p className="text-[10px]" style={{ color:'var(--text-muted)' }}>{(resumeFile.size/1024).toFixed(0)} KB · Will upload after save</p>
                      </div>
                      <button onClick={e=>{e.stopPropagation();setResumeFile(null)}} style={{ color:'var(--text-muted)' }}><X size={14}/></button>
                    </>
                  ) : (
                    <>
                      <Upload size={16} style={{ color:'var(--text-muted)' }}/>
                      <span className="text-xs" style={{ color:'var(--text-muted)' }}>Drop PDF/DOC/DOCX here or click to browse (max 5MB)</span>
                    </>
                  )}
                  <input ref={resumeInputRef} type="file" accept=".pdf,.doc,.docx" className="hidden" onChange={e=>setResumeFile(e.target.files?.[0]||null)}/>
                </div>
              </div>
              <div className="flex gap-3 pt-1">
                <button onClick={()=>setShowModal(false)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
                <button onClick={handleCreate} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow:'0 4px 14px rgba(124,58,237,0.4)', opacity:saving?0.7:1 }}>
                  {saving?'Saving…':'Add Candidate'}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
