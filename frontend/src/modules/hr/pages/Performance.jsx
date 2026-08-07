import { useState, useEffect, useCallback } from 'react'
import { useTheme } from '@/context/ThemeContext'
import {
  LayoutDashboard, Target, Gauge, ClipboardCheck, TrendingUp, IndianRupee,
  Plus, Pencil, X, Power, Search, UserPlus, Sparkles, Users, Award, Wallet,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { useMasterData, withInactive } from '@/modules/hr/useMasterData'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
// #3 — the shared filter bar. KPIs and Goals filter on the server (their
// endpoints already accept search/status/department); Reviews, Promotions and
// Increments have no such params, so those filter in memory via applyListFilter.
import ListFilter, { applyListFilter } from '@/components/ui/ListFilter'

const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'
const inr = v => `₹${Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`
const REVIEW_TYPES = ['Monthly', 'Quarterly', 'Half-Yearly', 'Annual']
const REVIEW_ST = { Draft:{c:'#f59e0b',bg:'rgba(245,158,11,0.14)'}, Submitted:{c:'#2563eb',bg:'rgba(37,99,235,0.12)'}, Reviewed:{c:'#8b5cf6',bg:'rgba(139,92,246,0.12)'}, Approved:{c:'#10b981',bg:'rgba(16,185,129,0.12)'} }
const REC_ST = { Pending:{c:'#f59e0b',bg:'rgba(245,158,11,0.14)'}, Approved:{c:'#10b981',bg:'rgba(16,185,129,0.12)'}, Rejected:{c:'#f87171',bg:'rgba(239,68,68,0.1)'} }

const TABS = [
  { key:'dashboard',  label:'Dashboard',  icon:LayoutDashboard },
  { key:'goals',      label:'Goals / KRA', icon:Target },
  { key:'kpis',       label:'KPIs',       icon:Gauge },
  { key:'reviews',    label:'Reviews',    icon:ClipboardCheck },
  { key:'promotions', label:'Promotions', icon:TrendingUp },
  { key:'increments', label:'Increments', icon:IndianRupee },
]

export default function Performance() {
  useTheme()
  const [tab, setTab] = useState('dashboard')
  const [toast, setToast] = useState(null)
  const [employees, setEmployees] = useState([])
  const showToast = (msg, type='success') => { setToast({ msg, type }); setTimeout(() => setToast(null), 3000) }

  useEffect(() => { hrApi.employees.list({ per_page: 200 }).then(r => setEmployees(Array.isArray(r) ? r : (r?.data ?? []))).catch(() => {}) }, [])

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl" style={{ background:toast.type==='success'?'linear-gradient(135deg,#10b981,#059669)':'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <p className="label-caps mb-1">HR Records</p>
          <h1 className="font-black flex items-center gap-2" style={{ fontSize:'clamp(1.3rem,2vw,1.7rem)', color:'var(--text-h)', letterSpacing:'-0.02em' }}>
            <Award size={22} style={{ color:'#a78bfa' }}/> Performance <span className="text-gradient">Management</span>
          </h1>
        </div>
      </div>

      <div className="flex gap-1.5 flex-wrap">
        {TABS.map(t => {
          const active = tab === t.key
          return (
            <button key={t.key} onClick={()=>setTab(t.key)} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold transition-all"
              style={{ background: active ? GRAD : 'var(--bg-input)', color: active ? '#fff' : 'var(--text-muted)', border: active ? 'none' : '1px solid var(--border)' }}>
              <t.icon size={15}/> {t.label}
            </button>
          )
        })}
      </div>

      {tab === 'dashboard'  && <Dashboard showToast={showToast} />}
      {tab === 'kpis'       && <Kpis showToast={showToast} />}
      {tab === 'goals'      && <Goals employees={employees} showToast={showToast} />}
      {tab === 'reviews'    && <Reviews employees={employees} showToast={showToast} />}
      {tab === 'promotions' && <Recommendations kind="promotions" employees={employees} showToast={showToast} />}
      {tab === 'increments' && <Recommendations kind="increments" employees={employees} showToast={showToast} />}
    </div>
  )
}

/* ── Dashboard ── */
function Dashboard({ showToast }) {
  const [d, setD] = useState(null)
  useEffect(() => { hrApi.performance.dashboard().then(setD).catch(() => showToast('Failed to load dashboard', 'error')) }, [showToast])
  if (!d) return <HrLoading label="Loading performance dashboard…" />
  const KPIS = [
    { l:'Total Employees', v:d.total_employees, c:'#7C3AED', I:Users },
    { l:'Goals Assigned', v:d.goals_assigned, c:'#0ea5e9', I:Target },
    { l:'Reviews Pending', v:d.reviews_pending, c:'#f59e0b', I:ClipboardCheck },
    { l:'Completed Reviews', v:d.reviews_completed, c:'#10b981', I:ClipboardCheck },
    { l:'Average Rating', v:d.avg_rating, c:'#8b5cf6', I:Gauge },
    { l:'Promotion Eligible', v:d.promotion_eligible, c:'#ec4899', I:TrendingUp },
  ]
  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
        {KPIS.map(k => (
          <div key={k.l} className="kpi-3d">
            <div className="flex items-center justify-between"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><k.I size={16} style={{ color:k.c, opacity:0.6 }}/></div>
            <p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p>
          </div>
        ))}
      </div>
      <div>
        {/* #40 — the six "AI Insights Coming Soon" placeholders that stood here
            are gone. Insights are per-EMPLOYEE and derived from that person's own
            performance, attendance, training and skill data, so they live on the
            employee's profile rather than as company-wide teasers with nothing
            behind them. */}
        <p className="text-[11px] font-bold uppercase mb-2 flex items-center gap-1.5" style={{ color:'#a78bfa', letterSpacing:'0.04em' }}><Sparkles size={13}/> Employee Insights</p>
        <div className="rounded-xl p-3.5 flex items-start gap-2" style={{ background:'rgba(124,58,237,0.05)', border:'1px solid rgba(124,58,237,0.25)' }}>
          <Sparkles size={14} style={{ color:'#a78bfa', marginTop:1, flexShrink:0 }}/>
          <div>
            <p className="text-xs font-bold" style={{ color:'var(--text-h)' }}>Overall score, strengths, improvement areas and risk factors</p>
            <p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}>
              Open any employee&rsquo;s profile and choose <b>Performance</b> to calculate their score and generate insights.
              Each one is backed by the record that produced it &mdash; reviews, attendance, training and skill fit.
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}

/* ── KPI master ── */
function Kpis({ showToast }) {
  const [rows, setRows] = useState([]); const [loading, setLoading] = useState(true)
  const [modal, setModal] = useState(null); const [saving, setSaving] = useState(false)
  // #3 — sent to the server: /hr/performance/kpis already accepts search + status.
  const [search, setSearch] = useState(''); const [statusF, setStatusF] = useState('All')
  const load = useCallback(() => {
    setLoading(true)
    const params = {}; if (search) params.search = search; if (statusF !== 'All') params.status = statusF
    hrApi.performance.kpis.list(params).then(setRows).catch(()=>showToast('Failed to load KPIs','error')).finally(()=>setLoading(false))
  }, [showToast, search, statusF])
  useEffect(() => { load() }, [load])
  const EMPTY = { name:'', category:'', description:'', weightage:'', rating_scale:5, is_active:true }
  const save = async () => {
    if (!modal.form.name.trim()) return showToast('Name is required','error')
    setSaving(true)
    try { modal.editing ? await hrApi.performance.kpis.update(modal.editing, modal.form) : await hrApi.performance.kpis.create(modal.form); showToast(`KPI ${modal.editing?'updated':'created'}`); setModal(null); load() }
    catch (e) { showToast(e.response?.data?.message||'Save failed','error') } finally { setSaving(false) }
  }
  const toggle = async (r) => { try { await hrApi.performance.kpis.setStatus(r.id, !r.is_active); load() } catch { showToast('Failed','error') } }
  return (
    <div className="space-y-4">
      <ListFilter
        search={search} setSearch={setSearch} placeholder="KPI name…"
        selects={[{ key:'status', label:'Status', value:statusF, onChange:setStatusF, options:['All','Active','Inactive'] }]}
        onClear={()=>{ setSearch(''); setStatusF('All') }}
        right={<button onClick={()=>setModal({ editing:null, form:{...EMPTY} })} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD }}><Plus size={15}/> Add KPI</button>}
      />
      {loading ? <HrLoading label="Loading KPIs…" /> : rows.length===0 ? <HrEmpty icon={Gauge} title="No KPIs yet" hint={search||statusF!=='All' ? 'No KPIs match these filters.' : 'Create measurable performance indicators (Quality, Teamwork, Productivity…).'} />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:640 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['KPI','Category','Weightage','Scale','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{rows.map(r=>(
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)', opacity:r.is_active?1:0.55 }}>
                  <td className="px-3 py-2.5 font-bold" style={{ color:'var(--text-h)' }}>{r.name}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.category||'—'}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{Number(r.weightage)}%</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>1–{r.rating_scale}</td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={r.is_active?{background:'rgba(16,185,129,0.12)',color:'#10b981'}:{background:'var(--bg-input)',color:'var(--text-muted)'}}>{r.is_active?'Active':'Inactive'}</span></td>
                  <td className="px-3 py-2.5"><div className="flex gap-1.5 justify-end">
                    <button onClick={()=>setModal({ editing:r.id, form:{ name:r.name, category:r.category||'', description:r.description||'', weightage:r.weightage??'', rating_scale:r.rating_scale, is_active:r.is_active } })} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Pencil size={13}/></button>
                    <button onClick={()=>toggle(r)} className="p-1.5 rounded-lg" style={r.is_active?{background:'rgba(239,68,68,0.1)',color:'#f87171'}:{background:'rgba(16,185,129,0.1)',color:'#10b981'}}><Power size={13}/></button>
                  </div></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}
      {modal && (
        <div className="modal-backdrop"><div className="modal-box max-w-md" onClick={e=>e.stopPropagation()}>
          <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{modal.editing?'Edit KPI':'Add KPI'}</h2><button onClick={()=>setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
          <div className="grid grid-cols-2 gap-3">
            <div className="col-span-2"><label className="label">Name *</label><input className="input-3d text-sm" value={modal.form.name} onChange={e=>setModal(m=>({...m,form:{...m.form,name:e.target.value}}))}/></div>
            <div><label className="label">Category</label><input className="input-3d text-sm" value={modal.form.category} onChange={e=>setModal(m=>({...m,form:{...m.form,category:e.target.value}}))}/></div>
            <div><label className="label">Weightage %</label><input type="number" className="input-3d text-sm" value={modal.form.weightage} onChange={e=>setModal(m=>({...m,form:{...m.form,weightage:e.target.value}}))}/></div>
            <div><label className="label">Rating Scale</label><select className="input-3d text-sm" value={modal.form.rating_scale} onChange={e=>setModal(m=>({...m,form:{...m.form,rating_scale:e.target.value}}))}>{[5,10].map(s=><option key={s} value={s}>1–{s}</option>)}</select></div>
            <div className="col-span-2"><label className="label">Description</label><textarea rows={2} className="input-3d text-sm resize-none" value={modal.form.description} onChange={e=>setModal(m=>({...m,form:{...m.form,description:e.target.value}}))}/></div>
          </div>
          <div className="flex gap-3 pt-4"><button onClick={()=>setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Saving…':'Save'}</button></div>
        </div></div>
      )}
    </div>
  )
}

/* ── Goals + assignment ── */
function Goals({ employees, showToast }) {
  const [rows, setRows] = useState([]); const [loading, setLoading] = useState(true)
  const [modal, setModal] = useState(null); const [assign, setAssign] = useState(null); const [saving, setSaving] = useState(false)
  // Goal targeting reuses Org Setup masters (no hardcoded/free-text dept/designation).
  const { masters } = useMasterData()
  const deptOptions  = (f) => withInactive((masters.departments  || []).map(d => d.name), f?.department)
  const desigOptions = (f) => withInactive((masters.designations || []).map(d => d.name), f?.designation)
  // #3 — /hr/performance/goals already accepts search, status and department.
  const [search, setSearch] = useState(''); const [statusF, setStatusF] = useState('All'); const [deptF, setDeptF] = useState('')
  const load = useCallback(() => {
    setLoading(true)
    const params = {}
    if (search) params.search = search
    if (statusF !== 'All') params.status = statusF
    if (deptF) params.department = deptF
    hrApi.performance.goals.list(params).then(setRows).catch(()=>showToast('Failed to load goals','error')).finally(()=>setLoading(false))
  }, [showToast, search, statusF, deptF])
  useEffect(() => { load() }, [load])
  const EMPTY = { title:'', description:'', department:'', designation:'', weightage:'', target:'', due_date:'', status:'Active' }
  const save = async () => {
    if (!modal.form.title.trim()) return showToast('Title is required','error')
    setSaving(true)
    try { modal.editing ? await hrApi.performance.goals.update(modal.editing, modal.form) : await hrApi.performance.goals.create(modal.form); showToast(`Goal ${modal.editing?'updated':'created'}`); setModal(null); load() }
    catch (e) { showToast(e.response?.data?.message||'Save failed','error') } finally { setSaving(false) }
  }
  return (
    <div className="space-y-4">
      <ListFilter
        search={search} setSearch={setSearch} placeholder="Goal title…"
        selects={[
          { key:'status', label:'Status', value:statusF, onChange:setStatusF, options:['All','Active','Inactive'] },
          { key:'dept', label:'Department', value:deptF, onChange:setDeptF,
            options:[{ value:'', label:'All departments' }, ...(masters.departments||[]).map(d=>({ value:d.name, label:d.name }))] },
        ]}
        onClear={()=>{ setSearch(''); setStatusF('All'); setDeptF('') }}
        right={<button onClick={()=>setModal({ editing:null, form:{...EMPTY} })} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD }}><Plus size={15}/> Add Goal</button>}
      />
      {loading ? <HrLoading label="Loading goals…" /> : rows.length===0 ? <HrEmpty icon={Target} title="No goals yet" hint={search||statusF!=='All'||deptF ? 'No goals match these filters.' : 'Create goals / KRAs and assign them to employees.'} />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:760 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Goal','Department','Weightage','Target','Due','Assigned','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{rows.map(r=>(
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                  <td className="px-3 py-2.5 font-bold" style={{ color:'var(--text-h)' }}>{r.title}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.department||'—'}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{Number(r.weightage)}%</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.target||'—'}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.due_date||'—'}</td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}>{r.assignments_count}</span></td>
                  <td className="px-3 py-2.5"><div className="flex gap-1.5 justify-end">
                    <button onClick={()=>setAssign({ goal:r, ids:[] })} title="Assign" className="p-1.5 rounded-lg" style={{ background:'rgba(16,185,129,0.1)', color:'#10b981' }}><UserPlus size={13}/></button>
                    <button onClick={()=>setModal({ editing:r.id, form:{ title:r.title, description:r.description||'', department:r.department||'', designation:r.designation||'', weightage:r.weightage??'', target:r.target||'', due_date:r.due_date||'', status:r.status } })} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Pencil size={13}/></button>
                  </div></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}

      {modal && (
        <div className="modal-backdrop"><div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()} style={{ maxHeight:'90vh', overflowY:'auto' }}>
          <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{modal.editing?'Edit Goal':'Add Goal'}</h2><button onClick={()=>setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
          <div className="grid grid-cols-2 gap-3">
            <div className="col-span-2"><label className="label">Title *</label><input className="input-3d text-sm" value={modal.form.title} onChange={e=>setModal(m=>({...m,form:{...m.form,title:e.target.value}}))}/></div>
            <div><label className="label">Department</label>
              <select className="input-3d text-sm" value={modal.form.department} onChange={e=>setModal(m=>({...m,form:{...m.form,department:e.target.value}}))}>
                <option value="">All / Select…</option>{deptOptions(modal.form).map(o=><option key={o.value} value={o.value}>{o.label}</option>)}
              </select>
            </div>
            <div><label className="label">Designation</label>
              <select className="input-3d text-sm" value={modal.form.designation} onChange={e=>setModal(m=>({...m,form:{...m.form,designation:e.target.value}}))}>
                <option value="">All / Select…</option>{desigOptions(modal.form).map(o=><option key={o.value} value={o.value}>{o.label}</option>)}
              </select>
            </div>
            <div><label className="label">Weightage %</label><input type="number" className="input-3d text-sm" value={modal.form.weightage} onChange={e=>setModal(m=>({...m,form:{...m.form,weightage:e.target.value}}))}/></div>
            <div><label className="label">Due Date</label><input type="date" className="input-3d text-sm" value={modal.form.due_date} onChange={e=>setModal(m=>({...m,form:{...m.form,due_date:e.target.value}}))}/></div>
            <div className="col-span-2"><label className="label">Target</label><input className="input-3d text-sm" value={modal.form.target} onChange={e=>setModal(m=>({...m,form:{...m.form,target:e.target.value}}))}/></div>
            <div className="col-span-2"><label className="label">Description</label><textarea rows={2} className="input-3d text-sm resize-none" value={modal.form.description} onChange={e=>setModal(m=>({...m,form:{...m.form,description:e.target.value}}))}/></div>
            {modal.editing && <div className="col-span-2"><label className="label">Status</label><select className="input-3d text-sm" value={modal.form.status} onChange={e=>setModal(m=>({...m,form:{...m.form,status:e.target.value}}))}>{['Active','Completed','Archived'].map(s=><option key={s}>{s}</option>)}</select></div>}
          </div>
          <div className="flex gap-3 pt-4"><button onClick={()=>setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Saving…':'Save'}</button></div>
        </div></div>
      )}

      {assign && <AssignModal assign={assign} setAssign={setAssign} employees={employees} onDone={()=>{ setAssign(null); load() }} showToast={showToast} />}
    </div>
  )
}

function AssignModal({ assign, setAssign, employees, onDone, showToast }) {
  const [busy, setBusy] = useState(false)
  const toggle = (id) => setAssign(a => ({ ...a, ids: a.ids.includes(id) ? a.ids.filter(x=>x!==id) : [...a.ids, id] }))
  const submit = async () => {
    if (!assign.ids.length) return showToast('Select at least one employee','error')
    setBusy(true)
    try { const r = await hrApi.performance.assignments.assign(assign.goal.id, assign.ids); showToast(`${r.assigned} assigned`); onDone() }
    catch (e) { showToast(e.response?.data?.message||'Failed','error'); setBusy(false) }
  }
  return (
    <div className="modal-backdrop"><div className="modal-box max-w-md" onClick={e=>e.stopPropagation()} style={{ maxHeight:'85vh', overflowY:'auto' }}>
      <div className="flex items-center justify-between mb-1"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Assign Goal</h2><button onClick={()=>setAssign(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
      <p className="text-xs mb-3" style={{ color:'var(--text-muted)' }}>{assign.goal.title}</p>
      <div className="space-y-1.5">
        {employees.map(e => (
          <label key={e.id} className="flex items-center gap-2 px-3 py-2 rounded-xl cursor-pointer" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
            <input type="checkbox" checked={assign.ids.includes(e.id)} onChange={()=>toggle(e.id)} />
            <span className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{e.name}</span>
            <span className="text-[10px] ml-auto" style={{ color:'var(--text-muted)' }}>{e.department}</span>
          </label>
        ))}
      </div>
      <div className="flex gap-3 pt-4"><button onClick={()=>setAssign(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={submit} disabled={busy} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:busy?0.7:1 }}>{busy?'Assigning…':`Assign (${assign.ids.length})`}</button></div>
    </div></div>
  )
}

/* ── Reviews ── */
function Reviews({ employees, showToast }) {
  const [rows, setRows] = useState([]); const [loading, setLoading] = useState(true)
  const [kpis, setKpis] = useState([]); const [modal, setModal] = useState(null); const [view, setView] = useState(null); const [saving, setSaving] = useState(false)
  const load = useCallback(() => { setLoading(true); hrApi.performance.reviews.list().then(setRows).catch(()=>showToast('Failed to load reviews','error')).finally(()=>setLoading(false)) }, [showToast])
  useEffect(() => { load(); hrApi.performance.kpis.list({ status:'Active' }).then(setKpis).catch(()=>{}) }, [load])

  const openCreate = () => setModal({ employee_id:'', review_type:'Quarterly', period_year:new Date().getFullYear(), period_label:'', strengths:'', improvements:'', comments:'', recommendation:'', kpis: kpis.map(k=>({ kpi_id:k.id, kpi_name:k.name, weightage:Number(k.weightage), rating:0, comment:'' })) })
  const save = async () => {
    if (!modal.employee_id) return showToast('Select an employee','error')
    setSaving(true)
    try { await hrApi.performance.reviews.create(modal); showToast('Review created'); setModal(null); load() }
    catch (e) { showToast(e.response?.data?.message||'Failed','error') } finally { setSaving(false) }
  }
  const advance = async (r, status) => { try { await hrApi.performance.reviews.setStatus(r.id, status); showToast(`Review ${status}`); load() } catch (e) { showToast(e.response?.data?.message||'Failed','error') } }
  const NEXT = { Draft:'Submitted', Submitted:'Reviewed', Reviewed:'Approved' }

  // #3 — /hr/performance/reviews takes no filter params, so this narrows the
  // loaded set in memory rather than inventing a server contract for it.
  const [search, setSearch] = useState(''); const [statusF, setStatusF] = useState('All'); const [typeF, setTypeF] = useState('All')
  const shown = applyListFilter(rows, {
    search, fields: ['employee_name', 'period_label'],
    matchers: [[statusF, (r, v) => r.status === v], [typeF, (r, v) => r.review_type === v]],
  })

  return (
    <div className="space-y-4">
      <ListFilter
        search={search} setSearch={setSearch} placeholder="Employee or period…"
        selects={[
          { key:'status', label:'Status', value:statusF, onChange:setStatusF, options:['All','Draft','Submitted','Reviewed','Approved'] },
          { key:'type', label:'Type', value:typeF, onChange:setTypeF, options:['All',...REVIEW_TYPES] },
        ]}
        onClear={()=>{ setSearch(''); setStatusF('All'); setTypeF('All') }}
        right={<button onClick={openCreate} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD }}><Plus size={15}/> New Review</button>}
      />
      {loading ? <HrLoading label="Loading reviews…" /> : shown.length===0 ? <HrEmpty icon={ClipboardCheck} title={rows.length ? 'No matching reviews' : 'No reviews yet'} hint={rows.length ? 'No reviews match these filters.' : 'Create a performance review with KPI ratings.'} />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:760 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Type','Period','Rating','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{shown.map(r=>{ const st = REVIEW_ST[r.status]||{}; return (
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                  <td className="px-3 py-2.5"><span className="font-bold" style={{ color:'var(--text-h)' }}>{r.employee_name}</span> <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code}</span></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.review_type}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.period_label||r.period_year||'—'}</td>
                  <td className="px-3 py-2.5 font-black" style={{ color:'#8b5cf6' }}>{r.overall_rating}</td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:st.bg, color:st.c }}>{r.status}</span></td>
                  <td className="px-3 py-2.5"><div className="flex gap-1.5 justify-end items-center">
                    <button onClick={()=>hrApi.performance.reviews.get(r.id).then(setView)} className="text-[11px] font-bold px-2.5 py-1 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}>View</button>
                    {NEXT[r.status] && <button onClick={()=>advance(r, NEXT[r.status])} className="text-[11px] font-bold px-2.5 py-1 rounded-lg text-white" style={{ background:GRAD }}>{NEXT[r.status]}</button>}
                  </div></td>
                </tr>
              )})}</tbody>
            </table>
          </div>}

      {modal && <ReviewModal modal={modal} setModal={setModal} employees={employees} saving={saving} onSave={save} />}
      {view && <ReviewView review={view} onClose={()=>setView(null)} />}
    </div>
  )
}

function ReviewModal({ modal, setModal, employees, saving, onSave }) {
  const setK = (i, patch) => setModal(m => ({ ...m, kpis: m.kpis.map((k,idx)=>idx===i?{...k,...patch}:k) }))
  const wsum = modal.kpis.reduce((s,k)=>s+Number(k.weightage||0),0)
  const overall = wsum>0 ? (modal.kpis.reduce((s,k)=>s+Number(k.rating||0)*Number(k.weightage||0),0)/wsum).toFixed(2) : '0.00'
  return (
    <div className="modal-backdrop"><div className="modal-box" onClick={e=>e.stopPropagation()} style={{ maxWidth:720, width:'95%', maxHeight:'92vh', overflowY:'auto' }}>
      <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>New Performance Review</h2><button onClick={()=>setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
      <div className="grid grid-cols-2 md:grid-cols-3 gap-3 mb-4">
        <div className="col-span-2"><label className="label">Employee *</label><select className="input-3d text-sm" value={modal.employee_id} onChange={e=>setModal(m=>({...m,employee_id:e.target.value}))}><option value="">Select…</option>{employees.map(e=><option key={e.id} value={e.id}>{e.name}</option>)}</select></div>
        <div><label className="label">Type</label><select className="input-3d text-sm" value={modal.review_type} onChange={e=>setModal(m=>({...m,review_type:e.target.value}))}>{REVIEW_TYPES.map(t=><option key={t}>{t}</option>)}</select></div>
        <div><label className="label">Year</label><input type="number" className="input-3d text-sm" value={modal.period_year} onChange={e=>setModal(m=>({...m,period_year:e.target.value}))}/></div>
        <div><label className="label">Period Label</label><input className="input-3d text-sm" placeholder="Q3 2026" value={modal.period_label} onChange={e=>setModal(m=>({...m,period_label:e.target.value}))}/></div>
      </div>
      <div className="flex items-center justify-between mb-2"><p className="text-[11px] font-bold uppercase" style={{ color:'var(--text-muted)' }}>KPI Ratings</p><span className="text-xs font-black" style={{ color:'#8b5cf6' }}>Overall: {overall}</span></div>
      <div className="space-y-2 mb-4">
        {modal.kpis.length===0 && <p className="text-xs" style={{ color:'var(--text-muted)' }}>No active KPIs — create KPIs first.</p>}
        {modal.kpis.map((k,i)=>(
          <div key={i} className="flex items-center gap-2 px-3 py-2 rounded-xl flex-wrap" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
            <span className="text-xs font-bold flex-1" style={{ color:'var(--text-h)' }}>{k.kpi_name}</span>
            <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>wt {k.weightage}%</span>
            <div className="flex items-center gap-1">{[1,2,3,4,5].map(n=><button key={n} onClick={()=>setK(i,{rating:n})} className="w-6 h-6 rounded-lg text-[11px] font-bold" style={{ background:Number(k.rating)>=n?GRAD:'var(--bg-card)', color:Number(k.rating)>=n?'#fff':'var(--text-muted)', border:'1px solid var(--border)' }}>{n}</button>)}</div>
          </div>
        ))}
      </div>
      <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div><label className="label">Strengths</label><textarea rows={2} className="input-3d text-sm resize-none" value={modal.strengths} onChange={e=>setModal(m=>({...m,strengths:e.target.value}))}/></div>
        <div><label className="label">Improvements</label><textarea rows={2} className="input-3d text-sm resize-none" value={modal.improvements} onChange={e=>setModal(m=>({...m,improvements:e.target.value}))}/></div>
        <div><label className="label">Comments</label><textarea rows={2} className="input-3d text-sm resize-none" value={modal.comments} onChange={e=>setModal(m=>({...m,comments:e.target.value}))}/></div>
        <div><label className="label">Recommendation</label><textarea rows={2} className="input-3d text-sm resize-none" value={modal.recommendation} onChange={e=>setModal(m=>({...m,recommendation:e.target.value}))}/></div>
      </div>
      <div className="flex gap-3 pt-4"><button onClick={()=>setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={onSave} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Saving…':'Create Review'}</button></div>
    </div></div>
  )
}

function ReviewView({ review, onClose }) {
  const st = REVIEW_ST[review.status]||{}
  return (
    <div className="modal-backdrop"><div className="modal-box" onClick={e=>e.stopPropagation()} style={{ maxWidth:640, width:'95%', maxHeight:'92vh', overflowY:'auto' }}>
      <div className="flex items-center justify-between mb-1"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{review.employee_name}</h2><button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
      <div className="flex items-center gap-2 mb-4"><span className="text-xs" style={{ color:'var(--text-muted)' }}>{review.review_type} · {review.period_label||review.period_year}</span><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:st.bg, color:st.c }}>{review.status}</span><span className="ml-auto text-lg font-black" style={{ color:'#8b5cf6' }}>{review.overall_rating}</span></div>
      <p className="text-[11px] font-bold uppercase mb-2" style={{ color:'var(--text-muted)' }}>KPI Ratings</p>
      <div className="space-y-1.5 mb-4">{(review.kpis||[]).map((k,i)=>(
        <div key={i} className="flex items-center justify-between px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}><span className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{k.kpi_name}</span><span className="text-xs" style={{ color:'var(--text-muted)' }}>wt {k.weightage}% · <b style={{ color:'#8b5cf6' }}>{k.rating}</b></span></div>
      ))}</div>
      {[['Strengths',review.strengths],['Improvements',review.improvements],['Comments',review.comments],['Recommendation',review.recommendation]].filter(([,v])=>v).map(([l,v])=>(
        <div key={l} className="mb-2"><p className="text-[10px] font-bold uppercase" style={{ color:'var(--text-muted)' }}>{l}</p><p className="text-sm" style={{ color:'var(--text-h)' }}>{v}</p></div>
      ))}
    </div></div>
  )
}

/* ── Promotions & Increments (shared shell) ── */
function Recommendations({ kind, employees, showToast }) {
  const isPromo = kind === 'promotions'
  const api = isPromo ? hrApi.performance.promotions : hrApi.performance.increments
  const [rows, setRows] = useState([]); const [loading, setLoading] = useState(true)
  const [gen, setGen] = useState(''); const [busy, setBusy] = useState(false)
  const [structures, setStructures] = useState([]); const [pick, setPick] = useState({})   // promotion → optional new structure per row
  const load = useCallback(() => { setLoading(true); api.list().then(setRows).catch(()=>showToast('Failed to load','error')).finally(()=>setLoading(false)) }, [kind])  // eslint-disable-line
  useEffect(() => { load() }, [load])
  // Salary Engine: a promotion may optionally assign a new active salary structure.
  useEffect(() => { if (isPromo) hrApi.payroll.salaryStructures.list({ status:'Active' }).then(r=>setStructures(r.data||[])).catch(()=>{}) }, [isPromo])
  const generate = async () => { if (!gen) return showToast('Select an employee','error'); setBusy(true); try { await api.generate(Number(gen)); showToast('Recommendation generated'); setGen(''); load() } catch (e) { showToast(e.response?.data?.message||'Failed','error') } finally { setBusy(false) } }
  const decide = async (r, status) => {
    try {
      if (isPromo) { const sid = pick[r.id]; await api.setStatus(r.id, status, r.recommended_designation || null, sid ? Number(sid) : null); showToast(status === 'Approved' && sid ? 'Approved — new salary structure assigned' : status) }
      else { await api.setStatus(r.id, status); showToast(status === 'Approved' ? 'Approved — salary revision applied' : status) }
      load()
    } catch (e) { showToast(e.response?.data?.message || 'Failed','error') }
  }

  // #3 — the two kinds carry the decision on different keys, so the status
  // matcher reads whichever this kind uses rather than assuming one of them.
  const [search, setSearch] = useState(''); const [statusF, setStatusF] = useState('All')
  const shown = applyListFilter(rows, {
    search, fields: ['employee_name', 'department'],
    matchers: [[statusF, (r, v) => (isPromo ? r.status : r.approval_status) === v]],
  })

  return (
    <div className="space-y-4">
      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 items-end flex-wrap">
          <div className="flex-1 min-w-[220px]"><label className="label">Generate {isPromo?'promotion':'increment'} recommendation for</label>
            <select className="input-3d text-sm" value={gen} onChange={e=>setGen(e.target.value)}><option value="">Select employee…</option>{employees.map(e=><option key={e.id} value={e.id}>{e.name} · {e.department}</option>)}</select>
          </div>
          <button onClick={generate} disabled={busy} className="px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:busy?0.7:1 }}>{busy?'Generating…':'Generate'}</button>
        </div>
        <p className="text-[11px] mt-2" style={{ color:'var(--text-muted)' }}>{isPromo ? 'Derived from the latest review rating and completed goals.' : 'Reads the current active salary (read-only) and the latest rating — Payroll is never modified.'}</p>
      </div>

      {/* #3 — in memory: neither recommendation endpoint accepts filter params. */}
      <ListFilter
        search={search} setSearch={setSearch} placeholder="Employee or department…"
        selects={[{ key:'status', label:'Status', value:statusF, onChange:setStatusF, options:['All','Pending','Approved','Rejected'] }]}
        onClear={()=>{ setSearch(''); setStatusF('All') }}
      />

      {loading ? <HrLoading label="Loading…" /> : shown.length===0 ? <HrEmpty icon={isPromo?TrendingUp:Wallet} title={rows.length ? 'No matching recommendations' : `No ${kind} yet`} hint={rows.length ? 'No recommendations match these filters.' : 'Generate a recommendation above.'} />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:820 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{(isPromo?['Employee','Eligible','Rating','Goals','Reason','Status','Actions']:['Employee','Current CTC','Suggested %','Amount','Reason','Status','Actions']).map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{shown.map(r=>{ const status = isPromo?r.status:r.approval_status; const st = REC_ST[status]||{}; return (
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                  <td className="px-3 py-2.5"><span className="font-bold" style={{ color:'var(--text-h)' }}>{r.employee_name}</span> <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>{r.department}</span></td>
                  {isPromo ? <>
                    <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={r.eligible?{background:'rgba(16,185,129,0.12)',color:'#10b981'}:{background:'rgba(239,68,68,0.1)',color:'#f87171'}}>{r.eligible?'Eligible':'Not Eligible'}</span></td>
                    <td className="px-3 py-2.5 font-bold" style={{ color:'#8b5cf6' }}>{r.overall_rating}</td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.completed_goals}</td>
                  </> : <>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{inr(r.current_salary)}</td>
                    <td className="px-3 py-2.5 font-bold" style={{ color:'#10b981' }}>{r.suggested_percentage}%</td>
                    <td className="px-3 py-2.5 font-black" style={{ color:'var(--text-h)' }}>{inr(r.suggested_amount)}</td>
                  </>}
                  <td className="px-3 py-2.5 text-[11px] max-w-[220px]" style={{ color:'var(--text-muted)' }}>{r.reason}</td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:st.bg, color:st.c }}>{status}</span></td>
                  <td className="px-3 py-2.5">{status==='Pending' && <div className="flex gap-1.5 justify-end items-center">
                    {isPromo && <select value={pick[r.id]||''} onChange={e=>setPick(p=>({...p,[r.id]:e.target.value}))} className="input-3d text-[11px]" style={{ padding:'4px 6px', maxWidth:150 }} title="Optionally assign a new salary structure on approval">
                      <option value="">No new structure</option>{structures.map(s=><option key={s.id} value={s.id}>{s.name}</option>)}
                    </select>}
                    <button onClick={()=>decide(r,'Approved')} className="text-[11px] font-bold px-2.5 py-1 rounded-lg" style={{ background:'rgba(16,185,129,0.12)', color:'#10b981' }}>Approve</button>
                    <button onClick={()=>decide(r,'Rejected')} className="text-[11px] font-bold px-2.5 py-1 rounded-lg" style={{ background:'rgba(239,68,68,0.1)', color:'#f87171' }}>Reject</button>
                  </div>}</td>
                </tr>
              )})}</tbody>
            </table>
          </div>}
    </div>
  )
}
