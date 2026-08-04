import { useState, useEffect, useCallback } from 'react'
import { Plus, Pencil, Trash2, X, Scale, MapPin, AlertTriangle, Info, ShieldCheck } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'

const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'

/* ────────────────────────────────────────────────────────────────────────
   The rule book, described declaratively.

   Every field below is a KNOB, never a value. No rate, ceiling or slab is
   pre-filled anywhere in this file — the organisation enters what it is legally
   required to apply, and an unconfigured rule type simply deducts nothing.
   ──────────────────────────────────────────────────────────────────────── */
const RULE_TYPES = [
  { key:'pf',       label:'Provident Fund',   blurb:'Employee + employer share on PF-applicable wages.' },
  { key:'esic',     label:'ESIC',             blurb:'Applies only at or below the gross threshold.' },
  { key:'pt',       label:'Professional Tax', blurb:'Levied per state — one rule per state you employ in.', perState:true },
  { key:'bonus',    label:'Bonus',            blurb:'Statutory bonus provision.' },
  { key:'gratuity', label:'Gratuity',         blurb:'Monthly provision, and the formula the exit Full & Final settlement pays on.' },
  { key:'tds',      label:'TDS / Income Tax', blurb:'Per regime. Slabs applied marginally on year-to-date income, not a 12× projection.' },
  // #30 — insurance premiums rather than contributions, but configured, resolved
  // and effective-dated exactly like the rest.
  { key:'wcp',       label:"Workmen's Comp.", blurb:'Insurance premium. Usually borne entirely by the employer — leave the employee share at zero for that.' },
  { key:'mediclaim', label:'Mediclaim',       blurb:'Medical insurance premium. Split between employee and employer however your policy is written.' },
]

// field: [key, label, kind, hint]
const FIELDS = {
  pf: [
    ['employee_rate', 'Employee share %', 'num'],
    ['employer_rate', 'Employer share %', 'num'],
    ['eps_rate', 'EPS %', 'num', 'Carved OUT of the employer share, not added to it.'],
    ['wage_ceiling', 'Wage ceiling', 'num'],
    ['restrict_to_ceiling', 'Restrict contribution to the ceiling', 'bool'],
  ],
  esic: [
    ['gross_threshold', 'Gross threshold', 'num', 'At or below this gross, ESIC applies. Above it, it does not.'],
    ['employee_rate', 'Employee share %', 'num'],
    ['employer_rate', 'Employer share %', 'num'],
  ],
  pt: [
    ['slabs', 'Slabs', 'slabs:amount'],
    ['month_overrides', 'Month overrides', 'months', 'For states where one month differs from the rest.'],
  ],
  bonus: [
    ['rate', 'Rate %', 'num'],
    ['eligibility_gross_ceiling', 'Eligibility gross ceiling', 'num', 'Above this gross, no bonus is payable.'],
    ['calculation_ceiling', 'Calculation ceiling', 'num', 'Wage base is capped at this figure.'],
  ],
  gratuity: [
    ['rate', 'Monthly provision %', 'num', 'Used for the monthly provision only.'],
    ['days_per_year', 'Days per year', 'num', 'Settlement formula.'],
    ['month_days', 'Days in a month', 'num', 'Settlement formula divisor.'],
    ['min_years', 'Minimum years of service', 'num'],
    ['max_amount', 'Maximum payable', 'num'],
  ],
  // TDS is configured PER REGIME. The same rule row carries both, because one
  // Finance Act defines both at once and they share an effective date.
  tds: [
    ['regimes', 'Regimes', 'regimes'],
  ],
  // #30 — one field set for both premiums; they differ in their rates, not in
  // their arithmetic. `mode` decides which half of the list actually applies.
  wcp: [
    ['mode', 'Premium mode', 'select:percentage,fixed', 'Percentage of gross, or a flat amount per employee per month.'],
    ['employee_rate', 'Employee share % (percentage mode)', 'num'],
    ['employer_rate', 'Employer share % (percentage mode)', 'num'],
    ['amount', 'Premium amount (fixed mode)', 'num', 'With no split below, the employer bears this in full.'],
    ['employee_amount', 'Employee share amount (fixed mode)', 'num'],
    ['employer_amount', 'Employer share amount (fixed mode)', 'num'],
    ['min_gross', 'Applies at or above gross', 'num', 'Optional lower band.'],
    ['gross_threshold', 'Applies at or below gross', 'num', 'Optional upper band.'],
  ],
  mediclaim: [
    ['mode', 'Premium mode', 'select:percentage,fixed', 'Percentage of gross, or a flat amount per employee per month.'],
    ['employee_rate', 'Employee share % (percentage mode)', 'num'],
    ['employer_rate', 'Employer share % (percentage mode)', 'num'],
    ['amount', 'Premium amount (fixed mode)', 'num', 'With no split below, the employer bears this in full.'],
    ['employee_amount', 'Employee share amount (fixed mode)', 'num'],
    ['employer_amount', 'Employer share amount (fixed mode)', 'num'],
    ['min_gross', 'Applies at or above gross', 'num', 'Optional lower band.'],
    ['gross_threshold', 'Applies at or below gross', 'num', 'Optional upper band.'],
  ],
}

/** The knobs inside one regime. Same shape for old and new — only values differ. */
const REGIME_FIELDS = [
  ['slabs', 'Income slabs', 'slabs:rate'],
  ['standard_deduction', 'Standard deduction', 'num'],
  ['rebate_87a.taxable_income_limit', 'Rebate — income limit', 'num'],
  ['rebate_87a.max_rebate', 'Rebate — maximum', 'num'],
  ['cess_rate', 'Cess %', 'num'],
  ['allowed_sections', 'Deduction sections allowed', 'sections',
   'Sections NOT listed here contribute nothing under this regime. This is how a narrower regime is expressed — nothing is assumed.'],
  ['section_limits', 'Per-section caps', 'limits'],
  ['hra.salary_percent_metro', 'HRA — % of salary (metro)', 'num'],
  ['hra.salary_percent_non_metro', 'HRA — % of salary (non-metro)', 'num'],
  ['hra.rent_excess_percent', 'HRA — rent excess % of salary', 'num',
   'Leave the three HRA fields blank to disallow the HRA exemption under this regime.'],
]

const SECTION_CODES = ['80C','80CCC','80CCD1','80CCD1B','80CCD2','80D','80DD','80DDB','80E','80EEA','80G','80GG','80TTA','80TTB','80U','24B']

const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December']

const getIn  = (obj, path) => path.split('.').reduce((o, k) => (o == null ? undefined : o[k]), obj)
const setIn  = (obj, path, value) => {
  const keys = path.split('.')
  const next = { ...obj }
  let cursor = next
  keys.slice(0, -1).forEach(k => { cursor[k] = { ...(cursor[k] || {}) }; cursor = cursor[k] })
  cursor[keys.at(-1)] = value
  return next
}
// '' means "not configured" and must stay out of the payload — a stored 0 is a
// deliberate zero, which is a different statement entirely.
const num = v => (v === '' || v === null || v === undefined ? undefined : Number(v))

const EMPTY_RULE = { rule_type:'pf', state:'', effective_from:'', effective_to:'', config:{}, is_active:true, notes:'' }

export default function StatutorySettings({ showToast }) {
  const [meta, setMeta]       = useState({ rule_types:[], work_states:[], defaults:{} })
  const [rules, setRules]     = useState([])
  const [loading, setLoading] = useState(true)
  const [type, setType]       = useState('pf')
  const [modal, setModal]     = useState(null)
  const [saving, setSaving]   = useState(false)
  const [defaultState, setDefaultState] = useState('')
  const [loanLimits, setLoanLimits] = useState({ warn: 40, max: 50, enforce: true })
  const [savingDefault, setSavingDefault] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [m, list] = await Promise.all([hrApi.payroll.statutory.meta(), hrApi.payroll.statutory.list()])
      setMeta(m); setRules(list); setDefaultState(m?.defaults?.default_work_state || '')
      setLoanLimits({
        warn: m?.defaults?.loan_emi_warn_percent ?? 40,
        max: m?.defaults?.loan_emi_max_percent ?? 50,
        enforce: m?.defaults?.loan_enforce_eligibility ?? true,
      })
    } catch (e) { showToast?.(e?.message || 'Could not load statutory rules', 'error') }
    finally { setLoading(false) }
  }, [showToast])

  useEffect(() => { load() }, [load])

  const visible = rules.filter(r => r.rule_type === type)
  const spec    = RULE_TYPES.find(t => t.key === type)

  const saveDefault = async () => {
    setSavingDefault(true)
    try {
      const d = await hrApi.payroll.statutory.saveDefaults({
        default_work_state: defaultState || null,
        loan_emi_warn_percent: Number(loanLimits.warn),
        loan_emi_max_percent: Number(loanLimits.max),
        loan_enforce_eligibility: !!loanLimits.enforce,
      })
      setDefaultState(d?.default_work_state || '')
      showToast?.('Payroll defaults saved')
    } catch (e) { showToast?.(e?.response?.data?.message || e?.message || 'Could not save', 'error') }
    finally { setSavingDefault(false) }
  }

  const save = async () => {
    setSaving(true)
    try {
      const body = { ...modal.form, state: modal.form.state || null, effective_to: modal.form.effective_to || null }
      if (modal.id) await hrApi.payroll.statutory.update(modal.id, body)
      else await hrApi.payroll.statutory.create(body)
      showToast?.(modal.id ? 'Rule updated' : 'Rule added')
      setModal(null); load()
    } catch (e) { showToast?.(e?.response?.data?.message || e?.message || 'Could not save the rule', 'error') }
    finally { setSaving(false) }
  }

  const remove = async (r) => {
    if (!window.confirm(`Delete this ${r.rule_type.toUpperCase()} rule${r.state ? ` for ${r.state}` : ''}? Future payroll runs will stop applying it.`)) return
    try { await hrApi.payroll.statutory.remove(r.id); showToast?.('Rule deleted'); load() }
    catch (e) { showToast?.(e?.message || 'Could not delete', 'error') }
  }

  if (loading) return <HrLoading />

  return (
    <div className="space-y-5">
      {/* Nothing is pre-filled — say so plainly rather than let a blank screen imply
          the figures are "missing". */}
      <div className="card-3d flex items-start gap-3" style={{ padding:'14px 16px' }}>
        <ShieldCheck size={18} style={{ color:'#a78bfa', flexShrink:0, marginTop:2 }}/>
        <div>
          <p className="text-xs font-black" style={{ color:'var(--text-h)' }}>No statutory figure ships with this system</p>
          <p className="text-[11px] mt-0.5" style={{ color:'var(--text-muted)' }}>
            Every rate, ceiling and slab below is entered by your organisation and takes effect from the date you set.
            A rule type left unconfigured simply deducts nothing — payroll still runs.
          </p>
        </div>
      </div>

      {/* Company-wide default work state */}
      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex items-center gap-2 mb-1"><MapPin size={15} style={{ color:'#a78bfa' }}/>
          <p className="text-xs font-black" style={{ color:'var(--text-h)' }}>Company work state</p></div>
        <p className="text-[11px] mb-3" style={{ color:'var(--text-muted)' }}>
          Fallback for employees whose own work state is blank. Professional Tax is resolved from the employee's
          work state first, then this. Neither set means no PT — never a guess.
        </p>
        <div className="flex flex-wrap items-center gap-2">
          <select className="input-3d text-sm" style={{ maxWidth:280 }} value={defaultState} onChange={e=>setDefaultState(e.target.value)}>
            <option value="">Not set</option>
            {meta.work_states.map(s => <option key={s.code} value={s.name}>{s.name}</option>)}
          </select>
        </div>

        {/* Loan affordability — company policy, not law, so it carries real
            defaults rather than the "unconfigured = do nothing" statutory rule. */}
        <div className="mt-4 pt-4" style={{ borderTop:'1px solid var(--border)' }}>
          <div className="flex items-center gap-2 mb-1"><Scale size={15} style={{ color:'#a78bfa' }}/>
            <p className="text-xs font-black" style={{ color:'var(--text-h)' }}>Loan affordability</p></div>
          <p className="text-[11px] mb-3" style={{ color:'var(--text-muted)' }}>
            Judged on an employee&apos;s TOTAL EMI — this loan plus anything already being repaid — against their
            monthly net salary. An employee with no salary on record is never blocked.
          </p>
          <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
            <div>
              <label className="label">Comfort threshold %</label>
              <input type="number" step="any" min="0" max="100" className="input-3d text-sm"
                value={loanLimits.warn} onChange={e=>setLoanLimits(l => ({ ...l, warn: e.target.value }))}/>
              <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>Above this, a warning is shown.</p>
            </div>
            <div>
              <label className="label">Hard limit %</label>
              <input type="number" step="any" min="0" max="100" className="input-3d text-sm"
                value={loanLimits.max} onChange={e=>setLoanLimits(l => ({ ...l, max: e.target.value }))}/>
              <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>Above this, the loan is refused.</p>
            </div>
            <div className="flex items-end pb-6">
              <label className="flex items-center gap-2 text-xs font-semibold cursor-pointer" style={{ color:'var(--text-muted)' }}>
                <input type="checkbox" checked={!!loanLimits.enforce} onChange={e=>setLoanLimits(l => ({ ...l, enforce: e.target.checked }))}/>
                Enforce the hard limit
              </label>
            </div>
          </div>
          {Number(loanLimits.max) < Number(loanLimits.warn) && (
            <p className="text-[10px] mt-1 flex items-start gap-1" style={{ color:'#fbbf24' }}>
              <AlertTriangle size={11} style={{ flexShrink:0, marginTop:1 }}/>
              A hard limit below the comfort threshold would make every warning a block, so it is raised to match.
            </p>
          )}
        </div>

        <div className="mt-4">
          <button onClick={saveDefault} disabled={savingDefault}
            className="px-4 py-2 rounded-xl text-xs font-bold text-white" style={{ background:GRAD, opacity:savingDefault?0.7:1 }}>
            {savingDefault ? 'Saving…' : 'Save payroll defaults'}
          </button>
        </div>
      </div>

      {/* Rule type selector */}
      <div className="flex gap-1.5 flex-wrap">
        {RULE_TYPES.map(t => {
          const active = type === t.key
          const count  = rules.filter(r => r.rule_type === t.key).length
          return (
            <button key={t.key} onClick={()=>setType(t.key)}
              className="flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold transition-all"
              style={{ background: active ? GRAD : 'var(--bg-input)', color: active ? '#fff' : 'var(--text-muted)', border: active ? 'none' : '1px solid var(--border)' }}>
              {t.label}
              <span className="px-1.5 py-0.5 rounded-md text-[10px]" style={{ background: active ? 'rgba(255,255,255,0.2)' : 'var(--bg-card)' }}>{count}</span>
            </button>
          )
        })}
      </div>

      <div className="flex items-start justify-between gap-3 flex-wrap">
        <p className="text-[11px] max-w-xl" style={{ color:'var(--text-muted)' }}>{spec.blurb}</p>
        <button onClick={()=>setModal({ id:null, form:{ ...EMPTY_RULE, rule_type:type, config:{} } })}
          className="flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold text-white" style={{ background:GRAD }}>
          <Plus size={14}/> Add {spec.label} rule
        </button>
      </div>

      {visible.length === 0 ? (
        <HrEmpty icon={Scale} title={`No ${spec.label} rule configured`}
          subtitle={spec.perState
            ? 'Add one rule per state you employ in. Employees in a state with no rule are not charged.'
            : 'Until a rule exists, this deduction stays at zero on every payslip.'} />
      ) : (
        <div className="space-y-2">
          {visible.map(r => (
            <div key={r.id} className="card-3d flex items-center gap-3 flex-wrap" style={{ padding:'12px 14px' }}>
              <div className="flex-1 min-w-[200px]">
                <div className="flex items-center gap-2 flex-wrap">
                  {r.state && <span className="px-2 py-0.5 rounded-lg text-[10px] font-black" style={{ background:'rgba(124,58,237,0.12)', color:'#a78bfa' }}>{r.state}</span>}
                  <span className="text-xs font-bold" style={{ color:'var(--text-h)' }}>
                    {r.effective_from}{r.effective_to ? ` → ${r.effective_to}` : ' → open'}
                  </span>
                  {!r.is_active && <span className="px-2 py-0.5 rounded-lg text-[10px] font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>Inactive</span>}
                </div>
                <p className="text-[11px] mt-1 font-mono" style={{ color:'var(--text-muted)' }}>{summarise(r)}</p>
                {r.notes && <p className="text-[11px] mt-0.5" style={{ color:'var(--text-muted)' }}>{r.notes}</p>}
              </div>
              <button onClick={()=>setModal({ id:r.id, form:{ ...r, state:r.state||'', effective_to:r.effective_to||'', notes:r.notes||'' } })}
                className="p-2 rounded-lg" style={{ background:'var(--bg-input)' }}><Pencil size={13} style={{ color:'var(--text-muted)' }}/></button>
              <button onClick={()=>remove(r)} className="p-2 rounded-lg" style={{ background:'rgba(239,68,68,0.1)' }}><Trash2 size={13} style={{ color:'#f87171' }}/></button>
            </div>
          ))}
        </div>
      )}

      {modal && (
        <RuleModal modal={modal} setModal={setModal} meta={meta} saving={saving} save={save} />
      )}
    </div>
  )
}

/** One-line human summary of a rule's config, so the list is readable without opening it. */
function summarise(r) {
  const c = r.config || {}
  switch (r.rule_type) {
    case 'pf':   return `Employee ${c.employee_rate ?? '—'}% · Employer ${c.employer_rate ?? '—'}%${c.wage_ceiling ? ` · ceiling ${c.wage_ceiling}` : ''}${c.restrict_to_ceiling ? ' (restricted)' : ''}`
    case 'esic': return `Threshold ${c.gross_threshold ?? '—'} · Employee ${c.employee_rate ?? '—'}% · Employer ${c.employer_rate ?? '—'}%`
    case 'pt':   return `${(c.slabs || []).length} slab(s)${c.month_overrides && Object.keys(c.month_overrides).length ? ` · ${Object.keys(c.month_overrides).length} month override(s)` : ''}`
    case 'tds': {
      if (c.regimes) {
        return ['new', 'old'].map(r => {
          const rc = c.regimes[r] || {}
          const n = (rc.slabs || []).length
          return `${r}: ${n ? `${n} slab(s)` : 'not configured'}${(rc.allowed_sections || []).length ? `, ${rc.allowed_sections.length} section(s)` : ''}`
        }).join(' · ')
      }
      return `${(c.slabs || []).length} slab(s)${c.cess_rate ? ` · cess ${c.cess_rate}%` : ''} · applies to every regime (legacy)`
    }
    case 'bonus':    return `Rate ${c.rate ?? '—'}% · eligibility ≤ ${c.eligibility_gross_ceiling ?? '—'}`
    case 'gratuity': return `Provision ${c.rate ?? '—'}% · ${c.days_per_year ?? '—'}/${c.month_days ?? '—'} days · min ${c.min_years ?? '—'} yr`
    default: return ''
  }
}

/* ────────────────────────────────────────────────────────────────────────
   Add / edit modal — renders from FIELDS, so a new knob is one line there.
   ──────────────────────────────────────────────────────────────────────── */
function RuleModal({ modal, setModal, meta, saving, save }) {
  const { form } = modal
  const spec   = RULE_TYPES.find(t => t.key === form.rule_type)
  const fields = FIELDS[form.rule_type] || []
  const setForm   = (patch) => setModal(m => ({ ...m, form: { ...m.form, ...patch } }))
  const setConfig = (path, value) => setModal(m => ({ ...m, form: { ...m.form, config: setIn(m.form.config || {}, path, value) } }))

  return (
    <div className="fixed inset-0 z-[9998] flex items-center justify-center p-4" style={{ background:'rgba(0,0,0,0.5)' }}>
      <div className="card-3d w-full max-w-2xl max-h-[90vh] overflow-y-auto" style={{ padding:'22px' }}>
        <div className="flex items-center justify-between mb-4">
          <div>
            <p className="text-sm font-black" style={{ color:'var(--text-h)' }}>{modal.id ? 'Edit' : 'Add'} {spec?.label} rule</p>
            <p className="text-[11px] mt-0.5" style={{ color:'var(--text-muted)' }}>{spec?.blurb}</p>
          </div>
          <button onClick={()=>setModal(null)} className="p-1.5 rounded-lg" style={{ background:'var(--bg-input)' }}><X size={15} style={{ color:'var(--text-muted)' }}/></button>
        </div>

        <div className="space-y-3">
          {!modal.id && (
            <div><label className="label">Rule Type</label>
              <select className="input-3d text-sm" value={form.rule_type}
                onChange={e=>setForm({ rule_type:e.target.value, config:{}, state:'' })}>
                {RULE_TYPES.filter(t => meta.rule_types.includes(t.key)).map(t => <option key={t.key} value={t.key}>{t.label}</option>)}
              </select>
            </div>
          )}

          <div>
            <label className="label">State {spec?.perState && <span style={{ color:'#f87171' }}>*</span>}</label>
            <select className="input-3d text-sm" value={form.state} onChange={e=>setForm({ state:e.target.value })}>
              <option value="">{spec?.perState ? 'Choose a state…' : 'All states (company-wide)'}</option>
              {meta.work_states.map(s => <option key={s.code} value={s.name}>{s.name}</option>)}
            </select>
            {spec?.perState && (
              <p className="text-[10px] mt-1 flex items-start gap-1" style={{ color:'#fbbf24' }}>
                <AlertTriangle size={11} style={{ flexShrink:0, marginTop:1 }}/>
                Professional Tax is matched to the employee's work state exactly. A rule with no state can never apply.
              </p>
            )}
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Effective From *</label>
              <input type="date" className="input-3d text-sm" value={form.effective_from||''} onChange={e=>setForm({ effective_from:e.target.value })}/></div>
            <div><label className="label">Effective To</label>
              <input type="date" className="input-3d text-sm" value={form.effective_to||''} onChange={e=>setForm({ effective_to:e.target.value })}/>
              <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>Leave blank while in force.</p></div>
          </div>

          <div className="pt-1" style={{ borderTop:'1px solid var(--border)' }}/>

          {fields.map(([key, label, kind, hint]) => {
            if (kind === 'bool') return (
              <label key={key} className="flex items-center gap-2 text-xs font-semibold cursor-pointer" style={{ color:'var(--text-muted)' }}>
                <input type="checkbox" checked={!!getIn(form.config, key)} onChange={e=>setConfig(key, e.target.checked)}/> {label}
              </label>
            )
            if (kind === 'regimes') return <RegimeEditor key={key} config={form.config} setConfig={setConfig} />
            if (kind === 'months') return <MonthOverrides key={key} label={label} hint={hint} value={getIn(form.config, key) || {}} onChange={v=>setConfig(key, v)} />
            if (kind?.startsWith('slabs')) return (
              <SlabEditor key={key} label={label} valueKey={kind.split(':')[1]} slabs={getIn(form.config, key) || []} onChange={v=>setConfig(key, v)} />
            )
            // #30 — a string-valued choice. Without this the mode would render as
            // a number input and store a number the calculator cannot read.
            if (kind?.startsWith('select:')) return (
              <div key={key}>
                <label className="label">{label}</label>
                <select className="input-3d text-sm" value={getIn(form.config, key) ?? kind.split(':')[1].split(',')[0]}
                  onChange={e=>setConfig(key, e.target.value)}>
                  {kind.split(':')[1].split(',').map(o => <option key={o} value={o}>{o}</option>)}
                </select>
                {hint && <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>{hint}</p>}
              </div>
            )
            return (
              <div key={key}>
                <label className="label">{label}</label>
                <input type="number" step="any" className="input-3d text-sm" placeholder="Not configured"
                  value={getIn(form.config, key) ?? ''} onChange={e=>setConfig(key, num(e.target.value))}/>
                {hint && <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>{hint}</p>}
              </div>
            )
          })}

          <div><label className="label">Notes</label>
            <textarea rows={2} className="input-3d text-sm resize-none" placeholder="e.g. the circular or notification this rule follows"
              value={form.notes||''} onChange={e=>setForm({ notes:e.target.value })}/>
            <p className="text-[10px] mt-1 flex items-start gap-1" style={{ color:'var(--text-muted)' }}>
              <Info size={11} style={{ flexShrink:0, marginTop:1 }}/> Recommended — records where these figures came from.
            </p>
          </div>

          <label className="flex items-center gap-2 text-xs font-semibold cursor-pointer" style={{ color:'var(--text-muted)' }}>
            <input type="checkbox" checked={!!form.is_active} onChange={e=>setForm({ is_active:e.target.checked })}/> Active
          </label>
        </div>

        <div className="flex gap-3 pt-5">
          <button onClick={()=>setModal(null)} className="flex-1 py-2.5 rounded-xl text-sm font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Cancel</button>
          <button onClick={save} disabled={saving} className="flex-1 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background:GRAD, opacity:saving?0.7:1 }}>{saving?'Saving…':modal.id?'Save Changes':'Add Rule'}</button>
        </div>
      </div>
    </div>
  )
}

/**
 * Old vs New regime, side by side under one effective date.
 *
 * Both are edited on the same rule because one Finance Act defines both. A rule
 * saved without a `regimes` key keeps applying to everyone — that is how the
 * TDS rules written before regimes existed continue to work untouched.
 */
function RegimeEditor({ config, setConfig }) {
  const [active, setActive] = useState('new')
  const regimes = config.regimes || {}
  const has = (r) => Object.keys(regimes[r] || {}).length > 0

  const setRegime = (path, value) => setConfig(`regimes.${active}.${path}`, value)
  const get = (path) => getIn(regimes[active] || {}, path)

  return (
    <div>
      <div className="flex items-center gap-1.5 mb-2 flex-wrap">
        {['new', 'old'].map(r => (
          <button key={r} onClick={()=>setActive(r)}
            className="px-3 py-1.5 rounded-lg text-[11px] font-bold capitalize"
            style={{ background: active === r ? GRAD : 'var(--bg-input)', color: active === r ? '#fff' : 'var(--text-muted)' }}>
            {r} regime {has(r) && <span style={{ opacity:0.7 }}>✓</span>}
          </button>
        ))}
        <p className="text-[10px] ml-auto" style={{ color:'var(--text-muted)' }}>
          Configure each separately — an empty regime deducts nothing.
        </p>
      </div>

      <div className="space-y-3 pl-2" style={{ borderLeft:'2px solid rgba(124,58,237,0.3)' }}>
        {REGIME_FIELDS.map(([key, label, kind, hint]) => {
          if (kind === 'sections') return (
            <div key={key}>
              <label className="label">{label}</label>
              <div className="flex flex-wrap gap-1">
                {SECTION_CODES.map(code => {
                  const list = get(key) || []
                  const on = list.includes(code)
                  return (
                    <button key={code} onClick={()=>setRegime(key, on ? list.filter(c=>c!==code) : [...list, code])}
                      className="px-2 py-1 rounded-lg text-[10px] font-bold"
                      style={{ background: on ? 'rgba(124,58,237,0.18)' : 'var(--bg-input)',
                               color: on ? '#a78bfa' : 'var(--text-muted)',
                               border: on ? '1px solid rgba(124,58,237,0.4)' : '1px solid var(--border)' }}>
                      {code}
                    </button>
                  )
                })}
              </div>
              {hint && <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>{hint}</p>}
            </div>
          )
          if (kind === 'limits') {
            const allowed = get('allowed_sections') || []
            if (allowed.length === 0) return null
            return (
              <div key={key}>
                <label className="label">{label}</label>
                <div className="grid grid-cols-2 md:grid-cols-3 gap-2">
                  {allowed.map(code => (
                    <div key={code} className="flex items-center gap-1.5">
                      <span className="text-[10px] font-bold" style={{ color:'var(--text-muted)', width:60 }}>{code}</span>
                      <input type="number" step="any" className="input-3d text-xs flex-1" placeholder="No cap"
                        value={getIn(regimes[active] || {}, `section_limits.${code}`) ?? ''}
                        onChange={e=>setRegime(`section_limits.${code}`, num(e.target.value))}/>
                    </div>
                  ))}
                </div>
                <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>Blank means the full claimed amount is allowed.</p>
              </div>
            )
          }
          if (kind?.startsWith('slabs')) return (
            <SlabEditor key={key} label={label} valueKey={kind.split(':')[1]}
              slabs={get(key) || []} onChange={v=>setRegime(key, v)} />
          )
          return (
            <div key={key}>
              <label className="label">{label}</label>
              <input type="number" step="any" className="input-3d text-sm" placeholder="Not configured"
                value={get(key) ?? ''} onChange={e=>setRegime(key, num(e.target.value))}/>
              {hint && <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>{hint}</p>}
            </div>
          )
        })}
      </div>
    </div>
  )
}

/** Slab rows. `valueKey` is 'amount' for PT (a flat figure) or 'rate' for TDS (a %). */
function SlabEditor({ label, valueKey, slabs, onChange }) {
  const rows = slabs.length ? slabs : [{ from:0, to:null, [valueKey]:'' }]
  const set = (i, k, v) => onChange(rows.map((r, j) => j === i ? { ...r, [k]:v } : r))

  return (
    <div>
      <div className="flex items-center justify-between mb-1.5">
        <label className="label" style={{ marginBottom:0 }}>{label}</label>
        <button onClick={()=>onChange([...rows, { from:'', to:null, [valueKey]:'' }])}
          className="flex items-center gap-1 px-2 py-1 rounded-lg text-[10px] font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>
          <Plus size={11}/> Add slab
        </button>
      </div>
      <div className="space-y-1.5">
        {rows.map((r, i) => (
          <div key={i} className="flex items-center gap-1.5">
            <input type="number" step="any" className="input-3d text-xs flex-1" placeholder="From"
              value={r.from ?? ''} onChange={e=>set(i, 'from', num(e.target.value))}/>
            <input type="number" step="any" className="input-3d text-xs flex-1" placeholder="To (blank = no upper limit)"
              value={r.to ?? ''} onChange={e=>set(i, 'to', e.target.value === '' ? null : Number(e.target.value))}/>
            <input type="number" step="any" className="input-3d text-xs" style={{ width:96 }} placeholder={valueKey === 'rate' ? '%' : 'Amount'}
              value={r[valueKey] ?? ''} onChange={e=>set(i, valueKey, num(e.target.value))}/>
            <button onClick={()=>onChange(rows.filter((_, j) => j !== i))} className="p-1.5 rounded-lg" style={{ background:'rgba(239,68,68,0.1)' }}>
              <Trash2 size={12} style={{ color:'#f87171' }}/>
            </button>
          </div>
        ))}
      </div>
      <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>
        {valueKey === 'rate'
          ? 'Applied marginally — each band taxes only the income inside it.'
          : 'The first slab the monthly gross falls into wins. Leave the last "To" blank for an open top band.'}
      </p>
    </div>
  )
}

/** Per-month overrides, e.g. a state where one month's PT differs from the rest. */
function MonthOverrides({ label, hint, value, onChange }) {
  const entries = Object.entries(value || {})
  return (
    <div>
      <div className="flex items-center justify-between mb-1.5">
        <label className="label" style={{ marginBottom:0 }}>{label}</label>
        <button onClick={()=>onChange({ ...value, '': '' })}
          className="flex items-center gap-1 px-2 py-1 rounded-lg text-[10px] font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>
          <Plus size={11}/> Add month
        </button>
      </div>
      {entries.length === 0
        ? <p className="text-[11px]" style={{ color:'var(--text-muted)' }}>None — every month uses the slab amount.</p>
        : entries.map(([m, amt], i) => (
          <div key={i} className="flex items-center gap-1.5 mb-1.5">
            <select className="input-3d text-xs flex-1" value={m} onChange={e=>{
              const next = { ...value }; delete next[m]; next[e.target.value] = amt; onChange(next)
            }}>
              <option value="">Choose month…</option>
              {MONTHS.map((name, idx) => <option key={idx} value={String(idx + 1)}>{name}</option>)}
            </select>
            <input type="number" step="any" className="input-3d text-xs" style={{ width:110 }} placeholder="Amount"
              value={amt ?? ''} onChange={e=>onChange({ ...value, [m]: num(e.target.value) })}/>
            <button onClick={()=>{ const next = { ...value }; delete next[m]; onChange(next) }}
              className="p-1.5 rounded-lg" style={{ background:'rgba(239,68,68,0.1)' }}><Trash2 size={12} style={{ color:'#f87171' }}/></button>
          </div>
        ))}
      {hint && <p className="text-[10px] mt-1" style={{ color:'var(--text-muted)' }}>{hint}</p>}
    </div>
  )
}
