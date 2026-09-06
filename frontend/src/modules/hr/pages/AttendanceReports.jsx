/**
 * Attendance for payroll: per employee, per month, per department.
 *
 * Built to answer one question — what does this person get this month — which
 * is why the money sits in the same table as the days. Approved claims and
 * outstanding advances used to live in two other screens, so deciding payroll
 * meant reconciling three of them by hand.
 *
 * PAYABLE DAYS EXCLUDES LEAVE, and the table says so rather than leaving
 * somebody to discover it from a total that does not add up. Whether a leave is
 * paid is a company policy this report does not know, and quietly guessing
 * produces a number that looks authoritative and is wrong for half its readers.
 */

import { useState, useEffect, useCallback } from 'react'
import { BarChart3, Download, RefreshCw, ChevronLeft, ChevronRight, Info } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import { useToast } from '@/components/ui/Toast'

const inr = n =>
  new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 })
    .format(Number(n) || 0)

const thisMonth = () => new Date().toISOString().slice(0, 7)

const monthLabel = m => {
  const [y, mo] = String(m || '').split('-')
  const d = new Date(Number(y), Number(mo) - 1, 1)
  return Number.isNaN(d.getTime()) ? m
    : d.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' })
}

const shift = (m, by) => {
  const [y, mo] = String(m).split('-').map(Number)
  const d = new Date(y, mo - 1 + by, 1)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

/** Columns, in one place, so the table and the CSV cannot drift apart. */
const COLUMNS = [
  { key: 'name',           label: 'Employee',  align: 'left' },
  { key: 'employee_code',  label: 'Code',      align: 'left' },
  { key: 'department',     label: 'Department', align: 'left' },
  { key: 'present_days',   label: 'Present' },
  { key: 'half_days',      label: 'Half' },
  { key: 'absent_days',    label: 'Absent' },
  { key: 'leave_days',     label: 'Leave' },
  { key: 'late_days',      label: 'Late' },
  { key: 'payable_days',   label: 'Payable' },
  { key: 'working_hours',  label: 'Hours' },
  { key: 'overtime_hours', label: 'OT' },
  { key: 'reimbursements_approved', label: 'Claims', money: true },
  { key: 'advance_outstanding',     label: 'Advance due', money: true },
]

const DEPT_COLUMNS = [
  { key: 'department',   label: 'Department', align: 'left' },
  { key: 'headcount',    label: 'People' },
  { key: 'present_days', label: 'Present' },
  { key: 'absent_days',  label: 'Absent' },
  { key: 'leave_days',   label: 'Leave' },
  { key: 'payable_days', label: 'Payable' },
  { key: 'working_hours', label: 'Hours' },
  { key: 'overtime_hours', label: 'OT' },
  { key: 'reimbursements_approved', label: 'Claims', money: true },
  { key: 'advance_outstanding',     label: 'Advance due', money: true },
]

/** A CSV that a spreadsheet can actually sum: raw numbers, no rupee symbols. */
function toCsv(rows, columns) {
  const esc = v => {
    const s = v === null || v === undefined ? '' : String(v)
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s
  }
  return [
    columns.map(c => esc(c.label)).join(','),
    ...rows.map(r => columns.map(c => esc(r[c.key])).join(',')),
  ].join('\n')
}

export default function AttendanceReports() {
  const toast = useToast()

  const [month, setMonth] = useState(thisMonth)
  const [view,  setView]  = useState('employees')   // 'employees' | 'departments'
  const [dept,  setDept]  = useState('')

  const [data,    setData]    = useState(null)
  const [loading, setLoading] = useState(true)
  const [error,   setError]   = useState(null)

  // The day-by-day breakdown behind one figure.
  const [detail, setDetail] = useState(null)

  const columns = view === 'departments' ? DEPT_COLUMNS : COLUMNS

  const load = useCallback(async () => {
    setLoading(true); setError(null)
    try {
      setData(view === 'departments'
        ? await hrApi.attendanceReports.departments({ month })
        : await hrApi.attendanceReports.monthly({ month, department: dept || undefined }))
    } catch (e) {
      setError(e?.response?.data?.message || 'Could not load the report.')
    } finally {
      setLoading(false)
    }
  }, [month, view, dept])

  useEffect(() => { load() }, [load])

  const openEmployee = async row => {
    if (view === 'departments') return
    setDetail({ loading: true, name: row.name })
    try {
      setDetail({ ...(await hrApi.attendanceReports.employee(row.employee_id, { month })), loading: false })
    } catch {
      toast.error('Could not load that breakdown.')
      setDetail(null)
    }
  }

  const download = () => {
    const rows = data?.rows || []
    if (!rows.length) return toast.error('Nothing to export.')

    const blob = new Blob([toCsv(rows, columns)], { type: 'text/csv;charset=utf-8' })
    const url  = URL.createObjectURL(blob)
    const a    = document.createElement('a')
    a.href = url
    a.download = `attendance-${view}-${month}.csv`
    a.click()
    URL.revokeObjectURL(url)
  }

  const departments = [...new Set((data?.rows || []).map(r => r.department).filter(Boolean))].sort()
  const totals = data?.totals

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <h1 className="text-lg font-bold flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
            <BarChart3 size={18} /> Attendance reports
          </h1>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
            Days, hours and the money that changes what somebody is paid.
          </p>
        </div>
        <div className="flex gap-2">
          <button onClick={load} disabled={loading}
            className="rounded-lg text-xs font-semibold flex items-center gap-1.5"
            style={{ padding: '7px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)' }}>
            <RefreshCw size={13} className={loading ? 'animate-spin' : ''} /> Refresh
          </button>
          <button onClick={download}
            className="rounded-lg text-xs font-bold flex items-center gap-1.5"
            style={{ padding: '7px 12px', background: 'var(--accent)', color: '#fff' }}>
            <Download size={13} /> CSV
          </button>
        </div>
      </div>

      {/* Filters in one row above the table. */}
      <div className="flex items-center gap-2 flex-wrap">
        <div className="flex items-center gap-1 rounded-lg" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <button onClick={() => setMonth(m => shift(m, -1))} aria-label="Previous month"
            style={{ padding: '6px 8px', color: 'var(--text-muted)' }}><ChevronLeft size={14} /></button>
          <span className="text-xs font-bold px-1" style={{ color: 'var(--text-h)', minWidth: 110, textAlign: 'center' }}>
            {monthLabel(month)}
          </span>
          <button onClick={() => setMonth(m => shift(m, 1))} aria-label="Next month"
            style={{ padding: '6px 8px', color: 'var(--text-muted)' }}><ChevronRight size={14} /></button>
        </div>

        {[['employees', 'By employee'], ['departments', 'By department']].map(([k, label]) => (
          <button key={k} onClick={() => setView(k)}
            className="rounded-lg text-xs font-semibold"
            style={{
              padding: '6px 12px',
              background: view === k ? 'var(--accent)' : 'var(--bg-input)',
              color: view === k ? '#fff' : 'var(--text-p)',
              border: '1px solid var(--border)',
            }}>{label}</button>
        ))}

        {view === 'employees' && departments.length > 0 && (
          <select value={dept} onChange={e => setDept(e.target.value)}
            className="rounded-lg text-xs"
            style={{ padding: '6px 10px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)' }}>
            <option value="">All departments</option>
            {departments.map(d => <option key={d} value={d}>{d}</option>)}
          </select>
        )}
      </div>

      {totals && (
        <div className="flex flex-wrap gap-x-6 gap-y-1 rounded-xl px-3.5 py-2.5"
          style={{ border: '1px solid var(--border)', background: 'var(--bg-card)' }}>
          {[['People', totals.employees], ['Payable days', totals.payable_days],
            ['Leave days', totals.leave_days], ['Absent', totals.absent_days],
            ['Hours', totals.working_hours], ['Overtime', totals.overtime_hours],
            ['Claims', inr(totals.reimbursements_approved)],
            ['Advance due', inr(totals.advance_outstanding)]].map(([k, v]) => (
            <div key={k}>
              <span className="text-[10px] uppercase tracking-wider font-bold" style={{ color: 'var(--text-muted)' }}>{k} </span>
              <span className="text-sm font-bold" style={{ color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>{v}</span>
            </div>
          ))}
        </div>
      )}

      <p className="text-[11px] flex items-start gap-1.5" style={{ color: 'var(--text-muted)' }}>
        <Info size={12} style={{ marginTop: 1, flexShrink: 0 }} />
        Payable days counts full days worked plus half of each half day. Leave is shown separately and
        never included — whether a leave is paid is your policy, not something this report should assume.
      </p>

      {loading ? <HrLoading /> : error ? (
        <div className="rounded-xl text-xs" style={{ padding: 14, background: 'rgba(248,113,113,0.1)', color: '#f87171' }}>
          {error} <button onClick={load} className="underline font-semibold ml-1">Try again</button>
        </div>
      ) : !data?.rows?.length ? (
        <HrEmpty icon={BarChart3} title="Nothing for this month" hint="No employees match these filters." />
      ) : (
        // The table scrolls inside its own box; the page never scrolls sideways.
        <div className="rounded-xl" style={{ border: '1px solid var(--border)', overflowX: 'auto' }}>
          <table className="w-full text-xs" style={{ borderCollapse: 'collapse', minWidth: 900 }}>
            <thead>
              <tr style={{ background: 'var(--bg-input)' }}>
                {columns.map(c => (
                  <th key={c.key} className="text-[10px] font-bold uppercase tracking-wider whitespace-nowrap"
                    style={{ padding: '9px 11px', textAlign: c.align || 'right', color: 'var(--text-muted)' }}>
                    {c.label}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.rows.map((r, i) => (
                <tr key={r.employee_id || r.department}
                  onClick={() => openEmployee(r)}
                  style={{
                    borderTop: '1px solid var(--border)',
                    cursor: view === 'employees' ? 'pointer' : 'default',
                    background: i % 2 ? 'transparent' : 'var(--bg-card)',
                  }}>
                  {columns.map(c => (
                    <td key={c.key} className="whitespace-nowrap"
                      style={{
                        padding: '8px 11px', textAlign: c.align || 'right',
                        color: c.key === 'name' || c.key === 'department' ? 'var(--text-h)' : 'var(--text-p)',
                        fontWeight: c.key === 'name' || c.key === 'payable_days' ? 700 : 400,
                        fontVariantNumeric: 'tabular-nums',
                      }}>
                      {c.money ? inr(r[c.key]) : (r[c.key] ?? '—')}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {detail && (
        <div role="dialog" aria-modal="true" aria-label="Daily breakdown"
          className="fixed inset-0 z-50 flex items-center justify-center p-4"
          style={{ background: 'rgba(0,0,0,0.6)' }} onClick={() => setDetail(null)}>
          <div onClick={e => e.stopPropagation()} className="rounded-2xl w-full max-w-xl flex flex-col"
            style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', maxHeight: '85vh' }}>
            <div className="p-5" style={{ borderBottom: '1px solid var(--border)' }}>
              <h2 className="text-base font-bold" style={{ color: 'var(--text-h)' }}>
                {detail.employee?.name || detail.name}
              </h2>
              <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                {monthLabel(month)} — every day recorded, so a total that looks wrong can be traced.
              </p>
            </div>

            <div className="p-5 overflow-y-auto">
              {detail.loading ? <HrLoading />
                : !detail.days?.length ? <HrEmpty icon={BarChart3} title="Nothing recorded" hint="No attendance for this month." />
                : (
                  <table className="w-full text-xs" style={{ borderCollapse: 'collapse' }}>
                    <thead>
                      <tr style={{ background: 'var(--bg-input)' }}>
                        {['Date', 'Status', 'In', 'Out', 'Hours', 'OT'].map(h => (
                          <th key={h} className="text-[10px] font-bold uppercase tracking-wider"
                            style={{ padding: '7px 9px', textAlign: h === 'Date' || h === 'Status' ? 'left' : 'right', color: 'var(--text-muted)' }}>
                            {h}
                          </th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {detail.days.map(d => (
                        <tr key={d.id} style={{ borderTop: '1px solid var(--border)' }}>
                          <td style={{ padding: '7px 9px', color: 'var(--text-h)' }}>{String(d.date).slice(0, 10)}</td>
                          <td style={{ padding: '7px 9px', color: 'var(--text-p)' }}>{d.status}</td>
                          <td style={{ padding: '7px 9px', textAlign: 'right', color: 'var(--text-p)' }}>
                            {d.check_in ? String(d.check_in).slice(11, 16) : '—'}
                          </td>
                          <td style={{ padding: '7px 9px', textAlign: 'right', color: 'var(--text-p)' }}>
                            {d.check_out ? String(d.check_out).slice(11, 16) : '—'}
                          </td>
                          <td style={{ padding: '7px 9px', textAlign: 'right', color: 'var(--text-p)', fontVariantNumeric: 'tabular-nums' }}>{d.working_hours}</td>
                          <td style={{ padding: '7px 9px', textAlign: 'right', color: 'var(--text-p)', fontVariantNumeric: 'tabular-nums' }}>{d.overtime_hours}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                )}
            </div>

            <div className="p-5" style={{ borderTop: '1px solid var(--border)' }}>
              <button onClick={() => setDetail(null)}
                className="rounded-lg text-xs font-semibold w-full"
                style={{ padding: '9px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)' }}>
                Close
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
