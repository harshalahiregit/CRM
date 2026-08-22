import { useState } from 'react'
import { useInr } from '@/modules/accounts/useMoney'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Loader2, Percent } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import LoadError from '@/components/ui/LoadError'


export default function Tds() {
  const inr = useInr()
  const [range, setRange] = useState({ from: '', to: '' })
  const { data, isLoading, isError, error, refetch } = useQuery({ queryKey: ['accounts', 'report', 'tds', range], queryFn: () => accountsApi.reports.tds(range) })

  return (
    <div className="space-y-5 animate-fade-in max-w-3xl">
      <Link to="/app/accounts/reports" className="flex items-center gap-1.5 text-sm font-semibold" style={{ color: 'var(--text-muted)' }}><ArrowLeft size={15} /> All reports</Link>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}><Percent size={18} style={{ color: '#a78bfa' }} /></div>
          <div><h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>TDS Summary</h1><p className="text-xs" style={{ color: 'var(--text-muted)' }}>Tax deducted at source, by section (26Q)</p></div>
        </div>
        <div className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-muted)' }}>
          <input type="date" className="input-3d text-sm" value={range.from} onChange={e => setRange(r => ({ ...r, from: e.target.value }))} /><span>to</span>
          <input type="date" className="input-3d text-sm" value={range.to} onChange={e => setRange(r => ({ ...r, to: e.target.value }))} />
        </div>
      </div>
      {isError ? <LoadError error={error} onRetry={refetch} title="Could not load this report" />
        : isLoading ? <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div> : (
        <>
          <div className="table-wrapper"><table className="table">
            <thead><tr><th>Section</th><th style={{ textAlign: 'right' }}>Deductions</th><th style={{ textAlign: 'right' }}>Base amount</th><th style={{ textAlign: 'right' }}>TDS</th></tr></thead>
            <tbody>
              {(data?.sections || []).length === 0 && <tr><td colSpan={4} style={{ color: 'var(--text-muted)' }}>No TDS deducted in range.</td></tr>}
              {(data?.sections || []).map((s, i) => (
                <tr key={i}><td style={{ color: 'var(--text-h)', fontWeight: 600 }}>{s.section}</td><td style={{ textAlign: 'right' }}>{s.deductions}</td><td style={{ textAlign: 'right' }}>{inr(s.base)}</td><td style={{ textAlign: 'right' }}>{inr(s.tds)}</td></tr>
              ))}
            </tbody>
          </table></div>
          <div className="kpi-3d flex items-center justify-between"><span className="font-black" style={{ color: 'var(--text-h)' }}>Total TDS deducted</span><span className="text-xl font-black" style={{ color: 'var(--text-h)' }}>{inr(data?.total_tds)}</span></div>
        </>
      )}
    </div>
  )
}
