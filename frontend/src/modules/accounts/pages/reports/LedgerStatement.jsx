import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Loader2, BookOpen } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import LoadError from '@/components/ui/LoadError'
import { fmtDate } from '@/modules/accounts/format'
import { useInr } from '@/modules/accounts/useMoney'
import { Select } from '@/components/ui/FormField'

export default function LedgerStatement() {
  const inr = useInr()
  const [ledgerId, setLedgerId] = useState('')
  const [range, setRange] = useState({ from: '', to: '' })

  const { data: ledgers = [] } = useQuery({ queryKey: ['accounts', 'ledgers', 'options'], queryFn: accountsApi.ledgers.options })
  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['accounts', 'report', 'ledger-statement', ledgerId, range],
    queryFn: () => accountsApi.reports.ledgerStatement({ ledger_id: ledgerId, ...range }),
    enabled: !!ledgerId,
  })

  return (
    <div className="space-y-5 animate-fade-in max-w-4xl">
      <Link to="/app/accounts/reports" className="flex items-center gap-1.5 text-sm font-semibold" style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={15} /> All reports
      </Link>

      <div className="flex items-center gap-3">
        <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}>
          <BookOpen size={18} style={{ color: '#a78bfa' }} />
        </div>
        <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Ledger Statement</h1>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <Select value={ledgerId} onChange={e => setLedgerId(e.target.value)} style={{ maxWidth: 280 }}>
          <option value="">Select a ledger…</option>
          {ledgers.map(l => <option key={l.id} value={l.id}>{l.name}</option>)}
        </Select>
        <input type="date" className="input-3d text-sm" value={range.from} onChange={e => setRange(r => ({ ...r, from: e.target.value }))} />
        <span className="text-sm" style={{ color: 'var(--text-muted)' }}>to</span>
        <input type="date" className="input-3d text-sm" value={range.to} onChange={e => setRange(r => ({ ...r, to: e.target.value }))} />
      </div>

      {!ledgerId && <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Pick a ledger to view its statement.</p>}

      {ledgerId && (isError ? <LoadError error={error} onRetry={refetch} title="Could not load this statement" />
        : isLoading ? <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div> : data && (
        <div className="table-wrapper">
          <table className="table">
            <thead><tr>
              <th>Date</th><th>Voucher</th><th>Narration</th>
              <th style={{ textAlign: 'right' }}>Debit</th><th style={{ textAlign: 'right' }}>Credit</th><th style={{ textAlign: 'right' }}>Balance</th>
            </tr></thead>
            <tbody>
              <tr>
                <td colSpan={5} style={{ fontWeight: 700, color: 'var(--text-muted)' }}>Opening balance</td>
                <td style={{ textAlign: 'right', fontWeight: 700, color: 'var(--text-muted)' }}>{inr(data.opening.balance)} {data.opening.balance_type}</td>
              </tr>
              {data.rows.map((r, i) => (
                <tr key={i}>
                  <td>{fmtDate(r.date)}</td>
                  <td style={{ color: 'var(--text-h)', fontWeight: 600 }}>{r.number}</td>
                  <td style={{ color: 'var(--text-muted)' }}>{r.narration || '—'}</td>
                  <td style={{ textAlign: 'right' }}>{Number(r.debit) > 0 ? inr(r.debit) : '—'}</td>
                  <td style={{ textAlign: 'right' }}>{Number(r.credit) > 0 ? inr(r.credit) : '—'}</td>
                  <td style={{ textAlign: 'right' }}>{inr(r.balance)} {r.balance_type}</td>
                </tr>
              ))}
              <tr style={{ borderTop: '2px solid var(--border)' }}>
                <td colSpan={3} style={{ fontWeight: 800, color: 'var(--text-h)' }}>Closing balance</td>
                <td style={{ textAlign: 'right', fontWeight: 800, color: 'var(--text-h)' }}>{inr(data.totals.debit)}</td>
                <td style={{ textAlign: 'right', fontWeight: 800, color: 'var(--text-h)' }}>{inr(data.totals.credit)}</td>
                <td style={{ textAlign: 'right', fontWeight: 800, color: 'var(--text-h)' }}>{inr(data.closing.balance)} {data.closing.balance_type}</td>
              </tr>
            </tbody>
          </table>
        </div>
      ))}
    </div>
  )
}
