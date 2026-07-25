import { useState } from 'react'
import { useInr } from '@/modules/accounts/useMoney'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Loader2, FileBarChart2 } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'


function Column({ title, rows, extraLabel, extraAmount, total }) {
  const inr = useInr()
  return (
    <div className="table-wrapper">
      <table className="table">
        <thead><tr><th>{title}</th><th style={{ textAlign: 'right' }}>Amount</th></tr></thead>
        <tbody>
          {rows.length === 0 && !extraLabel && <tr><td colSpan={2} style={{ color: 'var(--text-muted)' }}>No entries</td></tr>}
          {rows.map(r => (
            <tr key={r.ledger_id}>
              <td style={{ color: 'var(--text-h)' }}>{r.ledger} <span style={{ color: 'var(--text-muted)' }}>· {r.group}</span></td>
              <td style={{ textAlign: 'right' }}>{inr(r.amount)}</td>
            </tr>
          ))}
          {extraLabel && (
            <tr>
              <td style={{ color: '#a78bfa', fontWeight: 600 }}>{extraLabel}</td>
              <td style={{ textAlign: 'right', color: '#a78bfa' }}>{inr(extraAmount)}</td>
            </tr>
          )}
          <tr style={{ borderTop: '2px solid var(--border)' }}>
            <td style={{ fontWeight: 800, color: 'var(--text-h)' }}>Total</td>
            <td style={{ textAlign: 'right', fontWeight: 800, color: 'var(--text-h)' }}>{inr(total)}</td>
          </tr>
        </tbody>
      </table>
    </div>
  )
}

export default function BalanceSheet() {
  const inr = useInr()
  const [to, setTo] = useState('')
  const { data, isLoading } = useQuery({
    queryKey: ['accounts', 'report', 'balance-sheet', to],
    queryFn: () => accountsApi.reports.balanceSheet(to ? { to } : {}),
  })
  const t = data?.totals

  return (
    <div className="space-y-5 animate-fade-in max-w-5xl">
      <Link to="/app/accounts/reports" className="flex items-center gap-1.5 text-sm font-semibold" style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={15} /> All reports
      </Link>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}>
            <FileBarChart2 size={18} style={{ color: '#a78bfa' }} />
          </div>
          <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Balance Sheet</h1>
        </div>
        <label className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-muted)' }}>
          As of <input type="date" className="input-3d text-sm" value={to} onChange={e => setTo(e.target.value)} />
        </label>
      </div>

      {isLoading ? <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div> : (
        <>
          <div className="grid gap-4 lg:grid-cols-2">
            <Column title="Liabilities & Equity" rows={[...(data?.liabilities || []), ...(data?.equity || [])]}
              extraLabel={`Current ${(data?.net_profit || 0) >= 0 ? 'Year Profit' : 'Year Loss'}`}
              extraAmount={data?.net_profit}
              total={t?.liabilities_and_equity} />
            <Column title="Assets" rows={data?.assets || []} total={t?.assets} />
          </div>
          <p className="text-sm font-bold" style={{ color: t?.balanced ? '#10b981' : '#f87171' }}>
            {t?.balanced ? '✓ Balanced — Assets = Liabilities + Equity + Profit' : '⚠ Out of balance'}
          </p>
        </>
      )}
    </div>
  )
}
