import { Link } from 'react-router-dom'
import { Scale, TrendingUp, BookOpen, FileBarChart2, BarChart3, CalendarDays, Waves, Hourglass, Library, FileText, FileCheck2, Percent } from 'lucide-react'

const REPORTS = [
  { to: 'trial-balance', icon: Scale, title: 'Trial Balance', desc: 'Every ledger’s closing balance; debits must equal credits.' },
  { to: 'profit-loss', icon: TrendingUp, title: 'Profit & Loss', desc: 'Income minus expenses over a chosen period.' },
  { to: 'balance-sheet', icon: FileBarChart2, title: 'Balance Sheet', desc: 'Assets, liabilities and equity as of a date.' },
  { to: 'cash-flow', icon: Waves, title: 'Cash Flow', desc: 'Cash movement classified operating / investing / financing.' },
  { to: 'general-ledger', icon: Library, title: 'General Ledger', desc: 'Every ledger’s postings with running balances.' },
  { to: 'ledger-statement', icon: BookOpen, title: 'Ledger Statement', desc: 'Running balance of a single ledger over time.' },
  { to: 'day-book', icon: CalendarDays, title: 'Day Book', desc: 'All vouchers for a day or date range.' },
  { to: 'ageing', icon: Hourglass, title: 'Party Ageing', desc: 'Receivables & payables bucketed by age.' },
  { to: 'gstr-1', icon: FileText, title: 'GSTR-1', desc: 'Outward supplies, rate-wise taxable & tax.' },
  { to: 'gstr-3b', icon: FileCheck2, title: 'GSTR-3B', desc: 'Output tax vs input credit vs net payable.' },
  { to: 'tds', icon: Percent, title: 'TDS Summary', desc: 'Tax deducted at source, by section (26Q).' },
]

export default function ReportsIndex() {
  return (
    <div className="space-y-5 animate-fade-in">
      <div className="flex items-center gap-3">
        <div className="w-10 h-10 rounded-2xl flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.12)' }}>
          <BarChart3 size={18} style={{ color: '#a78bfa' }} />
        </div>
        <div>
          <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Reports</h1>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>All figures are derived live from the ledger</p>
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {REPORTS.map(({ to, icon: Icon, title, desc }) => (
          <Link key={to} to={to} className="kpi-3d block hover:-translate-y-0.5 transition-transform">
            <div className="w-11 h-11 rounded-2xl flex items-center justify-center mb-3" style={{ background: 'rgba(124,58,237,0.12)' }}>
              <Icon size={19} style={{ color: '#a78bfa' }} />
            </div>
            <h3 className="text-base font-black" style={{ color: 'var(--text-h)' }}>{title}</h3>
            <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{desc}</p>
          </Link>
        ))}
      </div>
    </div>
  )
}
