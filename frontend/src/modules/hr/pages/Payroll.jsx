import { useState, useEffect, useCallback, useMemo } from 'react'
import { useTheme } from '@/context/ThemeContext'
import {
  Wallet, Coins, Search, Plus, Pencil, X, Power, Lock, Sparkles, Layers, Users, PlayCircle, ReceiptText,
  Trash2, IndianRupee, Eye, Calendar, CheckCircle2, Ban, Plug,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'

const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'
const TYPES = ['Earning', 'Deduction', 'Benefit']
const CALC_TYPES = ['Fixed', 'Percentage']
const TYPE_C = { Earning:{c:'#10b981',bg:'rgba(16,185,129,0.12)'}, Deduction:{c:'#f87171',bg:'rgba(239,68,68,0.1)'}, Benefit:{c:'#3b82f6',bg:'rgba(59,130,246,0.12)'} }
const money = v => v === null || v === undefined || v === '' ? '—' : `₹${Number(v).toLocaleString('en-IN')}`

// Payroll module tabs. Only "Salary Components" is built (Phase 1); the rest are
// reserved structure for future phases — shown, locked, never routed to a page.
const TABS = [
  { key:'components', label:'Salary Components', icon:Coins,       ready:true },
  { key:'structures', label:'Salary Structures', icon:Layers,      ready:true },
  { key:'employee',   label:'Employee Salary',   icon:Users,       ready:true },
  { key:'processing', label:'Payroll Processing', icon:PlayCircle,  ready:true },
  { key:'payslips',   label:'Payslips',          icon:ReceiptText, ready:false },
]

export default function Payroll() {
  useTheme()
  const [tab, setTab] = useState('components')
  const [toast, setToast] = useState(null)
  const showToast = (msg, type='success') => { setToast({msg,type}); setTimeout(()=>setToast(null),3000) }

  const current = TABS.find(t => t.key === tab)

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl" style={{ background:toast.type==='success'?'linear-gradient(135deg,#10b981,#059669)':'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <p className="label-caps mb-1">HR Records</p>
          <h1 className="font-black flex items-center gap-2" style={{ fontSize:'clamp(1.3rem,2vw,1.7rem)', color:'var(--text-h)', letterSpacing:'-0.02em' }}>
            <Wallet size={22} style={{ color:'#a78bfa' }}/> <span className="text-gradient">Payroll</span>
          </h1>
        </div>
      </div>

      {/* Tabs */}
      <div className="flex gap-1.5 flex-wrap">
        {TABS.map(t => {
          const active = tab === t.key
          return (
            <button key={t.key} onClick={()=>setTab(t.key)}
              className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold transition-all"
              style={{ background: active ? GRAD : 'var(--bg-input)', color: active ? '#fff' : 'var(--text-muted)', border: active ? 'none' : '1px solid var(--border)' }}>
              <t.icon size={15}/> {t.label}
              {!t.ready && <Lock size={11} style={{ opacity:0.7 }}/>}
            </button>
          )
        })}
      </div>

      {tab === 'components' ? <SalaryComponents showToast={showToast} />
        : tab === 'structures' ? <SalaryStructures showToast={showToast} />
        : tab === 'employee' ? <EmployeeSalary showToast={showToast} />
        : tab === 'processing' ? <PayrollProcessing showToast={showToast} />
        : (
          <div className="card-3d flex flex-col items-center justify-center text-center" style={{ padding:'56px 20px' }}>
            <div className="rounded-2xl flex items-center justify-center mb-3" style={{ width:60, height:60, background:'rgba(124,58,237,0.1)' }}><current.icon size={26} style={{ color:'#a78bfa' }}/></div>
            <p className="text-sm font-black" style={{ color:'var(--text-h)' }}>{current.label}</p>
            <p className="text-xs mt-1" style={{ color:'var(--text-muted)' }}>Coming in a future Payroll phase.</p>
            <p className="text-[11px] mt-2 max-w-md" style={{ color:'var(--text-muted)' }}>This phase delivers the Salary Components master only. Structures, employee salary, processing and payslips build on top of it later.</p>
          </div>
        )}
    </div>
  )
}

/* ────────────────────────────────────────────────────────────────────────
   Salary Components master
   ──────────────────────────────────────────────────────────────────────── */
const EMPTY = { name:'', code:'', type:'Earning', calculation_type:'Fixed', amount_value:'', percentage_value:'', based_on:'Basic', description:'', is_active:true }

function SalaryComponents({ showToast }) {
  const [rows, setRows] = useState([])
  const [stats, setStats] = useState({ total:0, earnings:0, deductions:0, benefits:0, active:0 })
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [typeF, setTypeF] = useState('All')
  const [statusF, setStatusF] = useState('All')
  const [modal, setModal] = useState(null)     // { editing, form }
  const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    const params = {}
    if (typeF !== 'All') params.type = typeF
    if (statusF !== 'All') params.status = statusF
    if (search) params.search = search
    hrApi.payroll.salaryComponents.list(params)
      .then(res => { setRows(res.data || []); setStats(res.stats || stats) })
      .catch(() => showToast('Failed to load salary components', 'error'))
      .finally(() => setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [typeF, statusF, search])
  useEffect(() => { load() }, [load])

  const openCreate = () => setModal({ editing:null, form:{ ...EMPTY } })
  const openEdit = (r) => setModal({ editing:r.id, form:{
    name:r.name, code:r.code, type:r.type, calculation_type:r.calculation_type,
    amount_value:r.amount_value ?? '', percentage_value:r.percentage_value ?? '',
    based_on:r.based_on || 'Basic', description:r.description || '', is_active:r.is_active,
  }})

  const save = async () => {
    const { editing, form } = modal
    if (!form.name.trim() || !form.code.trim()) return showToast('Name and code are required', 'error')
    if (form.calculation_type === 'Fixed' && form.amount_value === '') return showToast('Amount value is required for a fixed component', 'error')
    if (form.calculation_type === 'Percentage' && form.percentage_value === '') return showToast('Percentage value is required', 'error')
    // Send only the value relevant to the chosen calculation type.
    const payload = { ...form,
      amount_value: form.calculation_type === 'Fixed' ? form.amount_value : null,
      percentage_value: form.calculation_type === 'Percentage' ? form.percentage_value : null,
      based_on: form.calculation_type === 'Percentage' ? form.based_on : null,
    }
    setSaving(true)
    try {
      if (editing) await hrApi.payroll.salaryComponents.update(editing, payload)
      else await hrApi.payroll.salaryComponents.create(payload)
      showToast(`Component ${editing ? 'updated' : 'created'}`)
      setModal(null); load()
    } catch (e) { showToast(e.response?.data?.message || 'Save failed', 'error') }
    finally { setSaving(false) }
  }

  const toggleStatus = async (r) => {
    try { await hrApi.payroll.salaryComponents.setStatus(r.id, !r.is_active); showToast(r.is_active ? 'Deactivated' : 'Activated'); load() }
    catch (e) { showToast(e.response?.data?.message || 'Failed', 'error') }
  }

  const KPIS = [
    { l:'Total', v:stats.total, c:'#7C3AED' },
    { l:'Earnings', v:stats.earnings, c:'#10b981' },
    { l:'Deductions', v:stats.deductions, c:'#f87171' },
    { l:'Benefits', v:stats.benefits, c:'#3b82f6' },
    { l:'Active', v:stats.active, c:'#0ea5e9' },
  ]
  const hasFilters = typeF!=='All' || statusF!=='All' || search

  return (
    <div className="space-y-4">
      {/* KPIs */}
      <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
        {KPIS.map(k => (
          <div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>
        ))}
      </div>

      {/* Filters + add */}
      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[200px]">
            <label className="label">Search</label>
            <Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/>
            <input className="input-3d pl-9 text-sm" placeholder="Name, code, description…" value={search} onChange={e=>setSearch(e.target.value)}/>
          </div>
          <div className="min-w-[150px]">
            <label className="label">Type</label>
            <select className="input-3d text-sm" value={typeF} onChange={e=>setTypeF(e.target.value)}>{['All',...TYPES].map(t=><option key={t}>{t}</option>)}</select>
          </div>
          <div className="min-w-[130px]">
            <label className="label">Status</label>
            <select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['All','Active','Inactive'].map(s=><option key={s}>{s}</option>)}</select>
          </div>
          {hasFilters && <button onClick={()=>{ setTypeF('All'); setStatusF('All'); setSearch('') }} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
          <button onClick={openCreate} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white ml-auto" style={{ background:GRAD, boxShadow:'0 4px 14px rgba(124,58,237,0.4)' }}><Plus size={15}/> Add Component</button>
        </div>
      </div>

      {loading ? <HrLoading label="Loading salary components…" />
        : rows.length === 0 ? <HrEmpty icon={Coins} title="No salary components yet" hint={hasFilters ? 'No components match the current filters.' : 'Create reusable components (Basic, HRA, PF…) that future salary structures will build on.'} />
        : (
          <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:760 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Component','Code','Type','Calculation','Value','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps whitespace-nowrap ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>
                {rows.map(r => {
                  const tc = TYPE_C[r.type] || { c:'var(--text-muted)', bg:'var(--bg-input)' }
                  return (
                    <tr key={r.id} style={{ borderBottom:'1px solid var(--border)', opacity:r.is_active?1:0.55 }}>
                      <td className="px-3 py-2.5 font-bold" style={{ color:'var(--text-h)' }}>{r.name}</td>
                      <td className="px-3 py-2.5 font-mono font-bold" style={{ color:'#a78bfa' }}>{r.code}</td>
                      <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:tc.bg, color:tc.c }}>{r.type}</span></td>
                      <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.calculation_type}</td>
                      <td className="px-3 py-2.5 font-semibold" style={{ color:'var(--text-h)' }}>{r.calculation_type==='Percentage' ? `${Number(r.percentage_value)}% of ${r.based_on||'Basic'}` : money(r.amount_value)}</td>
                      <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={r.is_active?{background:'rgba(16,185,129,0.12)',color:'#10b981'}:{background:'var(--bg-input)',color:'var(--text-muted)'}}>{r.is_active?'Active':'Inactive'}</span></td>
                      <td className="px-3 py-2.5">
                        <div className="flex gap-1.5 justify-end">
                          <button onClick={()=>openEdit(r)} title="Edit" className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Pencil size={13}/></button>
                          <button onClick={()=>toggleStatus(r)} title={r.is_active?'Deactivate':'Activate'} className="p-1.5 rounded-lg" style={r.is_active?{background:'rgba(239,68,68,0.1)',color:'#f87171'}:{background:'rgba(16,185,129,0.1)',color:'#10b981'}}><Power size={13}/></button>
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}

      {/* AI-ready extension point (no AI implemented). */}
      <div className="rounded-xl p-3 flex items-start gap-2.5" style={{ background:'rgba(124,58,237,0.05)', border:'1px dashed rgba(124,58,237,0.3)' }}>
        <Sparkles size={15} style={{ color:'#a78bfa', marginTop:1, flexShrink:0 }}/>
        <div>
          <p className="text-[11px] font-bold" style={{ color:'#a78bfa' }}>AI Insights <span className="font-normal" style={{ color:'var(--text-muted)' }}>· coming soon</span></p>
          <p className="text-[11px] mt-0.5" style={{ color:'var(--text-muted)' }}>Will suggest salary benchmarks, detect duplicate components, and surface cost/optimization analysis.</p>
        </div>
      </div>

      {/* Create / Edit modal */}
      {modal && (
        <div className="modal-backdrop" onClick={()=>setModal(null)}>
          <div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()} style={{ maxHeight:'90vh', overflowY:'auto' }}>
            <div className="flex items-center justify-between mb-5">
              <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{modal.editing ? 'Edit Component' : 'Add Salary Component'}</h2>
              <button onClick={()=>setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div><label className="label">Component Name *</label><input className="input-3d text-sm" placeholder="e.g. House Rent Allowance" value={modal.form.name} onChange={e=>setModal(m=>({...m,form:{...m.form,name:e.target.value}}))}/></div>
              <div><label className="label">Component Code *</label><input className="input-3d text-sm" placeholder="e.g. HRA" value={modal.form.code} onChange={e=>setModal(m=>({...m,form:{...m.form,code:e.target.value}}))}/></div>
              <div><label className="label">Type</label>
                <select className="input-3d text-sm" value={modal.form.type} onChange={e=>setModal(m=>({...m,form:{...m.form,type:e.target.value}}))}>{TYPES.map(t=><option key={t}>{t}</option>)}</select>
              </div>
              <div><label className="label">Calculation Type</label>
                <select className="input-3d text-sm" value={modal.form.calculation_type} onChange={e=>setModal(m=>({...m,form:{...m.form,calculation_type:e.target.value}}))}>{CALC_TYPES.map(t=><option key={t}>{t}</option>)}</select>
              </div>
              {modal.form.calculation_type === 'Fixed' ? (
                <div className="col-span-2"><label className="label">Amount Value (₹)</label><input type="number" min="0" className="input-3d text-sm" placeholder="e.g. 30000" value={modal.form.amount_value} onChange={e=>setModal(m=>({...m,form:{...m.form,amount_value:e.target.value}}))}/></div>
              ) : (
                <>
                  <div><label className="label">Percentage Value (%)</label><input type="number" min="0" max="100" className="input-3d text-sm" placeholder="e.g. 40" value={modal.form.percentage_value} onChange={e=>setModal(m=>({...m,form:{...m.form,percentage_value:e.target.value}}))}/></div>
                  <div><label className="label">Based On</label><input className="input-3d text-sm" placeholder="e.g. Basic" value={modal.form.based_on} onChange={e=>setModal(m=>({...m,form:{...m.form,based_on:e.target.value}}))}/></div>
                </>
              )}
              <div className="col-span-2"><label className="label">Description</label><textarea rows={2} className="input-3d text-sm resize-none" value={modal.form.description} onChange={e=>setModal(m=>({...m,form:{...m.form,description:e.target.value}}))}/></div>
              {modal.editing && (
                <div className="col-span-2 flex items-center gap-2">
                  <input type="checkbox" id="sc-active" checked={modal.form.is_active} onChange={e=>setModal(m=>({...m,form:{...m.form,is_active:e.target.checked}}))}/>
                  <label htmlFor="sc-active" className="text-xs font-semibold" style={{ color:'var(--text-muted)' }}>Active</label>
                </div>
              )}
            </div>
            <div className="flex gap-3 pt-5">
              <button onClick={()=>setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
              <button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Saving…':modal.editing?'Save Changes':'Add Component'}</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

/* ────────────────────────────────────────────────────────────────────────
   Salary Structures — compose components into a computed CTC breakdown
   ──────────────────────────────────────────────────────────────────────── */
const inr = v => `₹${Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`

// Client-side CTC resolver — mirrors SalaryStructureService for a live preview.
// The server recomputes authoritatively on save; this is instant feedback only.
function computeBreakdown(lines, compById) {
  const byKey = {}
  lines.forEach((l, i) => {
    const c = compById[l.component_id]; if (!c) return
    byKey[c.name.toLowerCase()] = i
    if (c.code) byKey[c.code.toLowerCase()] = i
  })
  const resolved = {}
  for (let pass = 0; pass < 12 && Object.keys(resolved).length < lines.length; pass++) {
    let progress = false
    lines.forEach((l, i) => {
      if (i in resolved) return
      const c = compById[l.component_id]; if (!c) { resolved[i] = 0; progress = true; return }
      if (c.calculation_type === 'Fixed') { resolved[i] = Number(l.amount ?? c.amount_value ?? 0); progress = true; return }
      const base = String(l.based_on || c.based_on || 'basic').toLowerCase()
      const bi = byKey[base]
      if (bi !== undefined && bi in resolved) {
        const pct = Number(l.percentage ?? c.percentage_value ?? 0)
        resolved[i] = Math.round(pct / 100 * resolved[bi] * 100) / 100
        progress = true
      }
    })
    if (!progress) break
  }
  let earnings = 0, benefits = 0, deductions = 0
  lines.forEach((l, i) => {
    const c = compById[l.component_id]; if (!c) return
    const a = resolved[i] ?? 0
    if (c.type === 'Earning') earnings += a
    if (c.type === 'Benefit') benefits += a
    if (c.type === 'Deduction') deductions += a
  })
  return { resolved, earnings, benefits, deductions, ctc: earnings + benefits, net: earnings - deductions }
}

function SalaryStructures({ showToast }) {
  const [rows, setRows] = useState([])
  const [stats, setStats] = useState({ total: 0, active: 0 })
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [statusF, setStatusF] = useState('All')
  const [components, setComponents] = useState([])   // active components for the picker
  const [orgOpts, setOrgOpts] = useState({ grades: [], designations: [] })
  const [builder, setBuilder] = useState(null)       // { editing, form }
  const [saving, setSaving] = useState(false)

  const compById = useMemo(() => Object.fromEntries(components.map(c => [c.id, c])), [components])

  const load = useCallback(() => {
    setLoading(true)
    const params = {}
    if (statusF !== 'All') params.status = statusF
    if (search) params.search = search
    hrApi.payroll.salaryStructures.list(params)
      .then(res => { setRows(res.data || []); setStats(res.stats || stats) })
      .catch(() => showToast('Failed to load salary structures', 'error'))
      .finally(() => setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusF, search])
  useEffect(() => { load() }, [load])

  // Reuse existing masters — active components + Organization Setup grades/designations.
  useEffect(() => {
    hrApi.payroll.salaryComponents.list({ status: 'Active' }).then(r => setComponents(r.data || [])).catch(() => {})
    hrApi.organization.options().then(o => setOrgOpts({ grades: o.grades || [], designations: o.designations || [] })).catch(() => {})
  }, [])

  const openCreate = () => setBuilder({ editing: null, form: { name: '', code: '', grade_id: '', designation_id: '', description: '', lines: [] } })
  const openEdit = async (row) => {
    try {
      const full = await hrApi.payroll.salaryStructures.get(row.id)
      setBuilder({ editing: full.id, form: {
        name: full.name, code: full.code || '', grade_id: full.grade_id || '', designation_id: full.designation_id || '',
        description: full.description || '',
        lines: full.lines.map(l => ({ component_id: l.component_id, amount: l.amount ?? '', percentage: l.percentage ?? '', based_on: l.based_on || '' })),
      }})
    } catch { showToast('Failed to open structure', 'error') }
  }

  const toggleStatus = async (r) => {
    try { await hrApi.payroll.salaryStructures.setStatus(r.id, !r.is_active); showToast(r.is_active ? 'Deactivated' : 'Activated'); load() }
    catch (e) { showToast(e.response?.data?.message || 'Failed', 'error') }
  }

  const save = async () => {
    const f = builder.form
    if (!f.name.trim()) return showToast('Structure name is required', 'error')
    if (!f.lines.length) return showToast('Add at least one component', 'error')
    setSaving(true)
    try {
      const payload = { name: f.name, code: f.code || null, grade_id: f.grade_id || null, designation_id: f.designation_id || null, description: f.description || null,
        lines: f.lines.map(l => ({ component_id: l.component_id, amount: l.amount === '' ? null : l.amount, percentage: l.percentage === '' ? null : l.percentage, based_on: l.based_on || null })) }
      if (builder.editing) await hrApi.payroll.salaryStructures.update(builder.editing, payload)
      else await hrApi.payroll.salaryStructures.create(payload)
      showToast(`Structure ${builder.editing ? 'updated' : 'created'}`)
      setBuilder(null); load()
    } catch (e) { showToast(e.response?.data?.message || 'Save failed', 'error') }
    finally { setSaving(false) }
  }

  const hasFilters = statusF !== 'All' || search

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {[{l:'Total Structures',v:stats.total,c:'#7C3AED'},{l:'Active',v:stats.active,c:'#0ea5e9'}].map(k=>(
          <div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>
        ))}
      </div>

      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[200px]">
            <label className="label">Search</label>
            <Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/>
            <input className="input-3d pl-9 text-sm" placeholder="Name or code…" value={search} onChange={e=>setSearch(e.target.value)}/>
          </div>
          <div className="min-w-[130px]">
            <label className="label">Status</label>
            <select className="input-3d text-sm" value={statusF} onChange={e=>setStatusF(e.target.value)}>{['All','Active','Inactive'].map(s=><option key={s}>{s}</option>)}</select>
          </div>
          {hasFilters && <button onClick={()=>{ setStatusF('All'); setSearch('') }} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
          <button onClick={openCreate} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white ml-auto" style={{ background:GRAD, boxShadow:'0 4px 14px rgba(124,58,237,0.4)' }}><Plus size={15}/> New Structure</button>
        </div>
      </div>

      {loading ? <HrLoading label="Loading salary structures…" />
        : rows.length === 0 ? <HrEmpty icon={Layers} title="No salary structures yet" hint={hasFilters ? 'No structures match the current filters.' : 'Compose components (Basic, HRA, PF…) into a named structure with a computed CTC.'} />
        : (
          <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:760 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Structure','Grade / Designation','Components','CTC','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps whitespace-nowrap ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>
                {rows.map(r => (
                  <tr key={r.id} style={{ borderBottom:'1px solid var(--border)', opacity:r.is_active?1:0.55 }}>
                    <td className="px-3 py-2.5"><span className="font-bold" style={{ color:'var(--text-h)' }}>{r.name}</span>{r.code && <span className="ml-2 text-[10px] font-mono font-bold" style={{ color:'#a78bfa' }}>{r.code}</span>}</td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{[r.grade_name, r.designation_name].filter(Boolean).join(' · ') || '—'}</td>
                    <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}>{r.lines?.length || 0}</span></td>
                    <td className="px-3 py-2.5 font-black" style={{ color:'#10b981' }}>{inr(r.totals?.ctc)}<span className="text-[10px] font-medium" style={{ color:'var(--text-muted)' }}>/mo</span></td>
                    <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={r.is_active?{background:'rgba(16,185,129,0.12)',color:'#10b981'}:{background:'var(--bg-input)',color:'var(--text-muted)'}}>{r.is_active?'Active':'Inactive'}</span></td>
                    <td className="px-3 py-2.5">
                      <div className="flex gap-1.5 justify-end">
                        <button onClick={()=>openEdit(r)} title="View / Edit" className="p-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Eye size={13}/></button>
                        <button onClick={()=>toggleStatus(r)} title={r.is_active?'Deactivate':'Activate'} className="p-1.5 rounded-lg" style={r.is_active?{background:'rgba(239,68,68,0.1)',color:'#f87171'}:{background:'rgba(16,185,129,0.1)',color:'#10b981'}}><Power size={13}/></button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

      {builder && (
        <StructureBuilder builder={builder} setBuilder={setBuilder} compById={compById} components={components} orgOpts={orgOpts} saving={saving} onSave={save} />
      )}
    </div>
  )
}

/* Structure builder modal — line editor (left) + live CTC breakdown (right). */
function StructureBuilder({ builder, setBuilder, compById, components, orgOpts, saving, onSave }) {
  const f = builder.form
  const setForm = (patch) => setBuilder(b => ({ ...b, form: { ...b.form, ...patch } }))
  const setLine = (i, patch) => setForm({ lines: f.lines.map((l, idx) => idx === i ? { ...l, ...patch } : l) })
  const removeLine = (i) => setForm({ lines: f.lines.filter((_, idx) => idx !== i) })
  const addComponent = (cid) => {
    if (!cid) return
    const c = compById[cid]
    if (f.lines.some(l => l.component_id === Number(cid))) return
    setForm({ lines: [...f.lines, { component_id: Number(cid), amount: '', percentage: '', based_on: c?.calculation_type === 'Percentage' ? (c.based_on || '') : '' }] })
  }
  const bd = computeBreakdown(f.lines, compById)
  // Component names available as a percentage base (any line already added).
  const baseOptions = f.lines.map(l => compById[l.component_id]).filter(Boolean).map(c => c.name)

  const TYPE_ORDER = { Earning:0, Benefit:1, Deduction:2 }
  const ordered = f.lines.map((l, i) => ({ l, i, c: compById[l.component_id] }))
    .sort((a, b) => (TYPE_ORDER[a.c?.type] ?? 9) - (TYPE_ORDER[b.c?.type] ?? 9))

  return (
    <div className="modal-backdrop" onClick={()=>setBuilder(null)}>
      <div className="modal-box" onClick={e=>e.stopPropagation()} style={{ maxWidth:920, width:'95%', maxHeight:'92vh', overflowY:'auto' }}>
        <div className="flex items-center justify-between mb-5">
          <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{builder.editing ? 'Edit Salary Structure' : 'New Salary Structure'}</h2>
          <button onClick={()=>setBuilder(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>

        {/* Header fields */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
          <div className="col-span-2"><label className="label">Structure Name *</label><input className="input-3d text-sm" placeholder="e.g. Software Engineer Grade A" value={f.name} onChange={e=>setForm({ name:e.target.value })}/></div>
          <div><label className="label">Code</label><input className="input-3d text-sm" placeholder="e.g. SE-A" value={f.code} onChange={e=>setForm({ code:e.target.value })}/></div>
          <div><label className="label">Grade</label>
            <select className="input-3d text-sm" value={f.grade_id} onChange={e=>setForm({ grade_id:e.target.value })}><option value="">—</option>{orgOpts.grades.map(g=><option key={g.id} value={g.id}>{g.name}</option>)}</select>
          </div>
          <div className="col-span-2"><label className="label">Designation</label>
            <select className="input-3d text-sm" value={f.designation_id} onChange={e=>setForm({ designation_id:e.target.value })}><option value="">—</option>{orgOpts.designations.map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select>
          </div>
          <div className="col-span-2"><label className="label">Add Component</label>
            <select className="input-3d text-sm" value="" onChange={e=>{ addComponent(e.target.value); e.target.value='' }}>
              <option value="">Select a component to add…</option>
              {TYPES.map(t => {
                const opts = components.filter(c => c.type === t && !f.lines.some(l => l.component_id === c.id))
                return opts.length ? <optgroup key={t} label={t}>{opts.map(c=><option key={c.id} value={c.id}>{c.name} ({c.code})</option>)}</optgroup> : null
              })}
            </select>
          </div>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
          {/* Lines */}
          <div className="lg:col-span-2 space-y-2">
            {f.lines.length === 0 && <p className="text-xs py-6 text-center" style={{ color:'var(--text-muted)' }}>No components yet — add from the dropdown above.</p>}
            {ordered.map(({ l, i, c }) => {
              if (!c) return null
              const tc = TYPE_C[c.type] || {}
              return (
                <div key={i} className="rounded-xl p-2.5" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:tc.bg, color:tc.c }}>{c.type}</span>
                    <span className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{c.name}</span>
                    <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{c.code}</span>
                    <span className="ml-auto text-xs font-black" style={{ color:'var(--text-h)' }}>{inr(bd.resolved[i])}</span>
                    <button onClick={()=>removeLine(i)} className="p-1 rounded-lg" style={{ background:'rgba(239,68,68,0.1)', color:'#f87171' }}><Trash2 size={12}/></button>
                  </div>
                  <div className="flex items-center gap-2 mt-2">
                    {c.calculation_type === 'Fixed' ? (
                      <div className="flex items-center gap-1.5">
                        <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>Amount ₹</span>
                        <input type="number" min="0" className="input-3d text-xs" style={{ width:120, padding:'6px 8px' }} placeholder={String(c.amount_value ?? '0')} value={l.amount} onChange={e=>setLine(i,{ amount:e.target.value })}/>
                      </div>
                    ) : (
                      <>
                        <div className="flex items-center gap-1.5">
                          <input type="number" min="0" max="100" className="input-3d text-xs" style={{ width:70, padding:'6px 8px' }} placeholder={String(c.percentage_value ?? '0')} value={l.percentage} onChange={e=>setLine(i,{ percentage:e.target.value })}/>
                          <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>% of</span>
                        </div>
                        <select className="input-3d text-xs" style={{ width:160, padding:'6px 8px' }} value={l.based_on || c.based_on || ''} onChange={e=>setLine(i,{ based_on:e.target.value })}>
                          <option value="">(base)</option>
                          {baseOptions.filter(n => n !== c.name).map(n => <option key={n} value={n}>{n}</option>)}
                        </select>
                      </>
                    )}
                  </div>
                </div>
              )
            })}
          </div>

          {/* Live breakdown */}
          <div className="rounded-xl p-4 h-fit" style={{ background:'var(--bg-input)', border:'1px solid var(--border)', position:'sticky', top:0 }}>
            <p className="text-[11px] font-bold uppercase mb-3 flex items-center gap-1.5" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}><IndianRupee size={12}/> CTC Breakdown</p>
            {[['Gross Earnings', bd.earnings, '#10b981'],['Employer Benefits', bd.benefits, '#3b82f6'],['Deductions', bd.deductions, '#f87171'],['Net Pay', bd.net, 'var(--text-h)']].map(([l,v,c])=>(
              <div key={l} className="flex justify-between py-1.5" style={{ borderBottom:'1px dashed var(--border)' }}>
                <span className="text-xs" style={{ color:'var(--text-muted)' }}>{l}</span>
                <span className="text-xs font-bold" style={{ color:c }}>{inr(v)}</span>
              </div>
            ))}
            <div className="flex justify-between pt-3 mt-1">
              <span className="text-sm font-black" style={{ color:'var(--text-h)' }}>CTC / month</span>
              <span className="text-sm font-black" style={{ color:'#7C3AED' }}>{inr(bd.ctc)}</span>
            </div>
            <p className="text-[10px] mt-2" style={{ color:'var(--text-muted)' }}>CTC = Gross Earnings + Employer Benefits. Recomputed on save.</p>
          </div>
        </div>

        <div className="flex gap-3 pt-5">
          <button onClick={()=>setBuilder(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
          <button onClick={onSave} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Saving…':builder.editing?'Save Changes':'Create Structure'}</button>
        </div>
      </div>
    </div>
  )
}

/* ────────────────────────────────────────────────────────────────────────
   Employee Salary — assign a structure to an employee (frozen snapshot + history)
   ──────────────────────────────────────────────────────────────────────── */
function EmployeeSalary({ showToast }) {
  const [employees, setEmployees] = useState([])
  const [structures, setStructures] = useState([])
  const [salaryByEmp, setSalaryByEmp] = useState({})  // employeeId -> current salary (or null)
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [manage, setManage] = useState(null)          // employee being managed

  useEffect(() => {
    setLoading(true)
    Promise.all([
      hrApi.employees.list({ per_page: 200 }),
      hrApi.payroll.salaryStructures.list({ status: 'Active' }),
    ]).then(([emps, str]) => {
      const list = Array.isArray(emps) ? emps : (emps?.data ?? [])
      setEmployees(list)
      setStructures(str.data || [])
      // Fetch each employee's current salary (small tenant; per-employee endpoint).
      return Promise.all(list.map(e =>
        hrApi.payroll.employeeSalary.get(e.id).then(s => [e.id, s.current]).catch(() => [e.id, null])
      ))
    }).then(pairs => setSalaryByEmp(Object.fromEntries(pairs || [])))
      .catch(() => showToast('Failed to load employee salaries', 'error'))
      .finally(() => setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const refreshOne = (employeeId, current) => setSalaryByEmp(m => ({ ...m, [employeeId]: current }))

  const filtered = employees.filter(e => {
    if (!search) return true
    const s = search.toLowerCase()
    return (e.name||'').toLowerCase().includes(s) || (e.employee_code||'').toLowerCase().includes(s) || (e.department||'').toLowerCase().includes(s)
  })
  const assignedCount = Object.values(salaryByEmp).filter(Boolean).length

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div className="kpi-3d"><p className="text-3xl font-black" style={{ color:'#7C3AED' }}>{employees.length}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>Employees</p></div>
        <div className="kpi-3d"><p className="text-3xl font-black" style={{ color:'#10b981' }}>{assignedCount}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>Salary Assigned</p></div>
        <div className="kpi-3d"><p className="text-3xl font-black" style={{ color:'#f59e0b' }}>{employees.length - assignedCount}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>Pending</p></div>
      </div>

      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="relative">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color:'var(--text-muted)' }}/>
          <input className="input-3d pl-9 text-sm" placeholder="Search employee by name, code or department…" value={search} onChange={e=>setSearch(e.target.value)}/>
        </div>
      </div>

      {loading ? <HrLoading label="Loading employee salaries…" />
        : filtered.length === 0 ? <HrEmpty icon={Users} title="No employees found" hint="Employees are created from the recruitment lifecycle." />
        : (
          <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:760 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Department','Salary Structure','Monthly CTC','Status','Actions'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps whitespace-nowrap ${h==='Actions'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>
                {filtered.map(e => {
                  const sal = salaryByEmp[e.id]
                  return (
                    <tr key={e.id} style={{ borderBottom:'1px solid var(--border)' }}>
                      <td className="px-3 py-2.5"><span className="font-bold" style={{ color:'var(--text-h)' }}>{e.name}</span><span className="ml-2 text-[10px] font-mono font-bold" style={{ color:'#a78bfa' }}>{e.employee_code}</span></td>
                      <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{e.department||'—'}</td>
                      <td className="px-3 py-2.5" style={{ color:'var(--text-h)' }}>{sal ? sal.structure_name : <span style={{ color:'var(--text-muted)' }}>Not assigned</span>}</td>
                      <td className="px-3 py-2.5 font-black" style={{ color: sal?'#10b981':'var(--text-muted)' }}>{sal ? inr(sal.monthly_ctc) : '—'}</td>
                      <td className="px-3 py-2.5">{sal ? <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:'rgba(16,185,129,0.12)', color:'#10b981' }}>Active</span> : <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>Pending</span>}</td>
                      <td className="px-3 py-2.5 text-right">
                        <button onClick={()=>setManage(e)} className="text-[11px] font-bold px-3 py-1.5 rounded-lg text-white" style={{ background:GRAD }}>{sal ? 'Manage' : 'Assign'}</button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}

      {/* AI-ready extension point (no AI implemented). */}
      <div className="rounded-xl p-3 flex items-start gap-2.5" style={{ background:'rgba(124,58,237,0.05)', border:'1px dashed rgba(124,58,237,0.3)' }}>
        <Sparkles size={15} style={{ color:'#a78bfa', marginTop:1, flexShrink:0 }}/>
        <div>
          <p className="text-[11px] font-bold" style={{ color:'#a78bfa' }}>AI Insights <span className="font-normal" style={{ color:'var(--text-muted)' }}>· coming soon</span></p>
          <p className="text-[11px] mt-0.5" style={{ color:'var(--text-muted)' }}>Will suggest salary benchmarks, increment recommendations, pay-gap analysis, and salary anomaly detection.</p>
        </div>
      </div>

      {manage && <ManageSalary employee={manage} structures={structures} onClose={()=>setManage(null)} onChanged={(cur)=>refreshOne(manage.id, cur)} showToast={showToast} />}
    </div>
  )
}

/* Per-employee salary drawer: current snapshot + assign/revise + history. */
function ManageSalary({ employee, structures, onClose, onChanged, showToast }) {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [structureId, setStructureId] = useState('')
  const [effectiveFrom, setEffectiveFrom] = useState('')
  const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    hrApi.payroll.employeeSalary.get(employee.id)
      .then(d => { setData(d); onChanged(d.current) })
      .catch(() => showToast('Failed to load salary', 'error'))
      .finally(() => setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [employee.id])
  useEffect(() => { load() }, [load])

  const selected = structures.find(s => s.id === Number(structureId))
  const preview = selected ? {
    monthly: selected.totals.ctc, annual: selected.totals.ctc * 12,
    gross: selected.totals.gross_earnings, benefits: selected.totals.employer_benefits,
    deductions: selected.totals.deductions, net: selected.totals.net_pay,
  } : null

  const assign = async () => {
    if (!structureId) return showToast('Select a salary structure', 'error')
    if (!effectiveFrom) return showToast('Effective date is required', 'error')
    setSaving(true)
    try {
      const res = await hrApi.payroll.employeeSalary.assign(employee.id, { salary_structure_id: Number(structureId), effective_from: effectiveFrom })
      showToast(data?.current ? 'Salary revised' : 'Salary assigned')
      setData(res); onChanged(res.current); setStructureId(''); setEffectiveFrom('')
    } catch (e) { showToast(e.response?.data?.message || 'Assignment failed', 'error') }
    finally { setSaving(false) }
  }

  const cur = data?.current
  const Row = ({ k, v, accent }) => (
    <div className="flex justify-between py-1.5" style={{ borderBottom:'1px dashed var(--border)' }}>
      <span className="text-xs" style={{ color:'var(--text-muted)' }}>{k}</span>
      <span className="text-xs font-bold" style={{ color: accent || 'var(--text-h)' }}>{v}</span>
    </div>
  )

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal-box" onClick={e=>e.stopPropagation()} style={{ maxWidth:820, width:'95%', maxHeight:'92vh', overflowY:'auto' }}>
        <div className="flex items-center justify-between mb-1">
          <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{employee.name} <span className="text-xs font-mono" style={{ color:'#a78bfa' }}>{employee.employee_code}</span></h2>
          <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>
        <p className="text-xs mb-4" style={{ color:'var(--text-muted)' }}>{employee.designation} · {employee.department}</p>

        {loading ? <p className="text-sm py-6" style={{ color:'var(--text-muted)' }}>Loading…</p> : (
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            {/* Current + assign */}
            <div className="space-y-4">
              <div>
                <p className="text-[11px] font-bold uppercase mb-2" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Current Salary</p>
                {cur ? (
                  <div className="rounded-xl p-3" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                    <div className="flex items-center justify-between mb-2">
                      <span className="text-sm font-bold" style={{ color:'var(--text-h)' }}>{cur.structure_name}</span>
                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:'rgba(16,185,129,0.12)', color:'#10b981' }}>Active</span>
                    </div>
                    <p className="text-[10px] mb-2" style={{ color:'var(--text-muted)' }}>Effective {cur.effective_from}{cur.effective_to?` → ${cur.effective_to}`:''}</p>
                    <Row k="Annual CTC" v={inr(cur.annual_ctc)} accent="#7C3AED"/>
                    <Row k="Monthly CTC" v={inr(cur.monthly_ctc)} accent="#7C3AED"/>
                    <Row k="Gross Salary" v={inr(cur.gross_salary)} accent="#10b981"/>
                    <Row k="Benefits" v={inr(cur.total_benefits)} accent="#3b82f6"/>
                    <Row k="Deductions" v={inr(cur.total_deductions)} accent="#f87171"/>
                    <Row k="Net Salary" v={inr(cur.net_salary)}/>
                  </div>
                ) : <p className="text-xs px-3 py-4 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>No salary assigned yet.</p>}
              </div>

              <div>
                <p className="text-[11px] font-bold uppercase mb-2" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>{cur ? 'Revise Salary' : 'Assign Salary'}</p>
                <div className="space-y-2">
                  <div><label className="label">Salary Structure</label>
                    <select className="input-3d text-sm" value={structureId} onChange={e=>setStructureId(e.target.value)}>
                      <option value="">Select a structure…</option>
                      {structures.map(s=><option key={s.id} value={s.id}>{s.name} — {inr(s.totals.ctc)}/mo</option>)}
                    </select>
                  </div>
                  <div><label className="label">Effective From</label><input type="date" className="input-3d text-sm" value={effectiveFrom} onChange={e=>setEffectiveFrom(e.target.value)}/></div>
                  {preview && (
                    <div className="rounded-xl p-2.5 text-[11px]" style={{ background:'rgba(124,58,237,0.06)', border:'1px dashed rgba(124,58,237,0.3)' }}>
                      <p className="font-bold mb-1" style={{ color:'#a78bfa' }}>Snapshot preview</p>
                      <div className="grid grid-cols-2 gap-x-3" style={{ color:'var(--text-muted)' }}>
                        <span>Annual CTC: <b style={{ color:'var(--text-h)' }}>{inr(preview.annual)}</b></span>
                        <span>Monthly: <b style={{ color:'var(--text-h)' }}>{inr(preview.monthly)}</b></span>
                        <span>Gross: <b style={{ color:'var(--text-h)' }}>{inr(preview.gross)}</b></span>
                        <span>Net: <b style={{ color:'var(--text-h)' }}>{inr(preview.net)}</b></span>
                      </div>
                    </div>
                  )}
                  <button onClick={assign} disabled={saving} className="w-full py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Saving…':cur?'Revise Salary':'Assign Salary'}</button>
                  {cur && <p className="text-[10px]" style={{ color:'var(--text-muted)' }}>Revising archives the current salary into history — existing records are never altered.</p>}
                </div>
              </div>
            </div>

            {/* History */}
            <div>
              <p className="text-[11px] font-bold uppercase mb-2" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Salary History</p>
              {(!data?.history || data.history.length === 0) ? <p className="text-xs" style={{ color:'var(--text-muted)' }}>No history yet.</p> : (
                <div className="space-y-2">
                  {data.history.map(h => (
                    <div key={h.id} className="rounded-xl p-2.5" style={{ background:'var(--bg-input)', border:'1px solid var(--border)', opacity:h.status==='active'?1:0.7 }}>
                      <div className="flex items-center justify-between">
                        <span className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{h.structure_name}</span>
                        <span className="text-[9px] font-bold px-2 py-0.5 rounded-lg" style={h.status==='active'?{background:'rgba(16,185,129,0.12)',color:'#10b981'}:{background:'var(--bg-card)',color:'var(--text-muted)'}}>{h.status}</span>
                      </div>
                      <div className="flex items-center justify-between mt-1">
                        <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>{h.effective_from}{h.effective_to?` → ${h.effective_to}`:' → present'}</span>
                        <span className="text-[11px] font-black" style={{ color:'#10b981' }}>{inr(h.monthly_ctc)}/mo</span>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  )
}

/* ────────────────────────────────────────────────────────────────────────
   Payroll Processing — monthly runs (create → process → summary + records)
   ──────────────────────────────────────────────────────────────────────── */
const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December']
const RUN_ST = {
  Draft:      { c:'#f59e0b', bg:'rgba(245,158,11,0.14)' },
  Processing: { c:'#2563eb', bg:'rgba(37,99,235,0.12)' },
  Completed:  { c:'#10b981', bg:'rgba(16,185,129,0.12)' },
  Cancelled:  { c:'#f87171', bg:'rgba(239,68,68,0.1)' },
}

function PayrollProcessing({ showToast }) {
  const [runs, setRuns] = useState([])
  const [loading, setLoading] = useState(true)
  const [creating, setCreating] = useState(false)
  const [processingId, setProcessingId] = useState(null)
  const [view, setView] = useState(null)   // run being viewed (with records)

  const load = useCallback(() => {
    setLoading(true)
    hrApi.payroll.runs.list().then(setRuns).catch(() => showToast('Failed to load payroll runs', 'error')).finally(() => setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])
  useEffect(() => { load() }, [load])

  const process = async (run) => {
    setProcessingId(run.id)
    try {
      const res = await hrApi.payroll.runs.process(run.id)
      showToast(`Payroll processed — ${res.total_employees} employee(s)`)
      load(); openView(res)
    } catch (e) { showToast(e.response?.data?.message || 'Processing failed', 'error') }
    finally { setProcessingId(null) }
  }
  const cancel = async (run) => {
    try { await hrApi.payroll.runs.setStatus(run.id, 'Cancelled'); showToast('Run cancelled'); load() }
    catch (e) { showToast(e.response?.data?.message || 'Failed', 'error') }
  }
  const openView = async (run) => {
    try {
      const [full, records] = await Promise.all([hrApi.payroll.runs.get(run.id), hrApi.payroll.runs.records(run.id)])
      setView({ ...full, records })
    } catch { showToast('Failed to load run', 'error') }
  }

  const completed = runs.filter(r => r.status === 'Completed')
  const latest = completed[0]

  return (
    <div className="space-y-4">
      {/* Summary KPIs from the latest completed run */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div className="kpi-3d"><p className="text-3xl font-black" style={{ color:'#7C3AED' }}>{latest?.total_employees ?? '—'}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>Employees {latest?`· ${latest.period_label}`:''}</p></div>
        <div className="kpi-3d"><p className="text-2xl font-black" style={{ color:'#10b981' }}>{latest?inr(latest.total_gross):'—'}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>Total Gross</p></div>
        <div className="kpi-3d"><p className="text-2xl font-black" style={{ color:'#f87171' }}>{latest?inr(latest.total_deductions):'—'}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>Total Deduction</p></div>
        <div className="kpi-3d"><p className="text-2xl font-black" style={{ color:'#0ea5e9' }}>{latest?inr(latest.total_net):'—'}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>Total Net</p></div>
      </div>

      {/* Attendance integration notice */}
      <div className="rounded-xl p-3 flex items-center gap-2.5" style={{ background:'var(--bg-input)', border:'1px dashed var(--border)' }}>
        <Plug size={15} style={{ color:'#a78bfa', flexShrink:0 }}/>
        <p className="text-[11px]" style={{ color:'var(--text-muted)' }}><b style={{ color:'var(--text-h)' }}>Attendance:</b> coming from SangoeTrack — not connected. Payroll uses full payable days until integration.</p>
      </div>

      <div className="flex justify-end">
        <button onClick={()=>setCreating(true)} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, boxShadow:'0 4px 14px rgba(124,58,237,0.4)' }}><Plus size={15}/> Create Payroll Run</button>
      </div>

      {loading ? <HrLoading label="Loading payroll runs…" />
        : runs.length === 0 ? <HrEmpty icon={Calendar} title="No payroll runs yet" hint="Create a monthly run to process payroll from assigned employee salaries." />
        : (
          <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:820 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Month','Employees','Gross','Deduction','Net','Status','Action'].map(h=><th key={h} className={`text-left px-3 py-3 label-caps whitespace-nowrap ${h==='Action'?'text-right':''}`}>{h}</th>)}</tr></thead>
              <tbody>
                {runs.map(r => {
                  const st = RUN_ST[r.status] || {}
                  return (
                    <tr key={r.id} style={{ borderBottom:'1px solid var(--border)', opacity:r.status==='Cancelled'?0.6:1 }}>
                      <td className="px-3 py-2.5 font-bold" style={{ color:'var(--text-h)' }}>{r.period_label}</td>
                      <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.total_employees}</td>
                      <td className="px-3 py-2.5 font-semibold" style={{ color:'#10b981' }}>{inr(r.total_gross)}</td>
                      <td className="px-3 py-2.5 font-semibold" style={{ color:'#f87171' }}>{inr(r.total_deductions)}</td>
                      <td className="px-3 py-2.5 font-black" style={{ color:'#0ea5e9' }}>{inr(r.total_net)}</td>
                      <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:st.bg, color:st.c }}>{r.status}</span></td>
                      <td className="px-3 py-2.5">
                        <div className="flex gap-1.5 justify-end">
                          {r.status === 'Draft' && <>
                            <button onClick={()=>process(r)} disabled={processingId===r.id} className="text-[11px] font-bold px-3 py-1.5 rounded-lg text-white flex items-center gap-1" style={{ background:GRAD, opacity:processingId===r.id?0.7:1 }}><PlayCircle size={12}/> {processingId===r.id?'Processing…':'Process'}</button>
                            <button onClick={()=>cancel(r)} title="Cancel" className="p-1.5 rounded-lg" style={{ background:'rgba(239,68,68,0.1)', color:'#f87171' }}><Ban size={13}/></button>
                          </>}
                          {r.status === 'Completed' && <button onClick={()=>openView(r)} className="text-[11px] font-bold px-3 py-1.5 rounded-lg flex items-center gap-1" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><Eye size={12}/> View</button>}
                          {r.status === 'Cancelled' && <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>—</span>}
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}

      {/* AI-ready extension point (no AI implemented). */}
      <div className="rounded-xl p-3 flex items-start gap-2.5" style={{ background:'rgba(124,58,237,0.05)', border:'1px dashed rgba(124,58,237,0.3)' }}>
        <Sparkles size={15} style={{ color:'#a78bfa', marginTop:1, flexShrink:0 }}/>
        <div>
          <p className="text-[11px] font-bold" style={{ color:'#a78bfa' }}>AI Insights <span className="font-normal" style={{ color:'var(--text-muted)' }}>· coming soon</span></p>
          <p className="text-[11px] mt-0.5" style={{ color:'var(--text-muted)' }}>Will run payroll anomaly detection, salary variance analysis, cost forecasting and per-employee payroll insights.</p>
        </div>
      </div>

      {creating && <CreateRunModal onClose={()=>setCreating(false)} onCreated={(run)=>{ setCreating(false); load(); process(run) }} showToast={showToast} />}
      {view && <RunSummaryModal run={view} onClose={()=>setView(null)} />}
    </div>
  )
}

function CreateRunModal({ onClose, onCreated, showToast }) {
  const now = new Date()
  const [month, setMonth] = useState(now.getMonth() + 1)
  const [year, setYear] = useState(now.getFullYear())
  const [saving, setSaving] = useState(false)
  const years = [now.getFullYear() - 1, now.getFullYear(), now.getFullYear() + 1]

  const create = async () => {
    setSaving(true)
    try {
      const run = await hrApi.payroll.runs.create(Number(month), Number(year))
      onCreated(run)
    } catch (e) { showToast(e.response?.data?.message || 'Could not create run', 'error'); setSaving(false) }
  }

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal-box max-w-sm" onClick={e=>e.stopPropagation()}>
        <div className="flex items-center justify-between mb-5"><h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Create Payroll Run</h2><button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button></div>
        <div className="grid grid-cols-2 gap-3">
          <div><label className="label">Month</label><select className="input-3d text-sm" value={month} onChange={e=>setMonth(e.target.value)}>{MONTHS.map((m,i)=><option key={m} value={i+1}>{m}</option>)}</select></div>
          <div><label className="label">Year</label><select className="input-3d text-sm" value={year} onChange={e=>setYear(e.target.value)}>{years.map(y=><option key={y} value={y}>{y}</option>)}</select></div>
        </div>
        <p className="text-[11px] mt-3" style={{ color:'var(--text-muted)' }}>The run is created then processed immediately from all employees with an active salary.</p>
        <div className="flex gap-3 pt-4">
          <button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
          <button onClick={create} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Creating…':'Create & Process'}</button>
        </div>
      </div>
    </div>
  )
}

function RunSummaryModal({ run, onClose }) {
  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal-box" onClick={e=>e.stopPropagation()} style={{ maxWidth:900, width:'95%', maxHeight:'92vh', overflowY:'auto' }}>
        <div className="flex items-center justify-between mb-1">
          <h2 className="font-black text-lg flex items-center gap-2" style={{ color:'var(--text-h)' }}><CheckCircle2 size={18} style={{ color:'#10b981' }}/> {run.period_label}</h2>
          <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>
        <p className="text-xs mb-4" style={{ color:'var(--text-muted)' }}>Payroll {run.status}{run.processed_at?` · processed ${new Date(run.processed_at).toLocaleString('en-IN')}`:''}</p>

        {/* Final summary */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
          {[['Total Employees', run.total_employees, '#7C3AED', false],['Total Gross', run.total_gross, '#10b981', true],['Total Deduction', run.total_deductions, '#f87171', true],['Total Net', run.total_net, '#0ea5e9', true]].map(([l,v,c,m])=>(
            <div key={l} className="rounded-xl px-3 py-3" style={{ background:'var(--bg-input)' }}><p className="text-xl font-black" style={{ color:c }}>{m?inr(v):v}</p><p className="text-[10px] font-semibold mt-1" style={{ color:'var(--text-muted)' }}>{l}</p></div>
          ))}
        </div>

        {run.attendance && !run.attendance.connected && (
          <div className="rounded-xl p-2.5 mb-4 flex items-center gap-2" style={{ background:'var(--bg-input)', border:'1px dashed var(--border)' }}>
            <Plug size={13} style={{ color:'#a78bfa' }}/><span className="text-[11px]" style={{ color:'var(--text-muted)' }}>Attendance from {run.attendance.source}: {run.attendance.message}. Full payable days used.</span>
          </div>
        )}

        {/* Records */}
        <p className="text-[11px] font-bold uppercase mb-2" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Employee Records</p>
        <div className="overflow-x-auto rounded-xl" style={{ border:'1px solid var(--border)' }}>
          <table className="w-full text-sm" style={{ minWidth:720 }}>
            <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Gross','Benefits','Deductions','Net','Payable Days','Attendance'].map(h=><th key={h} className="text-left px-3 py-2.5 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
            <tbody>
              {(run.records||[]).map(r => (
                <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                  <td className="px-3 py-2.5"><span className="font-semibold" style={{ color:'var(--text-h)' }}>{r.employee_name}</span> <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code}</span></td>
                  <td className="px-3 py-2.5" style={{ color:'#10b981' }}>{inr(r.gross_salary)}</td>
                  <td className="px-3 py-2.5" style={{ color:'#3b82f6' }}>{inr(r.total_benefits)}</td>
                  <td className="px-3 py-2.5" style={{ color:'#f87171' }}>{inr(r.total_deductions)}</td>
                  <td className="px-3 py-2.5 font-black" style={{ color:'var(--text-h)' }}>{inr(r.net_salary)}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.payable_days ?? '—'}</td>
                  <td className="px-3 py-2.5 text-[10px]" style={{ color:'var(--text-muted)' }}>{r.attendance_source}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
