import { useState, useEffect, useCallback } from 'react'
import {
  ArrowRightLeft, TrendingUp, TrendingDown, Tag, X, Plus, Search, History, Target, AlertTriangle,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'

const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'

const TYPE_C = {
  Transfer:      { c:'#3b82f6', bg:'rgba(59,130,246,0.12)', icon: ArrowRightLeft },
  Promotion:     { c:'#10b981', bg:'rgba(16,185,129,0.12)', icon: TrendingUp },
  Demotion:      { c:'#f87171', bg:'rgba(239,68,68,0.1)',   icon: TrendingDown },
  Redesignation: { c:'#a78bfa', bg:'rgba(124,58,237,0.12)', icon: Tag },
}

/**
 * Review comments #41 (department transfer), #42 (promotion/demotion) and #43
 * (skill fit shown at the point of assignment).
 *
 * The fit preview is the part worth keeping honest: it runs against the TARGET
 * position before the move is committed, so a gap is visible while it can still
 * change the decision.
 */
export default function EmployeeMovements({ showToast }) {
  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState({ movement_types: [] })
  const [masters, setMasters] = useState({ departments: [], designations: [], grades: [] })
  const [employees, setEmployees] = useState([])
  const [loading, setLoading] = useState(true)
  const [typeF, setTypeF] = useState('All')
  const [search, setSearch] = useState('')
  const [modal, setModal] = useState(null)
  const [historyFor, setHistoryFor] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [m, list, org, emps] = await Promise.all([
        hrApi.movements.meta(), hrApi.movements.list(),
        hrApi.organization.options(), hrApi.employees.list({ per_page: 500 }),
      ])
      setMeta(m); setRows(list); setEmployees(emps)
      setMasters({
        departments: org?.departments || [], designations: org?.designations || [], grades: org?.grades || [],
      })
    } catch (e) { showToast?.(e?.message || 'Could not load movements', 'error') }
    finally { setLoading(false) }
  }, [showToast])

  useEffect(() => { load() }, [load])

  const visible = rows.filter(r =>
    (typeF === 'All' || r.movement_type === typeF) &&
    (!search || `${r.employee_name} ${r.employee_code}`.toLowerCase().includes(search.toLowerCase()))
  )

  if (loading) return <HrLoading label="Loading movements…" />

  return (
    <div className="space-y-4">
      <div className="card-3d flex items-start gap-3" style={{ padding:'14px 16px' }}>
        <ArrowRightLeft size={18} style={{ color:'#a78bfa', flexShrink:0, marginTop:2 }}/>
        <div>
          <p className="text-xs font-black" style={{ color:'var(--text-h)' }}>Transfers, promotions and demotions</p>
          <p className="text-[11px] mt-0.5" style={{ color:'var(--text-muted)' }}>
            A movement updates the employee record and writes an immutable history entry in the same step.
            Promotion vs demotion is decided by grade level where grades are set — it is the only ranked master.
          </p>
        </div>
      </div>

      <div className="card-3d" style={{ padding:'14px 16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[200px]">
            <label className="label">Search</label>
            <Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/>
            <input className="input-3d pl-9 text-sm" placeholder="Employee name or code…" value={search} onChange={e=>setSearch(e.target.value)}/>
          </div>
          <div className="min-w-[150px]">
            <label className="label">Type</label>
            <select className="input-3d text-sm" value={typeF} onChange={e=>setTypeF(e.target.value)}>
              {['All', ...meta.movement_types].map(t => <option key={t}>{t}</option>)}
            </select>
          </div>
          <button onClick={()=>setModal({ employee_id:'', movement_type:'', to_department_id:'', to_designation_id:'', to_grade_id:'', effective_date:new Date().toISOString().slice(0,10), reason:'' })}
            className="flex items-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-bold text-white ml-auto" style={{ background:GRAD }}>
            <Plus size={15}/> New Movement
          </button>
        </div>
      </div>

      {visible.length === 0
        ? <HrEmpty icon={ArrowRightLeft} title="No movements recorded" subtitle="Transfers, promotions and demotions appear here with full history." />
        : (
          <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:820 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>
                {['Employee','Type','Change','Effective','Reason',''].map((h,i)=><th key={i} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}
              </tr></thead>
              <tbody>
                {visible.map(r => {
                  const tc = TYPE_C[r.movement_type] || {}
                  const Icon = tc.icon || Tag
                  return (
                    <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                      <td className="px-3 py-2.5">
                        <span className="font-semibold" style={{ color:'var(--text-h)' }}>{r.employee_name}</span>{' '}
                        <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code}</span>
                      </td>
                      <td className="px-3 py-2.5">
                        <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg inline-flex items-center gap-1" style={{ background:tc.bg, color:tc.c }}>
                          <Icon size={10}/> {r.movement_type}
                        </span>
                      </td>
                      <td className="px-3 py-2.5 text-[11px]" style={{ color:'var(--text-muted)' }}>{r.summary}</td>
                      <td className="px-3 py-2.5 text-[11px]" style={{ color:'var(--text-muted)' }}>{r.effective_date}</td>
                      <td className="px-3 py-2.5 text-[11px]" style={{ color:'var(--text-muted)' }}>
                        {r.reason || '—'}
                        {r.from_recommendation && <span className="ml-1 text-[9px] px-1 py-0.5 rounded" style={{ background:'rgba(124,58,237,0.12)', color:'#a78bfa' }}>FROM REVIEW</span>}
                      </td>
                      <td className="px-3 py-2.5 text-right">
                        <button onClick={()=>setHistoryFor({ id:r.employee_id, name:r.employee_name })}
                          className="text-[10px] font-bold px-2.5 py-1 rounded-lg inline-flex items-center gap-1"
                          style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><History size={11}/> History</button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}

      {modal && <MoveModal state={modal} setState={setModal} employees={employees} masters={masters} meta={meta}
        showToast={showToast} onDone={()=>{ setModal(null); load() }} />}
      {historyFor && <MovementHistory {...historyFor} onClose={()=>setHistoryFor(null)} showToast={showToast} />}
    </div>
  )
}

/* ────────────────────────────────────────────────────────────────────── */

function MoveModal({ state, setState, employees, masters, meta, showToast, onDone }) {
  const [saving, setSaving] = useState(false)
  const [fit, setFit] = useState(null)
  const set = (patch) => setState(s => ({ ...s, ...patch }))

  // #43 — check the fit against the TARGET position while the decision can still
  // change. Re-runs whenever the employee or any target master moves.
  useEffect(() => {
    const { employee_id, to_department_id, to_designation_id, to_grade_id } = state
    if (!employee_id || (!to_department_id && !to_designation_id && !to_grade_id)) { setFit(null); return }
    let cancelled = false
    hrApi.employees.previewSkills(Number(employee_id), {
      department_id: to_department_id ? Number(to_department_id) : null,
      designation_id: to_designation_id ? Number(to_designation_id) : null,
      grade_id: to_grade_id ? Number(to_grade_id) : null,
    }).then(r => { if (!cancelled) setFit(r) }).catch(() => { if (!cancelled) setFit(null) })
    return () => { cancelled = true }
  }, [state.employee_id, state.to_department_id, state.to_designation_id, state.to_grade_id])

  const save = async () => {
    setSaving(true)
    try {
      await hrApi.movements.move({
        employee_id: Number(state.employee_id),
        movement_type: state.movement_type || null,
        to_department_id: state.to_department_id ? Number(state.to_department_id) : null,
        to_designation_id: state.to_designation_id ? Number(state.to_designation_id) : null,
        to_grade_id: state.to_grade_id ? Number(state.to_grade_id) : null,
        effective_date: state.effective_date,
        reason: state.reason || null,
      })
      showToast?.('Movement recorded'); onDone()
    } catch (e) { showToast?.(e?.response?.data?.message || 'Could not record the movement', 'error') }
    finally { setSaving(false) }
  }

  return (
    <div className="modal-backdrop" onClick={()=>setState(null)}>
      <div className="modal-box" onClick={e=>e.stopPropagation()} style={{ maxWidth:600, width:'95%', maxHeight:'92vh', overflowY:'auto' }}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>New Movement</h2>
          <button onClick={()=>setState(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>

        <div className="space-y-3">
          <div><label className="label">Employee *</label>
            <select className="input-3d text-sm" value={state.employee_id} onChange={e=>set({ employee_id:e.target.value })}>
              <option value="">Choose…</option>
              {employees.map(e => <option key={e.id} value={e.id}>{e.name} ({e.employee_code}) · {e.department} / {e.designation}</option>)}
            </select>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">To Department</label>
              <select className="input-3d text-sm" value={state.to_department_id} onChange={e=>set({ to_department_id:e.target.value })}>
                <option value="">No change</option>
                {masters.departments.map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
              </select>
            </div>
            <div><label className="label">To Designation</label>
              <select className="input-3d text-sm" value={state.to_designation_id} onChange={e=>set({ to_designation_id:e.target.value })}>
                <option value="">No change</option>
                {masters.designations.map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
              </select>
            </div>
            <div><label className="label">To Grade</label>
              <select className="input-3d text-sm" value={state.to_grade_id} onChange={e=>set({ to_grade_id:e.target.value })}>
                <option value="">No change</option>
                {masters.grades.map(g => <option key={g.id} value={g.id}>{g.name}</option>)}
              </select>
            </div>
            <div><label className="label">Effective from *</label>
              <input type="date" className="input-3d text-sm" value={state.effective_date} onChange={e=>set({ effective_date:e.target.value })}/></div>
          </div>

          <div><label className="label">Type</label>
            <select className="input-3d text-sm" value={state.movement_type} onChange={e=>set({ movement_type:e.target.value })}>
              <option value="">Decide from the grade change</option>
              {meta.movement_types.map(t => <option key={t}>{t}</option>)}
            </select>
            <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>
              Left blank, a higher grade reads as a promotion and a lower one as a demotion. Designations carry no rank,
              so a title-only change is recorded as a redesignation.
            </p>
          </div>

          <div><label className="label">Reason</label>
            <textarea rows={2} className="input-3d text-sm resize-none" value={state.reason} onChange={e=>set({ reason:e.target.value })}/></div>

          <SkillFit fit={fit} />
        </div>

        <div className="flex gap-3 pt-5">
          <button onClick={()=>setState(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
          <button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Recording…':'Record Movement'}</button>
        </div>
      </div>
    </div>
  )
}

/** #43 — expected vs held skills for a position, with the gap named. */
export function SkillFit({ fit, title = 'Skill fit for the new position' }) {
  if (!fit) return null

  if (!fit.configured) {
    return (
      <div className="rounded-xl p-2.5 flex items-start gap-2" style={{ background:'var(--bg-input)' }}>
        <Target size={12} style={{ color:'var(--text-muted)', flexShrink:0, marginTop:2 }}/>
        <p className="text-[11px]" style={{ color:'var(--text-muted)' }}>
          No skills are configured for this position, so there is nothing to score against.
        </p>
      </div>
    )
  }

  const score = fit.overall.score ?? 0
  const tone = score >= 80 ? '#10b981' : score >= 50 ? '#fbbf24' : '#f87171'

  return (
    <div className="rounded-xl p-3" style={{ background:'var(--bg-input)', border:`1px solid ${tone}33` }}>
      <div className="flex items-center justify-between gap-3 mb-2">
        <p className="text-[11px] font-bold flex items-center gap-1.5" style={{ color:'var(--text-h)' }}>
          <Target size={12} style={{ color:tone }}/> {title}
        </p>
        <p className="text-sm font-black" style={{ color:tone }}>{score}%</p>
      </div>

      <div className="h-2 rounded-full overflow-hidden mb-2" style={{ background:'var(--bg-card)' }}>
        <div style={{ width:`${Math.min(100, score)}%`, height:'100%', background:tone }}/>
      </div>

      {fit.sources?.length > 0 && (
        <div className="space-y-1 mb-2">
          {fit.sources.map((s, i) => (
            <p key={i} className="text-[10px]" style={{ color:'var(--text-muted)' }}>
              <span className="capitalize font-semibold">{s.type.replace('_', ' ')}</span> · {s.name} —{' '}
              <b style={{ color:'var(--text-h)' }}>{s.score ?? '—'}%</b>
              {s.missing?.length > 0 && <> · missing {s.missing.join(', ')}</>}
            </p>
          ))}
        </div>
      )}

      {fit.overall.missing?.length > 0 && (
        <p className="text-[11px] flex items-start gap-1" style={{ color:tone }}>
          <AlertTriangle size={11} style={{ flexShrink:0, marginTop:1 }}/>
          Gaps: {fit.overall.missing.join(', ')}
        </p>
      )}
    </div>
  )
}

function MovementHistory({ id, name, onClose, showToast }) {
  const [rows, setRows] = useState(null)

  useEffect(() => {
    hrApi.movements.history(id).then(setRows).catch(e => { showToast?.(e?.message || 'Could not load', 'error'); setRows([]) })
  }, [id, showToast])

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal-box max-w-lg" onClick={e=>e.stopPropagation()}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="font-black text-lg flex items-center gap-2" style={{ color:'var(--text-h)' }}><History size={17} style={{ color:'#a78bfa' }}/> {name}</h2>
          <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>

        {rows === null ? <HrLoading label="Loading…" />
          : rows.length === 0 ? <p className="text-xs" style={{ color:'var(--text-muted)' }}>No movements recorded.</p>
          : (
            <div className="space-y-2">
              {rows.map(r => {
                const tc = TYPE_C[r.movement_type] || {}
                return (
                  <div key={r.id} className="rounded-xl px-3 py-2.5" style={{ background:'var(--bg-input)' }}>
                    <div className="flex items-center gap-2 flex-wrap">
                      <span className="text-[9px] font-bold px-1.5 py-0.5 rounded" style={{ background:tc.bg, color:tc.c }}>{r.movement_type}</span>
                      <span className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{r.summary}</span>
                    </div>
                    <p className="text-[11px] mt-0.5" style={{ color:'var(--text-muted)' }}>
                      Effective {r.effective_date}{r.reason ? ` · ${r.reason}` : ''}
                    </p>
                  </div>
                )
              })}
            </div>
          )}
      </div>
    </div>
  )
}
