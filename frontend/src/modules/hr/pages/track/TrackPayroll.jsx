/**
 * Salaries on SangoeTrack.
 *
 * This is the whole of payroll there today: what each person is paid per month,
 * and which payslip type they sit on. There is no payslip generation, no
 * allowances or deductions, and no sending — those exist in SangoeTrack's web
 * admin but have no API, so they are on the build list rather than hidden behind
 * a button that does nothing.
 *
 * One thing worth knowing while using this screen: SangoeTrack's payroll does
 * not read attendance. A payslip is not prorated by days worked — someone absent
 * half the month is paid the same as someone who was there every day. That is
 * their design, not a bug in this screen, but it is why "salary set" here means
 * "will be paid this", flatly.
 */

import { useState, useEffect, useCallback, useMemo } from 'react'
import { Search, IndianRupee, AlertTriangle } from 'lucide-react'
import { sangoeTrackApi, trackErrorMessage } from '@/services/sangoeTrackApi'
import { useToast } from '@/hooks/useToast'
import LoadError from '@/components/ui/LoadError'
import EmptyState from '@/components/ui/EmptyState'
import { TrackHeader, ExportButton } from './TrackShell'

const inr = n =>
  new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 })
    .format(Number(n) || 0)

/**
 * Salaries as a spreadsheet. Raw numbers, not formatted currency — a payroll
 * export that cannot be summed is not much of an export.
 */
const CSV = [
  { key: 'name',             label: 'Name' },
  { key: 'employee_code',    label: 'Employee code' },
  { key: 'department',       label: 'Department' },
  { key: 'designation',      label: 'Designation' },
  { key: 'branch',           label: 'Branch' },
  { key: 'joining_date',     label: 'Joined' },
  { key: 'salary_type_name', label: 'Payslip type' },
  { key: 'salary',           label: 'Monthly salary' },
  { key: 'annual_salary',    label: 'Annual salary' },
  { label: 'Salary set', value: r => (r.salary_set ? 'Yes' : 'No') },
]

/* ── set a salary ────────────────────────────────────────────────────── */

function SalaryDialog({ person, payslipTypes, onClose, onSaved }) {
  const [salary, setSalary] = useState(person.salary > 0 ? String(person.salary) : '')
  const [type, setType]     = useState(person.salary_type ?? (payslipTypes[0]?.id ?? ''))
  const [busy, setBusy]     = useState(false)
  const toast = useToast()

  const amount = Number(salary)
  const valid  = salary !== '' && Number.isFinite(amount) && amount >= 0 && type !== ''

  async function save() {
    setBusy(true)
    try {
      await sangoeTrackApi.payroll.setSalary(person.id, amount, Number(type))
      toast.success(`${person.name} set to ${inr(amount)} a month`)
      onSaved()
      onClose()
    } catch (err) {
      toast.error(trackErrorMessage(err, 'Could not update the salary.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div role="dialog" aria-modal="true" aria-label={`Set salary for ${person.name}`}
      onClick={onClose}
      className="fixed inset-0 z-50 flex items-center justify-center p-6"
      style={{ background: 'rgba(0,0,0,0.65)' }}>
      <div onClick={e => e.stopPropagation()}
        className="rounded-xl p-5 flex flex-col gap-3.5"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', width: 'min(440px, 100%)' }}>

        <div>
          <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>
            {person.salary_set ? 'Change salary' : 'Set salary'}
          </p>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
            {person.name} · {person.designation} · {person.department}
          </p>
        </div>

        <label className="flex flex-col gap-1">
          <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
            Monthly salary
          </span>
          <input type="number" min="0" inputMode="decimal" autoFocus
            value={salary} onChange={e => setSalary(e.target.value)}
            className="rounded-lg text-sm px-2.5 py-2"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }} />
          {valid && amount > 0 && (
            <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
              {inr(amount)} a month · {inr(amount * 12)} a year
            </span>
          )}
        </label>

        <label className="flex flex-col gap-1">
          <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
            Payslip type
          </span>
          <select value={type} onChange={e => setType(e.target.value)}
            className="rounded-lg text-sm px-2.5 py-2"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
            {payslipTypes.length === 0 && <option value="">None configured on SangoeTrack</option>}
            {payslipTypes.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
          </select>
          {payslipTypes.length === 0 && (
            <span className="text-[11px]" style={{ color: '#fbbf24' }}>
              SangoeTrack has no payslip types set up — add one there first.
            </span>
          )}
        </label>

        <div className="flex gap-2">
          <button onClick={save} disabled={busy || !valid}
            className="rounded-lg text-xs font-bold disabled:opacity-50"
            style={{ padding: '7px 14px', background: '#7C3AED', color: '#fff' }}>
            {busy ? 'Saving…' : 'Save salary'}
          </button>
          <button onClick={onClose} disabled={busy}
            className="rounded-lg text-xs font-semibold disabled:opacity-50"
            style={{ padding: '7px 14px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
            Cancel
          </button>
        </div>
      </div>
    </div>
  )
}

/* ── page ────────────────────────────────────────────────────────────── */

export default function TrackPayroll() {
  const [data, setData]       = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError]     = useState(null)
  const [query, setQuery]     = useState('')
  const [onlyMissing, setOnlyMissing] = useState(false)
  const [editing, setEditing] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const res = await sangoeTrackApi.payroll.overview()
      setData(res?.data ?? null)
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  const employees   = data?.employees ?? []
  const summary     = data?.summary ?? {}
  const payslipTypes = data?.payslip_types ?? []

  const visible = useMemo(() => {
    const q = query.trim().toLowerCase()
    return employees.filter(e => {
      if (onlyMissing && e.salary_set) return false
      if (!q) return true
      return [e.name, e.employee_code, e.department, e.designation]
        .some(v => String(v ?? '').toLowerCase().includes(q))
    })
  }, [employees, query, onlyMissing])

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">
      <TrackHeader
        title="Payroll"
        subtitle="Monthly salary per person, live from SangoeTrack."
        onRefresh={load}
        loading={loading}
      />

      {error ? (
        <LoadError error={error} onRetry={load} title="Could not load payroll" />
      ) : loading ? (
        <p className="text-sm py-8 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</p>
      ) : (
        <>
          {/* ── summary ──────────────────────────────────────────── */}
          <div className="grid gap-2.5" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))' }}>
            {[
              ['Monthly cost', inr(summary.monthly_payroll_cost), '#34d399'],
              ['Employees',    summary.total_employees ?? 0,      'var(--text-h)'],
              ['Salary set',   summary.salary_set ?? 0,           'var(--text-h)'],
              ['Not set',      summary.salary_missing ?? 0,       (summary.salary_missing > 0 ? '#fbbf24' : 'var(--text-h)')],
            ].map(([label, value, colour]) => (
              <div key={label} className="rounded-xl p-3.5" style={{ border: '1px solid var(--border)', background: 'var(--bg-card)' }}>
                <div className="text-lg font-bold leading-none" style={{ color: colour, fontVariantNumeric: 'tabular-nums' }}>
                  {value}
                </div>
                <div className="text-[11px] mt-1 font-semibold" style={{ color: 'var(--text-muted)' }}>{label}</div>
              </div>
            ))}
          </div>

          {summary.salary_missing > 0 && (
            <p className="text-[11px] flex items-center gap-1.5" style={{ color: '#fbbf24' }}>
              <AlertTriangle size={12} />
              {summary.salary_missing} {summary.salary_missing === 1 ? 'person has' : 'people have'} no salary —
              they are not counted in the monthly cost above.
            </p>
          )}

          {/* ── filters ──────────────────────────────────────────── */}
          <div className="flex flex-wrap gap-2 items-center">
            <div className="flex items-center gap-2 rounded-lg px-2.5 flex-1"
              style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', minWidth: 200, maxWidth: 340 }}>
              <Search size={14} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
              <input value={query} onChange={e => setQuery(e.target.value)}
                placeholder="Search name, code, department"
                className="text-sm py-2 flex-1 bg-transparent outline-none" style={{ color: 'var(--text-h)' }} />
            </div>
            <ExportButton filename="sangoetrack-salaries" rows={visible} columns={CSV} />
            <button onClick={() => setOnlyMissing(v => !v)} aria-pressed={onlyMissing}
              className="rounded-lg text-xs font-semibold"
              style={{
                padding: '8px 14px',
                background: onlyMissing ? 'rgba(251,191,36,0.14)' : 'var(--bg-input)',
                border: `1px solid ${onlyMissing ? '#fbbf24' : 'var(--border)'}`,
                color: onlyMissing ? '#fbbf24' : 'var(--text-muted)',
              }}>
              Salary not set
            </button>
          </div>

          {/* ── list ─────────────────────────────────────────────── */}
          {visible.length === 0 ? (
            <EmptyState icon={IndianRupee}
              title={onlyMissing ? 'Everyone has a salary set' : 'Nobody matches that'}
              description={onlyMissing ? 'Nothing outstanding here.' : 'Try a different search.'} />
          ) : (
            <div className="rounded-xl overflow-x-auto" style={{ border: '1px solid var(--border)' }}>
              <table className="w-full text-sm" style={{ minWidth: 760 }}>
                <thead>
                  <tr style={{ background: 'var(--bg-input)' }}>
                    {['Person', 'Code', 'Department', 'Payslip type', 'Monthly', ''].map((h, i) => (
                      <th key={h} className="text-[11px] font-bold uppercase tracking-wider px-3 py-2.5"
                        style={{ color: 'var(--text-muted)', whiteSpace: 'nowrap', textAlign: i === 4 ? 'right' : 'left' }}>{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {visible.map(e => (
                    <tr key={e.id} style={{ borderTop: '1px solid var(--border)' }}>
                      <td className="px-3 py-2.5">
                        <div className="font-semibold" style={{ color: 'var(--text-h)' }}>{e.name}</div>
                        <div className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{e.designation}</div>
                      </td>
                      <td className="px-3 py-2.5" style={{ color: 'var(--text-muted)', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' }}>
                        {e.employee_code}
                      </td>
                      <td className="px-3 py-2.5" style={{ color: 'var(--text-h)' }}>{e.department}</td>
                      <td className="px-3 py-2.5" style={{ color: e.salary_type_name ? 'var(--text-h)' : 'var(--text-muted)' }}>
                        {e.salary_type_name ?? '—'}
                      </td>
                      <td className="px-3 py-2.5 text-right" style={{ fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' }}>
                        {e.salary_set
                          ? <span style={{ color: 'var(--text-h)', fontWeight: 600 }}>{inr(e.salary)}</span>
                          : <span className="text-[11px] font-bold" style={{ color: '#fbbf24' }}>Not set</span>}
                      </td>
                      <td className="px-3 py-2.5 text-right">
                        <button onClick={() => setEditing(e)}
                          className="rounded-lg text-[11px] font-semibold ml-auto"
                          style={{ padding: '5px 10px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: '#a78bfa', whiteSpace: 'nowrap' }}>
                          {e.salary_set ? 'Change' : 'Set salary'}
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
            Payslip generation, allowances and deductions live in SangoeTrack's web admin and
            have no API yet — they are not available from here.
          </p>
        </>
      )}

      {editing && (
        <SalaryDialog
          person={editing}
          payslipTypes={payslipTypes}
          onClose={() => setEditing(null)}
          onSaved={load}
        />
      )}
    </div>
  )
}
