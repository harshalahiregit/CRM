import { useState } from 'react'
import { useInr } from '@/modules/accounts/useMoney'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Loader2, Scale } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'


export default function TrialBalance() {
  const inr = useInr()
  const [to, setTo] = useState('')
  const { data, isLoading } = useQuery({
    queryKey: ['accounts', 'report', 'trial-balance', to],
    queryFn: () => accountsApi.reports.trialBalance(to ? { to } : {}),
  })

  return (
    <div className="space-y-5 animate-fade-in max-w-4xl">
      <Link to="/app/accounts/reports" className="flex items-center gap-1.5 text-sm font-semibold" style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={15} /> All reports
      </Link>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}>
            <Scale size={18} style={{ color: '#a78bfa' }} />
          </div>
          <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Trial Balance</h1>
        </div>
        <label className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-muted)' }}>
          As of <input type="date" className="input-3d text-sm" value={to} onChange={e => setTo(e.target.value)} />
        </label>
      </div>

      {isLoading ? <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div> : (
        <>
          <div className="table-wrapper">
            <table className="table">
              <thead><tr>
                <th>Ledger</th><th>Group</th>
                <th style={{ textAlign: 'right' }}>Debit</th><th style={{ textAlign: 'right' }}>Credit</th>
              </tr></thead>
              <tbody>
                {(data?.rows || []).map(r => (
                  <tr key={r.ledger_id}>
                    <td style={{ color: 'var(--text-h)', fontWeight: 600 }}>{r.ledger}</td>
                    <td style={{ color: 'var(--text-muted)' }}>{r.group}</td>
                    <td style={{ textAlign: 'right' }}>{r.debit > 0 ? inr(r.debit) : '—'}</td>
                    <td style={{ textAlign: 'right' }}>{r.credit > 0 ? inr(r.credit) : '—'}</td>
                  </tr>
                ))}
                <tr style={{ borderTop: '2px solid var(--border)' }}>
                  <td colSpan={2} style={{ fontWeight: 800, color: 'var(--text-h)' }}>Totals</td>
                  <td style={{ textAlign: 'right', fontWeight: 800, color: 'var(--text-h)' }}>{inr(data?.totals?.debit)}</td>
                  <td style={{ textAlign: 'right', fontWeight: 800, color: 'var(--text-h)' }}>{inr(data?.totals?.credit)}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p className="text-sm font-bold" style={{ color: data?.totals?.balanced ? '#10b981' : '#f87171' }}>
            {data?.totals?.balanced ? '✓ Balanced — debits equal credits' : '⚠ Out of balance'}
          </p>
        </>
      )}
    </div>
  )
}
