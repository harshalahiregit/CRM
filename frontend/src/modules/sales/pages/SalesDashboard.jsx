import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  IndianRupee, FileText, Receipt, AlertTriangle, TrendingUp,
  CheckCircle, CreditCard, Percent, ArrowRight, Plus,
  FileSignature, ClipboardList, Truck
} from 'lucide-react'
import { salesApi } from '@/services/salesApi'

const fmt = (v) => '₹' + Number(v).toLocaleString('en-IN')

const KPI = ({ label, value, icon: Icon, gradient, shadow, sub, onClick }) => (
  <div className={`kpi-3d relative overflow-hidden ${onClick ? 'cursor-pointer hover:scale-[1.02] transition-transform' : ''}`} onClick={onClick}>
    <div className="absolute -top-4 -right-4 w-20 h-20 rounded-full opacity-[0.07]" style={{ background: gradient }} />
    <div className="flex items-start justify-between relative z-10">
      <div className="w-11 h-11 rounded-2xl flex items-center justify-center" style={{ background: gradient, boxShadow: `0 6px 18px ${shadow}40, inset 0 1px 0 rgba(255,255,255,0.25)` }}>
        <Icon size={20} className="text-white" />
      </div>
    </div>
    <p className="text-2xl font-black mt-3 relative z-10" style={{ color: 'var(--text-h)', letterSpacing: '-0.03em' }}>{value}</p>
    <p className="text-sm font-medium relative z-10" style={{ color: 'var(--text-muted)' }}>{label}</p>
    {sub && <p className="text-xs mt-1 relative z-10" style={{ color: 'var(--text-muted)' }}>{sub}</p>}
  </div>
)

const sc = s => s === 'Paid' ? { bg: 'rgba(16,185,129,0.1)', color: '#10b981' } : s === 'Overdue' ? { bg: 'rgba(239,68,68,0.1)', color: '#f87171' } : s === 'Partially Paid' ? { bg: 'rgba(245,158,11,0.1)', color: '#fbbf24' } : s === 'Unpaid' ? { bg: 'rgba(124,58,237,0.1)', color: '#a78bfa' } : s === 'Draft' ? { bg: 'rgba(100,116,139,0.1)', color: '#94a3b8' } : { bg: 'rgba(239,68,68,0.08)', color: '#f87171' }

export default function SalesDashboard() {
  const navigate = useNavigate()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    salesApi.dashboard.get().then(d => { setData(d); setLoading(false) })
  }, [])

  if (loading) {
    return (
      <div className="space-y-6 animate-fade-in">
        <div className="skeleton h-12 w-64 rounded-xl" style={{ background: 'var(--border)' }} />
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
          {[1, 2, 3, 4].map(i => (
            <div key={i} className="skeleton h-32 rounded-2xl" style={{ background: 'var(--border)' }} />
          ))}
        </div>
      </div>
    )
  }

  const k = data.kpis
  const maxRev = Math.max(...data.revenue_by_month.map(r => r.amount), 1)

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      <div>
        <p className="label-caps mb-1">Sales & Revenue</p>
        <h1 className="font-black" style={{ fontSize: 'clamp(1.4rem,2.5vw,1.9rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
          Sales <span className="text-gradient">Overview</span>
        </h1>
        <p className="text-sm mt-0.5" style={{ color: 'var(--text-muted)' }}>Revenue tracking · real-time insights</p>
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <KPI label="Total Revenue" value={fmt(k.total_revenue)} icon={IndianRupee} gradient="linear-gradient(145deg,#9f67ff,#7C3AED)" shadow="#7C3AED" sub="This month" />
        <KPI label="Open Invoices" value={k.open_invoices} icon={Receipt} gradient="linear-gradient(145deg,#818cf8,#6366f1)" shadow="#6366f1" onClick={() => navigate('/app/sales/invoices')} />
        <KPI label="Pending Proposals" value={k.pending_proposals} icon={FileText} gradient="linear-gradient(145deg,#fcd34d,#F59E0B)" shadow="#f59e0b" onClick={() => navigate('/app/sales/proposals')} />
        <KPI label="Overdue Payments" value={k.overdue_payments} icon={AlertTriangle} gradient="linear-gradient(145deg,#f87171,#EF4444)" shadow="#ef4444" />
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <KPI label="Accepted Estimates" value={k.accepted_estimates} icon={CheckCircle} gradient="linear-gradient(145deg,#34d399,#10B981)" shadow="#10b981" />
        <KPI label="Credit Notes" value={k.credit_notes_issued} icon={CreditCard} gradient="linear-gradient(145deg,#fb923c,#f97316)" shadow="#f97316" />
        <KPI label="Conversion Rate" value={`${k.conversion_rate}%`} icon={Percent} gradient="linear-gradient(145deg,#a78bfa,#8b5cf6)" shadow="#8b5cf6" sub="Proposal to Paid" />
        <KPI label="Monthly Target" value={fmt(k.monthly_target)} icon={TrendingUp} gradient="linear-gradient(145deg,#38bdf8,#0ea5e9)" shadow="#0ea5e9" sub={`${Math.round((k.total_revenue / k.monthly_target) * 100)}% achieved`} />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <div className="card-3d lg:col-span-2" style={{ padding: '24px' }}>
          <div className="flex items-center justify-between mb-5">
            <div>
              <h2 className="font-bold text-base" style={{ color: 'var(--text-h)' }}>Revenue by Month</h2>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Last 6 months performance</p>
            </div>
          </div>
          <div className="space-y-3">
            {data.revenue_by_month.map(r => {
              const pct = Math.round((r.amount / maxRev) * 100)
              return (
                <div key={r.month} className="flex items-center gap-3">
                  <span className="text-xs font-semibold w-10 text-right flex-shrink-0" style={{ color: 'var(--text-muted)' }}>{r.month}</span>
                  <div className="flex-1 h-7 rounded-xl overflow-hidden relative" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                    <div className="h-full rounded-xl flex items-center px-3" style={{ width: `${pct}%`, background: 'linear-gradient(90deg,rgba(124,58,237,0.6),#7C3AED)', boxShadow: '0 0 10px rgba(124,58,237,0.25)' }}>
                      <span className="text-xs font-black text-white">{fmt(r.amount)}</span>
                    </div>
                    <div className="absolute inset-0 rounded-xl" style={{ background: 'linear-gradient(180deg,rgba(255,255,255,0.05) 0%,transparent 50%)' }} />
                  </div>
                </div>
              )
            })}
          </div>
        </div>

        <div className="card-3d" style={{ padding: '24px' }}>
          <h2 className="font-bold text-base mb-4" style={{ color: 'var(--text-h)' }}>Sales Pipeline</h2>
          <div className="space-y-4">
            {data.pipeline.map((p, i) => {
              const colors = ['#7C3AED', '#6366f1', '#f59e0b', '#10b981']
              const color = colors[i % colors.length]
              const icons = [FileSignature, ClipboardList, Receipt, CheckCircle]
              const Icon = icons[i % icons.length]
              return (
                <div key={p.stage} className="flex items-center gap-3 p-2.5 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                  <div className="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0" style={{ background: `${color}20` }}>
                    <Icon size={16} style={{ color }} />
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>{p.stage}</p>
                    <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{p.count} items · {fmt(p.value)}</p>
                  </div>
                  <span className="text-sm font-black flex-shrink-0" style={{ color }}>{p.count}</span>
                </div>
              )
            })}
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <div className="card-3d lg:col-span-2" style={{ padding: '24px' }}>
          <div className="flex items-center justify-between mb-4">
            <h2 className="font-bold text-base" style={{ color: 'var(--text-h)' }}>Recent Invoices</h2>
            <button onClick={() => navigate('/app/sales/invoices')} className="text-xs font-semibold flex items-center gap-1" style={{ color: '#a78bfa' }}>View all <ArrowRight size={11} /></button>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead><tr style={{ borderBottom: '1px solid var(--border)' }}>{['Invoice', 'Client', 'Amount', 'Status'].map(h => <th key={h} className="pb-2 text-left pr-3 label-caps">{h}</th>)}</tr></thead>
              <tbody>
                {data.recent_invoices.map(inv => {
                  const s = sc(inv.status)
                  return (
                    <tr key={inv.id} style={{ borderBottom: '1px solid var(--border)' }} onMouseEnter={e => e.currentTarget.style.background = 'rgba(124,58,237,0.04)'} onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                      <td className="py-2.5 pr-3 font-bold" style={{ color: '#a78bfa' }}>{inv.number}</td>
                      <td className="py-2.5 pr-3" style={{ color: 'var(--text-h)' }}>{inv.client}</td>
                      <td className="py-2.5 pr-3 font-bold" style={{ color: 'var(--text-h)' }}>{fmt(inv.amount)}</td>
                      <td className="py-2.5"><span className="px-2 py-0.5 rounded-lg text-[10px] font-bold" style={{ background: s.bg, color: s.color }}>{inv.status}</span></td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>

        <div className="space-y-4">
          <div className="card-3d" style={{ padding: '20px' }}>
            <h2 className="font-bold text-sm mb-3" style={{ color: 'var(--text-h)' }}>Top Clients</h2>
            <div className="space-y-2.5">
              {data.top_clients.map((c, i) => (
                <div key={c.name} className="flex items-center gap-2.5 p-2 rounded-xl" style={{ background: 'var(--bg-input)' }}>
                  <div className="w-8 h-8 rounded-xl flex items-center justify-center text-[10px] font-black text-white flex-shrink-0" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
                    {c.name.split(' ').map(n => n[0]).join('').slice(0, 2)}
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="text-xs font-bold truncate" style={{ color: 'var(--text-h)' }}>{c.name}</p>
                  </div>
                  <span className="text-xs font-black flex-shrink-0" style={{ color: '#a78bfa' }}>{fmt(c.revenue)}</span>
                </div>
              ))}
            </div>
          </div>

          <div className="card-3d" style={{ padding: '20px' }}>
            <h2 className="font-bold text-sm mb-3" style={{ color: 'var(--text-h)' }}>Quick Actions</h2>
            <div className="space-y-2">
              {[
                { label: 'New Proposal', path: '/app/sales/proposals', icon: FileSignature, color: '#7C3AED' },
                { label: 'New Invoice', path: '/app/sales/invoices', icon: Receipt, color: '#6366f1' },
                { label: 'Record Payment', path: '/app/sales/payments', icon: CreditCard, color: '#10b981' },
              ].map(a => (
                <button key={a.label} onClick={() => navigate(a.path)} className="w-full flex items-center gap-2.5 p-2.5 rounded-xl transition-all hover:scale-[1.01]" style={{ background: `${a.color}10`, border: `1px solid ${a.color}25` }}>
                  <div className="w-7 h-7 rounded-lg flex items-center justify-center" style={{ background: `${a.color}20` }}>
                    <a.icon size={13} style={{ color: a.color }} />
                  </div>
                  <span className="text-xs font-semibold" style={{ color: a.color }}>{a.label}</span>
                  <ArrowRight size={11} className="ml-auto" style={{ color: a.color }} />
                </button>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
