import { useState, useEffect, useCallback } from 'react'
import { useTheme } from '@/context/ThemeContext'
import {
  ShieldCheck, Tag, FileText, UserCheck, ClipboardList, Clock3, BadgeCheck, BarChart3,
  Lock, Plus, Pencil, X, Power, Search, Eye, PlayCircle, XCircle, Clock, CalendarDays, UserPlus,
  Star, Send, CheckCircle2, CalendarClock, Check, BadgeCheck as BadgeCheckIcon,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import ProbationReports from './ProbationReports'

const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'
const PROB_C = { Assigned:'#3b82f6', Active:'#10b981', Extended:'#f59e0b', Confirmed:'#8b5cf6', Failed:'#f87171', Cancelled:'#94a3b8' }
const REVIEW_C = { Draft:'#94a3b8', Submitted:'#3b82f6', Completed:'#10b981' }
const REC_C = { Continue:'#3b82f6', Extend:'#f59e0b', Confirm:'#10b981', Fail:'#f87171' }
const EXT_C = { Pending:'#f59e0b', Approved:'#10b981', Rejected:'#f87171' }
const CONF_C = { Pending:'#f59e0b', Approved:'#3b82f6', Rejected:'#f87171', Confirmed:'#10b981' }
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' }) : '—'

const TABS = [
  { key:'types',        label:'Probation Types',    icon:Tag,           ready:true },
  { key:'policies',     label:'Probation Policies', icon:FileText,      ready:true },
  { key:'employees',    label:'Employee Probation', icon:UserCheck,     ready:true },
  { key:'reviews',      label:'Reviews',            icon:ClipboardList, ready:true },
  { key:'extensions',   label:'Extensions',         icon:Clock3,        ready:true },
  { key:'confirmation', label:'Confirmation',       icon:BadgeCheck,    ready:true },
  { key:'reports',      label:'Reports',            icon:BarChart3,     ready:true },
]

export default function ProbationManagement() {
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
            <ShieldCheck size={22} style={{ color:'#a78bfa' }}/> Probation <span className="text-gradient">Management</span>
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

      {tab === 'types' ? <ProbationTypes showToast={showToast} />
        : tab === 'policies' ? <ProbationPolicies showToast={showToast} />
        : tab === 'employees' ? <EmployeeProbation showToast={showToast} />
        : tab === 'reviews' ? <ProbationReviews showToast={showToast} />
        : tab === 'extensions' ? <ProbationExtensions showToast={showToast} />
        : tab === 'confirmation' ? <ProbationConfirmations showToast={showToast} />
        : tab === 'reports' ? <ProbationReports showToast={showToast} />
        : null}
    </div>
  )
}

const StatusPill = ({ active }) => <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={active?{background:'rgba(16,185,129,0.12)',color:'#10b981'}:{background:'var(--bg-input)',color:'var(--text-muted)'}}>{active?'Active':'Inactive'}</span>
const RowActions = ({ onEdit, onToggle, active }) => (
  <div className="flex gap-1.5 justify-end">
    <button onClick={onEdit} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Pencil size={13}/></button>
    <button onClick={onToggle} className="p-1.5 rounded-lg" style={active?{background:'rgba(239,68,68,0.1)',color:'#f87171'}:{background:'rgba(16,185,129,0.1)',color:'#10b981'}}><Power size={13}/></button>
  </div>
)
function Kpis({ stats }) {
  const K = [{l:'Total',v:stats.total,c:'#7C3AED'},{l:'Active',v:stats.active,c:'#10b981'},{l:'Inactive',v:stats.inactive,c:'#f87171'}]
  return <div className="grid grid-cols-3 gap-4">{K.map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}</div>
}

/* ── Probation Types ── */
const TYPE_FLAGS = [['confirmation_required','Confirmation Required'],['review_required','Review Required'],['extension_allowed','Extension Allowed']]
function ProbationTypes({ showToast }) {
  const [rows, setRows] = useState([]); const [stats, setStats] = useState({ total:0, active:0, inactive:0 })
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState(''); const [statusF, setStatusF] = useState('All')
  const [modal, setModal] = useState(null); const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    const params = {}; if (statusF !== 'All') params.status = statusF; if (search) params.search = search
    hrApi.probation.types.list(params).then(res => { setRows(res.data||[]); setStats(res.stats||stats) }).catch(()=>showToast('Failed to load types','error')).finally(()=>setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusF, search])
  useEffect(() => { load() }, [load])

  const EMPTY = { code:'', name:'', default_duration_days:90, confirmation_required:true, review_required:true, extension_allowed:true, max_extensions:1, description:'', is_active:true }
  const save = async () => {
    const f = modal.form
    if (!f.code.trim() || !f.name.trim()) return showToast('Code and name are required','error')
    setSaving(true)
    try { modal.editing ? await hrApi.probation.types.update(modal.editing, f) : await hrApi.probation.types.create(f); showToast(`Probation type ${modal.editing?'updated':'created'}`); setModal(null); load() }
    catch (e) { showToast(e.response?.data?.message||'Save failed','error') } finally { setSaving(false) }
  }
  const toggle = async (r) => { try { await hrApi.probation.types.setStatus(r.id, !r.is_active); load() } catch { showToast('Failed','error') } }
  const hasF = statusF!=='All'||search

  return (
    <div className="space-y-4">
      <Kpis stats={stats} />
      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[200px]"><label className="label">Search</label><Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/><input className="input-3d pl-9 text-sm" placeholder="Name or code…" value={search} onChange={e=>setSearch(e.target.value)}/></div>
          <div className="min-w-[130px]"><label className="label">Status</label><select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['All','Active','Inactive'].map(s=><option key={s}>{s}</option>)}</select></div>
          {hasF && <button onClick={()=>{ setStatusF('All'); setSearch('') }} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
          <button onClick={()=>setModal({ editing:null, form:{...EMPTY} })} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white ml-auto" style={{ background:GRAD }}><Plus size={15}/> Add Type</button>
        </div>
      </div>

      {loading ? <HrLoading label="Loading probation types…" /> : rows.length===0 ? <HrEmpty icon={Tag} title="No probation types yet" hint="Create probation types (Standard, Extended…)." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:820 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Type','Duration','Confirmation','Review','Extensions','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{rows.map(r=>(
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)', opacity:r.is_active?1:0.55 }}>
                  <td className="px-3 py-2.5"><span className="font-bold" style={{ color:'var(--text-h)' }}>{r.name}</span> <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.code}</span></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.default_duration_days}d</td>
                  <td className="px-3 py-2.5">{r.confirmation_required?'✓':'—'}</td>
                  <td className="px-3 py-2.5">{r.review_required?'✓':'—'}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.extension_allowed?`${r.max_extensions}×`:'—'}</td>
                  <td className="px-3 py-2.5"><StatusPill active={r.is_active} /></td>
                  <td className="px-3 py-2.5"><RowActions onEdit={()=>setModal({ editing:r.id, form:{ ...EMPTY, ...r } })} onToggle={()=>toggle(r)} active={r.is_active} /></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}

      {modal && (
        <div className="modal-backdrop"><div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()} style={{ maxHeight:'92vh', overflowY:'auto' }}>
          <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{modal.editing?'Edit Probation Type':'Add Probation Type'}</h2><button onClick={()=>setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Code *</label><input className="input-3d text-sm" value={modal.form.code} onChange={e=>setModal(m=>({...m,form:{...m.form,code:e.target.value}}))}/></div>
            <div><label className="label">Name *</label><input className="input-3d text-sm" value={modal.form.name} onChange={e=>setModal(m=>({...m,form:{...m.form,name:e.target.value}}))}/></div>
            <div><label className="label">Default Duration (days)</label><input type="number" min="0" className="input-3d text-sm" value={modal.form.default_duration_days} onChange={e=>setModal(m=>({...m,form:{...m.form,default_duration_days:e.target.value}}))}/></div>
            <div><label className="label">Max Extensions</label><input type="number" min="0" className="input-3d text-sm" value={modal.form.max_extensions} onChange={e=>setModal(m=>({...m,form:{...m.form,max_extensions:e.target.value}}))}/></div>
            <div className="col-span-2 grid grid-cols-1 md:grid-cols-3 gap-2">
              {TYPE_FLAGS.map(([k,l])=><label key={k} className="flex items-center gap-2 text-xs font-semibold px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}><input type="checkbox" checked={modal.form[k]} onChange={e=>setModal(m=>({...m,form:{...m.form,[k]:e.target.checked}}))}/>{l}</label>)}
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

/* ── Probation Policies ── */
const FREQUENCIES = ['Weekly', 'Monthly', 'Quarterly']
function ProbationPolicies({ showToast }) {
  const [rows, setRows] = useState([]); const [stats, setStats] = useState({ total:0, active:0, inactive:0 })
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState(''); const [statusF, setStatusF] = useState('All')
  const [types, setTypes] = useState([]); const [org, setOrg] = useState({ grades:[], designations:[], departments:[] })
  const [modal, setModal] = useState(null); const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    const params = {}; if (statusF !== 'All') params.status = statusF; if (search) params.search = search
    hrApi.probation.policies.list(params).then(res => { setRows(res.data||[]); setStats(res.stats||stats) }).catch(()=>showToast('Failed to load policies','error')).finally(()=>setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusF, search])
  useEffect(() => { load() }, [load])
  useEffect(() => {
    hrApi.probation.types.list({ status:'Active' }).then(r=>setTypes(r.data||[])).catch(()=>{})
    hrApi.organization.options().then(o=>setOrg({ grades:o.grades||[], designations:o.designations||[], departments:o.departments||[] })).catch(()=>{})
  }, [])

  const EMPTY = { name:'', probation_type_id:'', department_id:'', designation_id:'', grade_id:'', review_frequency:'Monthly', notice_days:0, extension_limit:1, auto_confirmation:false, is_active:true }
  const openEdit = async (row) => {
    try { const full = await hrApi.probation.policies.get(row.id)
      setModal({ editing:full.id, form:{ ...EMPTY, ...full, probation_type_id:full.probation_type_id||'', department_id:full.department_id||'', designation_id:full.designation_id||'', grade_id:full.grade_id||'' } })
    } catch { showToast('Failed to load policy','error') }
  }
  const save = async () => {
    const f = modal.form
    if (!f.name.trim()) return showToast('Policy name is required','error')
    if (!f.probation_type_id) return showToast('Probation type is required','error')
    setSaving(true)
    try { modal.editing ? await hrApi.probation.policies.update(modal.editing, f) : await hrApi.probation.policies.create(f); showToast(`Policy ${modal.editing?'updated':'created'}`); setModal(null); load() }
    catch (e) { showToast(e.response?.data?.message||'Save failed','error') } finally { setSaving(false) }
  }
  const toggle = async (r) => { try { await hrApi.probation.policies.setStatus(r.id, !r.is_active); load() } catch { showToast('Failed','error') } }
  const hasF = statusF!=='All'||search

  return (
    <div className="space-y-4">
      <Kpis stats={stats} />
      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[200px]"><label className="label">Search</label><Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/><input className="input-3d pl-9 text-sm" placeholder="Policy name…" value={search} onChange={e=>setSearch(e.target.value)}/></div>
          <div className="min-w-[130px]"><label className="label">Status</label><select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['All','Active','Inactive'].map(s=><option key={s}>{s}</option>)}</select></div>
          {hasF && <button onClick={()=>{ setStatusF('All'); setSearch('') }} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
          <button onClick={()=>setModal({ editing:null, form:{...EMPTY} })} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white ml-auto" style={{ background:GRAD }}><Plus size={15}/> Add Policy</button>
        </div>
      </div>

      {loading ? <HrLoading label="Loading policies…" /> : rows.length===0 ? <HrEmpty icon={FileText} title="No probation policies yet" hint="Create a policy scoped to a grade, designation or department." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:860 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Policy','Probation Type','Scope','Review','Notice','Auto Confirm','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{rows.map(r=>(
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)', opacity:r.is_active?1:0.55 }}>
                  <td className="px-3 py-2.5 font-bold" style={{ color:'var(--text-h)' }}>{r.name}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.probation_type||'—'}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{[r.grade_name, r.designation_name, r.department_name].filter(Boolean).join(' · ')||'All'}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.review_frequency}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.notice_days}d</td>
                  <td className="px-3 py-2.5">{r.auto_confirmation?'✓':'—'}</td>
                  <td className="px-3 py-2.5"><StatusPill active={r.is_active} /></td>
                  <td className="px-3 py-2.5"><RowActions onEdit={()=>openEdit(r)} onToggle={()=>toggle(r)} active={r.is_active} /></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}

      {modal && (
        <div className="modal-backdrop"><div className="modal-box" onClick={e=>e.stopPropagation()} style={{ maxWidth:720, width:'95%', maxHeight:'92vh', overflowY:'auto' }}>
          <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{modal.editing?'Edit Probation Policy':'Add Probation Policy'}</h2><button onClick={()=>setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
          <div className="grid grid-cols-2 md:grid-cols-3 gap-3 mb-3">
            <div className="col-span-2"><label className="label">Policy Name *</label><input className="input-3d text-sm" value={modal.form.name} onChange={e=>setModal(m=>({...m,form:{...m.form,name:e.target.value}}))}/></div>
            <div><label className="label">Probation Type *</label><select className="input-3d text-sm" value={modal.form.probation_type_id} onChange={e=>setModal(m=>({...m,form:{...m.form,probation_type_id:e.target.value}}))}><option value="">Select…</option>{types.map(t=><option key={t.id} value={t.id}>{t.name}</option>)}</select></div>
            <div><label className="label">Review Frequency</label><select className="input-3d text-sm" value={modal.form.review_frequency} onChange={e=>setModal(m=>({...m,form:{...m.form,review_frequency:e.target.value}}))}>{FREQUENCIES.map(x=><option key={x}>{x}</option>)}</select></div>
            <div><label className="label">Notice Days</label><input type="number" min="0" className="input-3d text-sm" value={modal.form.notice_days} onChange={e=>setModal(m=>({...m,form:{...m.form,notice_days:e.target.value}}))}/></div>
            <div><label className="label">Extension Limit</label><input type="number" min="0" className="input-3d text-sm" value={modal.form.extension_limit} onChange={e=>setModal(m=>({...m,form:{...m.form,extension_limit:e.target.value}}))}/></div>
            <div><label className="label">Grade</label><select className="input-3d text-sm" value={modal.form.grade_id} onChange={e=>setModal(m=>({...m,form:{...m.form,grade_id:e.target.value}}))}><option value="">—</option>{org.grades.map(g=><option key={g.id} value={g.id}>{g.name}</option>)}</select></div>
            <div><label className="label">Designation</label><select className="input-3d text-sm" value={modal.form.designation_id} onChange={e=>setModal(m=>({...m,form:{...m.form,designation_id:e.target.value}}))}><option value="">—</option>{org.designations.map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select></div>
            <div><label className="label">Department</label><select className="input-3d text-sm" value={modal.form.department_id} onChange={e=>setModal(m=>({...m,form:{...m.form,department_id:e.target.value}}))}><option value="">—</option>{org.departments.map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select></div>
            <label className="col-span-2 md:col-span-3 flex items-center gap-2 text-xs font-semibold px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}><input type="checkbox" checked={modal.form.auto_confirmation} onChange={e=>setModal(m=>({...m,form:{...m.form,auto_confirmation:e.target.checked}}))}/> Auto Confirmation</label>
            {modal.editing && <label className="col-span-2 md:col-span-3 flex items-center gap-2 text-xs font-semibold"><input type="checkbox" checked={modal.form.is_active} onChange={e=>setModal(m=>({...m,form:{...m.form,is_active:e.target.checked}}))}/> Active</label>}
          </div>
          <div className="flex gap-3 pt-2"><button onClick={()=>setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Saving…':'Save Policy'}</button></div>
        </div></div>
      )}
    </div>
  )
}

/* ── Employee Probation (Phase 2) ── */
const ProbPill = ({ status }) => <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${PROB_C[status]||'#7C3AED'}1f`, color:PROB_C[status]||'#7C3AED' }}>{status}</span>

function EmployeeProbation({ showToast }) {
  const [data, setData] = useState({ data:[], stats:{ total:0, active:0, extended:0, confirmed:0, pending_confirmation:0 } })
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState(''); const [statusF, setStatusF] = useState('All'); const [deptF, setDeptF] = useState(''); const [polF, setPolF] = useState('')
  const [employees, setEmployees] = useState([]); const [policies, setPolicies] = useState([]); const [depts, setDepts] = useState([])
  const [modal, setModal] = useState(null); const [view, setView] = useState(null); const [cancelling, setCancelling] = useState(null); const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    const params = {}
    if (statusF !== 'All') params.status = statusF
    if (deptF) params.department = deptF
    if (polF) params.probation_policy_id = polF
    if (search) params.search = search
    hrApi.probation.employees.list(params).then(setData).catch(()=>showToast('Failed to load probations','error')).finally(()=>setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusF, deptF, polF, search])
  useEffect(() => { load() }, [load])
  useEffect(() => {
    hrApi.employees.list({ per_page:500 }).then(r=>setEmployees(Array.isArray(r)?r:[])).catch(()=>{})
    hrApi.probation.policies.list({ status:'Active' }).then(r=>setPolicies(r.data||[])).catch(()=>{})
    hrApi.organization.options().then(o=>setDepts((o.departments||[]).map(d=>d.name))).catch(()=>{})
  }, [])

  const doAssign = async (form) => {
    if (!form.employee_id) return showToast('Select an employee','error')
    setSaving(true)
    try { await hrApi.probation.employees.assign({ employee_id:form.employee_id, probation_policy_id:form.probation_policy_id||undefined, probation_start_date:form.probation_start_date||undefined, remarks:form.remarks||undefined }); showToast('Probation assigned'); setModal(null); load() }
    catch (e) { showToast(e.response?.data?.message||'Assignment failed','error') } finally { setSaving(false) }
  }
  const doCancel = async () => {
    setSaving(true)
    try { await hrApi.probation.employees.cancel(cancelling.id, cancelling.reason||''); showToast('Probation cancelled'); setCancelling(null); load(); if (view) setView(null) }
    catch (e) { showToast(e.response?.data?.message||'Failed','error') } finally { setSaving(false) }
  }

  const s = data.stats
  const KPIS = [
    { l:'Total', v:s.total, c:'#7C3AED' }, { l:'Active', v:s.active, c:'#10b981' },
    { l:'Extended', v:s.extended, c:'#f59e0b' }, { l:'Confirmed', v:s.confirmed, c:'#8b5cf6' }, { l:'Pending Confirmation', v:s.pending_confirmation, c:'#f87171' },
  ]
  const hasF = statusF!=='All'||deptF||polF||search
  const rows = data.data

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-5 gap-3">{KPIS.map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}</div>

      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[180px]"><label className="label">Search</label><Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/><input className="input-3d pl-9 text-sm" placeholder="Employee name or code…" value={search} onChange={e=>setSearch(e.target.value)}/></div>
          <div className="min-w-[150px]"><label className="label">Department</label><select className="input-3d text-sm" value={deptF} onChange={e=>setDeptF(e.target.value)}><option value="">All</option>{depts.map(d=><option key={d} value={d}>{d}</option>)}</select></div>
          <div className="min-w-[160px]"><label className="label">Policy</label><select className="input-3d text-sm" value={polF} onChange={e=>setPolF(e.target.value)}><option value="">All</option>{policies.map(p=><option key={p.id} value={p.id}>{p.name}</option>)}</select></div>
          <div className="min-w-[140px]"><label className="label">Status</label><select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['All','Assigned','Active','Extended','Confirmed','Failed','Cancelled'].map(x=><option key={x}>{x}</option>)}</select></div>
          {hasF && <button onClick={()=>{ setStatusF('All'); setDeptF(''); setPolF(''); setSearch('') }} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
          <button onClick={()=>setModal(true)} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white ml-auto" style={{ background:GRAD }}><UserPlus size={15}/> Assign Probation</button>
        </div>
      </div>

      {loading ? <HrLoading label="Loading probations…" /> : rows.length===0 ? <HrEmpty icon={UserCheck} title="No probations yet" hint="Assign an employee to a probation policy to begin." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:1000 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Department','Designation','Policy','Type','Start','End','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{rows.map(r=>(
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                  <td className="px-3 py-2.5"><div className="font-bold" style={{ color:'var(--text-h)' }}>{r.employee_name}</div><div className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code}</div></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.department||'—'}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.designation||'—'}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-h)' }}>{r.policy}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.probation_type}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{fmtDate(r.probation_start_date)}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{fmtDate(r.probation_end_date)}{r.remaining_days!=null && <div className="text-[10px]" style={{ color:r.remaining_days<0?'#f87171':'var(--text-muted)' }}>{r.remaining_days<0?`${-r.remaining_days}d overdue`:`${r.remaining_days}d left`}</div>}</td>
                  <td className="px-3 py-2.5"><ProbPill status={r.current_status} /></td>
                  <td className="px-3 py-2.5"><div className="flex gap-1.5 justify-end">
                    <button title="View" onClick={()=>setView(r.id)} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Eye size={13}/></button>
                    {r.current_status==='Assigned' && <button title="Activate" onClick={()=>hrApi.probation.employees.activate(r.id).then(load).catch(e=>showToast(e.response?.data?.message||'Failed','error'))} className="p-1.5 rounded-lg" style={{ background:'rgba(16,185,129,0.1)', color:'#10b981' }}><PlayCircle size={13}/></button>}
                    {!['Confirmed','Failed','Cancelled'].includes(r.current_status) && <button title="Cancel" onClick={()=>setCancelling({ id:r.id, reason:'' })} className="p-1.5 rounded-lg" style={{ background:'rgba(239,68,68,0.1)', color:'#f87171' }}><XCircle size={13}/></button>}
                  </div></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}

      {modal && <AssignProbationModal employees={employees} policies={policies} saving={saving} onClose={()=>setModal(null)} onAssign={doAssign} />}
      {cancelling && (
        <div className="modal-backdrop"><div className="modal-box max-w-md" onClick={e=>e.stopPropagation()}>
          <div className="flex items-center justify-between mb-3"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Cancel Probation</h2><button onClick={()=>setCancelling(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
          <p className="text-xs mb-3" style={{ color:'var(--text-muted)' }}>This marks the probation as Cancelled (read-only afterwards).</p>
          <label className="label">Reason (optional)</label><textarea rows={3} className="input-3d text-sm resize-none" value={cancelling.reason} onChange={e=>setCancelling(c=>({...c,reason:e.target.value}))}/>
          <div className="flex gap-3 pt-4"><button onClick={()=>setCancelling(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Keep</button><button onClick={doCancel} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#f87171,#ef4444)' }}>{saving?'Working…':'Cancel Probation'}</button></div>
        </div></div>
      )}
      {view && <ProbationDrawer id={view} onClose={()=>setView(null)} onChanged={load} onCancel={(id)=>setCancelling({ id, reason:'' })} showToast={showToast} />}
    </div>
  )
}

function AssignProbationModal({ employees, policies, saving, onClose, onAssign }) {
  const [form, setForm] = useState({ employee_id:'', probation_policy_id:'', probation_start_date:'', remarks:'' })
  const setF = p => setForm(f => ({ ...f, ...p }))
  return (
    <div className="modal-backdrop"><div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()}>
      <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Assign Probation</h2><button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
      <div className="space-y-3">
        <div><label className="label">Employee *</label><select className="input-3d text-sm" value={form.employee_id} onChange={e=>setF({ employee_id:e.target.value })}><option value="">Select…</option>{employees.map(e=><option key={e.id} value={e.id}>{e.name} ({e.employee_code})</option>)}</select></div>
        <div><label className="label">Policy</label><select className="input-3d text-sm" value={form.probation_policy_id} onChange={e=>setF({ probation_policy_id:e.target.value })}><option value="">Auto-select from employee</option>{policies.map(p=><option key={p.id} value={p.id}>{p.name}</option>)}</select><p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>Left blank, the policy is matched from the employee’s grade / designation / department.</p></div>
        <div><label className="label">Start Date</label><input type="date" className="input-3d text-sm" value={form.probation_start_date} onChange={e=>setF({ probation_start_date:e.target.value })}/><p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>Defaults to the employee’s joining date. End date is auto-calculated from the probation type.</p></div>
        <div><label className="label">Remarks</label><textarea rows={2} className="input-3d text-sm resize-none" value={form.remarks} onChange={e=>setF({ remarks:e.target.value })}/></div>
      </div>
      <div className="flex gap-3 pt-4"><button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={()=>onAssign(form)} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Assigning…':'Assign'}</button></div>
    </div></div>
  )
}

function ProbationDrawer({ id, onClose, onChanged, onCancel, showToast }) {
  const [p, setP] = useState(null); const [loading, setLoading] = useState(true); const [busy, setBusy] = useState(false)
  const reload = useCallback(() => { setLoading(true); return hrApi.probation.employees.get(id).then(setP).finally(()=>setLoading(false)) }, [id])
  useEffect(() => { reload() }, [reload])
  const activate = async () => { setBusy(true); try { await hrApi.probation.employees.activate(id); showToast('Probation activated'); await reload(); onChanged() } catch (e) { showToast(e.response?.data?.message||'Failed','error') } finally { setBusy(false) } }
  const Field = ({ l, v }) => <div><p className="label-caps mb-0.5">{l}</p><p className="text-sm font-semibold" style={{ color:'var(--text-h)' }}>{v||'—'}</p></div>
  const open = p && !['Confirmed','Failed','Cancelled'].includes(p.current_status)

  return (
    <div className="fixed inset-0 z-[9998] flex justify-end">
      <div className="absolute inset-0" style={{ background:'rgba(0,0,0,0.4)' }} />
      <div className="relative h-full overflow-y-auto animate-[slideIn_0.25s_ease]" onClick={e=>e.stopPropagation()} style={{ width:'min(470px,97vw)', background:'var(--bg-card,var(--bg-input))', borderLeft:'1px solid var(--border)', boxShadow:'-10px 0 40px rgba(0,0,0,0.3)' }}>
        <div className="sticky top-0 z-10 flex items-center justify-between px-5 py-4" style={{ background:'var(--bg-card,var(--bg-input))', borderBottom:'1px solid var(--border)' }}>
          <h2 className="font-black text-base flex items-center gap-2" style={{ color:'var(--text-h)' }}><ShieldCheck size={16} style={{ color:'#a78bfa' }}/> Employee Probation</h2>
          <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>
        {loading || !p ? <div className="p-6"><HrLoading label="Loading…" /></div> : (
          <div className="p-5 space-y-5">
            <div className="flex items-center justify-between gap-3">
              <div><p className="font-black text-lg" style={{ color:'var(--text-h)' }}>{p.employee_name}</p><p className="text-[11px] font-mono" style={{ color:'#a78bfa' }}>{p.employee_code} · {p.designation||'—'}</p></div>
              <ProbPill status={p.current_status} />
            </div>
            {p.remaining_days!=null && <div className="flex items-center gap-2 px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}><Clock size={14} style={{ color:'#a78bfa' }}/><span className="text-sm font-bold" style={{ color:p.remaining_days<0?'#f87171':'var(--text-h)' }}>{p.remaining_days<0?`${-p.remaining_days} days overdue`:`${p.remaining_days} days remaining`}</span></div>}
            <div className="grid grid-cols-2 gap-4">
              <Field l="Current Policy" v={p.policy} />
              <Field l="Probation Type" v={p.probation_type} />
              <Field l="Joining Date" v={fmtDate(p.joining_date)} />
              <Field l="Start Date" v={fmtDate(p.probation_start_date)} />
              <Field l="End Date" v={fmtDate(p.probation_end_date)} />
              <Field l="Confirmation Due" v={fmtDate(p.confirmation_due_date)} />
              <Field l="Review Cycle" v={p.review_cycle} />
              <Field l="Extension Count" v={p.extension_count} />
            </div>
            {p.remarks && <div><p className="label-caps mb-1">Remarks</p><p className="text-sm" style={{ color:'var(--text-h)' }}>{p.remarks}</p></div>}

            <div><p className="label-caps mb-2 flex items-center gap-1.5"><Clock size={12}/> Timeline</p>
              <div className="space-y-2.5">{(p.timeline||[]).map((t,i)=>(
                <div key={i} className="flex gap-3"><div className="mt-1.5 rounded-full flex-shrink-0" style={{ width:8, height:8, background:'#a78bfa' }}/><div><p className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{t.action}</p><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>{t.actor_name||'System'} · {t.created_at?new Date(t.created_at).toLocaleString():''}</p>{t.comment && <p className="text-[11px] mt-0.5" style={{ color:'var(--text-muted)' }}>“{t.comment}”</p>}</div></div>
              ))}{(!p.timeline||!p.timeline.length) && <p className="text-xs" style={{ color:'var(--text-muted)' }}>No timeline yet.</p>}</div>
            </div>

            {open && (
              <div className="flex gap-2">
                {p.current_status==='Assigned' && <button onClick={activate} disabled={busy} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:'linear-gradient(135deg,#10b981,#059669)' }}><PlayCircle size={14}/> Activate</button>}
                <button onClick={()=>onCancel(p.id)} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:'linear-gradient(135deg,#f87171,#ef4444)' }}><XCircle size={14}/> Cancel</button>
              </div>
            )}
            {!open && <p className="text-xs text-center py-2 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>This probation is {p.current_status.toLowerCase()} and read-only.</p>}
          </div>
        )}
      </div>
    </div>
  )
}

/* ── Probation Reviews (Phase 3) ── */
const ReviewPill = ({ status }) => <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${REVIEW_C[status]||'#7C3AED'}1f`, color:REVIEW_C[status]||'#7C3AED' }}>{status}</span>
const RecPill = ({ rec }) => <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${REC_C[rec]||'#7C3AED'}1f`, color:REC_C[rec]||'#7C3AED' }}>{rec}</span>
const Stars = ({ n }) => <span className="inline-flex items-center gap-0.5">{[1,2,3,4,5].map(i=><Star key={i} size={12} style={{ color:i<=n?'#f59e0b':'var(--border)' }} fill={i<=n?'#f59e0b':'none'} />)}</span>
const RATING_FIELDS = [['technical_rating','Technical'],['behaviour_rating','Behaviour'],['attendance_rating','Attendance'],['communication_rating','Communication']]

function ProbationReviews({ showToast }) {
  const [data, setData] = useState({ data:[], stats:{ total:0, pending:0, completed:0, avg_rating:0, recommend_confirm:0 } })
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState(''); const [statusF, setStatusF] = useState('All'); const [recF, setRecF] = useState('All'); const [deptF, setDeptF] = useState(''); const [revF, setRevF] = useState(''); const [dateF, setDateF] = useState('')
  const [probations, setProbations] = useState([]); const [employees, setEmployees] = useState([]); const [depts, setDepts] = useState([])
  const [modal, setModal] = useState(null); const [view, setView] = useState(null)

  const load = useCallback(() => {
    setLoading(true)
    const params = {}
    if (statusF !== 'All') params.status = statusF
    if (recF !== 'All') params.recommendation = recF
    if (deptF) params.department = deptF
    if (revF) params.reviewer_id = revF
    if (dateF) params.from = dateF
    if (search) params.search = search
    hrApi.probation.reviews.list(params).then(setData).catch(()=>showToast('Failed to load reviews','error')).finally(()=>setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusF, recF, deptF, revF, dateF, search])
  useEffect(() => { load() }, [load])
  useEffect(() => {
    hrApi.probation.employees.list().then(r=>setProbations((r.data||[]).filter(p=>['Active','Extended'].includes(p.current_status)))).catch(()=>{})
    hrApi.employees.list({ per_page:500 }).then(r=>setEmployees(Array.isArray(r)?r:[])).catch(()=>{})
    hrApi.organization.options().then(o=>setDepts((o.departments||[]).map(d=>d.name))).catch(()=>{})
  }, [])

  const s = data.stats
  const KPIS = [
    { l:'Total Reviews', v:s.total, c:'#7C3AED' }, { l:'Pending', v:s.pending, c:'#f59e0b' },
    { l:'Completed', v:s.completed, c:'#10b981' }, { l:'Average Rating', v:s.avg_rating, c:'#ec4899' }, { l:'Recommended Confirm', v:s.recommend_confirm, c:'#8b5cf6' },
  ]
  const hasF = statusF!=='All'||recF!=='All'||deptF||revF||dateF||search
  const rows = data.data

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-5 gap-3">{KPIS.map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}</div>

      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[160px]"><label className="label">Search</label><Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/><input className="input-3d pl-9 text-sm" placeholder="Employee name or code…" value={search} onChange={e=>setSearch(e.target.value)}/></div>
          <div className="min-w-[140px]"><label className="label">Department</label><select className="input-3d text-sm" value={deptF} onChange={e=>setDeptF(e.target.value)}><option value="">All</option>{depts.map(d=><option key={d} value={d}>{d}</option>)}</select></div>
          <div className="min-w-[150px]"><label className="label">Reviewer</label><select className="input-3d text-sm" value={revF} onChange={e=>setRevF(e.target.value)}><option value="">All</option>{employees.map(e=><option key={e.id} value={e.id}>{e.name}</option>)}</select></div>
          <div className="min-w-[130px]"><label className="label">Status</label><select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['All','Draft','Submitted','Completed'].map(x=><option key={x}>{x}</option>)}</select></div>
          <div className="min-w-[130px]"><label className="label">Recommendation</label><select className="input-3d text-sm" value={recF} onChange={e=>setRecF(e.target.value)}>{['All','Continue','Extend','Confirm','Fail'].map(x=><option key={x}>{x}</option>)}</select></div>
          <div className="min-w-[140px]"><label className="label">Review Date (from)</label><input type="date" className="input-3d text-sm" value={dateF} onChange={e=>setDateF(e.target.value)}/></div>
          {hasF && <button onClick={()=>{ setStatusF('All'); setRecF('All'); setDeptF(''); setRevF(''); setDateF(''); setSearch('') }} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
          <button onClick={()=>setModal({ editing:null })} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white ml-auto" style={{ background:GRAD }}><Plus size={15}/> Create Review</button>
        </div>
      </div>

      {loading ? <HrLoading label="Loading reviews…" /> : rows.length===0 ? <HrEmpty icon={ClipboardList} title="No reviews yet" hint="Create a review for an active or extended probation." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:1000 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Review #','Reviewer','Review Date','Rating','Recommendation','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{rows.map(r=>(
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                  <td className="px-3 py-2.5"><div className="font-bold" style={{ color:'var(--text-h)' }}>{r.employee_name}</div><div className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code} · {r.department||'—'}</div></td>
                  <td className="px-3 py-2.5 font-bold" style={{ color:'#a78bfa' }}>#{r.review_no}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.reviewer_name||'—'}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{fmtDate(r.review_date)}</td>
                  <td className="px-3 py-2.5"><Stars n={r.overall_rating} /></td>
                  <td className="px-3 py-2.5"><RecPill rec={r.recommendation} /></td>
                  <td className="px-3 py-2.5"><ReviewPill status={r.status} /></td>
                  <td className="px-3 py-2.5"><div className="flex gap-1.5 justify-end">
                    <button title="View" onClick={()=>setView(r.id)} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Eye size={13}/></button>
                    {r.status!=='Completed' && <button title="Edit" onClick={()=>setModal({ editing:r.id })} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Pencil size={13}/></button>}
                    {r.status==='Draft' && <button title="Submit" onClick={()=>hrApi.probation.reviews.submit(r.id).then(load).catch(e=>showToast(e.response?.data?.message||'Failed','error'))} className="p-1.5 rounded-lg" style={{ background:'rgba(59,130,246,0.1)', color:'#3b82f6' }}><Send size={13}/></button>}
                    {r.status==='Submitted' && <button title="Complete" onClick={()=>hrApi.probation.reviews.complete(r.id).then(load).catch(e=>showToast(e.response?.data?.message||'Failed','error'))} className="p-1.5 rounded-lg" style={{ background:'rgba(16,185,129,0.1)', color:'#10b981' }}><CheckCircle2 size={13}/></button>}
                  </div></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}

      {modal && <ReviewModal editingId={modal.editing} probations={probations} employees={employees} onClose={()=>setModal(null)} onSaved={()=>{ setModal(null); load() }} showToast={showToast} />}
      {view && <ReviewDrawer id={view} onClose={()=>setView(null)} onChanged={load} showToast={showToast} />}
    </div>
  )
}

function ReviewModal({ editingId, probations, employees, onClose, onSaved, showToast }) {
  const [form, setForm] = useState({ employee_probation_id:'', reviewer_id:'', review_date:new Date().toISOString().slice(0,10), technical_rating:3, behaviour_rating:3, attendance_rating:3, communication_rating:3, recommendation:'Continue', strengths:'', improvements:'', manager_comments:'', hr_comments:'' })
  const [loading, setLoading] = useState(!!editingId); const [saving, setSaving] = useState(false)
  const setF = p => setForm(f => ({ ...f, ...p }))
  useEffect(() => {
    if (!editingId) return
    hrApi.probation.reviews.get(editingId).then(r=>setForm(f=>({ ...f, ...r, employee_probation_id:r.employee_probation_id, reviewer_id:r.reviewer_id||'' }))).finally(()=>setLoading(false))
  }, [editingId])

  const save = async () => {
    if (!editingId && !form.employee_probation_id) return showToast('Select a probation','error')
    if (!form.reviewer_id) return showToast('Reviewer is required','error')
    setSaving(true)
    const payload = { employee_probation_id:form.employee_probation_id, reviewer_id:form.reviewer_id, review_date:form.review_date, recommendation:form.recommendation,
      technical_rating:form.technical_rating, behaviour_rating:form.behaviour_rating, attendance_rating:form.attendance_rating, communication_rating:form.communication_rating,
      strengths:form.strengths, improvements:form.improvements, manager_comments:form.manager_comments, hr_comments:form.hr_comments }
    try { editingId ? await hrApi.probation.reviews.update(editingId, payload) : await hrApi.probation.reviews.create(payload); showToast(`Review ${editingId?'updated':'created'}`); onSaved() }
    catch (e) { showToast(e.response?.data?.message||'Save failed','error') } finally { setSaving(false) }
  }
  const RatingRow = ({ k, label }) => (
    <div className="flex items-center justify-between"><span className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{label}</span>
      <div className="flex gap-1">{[1,2,3,4,5].map(i=><button key={i} onClick={()=>setF({ [k]:i })} type="button"><Star size={18} style={{ color:i<=form[k]?'#f59e0b':'var(--border)' }} fill={i<=form[k]?'#f59e0b':'none'} /></button>)}</div>
    </div>
  )
  return (
    <div className="modal-backdrop"><div className="modal-box" onClick={e=>e.stopPropagation()} style={{ maxWidth:640, width:'96%', maxHeight:'92vh', overflowY:'auto' }}>
      <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{editingId?'Edit Review':'Create Review'}</h2><button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
      {loading ? <HrLoading label="Loading…" /> : (
        <div className="space-y-3">
          {!editingId && <div><label className="label">Probation *</label><select className="input-3d text-sm" value={form.employee_probation_id} onChange={e=>setF({ employee_probation_id:e.target.value })}><option value="">Select an active/extended probation…</option>{probations.map(p=><option key={p.id} value={p.id}>{p.employee_name} · {p.policy} ({p.current_status})</option>)}</select></div>}
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Reviewer *</label><select className="input-3d text-sm" value={form.reviewer_id} onChange={e=>setF({ reviewer_id:e.target.value })}><option value="">Select…</option>{employees.map(e=><option key={e.id} value={e.id}>{e.name}</option>)}</select></div>
            <div><label className="label">Review Date</label><input type="date" className="input-3d text-sm" value={form.review_date} onChange={e=>setF({ review_date:e.target.value })}/></div>
          </div>
          <div className="rounded-xl p-3 space-y-2" style={{ background:'var(--bg-input)' }}>
            <p className="label-caps mb-1">Ratings (1–5)</p>
            {RATING_FIELDS.map(([k,l])=><RatingRow key={k} k={k} label={l} />)}
          </div>
          <div><label className="label">Recommendation *</label><select className="input-3d text-sm" value={form.recommendation} onChange={e=>setF({ recommendation:e.target.value })}>{['Continue','Extend','Confirm','Fail'].map(x=><option key={x}>{x}</option>)}</select></div>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div><label className="label">Strengths</label><textarea rows={2} className="input-3d text-sm resize-none" value={form.strengths} onChange={e=>setF({ strengths:e.target.value })}/></div>
            <div><label className="label">Improvements</label><textarea rows={2} className="input-3d text-sm resize-none" value={form.improvements} onChange={e=>setF({ improvements:e.target.value })}/></div>
            <div><label className="label">Manager Comments</label><textarea rows={2} className="input-3d text-sm resize-none" value={form.manager_comments} onChange={e=>setF({ manager_comments:e.target.value })}/></div>
            <div><label className="label">HR Comments</label><textarea rows={2} className="input-3d text-sm resize-none" value={form.hr_comments} onChange={e=>setF({ hr_comments:e.target.value })}/></div>
          </div>
          <div className="flex gap-3 pt-2"><button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Saving…':'Save Review'}</button></div>
        </div>
      )}
    </div></div>
  )
}

function ReviewDrawer({ id, onClose, onChanged, showToast }) {
  const [r, setR] = useState(null); const [loading, setLoading] = useState(true); const [busy, setBusy] = useState(false)
  const reload = useCallback(() => { setLoading(true); return hrApi.probation.reviews.get(id).then(setR).finally(()=>setLoading(false)) }, [id])
  useEffect(() => { reload() }, [reload])
  const act = async (fn, ok) => { setBusy(true); try { await fn(); showToast(ok); await reload(); onChanged() } catch (e) { showToast(e.response?.data?.message||'Failed','error') } finally { setBusy(false) } }
  const Field = ({ l, v }) => <div><p className="label-caps mb-0.5">{l}</p><p className="text-sm font-semibold" style={{ color:'var(--text-h)' }}>{v||'—'}</p></div>

  return (
    <div className="fixed inset-0 z-[9998] flex justify-end">
      <div className="absolute inset-0" style={{ background:'rgba(0,0,0,0.4)' }} />
      <div className="relative h-full overflow-y-auto animate-[slideIn_0.25s_ease]" onClick={e=>e.stopPropagation()} style={{ width:'min(480px,97vw)', background:'var(--bg-card,var(--bg-input))', borderLeft:'1px solid var(--border)', boxShadow:'-10px 0 40px rgba(0,0,0,0.3)' }}>
        <div className="sticky top-0 z-10 flex items-center justify-between px-5 py-4" style={{ background:'var(--bg-card,var(--bg-input))', borderBottom:'1px solid var(--border)' }}>
          <h2 className="font-black text-base flex items-center gap-2" style={{ color:'var(--text-h)' }}><ClipboardList size={16} style={{ color:'#a78bfa' }}/> Probation Review</h2>
          <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>
        {loading || !r ? <div className="p-6"><HrLoading label="Loading…" /></div> : (
          <div className="p-5 space-y-5">
            <div className="flex items-center justify-between gap-3">
              <div><p className="font-black text-lg" style={{ color:'var(--text-h)' }}>{r.employee_name} <span className="text-xs font-bold" style={{ color:'#a78bfa' }}>· Review #{r.review_no}</span></p><p className="text-[11px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code} · {r.policy||'—'}</p></div>
              <ReviewPill status={r.status} />
            </div>
            <div className="flex items-center gap-3"><Stars n={r.overall_rating} /><span className="text-sm font-bold" style={{ color:'var(--text-h)' }}>{r.overall_rating}/5</span><RecPill rec={r.recommendation} /></div>
            <div className="grid grid-cols-2 gap-4">
              <Field l="Reviewer" v={r.reviewer_name} />
              <Field l="Review Date" v={fmtDate(r.review_date)} />
              {RATING_FIELDS.map(([k,l])=><div key={k}><p className="label-caps mb-0.5">{l}</p><Stars n={r[k]} /></div>)}
            </div>
            {r.strengths && <div><p className="label-caps mb-1">Strengths</p><p className="text-sm" style={{ color:'var(--text-h)' }}>{r.strengths}</p></div>}
            {r.improvements && <div><p className="label-caps mb-1">Improvements</p><p className="text-sm" style={{ color:'var(--text-h)' }}>{r.improvements}</p></div>}
            {r.manager_comments && <div><p className="label-caps mb-1">Manager Comments</p><p className="text-sm" style={{ color:'var(--text-h)' }}>{r.manager_comments}</p></div>}
            {r.hr_comments && <div><p className="label-caps mb-1">HR Comments</p><p className="text-sm" style={{ color:'var(--text-h)' }}>{r.hr_comments}</p></div>}

            <div><p className="label-caps mb-2 flex items-center gap-1.5"><Clock size={12}/> Timeline</p>
              <div className="space-y-2.5">{(r.timeline||[]).map((t,i)=>(
                <div key={i} className="flex gap-3"><div className="mt-1.5 rounded-full flex-shrink-0" style={{ width:8, height:8, background:'#a78bfa' }}/><div><p className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{t.action}</p><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>{t.actor_name||'System'} · {t.created_at?new Date(t.created_at).toLocaleString():''}</p></div></div>
              ))}{(!r.timeline||!r.timeline.length) && <p className="text-xs" style={{ color:'var(--text-muted)' }}>No timeline yet.</p>}</div>
            </div>

            {r.status!=='Completed' && (
              <div className="flex gap-2">
                {r.status==='Draft' && <button onClick={()=>act(()=>hrApi.probation.reviews.submit(id), 'Review submitted')} disabled={busy} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:'linear-gradient(135deg,#3b82f6,#2563eb)' }}><Send size={14}/> Submit</button>}
                {r.status==='Submitted' && <button onClick={()=>act(()=>hrApi.probation.reviews.complete(id), 'Review completed')} disabled={busy} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:'linear-gradient(135deg,#10b981,#059669)' }}><CheckCircle2 size={14}/> Complete</button>}
              </div>
            )}
            {r.status==='Completed' && <p className="text-xs text-center py-2 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>This review is completed and read-only.</p>}
          </div>
        )}
      </div>
    </div>
  )
}

/* ── Probation Extensions (Phase 4) ── */
const ExtPill = ({ status }) => <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${EXT_C[status]||'#7C3AED'}1f`, color:EXT_C[status]||'#7C3AED' }}>{status}</span>

function ProbationExtensions({ showToast }) {
  const [data, setData] = useState({ data:[], stats:{ pending:0, approved:0, rejected:0, active_extensions:0, avg_days:0 } })
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState(''); const [statusF, setStatusF] = useState('All'); const [deptF, setDeptF] = useState(''); const [dateF, setDateF] = useState('')
  const [probations, setProbations] = useState([]); const [employees, setEmployees] = useState([]); const [depts, setDepts] = useState([])
  const [modal, setModal] = useState(null); const [view, setView] = useState(null); const [decide, setDecide] = useState(null); const [busy, setBusy] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    const params = {}
    if (statusF !== 'All') params.status = statusF
    if (deptF) params.department = deptF
    if (dateF) params.from = dateF
    if (search) params.search = search
    hrApi.probation.extensions.list(params).then(setData).catch(()=>showToast('Failed to load extensions','error')).finally(()=>setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusF, deptF, dateF, search])
  useEffect(() => { load() }, [load])
  useEffect(() => {
    hrApi.probation.employees.list().then(r=>setProbations((r.data||[]).filter(p=>['Active','Extended'].includes(p.current_status)))).catch(()=>{})
    hrApi.employees.list({ per_page:500 }).then(r=>setEmployees(Array.isArray(r)?r:[])).catch(()=>{})
    hrApi.organization.options().then(o=>setDepts((o.departments||[]).map(d=>d.name))).catch(()=>{})
  }, [])

  const runDecide = async () => {
    setBusy(true)
    const fn = decide.mode==='approve' ? hrApi.probation.extensions.approve : hrApi.probation.extensions.reject
    try { await fn(decide.id, decide.hr_comments||''); showToast(`Extension ${decide.mode==='approve'?'approved':'rejected'}`); setDecide(null); load(); if (view) setView(null) }
    catch (e) { showToast(e.response?.data?.message||'Action failed','error') } finally { setBusy(false) }
  }
  const s = data.stats
  const KPIS = [
    { l:'Pending', v:s.pending, c:'#f59e0b' }, { l:'Approved', v:s.approved, c:'#10b981' },
    { l:'Rejected', v:s.rejected, c:'#f87171' }, { l:'Active Extensions', v:s.active_extensions, c:'#3b82f6' }, { l:'Avg Extension Days', v:s.avg_days, c:'#8b5cf6' },
  ]
  const hasF = statusF!=='All'||deptF||dateF||search
  const rows = data.data

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-5 gap-3">{KPIS.map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}</div>

      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[180px]"><label className="label">Search</label><Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/><input className="input-3d pl-9 text-sm" placeholder="Employee name or code…" value={search} onChange={e=>setSearch(e.target.value)}/></div>
          <div className="min-w-[150px]"><label className="label">Department</label><select className="input-3d text-sm" value={deptF} onChange={e=>setDeptF(e.target.value)}><option value="">All</option>{depts.map(d=><option key={d} value={d}>{d}</option>)}</select></div>
          <div className="min-w-[140px]"><label className="label">Status</label><select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['All','Pending','Approved','Rejected'].map(x=><option key={x}>{x}</option>)}</select></div>
          <div className="min-w-[140px]"><label className="label">Requested (from)</label><input type="date" className="input-3d text-sm" value={dateF} onChange={e=>setDateF(e.target.value)}/></div>
          {hasF && <button onClick={()=>{ setStatusF('All'); setDeptF(''); setDateF(''); setSearch('') }} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
          <button onClick={()=>setModal({ editing:null })} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white ml-auto" style={{ background:GRAD }}><CalendarClock size={15}/> Request Extension</button>
        </div>
      </div>

      {loading ? <HrLoading label="Loading extensions…" /> : rows.length===0 ? <HrEmpty icon={Clock3} title="No extensions yet" hint="Request an extension for an active or extended probation." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:1040 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Department','Current End','Extended End','Days','Status','Requested By','Requested','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{rows.map(r=>(
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                  <td className="px-3 py-2.5"><div className="font-bold" style={{ color:'var(--text-h)' }}>{r.employee_name}</div><div className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code} · #{r.extension_number}</div></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.department||'—'}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{fmtDate(r.current_end_date)}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-h)' }}>{fmtDate(r.extended_end_date)}</td>
                  <td className="px-3 py-2.5 font-bold" style={{ color:'#7C3AED' }}>+{r.extension_days}d</td>
                  <td className="px-3 py-2.5"><ExtPill status={r.status} /></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.requested_by_name||'—'}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{fmtDate(r.requested_date)}</td>
                  <td className="px-3 py-2.5"><div className="flex gap-1.5 justify-end">
                    <button title="View" onClick={()=>setView(r.id)} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Eye size={13}/></button>
                    {r.status==='Pending' && <>
                      <button title="Edit" onClick={()=>setModal({ editing:r.id })} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Pencil size={13}/></button>
                      <button title="Approve" onClick={()=>setDecide({ id:r.id, mode:'approve', hr_comments:'' })} className="p-1.5 rounded-lg" style={{ background:'rgba(16,185,129,0.1)', color:'#10b981' }}><Check size={13}/></button>
                      <button title="Reject" onClick={()=>setDecide({ id:r.id, mode:'reject', hr_comments:'' })} className="p-1.5 rounded-lg" style={{ background:'rgba(239,68,68,0.1)', color:'#f87171' }}><XCircle size={13}/></button>
                    </>}
                  </div></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}

      {modal && <ExtensionModal editingId={modal.editing} probations={probations} employees={employees} onClose={()=>setModal(null)} onSaved={()=>{ setModal(null); load() }} showToast={showToast} />}
      {decide && (
        <div className="modal-backdrop"><div className="modal-box max-w-md" onClick={e=>e.stopPropagation()}>
          <div className="flex items-center justify-between mb-3"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{decide.mode==='approve'?'Approve':'Reject'} Extension</h2><button onClick={()=>setDecide(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
          <p className="text-xs mb-3" style={{ color:'var(--text-muted)' }}>{decide.mode==='approve'?'Approving updates the probation end date and marks it Extended.':'Rejecting is final — the extension cannot be re-opened.'}</p>
          <label className="label">HR Comments</label><textarea rows={3} className="input-3d text-sm resize-none" value={decide.hr_comments} onChange={e=>setDecide(d=>({...d,hr_comments:e.target.value}))}/>
          <div className="flex gap-3 pt-4"><button onClick={()=>setDecide(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={runDecide} disabled={busy} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: decide.mode==='approve'?'linear-gradient(135deg,#10b981,#059669)':'linear-gradient(135deg,#f87171,#ef4444)', opacity:busy?0.7:1 }}>{busy?'Working…':(decide.mode==='approve'?'Confirm Approve':'Confirm Reject')}</button></div>
        </div></div>
      )}
      {view && <ExtensionDrawer id={view} onClose={()=>setView(null)} onDecide={(id,mode)=>setDecide({ id, mode, hr_comments:'' })} />}
    </div>
  )
}

function ExtensionModal({ editingId, probations, employees, onClose, onSaved, showToast }) {
  const [form, setForm] = useState({ probation_id:'', requested_by:'', extension_days:30, reason:'', manager_comments:'', hr_comments:'' })
  const [loading, setLoading] = useState(!!editingId); const [saving, setSaving] = useState(false)
  const setF = p => setForm(f => ({ ...f, ...p }))
  useEffect(() => {
    if (!editingId) return
    hrApi.probation.extensions.get(editingId).then(r=>setForm(f=>({ ...f, probation_id:r.probation_id, extension_days:r.extension_days, reason:r.reason||'', manager_comments:r.manager_comments||'', hr_comments:r.hr_comments||'' }))).finally(()=>setLoading(false))
  }, [editingId])
  const prob = probations.find(p => String(p.id) === String(form.probation_id))

  const save = async () => {
    if (!editingId && !form.probation_id) return showToast('Select a probation','error')
    if (Number(form.extension_days) <= 0) return showToast('Extension days must be greater than zero','error')
    setSaving(true)
    const payload = { extension_days:Number(form.extension_days), reason:form.reason, manager_comments:form.manager_comments, hr_comments:form.hr_comments }
    if (!editingId) { payload.probation_id = form.probation_id; if (form.requested_by) payload.requested_by = form.requested_by }
    try { editingId ? await hrApi.probation.extensions.update(editingId, payload) : await hrApi.probation.extensions.request(payload); showToast(`Extension ${editingId?'updated':'requested'}`); onSaved() }
    catch (e) { showToast(e.response?.data?.message||'Save failed','error') } finally { setSaving(false) }
  }
  return (
    <div className="modal-backdrop"><div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()} style={{ maxHeight:'92vh', overflowY:'auto' }}>
      <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{editingId?'Edit Extension':'Request Extension'}</h2><button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
      {loading ? <HrLoading label="Loading…" /> : (
        <div className="space-y-3">
          {!editingId && <div><label className="label">Probation *</label><select className="input-3d text-sm" value={form.probation_id} onChange={e=>setF({ probation_id:e.target.value })}><option value="">Select an active/extended probation…</option>{probations.map(p=><option key={p.id} value={p.id}>{p.employee_name} · {p.policy} (ends {fmtDate(p.probation_end_date)})</option>)}</select></div>}
          {prob && <div className="text-[11px] px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>Current end date: <b style={{ color:'var(--text-h)' }}>{fmtDate(prob.probation_end_date)}</b> · Extended to <b style={{ color:'#7C3AED' }}>{fmtDate(new Date(new Date(prob.probation_end_date).getTime() + Number(form.extension_days||0)*86400000))}</b></div>}
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Extension Days *</label><input type="number" min="1" className="input-3d text-sm" value={form.extension_days} onChange={e=>setF({ extension_days:e.target.value })}/></div>
            {!editingId && <div><label className="label">Requested By</label><select className="input-3d text-sm" value={form.requested_by} onChange={e=>setF({ requested_by:e.target.value })}><option value="">—</option>{employees.map(e=><option key={e.id} value={e.id}>{e.name}</option>)}</select></div>}
          </div>
          <div><label className="label">Reason</label><textarea rows={2} className="input-3d text-sm resize-none" value={form.reason} onChange={e=>setF({ reason:e.target.value })}/></div>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div><label className="label">Manager Comments</label><textarea rows={2} className="input-3d text-sm resize-none" value={form.manager_comments} onChange={e=>setF({ manager_comments:e.target.value })}/></div>
            <div><label className="label">HR Comments</label><textarea rows={2} className="input-3d text-sm resize-none" value={form.hr_comments} onChange={e=>setF({ hr_comments:e.target.value })}/></div>
          </div>
          <div className="flex gap-3 pt-2"><button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Saving…':(editingId?'Save':'Request')}</button></div>
        </div>
      )}
    </div></div>
  )
}

function ExtensionDrawer({ id, onClose, onDecide }) {
  const [e, setE] = useState(null); const [loading, setLoading] = useState(true)
  useEffect(() => { setLoading(true); hrApi.probation.extensions.get(id).then(setE).finally(()=>setLoading(false)) }, [id])
  const Field = ({ l, v }) => <div><p className="label-caps mb-0.5">{l}</p><p className="text-sm font-semibold" style={{ color:'var(--text-h)' }}>{v||'—'}</p></div>

  return (
    <div className="fixed inset-0 z-[9998] flex justify-end">
      <div className="absolute inset-0" style={{ background:'rgba(0,0,0,0.4)' }} />
      <div className="relative h-full overflow-y-auto animate-[slideIn_0.25s_ease]" onClick={ev=>ev.stopPropagation()} style={{ width:'min(470px,97vw)', background:'var(--bg-card,var(--bg-input))', borderLeft:'1px solid var(--border)', boxShadow:'-10px 0 40px rgba(0,0,0,0.3)' }}>
        <div className="sticky top-0 z-10 flex items-center justify-between px-5 py-4" style={{ background:'var(--bg-card,var(--bg-input))', borderBottom:'1px solid var(--border)' }}>
          <h2 className="font-black text-base flex items-center gap-2" style={{ color:'var(--text-h)' }}><CalendarClock size={16} style={{ color:'#a78bfa' }}/> Probation Extension</h2>
          <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>
        {loading || !e ? <div className="p-6"><HrLoading label="Loading…" /></div> : (
          <div className="p-5 space-y-5">
            <div className="flex items-center justify-between gap-3">
              <div><p className="font-black text-lg" style={{ color:'var(--text-h)' }}>{e.employee_name} <span className="text-xs font-bold" style={{ color:'#a78bfa' }}>· Ext #{e.extension_number}</span></p><p className="text-[11px] font-mono" style={{ color:'#a78bfa' }}>{e.employee_code} · {e.policy||'—'}</p></div>
              <ExtPill status={e.status} />
            </div>
            <div className="flex items-center gap-2 px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}><span className="text-xs" style={{ color:'var(--text-muted)' }}>{fmtDate(e.current_end_date)}</span><span style={{ color:'#a78bfa' }}>→</span><span className="text-sm font-bold" style={{ color:'#7C3AED' }}>{fmtDate(e.extended_end_date)}</span><span className="text-[10px] font-bold ml-auto px-2 py-0.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}>+{e.extension_days} days</span></div>
            <div className="grid grid-cols-2 gap-4">
              <Field l="Department" v={e.department} />
              <Field l="Designation" v={e.designation} />
              <Field l="Current Probation" v={e.probation_status} />
              <Field l="Requested By" v={e.requested_by_name} />
            </div>
            {e.reason && <div><p className="label-caps mb-1">Reason</p><p className="text-sm" style={{ color:'var(--text-h)' }}>{e.reason}</p></div>}
            {e.manager_comments && <div><p className="label-caps mb-1">Manager Comments</p><p className="text-sm" style={{ color:'var(--text-h)' }}>{e.manager_comments}</p></div>}
            {e.hr_comments && <div><p className="label-caps mb-1">HR Comments</p><p className="text-sm" style={{ color:'var(--text-h)' }}>{e.hr_comments}</p></div>}

            <div><p className="label-caps mb-2 flex items-center gap-1.5"><Clock size={12}/> Approval &amp; Audit Timeline</p>
              <div className="space-y-2.5">{(e.timeline||[]).map((t,i)=>(
                <div key={i} className="flex gap-3"><div className="mt-1.5 rounded-full flex-shrink-0" style={{ width:8, height:8, background:'#a78bfa' }}/><div><p className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{t.action}</p><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>{t.actor_name||'System'} · {t.created_at?new Date(t.created_at).toLocaleString():''}</p>{t.comment && <p className="text-[11px] mt-0.5" style={{ color:'var(--text-muted)' }}>“{t.comment}”</p>}</div></div>
              ))}{(!e.timeline||!e.timeline.length) && <p className="text-xs" style={{ color:'var(--text-muted)' }}>No timeline yet.</p>}</div>
            </div>

            {e.status==='Pending' && (
              <div className="flex gap-2">
                <button onClick={()=>onDecide(e.id,'reject')} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:'linear-gradient(135deg,#f87171,#ef4444)' }}><XCircle size={14}/> Reject</button>
                <button onClick={()=>onDecide(e.id,'approve')} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:'linear-gradient(135deg,#10b981,#059669)' }}><Check size={14}/> Approve</button>
              </div>
            )}
            {e.status!=='Pending' && <p className="text-xs text-center py-2 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>This extension is {e.status.toLowerCase()} and read-only.</p>}
          </div>
        )}
      </div>
    </div>
  )
}

/* ── Probation Confirmations (Phase 5) ── */
const ConfPill = ({ status }) => <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${CONF_C[status]||'#7C3AED'}1f`, color:CONF_C[status]||'#7C3AED' }}>{status}</span>

function ProbationConfirmations({ showToast }) {
  const [data, setData] = useState({ data:[], stats:{ pending:0, approved:0, rejected:0, confirmed:0, due_this_month:0 } })
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState(''); const [statusF, setStatusF] = useState('All'); const [recF, setRecF] = useState('All'); const [deptF, setDeptF] = useState(''); const [dateF, setDateF] = useState('')
  const [probations, setProbations] = useState([]); const [depts, setDepts] = useState([])
  const [modal, setModal] = useState(null); const [view, setView] = useState(null); const [decide, setDecide] = useState(null); const [busy, setBusy] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    const params = {}
    if (statusF !== 'All') params.status = statusF
    if (recF !== 'All') params.recommendation = recF
    if (deptF) params.department = deptF
    if (dateF) params.from = dateF
    if (search) params.search = search
    hrApi.probation.confirmations.list(params).then(setData).catch(()=>showToast('Failed to load confirmations','error')).finally(()=>setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusF, recF, deptF, dateF, search])
  useEffect(() => { load() }, [load])
  useEffect(() => {
    hrApi.probation.employees.list().then(r=>setProbations((r.data||[]).filter(p=>['Active','Extended'].includes(p.current_status)))).catch(()=>{})
    hrApi.organization.options().then(o=>setDepts((o.departments||[]).map(d=>d.name))).catch(()=>{})
  }, [])

  const runDecide = async () => {
    setBusy(true)
    try {
      if (decide.mode==='approve') await hrApi.probation.confirmations.approve(decide.id, decide.hr_comments||'')
      else if (decide.mode==='reject') await hrApi.probation.confirmations.reject(decide.id, decide.hr_comments||'')
      else await hrApi.probation.confirmations.confirm(decide.id, { effective_date:decide.effective_date||undefined, remarks:decide.hr_comments||undefined })
      showToast(decide.mode==='approve'?'Confirmation approved':decide.mode==='reject'?'Confirmation rejected':'Employee confirmed')
      setDecide(null); load(); if (view) setView(null)
    } catch (e) { showToast(e.response?.data?.message||'Action failed','error') } finally { setBusy(false) }
  }
  const s = data.stats
  const KPIS = [
    { l:'Pending', v:s.pending, c:'#f59e0b' }, { l:'Approved', v:s.approved, c:'#3b82f6' },
    { l:'Rejected', v:s.rejected, c:'#f87171' }, { l:'Confirmed', v:s.confirmed, c:'#10b981' }, { l:'Due This Month', v:s.due_this_month, c:'#8b5cf6' },
  ]
  const hasF = statusF!=='All'||recF!=='All'||deptF||dateF||search
  const rows = data.data

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-5 gap-3">{KPIS.map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}</div>

      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[160px]"><label className="label">Search</label><Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/><input className="input-3d pl-9 text-sm" placeholder="Employee name or code…" value={search} onChange={e=>setSearch(e.target.value)}/></div>
          <div className="min-w-[140px]"><label className="label">Department</label><select className="input-3d text-sm" value={deptF} onChange={e=>setDeptF(e.target.value)}><option value="">All</option>{depts.map(d=><option key={d} value={d}>{d}</option>)}</select></div>
          <div className="min-w-[130px]"><label className="label">Recommendation</label><select className="input-3d text-sm" value={recF} onChange={e=>setRecF(e.target.value)}>{['All','Continue','Extend','Confirm','Fail'].map(x=><option key={x}>{x}</option>)}</select></div>
          <div className="min-w-[130px]"><label className="label">Status</label><select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['All','Pending','Approved','Rejected','Confirmed'].map(x=><option key={x}>{x}</option>)}</select></div>
          <div className="min-w-[140px]"><label className="label">Created (from)</label><input type="date" className="input-3d text-sm" value={dateF} onChange={e=>setDateF(e.target.value)}/></div>
          {hasF && <button onClick={()=>{ setStatusF('All'); setRecF('All'); setDeptF(''); setDateF(''); setSearch('') }} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
          <button onClick={()=>setModal({ editing:null })} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white ml-auto" style={{ background:GRAD }}><BadgeCheckIcon size={15}/> New Confirmation</button>
        </div>
      </div>

      {loading ? <HrLoading label="Loading confirmations…" /> : rows.length===0 ? <HrEmpty icon={BadgeCheckIcon} title="No confirmations yet" hint="Start a confirmation for an active or extended probation." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:1120 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Department','Policy','Probation','Review Rec.','Current End','Confirm Date','Effective','Decision','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{rows.map(r=>(
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                  <td className="px-3 py-2.5"><div className="font-bold" style={{ color:'var(--text-h)' }}>{r.employee_name}</div><div className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code}</div></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.department||'—'}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.policy||'—'}</td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${PROB_C[r.probation_status]||'#7C3AED'}1f`, color:PROB_C[r.probation_status]||'#7C3AED' }}>{r.probation_status}</span></td>
                  <td className="px-3 py-2.5">{r.recommendation ? <RecPill rec={r.recommendation} /> : '—'}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{fmtDate(r.current_end_date)}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{fmtDate(r.confirmation_date)}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{fmtDate(r.effective_date)}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-h)' }}>{r.decision||'—'}</td>
                  <td className="px-3 py-2.5"><ConfPill status={r.status} /></td>
                  <td className="px-3 py-2.5"><div className="flex gap-1.5 justify-end">
                    <button title="View" onClick={()=>setView(r.id)} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Eye size={13}/></button>
                    {r.status==='Pending' && <>
                      <button title="Edit" onClick={()=>setModal({ editing:r.id })} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Pencil size={13}/></button>
                      <button title="Approve" onClick={()=>setDecide({ id:r.id, mode:'approve', hr_comments:'' })} className="p-1.5 rounded-lg" style={{ background:'rgba(59,130,246,0.1)', color:'#3b82f6' }}><Check size={13}/></button>
                      <button title="Reject" onClick={()=>setDecide({ id:r.id, mode:'reject', hr_comments:'' })} className="p-1.5 rounded-lg" style={{ background:'rgba(239,68,68,0.1)', color:'#f87171' }}><XCircle size={13}/></button>
                    </>}
                    {r.status==='Approved' && <button title="Confirm" onClick={()=>setDecide({ id:r.id, mode:'confirm', hr_comments:'', effective_date:r.effective_date||'' })} className="p-1.5 rounded-lg" style={{ background:'rgba(16,185,129,0.1)', color:'#10b981' }}><BadgeCheckIcon size={13}/></button>}
                  </div></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}

      {modal && <ConfirmationModal editingId={modal.editing} probations={probations} onClose={()=>setModal(null)} onSaved={()=>{ setModal(null); load() }} showToast={showToast} />}
      {decide && (
        <div className="modal-backdrop"><div className="modal-box max-w-md" onClick={e=>e.stopPropagation()}>
          <div className="flex items-center justify-between mb-3"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{decide.mode==='approve'?'Approve':decide.mode==='reject'?'Reject':'Confirm Employee'}</h2><button onClick={()=>setDecide(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
          <p className="text-xs mb-3" style={{ color:'var(--text-muted)' }}>{decide.mode==='confirm'?'Confirming closes the probation and marks the employee Confirmed (terminal).':decide.mode==='approve'?'Approving allows the employee to be confirmed next.':'Rejecting is final — the employee will not be confirmed.'}</p>
          {decide.mode==='confirm' && <div className="mb-3"><label className="label">Effective Date</label><input type="date" className="input-3d text-sm" value={decide.effective_date} onChange={e=>setDecide(d=>({...d,effective_date:e.target.value}))}/></div>}
          <label className="label">{decide.mode==='confirm'?'Remarks':'HR Comments'}</label><textarea rows={3} className="input-3d text-sm resize-none" value={decide.hr_comments} onChange={e=>setDecide(d=>({...d,hr_comments:e.target.value}))}/>
          <div className="flex gap-3 pt-4"><button onClick={()=>setDecide(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={runDecide} disabled={busy} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: decide.mode==='reject'?'linear-gradient(135deg,#f87171,#ef4444)':decide.mode==='approve'?'linear-gradient(135deg,#3b82f6,#2563eb)':'linear-gradient(135deg,#10b981,#059669)', opacity:busy?0.7:1 }}>{busy?'Working…':(decide.mode==='approve'?'Confirm Approve':decide.mode==='reject'?'Confirm Reject':'Confirm Employee')}</button></div>
        </div></div>
      )}
      {view && <ConfirmationDrawer id={view} onClose={()=>setView(null)} onDecide={(id,mode,eff)=>setDecide({ id, mode, hr_comments:'', effective_date:eff||'' })} />}
    </div>
  )
}

function ConfirmationModal({ editingId, probations, onClose, onSaved, showToast }) {
  const [form, setForm] = useState({ probation_id:'', decision:'Confirm', effective_date:'', manager_comments:'', hr_comments:'', remarks:'' })
  const [loading, setLoading] = useState(!!editingId); const [saving, setSaving] = useState(false)
  const setF = p => setForm(f => ({ ...f, ...p }))
  useEffect(() => {
    if (!editingId) return
    hrApi.probation.confirmations.get(editingId).then(r=>setForm(f=>({ ...f, probation_id:r.probation_id, decision:r.decision||'Confirm', effective_date:r.effective_date||'', manager_comments:r.manager_comments||'', hr_comments:r.hr_comments||'', remarks:r.remarks||'' }))).finally(()=>setLoading(false))
  }, [editingId])
  const prob = probations.find(p => String(p.id) === String(form.probation_id))

  const save = async () => {
    if (!editingId && !form.probation_id) return showToast('Select a probation','error')
    setSaving(true)
    const payload = { decision:form.decision, effective_date:form.effective_date||undefined, manager_comments:form.manager_comments, hr_comments:form.hr_comments, remarks:form.remarks }
    if (!editingId) payload.probation_id = form.probation_id
    try { editingId ? await hrApi.probation.confirmations.update(editingId, payload) : await hrApi.probation.confirmations.create(payload); showToast(`Confirmation ${editingId?'updated':'created'}`); onSaved() }
    catch (e) { showToast(e.response?.data?.message||'Save failed','error') } finally { setSaving(false) }
  }
  return (
    <div className="modal-backdrop"><div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()} style={{ maxHeight:'92vh', overflowY:'auto' }}>
      <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{editingId?'Edit Confirmation':'New Confirmation'}</h2><button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
      {loading ? <HrLoading label="Loading…" /> : (
        <div className="space-y-3">
          {!editingId && <div><label className="label">Probation *</label><select className="input-3d text-sm" value={form.probation_id} onChange={e=>setF({ probation_id:e.target.value })}><option value="">Select an active/extended probation…</option>{probations.map(p=><option key={p.id} value={p.id}>{p.employee_name} · {p.policy} (ends {fmtDate(p.probation_end_date)})</option>)}</select><p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>The latest review recommendation and extension are captured automatically.</p></div>}
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Decision</label><select className="input-3d text-sm" value={form.decision} onChange={e=>setF({ decision:e.target.value })}>{['Confirm','Extend','Terminate','Continue'].map(x=><option key={x}>{x}</option>)}</select></div>
            <div><label className="label">Effective Date</label><input type="date" className="input-3d text-sm" value={form.effective_date} onChange={e=>setF({ effective_date:e.target.value })}/></div>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div><label className="label">Manager Comments</label><textarea rows={2} className="input-3d text-sm resize-none" value={form.manager_comments} onChange={e=>setF({ manager_comments:e.target.value })}/></div>
            <div><label className="label">HR Comments</label><textarea rows={2} className="input-3d text-sm resize-none" value={form.hr_comments} onChange={e=>setF({ hr_comments:e.target.value })}/></div>
          </div>
          <div><label className="label">Remarks</label><textarea rows={2} className="input-3d text-sm resize-none" value={form.remarks} onChange={e=>setF({ remarks:e.target.value })}/></div>
          <div className="flex gap-3 pt-2"><button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Saving…':(editingId?'Save':'Create')}</button></div>
        </div>
      )}
    </div></div>
  )
}

function ConfirmationDrawer({ id, onClose, onDecide }) {
  const [c, setC] = useState(null); const [loading, setLoading] = useState(true)
  useEffect(() => { setLoading(true); hrApi.probation.confirmations.get(id).then(setC).finally(()=>setLoading(false)) }, [id])
  const Field = ({ l, v }) => <div><p className="label-caps mb-0.5">{l}</p><p className="text-sm font-semibold" style={{ color:'var(--text-h)' }}>{v||'—'}</p></div>

  return (
    <div className="fixed inset-0 z-[9998] flex justify-end">
      <div className="absolute inset-0" style={{ background:'rgba(0,0,0,0.4)' }} />
      <div className="relative h-full overflow-y-auto animate-[slideIn_0.25s_ease]" onClick={ev=>ev.stopPropagation()} style={{ width:'min(490px,97vw)', background:'var(--bg-card,var(--bg-input))', borderLeft:'1px solid var(--border)', boxShadow:'-10px 0 40px rgba(0,0,0,0.3)' }}>
        <div className="sticky top-0 z-10 flex items-center justify-between px-5 py-4" style={{ background:'var(--bg-card,var(--bg-input))', borderBottom:'1px solid var(--border)' }}>
          <h2 className="font-black text-base flex items-center gap-2" style={{ color:'var(--text-h)' }}><BadgeCheckIcon size={16} style={{ color:'#a78bfa' }}/> Probation Confirmation</h2>
          <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>
        {loading || !c ? <div className="p-6"><HrLoading label="Loading…" /></div> : (
          <div className="p-5 space-y-5">
            <div className="flex items-center justify-between gap-3">
              <div><p className="font-black text-lg" style={{ color:'var(--text-h)' }}>{c.employee_name}</p><p className="text-[11px] font-mono" style={{ color:'#a78bfa' }}>{c.employee_code} · {c.policy||'—'}</p></div>
              <ConfPill status={c.status} />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <Field l="Department" v={c.department} />
              <Field l="Designation" v={c.designation} />
              <Field l="Grade" v={c.grade} />
              <Field l="Probation Type" v={c.probation_type} />
              <Field l="Start Date" v={fmtDate(c.probation_start_date)} />
              <Field l="End Date" v={fmtDate(c.current_end_date)} />
              <Field l="Recommendation" v={c.recommendation} />
              <Field l="Decision" v={c.decision} />
              <Field l="Confirmation Date" v={fmtDate(c.confirmation_date)} />
              <Field l="Effective Date" v={fmtDate(c.effective_date)} />
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              <div className="rounded-xl p-3" style={{ background:'var(--bg-input)' }}><p className="label-caps mb-1">Latest Review</p>{c.review_summary ? <p className="text-xs" style={{ color:'var(--text-h)' }}>#{c.review_summary.review_no} · {c.review_summary.rating}/5 · {c.review_summary.recommendation} <span style={{ color:'var(--text-muted)' }}>({c.review_summary.status})</span></p> : <p className="text-xs" style={{ color:'var(--text-muted)' }}>No review</p>}</div>
              <div className="rounded-xl p-3" style={{ background:'var(--bg-input)' }}><p className="label-caps mb-1">Latest Extension</p>{c.extension_summary ? <p className="text-xs" style={{ color:'var(--text-h)' }}>#{c.extension_summary.extension_number} · +{c.extension_summary.extension_days}d → {fmtDate(c.extension_summary.extended_end_date)} <span style={{ color:'var(--text-muted)' }}>({c.extension_summary.status})</span></p> : <p className="text-xs" style={{ color:'var(--text-muted)' }}>No extension</p>}</div>
            </div>
            {c.manager_comments && <div><p className="label-caps mb-1">Manager Comments</p><p className="text-sm" style={{ color:'var(--text-h)' }}>{c.manager_comments}</p></div>}
            {c.hr_comments && <div><p className="label-caps mb-1">HR Comments</p><p className="text-sm" style={{ color:'var(--text-h)' }}>{c.hr_comments}</p></div>}
            {c.remarks && <div><p className="label-caps mb-1">Remarks</p><p className="text-sm" style={{ color:'var(--text-h)' }}>{c.remarks}</p></div>}

            <div><p className="label-caps mb-2 flex items-center gap-1.5"><Clock size={12}/> Approval &amp; Audit Timeline</p>
              <div className="space-y-2.5">{(c.timeline||[]).map((t,i)=>(
                <div key={i} className="flex gap-3"><div className="mt-1.5 rounded-full flex-shrink-0" style={{ width:8, height:8, background:'#a78bfa' }}/><div><p className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{t.action}</p><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>{t.actor_name||'System'} · {t.created_at?new Date(t.created_at).toLocaleString():''}</p>{t.comment && <p className="text-[11px] mt-0.5" style={{ color:'var(--text-muted)' }}>“{t.comment}”</p>}</div></div>
              ))}{(!c.timeline||!c.timeline.length) && <p className="text-xs" style={{ color:'var(--text-muted)' }}>No timeline yet.</p>}</div>
            </div>

            {c.status==='Pending' && (
              <div className="flex gap-2">
                <button onClick={()=>onDecide(c.id,'reject')} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:'linear-gradient(135deg,#f87171,#ef4444)' }}><XCircle size={14}/> Reject</button>
                <button onClick={()=>onDecide(c.id,'approve')} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:'linear-gradient(135deg,#3b82f6,#2563eb)' }}><Check size={14}/> Approve</button>
              </div>
            )}
            {c.status==='Approved' && <button onClick={()=>onDecide(c.id,'confirm',c.effective_date)} className="w-full py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:'linear-gradient(135deg,#10b981,#059669)' }}><BadgeCheckIcon size={14}/> Confirm Employee</button>}
            {(c.status==='Confirmed'||c.status==='Rejected') && <p className="text-xs text-center py-2 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>This confirmation is {c.status.toLowerCase()} and read-only.</p>}
          </div>
        )}
      </div>
    </div>
  )
}
