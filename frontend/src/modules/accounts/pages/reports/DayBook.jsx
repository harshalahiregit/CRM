import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Loader2, CalendarDays } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import { inr, fmtDate } from '@/modules/accounts/format'

export default function DayBook() {
  const today = new Date().toISOString().slice(0, 10)
  const [range, setRange] = useState({ from: today, to: today })
  const { data, isLoading } = useQuery({
    queryKey: ['accounts', 'report', 'day-book', range],
    queryFn: () => accountsApi.reports.dayBook(range),
  })

  return (
    <div className="space-y-5 animate-fade-in max-w-4xl">
      <Link to="/app/accounts/reports" className="flex items-center gap-1.5 text-sm font-semibold" style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={15} /> All reports
      </Link>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}><CalendarDays size={18} style={{ color: '#a78bfa' }} /></div>
          <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Day Book</h1>
        </div>
        <div className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-muted)' }}>
          <input type="date" className="input-3d text-sm" value={range.from} onChange={e => setRange(r => ({ ...r, from: e.target.value }))} />
          <span>to</span>
          <input type="date" className="input-3d text-sm" value={range.to} onChange={e => setRange(r => ({ ...r, to: e.target.value }))} />
        </div>
      </div>

      {isLoading ? <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div> : (
        <>
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>{data?.vouchers?.length || 0} vouchers · total {inr(data?.total)}</p>
          <div className="space-y-3">
            {(data?.vouchers || []).map(v => (
              <div key={v.id} className="kpi-3d">
                <div className="flex items-center justify-between">
                  <div>
                    <span className="font-bold" style={{ color: 'var(--text-h)' }}>{v.number}</span>
                    <span className="text-xs ml-2" style={{ color: 'var(--text-muted)' }}>{v.type} · {fmtDate(v.date)}</span>
                  </div>
                  <span className="font-bold" style={{ color: 'var(--text-h)' }}>{inr(v.amount)}</span>
                </div>
                {v.narration && <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{v.narration}</p>}
                <table className="table mt-2">
                  <tbody>
                    {v.lines.map((l, i) => (
                      <tr key={i}>
                        <td style={{ color: 'var(--text-h)' }}>{l.ledger}</td>
                        <td style={{ textAlign: 'right', width: 120 }}>{l.debit > 0 ? inr(l.debit) : ''}</td>
                        <td style={{ textAlign: 'right', width: 120 }}>{l.credit > 0 ? inr(l.credit) : ''}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ))}
            {(data?.vouchers || []).length === 0 && <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No vouchers in this range.</p>}
          </div>
        </>
      )}
    </div>
  )
}
