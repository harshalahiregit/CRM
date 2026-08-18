import { useState, useEffect, useCallback } from 'react'
import { useTheme } from '@/context/ThemeContext'
import {
  LogOut, Tag, FileText, Send, CheckSquare, ClipboardCheck, Wallet, BarChart3,
  Lock, Plus, Pencil, X, Power, Search, Eye, Paperclip, Download, Undo2, Clock,
  PlayCircle, CheckCircle2, XCircle, MessageSquare, FileQuestion,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import ExitReports from './ExitReports'
import ExitQuestionnaires from '../components/ExitQuestionnaires'

const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'

const TABS = [
  { key:'types',     label:'Exit Types',    icon:Tag,           ready:true },
  { key:'policies',  label:'Exit Policies', icon:FileText,      ready:true },
  // #44 — sits with the other exit masters, before the requests that use it.
  { key:'questionnaires', label:'Questionnaires', icon:FileQuestion, ready:true },
  { key:'requests',  label:'Exit Requests', icon:Send,          ready:true },
  { key:'approval',  label:'Exit Approval', icon:CheckSquare,   ready:true },
  { key:'clearance', label:'Clearance',     icon:ClipboardCheck,ready:true },
  { key:'fnf',       label:'Full & Final',  icon:Wallet,        ready:true },
  { key:'reports',   label:'Exit Reports',  icon:BarChart3,     ready:true },
]

const REQ_STATUS_STYLE = {
  Draft:          { background:'var(--bg-input)', color:'var(--text-muted)' },
  Submitted:      { background:'rgba(59,130,246,0.12)', color:'#3b82f6' },
  'Under Review': { background:'rgba(245,158,11,0.14)', color:'#f59e0b' },
  Approved:       { background:'rgba(16,185,129,0.14)', color:'#10b981' },
  Rejected:       { background:'rgba(239,68,68,0.12)', color:'#f87171' },
  Withdrawn:      { background:'rgba(148,163,184,0.15)', color:'#94a3b8' },
}
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' }) : '—'

// Clearance overall + per-department item statuses.
const CLR_STATUS_STYLE = {
  Pending:       { background:'var(--bg-input)', color:'var(--text-muted)' },
  'In Progress': { background:'rgba(245,158,11,0.14)', color:'#f59e0b' },
  Cleared:       { background:'rgba(16,185,129,0.14)', color:'#10b981' },
  Completed:     { background:'rgba(16,185,129,0.14)', color:'#10b981' },
  Rejected:      { background:'rgba(239,68,68,0.12)', color:'#f87171' },
}

// Full & Final settlement statuses.
const SET_STATUS_STYLE = {
  Pending:   { background:'var(--bg-input)', color:'var(--text-muted)' },
  Generated: { background:'rgba(59,130,246,0.12)', color:'#3b82f6' },
  Reviewed:  { background:'rgba(245,158,11,0.14)', color:'#f59e0b' },
  Approved:  { background:'rgba(124,58,237,0.14)', color:'#a78bfa' },
  Settled:   { background:'rgba(16,185,129,0.14)', color:'#10b981' },
}
const money = (v) => (v === null || v === undefined) ? '—' : `₹${Number(v).toLocaleString('en-IN', { minimumFractionDigits:2, maximumFractionDigits:2 })}`

export default function ExitManagement() {
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
            <LogOut size={22} style={{ color:'#a78bfa' }}/> Exit <span className="text-gradient">Management</span>
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

      {tab === 'types' ? <ExitTypes showToast={showToast} />
        : tab === 'policies' ? <ExitPolicies showToast={showToast} />
        : tab === 'questionnaires' ? <ExitQuestionnaires showToast={showToast} />
        : tab === 'requests' ? <ExitRequests showToast={showToast} />
        : tab === 'approval' ? <ExitApproval showToast={showToast} />
        : tab === 'clearance' ? <ExitClearance showToast={showToast} />
        : tab === 'fnf' ? <FullAndFinal showToast={showToast} />
        : tab === 'reports' ? <ExitReports showToast={showToast} />
        : (
          <div className="card-3d flex flex-col items-center justify-center text-center" style={{ padding:'56px 20px' }}>
            <div className="rounded-2xl flex items-center justify-center mb-3" style={{ width:60, height:60, background:'rgba(124,58,237,0.1)' }}><current.icon size={26} style={{ color:'#a78bfa' }}/></div>
            <p className="text-sm font-black" style={{ color:'var(--text-h)' }}>{current.label}</p>
            <p className="text-xs mt-1" style={{ color:'var(--text-muted)' }}>Coming in future phase.</p>
          </div>
        )}
    </div>
  )
}

/* ── Exit Types ── */
const TYPE_FLAGS = [['notice_required','Notice Required'],['clearance_required','Clearance Required'],['fnf_required','FnF Required'],['exit_interview_required','Exit Interview Required']]

function ExitTypes({ showToast }) {
  const [rows, setRows] = useState([]); const [stats, setStats] = useState({ total:0, active:0, inactive:0 })
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState(''); const [statusF, setStatusF] = useState('All')
  const [modal, setModal] = useState(null); const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    const params = {}
    if (statusF !== 'All') params.status = statusF
    if (search) params.search = search
    hrApi.exit.types.list(params).then(res => { setRows(res.data||[]); setStats(res.stats||stats) }).catch(()=>showToast('Failed to load exit types','error')).finally(()=>setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusF, search])
  useEffect(() => { load() }, [load])

  const EMPTY = { name:'', code:'', description:'', notice_required:true, default_notice_days:30, clearance_required:true, fnf_required:true, exit_interview_required:false, is_active:true }
  const save = async () => {
    const f = modal.form
    if (!f.name.trim() || !f.code.trim()) return showToast('Name and code are required','error')
    setSaving(true)
    try { modal.editing ? await hrApi.exit.types.update(modal.editing, f) : await hrApi.exit.types.create(f); showToast(`Exit type ${modal.editing?'updated':'created'}`); setModal(null); load() }
    catch (e) { showToast(e.response?.data?.message||'Save failed','error') } finally { setSaving(false) }
  }
  const toggle = async (r) => { try { await hrApi.exit.types.setStatus(r.id, !r.is_active); load() } catch { showToast('Failed','error') } }
  const KPIS = [{l:'Total',v:stats.total,c:'#7C3AED'},{l:'Active',v:stats.active,c:'#10b981'},{l:'Inactive',v:stats.inactive,c:'#f87171'}]
  const hasF = statusF!=='All'||search

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-3 gap-4">{KPIS.map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}</div>

      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[200px]"><label className="label">Search</label><Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/><input className="input-3d pl-9 text-sm" placeholder="Name or code…" value={search} onChange={e=>setSearch(e.target.value)}/></div>
          <div className="min-w-[130px]"><label className="label">Status</label><select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['All','Active','Inactive'].map(s=><option key={s}>{s}</option>)}</select></div>
          {hasF && <button onClick={()=>{ setStatusF('All'); setSearch('') }} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
          <button onClick={()=>setModal({ editing:null, form:{...EMPTY} })} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white ml-auto" style={{ background:GRAD }}><Plus size={15}/> Add Exit Type</button>
        </div>
      </div>

      {loading ? <HrLoading label="Loading exit types…" /> : rows.length===0 ? <HrEmpty icon={Tag} title="No exit types yet" hint="Create exit types (Resignation, Termination, Retirement…)." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:820 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Exit Type','Notice','Clearance','FnF','Interview','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{rows.map(r=>(
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)', opacity:r.is_active?1:0.55 }}>
                  <td className="px-3 py-2.5"><span className="font-bold" style={{ color:'var(--text-h)' }}>{r.name}</span> <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.code}</span></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.notice_required?`${r.default_notice_days}d`:'—'}</td>
                  <td className="px-3 py-2.5">{r.clearance_required?'✓':'—'}</td>
                  <td className="px-3 py-2.5">{r.fnf_required?'✓':'—'}</td>
                  <td className="px-3 py-2.5">{r.exit_interview_required?'✓':'—'}</td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={r.is_active?{background:'rgba(16,185,129,0.12)',color:'#10b981'}:{background:'var(--bg-input)',color:'var(--text-muted)'}}>{r.is_active?'Active':'Inactive'}</span></td>
                  <td className="px-3 py-2.5"><div className="flex gap-1.5 justify-end">
                    <button onClick={()=>setModal({ editing:r.id, form:{ ...EMPTY, ...r } })} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Pencil size={13}/></button>
                    <button onClick={()=>toggle(r)} className="p-1.5 rounded-lg" style={r.is_active?{background:'rgba(239,68,68,0.1)',color:'#f87171'}:{background:'rgba(16,185,129,0.1)',color:'#10b981'}}><Power size={13}/></button>
                  </div></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}

      {modal && (
        <div className="modal-backdrop"><div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()} style={{ maxHeight:'92vh', overflowY:'auto' }}>
          <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{modal.editing?'Edit Exit Type':'Add Exit Type'}</h2><button onClick={()=>setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Name *</label><input className="input-3d text-sm" value={modal.form.name} onChange={e=>setModal(m=>({...m,form:{...m.form,name:e.target.value}}))}/></div>
            <div><label className="label">Code *</label><input className="input-3d text-sm" value={modal.form.code} onChange={e=>setModal(m=>({...m,form:{...m.form,code:e.target.value}}))}/></div>
            <div><label className="label">Default Notice Days</label><input type="number" min="0" className="input-3d text-sm" value={modal.form.default_notice_days} onChange={e=>setModal(m=>({...m,form:{...m.form,default_notice_days:e.target.value}}))}/></div>
            <div className="col-span-2 grid grid-cols-2 gap-2">
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

/* ── Exit Policies ── */
const POL_FLAGS = [['buyout_allowed','Buyout Allowed'],['recovery_allowed','Recovery Allowed'],['leave_encashment','Leave Encashment'],['gratuity_applicable','Gratuity Applicable'],['clearance_required','Clearance Required'],['exit_interview_required','Exit Interview Required']]

function ExitPolicies({ showToast }) {
  const [rows, setRows] = useState([]); const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState(''); const [statusF, setStatusF] = useState('All')
  const [opts, setOpts] = useState({ grades:[], designations:[], departments:[] }); const [types, setTypes] = useState([])
  const [modal, setModal] = useState(null); const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    const params = {}
    if (statusF !== 'All') params.status = statusF
    if (search) params.search = search
    hrApi.exit.policies.list(params).then(setRows).catch(()=>showToast('Failed to load policies','error')).finally(()=>setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusF, search])
  useEffect(() => { load() }, [load])
  useEffect(() => {
    hrApi.organization.options().then(o=>setOpts({ grades:o.grades||[], designations:o.designations||[], departments:o.departments||[] })).catch(()=>{})
    hrApi.exit.types.list({ status:'Active' }).then(r=>setTypes(r.data||[])).catch(()=>{})
  }, [])

  const EMPTY = { name:'', grade_id:'', designation_id:'', department_id:'', default_exit_type_id:'', notice_days:30, buyout_allowed:false, recovery_allowed:false, leave_encashment:false, gratuity_applicable:false, clearance_required:true, exit_interview_required:false, description:'', is_active:true }
  const openEdit = async (row) => {
    try { const full = await hrApi.exit.policies.get(row.id); setModal({ editing:full.id, form:{ ...EMPTY, ...full, grade_id:full.grade_id||'', designation_id:full.designation_id||'', department_id:full.department_id||'', default_exit_type_id:full.default_exit_type_id||'' } }) }
    catch { showToast('Failed to load policy','error') }
  }
  const save = async () => {
    if (!modal.form.name.trim()) return showToast('Policy name is required','error')
    setSaving(true)
    try { modal.editing ? await hrApi.exit.policies.update(modal.editing, modal.form) : await hrApi.exit.policies.create(modal.form); showToast(`Policy ${modal.editing?'updated':'created'}`); setModal(null); load() }
    catch (e) { showToast(e.response?.data?.message||'Save failed','error') } finally { setSaving(false) }
  }
  const toggle = async (r) => { try { await hrApi.exit.policies.setStatus(r.id, !r.is_active); load() } catch { showToast('Failed','error') } }
  const hasF = statusF!=='All'||search

  return (
    <div className="space-y-4">
      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[200px]"><label className="label">Search</label><Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/><input className="input-3d pl-9 text-sm" placeholder="Policy name…" value={search} onChange={e=>setSearch(e.target.value)}/></div>
          <div className="min-w-[130px]"><label className="label">Status</label><select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['All','Active','Inactive'].map(s=><option key={s}>{s}</option>)}</select></div>
          {hasF && <button onClick={()=>{ setStatusF('All'); setSearch('') }} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
          <button onClick={()=>setModal({ editing:null, form:{...EMPTY} })} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white ml-auto" style={{ background:GRAD }}><Plus size={15}/> Add Policy</button>
        </div>
      </div>

      {loading ? <HrLoading label="Loading policies…" /> : rows.length===0 ? <HrEmpty icon={FileText} title="No exit policies yet" hint="Create an exit policy scoped to a grade, designation or department." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:820 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Policy','Scope','Default Exit Type','Notice','Gratuity','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{rows.map(r=>(
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)', opacity:r.is_active?1:0.55 }}>
                  <td className="px-3 py-2.5 font-bold" style={{ color:'var(--text-h)' }}>{r.name}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{[r.grade_name, r.designation_name, r.department_name].filter(Boolean).join(' · ')||'All'}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.default_exit_type||'—'}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.notice_days}d</td>
                  <td className="px-3 py-2.5">{r.gratuity_applicable?'✓':'—'}</td>
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
        <div className="modal-backdrop"><div className="modal-box" onClick={e=>e.stopPropagation()} style={{ maxWidth:720, width:'95%', maxHeight:'92vh', overflowY:'auto' }}>
          <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{modal.editing?'Edit Exit Policy':'Add Exit Policy'}</h2><button onClick={()=>setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
          <div className="grid grid-cols-2 md:grid-cols-3 gap-3 mb-3">
            <div className="col-span-2"><label className="label">Policy Name *</label><input className="input-3d text-sm" value={modal.form.name} onChange={e=>setModal(m=>({...m,form:{...m.form,name:e.target.value}}))}/></div>
            <div><label className="label">Notice Days</label><input type="number" min="0" className="input-3d text-sm" value={modal.form.notice_days} onChange={e=>setModal(m=>({...m,form:{...m.form,notice_days:e.target.value}}))}/></div>
            <div><label className="label">Grade</label><select className="input-3d text-sm" value={modal.form.grade_id} onChange={e=>setModal(m=>({...m,form:{...m.form,grade_id:e.target.value}}))}><option value="">—</option>{opts.grades.map(g=><option key={g.id} value={g.id}>{g.name}</option>)}</select></div>
            <div><label className="label">Designation</label><select className="input-3d text-sm" value={modal.form.designation_id} onChange={e=>setModal(m=>({...m,form:{...m.form,designation_id:e.target.value}}))}><option value="">—</option>{opts.designations.map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select></div>
            <div><label className="label">Department</label><select className="input-3d text-sm" value={modal.form.department_id} onChange={e=>setModal(m=>({...m,form:{...m.form,department_id:e.target.value}}))}><option value="">—</option>{opts.departments.map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select></div>
            <div className="col-span-2 md:col-span-3"><label className="label">Default Exit Type</label><select className="input-3d text-sm" value={modal.form.default_exit_type_id} onChange={e=>setModal(m=>({...m,form:{...m.form,default_exit_type_id:e.target.value}}))}><option value="">—</option>{types.map(t=><option key={t.id} value={t.id}>{t.name}</option>)}</select></div>
          </div>
          <div className="grid grid-cols-2 md:grid-cols-3 gap-2 mb-3">
            {POL_FLAGS.map(([k,l])=><label key={k} className="flex items-center gap-2 text-xs font-semibold px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}><input type="checkbox" checked={modal.form[k]} onChange={e=>setModal(m=>({...m,form:{...m.form,[k]:e.target.checked}}))}/>{l}</label>)}
          </div>
          <div><label className="label">Description</label><textarea rows={2} className="input-3d text-sm resize-none" value={modal.form.description} onChange={e=>setModal(m=>({...m,form:{...m.form,description:e.target.value}}))}/></div>
          <div className="flex gap-3 pt-4"><button onClick={()=>setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Saving…':'Save Policy'}</button></div>
        </div></div>
      )}
    </div>
  )
}

/* ── Exit Requests (Phase 2) ── */
const addDays = (iso, n) => { if (!iso) return ''; const d = new Date(iso); d.setDate(d.getDate() + Number(n||0)); return d.toISOString().slice(0,10) }

function ExitRequests({ showToast }) {
  const [data, setData] = useState({ rows:[], stats:{ draft:0, submitted:0, withdrawn:0, active_notice:0, exits_this_month:0 } })
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState(''); const [statusF, setStatusF] = useState('All'); const [typeF, setTypeF] = useState('')
  const [employees, setEmployees] = useState([]); const [types, setTypes] = useState([]); const [policies, setPolicies] = useState([])
  const [modal, setModal] = useState(null); const [saving, setSaving] = useState(false)
  const [view, setView] = useState(null); const [withdrawing, setWithdrawing] = useState(null)

  const load = useCallback(() => {
    setLoading(true)
    const params = {}
    if (statusF !== 'All') params.status = statusF
    if (typeF) params.exit_type_id = typeF
    if (search) params.search = search
    hrApi.exit.requests.list(params).then(setData).catch(()=>showToast('Failed to load exit requests','error')).finally(()=>setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusF, typeF, search])
  useEffect(() => { load() }, [load])
  useEffect(() => {
    hrApi.employees.list({ per_page:500 }).then(r=>setEmployees(Array.isArray(r)?r:[])).catch(()=>{})
    hrApi.exit.types.list({ status:'Active' }).then(r=>setTypes(r.data||[])).catch(()=>{})
    hrApi.exit.policies.list({ status:'Active' }).then(r=>setPolicies(r||[])).catch(()=>{})
  }, [])

  const EMPTY = { employee_id:'', exit_type_id:'', exit_policy_id:'', request_date:new Date().toISOString().slice(0,10), last_working_date:'', notice_start_date:'', notice_end_date:'', notice_days:'', reason:'', employee_remarks:'', hr_remarks:'' }

  const openCreate = () => setModal({ editing:null, form:{ ...EMPTY }, file:null })
  const openEdit = async (row) => {
    try {
      const full = await hrApi.exit.requests.get(row.id)
      setModal({ editing:full.id, form:{
        employee_id:full.employee_id||'', exit_type_id:full.exit_type_id||'', exit_policy_id:full.exit_policy_id||'',
        request_date:full.request_date||'', last_working_date:full.last_working_date||'', notice_start_date:full.notice_start_date||'',
        notice_end_date:full.notice_end_date||'', notice_days:full.notice_days??'', reason:full.reason||'',
        employee_remarks:full.employee_remarks||'', hr_remarks:full.hr_remarks||'',
      }, file:null })
    } catch { showToast('Failed to load request','error') }
  }

  // When exit type / policy / request date changes, suggest notice days + end date.
  const applySuggestions = (form) => {
    const pol = policies.find(p => String(p.id) === String(form.exit_policy_id))
    const typ = types.find(t => String(t.id) === String(form.exit_type_id))
    const nd = form.notice_days !== '' ? form.notice_days : (pol ? pol.notice_days : (typ ? typ.default_notice_days : ''))
    const start = form.notice_start_date || form.request_date
    const end = form.notice_end_date || (start && nd !== '' ? addDays(start, nd) : '')
    return { ...form, notice_days:nd, notice_start_date:start, notice_end_date:end }
  }

  const setForm = (patch, suggest=false) => setModal(m => { let f = { ...m.form, ...patch }; if (suggest) f = applySuggestions(f); return { ...m, form:f } })

  const submitStatus = async (asSubmit) => {
    const f = modal.form
    if (!f.employee_id) return showToast('Select an employee','error')
    if (!f.exit_type_id) return showToast('Select an exit type','error')
    if (!f.request_date) return showToast('Request date is required','error')
    setSaving(true)
    try {
      const fd = new FormData()
      const fields = ['employee_id','exit_type_id','exit_policy_id','request_date','last_working_date','notice_start_date','notice_end_date','notice_days','reason','employee_remarks','hr_remarks']
      fields.forEach(k => { if (f[k] !== '' && f[k] !== null && f[k] !== undefined) fd.append(k, f[k]) })
      if (!modal.editing) fd.append('status', asSubmit ? 'Submitted' : 'Draft')
      if (modal.file) fd.append('attachment', modal.file)
      const saved = modal.editing ? await hrApi.exit.requests.update(modal.editing, fd) : await hrApi.exit.requests.create(fd)
      // On edit, honour the Submit button too.
      if (modal.editing && asSubmit && saved.status === 'Draft') await hrApi.exit.requests.submit(modal.editing)
      showToast(`Exit request ${modal.editing?'updated':(asSubmit?'submitted':'saved as draft')}`)
      setModal(null); load()
    } catch (e) { showToast(e.response?.data?.message || 'Save failed','error') } finally { setSaving(false) }
  }

  const submit = async (r) => { try { await hrApi.exit.requests.submit(r.id); showToast('Request submitted'); load() } catch (e) { showToast(e.response?.data?.message||'Failed','error') } }
  const doWithdraw = async () => {
    setSaving(true)
    try { await hrApi.exit.requests.withdraw(withdrawing.id, { reason:withdrawing.reason||'' }); showToast('Request withdrawn'); setWithdrawing(null); load(); if (view) setView(null) }
    catch (e) { showToast(e.response?.data?.message||'Failed','error') } finally { setSaving(false) }
  }

  const KPIS = [
    { l:'Draft', v:data.stats.draft, c:'#94a3b8' },
    { l:'Submitted', v:data.stats.submitted, c:'#3b82f6' },
    { l:'Withdrawn', v:data.stats.withdrawn, c:'#f87171' },
    { l:'Active Notice', v:data.stats.active_notice, c:'#f59e0b' },
    { l:'Exits This Month', v:data.stats.exits_this_month, c:'#7C3AED' },
  ]
  const hasF = statusF!=='All' || typeF || search
  const canEdit = (r) => r.status !== 'Withdrawn'
  const canWithdraw = (r) => r.status === 'Draft' || r.status === 'Submitted'

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-5 gap-3">{KPIS.map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}</div>

      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[200px]"><label className="label">Search</label><Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/><input className="input-3d pl-9 text-sm" placeholder="Employee name or code…" value={search} onChange={e=>setSearch(e.target.value)}/></div>
          <div className="min-w-[150px]"><label className="label">Status</label><select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['All','Draft','Submitted','Withdrawn'].map(s=><option key={s}>{s}</option>)}</select></div>
          <div className="min-w-[150px]"><label className="label">Exit Type</label><select className="input-3d text-sm" value={typeF} onChange={e=>setTypeF(e.target.value)}><option value="">All</option>{types.map(t=><option key={t.id} value={t.id}>{t.name}</option>)}</select></div>
          {hasF && <button onClick={()=>{ setStatusF('All'); setTypeF(''); setSearch('') }} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
          <button onClick={openCreate} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white ml-auto" style={{ background:GRAD }}><Plus size={15}/> New Exit Request</button>
        </div>
      </div>

      {loading ? <HrLoading label="Loading exit requests…" /> : data.rows.length===0 ? <HrEmpty icon={Send} title="No exit requests yet" hint="Raise an exit request for an employee to begin the separation process." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:940 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Exit Type','Request Date','Last Working','Notice','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{data.rows.map(r=>(
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                  <td className="px-3 py-2.5"><div className="font-bold" style={{ color:'var(--text-h)' }}>{r.employee_name}</div><div className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code} · {r.department||'—'}</div></td>
                  <td className="px-3 py-2.5"><span className="font-semibold" style={{ color:'var(--text-h)' }}>{r.exit_type}</span>{r.policy_name && <div className="text-[10px]" style={{ color:'var(--text-muted)' }}>{r.policy_name}</div>}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{fmtDate(r.request_date)}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{fmtDate(r.last_working_date)}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.notice_days}d{r.notice_end_date && <div className="text-[10px]">→ {fmtDate(r.notice_end_date)}</div>}</td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={REQ_STATUS_STYLE[r.status]||{}}>{r.status}</span></td>
                  <td className="px-3 py-2.5"><div className="flex gap-1.5 justify-end">
                    <button title="View" onClick={()=>setView(r.id)} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Eye size={13}/></button>
                    {r.status==='Draft' && <button title="Submit" onClick={()=>submit(r)} className="p-1.5 rounded-lg" style={{ background:'rgba(59,130,246,0.1)', color:'#3b82f6' }}><Send size={13}/></button>}
                    {canEdit(r) && <button title="Edit" onClick={()=>openEdit(r)} className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Pencil size={13}/></button>}
                    {canWithdraw(r) && <button title="Withdraw" onClick={()=>setWithdrawing({ id:r.id, reason:'' })} className="p-1.5 rounded-lg" style={{ background:'rgba(239,68,68,0.1)', color:'#f87171' }}><Undo2 size={13}/></button>}
                  </div></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}

      {/* Create / Edit modal */}
      {modal && (
        <div className="modal-backdrop"><div className="modal-box" onClick={e=>e.stopPropagation()} style={{ maxWidth:760, width:'95%', maxHeight:'92vh', overflowY:'auto' }}>
          <div className="flex items-center justify-between mb-4"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{modal.editing?'Edit Exit Request':'New Exit Request'}</h2><button onClick={()=>setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
          <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
            <div className="col-span-2 md:col-span-1"><label className="label">Employee *</label><select className="input-3d text-sm" value={modal.form.employee_id} onChange={e=>setForm({ employee_id:e.target.value })} disabled={!!modal.editing}><option value="">Select…</option>{employees.map(e=><option key={e.id} value={e.id}>{e.name} ({e.employee_code})</option>)}</select></div>
            <div><label className="label">Exit Type *</label><select className="input-3d text-sm" value={modal.form.exit_type_id} onChange={e=>setForm({ exit_type_id:e.target.value, notice_days:'', notice_end_date:'' }, true)}><option value="">Select…</option>{types.map(t=><option key={t.id} value={t.id}>{t.name}</option>)}</select></div>
            <div><label className="label">Exit Policy</label><select className="input-3d text-sm" value={modal.form.exit_policy_id} onChange={e=>setForm({ exit_policy_id:e.target.value, notice_days:'', notice_end_date:'' }, true)}><option value="">Auto from employee</option>{policies.map(p=><option key={p.id} value={p.id}>{p.name} ({p.notice_days}d)</option>)}</select></div>
            <div><label className="label">Request Date *</label><input type="date" className="input-3d text-sm" value={modal.form.request_date} onChange={e=>setForm({ request_date:e.target.value, notice_start_date:'', notice_end_date:'' }, true)}/></div>
            <div><label className="label">Last Working Date</label><input type="date" className="input-3d text-sm" value={modal.form.last_working_date} onChange={e=>setForm({ last_working_date:e.target.value })}/></div>
            <div><label className="label">Notice Days</label><input type="number" min="0" className="input-3d text-sm" value={modal.form.notice_days} onChange={e=>setForm({ notice_days:e.target.value, notice_end_date:'' }, true)}/></div>
            <div><label className="label">Notice Start</label><input type="date" className="input-3d text-sm" value={modal.form.notice_start_date} onChange={e=>setForm({ notice_start_date:e.target.value, notice_end_date:'' }, true)}/></div>
            <div><label className="label">Notice End</label><input type="date" className="input-3d text-sm" value={modal.form.notice_end_date} onChange={e=>setForm({ notice_end_date:e.target.value })}/><p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>Override needs a buyout policy.</p></div>
            <div className="col-span-2 md:col-span-3"><label className="label">Reason</label><textarea rows={2} className="input-3d text-sm resize-none" value={modal.form.reason} onChange={e=>setForm({ reason:e.target.value })}/></div>
            <div className="col-span-2 md:col-span-3 grid grid-cols-1 md:grid-cols-2 gap-3">
              <div><label className="label">Employee Remarks</label><textarea rows={2} className="input-3d text-sm resize-none" value={modal.form.employee_remarks} onChange={e=>setForm({ employee_remarks:e.target.value })}/></div>
              <div><label className="label">HR Remarks</label><textarea rows={2} className="input-3d text-sm resize-none" value={modal.form.hr_remarks} onChange={e=>setForm({ hr_remarks:e.target.value })}/></div>
            </div>
            <div className="col-span-2 md:col-span-3"><label className="label">Attachment</label>
              <label className="input-3d text-sm flex items-center gap-2 cursor-pointer" style={{ color:'var(--text-muted)' }}><Paperclip size={14}/>{modal.file ? modal.file.name : 'Attach a file (resignation letter, etc.)'}<input type="file" className="hidden" onChange={e=>setModal(m=>({ ...m, file:e.target.files?.[0]||null }))}/></label>
            </div>
          </div>
          <div className="flex gap-3 pt-4">
            <button onClick={()=>setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
            <button onClick={()=>submitStatus(false)} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold" style={{ background:'var(--bg-input)', color:'var(--text-h)', border:'1px solid var(--border)', opacity:saving?0.7:1 }}>{saving?'Saving…':'Save Draft'}</button>
            <button onClick={()=>submitStatus(true)} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Saving…':(modal.editing?'Save & Submit':'Submit')}</button>
          </div>
        </div></div>
      )}

      {/* Withdraw confirm */}
      {withdrawing && (
        <div className="modal-backdrop"><div className="modal-box max-w-md" onClick={e=>e.stopPropagation()}>
          <div className="flex items-center justify-between mb-3"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Withdraw Exit Request</h2><button onClick={()=>setWithdrawing(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
          <p className="text-xs mb-3" style={{ color:'var(--text-muted)' }}>This marks the request as Withdrawn. It can no longer be edited or submitted.</p>
          <label className="label">Reason (optional)</label><textarea rows={3} className="input-3d text-sm resize-none" value={withdrawing.reason} onChange={e=>setWithdrawing(w=>({ ...w, reason:e.target.value }))}/>
          <div className="flex gap-3 pt-4"><button onClick={()=>setWithdrawing(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button><button onClick={doWithdraw} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#f87171,#ef4444)', opacity:saving?0.7:1 }}>{saving?'Working…':'Withdraw'}</button></div>
        </div></div>
      )}

      {/* View drawer */}
      {view && <ExitRequestDrawer id={view} onClose={()=>setView(null)} onWithdraw={(r)=>setWithdrawing({ id:r.id, reason:'' })} />}
    </div>
  )
}

function ExitRequestDrawer({ id, onClose, onWithdraw }) {
  const [r, setR] = useState(null); const [loading, setLoading] = useState(true)
  useEffect(() => { setLoading(true); hrApi.exit.requests.get(id).then(setR).finally(()=>setLoading(false)) }, [id])

  const Field = ({ l, v }) => <div><p className="label-caps mb-0.5">{l}</p><p className="text-sm font-semibold" style={{ color:'var(--text-h)' }}>{v||'—'}</p></div>

  return (
    <div className="fixed inset-0 z-[9998] flex justify-end">
      <div className="absolute inset-0" style={{ background:'rgba(0,0,0,0.4)' }} />
      <div className="relative h-full overflow-y-auto animate-[slideIn_0.25s_ease]" onClick={e=>e.stopPropagation()} style={{ width:'min(460px,95vw)', background:'var(--bg-card,var(--bg-input))', borderLeft:'1px solid var(--border)', boxShadow:'-10px 0 40px rgba(0,0,0,0.3)' }}>
        <div className="sticky top-0 flex items-center justify-between px-5 py-4" style={{ background:'var(--bg-card,var(--bg-input))', borderBottom:'1px solid var(--border)' }}>
          <h2 className="font-black text-base flex items-center gap-2" style={{ color:'var(--text-h)' }}><LogOut size={16} style={{ color:'#a78bfa' }}/> Exit Request</h2>
          <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>
        {loading || !r ? <div className="p-6"><HrLoading label="Loading…" /></div> : (
          <div className="p-5 space-y-5">
            <div className="flex items-center justify-between">
              <div><p className="font-black text-lg" style={{ color:'var(--text-h)' }}>{r.employee_name}</p><p className="text-[11px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code} · {r.designation||'—'}</p></div>
              <span className="text-[11px] font-bold px-2.5 py-1 rounded-lg" style={REQ_STATUS_STYLE[r.status]||{}}>{r.status}</span>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <Field l="Exit Type" v={r.exit_type} />
              <Field l="Exit Policy" v={r.policy_name} />
              <Field l="Request Date" v={fmtDate(r.request_date)} />
              <Field l="Last Working Date" v={fmtDate(r.last_working_date)} />
              <Field l="Notice Start" v={fmtDate(r.notice_start_date)} />
              <Field l="Notice End" v={fmtDate(r.notice_end_date)} />
              <Field l="Notice Days" v={`${r.notice_days} day(s)`} />
              <Field l="Department" v={r.department} />
            </div>
            {r.reason && <div><p className="label-caps mb-0.5">Reason</p><p className="text-sm" style={{ color:'var(--text-h)' }}>{r.reason}</p></div>}
            {r.employee_remarks && <div><p className="label-caps mb-0.5">Employee Remarks</p><p className="text-sm" style={{ color:'var(--text-h)' }}>{r.employee_remarks}</p></div>}
            {r.hr_remarks && <div><p className="label-caps mb-0.5">HR Remarks</p><p className="text-sm" style={{ color:'var(--text-h)' }}>{r.hr_remarks}</p></div>}
            {r.has_attachment && <a href={hrApi.exit.requests.attachmentUrl(r.id)} target="_blank" rel="noreferrer" className="inline-flex items-center gap-2 text-xs font-bold px-3 py-2 rounded-xl" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Download size={13}/> Download attachment</a>}

            <div><p className="label-caps mb-2 flex items-center gap-1.5"><Clock size={12}/> Timeline</p>
              <div className="space-y-2.5">{(r.timeline||[]).map((t,i)=>(
                <div key={i} className="flex gap-3">
                  <div className="mt-1.5 rounded-full flex-shrink-0" style={{ width:8, height:8, background:'#a78bfa' }}/>
                  <div><p className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{t.action}</p><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>{t.actor_name||'System'} · {t.created_at?new Date(t.created_at).toLocaleString():''}</p>{t.comment && <p className="text-[11px] mt-0.5" style={{ color:'var(--text-muted)' }}>“{t.comment}”</p>}</div>
                </div>
              ))}{(!r.timeline||!r.timeline.length) && <p className="text-xs" style={{ color:'var(--text-muted)' }}>No timeline yet.</p>}</div>
            </div>

            {(r.status==='Draft'||r.status==='Submitted') && <button onClick={()=>onWithdraw(r)} className="w-full py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:'linear-gradient(135deg,#f87171,#ef4444)' }}><Undo2 size={14}/> Withdraw Request</button>}
          </div>
        )}
      </div>
    </div>
  )
}

/* ── Exit Approval (Phase 3) ── */
function ExitApproval({ showToast }) {
  const [data, setData] = useState({ rows:[], stats:{ pending:0, under_review:0, approved:0, rejected:0, exits_this_month:0 } })
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState(''); const [statusF, setStatusF] = useState('All'); const [typeF, setTypeF] = useState(''); const [deptF, setDeptF] = useState('')
  const [types, setTypes] = useState([]); const [view, setView] = useState(null)

  const load = useCallback(() => {
    setLoading(true)
    const params = {}
    if (statusF !== 'All') params.status = statusF
    if (typeF) params.exit_type_id = typeF
    if (deptF) params.department = deptF
    if (search) params.search = search
    hrApi.exit.approvals.list(params).then(setData).catch(()=>showToast('Failed to load approvals','error')).finally(()=>setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusF, typeF, deptF, search])
  useEffect(() => { load() }, [load])
  useEffect(() => { hrApi.exit.types.list({ status:'Active' }).then(r=>setTypes(r.data||[])).catch(()=>{}) }, [])

  const departments = [...new Set(data.rows.map(r=>r.department).filter(Boolean))].sort()
  const KPIS = [
    { l:'Pending', v:data.stats.pending, c:'#3b82f6' },
    { l:'Under Review', v:data.stats.under_review, c:'#f59e0b' },
    { l:'Approved', v:data.stats.approved, c:'#10b981' },
    { l:'Rejected', v:data.stats.rejected, c:'#f87171' },
    { l:'Exits This Month', v:data.stats.exits_this_month, c:'#7C3AED' },
  ]
  const hasF = statusF!=='All' || typeF || deptF || search

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-5 gap-3">{KPIS.map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}</div>

      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[180px]"><label className="label">Search</label><Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/><input className="input-3d pl-9 text-sm" placeholder="Employee name or code…" value={search} onChange={e=>setSearch(e.target.value)}/></div>
          <div className="min-w-[140px]"><label className="label">Department</label><select className="input-3d text-sm" value={deptF} onChange={e=>setDeptF(e.target.value)}><option value="">All</option>{departments.map(d=><option key={d} value={d}>{d}</option>)}</select></div>
          <div className="min-w-[140px]"><label className="label">Exit Type</label><select className="input-3d text-sm" value={typeF} onChange={e=>setTypeF(e.target.value)}><option value="">All</option>{types.map(t=><option key={t.id} value={t.id}>{t.name}</option>)}</select></div>
          <div className="min-w-[150px]"><label className="label">Status</label><select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['All','Submitted','Under Review','Approved','Rejected'].map(s=><option key={s}>{s}</option>)}</select></div>
          {hasF && <button onClick={()=>{ setStatusF('All'); setTypeF(''); setDeptF(''); setSearch('') }} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
        </div>
      </div>

      {loading ? <HrLoading label="Loading approval queue…" /> : data.rows.length===0 ? <HrEmpty icon={CheckSquare} title="Nothing in the approval queue" hint="Submitted exit requests appear here for review, approval or rejection." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:1000 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Department','Exit Type','Notice','Last Working','Submitted','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{data.rows.map(r=>(
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                  <td className="px-3 py-2.5"><div className="font-bold" style={{ color:'var(--text-h)' }}>{r.employee_name}</div><div className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code}</div></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.department||'—'}</td>
                  <td className="px-3 py-2.5"><span className="font-semibold" style={{ color:'var(--text-h)' }}>{r.exit_type}</span></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.notice_days}d</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{fmtDate(r.last_working_date)}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.submitted_at?fmtDate(r.submitted_at):'—'}</td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={REQ_STATUS_STYLE[r.status]||{}}>{r.status}</span></td>
                  <td className="px-3 py-2.5"><div className="flex gap-1.5 justify-end"><button title="Review" onClick={()=>setView(r.id)} className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold text-white" style={{ background:GRAD }}><Eye size={13}/> Review</button></div></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}

      {view && <ExitApprovalDrawer id={view} onClose={()=>setView(null)} onChanged={load} showToast={showToast} />}
    </div>
  )
}

function ExitApprovalDrawer({ id, onClose, onChanged, showToast }) {
  const [r, setR] = useState(null); const [loading, setLoading] = useState(true); const [busy, setBusy] = useState(false)
  const [remarks, setRemarks] = useState(''); const [decision, setDecision] = useState(null) // 'approve' | 'reject'

  const reload = useCallback(() => { setLoading(true); return hrApi.exit.approvals.get(id).then(d=>{ setR(d); setRemarks(d.review_remarks||'') }).finally(()=>setLoading(false)) }, [id])
  useEffect(() => { reload() }, [reload])

  const act = async (fn, ok) => { setBusy(true); try { await fn(); showToast(ok); await reload(); onChanged() } catch (e) { showToast(e.response?.data?.message||'Action failed','error') } finally { setBusy(false) } }
  const startReview = () => act(()=>hrApi.exit.approvals.startReview(id, remarks), 'Review started')
  const saveRemarks = () => act(()=>hrApi.exit.approvals.updateRemarks(id, remarks), 'Review remarks updated')
  const confirmDecision = () => act(()=> decision==='approve' ? hrApi.exit.approvals.approve(id, remarks) : hrApi.exit.approvals.reject(id, remarks), decision==='approve'?'Exit approved':'Exit rejected').then(()=>setDecision(null))

  const Field = ({ l, v }) => <div><p className="label-caps mb-0.5">{l}</p><p className="text-sm font-semibold" style={{ color:'var(--text-h)' }}>{v||'—'}</p></div>
  const isSubmitted = r?.status==='Submitted', isReview = r?.status==='Under Review', isDecided = r?.status==='Approved'||r?.status==='Rejected'

  return (
    <div className="fixed inset-0 z-[9998] flex justify-end">
      <div className="absolute inset-0" style={{ background:'rgba(0,0,0,0.4)' }} />
      <div className="relative h-full overflow-y-auto animate-[slideIn_0.25s_ease]" onClick={e=>e.stopPropagation()} style={{ width:'min(480px,96vw)', background:'var(--bg-card,var(--bg-input))', borderLeft:'1px solid var(--border)', boxShadow:'-10px 0 40px rgba(0,0,0,0.3)' }}>
        <div className="sticky top-0 z-10 flex items-center justify-between px-5 py-4" style={{ background:'var(--bg-card,var(--bg-input))', borderBottom:'1px solid var(--border)' }}>
          <h2 className="font-black text-base flex items-center gap-2" style={{ color:'var(--text-h)' }}><CheckSquare size={16} style={{ color:'#a78bfa' }}/> Exit Approval</h2>
          <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>
        {loading || !r ? <div className="p-6"><HrLoading label="Loading…" /></div> : (
          <div className="p-5 space-y-5">
            <div className="flex items-center justify-between">
              <div><p className="font-black text-lg" style={{ color:'var(--text-h)' }}>{r.employee_name}</p><p className="text-[11px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code}</p></div>
              <span className="text-[11px] font-bold px-2.5 py-1 rounded-lg" style={REQ_STATUS_STYLE[r.status]||{}}>{r.status}</span>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <Field l="Department" v={r.department} />
              <Field l="Designation" v={r.designation} />
              <Field l="Exit Type" v={r.exit_type} />
              <Field l="Exit Policy" v={r.policy_name} />
              <Field l="Request Date" v={fmtDate(r.request_date)} />
              <Field l="Last Working Date" v={fmtDate(r.last_working_date)} />
              <Field l="Notice Period" v={`${r.notice_days} day(s)`} />
              <Field l="Notice Window" v={r.notice_start_date?`${fmtDate(r.notice_start_date)} → ${fmtDate(r.notice_end_date)}`:'—'} />
            </div>
            {r.reason && <div><p className="label-caps mb-0.5">Reason</p><p className="text-sm" style={{ color:'var(--text-h)' }}>{r.reason}</p></div>}
            {r.hr_remarks && <div><p className="label-caps mb-0.5">HR Remarks</p><p className="text-sm" style={{ color:'var(--text-h)' }}>{r.hr_remarks}</p></div>}
            {r.has_attachment && <a href={hrApi.exit.requests.attachmentUrl(r.id)} target="_blank" rel="noreferrer" className="inline-flex items-center gap-2 text-xs font-bold px-3 py-2 rounded-xl" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Download size={13}/> Download attachment</a>}

            {/* Review remarks — editable only while Under Review; read-only otherwise. */}
            {(isReview || r.review_remarks) && (
              <div>
                <p className="label-caps mb-1 flex items-center gap-1.5"><MessageSquare size={12}/> Review Remarks</p>
                {isReview
                  ? <><textarea rows={3} className="input-3d text-sm resize-none" value={remarks} onChange={e=>setRemarks(e.target.value)} placeholder="Notes captured during review…"/>
                      <button onClick={saveRemarks} disabled={busy} className="mt-2 text-xs font-bold px-3 py-1.5 rounded-lg" style={{ background:'var(--bg-input)', color:'var(--text-h)', border:'1px solid var(--border)' }}>Save Remarks</button></>
                  : <p className="text-sm" style={{ color:'var(--text-h)' }}>{r.review_remarks||'—'}</p>}
              </div>
            )}
            {isDecided && <div><p className="label-caps mb-0.5">{r.status} Remarks</p><p className="text-sm" style={{ color:'var(--text-h)' }}>{r.decision_remarks||'—'}</p><p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}>Decided {r.decided_at?new Date(r.decided_at).toLocaleString():'—'}</p></div>}

            <div><p className="label-caps mb-2 flex items-center gap-1.5"><Clock size={12}/> Timeline &amp; Audit History</p>
              <div className="space-y-2.5">{(r.timeline||[]).map((t,i)=>(
                <div key={i} className="flex gap-3">
                  <div className="mt-1.5 rounded-full flex-shrink-0" style={{ width:8, height:8, background:'#a78bfa' }}/>
                  <div><p className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{t.action}</p><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>{t.actor_name||'System'} · {t.created_at?new Date(t.created_at).toLocaleString():''}</p>{t.comment && <p className="text-[11px] mt-0.5" style={{ color:'var(--text-muted)' }}>“{t.comment}”</p>}</div>
                </div>
              ))}{(!r.timeline||!r.timeline.length) && <p className="text-xs" style={{ color:'var(--text-muted)' }}>No timeline yet.</p>}</div>
            </div>

            {/* Actions by state */}
            {isSubmitted && <button onClick={startReview} disabled={busy} className="w-full py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:'linear-gradient(135deg,#f59e0b,#d97706)', opacity:busy?0.7:1 }}><PlayCircle size={15}/> Start Review</button>}
            {isReview && !decision && (
              <div className="flex gap-3">
                <button onClick={()=>setDecision('reject')} disabled={busy} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:'linear-gradient(135deg,#f87171,#ef4444)' }}><XCircle size={15}/> Reject</button>
                <button onClick={()=>setDecision('approve')} disabled={busy} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:'linear-gradient(135deg,#10b981,#059669)' }}><CheckCircle2 size={15}/> Approve</button>
              </div>
            )}
            {isReview && decision && (
              <div className="rounded-xl p-3" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                <p className="text-xs font-bold mb-2" style={{ color:'var(--text-h)' }}>{decision==='approve'?'Approve':'Reject'} this exit request?</p>
                <textarea rows={2} className="input-3d text-sm resize-none" value={remarks} onChange={e=>setRemarks(e.target.value)} placeholder={`${decision==='approve'?'Approval':'Rejection'} remarks (optional)`}/>
                <div className="flex gap-2 mt-2">
                  <button onClick={()=>setDecision(null)} className="flex-1 py-2 rounded-lg text-xs font-bold" style={{ background:'var(--bg-card,var(--bg-input))', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
                  <button onClick={confirmDecision} disabled={busy} className="flex-1 py-2 rounded-lg text-xs font-bold text-white" style={{ background: decision==='approve'?'linear-gradient(135deg,#10b981,#059669)':'linear-gradient(135deg,#f87171,#ef4444)', opacity:busy?0.7:1 }}>{busy?'Working…':`Confirm ${decision==='approve'?'Approval':'Rejection'}`}</button>
                </div>
              </div>
            )}
            {isDecided && <p className="text-xs text-center py-2 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>This request is {r.status.toLowerCase()} and read-only.</p>}
          </div>
        )}
      </div>
    </div>
  )
}

/* ── Clearance (Phase 4) ── */
function ExitClearance({ showToast }) {
  const [data, setData] = useState({ rows:[], stats:{ pending:0, in_progress:0, cleared:0, rejected:0, completed_this_month:0 } })
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState(''); const [statusF, setStatusF] = useState('All'); const [typeF, setTypeF] = useState(''); const [deptF, setDeptF] = useState('')
  const [types, setTypes] = useState([]); const [view, setView] = useState(null)

  const load = useCallback(() => {
    setLoading(true)
    const params = {}
    if (statusF !== 'All') params.status = statusF
    if (typeF) params.exit_type_id = typeF
    if (deptF) params.department = deptF
    if (search) params.search = search
    hrApi.exit.clearances.list(params).then(setData).catch(()=>showToast('Failed to load clearances','error')).finally(()=>setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusF, typeF, deptF, search])
  useEffect(() => { load() }, [load])
  useEffect(() => { hrApi.exit.types.list({ status:'Active' }).then(r=>setTypes(r.data||[])).catch(()=>{}) }, [])

  const departments = [...new Set(data.rows.map(r=>r.department).filter(Boolean))].sort()
  const KPIS = [
    { l:'Pending Clearance', v:data.stats.pending, c:'#94a3b8' },
    { l:'In Progress', v:data.stats.in_progress, c:'#f59e0b' },
    { l:'Cleared', v:data.stats.cleared, c:'#10b981' },
    { l:'Rejected', v:data.stats.rejected, c:'#f87171' },
    { l:'Completed This Month', v:data.stats.completed_this_month, c:'#7C3AED' },
  ]
  const hasF = statusF!=='All' || typeF || deptF || search

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-5 gap-3">{KPIS.map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}</div>

      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[180px]"><label className="label">Search</label><Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/><input className="input-3d pl-9 text-sm" placeholder="Employee name or code…" value={search} onChange={e=>setSearch(e.target.value)}/></div>
          <div className="min-w-[140px]"><label className="label">Department</label><select className="input-3d text-sm" value={deptF} onChange={e=>setDeptF(e.target.value)}><option value="">All</option>{departments.map(d=><option key={d} value={d}>{d}</option>)}</select></div>
          <div className="min-w-[140px]"><label className="label">Exit Type</label><select className="input-3d text-sm" value={typeF} onChange={e=>setTypeF(e.target.value)}><option value="">All</option>{types.map(t=><option key={t.id} value={t.id}>{t.name}</option>)}</select></div>
          <div className="min-w-[150px]"><label className="label">Status</label><select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['All','Pending','In Progress','Completed','Rejected'].map(s=><option key={s}>{s}</option>)}</select></div>
          {hasF && <button onClick={()=>{ setStatusF('All'); setTypeF(''); setDeptF(''); setSearch('') }} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
        </div>
      </div>

      {loading ? <HrLoading label="Loading clearance queue…" /> : data.rows.length===0 ? <HrEmpty icon={ClipboardCheck} title="No clearances yet" hint="Approved exit requests enter departmental clearance automatically." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:1040 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Department','Current Stage','Assigned To','Progress','Status','Last Updated','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{data.rows.map(r=>(
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                  <td className="px-3 py-2.5"><div className="font-bold" style={{ color:'var(--text-h)' }}>{r.employee_name}</div><div className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code}</div></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.department||'—'}</td>
                  <td className="px-3 py-2.5"><span className="font-semibold" style={{ color:'var(--text-h)' }}>{r.current_stage}</span></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.assigned_to||'—'}</td>
                  <td className="px-3 py-2.5"><div className="flex items-center gap-2"><div className="h-1.5 rounded-full flex-1 min-w-[60px]" style={{ background:'var(--bg-input)' }}><div className="h-full rounded-full" style={{ width:`${r.progress.total?Math.round(r.progress.cleared/r.progress.total*100):0}%`, background:GRAD }}/></div><span className="text-[10px] font-bold" style={{ color:'var(--text-muted)' }}>{r.progress.cleared}/{r.progress.total}</span></div></td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={CLR_STATUS_STYLE[r.status]||{}}>{r.status}</span></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.last_updated?fmtDate(r.last_updated):'—'}</td>
                  <td className="px-3 py-2.5"><div className="flex gap-1.5 justify-end"><button title="Manage" onClick={()=>setView(r.id)} className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold text-white" style={{ background:GRAD }}><Eye size={13}/> Manage</button></div></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}

      {view && <ClearanceDrawer id={view} onClose={()=>setView(null)} onChanged={load} showToast={showToast} />}
    </div>
  )
}

function ClearanceDrawer({ id, onClose, onChanged, showToast }) {
  const [c, setC] = useState(null); const [loading, setLoading] = useState(true); const [busy, setBusy] = useState(false)
  const [editItem, setEditItem] = useState(null) // { id, mode:'reject'|'remarks', remarks }

  const reload = useCallback(() => { setLoading(true); return hrApi.exit.clearances.get(id).then(setC).finally(()=>setLoading(false)) }, [id])
  useEffect(() => { reload() }, [reload])

  const act = async (fn, ok) => { setBusy(true); try { await fn(); showToast(ok); setEditItem(null); await reload(); onChanged() } catch (e) { showToast(e.response?.data?.message||'Action failed','error') } finally { setBusy(false) } }
  const start = (it) => act(()=>hrApi.exit.clearances.start(id, it.id), `${it.department} clearance started`)
  const clear = (it) => act(()=>hrApi.exit.clearances.clear(id, it.id, editItem?.itemId===it.id?editItem.remarks:''), `${it.department} cleared`)
  const reject = (it) => act(()=>hrApi.exit.clearances.reject(id, it.id, editItem?.remarks||''), `${it.department} rejected`)
  const saveRemarks = (it) => act(()=>hrApi.exit.clearances.remarks(id, it.id, { remarks:editItem?.remarks||'' }), 'Remarks updated')

  const Field = ({ l, v }) => <div><p className="label-caps mb-0.5">{l}</p><p className="text-sm font-semibold" style={{ color:'var(--text-h)' }}>{v||'—'}</p></div>
  const readOnly = c?.status === 'Completed'

  return (
    <div className="fixed inset-0 z-[9998] flex justify-end">
      <div className="absolute inset-0" style={{ background:'rgba(0,0,0,0.4)' }} />
      <div className="relative h-full overflow-y-auto animate-[slideIn_0.25s_ease]" onClick={e=>e.stopPropagation()} style={{ width:'min(520px,97vw)', background:'var(--bg-card,var(--bg-input))', borderLeft:'1px solid var(--border)', boxShadow:'-10px 0 40px rgba(0,0,0,0.3)' }}>
        <div className="sticky top-0 z-10 flex items-center justify-between px-5 py-4" style={{ background:'var(--bg-card,var(--bg-input))', borderBottom:'1px solid var(--border)' }}>
          <h2 className="font-black text-base flex items-center gap-2" style={{ color:'var(--text-h)' }}><ClipboardCheck size={16} style={{ color:'#a78bfa' }}/> Exit Clearance</h2>
          <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>
        {loading || !c ? <div className="p-6"><HrLoading label="Loading…" /></div> : (
          <div className="p-5 space-y-5">
            <div className="flex items-center justify-between">
              <div><p className="font-black text-lg" style={{ color:'var(--text-h)' }}>{c.employee_name}</p><p className="text-[11px] font-mono" style={{ color:'#a78bfa' }}>{c.employee_code}</p></div>
              <span className="text-[11px] font-bold px-2.5 py-1 rounded-lg" style={CLR_STATUS_STYLE[c.status]||{}}>{c.status}</span>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <Field l="Department" v={c.department} />
              <Field l="Designation" v={c.designation} />
              <Field l="Exit Type" v={c.exit_type} />
              <Field l="Approval Status" v={c.approval_status} />
              <Field l="Notice Period" v={`${c.notice_days??0} day(s)`} />
              <Field l="Last Working Date" v={fmtDate(c.last_working_date)} />
            </div>

            {/* Departmental checklist */}
            <div>
              <div className="flex items-center justify-between mb-2">
                <p className="label-caps">Department Clearance</p>
                <span className="text-[10px] font-bold" style={{ color:'var(--text-muted)' }}>{c.progress.cleared}/{c.progress.total} cleared</span>
              </div>
              <div className="space-y-2">
                {c.items.map(it=>{
                  const editing = editItem?.itemId===it.id
                  return (
                    <div key={it.id} className="rounded-xl p-3" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                      <div className="flex items-center justify-between flex-wrap gap-2">
                        <div>
                          <span className="text-sm font-bold" style={{ color:'var(--text-h)' }}>{it.department}</span>
                          {it.assigned_to && <span className="text-[10px] ml-2" style={{ color:'var(--text-muted)' }}>· {it.assigned_to}</span>}
                        </div>
                        <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={CLR_STATUS_STYLE[it.status]||{}}>{it.status}</span>
                      </div>
                      {it.remarks && !editing && <p className="text-[11px] mt-1" style={{ color:'var(--text-muted)' }}>“{it.remarks}”</p>}

                      {/* Per-department actions */}
                      {!readOnly && (
                        <div className="mt-2">
                          {editing ? (
                            <div>
                              <textarea rows={2} className="input-3d text-xs resize-none" value={editItem.remarks} onChange={e=>setEditItem(s=>({...s,remarks:e.target.value}))} placeholder={editItem.mode==='reject'?'Rejection reason…':(editItem.mode==='clear'?'Clearance note (optional)…':'Remarks…')}/>
                              <div className="flex gap-2 mt-2">
                                <button onClick={()=>setEditItem(null)} className="flex-1 py-1.5 rounded-lg text-[11px] font-bold" style={{ background:'var(--bg-card,var(--bg-input))', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
                                {editItem.mode==='reject' && <button onClick={()=>reject(it)} disabled={busy} className="flex-1 py-1.5 rounded-lg text-[11px] font-bold text-white" style={{ background:'linear-gradient(135deg,#f87171,#ef4444)' }}>Confirm Reject</button>}
                                {editItem.mode==='clear' && <button onClick={()=>clear(it)} disabled={busy} className="flex-1 py-1.5 rounded-lg text-[11px] font-bold text-white" style={{ background:'linear-gradient(135deg,#10b981,#059669)' }}>Confirm Clear</button>}
                                {editItem.mode==='remarks' && <button onClick={()=>saveRemarks(it)} disabled={busy} className="flex-1 py-1.5 rounded-lg text-[11px] font-bold text-white" style={{ background:GRAD }}>Save</button>}
                              </div>
                            </div>
                          ) : (
                            <div className="flex gap-1.5 flex-wrap">
                              {it.status==='Pending' && <button onClick={()=>start(it)} disabled={busy} className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-bold text-white" style={{ background:'linear-gradient(135deg,#f59e0b,#d97706)' }}><PlayCircle size={12}/> Start</button>}
                              {it.status==='In Progress' && <>
                                <button onClick={()=>setEditItem({ itemId:it.id, mode:'clear', remarks:'' })} disabled={busy} className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-bold text-white" style={{ background:'linear-gradient(135deg,#10b981,#059669)' }}><CheckCircle2 size={12}/> Clear</button>
                                <button onClick={()=>setEditItem({ itemId:it.id, mode:'reject', remarks:'' })} disabled={busy} className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-bold text-white" style={{ background:'linear-gradient(135deg,#f87171,#ef4444)' }}><XCircle size={12}/> Reject</button>
                                <button onClick={()=>setEditItem({ itemId:it.id, mode:'remarks', remarks:it.remarks||'' })} disabled={busy} className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-bold" style={{ background:'var(--bg-card,var(--bg-input))', color:'var(--text-h)', border:'1px solid var(--border)' }}><MessageSquare size={12}/> Remarks</button>
                              </>}
                            </div>
                          )}
                        </div>
                      )}
                    </div>
                  )
                })}
              </div>
            </div>

            <div><p className="label-caps mb-2 flex items-center gap-1.5"><Clock size={12}/> Timeline &amp; Audit History</p>
              <div className="space-y-2.5">{(c.timeline||[]).map((t,i)=>(
                <div key={i} className="flex gap-3">
                  <div className="mt-1.5 rounded-full flex-shrink-0" style={{ width:8, height:8, background:'#a78bfa' }}/>
                  <div><p className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{t.action}</p><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>{t.actor_name||'System'} · {t.created_at?new Date(t.created_at).toLocaleString():''}</p>{t.comment && <p className="text-[11px] mt-0.5" style={{ color:'var(--text-muted)' }}>“{t.comment}”</p>}</div>
                </div>
              ))}{(!c.timeline||!c.timeline.length) && <p className="text-xs" style={{ color:'var(--text-muted)' }}>No timeline yet.</p>}</div>
            </div>

            {readOnly && <p className="text-xs text-center py-2 rounded-xl flex items-center justify-center gap-1.5" style={{ background:'rgba(16,185,129,0.1)', color:'#10b981' }}><CheckCircle2 size={13}/> Clearance complete — read-only{c.completed_at?` · ${fmtDate(c.completed_at)}`:''}.</p>}
          </div>
        )}
      </div>
    </div>
  )
}

/* ── Full & Final Settlement (Phase 5) ── */
const GEN_FIELDS = [
  ['bonus','Bonus','earn'], ['incentives','Incentives','earn'], ['other_earnings','Other Earnings','earn'],
  ['notice_recovery','Notice Recovery','rec'], ['buyout_recovery','Buyout Recovery','rec'], ['asset_recovery','Asset Recovery','rec'], ['other_deductions','Other Deductions','rec'],
]

function FullAndFinal({ showToast }) {
  const [data, setData] = useState({ rows:[], stats:{ pending:0, generated:0, reviewed:0, approved:0, settled:0 }, months:[] })
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState(''); const [statusF, setStatusF] = useState('All'); const [typeF, setTypeF] = useState(''); const [deptF, setDeptF] = useState(''); const [monthF, setMonthF] = useState('')
  const [types, setTypes] = useState([]); const [view, setView] = useState(null)

  const load = useCallback(() => {
    setLoading(true)
    const params = {}
    if (statusF !== 'All') params.status = statusF
    if (typeF) params.exit_type_id = typeF
    if (deptF) params.department = deptF
    if (monthF) params.settlement_month = monthF
    if (search) params.search = search
    hrApi.exit.settlements.list(params).then(setData).catch(()=>showToast('Failed to load settlements','error')).finally(()=>setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusF, typeF, deptF, monthF, search])
  useEffect(() => { load() }, [load])
  useEffect(() => { hrApi.exit.types.list({ status:'Active' }).then(r=>setTypes(r.data||[])).catch(()=>{}) }, [])

  const departments = [...new Set(data.rows.map(r=>r.department).filter(Boolean))].sort()
  const KPIS = [
    { l:'Pending', v:data.stats.pending, c:'#94a3b8' },
    { l:'Generated', v:data.stats.generated, c:'#3b82f6' },
    { l:'Reviewed', v:data.stats.reviewed, c:'#f59e0b' },
    { l:'Approved', v:data.stats.approved, c:'#a78bfa' },
    { l:'Settled', v:data.stats.settled, c:'#10b981' },
  ]
  const hasF = statusF!=='All' || typeF || deptF || monthF || search

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-5 gap-3">{KPIS.map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}</div>

      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[170px]"><label className="label">Search</label><Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/><input className="input-3d pl-9 text-sm" placeholder="Employee name or code…" value={search} onChange={e=>setSearch(e.target.value)}/></div>
          <div className="min-w-[130px]"><label className="label">Department</label><select className="input-3d text-sm" value={deptF} onChange={e=>setDeptF(e.target.value)}><option value="">All</option>{departments.map(d=><option key={d} value={d}>{d}</option>)}</select></div>
          <div className="min-w-[130px]"><label className="label">Exit Type</label><select className="input-3d text-sm" value={typeF} onChange={e=>setTypeF(e.target.value)}><option value="">All</option>{types.map(t=><option key={t.id} value={t.id}>{t.name}</option>)}</select></div>
          <div className="min-w-[130px]"><label className="label">Settlement Month</label><select className="input-3d text-sm" value={monthF} onChange={e=>setMonthF(e.target.value)}><option value="">All</option>{(data.months||[]).map(m=><option key={m} value={m}>{m}</option>)}</select></div>
          <div className="min-w-[140px]"><label className="label">Status</label><select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['All','Pending','Generated','Reviewed','Approved','Settled'].map(s=><option key={s}>{s}</option>)}</select></div>
          {hasF && <button onClick={()=>{ setStatusF('All'); setTypeF(''); setDeptF(''); setMonthF(''); setSearch('') }} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
        </div>
      </div>

      {loading ? <HrLoading label="Loading settlements…" /> : data.rows.length===0 ? <HrEmpty icon={Wallet} title="No settlements yet" hint="Exits with completed clearance enter Full & Final automatically." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:1040 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Department','Exit Type','Settlement Amount','Status','Generated','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>{data.rows.map(r=>(
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                  <td className="px-3 py-2.5"><div className="font-bold" style={{ color:'var(--text-h)' }}>{r.employee_name}</div><div className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code}</div></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.department||'—'}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.exit_type||'—'}</td>
                  <td className="px-3 py-2.5 font-bold" style={{ color:r.net_settlement!=null?'var(--text-h)':'var(--text-muted)' }}>{r.net_settlement!=null?money(r.net_settlement):'Not generated'}</td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={SET_STATUS_STYLE[r.status]||{}}>{r.status}</span></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.generated_at?fmtDate(r.generated_at):'—'}</td>
                  <td className="px-3 py-2.5"><div className="flex gap-1.5 justify-end"><button title="Open" onClick={()=>setView(r.id)} className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold text-white" style={{ background:GRAD }}><Eye size={13}/> {r.status==='Pending'?'Generate':'Open'}</button></div></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}

      {view && <SettlementDrawer id={view} onClose={()=>setView(null)} onChanged={load} showToast={showToast} />}
    </div>
  )
}

function SettlementDrawer({ id, onClose, onChanged, showToast }) {
  const [s, setS] = useState(null); const [loading, setLoading] = useState(true); const [busy, setBusy] = useState(false)
  const [genForm, setGenForm] = useState(null)

  const reload = useCallback(() => { setLoading(true); return hrApi.exit.settlements.get(id).then(setS).finally(()=>setLoading(false)) }, [id])
  useEffect(() => { reload() }, [reload])

  const act = async (fn, ok) => { setBusy(true); try { await fn(); showToast(ok); setGenForm(null); await reload(); onChanged() } catch (e) { showToast(e.response?.data?.message||'Action failed','error') } finally { setBusy(false) } }
  const generate = () => act(()=>hrApi.exit.settlements.generate(id, genForm||{}), 'Settlement generated')
  const review = () => act(()=>hrApi.exit.settlements.review(id), 'Settlement reviewed')
  const approve = () => act(()=>hrApi.exit.settlements.approve(id), 'Settlement approved')
  const settle = () => act(()=>hrApi.exit.settlements.settle(id), 'Settlement settled')

  const Field = ({ l, v }) => <div><p className="label-caps mb-0.5">{l}</p><p className="text-sm font-semibold" style={{ color:'var(--text-h)' }}>{v||'—'}</p></div>
  const Row = ({ l, v, strong, neg }) => <div className="flex items-center justify-between px-3 py-2 rounded-lg" style={{ background:'var(--bg-input)' }}><span className="text-xs font-semibold" style={{ color:'var(--text-h)' }}>{l}</span><span className="text-sm font-bold" style={{ color:neg?'#f87171':(strong?'#10b981':'var(--text-h)') }}>{neg?'− ':''}{money(v)}</span></div>

  const c = s?.components
  return (
    <div className="fixed inset-0 z-[9998] flex justify-end">
      <div className="absolute inset-0" style={{ background:'rgba(0,0,0,0.4)' }} />
      <div className="relative h-full overflow-y-auto animate-[slideIn_0.25s_ease]" onClick={e=>e.stopPropagation()} style={{ width:'min(540px,98vw)', background:'var(--bg-card,var(--bg-input))', borderLeft:'1px solid var(--border)', boxShadow:'-10px 0 40px rgba(0,0,0,0.3)' }}>
        <div className="sticky top-0 z-10 flex items-center justify-between px-5 py-4" style={{ background:'var(--bg-card,var(--bg-input))', borderBottom:'1px solid var(--border)' }}>
          <h2 className="font-black text-base flex items-center gap-2" style={{ color:'var(--text-h)' }}><Wallet size={16} style={{ color:'#a78bfa' }}/> Full &amp; Final Settlement</h2>
          <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>
        {loading || !s ? <div className="p-6"><HrLoading label="Loading…" /></div> : (
          <div className="p-5 space-y-5">
            <div className="flex items-center justify-between">
              <div><p className="font-black text-lg" style={{ color:'var(--text-h)' }}>{s.employee_name}</p><p className="text-[11px] font-mono" style={{ color:'#a78bfa' }}>{s.employee_code}</p></div>
              <span className="text-[11px] font-bold px-2.5 py-1 rounded-lg" style={SET_STATUS_STYLE[s.status]||{}}>{s.status}</span>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <Field l="Department" v={s.department} />
              <Field l="Designation" v={s.designation} />
              <Field l="Exit Type" v={s.exit_type} />
              <Field l="Last Working Date" v={fmtDate(s.last_working_date)} />
              <Field l="Settlement Month" v={s.settlement_month} />
              <Field l="Net Settlement" v={s.net_settlement!=null?money(s.net_settlement):'—'} />
            </div>

            {s.status==='Pending' && (
              <div className="rounded-xl p-3" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                <p className="text-xs font-black mb-1" style={{ color:'var(--text-h)' }}>Generate Settlement</p>
                <p className="text-[11px] mb-3" style={{ color:'var(--text-muted)' }}>Salary, pending pay, leave encashment and gratuity are read from payroll automatically. Add any discretionary amounts below, then generate a frozen snapshot.</p>
                <div className="grid grid-cols-2 gap-2">
                  {GEN_FIELDS.map(([k,l,t])=>(
                    <div key={k}><label className="label" style={{ color:t==='rec'?'#f87171':'var(--text-muted)' }}>{l}</label><input type="number" min="0" step="0.01" className="input-3d text-sm" value={genForm?.[k]??''} onChange={e=>setGenForm(f=>({...(f||{}),[k]:e.target.value}))} placeholder="0"/></div>
                  ))}
                </div>
                <button onClick={generate} disabled={busy} className="mt-3 w-full py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:GRAD, opacity:busy?0.7:1 }}><FileText size={15}/> {busy?'Generating…':'Generate Settlement'}</button>
              </div>
            )}

            {c && (
              <>
                <div>
                  <p className="label-caps mb-2">Payroll Summary <span className="normal-case font-normal">(read-only snapshot)</span></p>
                  <div className="grid grid-cols-2 gap-4">
                    <Field l="Monthly Gross" v={money(c.context.monthly_gross)} />
                    <Field l="Monthly Basic" v={money(c.context.monthly_basic)} />
                    <Field l="Salary Structure" v={c.context.structure_name} />
                    <Field l="Tenure" v={`${c.context.tenure_years} yr`} />
                    <Field l="Leave Days" v={c.context.leave_days} />
                    <Field l="Policy" v={c.context.policy_name} />
                  </div>
                </div>
                <div>
                  <p className="label-caps mb-2">Settlement Components — Earnings</p>
                  <div className="space-y-1.5">
                    <Row l="Basic Salary (reference)" v={c.earnings.basic_salary} />
                    <Row l="Pending Salary" v={c.earnings.pending_salary} />
                    <Row l="Leave Encashment" v={c.earnings.leave_encashment} />
                    <Row l="Gratuity" v={c.earnings.gratuity} />
                    {/* How gratuity was arrived at. `legacy_default` means no
                        Gratuity statutory rule is configured and a formula built
                        into the code was used — worth surfacing, not hiding. */}
                    {c.context?.gratuity_basis && (
                      <p className="text-[10px] pl-1 -mt-0.5" style={{ color: c.context.gratuity_basis.source === 'legacy_default' ? '#fbbf24' : 'var(--text-muted)' }}>
                        {c.context.gratuity_basis.formula ? `${c.context.gratuity_basis.formula} · ` : ''}
                        {c.context.gratuity_basis.eligible_years ? `${c.context.gratuity_basis.eligible_years} yr · ` : ''}
                        {c.context.gratuity_basis.reason || 'From the configured Gratuity rule'}
                      </p>
                    )}
                    <Row l="Bonus" v={c.earnings.bonus} />
                    <Row l="Incentives" v={c.earnings.incentives} />
                    <Row l="Other Earnings" v={c.earnings.other_earnings} />
                    <Row l="Gross Earnings" v={c.totals.gross_earnings} strong />
                  </div>
                </div>
                <div>
                  <p className="label-caps mb-2">Recoveries &amp; Deductions</p>
                  <div className="space-y-1.5">
                    <Row l="Notice Recovery" v={c.recoveries.notice_recovery} neg />
                    <Row l="Buyout Recovery" v={c.recoveries.buyout_recovery} neg />
                    <Row l="Asset Recovery" v={c.recoveries.asset_recovery} neg />
                    <Row l="Other Deductions" v={c.recoveries.other_deductions} neg />
                    <Row l="Total Recoveries" v={c.totals.total_recoveries} neg />
                  </div>
                </div>
                <div className="flex items-center justify-between px-4 py-3 rounded-xl" style={{ background:GRAD }}>
                  <span className="text-sm font-black text-white">Net Settlement</span>
                  <span className="text-lg font-black text-white">{money(c.totals.net_settlement)}</span>
                </div>
              </>
            )}

            <div><p className="label-caps mb-2 flex items-center gap-1.5"><Clock size={12}/> Approval &amp; Audit Timeline</p>
              <div className="space-y-2.5">{(s.timeline||[]).map((t,i)=>(
                <div key={i} className="flex gap-3">
                  <div className="mt-1.5 rounded-full flex-shrink-0" style={{ width:8, height:8, background:'#a78bfa' }}/>
                  <div><p className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{t.action}</p><p className="text-[10px]" style={{ color:'var(--text-muted)' }}>{t.actor_name||'System'} · {t.created_at?new Date(t.created_at).toLocaleString():''}</p></div>
                </div>
              ))}{(!s.timeline||!s.timeline.length) && <p className="text-xs" style={{ color:'var(--text-muted)' }}>No timeline yet.</p>}</div>
            </div>

            <div className="flex gap-2 flex-wrap">
              {s.status==='Generated' && <button onClick={review} disabled={busy} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:'linear-gradient(135deg,#f59e0b,#d97706)' }}><Eye size={14}/> Mark Reviewed</button>}
              {s.status==='Reviewed' && <button onClick={approve} disabled={busy} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:GRAD }}><CheckCircle2 size={14}/> Approve</button>}
              {s.status==='Approved' && <button onClick={settle} disabled={busy} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2" style={{ background:'linear-gradient(135deg,#10b981,#059669)' }}><Wallet size={14}/> Mark Settled</button>}
              {c && <button onClick={()=>showToast('Summary export arrives with Exit Reports (next phase).','error')} className="flex-1 py-2.5 rounded-xl text-sm font-bold flex items-center justify-center gap-2" style={{ background:'var(--bg-input)', color:'var(--text-h)', border:'1px solid var(--border)' }}><Download size={14}/> Download Summary</button>}
            </div>
            {s.status==='Settled' && <p className="text-xs text-center py-2 rounded-xl flex items-center justify-center gap-1.5" style={{ background:'rgba(16,185,129,0.1)', color:'#10b981' }}><CheckCircle2 size={13}/> Settled — frozen &amp; read-only{s.settled_at?` · ${fmtDate(s.settled_at)}`:''}.</p>}
          </div>
        )}
      </div>
    </div>
  )
}
