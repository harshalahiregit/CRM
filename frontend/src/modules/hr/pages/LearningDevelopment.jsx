import { useState, useEffect, useCallback } from 'react'
import { useTheme } from '@/context/ThemeContext'
import {
  GraduationCap, FolderTree, Layers, Building2, BookOpen, CalendarRange, UserPlus,
  Presentation, ClipboardCheck, FileQuestion, MessageSquare, Award, CheckCircle2,
  LayoutDashboard, BarChart3, Lock, Plus, Pencil, X, Power, Search, Globe, Mail, Phone,
  Eye, Clock, Users, Target, MapPin, Video, PlayCircle, XCircle, ChevronLeft, ChevronRight, User,
  Check, Download, Upload, Percent, UserCheck, ListChecks,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import TagInput from '@/components/ui/TagInput'   // #22 — shared, not a fifth tag input
import QuizBuilder from '../components/QuizBuilder'   // #25
import { AssignmentQuizzes } from '../components/QuizRunner'   // #25 — sitting a quiz
import LearningReports from './LearningReports'

const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'
const SESSION_C = { Scheduled:'#3b82f6', Ongoing:'#f59e0b', Completed:'#10b981', Cancelled:'#f87171' }
const ASSIGN_C = { Assigned:'#3b82f6', 'In Progress':'#f59e0b', Completed:'#10b981', Cancelled:'#f87171' }
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' }) : '—'
const fmtTime = (d) => d ? new Date(d).toLocaleTimeString('en-US', { hour:'2-digit', minute:'2-digit' }) : ''
const fmtDT = (d) => d ? `${fmtDate(d)} · ${fmtTime(d)}` : '—'
const toLocalInput = (iso) => { if (!iso) return ''; const d = new Date(iso); const p = n => String(n).padStart(2,'0'); return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}` }

// Ordered to follow the training lifecycle, left → right:
//   overview (Dashboard) → setup (Categories…Programs) → delivery (Calendar…
//   Attendance) → evaluation (Assessment…Completion) → Reports.
// Dashboard used to sit 13th of 14, so the module's summary was the last thing
// anyone found; it now opens the module, and Reports closes it.
const TABS = [
  { key:'dashboard',  label:'Dashboard',          icon:LayoutDashboard,ready:true },
  { key:'categories', label:'Categories',        icon:FolderTree,     ready:true },
  { key:'types',      label:'Training Types',     icon:Layers,         ready:true },
  { key:'providers',  label:'Providers',          icon:Building2,      ready:true },
  { key:'programs',   label:'Programs',           icon:BookOpen,       ready:true },
  // #25 — authoring: the question bank, and the quizzes assembled from it. The
  // 'quiz' tab further down is a different thing — it records a SCORE against the
  // legacy hr_training_quizzes table and is left exactly as it was.
  { key:'quizbuilder',label:'Quiz Builder',       icon:ListChecks,     ready:true },
  { key:'calendar',   label:'Calendar',           icon:CalendarRange,  ready:true },
  { key:'sessions',   label:'Sessions',           icon:Presentation,   ready:true },
  { key:'assignment', label:'Assignment',         icon:UserPlus,       ready:true },
  { key:'attendance', label:'Attendance',         icon:UserCheck,      ready:true },
  { key:'assessment', label:'Assessment',         icon:ClipboardCheck, ready:true },
  { key:'quiz',       label:'Quiz Scores',        icon:FileQuestion,   ready:true },
  { key:'certificates',label:'Certificates',      icon:Award,          ready:true },
  { key:'completion', label:'Completion',         icon:CheckCircle2,   ready:true },
  { key:'reports',    label:'Reports',            icon:BarChart3,      ready:true },
]

/**
 * Review comment #24 — "Lifecycle - present it properly".
 *
 * The tab ORDER already follows the lifecycle (fixed in an earlier phase), but a
 * flat strip of fourteen equal buttons doesn't show that there IS a lifecycle —
 * "Assignment" looks like a sibling of "Categories" rather than a later stage.
 * These groups make the existing order legible.
 *
 * Presentation only: same tabs, same keys, same order, same handlers. Nothing
 * about how a program, session or assessment behaves changes.
 */
const TAB_PHASES = [
  { label:'Overview',   keys:['dashboard'] },
  { label:'Setup',      keys:['categories', 'types', 'providers', 'programs', 'quizbuilder'] },
  { label:'Delivery',   keys:['calendar', 'sessions', 'assignment', 'attendance'] },
  { label:'Evaluation', keys:['assessment', 'quiz', 'certificates', 'completion'] },
  { label:'Reports',    keys:['reports'] },
]

export default function LearningDevelopment() {
  useTheme()
  const [tab, setTab] = useState('dashboard')
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
            <GraduationCap size={22} style={{ color:'#a78bfa' }}/> Learning &amp; <span className="text-gradient">Development</span>
          </h1>
        </div>
      </div>

      {/* #24 — the same tabs in the same order, grouped so the training lifecycle
          reads as stages instead of fourteen equal buttons. */}
      <div className="flex gap-x-5 gap-y-3 flex-wrap items-start">
        {TAB_PHASES.map((phase, i) => (
          <div key={phase.label} className="flex items-start gap-5">
            <div>
              <p className="label-caps mb-1.5" style={{ fontSize:10, letterSpacing:'0.06em' }}>{phase.label}</p>
              <div className="flex gap-1.5 flex-wrap">
                {phase.keys.map(key => {
                  const t = TABS.find(x => x.key === key)
                  if (!t) return null
                  const active = tab === t.key
                  return (
                    <button key={t.key} onClick={()=>setTab(t.key)} className="flex items-center gap-2 px-3.5 py-2 rounded-xl text-sm font-bold transition-all"
                      style={{ background: active ? GRAD : 'var(--bg-input)', color: active ? '#fff' : 'var(--text-muted)', border: active ? 'none' : '1px solid var(--border)' }}>
                      <t.icon size={15}/> {t.label}{!t.ready && <Lock size={11} style={{ opacity:0.7 }}/>}
                    </button>
                  )
                })}
              </div>
            </div>
            {/* Separator between phases — the arrow is what makes it read as a
                progression rather than five unrelated groups. */}
            {i < TAB_PHASES.length - 1 && (
              <ChevronRight size={16} style={{ color:'var(--text-muted)', opacity:0.5, marginTop:26, flexShrink:0 }}/>
            )}
          </div>
        ))}
      </div>

      {tab === 'categories' ? <Categories showToast={showToast} />
        : tab === 'types' ? <Types showToast={showToast} />
        : tab === 'providers' ? <Providers showToast={showToast} />
        : tab === 'programs' ? <Programs showToast={showToast} />
        : tab === 'calendar' ? <Calendar showToast={showToast} />
        : tab === 'sessions' ? <Sessions showToast={showToast} />
        : tab === 'assignment' ? <Assignments showToast={showToast} />
        : tab === 'attendance' ? <Attendance showToast={showToast} />
        : tab === 'assessment' ? <Assessments showToast={showToast} />
        : tab === 'quizbuilder' ? <QuizBuilder showToast={showToast} />
        : tab === 'quiz' ? <Quizzes showToast={showToast} />
        : tab === 'certificates' ? <Certificates showToast={showToast} />
        : tab === 'completion' ? <Completion showToast={showToast} />
        : tab === 'dashboard' ? <LearningReports showToast={showToast} />
        : tab === 'reports' ? <LearningReports showToast={showToast} />
        : null}
    </div>
  )
}

/* ── Shared master UI (Categories / Types / Providers share table + modal shells) ── */
function Kpis({ stats }) {
  const K = [{l:'Total',v:stats.total,c:'#7C3AED'},{l:'Active',v:stats.active,c:'#10b981'},{l:'Inactive',v:stats.inactive,c:'#f87171'}]
  return <div className="grid grid-cols-3 gap-4">{K.map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}</div>
}

function FilterBar({ search, setSearch, statusF, setStatusF, extra, onClear, hasF, onAdd, addLabel, placeholder }) {
  return (
    <div className="card-3d" style={{ padding:'16px' }}>
      <div className="flex gap-3 flex-wrap items-end">
        <div className="relative flex-1 min-w-[200px]"><label className="label">Search</label><Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/><input className="input-3d pl-9 text-sm" placeholder={placeholder} value={search} onChange={e=>setSearch(e.target.value)}/></div>
        <div className="min-w-[130px]"><label className="label">Status</label><select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['All','Active','Inactive'].map(s=><option key={s}>{s}</option>)}</select></div>
        {extra}
        {hasF && <button onClick={onClear} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
        <button onClick={onAdd} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white ml-auto" style={{ background:GRAD }}><Plus size={15}/> {addLabel}</button>
      </div>
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

/* ── Training Categories ── */
function Categories({ showToast }) {
  const [rows, setRows] = useState([]); const [stats, setStats] = useState({ total:0, active:0, inactive:0 })
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState(''); const [statusF, setStatusF] = useState('All')
  const [modal, setModal] = useState(null); const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    const params = {}; if (statusF !== 'All') params.status = statusF; if (search) params.search = search
    hrApi.learning.categories.list(params).then(res => { setRows(res.data||[]); setStats(res.stats||stats) }).catch(()=>showToast('Failed to load categories','error')).finally(()=>setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusF, search])
  useEffect(() => { load() }, [load])

  const EMPTY = { name:'', code:'', description:'', is_active:true }
  const save = async () => {
    const f = modal.form
    if (!f.name.trim() || !f.code.trim()) return showToast('Name and code are required','error')
    setSaving(true)
    try { modal.editing ? await hrApi.learning.categories.update(modal.editing, f) : await hrApi.learning.categories.create(f); showToast(`Category ${modal.editing?'updated':'created'}`); setModal(null); load() }
    catch (e) { showToast(e.response?.data?.message||'Save failed','error') } finally { setSaving(false) }
  }
  const toggle = async (r) => { try { await hrApi.learning.categories.setStatus(r.id, !r.is_active); load() } catch { showToast('Failed','error') } }
  const hasF = statusF!=='All'||search

  return (
    <div className="space-y-4">
      <Kpis stats={stats} />
      <FilterBar search={search} setSearch={setSearch} statusF={statusF} setStatusF={setStatusF} hasF={hasF} placeholder="Name or code…"
        onClear={()=>{ setStatusF('All'); setSearch('') }} onAdd={()=>setModal({ editing:null, form:{...EMPTY} })} addLabel="Add Category" />

      {loading ? <HrLoading label="Loading categories…" /> : rows.length===0 ? <HrEmpty icon={FolderTree} title="No training categories yet" hint="Create categories (Technical, Soft Skills, Compliance…)." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:620 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Category','Description','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{rows.map(r=>(
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)', opacity:r.is_active?1:0.55 }}>
                  <td className="px-3 py-2.5"><span className="font-bold" style={{ color:'var(--text-h)' }}>{r.name}</span> <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.code}</span></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.description||'—'}</td>
                  <td className="px-3 py-2.5"><StatusPill active={r.is_active} /></td>
                  <td className="px-3 py-2.5"><RowActions onEdit={()=>setModal({ editing:r.id, form:{ ...EMPTY, ...r } })} onToggle={()=>toggle(r)} active={r.is_active} /></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}

      {modal && (
        <div className="modal-backdrop" onClick={()=>setModal(null)}><div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()}>
          <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{modal.editing?'Edit Category':'Add Category'}</h2><button onClick={()=>setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Name *</label><input className="input-3d text-sm" value={modal.form.name} onChange={e=>setModal(m=>({...m,form:{...m.form,name:e.target.value}}))}/></div>
            <div><label className="label">Code *</label><input className="input-3d text-sm" value={modal.form.code} onChange={e=>setModal(m=>({...m,form:{...m.form,code:e.target.value}}))}/></div>
            <div className="col-span-2"><label className="label">Description</label><textarea rows={2} className="input-3d text-sm resize-none" value={modal.form.description} onChange={e=>setModal(m=>({...m,form:{...m.form,description:e.target.value}}))}/></div>
            {modal.editing && <label className="col-span-2 flex items-center gap-2 text-xs font-semibold"><input type="checkbox" checked={modal.form.is_active} onChange={e=>setModal(m=>({...m,form:{...m.form,is_active:e.target.checked}}))}/> Active</label>}
          </div>
          <div className="flex gap-3 pt-4"><button onClick={()=>setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Saving…':'Save'}</button></div>
        </div></div>
      )}
    </div>
  )
}

/* ── Training Types ── */
const MODES = ['Online', 'Offline', 'Hybrid']
function Types({ showToast }) {
  const [rows, setRows] = useState([]); const [stats, setStats] = useState({ total:0, active:0, inactive:0 })
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState(''); const [statusF, setStatusF] = useState('All')
  const [modal, setModal] = useState(null); const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    const params = {}; if (statusF !== 'All') params.status = statusF; if (search) params.search = search
    hrApi.learning.types.list(params).then(res => { setRows(res.data||[]); setStats(res.stats||stats) }).catch(()=>showToast('Failed to load types','error')).finally(()=>setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusF, search])
  useEffect(() => { load() }, [load])

  const EMPTY = { name:'', code:'', mode:'Offline', default_duration_hours:8, certification_applicable:false, description:'', is_active:true }
  const save = async () => {
    const f = modal.form
    if (!f.name.trim() || !f.code.trim()) return showToast('Name and code are required','error')
    setSaving(true)
    try { modal.editing ? await hrApi.learning.types.update(modal.editing, f) : await hrApi.learning.types.create(f); showToast(`Type ${modal.editing?'updated':'created'}`); setModal(null); load() }
    catch (e) { showToast(e.response?.data?.message||'Save failed','error') } finally { setSaving(false) }
  }
  const toggle = async (r) => { try { await hrApi.learning.types.setStatus(r.id, !r.is_active); load() } catch { showToast('Failed','error') } }
  const hasF = statusF!=='All'||search

  return (
    <div className="space-y-4">
      <Kpis stats={stats} />
      <FilterBar search={search} setSearch={setSearch} statusF={statusF} setStatusF={setStatusF} hasF={hasF} placeholder="Name or code…"
        onClear={()=>{ setStatusF('All'); setSearch('') }} onAdd={()=>setModal({ editing:null, form:{...EMPTY} })} addLabel="Add Type" />

      {loading ? <HrLoading label="Loading types…" /> : rows.length===0 ? <HrEmpty icon={Layers} title="No training types yet" hint="Create types (Classroom, E-Learning, Workshop…)." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:760 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Type','Mode','Duration','Certification','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{rows.map(r=>(
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)', opacity:r.is_active?1:0.55 }}>
                  <td className="px-3 py-2.5"><span className="font-bold" style={{ color:'var(--text-h)' }}>{r.name}</span> <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.code}</span></td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}>{r.mode}</span></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.default_duration_hours}h</td>
                  <td className="px-3 py-2.5">{r.certification_applicable?'✓':'—'}</td>
                  <td className="px-3 py-2.5"><StatusPill active={r.is_active} /></td>
                  <td className="px-3 py-2.5"><RowActions onEdit={()=>setModal({ editing:r.id, form:{ ...EMPTY, ...r } })} onToggle={()=>toggle(r)} active={r.is_active} /></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}

      {modal && (
        <div className="modal-backdrop" onClick={()=>setModal(null)}><div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()} style={{ maxHeight:'92vh', overflowY:'auto' }}>
          <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{modal.editing?'Edit Type':'Add Type'}</h2><button onClick={()=>setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Name *</label><input className="input-3d text-sm" value={modal.form.name} onChange={e=>setModal(m=>({...m,form:{...m.form,name:e.target.value}}))}/></div>
            <div><label className="label">Code *</label><input className="input-3d text-sm" value={modal.form.code} onChange={e=>setModal(m=>({...m,form:{...m.form,code:e.target.value}}))}/></div>
            <div><label className="label">Mode</label><select className="input-3d text-sm" value={modal.form.mode} onChange={e=>setModal(m=>({...m,form:{...m.form,mode:e.target.value}}))}>{MODES.map(x=><option key={x}>{x}</option>)}</select></div>
            <div><label className="label">Default Duration (hours)</label><input type="number" min="0" className="input-3d text-sm" value={modal.form.default_duration_hours} onChange={e=>setModal(m=>({...m,form:{...m.form,default_duration_hours:e.target.value}}))}/></div>
            <label className="col-span-2 flex items-center gap-2 text-xs font-semibold px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}><input type="checkbox" checked={modal.form.certification_applicable} onChange={e=>setModal(m=>({...m,form:{...m.form,certification_applicable:e.target.checked}}))}/> Certification Applicable</label>
            <div className="col-span-2"><label className="label">Description</label><textarea rows={2} className="input-3d text-sm resize-none" value={modal.form.description} onChange={e=>setModal(m=>({...m,form:{...m.form,description:e.target.value}}))}/></div>
            {modal.editing && <label className="col-span-2 flex items-center gap-2 text-xs font-semibold"><input type="checkbox" checked={modal.form.is_active} onChange={e=>setModal(m=>({...m,form:{...m.form,is_active:e.target.checked}}))}/> Active</label>}
          </div>
          <div className="flex gap-3 pt-4"><button onClick={()=>setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Saving…':'Save'}</button></div>
        </div></div>
      )}
    </div>
  )
}

/* ── Training Providers ── */
const PROVIDER_TYPES = ['Internal', 'External']
function Providers({ showToast }) {
  const [rows, setRows] = useState([]); const [stats, setStats] = useState({ total:0, active:0, inactive:0 })
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState(''); const [statusF, setStatusF] = useState('All'); const [typeF, setTypeF] = useState('All')
  const [modal, setModal] = useState(null); const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    const params = {}; if (statusF !== 'All') params.status = statusF; if (typeF !== 'All') params.provider_type = typeF; if (search) params.search = search
    hrApi.learning.providers.list(params).then(res => { setRows(res.data||[]); setStats(res.stats||stats) }).catch(()=>showToast('Failed to load providers','error')).finally(()=>setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusF, typeF, search])
  useEffect(() => { load() }, [load])

  // #22 — the department list. Reuses the same organization options endpoint the
  // Programs tab already calls; no second source of departments.
  const [departments, setDepartments] = useState([])
  useEffect(() => { hrApi.organization.options().then(o=>setDepartments(o.departments||[])).catch(()=>{}) }, [])

  // #22 — Department, Expertise, Certifications, Qualifications and Skills. The
  // API has accepted and returned all five since Phase B; the form simply never
  // sent them, so every value a user typed elsewhere was invisible here. The four
  // lists MUST default to arrays: TagInput spreads `value`, and an undefined would
  // throw on first render.
  const EMPTY = {
    name:'', code:'', provider_type:'External', contact_person:'', email:'', phone:'',
    website:'', description:'', is_active:true,
    department_id:'', expertise:[], certifications:[], qualifications:[], skills:[],
  }
  const save = async () => {
    const f = modal.form
    if (!f.name.trim()) return showToast('Provider name is required','error')
    setSaving(true)
    // #22 — the select yields '' for "no department"; the API validates
    // `nullable|integer`, which '' fails. Send null so "none" is expressible.
    const payload = { ...f, department_id: f.department_id === '' ? null : f.department_id }
    try { modal.editing ? await hrApi.learning.providers.update(modal.editing, payload) : await hrApi.learning.providers.create(payload); showToast(`Provider ${modal.editing?'updated':'created'}`); setModal(null); load() }
    catch (e) { showToast(e.response?.data?.message||'Save failed','error') } finally { setSaving(false) }
  }
  const toggle = async (r) => { try { await hrApi.learning.providers.setStatus(r.id, !r.is_active); load() } catch { showToast('Failed','error') } }
  const hasF = statusF!=='All'||typeF!=='All'||search
  const typeExtra = <div className="min-w-[140px]"><label className="label">Type</label><select className="input-3d text-sm" value={typeF} onChange={e=>setTypeF(e.target.value)}>{['All',...PROVIDER_TYPES].map(s=><option key={s}>{s}</option>)}</select></div>

  return (
    <div className="space-y-4">
      <Kpis stats={stats} />
      <FilterBar search={search} setSearch={setSearch} statusF={statusF} setStatusF={setStatusF} hasF={hasF} extra={typeExtra} placeholder="Name or contact…"
        onClear={()=>{ setStatusF('All'); setTypeF('All'); setSearch('') }} onAdd={()=>setModal({ editing:null, form:{...EMPTY} })} addLabel="Add Provider" />

      {loading ? <HrLoading label="Loading providers…" /> : rows.length===0 ? <HrEmpty icon={Building2} title="No training providers yet" hint="Add internal teams or external vendors (Coursera, Udemy…)." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:820 }}>
              {/* #22 — Department is a column, not a modal-only field: it is how a
                  trainer is matched to a team, so it has to be visible in the list. */}
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Provider','Type','Department','Contact','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{rows.map(r=>(
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)', opacity:r.is_active?1:0.55 }}>
                  <td className="px-3 py-2.5">
                    <span className="font-bold" style={{ color:'var(--text-h)' }}>{r.name}</span>
                    {r.website && <a href={r.website.startsWith('http')?r.website:`https://${r.website}`} target="_blank" rel="noreferrer" className="ml-2 inline-flex" style={{ color:'#a78bfa' }}><Globe size={12}/></a>}
                    {/* #22 — what this provider actually delivers, at a glance. */}
                    {r.expertise?.length > 0 && (
                      <span className="flex flex-wrap gap-1 mt-1">
                        {r.expertise.slice(0,3).map(x=><span key={x} className="text-[9px] font-bold px-1.5 py-0.5 rounded-md" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}>{x}</span>)}
                        {r.expertise.length>3 && <span className="text-[9px]" style={{ color:'var(--text-muted)' }}>+{r.expertise.length-3}</span>}
                      </span>
                    )}
                  </td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={r.provider_type==='Internal'?{background:'rgba(59,130,246,0.12)',color:'#3b82f6'}:{background:'rgba(124,58,237,0.1)',color:'#a78bfa'}}>{r.provider_type}</span></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.department_name || '—'}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.contact_person||'—'}{r.email && <span className="ml-2 inline-flex items-center gap-1 text-[10px]"><Mail size={10}/>{r.email}</span>}{r.phone && <span className="ml-2 inline-flex items-center gap-1 text-[10px]"><Phone size={10}/>{r.phone}</span>}</td>
                  <td className="px-3 py-2.5"><StatusPill active={r.is_active} /></td>
                  {/* #22 — null becomes '' for the select, and the four lists fall
                      back to [] so a provider saved before this existed still opens. */}
                  <td className="px-3 py-2.5"><RowActions onEdit={()=>setModal({ editing:r.id, form:{ ...EMPTY, ...r,
                    department_id: r.department_id ?? '',
                    expertise: r.expertise||[], certifications: r.certifications||[],
                    qualifications: r.qualifications||[], skills: r.skills||[],
                  } })} onToggle={()=>toggle(r)} active={r.is_active} /></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}

      {modal && (
        <div className="modal-backdrop" onClick={()=>setModal(null)}><div className="modal-box" onClick={e=>e.stopPropagation()} style={{ maxWidth:640, width:'95%', maxHeight:'92vh', overflowY:'auto' }}>
          <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{modal.editing?'Edit Provider':'Add Provider'}</h2><button onClick={()=>setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Name *</label><input className="input-3d text-sm" value={modal.form.name} onChange={e=>setModal(m=>({...m,form:{...m.form,name:e.target.value}}))}/></div>
            <div><label className="label">Code</label><input className="input-3d text-sm" value={modal.form.code} onChange={e=>setModal(m=>({...m,form:{...m.form,code:e.target.value}}))}/></div>
            <div><label className="label">Type</label><select className="input-3d text-sm" value={modal.form.provider_type} onChange={e=>setModal(m=>({...m,form:{...m.form,provider_type:e.target.value}}))}>{PROVIDER_TYPES.map(x=><option key={x}>{x}</option>)}</select></div>
            <div><label className="label">Contact Person</label><input className="input-3d text-sm" value={modal.form.contact_person} onChange={e=>setModal(m=>({...m,form:{...m.form,contact_person:e.target.value}}))}/></div>
            <div><label className="label">Email</label><input className="input-3d text-sm" value={modal.form.email} onChange={e=>setModal(m=>({...m,form:{...m.form,email:e.target.value}}))}/></div>
            <div><label className="label">Phone</label><input className="input-3d text-sm" value={modal.form.phone} onChange={e=>setModal(m=>({...m,form:{...m.form,phone:e.target.value}}))}/></div>
            <div className="col-span-2"><label className="label">Website</label><input className="input-3d text-sm" value={modal.form.website} onChange={e=>setModal(m=>({...m,form:{...m.form,website:e.target.value}}))}/></div>
            {/* #22 — Department reuses hr_departments, so a provider lines up with
                the same org structure everything else in HR uses. */}
            <div className="col-span-2"><label className="label">Department</label>
              <select className="input-3d text-sm" value={modal.form.department_id}
                onChange={e=>setModal(m=>({...m,form:{...m.form,department_id:e.target.value}}))}>
                <option value="">— None —</option>
                {departments.map(d=><option key={d.id} value={d.id}>{d.name}</option>)}
              </select>
            </div>
            <div className="col-span-2"><label className="label">Description</label><textarea rows={2} className="input-3d text-sm resize-none" value={modal.form.description} onChange={e=>setModal(m=>({...m,form:{...m.form,description:e.target.value}}))}/></div>

            {/* #22 — Expertise / Certifications / Qualifications / Skills. Free-text
                lists on the same shared TagInput the rest of the app uses; the
                server trims, drops blanks and de-duplicates them case-insensitively. */}
            {[
              ['expertise',      'Expertise',      'e.g. Safety Training',        20],
              ['certifications', 'Certifications', 'e.g. ISO 45001',              20],
              ['qualifications', 'Qualifications', 'e.g. Certified NEBOSH Tutor', 20],
              ['skills',         'Skills',         'e.g. Scaffolding',            30],
            ].map(([key, label, placeholder, max]) => (
              <div key={key} className="col-span-2">
                <label className="label">{label}</label>
                <TagInput value={modal.form[key]} max={max} placeholder={placeholder}
                  onChange={next=>setModal(m=>({...m,form:{...m.form,[key]:next}}))}/>
              </div>
            ))}

            {modal.editing && <label className="col-span-2 flex items-center gap-2 text-xs font-semibold"><input type="checkbox" checked={modal.form.is_active} onChange={e=>setModal(m=>({...m,form:{...m.form,is_active:e.target.checked}}))}/> Active</label>}
          </div>
          <div className="flex gap-3 pt-4"><button onClick={()=>setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Saving…':'Save'}</button></div>
        </div></div>
      )}
    </div>
  )
}

/* ── Training Programs (Phase 2) ── */
const DURATION_UNITS = ['Hours', 'Days', 'Weeks']
function Programs({ showToast }) {
  const [data, setData] = useState({ data:[], stats:{ total:0, active:0, inactive:0, certification:0, total_seats:0 } })
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState(''); const [statusF, setStatusF] = useState('All')
  const [catF, setCatF] = useState(''); const [typeF, setTypeF] = useState(''); const [provF, setProvF] = useState('')
  const [cats, setCats] = useState([]); const [types, setTypes] = useState([]); const [provs, setProvs] = useState([])
  const [org, setOrg] = useState({ departments:[], designations:[] })
  const [modal, setModal] = useState(null); const [saving, setSaving] = useState(false); const [view, setView] = useState(null)

  const load = useCallback(() => {
    setLoading(true)
    const params = {}
    if (statusF !== 'All') params.status = statusF
    if (catF) params.category_id = catF
    if (typeF) params.training_type_id = typeF
    if (provF) params.provider_id = provF
    if (search) params.search = search
    hrApi.learning.programs.list(params).then(setData).catch(()=>showToast('Failed to load programs','error')).finally(()=>setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusF, catF, typeF, provF, search])
  useEffect(() => { load() }, [load])
  useEffect(() => {
    hrApi.learning.categories.list({ status:'Active' }).then(r=>setCats(r.data||[])).catch(()=>{})
    hrApi.learning.types.list({ status:'Active' }).then(r=>setTypes(r.data||[])).catch(()=>{})
    hrApi.learning.providers.list({ status:'Active' }).then(r=>setProvs(r.data||[])).catch(()=>{})
    hrApi.organization.options().then(o=>setOrg({ departments:o.departments||[], designations:o.designations||[] })).catch(()=>{})
  }, [])

  const EMPTY = { program_code:'', program_name:'', category_id:'', training_type_id:'', provider_id:'', department_id:'', designation_id:'', description:'', objectives:'', duration:8, duration_unit:'Hours', mode:'Offline', capacity:20, certification_applicable:false, passing_percentage:60, validity_days:'' }
  const openEdit = async (row) => {
    try {
      const f = await hrApi.learning.programs.get(row.id)
      setModal({ editing:f.id, form:{ ...EMPTY, ...f, department_id:f.department_id||'', designation_id:f.designation_id||'', validity_days:f.validity_days??'' } })
    } catch { showToast('Failed to load program','error') }
  }
  const save = async () => {
    const f = modal.form
    if (!f.program_code.trim() || !f.program_name.trim()) return showToast('Program code and name are required','error')
    if (!f.category_id || !f.training_type_id || !f.provider_id) return showToast('Category, type and provider are required','error')
    if (Number(f.duration) <= 0 || Number(f.capacity) <= 0) return showToast('Duration and capacity must be greater than zero','error')
    setSaving(true)
    try { modal.editing ? await hrApi.learning.programs.update(modal.editing, f) : await hrApi.learning.programs.create(f); showToast(`Program ${modal.editing?'updated':'created'}`); setModal(null); load() }
    catch (e) { showToast(e.response?.data?.message||'Save failed','error') } finally { setSaving(false) }
  }
  const toggle = async (r) => { try { await hrApi.learning.programs.setStatus(r.id, !r.is_active); load() } catch { showToast('Failed','error') } }

  const s = data.stats
  const KPIS = [
    { l:'Total', v:s.total, c:'#7C3AED' }, { l:'Active', v:s.active, c:'#10b981' },
    { l:'Inactive', v:s.inactive, c:'#f87171' }, { l:'Certified', v:s.certification, c:'#3b82f6' }, { l:'Active Seats', v:s.total_seats, c:'#f59e0b' },
  ]
  const hasF = statusF!=='All'||catF||typeF||provF||search
  const rows = data.data

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-5 gap-3">{KPIS.map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}</div>

      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[180px]"><label className="label">Search</label><Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/><input className="input-3d pl-9 text-sm" placeholder="Program name or code…" value={search} onChange={e=>setSearch(e.target.value)}/></div>
          <div className="min-w-[140px]"><label className="label">Category</label><select className="input-3d text-sm" value={catF} onChange={e=>setCatF(e.target.value)}><option value="">All</option>{cats.map(c=><option key={c.id} value={c.id}>{c.name}</option>)}</select></div>
          <div className="min-w-[140px]"><label className="label">Type</label><select className="input-3d text-sm" value={typeF} onChange={e=>setTypeF(e.target.value)}><option value="">All</option>{types.map(t=><option key={t.id} value={t.id}>{t.name}</option>)}</select></div>
          <div className="min-w-[140px]"><label className="label">Provider</label><select className="input-3d text-sm" value={provF} onChange={e=>setProvF(e.target.value)}><option value="">All</option>{provs.map(p=><option key={p.id} value={p.id}>{p.name}</option>)}</select></div>
          <div className="min-w-[120px]"><label className="label">Status</label><select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['All','Active','Inactive'].map(x=><option key={x}>{x}</option>)}</select></div>
          {hasF && <button onClick={()=>{ setStatusF('All'); setCatF(''); setTypeF(''); setProvF(''); setSearch('') }} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
          <button onClick={()=>setModal({ editing:null, form:{...EMPTY} })} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white ml-auto" style={{ background:GRAD }}><Plus size={15}/> Add Program</button>
        </div>
      </div>

      {loading ? <HrLoading label="Loading programs…" /> : rows.length===0 ? <HrEmpty icon={BookOpen} title="No training programs yet" hint="Create a program from your categories, types and providers." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:940 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Program','Category','Type','Provider','Duration','Capacity','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{rows.map(r=>(
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)', opacity:r.is_active?1:0.55 }}>
                  <td className="px-3 py-2.5"><div className="font-bold" style={{ color:'var(--text-h)' }}>{r.program_name}</div><div className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.program_code}{r.certification_applicable && <span className="ml-2" style={{ color:'#3b82f6' }}>• Certified</span>}</div></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.category||'—'}</td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}>{r.mode}</span> <span style={{ color:'var(--text-muted)' }}>{r.training_type}</span></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.provider||'—'}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.duration} {r.duration_unit}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.capacity}</td>
                  <td className="px-3 py-2.5"><StatusPill active={r.is_active} /></td>
                  <td className="px-3 py-2.5"><div className="flex gap-1.5 justify-end">
                    <button title="View" onClick={()=>setView(r.id)} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Eye size={13}/></button>
                    <button title="Edit" onClick={()=>openEdit(r)} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Pencil size={13}/></button>
                    <button title={r.is_active?'Deactivate':'Activate'} onClick={()=>toggle(r)} className="p-1.5 rounded-lg" style={r.is_active?{background:'rgba(239,68,68,0.1)',color:'#f87171'}:{background:'rgba(16,185,129,0.1)',color:'#10b981'}}><Power size={13}/></button>
                  </div></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}

      {/* Add / Edit modal */}
      {modal && (
        <div className="modal-backdrop" onClick={()=>setModal(null)}><div className="modal-box" onClick={e=>e.stopPropagation()} style={{ maxWidth:760, width:'96%', maxHeight:'92vh', overflowY:'auto' }}>
          <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{modal.editing?'Edit Program':'Add Program'}</h2><button onClick={()=>setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
          <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
            <div><label className="label">Program Code *</label><input className="input-3d text-sm" value={modal.form.program_code} onChange={e=>setModal(m=>({...m,form:{...m.form,program_code:e.target.value}}))}/></div>
            <div className="col-span-1 md:col-span-2"><label className="label">Program Name *</label><input className="input-3d text-sm" value={modal.form.program_name} onChange={e=>setModal(m=>({...m,form:{...m.form,program_name:e.target.value}}))}/></div>
            <div><label className="label">Category *</label><select className="input-3d text-sm" value={modal.form.category_id} onChange={e=>setModal(m=>({...m,form:{...m.form,category_id:e.target.value}}))}><option value="">Select…</option>{cats.map(c=><option key={c.id} value={c.id}>{c.name}</option>)}</select></div>
            <div><label className="label">Training Type *</label><select className="input-3d text-sm" value={modal.form.training_type_id} onChange={e=>setModal(m=>({...m,form:{...m.form,training_type_id:e.target.value}}))}><option value="">Select…</option>{types.map(t=><option key={t.id} value={t.id}>{t.name}</option>)}</select></div>
            <div><label className="label">Provider *</label><select className="input-3d text-sm" value={modal.form.provider_id} onChange={e=>setModal(m=>({...m,form:{...m.form,provider_id:e.target.value}}))}><option value="">Select…</option>{provs.map(p=><option key={p.id} value={p.id}>{p.name}</option>)}</select></div>
            <div><label className="label">Duration *</label><input type="number" min="1" className="input-3d text-sm" value={modal.form.duration} onChange={e=>setModal(m=>({...m,form:{...m.form,duration:e.target.value}}))}/></div>
            <div><label className="label">Duration Unit</label><select className="input-3d text-sm" value={modal.form.duration_unit} onChange={e=>setModal(m=>({...m,form:{...m.form,duration_unit:e.target.value}}))}>{DURATION_UNITS.map(x=><option key={x}>{x}</option>)}</select></div>
            <div><label className="label">Mode</label><select className="input-3d text-sm" value={modal.form.mode} onChange={e=>setModal(m=>({...m,form:{...m.form,mode:e.target.value}}))}>{MODES.map(x=><option key={x}>{x}</option>)}</select></div>
            <div><label className="label">Capacity *</label><input type="number" min="1" className="input-3d text-sm" value={modal.form.capacity} onChange={e=>setModal(m=>({...m,form:{...m.form,capacity:e.target.value}}))}/></div>
            <div><label className="label">Passing %</label><input type="number" min="0" max="100" className="input-3d text-sm" value={modal.form.passing_percentage} onChange={e=>setModal(m=>({...m,form:{...m.form,passing_percentage:e.target.value}}))}/></div>
            <div><label className="label">Validity (days)</label><input type="number" min="0" className="input-3d text-sm" value={modal.form.validity_days} onChange={e=>setModal(m=>({...m,form:{...m.form,validity_days:e.target.value}}))}/></div>
            <div><label className="label">Department</label><select className="input-3d text-sm" value={modal.form.department_id} onChange={e=>setModal(m=>({...m,form:{...m.form,department_id:e.target.value}}))}><option value="">—</option>{org.departments.map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select></div>
            <div><label className="label">Designation</label><select className="input-3d text-sm" value={modal.form.designation_id} onChange={e=>setModal(m=>({...m,form:{...m.form,designation_id:e.target.value}}))}><option value="">—</option>{org.designations.map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select></div>
            <label className="flex items-center gap-2 text-xs font-semibold px-3 py-2 rounded-xl self-end" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}><input type="checkbox" checked={modal.form.certification_applicable} onChange={e=>setModal(m=>({...m,form:{...m.form,certification_applicable:e.target.checked}}))}/> Certification</label>
            <div className="col-span-2 md:col-span-3"><label className="label">Objectives</label><textarea rows={2} className="input-3d text-sm resize-none" value={modal.form.objectives} onChange={e=>setModal(m=>({...m,form:{...m.form,objectives:e.target.value}}))}/></div>
            <div className="col-span-2 md:col-span-3"><label className="label">Description</label><textarea rows={2} className="input-3d text-sm resize-none" value={modal.form.description} onChange={e=>setModal(m=>({...m,form:{...m.form,description:e.target.value}}))}/></div>
            {modal.editing && <label className="col-span-2 md:col-span-3 flex items-center gap-2 text-xs font-semibold"><input type="checkbox" checked={modal.form.is_active} onChange={e=>setModal(m=>({...m,form:{...m.form,is_active:e.target.checked}}))}/> Active</label>}
          </div>
          <div className="flex gap-3 pt-4"><button onClick={()=>setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Saving…':'Save Program'}</button></div>
        </div></div>
      )}

      {/* View drawer */}
      {view && <ProgramDrawer id={view} onClose={()=>setView(null)} onEdit={(id)=>{ setView(null); openEdit({ id }) }} />}
    </div>
  )
}

function ProgramDrawer({ id, onClose, onEdit }) {
  const [p, setP] = useState(null); const [loading, setLoading] = useState(true)
  useEffect(() => { setLoading(true); hrApi.learning.programs.get(id).then(setP).finally(()=>setLoading(false)) }, [id])
  const Field = ({ l, v }) => <div><p className="label-caps mb-0.5">{l}</p><p className="text-sm font-semibold" style={{ color:'var(--text-h)' }}>{v||'—'}</p></div>

  return (
    <div className="fixed inset-0 z-[9998] flex justify-end" onClick={onClose}>
      <div className="absolute inset-0" style={{ background:'rgba(0,0,0,0.4)' }} />
      <div className="relative h-full overflow-y-auto animate-[slideIn_0.25s_ease]" onClick={e=>e.stopPropagation()} style={{ width:'min(460px,96vw)', background:'var(--bg-card,var(--bg-input))', borderLeft:'1px solid var(--border)', boxShadow:'-10px 0 40px rgba(0,0,0,0.3)' }}>
        <div className="sticky top-0 z-10 flex items-center justify-between px-5 py-4" style={{ background:'var(--bg-card,var(--bg-input))', borderBottom:'1px solid var(--border)' }}>
          <h2 className="font-black text-base flex items-center gap-2" style={{ color:'var(--text-h)' }}><BookOpen size={16} style={{ color:'#a78bfa' }}/> Training Program</h2>
          <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>
        {loading || !p ? <div className="p-6"><HrLoading label="Loading…" /></div> : (
          <div className="p-5 space-y-5">
            <div className="flex items-center justify-between">
              <div><p className="font-black text-lg" style={{ color:'var(--text-h)' }}>{p.program_name}</p><p className="text-[11px] font-mono" style={{ color:'#a78bfa' }}>{p.program_code}</p></div>
              <StatusPill active={p.is_active} />
            </div>
            <div className="flex gap-3 flex-wrap">
              <span className="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 rounded-lg" style={{ background:'var(--bg-input)', color:'var(--text-h)' }}><Clock size={12}/> {p.duration} {p.duration_unit}</span>
              <span className="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 rounded-lg" style={{ background:'var(--bg-input)', color:'var(--text-h)' }}><Users size={12}/> {p.capacity} seats</span>
              <span className="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}>{p.mode}</span>
              {p.certification_applicable && <span className="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 rounded-lg" style={{ background:'rgba(59,130,246,0.12)', color:'#3b82f6' }}><Award size={12}/> Certified</span>}
            </div>
            <div className="grid grid-cols-2 gap-4">
              <Field l="Category" v={p.category} />
              <Field l="Training Type" v={p.training_type} />
              <Field l="Provider" v={`${p.provider||'—'}${p.provider_type?` (${p.provider_type})`:''}`} />
              <Field l="Passing %" v={`${p.passing_percentage}%`} />
              <Field l="Validity" v={p.validity_days!=null?`${p.validity_days} days`:'No expiry'} />
              <Field l="Department" v={p.department} />
              <Field l="Designation" v={p.designation} />
            </div>
            {p.objectives && <div><p className="label-caps mb-1 flex items-center gap-1.5"><Target size={12}/> Objectives</p><p className="text-sm" style={{ color:'var(--text-h)' }}>{p.objectives}</p></div>}
            {p.description && <div><p className="label-caps mb-1">Description</p><p className="text-sm" style={{ color:'var(--text-h)' }}>{p.description}</p></div>}
            <button onClick={()=>onEdit(p.id)} className="w-full py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:GRAD }}><Pencil size={14}/> Edit Program</button>
          </div>
        )}
      </div>
    </div>
  )
}

/* ── Training Sessions (Phase 3) ── */
const SESSION_KPIS = (s) => [
  { l:'Upcoming', v:s.upcoming, c:'#3b82f6' }, { l:"Today's Sessions", v:s.today, c:'#7C3AED' },
  { l:'Ongoing', v:s.ongoing, c:'#f59e0b' }, { l:'Completed', v:s.completed, c:'#10b981' }, { l:'Cancelled', v:s.cancelled, c:'#f87171' },
]
const SessionPill = ({ status }) => <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${SESSION_C[status]||'#7C3AED'}1f`, color:SESSION_C[status]||'#7C3AED' }}>{status}</span>

function useSessionForm(showToast) {
  const [programs, setPrograms] = useState([]); const [provs, setProvs] = useState([]); const [org, setOrg] = useState({ departments:[], designations:[] })
  useEffect(() => {
    hrApi.learning.programs.list({ status:'Active' }).then(r=>setPrograms(r.data||[])).catch(()=>{})
    hrApi.learning.providers.list({ status:'Active' }).then(r=>setProvs(r.data||[])).catch(()=>{})
    hrApi.organization.options().then(o=>setOrg({ departments:o.departments||[], designations:o.designations||[] })).catch(()=>{})
  }, [])
  const EMPTY = { training_program_id:'', trainer_name:'', mode:'Offline', venue:'', meeting_url:'', start_at:'', end_at:'', capacity:'', provider_id:'', department_id:'', designation_id:'', title:'', notes:'' }
  const submit = async (modal, setSaving, onDone) => {
    const f = modal.form
    if (!f.training_program_id) return showToast('Select a program','error')
    if (!f.trainer_name.trim()) return showToast('Trainer name is required','error')
    if (!f.start_at || !f.end_at) return showToast('Start and end time are required','error')
    if (new Date(f.end_at) <= new Date(f.start_at)) return showToast('End must be after start','error')
    setSaving(true)
    const payload = { ...f, start_at:f.start_at.replace('T',' '), end_at:f.end_at.replace('T',' ') }
    try { modal.editing ? await hrApi.learning.sessions.update(modal.editing, payload) : await hrApi.learning.sessions.create(payload); showToast(`Session ${modal.editing?'updated':'scheduled'}`); onDone() }
    catch (e) { showToast(e.response?.data?.message||'Save failed','error') } finally { setSaving(false) }
  }
  return { programs, provs, org, EMPTY, submit }
}

function SessionModal({ modal, setModal, ctx, onClose, onSaved }) {
  const [saving, setSaving] = useState(false)
  const setF = patch => setModal(m => ({ ...m, form:{ ...m.form, ...patch } }))
  return (
    <div className="modal-backdrop" onClick={onClose}><div className="modal-box" onClick={e=>e.stopPropagation()} style={{ maxWidth:720, width:'96%', maxHeight:'92vh', overflowY:'auto' }}>
      <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{modal.editing?'Edit Session':'Schedule Session'}</h2><button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
      <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
        <div className="col-span-2 md:col-span-3"><label className="label">Program *</label><select className="input-3d text-sm" value={modal.form.training_program_id} onChange={e=>setF({ training_program_id:e.target.value })} disabled={!!modal.editing}><option value="">Select…</option>{ctx.programs.map(p=><option key={p.id} value={p.id}>{p.program_name} ({p.program_code})</option>)}</select></div>
        <div className="col-span-2 md:col-span-3"><label className="label">Title</label><input className="input-3d text-sm" placeholder="Auto from program + date if blank" value={modal.form.title} onChange={e=>setF({ title:e.target.value })}/></div>
        <div><label className="label">Trainer *</label><input className="input-3d text-sm" value={modal.form.trainer_name} onChange={e=>setF({ trainer_name:e.target.value })}/></div>
        <div><label className="label">Mode</label><select className="input-3d text-sm" value={modal.form.mode} onChange={e=>setF({ mode:e.target.value })}>{MODES.map(x=><option key={x}>{x}</option>)}</select></div>
        <div><label className="label">Capacity</label><input type="number" min="1" className="input-3d text-sm" placeholder="From program" value={modal.form.capacity} onChange={e=>setF({ capacity:e.target.value })}/></div>
        <div><label className="label">Start *</label><input type="datetime-local" className="input-3d text-sm" value={modal.form.start_at} onChange={e=>setF({ start_at:e.target.value })}/></div>
        <div><label className="label">End *</label><input type="datetime-local" className="input-3d text-sm" value={modal.form.end_at} onChange={e=>setF({ end_at:e.target.value })}/></div>
        <div><label className="label">Provider</label><select className="input-3d text-sm" value={modal.form.provider_id} onChange={e=>setF({ provider_id:e.target.value })}><option value="">From program</option>{ctx.provs.map(p=><option key={p.id} value={p.id}>{p.name}</option>)}</select></div>
        <div><label className="label">Venue</label><input className="input-3d text-sm" value={modal.form.venue} onChange={e=>setF({ venue:e.target.value })}/></div>
        <div className="col-span-2"><label className="label">Meeting URL</label><input className="input-3d text-sm" value={modal.form.meeting_url} onChange={e=>setF({ meeting_url:e.target.value })}/></div>
        <div><label className="label">Department</label><select className="input-3d text-sm" value={modal.form.department_id} onChange={e=>setF({ department_id:e.target.value })}><option value="">—</option>{ctx.org.departments.map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select></div>
        <div><label className="label">Designation</label><select className="input-3d text-sm" value={modal.form.designation_id} onChange={e=>setF({ designation_id:e.target.value })}><option value="">—</option>{ctx.org.designations.map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select></div>
        <div className="col-span-2 md:col-span-3"><label className="label">Notes</label><textarea rows={2} className="input-3d text-sm resize-none" value={modal.form.notes} onChange={e=>setF({ notes:e.target.value })}/></div>
      </div>
      <div className="flex gap-3 pt-4"><button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={()=>ctx.submit(modal, setSaving, onSaved)} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Saving…':(modal.editing?'Save':'Schedule')}</button></div>
    </div></div>
  )
}

function Sessions({ showToast }) {
  const [data, setData] = useState({ data:[], stats:{ upcoming:0, today:0, ongoing:0, completed:0, cancelled:0 } })
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState(''); const [statusF, setStatusF] = useState('All'); const [progF, setProgF] = useState(''); const [modeF, setModeF] = useState('All')
  const [modal, setModal] = useState(null); const [view, setView] = useState(null)
  const ctx = useSessionForm(showToast)

  const load = useCallback(() => {
    setLoading(true)
    const params = {}
    if (statusF !== 'All') params.status = statusF
    if (progF) params.training_program_id = progF
    if (modeF !== 'All') params.mode = modeF
    if (search) params.search = search
    hrApi.learning.sessions.list(params).then(setData).catch(()=>showToast('Failed to load sessions','error')).finally(()=>setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusF, progF, modeF, search])
  useEffect(() => { load() }, [load])

  const openEdit = async (row) => {
    try { const f = await hrApi.learning.sessions.get(row.id)
      setModal({ editing:f.id, form:{ ...ctx.EMPTY, ...f, start_at:toLocalInput(f.start_at), end_at:toLocalInput(f.end_at), provider_id:f.provider_id||'', department_id:f.department_id||'', designation_id:f.designation_id||'', capacity:f.capacity??'' } })
    } catch { showToast('Failed to load session','error') }
  }
  const hasF = statusF!=='All'||progF||modeF!=='All'||search
  const rows = data.data

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-5 gap-3">{SESSION_KPIS(data.stats).map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}</div>

      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[180px]"><label className="label">Search</label><Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/><input className="input-3d pl-9 text-sm" placeholder="Program, title or trainer…" value={search} onChange={e=>setSearch(e.target.value)}/></div>
          <div className="min-w-[160px]"><label className="label">Program</label><select className="input-3d text-sm" value={progF} onChange={e=>setProgF(e.target.value)}><option value="">All</option>{ctx.programs.map(p=><option key={p.id} value={p.id}>{p.program_name}</option>)}</select></div>
          <div className="min-w-[120px]"><label className="label">Mode</label><select className="input-3d text-sm" value={modeF} onChange={e=>setModeF(e.target.value)}>{['All',...MODES].map(x=><option key={x}>{x}</option>)}</select></div>
          <div className="min-w-[140px]"><label className="label">Status</label><select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['All','Scheduled','Ongoing','Completed','Cancelled'].map(x=><option key={x}>{x}</option>)}</select></div>
          {hasF && <button onClick={()=>{ setStatusF('All'); setProgF(''); setModeF('All'); setSearch('') }} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
          <button onClick={()=>setModal({ editing:null, form:{...ctx.EMPTY} })} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white ml-auto" style={{ background:GRAD }}><Plus size={15}/> Schedule Session</button>
        </div>
      </div>

      {loading ? <HrLoading label="Loading sessions…" /> : rows.length===0 ? <HrEmpty icon={Presentation} title="No sessions scheduled" hint="Schedule a session from an active training program." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:960 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Session','Program','Trainer','When','Mode','Capacity','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{rows.map(r=>(
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                  <td className="px-3 py-2.5 font-bold" style={{ color:'var(--text-h)' }}>{r.title||r.program}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.program} <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.program_code}</span></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.trainer_name}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{fmtDT(r.start_at)}<div className="text-[10px]">→ {fmtTime(r.end_at)}</div></td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}>{r.mode}</span></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.capacity}</td>
                  <td className="px-3 py-2.5"><SessionPill status={r.status} /></td>
                  <td className="px-3 py-2.5"><div className="flex gap-1.5 justify-end">
                    <button title="View" onClick={()=>setView(r.id)} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Eye size={13}/></button>
                    {(r.status==='Scheduled'||r.status==='Ongoing') && <button title="Edit" onClick={()=>openEdit(r)} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Pencil size={13}/></button>}
                  </div></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}

      {modal && <SessionModal modal={modal} setModal={setModal} ctx={ctx} onClose={()=>setModal(null)} onSaved={()=>{ setModal(null); load() }} />}
      {view && <SessionDrawer id={view} onClose={()=>setView(null)} onChanged={load} onEdit={(id)=>{ setView(null); openEdit({ id }) }} showToast={showToast} />}
    </div>
  )
}

function SessionDrawer({ id, onClose, onChanged, onEdit, showToast }) {
  const [s, setS] = useState(null); const [loading, setLoading] = useState(true); const [busy, setBusy] = useState(false)
  const reload = useCallback(() => { setLoading(true); return hrApi.learning.sessions.get(id).then(setS).finally(()=>setLoading(false)) }, [id])
  useEffect(() => { reload() }, [reload])
  const act = async (status, ok) => { setBusy(true); try { await hrApi.learning.sessions.setStatus(id, status); showToast(ok); await reload(); onChanged() } catch (e) { showToast(e.response?.data?.message||'Action failed','error') } finally { setBusy(false) } }
  const Field = ({ l, v }) => <div><p className="label-caps mb-0.5">{l}</p><p className="text-sm font-semibold" style={{ color:'var(--text-h)' }}>{v||'—'}</p></div>

  return (
    <div className="fixed inset-0 z-[9998] flex justify-end" onClick={onClose}>
      <div className="absolute inset-0" style={{ background:'rgba(0,0,0,0.4)' }} />
      <div className="relative h-full overflow-y-auto animate-[slideIn_0.25s_ease]" onClick={e=>e.stopPropagation()} style={{ width:'min(480px,97vw)', background:'var(--bg-card,var(--bg-input))', borderLeft:'1px solid var(--border)', boxShadow:'-10px 0 40px rgba(0,0,0,0.3)' }}>
        <div className="sticky top-0 z-10 flex items-center justify-between px-5 py-4" style={{ background:'var(--bg-card,var(--bg-input))', borderBottom:'1px solid var(--border)' }}>
          <h2 className="font-black text-base flex items-center gap-2" style={{ color:'var(--text-h)' }}><Presentation size={16} style={{ color:'#a78bfa' }}/> Training Session</h2>
          <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>
        {loading || !s ? <div className="p-6"><HrLoading label="Loading…" /></div> : (
          <div className="p-5 space-y-5">
            <div className="flex items-center justify-between gap-3">
              <div><p className="font-black text-lg" style={{ color:'var(--text-h)' }}>{s.title||s.program}</p><p className="text-[11px]" style={{ color:'var(--text-muted)' }}>{s.program} · <span className="font-mono" style={{ color:'#a78bfa' }}>{s.program_code}</span></p></div>
              <SessionPill status={s.status} />
            </div>
            <div className="flex gap-3 flex-wrap">
              <span className="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 rounded-lg" style={{ background:'var(--bg-input)', color:'var(--text-h)' }}><Clock size={12}/> {fmtTime(s.start_at)}–{fmtTime(s.end_at)}</span>
              <span className="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 rounded-lg" style={{ background:'var(--bg-input)', color:'var(--text-h)' }}><Users size={12}/> {s.capacity} seats</span>
              <span className="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}>{s.mode}</span>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <Field l="Date" v={fmtDate(s.start_at)} />
              <Field l="Trainer" v={s.trainer_name} />
              <Field l="Category" v={s.category} />
              <Field l="Provider" v={s.provider} />
              <Field l="Department" v={s.department} />
              <Field l="Designation" v={s.designation} />
            </div>
            {s.venue && <div className="flex items-center gap-2 text-sm" style={{ color:'var(--text-h)' }}><MapPin size={14} style={{ color:'#a78bfa' }}/> {s.venue}</div>}
            {s.meeting_url && <a href={s.meeting_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-2 text-xs font-bold px-3 py-2 rounded-xl" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Video size={13}/> Join meeting</a>}
            {s.notes && <div><p className="label-caps mb-1">Notes</p><p className="text-sm" style={{ color:'var(--text-h)' }}>{s.notes}</p></div>}

            <div><p className="label-caps mb-2 flex items-center gap-1.5"><Clock size={12}/> Timeline</p>
              <div className="space-y-2.5">{(s.timeline||[]).map((t,i)=>(
                <div key={i} className="flex gap-3"><div className="mt-1.5 rounded-full flex-shrink-0" style={{ width:8, height:8, background:'#a78bfa' }}/><div><p className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{t.action}</p><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>{t.actor_name||'System'} · {t.created_at?new Date(t.created_at).toLocaleString():''}</p></div></div>
              ))}{(!s.timeline||!s.timeline.length) && <p className="text-xs" style={{ color:'var(--text-muted)' }}>No timeline yet.</p>}</div>
            </div>

            {(s.status==='Scheduled'||s.status==='Ongoing') && (
              <div className="flex gap-2 flex-wrap">
                {s.status==='Scheduled' && <button onClick={()=>act('Ongoing','Session started')} disabled={busy} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:'linear-gradient(135deg,#f59e0b,#d97706)' }}><PlayCircle size={14}/> Start</button>}
                <button onClick={()=>act('Completed','Session completed')} disabled={busy} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:'linear-gradient(135deg,#10b981,#059669)' }}><CheckCircle2 size={14}/> Complete</button>
                <button onClick={()=>act('Cancelled','Session cancelled')} disabled={busy} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:'linear-gradient(135deg,#f87171,#ef4444)' }}><XCircle size={14}/> Cancel</button>
                <button onClick={()=>onEdit(s.id)} className="w-full py-2.5 rounded-xl text-sm font-bold flex items-center justify-center gap-2" style={{ background:'var(--bg-input)', color:'var(--text-h)', border:'1px solid var(--border)' }}><Pencil size={14}/> Edit Session</button>
              </div>
            )}
            {(s.status==='Completed'||s.status==='Cancelled') && <p className="text-xs text-center py-2 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>This session is {s.status.toLowerCase()} and read-only.</p>}
          </div>
        )}
      </div>
    </div>
  )
}

/* ── Training Calendar (Phase 3) ── */
const WEEKDAYS = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']
function Calendar({ showToast }) {
  const now = new Date()
  const [ym, setYm] = useState({ year:now.getFullYear(), month:now.getMonth()+1 })
  const [data, setData] = useState(null); const [loading, setLoading] = useState(true)
  const [selected, setSelected] = useState(null); const [view, setView] = useState(null)

  const load = useCallback(() => {
    setLoading(true)
    hrApi.learning.sessions.calendar({ year:ym.year, month:ym.month }).then(d=>{ setData(d); setSelected(null) }).catch(()=>showToast('Failed to load calendar','error')).finally(()=>setLoading(false))
  }, [ym, showToast])
  useEffect(() => { load() }, [load])

  const shift = (n) => setYm(v => { let m = v.month + n, y = v.year; if (m<1){ m=12; y-- } if (m>12){ m=1; y++ } return { year:y, month:m } })
  const goToday = () => setYm({ year:now.getFullYear(), month:now.getMonth()+1 })

  const byDate = data?.by_date || {}
  const first = new Date(ym.year, ym.month-1, 1)
  const daysInMonth = new Date(ym.year, ym.month, 0).getDate()
  const startPad = first.getDay()
  const cells = [...Array(startPad).fill(null), ...Array.from({ length:daysInMonth }, (_,i)=>i+1)]
  const todayStr = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}`
  const dateStr = (d) => `${ym.year}-${String(ym.month).padStart(2,'0')}-${String(d).padStart(2,'0')}`
  const selectedSessions = selected ? (byDate[selected]||[]) : (data?.sessions||[])

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-5 gap-3">{SESSION_KPIS(data?.stats||{}).map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v ?? 0}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}</div>

      <div className="card-3d" style={{ padding:'18px' }}>
        <div className="flex items-center justify-between mb-4 flex-wrap gap-2">
          <div className="flex items-center gap-2">
            <button onClick={()=>shift(-1)} className="p-2 rounded-xl" style={{ background:'var(--bg-input)', border:'1px solid var(--border)', color:'var(--text-muted)' }}><ChevronLeft size={16}/></button>
            <h3 className="font-black text-base" style={{ color:'var(--text-h)', minWidth:130, textAlign:'center' }}>{data?.month_label||'…'}</h3>
            <button onClick={()=>shift(1)} className="p-2 rounded-xl" style={{ background:'var(--bg-input)', border:'1px solid var(--border)', color:'var(--text-muted)' }}><ChevronRight size={16}/></button>
          </div>
          <button onClick={goToday} className="px-3 py-2 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Today</button>
        </div>
        {loading ? <HrLoading label="Loading calendar…" /> : (
          <div className="grid grid-cols-7 gap-1.5">
            {WEEKDAYS.map(d=><div key={d} className="text-center text-[10px] font-bold pb-1" style={{ color:'var(--text-muted)' }}>{d}</div>)}
            {cells.map((d,i)=>{
              if (!d) return <div key={`p${i}`} />
              const ds = dateStr(d); const list = byDate[ds]||[]
              const isToday = ds===todayStr; const isSel = ds===selected
              return (
                <button key={ds} onClick={()=>setSelected(isSel?null:ds)} className="rounded-xl p-1.5 text-left transition-all" style={{ minHeight:64, background:isSel?'rgba(124,58,237,0.12)':'var(--bg-input)', border:isToday?'1.5px solid #7C3AED':'1px solid var(--border)' }}>
                  <div className="text-[11px] font-bold mb-1" style={{ color:isToday?'#a78bfa':'var(--text-h)' }}>{d}</div>
                  <div className="flex flex-wrap gap-1">{list.slice(0,4).map(s=><span key={s.id} className="w-1.5 h-1.5 rounded-full" style={{ background:SESSION_C[s.status]||'#7C3AED' }}/>)}</div>
                  {list.length>0 && <div className="text-[9px] mt-0.5" style={{ color:'var(--text-muted)' }}>{list.length} session{list.length>1?'s':''}</div>}
                </button>
              )
            })}
          </div>
        )}
        <div className="flex gap-4 mt-3 flex-wrap">{Object.entries(SESSION_C).map(([l,c])=><span key={l} className="flex items-center gap-1.5 text-[10px]" style={{ color:'var(--text-muted)' }}><span className="w-2.5 h-2.5 rounded-full" style={{ background:c }}/>{l}</span>)}</div>
      </div>

      <div className="card-3d" style={{ padding:'16px' }}>
        <p className="text-[11px] font-bold uppercase mb-3" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>{selected?`Sessions on ${fmtDate(selected)}`:`All sessions · ${data?.month_label||''}`}</p>
        {selectedSessions.length===0 ? <HrEmpty icon={CalendarRange} title="No sessions" hint={selected?'Nothing scheduled for this day.':'No sessions this month.'} />
          : <div className="space-y-2">{selectedSessions.map(s=>(
              <button key={s.id} onClick={()=>setView(s.id)} className="w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-left" style={{ background:'var(--bg-input)' }}>
                <div className="flex items-center gap-3">
                  <span className="w-2.5 h-2.5 rounded-full flex-shrink-0" style={{ background:SESSION_C[s.status]||'#7C3AED' }}/>
                  <div><p className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{s.title||s.program}</p><p className="text-[10px]" style={{ color:'var(--text-muted)' }}><User size={9} className="inline"/> {s.trainer_name} · {fmtDT(s.start_at)}</p></div>
                </div>
                <SessionPill status={s.status} />
              </button>
            ))}</div>}
      </div>

      {view && <SessionDrawer id={view} onClose={()=>setView(null)} onChanged={load} onEdit={()=>{}} showToast={showToast} />}
    </div>
  )
}

/* ── Employee Training Assignment (Phase 4) ── */
const AssignPill = ({ status }) => <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${ASSIGN_C[status]||'#7C3AED'}1f`, color:ASSIGN_C[status]||'#7C3AED' }}>{status}</span>
const ProgressBar = ({ pct }) => <div className="flex items-center gap-2"><div className="flex-1 h-1.5 rounded-full min-w-[50px]" style={{ background:'var(--bg-input)' }}><div className="h-full rounded-full" style={{ width:`${Math.min(100,pct||0)}%`, background:GRAD }}/></div><span className="text-[10px] font-bold" style={{ color:'#7C3AED' }}>{pct||0}%</span></div>

function Assignments({ showToast }) {
  const [data, setData] = useState({ data:[], stats:{ total:0, assigned:0, in_progress:0, completed:0, cancelled:0, completion_pct:0 } })
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState(''); const [statusF, setStatusF] = useState('All'); const [deptF, setDeptF] = useState(''); const [progF, setProgF] = useState('')
  const [programs, setPrograms] = useState([]); const [employees, setEmployees] = useState([]); const [org, setOrg] = useState({ departments:[] })
  const [modal, setModal] = useState(null); const [view, setView] = useState(null)

  const load = useCallback(() => {
    setLoading(true)
    const params = {}
    if (statusF !== 'All') params.status = statusF
    if (deptF) params.department = deptF
    if (progF) params.training_program_id = progF
    if (search) params.search = search
    hrApi.learning.assignments.list(params).then(setData).catch(()=>showToast('Failed to load assignments','error')).finally(()=>setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusF, deptF, progF, search])
  useEffect(() => { load() }, [load])
  useEffect(() => {
    hrApi.learning.programs.list({ status:'Active' }).then(r=>setPrograms(r.data||[])).catch(()=>{})
    hrApi.employees.list({ per_page:500 }).then(r=>setEmployees(Array.isArray(r)?r:[])).catch(()=>{})
    hrApi.organization.options().then(o=>setOrg({ departments:o.departments||[] })).catch(()=>{})
  }, [])

  const departments = org.departments.length ? org.departments.map(d=>d.name) : [...new Set(data.data.map(r=>r.department).filter(Boolean))]
  const s = data.stats
  const KPIS = [
    { l:'Total Assigned', v:s.total, c:'#7C3AED' }, { l:'In Progress', v:s.in_progress, c:'#f59e0b' },
    { l:'Completed', v:s.completed, c:'#10b981' }, { l:'Cancelled', v:s.cancelled, c:'#f87171' }, { l:'Completion %', v:`${s.completion_pct}%`, c:'#3b82f6' },
  ]
  const hasF = statusF!=='All'||deptF||progF||search
  const rows = data.data

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-5 gap-3">{KPIS.map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}</div>

      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[180px]"><label className="label">Search</label><Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/><input className="input-3d pl-9 text-sm" placeholder="Employee name or code…" value={search} onChange={e=>setSearch(e.target.value)}/></div>
          <div className="min-w-[150px]"><label className="label">Department</label><select className="input-3d text-sm" value={deptF} onChange={e=>setDeptF(e.target.value)}><option value="">All</option>{departments.map(d=><option key={d} value={d}>{d}</option>)}</select></div>
          <div className="min-w-[160px]"><label className="label">Program</label><select className="input-3d text-sm" value={progF} onChange={e=>setProgF(e.target.value)}><option value="">All</option>{programs.map(p=><option key={p.id} value={p.id}>{p.program_name}</option>)}</select></div>
          <div className="min-w-[140px]"><label className="label">Status</label><select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['All','Assigned','In Progress','Completed','Cancelled'].map(x=><option key={x}>{x}</option>)}</select></div>
          {hasF && <button onClick={()=>{ setStatusF('All'); setDeptF(''); setProgF(''); setSearch('') }} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
          <button onClick={()=>setModal(true)} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white ml-auto" style={{ background:GRAD }}><UserPlus size={15}/> Assign Employee</button>
        </div>
      </div>

      {loading ? <HrLoading label="Loading assignments…" /> : rows.length===0 ? <HrEmpty icon={UserPlus} title="No training assignments yet" hint="Assign an employee to a scheduled session." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:1000 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Program','Session','Due','Progress','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{rows.map(r=>(
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                  <td className="px-3 py-2.5"><div className="font-bold" style={{ color:'var(--text-h)' }}>{r.employee_name}</div><div className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code} · {r.department||'—'}</div></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-h)' }}>{r.program} <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.program_code}</span>
                    {/* #23 — a repeat assignment must not read as a first attempt.
                        Uses the attempt_number / is_retraining the API already sends. */}
                    {r.is_retraining && (
                      <span title={r.retraining_reason || 'Repeat of an earlier assignment'}
                        className="ml-1.5 text-[9px] font-bold px-1.5 py-0.5 rounded-md whitespace-nowrap"
                        style={{ background:'rgba(249,115,22,0.14)', color:'#f97316' }}>
                        Retraining #{r.attempt_number}
                      </span>
                    )}
                  </td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.session_title||'—'}<div className="text-[10px]">{r.trainer_name} · {fmtDate(r.session_start)}</div></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{fmtDate(r.due_date)}</td>
                  <td className="px-3 py-2.5" style={{ minWidth:130 }}><ProgressBar pct={r.completion_percentage} /></td>
                  <td className="px-3 py-2.5"><AssignPill status={r.status} /></td>
                  <td className="px-3 py-2.5"><div className="flex gap-1.5 justify-end"><button title="View" onClick={()=>setView(r.id)} className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold text-white" style={{ background:GRAD }}><Eye size={13}/> Manage</button></div></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}

      {modal && <AssignModal programs={programs} employees={employees} onClose={()=>setModal(false)} onSaved={()=>{ setModal(false); load() }} showToast={showToast} />}
      {view && <AssignmentDrawer id={view} onClose={()=>setView(null)} onChanged={load} showToast={showToast} />}
    </div>
  )
}

function AssignModal({ programs, employees, onClose, onSaved, showToast }) {
  const [form, setForm] = useState({ employee_id:'', training_program_id:'', training_session_id:'', due_date:'', remarks:'' })
  const [sessions, setSessions] = useState([]); const [saving, setSaving] = useState(false)
  const setF = patch => setForm(f => ({ ...f, ...patch }))

  useEffect(() => {
    if (!form.training_program_id) { setSessions([]); return }
    hrApi.learning.sessions.list({ training_program_id:form.training_program_id }).then(r=>{
      setSessions((r.data||[]).filter(s=>s.status==='Scheduled'||s.status==='Ongoing'))
    }).catch(()=>setSessions([]))
  }, [form.training_program_id])

  const save = async () => {
    if (!form.employee_id) return showToast('Select an employee','error')
    if (!form.training_session_id) return showToast('Select an active session','error')
    setSaving(true)
    try { await hrApi.learning.assignments.assign({ employee_id:form.employee_id, training_session_id:form.training_session_id, due_date:form.due_date||undefined, remarks:form.remarks||undefined }); showToast('Employee assigned'); onSaved() }
    catch (e) { showToast(e.response?.data?.message||'Assignment failed','error') } finally { setSaving(false) }
  }

  return (
    <div className="modal-backdrop" onClick={onClose}><div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()} style={{ maxHeight:'92vh', overflowY:'auto' }}>
      <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Assign Employee</h2><button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
      <div className="space-y-3">
        <div><label className="label">Employee *</label><select className="input-3d text-sm" value={form.employee_id} onChange={e=>setF({ employee_id:e.target.value })}><option value="">Select…</option>{employees.map(e=><option key={e.id} value={e.id}>{e.name} ({e.employee_code})</option>)}</select></div>
        <div><label className="label">Program *</label><select className="input-3d text-sm" value={form.training_program_id} onChange={e=>setF({ training_program_id:e.target.value, training_session_id:'' })}><option value="">Select…</option>{programs.map(p=><option key={p.id} value={p.id}>{p.program_name} ({p.program_code})</option>)}</select></div>
        <div><label className="label">Session * <span className="normal-case font-normal" style={{ color:'var(--text-muted)' }}>(active only)</span></label>
          <select className="input-3d text-sm" value={form.training_session_id} onChange={e=>setF({ training_session_id:e.target.value })} disabled={!form.training_program_id}>
            <option value="">{form.training_program_id?(sessions.length?'Select…':'No active sessions'):'Pick a program first'}</option>
            {sessions.map(s=><option key={s.id} value={s.id}>{(s.title||s.program)} · {fmtDT(s.start_at)} · {s.mode} · cap {s.capacity}</option>)}
          </select>
        </div>
        <div><label className="label">Due Date</label><input type="date" className="input-3d text-sm" value={form.due_date} onChange={e=>setF({ due_date:e.target.value })}/><p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>Defaults to the session end date.</p></div>
        <div><label className="label">Remarks</label><textarea rows={2} className="input-3d text-sm resize-none" value={form.remarks} onChange={e=>setF({ remarks:e.target.value })}/></div>
      </div>
      <div className="flex gap-3 pt-4"><button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Assigning…':'Assign'}</button></div>
    </div></div>
  )
}

function AssignmentDrawer({ id, onClose, onChanged, showToast }) {
  const [a, setA] = useState(null); const [loading, setLoading] = useState(true); const [busy, setBusy] = useState(false)
  const reload = useCallback(() => { setLoading(true); return hrApi.learning.assignments.get(id).then(setA).finally(()=>setLoading(false)) }, [id])
  useEffect(() => { reload() }, [reload])
  const act = async (fn, ok) => { setBusy(true); try { await fn(); showToast(ok); await reload(); onChanged() } catch (e) { showToast(e.response?.data?.message||'Action failed','error') } finally { setBusy(false) } }
  const Field = ({ l, v }) => <div><p className="label-caps mb-0.5">{l}</p><p className="text-sm font-semibold" style={{ color:'var(--text-h)' }}>{v||'—'}</p></div>

  return (
    <div className="fixed inset-0 z-[9998] flex justify-end" onClick={onClose}>
      <div className="absolute inset-0" style={{ background:'rgba(0,0,0,0.4)' }} />
      <div className="relative h-full overflow-y-auto animate-[slideIn_0.25s_ease]" onClick={e=>e.stopPropagation()} style={{ width:'min(480px,97vw)', background:'var(--bg-card,var(--bg-input))', borderLeft:'1px solid var(--border)', boxShadow:'-10px 0 40px rgba(0,0,0,0.3)' }}>
        <div className="sticky top-0 z-10 flex items-center justify-between px-5 py-4" style={{ background:'var(--bg-card,var(--bg-input))', borderBottom:'1px solid var(--border)' }}>
          <h2 className="font-black text-base flex items-center gap-2" style={{ color:'var(--text-h)' }}><UserPlus size={16} style={{ color:'#a78bfa' }}/> Training Assignment</h2>
          <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>
        {loading || !a ? <div className="p-6"><HrLoading label="Loading…" /></div> : (
          <div className="p-5 space-y-5">
            <div className="flex items-center justify-between gap-3">
              <div><p className="font-black text-lg" style={{ color:'var(--text-h)' }}>{a.employee_name}</p><p className="text-[11px] font-mono" style={{ color:'#a78bfa' }}>{a.employee_code} · {a.department||'—'}</p></div>
              <AssignPill status={a.status} />
            </div>
            <div><p className="label-caps mb-1">Progress</p><ProgressBar pct={a.completion_percentage} /></div>
            <div className="grid grid-cols-2 gap-4">
              <Field l="Program" v={a.program} />
              <Field l="Session" v={a.session_title} />
              <Field l="Trainer" v={a.trainer_name} />
              <Field l="Provider" v={a.provider} />
              <Field l="Mode" v={a.mode} />
              <Field l="Session Date" v={fmtDate(a.session_start)} />
              <Field l="Assigned" v={fmtDate(a.assigned_at)} />
              <Field l="Due Date" v={fmtDate(a.due_date)} />
            </div>
            {a.remarks && <div><p className="label-caps mb-1">Remarks</p><p className="text-sm" style={{ color:'var(--text-h)' }}>{a.remarks}</p></div>}

            <div><p className="label-caps mb-2 flex items-center gap-1.5"><Clock size={12}/> Timeline</p>
              <div className="space-y-2.5">{(a.timeline||[]).map((t,i)=>(
                <div key={i} className="flex gap-3"><div className="mt-1.5 rounded-full flex-shrink-0" style={{ width:8, height:8, background:'#a78bfa' }}/><div><p className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{t.action}</p><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>{t.actor_name||'System'} · {t.created_at?new Date(t.created_at).toLocaleString():''}</p>{t.comment && <p className="text-[11px] mt-0.5" style={{ color:'var(--text-muted)' }}>“{t.comment}”</p>}</div></div>
              ))}{(!a.timeline||!a.timeline.length) && <p className="text-xs" style={{ color:'var(--text-muted)' }}>No timeline yet.</p>}</div>
            </div>

            {/* #25 — the assigned quiz. The assignment already knows the employee
                and the programme, so neither has to be picked again. */}
            <AssignmentQuizzes assignment={a} showToast={showToast} />

            {(a.status==='Assigned'||a.status==='In Progress') && (
              <div className="flex gap-2 flex-wrap">
                {a.status==='Assigned' && <button onClick={()=>act(()=>hrApi.learning.assignments.start(id, { completion_percentage:10 }), 'Training started')} disabled={busy} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:'linear-gradient(135deg,#f59e0b,#d97706)' }}><PlayCircle size={14}/> Start</button>}
                <button onClick={()=>act(()=>hrApi.learning.assignments.complete(id), 'Training completed')} disabled={busy} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:'linear-gradient(135deg,#10b981,#059669)' }}><CheckCircle2 size={14}/> Complete</button>
                <button onClick={()=>act(()=>hrApi.learning.assignments.cancel(id), 'Training cancelled')} disabled={busy} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:'linear-gradient(135deg,#f87171,#ef4444)' }}><XCircle size={14}/> Cancel</button>
              </div>
            )}
            {(a.status==='Completed'||a.status==='Cancelled') && <p className="text-xs text-center py-2 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>This assignment is {a.status.toLowerCase()} and read-only.</p>}
          </div>
        )}
      </div>
    </div>
  )
}

/* ── shared KPI row + assignment picker for record modals ── */
function KpiRow({ items }) {
  return <div className="grid grid-cols-2 md:grid-cols-5 gap-3">{items.map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}</div>
}
function useAssignments() {
  const [rows, setRows] = useState([])
  useEffect(() => { hrApi.learning.assignments.list().then(r=>setRows(r.data||[])).catch(()=>{}) }, [])
  return rows
}

/* ── Training Attendance (Phase 5) ── */
const ATT_C = { Present:'#10b981', Absent:'#f87171' }
function Attendance({ showToast }) {
  const [data, setData] = useState({ data:[], stats:{ assigned:0, present:0, absent:0, pending:0, attendance_pct:0 } })
  const [loading, setLoading] = useState(true)
  const [sessions, setSessions] = useState([]); const [sessF, setSessF] = useState(''); const [statusF, setStatusF] = useState('All')
  const [roster, setRoster] = useState(null); const [marks, setMarks] = useState({}); const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    const params = {}; if (sessF) params.training_session_id = sessF; if (statusF !== 'All') params.attendance_status = statusF
    hrApi.learning.attendance.list(params).then(setData).catch(()=>showToast('Failed to load attendance','error')).finally(()=>setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sessF, statusF])
  useEffect(() => { load() }, [load])
  useEffect(() => { hrApi.learning.sessions.list().then(r=>setSessions((r.data||[]).filter(s=>s.status!=='Cancelled'))).catch(()=>{}) }, [])

  const openRoster = async (sid) => {
    if (!sid) { setRoster(null); return }
    try { const r = await hrApi.learning.attendance.roster(sid)
      setRoster(r); setMarks(Object.fromEntries(r.roster.map(x=>[x.employee_training_id, x.attendance_status||'Present'])))
    } catch { showToast('Failed to load roster','error') }
  }
  const saveBulk = async () => {
    if (!roster) return
    setSaving(true)
    const records = roster.roster.map(x=>({ employee_training_id:x.employee_training_id, attendance_status:marks[x.employee_training_id]||'Present' }))
    try { await hrApi.learning.attendance.mark({ training_session_id:roster.session.id, records }); showToast('Attendance saved'); setRoster(null); load() }
    catch (e) { showToast(e.response?.data?.message||'Save failed','error') } finally { setSaving(false) }
  }
  const s = data.stats
  const KPIS = [{l:'Assigned',v:s.assigned,c:'#7C3AED'},{l:'Present',v:s.present,c:'#10b981'},{l:'Absent',v:s.absent,c:'#f87171'},{l:'Pending',v:s.pending,c:'#f59e0b'},{l:'Attendance %',v:`${s.attendance_pct}%`,c:'#3b82f6'}]

  return (
    <div className="space-y-4">
      <KpiRow items={KPIS} />
      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="min-w-[220px] flex-1"><label className="label">Bulk mark — pick a session</label><select className="input-3d text-sm" value={roster?.session?.id||''} onChange={e=>openRoster(e.target.value)}><option value="">Select a session…</option>{sessions.map(x=><option key={x.id} value={x.id}>{(x.title||x.program)} · {fmtDT(x.start_at)}</option>)}</select></div>
          <div className="min-w-[180px]"><label className="label">Filter by session</label><select className="input-3d text-sm" value={sessF} onChange={e=>setSessF(e.target.value)}><option value="">All</option>{sessions.map(x=><option key={x.id} value={x.id}>{x.title||x.program}</option>)}</select></div>
          <div className="min-w-[130px]"><label className="label">Status</label><select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['All','Present','Absent'].map(x=><option key={x}>{x}</option>)}</select></div>
        </div>
      </div>

      {roster && (
        <div className="card-3d" style={{ padding:'16px' }}>
          <div className="flex items-center justify-between mb-3"><p className="font-black text-sm" style={{ color:'var(--text-h)' }}>Roster · {roster.session.title}</p><button onClick={()=>setRoster(null)} style={{ color:'var(--text-muted)' }}><X size={16}/></button></div>
          {roster.roster.length===0 ? <p className="text-xs" style={{ color:'var(--text-muted)' }}>No assigned employees on this session.</p>
            : <div className="space-y-2">{roster.roster.map(x=>(
                <div key={x.employee_training_id} className="flex items-center justify-between px-3 py-2 rounded-xl flex-wrap gap-2" style={{ background:'var(--bg-input)' }}>
                  <div><span className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{x.employee_name}</span> <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{x.employee_code}</span></div>
                  <div className="flex gap-1.5">{['Present','Absent'].map(st=>(
                    <button key={st} onClick={()=>setMarks(m=>({...m,[x.employee_training_id]:st}))} className="px-3 py-1 rounded-lg text-[11px] font-bold" style={marks[x.employee_training_id]===st?{background:ATT_C[st],color:'#fff'}:{background:'var(--bg-card,var(--bg-input))',color:'var(--text-muted)',border:'1px solid var(--border)'}}>{st}</button>
                  ))}</div>
                </div>
              ))}<button onClick={saveBulk} disabled={saving} className="w-full mt-2 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:GRAD, opacity:saving?0.7:1 }}><Check size={15}/> {saving?'Saving…':'Save Attendance'}</button></div>}
        </div>
      )}

      {loading ? <HrLoading label="Loading attendance…" /> : data.data.length===0 ? <HrEmpty icon={UserCheck} title="No attendance yet" hint="Pick a session above to mark attendance for its assigned employees." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:820 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Program','Session','Status','Remarks','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{data.data.map(r=>(
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                  <td className="px-3 py-2.5"><span className="font-bold" style={{ color:'var(--text-h)' }}>{r.employee_name}</span> <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code}</span></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.program}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.session_title}</td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${ATT_C[r.attendance_status]}1f`, color:ATT_C[r.attendance_status] }}>{r.attendance_status}</span></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.remarks||'—'}</td>
                  <td className="px-3 py-2.5 text-right"><button onClick={()=>hrApi.learning.attendance.update(r.id,{ attendance_status:r.attendance_status==='Present'?'Absent':'Present' }).then(load).catch(e=>showToast(e.response?.data?.message||'Failed','error'))} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }} title="Toggle"><Pencil size={13}/></button></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}
    </div>
  )
}

/* ── Marks modal shared by Assessment + Quiz ── */
function MarksModal({ kind, assignments, editing, onClose, onSaved, showToast }) {
  const isAssess = kind === 'assessment'
  const [form, setForm] = useState(editing || { employee_training_id:'', name:'', total_marks:100, obtained_marks:0, passing_marks:40 })
  const [saving, setSaving] = useState(false)
  const setF = p => setForm(f => ({ ...f, ...p }))
  const pct = Number(form.total_marks) > 0 ? Math.round(Number(form.obtained_marks)/Number(form.total_marks)*100) : 0
  const result = isAssess ? (Number(form.obtained_marks) >= Number(form.passing_marks) ? 'Pass' : 'Fail') : (pct >= 50 ? 'Likely Pass' : 'Likely Fail')

  const save = async () => {
    if (!editing && !form.employee_training_id) return showToast('Select an assignment','error')
    if (Number(form.obtained_marks) > Number(form.total_marks)) return showToast('Obtained cannot exceed total','error')
    setSaving(true)
    const payload = isAssess
      ? { employee_training_id:form.employee_training_id, assessment_name:form.name||'Assessment', total_marks:form.total_marks, obtained_marks:form.obtained_marks, passing_marks:form.passing_marks }
      : { employee_training_id:form.employee_training_id, quiz_name:form.name||'Quiz', total_marks:form.total_marks, obtained_marks:form.obtained_marks }
    const api = isAssess ? hrApi.learning.assessments : hrApi.learning.quizzes
    try { editing ? await api.update(editing.id, payload) : await api.create(payload); showToast(`${isAssess?'Assessment':'Quiz'} saved`); onSaved() }
    catch (e) { showToast(e.response?.data?.message||'Save failed','error') } finally { setSaving(false) }
  }
  return (
    <div className="modal-backdrop" onClick={onClose}><div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()}>
      <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{editing?'Edit':'Add'} {isAssess?'Assessment':'Quiz'}</h2><button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
      <div className="space-y-3">
        {!editing && <div><label className="label">Assignment *</label><select className="input-3d text-sm" value={form.employee_training_id} onChange={e=>setF({ employee_training_id:e.target.value })}><option value="">Select employee + program…</option>{assignments.map(a=><option key={a.id} value={a.id}>{a.employee_name} · {a.program} ({a.status})</option>)}</select></div>}
        <div><label className="label">{isAssess?'Assessment':'Quiz'} Name</label><input className="input-3d text-sm" value={form.name} onChange={e=>setF({ name:e.target.value })}/></div>
        <div className="grid grid-cols-3 gap-3">
          <div><label className="label">Total *</label><input type="number" min="1" className="input-3d text-sm" value={form.total_marks} onChange={e=>setF({ total_marks:e.target.value })}/></div>
          <div><label className="label">Obtained *</label><input type="number" min="0" className="input-3d text-sm" value={form.obtained_marks} onChange={e=>setF({ obtained_marks:e.target.value })}/></div>
          {isAssess && <div><label className="label">Passing</label><input type="number" min="0" className="input-3d text-sm" value={form.passing_marks} onChange={e=>setF({ passing_marks:e.target.value })}/></div>}
        </div>
        <div className="flex items-center gap-3 px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)' }}><Percent size={14} style={{ color:'#a78bfa' }}/><span className="text-sm font-bold" style={{ color:'var(--text-h)' }}>{pct}%</span><span className="text-[11px] font-bold px-2 py-0.5 rounded-lg" style={{ background:result.includes('Pass')?'rgba(16,185,129,0.14)':'rgba(239,68,68,0.12)', color:result.includes('Pass')?'#10b981':'#f87171' }}>{result}</span></div>
      </div>
      <div className="flex gap-3 pt-4"><button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Saving…':'Save'}</button></div>
    </div></div>
  )
}

/* ── Assessment (Phase 5) ── */
function Assessments({ showToast }) {
  const [data, setData] = useState({ data:[], stats:{ passed:0, failed:0, total:0, avg_pct:0 } })
  const [loading, setLoading] = useState(true); const [resF, setResF] = useState('All'); const [modal, setModal] = useState(null)
  const assignments = useAssignments()
  const load = useCallback(() => { setLoading(true); const p={}; if(resF!=='All')p.result=resF; hrApi.learning.assessments.list(p).then(setData).catch(()=>showToast('Failed','error')).finally(()=>setLoading(false)) // eslint-disable-line
  }, [resF])
  useEffect(() => { load() }, [load])
  const s = data.stats
  const KPIS = [{l:'Passed',v:s.passed,c:'#10b981'},{l:'Failed',v:s.failed,c:'#f87171'},{l:'Assessed',v:s.total,c:'#7C3AED'},{l:'Average %',v:`${s.avg_pct}%`,c:'#3b82f6'}]
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">{KPIS.map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}</div>
      <div className="card-3d" style={{ padding:'16px' }}><div className="flex gap-3 items-end"><div className="min-w-[140px]"><label className="label">Result</label><select className="input-3d text-sm" value={resF} onChange={e=>setResF(e.target.value)}>{['All','Pass','Fail'].map(x=><option key={x}>{x}</option>)}</select></div><button onClick={()=>setModal({})} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white ml-auto" style={{ background:GRAD }}><Plus size={15}/> Add Assessment</button></div></div>
      {loading ? <HrLoading label="Loading…" /> : data.data.length===0 ? <HrEmpty icon={ClipboardCheck} title="No assessments yet" hint="Add an assessment for an employee who has attendance recorded." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}><table className="w-full text-sm" style={{ minWidth:860 }}>
            <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Program','Assessment','Marks','%','Result','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
            <tbody>{data.data.map(r=>(
              <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                <td className="px-3 py-2.5"><span className="font-bold" style={{ color:'var(--text-h)' }}>{r.employee_name}</span> <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code}</span></td>
                <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.program}</td>
                <td className="px-3 py-2.5" style={{ color:'var(--text-h)' }}>{r.assessment_name}</td>
                <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.obtained_marks}/{r.total_marks}</td>
                <td className="px-3 py-2.5 font-bold" style={{ color:'#7C3AED' }}>{r.percentage}%</td>
                <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:r.result==='Pass'?'rgba(16,185,129,0.14)':'rgba(239,68,68,0.12)', color:r.result==='Pass'?'#10b981':'#f87171' }}>{r.result}</span></td>
                <td className="px-3 py-2.5 text-right"><button onClick={()=>setModal({ id:r.id, employee_training_id:r.employee_training_id, name:r.assessment_name, total_marks:r.total_marks, obtained_marks:r.obtained_marks, passing_marks:r.passing_marks })} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Pencil size={13}/></button></td>
              </tr>
            ))}</tbody></table></div>}
      {modal && <MarksModal kind="assessment" assignments={assignments} editing={modal.id?modal:null} onClose={()=>setModal(null)} onSaved={()=>{ setModal(null); load() }} showToast={showToast} />}
    </div>
  )
}

/* ── Quiz (Phase 5) ── */
function Quizzes({ showToast }) {
  const [data, setData] = useState({ data:[], stats:{ completed:0, passed:0, failed:0, avg_pct:0 } })
  const [loading, setLoading] = useState(true); const [pf, setPf] = useState('All'); const [modal, setModal] = useState(null)
  const assignments = useAssignments()
  const load = useCallback(() => { setLoading(true); const p={}; if(pf!=='All')p.passed=pf; hrApi.learning.quizzes.list(p).then(setData).catch(()=>showToast('Failed','error')).finally(()=>setLoading(false)) // eslint-disable-line
  }, [pf])
  useEffect(() => { load() }, [load])
  const s = data.stats
  const KPIS = [{l:'Completed',v:s.completed,c:'#7C3AED'},{l:'Passed',v:s.passed,c:'#10b981'},{l:'Failed',v:s.failed,c:'#f87171'},{l:'Average %',v:`${s.avg_pct}%`,c:'#3b82f6'}]
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">{KPIS.map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}</div>
      <div className="card-3d" style={{ padding:'16px' }}><div className="flex gap-3 items-end"><div className="min-w-[140px]"><label className="label">Result</label><select className="input-3d text-sm" value={pf} onChange={e=>setPf(e.target.value)}>{['All','Passed','Failed'].map(x=><option key={x}>{x}</option>)}</select></div><button onClick={()=>setModal({})} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white ml-auto" style={{ background:GRAD }}><Plus size={15}/> Add Quiz</button></div></div>
      {loading ? <HrLoading label="Loading…" /> : data.data.length===0 ? <HrEmpty icon={FileQuestion} title="No quizzes yet" hint="Record a quiz score for an assigned employee." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}><table className="w-full text-sm" style={{ minWidth:820 }}>
            <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Program','Quiz','Marks','%','Result','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
            <tbody>{data.data.map(r=>(
              <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                <td className="px-3 py-2.5"><span className="font-bold" style={{ color:'var(--text-h)' }}>{r.employee_name}</span> <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code}</span></td>
                <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.program}</td>
                <td className="px-3 py-2.5" style={{ color:'var(--text-h)' }}>{r.quiz_name}</td>
                <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.obtained_marks}/{r.total_marks}</td>
                <td className="px-3 py-2.5 font-bold" style={{ color:'#7C3AED' }}>{r.percentage}%</td>
                <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:r.passed?'rgba(16,185,129,0.14)':'rgba(239,68,68,0.12)', color:r.passed?'#10b981':'#f87171' }}>{r.passed?'Passed':'Failed'}</span></td>
                <td className="px-3 py-2.5 text-right"><button onClick={()=>setModal({ id:r.id, employee_training_id:r.employee_training_id, name:r.quiz_name, total_marks:r.total_marks, obtained_marks:r.obtained_marks })} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Pencil size={13}/></button></td>
              </tr>
            ))}</tbody></table></div>}
      {modal && <MarksModal kind="quiz" assignments={assignments} editing={modal.id?modal:null} onClose={()=>setModal(null)} onSaved={()=>{ setModal(null); load() }} showToast={showToast} />}
    </div>
  )
}

/* ── Certificates (Phase 6) ── */
const CERT_C = { Issued:'#10b981', Expired:'#f87171' }
function Certificates({ showToast }) {
  const [data, setData] = useState({ data:[], stats:{ issued:0, expired:0, expiring_soon:0, pending:0 } })
  const [loading, setLoading] = useState(true); const [statusF, setStatusF] = useState('All'); const [modal, setModal] = useState(null)
  const [completedNoCert, setCompletedNoCert] = useState([])
  const load = useCallback(() => { setLoading(true); const p={}; if(statusF!=='All')p.status=statusF; hrApi.learning.certificates.list(p).then(setData).catch(()=>showToast('Failed','error')).finally(()=>setLoading(false)) // eslint-disable-line
  }, [statusF])
  useEffect(() => { load() }, [load])
  useEffect(() => { hrApi.learning.completion.list().then(r=>setCompletedNoCert((r.data||[]).filter(x=>x.assignment_status==='Completed' && !x.certified))).catch(()=>{}) }, [modal])
  const s = data.stats
  const KPIS = [{l:'Issued',v:s.issued,c:'#10b981'},{l:'Expired',v:s.expired,c:'#f87171'},{l:'Expiring Soon',v:s.expiring_soon,c:'#f59e0b'},{l:'Pending',v:s.pending,c:'#7C3AED'}]
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">{KPIS.map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}</div>
      <div className="card-3d" style={{ padding:'16px' }}><div className="flex gap-3 items-end"><div className="min-w-[140px]"><label className="label">Status</label><select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['All','Issued','Expired'].map(x=><option key={x}>{x}</option>)}</select></div><button onClick={()=>setModal({})} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white ml-auto" style={{ background:GRAD }}><Award size={15}/> Generate Certificate</button></div></div>
      {loading ? <HrLoading label="Loading…" /> : data.data.length===0 ? <HrEmpty icon={Award} title="No certificates yet" hint="Generate a certificate for a completed, passed training." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}><table className="w-full text-sm" style={{ minWidth:900 }}>
            <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Certificate No','Employee','Program','Issued','Expiry','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
            <tbody>{data.data.map(r=>(
              <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                <td className="px-3 py-2.5 font-mono text-[11px]" style={{ color:'#a78bfa' }}>{r.certificate_number}</td>
                <td className="px-3 py-2.5"><span className="font-bold" style={{ color:'var(--text-h)' }}>{r.employee_name}</span></td>
                <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.program}</td>
                <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{fmtDate(r.issue_date)}</td>
                <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.expiry_date?fmtDate(r.expiry_date):'No expiry'}</td>
                <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${CERT_C[r.status]}1f`, color:CERT_C[r.status] }}>{r.status}</span></td>
                <td className="px-3 py-2.5"><div className="flex gap-1.5 justify-end">
                  <button title="Download" onClick={()=>hrApi.learning.certificates.download(r.id)} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Download size={13}/></button>
                  {r.status==='Issued' && <button title="Expire" onClick={()=>hrApi.learning.certificates.expire(r.id).then(load).catch(e=>showToast(e.response?.data?.message||'Failed','error'))} className="p-1.5 rounded-lg" style={{ background:'rgba(239,68,68,0.1)', color:'#f87171' }}><XCircle size={13}/></button>}
                </div></td>
              </tr>
            ))}</tbody></table></div>}
      {modal && <CertModal completed={completedNoCert} onClose={()=>setModal(null)} onSaved={()=>{ setModal(null); load() }} showToast={showToast} />}
    </div>
  )
}
function CertModal({ completed, onClose, onSaved, showToast }) {
  const [form, setForm] = useState({ employee_training_id:'', issue_date:new Date().toISOString().slice(0,10), expiry_date:'' })
  const [saving, setSaving] = useState(false)
  const save = async () => {
    if (!form.employee_training_id) return showToast('Select a completed training','error')
    if (!form.issue_date) return showToast('Issue date is required','error')
    setSaving(true)
    try { await hrApi.learning.certificates.generate({ employee_training_id:form.employee_training_id, issue_date:form.issue_date, expiry_date:form.expiry_date||undefined }); showToast('Certificate generated'); onSaved() }
    catch (e) { showToast(e.response?.data?.message||'Generation failed','error') } finally { setSaving(false) }
  }
  return (
    <div className="modal-backdrop" onClick={onClose}><div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()}>
      <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Generate Certificate</h2><button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
      <div className="space-y-3">
        <div><label className="label">Completed Training *</label><select className="input-3d text-sm" value={form.employee_training_id} onChange={e=>setForm(f=>({...f,employee_training_id:e.target.value}))}><option value="">Select…</option>{completed.map(c=><option key={c.employee_training_id} value={c.employee_training_id}>{c.employee_name} · {c.program}</option>)}</select><p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>Only completed trainings (attendance + assessment/quiz passed) qualify.</p></div>
        <div className="grid grid-cols-2 gap-3"><div><label className="label">Issue Date *</label><input type="date" className="input-3d text-sm" value={form.issue_date} onChange={e=>setForm(f=>({...f,issue_date:e.target.value}))}/></div><div><label className="label">Expiry Date</label><input type="date" className="input-3d text-sm" value={form.expiry_date} onChange={e=>setForm(f=>({...f,expiry_date:e.target.value}))}/></div></div>
      </div>
      <div className="flex gap-3 pt-4"><button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Generating…':'Generate'}</button></div>
    </div></div>
  )
}

/* ── Completion (Phase 6) ── */
const COMP_C = { Certified:'#10b981', Completed:'#10b981', 'In Progress':'#f59e0b', Failed:'#f87171' }
function Completion({ showToast }) {
  const [data, setData] = useState({ data:[], stats:{ completed:0, in_progress:0, failed:0, certified:0 } })
  const [loading, setLoading] = useState(true)
  useEffect(() => { hrApi.learning.completion.list().then(setData).catch(()=>showToast('Failed','error')).finally(()=>setLoading(false)) }, [showToast])
  const s = data.stats
  const KPIS = [{l:'Completed',v:s.completed,c:'#10b981'},{l:'In Progress',v:s.in_progress,c:'#f59e0b'},{l:'Failed',v:s.failed,c:'#f87171'},{l:'Certified',v:s.certified,c:'#8b5cf6'}]
  const yn = (v) => v===null||v===undefined ? '—' : (v ? '✓' : '✗')
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">{KPIS.map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}</div>
      {loading ? <HrLoading label="Loading completion…" /> : data.data.length===0 ? <HrEmpty icon={CheckCircle2} title="No trainings yet" hint="Completion appears here as employees progress through trainings." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}><table className="w-full text-sm" style={{ minWidth:960 }}>
            <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Program','Attendance','Assessment','Quiz','Progress','Certificate','Status'].map(h=><th key={h} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
            <tbody>{data.data.map(r=>(
              <tr key={r.employee_training_id} style={{ borderBottom:'1px solid var(--border)' }}>
                <td className="px-3 py-2.5"><span className="font-bold" style={{ color:'var(--text-h)' }}>{r.employee_name}</span> <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code}</span></td>
                <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.program}</td>
                <td className="px-3 py-2.5" style={{ color:r.attendance==='Present'?'#10b981':r.attendance==='Absent'?'#f87171':'var(--text-muted)' }}>{r.attendance||'—'}</td>
                <td className="px-3 py-2.5" style={{ color:r.assessment_result==='Pass'?'#10b981':r.assessment_result==='Fail'?'#f87171':'var(--text-muted)' }}>{r.assessment_result||'—'}{r.assessment_pct!=null?` (${r.assessment_pct}%)`:''}</td>
                <td className="px-3 py-2.5" style={{ color:r.quiz_passed===true?'#10b981':r.quiz_passed===false?'#f87171':'var(--text-muted)' }}>{yn(r.quiz_passed)}</td>
                <td className="px-3 py-2.5" style={{ minWidth:120 }}><div className="flex items-center gap-2"><div className="flex-1 h-1.5 rounded-full" style={{ background:'var(--bg-input)' }}><div className="h-full rounded-full" style={{ width:`${r.completion_percentage}%`, background:GRAD }}/></div><span className="text-[10px] font-bold" style={{ color:'#7C3AED' }}>{r.completion_percentage}%</span></div></td>
                <td className="px-3 py-2.5">{r.certificate_number ? <a onClick={()=>hrApi.learning.certificates.download(r.certificate_id)} className="text-[11px] font-mono cursor-pointer" style={{ color:'#a78bfa' }}>{r.certificate_number}</a> : '—'}</td>
                <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${COMP_C[r.status]||'#7C3AED'}1f`, color:COMP_C[r.status]||'#7C3AED' }}>{r.status}</span></td>
              </tr>
            ))}</tbody></table></div>}
    </div>
  )
}
