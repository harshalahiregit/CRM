import { useState } from 'react'
import { useInr } from '@/modules/accounts/useMoney'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Loader2, FileCheck2 } from 'lucide-react'
import { accountsApi } from '@/services/accountsApi'


const COMPS = ['cgst', 'sgst', 'igst', 'cess']

export default function Gstr3b() {
  const inr = useInr()
  const [range, setRange] = useState({ from: '', to: '' })
  const { data, isLoading } = useQuery({ queryKey: ['accounts', 'report', 'gstr3b', range], queryFn: () => accountsApi.reports.gstr3b(range) })

  const row = (label, obj, bold) => (
    <tr style={bold ? { borderTop: '2px solid var(--border)' } : undefined}>
      <td style={{ fontWeight: bold ? 800 : 600, color: 'var(--text-h)' }}>{label}</td>
      {COMPS.map(c => <td key={c} style={{ textAlign: 'right', fontWeight: bold ? 800 : 400, color: (obj?.[c] || 0) < 0 ? '#10b981' : 'var(--text-h)' }}>{inr(obj?.[c])}</td>)}
    </tr>
  )

  return (
    <div className="space-y-5 animate-fade-in max-w-3xl">
      <Link to="/app/accounts/reports" className="flex items-center gap-1.5 text-sm font-semibold" style={{ color: 'var(--text-muted)' }}><ArrowLeft size={15} /> All reports</Link>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}><FileCheck2 size={18} style={{ color: '#a78bfa' }} /></div>
          <div><h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>GSTR-3B · Summary</h1><p className="text-xs" style={{ color: 'var(--text-muted)' }}>Output tax vs input credit vs net payable</p></div>
        </div>
        <div className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-muted)' }}>
          <input type="date" className="input-3d text-sm" value={range.from} onChange={e => setRange(r => ({ ...r, from: e.target.value }))} /><span>to</span>
          <input type="date" className="input-3d text-sm" value={range.to} onChange={e => setRange(r => ({ ...r, to: e.target.value }))} />
        </div>
      </div>
      {isLoading ? <div className="flex justify-center py-10"><Loader2 className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div> : (
        <div className="table-wrapper"><table className="table">
          <thead><tr><th></th><th style={{ textAlign: 'right' }}>CGST</th><th style={{ textAlign: 'right' }}>SGST</th><th style={{ textAlign: 'right' }}>IGST</th><th style={{ textAlign: 'right' }}>Cess</th></tr></thead>
          <tbody>
            {row('Output tax liability', data?.outward_liability)}
            {row('Input tax credit (ITC)', data?.input_tax_credit)}
            {row('Net GST payable', data?.net_payable, true)}
          </tbody>
        </table></div>
      )}
      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>A negative net figure is a carry-forward input credit. Taxable outward: {inr(data?.outward_liability?.taxable)}.</p>
    </div>
  )
}
