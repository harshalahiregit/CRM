import { useState } from 'react'
import { useInr } from '@/modules/accounts/useMoney'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Loader2, TrendingUp } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import LoadError from '@/components/ui/LoadError'


function Section({ title, rows, total }) {
  const inr = useInr()
  return (
    <div className="table-wrapper">
      <table className="table">
        <thead><tr><th>{title}</th><th style={{ textAlign: 'right' }}>Amount</th></tr></thead>
        <tbody>
          {rows.length === 0 && <tr><td colSpan={2} style={{ color: 'var(--text-muted)' }}>No entries</td></tr>}
          {rows.map(r => (
            <tr key={r.ledger_id}>
              <td style={{ color: 'var(--text-h)' }}>{r.ledger} <span style={{ color: 'var(--text-muted)' }}>· {r.group}</span></td>
              <td style={{ textAlign: 'right' }}>{inr(r.amount)}</td>
            </tr>
          ))}
          <tr style={{ borderTop: '2px solid var(--border)' }}>
            <td style={{ fontWeight: 800, color: 'var(--text-h)' }}>Total {title}</td>
            <td style={{ textAlign: 'right', fontWeight: 800, color: 'var(--text-h)' }}>{inr(total)}</td>
          </tr>
        </tbody>
      </table>
    </div>
  )
}

export default function ProfitAndLoss() {
  const inr = useInr()
  const [range, setRange] = useState({ from: '', to: '' })
  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['accounts', 'report', 'profit-loss', range],
    queryFn: () => accountsApi.reports.profitLoss(range),
  })
  const t = data?.totals

  return (
    <div className="space-y-5 animate-fade-in max-w-4xl">
      <Link to="/app/accounts/reports" className="flex items-center gap-1.5 text-sm font-semibold" style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={15} /> All reports
      </Link>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}>
            <TrendingUp size={18} style={{ color: '#a78bfa' }} />
          </div>
          <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Profit &amp; Loss</h1>
        </div>
        <div className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-muted)' }}>
          <input type="date" className="input-3d text-sm" value={range.from} onChange={e => setRange(r => ({ ...r, from: e.target.value }))} />
          <span>to</span>
          <input type="date" className="input-3d text-sm" value={range.to} onChange={e => setRange(r => ({ ...r, to: e.target.value }))} />
        </div>
      </div>

      {isError ? <LoadError error={error} onRetry={refetch} title="Could not load this report" />
        : isLoading ? <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div> : (
        <>
          <div className="grid gap-4 lg:grid-cols-2">
            <Section title="Income" rows={data?.income || []} total={t?.income} />
            <Section title="Expense" rows={data?.expense || []} total={t?.expense} />
          </div>
          <div className="kpi-3d flex items-center justify-between">
            <span className="font-black" style={{ color: 'var(--text-h)' }}>{t?.is_profit ? 'Net Profit' : 'Net Loss'}</span>
            <span className="text-xl font-black" style={{ color: t?.is_profit ? '#10b981' : '#f87171' }}>{inr(Math.abs(t?.net_profit || 0))}</span>
          </div>
        </>
      )}
    </div>
  )
}
