import { useState, useEffect, useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTheme } from '@/context/ThemeContext'
import { Search, Building2, Plus, X, LayoutGrid, List, Eye, Pencil } from 'lucide-react'
import { hrApi } from '@/services/hrApi'

const DEPT_COLORS = { Engineering:'#3b82f6', Sales:'#10b981', HR:'#7C3AED', Operations:'#f59e0b', Product:'#ec4899', Marketing:'#f97316', Finance:'#6366f1' }
const STATUS_S = s => s==='Active'?{c:'#10b981',bg:'rgba(16,185,129,0.12)'}:s==='On Leave'?{c:'#f59e0b',bg:'rgba(245,158,11,0.12)'}:{c:'#f87171',bg:'rgba(239,68,68,0.1)'}
const initials = n => (n||'').split(' ').slice(0,2).map(x=>x[0]).join('').toUpperCase()
const fmtDate  = d => d ? new Date(d).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—'
const deptColor = d => DEPT_COLORS[d]||'#7C3AED'

const EMPTY_FORM = { name:'', email:'', phone:'', dob:'', gender:'', address:'', department:'', designation:'', reporting_manager_name:'', joining_date:'', probation_end_date:'', confirmation_date:'', status:'Active' }

// Avatar built from initials (no photo store) — consistent across card & list.
const Avatar = ({ name, dept, size=44 }) => {
  const dc = deptColor(dept)
  return <div className="rounded-2xl flex items-center justify-center font-black text-white flex-shrink-0" style={{ width:size, height:size, fontSize:size*0.3, background:`linear-gradient(145deg,${dc}cc,${dc})`, boxShadow:`0 6px 18px ${dc}40` }}>{initials(name)}</div>
}

export default function Employees() {
  const { isDark } = useTheme()
  const navigate = useNavigate()
  const [employees, setEmployees] = useState([])
  const [optionsList, setOptionsList] = useState([])   // unfiltered — powers filter dropdowns
  const [stats, setStats]         = useState({ total:0, active:0, on_leave:0, by_dept:[] })
  const [loading, setLoading]     = useState(true)
  const [viewMode, setViewMode]   = useState('card')   // 'card' | 'list'

  // Filters
  const [search, setSearch]       = useState('')
  const [deptF, setDeptF]         = useState('All')
  const [desigF, setDesigF]       = useState('All')
  const [statusF, setStatusF]     = useState('All')
  const [joinedFrom, setJoinedFrom] = useState('')

  const [showModal, setShowModal] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [form, setForm]           = useState(EMPTY_FORM)
  const [saving, setSaving]       = useState(false)
  const [toast, setToast]         = useState(null)

  const showToast = (msg, type='success') => { setToast({msg,type}); setTimeout(()=>setToast(null),3000) }

  const fetchData = async () => {
    setLoading(true)
    try {
      const params = {}
      if (deptF!=='All') params.department = deptF
      if (desigF!=='All') params.designation = desigF
      if (statusF!=='All') params.status = statusF
      if (joinedFrom) params.joined_from = joinedFrom
      if (search) params.search = search
      const [emps, st] = await Promise.all([hrApi.employees.list(params), hrApi.employees.stats()])
      setEmployees(emps); setStats(st)
    } catch { showToast('Failed to load employees','error') }
    finally { setLoading(false) }
  }
  useEffect(()=>{ fetchData() },[deptF, desigF, statusF, joinedFrom, search])
  useEffect(()=>{ hrApi.employees.list().then(setOptionsList).catch(()=>{}) },[])

  const departments = useMemo(()=>['All', ...new Set(optionsList.map(e=>e.department).filter(Boolean))], [optionsList])
  const designations = useMemo(()=>['All', ...new Set(optionsList.map(e=>e.designation).filter(Boolean))], [optionsList])

  const openCreate = () => { setEditingId(null); setForm(EMPTY_FORM); setShowModal(true) }
  const openEdit = (emp) => {
    setEditingId(emp.id)
    setForm({ ...EMPTY_FORM, ...Object.fromEntries(Object.keys(EMPTY_FORM).map(k=>[k, emp[k] ?? (k==='status'?'Active':'')])) })
    setShowModal(true)
  }
  const openProfile = (id) => navigate(`/app/hr/employees/${id}`)

  const handleSave = async () => {
    if (!form.name||!form.department||!form.designation||!form.joining_date) return showToast('Name, department, designation & joining date required','error')
    setSaving(true)
    try {
      if (editingId) {
        const emp = await hrApi.employees.update(editingId, form)
        setEmployees(prev=>prev.map(e=>e.id===editingId?emp:e))
        showToast('Employee updated!')
      } else {
        const emp = await hrApi.employees.create(form)
        setEmployees(prev=>[emp,...prev])
        setStats(prev=>({...prev,total:prev.total+1,active:prev.active+1}))
        showToast('Employee added!')
      }
      setShowModal(false); setForm(EMPTY_FORM); setEditingId(null)
    } catch (e) { showToast(e.response?.data?.message||'Failed','error') }
    finally { setSaving(false) }
  }

  const resetFilters = () => { setDeptF('All'); setDesigF('All'); setStatusF('All'); setJoinedFrom(''); setSearch('') }
  const hasFilters = deptF!=='All'||desigF!=='All'||statusF!=='All'||joinedFrom||search

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl" style={{ background:toast.type==='success'?'linear-gradient(135deg,#10b981,#059669)':'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      <div className="flex items-center justify-between flex-wrap gap-3">
        <div><p className="label-caps mb-1">HR Module</p><h1 className="font-black" style={{ fontSize:'clamp(1.3rem,2vw,1.7rem)', color:'var(--text-h)', letterSpacing:'-0.02em' }}>Employee <span className="text-gradient">Management</span></h1></div>
        <div className="flex items-center gap-2">
          {/* Card / List view toggle */}
          <div className="flex rounded-xl overflow-hidden" style={{ border:'1px solid var(--border)' }}>
            {[{k:'card',I:LayoutGrid},{k:'list',I:List}].map(v=>(
              <button key={v.k} onClick={()=>setViewMode(v.k)} className="px-3 py-2.5 flex items-center" title={`${v.k} view`}
                style={{ background: viewMode===v.k ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)', color: viewMode===v.k ? '#fff' : 'var(--text-muted)' }}>
                <v.I size={15}/>
              </button>
            ))}
          </div>
          <button onClick={openCreate} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow:'0 4px 14px rgba(124,58,237,0.4)' }}><Plus size={15}/> Add Employee</button>
        </div>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-3 gap-4">
        {[{l:'Total',v:stats.total,c:'#7C3AED'},{l:'Active',v:stats.active,c:'#10b981'},{l:'On Leave',v:stats.on_leave,c:'#f59e0b'}].map(k=>(
          <div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-sm font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>
        ))}
      </div>

      {/* Search & Filters */}
      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[200px]">
            <label className="label">Search</label>
            <Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/>
            <input className="input-3d pl-9 text-sm" placeholder="Name, Employee ID, email, department…" value={search} onChange={e=>setSearch(e.target.value)}/>
          </div>
          <div className="min-w-[140px]">
            <label className="label">Department</label>
            <select className="input-3d text-sm" value={deptF} onChange={e=>setDeptF(e.target.value)}>{departments.map(d=><option key={d}>{d}</option>)}</select>
          </div>
          <div className="min-w-[140px]">
            <label className="label">Designation</label>
            <select className="input-3d text-sm" value={desigF} onChange={e=>setDesigF(e.target.value)}>{designations.map(d=><option key={d}>{d}</option>)}</select>
          </div>
          <div className="min-w-[120px]">
            <label className="label">Status</label>
            <select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['All','Active','On Leave','Inactive'].map(s=><option key={s}>{s}</option>)}</select>
          </div>
          <div className="min-w-[140px]">
            <label className="label">Joined on/after</label>
            <input type="date" className="input-3d text-sm" value={joinedFrom} onChange={e=>setJoinedFrom(e.target.value)}/>
          </div>
          {hasFilters && <button onClick={resetFilters} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
        </div>
      </div>

      {loading ? <div className="text-center py-12" style={{ color:'var(--text-muted)' }}>Loading…</div>
        : employees.length===0 ? <div className="text-center py-12" style={{ color:'var(--text-muted)' }}>No employees found.</div>
        : viewMode==='card' ? (
        /* ── CARD VIEW ── */
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
          {employees.map(emp=>{
            const ss = STATUS_S(emp.status)
            return(
              <div key={emp.id} className="card-3d flex flex-col" style={{ padding:'20px' }}>
                <div className="flex items-start gap-3 mb-3">
                  <Avatar name={emp.name} dept={emp.department} size={48}/>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                      <p className="font-bold text-sm" style={{ color:'var(--text-h)' }}>{emp.name}</p>
                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:ss.bg, color:ss.c }}>{emp.status}</span>
                    </div>
                    <p className="text-xs mt-0.5" style={{ color:'var(--text-muted)' }}>{emp.designation}</p>
                    <span className="text-[10px] font-semibold font-mono" style={{ color:'var(--text-muted)' }}>{emp.employee_code}</span>
                  </div>
                </div>
                <div className="grid grid-cols-2 gap-2 mb-4">
                  <div className="px-2.5 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>Department</p><p className="text-xs font-bold mt-0.5" style={{ color:deptColor(emp.department) }}>{emp.department||'—'}</p></div>
                  <div className="px-2.5 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>Joined</p><p className="text-xs font-bold mt-0.5" style={{ color:'var(--text-h)' }}>{fmtDate(emp.joining_date)}</p></div>
                  <div className="px-2.5 py-2 rounded-xl col-span-2" style={{ background:'var(--bg-input)' }}><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>Reporting Manager</p><p className="text-xs font-semibold mt-0.5" style={{ color:'var(--text-h)' }}>{emp.reporting_manager_name||'—'}</p></div>
                </div>
                <div className="flex gap-2 mt-auto">
                  <button onClick={()=>openProfile(emp.id)} className="flex-1 flex items-center justify-center gap-1.5 py-2 rounded-xl text-xs font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)' }}><Eye size={12}/> View Profile</button>
                  <button onClick={()=>openEdit(emp)} className="flex items-center justify-center gap-1.5 py-2 px-3 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><Pencil size={12}/> Edit</button>
                </div>
              </div>
            )
          })}
        </div>
      ) : (
        /* ── LIST VIEW ── */
        <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
          <table className="w-full text-sm" style={{ minWidth:820 }}>
            <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee ID','Employee','Department','Designation','Status','Reporting Manager','Joining Date','Actions'].map(h=><th key={h} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
            <tbody>
              {employees.map(emp=>{
                const ss = STATUS_S(emp.status)
                return(
                  <tr key={emp.id} className="cursor-pointer" onClick={()=>openProfile(emp.id)} style={{ borderBottom:'1px solid var(--border)' }}
                    onMouseEnter={e=>e.currentTarget.style.background='rgba(124,58,237,0.04)'} onMouseLeave={e=>e.currentTarget.style.background='transparent'}>
                    <td className="px-3 py-2.5 font-mono font-bold whitespace-nowrap" style={{ color:'#a78bfa' }}>{emp.employee_code}</td>
                    <td className="px-3 py-2.5"><div className="flex items-center gap-2.5"><Avatar name={emp.name} dept={emp.department} size={34}/><span className="font-semibold" style={{ color:'var(--text-h)' }}>{emp.name}</span></div></td>
                    <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${deptColor(emp.department)}18`, color:deptColor(emp.department) }}>{emp.department||'—'}</span></td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{emp.designation||'—'}</td>
                    <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:ss.bg, color:ss.c }}>{emp.status}</span></td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{emp.reporting_manager_name||'—'}</td>
                    <td className="px-3 py-2.5 whitespace-nowrap" style={{ color:'var(--text-muted)' }}>{fmtDate(emp.joining_date)}</td>
                    <td className="px-3 py-2.5" onClick={e=>e.stopPropagation()}>
                      <div className="flex gap-1.5">
                        <button onClick={()=>openProfile(emp.id)} title="View profile" className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Eye size={13}/></button>
                        <button onClick={()=>openEdit(emp)} title="Edit" className="p-1.5 rounded-lg" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><Pencil size={13}/></button>
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      {/* Department distribution */}
      <div className="card-3d" style={{ padding:'22px' }}>
        <h3 className="font-bold text-sm mb-4 flex items-center gap-2" style={{ color:'var(--text-h)' }}><Building2 size={14} style={{ color:'#a78bfa' }}/> By Department</h3>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-3">
          {(stats.by_dept||[]).sort((a,b)=>b.count-a.count).map(({department,count})=>{
            const color = deptColor(department)
            const pct = stats.total ? Math.round((count/stats.total)*100) : 0
            return(
              <div key={department||'—'}>
                <div className="flex justify-between mb-1.5">
                  <div className="flex items-center gap-2"><div className="w-2 h-2 rounded-full" style={{ background:color }}/><span className="text-xs font-semibold" style={{ color:'var(--text-muted)' }}>{department||'Unassigned'}</span></div>
                  <span className="text-xs font-black" style={{ color }}>{count}</span>
                </div>
                <div className="h-1.5 rounded-full" style={{ background:'var(--bg-input)' }}><div className="h-full rounded-full" style={{ width:`${pct}%`, background:color }}/></div>
              </div>
            )
          })}
        </div>
      </div>

      {/* Add / Edit Employee Modal */}
      {showModal && (
        <div className="modal-backdrop" onClick={()=>setShowModal(false)}>
          <div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()} style={{ maxHeight:'90vh', overflowY:'auto' }}>
            <div className="flex items-center justify-between mb-5"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{editingId?'Edit Employee':'Add Employee'}</h2><button onClick={()=>setShowModal(false)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
            <div className="space-y-3">
              <div><label className="label">Full Name *</label><input className="input-3d text-sm" placeholder="Arjun Sharma" value={form.name} onChange={e=>setForm({...form,name:e.target.value})}/></div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Email</label><input type="email" className="input-3d text-sm" value={form.email} onChange={e=>setForm({...form,email:e.target.value})}/></div>
                <div><label className="label">Phone</label><input className="input-3d text-sm" value={form.phone} onChange={e=>setForm({...form,phone:e.target.value})}/></div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Date of Birth</label><input type="date" className="input-3d text-sm" value={form.dob||''} onChange={e=>setForm({...form,dob:e.target.value})}/></div>
                <div><label className="label">Gender</label>
                  <select className="input-3d text-sm" value={form.gender||''} onChange={e=>setForm({...form,gender:e.target.value})}>
                    <option value="">Select...</option>
                    {['Male','Female','Other','Prefer not to say'].map(g=><option key={g}>{g}</option>)}
                  </select>
                </div>
              </div>
              <div><label className="label">Address</label><textarea rows={2} className="input-3d text-sm resize-none" value={form.address||''} onChange={e=>setForm({...form,address:e.target.value})}/></div>
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
                <div><label className="label">Reporting Manager</label><input className="input-3d text-sm" value={form.reporting_manager_name||''} onChange={e=>setForm({...form,reporting_manager_name:e.target.value})}/></div>
                <div><label className="label">Joining Date *</label><input type="date" className="input-3d text-sm" value={form.joining_date||''} onChange={e=>setForm({...form,joining_date:e.target.value})}/></div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="label">Probation End Date</label><input type="date" className="input-3d text-sm" value={form.probation_end_date||''} onChange={e=>setForm({...form,probation_end_date:e.target.value})}/></div>
                <div><label className="label">Confirmation Date</label><input type="date" className="input-3d text-sm" value={form.confirmation_date||''} onChange={e=>setForm({...form,confirmation_date:e.target.value})}/></div>
              </div>
              {editingId && <div><label className="label">Status</label><select className="input-3d text-sm" value={form.status} onChange={e=>setForm({...form,status:e.target.value})}>{['Active','On Leave','Inactive'].map(s=><option key={s}>{s}</option>)}</select></div>}
              <div className="flex gap-3 pt-1">
                <button onClick={()=>setShowModal(false)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
                <button onClick={handleSave} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity:saving?0.7:1 }}>{saving?'Saving…':editingId?'Save Changes':'Add Employee'}</button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
