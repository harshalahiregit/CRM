import { useState, useEffect } from 'react'
import { Landmark, AlertTriangle } from 'lucide-react'
import { hrApi } from '@/services/hrApi'

/**
 * Review comment #38 — the employee-facing half of "show loan deduction status in
 * the relevant screens".
 *
 * READ-ONLY, and summary only. Every figure comes from LoanRecoveryService, which
 * aggregates columns payroll already wrote; nothing here recalculates anything or
 * offers an action. Approving, disbursing and waiving all stay on the Loans
 * screen, which owns those rules.
 *
 * The endpoint is HR-gated, so a caller without permission simply gets nothing —
 * the card renders null rather than an error, because a missing loan card is not
 * a broken profile.
 */
export default function EmployeeLoanCard({ employeeId }) {
  const [data, setData] = useState(null)

  useEffect(() => {
    if (!employeeId) return
    let cancelled = false
    hrApi.employees.loanSummary(employeeId)
      .then(r => { if (!cancelled) setData(r) })
      .catch(() => { if (!cancelled) setData(null) })
    return () => { cancelled = true }
  }, [employeeId])

  // No loans is the common case and needs no card of its own.
  if (!data?.has_loans) return null

  const stats = [
    ['Outstanding',  inr(data.total_outstanding), '#f59e0b'],
    ['Monthly EMI',  inr(data.monthly_emi),       'var(--text-h)'],
    ['Active Loans', data.active_count,           'var(--text-h)'],
    ['Closed',       data.closed_count,           'var(--text-muted)'],
  ]

  return (
    // The spacing lives here, not on the host: the card renders null for most
    // employees, and a margin on the next heading would leave a gap for nothing.
    <div className="card-3d mb-5" style={{ padding:'14px 16px' }}>
      {/* No "Open Loans" link: /app/hr/loans has no route and no page, so it
          landed the user on the full-screen 404 and out of the app shell. The
          figures below are the whole summary, so nothing is lost by removing
          it. Restore the link when modules/hr/pages/Loans.jsx exists — and have
          it read ?employee= via useSearchParams, or the param is dropped. */}
      <div className="flex items-center justify-between gap-2 mb-3">
        <p className="text-xs font-black flex items-center gap-1.5" style={{ color:'var(--text-h)' }}>
          <Landmark size={14} style={{ color:'#f59e0b' }}/> Loans &amp; Advances
        </p>
      </div>

      <div className="grid grid-cols-2 md:grid-cols-4 gap-2 mb-3">
        {stats.map(([label, value, colour]) => (
          <div key={label}>
            <p className="text-sm font-black" style={{ color:colour }}>{value}</p>
            <p className="text-[10px]" style={{ color:'var(--text-muted)' }}>{label}</p>
          </div>
        ))}
      </div>

      {/* Arrears are the actionable number: instalments whose period has passed
          that payroll never collected. Silence here would let a loan quietly stop
          being repaid. */}
      {data.arrear_count > 0 && (
        <div className="rounded-xl p-2.5 mb-3 flex items-start gap-2" style={{ background:'rgba(248,113,113,0.08)', border:'1px solid rgba(248,113,113,0.25)' }}>
          <AlertTriangle size={13} style={{ color:'#f87171', flexShrink:0, marginTop:1 }}/>
          <p className="text-[11px]" style={{ color:'#f87171' }}>
            {data.arrear_count} instalment(s) totalling {inr(data.arrear_amount)} were due in an earlier period
            and have not been recovered by payroll.
          </p>
        </div>
      )}

      <div className="space-y-1">
        {(data.loans || []).map(l => (
          <div key={l.id} className="flex items-center gap-2 px-2 py-1.5 rounded-lg" style={{ background:'var(--bg-input)' }}>
            <span className="text-[11px] font-semibold" style={{ color:'var(--text-h)' }}>{l.loan_number}</span>
            <span className="text-[10px]" style={{ color:'var(--text-muted)' }}>
              {l.loan_type}{l.is_advance ? ' · Advance' : ''}
            </span>
            <span className="ml-auto flex items-center gap-2">
              <span className="rounded-full overflow-hidden" style={{ width:56, height:5, background:'var(--border)' }}>
                <span style={{ display:'block', height:'100%', width:`${Math.min(100, l.percent_recovered)}%`, background: l.status === 'closed' ? '#10b981' : '#f59e0b' }}/>
              </span>
              <span className="text-[10px] whitespace-nowrap font-semibold" style={{ color: l.status === 'closed' ? '#10b981' : '#f59e0b' }}>
                {l.percent_recovered}%
              </span>
              <span className="text-[10px] whitespace-nowrap" style={{ color:'var(--text-muted)' }}>{inr(l.outstanding)} left</span>
            </span>
          </div>
        ))}
      </div>
    </div>
  )
}

const inr = (n) => `₹${Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 })}`
