import { useState, useEffect, useCallback } from 'react'
import { Plus, Check, X, Trash2, Landmark, AlertTriangle } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import Modal from '@/components/ui/Modal'

/**
 * Review comment #31 — "Earnings: Commissions/Incentives for employees".
 *
 * A commission is raised against an existing EARNING component for one period,
 * approved, and then collected by that period's payroll run. This screen owns the
 * first two steps only; payroll owns the third and this never writes a payroll
 * figure itself.
 */

const STATUS = {
  pending:  { label: 'Pending Approval', colour: '#f59e0b' },
  approved: { label: 'Approved',         colour: '#10b981' },
  rejected: { label: 'Rejected',         colour: '#f87171' },
  paid:     { label: 'Paid',             colour: '#0ea5e9' },
}

const inr = (n) => `₹${Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 })}`
const thisPeriod = () => new Date().toISOString().slice(0, 7)

export default function VariableEarnings({ showToast }) {
  const [rows, setRows]       = useState([])
  const [components, setComponents] = useState([])
  const [employees, setEmployees]   = useState([])
  const [loading, setLoading] = useState(true)
  const [statusF, setStatusF] = useState('All')
  const [periodF, setPeriodF] = useState('')
  const [modal, setModal]     = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [list, comps] = await Promise.all([
        hrApi.variableEarnings.list({
          ...(statusF !== 'All' ? { status: statusF } : {}),
          ...(periodF ? { period: periodF } : {}),
        }),
        hrApi.variableEarnings.components(),
      ])
      setRows(list); setComponents(comps)
    } catch (e) { console.error(e); setRows([]) }
    finally { setLoading(false) }
  }, [statusF, periodF])

  useEffect(() => { load() }, [load])
  useEffect(() => { hrApi.employees.list().then(setEmployees).catch(() => setEmployees([])) }, [])

  const act = async (fn, ok) => {
    try { await fn(); showToast?.(ok); load() }
    catch (e) { showToast?.(e?.response?.data?.message || 'Action failed', 'error') }
  }

  const totals = rows.reduce((a, r) => {
    a[r.status] = (a[r.status] || 0) + r.amount
    return a
  }, {})

  return (
    <div className="space-y-4">
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <p className="text-sm font-black flex items-center gap-2" style={{ color:'var(--text-h)' }}>
            <Landmark size={16} style={{ color:'#f59e0b' }}/> Commissions &amp; Incentives
          </p>
          <p className="text-[11px] mt-0.5" style={{ color:'var(--text-muted)' }}>
            Paid on top of the salary structure for one period. Only <b>approved</b> entries are collected by payroll.
          </p>
        </div>
        <button onClick={()=>setModal({ id:null, form:{ period: thisPeriod() } })}
          className="flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-sm font-bold text-white"
          style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
          <Plus size={15}/> Add Earning
        </button>
      </div>

      {components.length === 0 && !loading && (
        <div className="rounded-xl p-3 flex items-start gap-2" style={{ background:'rgba(251,191,36,0.08)', border:'1px solid rgba(251,191,36,0.25)' }}>
          <AlertTriangle size={13} style={{ color:'#fbbf24', flexShrink:0, marginTop:2 }}/>
          <p className="text-[11px]" style={{ color:'#fbbf24' }}>
            No active <b>Earning</b> salary components exist yet. Define one (e.g. "Sales Commission") under
            Salary Components first — its taxable / PF / ESIC flags decide how the money is treated.
          </p>
        </div>
      )}

      <div className="card-3d flex items-center gap-3 flex-wrap" style={{ padding:'12px 16px' }}>
        <select value={statusF} onChange={e=>setStatusF(e.target.value)} className="input-3d text-sm" style={{ width:'auto' }}>
          <option value="All">All Statuses</option>
          {Object.entries(STATUS).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}
        </select>
        <input type="month" value={periodF} onChange={e=>setPeriodF(e.target.value)}
          className="input-3d text-sm" style={{ width:'auto' }} placeholder="All periods"/>
        {periodF && <button onClick={()=>setPeriodF('')} className="text-[11px] font-semibold" style={{ color:'#a78bfa' }}>Clear period</button>}

        <div className="flex items-center gap-3 ml-auto flex-wrap">
          {Object.entries(STATUS).map(([k, v]) => totals[k] > 0 && (
            <span key={k} className="text-[11px]" style={{ color:'var(--text-muted)' }}>
              {v.label}: <b style={{ color:v.colour }}>{inr(totals[k])}</b>
            </span>
          ))}
        </div>
      </div>

      {loading ? <HrLoading label="Loading earnings…" />
        : rows.length === 0 ? (
          <HrEmpty icon={Landmark} title="No commissions or incentives yet"
            hint="Add one against an Earning component and a payroll period. Payroll collects it once approved." />
        ) : (
          <div className="card-3d" style={{ padding:0, overflow:'hidden' }}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm" style={{ minWidth:760 }}>
                <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>
                  {['Employee','Component','Period','Amount','Reference','Status',''].map(h =>
                    <th key={h} className="text-left px-3 py-2.5 label-caps whitespace-nowrap">{h}</th>)}
                </tr></thead>
                <tbody>
                  {rows.map(r => {
                    const st = STATUS[r.status] || STATUS.pending
                    const locked = r.status === 'paid'
                    return (
                      <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                        <td className="px-3 py-2.5">
                          <span className="font-semibold" style={{ color:'var(--text-h)' }}>{r.employee_name}</span>{' '}
                          <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code}</span>
                        </td>
                        <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.component_name}</td>
                        <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.period}</td>
                        <td className="px-3 py-2.5 font-black" style={{ color:'#f59e0b' }}>{inr(r.amount)}</td>
                        <td className="px-3 py-2.5 text-[11px]" style={{ color:'var(--text-muted)' }}>{r.reference || '—'}</td>
                        <td className="px-3 py-2.5">
                          <span className="px-2 py-0.5 rounded-lg text-[10px] font-bold"
                            style={{ background:`${st.colour}1a`, color:st.colour }}>{st.label}</span>
                        </td>
                        <td className="px-3 py-2.5 text-right whitespace-nowrap">
                          {/* A paid earning belongs to a payroll record — it is
                              history, not a row to edit. */}
                          {locked ? <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>In payroll #{r.payroll_record_id}</span> : (
                            <span className="flex items-center gap-1.5 justify-end">
                              {r.status !== 'approved' && (
                                <IconBtn onClick={()=>act(()=>hrApi.variableEarnings.approve(r.id), 'Approved')}
                                  colour="#10b981" title="Approve"><Check size={13}/></IconBtn>
                              )}
                              {r.status !== 'rejected' && (
                                <IconBtn onClick={()=>{
                                  const remarks = window.prompt('Why is this being rejected?')
                                  if (remarks) act(()=>hrApi.variableEarnings.reject(r.id, remarks), 'Rejected')
                                }} colour="#f87171" title="Reject"><X size={13}/></IconBtn>
                              )}
                              <IconBtn onClick={()=>setModal({ id:r.id, form:{ ...r } })} colour="#a78bfa" title="Edit">✎</IconBtn>
                              <IconBtn onClick={()=>{
                                if (window.confirm('Delete this earning?')) act(()=>hrApi.variableEarnings.remove(r.id), 'Deleted')
                              }} colour="var(--text-muted)" title="Delete"><Trash2 size={13}/></IconBtn>
                            </span>
                          )}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          </div>
        )}

      {modal && (
        <EarningModal modal={modal} components={components} employees={employees}
          onClose={()=>setModal(null)}
          onSaved={(msg)=>{ setModal(null); showToast?.(msg); load() }}
          onError={(msg)=>showToast?.(msg, 'error')} />
      )}
    </div>
  )
}

function IconBtn({ onClick, colour, title, children }) {
  return (
    <button onClick={onClick} title={title} className="rounded-lg px-2 py-1 text-[11px] font-bold"
      style={{ background:'var(--bg-input)', border:'1px solid var(--border)', color:colour }}>
      {children}
    </button>
  )
}

function EarningModal({ modal, components, employees, onClose, onSaved, onError }) {
  const [form, setForm] = useState({
    employee_id: modal.form.employee_id || '',
    component_id: modal.form.component_id || '',
    period: modal.form.period || thisPeriod(),
    amount: modal.form.amount || '',
    reference: modal.form.reference || '',
    remarks: modal.form.remarks || '',
  })
  const [saving, setSaving] = useState(false)
  const set = k => e => setForm(f => ({ ...f, [k]: e.target.value }))
  const component = components.find(c => String(c.id) === String(form.component_id))

  const save = async () => {
    setSaving(true)
    try {
      await hrApi.variableEarnings.save(modal.id, form)
      onSaved(modal.id ? 'Earning updated — it needs approving again' : 'Earning raised')
    } catch (e) { onError(e?.response?.data?.message || 'Could not save') }
    finally { setSaving(false) }
  }

  return (
    <Modal open onClose={onClose} className="max-w-md" style={{ maxHeight:'90vh', overflowY:'auto' }}>
      <div className="flex items-center justify-between mb-4">
        <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>
          {modal.id ? 'Edit Earning' : 'Add Commission / Incentive'}
        </h2>
        <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
      </div>

      <div className="space-y-3">
        <div>
          <label className="label">Employee *</label>
          <select className="input-3d text-sm" value={form.employee_id} onChange={set('employee_id')}>
            <option value="">Select employee…</option>
            {employees.map(e => <option key={e.id} value={e.id}>{e.name} · {e.employee_code}</option>)}
          </select>
        </div>

        <div>
          <label className="label">Earning Component *</label>
          <select className="input-3d text-sm" value={form.component_id} onChange={set('component_id')}>
            <option value="">Select component…</option>
            {components.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
          {/* The component — not this screen — decides the tax treatment. Saying
              so here stops anyone expecting a per-payout override. */}
          {component && (
            <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>
              Treated as {component.taxable ? 'taxable' : 'non-taxable'}
              {component.pf_applicable ? ', PF-applicable' : ''}
              {component.esic_applicable ? ', ESIC-applicable' : ''} — set on the component, not here.
            </p>
          )}
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className="label">Payroll Period *</label>
            <input type="month" className="input-3d text-sm" value={form.period} onChange={set('period')}/>
          </div>
          <div>
            <label className="label">Amount *</label>
            <input type="number" min="0" className="input-3d text-sm" value={form.amount} onChange={set('amount')} placeholder="e.g. 25000"/>
          </div>
        </div>

        <div>
          <label className="label">Reference</label>
          <input className="input-3d text-sm" value={form.reference} onChange={set('reference')} placeholder="Deal, target or scheme this relates to"/>
        </div>
        <div>
          <label className="label">Remarks</label>
          <textarea className="input-3d text-sm" rows={2} value={form.remarks} onChange={set('remarks')}/>
        </div>

        <p className="text-[11px] rounded-xl p-2.5" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>
          Saved as <b>Pending</b>. Payroll collects it only once approved, and only for the period above.
        </p>

        <div className="flex gap-3 pt-1">
          <button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-semibold"
            style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
          <button onClick={save} disabled={saving}
            className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white"
            style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity:saving?0.7:1 }}>
            {saving ? 'Saving…' : modal.id ? 'Save Changes' : 'Add Earning'}
          </button>
        </div>
      </div>
    </Modal>
  )
}
