import { useState } from 'react'
import { useInr } from '@/modules/accounts/useMoney'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Loader2, Hourglass } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import LoadError from '@/components/ui/LoadError'


export default function Ageing() {
  const inr = useInr()
  const [type, setType] = useState('receivable')
  const [to, setTo] = useState('')
  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['accounts', 'report', 'ageing', type, to],
    queryFn: () => accountsApi.reports.ageing({ type, ...(to ? { to } : {}) }),
  })
  const t = data?.totals

  const col = (v) => <td style={{ textAlign: 'right' }}>{v > 0 ? inr(v) : '—'}</td>

  return (
    <div className="space-y-5 animate-fade-in max-w-4xl">
      <Link to="/app/accounts/reports" className="flex items-center gap-1.5 text-sm font-semibold" style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={15} /> All reports
      </Link>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}><Hourglass size={18} style={{ color: '#a78bfa' }} /></div>
          <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Party Ageing</h1>
        </div>
        <div className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-muted)' }}>
          <div className="flex rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
            {['receivable', 'payable'].map(x => (
              <button key={x} onClick={() => setType(x)} className="px-3 py-1.5 text-sm font-bold capitalize"
                style={{ background: type === x ? 'linear-gradient(135deg,#7C3AED,#6d28d9)' : 'transparent', color: type === x ? '#fff' : 'var(--text-muted)' }}>{x}</button>
            ))}
          </div>
          As of <input type="date" className="input-3d text-sm" value={to} onChange={e => setTo(e.target.value)} />
        </div>
      </div>

      {isError ? <LoadError error={error} onRetry={refetch} title="Could not load this report" />
        : isLoading ? <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div> : (
        <div className="table-wrapper">
          <table className="table">
            <thead><tr>
              <th>{type === 'receivable' ? 'Customer' : 'Vendor'}</th>
              <th style={{ textAlign: 'right' }}>0–30</th><th style={{ textAlign: 'right' }}>31–60</th>
              <th style={{ textAlign: 'right' }}>61–90</th><th style={{ textAlign: 'right' }}>90+</th>
              <th style={{ textAlign: 'right' }}>Total</th>
            </tr></thead>
            <tbody>
              {(data?.parties || []).length === 0 && <tr><td colSpan={6} style={{ color: 'var(--text-muted)' }}>Nothing outstanding.</td></tr>}
              {(data?.parties || []).map((p, i) => (
                <tr key={i}>
                  <td style={{ color: 'var(--text-h)', fontWeight: 600 }}>{p.ledger}</td>
                  {col(p.b0_30)}{col(p.b31_60)}{col(p.b61_90)}{col(p.b90_plus)}
                  <td style={{ textAlign: 'right', fontWeight: 700, color: 'var(--text-h)' }}>{inr(p.total)}</td>
                </tr>
              ))}
              {t && (
                <tr style={{ borderTop: '2px solid var(--border)' }}>
                  <td style={{ fontWeight: 800, color: 'var(--text-h)' }}>Total</td>
                  {col(t.b0_30)}{col(t.b31_60)}{col(t.b61_90)}{col(t.b90_plus)}
                  <td style={{ textAlign: 'right', fontWeight: 800, color: 'var(--text-h)' }}>{inr(t.total)}</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
