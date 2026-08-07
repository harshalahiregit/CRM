import { useState, useEffect, useCallback } from 'react'
import { Plus, Pencil, Trash2, X, Building, DoorOpen, Layers, MapPin, Users, History, Info } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
// #3 — every view is already loaded in one call, so filtering is in memory.
import ListFilter, { applyListFilter } from '@/components/ui/ListFilter'

const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'

/**
 * Branch → Office → Floor, plus seating.
 *
 * Each level filters the one below it, so an office is always created against a
 * known branch and a floor against a known office — the backend refuses
 * mismatches, and the UI simply never offers them.
 */
export default function WorkplaceManagement({ showToast }) {
  const [view, setView] = useState('branches')
  const [states, setStates] = useState([])
  const [branches, setBranches] = useState([])
  const [offices, setOffices] = useState([])
  const [floors, setFloors] = useState([])
  const [seating, setSeating] = useState([])
  // #3 — shared across the four views and reset when the view changes.
  const [search, setSearch] = useState('')
  const [statusF, setStatusF] = useState('All')
  const [employees, setEmployees] = useState([])
  const [loading, setLoading] = useState(true)
  const [modal, setModal] = useState(null)
  const [assignModal, setAssignModal] = useState(null)
  const [historyFor, setHistoryFor] = useState(null)
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [meta, b, o, f, s, emps] = await Promise.all([
        hrApi.workplace.meta(), hrApi.workplace.branches(), hrApi.workplace.offices(),
        hrApi.workplace.floors(), hrApi.workplace.seating(), hrApi.employees.list({ per_page: 500 }),
      ])
      setStates(meta.work_states || []); setBranches(b); setOffices(o); setFloors(f); setSeating(s); setEmployees(emps)
    } catch (e) { showToast?.(e?.message || 'Could not load workplaces', 'error') }
    finally { setLoading(false) }
  }, [showToast])

  useEffect(() => { load() }, [load])
  useEffect(() => { setSearch(''); setStatusF('All') }, [view])

  const activeMatch = [ (r, v) => (r.is_active === false ? 'Inactive' : 'Active') === v ]
  const shownBranches = applyListFilter(branches, { search, fields: ['name','code','city','work_state'], matchers: [[statusF, activeMatch[0]]] })
  const shownOffices  = applyListFilter(offices,  { search, fields: ['name','code','branch_name'],       matchers: [[statusF, activeMatch[0]]] })
  const shownFloors   = applyListFilter(floors,   { search, fields: ['name','code','office_name'],       matchers: [[statusF, activeMatch[0]]] })
  const shownSeating  = applyListFilter(seating,  { search, fields: ['employee_name','employee_code','branch_name','office_name','floor_name','seat_number'] })

  const save = async () => {
    setSaving(true)
    try {
      const { kind, id, form } = modal
      if (kind === 'branch') await hrApi.workplace.saveBranch(id, form)
      if (kind === 'office') await hrApi.workplace.saveOffice(id, form)
      if (kind === 'floor')  await hrApi.workplace.saveFloor(id, { ...form, seat_capacity: form.seat_capacity === '' ? null : Number(form.seat_capacity) })
      showToast?.('Saved'); setModal(null); load()
    } catch (e) { showToast?.(e?.response?.data?.message || 'Could not save', 'error') }
    finally { setSaving(false) }
  }

  const remove = async (kind, row) => {
    if (!window.confirm(`Delete "${row.name}"?`)) return
    try {
      if (kind === 'branch') await hrApi.workplace.removeBranch(row.id)
      if (kind === 'office') await hrApi.workplace.removeOffice(row.id)
      if (kind === 'floor')  await hrApi.workplace.removeFloor(row.id)
      showToast?.('Deleted'); load()
    } catch (e) { showToast?.(e?.response?.data?.message || 'Could not delete', 'error') }
  }

  const assign = async () => {
    setSaving(true)
    try {
      await hrApi.workplace.assign({
        employee_id: Number(assignModal.employee_id),
        branch_id: Number(assignModal.branch_id),
        office_id: assignModal.office_id ? Number(assignModal.office_id) : null,
        floor_id: assignModal.floor_id ? Number(assignModal.floor_id) : null,
        seat_no: assignModal.seat_no || null,
        effective_from: assignModal.effective_from,
        reason: assignModal.reason || null,
        sync_work_state: !!assignModal.sync_work_state,
      })
      showToast?.('Work location assigned'); setAssignModal(null); load()
    } catch (e) { showToast?.(e?.response?.data?.message || 'Could not assign', 'error') }
    finally { setSaving(false) }
  }

  if (loading) return <HrLoading label="Loading workplaces…" />

  const TABS = [
    ['branches', 'Branches', Building, branches.length],
    ['offices', 'Offices', DoorOpen, offices.length],
    ['floors', 'Floors', Layers, floors.length],
    ['seating', 'Seating', Users, seating.length],
  ]

  const addFor = {
    branches: () => setModal({ kind:'branch', id:null, form:{ name:'', code:'', address:'', city:'', work_state:'', pincode:'', phone:'', email:'', is_head_office:false, is_active:true } }),
    offices:  () => setModal({ kind:'office', id:null, form:{ branch_id:'', name:'', code:'', address:'', is_active:true } }),
    floors:   () => setModal({ kind:'floor',  id:null, form:{ office_id:'', name:'', code:'', seat_capacity:'', is_active:true } }),
    seating:  () => setAssignModal({ employee_id:'', branch_id:'', office_id:'', floor_id:'', seat_no:'', effective_from:new Date().toISOString().slice(0,10), reason:'', sync_work_state:false }),
  }

  return (
    <div className="space-y-4">
      <div className="flex gap-1.5 flex-wrap items-center">
        {TABS.map(([k, label, Icon, count]) => (
          <button key={k} onClick={() => setView(k)}
            className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold"
            style={{ background: view === k ? 'rgba(124,58,237,0.15)' : 'var(--bg-input)', color: view === k ? '#a78bfa' : 'var(--text-muted)' }}>
            <Icon size={13}/> {label}
            <span className="px-1.5 rounded" style={{ background:'var(--bg-card)' }}>{count}</span>
          </button>
        ))}
        <button onClick={addFor[view]} className="ml-auto flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold text-white" style={{ background: GRAD }}>
          <Plus size={14}/> {view === 'seating' ? 'Assign Location' : `Add ${view.slice(0, -1)}`}
        </button>
      </div>

      {/* #3 — Status is meaningless for a seating assignment, so it is offered
          only on the three master views. */}
      <ListFilter
        search={search} setSearch={setSearch}
        placeholder={view === 'seating' ? 'Employee, office or seat…' : `Search ${view}…`}
        selects={view === 'seating' ? [] : [
          { key:'status', label:'Status', value:statusF, onChange:setStatusF, options:['All','Active','Inactive'] },
        ]}
        onClear={()=>{ setSearch(''); setStatusF('All') }}
      />

      {/* ── Branches ── */}
      {view === 'branches' && (shownBranches.length === 0
        ? <HrEmpty icon={Building} title={branches.length ? 'No matching branches' : 'No branches yet'} subtitle={branches.length ? 'Nothing matches these filters.' : 'A branch also carries the state Professional Tax is levied under.'} />
        : (
          <div className="grid md:grid-cols-2 gap-3">
            {shownBranches.map(b => (
              <div key={b.id} className="card-3d" style={{ padding:'14px 16px', opacity: b.is_active ? 1 : 0.55 }}>
                <div className="flex items-start gap-2">
                  <div className="flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                      <span className="text-sm font-black" style={{ color:'var(--text-h)' }}>{b.name}</span>
                      {b.is_head_office && <span className="text-[9px] font-bold px-1.5 py-0.5 rounded" style={{ background:'rgba(124,58,237,0.15)', color:'#a78bfa' }}>HEAD OFFICE</span>}
                      {!b.is_active && <span className="text-[9px] font-bold px-1.5 py-0.5 rounded" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>INACTIVE</span>}
                    </div>
                    <p className="text-[11px] mt-1 flex items-center gap-1" style={{ color:'var(--text-muted)' }}>
                      <MapPin size={10}/> {[b.city, b.work_state, b.pincode].filter(Boolean).join(', ') || 'No address'}
                    </p>
                    {!b.work_state && (
                      <p className="text-[10px] mt-1" style={{ color:'#fbbf24' }}>
                        No work state — Professional Tax cannot be resolved from this branch.
                      </p>
                    )}
                    <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>{b.offices_count ?? 0} office(s)</p>
                  </div>
                  <button onClick={() => setModal({ kind:'branch', id:b.id, form:{ ...b } })} className="p-2 rounded-lg" style={{ background:'var(--bg-input)' }}><Pencil size={13} style={{ color:'var(--text-muted)' }}/></button>
                  <button onClick={() => remove('branch', b)} className="p-2 rounded-lg" style={{ background:'rgba(239,68,68,0.1)' }}><Trash2 size={13} style={{ color:'#f87171' }}/></button>
                </div>
              </div>
            ))}
          </div>
        ))}

      {/* ── Offices ── */}
      {view === 'offices' && (shownOffices.length === 0
        ? <HrEmpty icon={DoorOpen} title="No offices yet" subtitle="An office belongs to a branch." />
        : (
          <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:640 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Office','Branch','Floors','Status',''].map((h,i)=><th key={i} className="text-left px-3 py-3 label-caps">{h}</th>)}</tr></thead>
              <tbody>
                {shownOffices.map(o => (
                  <tr key={o.id} style={{ borderBottom:'1px solid var(--border)' }}>
                    <td className="px-3 py-2.5 font-bold" style={{ color:'var(--text-h)' }}>{o.name}</td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{o.branch_name}</td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{o.floors_count ?? 0}</td>
                    <td className="px-3 py-2.5 text-[10px] font-bold" style={{ color: o.is_active ? '#10b981' : 'var(--text-muted)' }}>{o.is_active ? 'Active' : 'Inactive'}</td>
                    <td className="px-3 py-2.5 text-right whitespace-nowrap">
                      <button onClick={() => setModal({ kind:'office', id:o.id, form:{ ...o } })} className="p-1.5 rounded-lg" style={{ background:'var(--bg-input)' }}><Pencil size={12} style={{ color:'var(--text-muted)' }}/></button>
                      <button onClick={() => remove('office', o)} className="ml-1 p-1.5 rounded-lg" style={{ background:'rgba(239,68,68,0.1)' }}><Trash2 size={12} style={{ color:'#f87171' }}/></button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ))}

      {/* ── Floors ── */}
      {view === 'floors' && (shownFloors.length === 0
        ? <HrEmpty icon={Layers} title="No floors yet" subtitle="A floor belongs to an office." />
        : (
          <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:640 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Floor','Office','Occupancy','Status',''].map((h,i)=><th key={i} className="text-left px-3 py-3 label-caps">{h}</th>)}</tr></thead>
              <tbody>
                {shownFloors.map(f => (
                  <tr key={f.id} style={{ borderBottom:'1px solid var(--border)' }}>
                    <td className="px-3 py-2.5 font-bold" style={{ color:'var(--text-h)' }}>{f.name}</td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{f.office_name}</td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>
                      {f.seats_used}{f.seat_capacity ? ` / ${f.seat_capacity}` : ''}
                      {f.seat_capacity && f.seats_used > f.seat_capacity && (
                        <span className="ml-1.5 text-[10px] font-bold" style={{ color:'#fbbf24' }}>over capacity</span>
                      )}
                    </td>
                    <td className="px-3 py-2.5 text-[10px] font-bold" style={{ color: f.is_active ? '#10b981' : 'var(--text-muted)' }}>{f.is_active ? 'Active' : 'Inactive'}</td>
                    <td className="px-3 py-2.5 text-right whitespace-nowrap">
                      <button onClick={() => setModal({ kind:'floor', id:f.id, form:{ ...f, seat_capacity: f.seat_capacity ?? '' } })} className="p-1.5 rounded-lg" style={{ background:'var(--bg-input)' }}><Pencil size={12} style={{ color:'var(--text-muted)' }}/></button>
                      <button onClick={() => remove('floor', f)} className="ml-1 p-1.5 rounded-lg" style={{ background:'rgba(239,68,68,0.1)' }}><Trash2 size={12} style={{ color:'#f87171' }}/></button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ))}

      {/* ── Seating ── */}
      {view === 'seating' && (shownSeating.length === 0
        ? <HrEmpty icon={Users} title="Nobody is seated yet" subtitle="Assign employees to a branch, and optionally an office, floor and seat." />
        : (
          <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:800 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Branch','Office','Floor','Seat','Since',''].map((h,i)=><th key={i} className="text-left px-3 py-3 label-caps">{h}</th>)}</tr></thead>
              <tbody>
                {shownSeating.map(s => (
                  <tr key={s.id} style={{ borderBottom:'1px solid var(--border)' }}>
                    <td className="px-3 py-2.5">
                      <span className="font-semibold" style={{ color:'var(--text-h)' }}>{s.employee_name}</span>{' '}
                      <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{s.employee_code}</span>
                    </td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{s.branch_name}{s.work_state ? ` · ${s.work_state}` : ''}</td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{s.office_name || '—'}</td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{s.floor_name || '—'}</td>
                    <td className="px-3 py-2.5 font-mono text-[11px]" style={{ color:'var(--text-muted)' }}>{s.seat_no || '—'}</td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{s.effective_from}</td>
                    <td className="px-3 py-2.5 text-right">
                      <button onClick={() => setHistoryFor({ id:s.employee_id, name:s.employee_name })}
                        className="text-[10px] font-bold px-2.5 py-1 rounded-lg flex items-center gap-1 ml-auto"
                        style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}><History size={11}/> History</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ))}

      {modal && <RecordModal modal={modal} setModal={setModal} states={states} branches={branches} offices={offices} saving={saving} onSave={save} />}
      {assignModal && <SeatModal state={assignModal} setState={setAssignModal} employees={employees} branches={branches} offices={offices} floors={floors} saving={saving} onSave={assign} />}
      {historyFor && <LocationHistory {...historyFor} onClose={() => setHistoryFor(null)} showToast={showToast} />}
    </div>
  )
}

/* ────────────────────────────────────────────────────────────────────── */

function RecordModal({ modal, setModal, states, branches, offices, saving, onSave }) {
  const { kind, form } = modal
  const set = (patch) => setModal(m => ({ ...m, form: { ...m.form, ...patch } }))
  const title = { branch:'Branch', office:'Office', floor:'Floor' }[kind]

  return (
    <div className="modal-backdrop">
      <div className="modal-box max-w-lg" onClick={e => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{modal.id ? 'Edit' : 'Add'} {title}</h2>
          <button onClick={() => setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>

        <div className="space-y-3">
          {kind === 'office' && (
            <div><label className="label">Branch *</label>
              <select className="input-3d text-sm" value={form.branch_id || ''} onChange={e => set({ branch_id: e.target.value })}>
                <option value="">Choose…</option>
                {branches.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
              </select>
            </div>
          )}
          {kind === 'floor' && (
            <div><label className="label">Office *</label>
              <select className="input-3d text-sm" value={form.office_id || ''} onChange={e => set({ office_id: e.target.value })}>
                <option value="">Choose…</option>
                {offices.map(o => <option key={o.id} value={o.id}>{o.name} · {o.branch_name}</option>)}
              </select>
            </div>
          )}

          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Name *</label><input className="input-3d text-sm" value={form.name || ''} onChange={e => set({ name: e.target.value })}/></div>
            <div><label className="label">Code</label><input className="input-3d text-sm" value={form.code || ''} onChange={e => set({ code: e.target.value })}/></div>
          </div>

          {kind === 'branch' && (
            <>
              <div><label className="label">Address</label><textarea rows={2} className="input-3d text-sm resize-none" value={form.address || ''} onChange={e => set({ address: e.target.value })}/></div>
              <div className="grid grid-cols-3 gap-3">
                <div><label className="label">City</label><input className="input-3d text-sm" value={form.city || ''} onChange={e => set({ city: e.target.value })}/></div>
                <div className="col-span-2"><label className="label">Work State</label>
                  <select className="input-3d text-sm" value={form.work_state || ''} onChange={e => set({ work_state: e.target.value })}>
                    <option value="">Not set</option>
                    {states.map(s => <option key={s.code} value={s.name}>{s.name}</option>)}
                  </select>
                  <p className="text-[10px] mt-1 flex items-start gap-1" style={{ color:'var(--text-muted)' }}>
                    <Info size={10} style={{ flexShrink:0, marginTop:1 }}/>
                    The jurisdiction Professional Tax is levied under — the same list employees use.
                  </p>
                </div>
              </div>
              <div className="grid grid-cols-3 gap-3">
                <div><label className="label">Pincode</label><input className="input-3d text-sm" value={form.pincode || ''} onChange={e => set({ pincode: e.target.value })}/></div>
                <div><label className="label">Phone</label><input className="input-3d text-sm" value={form.phone || ''} onChange={e => set({ phone: e.target.value })}/></div>
                <div><label className="label">Email</label><input type="email" className="input-3d text-sm" value={form.email || ''} onChange={e => set({ email: e.target.value })}/></div>
              </div>
              <label className="flex items-center gap-2 text-xs font-semibold cursor-pointer" style={{ color:'var(--text-muted)' }}>
                <input type="checkbox" checked={!!form.is_head_office} onChange={e => set({ is_head_office: e.target.checked })}/>
                Head office <span className="opacity-70">(setting this clears it on every other branch)</span>
              </label>
            </>
          )}

          {kind === 'office' && (
            <div><label className="label">Address</label><textarea rows={2} className="input-3d text-sm resize-none" value={form.address || ''} onChange={e => set({ address: e.target.value })}/></div>
          )}
          {kind === 'floor' && (
            <div><label className="label">Seat capacity</label><input type="number" className="input-3d text-sm" placeholder="Not set" value={form.seat_capacity} onChange={e => set({ seat_capacity: e.target.value })}/></div>
          )}

          <label className="flex items-center gap-2 text-xs font-semibold cursor-pointer" style={{ color:'var(--text-muted)' }}>
            <input type="checkbox" checked={!!form.is_active} onChange={e => set({ is_active: e.target.checked })}/> Active
          </label>
        </div>

        <div className="flex gap-3 pt-5">
          <button onClick={() => setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
          <button onClick={onSave} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: GRAD, opacity: saving ? 0.7 : 1 }}>{saving ? 'Saving…' : 'Save'}</button>
        </div>
      </div>
    </div>
  )
}

function SeatModal({ state, setState, employees, branches, offices, floors, saving, onSave }) {
  const set = (patch) => setState(s => ({ ...s, ...patch }))
  // Cascade: only offer children of the chosen parent, so an impossible seat
  // can never be submitted in the first place.
  const branchOffices = offices.filter(o => String(o.branch_id) === String(state.branch_id))
  const officeFloors  = floors.filter(f => String(f.office_id) === String(state.office_id))
  const branch = branches.find(b => String(b.id) === String(state.branch_id))

  return (
    <div className="modal-backdrop">
      <div className="modal-box max-w-md" onClick={e => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>Assign Work Location</h2>
          <button onClick={() => setState(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>

        <div className="space-y-3">
          <div><label className="label">Employee *</label>
            <select className="input-3d text-sm" value={state.employee_id} onChange={e => set({ employee_id: e.target.value })}>
              <option value="">Choose…</option>
              {employees.map(e => <option key={e.id} value={e.id}>{e.name} ({e.employee_code})</option>)}
            </select>
          </div>
          <div><label className="label">Branch *</label>
            <select className="input-3d text-sm" value={state.branch_id} onChange={e => set({ branch_id: e.target.value, office_id: '', floor_id: '' })}>
              <option value="">Choose…</option>
              {branches.filter(b => b.is_active).map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
            </select>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Office</label>
              <select className="input-3d text-sm" value={state.office_id} disabled={!state.branch_id} onChange={e => set({ office_id: e.target.value, floor_id: '' })}>
                <option value="">—</option>
                {branchOffices.map(o => <option key={o.id} value={o.id}>{o.name}</option>)}
              </select>
            </div>
            <div><label className="label">Floor</label>
              <select className="input-3d text-sm" value={state.floor_id} disabled={!state.office_id} onChange={e => set({ floor_id: e.target.value })}>
                <option value="">—</option>
                {officeFloors.map(f => <option key={f.id} value={f.id}>{f.name}</option>)}
              </select>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Seat no.</label><input className="input-3d text-sm" value={state.seat_no} onChange={e => set({ seat_no: e.target.value })}/></div>
            <div><label className="label">Effective from *</label><input type="date" className="input-3d text-sm" value={state.effective_from} onChange={e => set({ effective_from: e.target.value })}/></div>
          </div>
          <div><label className="label">Reason</label><input className="input-3d text-sm" value={state.reason} onChange={e => set({ reason: e.target.value })}/></div>

          {branch?.work_state && (
            <label className="flex items-start gap-2 text-xs font-semibold cursor-pointer" style={{ color:'var(--text-muted)' }}>
              <input type="checkbox" className="mt-0.5" checked={!!state.sync_work_state} onChange={e => set({ sync_work_state: e.target.checked })}/>
              <span>
                Also set the employee&apos;s work state to <b style={{ color:'var(--text-h)' }}>{branch.work_state}</b>
                <span className="block text-[10px] font-normal mt-0.5" style={{ color:'#fbbf24' }}>
                  This changes what Professional Tax is computed against — it is opt-in for that reason.
                </span>
              </span>
            </label>
          )}
        </div>

        <div className="flex gap-3 pt-5">
          <button onClick={() => setState(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
          <button onClick={onSave} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: GRAD, opacity: saving ? 0.7 : 1 }}>{saving ? 'Assigning…' : 'Assign'}</button>
        </div>
      </div>
    </div>
  )
}

function LocationHistory({ id, name, onClose, showToast }) {
  const [rows, setRows] = useState(null)

  useEffect(() => {
    hrApi.workplace.history(id).then(setRows).catch(e => { showToast?.(e?.message || 'Could not load', 'error'); setRows([]) })
  }, [id, showToast])

  return (
    <div className="modal-backdrop">
      <div className="modal-box max-w-lg" onClick={e => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="font-black text-lg flex items-center gap-2" style={{ color:'var(--text-h)' }}><History size={17} style={{ color:'#a78bfa' }}/> {name}</h2>
          <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>

        {rows === null ? <HrLoading label="Loading…" />
          : rows.length === 0 ? <p className="text-xs" style={{ color:'var(--text-muted)' }}>No work location assigned yet.</p>
          : (
            <div className="space-y-2">
              {rows.map(r => (
                <div key={r.id} className="rounded-xl px-3 py-2.5" style={{ background:'var(--bg-input)' }}>
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-xs font-bold" style={{ color:'var(--text-h)' }}>
                      {[r.branch_name, r.office_name, r.floor_name].filter(Boolean).join(' · ')}
                    </span>
                    {r.is_current && <span className="text-[9px] font-bold px-1.5 py-0.5 rounded" style={{ background:'rgba(16,185,129,0.15)', color:'#10b981' }}>CURRENT</span>}
                  </div>
                  <p className="text-[11px] mt-0.5" style={{ color:'var(--text-muted)' }}>
                    {r.effective_from} → {r.effective_to || 'ongoing'}
                    {r.seat_no ? ` · seat ${r.seat_no}` : ''}{r.reason ? ` · ${r.reason}` : ''}
                  </p>
                </div>
              ))}
            </div>
          )}
      </div>
    </div>
  )
}
