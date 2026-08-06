import { useState, useEffect, useCallback } from 'react'
import {
  Plus, Trash2, X, FileText, ShieldCheck, AlertTriangle, Send, CheckCircle2,
  Ban, Unlock, Search, Receipt, Home, Briefcase,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'

const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'
const inr = v => `₹${Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`

const STATUS_C = {
  Draft:     { c:'var(--text-muted)', bg:'var(--bg-input)' },
  Submitted: { c:'#fbbf24', bg:'rgba(251,191,36,0.12)' },
  Verified:  { c:'#10b981', bg:'rgba(16,185,129,0.12)' },
  Rejected:  { c:'#f87171', bg:'rgba(239,68,68,0.1)' },
}

/**
 * Investment declarations.
 *
 * The one thing this screen must never let anyone believe: that submitting a claim
 * reduces tax. It does not — only verification does. That is stated on the badge,
 * in the banner, and on the submit confirmation, because the consequence of the
 * misunderstanding lands on the employee in March.
 */
export default function TaxDeclarations({ showToast }) {
  const [meta, setMeta]     = useState({ sections:[], regimes:[], statuses:[], current_fy:'' })
  const [rows, setRows]     = useState([])
  const [loading, setLoading] = useState(true)
  const [employees, setEmployees] = useState([])
  const [fy, setFy]         = useState('')
  const [statusF, setStatusF] = useState('All')
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState(null)   // full declaration being edited
  const [form16, setForm16] = useState(null)

  const load = useCallback(async (year) => {
    setLoading(true)
    try {
      const list = await hrApi.payroll.declarations.list(year ? { financial_year: year } : {})
      setRows(list)
    } catch (e) { showToast?.(e?.message || 'Could not load declarations', 'error') }
    finally { setLoading(false) }
  }, [showToast])

  useEffect(() => {
    (async () => {
      try {
        const [m, emps] = await Promise.all([
          hrApi.payroll.declarations.meta(),
          hrApi.employees.list({ per_page: 500 }),
        ])
        setMeta(m); setFy(m.current_fy); setEmployees(emps)
        await load(m.current_fy)
      } catch (e) { showToast?.(e?.message || 'Could not load', 'error'); setLoading(false) }
    })()
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const open = async (employeeId) => {
    try { setEditing(await hrApi.payroll.declarations.forEmployee(employeeId, fy)) }
    catch (e) { showToast?.(e?.response?.data?.message || 'Could not open the declaration', 'error') }
  }

  const visible = rows.filter(r =>
    (statusF === 'All' || r.status === statusF) &&
    (!search || `${r.employee_name} ${r.employee_code}`.toLowerCase().includes(search.toLowerCase()))
  )

  const counts = meta.statuses.reduce((a, s) => ({ ...a, [s]: rows.filter(r => r.status === s).length }), {})

  if (loading) return <HrLoading label="Loading declarations…" />

  return (
    <div className="space-y-5">
      <div className="card-3d flex items-start gap-3" style={{ padding:'14px 16px' }}>
        <ShieldCheck size={18} style={{ color:'#a78bfa', flexShrink:0, marginTop:2 }}/>
        <div>
          <p className="text-xs font-black" style={{ color:'var(--text-h)' }}>Only a verified declaration reduces tax</p>
          <p className="text-[11px] mt-0.5" style={{ color:'var(--text-muted)' }}>
            A submitted claim is an intention, not evidence. TDS is computed without it until payroll checks the
            proofs and verifies it — acting sooner would under-deduct all year and leave the employee a bill in March.
          </p>
        </div>
      </div>

      {/* Status summary */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        {meta.statuses.map(s => (
          <button key={s} onClick={()=>setStatusF(statusF === s ? 'All' : s)}
            className="kpi-3d text-left" style={{ outline: statusF === s ? '2px solid #7C3AED' : 'none' }}>
            <p className="text-2xl font-black" style={{ color:STATUS_C[s]?.c }}>{counts[s] ?? 0}</p>
            <p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{s}</p>
          </button>
        ))}
      </div>

      {/* Filters */}
      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="relative flex-1 min-w-[200px]">
            <label className="label">Search</label>
            <Search size={14} className="absolute left-3 top-[34px]" style={{ color:'var(--text-muted)' }}/>
            <input className="input-3d pl-9 text-sm" placeholder="Employee name or code…" value={search} onChange={e=>setSearch(e.target.value)}/>
          </div>
          <div className="min-w-[150px]">
            <label className="label">Financial Year</label>
            <input className="input-3d text-sm" placeholder="2026-2027" value={fy}
              onChange={e=>setFy(e.target.value)} onBlur={()=>load(fy)}/>
          </div>
          <div className="min-w-[150px]">
            <label className="label">Open for employee</label>
            <select className="input-3d text-sm" value="" onChange={e=>e.target.value && open(Number(e.target.value))}>
              <option value="">Choose…</option>
              {employees.map(e => <option key={e.id} value={e.id}>{e.name} ({e.employee_code})</option>)}
            </select>
          </div>
        </div>
      </div>

      {visible.length === 0 ? (
        <HrEmpty icon={Receipt} title="No declarations for this year"
          subtitle="Choose an employee above to open their declaration." />
      ) : (
        <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
          <table className="w-full text-sm" style={{ minWidth:820 }}>
            <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>
              {['Employee','Regime','Declared','Verified','Status','Reduces tax',''].map((h,i)=>
                <th key={i} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}
            </tr></thead>
            <tbody>
              {visible.map(r => {
                const sc = STATUS_C[r.status] || {}
                return (
                  <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }}>
                    <td className="px-3 py-2.5">
                      <span className="font-semibold" style={{ color:'var(--text-h)' }}>{r.employee_name}</span>{' '}
                      <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code}</span>
                    </td>
                    <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg uppercase" style={{ background:'rgba(124,58,237,0.12)', color:'#a78bfa' }}>{r.regime}</span></td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{inr(r.declared_total)}</td>
                    <td className="px-3 py-2.5 font-semibold" style={{ color:'#10b981' }}>{inr(r.verified_total)}</td>
                    <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:sc.bg, color:sc.c }}>{r.status}</span></td>
                    <td className="px-3 py-2.5">
                      {r.counts_for_tax
                        ? <span className="text-[10px] font-bold" style={{ color:'#10b981' }}>Yes</span>
                        : <span className="text-[10px] font-bold" style={{ color:'var(--text-muted)' }}>Not yet</span>}
                    </td>
                    <td className="px-3 py-2.5 text-right whitespace-nowrap">
                      <button onClick={()=>open(r.employee_id)} className="text-[11px] font-bold px-3 py-1.5 rounded-lg" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}>Open</button>
                      <button onClick={()=>setForm16({ employeeId:r.employee_id, name:r.employee_name })} className="ml-1.5 text-[11px] font-bold px-3 py-1.5 rounded-lg" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>Tax data</button>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      {editing && <DeclarationDrawer declaration={editing} meta={meta} showToast={showToast}
        onClose={()=>setEditing(null)} onSaved={(d)=>{ setEditing(d); load(fy) }} />}
      {form16 && <Form16Drawer {...form16} onClose={()=>setForm16(null)} showToast={showToast} />}
    </div>
  )
}

/* ────────────────────────────────────────────────────────────────────────
   The declaration itself
   ──────────────────────────────────────────────────────────────────────── */
function DeclarationDrawer({ declaration, meta, onClose, onSaved, showToast }) {
  const [d, setD]         = useState(declaration)
  const [saving, setSaving] = useState(false)
  const editable = d.status === 'Draft'

  const patch = (p) => setD(prev => ({ ...prev, ...p }))
  const setHra = (k, v) => setD(prev => ({ ...prev, hra: { ...(prev.hra || {}), [k]: v } }))
  const setItem = (i, k, v) => setD(prev => ({ ...prev, items: prev.items.map((it, j) => j === i ? { ...it, [k]: v } : it) }))

  const call = async (fn, successMsg) => {
    setSaving(true)
    try { const next = await fn(); setD(next); onSaved(next); showToast?.(successMsg) }
    catch (e) { showToast?.(e?.response?.data?.message || e?.message || 'Action failed', 'error') }
    finally { setSaving(false) }
  }

  const save = () => call(() => hrApi.payroll.declarations.save(d.id, {
    regime: d.regime,
    previous_employer_income: d.previous_employer_income ?? null,
    previous_employer_tds: d.previous_employer_tds ?? null,
    previous_employer_pf: d.previous_employer_pf ?? null,
    previous_employer_pt: d.previous_employer_pt ?? null,
    hra: d.hra || null,
    remarks: d.remarks || null,
    items: (d.items || []).filter(i => i.section).map(i => ({
      section: i.section, particulars: i.particulars || null,
      declared_amount: Number(i.declared_amount) || 0, proof_submitted: !!i.proof_submitted,
    })),
  }), 'Declaration saved')

  const submit = () => {
    if (!window.confirm('Submit this declaration for verification?\n\nIt will lock for editing, and it will NOT reduce tax until payroll verifies the proofs.')) return
    call(() => hrApi.payroll.declarations.submit(d.id), 'Submitted for verification')
  }

  const verify = () => call(() => hrApi.payroll.declarations.verify(d.id, {
    items: (d.items || []).map(i => ({ id: i.id, verified_amount: i.verified_amount ?? i.declared_amount, remarks: i.remarks || null })),
  }), 'Verified — the deductions now apply')

  const reject = () => {
    const remarks = window.prompt('Why is this declaration rejected?')
    if (!remarks) return
    call(() => hrApi.payroll.declarations.reject(d.id, remarks), 'Rejected')
  }

  const reopen = () => call(() => hrApi.payroll.declarations.reopen(d.id), 'Reopened for editing')

  const sc = STATUS_C[d.status] || {}
  const declaredTotal = (d.items || []).reduce((a, i) => a + (Number(i.declared_amount) || 0), 0)

  return (
    <div className="modal-backdrop">
      <div className="modal-box" onClick={e=>e.stopPropagation()} style={{ maxWidth:840, width:'95%', maxHeight:'92vh', overflowY:'auto' }}>
        <div className="flex items-center justify-between mb-1">
          <h2 className="font-black text-lg flex items-center gap-2" style={{ color:'var(--text-h)' }}>
            <Receipt size={18} style={{ color:'#a78bfa' }}/> {d.employee_name}
          </h2>
          <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>
        <div className="flex items-center gap-2 flex-wrap mb-4">
          <span className="text-xs" style={{ color:'var(--text-muted)' }}>{d.financial_year}</span>
          <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:sc.bg, color:sc.c }}>{d.status}</span>
          {!d.counts_for_tax && (
            <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg flex items-center gap-1" style={{ background:'rgba(251,191,36,0.12)', color:'#fbbf24' }}>
              <AlertTriangle size={10}/> Not reducing tax
            </span>
          )}
        </div>

        {/* Regime */}
        <div className="rounded-xl p-3 mb-4" style={{ background:'var(--bg-input)' }}>
          <p className="text-[11px] font-bold uppercase mb-2" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Tax regime</p>
          <div className="flex gap-2">
            {meta.regimes.map(r => (
              <button key={r} disabled={!editable} onClick={()=>patch({ regime:r })}
                className="px-4 py-2 rounded-xl text-xs font-bold capitalize"
                style={{ background: d.regime === r ? GRAD : 'var(--bg-card)', color: d.regime === r ? '#fff' : 'var(--text-muted)',
                         border: d.regime === r ? 'none' : '1px solid var(--border)', opacity: editable ? 1 : 0.6 }}>
                {r} regime
              </button>
            ))}
          </div>
          <p className="text-[10px] mt-2" style={{ color:'var(--text-muted)' }}>
            Which deductions each regime allows is set under <b>Statutory Rules → TDS</b>. Nothing is assumed here.
          </p>
        </div>

        {/* HRA */}
        <Section icon={Home} title="House Rent (HRA exemption)">
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
            <Field label="Annual rent paid" value={d.hra?.rent_paid_annual} disabled={!editable} onChange={v=>setHra('rent_paid_annual', v)} />
            <Field label="Months rented" value={d.hra?.months} disabled={!editable} onChange={v=>setHra('months', v)} placeholder="12" />
            <div>
              <label className="label">City</label>
              <select className="input-3d text-sm" disabled={!editable} value={d.hra?.metro ? '1' : '0'} onChange={e=>setHra('metro', e.target.value === '1')}>
                <option value="0">Non-metro</option><option value="1">Metro</option>
              </select>
            </div>
            <div>
              <label className="label">Landlord PAN</label>
              <input className="input-3d text-sm" disabled={!editable} value={d.hra?.landlord_pan || ''} onChange={e=>setHra('landlord_pan', e.target.value)}/>
            </div>
          </div>
          <p className="text-[10px] mt-1.5" style={{ color:'var(--text-muted)' }}>
            The exemption is the least of HRA received, the configured share of salary, and rent above the configured
            threshold — computed from the salary structure, not entered here.
          </p>
        </Section>

        {/* Previous employer */}
        <Section icon={Briefcase} title="Previous employer (this financial year)">
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
            <Field label="Taxable income" value={d.previous_employer_income} disabled={!editable} onChange={v=>patch({ previous_employer_income:v })}/>
            <Field label="TDS deducted" value={d.previous_employer_tds} disabled={!editable} onChange={v=>patch({ previous_employer_tds:v })}/>
            <Field label="PF deducted" value={d.previous_employer_pf} disabled={!editable} onChange={v=>patch({ previous_employer_pf:v })}/>
            <Field label="Professional tax" value={d.previous_employer_pt} disabled={!editable} onChange={v=>patch({ previous_employer_pt:v })}/>
          </div>
          <p className="text-[10px] mt-1.5" style={{ color:'var(--text-muted)' }}>
            Income is added to the year; TDS already deducted is credited, not collected again.
          </p>
        </Section>

        {/* Items */}
        <Section icon={FileText} title="Deductions claimed">
          <div className="space-y-1.5">
            {(d.items || []).map((it, i) => (
              <div key={i} className="flex items-center gap-1.5 flex-wrap">
                <select className="input-3d text-xs" style={{ width:130 }} disabled={!editable}
                  value={it.section} onChange={e=>setItem(i, 'section', e.target.value)}>
                  <option value="">Section…</option>
                  {meta.sections.map(s => <option key={s.code} value={s.code}>{s.code}</option>)}
                </select>
                <input className="input-3d text-xs flex-1" style={{ minWidth:160 }} placeholder="Particulars — e.g. LIC policy 1234"
                  disabled={!editable} value={it.particulars || ''} onChange={e=>setItem(i, 'particulars', e.target.value)}/>
                <input type="number" className="input-3d text-xs" style={{ width:120 }} placeholder="Declared"
                  disabled={!editable} value={it.declared_amount ?? ''} onChange={e=>setItem(i, 'declared_amount', e.target.value)}/>
                {d.status === 'Submitted' && (
                  <input type="number" className="input-3d text-xs" style={{ width:120 }} placeholder="Verified"
                    value={it.verified_amount ?? ''} onChange={e=>setItem(i, 'verified_amount', e.target.value)}/>
                )}
                {d.status === 'Verified' && (
                  <span className="text-xs font-bold px-2" style={{ color:'#10b981', width:120 }}>{inr(it.verified_amount)}</span>
                )}
                {editable && (
                  <button onClick={()=>patch({ items: d.items.filter((_, j) => j !== i) })}
                    className="p-1.5 rounded-lg" style={{ background:'rgba(239,68,68,0.1)' }}><Trash2 size={12} style={{ color:'#f87171' }}/></button>
                )}
              </div>
            ))}
          </div>
          {editable && (
            <button onClick={()=>patch({ items:[...(d.items || []), { section:'', particulars:'', declared_amount:'' }] })}
              className="flex items-center gap-1 mt-2 px-2.5 py-1.5 rounded-lg text-[11px] font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>
              <Plus size={12}/> Add a deduction
            </button>
          )}
          <p className="text-[11px] mt-2 pt-2" style={{ color:'var(--text-muted)', borderTop:'1px solid var(--border)' }}>
            Declared total <b style={{ color:'var(--text-h)' }}>{inr(declaredTotal)}</b>
            {d.status === 'Verified' && <> · Verified <b style={{ color:'#10b981' }}>{inr(d.verified_total)}</b></>}
            {' '}· each section is capped at the limit configured for the regime.
          </p>
        </Section>

        {d.remarks && (
          <p className="text-[11px] mt-3 px-3 py-2 rounded-lg" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>
            <b style={{ color:'var(--text-h)' }}>Remarks:</b> {d.remarks}
          </p>
        )}

        {/* Actions */}
        <div className="flex gap-2 pt-5 flex-wrap">
          {editable && <>
            <button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Saving…':'Save Draft'}</button>
            <button onClick={submit} disabled={saving} className="flex items-center justify-center gap-1.5 flex-1 py-2.5 rounded-xl text-sm font-bold" style={{ background:'rgba(251,191,36,0.15)', color:'#fbbf24' }}><Send size={14}/> Submit</button>
          </>}
          {d.status === 'Submitted' && <>
            <button onClick={verify} disabled={saving} className="flex items-center justify-center gap-1.5 flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:'linear-gradient(135deg,#10b981,#059669)' }}><CheckCircle2 size={14}/> Verify</button>
            <button onClick={reject} disabled={saving} className="flex items-center justify-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-bold" style={{ background:'rgba(239,68,68,0.1)', color:'#f87171' }}><Ban size={14}/> Reject</button>
          </>}
          {(d.status === 'Verified' || d.status === 'Rejected') && (
            <button onClick={reopen} disabled={saving} className="flex items-center justify-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}><Unlock size={14}/> Reopen</button>
          )}
          <button onClick={onClose} className="px-5 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Close</button>
        </div>
      </div>
    </div>
  )
}

function Section({ icon:Icon, title, children }) {
  return (
    <div className="mb-4">
      <div className="flex items-center gap-2 mb-2"><Icon size={14} style={{ color:'#a78bfa' }}/>
        <p className="text-[11px] font-bold uppercase" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>{title}</p></div>
      {children}
    </div>
  )
}

function Field({ label, value, onChange, disabled, placeholder }) {
  return (
    <div>
      <label className="label">{label}</label>
      <input type="number" className="input-3d text-sm" placeholder={placeholder || 'None'} disabled={disabled}
        value={value ?? ''} onChange={e=>onChange(e.target.value === '' ? null : Number(e.target.value))}/>
    </div>
  )
}

/* ────────────────────────────────────────────────────────────────────────
   Form-16-ready tax data
   ──────────────────────────────────────────────────────────────────────── */
function Form16Drawer({ employeeId, name, onClose, showToast }) {
  const [data, setData]   = useState(null)
  const [years, setYears] = useState([])
  const [year, setYear]   = useState('')

  useEffect(() => {
    (async () => {
      try {
        const ys = await hrApi.payroll.form16.years(employeeId)
        setYears(ys)
        const pick = ys[0]?.label || ''
        setYear(pick)
        setData(await hrApi.payroll.form16.get(employeeId, pick || undefined))
      } catch (e) { showToast?.(e?.message || 'Could not load tax data', 'error') }
    })()
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [employeeId])

  const reload = async (y) => {
    setYear(y); setData(null)
    try { setData(await hrApi.payroll.form16.get(employeeId, y)) }
    catch (e) { showToast?.(e?.message || 'Could not load', 'error') }
  }

  const Row = ({ label, value, bold, color }) => (
    <div className="flex items-center justify-between gap-3 py-1" style={{ borderBottom:'1px solid var(--border)' }}>
      <span className="text-[11px]" style={{ color:'var(--text-muted)' }}>{label}</span>
      <span className={`text-[11px] ${bold ? 'font-black' : 'font-semibold'}`} style={{ color: color || 'var(--text-h)' }}>{inr(value)}</span>
    </div>
  )

  return (
    <div className="modal-backdrop">
      <div className="modal-box" onClick={e=>e.stopPropagation()} style={{ maxWidth:820, width:'95%', maxHeight:'92vh', overflowY:'auto' }}>
        <div className="flex items-center justify-between mb-1">
          <h2 className="font-black text-lg flex items-center gap-2" style={{ color:'var(--text-h)' }}>
            <FileText size={18} style={{ color:'#a78bfa' }}/> Tax data — {name}
          </h2>
          <button onClick={onClose} style={{ color:'var(--text-muted)' }}><X size={18}/></button>
        </div>

        {!data ? <HrLoading label="Assembling from processed payroll…" /> : (
          <>
            <div className="flex items-center gap-2 flex-wrap mb-3">
              {years.length > 0 && (
                <select className="input-3d text-xs" style={{ width:160 }} value={year} onChange={e=>reload(e.target.value)}>
                  {years.map(y => <option key={y.label} value={y.label}>FY {y.short}</option>)}
                </select>
              )}
              <span className="text-[11px]" style={{ color:'var(--text-muted)' }}>
                AY {data.financial_year.assessment_year} · {data.months_processed}/12 months
              </span>
              {data.regime && <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg uppercase" style={{ background:'rgba(124,58,237,0.12)', color:'#a78bfa' }}>{data.regime} regime</span>}
            </div>

            {/* The disclaimer is not decoration — this must never be issued as a Form 16. */}
            <div className="rounded-xl p-2.5 mb-3 flex items-start gap-2" style={{ background:'rgba(251,191,36,0.08)', border:'1px solid rgba(251,191,36,0.3)' }}>
              <AlertTriangle size={13} style={{ color:'#fbbf24', flexShrink:0, marginTop:1 }}/>
              <p className="text-[11px]" style={{ color:'#fbbf24' }}>{data.disclaimer}</p>
            </div>

            {data.warnings?.length > 0 && (
              <div className="rounded-xl p-2.5 mb-4" style={{ background:'var(--bg-input)' }}>
                {data.warnings.map((w, i) => <p key={i} className="text-[11px]" style={{ color:'var(--text-muted)' }}>• {w}</p>)}
              </div>
            )}

            <div className="grid md:grid-cols-2 gap-5">
              <div>
                <p className="text-[10px] font-bold uppercase mb-1.5" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Salary</p>
                <Row label="Gross salary (this employer)" value={data.salary.gross_salary_this_employer}/>
                <Row label="Previous employer income" value={data.salary.previous_employer_income}/>
                <Row label="Gross total" value={data.salary.gross_total_salary} bold/>
                <Row label="HRA exemption" value={data.salary.exemptions.hra.amount} color="#10b981"/>
                <Row label="Standard deduction" value={data.salary.standard_deduction} color="#10b981"/>
              </div>
              <div>
                <p className="text-[10px] font-bold uppercase mb-1.5" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Chapter VI-A</p>
                {data.chapter_via.sections.length === 0
                  ? <p className="text-[11px]" style={{ color:'var(--text-muted)' }}>No deductions applied.</p>
                  : data.chapter_via.sections.map((s, i) => (
                    <div key={i} className="flex items-center justify-between gap-3 py-1" style={{ borderBottom:'1px solid var(--border)' }}>
                      <span className="text-[11px]" style={{ color:'var(--text-muted)' }}>
                        {s.section}{s.limit ? <span className="opacity-60"> (cap {inr(s.limit)})</span> : ''}
                      </span>
                      <span className="text-[11px] font-semibold" style={{ color:'#10b981' }}>{inr(s.allowed)}</span>
                    </div>
                  ))}
                <Row label="Total deductions" value={data.chapter_via.total} bold color="#10b981"/>
              </div>
            </div>

            <div className="grid md:grid-cols-2 gap-5 mt-5">
              <div>
                <p className="text-[10px] font-bold uppercase mb-1.5" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Tax</p>
                <Row label="Taxable income" value={data.tax.taxable_income} bold/>
                <Row label="Tax before rebate" value={data.tax.tax_before_rebate}/>
                <Row label="Surcharge" value={data.tax.surcharge}/>
                <Row label="Cess" value={data.tax.cess}/>
                <Row label="Tax liability" value={data.tax.tax_liability} bold color="#f87171"/>
              </div>
              <div>
                <p className="text-[10px] font-bold uppercase mb-1.5" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>TDS</p>
                <Row label="Deducted (this employer)" value={data.tds.deducted_this_employer}/>
                <Row label="Deducted (previous)" value={data.tds.deducted_previous}/>
                <Row label="Total deducted" value={data.tds.total_deducted} bold/>
                <Row label={data.tds.balance_payable >= 0 ? 'Still payable' : 'Over-deducted'}
                  value={Math.abs(data.tds.balance_payable)} bold
                  color={data.tds.balance_payable > 0 ? '#f87171' : '#10b981'}/>
                <Row label="Professional tax" value={data.other.professional_tax}/>
                <Row label="PF (employee)" value={data.other.pf_employee}/>
              </div>
            </div>

            {data.monthly?.length > 0 && (
              <div className="mt-5">
                <p className="text-[10px] font-bold uppercase mb-1.5" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Month by month</p>
                <div className="overflow-x-auto rounded-xl" style={{ border:'1px solid var(--border)' }}>
                  <table className="w-full text-sm" style={{ minWidth:560 }}>
                    <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>
                      {['Period','Gross','Taxable','PF','PT','TDS'].map(h=><th key={h} className="text-left px-3 py-2 label-caps">{h}</th>)}
                    </tr></thead>
                    <tbody>
                      {data.monthly.map(m => (
                        <tr key={m.period} style={{ borderBottom:'1px solid var(--border)' }}>
                          <td className="px-3 py-2 font-semibold" style={{ color:'var(--text-h)' }}>{m.period}</td>
                          <td className="px-3 py-2 text-[11px]" style={{ color:'var(--text-muted)' }}>{inr(m.gross)}</td>
                          <td className="px-3 py-2 text-[11px]" style={{ color:'var(--text-muted)' }}>{inr(m.taxable)}</td>
                          <td className="px-3 py-2 text-[11px]" style={{ color:'var(--text-muted)' }}>{inr(m.pf_employee)}</td>
                          <td className="px-3 py-2 text-[11px]" style={{ color:'var(--text-muted)' }}>{inr(m.professional_tax)}</td>
                          <td className="px-3 py-2 text-[11px] font-bold" style={{ color:'#f87171' }}>{inr(m.tds)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  )
}
