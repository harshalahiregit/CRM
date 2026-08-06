import { useState, useEffect, useCallback } from 'react'
import { Plus, Pencil, Trash2, X, Clock, Moon, Repeat, Users, History, CalendarOff } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
// #3 — all three views (shifts, rotations, roster) are loaded in one call, so
// filtering happens in memory over what is already here.
import ListFilter, { applyListFilter } from '@/components/ui/ListFilter'

const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'
const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']

/** A blank week: Mon–Fri working, Sat/Sun off. Only a starting point — fully editable. */
const blankTimings = () => DAYS.map((_, d) => ({
  day_of_week: d,
  start_time: d >= 1 && d <= 5 ? '09:00' : '',
  end_time:   d >= 1 && d <= 5 ? '18:00' : '',
  is_week_off: d === 0 || d === 6,
  week_numbers: [],
}))

const EMPTY_SHIFT = {
  name: '', code: '', shift_type: 'Fixed', is_night_shift: false,
  grace_in_minutes: 0, grace_out_minutes: 0, break_minutes: 0,
  full_day_hours: '', half_day_hours: '', description: '', is_active: true,
  timings: blankTimings(),
}

export default function ShiftManagement({ showToast }) {
  const [view, setView]   = useState('shifts')   // shifts | rotations | roster
  const [meta, setMeta]   = useState({ shift_types: [], days: DAYS })
  const [shifts, setShifts] = useState([])
  const [rotations, setRotations] = useState([])
  const [roster, setRoster] = useState([])
  // #3 — one filter state shared by the three views; cleared on a view change so
  // a search typed for shifts never silently hides half the roster.
  const [search, setSearch] = useState('')
  const [typeF, setTypeF]   = useState('All')
  const [statusF, setStatusF] = useState('All')
  const [employees, setEmployees] = useState([])
  const [loading, setLoading] = useState(true)
  const [modal, setModal] = useState(null)
  const [rotModal, setRotModal] = useState(null)
  const [assignModal, setAssignModal] = useState(null)
  const [historyFor, setHistoryFor] = useState(null)
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [m, s, r, ro, emps] = await Promise.all([
        hrApi.shifts.meta(), hrApi.shifts.list(), hrApi.shifts.rotations(),
        hrApi.shifts.roster(), hrApi.employees.list({ per_page: 500 }),
      ])
      setMeta(m); setShifts(s); setRotations(r); setRoster(ro); setEmployees(emps)
    } catch (e) { showToast?.(e?.message || 'Could not load shifts', 'error') }
    finally { setLoading(false) }
  }, [showToast])

  useEffect(() => { load() }, [load])
  useEffect(() => { setSearch(''); setTypeF('All'); setStatusF('All') }, [view])

  const shownShifts = applyListFilter(shifts, {
    search, fields: ['name', 'code', 'shift_type'],
    matchers: [
      [typeF, (r, v) => r.shift_type === v],
      [statusF, (r, v) => (r.is_active === false ? 'Inactive' : 'Active') === v],
    ],
  })
  const shownRotations = applyListFilter(rotations, { search, fields: ['name', 'code'] })
  const shownRoster    = applyListFilter(roster, {
    search, fields: ['employee_name', 'employee_code', 'department', 'shift_name', 'rotation_name'],
  })

  const saveShift = async () => {
    setSaving(true)
    try {
      const body = {
        ...modal.form,
        full_day_hours: modal.form.full_day_hours === '' ? null : Number(modal.form.full_day_hours),
        half_day_hours: modal.form.half_day_hours === '' ? null : Number(modal.form.half_day_hours),
        timings: modal.form.timings.map(t => ({
          day_of_week: t.day_of_week,
          start_time: t.is_week_off ? null : (t.start_time || null),
          end_time:   t.is_week_off ? null : (t.end_time || null),
          is_week_off: !!t.is_week_off,
          week_numbers: t.is_week_off ? (t.week_numbers || []) : [],
        })),
      }
      if (modal.id) await hrApi.shifts.update(modal.id, body)
      else await hrApi.shifts.create(body)
      showToast?.(modal.id ? 'Shift updated' : 'Shift created')
      setModal(null); load()
    } catch (e) { showToast?.(e?.response?.data?.message || 'Could not save the shift', 'error') }
    finally { setSaving(false) }
  }

  const removeShift = async (s) => {
    if (!window.confirm(`Delete "${s.name}"?`)) return
    try { await hrApi.shifts.remove(s.id); showToast?.('Shift deleted'); load() }
    catch (e) { showToast?.(e?.response?.data?.message || 'Could not delete', 'error') }
  }

  const saveRotation = async () => {
    setSaving(true)
    try {
      await hrApi.shifts.saveRotation(rotModal.id, {
        name: rotModal.form.name, code: rotModal.form.code,
        description: rotModal.form.description, is_active: rotModal.form.is_active,
        steps: (rotModal.form.steps || []).filter(s => s.shift_id)
          .map(s => ({ shift_id: Number(s.shift_id), duration_days: Number(s.duration_days) || 7 })),
      })
      showToast?.('Rotation saved'); setRotModal(null); load()
    } catch (e) { showToast?.(e?.response?.data?.message || 'Could not save the rotation', 'error') }
    finally { setSaving(false) }
  }

  const assign = async () => {
    setSaving(true)
    try {
      await hrApi.shifts.assign({
        employee_id: Number(assignModal.employee_id),
        shift_id: assignModal.mode === 'shift' ? Number(assignModal.shift_id) : null,
        rotation_id: assignModal.mode === 'rotation' ? Number(assignModal.rotation_id) : null,
        effective_from: assignModal.effective_from,
        reason: assignModal.reason || null,
      })
      showToast?.('Shift assigned'); setAssignModal(null); load()
    } catch (e) { showToast?.(e?.response?.data?.message || 'Could not assign', 'error') }
    finally { setSaving(false) }
  }

  if (loading) return <HrLoading label="Loading shifts…" />

  return (
    <div className="space-y-4">
      <div className="flex gap-1.5 flex-wrap items-center">
        {[['shifts', 'Shifts', Clock], ['rotations', 'Rotation Plans', Repeat], ['roster', 'Roster', Users]].map(([k, label, Icon]) => (
          <button key={k} onClick={() => setView(k)}
            className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold"
            style={{ background: view === k ? 'rgba(124,58,237,0.15)' : 'var(--bg-input)', color: view === k ? '#a78bfa' : 'var(--text-muted)' }}>
            <Icon size={13}/> {label}
          </button>
        ))}
        <div className="ml-auto flex gap-2">
          {view === 'shifts' && (
            <button onClick={() => setModal({ id: null, form: { ...EMPTY_SHIFT, timings: blankTimings() } })}
              className="flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold text-white" style={{ background: GRAD }}>
              <Plus size={14}/> Add Shift
            </button>
          )}
          {view === 'rotations' && (
            <button onClick={() => setRotModal({ id: null, form: { name: '', code: '', description: '', is_active: true, steps: [] } })}
              className="flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold text-white" style={{ background: GRAD }}>
              <Plus size={14}/> Add Rotation
            </button>
          )}
          {view === 'roster' && (
            <button onClick={() => setAssignModal({ employee_id: '', mode: 'shift', shift_id: '', rotation_id: '', effective_from: new Date().toISOString().slice(0, 10), reason: '' })}
              className="flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold text-white" style={{ background: GRAD }}>
              <Plus size={14}/> Assign Shift
            </button>
          )}
        </div>
      </div>

      {/* #3 — one bar, re-labelled per view, so the same control filters whichever
          list is on screen instead of three near-identical bars. */}
      <ListFilter
        search={search} setSearch={setSearch}
        placeholder={view === 'shifts' ? 'Shift name or code…' : view === 'rotations' ? 'Rotation name…' : 'Employee, department or shift…'}
        selects={view === 'shifts' ? [
          { key:'type', label:'Type', value:typeF, onChange:setTypeF, options:['All','Fixed','Rotational','Flexible'] },
          { key:'status', label:'Status', value:statusF, onChange:setStatusF, options:['All','Active','Inactive'] },
        ] : []}
        onClear={()=>{ setSearch(''); setTypeF('All'); setStatusF('All') }}
      />

      {/* ── Shifts ── */}
      {view === 'shifts' && (shownShifts.length === 0
        ? <HrEmpty icon={Clock} title={shifts.length ? 'No matching shifts' : 'No shifts yet'} subtitle={shifts.length ? 'Nothing matches these filters.' : 'A shift defines the working hours for each weekday — and which days are weekly offs.'} />
        : (
          <div className="space-y-2">
            {shownShifts.map(s => (
              <div key={s.id} className="card-3d flex items-start gap-3 flex-wrap" style={{ padding:'14px 16px', opacity: s.is_active ? 1 : 0.55 }}>
                <div className="flex-1 min-w-[240px]">
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-sm font-black" style={{ color:'var(--text-h)' }}>{s.name}</span>
                    {s.code && <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{s.code}</span>}
                    <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:'rgba(124,58,237,0.12)', color:'#a78bfa' }}>{s.shift_type}</span>
                    {s.is_night_shift && <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg flex items-center gap-1" style={{ background:'rgba(59,130,246,0.12)', color:'#3b82f6' }}><Moon size={9}/> Night</span>}
                    {!s.is_active && <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>Inactive</span>}
                  </div>
                  <div className="flex flex-wrap gap-1 mt-2">
                    {s.timings.map(t => (
                      <span key={t.day_of_week} className="text-[10px] px-1.5 py-0.5 rounded"
                        style={{ background: t.is_week_off ? 'rgba(239,68,68,0.1)' : 'var(--bg-input)', color: t.is_week_off ? '#f87171' : 'var(--text-muted)' }}>
                        {t.day_name.slice(0, 3)}{' '}
                        {t.is_week_off
                          ? `off${t.week_numbers?.length ? ` (wk ${t.week_numbers.join(',')})` : ''}`
                          : `${t.start_time || '—'}–${t.end_time || '—'}`}
                      </span>
                    ))}
                  </div>
                  <p className="text-[10px] mt-1.5" style={{ color:'var(--text-muted)' }}>
                    Grace {s.grace_in_minutes}m in / {s.grace_out_minutes}m out
                    {s.full_day_hours ? ` · full day ${s.full_day_hours}h` : ''}
                    {s.half_day_hours ? ` · half day ${s.half_day_hours}h` : ''}
                  </p>
                </div>
                <button onClick={() => setModal({ id: s.id, form: { ...EMPTY_SHIFT, ...s, full_day_hours: s.full_day_hours ?? '', half_day_hours: s.half_day_hours ?? '' } })}
                  className="p-2 rounded-lg" style={{ background:'var(--bg-input)' }}><Pencil size={13} style={{ color:'var(--text-muted)' }}/></button>
                <button onClick={() => removeShift(s)} className="p-2 rounded-lg" style={{ background:'rgba(239,68,68,0.1)' }}><Trash2 size={13} style={{ color:'#f87171' }}/></button>
              </div>
            ))}
          </div>
        ))}

      {/* ── Rotations ── */}
      {view === 'rotations' && (shownRotations.length === 0
        ? <HrEmpty icon={Repeat} title="No rotation plans" subtitle="A rotation cycles an employee through shifts — e.g. one week days, one week nights." />
        : (
          <div className="space-y-2">
            {shownRotations.map(r => (
              <div key={r.id} className="card-3d flex items-center gap-3 flex-wrap" style={{ padding:'14px 16px' }}>
                <div className="flex-1 min-w-[240px]">
                  <span className="text-sm font-black" style={{ color:'var(--text-h)' }}>{r.name}</span>
                  <span className="ml-2 text-[10px]" style={{ color:'var(--text-muted)' }}>{r.cycle_days}-day cycle</span>
                  <div className="flex items-center gap-1.5 mt-1.5 flex-wrap">
                    {r.steps.map((s, i) => (
                      <span key={s.id} className="text-[10px] px-2 py-0.5 rounded-lg" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>
                        {i + 1}. {s.shift_name} · {s.duration_days}d
                      </span>
                    ))}
                    {r.steps.length === 0 && <span className="text-[10px]" style={{ color:'#fbbf24' }}>No steps — this plan cannot be assigned.</span>}
                  </div>
                </div>
                <button onClick={() => setRotModal({ id: r.id, form: { ...r, steps: r.steps.map(s => ({ shift_id: s.shift_id, duration_days: s.duration_days })) } })}
                  className="p-2 rounded-lg" style={{ background:'var(--bg-input)' }}><Pencil size={13} style={{ color:'var(--text-muted)' }}/></button>
                <button onClick={async () => {
                  if (!window.confirm(`Delete "${r.name}"?`)) return
                  try { await hrApi.shifts.removeRotation(r.id); showToast?.('Deleted'); load() }
                  catch (e) { showToast?.(e?.response?.data?.message || 'Could not delete', 'error') }
                }} className="p-2 rounded-lg" style={{ background:'rgba(239,68,68,0.1)' }}><Trash2 size={13} style={{ color:'#f87171' }}/></button>
              </div>
            ))}
          </div>
        ))}

      {/* ── Roster ── */}
      {view === 'roster' && (shownRoster.length === 0
        ? <HrEmpty icon={Users} title="Nobody is assigned to a shift" subtitle="Employees without an assignment keep working exactly as they did before shifts existed." />
        : (
          <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:760 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>
                {['Employee','Department','Shift / Rotation','Effective From','Reason',''].map((h, i) =>
                  <th key={i} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}
              </tr></thead>
              <tbody>
                {shownRoster.map(r => (
                  <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                    <td className="px-3 py-2.5">
                      <span className="font-semibold" style={{ color:'var(--text-h)' }}>{r.employee_name}</span>{' '}
                      <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code}</span>
                    </td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.department || '—'}</td>
                    <td className="px-3 py-2.5">
                      <span className="font-semibold" style={{ color:'var(--text-h)' }}>{r.shift_name || r.rotation_name}</span>
                      {r.rotation_name && <span className="ml-1.5 text-[10px] px-1.5 py-0.5 rounded" style={{ background:'rgba(59,130,246,0.12)', color:'#3b82f6' }}>Rotation</span>}
                    </td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.effective_from}</td>
                    <td className="px-3 py-2.5 text-[11px]" style={{ color:'var(--text-muted)' }}>{r.reason || '—'}</td>
                    <td className="px-3 py-2.5 text-right">
                      <button onClick={() => setHistoryFor({ id: r.employee_id, name: r.employee_name })}
                        className="text-[10px] font-bold px-2.5 py-1 rounded-lg flex items-center gap-1 ml-auto"
                        style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><History size={11}/> History</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ))}

      {modal && <ShiftModal modal={modal} setModal={setModal} meta={meta} saving={saving} onSave={saveShift} />}
      {rotModal && <RotationModal modal={rotModal} setModal={setRotModal} shifts={shifts} saving={saving} onSave={saveRotation} />}
      {assignModal && <AssignModal state={assignModal} setState={setAssignModal} employees={employees} shifts={shifts} rotations={rotations} saving={saving} onSave={assign} />}
      {historyFor && <HistoryDrawer {...historyFor} onClose={() => setHistoryFor(null)} showToast={showToast} />}
    </div>
  )
}

/* ────────────────────────────────────────────────────────────────────── */

function ShiftModal({ modal, setModal, meta, saving, onSave }) {
  const { form } = modal
  const set = (patch) => setModal(m => ({ ...m, form: { ...m.form, ...patch } }))
  const setDay = (d, patch) => setModal(m => ({
    ...m, form: { ...m.form, timings: m.form.timings.map(t => t.day_of_week === d ? { ...t, ...patch } : t) },
  }))

  return (
    <div className="modal-backdrop">
      <div className="modal-box" onClick={e => e.stopPropagation()} style={{ maxWidth:760, width:'95%', maxHeight:'92vh', overflowY:'auto' }}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{modal.id ? 'Edit' : 'Add'} Shift</h2>
          <button onClick={() => setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div><label className="label">Name *</label><input className="input-3d text-sm" value={form.name} onChange={e => set({ name: e.target.value })}/></div>
          <div><label className="label">Code</label><input className="input-3d text-sm" value={form.code || ''} onChange={e => set({ code: e.target.value })}/></div>
          <div><label className="label">Type</label>
            <select className="input-3d text-sm" value={form.shift_type} onChange={e => set({ shift_type: e.target.value })}>
              {meta.shift_types.map(t => <option key={t}>{t}</option>)}
            </select>
          </div>
          <div className="flex items-end gap-4 pb-1">
            <label className="flex items-center gap-1.5 text-xs font-semibold cursor-pointer" style={{ color:'var(--text-muted)' }}>
              <input type="checkbox" checked={!!form.is_night_shift} onChange={e => set({ is_night_shift: e.target.checked })}/> Night shift
            </label>
            <label className="flex items-center gap-1.5 text-xs font-semibold cursor-pointer" style={{ color:'var(--text-muted)' }}>
              <input type="checkbox" checked={!!form.is_active} onChange={e => set({ is_active: e.target.checked })}/> Active
            </label>
          </div>
          <div><label className="label">Grace in (min)</label><input type="number" className="input-3d text-sm" value={form.grace_in_minutes} onChange={e => set({ grace_in_minutes: e.target.value })}/></div>
          <div><label className="label">Grace out (min)</label><input type="number" className="input-3d text-sm" value={form.grace_out_minutes} onChange={e => set({ grace_out_minutes: e.target.value })}/></div>
          <div><label className="label">Full day hours</label><input type="number" step="any" className="input-3d text-sm" placeholder="Not set" value={form.full_day_hours} onChange={e => set({ full_day_hours: e.target.value })}/></div>
          <div><label className="label">Half day hours</label><input type="number" step="any" className="input-3d text-sm" placeholder="Not set" value={form.half_day_hours} onChange={e => set({ half_day_hours: e.target.value })}/></div>
        </div>

        {/* Weekday timing — this IS the weekly off definition. */}
        <div className="mt-4">
          <div className="flex items-center gap-2 mb-2">
            <CalendarOff size={13} style={{ color:'#a78bfa' }}/>
            <p className="text-[11px] font-bold uppercase" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Weekly timing &amp; offs</p>
          </div>
          <div className="space-y-1.5">
            {form.timings.map(t => (
              <div key={t.day_of_week} className="flex items-center gap-2 flex-wrap">
                <span className="text-xs font-semibold" style={{ color:'var(--text-h)', width:84 }}>{DAYS[t.day_of_week]}</span>
                <label className="flex items-center gap-1.5 text-[11px] font-semibold cursor-pointer" style={{ color: t.is_week_off ? '#f87171' : 'var(--text-muted)', width:90 }}>
                  <input type="checkbox" checked={!!t.is_week_off} onChange={e => setDay(t.day_of_week, { is_week_off: e.target.checked })}/> Week off
                </label>
                {t.is_week_off ? (
                  <div className="flex items-center gap-1">
                    <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>Weeks:</span>
                    {[1, 2, 3, 4, 5].map(w => {
                      const on = (t.week_numbers || []).includes(w)
                      return (
                        <button key={w} onClick={() => setDay(t.day_of_week, {
                          week_numbers: on ? t.week_numbers.filter(x => x !== w) : [...(t.week_numbers || []), w].sort(),
                        })} className="w-6 h-6 rounded text-[10px] font-bold"
                          style={{ background: on ? 'rgba(124,58,237,0.2)' : 'var(--bg-input)', color: on ? '#a78bfa' : 'var(--text-muted)' }}>{w}</button>
                      )
                    })}
                    <span className="text-[10px] ml-1" style={{ color:'var(--text-muted)' }}>
                      {(t.week_numbers || []).length === 0 ? 'every week' : 'selected weeks only'}
                    </span>
                  </div>
                ) : (
                  <>
                    <input type="time" className="input-3d text-xs" style={{ width:110 }} value={t.start_time || ''} onChange={e => setDay(t.day_of_week, { start_time: e.target.value })}/>
                    <span className="text-[11px]" style={{ color:'var(--text-muted)' }}>to</span>
                    <input type="time" className="input-3d text-xs" style={{ width:110 }} value={t.end_time || ''} onChange={e => setDay(t.day_of_week, { end_time: e.target.value })}/>
                  </>
                )}
              </div>
            ))}
          </div>
          <p className="text-[10px] mt-2" style={{ color:'var(--text-muted)' }}>
            Leaving the week list empty makes the day off every week. Choosing 2 and 4 gives the alternate-Saturday pattern.
          </p>
        </div>

        <div className="flex gap-3 pt-5">
          <button onClick={() => setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
          <button onClick={onSave} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: GRAD, opacity: saving ? 0.7 : 1 }}>{saving ? 'Saving…' : 'Save'}</button>
        </div>
      </div>
    </div>
  )
}

function RotationModal({ modal, setModal, shifts, saving, onSave }) {
  const { form } = modal
  const set = (patch) => setModal(m => ({ ...m, form: { ...m.form, ...patch } }))
  const steps = form.steps || []

  return (
    <div className="modal-backdrop">
      <div className="modal-box max-w-lg" onClick={e => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{modal.id ? 'Edit' : 'Add'} Rotation Plan</h2>
          <button onClick={() => setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div><label className="label">Name *</label><input className="input-3d text-sm" value={form.name} onChange={e => set({ name: e.target.value })}/></div>
          <div><label className="label">Code</label><input className="input-3d text-sm" value={form.code || ''} onChange={e => set({ code: e.target.value })}/></div>
        </div>

        <div className="mt-3">
          <div className="flex items-center justify-between mb-1.5">
            <label className="label" style={{ marginBottom:0 }}>Steps</label>
            <button onClick={() => set({ steps: [...steps, { shift_id: '', duration_days: 7 }] })}
              className="flex items-center gap-1 px-2 py-1 rounded-lg text-[10px] font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>
              <Plus size={11}/> Add step
            </button>
          </div>
          {steps.length === 0 && <p className="text-[11px]" style={{ color:'#fbbf24' }}>A plan with no steps cannot be assigned.</p>}
          <div className="space-y-1.5">
            {steps.map((s, i) => (
              <div key={i} className="flex items-center gap-1.5">
                <span className="text-[11px] font-bold" style={{ color:'var(--text-muted)', width:18 }}>{i + 1}</span>
                <select className="input-3d text-xs flex-1" value={s.shift_id}
                  onChange={e => set({ steps: steps.map((x, j) => j === i ? { ...x, shift_id: e.target.value } : x) })}>
                  <option value="">Choose shift…</option>
                  {shifts.map(sh => <option key={sh.id} value={sh.id}>{sh.name}</option>)}
                </select>
                <input type="number" min="1" className="input-3d text-xs" style={{ width:80 }} value={s.duration_days}
                  onChange={e => set({ steps: steps.map((x, j) => j === i ? { ...x, duration_days: e.target.value } : x) })}/>
                <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>days</span>
                <button onClick={() => set({ steps: steps.filter((_, j) => j !== i) })}
                  className="p-1.5 rounded-lg" style={{ background:'rgba(239,68,68,0.1)' }}><Trash2 size={12} style={{ color:'#f87171' }}/></button>
              </div>
            ))}
          </div>
          <p className="text-[10px] mt-1.5" style={{ color:'var(--text-muted)' }}>
            Total cycle: {steps.reduce((a, s) => a + (Number(s.duration_days) || 0), 0)} days, then it repeats.
          </p>
        </div>

        <div className="flex gap-3 pt-5">
          <button onClick={() => setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
          <button onClick={onSave} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: GRAD, opacity: saving ? 0.7 : 1 }}>{saving ? 'Saving…' : 'Save'}</button>
        </div>
      </div>
    </div>
  )
}

function AssignModal({ state, setState, employees, shifts, rotations, saving, onSave }) {
  const set = (patch) => setState(s => ({ ...s, ...patch }))

  return (
    <div className="modal-backdrop">
      <div className="modal-box max-w-md" onClick={e => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Assign Shift</h2>
          <button onClick={() => setState(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>

        <div className="space-y-3">
          <div><label className="label">Employee *</label>
            <select className="input-3d text-sm" value={state.employee_id} onChange={e => set({ employee_id: e.target.value })}>
              <option value="">Choose…</option>
              {employees.map(e => <option key={e.id} value={e.id}>{e.name} ({e.employee_code})</option>)}
            </select>
          </div>

          <div className="flex gap-2">
            {[['shift', 'Fixed shift'], ['rotation', 'Rotation plan']].map(([k, label]) => (
              <button key={k} onClick={() => set({ mode: k })} className="flex-1 py-2 rounded-xl text-xs font-bold"
                style={{ background: state.mode === k ? GRAD : 'var(--bg-input)', color: state.mode === k ? '#fff' : 'var(--text-muted)' }}>{label}</button>
            ))}
          </div>

          {state.mode === 'shift' ? (
            <div><label className="label">Shift *</label>
              <select className="input-3d text-sm" value={state.shift_id} onChange={e => set({ shift_id: e.target.value })}>
                <option value="">Choose…</option>
                {shifts.filter(s => s.is_active).map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
            </div>
          ) : (
            <div><label className="label">Rotation plan *</label>
              <select className="input-3d text-sm" value={state.rotation_id} onChange={e => set({ rotation_id: e.target.value })}>
                <option value="">Choose…</option>
                {rotations.filter(r => r.steps.length > 0).map(r => <option key={r.id} value={r.id}>{r.name} ({r.cycle_days}d)</option>)}
              </select>
            </div>
          )}

          <div><label className="label">Effective from *</label>
            <input type="date" className="input-3d text-sm" value={state.effective_from} onChange={e => set({ effective_from: e.target.value })}/>
            <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>
              The current assignment is closed the day before — it becomes history, not deleted.
            </p>
          </div>

          <div><label className="label">Reason</label><input className="input-3d text-sm" value={state.reason} onChange={e => set({ reason: e.target.value })}/></div>
        </div>

        <div className="flex gap-3 pt-5">
          <button onClick={() => setState(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
          <button onClick={onSave} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: GRAD, opacity: saving ? 0.7 : 1 }}>{saving ? 'Assigning…' : 'Assign'}</button>
        </div>
      </div>
    </div>
  )
}

function HistoryDrawer({ id, name, onClose, showToast }) {
  const [rows, setRows] = useState(null)

  useEffect(() => {
    hrApi.shifts.history(id).then(setRows).catch(e => { showToast?.(e?.message || 'Could not load history', 'error'); setRows([]) })
  }, [id, showToast])

  return (
    <div className="modal-backdrop">
      <div className="modal-box max-w-lg" onClick={e => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="font-black text-lg flex items-center gap-2" style={{ color:'var(--text-h)' }}><History size={17} style={{ color:'#a78bfa' }}/> {name}</h2>
          <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>

        {rows === null ? <HrLoading label="Loading history…" />
          : rows.length === 0 ? <p className="text-xs" style={{ color:'var(--text-muted)' }}>No shift has been assigned yet.</p>
          : (
            <div className="space-y-2">
              {rows.map(r => (
                <div key={r.id} className="rounded-xl px-3 py-2.5" style={{ background:'var(--bg-input)' }}>
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{r.shift_name || r.rotation_name}</span>
                    {r.is_current && <span className="text-[9px] font-bold px-1.5 py-0.5 rounded" style={{ background:'rgba(16,185,129,0.15)', color:'#10b981' }}>CURRENT</span>}
                  </div>
                  <p className="text-[11px] mt-0.5" style={{ color:'var(--text-muted)' }}>
                    {r.effective_from} → {r.effective_to || 'ongoing'}{r.reason ? ` · ${r.reason}` : ''}
                  </p>
                </div>
              ))}
            </div>
          )}
      </div>
    </div>
  )
}
