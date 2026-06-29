import { useState, useEffect, useCallback } from 'react'
import { useTheme } from '@/context/ThemeContext'
import { useNavigate } from 'react-router-dom'
import { Plus, Search, X, Linkedin, Loader2 } from 'lucide-react'
import { hrApi } from '@/services/hrApi'

const STAGES = ['Applied','Screening','Assessment','Interview','Offer','Hired','Rejected']
const STAGE_COLORS = { Applied:'#3b82f6', Screening:'#f59e0b', Assessment:'#a855f7', Interview:'#6366f1', Offer:'#10b981', Hired:'#059669', Rejected:'#ef4444' }
const SOURCE_COLORS = { LinkedIn:'#0077b5', Naukri:'#f97316', 'Career Page':'#7C3AED', 'Internal Portal':'#3b82f6', 'Employee Referral':'#10b981', 'Walk-in':'#6b7280' }
const initials = n => (n||'').split(' ').slice(0,2).map(x=>x[0]).join('').toUpperCase()

const EMPTY_FORM = { name:'', email:'', phone:'', location:'', current_company:'', experience_years:'', source:'LinkedIn', stage:'Applied', job_posting_id:'', linkedin_url:'', skills:[], notes:'' }

export default function Candidates() {
  const { isDark } = useTheme()
  const navigate = useNavigate()
  const [view, setView]           = useState('kanban')
  const [candidates, setCands]    = useState([])
  const [jobs, setJobs]           = useState([])
  const [loading, setLoading]     = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [form, setForm]           = useState(EMPTY_FORM)
  const [saving, setSaving]       = useState(false)
  const [toast, setToast]         = useState(null)
  const [stageF, setStageF]       = useState('All')
  const [search, setSearch]       = useState('')
  // LinkedIn extractor
  const [liUrl, setLiUrl]         = useState('')
  const [liLoading, setLiLoading] = useState(false)
  const [liNote, setLiNote]       = useState('')

  const showToast = (msg, type='success') => { setToast({msg,type}); setTimeout(()=>setToast(null),3000) }

  const fetchData = useCallback(async () => {
    setLoading(true)
    try {
      const params = {}
      if (stageF !== 'All') params.stage = stageF
      if (search) params.search = search
      const [cands, jbs] = await Promise.all([hrApi.candidates.list(params), hrApi.jobs.list()])
      setCands(cands); setJobs(jbs)
    } catch { showToast('Failed to load candidates','error') }
    finally { setLoading(false) }
  }, [stageF, search])

  useEffect(()=>{ fetchData() },[fetchData])

  // LinkedIn extractor
  const handleLinkedInExtract = async () => {
    if (!liUrl) return
    setLiLoading(true); setLiNote('')
    try {
      const res = await hrApi.candidates.linkedinParse(liUrl)
      if (res.success) {
        setForm(prev => ({
          ...prev,
          name:            res.data.name || prev.name,
          current_company: res.data.current_company || prev.current_company,
          location:        res.data.location || prev.location,
          linkedin_url:    liUrl,
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
      setCands(prev => [cand, ...prev])
      setShowModal(false); setForm(EMPTY_FORM); setLiUrl(''); setLiNote('')
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

  const kanbanStages = STAGES.filter(s => s !== 'Rejected')

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

      {/* Search */}
      <div className="relative max-w-sm">
        <Search size={13} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color:'var(--text-muted)' }}/>
        <input className="input-3d pl-9 text-sm" placeholder="Search candidates..." value={search} onChange={e=>setSearch(e.target.value)}/>
      </div>

      {loading ? (
        <div className="text-center py-16" style={{ color:'var(--text-muted)' }}>Loading candidates…</div>
      ) : view === 'kanban' ? (
        // ── Kanban View ──
        <div className="flex gap-4 overflow-x-auto pb-4 scrollbar-hide">
          {kanbanStages.map(stage => {
            const stageCands = filtered.filter(c => c.stage === stage)
            const color = STAGE_COLORS[stage]
            return (
              <div key={stage} className="flex-shrink-0 w-64 rounded-2xl" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                <div className="flex items-center justify-between px-4 py-3" style={{ borderBottom:'1px solid var(--border)' }}>
                  <span className="text-xs font-bold" style={{ color }}>{stage}</span>
                  <span className="text-xs font-black w-6 h-6 rounded-full flex items-center justify-center" style={{ background:`${color}20`, color }}>{stageCands.length}</span>
                </div>
                <div className="p-3 space-y-2 max-h-[60vh] overflow-y-auto scrollbar-hide">
                  {stageCands.map(c => (
                    <div key={c.id} onClick={()=>navigate(`/app/hr/candidates/${c.id}`)} className="p-3 rounded-xl cursor-pointer transition-all card-3d" style={{ padding:'12px' }}
                      onMouseEnter={e=>e.currentTarget.style.transform='translateY(-2px)'}
                      onMouseLeave={e=>e.currentTarget.style.transform='translateY(0)'}>
                      <div className="flex items-center gap-2 mb-2">
                        <div className="w-8 h-8 rounded-xl flex items-center justify-center text-xs font-black text-white flex-shrink-0" style={{ background:`linear-gradient(135deg,${color}cc,${color})` }}>{initials(c.name)}</div>
                        <div className="min-w-0">
                          <p className="text-xs font-bold truncate" style={{ color:'var(--text-h)' }}>{c.name}</p>
                          <p className="text-[10px] truncate" style={{ color:'var(--text-muted)' }}>{c.job_posting?.title || 'Applied'}</p>
                        </div>
                      </div>
                      <div className="flex items-center justify-between mt-1">
                        <span className="text-[9px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${SOURCE_COLORS[c.source]||'#7C3AED'}20`, color:SOURCE_COLORS[c.source]||'#7C3AED' }}>{c.source}</span>
                        {c.ai_score && <span className="text-[10px] font-black" style={{ color:'#a78bfa' }}>AI {c.ai_score}%</span>}
                      </div>
                    </div>
                  ))}
                  {stageCands.length === 0 && <p className="text-[10px] text-center py-4" style={{ color:'var(--text-muted)' }}>No candidates</p>}
                </div>
              </div>
            )
          })}
        </div>
      ) : (
        // ── List View ──
        <div className="card-3d overflow-x-auto" style={{ padding:0 }}>
          <table className="w-full text-sm">
            <thead>
              <tr style={{ borderBottom:'1px solid var(--border)' }}>
                {['Name','Applied For','Source','Stage','AI Score','Actions'].map(h=>(
                  <th key={h} className="label-caps px-4 py-3 text-left">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {filtered.map(c=>{
                const sc = STAGE_COLORS[c.stage]||'#7C3AED'
                return(
                  <tr key={c.id} style={{ borderBottom:'1px solid var(--border)' }}>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-2">
                        <div className="w-8 h-8 rounded-xl flex items-center justify-center text-xs font-black text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>{initials(c.name)}</div>
                        <div>
                          <p className="font-semibold" style={{ color:'var(--text-h)' }}>{c.name}</p>
                          <p className="text-[10px]" style={{ color:'var(--text-muted)' }}>{c.email}</p>
                        </div>
                      </div>
                    </td>
                    <td className="px-4 py-3 text-xs" style={{ color:'var(--text-muted)' }}>{c.job_posting?.title||'—'}</td>
                    <td className="px-4 py-3"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${SOURCE_COLORS[c.source]||'#7C3AED'}20`, color:SOURCE_COLORS[c.source]||'#7C3AED' }}>{c.source}</span></td>
                    <td className="px-4 py-3"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${sc}15`, color:sc }}>{c.stage}</span></td>
                    <td className="px-4 py-3 font-black text-sm" style={{ color:'#a78bfa' }}>{c.ai_score ? `${c.ai_score}%` : '—'}</td>
                    <td className="px-4 py-3">
                      <button onClick={()=>navigate(`/app/hr/candidates/${c.id}`)} className="px-3 py-1.5 rounded-xl text-[11px] font-bold" style={{ background:'rgba(124,58,237,0.12)', color:'#a78bfa' }}>View Profile</button>
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
                <div><label className="label">Current Company</label><input className="input-3d text-sm" value={form.current_company} onChange={e=>setForm({...form,current_company:e.target.value})}/></div>
                <div><label className="label">Experience (yrs)</label><input type="number" step="0.5" className="input-3d text-sm" value={form.experience_years} onChange={e=>setForm({...form,experience_years:e.target.value})}/></div>
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
