import { useState, useEffect, useCallback } from 'react'
import { BarChart3, Download, FileText, Filter } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'

const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'
const inr = v => `₹${Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`

/**
 * Enterprise Salary Reports — one generic viewer for all ten reports. Report picker
 * + filters drive a uniform {title, columns, rows} payload from the server; CSV/PDF
 * export reuses the shared payroll report framework. Read-only.
 */
export default function SalaryReports({ showToast }) {
  const [reports, setReports] = useState([])
  const [filterOpts, setFilterOpts] = useState({ departments: [], designations: [], grades: [], structures: [] })
  const [report, setReport] = useState('employees')
  const [f, setF] = useState({ department: 'All', designation: 'All', grade_id: 'All', structure_id: 'All', status: 'All' })
  const [data, setData] = useState(null)
  const [summary, setSummary] = useState(null)
  const [loading, setLoading] = useState(true)
  const [exporting, setExporting] = useState(false)

  useEffect(() => {
    hrApi.payroll.salaryReports.meta()
      .then(m => { setReports(m.reports || []); setFilterOpts(m.filters || filterOpts) })
      .catch(() => showToast?.('Failed to load report catalog', 'error'))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const params = useCallback(() => {
    const p = {}
    Object.entries(f).forEach(([k, v]) => { if (v && v !== 'All') p[k] = v })
    return p
  }, [f])

  const load = useCallback(() => {
    setLoading(true)
    Promise.all([hrApi.payroll.salaryReports.get(report, params()), hrApi.payroll.salaryReports.summary(params())])
      .then(([d, s]) => { setData(d); setSummary(s) })
      .catch(() => showToast?.('Failed to load report', 'error'))
      .finally(() => setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [report, f])
  useEffect(() => { load() }, [load])

  const doExport = async (format) => {
    setExporting(true)
    try { await hrApi.payroll.salaryReports.export(report, format, params()) }
    catch { showToast?.('Export failed', 'error') }
    finally { setExporting(false) }
  }

  const KPIS = summary ? [
    { l: 'Employees', v: summary.employees, c: '#7C3AED', money: false },
    { l: 'Monthly CTC', v: summary.total_monthly_ctc, c: '#0ea5e9', money: true },
    { l: 'Annual CTC', v: summary.total_annual_ctc, c: '#6366f1', money: true },
    { l: 'Avg CTC', v: summary.average_ctc, c: '#8b5cf6', money: true },
    { l: 'Gross', v: summary.total_gross, c: '#10b981', money: true },
    { l: 'Employer', v: summary.total_employer, c: '#3b82f6', money: true },
    { l: 'Deductions', v: summary.total_deductions, c: '#f87171', money: true },
    { l: 'Net', v: summary.total_net, c: '#059669', money: true },
  ] : []

  return (
    <div className="space-y-4">
      {/* KPI summary */}
      {summary && (
        <div className="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-3">
          {KPIS.map(k => (
            <div key={k.l} className="kpi-3d"><p className="text-xl font-black" style={{ color: k.c }}>{k.money ? inr(k.v) : k.v}</p><p className="text-[11px] font-medium mt-1" style={{ color: 'var(--text-muted)' }}>{k.l}</p></div>
          ))}
        </div>
      )}

      {/* Report picker + filters + export */}
      <div className="card-3d" style={{ padding: '16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="min-w-[220px]"><label className="label flex items-center gap-1"><BarChart3 size={12} /> Report</label>
            <select className="input-3d text-sm" value={report} onChange={e => setReport(e.target.value)}>
              {reports.map(r => <option key={r.key} value={r.key}>{r.label}</option>)}
            </select>
          </div>
          <Sel label="Department" val={f.department} set={v => setF(s => ({ ...s, department: v }))} opts={['All', ...filterOpts.departments]} />
          <Sel label="Designation" val={f.designation} set={v => setF(s => ({ ...s, designation: v }))} opts={['All', ...filterOpts.designations]} />
          <div className="min-w-[130px]"><label className="label">Grade</label>
            <select className="input-3d text-sm" value={f.grade_id} onChange={e => setF(s => ({ ...s, grade_id: e.target.value }))}>
              <option value="All">All</option>{filterOpts.grades.map(g => <option key={g.id} value={g.id}>{g.name}</option>)}
            </select>
          </div>
          <Sel label="Status" val={f.status} set={v => setF(s => ({ ...s, status: v }))} opts={['All', 'Active', 'Inactive']} />
          <div className="ml-auto flex gap-2">
            <button onClick={() => doExport('csv')} disabled={exporting} className="flex items-center gap-1.5 px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}><Download size={13} /> CSV</button>
            <button onClick={() => doExport('pdf')} disabled={exporting} className="flex items-center gap-1.5 px-3 py-2.5 rounded-xl text-xs font-bold text-white" style={{ background: GRAD }}><FileText size={13} /> PDF</button>
          </div>
        </div>
      </div>

      {/* Report table */}
      {loading ? <HrLoading label="Loading report…" />
        : !data || data.rows.length === 0 ? <HrEmpty icon={Filter} title="No data" hint="No rows match the current filters for this report." />
        : (
          <div className="card-3d overflow-x-auto" style={{ padding: '6px' }}>
            <div className="px-3 py-2 flex items-center justify-between">
              <span className="text-sm font-black" style={{ color: 'var(--text-h)' }}>{data.title}</span>
              <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{data.rows.length} row(s)</span>
            </div>
            <table className="w-full text-sm" style={{ minWidth: 760 }}>
              <thead><tr style={{ borderBottom: '1px solid var(--border)' }}>
                {data.columns.map(c => <th key={c.key} className={`px-3 py-2.5 label-caps whitespace-nowrap ${c.numeric ? 'text-right' : 'text-left'}`}>{c.label}</th>)}
              </tr></thead>
              <tbody>
                {data.rows.map((row, i) => (
                  <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                    {data.columns.map(c => {
                      const v = row[c.key]
                      const isMoney = c.numeric && !['Rev #', 'Employees', 'Used In', 'Net % of Gross', '% of CTC', '% of Gross'].includes(c.label)
                      return (
                        <td key={c.key} className={`px-3 py-2.5 ${c.numeric ? 'text-right font-semibold' : ''}`} style={{ color: c.numeric ? 'var(--text-h)' : 'var(--text-muted)' }}>
                          {v === '' || v === null || v === undefined ? '—'
                            : isMoney ? inr(v)
                            : (c.label.includes('%') ? `${v}%` : v)}
                        </td>
                      )
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
    </div>
  )
}

const Sel = ({ label, val, set, opts }) => (
  <div className="min-w-[140px]"><label className="label">{label}</label>
    <select className="input-3d text-sm" value={val} onChange={e => set(e.target.value)}>{opts.map(o => <option key={o} value={o}>{o}</option>)}</select>
  </div>
)
