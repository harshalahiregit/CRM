import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Loader2, BookOpen } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import LoadError from '@/components/ui/LoadError'
import { fmtDate } from '@/modules/accounts/format'
import { useInr } from '@/modules/accounts/useMoney'

export default function GeneralLedger() {
  const inr = useInr()
  const [range, setRange] = useState({ from: '', to: '' })
  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['accounts', 'report', 'general-ledger', range],
    queryFn: () => accountsApi.reports.generalLedger(range),
  })

  return (
    <div className="space-y-5 animate-fade-in max-w-5xl">
      <Link to="/app/accounts/reports" className="flex items-center gap-1.5 text-sm font-semibold" style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={15} /> All reports
      </Link>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}><BookOpen size={18} style={{ color: '#a78bfa' }} /></div>
          <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>General Ledger</h1>
        </div>
        <div className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-muted)' }}>
          <input type="date" className="input-3d text-sm" value={range.from} onChange={e => setRange(r => ({ ...r, from: e.target.value }))} />
          <span>to</span>
          <input type="date" className="input-3d text-sm" value={range.to} onChange={e => setRange(r => ({ ...r, to: e.target.value }))} />
        </div>
      </div>

      {isError ? <LoadError error={error} onRetry={refetch} title="Could not load this report" />
        : isLoading ? <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div> : (
        <div className="space-y-6">
          {(data?.ledgers || []).length === 0 && <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No ledger activity.</p>}
          {(data?.ledgers || []).map((l, i) => (
            <div key={i}>
              <div className="flex items-center justify-between mb-1">
                <p className="font-bold" style={{ color: 'var(--text-h)' }}>{l.ledger} <span className="text-xs font-normal" style={{ color: 'var(--text-muted)' }}>· {l.group}</span></p>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Opening {inr(l.opening.balance)} {l.opening.balance_type}</p>
              </div>
              <div className="table-wrapper">
                <table className="table">
                  <thead><tr><th>Date</th><th>Voucher</th><th>Narration</th><th style={{ textAlign: 'right' }}>Debit</th><th style={{ textAlign: 'right' }}>Credit</th><th style={{ textAlign: 'right' }}>Balance</th></tr></thead>
                  <tbody>
                    {l.rows.map((r, j) => (
                      <tr key={j}>
                        <td>{fmtDate(r.date)}</td>
                        <td style={{ color: 'var(--text-h)' }}>{r.number}</td>
                        <td style={{ color: 'var(--text-muted)' }}>{r.narration || '—'}</td>
                        <td style={{ textAlign: 'right' }}>{r.debit > 0 ? inr(r.debit) : '—'}</td>
                        <td style={{ textAlign: 'right' }}>{r.credit > 0 ? inr(r.credit) : '—'}</td>
                        <td style={{ textAlign: 'right' }}>{inr(r.balance)} {r.balance_type}</td>
                      </tr>
                    ))}
                    <tr style={{ borderTop: '2px solid var(--border)' }}>
                      <td colSpan={3} style={{ fontWeight: 800, color: 'var(--text-h)' }}>Closing</td>
                      <td style={{ textAlign: 'right', fontWeight: 800 }}>{inr(l.totals.debit)}</td>
                      <td style={{ textAlign: 'right', fontWeight: 800 }}>{inr(l.totals.credit)}</td>
                      <td style={{ textAlign: 'right', fontWeight: 800, color: 'var(--text-h)' }}>{inr(l.closing.balance)} {l.closing.balance_type}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
