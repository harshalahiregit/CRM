import { useState, useEffect } from 'react'
import { Wallet, History } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import SalarySheet from './SalarySheet'

/**
 * Read-only Salary Structure section for the Employee Profile. Shows the current
 * assigned structure's frozen snapshot figures (authoritative), the component-level
 * salary sheet, and the append-only revision history. Purely presentational — every
 * number comes from the central Salary Engine; nothing is edited here.
 */
const inr = v => `₹${Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`

export default function EmployeeSalarySection({ employeeId }) {
  const [data, setData] = useState(null)
  const [breakdown, setBreakdown] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!employeeId) return
    let alive = true
    setLoading(true)
    hrApi.payroll.employeeSalary.get(employeeId)
      .then(async d => {
        if (!alive) return
        setData(d)
        if (d.current?.salary_structure_id) {
          try {
            const s = await hrApi.payroll.salaryStructures.get(d.current.salary_structure_id)
            if (alive) setBreakdown(s.breakdown)
          } catch { /* structure may be gone; snapshot figures still show */ }
        }
      })
      .catch(() => {})
      .finally(() => { if (alive) setLoading(false) })
    return () => { alive = false }
  }, [employeeId])

  if (loading) return <p className="text-xs py-4" style={{ color: 'var(--text-muted)' }}>Loading salary…</p>

  const cur = data?.current
  if (!cur) {
    return (
      <div className="rounded-xl p-4 text-sm" style={{ background: 'var(--bg-input)', border: '1px dashed var(--border)', color: 'var(--text-muted)' }}>
        <Wallet size={16} className="inline mr-2" style={{ color: '#a78bfa' }} /> No salary structure assigned yet.
      </div>
    )
  }

  const KPIS = [
    { l: 'Annual CTC', v: cur.annual_ctc, c: '#7C3AED' },
    { l: 'Monthly CTC', v: cur.monthly_ctc, c: '#7C3AED' },
    { l: 'Gross', v: cur.gross_salary, c: '#10b981' },
    { l: 'Employer', v: cur.total_benefits, c: '#3b82f6' },
    { l: 'Net (In Hand)', v: cur.net_salary, c: '#059669' },
  ]

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between flex-wrap gap-2">
        <div>
          <p className="text-sm font-black" style={{ color: 'var(--text-h)' }}>{cur.structure_name}</p>
          <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>Effective {cur.effective_from}{cur.effective_to ? ` → ${cur.effective_to}` : ' → present'}</p>
        </div>
        <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background: 'rgba(16,185,129,0.12)', color: '#10b981' }}>Active</span>
      </div>

      <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
        {KPIS.map(k => (
          <div key={k.l} className="rounded-xl p-3" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            <p className="text-lg font-black" style={{ color: k.c }}>{inr(k.v)}</p>
            <p className="text-[10px] font-medium mt-0.5" style={{ color: 'var(--text-muted)' }}>{k.l}</p>
          </div>
        ))}
      </div>

      {breakdown && <SalarySheet breakdown={breakdown} structureName={cur.structure_name} />}

      {data?.revisions?.length > 0 && (
        <div>
          <p className="text-[11px] font-bold uppercase mb-2 flex items-center gap-1.5" style={{ color: 'var(--text-muted)', letterSpacing: '0.04em' }}><History size={12} /> Revision History</p>
          <div className="space-y-2">
            {data.revisions.map(rv => (
              <div key={rv.id} className="rounded-xl p-2.5 flex items-center justify-between flex-wrap gap-2" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <span className="text-[11px] font-bold" style={{ color: 'var(--text-h)' }}>Rev #{rv.revision_no} · {rv.to_structure}</span>
                <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{rv.effective_from}{rv.reason ? ` · ${rv.reason}` : ''}</span>
                <span className="text-[11px] font-black" style={{ color: '#10b981' }}>
                  {rv.previous_monthly_ctc != null && <span style={{ color: 'var(--text-muted)', fontWeight: 400 }}>{inr(rv.previous_monthly_ctc)} → </span>}
                  {inr(rv.new_monthly_ctc)}/mo
                </span>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
