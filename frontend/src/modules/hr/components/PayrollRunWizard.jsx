import { useState, useEffect, useCallback, useMemo } from 'react'
import {
  CheckCircle2, Circle, Search, PlayCircle, AlertTriangle, Plus, Trash2,
  ShieldCheck, Undo2, Banknote, Eye, EyeOff, X, Lock,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'

/*  ────────────────────────────────────────────────────────────────────────
    The stepped payroll run.

    Payroll used to be one button: it swept every employee with an active
    salary, computed them, and finished. That is one person's decision with no
    record of who agreed to it — and it left no way to run payroll for 40 of 50
    people, so a single incomplete profile meant discarding the run.

    The five stages here are the ones the business actually works in, and each
    one is a different person's job:

      Pre-check   HR chooses who is in the run, and sees who cannot be paid
      Inputs      attendance, leave and variable earnings settle
      Calculate   the engine runs; HR may add or deduct WITH A REASON
      Approve     the reporting manager signs, and the run locks
      Disburse    accounts records each transfer, and payslips are released

    Every guard is enforced on the server. Nothing here is load-bearing for
    correctness — this screen makes the state visible and hard to misread, and
    the API refuses the same things whether or not the button was hidden.
    ──────────────────────────────────────────────────────────────────────── */

const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'
const money = v => v === null || v === undefined || v === '' ? '—' : `₹${Number(v).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`

const STAGES = [
  { key: 'Pre-check', label: 'Pre-checks',  hint: 'Who is in this run' },
  { key: 'Inputs',    label: 'Inputs',      hint: 'Attendance · variables' },
  { key: 'Calculate', label: 'Calculate',   hint: 'Compute · adjust' },
  { key: 'Approve',   label: 'Approve',     hint: 'Reporting manager' },
  { key: 'Disburse',  label: 'Disburse',    hint: 'Payments · payslips' },
]

const PAY_C = {
  Pending: { c: '#f59e0b', bg: 'rgba(245,158,11,0.14)' },
  Paid:    { c: '#10b981', bg: 'rgba(16,185,129,0.12)' },
  Hold:    { c: '#0ea5e9', bg: 'rgba(14,165,233,0.12)' },
  Failed:  { c: '#f87171', bg: 'rgba(239,68,68,0.1)' },
}

/** Paid is the last stage; treat it as Disburse for the rail. */
const railIndex = (stage) => {
  if (stage === 'Paid') return 4
  const i = STAGES.findIndex(s => s.key === stage)
  return i < 0 ? 0 : i
}

export default function PayrollRunWizard({ run, records = [], onClose, onChanged, showToast }) {
  const [stage, setStage] = useState(run.stage || 'Pre-check')
  const [busy, setBusy] = useState(false)

  // The rail lets you look BACK at a finished stage without moving the run.
  // `viewing` is where the user is; `stage` is where the run actually is.
  const [viewing, setViewing] = useState(run.stage || 'Pre-check')

  useEffect(() => { setStage(run.stage || 'Pre-check'); setViewing(run.stage || 'Pre-check') }, [run.stage])

  const at = railIndex(stage)
  const looking = railIndex(viewing)

  const act = async (fn, okMsg) => {
    setBusy(true)
    try {
      const res = await fn()
      if (okMsg) showToast(okMsg)
      onChanged?.()
      return res
    } catch (e) {
      showToast(e.response?.data?.message || 'That did not work', 'error')
      return null
    } finally { setBusy(false) }
  }

  return (
    <div className="space-y-4">
      {/* ── Stage rail ──────────────────────────────────────────────── */}
      <div className="card-3d" style={{ padding: '14px 16px' }}>
        <div className="flex items-center justify-between gap-3 mb-3">
          <div>
            <p className="text-sm font-black" style={{ color: 'var(--text-h)' }}>{run.period_label}</p>
            <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
              Step {at + 1} of 5 · {STAGES[at]?.label}
              {run.is_approved && <> · <span style={{ color: '#10b981' }}>approved by {run.approved_by || '—'}</span></>}
            </p>
          </div>
          {onClose && <button onClick={onClose} className="p-1.5 rounded-lg" style={{ background: 'var(--bg-input)' }}><X size={14} /></button>}
        </div>

        <div className="flex items-center gap-1 overflow-x-auto pb-1">
          {STAGES.map((s, i) => {
            const done = i < at
            const here = i === at
            const active = i === looking
            return (
              <button
                key={s.key}
                onClick={() => setViewing(s.key)}
                // Stages ahead of the run are unreachable — clicking one would
                // suggest the run can be pushed forward from the UI, and it
                // cannot: the server refuses every skipped stage.
                disabled={i > at}
                className="flex items-center gap-2 px-3 py-2 rounded-xl whitespace-nowrap"
                style={{
                  background: active ? 'rgba(124,58,237,0.12)' : 'transparent',
                  opacity: i > at ? 0.4 : 1,
                  cursor: i > at ? 'not-allowed' : 'pointer',
                  minWidth: 0,
                }}
              >
                {done
                  ? <CheckCircle2 size={15} style={{ color: '#10b981', flexShrink: 0 }} />
                  : <Circle size={15} style={{ color: here ? '#7C3AED' : 'var(--text-muted)', flexShrink: 0 }} />}
                <span className="text-left">
                  <span className="block text-[11px] font-bold" style={{ color: here || active ? 'var(--text-h)' : 'var(--text-muted)' }}>{s.label}</span>
                  <span className="block text-[9px]" style={{ color: 'var(--text-muted)' }}>{s.hint}</span>
                </span>
              </button>
            )
          })}
        </div>
      </div>

      {viewing === 'Pre-check' && <PreCheck run={run} at={at} busy={busy} act={act} showToast={showToast} onAdvance={() => setViewing('Inputs')} />}
      {viewing === 'Inputs'    && <Inputs run={run} at={at} busy={busy} act={act} onAdvance={() => setViewing('Calculate')} />}
      {viewing === 'Calculate' && <Calculate run={run} records={records} busy={busy} act={act} showToast={showToast} onAdvance={() => setViewing('Approve')} />}
      {viewing === 'Approve'   && <Approve run={run} at={at} busy={busy} act={act} showToast={showToast} onAdvance={() => setViewing('Disburse')} />}
      {viewing === 'Disburse'  && <Disburse run={run} records={records} busy={busy} act={act} />}
    </div>
  )
}

/* ── Stage 1 · Pre-check ─────────────────────────────────────────────────
   Who is in this run, and what is stopping the rest.

   Blocked people are SHOWN, greyed, with the reason. Omitting them is what
   the old behaviour did, and it made "not due a salary" and "we could not pay
   them" the same thing — which is how somebody misses a month and nobody
   notices until they ask.
   ──────────────────────────────────────────────────────────────────────── */
function PreCheck({ run, at, busy, act, showToast, onAdvance }) {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [picked, setPicked] = useState(() => new Set())
  const [q, setQ] = useState('')

  const load = useCallback(() => {
    setLoading(true)
    hrApi.payroll.runs.precheck(run.id)
      .then(d => {
        setData(d)
        setPicked(new Set((d.employees || []).filter(e => e.selected).map(e => e.employee_id)))
      })
      .catch(() => showToast('Failed to load the pre-check', 'error'))
      .finally(() => setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [run.id])
  useEffect(() => { load() }, [load])

  const rows = useMemo(() => {
    const all = data?.employees || []
    const needle = q.trim().toLowerCase()
    if (!needle) return all
    return all.filter(e =>
      (e.name || '').toLowerCase().includes(needle) ||
      (e.employee_code || '').toLowerCase().includes(needle) ||
      (e.department || '').toLowerCase().includes(needle))
  }, [data, q])

  const locked = at > 0
  const selectable = rows.filter(e => !e.blocked)
  const allPicked = selectable.length > 0 && selectable.every(e => picked.has(e.employee_id))

  const toggle = (id) => {
    if (locked) return
    setPicked(prev => {
      const next = new Set(prev)
      next.has(id) ? next.delete(id) : next.add(id)
      return next
    })
  }

  const toggleAll = () => {
    if (locked) return
    setPicked(prev => {
      const next = new Set(prev)
      selectable.forEach(e => allPicked ? next.delete(e.employee_id) : next.add(e.employee_id))
      return next
    })
  }

  const save = async () => {
    if (picked.size === 0) { showToast('Select at least one employee', 'error'); return }
    const ok = await act(() => hrApi.payroll.runs.selectEmployees(run.id, [...picked]), `${picked.size} employee(s) selected`)
    if (ok) onAdvance()
  }

  if (loading) return <HrLoading label="Checking who can be paid…" />
  if (!data) return null

  const s = data.summary || {}

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <Stat label="Employees" value={s.total} colour="#7C3AED" />
        <Stat label="Ready to pay" value={s.ready} colour="#10b981" />
        <Stat label="Blocked" value={s.blocked} colour="#f87171" />
        <Stat label="Warnings" value={s.warnings} colour="#f59e0b" />
      </div>

      {s.blocked > 0 && (
        <div className="rounded-xl p-3 flex items-start gap-2.5" style={{ background: 'rgba(239,68,68,0.06)', border: '1px dashed rgba(239,68,68,0.35)' }}>
          <AlertTriangle size={15} style={{ color: '#f87171', marginTop: 1, flexShrink: 0 }} />
          <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
            <b style={{ color: 'var(--text-h)' }}>{s.blocked} employee(s) cannot be paid yet.</b>{' '}
            They stay listed with the reason so it can be fixed — they are not silently dropped from the run.
          </p>
        </div>
      )}

      <div className="flex items-center gap-2 flex-wrap">
        <div className="relative flex-1" style={{ minWidth: 200 }}>
          <Search size={14} style={{ position: 'absolute', left: 10, top: 10, color: 'var(--text-muted)' }} />
          <input
            value={q} onChange={e => setQ(e.target.value)}
            placeholder="Search name, code or department…"
            className="w-full text-sm rounded-xl pl-8 pr-3 py-2"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}
          />
        </div>
        {!locked && (
          <button onClick={toggleAll} className="text-[11px] font-bold px-3 py-2 rounded-xl" style={{ background: 'rgba(124,58,237,0.1)', color: '#a78bfa' }}>
            {allPicked ? 'Clear all' : 'Select all'}
          </button>
        )}
        <span className="text-[11px] font-bold" style={{ color: 'var(--text-muted)' }}>{picked.size} selected</span>
      </div>

      <div className="card-3d overflow-x-auto" style={{ padding: 6 }}>
        <table className="w-full text-sm" style={{ minWidth: 700 }}>
          <thead>
            <tr style={{ borderBottom: '1px solid var(--border)' }}>
              {['', 'Employee', 'Department', 'Pay mode', 'Status'].map((h, i) =>
                <th key={i} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}
            </tr>
          </thead>
          <tbody>
            {rows.map(e => (
              <tr key={e.employee_id} style={{ borderBottom: '1px solid var(--border)', opacity: e.blocked ? 0.55 : 1 }}>
                <td className="px-3 py-2.5">
                  <input
                    type="checkbox"
                    checked={picked.has(e.employee_id)}
                    disabled={e.blocked || locked}
                    onChange={() => toggle(e.employee_id)}
                    style={{ accentColor: '#7C3AED', cursor: e.blocked || locked ? 'not-allowed' : 'pointer' }}
                  />
                </td>
                <td className="px-3 py-2.5">
                  <span className="block font-bold" style={{ color: 'var(--text-h)' }}>{e.name}</span>
                  <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{e.employee_code}</span>
                </td>
                <td className="px-3 py-2.5" style={{ color: 'var(--text-muted)' }}>{e.department || '—'}</td>
                <td className="px-3 py-2.5" style={{ color: 'var(--text-muted)' }}>{e.pay_mode}</td>
                <td className="px-3 py-2.5">
                  {e.blocked
                    ? <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background: 'rgba(239,68,68,0.1)', color: '#f87171' }}>{e.blocked_reason}</span>
                    : e.warnings?.length
                      ? <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background: 'rgba(245,158,11,0.14)', color: '#f59e0b' }} title={e.warnings.join(' · ')}>{e.warnings.length} warning(s)</span>
                      : <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background: 'rgba(16,185,129,0.12)', color: '#10b981' }}>Ready</span>}
                </td>
              </tr>
            ))}
            {rows.length === 0 && <tr><td colSpan={5} className="px-3 py-6 text-center text-[11px]" style={{ color: 'var(--text-muted)' }}>Nobody matches that search.</td></tr>}
          </tbody>
        </table>
      </div>

      {locked
        ? <Locked text="The employees for this run are already fixed." />
        : <div className="flex justify-end">
            {/* Disabled at zero rather than left to bounce off the server. The
                refusal is correct either way, but a button that looks live and
                then fails reads as a bug in the page. */}
            <button
              onClick={save}
              disabled={busy || picked.size === 0}
              className="px-4 py-2.5 rounded-xl text-sm font-bold text-white"
              style={{
                background: GRAD,
                opacity: busy || picked.size === 0 ? 0.45 : 1,
                cursor: picked.size === 0 ? 'not-allowed' : 'pointer',
              }}
            >
              {picked.size === 0 ? 'Select employees to continue' : `Continue with ${picked.size} employee(s) →`}
            </button>
          </div>}
    </div>
  )
}

/* ── Stage 2 · Inputs ────────────────────────────────────────────────────
   What feeds the calculation. Read-only here on purpose: attendance, leave,
   loans and commissions are each owned by their own screen, and duplicating
   the editing here is how two places start disagreeing about the same number.
   ──────────────────────────────────────────────────────────────────────── */
function Inputs({ run, at, busy, act, onAdvance }) {
  const locked = at > 1

  const confirm = async () => {
    const ok = await act(() => hrApi.payroll.runs.confirmInputs(run.id), 'Inputs confirmed')
    if (ok) onAdvance()
  }

  return (
    <div className="space-y-4">
      <div className="rounded-xl p-3 flex items-start gap-2.5" style={{ background: 'var(--bg-input)', border: '1px dashed var(--border)' }}>
        <AlertTriangle size={15} style={{ color: '#a78bfa', marginTop: 1, flexShrink: 0 }} />
        <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
          <b style={{ color: 'var(--text-h)' }}>Attendance:</b>{' '}
          {run.attendance?.connected
            ? `from ${run.attendance.source}.`
            : `${run.attendance?.source || 'SangoeTrack'} — not connected. Payroll uses full payable days until the integration lands.`}
        </p>
      </div>

      <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
        <Stat label="Commissions this period" value={money(run.variable_earnings?.total_paid)} colour="#10b981" small />
        <Stat label="Loan recovery" value={money(run.loan_recovery?.total_recovered)} colour="#f59e0b" small />
        <Stat label="Employees on loans" value={run.loan_recovery?.employees_count ?? 0} colour="#0ea5e9" small />
      </div>

      <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
        Attendance, leave, commissions and loan instalments are each maintained on their own screen and pulled in when the run is calculated.
        Confirming here records that they have been reviewed for this month.
      </p>

      {locked
        ? <Locked text="Inputs for this run are already confirmed." />
        : <div className="flex justify-end">
            <button onClick={confirm} disabled={busy} className="px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: GRAD, opacity: busy ? 0.7 : 1 }}>
              Inputs look right →
            </button>
          </div>}
    </div>
  )
}

/* ── Stage 3 · Calculate ─────────────────────────────────────────────────
   Run the engine, then let HR add or deduct WITH A REASON.

   The reason is mandatory at every layer — the column, the service, the API
   and this form. An unexplained ±₹1,000 against a name is indistinguishable
   from a calculation error by the time anybody queries it.
   ──────────────────────────────────────────────────────────────────────── */
// `at` is not taken here: this stage locks on the run being APPROVED, not on
// the rail having moved past it — a run sent back from approval returns to
// Calculate and must be adjustable again.
function Calculate({ run, records, busy, act, showToast, onAdvance }) {
  const [adjustments, setAdjustments] = useState([])
  const [target, setTarget] = useState(null)   // record being adjusted

  const calculated = run.status === 'Completed'
  const locked = run.is_approved

  const loadAdjustments = useCallback(() => {
    hrApi.payroll.runs.adjustments(run.id).then(setAdjustments).catch(() => {})
  }, [run.id])
  useEffect(() => { loadAdjustments() }, [loadAdjustments, records])

  const compute = () => act(() => hrApi.payroll.runs.process(run.id), 'Payroll calculated')

  const remove = async (id) => {
    await act(() => hrApi.payroll.runs.removeAdjustment(id), 'Adjustment removed')
    loadAdjustments()
  }

  if (!calculated) {
    return (
      <div className="card-3d text-center" style={{ padding: '32px 20px' }}>
        <PlayCircle size={30} style={{ color: '#a78bfa', margin: '0 auto 10px' }} />
        <p className="text-sm font-black" style={{ color: 'var(--text-h)' }}>Ready to calculate</p>
        <p className="text-[11px] mt-1 mb-4" style={{ color: 'var(--text-muted)' }}>
          Salary structures, the statutory rules and this period's inputs are combined into a frozen snapshot per employee.
        </p>
        <button onClick={compute} disabled={busy} className="px-5 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: GRAD, opacity: busy ? 0.7 : 1 }}>
          {busy ? 'Calculating…' : 'Calculate payroll'}
        </button>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <Stat label="Employees" value={run.total_employees} colour="#7C3AED" />
        <Stat label="Gross" value={money(run.total_gross)} colour="#10b981" small />
        <Stat label="Deductions" value={money(run.total_deductions)} colour="#f87171" small />
        <Stat label="Net payable" value={money(run.total_payable)} colour="#0ea5e9" small />
      </div>

      <div className="card-3d overflow-x-auto" style={{ padding: 6 }}>
        <table className="w-full text-sm" style={{ minWidth: 760 }}>
          <thead>
            <tr style={{ borderBottom: '1px solid var(--border)' }}>
              {['Employee', 'Gross', 'Statutory', 'Adjustments', 'Net payable', ''].map((h, i) =>
                <th key={i} className={`text-left px-3 py-3 label-caps whitespace-nowrap ${i === 5 ? 'text-right' : ''}`}>{h}</th>)}
            </tr>
          </thead>
          <tbody>
            {records.map(r => (
              <tr key={r.id} style={{ borderBottom: '1px solid var(--border)' }}>
                <td className="px-3 py-2.5">
                  <span className="block font-bold" style={{ color: 'var(--text-h)' }}>{r.employee_name}</span>
                  <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{r.employee_code}</span>
                </td>
                <td className="px-3 py-2.5" style={{ color: '#10b981' }}>{money(r.gross_salary)}</td>
                <td className="px-3 py-2.5" style={{ color: '#f87171' }}>{money(r.statutory?.total_deductions)}</td>
                <td className="px-3 py-2.5 font-semibold" style={{ color: r.adjustment_total > 0 ? '#10b981' : r.adjustment_total < 0 ? '#f87171' : 'var(--text-muted)' }}>
                  {r.adjustment_total ? money(r.adjustment_total) : '—'}
                </td>
                <td className="px-3 py-2.5 font-black" style={{ color: '#0ea5e9' }}>{money(r.net_payable)}</td>
                <td className="px-3 py-2.5 text-right">
                  {!locked && (
                    <button onClick={() => setTarget(r)} className="text-[11px] font-bold px-2.5 py-1.5 rounded-lg inline-flex items-center gap-1" style={{ background: 'rgba(124,58,237,0.1)', color: '#a78bfa' }}>
                      <Plus size={12} /> Adjust
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {adjustments.length > 0 && (
        <div className="card-3d" style={{ padding: 14 }}>
          <p className="text-[11px] label-caps mb-2">Adjustments on this run</p>
          <div className="space-y-2">
            {adjustments.map(a => (
              <div key={a.id} className="flex items-start justify-between gap-3 rounded-xl p-2.5" style={{ background: 'var(--bg-input)' }}>
                <div style={{ minWidth: 0 }}>
                  <p className="text-[12px] font-bold" style={{ color: 'var(--text-h)' }}>
                    {a.employee_name} · <span style={{ color: a.signed_amount < 0 ? '#f87171' : '#10b981' }}>{a.signed_amount < 0 ? '−' : '+'}{money(Math.abs(a.signed_amount))}</span>
                  </p>
                  <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{a.reason}</p>
                  <p className="text-[9px] mt-0.5" style={{ color: 'var(--text-muted)' }}>by {a.created_by || '—'}</p>
                </div>
                {!locked && (
                  <button onClick={() => remove(a.id)} className="p-1.5 rounded-lg shrink-0" style={{ background: 'rgba(239,68,68,0.1)', color: '#f87171' }}><Trash2 size={13} /></button>
                )}
              </div>
            ))}
          </div>
        </div>
      )}

      {locked
        ? <Locked text="This run is approved — the amounts can no longer be changed." />
        : <div className="flex justify-end gap-2">
            <button onClick={compute} disabled={busy} className="px-4 py-2.5 rounded-xl text-sm font-bold" style={{ background: 'rgba(124,58,237,0.1)', color: '#a78bfa' }}>
              Recalculate
            </button>
            <button onClick={onAdvance} className="px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: GRAD }}>
              Send for approval →
            </button>
          </div>}

      {target && (
        <AdjustDialog
          record={target}
          busy={busy}
          onClose={() => setTarget(null)}
          onSave={async (body) => {
            const ok = await act(() => hrApi.payroll.runs.addAdjustment(target.id, body), 'Adjustment added')
            if (ok) { setTarget(null); loadAdjustments() }
          }}
          showToast={showToast}
        />
      )}
    </div>
  )
}

function AdjustDialog({ record, busy, onClose, onSave, showToast }) {
  const [type, setType] = useState('Addition')
  const [amount, setAmount] = useState('')
  const [reason, setReason] = useState('')

  const submit = () => {
    if (!(Number(amount) > 0)) { showToast('Enter an amount greater than zero', 'error'); return }
    if (!reason.trim()) { showToast('A reason is required', 'error'); return }
    onSave({ type, amount: Number(amount), reason: reason.trim() })
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ background: 'rgba(0,0,0,0.45)' }}>
      <div className="card-3d w-full" style={{ maxWidth: 420, padding: 18 }}>
        <div className="flex items-center justify-between mb-3">
          <p className="text-sm font-black" style={{ color: 'var(--text-h)' }}>Adjust {record.employee_name}</p>
          <button onClick={onClose} className="p-1.5 rounded-lg" style={{ background: 'var(--bg-input)' }}><X size={14} /></button>
        </div>

        <div className="flex gap-2 mb-3">
          {['Addition', 'Deduction'].map(t => (
            <button key={t} onClick={() => setType(t)} className="flex-1 py-2 rounded-xl text-[11px] font-bold"
              style={{
                background: type === t ? (t === 'Addition' ? 'rgba(16,185,129,0.14)' : 'rgba(239,68,68,0.1)') : 'var(--bg-input)',
                color: type === t ? (t === 'Addition' ? '#10b981' : '#f87171') : 'var(--text-muted)',
              }}>{t}</button>
          ))}
        </div>

        <label className="block text-[11px] label-caps mb-1">Amount</label>
        <input type="number" min="0" step="0.01" value={amount} onChange={e => setAmount(e.target.value)}
          className="w-full text-sm rounded-xl px-3 py-2 mb-3"
          style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />

        <label className="block text-[11px] label-caps mb-1">Reason <span style={{ color: '#f87171' }}>*</span></label>
        <textarea rows={3} value={reason} onChange={e => setReason(e.target.value)}
          placeholder="e.g. Unsettled travel claim from June"
          className="w-full text-sm rounded-xl px-3 py-2"
          style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
        <p className="text-[10px] mt-1 mb-3" style={{ color: 'var(--text-muted)' }}>
          Required. Six months from now this is the only thing that distinguishes an adjustment from a mistake.
        </p>

        <div className="flex justify-end gap-2">
          <button onClick={onClose} className="px-3 py-2 rounded-xl text-[12px] font-bold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>Cancel</button>
          <button onClick={submit} disabled={busy} className="px-4 py-2 rounded-xl text-[12px] font-bold text-white" style={{ background: GRAD, opacity: busy ? 0.7 : 1 }}>Save adjustment</button>
        </div>
      </div>
    </div>
  )
}

/* ── Stage 4 · Approve ───────────────────────────────────────────────────
   The reporting manager signs, or sends it back with a reason.

   The rejection note is required and the approval note is not: a rejection
   with no reason gets re-submitted unchanged, and the loop repeats until
   somebody picks up the phone.
   ──────────────────────────────────────────────────────────────────────── */
function Approve({ run, at, busy, act, showToast, onAdvance }) {
  const [note, setNote] = useState('')
  const done = at > 3

  const approve = async () => {
    const ok = await act(() => hrApi.payroll.runs.approve(run.id, note || null), 'Payroll approved')
    if (ok) onAdvance()
  }
  const reject = async () => {
    if (!note.trim()) { showToast('Say why it is going back', 'error'); return }
    await act(() => hrApi.payroll.runs.reject(run.id, note.trim()), 'Sent back to HR')
  }

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <Stat label="Employees" value={run.total_employees} colour="#7C3AED" />
        <Stat label="Gross" value={money(run.total_gross)} colour="#10b981" small />
        <Stat label="Statutory" value={money(run.statutory?.total_deductions)} colour="#f87171" small />
        <Stat label="To be transferred" value={money(run.total_payable)} colour="#0ea5e9" small />
      </div>

      {run.approval_note && (
        <div className="rounded-xl p-3" style={{ background: 'var(--bg-input)', border: '1px dashed var(--border)' }}>
          <p className="text-[10px] label-caps mb-1">Note</p>
          <p className="text-[12px]" style={{ color: 'var(--text-h)' }}>{run.approval_note}</p>
        </div>
      )}

      {done
        ? <Locked text={`Approved by ${run.approved_by || '—'}.`} icon={ShieldCheck} tone="#10b981" />
        : (
          <div className="card-3d" style={{ padding: 16 }}>
            <p className="text-[11px] label-caps mb-1">Note</p>
            <textarea rows={3} value={note} onChange={e => setNote(e.target.value)}
              placeholder="Optional when approving · required when sending back"
              className="w-full text-sm rounded-xl px-3 py-2 mb-3"
              style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />

            <p className="text-[11px] mb-3" style={{ color: 'var(--text-muted)' }}>
              Approving locks the amounts. Nothing can be adjusted afterwards — that is the point of the signature.
            </p>

            <div className="flex justify-end gap-2">
              <button onClick={reject} disabled={busy} className="px-4 py-2.5 rounded-xl text-sm font-bold inline-flex items-center gap-1.5" style={{ background: 'rgba(239,68,68,0.1)', color: '#f87171' }}>
                <Undo2 size={14} /> Send back
              </button>
              <button onClick={approve} disabled={busy} className="px-4 py-2.5 rounded-xl text-sm font-bold text-white inline-flex items-center gap-1.5" style={{ background: GRAD, opacity: busy ? 0.7 : 1 }}>
                <ShieldCheck size={14} /> Approve payroll
              </button>
            </div>
          </div>
        )}
    </div>
  )
}

/* ── Stage 5 · Disburse ──────────────────────────────────────────────────
   Accounts records what actually happened to each transfer, and payslips are
   released.

   Payment is per person, not per run: transfers fail one at a time, and "the
   run completed" is a statement about arithmetic rather than about money
   arriving in somebody's account.
   ──────────────────────────────────────────────────────────────────────── */
function Disburse({ run, records, busy, act }) {
  const p = run.payments || {}
  const anyReleased = (p.released ?? 0) > 0

  const mark = (record, status) => act(() => hrApi.payroll.runs.markPayment(record.id, status, null), `${record.employee_name} · ${status}`)
  const markAll = (status) => act(() => hrApi.payroll.runs.markAllPayments(run.id, status), `All transfers marked ${status}`)
  const release = (visible) => act(() => hrApi.payroll.runs.releasePayslips(run.id, visible), visible ? 'Payslips released' : 'Payslips hidden')

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <Stat label="To transfer" value={money(run.total_payable)} colour="#0ea5e9" small />
        <Stat label="Paid" value={p.paid ?? 0} colour="#10b981" />
        <Stat label="Pending" value={p.pending ?? 0} colour="#f59e0b" />
        <Stat label="Payslips released" value={p.released ?? 0} colour="#7C3AED" />
      </div>

      <div className="flex gap-2 flex-wrap justify-end">
        <button onClick={() => markAll('Paid')} disabled={busy} className="text-[11px] font-bold px-3 py-2 rounded-xl inline-flex items-center gap-1.5" style={{ background: 'rgba(16,185,129,0.12)', color: '#10b981' }}>
          <Banknote size={13} /> Mark all paid
        </button>
        <button onClick={() => release(!anyReleased)} disabled={busy} className="text-[11px] font-bold px-3 py-2 rounded-xl inline-flex items-center gap-1.5" style={{ background: 'rgba(124,58,237,0.1)', color: '#a78bfa' }}>
          {anyReleased ? <><EyeOff size={13} /> Hide payslips</> : <><Eye size={13} /> Release payslips</>}
        </button>
      </div>

      <div className="card-3d overflow-x-auto" style={{ padding: 6 }}>
        <table className="w-full text-sm" style={{ minWidth: 720 }}>
          <thead>
            <tr style={{ borderBottom: '1px solid var(--border)' }}>
              {['Employee', 'Net payable', 'Payment', 'Payslip', 'Mark as'].map((h, i) =>
                <th key={i} className={`text-left px-3 py-3 label-caps whitespace-nowrap ${i === 4 ? 'text-right' : ''}`}>{h}</th>)}
            </tr>
          </thead>
          <tbody>
            {records.map(r => {
              const st = PAY_C[r.payment_status] || PAY_C.Pending
              return (
                <tr key={r.id} style={{ borderBottom: '1px solid var(--border)' }}>
                  <td className="px-3 py-2.5">
                    <span className="block font-bold" style={{ color: 'var(--text-h)' }}>{r.employee_name}</span>
                    <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{r.employee_code}</span>
                  </td>
                  <td className="px-3 py-2.5 font-black" style={{ color: '#0ea5e9' }}>{money(r.net_payable)}</td>
                  <td className="px-3 py-2.5">
                    <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background: st.bg, color: st.c }}>{r.payment_status}</span>
                  </td>
                  <td className="px-3 py-2.5">
                    {r.payslip_visible
                      ? <span className="text-[10px] font-bold inline-flex items-center gap-1" style={{ color: '#10b981' }}><Eye size={11} /> Visible</span>
                      : <span className="text-[10px] font-bold inline-flex items-center gap-1" style={{ color: 'var(--text-muted)' }}><EyeOff size={11} /> Hidden</span>}
                  </td>
                  <td className="px-3 py-2.5">
                    <div className="flex gap-1 justify-end">
                      {['Paid', 'Hold', 'Failed'].map(s => (
                        <button key={s} onClick={() => mark(r, s)} disabled={busy || r.payment_status === s}
                          className="text-[10px] font-bold px-2 py-1 rounded-lg"
                          style={{ background: PAY_C[s].bg, color: PAY_C[s].c, opacity: r.payment_status === s ? 0.45 : 1 }}>
                          {s}
                        </button>
                      ))}
                    </div>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      {records.length === 0 && <HrEmpty icon={Banknote} title="No records on this run" />}
    </div>
  )
}

/* ── Small shared pieces ─────────────────────────────────────────────── */

function Stat({ label, value, colour, small }) {
  return (
    <div className="kpi-3d">
      <p className={small ? 'text-xl font-black' : 'text-3xl font-black'} style={{ color: colour }}>{value ?? '—'}</p>
      <p className="text-xs font-medium mt-1" style={{ color: 'var(--text-muted)' }}>{label}</p>
    </div>
  )
}

function Locked({ text, icon: Icon = Lock, tone = 'var(--text-muted)' }) {
  return (
    <div className="rounded-xl p-3 flex items-center gap-2.5" style={{ background: 'var(--bg-input)', border: '1px dashed var(--border)' }}>
      <Icon size={15} style={{ color: tone, flexShrink: 0 }} />
      <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{text}</p>
    </div>
  )
}
