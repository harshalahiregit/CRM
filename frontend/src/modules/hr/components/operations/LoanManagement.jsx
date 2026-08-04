import { useState, useEffect, useCallback } from 'react'
import {
  Plus, Pencil, Trash2, X, Banknote, Send, CheckCircle2, Ban, Wallet,
  ListChecks, AlertTriangle, Search, Zap,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'

const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'
const inr = v => `₹${Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`

const STATUS_C = {
  Draft:     { c:'var(--text-muted)', bg:'var(--bg-input)' },
  Submitted: { c:'#fbbf24', bg:'rgba(251,191,36,0.12)' },
  Approved:  { c:'#3b82f6', bg:'rgba(59,130,246,0.12)' },
  Disbursed: { c:'#10b981', bg:'rgba(16,185,129,0.12)' },
  Closed:    { c:'var(--text-muted)', bg:'var(--bg-input)' },
  Rejected:  { c:'#f87171', bg:'rgba(239,68,68,0.1)' },
  Cancelled: { c:'var(--text-muted)', bg:'var(--bg-input)' },
}

const INST_C = {
  Pending:  { c:'var(--text-muted)', bg:'var(--bg-input)' },
  Deducted: { c:'#10b981', bg:'rgba(16,185,129,0.12)' },
  Waived:   { c:'#3b82f6', bg:'rgba(59,130,246,0.12)' },
  Skipped:  { c:'#f87171', bg:'rgba(239,68,68,0.1)' },
}

/**
 * Loans & salary advances.
 *
 * The two are the same records — an advance is a loan whose TYPE is flagged — so
 * this screen filters rather than duplicating. The status pill carries the one
 * fact people get wrong: only a DISBURSED loan is deducted by payroll.
 */
export default function LoanManagement({ showToast }) {
  const [view, setView] = useState('loans')
  const [stats, setStats] = useState({})
  const [loans, setLoans] = useState([])
  const [types, setTypes] = useState([])
  const [employees, setEmployees] = useState([])
  const [loading, setLoading] = useState(true)
  const [kindF, setKindF] = useState('all')     // all | loans | advances
  const [statusF, setStatusF] = useState('All')
  const [search, setSearch] = useState('')
  const [typeModal, setTypeModal] = useState(null)
  const [loanModal, setLoanModal] = useState(null)
  const [detail, setDetail] = useState(null)
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [s, l, t, emps] = await Promise.all([
        hrApi.loans.stats(), hrApi.loans.list(), hrApi.loans.types(), hrApi.employees.list({ per_page: 500 }),
      ])
      setStats(s); setLoans(l); setTypes(t); setEmployees(emps)
    } catch (e) { showToast?.(e?.message || 'Could not load loans', 'error') }
    finally { setLoading(false) }
  }, [showToast])

  useEffect(() => { load() }, [load])

  const act = async (fn, msg) => {
    try { const next = await fn(); showToast?.(msg); load(); if (detail) setDetail(next) }
    catch (e) { showToast?.(e?.response?.data?.message || 'Action failed', 'error') }
  }

  const saveType = async () => {
    setSaving(true)
    try {
      await hrApi.loans.saveType(typeModal.id, {
        ...typeModal.form,
        max_amount: typeModal.form.max_amount === '' ? null : Number(typeModal.form.max_amount),
        max_tenure_months: typeModal.form.max_tenure_months === '' ? null : Number(typeModal.form.max_tenure_months),
        interest_rate: typeModal.form.interest_rate === '' ? null : Number(typeModal.form.interest_rate),
      })
      showToast?.('Loan type saved'); setTypeModal(null); load()
    } catch (e) { showToast?.(e?.response?.data?.message || 'Could not save', 'error') }
    finally { setSaving(false) }
  }

  const saveLoan = async () => {
    setSaving(true)
    try {
      await hrApi.loans.save(loanModal.id, {
        employee_id: Number(loanModal.form.employee_id),
        loan_type_id: Number(loanModal.form.loan_type_id),
        principal: Number(loanModal.form.principal),
        tenure_months: Number(loanModal.form.tenure_months) || 1,
        interest_rate: loanModal.form.interest_rate === '' ? null : Number(loanModal.form.interest_rate),
        purpose: loanModal.form.purpose || null,
      })
      showToast?.('Saved'); setLoanModal(null); load()
    } catch (e) { showToast?.(e?.response?.data?.message || 'Could not save', 'error') }
    finally { setSaving(false) }
  }

  const visible = loans.filter(l =>
    (kindF === 'all' || (kindF === 'advances' ? l.is_advance : !l.is_advance)) &&
    (statusF === 'All' || l.status === statusF) &&
    (!search || `${l.employee_name} ${l.employee_code} ${l.loan_number || ''}`.toLowerCase().includes(search.toLowerCase()))
  )

  if (loading) return <HrLoading label="Loading loans…" />

  return (
    <div className="space-y-4">
      {/* Stats */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div className="kpi-3d"><p className="text-2xl font-black" style={{ color:'#fbbf24' }}>{stats.pending_approval ?? 0}</p><p className="text-xs mt-1" style={{ color:'var(--text-muted)' }}>Awaiting approval</p></div>
        <div className="kpi-3d"><p className="text-2xl font-black" style={{ color:'#10b981' }}>{stats.active ?? 0}</p><p className="text-xs mt-1" style={{ color:'var(--text-muted)' }}>Being repaid</p></div>
        <div className="kpi-3d"><p className="text-xl font-black" style={{ color:'#f87171' }}>{inr(stats.total_outstanding)}</p><p className="text-xs mt-1" style={{ color:'var(--text-muted)' }}>Outstanding</p></div>
        <div className="kpi-3d"><p className="text-xl font-black" style={{ color:'#7C3AED' }}>{inr(stats.total_disbursed)}</p><p className="text-xs mt-1" style={{ color:'var(--text-muted)' }}>Disbursed to date</p></div>
      </div>

      <div className="flex gap-1.5 flex-wrap items-center">
        {[['loans', 'Loans & Advances', Banknote], ['types', 'Loan Types', ListChecks], ['recovery', 'Recovery', Wallet]].map(([k, label, Icon]) => (
          <button key={k} onClick={() => setView(k)}
            className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold"
            style={{ background: view === k ? 'rgba(124,58,237,0.15)' : 'var(--bg-input)', color: view === k ? '#a78bfa' : 'var(--text-muted)' }}>
            <Icon size={13}/> {label}
          </button>
        ))}
        {view !== 'recovery' && <button
          onClick={() => view === 'types'
            ? setTypeModal({ id:null, form:{ name:'', code:'', is_advance:false, max_amount:'', max_tenure_months:'', interest_rate:'', requires_approval:true, description:'', is_active:true } })
            : setLoanModal({ id:null, form:{ employee_id:'', loan_type_id:'', principal:'', tenure_months:12, interest_rate:'', purpose:'' } })}
          className="ml-auto flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold text-white" style={{ background: GRAD }}>
          <Plus size={14}/> {view === 'types' ? 'Add Loan Type' : 'New Loan / Advance'}
        </button>}
      </div>

      {/* ── Loan types ── */}
      {view === 'types' && (types.length === 0
        ? <HrEmpty icon={ListChecks} title="No loan types" subtitle="A salary advance is a loan type with the advance flag set — one engine serves both." />
        : (
          <div className="space-y-2">
            {types.map(t => (
              <div key={t.id} className="card-3d flex items-center gap-3 flex-wrap" style={{ padding:'13px 16px', opacity: t.is_active ? 1 : 0.55 }}>
                <div className="flex-1 min-w-[220px]">
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-sm font-black" style={{ color:'var(--text-h)' }}>{t.name}</span>
                    {t.is_advance && <span className="text-[9px] font-bold px-1.5 py-0.5 rounded flex items-center gap-1" style={{ background:'rgba(59,130,246,0.12)', color:'#3b82f6' }}><Zap size={9}/> ADVANCE</span>}
                    {!t.requires_approval && <span className="text-[9px] font-bold px-1.5 py-0.5 rounded" style={{ background:'rgba(16,185,129,0.12)', color:'#10b981' }}>AUTO-APPROVED</span>}
                  </div>
                  <p className="text-[11px] mt-1" style={{ color:'var(--text-muted)' }}>
                    {t.max_amount ? `Up to ${inr(t.max_amount)}` : 'No amount ceiling'}
                    {t.is_advance ? ' · single instalment, interest-free'
                      : ` · up to ${t.max_tenure_months ?? '∞'} months · ${t.interest_rate ?? 0}% p.a.`}
                  </p>
                </div>
                <button onClick={() => setTypeModal({ id:t.id, form:{ ...t, max_amount:t.max_amount ?? '', max_tenure_months:t.max_tenure_months ?? '', interest_rate:t.interest_rate ?? '' } })}
                  className="p-2 rounded-lg" style={{ background:'var(--bg-input)' }}><Pencil size={13} style={{ color:'var(--text-muted)' }}/></button>
                <button onClick={async () => {
                  if (!window.confirm(`Delete "${t.name}"?`)) return
                  try { await hrApi.loans.removeType(t.id); showToast?.('Deleted'); load() }
                  catch (e) { showToast?.(e?.response?.data?.message || 'Could not delete', 'error') }
                }} className="p-2 rounded-lg" style={{ background:'rgba(239,68,68,0.1)' }}><Trash2 size={13} style={{ color:'#f87171' }}/></button>
              </div>
            ))}
          </div>
        ))}

      {/* ── Loans ── */}
      {view === 'loans' && (
        <>
          <div className="card-3d" style={{ padding:'14px 16px' }}>
            <div className="flex gap-3 flex-wrap items-end">
              <div className="relative flex-1 min-w-[180px]">
                <label className="label">Search</label>
                <Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/>
                <input className="input-3d pl-9 text-sm" placeholder="Employee or loan number…" value={search} onChange={e => setSearch(e.target.value)}/>
              </div>
              <div className="min-w-[140px]"><label className="label">Kind</label>
                <select className="input-3d text-sm" value={kindF} onChange={e => setKindF(e.target.value)}>
                  <option value="all">All</option><option value="loans">Loans only</option><option value="advances">Advances only</option>
                </select>
              </div>
              <div className="min-w-[140px]"><label className="label">Status</label>
                <select className="input-3d text-sm" value={statusF} onChange={e => setStatusF(e.target.value)}>
                  {['All', ...Object.keys(STATUS_C)].map(s => <option key={s}>{s}</option>)}
                </select>
              </div>
            </div>
          </div>

          {visible.length === 0
            ? <HrEmpty icon={Wallet} title="No loans" subtitle="Payroll deducts nothing until a loan is disbursed." />
            : (
              <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
                <table className="w-full text-sm" style={{ minWidth:900 }}>
                  <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>
                    {['Employee','Type','Principal','EMI','Outstanding','Status','From',''].map((h,i)=><th key={i} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}
                  </tr></thead>
                  <tbody>
                    {visible.map(l => {
                      const sc = STATUS_C[l.status] || {}
                      return (
                        <tr key={l.id} style={{ borderBottom:'1px solid var(--border)' }}>
                          <td className="px-3 py-2.5">
                            <span className="font-semibold" style={{ color:'var(--text-h)' }}>{l.employee_name}</span>{' '}
                            <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{l.loan_number || l.employee_code}</span>
                          </td>
                          <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>
                            {l.loan_type}{l.is_advance && <span className="ml-1 text-[9px] font-bold px-1 py-0.5 rounded" style={{ background:'rgba(59,130,246,0.12)', color:'#3b82f6' }}>ADV</span>}
                          </td>
                          <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{inr(l.principal)}</td>
                          <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{inr(l.emi)} × {l.tenure_months}</td>
                          <td className="px-3 py-2.5 font-bold" style={{ color: l.outstanding > 0 ? '#f87171' : '#10b981' }}>{inr(l.outstanding)}</td>
                          <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:sc.bg, color:sc.c }}>{l.status}</span></td>
                          <td className="px-3 py-2.5 text-[11px]" style={{ color:'var(--text-muted)' }}>{l.start_period || '—'}</td>
                          <td className="px-3 py-2.5 text-right">
                            <button onClick={async () => setDetail(await hrApi.loans.get(l.id))}
                              className="text-[10px] font-bold px-2.5 py-1 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}>Open</button>
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            )}
        </>
      )}

      {view === 'recovery' && <RecoveryQueue showToast={showToast} />}

      {typeModal && <TypeModal modal={typeModal} setModal={setTypeModal} saving={saving} onSave={saveType} />}
      {loanModal && <LoanModal modal={loanModal} setModal={setLoanModal} employees={employees} types={types} saving={saving} onSave={saveLoan} showToast={showToast} />}
      {detail && <LoanDetail loan={detail} onClose={() => setDetail(null)} act={act} showToast={showToast} />}
    </div>
  )
}

/* ────────────────────────────────────────────────────────────────────── */

function TypeModal({ modal, setModal, saving, onSave }) {
  const { form } = modal
  const set = (patch) => setModal(m => ({ ...m, form: { ...m.form, ...patch } }))

  return (
    <div className="modal-backdrop" onClick={() => setModal(null)}>
      <div className="modal-box max-w-lg" onClick={e => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>{modal.id ? 'Edit' : 'Add'} Loan Type</h2>
          <button onClick={() => setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>

        <div className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Name *</label><input className="input-3d text-sm" value={form.name} onChange={e => set({ name: e.target.value })}/></div>
            <div><label className="label">Code</label><input className="input-3d text-sm" value={form.code || ''} onChange={e => set({ code: e.target.value })}/></div>
          </div>

          <label className="flex items-start gap-2 text-xs font-semibold cursor-pointer" style={{ color:'var(--text-muted)' }}>
            <input type="checkbox" className="mt-0.5" checked={!!form.is_advance} onChange={e => set({ is_advance: e.target.checked })}/>
            <span>Salary advance
              <span className="block text-[10px] font-normal mt-0.5">Repaid in a single interest-free instalment. Tenure and rate are ignored.</span>
            </span>
          </label>

          <div className="grid grid-cols-3 gap-3">
            <div><label className="label">Max amount</label><input type="number" className="input-3d text-sm" placeholder="No cap" value={form.max_amount} onChange={e => set({ max_amount: e.target.value })}/></div>
            <div><label className="label">Max months</label><input type="number" className="input-3d text-sm" placeholder="No cap" disabled={form.is_advance} value={form.max_tenure_months} onChange={e => set({ max_tenure_months: e.target.value })}/></div>
            <div><label className="label">Interest % p.a.</label><input type="number" step="any" className="input-3d text-sm" placeholder="0" disabled={form.is_advance} value={form.interest_rate} onChange={e => set({ interest_rate: e.target.value })}/></div>
          </div>

          <div className="flex gap-4">
            <label className="flex items-center gap-1.5 text-xs font-semibold cursor-pointer" style={{ color:'var(--text-muted)' }}>
              <input type="checkbox" checked={!!form.requires_approval} onChange={e => set({ requires_approval: e.target.checked })}/> Requires approval
            </label>
            <label className="flex items-center gap-1.5 text-xs font-semibold cursor-pointer" style={{ color:'var(--text-muted)' }}>
              <input type="checkbox" checked={!!form.is_active} onChange={e => set({ is_active: e.target.checked })}/> Active
            </label>
          </div>
          <p className="text-[10px]" style={{ color:'var(--text-muted)' }}>
            With approval off, submitting goes straight to Approved — no queue nobody is watching.
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

function LoanModal({ modal, setModal, employees, types, saving, onSave, showToast }) {
  const { form } = modal
  const [preview, setPreview] = useState(null)
  const [eligibility, setEligibility] = useState(null)
  const set = (patch) => setModal(m => ({ ...m, form: { ...m.form, ...patch } }))
  const type = types.find(t => String(t.id) === String(form.loan_type_id))

  // Preview and affordability move together: the EMI is what affordability is
  // judged on, so re-deriving one without the other would show a stale verdict.
  const runPreview = async () => {
    if (!form.principal) return
    try {
      const schedule = await hrApi.loans.preview({
        principal: Number(form.principal),
        tenure_months: type?.is_advance ? 1 : (Number(form.tenure_months) || 1),
        interest_rate: type?.is_advance ? 0 : Number(form.interest_rate || type?.interest_rate || 0),
      })
      setPreview(schedule)

      if (form.employee_id) {
        setEligibility(await hrApi.loans.eligibility({
          employee_id: Number(form.employee_id),
          emi: schedule.emi,
          exclude_loan_id: modal.id || 0,
        }))
      }
    } catch (e) { showToast?.(e?.response?.data?.message || 'Could not preview', 'error') }
  }

  return (
    <div className="modal-backdrop" onClick={() => setModal(null)}>
      <div className="modal-box" onClick={e => e.stopPropagation()} style={{ maxWidth:640, width:'95%', maxHeight:'92vh', overflowY:'auto' }}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="font-black text-lg" style={{ color:'var(--text-h)' }}>New Loan / Advance</h2>
          <button onClick={() => setModal(null)} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>

        <div className="space-y-3">
          <div><label className="label">Employee *</label>
            <select className="input-3d text-sm" value={form.employee_id} onChange={e => set({ employee_id: e.target.value })}>
              <option value="">Choose…</option>
              {employees.map(e => <option key={e.id} value={e.id}>{e.name} ({e.employee_code})</option>)}
            </select>
          </div>
          <div><label className="label">Type *</label>
            <select className="input-3d text-sm" value={form.loan_type_id}
              onChange={e => { const t = types.find(x => String(x.id) === e.target.value); set({ loan_type_id: e.target.value, interest_rate: t?.interest_rate ?? '', tenure_months: t?.is_advance ? 1 : form.tenure_months }) }}>
              <option value="">Choose…</option>
              {types.filter(t => t.is_active).map(t => <option key={t.id} value={t.id}>{t.name}{t.is_advance ? ' (advance)' : ''}</option>)}
            </select>
            {type && (
              <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>
                {type.max_amount ? `Capped at ${inr(type.max_amount)}. ` : ''}
                {type.is_advance ? 'Single interest-free instalment.' : `Up to ${type.max_tenure_months ?? '∞'} months.`}
              </p>
            )}
          </div>

          <div className="grid grid-cols-3 gap-3">
            <div><label className="label">Amount *</label><input type="number" className="input-3d text-sm" value={form.principal} onChange={e => set({ principal: e.target.value })} onBlur={runPreview}/></div>
            <div><label className="label">Months</label><input type="number" className="input-3d text-sm" disabled={type?.is_advance} value={type?.is_advance ? 1 : form.tenure_months} onChange={e => set({ tenure_months: e.target.value })} onBlur={runPreview}/></div>
            <div><label className="label">Interest %</label><input type="number" step="any" className="input-3d text-sm" disabled={type?.is_advance} value={type?.is_advance ? 0 : form.interest_rate} onChange={e => set({ interest_rate: e.target.value })} onBlur={runPreview}/></div>
          </div>

          <div><label className="label">Purpose</label><input className="input-3d text-sm" value={form.purpose} onChange={e => set({ purpose: e.target.value })}/></div>

          <button onClick={runPreview} className="text-[11px] font-bold px-3 py-1.5 rounded-lg" style={{ background:'var(--bg-input)', color:'#a78bfa' }}>
            Preview schedule
          </button>

          {preview && (
            <div className="rounded-xl p-3" style={{ background:'var(--bg-input)' }}>
              <p className="text-[11px] font-bold" style={{ color:'var(--text-h)' }}>
                EMI {inr(preview.emi)} · Total {inr(preview.total_payable)}
                {preview.total_interest > 0 && <span style={{ color:'#fbbf24' }}> · Interest {inr(preview.total_interest)}</span>}
              </p>
              <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>
                {preview.rows.length} instalment(s). The final one absorbs rounding so the schedule settles exactly.
              </p>
            </div>
          )}

          <EligibilityMeter result={eligibility} />

          <div className="rounded-xl p-2.5 flex items-start gap-2" style={{ background:'rgba(251,191,36,0.08)', border:'1px solid rgba(251,191,36,0.25)' }}>
            <AlertTriangle size={12} style={{ color:'#fbbf24', flexShrink:0, marginTop:2 }}/>
            <p className="text-[11px]" style={{ color:'#fbbf24' }}>
              Payroll deducts nothing until the loan is <b>disbursed</b>. The schedule is frozen at that point.
            </p>
          </div>
        </div>

        <div className="flex gap-3 pt-5">
          <button onClick={() => setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
          <button onClick={onSave} disabled={saving || eligibility?.blocks}
            title={eligibility?.blocks ? eligibility.message : undefined}
            className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white"
            style={{ background: GRAD, opacity: (saving || eligibility?.blocks) ? 0.5 : 1, cursor: eligibility?.blocks ? 'not-allowed' : 'pointer' }}>
            {saving ? 'Saving…' : eligibility?.blocks ? 'Exceeds EMI limit' : 'Save Draft'}
          </button>
        </div>
      </div>
    </div>
  )
}

/**
 * EMI against net salary, with the two configured thresholds marked on the bar.
 *
 * The bar shows the TOTAL burden — this loan plus anything already being repaid —
 * because that is what the rule is actually judged on. Showing only this loan's
 * share would make a blocked verdict look arbitrary.
 */
function EligibilityMeter({ result }) {
  if (!result) return null

  if (result.status === 'not_evaluated') {
    return (
      <div className="rounded-xl p-2.5 flex items-start gap-2" style={{ background:'var(--bg-input)' }}>
        <AlertTriangle size={12} style={{ color:'var(--text-muted)', flexShrink:0, marginTop:2 }}/>
        <p className="text-[11px]" style={{ color:'var(--text-muted)' }}>{result.message}</p>
      </div>
    )
  }

  const tone = { ok:'#10b981', warning:'#fbbf24', blocked:'#f87171' }[result.status] || 'var(--text-muted)'
  // Cap the fill at 100% so an EMI far above net still renders inside the track.
  const fill = Math.min(100, result.percent ?? 0)

  return (
    <div className="rounded-xl p-3" style={{ background:'var(--bg-input)', border:`1px solid ${tone}33` }}>
      <div className="flex items-center justify-between gap-3 mb-2">
        <p className="text-[11px] font-bold" style={{ color:'var(--text-h)' }}>EMI vs net salary</p>
        <p className="text-sm font-black" style={{ color:tone }}>{result.percent}%</p>
      </div>

      <div className="relative h-2 rounded-full overflow-hidden" style={{ background:'var(--bg-card)' }}>
        <div style={{ width:`${fill}%`, height:'100%', background:tone }}/>
        {/* Threshold markers, drawn from the tenant's own configuration. */}
        {[result.warn_percent, result.max_percent].map((p, i) => (
          <div key={i} style={{
            position:'absolute', left:`${Math.min(100, p)}%`, top:0, bottom:0,
            width:1, background:'var(--text-muted)', opacity:0.6,
          }}/>
        ))}
      </div>

      <p className="text-[10px] mt-1.5" style={{ color:'var(--text-muted)' }}>
        Total EMI {inr(result.total_emi)}
        {result.existing_emi > 0 && <> (incl. {inr(result.existing_emi)} on existing loans)</>}
        {' '}of {inr(result.net_salary)} net · comfort {result.warn_percent}% · limit {result.max_percent}%
      </p>

      {result.message && (
        <p className="text-[11px] mt-1.5 font-semibold" style={{ color:tone }}>{result.message}</p>
      )}
      {result.status === 'blocked' && !result.enforced && (
        <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>
          Enforcement is off for this tenant, so this is advisory only.
        </p>
      )}
    </div>
  )
}

function LoanDetail({ loan, onClose, act, showToast }) {
  const sc = STATUS_C[loan.status] || {}
  const progress = loan.total_payable > 0 ? Math.round((loan.total_repaid / loan.total_payable) * 100) : 0

  const disburse = () => {
    const period = window.prompt('First deduction period (YYYY-MM). Leave blank for the month after disbursement:')
    if (period === null) return
    act(() => hrApi.loans.disburse(loan.id, { disbursed_on: new Date().toISOString().slice(0, 10), start_period: period || undefined }), 'Disbursed — repayment scheduled')
  }
  const reject = () => { const r = window.prompt('Reason for rejection?'); if (r) act(() => hrApi.loans.reject(loan.id, r), 'Rejected') }
  const close  = () => { const r = window.prompt('Reason for closing?'); if (r) act(() => hrApi.loans.close(loan.id, r), 'Closed') }
  const waive  = (i) => { const r = window.prompt(`Reason for waiving instalment ${i.sequence}?`); if (r) act(() => hrApi.loans.waive(loan.id, i.id, r), 'Instalment waived') }

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal-box" onClick={e => e.stopPropagation()} style={{ maxWidth:800, width:'95%', maxHeight:'92vh', overflowY:'auto' }}>
        <div className="flex items-center justify-between mb-1">
          <h2 className="font-black text-lg flex items-center gap-2" style={{ color:'var(--text-h)' }}>
            <Banknote size={18} style={{ color:'#a78bfa' }}/> {loan.employee_name}
          </h2>
          <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>
        <div className="flex items-center gap-2 flex-wrap mb-4">
          <span className="text-xs font-mono" style={{ color:'#a78bfa' }}>{loan.loan_number || '—'}</span>
          <span className="text-xs" style={{ color:'var(--text-muted)' }}>{loan.loan_type}</span>
          <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:sc.bg, color:sc.c }}>{loan.status}</span>
          {loan.status !== 'Disbursed' && loan.status !== 'Closed' && (
            <span className="text-[10px]" style={{ color:'#fbbf24' }}>Not being deducted</span>
          )}
        </div>

        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
          {[['Principal', loan.principal, '#7C3AED'], ['EMI', loan.emi, '#3b82f6'], ['Repaid', loan.total_repaid, '#10b981'], ['Outstanding', loan.outstanding, '#f87171']].map(([l, v, c]) => (
            <div key={l} className="rounded-xl px-3 py-2.5" style={{ background:'var(--bg-input)' }}>
              <p className="text-base font-black" style={{ color:c }}>{inr(v)}</p>
              <p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}>{l}</p>
            </div>
          ))}
        </div>

        {loan.status === 'Disbursed' && (
          <div className="mb-4">
            <div className="h-2 rounded-full overflow-hidden" style={{ background:'var(--bg-input)' }}>
              <div style={{ width:`${progress}%`, height:'100%', background:'linear-gradient(90deg,#10b981,#059669)' }}/>
            </div>
            <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>{progress}% repaid</p>
          </div>
        )}

        {/* Live affordability — a loan approved months ago may no longer fit. */}
        {loan.eligibility && <div className="mb-4"><EligibilityMeter result={loan.eligibility} /></div>}

        {loan.installments?.length > 0 && (
          <div className="overflow-x-auto rounded-xl mb-4" style={{ border:'1px solid var(--border)' }}>
            <table className="w-full text-sm" style={{ minWidth:600 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['#','Period','Amount','Principal','Interest','Status',''].map((h,i)=><th key={i} className="text-left px-3 py-2 label-caps">{h}</th>)}</tr></thead>
              <tbody>
                {loan.installments.map(i => {
                  const ic = INST_C[i.status] || {}
                  return (
                    <tr key={i.id} style={{ borderBottom:'1px solid var(--border)' }}>
                      <td className="px-3 py-2 text-[11px]" style={{ color:'var(--text-muted)' }}>{i.sequence}</td>
                      <td className="px-3 py-2 text-[11px] font-semibold" style={{ color:'var(--text-h)' }}>{i.period}</td>
                      <td className="px-3 py-2 text-[11px]" style={{ color:'var(--text-h)' }}>{inr(i.amount)}</td>
                      <td className="px-3 py-2 text-[11px]" style={{ color:'var(--text-muted)' }}>{inr(i.principal_component)}</td>
                      <td className="px-3 py-2 text-[11px]" style={{ color:'var(--text-muted)' }}>{inr(i.interest_component)}</td>
                      <td className="px-3 py-2"><span className="text-[9px] font-bold px-1.5 py-0.5 rounded" style={{ background:ic.bg, color:ic.c }}>{i.status}</span></td>
                      <td className="px-3 py-2 text-right">
                        {i.status === 'Pending' && loan.status === 'Disbursed' && (
                          <button onClick={() => waive(i)} className="text-[10px] font-bold px-2 py-0.5 rounded" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>Waive</button>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}

        <div className="flex gap-2 flex-wrap">
          {loan.status === 'Draft' && <>
            <button onClick={() => act(() => hrApi.loans.submit(loan.id), 'Submitted')} className="flex items-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: GRAD }}><Send size={14}/> Submit</button>
            <button onClick={() => act(() => hrApi.loans.cancel(loan.id), 'Cancelled')} className="px-4 py-2.5 rounded-xl text-sm font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>Cancel loan</button>
          </>}
          {loan.status === 'Submitted' && <>
            <button onClick={() => act(() => hrApi.loans.approve(loan.id), 'Approved')} className="flex items-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#10b981,#059669)' }}><CheckCircle2 size={14}/> Approve</button>
            <button onClick={reject} className="flex items-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-bold" style={{ background:'rgba(239,68,68,0.1)', color:'#f87171' }}><Ban size={14}/> Reject</button>
          </>}
          {loan.status === 'Approved' && (
            <button onClick={disburse} className="flex items-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: GRAD }}><Wallet size={14}/> Disburse &amp; schedule</button>
          )}
          {loan.status === 'Disbursed' && (
            <button onClick={close} className="px-4 py-2.5 rounded-xl text-sm font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>Close early</button>
          )}
          <button onClick={onClose} className="ml-auto px-5 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Close</button>
        </div>
      </div>
    </div>
  )
}

/**
 * Review comment #38 — loan recovery across the payroll ecosystem.
 *
 * Read-only. Every figure is read from the frozen payroll records and the
 * instalment schedule; opening this screen cannot change what payroll collected.
 *
 * `arrear_count` is the number that matters: an instalment whose period has
 * passed with no payroll run to collect it. That is the difference between
 * "this loan is being repaid" and "this loan is quietly not being repaid".
 */
function RecoveryQueue({ showToast }) {
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [detail, setDetail] = useState(null)

  useEffect(() => {
    hrApi.loans.outstandingRecovery()
      .then(setRows)
      .catch(e => showToast?.(e?.response?.data?.message || 'Could not load recovery', 'error'))
      .finally(() => setLoading(false))
  }, [showToast])

  if (loading) return <HrLoading label="Loading recovery…" />

  if (rows.length === 0) {
    return <HrEmpty icon={Wallet} title="Nothing being recovered"
      subtitle="Only disbursed loans appear here — an approved loan has handed over no money yet." />
  }

  const totalOutstanding = rows.reduce((a, r) => a + (r.outstanding || 0), 0)
  const totalArrears = rows.reduce((a, r) => a + (r.arrear_amount || 0), 0)

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
        <div className="kpi-3d">
          <p className="text-2xl font-black" style={{ color:'#7C3AED' }}>{rows.length}</p>
          <p className="text-xs mt-1" style={{ color:'var(--text-muted)' }}>Loans in recovery</p>
        </div>
        <div className="kpi-3d">
          <p className="text-xl font-black" style={{ color:'#f87171' }}>{inr(totalOutstanding)}</p>
          <p className="text-xs mt-1" style={{ color:'var(--text-muted)' }}>Outstanding</p>
        </div>
        <div className="kpi-3d">
          <p className="text-xl font-black" style={{ color: totalArrears > 0 ? '#fbbf24' : '#10b981' }}>{inr(totalArrears)}</p>
          <p className="text-xs mt-1" style={{ color:'var(--text-muted)' }}>In arrears</p>
        </div>
      </div>

      <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
        <table className="w-full text-sm" style={{ minWidth:860 }}>
          <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>
            {['Employee','Loan','EMI','Recovered','Outstanding','Arrears',''].map((h,i)=>
              <th key={i} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}
          </tr></thead>
          <tbody>
            {rows.map(r => (
              <tr key={r.loan_id} style={{ borderBottom:'1px solid var(--border)' }}>
                <td className="px-3 py-2.5">
                  <span className="font-semibold" style={{ color:'var(--text-h)' }}>{r.employee_name}</span>{' '}
                  <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code}</span>
                </td>
                <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>
                  {r.loan_type}
                  {r.is_advance && (
                    <span className="ml-1 text-[9px] font-bold px-1 py-0.5 rounded"
                      style={{ background:'rgba(59,130,246,0.12)', color:'#3b82f6' }}>ADV</span>
                  )}
                </td>
                <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{inr(r.emi)}</td>
                <td className="px-3 py-2.5">
                  <span className="font-semibold" style={{ color:'#10b981' }}>{inr(r.total_repaid)}</span>
                  <span className="text-[10px] ml-1" style={{ color:'var(--text-muted)' }}>({r.percent_recovered}%)</span>
                </td>
                <td className="px-3 py-2.5 font-bold" style={{ color:'#f87171' }}>{inr(r.outstanding)}</td>
                <td className="px-3 py-2.5">
                  {r.arrear_count > 0 ? (
                    <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg inline-flex items-center gap-1"
                      style={{ background:'rgba(251,191,36,0.12)', color:'#fbbf24' }}>
                      <AlertTriangle size={9}/> {r.arrear_count} × {inr(r.arrear_amount)}
                    </span>
                  ) : (
                    <span className="text-[10px]" style={{ color:'#10b981' }}>On schedule</span>
                  )}
                </td>
                <td className="px-3 py-2.5 text-right">
                  <button onClick={async () => {
                    try { setDetail(await hrApi.loans.recovery(r.loan_id)) }
                    catch (e) { showToast?.(e?.response?.data?.message || 'Could not load', 'error') }
                  }} className="text-[10px] font-bold px-2.5 py-1 rounded-lg"
                    style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}>Schedule</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {detail && <RecoveryDetail data={detail} onClose={() => setDetail(null)} />}
    </div>
  )
}

/** Per-instalment recovery, naming the payroll run that collected each one. */
function RecoveryDetail({ data, onClose }) {
  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal-box" onClick={e=>e.stopPropagation()} style={{ maxWidth:820, width:'95%', maxHeight:'92vh', overflowY:'auto' }}>
        <div className="flex items-center justify-between mb-1">
          <h2 className="font-black text-lg flex items-center gap-2" style={{ color:'var(--text-h)' }}>
            <Wallet size={18} style={{ color:'#a78bfa' }}/> {data.loan.employee_name}
          </h2>
          <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>
        <p className="text-xs mb-4" style={{ color:'var(--text-muted)' }}>
          {data.loan.loan_number} · {data.loan.loan_type} · {data.recovery.installments_collected} of {data.recovery.installments_total} collected
        </p>

        <div className="grid grid-cols-3 gap-3 mb-4">
          {[['Scheduled', data.recovery.scheduled_total, '#7C3AED'],
            ['Collected', data.recovery.collected_total, '#10b981'],
            ['Outstanding', data.recovery.outstanding_total, '#f87171']].map(([l, v, c]) => (
            <div key={l} className="rounded-xl px-3 py-2.5" style={{ background:'var(--bg-input)' }}>
              <p className="text-base font-black" style={{ color:c }}>{inr(v)}</p>
              <p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}>{l}</p>
            </div>
          ))}
        </div>

        <div className="overflow-x-auto rounded-xl" style={{ border:'1px solid var(--border)' }}>
          <table className="w-full text-sm" style={{ minWidth:640 }}>
            <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>
              {['#','Period','Amount','Status','Collected by','Attendance source'].map((h,i)=>
                <th key={i} className="text-left px-3 py-2 label-caps">{h}</th>)}
            </tr></thead>
            <tbody>
              {data.installments.map(i => (
                <tr key={i.id} style={{ borderBottom:'1px solid var(--border)' }}>
                  <td className="px-3 py-2 text-[11px]" style={{ color:'var(--text-muted)' }}>{i.sequence}</td>
                  <td className="px-3 py-2 text-[11px] font-semibold" style={{ color:'var(--text-h)' }}>{i.period}</td>
                  <td className="px-3 py-2 text-[11px]" style={{ color:'var(--text-h)' }}>{inr(i.amount)}</td>
                  <td className="px-3 py-2">
                    <span className="text-[9px] font-bold px-1.5 py-0.5 rounded"
                      style={{ background:(INST_C[i.status] || {}).bg, color:(INST_C[i.status] || {}).c }}>
                      {i.status}
                    </span>
                  </td>
                  <td className="px-3 py-2 text-[11px]" style={{ color:'var(--text-muted)' }}>
                    {i.payroll_run ? `${i.payroll_run.period_label} (${i.payroll_run.run_status})` : '—'}
                  </td>
                  {/* The SangoeTrack link: which source supplied that run's days. */}
                  <td className="px-3 py-2 text-[10px]" style={{ color:'var(--text-muted)' }}>
                    {i.payroll_run?.attendance_source || '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
