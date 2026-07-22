import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Landmark, TrendingUp, TrendingDown, Hourglass, AlertTriangle, Loader2 } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import { inr, fmtDate } from '@/modules/accounts/format'

/**
 * Accounts landing page (old-CRM Accounting dashboard parity). No charting
 * library is added for this — the trend/breakdown visuals are plain CSS bars
 * over the numbers the backend already derives from the ledger.
 */
export default function Dashboard() {
  const { data, isLoading } = useQuery({ queryKey: ['accounts', 'dashboard'], queryFn: accountsApi.dashboard })

  if (isLoading || !data) {
    return <div className="flex justify-center py-20"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
  }

  const maxTrend = Math.max(1, ...data.trend.flatMap(t => [t.income, t.expense]))
  const maxExpense = Math.max(1, ...data.expense_breakdown.map(e => Number(e.amount)))

  const tiles = [
    { label: 'Cash & Bank', value: inr(data.cash_and_bank), icon: Landmark, color: '#a78bfa' },
    { label: 'This Month — Income', value: inr(data.month_income), icon: TrendingUp, color: '#10b981' },
    { label: 'This Month — Expense', value: inr(data.month_expense), icon: TrendingDown, color: '#f87171' },
    { label: 'Receivable (AR)', value: inr(data.receivable_total), icon: Hourglass, color: '#22d3ee' },
    { label: 'Payable — Bills', value: inr(data.payable_total), icon: Hourglass, color: '#f59e0b' },
    { label: 'Overdue Bills', value: inr(data.payable_overdue), icon: AlertTriangle, color: '#f87171' },
  ]

  return (
    <div className="space-y-6 animate-fade-in">
      <div>
        <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Dashboard</h1>
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Live from the ledger — nothing here is a separate source of truth</p>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {tiles.map(({ label, value, icon: Icon, color }) => (
          <div key={label} className="kpi-3d flex items-center gap-3">
            <div className="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0" style={{ background: `${color}22` }}>
              <Icon size={19} style={{ color }} />
            </div>
            <div className="min-w-0">
              <p className="text-lg font-black truncate" style={{ color: 'var(--text-h)' }}>{value}</p>
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{label}</p>
            </div>
          </div>
        ))}
      </div>

      <div className="grid gap-5 lg:grid-cols-2">
        {/* Income vs Expense trend, 6 months */}
        <div className="kpi-3d">
          <h3 className="font-bold text-sm mb-4" style={{ color: 'var(--text-h)' }}>Income vs Expense — last 6 months</h3>
          <div className="flex items-end gap-3" style={{ height: 160 }}>
            {data.trend.map((t) => (
              <div key={t.month} className="flex-1 flex flex-col items-center gap-1">
                <div className="w-full flex items-end justify-center gap-1" style={{ height: 130 }}>
                  <div title={inr(t.income)} className="flex-1 rounded-t-md" style={{ height: `${(t.income / maxTrend) * 100}%`, background: '#10b981', minHeight: 2 }} />
                  <div title={inr(t.expense)} className="flex-1 rounded-t-md" style={{ height: `${(t.expense / maxTrend) * 100}%`, background: '#f87171', minHeight: 2 }} />
                </div>
                <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{t.month}</span>
              </div>
            ))}
          </div>
          <div className="flex items-center gap-4 mt-3 text-[11px]" style={{ color: 'var(--text-muted)' }}>
            <span className="flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-sm" style={{ background: '#10b981' }} /> Income</span>
            <span className="flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-sm" style={{ background: '#f87171' }} /> Expense</span>
          </div>
        </div>

        {/* Expense breakdown */}
        <div className="kpi-3d">
          <h3 className="font-bold text-sm mb-4" style={{ color: 'var(--text-h)' }}>Top expenses this month</h3>
          {!data.expense_breakdown.length ? (
            <p className="text-xs py-6 text-center" style={{ color: 'var(--text-muted)' }}>No expenses posted this month.</p>
          ) : (
            <div className="space-y-2.5">
              {data.expense_breakdown.map((e) => (
                <div key={e.name}>
                  <div className="flex justify-between text-xs mb-1">
                    <span style={{ color: 'var(--text-h)' }}>{e.name}</span>
                    <span className="font-bold" style={{ color: 'var(--text-muted)' }}>{inr(e.amount)}</span>
                  </div>
                  <div className="h-1.5 rounded-full" style={{ background: 'var(--bg-input)' }}>
                    <div className="h-full rounded-full" style={{ width: `${(e.amount / maxExpense) * 100}%`, background: '#a78bfa' }} />
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>

      {/* Bank accounts + recent vouchers */}
      <div className="grid gap-5 lg:grid-cols-2">
        <div className="kpi-3d">
          <h3 className="font-bold text-sm mb-3" style={{ color: 'var(--text-h)' }}>Bank & cash accounts</h3>
          {!data.bank_accounts.length ? (
            <p className="text-xs py-4 text-center" style={{ color: 'var(--text-muted)' }}>No bank accounts set up yet.</p>
          ) : data.bank_accounts.map(b => (
            <div key={b.id} className="flex justify-between items-center py-2" style={{ borderBottom: '1px solid var(--border)' }}>
              <span className="text-sm" style={{ color: 'var(--text-h)' }}>{b.bank_name}</span>
              <span className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{inr(b.current_balance)}</span>
            </div>
          ))}
        </div>

        <div className="kpi-3d">
          <h3 className="font-bold text-sm mb-3" style={{ color: 'var(--text-h)' }}>Recent vouchers</h3>
          {!data.recent_vouchers.length ? (
            <p className="text-xs py-4 text-center" style={{ color: 'var(--text-muted)' }}>No vouchers posted yet.</p>
          ) : data.recent_vouchers.map(v => (
            <Link key={v.id} to={`/app/accounts/vouchers/${v.id}`} className="flex justify-between items-center py-2 hover:opacity-80" style={{ borderBottom: '1px solid var(--border)' }}>
              <div className="min-w-0">
                <p className="text-sm font-semibold truncate" style={{ color: 'var(--text-h)' }}>{v.number}</p>
                <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{v.voucher_type?.name} · {fmtDate(v.date)}</p>
              </div>
              <span className="text-sm font-bold flex-shrink-0" style={{ color: 'var(--text-h)' }}>{inr(v.total_amount)}</span>
            </Link>
          ))}
        </div>
      </div>
    </div>
  )
}
