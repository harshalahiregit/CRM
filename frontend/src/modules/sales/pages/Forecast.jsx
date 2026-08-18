import { useState, useEffect } from 'react'
import { TrendingUp, IndianRupee, Target } from 'lucide-react'
import { forecastApi } from '@/services/forecastApi'
import { useToast } from '@/hooks/useToast'

const fmt = v => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 })
const PERIODS = [['monthly', 'Monthly'], ['quarterly', 'Quarterly'], ['annual', 'Annual']]

export default function Forecast() {
  const toast = useToast()
  const [period, setPeriod] = useState('monthly')
  const [revenue, setRevenue] = useState(null)
  const [pipeline, setPipeline] = useState([])
  const [funnel, setFunnel] = useState(null)

  useEffect(() => { forecastApi.revenue(period).then(setRevenue).catch(e => toast.error(e.message)) }, [period])
  useEffect(() => {
    forecastApi.pipeline().then(setPipeline).catch(() => {})
    forecastApi.funnel().then(setFunnel).catch(() => {})
  }, [])

  const maxWeighted = Math.max(1, ...(revenue?.buckets || []).map(b => b.weighted))
  const maxStage = Math.max(1, ...pipeline.map(s => s.value))
  const maxFunnel = Math.max(1, ...(funnel?.stages || []).map(s => s.count))

  return (
    <div className="p-4 md:p-6 max-w-[1400px] mx-auto">
      <div className="mb-5">
        <p className="label-caps mb-1" style={{ color: '#a78bfa' }}>Sales &amp; Revenue</p>
        <h1 className="text-2xl font-black" style={{ color: 'var(--text-h)' }}>Forecasting</h1>
      </div>

      {/* KPI row */}
      <div className="grid grid-cols-2 lg:grid-cols-3 gap-3 mb-5">
        <div className="kpi-3d p-4"><IndianRupee size={18} style={{ color: '#10b981' }} /><p className="text-2xl font-black mt-2" style={{ color: 'var(--text-h)' }}>{fmt(revenue?.totals?.weighted)}</p><p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>Weighted Expected Revenue</p></div>
        <div className="kpi-3d p-4"><TrendingUp size={18} style={{ color: '#3b82f6' }} /><p className="text-2xl font-black mt-2" style={{ color: 'var(--text-h)' }}>{fmt(revenue?.totals?.gross)}</p><p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>Gross Pipeline Value</p></div>
        <div className="kpi-3d p-4"><Target size={18} style={{ color: '#f59e0b' }} /><p className="text-2xl font-black mt-2" style={{ color: 'var(--text-h)' }}>{funnel?.conversion_rate ?? 0}%</p><p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>Conversion Rate</p></div>
      </div>

      {/* Revenue forecast bar chart */}
      <div className="card-3d mb-5">
        <div className="flex items-center justify-between mb-4">
          <p className="label-caps">Expected Revenue by Period</p>
          <div className="flex rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
            {PERIODS.map(([k, label]) => (
              <button key={k} onClick={() => setPeriod(k)} className="px-3 py-1.5 text-xs font-bold" style={{ background: period === k ? 'var(--accent)' : 'transparent', color: period === k ? '#fff' : 'var(--text-body)' }}>{label}</button>
            ))}
          </div>
        </div>
        {(revenue?.buckets || []).length === 0 ? (
          <p className="text-xs text-center py-8" style={{ color: 'var(--text-muted)' }}>No open leads to forecast. Add lead values and expected close dates.</p>
        ) : (
          <div className="space-y-3">
            {revenue.buckets.map(b => (
              <div key={b.label}>
                <div className="flex justify-between text-xs mb-1">
                  <span style={{ color: 'var(--text-body)' }}>{b.label} <span style={{ color: 'var(--text-muted)' }}>({b.count})</span></span>
                  <span className="font-bold" style={{ color: '#10b981' }}>{fmt(b.weighted)}</span>
                </div>
                <div className="h-2.5 rounded-full" style={{ background: 'var(--border)' }}>
                  <div className="h-full rounded-full" style={{ width: `${(b.weighted / maxWeighted) * 100}%`, background: 'linear-gradient(90deg,#7C3AED,#a78bfa)' }} />
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      <div className="grid md:grid-cols-2 gap-5">
        {/* Pipeline by stage */}
        <div className="card-3d">
          <p className="label-caps mb-4">Pipeline by Stage</p>
          <div className="space-y-3">
            {pipeline.map(s => (
              <div key={s.id}>
                <div className="flex justify-between text-xs mb-1">
                  <span className="flex items-center gap-2" style={{ color: 'var(--text-body)' }}><span className="w-2 h-2 rounded-full" style={{ background: s.color }} />{s.name} <span style={{ color: 'var(--text-muted)' }}>({s.count})</span></span>
                  <span className="font-bold" style={{ color: 'var(--text-h)' }}>{fmt(s.value)}</span>
                </div>
                <div className="h-2 rounded-full" style={{ background: 'var(--border)' }}>
                  <div className="h-full rounded-full" style={{ width: `${(s.value / maxStage) * 100}%`, background: s.color }} />
                </div>
              </div>
            ))}
            {pipeline.length === 0 && <p className="text-xs text-center py-4" style={{ color: 'var(--text-muted)' }}>No pipeline data.</p>}
          </div>
        </div>

        {/* Conversion funnel */}
        <div className="card-3d">
          <p className="label-caps mb-4">Conversion Funnel</p>
          <div className="space-y-2">
            {(funnel?.stages || []).map((s, i) => (
              <div key={i} className="flex items-center gap-3">
                <span className="text-xs w-32 truncate" style={{ color: 'var(--text-body)' }}>{s.name}</span>
                <div className="flex-1 h-6 rounded-lg flex items-center px-2" style={{ width: `${(s.count / maxFunnel) * 100}%`, minWidth: 40, background: 'linear-gradient(90deg,rgba(124,58,237,0.25),rgba(124,58,237,0.12))' }}>
                  <span className="text-[11px] font-bold" style={{ color: 'var(--text-h)' }}>{s.count}</span>
                </div>
              </div>
            ))}
            {(!funnel || funnel.stages.length === 0) && <p className="text-xs text-center py-4" style={{ color: 'var(--text-muted)' }}>No funnel data.</p>}
          </div>
        </div>
      </div>
    </div>
  )
}
