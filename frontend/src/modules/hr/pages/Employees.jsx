import { useState, useEffect } from 'react'
import { useTheme } from '@/context/ThemeContext'
import { Search, Mail, Phone, Building2, Plus, X } from 'lucide-react'
import { hrApi } from '@/services/hrApi'

const DEPT_COLORS = { Engineering:'#3b82f6', Sales:'#10b981', HR:'#7C3AED', Operations:'#f59e0b', Product:'#ec4899', Marketing:'#f97316', Finance:'#6366f1' }
const STATUS_S = s => s==='Active'?{c:'#10b981',bg:'rgba(16,185,129,0.12)'}:s==='On Leave'?{c:'#f59e0b',bg:'rgba(245,158,11,0.12)'}:{c:'#f87171',bg:'rgba(239,68,68,0.1)'}
const initials = n => (n||'').split(' ').slice(0,2).map(x=>x[0]).join('').toUpperCase()
const fmtDate  = d => d ? new Date(d).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—'

const EMPTY_FORM = { name:'', email:'', phone:'', department:'', designation:'', reporting_manager_name:'', joining_date:'', status:'Active' }

export default function Employees() {
  const { isDark } = useTheme()
  const [employees, setEmployees] = useState([])
  const [stats, setStats]         = useState({ total:0, active:0, on_leave:0, by_dept:[] })
  const [loading, setLoading]     = useState(true)
  const [search, setSearch]       = useState('')
  const [deptF, setDeptF]         = useState('All')
  const [selected, setSelected]   = useState(null)
  const [showModal, setShowModal] = useState(false)
  const [form, setForm]           = useState(EMPTY_FORM)
  const [saving, setSaving]       = useState(false)
  const [toast, setToast]         = useState(null)

  const showToast = (msg, type='success') => { setToast({msg,type}); setTimeout(()=>setToast(null),3000) }

  const fetchData = async () => {
    setLoading(true)
    try {
      const params = {}
      if (deptF!=='All') params.department=deptF
      if (search) params.search=search
      const [emps, st] = await Promise.all([hrApi.employees.list(params), hrApi.employees.stats()])
      setEmployees(emps); setStats(st)
    } catch { showToast('Failed to load employees','error') }
    finally { setLoading(false) }
  }
  useEffect(()=>{ fetchData() },[deptF, search])

  const handleCreate = async () => {
    if (!form.name||!form.department||!form.designation||!form.joining_date) return showToast('Name, department, designation & joining date required','error')
    setSaving(true)
    try {
      const emp = await hrApi.employees.create(form)
      setEmployees(prev=>[emp,...prev])
      setStats(prev=>({...prev,total:prev.total+1,active:prev.active+1}))
      setShowModal(false); setForm(EMPTY_FORM)
      showToast('Employee added!')
    } catch (e) { showToast(e.response?.data?.message||'Failed','error') }
    finally { setSaving(false) }
  }

  const handleDelete = async (id) => {
    if (!window.confirm('Remove this employee?')) return
    try {
      await hrApi.employees.delete(id)
      setEmployees(prev=>prev.filter(e=>e.id!==id))
      showToast('Employee removed')
    } catch { showToast('Failed','error') }
  }

  const depts = ['All', ...new Set(employees.map(e=>e.department).filter(Boolean))]

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl" style={{ background:toast.type==='success'?'linear-gradient(135deg,#10b981,#059669)':'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      <div className="flex items-center justify-between flex-wrap gap-3">
        <div><p className="label-caps mb-1">HR Module</p><h1 className="font-black" style={{ fontSize:'clamp(1.3rem,2vw,1.7rem)', color:'var(--text-h)', letterSpacing:'-0.02em' }}>Employee <span className="text-gradient">Records</span></h1></div>
        <button onClick={()=>setShowModal(true)} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow:'0 4px 14px rgba(124,58,237,0.4)' }}><Plus size={15}/> Add Employee</button>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-3 gap-4">
        {[{l:'Total',v:stats.total,c:'#7C3AED'},{l:'Active',v:stats.active,c:'#10b981'},{l:'On Leave',v:stats.on_leave,c:'#f59e0b'}].map(k=>(
          <div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-sm font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>
        ))}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-4 gap-5">
        <div className="lg:col-span-3 space-y-4">
          {/* Filters */}
          <div className="flex gap-3 flex-wrap items-center">
            <div className="relative flex-1 min-w-[180px]">
              <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color:'var(--text-muted)' }}/>
              <input className="input-3d pl-9 text-sm" placeholder="Search by name, EMP ID, designation…" value={search} onChange={e=>setSearch(e.target.value)}/>
            </div>
            <div className="flex gap-2 flex-wrap">
              {depts.map(d=>(
                <button key={d} onClick={()=>setDeptF(d)} className="px-3 py-1.5 rounded-xl text-xs font-bold transition-all" style={{ background:deptF===d?'linear-gradient(135deg,#7C3AED,#5b21b6)':'var(--bg-input)', color:deptF===d?'#fff':'var(--text-muted)', border:`1px solid ${deptF===d?'transparent':'var(--border)'}` }}>{d}</button>
              ))}
            </div>
          </div>

          {/* Employee cards */}
          {loading ? <div className="text-center py-12" style={{ color:'var(--text-muted)' }}>Loading…</div> : (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {employees.map(emp=>{
                const dc = DEPT_COLORS[emp.department]||'#7C3AED'
                const ss = STATUS_S(emp.status)
                return(
                  <div key={emp.id} onClick={()=>setSelected(selected===emp.id?null:emp.id)} className="card-3d cursor-pointer" style={{ padding:'18px' }}>
                    <div className="flex items-start gap-3">
                      <div className="w-12 h-12 rounded-2xl flex items-center justify-center text-sm font-black text-white flex-shrink-0" style={{ background:`linear-gradient(145deg,${dc}cc,${dc})`, boxShadow:`0 6px 18px ${dc}40` }}>{initials(emp.name)}</div>
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2 flex-wrap">
                          <p className="font-bold text-sm" style={{ color:'var(--text-h)' }}>{emp.name}</p>
                          <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:ss.bg, color:ss.c }}>{emp.status}</span>
                        </div>
                        <p className="text-xs mt-0.5" style={{ color:'var(--text-muted)' }}>{emp.designation}</p>
                        <div className="flex items-center gap-2 mt-1.5">
                          <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${dc}18`, color:dc }}>{emp.department}</span>
                          <span className="text-[10px] font-semibold font-mono" style={{ color:'var(--text-muted)' }}>{emp.employee_code}</span>
                        </div>
                      </div>
                    </div>
                    {selected===emp.id && (
                      <div className="mt-3 pt-3 space-y-2" style={{ borderTop:'1px solid var(--border)' }}>
                        {emp.email && <div className="flex items-center gap-2"><Mail size={11} style={{ color:'var(--text-muted)' }}/><span className="text-xs" style={{ color:'var(--text-muted)' }}>{emp.email}</span></div>}
                        {emp.phone && <div className="flex items-center gap-2"><Phone size={11} style={{ color:'var(--text-muted)' }}/><span className="text-xs" style={{ color:'var(--text-muted)' }}>{emp.phone}</span></div>}
                        <div className="grid grid-cols-2 gap-2 mt-2">
                          <div className="px-2.5 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>Reports To</p><p className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{emp.reporting_manager_name||'—'}</p></div>
                          <div className="px-2.5 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>Joined</p><p className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{fmtDate(emp.joining_date)}</p></div>
                        </div>
                        <div className="flex justify-end mt-1">
                          <button onClick={(e)=>{e.stopPropagation(); handleDelete(emp.id)}} className="text-[10px] font-semibold px-3 py-1.5 rounded-xl" style={{ background:'rgba(239,68,68,0.1)', color:'#f87171' }}>Remove</button>
                        </div>
                      </div>
                    )}
                  </div>
                )
              })}
              {employees.length===0 && <div className="col-span-2 text-center py-12" style={{ color:'var(--text-muted)' }}>No employees found.</div>}
            </div>
          )}
        </div>

        {/* Department sidebar */}
        <div>
          <div className="card-3d" style={{ padding:'22px' }}>
            <h3 className="font-bold text-sm mb-4 flex items-center gap-2" style={{ color:'var(--text-h)' }}><Building2 size={14} style={{ color:'#a78bfa' }}/> By Department</h3>
            <div className="space-y-3">
              {(stats.by_dept||[]).sort((a,b)=>b.count-a.count).map(({department,count})=>{
                const color = DEPT_COLORS[department]||'#7C3AED'
                const pct = stats.total ? Math.round((count/stats.total)*100) : 0
                return(
                  <div key={department}>
                    <div className="flex justify-between mb-1.5">
                      <div className="flex items-center gap-2"><div className="w-2 h-2 rounded-full" style={{ background:color }}/><span className="text-xs font-semibold" style={{ color:'var(--text-muted)' }}>{department}</span></div>
                      <span className="text-xs font-black" style={{ color }}>{count}</span>
                    </div>
                    <div className="h-1.5 rounded-full" style={{ background:'var(--bg-input)' }}>
                      <div className="h-full rounded-full" style={{ width:`${pct}%`, background:color }}/>
                    </div>
                  </div>
                )
              })}
            </div>
          </div>
        </div>
      </div>

      {/* Add Employee Modal */}
      {showModal && (
        <div className="modal-backdrop" onClick={()=>setShowModal(false)}>
          <div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()}>
            <div className="flex items-center justify-between mb-5"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Add Employee</h2><button onClick={()=>setShowModal(false)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
            <div className="space-y-3">
              <div><label className="label">Full Name *</label><input className="input-3d text-sm" placeholder="Arjun Sharma" value={form.name} onChange={e=>setForm({...form,name:e.target.value})}/></div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Email</label><input type="email" className="input-3d text-sm" value={form.email} onChange={e=>setForm({...form,email:e.target.value})}/></div>
                <div><label className="label">Phone</label><input className="input-3d text-sm" value={form.phone} onChange={e=>setForm({...form,phone:e.target.value})}/></div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Department *</label>
                  <select className="input-3d text-sm" value={form.department} onChange={e=>setForm({...form,department:e.target.value})}>
                    <option value="">Select...</option>
                    {['Engineering','Sales','HR','Operations','Finance','Product','Marketing'].map(d=><option key={d}>{d}</option>)}
                  </select>
                </div>
                <div><label className="label">Designation *</label><input className="input-3d text-sm" value={form.designation} onChange={e=>setForm({...form,designation:e.target.value})}/></div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Reporting Manager</label><input className="input-3d text-sm" value={form.reporting_manager_name} onChange={e=>setForm({...form,reporting_manager_name:e.target.value})}/></div>
                <div><label className="label">Joining Date *</label><input type="date" className="input-3d text-sm" value={form.joining_date} onChange={e=>setForm({...form,joining_date:e.target.value})}/></div>
              </div>
              <div className="flex gap-3 pt-1">
                <button onClick={()=>setShowModal(false)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
                <button onClick={handleCreate} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity:saving?0.7:1 }}>{saving?'Saving…':'Add Employee'}</button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
