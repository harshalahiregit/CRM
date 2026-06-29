import { useState, useEffect } from 'react'
import { useTheme } from '@/context/ThemeContext'
import { Plus, X, Users, Calendar, MapPin } from 'lucide-react'
import { hrApi } from '@/services/hrApi'

const STATUS_S = s => s==='Active'?{c:'#10b981',bg:'rgba(16,185,129,0.12)'}:s==='Draft'?{c:'#fbbf24',bg:'rgba(245,158,11,0.12)'}:{c:'#f87171',bg:'rgba(239,68,68,0.1)'}
const TYPE_COLOR = { 'Full-time':'#7C3AED','Part-time':'#3b82f6','Contract':'#f59e0b','Remote':'#10b981','Internship':'#ec4899' }
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—'
const fmtSalary = (from, to) => { if(!from&&!to) return '—'; const f = from?'₹'+Math.round(from/100000)+'L':''; const t = to?'₹'+Math.round(to/100000)+'L':''; return [f,t].filter(Boolean).join(' – ') }

const EMPTY = { title:'', department:'', location:'', job_type:'Full-time', posting_type:'Both', description:'', requirements:'', salary_from:'', salary_to:'', number_of_openings:1, closing_date:'', status:'Active', sources:[] }

export default function JobPostings() {
  const { isDark } = useTheme()
  const [jobs, setJobs]           = useState([])
  const [loading, setLoading]     = useState(true)
  const [tab, setTab]             = useState('All')
  const [showModal, setShowModal] = useState(false)
  const [form, setForm]           = useState(EMPTY)
  const [saving, setSaving]       = useState(false)
  const [toast, setToast]         = useState(null)

  const showToast = (msg, type='success') => { setToast({msg,type}); setTimeout(()=>setToast(null),3000) }

  const fetchData = async () => {
    setLoading(true)
    try {
      const params = tab!=='All'?{status:tab}:{}
      const data = await hrApi.jobs.list(params)
      setJobs(data)
    } catch { showToast('Failed to load jobs','error') }
    finally { setLoading(false) }
  }
  useEffect(()=>{ fetchData() },[tab])

  const handleCreate = async () => {
    if (!form.title||!form.department||!form.location) return showToast('Title, department and location required','error')
    setSaving(true)
    try {
      const job = await hrApi.jobs.create(form)
      setJobs(prev=>[job,...prev])
      setShowModal(false); setForm(EMPTY)
      showToast('Job posted!')
    } catch (e) { showToast(e.response?.data?.message||'Failed','error') }
    finally { setSaving(false) }
  }

  const handleClose = async (id) => {
    try {
      const updated = await hrApi.jobs.updateStatus(id, 'Closed')
      setJobs(prev=>prev.map(j=>j.id===id?updated:j))
      showToast('Job closed')
    } catch { showToast('Failed','error') }
  }

  const toggleSource = (src) => {
    const sources = Array.isArray(form.sources)?form.sources:[]
    setForm({...form, sources: sources.includes(src) ? sources.filter(s=>s!==src) : [...sources,src]})
  }

  return (
    <div className="space-y-5 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl" style={{ background:toast.type==='success'?'linear-gradient(135deg,#10b981,#059669)':'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      <div className="flex items-center justify-between flex-wrap gap-3">
        <div><p className="label-caps mb-1">HR Module</p><h1 className="font-black" style={{ fontSize:'clamp(1.3rem,2vw,1.7rem)', color:'var(--text-h)', letterSpacing:'-0.02em' }}>Job <span className="text-gradient">Postings</span></h1></div>
        <button onClick={()=>setShowModal(true)} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow:'0 4px 14px rgba(124,58,237,0.4)' }}><Plus size={15}/> Post New Job</button>
      </div>

      {/* Tabs */}
      <div className="flex gap-2">
        {['All','Active','Draft','Closed'].map(t=>(
          <button key={t} onClick={()=>setTab(t)} className="px-3 py-1.5 rounded-xl text-xs font-bold transition-all" style={{ background:tab===t?'linear-gradient(135deg,#7C3AED,#5b21b6)':'var(--bg-input)', color:tab===t?'#fff':'var(--text-muted)', border:`1px solid ${tab===t?'transparent':'var(--border)'}` }}>{t}</button>
        ))}
      </div>

      {loading ? <div className="text-center py-12" style={{ color:'var(--text-muted)' }}>Loading…</div> : (
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
          {jobs.map(job=>{
            const ss = STATUS_S(job.status)
            const tc = TYPE_COLOR[job.job_type]||'#7C3AED'
            const pct = job.number_of_openings ? Math.min(100, Math.round((job.applicant_count/(job.number_of_openings*10))*100)) : 0
            return(
              <div key={job.id} className="card-3d flex flex-col" style={{ padding:'20px' }}>
                <div className="flex items-start justify-between mb-3">
                  <div>
                    <p className="font-bold" style={{ color:'var(--text-h)' }}>{job.title}</p>
                    <p className="text-xs mt-0.5" style={{ color:'var(--text-muted)' }}>{job.department}</p>
                  </div>
                  <span className="text-[10px] font-bold px-2.5 py-1 rounded-xl flex-shrink-0" style={{ background:ss.bg, color:ss.c }}>{job.status}</span>
                </div>
                <div className="flex gap-2 flex-wrap mb-3">
                  <span className="text-[10px] font-bold px-2.5 py-1 rounded-lg" style={{ background:`${tc}15`, color:tc }}>{job.job_type}</span>
                  <span className="text-[10px] flex items-center gap-1" style={{ color:'var(--text-muted)' }}><MapPin size={9}/>{job.location}</span>
                </div>
                {/* Applicants progress */}
                <div className="mb-3">
                  <div className="flex justify-between text-[10px] mb-1" style={{ color:'var(--text-muted)' }}>
                    <span className="flex items-center gap-1"><Users size={9}/>{job.applicant_count} applicants</span>
                    <span>{job.number_of_openings} opening{job.number_of_openings>1?'s':''}</span>
                  </div>
                  <div className="h-1.5 rounded-full" style={{ background:'var(--bg-input)' }}>
                    <div className="h-full rounded-full" style={{ width:`${pct}%`, background:'linear-gradient(90deg,#a78bfa,#7C3AED)' }}/>
                  </div>
                </div>
                <div className="flex items-center justify-between text-[10px] mb-4" style={{ color:'var(--text-muted)' }}>
                  <span className="flex items-center gap-1"><Calendar size={9}/> Closes {fmtDate(job.closing_date)}</span>
                  <span>{fmtSalary(job.salary_from, job.salary_to)}</span>
                </div>
                {/* Source tags */}
                {job.sources?.length > 0 && (
                  <div className="flex gap-1.5 flex-wrap mb-3">
                    {job.sources.map(s=><span key={s} className="text-[9px] font-semibold px-2 py-0.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}>{s}</span>)}
                  </div>
                )}
                <div className="flex gap-2 mt-auto">
                  {job.status !== 'Closed' && <button onClick={()=>handleClose(job.id)} className="flex-1 py-2 rounded-xl text-[10px] font-bold" style={{ background:'rgba(239,68,68,0.08)', color:'#f87171', border:'1px solid rgba(239,68,68,0.15)' }}>Close Job</button>}
                  {job.status === 'Closed' && <span className="flex-1 text-center text-[10px] py-2 font-semibold" style={{ color:'var(--text-muted)' }}>Closed</span>}
                </div>
              </div>
            )
          })}
          {jobs.length===0 && <div className="col-span-3 text-center py-12" style={{ color:'var(--text-muted)' }}>No job postings found.</div>}
        </div>
      )}

      {/* Post Job Modal */}
      {showModal && (
        <div className="modal-backdrop" onClick={()=>setShowModal(false)}>
          <div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()} style={{ maxHeight:'90vh', overflowY:'auto' }}>
            <div className="flex items-center justify-between mb-5"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Post New Job</h2><button onClick={()=>setShowModal(false)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
            <div className="space-y-3">
              <div><label className="label">Job Title *</label><input className="input-3d text-sm" placeholder="e.g. Senior React Developer" value={form.title} onChange={e=>setForm({...form,title:e.target.value})}/></div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Department *</label>
                  <select className="input-3d text-sm" value={form.department} onChange={e=>setForm({...form,department:e.target.value})}>
                    <option value="">Select...</option>
                    {['Engineering','Sales','HR','Operations','Finance','Product','Marketing'].map(d=><option key={d}>{d}</option>)}
                  </select>
                </div>
                <div><label className="label">Location *</label><input className="input-3d text-sm" placeholder="Bangalore / Remote" value={form.location} onChange={e=>setForm({...form,location:e.target.value})}/></div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Job Type</label>
                  <select className="input-3d text-sm" value={form.job_type} onChange={e=>setForm({...form,job_type:e.target.value})}>
                    {['Full-time','Part-time','Contract','Internship','Remote'].map(t=><option key={t}>{t}</option>)}
                  </select>
                </div>
                <div><label className="label">Posting Type</label>
                  <select className="input-3d text-sm" value={form.posting_type} onChange={e=>setForm({...form,posting_type:e.target.value})}>
                    {['Both','Internal','External'].map(t=><option key={t}>{t}</option>)}
                  </select>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Salary From (₹)</label><input type="number" className="input-3d text-sm" placeholder="500000" value={form.salary_from} onChange={e=>setForm({...form,salary_from:e.target.value})}/></div>
                <div><label className="label">Salary To (₹)</label><input type="number" className="input-3d text-sm" placeholder="1200000" value={form.salary_to} onChange={e=>setForm({...form,salary_to:e.target.value})}/></div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">No. of Openings</label><input type="number" min="1" className="input-3d text-sm" value={form.number_of_openings} onChange={e=>setForm({...form,number_of_openings:parseInt(e.target.value)||1})}/></div>
                <div><label className="label">Closing Date</label><input type="date" className="input-3d text-sm" value={form.closing_date} onChange={e=>setForm({...form,closing_date:e.target.value})}/></div>
              </div>
              <div>
                <label className="label">Description</label>
                <textarea rows={2} className="input-3d text-sm resize-none" placeholder="Job description..." value={form.description} onChange={e=>setForm({...form,description:e.target.value})}/>
              </div>
              <div>
                <label className="label">Posting Sources</label>
                <div className="flex gap-2 flex-wrap">
                  {['LinkedIn','Naukri','Career Page','Internal Portal','Employee Referral'].map(s=>{
                    const selected = (form.sources||[]).includes(s)
                    return <button key={s} onClick={()=>toggleSource(s)} type="button" className="px-3 py-1.5 rounded-xl text-[10px] font-bold transition-all" style={{ background:selected?'rgba(124,58,237,0.2)':'var(--bg-input)', color:selected?'#a78bfa':'var(--text-muted)', border:`1px solid ${selected?'rgba(124,58,237,0.4)':'var(--border)'}` }}>{s}</button>
                  })}
                </div>
              </div>
              <div className="flex gap-3 pt-1">
                <button onClick={()=>setShowModal(false)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
                <button onClick={handleCreate} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity:saving?0.7:1 }}>{saving?'Posting…':'Post Job'}</button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
