import { useState, useEffect } from 'react'
import { useTheme } from '@/context/ThemeContext'
import { Plus, X, Check } from 'lucide-react'
import { hrApi } from '@/services/hrApi'

const STEPS = [
  { key:'doc_verification',  label:'Document Verification',     icon:'📄' },
  { key:'joining_confirmed', label:'Joining Date Confirmed',    icon:'📅' },
  { key:'emp_id_generated',  label:'Employee ID Generated',     icon:'🪪' },
  { key:'dept_assigned',     label:'Department Assigned',       icon:'🏢' },
  { key:'manager_assigned',  label:'Reporting Manager Assigned',icon:'👤' },
  { key:'record_created',    label:'Employee Record Created',   icon:'✅' },
]
const STATUS_S = s => s==='Completed'?{c:'#10b981',bg:'rgba(16,185,129,0.12)'}:s==='In Progress'?{c:'#a78bfa',bg:'rgba(124,58,237,0.12)'}:{c:'#fbbf24',bg:'rgba(245,158,11,0.12)'}
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—'

export default function Onboarding() {
  const { isDark } = useTheme()
  const [records, setRecords]     = useState([])
  const [loading, setLoading]     = useState(true)
  const [filterS, setFilterS]     = useState('All')
  const [expanded, setExpanded]   = useState(null)
  const [showModal, setShowModal] = useState(false)
  const [form, setForm]           = useState({ candidate_name:'', position:'', joining_date:'', department:'' })
  const [saving, setSaving]       = useState(false)
  const [toast, setToast]         = useState(null)

  const showToast = (msg, type='success') => { setToast({msg,type}); setTimeout(()=>setToast(null),3000) }

  const fetchData = async () => {
    setLoading(true)
    try {
      const params = filterS!=='All'?{status:filterS}:{}
      const data = await hrApi.onboarding.list(params)
      setRecords(data)
    } catch { showToast('Failed to load onboarding','error') }
    finally { setLoading(false) }
  }
  useEffect(()=>{ fetchData() },[filterS])

  const DOC_ITEMS = ['offer_signed','id_proof','educational_certs','prev_employment_docs','bank_details','passport_photos']
  const DOC_LABELS = { offer_signed:'Offer Letter (Signed)', id_proof:'ID Proof (Aadhaar/PAN)', educational_certs:'Educational Certificates', prev_employment_docs:'Previous Employment Docs', bank_details:'Bank Account Details', passport_photos:'Passport Size Photos' }

  const handleToggle = async (id, step) => {
    try {
      const updated = await hrApi.onboarding.toggleStep(id, step)
      setRecords(prev => prev.map(r => r.id===id ? updated : r))
      if (updated.status==='Completed') showToast('🎉 Onboarding complete! Employee record created.')
    } catch { showToast('Failed to update step','error') }
  }

  const handleDocToggle = async (id, docKey, current) => {
    const rec = records.find(r=>r.id===id)
    if (!rec) return
    const checklist = { ...(rec.document_checklist||{}), [docKey]: !current }
    try {
      const updated = await hrApi.onboarding.updateChecklist(id, checklist)
      setRecords(prev => prev.map(r => r.id===id ? updated : r))
    } catch { showToast('Failed','error') }
  }

  const handleCreate = async () => {
    if (!form.candidate_name||!form.position||!form.joining_date) return showToast('Name, position & date required','error')
    setSaving(true)
    try {
      const rec = await hrApi.onboarding.start(form)
      setRecords(prev=>[rec,...prev])
      setShowModal(false); setForm({candidate_name:'',position:'',joining_date:'',department:''})
      showToast('Onboarding started!')
    } catch (e) { showToast(e.response?.data?.message||'Failed','error') }
    finally { setSaving(false) }
  }

  const getStepDone = (r, key) => r[`step_${key}`] || false
  const getDoneCount = r => STEPS.filter(s => getStepDone(r, s.key)).length

  const stats = { total:records.length, inProgress:records.filter(r=>r.status==='In Progress').length, completed:records.filter(r=>r.status==='Completed').length, pending:records.filter(r=>r.status==='Pending').length }

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl" style={{ background:toast.type==='success'?'linear-gradient(135deg,#10b981,#059669)':'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      <div className="flex items-center justify-between flex-wrap gap-3">
        <div><p className="label-caps mb-1">HR Module</p><h1 className="font-black" style={{ fontSize:'clamp(1.3rem,2vw,1.7rem)', color:'var(--text-h)', letterSpacing:'-0.02em' }}>Onboarding <span className="text-gradient">Tracker</span></h1></div>
        <button onClick={()=>setShowModal(true)} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow:'0 4px 14px rgba(124,58,237,0.4)' }}><Plus size={15}/> Start Onboarding</button>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {[{l:'Total',v:stats.total,c:'#7C3AED'},{l:'In Progress',v:stats.inProgress,c:'#a78bfa'},{l:'Completed',v:stats.completed,c:'#10b981'},{l:'Pending',v:stats.pending,c:'#fbbf24'}].map(k=>(
          <div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-sm font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>
        ))}
      </div>

      {/* Step legend */}
      <div className="card-3d" style={{ padding:'18px' }}>
        <p className="text-xs font-bold mb-3" style={{ color:'var(--text-h)' }}>Onboarding Process</p>
        <div className="flex gap-2 overflow-x-auto scrollbar-hide">
          {STEPS.map((s,i)=>(
            <div key={s.key} className="flex-shrink-0 flex items-center gap-2 px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
              <div className="w-6 h-6 rounded-lg flex items-center justify-center text-xs font-black text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>{i+1}</div>
              <span className="text-[10px] font-semibold" style={{ color:'var(--text-h)' }}>{s.label}</span>
            </div>
          ))}
        </div>
      </div>

      {/* Filters */}
      <div className="flex gap-2">
        {['All','In Progress','Completed','Pending'].map(f=>(
          <button key={f} onClick={()=>setFilterS(f)} className="px-3 py-1.5 rounded-xl text-xs font-bold transition-all"
            style={{ background:filterS===f?'linear-gradient(135deg,#7C3AED,#5b21b6)':'var(--bg-input)', color:filterS===f?'#fff':'var(--text-muted)', border:`1px solid ${filterS===f?'transparent':'var(--border)'}` }}>{f}</button>
        ))}
      </div>

      {loading ? <div className="text-center py-12" style={{ color:'var(--text-muted)' }}>Loading…</div> : (
        <div className="space-y-4">
          {records.map(r=>{
            const ss = STATUS_S(r.status)
            const done = getDoneCount(r)
            return(
              <div key={r.id} className="card-3d" style={{ padding:'20px' }}>
                <div className="flex items-start justify-between gap-4 flex-wrap mb-3">
                  <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-2xl flex items-center justify-center text-sm font-black text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
                      {(r.candidate_name||'?').split(' ').map(x=>x[0]).join('').toUpperCase()}
                    </div>
                    <div>
                      <p className="font-bold" style={{ color:'var(--text-h)' }}>{r.candidate_name}</p>
                      <p className="text-xs" style={{ color:'var(--text-muted)' }}>{r.position}{r.department?` · ${r.department}`:''} · Joining: {fmtDate(r.joining_date)}</p>
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="text-[10px] font-bold px-2.5 py-1 rounded-xl" style={{ background:ss.bg, color:ss.c }}>{r.status}</span>
                    <button onClick={()=>setExpanded(expanded===r.id?null:r.id)} className="text-xs px-3 py-1.5 rounded-xl font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>
                      {expanded===r.id?'Hide':'Steps'}
                    </button>
                  </div>
                </div>

                {/* Progress bar */}
                <div>
                  <div className="flex gap-0.5 mb-1.5">
                    {STEPS.map(s=>(
                      <div key={s.key} className="flex-1 h-2 rounded-full transition-all" style={{ background:getStepDone(r,s.key)?'linear-gradient(90deg,#a78bfa,#7C3AED)':'var(--bg-input)' }}/>
                    ))}
                  </div>
                  <p className="text-[10px]" style={{ color:'var(--text-muted)' }}>{done}/{STEPS.length} steps completed</p>
                </div>

                {/* Expanded steps */}
                {expanded===r.id && (
                  <div className="mt-4 grid grid-cols-2 md:grid-cols-3 gap-2.5">
                    {STEPS.map(s=>{
                      const isDone = getStepDone(r, s.key)
                      return(
                        <div key={s.key} onClick={()=>handleToggle(r.id, s.key)} className="px-3 py-2.5 rounded-xl cursor-pointer transition-all"
                          style={{ background:isDone?'rgba(16,185,129,0.1)':'var(--bg-input)', border:`1px solid ${isDone?'rgba(16,185,129,0.3)':'var(--border)'}` }}>
                          <div className="flex items-center gap-2 mb-1">
                            <span className="text-base">{s.icon}</span>
                            <div className="w-5 h-5 rounded-lg flex items-center justify-center" style={{ background:isDone?'rgba(16,185,129,0.2)':'var(--bg-card)' }}>
                              {isDone?<Check size={10} style={{ color:'#10b981' }}/>:<div className="w-2 h-2 rounded-full" style={{ background:'var(--border)' }}/>}
                            </div>
                          </div>
                          <p className="text-[10px] font-bold" style={{ color:isDone?'#10b981':'var(--text-muted)' }}>{s.label}</p>
                        </div>
                      )
                    })}
                  </div>

                  {/* Document Checklist */}
                  <div className="mt-4 pt-3" style={{ borderTop:'1px solid var(--border)' }}>
                    <p className="text-xs font-bold mb-2" style={{ color:'var(--text-h)' }}>📋 Document Checklist</p>
                    <div className="grid grid-cols-2 gap-2">
                      {DOC_ITEMS.map(docKey=>{
                        const checked = !!(r.document_checklist?.[docKey])
                        return(
                          <div key={docKey} onClick={()=>handleDocToggle(r.id,docKey,checked)} className="flex items-center gap-2 px-2.5 py-2 rounded-xl cursor-pointer transition-all"
                            style={{ background:checked?'rgba(16,185,129,0.08)':'var(--bg-input)', border:`1px solid ${checked?'rgba(16,185,129,0.25)':'var(--border)'}` }}>
                            <div className="w-4 h-4 rounded flex items-center justify-center flex-shrink-0" style={{ background:checked?'rgba(16,185,129,0.2)':'var(--bg-card)', border:`1px solid ${checked?'#10b981':'var(--border)'}` }}>
                              {checked && <Check size={8} style={{ color:'#10b981' }}/>}
                            </div>
                            <span className="text-[10px] font-semibold" style={{ color:checked?'#10b981':'var(--text-muted)' }}>{DOC_LABELS[docKey]}</span>
                          </div>
                        )
                      })}
                    </div>
                  </div>
                )}
              </div>
            )
          })}
          {records.length===0 && <p className="text-center py-10" style={{ color:'var(--text-muted)' }}>No onboarding records found.</p>}
        </div>
      )}

      {/* Start Onboarding Modal */}
      {showModal && (
        <div className="modal-backdrop" onClick={()=>setShowModal(false)}>
          <div className="modal-box max-w-md" onClick={e=>e.stopPropagation()}>
            <div className="flex items-center justify-between mb-5"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Start Onboarding</h2><button onClick={()=>setShowModal(false)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
            <div className="space-y-3">
              <div><label className="label">Candidate Name *</label><input className="input-3d text-sm" placeholder="Full name" value={form.candidate_name} onChange={e=>setForm({...form,candidate_name:e.target.value})}/></div>
              <div><label className="label">Position *</label><input className="input-3d text-sm" placeholder="Job title" value={form.position} onChange={e=>setForm({...form,position:e.target.value})}/></div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Department</label><input className="input-3d text-sm" placeholder="Department" value={form.department} onChange={e=>setForm({...form,department:e.target.value})}/></div>
                <div><label className="label">Joining Date *</label><input type="date" className="input-3d text-sm" value={form.joining_date} onChange={e=>setForm({...form,joining_date:e.target.value})}/></div>
              </div>
              <div className="flex gap-3 pt-1">
                <button onClick={()=>setShowModal(false)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
                <button onClick={handleCreate} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity:saving?0.7:1 }}>{saving?'Starting…':'Start Onboarding'}</button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
