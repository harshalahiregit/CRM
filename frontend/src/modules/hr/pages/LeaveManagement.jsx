import { useState, useEffect, useCallback } from 'react'
import { useTheme } from '@/context/ThemeContext'
import {
  CalendarDays, Tag, FileText, Wallet, CalendarCheck, CheckSquare, CalendarRange,
  Lock, Plus, Pencil, X, Power, Search, UserPlus, SlidersHorizontal, History,
  Check, Ban, Eye, Paperclip, Send, LayoutGrid, List, ChevronLeft, ChevronRight, BarChart3,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import LeaveReports from './LeaveReports'

const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'
const CATEGORIES = ['Casual', 'Sick', 'Earned', 'Maternity', 'Paternity', 'Restricted', 'Unpaid']
const CAT_C = { Casual:'#10b981', Sick:'#f59e0b', Earned:'#3b82f6', Maternity:'#ec4899', Paternity:'#8b5cf6', Restricted:'#f97316', Unpaid:'#94a3b8' }

const TABS = [
  { key:'types',    label:'Leave Types',    icon:Tag,           ready:true },
  { key:'policies', label:'Leave Policies', icon:FileText,      ready:true },
  { key:'balance',  label:'Leave Balance',  icon:Wallet,        ready:true },
  { key:'apply',    label:'Apply Leave',    icon:CalendarCheck, ready:true },
  { key:'approval', label:'Leave Approval', icon:CheckSquare,   ready:true },
  { key:'calendar', label:'Holiday Calendar', icon:CalendarRange, ready:true },
  { key:'reports',  label:'Leave Reports',  icon:BarChart3,     ready:true },
]

export default function LeaveManagement() {
  useTheme()
  const [tab, setTab] = useState('types')
  const [toast, setToast] = useState(null)
  const showToast = (msg, type='success') => { setToast({ msg, type }); setTimeout(() => setToast(null), 3000) }
  const current = TABS.find(t => t.key === tab)

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl" style={{ background:toast.type==='success'?'linear-gradient(135deg,#10b981,#059669)':'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <p className="label-caps mb-1">HR Records</p>
          <h1 className="font-black flex items-center gap-2" style={{ fontSize:'clamp(1.3rem,2vw,1.7rem)', color:'var(--text-h)', letterSpacing:'-0.02em' }}>
            <CalendarDays size={22} style={{ color:'#a78bfa' }}/> Leave <span className="text-gradient">Management</span>
          </h1>
        </div>
      </div>

      <div className="flex gap-1.5 flex-wrap">
        {TABS.map(t => {
          const active = tab === t.key
          return (
            <button key={t.key} onClick={()=>setTab(t.key)} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold transition-all"
              style={{ background: active ? GRAD : 'var(--bg-input)', color: active ? '#fff' : 'var(--text-muted)', border: active ? 'none' : '1px solid var(--border)' }}>
              <t.icon size={15}/> {t.label}{!t.ready && <Lock size={11} style={{ opacity:0.7 }}/>}
            </button>
          )
        })}
      </div>

      {tab === 'types' ? <LeaveTypes showToast={showToast} />
        : tab === 'policies' ? <LeavePolicies showToast={showToast} />
        : tab === 'balance' ? <LeaveBalance showToast={showToast} />
        : tab === 'apply' ? <ApplyLeave showToast={showToast} />
        : tab === 'approval' ? <LeaveApproval showToast={showToast} />
        : tab === 'calendar' ? <HolidayCalendar showToast={showToast} />
        : tab === 'reports' ? <LeaveReports showToast={showToast} />
        : (
          <div className="card-3d flex flex-col items-center justify-center text-center" style={{ padding:'56px 20px' }}>
            <div className="rounded-2xl flex items-center justify-center mb-3" style={{ width:60, height:60, background:'rgba(124,58,237,0.1)' }}><current.icon size={26} style={{ color:'#a78bfa' }}/></div>
            <p className="text-sm font-black" style={{ color:'var(--text-h)' }}>{current.label}</p>
            <p className="text-xs mt-1" style={{ color:'var(--text-muted)' }}>Coming in future phase.</p>
            <p className="text-[11px] mt-2 max-w-md" style={{ color:'var(--text-muted)' }}>Phase 1 delivers the Leave Types master and Leave Policies. Balance, application, approval and the holiday calendar build on this foundation later.</p>
          </div>
        )}
    </div>
  )
}

/* ── Leave Types ── */
function LeaveTypes({ showToast }) {
  const [rows, setRows] = useState([]); const [stats, setStats] = useState({ total:0, paid:0, unpaid:0, active:0, inactive:0 })
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState(''); const [catF, setCatF] = useState('All'); const [statusF, setStatusF] = useState('All')
  const [modal, setModal] = useState(null); const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    const params = {}
    if (catF !== 'All') params.category = catF
    if (statusF !== 'All') params.status = statusF
    if (search) params.search = search
    hrApi.leave.types.list(params).then(res => { setRows(res.data || []); setStats(res.stats || stats) }).catch(()=>showToast('Failed to load leave types','error')).finally(()=>setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [catF, statusF, search])
  useEffect(() => { load() }, [load])

  const EMPTY = { name:'', code:'', category:'Casual', paid:true, yearly_limit:'', carry_forward:false, max_carry_forward:'', requires_attachment:false, requires_approval:true, color:'#7C3AED', description:'', is_active:true }
  const save = async () => {
    const f = modal.form
    if (!f.name.trim() || !f.code.trim()) return showToast('Name and code are required','error')
    setSaving(true)
    try { modal.editing ? await hrApi.leave.types.update(modal.editing, f) : await hrApi.leave.types.create(f); showToast(`Leave type ${modal.editing?'updated':'created'}`); setModal(null); load() }
    catch (e) { showToast(e.response?.data?.message||'Save failed','error') } finally { setSaving(false) }
  }
  const toggle = async (r) => { try { await hrApi.leave.types.setStatus(r.id, !r.is_active); load() } catch { showToast('Failed','error') } }
  const KPIS = [{l:'Total',v:stats.total,c:'#7C3AED'},{l:'Paid',v:stats.paid,c:'#10b981'},{l:'Unpaid',v:stats.unpaid,c:'#94a3b8'},{l:'Active',v:stats.active,c:'#0ea5e9'},{l:'Inactive',v:stats.inactive,c:'#f87171'}]
  const hasF = catF!=='All'||statusF!=='All'||search

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
        {KPIS.map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}
      </div>

      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[200px]"><label className="label">Search</label><Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/><input className="input-3d pl-9 text-sm" placeholder="Name or code…" value={search} onChange={e=>setSearch(e.target.value)}/></div>
          <div className="min-w-[150px]"><label className="label">Category</label><select className="input-3d text-sm" value={catF} onChange={e=>setCatF(e.target.value)}>{['All',...CATEGORIES].map(c=><option key={c}>{c}</option>)}</select></div>
          <div className="min-w-[130px]"><label className="label">Status</label><select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['All','Active','Inactive'].map(s=><option key={s}>{s}</option>)}</select></div>
          {hasF && <button onClick={()=>{ setCatF('All'); setStatusF('All'); setSearch('') }} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
          <button onClick={()=>setModal({ editing:null, form:{...EMPTY} })} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white ml-auto" style={{ background:GRAD }}><Plus size={15}/> Add Leave Type</button>
        </div>
      </div>

      {loading ? <HrLoading label="Loading leave types…" /> : rows.length===0 ? <HrEmpty icon={Tag} title="No leave types yet" hint="Create leave types (Casual, Sick, Earned…) with their yearly limits." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:820 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Leave Type','Category','Paid','Yearly','Carry Fwd','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{rows.map(r=>(
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)', opacity:r.is_active?1:0.55 }}>
                  <td className="px-3 py-2.5"><span className="inline-flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-full" style={{ background:r.color||'#7C3AED' }}/><span className="font-bold" style={{ color:'var(--text-h)' }}>{r.name}</span><span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.code}</span></span></td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${CAT_C[r.category]||'#7C3AED'}1f`, color:CAT_C[r.category]||'#7C3AED' }}>{r.category}</span></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.paid?'Paid':'Unpaid'}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{Number(r.yearly_limit)}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.carry_forward?`≤ ${Number(r.max_carry_forward)}`:'—'}</td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={r.is_active?{background:'rgba(16,185,129,0.12)',color:'#10b981'}:{background:'var(--bg-input)',color:'var(--text-muted)'}}>{r.is_active?'Active':'Inactive'}</span></td>
                  <td className="px-3 py-2.5"><div className="flex gap-1.5 justify-end">
                    <button onClick={()=>setModal({ editing:r.id, form:{ name:r.name, code:r.code, category:r.category, paid:r.paid, yearly_limit:r.yearly_limit, carry_forward:r.carry_forward, max_carry_forward:r.max_carry_forward, requires_attachment:r.requires_attachment, requires_approval:r.requires_approval, color:r.color||'#7C3AED', description:r.description||'', is_active:r.is_active } })} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Pencil size={13}/></button>
                    <button onClick={()=>toggle(r)} className="p-1.5 rounded-lg" style={r.is_active?{background:'rgba(239,68,68,0.1)',color:'#f87171'}:{background:'rgba(16,185,129,0.1)',color:'#10b981'}}><Power size={13}/></button>
                  </div></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}

      {modal && (
        <div className="modal-backdrop" onClick={()=>setModal(null)}><div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()} style={{ maxHeight:'92vh', overflowY:'auto' }}>
          <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{modal.editing?'Edit Leave Type':'Add Leave Type'}</h2><button onClick={()=>setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Name *</label><input className="input-3d text-sm" value={modal.form.name} onChange={e=>setModal(m=>({...m,form:{...m.form,name:e.target.value}}))}/></div>
            <div><label className="label">Code *</label><input className="input-3d text-sm" value={modal.form.code} onChange={e=>setModal(m=>({...m,form:{...m.form,code:e.target.value}}))}/></div>
            <div><label className="label">Category</label><select className="input-3d text-sm" value={modal.form.category} onChange={e=>setModal(m=>({...m,form:{...m.form,category:e.target.value}}))}>{CATEGORIES.map(c=><option key={c}>{c}</option>)}</select></div>
            <div><label className="label">Color</label><input type="color" className="input-3d text-sm h-[42px] p-1" value={modal.form.color} onChange={e=>setModal(m=>({...m,form:{...m.form,color:e.target.value}}))}/></div>
            <div><label className="label">Yearly Limit</label><input type="number" min="0" className="input-3d text-sm" value={modal.form.yearly_limit} onChange={e=>setModal(m=>({...m,form:{...m.form,yearly_limit:e.target.value}}))}/></div>
            <div><label className="label">Max Carry Forward</label><input type="number" min="0" className="input-3d text-sm" value={modal.form.max_carry_forward} onChange={e=>setModal(m=>({...m,form:{...m.form,max_carry_forward:e.target.value}}))}/></div>
            <div className="col-span-2 grid grid-cols-2 gap-2">
              {[['paid','Paid'],['carry_forward','Carry Forward'],['requires_attachment','Requires Attachment'],['requires_approval','Requires Approval']].map(([k,l])=>(
                <label key={k} className="flex items-center gap-2 text-xs font-semibold px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}><input type="checkbox" checked={modal.form[k]} onChange={e=>setModal(m=>({...m,form:{...m.form,[k]:e.target.checked}}))}/>{l}</label>
              ))}
            </div>
            <div className="col-span-2"><label className="label">Description</label><textarea rows={2} className="input-3d text-sm resize-none" value={modal.form.description} onChange={e=>setModal(m=>({...m,form:{...m.form,description:e.target.value}}))}/></div>
            {modal.editing && <label className="col-span-2 flex items-center gap-2 text-xs font-semibold"><input type="checkbox" checked={modal.form.is_active} onChange={e=>setModal(m=>({...m,form:{...m.form,is_active:e.target.checked}}))}/> Active</label>}
          </div>
          <div className="flex gap-3 pt-4"><button onClick={()=>setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Saving…':'Save'}</button></div>
        </div></div>
      )}
    </div>
  )
}

/* ── Leave Policies ── */
function LeavePolicies({ showToast }) {
  const [rows, setRows] = useState([]); const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState(''); const [statusF, setStatusF] = useState('All')
  const [types, setTypes] = useState([]); const [opts, setOpts] = useState({ grades:[], designations:[] })
  const [modal, setModal] = useState(null); const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    const params = {}
    if (statusF !== 'All') params.status = statusF
    if (search) params.search = search
    hrApi.leave.policies.list(params).then(setRows).catch(()=>showToast('Failed to load policies','error')).finally(()=>setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusF, search])
  useEffect(() => { load() }, [load])
  useEffect(() => {
    hrApi.leave.types.list({ status:'Active' }).then(r=>setTypes(r.data||[])).catch(()=>{})
    hrApi.organization.options().then(o=>setOpts({ grades:o.grades||[], designations:o.designations||[] })).catch(()=>{})
  }, [])

  const EMPTY = { name:'', applies_to:'All', grade_id:'', designation_id:'', probation_allowed:false, notice_period_allowed:false, weekends_count:false, holidays_count:false, half_day_allowed:true, negative_balance_allowed:false, description:'', is_active:true, leave_types:[] }
  const openEdit = async (row) => {
    try { const full = await hrApi.leave.policies.get(row.id); setModal({ editing:full.id, form:{ ...EMPTY, ...full, grade_id:full.grade_id||'', designation_id:full.designation_id||'', leave_types: full.leave_types.map(t=>({ leave_type_id:t.leave_type_id, yearly_allocation:t.yearly_allocation, carry_forward_limit:t.carry_forward_limit })) } }) }
    catch { showToast('Failed to load policy','error') }
  }
  const toggleType = (tid) => setModal(m => {
    const has = m.form.leave_types.find(t=>t.leave_type_id===tid)
    const leave_types = has ? m.form.leave_types.filter(t=>t.leave_type_id!==tid) : [...m.form.leave_types, { leave_type_id:tid, yearly_allocation:0, carry_forward_limit:0 }]
    return { ...m, form:{ ...m.form, leave_types } }
  })
  const setTypeVal = (tid, key, val) => setModal(m => ({ ...m, form:{ ...m.form, leave_types: m.form.leave_types.map(t=>t.leave_type_id===tid?{...t,[key]:val}:t) } }))
  const save = async () => {
    if (!modal.form.name.trim()) return showToast('Policy name is required','error')
    setSaving(true)
    try { modal.editing ? await hrApi.leave.policies.update(modal.editing, modal.form) : await hrApi.leave.policies.create(modal.form); showToast(`Policy ${modal.editing?'updated':'created'}`); setModal(null); load() }
    catch (e) { showToast(e.response?.data?.message||'Save failed','error') } finally { setSaving(false) }
  }
  const toggle = async (r) => { try { await hrApi.leave.policies.setStatus(r.id, !r.is_active); load() } catch { showToast('Failed','error') } }
  const hasF = statusF!=='All'||search

  return (
    <div className="space-y-4">
      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[200px]"><label className="label">Search</label><Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/><input className="input-3d pl-9 text-sm" placeholder="Policy name…" value={search} onChange={e=>setSearch(e.target.value)}/></div>
          <div className="min-w-[130px]"><label className="label">Status</label><select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['All','Active','Inactive'].map(s=><option key={s}>{s}</option>)}</select></div>
          {hasF && <button onClick={()=>{ setStatusF('All'); setSearch('') }} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
          <button onClick={()=>setModal({ editing:null, form:{...EMPTY, leave_types:[]} })} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white ml-auto" style={{ background:GRAD }}><Plus size={15}/> Add Policy</button>
        </div>
      </div>

      {loading ? <HrLoading label="Loading policies…" /> : rows.length===0 ? <HrEmpty icon={FileText} title="No leave policies yet" hint="Create a policy and map leave types with their yearly allocation." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:760 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Policy','Applies To','Grade / Designation','Mapped Types','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{rows.map(r=>(
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)', opacity:r.is_active?1:0.55 }}>
                  <td className="px-3 py-2.5 font-bold" style={{ color:'var(--text-h)' }}>{r.name}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.applies_to}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{[r.grade_name, r.designation_name].filter(Boolean).join(' · ')||'—'}</td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}>{r.leave_types.length}</span></td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={r.is_active?{background:'rgba(16,185,129,0.12)',color:'#10b981'}:{background:'var(--bg-input)',color:'var(--text-muted)'}}>{r.is_active?'Active':'Inactive'}</span></td>
                  <td className="px-3 py-2.5"><div className="flex gap-1.5 justify-end">
                    <button onClick={()=>openEdit(r)} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Pencil size={13}/></button>
                    <button onClick={()=>toggle(r)} className="p-1.5 rounded-lg" style={r.is_active?{background:'rgba(239,68,68,0.1)',color:'#f87171'}:{background:'rgba(16,185,129,0.1)',color:'#10b981'}}><Power size={13}/></button>
                  </div></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}

      {modal && (
        <div className="modal-backdrop" onClick={()=>setModal(null)}><div className="modal-box" onClick={e=>e.stopPropagation()} style={{ maxWidth:720, width:'95%', maxHeight:'92vh', overflowY:'auto' }}>
          <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{modal.editing?'Edit Leave Policy':'Add Leave Policy'}</h2><button onClick={()=>setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
          <div className="grid grid-cols-2 md:grid-cols-3 gap-3 mb-3">
            <div className="col-span-2"><label className="label">Policy Name *</label><input className="input-3d text-sm" value={modal.form.name} onChange={e=>setModal(m=>({...m,form:{...m.form,name:e.target.value}}))}/></div>
            <div><label className="label">Applies To</label><select className="input-3d text-sm" value={modal.form.applies_to} onChange={e=>setModal(m=>({...m,form:{...m.form,applies_to:e.target.value}}))}>{['All','Grade','Designation'].map(a=><option key={a}>{a}</option>)}</select></div>
            <div><label className="label">Grade</label><select className="input-3d text-sm" value={modal.form.grade_id} onChange={e=>setModal(m=>({...m,form:{...m.form,grade_id:e.target.value}}))}><option value="">—</option>{opts.grades.map(g=><option key={g.id} value={g.id}>{g.name}</option>)}</select></div>
            <div><label className="label">Designation</label><select className="input-3d text-sm" value={modal.form.designation_id} onChange={e=>setModal(m=>({...m,form:{...m.form,designation_id:e.target.value}}))}><option value="">—</option>{opts.designations.map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select></div>
          </div>
          <div className="grid grid-cols-2 md:grid-cols-3 gap-2 mb-4">
            {[['probation_allowed','Probation Allowed'],['notice_period_allowed','Notice Period Allowed'],['half_day_allowed','Half Day Allowed'],['weekends_count','Weekends Count'],['holidays_count','Holidays Count'],['negative_balance_allowed','Negative Balance']].map(([k,l])=>(
              <label key={k} className="flex items-center gap-2 text-xs font-semibold px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}><input type="checkbox" checked={modal.form[k]} onChange={e=>setModal(m=>({...m,form:{...m.form,[k]:e.target.checked}}))}/>{l}</label>
            ))}
          </div>
          <p className="text-[11px] font-bold uppercase mb-2" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Mapped Leave Types</p>
          <div className="space-y-1.5 mb-4">
            {types.length===0 && <p className="text-xs" style={{ color:'var(--text-muted)' }}>No active leave types — create leave types first.</p>}
            {types.map(t=>{ const m = modal.form.leave_types.find(x=>x.leave_type_id===t.id); return (
              <div key={t.id} className="flex items-center gap-2 px-3 py-2 rounded-xl flex-wrap" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                <label className="flex items-center gap-2 flex-1"><input type="checkbox" checked={!!m} onChange={()=>toggleType(t.id)}/><span className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{t.name}</span><span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{t.code}</span></label>
                {m && <>
                  <div className="flex items-center gap-1"><span className="text-[10px]" style={{ color:'var(--text-muted)' }}>Alloc</span><input type="number" min="0" className="input-3d text-xs" style={{ width:70, padding:'6px 8px' }} value={m.yearly_allocation} onChange={e=>setTypeVal(t.id,'yearly_allocation',e.target.value)}/></div>
                  <div className="flex items-center gap-1"><span className="text-[10px]" style={{ color:'var(--text-muted)' }}>CF≤</span><input type="number" min="0" className="input-3d text-xs" style={{ width:60, padding:'6px 8px' }} value={m.carry_forward_limit} onChange={e=>setTypeVal(t.id,'carry_forward_limit',e.target.value)}/></div>
                </>}
              </div>
            )})}
          </div>
          <div className="flex gap-3 pt-1"><button onClick={()=>setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Saving…':'Save Policy'}</button></div>
        </div></div>
      )}
    </div>
  )
}

/* ── Leave Balance & Allocation (Phase 2) ── */
function LeaveBalance({ showToast }) {
  const [rows, setRows] = useState([]); const [stats, setStats] = useState({ employees_covered:0, total_allocation:0, total_available:0, policies_assigned:0 })
  const [loading, setLoading] = useState(true)
  const [employees, setEmployees] = useState([]); const [policies, setPolicies] = useState([]); const [types, setTypes] = useState([])
  const [modal, setModal] = useState(null)  // { kind:'assign'|'allocate'|'adjust'|'history', ... }

  const load = useCallback(() => {
    setLoading(true)
    hrApi.leave.balances.list().then(res => { setRows(res.data || []); setStats(res.stats || stats) }).catch(()=>showToast('Failed to load balances','error')).finally(()=>setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])
  useEffect(() => { load() }, [load])
  useEffect(() => {
    hrApi.employees.list({ per_page:200 }).then(r=>setEmployees(Array.isArray(r)?r:(r?.data??[]))).catch(()=>{})
    hrApi.leave.policies.list({ status:'Active' }).then(r=>setPolicies(r||[])).catch(()=>{})
    hrApi.leave.types.list({ status:'Active' }).then(r=>setTypes(r.data||[])).catch(()=>{})
  }, [])

  const KPIS = [
    { l:'Employees Covered', v:stats.employees_covered, c:'#7C3AED' },
    { l:'Total Allocation', v:stats.total_allocation, c:'#3b82f6' },
    { l:'Total Available', v:stats.total_available, c:'#10b981' },
    { l:'Policies Assigned', v:stats.policies_assigned, c:'#0ea5e9' },
  ]

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {KPIS.map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}
      </div>

      <div className="flex gap-2 justify-end flex-wrap">
        <button onClick={()=>setModal({ kind:'allocate', employee_id:'', leave_type_id:'', quantity:'', remarks:'' })} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><Plus size={15}/> Allocate Leave</button>
        <button onClick={()=>setModal({ kind:'assign', employee_id:'', leave_policy_id:'', effective_from:'' })} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD }}><UserPlus size={15}/> Assign Policy</button>
      </div>

      {loading ? <HrLoading label="Loading leave balances…" /> : rows.length===0 ? <HrEmpty icon={Wallet} title="No leave balances yet" hint="Assign a leave policy to an employee to create balances." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:900 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Policy','Leave Type','Allocated','Used','Available','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{rows.map(b=>(
                <tr key={b.id} style={{ borderBottom:'1px solid var(--border)', opacity:b.status==='active'?1:0.55 }}>
                  <td className="px-3 py-2.5"><span className="font-bold" style={{ color:'var(--text-h)' }}>{b.employee_name}</span> <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{b.employee_code}</span></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{b.policy_name||'—'}</td>
                  <td className="px-3 py-2.5"><span className="inline-flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-full" style={{ background:b.color||'#7C3AED' }}/><span style={{ color:'var(--text-h)' }}>{b.leave_type}</span></span></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{b.allocated}{b.carried_forward>0 && <span className="text-[10px]" style={{ color:'#3b82f6' }}> +{b.carried_forward} CF</span>}</td>
                  <td className="px-3 py-2.5" style={{ color:'#f87171' }}>{b.used}</td>
                  <td className="px-3 py-2.5 font-black" style={{ color: b.available_balance<0?'#f87171':'#10b981' }}>{b.available_balance}</td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={b.status==='active'?{background:'rgba(16,185,129,0.12)',color:'#10b981'}:{background:'var(--bg-input)',color:'var(--text-muted)'}}>{b.status}</span></td>
                  <td className="px-3 py-2.5"><div className="flex gap-1.5 justify-end">
                    {b.status==='active' && <button onClick={()=>setModal({ kind:'adjust', balance:b, quantity:'', remarks:'' })} title="Adjust" className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><SlidersHorizontal size={13}/></button>}
                    <button onClick={()=>setModal({ kind:'history', balance:b })} title="History" className="p-1.5 rounded-lg" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><History size={13}/></button>
                  </div></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}

      {modal?.kind==='assign' && <AssignModal modal={modal} setModal={setModal} employees={employees} policies={policies} onDone={()=>{ setModal(null); load() }} showToast={showToast} />}
      {modal?.kind==='allocate' && <AllocateModal modal={modal} setModal={setModal} employees={employees} types={types} onDone={()=>{ setModal(null); load() }} showToast={showToast} />}
      {modal?.kind==='adjust' && <AdjustModal modal={modal} setModal={setModal} onDone={()=>{ setModal(null); load() }} showToast={showToast} />}
      {modal?.kind==='history' && <HistoryModal balance={modal.balance} onClose={()=>setModal(null)} showToast={showToast} />}
    </div>
  )
}

function AssignModal({ modal, setModal, employees, policies, onDone, showToast }) {
  const [busy, setBusy] = useState(false)
  const submit = async () => {
    if (!modal.employee_id || !modal.leave_policy_id) return showToast('Select employee and policy','error')
    setBusy(true)
    try { await hrApi.leave.balances.assign({ employee_id:Number(modal.employee_id), leave_policy_id:Number(modal.leave_policy_id), effective_from:modal.effective_from||undefined }); showToast('Policy assigned — balances created'); onDone() }
    catch (e) { showToast(e.response?.data?.message||'Failed','error'); setBusy(false) }
  }
  return (
    <div className="modal-backdrop" onClick={()=>setModal(null)}><div className="modal-box max-w-md" onClick={e=>e.stopPropagation()}>
      <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Assign Leave Policy</h2><button onClick={()=>setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
      <div className="space-y-3">
        <div><label className="label">Employee</label><select className="input-3d text-sm" value={modal.employee_id} onChange={e=>setModal(m=>({...m,employee_id:e.target.value}))}><option value="">Select…</option>{employees.map(e=><option key={e.id} value={e.id}>{e.name} · {e.department}</option>)}</select></div>
        <div><label className="label">Leave Policy</label><select className="input-3d text-sm" value={modal.leave_policy_id} onChange={e=>setModal(m=>({...m,leave_policy_id:e.target.value}))}><option value="">Select…</option>{policies.map(p=><option key={p.id} value={p.id}>{p.name} ({p.leave_types.length} types)</option>)}</select></div>
        <div><label className="label">Effective From</label><input type="date" className="input-3d text-sm" value={modal.effective_from} onChange={e=>setModal(m=>({...m,effective_from:e.target.value}))}/></div>
        <p className="text-[11px]" style={{ color:'var(--text-muted)' }}>Creates a balance for every mapped leave type. Any existing active policy is archived (history preserved) and eligible balances carry forward.</p>
      </div>
      <div className="flex gap-3 pt-4"><button onClick={()=>setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={submit} disabled={busy} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:busy?0.7:1 }}>{busy?'Assigning…':'Assign & Allocate'}</button></div>
    </div></div>
  )
}

function AllocateModal({ modal, setModal, employees, types, onDone, showToast }) {
  const [busy, setBusy] = useState(false)
  const submit = async () => {
    if (!modal.employee_id || !modal.leave_type_id || !modal.quantity) return showToast('Employee, type and quantity required','error')
    setBusy(true)
    try { await hrApi.leave.balances.allocate({ employee_id:Number(modal.employee_id), leave_type_id:Number(modal.leave_type_id), quantity:Number(modal.quantity), remarks:modal.remarks }); showToast('Leave allocated'); onDone() }
    catch (e) { showToast(e.response?.data?.message||'Failed','error'); setBusy(false) }
  }
  return (
    <div className="modal-backdrop" onClick={()=>setModal(null)}><div className="modal-box max-w-md" onClick={e=>e.stopPropagation()}>
      <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Allocate Leave</h2><button onClick={()=>setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
      <div className="space-y-3">
        <div><label className="label">Employee</label><select className="input-3d text-sm" value={modal.employee_id} onChange={e=>setModal(m=>({...m,employee_id:e.target.value}))}><option value="">Select…</option>{employees.map(e=><option key={e.id} value={e.id}>{e.name}</option>)}</select></div>
        <div><label className="label">Leave Type</label><select className="input-3d text-sm" value={modal.leave_type_id} onChange={e=>setModal(m=>({...m,leave_type_id:e.target.value}))}><option value="">Select…</option>{types.map(t=><option key={t.id} value={t.id}>{t.name} ({t.code})</option>)}</select></div>
        <div><label className="label">Quantity (days)</label><input type="number" min="0" step="0.5" className="input-3d text-sm" value={modal.quantity} onChange={e=>setModal(m=>({...m,quantity:e.target.value}))}/></div>
        <div><label className="label">Remarks</label><input className="input-3d text-sm" value={modal.remarks} onChange={e=>setModal(m=>({...m,remarks:e.target.value}))}/></div>
      </div>
      <div className="flex gap-3 pt-4"><button onClick={()=>setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={submit} disabled={busy} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:busy?0.7:1 }}>{busy?'Allocating…':'Allocate'}</button></div>
    </div></div>
  )
}

function AdjustModal({ modal, setModal, onDone, showToast }) {
  const [busy, setBusy] = useState(false); const b = modal.balance
  const submit = async () => {
    if (!modal.quantity || Number(modal.quantity)===0) return showToast('Enter a non-zero adjustment','error')
    setBusy(true)
    try { await hrApi.leave.balances.adjust({ balance_id:b.id, quantity:Number(modal.quantity), remarks:modal.remarks }); showToast('Balance adjusted'); onDone() }
    catch (e) { showToast(e.response?.data?.message||'Failed','error'); setBusy(false) }
  }
  return (
    <div className="modal-backdrop" onClick={()=>setModal(null)}><div className="modal-box max-w-md" onClick={e=>e.stopPropagation()}>
      <div className="flex items-center justify-between mb-1"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Adjust Balance</h2><button onClick={()=>setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
      <p className="text-xs mb-4" style={{ color:'var(--text-muted)' }}>{b.employee_name} · {b.leave_type} · current available <b style={{ color:'#10b981' }}>{b.available_balance}</b></p>
      <div className="space-y-3">
        <div><label className="label">Adjustment (+ increase / − decrease)</label><input type="number" step="0.5" className="input-3d text-sm" placeholder="e.g. 2 or -1" value={modal.quantity} onChange={e=>setModal(m=>({...m,quantity:e.target.value}))}/></div>
        <div><label className="label">Remarks</label><input className="input-3d text-sm" value={modal.remarks} onChange={e=>setModal(m=>({...m,remarks:e.target.value}))}/></div>
        <p className="text-[11px]" style={{ color:'var(--text-muted)' }}>A ledger entry is recorded; values are never overwritten. Negative results are blocked unless the policy allows it.</p>
      </div>
      <div className="flex gap-3 pt-4"><button onClick={()=>setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={submit} disabled={busy} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:busy?0.7:1 }}>{busy?'Saving…':'Apply Adjustment'}</button></div>
    </div></div>
  )
}

function HistoryModal({ balance, onClose, showToast }) {
  const [data, setData] = useState(null)
  useEffect(() => { hrApi.leave.balances.history(balance.id).then(setData).catch(()=>showToast('Failed to load history','error')) }, [balance.id, showToast])
  const TXN_C = { Allocation:'#10b981', Adjustment:'#f59e0b', 'Carry Forward':'#3b82f6', 'Opening Balance':'#8b5cf6', Correction:'#f87171' }
  return (
    <div className="modal-backdrop" onClick={onClose}><div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()} style={{ maxHeight:'85vh', overflowY:'auto' }}>
      <div className="flex items-center justify-between mb-1"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Leave Ledger</h2><button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
      <p className="text-xs mb-4" style={{ color:'var(--text-muted)' }}>{balance.employee_name} · {balance.leave_type} · available <b style={{ color:'#10b981' }}>{balance.available_balance}</b></p>
      {!data ? <p className="text-sm py-4" style={{ color:'var(--text-muted)' }}>Loading…</p>
        : (data.ledger||[]).length===0 ? <p className="text-xs" style={{ color:'var(--text-muted)' }}>No transactions.</p>
        : <div className="space-y-1.5">{data.ledger.map(t=>(
            <div key={t.id} className="flex items-center justify-between px-3 py-2 rounded-xl flex-wrap gap-2" style={{ background:'var(--bg-input)' }}>
              <div><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${TXN_C[t.transaction_type]||'#7C3AED'}1f`, color:TXN_C[t.transaction_type]||'#7C3AED' }}>{t.transaction_type}</span>{t.remarks && <span className="text-[11px] ml-2" style={{ color:'var(--text-muted)' }}>{t.remarks}</span>}</div>
              <div className="flex items-center gap-2"><span className="text-xs font-black" style={{ color:t.quantity<0?'#f87171':'#10b981' }}>{t.quantity>0?'+':''}{t.quantity}</span><span className="text-[10px]" style={{ color:'var(--text-muted)' }}>{t.created_at ? new Date(t.created_at).toLocaleDateString('en-IN',{day:'2-digit',month:'short'}) : ''}</span></div>
            </div>
          ))}</div>}
    </div></div>
  )
}

const APP_ST = { Draft:{c:'#94a3b8',bg:'var(--bg-input)'}, Submitted:{c:'#f59e0b',bg:'rgba(245,158,11,0.14)'}, Approved:{c:'#10b981',bg:'rgba(16,185,129,0.12)'}, Rejected:{c:'#f87171',bg:'rgba(239,68,68,0.1)'}, Cancelled:{c:'#94a3b8',bg:'var(--bg-input)'} }
const fmtD = d => d ? new Date(d).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—'

/* ── Apply Leave (Phase 3) ── */
function ApplyLeave({ showToast }) {
  const [rows, setRows] = useState([]); const [loading, setLoading] = useState(true)
  const [employees, setEmployees] = useState([]); const [types, setTypes] = useState([])
  const [modal, setModal] = useState(null); const [saving, setSaving] = useState(false)
  const load = useCallback(() => { setLoading(true); hrApi.leave.applications.list().then(setRows).catch(()=>showToast('Failed to load applications','error')).finally(()=>setLoading(false)) }, [showToast])
  useEffect(() => { load() }, [load])
  useEffect(() => {
    hrApi.employees.list({ per_page:200 }).then(r=>setEmployees(Array.isArray(r)?r:(r?.data??[]))).catch(()=>{})
    hrApi.leave.types.list({ status:'Active' }).then(r=>setTypes(r.data||[])).catch(()=>{})
  }, [])

  const EMPTY = { employee_id:'', leave_type_id:'', from_date:'', to_date:'', half_day:false, reason:'', file:null }
  const save = async () => {
    const f = modal
    if (!f.employee_id||!f.leave_type_id||!f.from_date||!f.to_date) return showToast('Employee, type and dates required','error')
    setSaving(true)
    try {
      const fd = new FormData()
      fd.append('employee_id', f.employee_id); fd.append('leave_type_id', f.leave_type_id)
      fd.append('from_date', f.from_date); fd.append('to_date', f.to_date)
      fd.append('half_day', f.half_day ? 1 : 0); if (f.reason) fd.append('reason', f.reason)
      if (f.file) fd.append('attachment', f.file)
      await hrApi.leave.applications.apply(fd); showToast('Leave application submitted'); setModal(null); load()
    } catch (e) { showToast(e.response?.data?.message||'Failed','error') } finally { setSaving(false) }
  }
  const cancel = async (r) => { try { await hrApi.leave.applications.cancel(r.id); showToast('Cancelled'); load() } catch (e) { showToast(e.response?.data?.message||'Failed','error') } }

  return (
    <div className="space-y-4">
      <div className="flex justify-end"><button onClick={()=>setModal({...EMPTY})} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD }}><Plus size={15}/> Apply Leave</button></div>
      {loading ? <HrLoading label="Loading applications…" /> : rows.length===0 ? <HrEmpty icon={CalendarCheck} title="No leave applications yet" hint="Apply for leave on behalf of an employee against their active balance." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:820 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Leave Type','Dates','Days','Reason','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{rows.map(r=>{ const st=APP_ST[r.status]||{}; return (
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                  <td className="px-3 py-2.5"><span className="font-bold" style={{ color:'var(--text-h)' }}>{r.employee_name}</span> <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code}</span></td>
                  <td className="px-3 py-2.5"><span className="inline-flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-full" style={{ background:r.color||'#7C3AED' }}/><span style={{ color:'var(--text-h)' }}>{r.leave_type}</span></span></td>
                  <td className="px-3 py-2.5 text-[11px]" style={{ color:'var(--text-muted)' }}>{fmtD(r.from_date)} → {fmtD(r.to_date)}</td>
                  <td className="px-3 py-2.5 font-bold" style={{ color:'var(--text-h)' }}>{r.days}{r.half_day && <span className="text-[9px]" style={{ color:'var(--text-muted)' }}> ½</span>}</td>
                  <td className="px-3 py-2.5 text-[11px] max-w-[180px] truncate" style={{ color:'var(--text-muted)' }}>{r.reason||'—'}</td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:st.bg, color:st.c }}>{r.status}</span></td>
                  <td className="px-3 py-2.5">{['Draft','Submitted'].includes(r.status) && <div className="flex justify-end"><button onClick={()=>cancel(r)} title="Cancel" className="p-1.5 rounded-lg" style={{ background:'rgba(239,68,68,0.1)', color:'#f87171' }}><Ban size={13}/></button></div>}</td>
                </tr>
              )})}</tbody>
            </table>
          </div>}

      {modal && (
        <div className="modal-backdrop" onClick={()=>setModal(null)}><div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()} style={{ maxHeight:'92vh', overflowY:'auto' }}>
          <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Apply Leave</h2><button onClick={()=>setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
          <div className="grid grid-cols-2 gap-3">
            <div className="col-span-2"><label className="label">Employee</label><select className="input-3d text-sm" value={modal.employee_id} onChange={e=>setModal(m=>({...m,employee_id:e.target.value}))}><option value="">Select…</option>{employees.map(e=><option key={e.id} value={e.id}>{e.name} · {e.department}</option>)}</select></div>
            <div className="col-span-2"><label className="label">Leave Type</label><select className="input-3d text-sm" value={modal.leave_type_id} onChange={e=>setModal(m=>({...m,leave_type_id:e.target.value}))}><option value="">Select…</option>{types.map(t=><option key={t.id} value={t.id}>{t.name} ({t.code})</option>)}</select></div>
            <div><label className="label">From</label><input type="date" className="input-3d text-sm" value={modal.from_date} onChange={e=>setModal(m=>({...m,from_date:e.target.value,to_date:m.to_date||e.target.value}))}/></div>
            <div><label className="label">To</label><input type="date" className="input-3d text-sm" value={modal.to_date} onChange={e=>setModal(m=>({...m,to_date:e.target.value}))}/></div>
            <label className="col-span-2 flex items-center gap-2 text-xs font-semibold px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}><input type="checkbox" checked={modal.half_day} onChange={e=>setModal(m=>({...m,half_day:e.target.checked}))}/> Half day (counts as 0.5)</label>
            <div className="col-span-2"><label className="label">Reason</label><textarea rows={2} className="input-3d text-sm resize-none" value={modal.reason} onChange={e=>setModal(m=>({...m,reason:e.target.value}))}/></div>
            <div className="col-span-2"><label className="label">Attachment (optional)</label><input type="file" className="text-xs" onChange={e=>setModal(m=>({...m,file:e.target.files?.[0]||null}))}/></div>
          </div>
          <div className="flex gap-3 pt-4"><button onClick={()=>setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Submitting…':'Submit Application'}</button></div>
        </div></div>
      )}
    </div>
  )
}

/* ── Leave Approval (Phase 4) ── */
function LeaveApproval({ showToast }) {
  const [rows, setRows] = useState([]); const [stats, setStats] = useState({ pending:0, approved:0, rejected:0, cancelled:0 })
  const [loading, setLoading] = useState(true)
  const [statusF, setStatusF] = useState('Submitted'); const [search, setSearch] = useState('')
  const [review, setReview] = useState(null); const [decision, setDecision] = useState(null)  // { kind:'approve'|'reject', app }

  const load = useCallback(() => {
    setLoading(true)
    const params = {}
    if (statusF !== 'All') params.status = statusF
    hrApi.leave.approvals.list(params).then(res => { setRows(res.data||[]); setStats(res.stats||stats) }).catch(()=>showToast('Failed to load queue','error')).finally(()=>setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusF])
  useEffect(() => { load() }, [load])

  const filtered = rows.filter(r => !search || (r.employee_name||'').toLowerCase().includes(search.toLowerCase()) || (r.employee_code||'').toLowerCase().includes(search.toLowerCase()))
  const KPIS = [
    { l:'Pending', v:stats.pending, c:'#f59e0b' }, { l:'Approved', v:stats.approved, c:'#10b981' },
    { l:'Rejected', v:stats.rejected, c:'#f87171' }, { l:'Cancelled', v:stats.cancelled, c:'#94a3b8' },
  ]

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {KPIS.map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}
      </div>

      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[200px]"><label className="label">Search</label><Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/><input className="input-3d pl-9 text-sm" placeholder="Employee name or code…" value={search} onChange={e=>setSearch(e.target.value)}/></div>
          <div className="min-w-[150px]"><label className="label">Status</label><select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['Submitted','Approved','Rejected','Cancelled','All'].map(s=><option key={s}>{s}</option>)}</select></div>
        </div>
      </div>

      {loading ? <HrLoading label="Loading approval queue…" /> : filtered.length===0 ? <HrEmpty icon={CheckSquare} title="Nothing to review" hint="No applications match the current filter." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:900 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Department','Leave Type','Dates','Days','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{filtered.map(r=>{ const st=APP_ST[r.status]||{}; return (
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                  <td className="px-3 py-2.5"><span className="font-bold" style={{ color:'var(--text-h)' }}>{r.employee_name}</span> <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code}</span></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.department||'—'}</td>
                  <td className="px-3 py-2.5"><span className="inline-flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-full" style={{ background:r.color||'#7C3AED' }}/><span style={{ color:'var(--text-h)' }}>{r.leave_type}</span></span></td>
                  <td className="px-3 py-2.5 text-[11px]" style={{ color:'var(--text-muted)' }}>{fmtD(r.from_date)} → {fmtD(r.to_date)}</td>
                  <td className="px-3 py-2.5 font-bold" style={{ color:'var(--text-h)' }}>{r.days}</td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:st.bg, color:st.c }}>{r.status}</span></td>
                  <td className="px-3 py-2.5"><div className="flex gap-1.5 justify-end">
                    <button onClick={()=>hrApi.leave.approvals.get(r.id).then(setReview).catch(()=>showToast('Failed','error'))} className="text-[11px] font-bold px-2.5 py-1 rounded-lg flex items-center gap-1" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Eye size={12}/> Review</button>
                    {r.status==='Submitted' && <>
                      <button onClick={()=>setDecision({ kind:'approve', app:r, remarks:'' })} className="text-[11px] font-bold px-2.5 py-1 rounded-lg" style={{ background:'rgba(16,185,129,0.12)', color:'#10b981' }}>Approve</button>
                      <button onClick={()=>setDecision({ kind:'reject', app:r, remarks:'' })} className="text-[11px] font-bold px-2.5 py-1 rounded-lg" style={{ background:'rgba(239,68,68,0.1)', color:'#f87171' }}>Reject</button>
                    </>}
                  </div></td>
                </tr>
              )})}</tbody>
            </table>
          </div>}

      {review && <ReviewDrawer app={review} onClose={()=>setReview(null)} onDecide={(kind)=>{ setDecision({ kind, app:review, remarks:'' }); setReview(null) }} />}
      {decision && <DecisionModal decision={decision} setDecision={setDecision} onDone={()=>{ setDecision(null); load() }} showToast={showToast} />}
    </div>
  )
}

function ReviewDrawer({ app, onClose, onDecide }) {
  const st = APP_ST[app.status]||{}
  return (
    <div className="modal-backdrop" onClick={onClose}><div className="modal-box" onClick={e=>e.stopPropagation()} style={{ maxWidth:640, width:'95%', maxHeight:'92vh', overflowY:'auto' }}>
      <div className="flex items-center justify-between mb-1"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{app.employee_name}</h2><button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
      <div className="flex items-center gap-2 mb-4"><span className="text-xs" style={{ color:'var(--text-muted)' }}>{app.employee_code} · {app.designation} · {app.department}</span><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:st.bg, color:st.c }}>{app.status}</span></div>
      <div className="grid grid-cols-2 gap-2 mb-4">
        {[['Leave Type',app.leave_type],['Policy',app.policy_name||'—'],['Date Range',`${fmtD(app.from_date)} → ${fmtD(app.to_date)}`],['Requested Days',app.days+(app.half_day?' (½)':'')],['Available Balance',app.available_balance ?? '—'],['Reason',app.reason||'—']].map(([k,v])=>(
          <div key={k} className={k==='Reason'?'col-span-2':''} style={{ background:'var(--bg-input)', borderRadius:12, padding:'10px 12px' }}><p className="text-[10px] font-bold uppercase" style={{ color:'var(--text-muted)' }}>{k}</p><p className="text-sm font-semibold mt-0.5" style={{ color:'var(--text-h)' }}>{v}</p></div>
        ))}
      </div>
      {app.has_attachment && <a href={hrApi.leave.applications.attachmentUrl(app.id)} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl mb-4" style={{ background:'rgba(59,130,246,0.1)', color:'#60a5fa' }}><Paperclip size={13}/> View Attachment</a>}
      <p className="text-[11px] font-bold uppercase mb-2" style={{ color:'var(--text-muted)' }}>Timeline</p>
      <div className="space-y-1.5 mb-4">{(app.timeline||[]).map((t,i)=>(
        <div key={i} className="flex items-center justify-between px-3 py-1.5 rounded-xl" style={{ background:'var(--bg-input)' }}><span className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{t.action}</span><span className="text-[10px]" style={{ color:'var(--text-muted)' }}>{t.actor_name} · {t.created_at?new Date(t.created_at).toLocaleDateString('en-IN',{day:'2-digit',month:'short'}):''}</span></div>
      ))}</div>
      {app.decision_remarks && <div className="mb-3 px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}><p className="text-[10px] font-bold uppercase" style={{ color:'var(--text-muted)' }}>Decision Remarks</p><p className="text-sm" style={{ color:'var(--text-h)' }}>{app.decision_remarks}</p></div>}
      {app.status==='Submitted' && <div className="flex gap-3"><button onClick={()=>onDecide('reject')} className="flex-1 py-2.5 rounded-xl text-sm font-bold flex items-center justify-center gap-1.5" style={{ background:'rgba(239,68,68,0.1)', color:'#f87171' }}><Ban size={14}/> Reject</button><button onClick={()=>onDecide('approve')} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-1.5" style={{ background:GRAD }}><Check size={14}/> Approve</button></div>}
    </div></div>
  )
}

function DecisionModal({ decision, setDecision, onDone, showToast }) {
  const [busy, setBusy] = useState(false); const isApprove = decision.kind==='approve'; const a = decision.app
  const submit = async () => {
    setBusy(true)
    try {
      isApprove ? await hrApi.leave.approvals.approve(a.id, decision.remarks) : await hrApi.leave.approvals.reject(a.id, decision.remarks)
      showToast(isApprove ? 'Leave approved — balance deducted' : 'Leave rejected'); onDone()
    } catch (e) { showToast(e.response?.data?.message||'Failed','error'); setBusy(false) }
  }
  return (
    <div className="modal-backdrop" onClick={()=>setDecision(null)}><div className="modal-box max-w-md" onClick={e=>e.stopPropagation()}>
      <div className="flex items-center justify-between mb-1"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{isApprove?'Approve Leave':'Reject Leave'}</h2><button onClick={()=>setDecision(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
      <p className="text-xs mb-4" style={{ color:'var(--text-muted)' }}>{a.employee_name} · {a.leave_type} · {a.days} day(s){isApprove && <> — deducts from balance</>}</p>
      <div><label className="label">Remarks{isApprove?' (optional)':''}</label><textarea rows={3} className="input-3d text-sm resize-none" value={decision.remarks} onChange={e=>setDecision(d=>({...d,remarks:e.target.value}))}/></div>
      <div className="flex gap-3 pt-4"><button onClick={()=>setDecision(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={submit} disabled={busy} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:isApprove?GRAD:'linear-gradient(135deg,#f87171,#ef4444)', opacity:busy?0.7:1 }}>{busy?'Saving…':isApprove?'Confirm Approve':'Confirm Reject'}</button></div>
    </div></div>
  )
}

/* ── Holiday Calendar (Phase 5) ── */
const HOL_C = { National:'#f87171', Festival:'#8b5cf6', Company:'#3b82f6', Optional:'#f59e0b' }
const MONTHS_H = ['January','February','March','April','May','June','July','August','September','October','November','December']

function HolidayCalendar({ showToast }) {
  const [rows, setRows] = useState([]); const [stats, setStats] = useState({ total:0, national:0, festival:0, optional:0, active:0 })
  const [loading, setLoading] = useState(true)
  const [view, setView] = useState('calendar'); const [year, setYear] = useState(new Date().getFullYear())
  const [search, setSearch] = useState(''); const [typeF, setTypeF] = useState('All'); const [deptF, setDeptF] = useState('All')
  const [depts, setDepts] = useState([]); const [desigs, setDesigs] = useState([])
  const [month, setMonth] = useState(new Date().getMonth())
  const [modal, setModal] = useState(null); const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    const params = { year }
    if (typeF !== 'All') params.holiday_type = typeF
    if (deptF !== 'All') params.department_id = deptF
    if (search) params.search = search
    hrApi.leave.holidays.list(params).then(res => { setRows(res.data||[]); setStats(res.stats||stats) }).catch(()=>showToast('Failed to load holidays','error')).finally(()=>setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [year, typeF, deptF, search])
  useEffect(() => { load() }, [load])
  useEffect(() => { hrApi.organization.options().then(o=>{ setDepts(o.departments||[]); setDesigs(o.designations||[]) }).catch(()=>{}) }, [])

  const EMPTY = { title:'', description:'', holiday_date:'', holiday_type:'National', applicable_for:'Organization', department_id:'', designation_id:'', is_optional:false, is_active:true }
  const save = async () => {
    const f = modal.form
    if (!f.title.trim() || !f.holiday_date) return showToast('Title and date are required','error')
    setSaving(true)
    try { modal.editing ? await hrApi.leave.holidays.update(modal.editing, f) : await hrApi.leave.holidays.create(f); showToast(`Holiday ${modal.editing?'updated':'created'}`); setModal(null); load() }
    catch (e) { showToast(e.response?.data?.message||'Save failed','error') } finally { setSaving(false) }
  }
  const toggle = async (r) => { try { await hrApi.leave.holidays.setStatus(r.id, !r.is_active); load() } catch { showToast('Failed','error') } }

  const KPIS = [{l:'Total',v:stats.total,c:'#7C3AED'},{l:'National',v:stats.national,c:'#f87171'},{l:'Festival',v:stats.festival,c:'#8b5cf6'},{l:'Optional',v:stats.optional,c:'#f59e0b'},{l:'Active',v:stats.active,c:'#10b981'}]
  const years = [year-1, year, year+1]
  const byDate = {}; rows.forEach(h => { (byDate[h.holiday_date] = byDate[h.holiday_date] || []).push(h) })

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
        {KPIS.map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}
      </div>

      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[180px]"><label className="label">Search</label><Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/><input className="input-3d pl-9 text-sm" placeholder="Holiday name…" value={search} onChange={e=>setSearch(e.target.value)}/></div>
          <div className="min-w-[110px]"><label className="label">Year</label><select className="input-3d text-sm" value={year} onChange={e=>setYear(Number(e.target.value))}>{years.map(y=><option key={y} value={y}>{y}</option>)}</select></div>
          <div className="min-w-[140px]"><label className="label">Type</label><select className="input-3d text-sm" value={typeF} onChange={e=>setTypeF(e.target.value)}>{['All','National','Festival','Company','Optional'].map(t=><option key={t}>{t}</option>)}</select></div>
          <div className="min-w-[150px]"><label className="label">Department</label><select className="input-3d text-sm" value={deptF} onChange={e=>setDeptF(e.target.value)}><option value="All">All</option>{depts.map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select></div>
          <div className="flex rounded-xl overflow-hidden" style={{ border:'1px solid var(--border)' }}>
            {[{k:'calendar',I:LayoutGrid},{k:'list',I:List}].map(v=><button key={v.k} onClick={()=>setView(v.k)} className="px-3 py-2.5" style={{ background:view===v.k?GRAD:'var(--bg-input)', color:view===v.k?'#fff':'var(--text-muted)' }}><v.I size={15}/></button>)}
          </div>
          <button onClick={()=>setModal({ editing:null, form:{...EMPTY} })} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD }}><Plus size={15}/> Add Holiday</button>
        </div>
      </div>

      {loading ? <HrLoading label="Loading holidays…" />
        : view === 'list' ? (
          rows.length===0 ? <HrEmpty icon={CalendarRange} title="No holidays" hint="Add holidays for the selected year." />
          : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
              <table className="w-full text-sm" style={{ minWidth:760 }}>
                <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Holiday','Date','Type','Applies To','Optional','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
                <tbody>{rows.map(h=>(
                  <tr key={h.id} style={{ borderBottom:'1px solid var(--border)', opacity:h.is_active?1:0.55 }}>
                    <td className="px-3 py-2.5 font-bold" style={{ color:'var(--text-h)' }}>{h.title}</td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{fmtD(h.holiday_date)}</td>
                    <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${HOL_C[h.holiday_type]||'#7C3AED'}1f`, color:HOL_C[h.holiday_type]||'#7C3AED' }}>{h.holiday_type}</span></td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{h.applicable_for}{h.department_name?` · ${h.department_name}`:''}{h.designation_name?` · ${h.designation_name}`:''}</td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{h.is_optional?'Yes':'—'}</td>
                    <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={h.is_active?{background:'rgba(16,185,129,0.12)',color:'#10b981'}:{background:'var(--bg-input)',color:'var(--text-muted)'}}>{h.is_active?'Active':'Inactive'}</span></td>
                    <td className="px-3 py-2.5"><div className="flex gap-1.5 justify-end">
                      <button onClick={()=>setModal({ editing:h.id, form:{ ...EMPTY, ...h, department_id:h.department_id||'', designation_id:h.designation_id||'' } })} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Pencil size={13}/></button>
                      <button onClick={()=>toggle(h)} className="p-1.5 rounded-lg" style={h.is_active?{background:'rgba(239,68,68,0.1)',color:'#f87171'}:{background:'rgba(16,185,129,0.1)',color:'#10b981'}}><Power size={13}/></button>
                    </div></td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
        ) : (
          <div className="card-3d" style={{ padding:'18px' }}>
            <div className="flex items-center justify-between mb-3">
              <button onClick={()=>setMonth(m=>m>0?m-1:11)} className="p-1.5 rounded-lg" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><ChevronLeft size={15}/></button>
              <p className="text-sm font-black" style={{ color:'var(--text-h)' }}>{MONTHS_H[month]} {year}</p>
              <button onClick={()=>setMonth(m=>m<11?m+1:0)} className="p-1.5 rounded-lg" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><ChevronRight size={15}/></button>
            </div>
            <div className="grid grid-cols-7 gap-1.5">
              {['Su','Mo','Tu','We','Th','Fr','Sa'].map(d=><div key={d} className="text-center text-[10px] font-bold py-1" style={{ color:'var(--text-muted)' }}>{d}</div>)}
              {(() => {
                const first = new Date(year, month, 1).getDay(); const dim = new Date(year, month+1, 0).getDate()
                const cells = [...Array(first).fill(null), ...Array.from({length:dim},(_,i)=>i+1)]
                return cells.map((n,i)=>{
                  if (!n) return <div key={`b${i}`} />
                  const ds = `${year}-${String(month+1).padStart(2,'0')}-${String(n).padStart(2,'0')}`
                  const hs = byDate[ds] || []
                  return (
                    <div key={ds} className="rounded-lg p-1.5 min-h-[54px]" style={{ background: hs.length?`${HOL_C[hs[0].holiday_type]||'#7C3AED'}14`:'var(--bg-input)', border:`1px solid ${hs.length?(HOL_C[hs[0].holiday_type]||'#7C3AED')+'55':'var(--border)'}` }}>
                      <p className="text-[11px] font-bold" style={{ color:hs.length?(HOL_C[hs[0].holiday_type]||'#7C3AED'):'var(--text-muted)' }}>{n}</p>
                      {hs.map(h=><p key={h.id} className="text-[8.5px] font-semibold truncate mt-0.5" style={{ color:HOL_C[h.holiday_type]||'#7C3AED' }} title={h.title}>{h.title}</p>)}
                    </div>
                  )
                })
              })()}
            </div>
            <div className="flex flex-wrap gap-3 mt-3">{Object.entries(HOL_C).map(([t,c])=><div key={t} className="flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-full" style={{ background:c }}/><span className="text-[10px]" style={{ color:'var(--text-muted)' }}>{t}</span></div>)}</div>
          </div>
        )}

      {modal && (
        <div className="modal-backdrop" onClick={()=>setModal(null)}><div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()} style={{ maxHeight:'92vh', overflowY:'auto' }}>
          <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{modal.editing?'Edit Holiday':'Add Holiday'}</h2><button onClick={()=>setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
          <div className="grid grid-cols-2 gap-3">
            <div className="col-span-2"><label className="label">Title *</label><input className="input-3d text-sm" value={modal.form.title} onChange={e=>setModal(m=>({...m,form:{...m.form,title:e.target.value}}))}/></div>
            <div><label className="label">Date *</label><input type="date" className="input-3d text-sm" value={modal.form.holiday_date} onChange={e=>setModal(m=>({...m,form:{...m.form,holiday_date:e.target.value}}))}/></div>
            <div><label className="label">Type</label><select className="input-3d text-sm" value={modal.form.holiday_type} onChange={e=>setModal(m=>({...m,form:{...m.form,holiday_type:e.target.value}}))}>{['National','Festival','Company','Optional'].map(t=><option key={t}>{t}</option>)}</select></div>
            <div><label className="label">Applies To</label><select className="input-3d text-sm" value={modal.form.applicable_for} onChange={e=>setModal(m=>({...m,form:{...m.form,applicable_for:e.target.value}}))}>{['Organization','Department','Designation'].map(a=><option key={a}>{a}</option>)}</select></div>
            {modal.form.applicable_for==='Department' && <div><label className="label">Department</label><select className="input-3d text-sm" value={modal.form.department_id} onChange={e=>setModal(m=>({...m,form:{...m.form,department_id:e.target.value}}))}><option value="">Select…</option>{depts.map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select></div>}
            {modal.form.applicable_for==='Designation' && <div><label className="label">Designation</label><select className="input-3d text-sm" value={modal.form.designation_id} onChange={e=>setModal(m=>({...m,form:{...m.form,designation_id:e.target.value}}))}><option value="">Select…</option>{desigs.map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select></div>}
            <label className="col-span-2 flex items-center gap-2 text-xs font-semibold px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}><input type="checkbox" checked={modal.form.is_optional} onChange={e=>setModal(m=>({...m,form:{...m.form,is_optional:e.target.checked}}))}/> Optional holiday</label>
            <div className="col-span-2"><label className="label">Description</label><textarea rows={2} className="input-3d text-sm resize-none" value={modal.form.description} onChange={e=>setModal(m=>({...m,form:{...m.form,description:e.target.value}}))}/></div>
          </div>
          <div className="flex gap-3 pt-4"><button onClick={()=>setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Saving…':'Save Holiday'}</button></div>
        </div></div>
      )}
    </div>
  )
}
