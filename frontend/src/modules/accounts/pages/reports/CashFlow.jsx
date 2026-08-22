import { useState } from 'react'
import { useInr } from '@/modules/accounts/useMoney'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Loader2, Waves } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import LoadError from '@/components/ui/LoadError'


const LABELS = { operating: 'Operating Activities', investing: 'Investing Activities', financing: 'Financing Activities' }

export default function CashFlow() {
  const inr = useInr()
  const [range, setRange] = useState({ from: '', to: '' })
  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['accounts', 'report', 'cash-flow', range],
    queryFn: () => accountsApi.reports.cashFlow(range),
  })

  const signed = (v) => (v < 0 ? `(${inr(Math.abs(v))})` : inr(v))

  return (
    <div className="space-y-5 animate-fade-in max-w-3xl">
      <Link to="/app/accounts/reports" className="flex items-center gap-1.5 text-sm font-semibold" style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={15} /> All reports
      </Link>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}><Waves size={18} style={{ color: '#a78bfa' }} /></div>
          <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Cash Flow</h1>
        </div>
        <div className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-muted)' }}>
          <input type="date" className="input-3d text-sm" value={range.from} onChange={e => setRange(r => ({ ...r, from: e.target.value }))} />
          <span>to</span>
          <input type="date" className="input-3d text-sm" value={range.to} onChange={e => setRange(r => ({ ...r, to: e.target.value }))} />
        </div>
      </div>

      {isError ? <LoadError error={error} onRetry={refetch} title="Could not load this report" />
        : isLoading ? <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div> : (
        <div className="kpi-3d">
          <div className="flex justify-between py-2" style={{ borderBottom: '1px solid var(--border)' }}>
            <span style={{ color: 'var(--text-muted)' }}>Opening cash &amp; bank</span>
            <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{inr(data?.opening_cash)}</span>
          </div>
          {(data?.sections || []).map(sec => (
            <div key={sec.key} className="py-3" style={{ borderBottom: '1px solid var(--border)' }}>
              <div className="flex justify-between">
                <span className="font-bold" style={{ color: 'var(--text-h)' }}>{LABELS[sec.key]}</span>
                <span className="font-bold" style={{ color: sec.total < 0 ? '#f87171' : '#10b981' }}>{signed(sec.total)}</span>
              </div>
              {sec.lines.map((l, i) => (
                <div key={i} className="flex justify-between text-sm mt-1 pl-3" style={{ color: 'var(--text-muted)' }}>
                  <span>{l.ledger}</span><span>{signed(l.amount)}</span>
                </div>
              ))}
              {sec.lines.length === 0 && <p className="text-xs pl-3 mt-1" style={{ color: 'var(--text-muted)' }}>No activity</p>}
            </div>
          ))}
          <div className="flex justify-between py-2 mt-1">
            <span className="font-bold" style={{ color: 'var(--text-h)' }}>Net change in cash</span>
            <span className="font-bold" style={{ color: (data?.net || 0) < 0 ? '#f87171' : '#10b981' }}>{signed(data?.net || 0)}</span>
          </div>
          <div className="flex justify-between py-2" style={{ borderTop: '2px solid var(--border)' }}>
            <span className="font-black" style={{ color: 'var(--text-h)' }}>Closing cash &amp; bank</span>
            <span className="font-black" style={{ color: 'var(--text-h)' }}>{inr(data?.closing_cash)}</span>
          </div>
        </div>
      )}
    </div>
  )
}
