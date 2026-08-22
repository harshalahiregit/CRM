import { useState } from 'react'
import { useInr } from '@/modules/accounts/useMoney'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Loader2, FileText } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'
import LoadError from '@/components/ui/LoadError'


export default function Gstr1() {
  const inr = useInr()
  const [range, setRange] = useState({ from: '', to: '' })
  const { data, isLoading, isError, error, refetch } = useQuery({ queryKey: ['accounts', 'report', 'gstr1', range], queryFn: () => accountsApi.reports.gstr1(range) })
  const t = data?.totals

  return (
    <div className="space-y-5 animate-fade-in max-w-4xl">
      <Link to="/app/accounts/reports" className="flex items-center gap-1.5 text-sm font-semibold" style={{ color: 'var(--text-muted)' }}><ArrowLeft size={15} /> All reports</Link>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}><FileText size={18} style={{ color: '#a78bfa' }} /></div>
          <div><h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>GSTR-1 · Outward Supplies</h1><p className="text-xs" style={{ color: 'var(--text-muted)' }}>Rate-wise taxable value &amp; tax</p></div>
        </div>
        <div className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-muted)' }}>
          <input type="date" className="input-3d text-sm" value={range.from} onChange={e => setRange(r => ({ ...r, from: e.target.value }))} /><span>to</span>
          <input type="date" className="input-3d text-sm" value={range.to} onChange={e => setRange(r => ({ ...r, to: e.target.value }))} />
        </div>
      </div>
      {isError ? <LoadError error={error} onRetry={refetch} title="Could not load this report" />
        : isLoading ? <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div> : (
        <div className="table-wrapper"><table className="table">
          <thead><tr><th>Rate</th><th style={{ textAlign: 'right' }}>Taxable</th><th style={{ textAlign: 'right' }}>CGST</th><th style={{ textAlign: 'right' }}>SGST</th><th style={{ textAlign: 'right' }}>IGST</th><th style={{ textAlign: 'right' }}>Cess</th></tr></thead>
          <tbody>
            {(data?.rows || []).length === 0 && <tr><td colSpan={6} style={{ color: 'var(--text-muted)' }}>No outward supplies in range.</td></tr>}
            {(data?.rows || []).map((r, i) => (
              <tr key={i}><td style={{ color: 'var(--text-h)', fontWeight: 600 }}>{r.rate}%</td><td style={{ textAlign: 'right' }}>{inr(r.taxable)}</td><td style={{ textAlign: 'right' }}>{inr(r.cgst)}</td><td style={{ textAlign: 'right' }}>{inr(r.sgst)}</td><td style={{ textAlign: 'right' }}>{inr(r.igst)}</td><td style={{ textAlign: 'right' }}>{inr(r.cess)}</td></tr>
            ))}
            {t && <tr style={{ borderTop: '2px solid var(--border)' }}><td style={{ fontWeight: 800, color: 'var(--text-h)' }}>Total</td><td style={{ textAlign: 'right', fontWeight: 800 }}>{inr(t.taxable)}</td><td style={{ textAlign: 'right', fontWeight: 800 }}>{inr(t.cgst)}</td><td style={{ textAlign: 'right', fontWeight: 800 }}>{inr(t.sgst)}</td><td style={{ textAlign: 'right', fontWeight: 800 }}>{inr(t.igst)}</td><td style={{ textAlign: 'right', fontWeight: 800 }}>{inr(t.cess)}</td></tr>}
          </tbody>
        </table></div>
      )}
    </div>
  )
}
