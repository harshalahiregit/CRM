import { useState, useEffect, useMemo, useCallback } from 'react'
import {
  LayoutDashboard, Users, Building2, PieChart, TrendingUp,
  FileDown, FileText, Sparkles, IndianRupee, Wallet,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'

const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'
const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December']
const inr = v => `₹${Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`
const TYPE_C = { Earning:{c:'#10b981',bg:'rgba(16,185,129,0.12)'}, Deduction:{c:'#f87171',bg:'rgba(239,68,68,0.1)'}, Benefit:{c:'#3b82f6',bg:'rgba(59,130,246,0.12)'} }

const SUBS = [
  { key:'overview',   label:'Overview',            icon:LayoutDashboard },
  { key:'employees',  label:'Employee Report',     icon:Users },
  { key:'departments',label:'Department Report',   icon:Building2 },
  { key:'components', label:'Component Analysis',  icon:PieChart },
  { key:'trends',     label:'Trends',              icon:TrendingUp },
]

export default function PayrollReports({ showToast }) {
  const [sub, setSub] = useState('overview')
  const [opts, setOpts] = useState({ years:[], departments:[], designations:[], employees:[] })
  const [filters, setFilters] = useState({ year:'', month:'', department:'', designation:'', employee_id:'' })

  useEffect(() => { hrApi.payroll.reports.filters().then(setOpts).catch(() => {}) }, [])

  const params = useMemo(() => {
    const p = {}
    Object.entries(filters).forEach(([k, v]) => { if (v && v !== 'All') p[k] = v })
    return p
  }, [filters])

  const set = (k, v) => setFilters(f => ({ ...f, [k]: v }))
  const hasFilters = Object.keys(params).length > 0

  return (
    <div className="space-y-4">
      {/* Filter bar */}
      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="min-w-[110px]"><label className="label">Year</label>
            <select className="input-3d text-sm" value={filters.year} onChange={e=>set('year',e.target.value)}><option value="">All</option>{opts.years.map(y=><option key={y} value={y}>{y}</option>)}</select>
          </div>
          <div className="min-w-[130px]"><label className="label">Month</label>
            <select className="input-3d text-sm" value={filters.month} onChange={e=>set('month',e.target.value)}><option value="">All</option>{MONTHS.map((m,i)=><option key={m} value={i+1}>{m}</option>)}</select>
          </div>
          <div className="min-w-[150px]"><label className="label">Department</label>
            <select className="input-3d text-sm" value={filters.department} onChange={e=>set('department',e.target.value)}><option value="">All</option>{opts.departments.map(d=><option key={d} value={d}>{d}</option>)}</select>
          </div>
          <div className="min-w-[150px]"><label className="label">Designation</label>
            <select className="input-3d text-sm" value={filters.designation} onChange={e=>set('designation',e.target.value)}><option value="">All</option>{opts.designations.map(d=><option key={d} value={d}>{d}</option>)}</select>
          </div>
          <div className="min-w-[170px]"><label className="label">Employee</label>
            <select className="input-3d text-sm" value={filters.employee_id} onChange={e=>set('employee_id',e.target.value)}><option value="">All</option>{opts.employees.map(e=><option key={e.id} value={e.id}>{e.name}</option>)}</select>
          </div>
          {hasFilters && <button onClick={()=>setFilters({ year:'', month:'', department:'', designation:'', employee_id:'' })} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
        </div>
      </div>

      {/* Sub-tabs */}
      <div className="flex gap-1.5 flex-wrap">
        {SUBS.map(t => {
          const active = sub === t.key
          return (
            <button key={t.key} onClick={()=>setSub(t.key)} className="flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold transition-all"
              style={{ background: active ? GRAD : 'var(--bg-input)', color: active ? '#fff' : 'var(--text-muted)', border: active ? 'none' : '1px solid var(--border)' }}>
              <t.icon size={14}/> {t.label}
            </button>
          )
        })}
      </div>

      {sub === 'overview'    && <Overview params={params} showToast={showToast} />}
      {sub === 'employees'   && <EmployeeReport params={params} showToast={showToast} />}
      {sub === 'departments' && <DepartmentReport params={params} showToast={showToast} />}
      {sub === 'components'  && <ComponentReport params={params} showToast={showToast} />}
      {sub === 'trends'      && <TrendsReport params={params} showToast={showToast} />}
    </div>
  )
}

/* Shared data hook — refetch whenever the filters change. */
function useReport(fetcher, params, showToast) {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const key = JSON.stringify(params)
  const load = useCallback(() => {
    setLoading(true)
    fetcher(params).then(setData).catch(() => showToast('Failed to load report', 'error')).finally(() => setLoading(false))
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key])
  useEffect(() => { load() }, [load])
  return { data, loading }
}

/* Export buttons (CSV = Excel, PDF). */
function ExportButtons({ report, params }) {
  const [busy, setBusy] = useState(null)
  const run = async (format) => {
    setBusy(format)
    try { await hrApi.payroll.reports.export(report, format, params) } catch { /* ignore */ }
    finally { setBusy(null) }
  }
  return (
    <div className="flex gap-2">
      <button onClick={()=>run('csv')} disabled={busy} className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><FileDown size={13}/> {busy==='csv'?'…':'Excel'}</button>
      <button onClick={()=>run('pdf')} disabled={busy} className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><FileText size={13}/> {busy==='pdf'?'…':'PDF'}</button>
    </div>
  )
}

/* ── Overview: KPI cards + AI placeholders ── */
function Overview({ params, showToast }) {
  const { data, loading } = useReport(hrApi.payroll.reports.summary, params, showToast)
  if (loading || !data) return <HrLoading label="Loading payroll summary…" />

  const KPIS = [
    { l:'Total Payroll Cost', v:inr(data.total_payroll_cost), c:'#7C3AED', I:Wallet },
    { l:'Employees Paid', v:data.employees_paid, c:'#0ea5e9', I:Users },
    { l:'Average Salary', v:inr(data.average_salary), c:'#8b5cf6', I:IndianRupee },
    { l:'Total Earnings', v:inr(data.total_earnings), c:'#10b981', I:TrendingUp },
    { l:'Total Deductions', v:inr(data.total_deductions), c:'#f87171', I:PieChart },
    { l:'Total Benefits', v:inr(data.total_benefits), c:'#3b82f6', I:Building2 },
  ]
  const AI = [
    'Salary cost increase detection', 'High-deduction employees', 'Payroll anomaly detection',
    'Department cost comparison', 'Salary optimization suggestions',
  ]

  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
        {KPIS.map(k => (
          <div key={k.l} className="kpi-3d">
            <div className="flex items-center justify-between"><p className="text-2xl font-black" style={{ color:k.c }}>{k.v}</p><k.I size={16} style={{ color:k.c, opacity:0.6 }}/></div>
            <p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p>
          </div>
        ))}
      </div>

      {/* AI Payroll Insights — placeholders only (architecture ready for future local models). */}
      <div>
        <p className="text-[11px] font-bold uppercase mb-2 flex items-center gap-1.5" style={{ color:'#a78bfa', letterSpacing:'0.04em' }}><Sparkles size={13}/> AI Payroll Insights</p>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
          {AI.map(t => (
            <div key={t} className="rounded-xl p-3.5" style={{ background:'rgba(124,58,237,0.05)', border:'1px dashed rgba(124,58,237,0.3)' }}>
              <div className="flex items-start gap-2">
                <Sparkles size={14} style={{ color:'#a78bfa', marginTop:1, flexShrink:0 }}/>
                <div>
                  <p className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{t}</p>
                  <p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}>AI insights coming soon</p>
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}

/* ── Employee-wise summary report ── */
function EmployeeReport({ params, showToast }) {
  const { data, loading } = useReport(hrApi.payroll.reports.employees, params, showToast)
  if (loading) return <HrLoading label="Loading employee report…" />
  const rows = data || []
  return (
    <div className="space-y-3">
      <div className="flex justify-end"><ExportButtons report="summary" params={params} /></div>
      {rows.length === 0 ? <HrEmpty icon={Users} title="No payroll data" hint="No processed payroll matches the current filters." />
        : (
          <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:900 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Department','Designation','Structure','Gross','Benefits','Deductions','Net','Payslip'].map(h=><th key={h} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
              <tbody>
                {rows.map((r,i) => (
                  <tr key={i} style={{ borderBottom:'1px solid var(--border)' }}>
                    <td className="px-3 py-2.5"><span className="font-bold" style={{ color:'var(--text-h)' }}>{r.employee_name}</span> <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code}</span></td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.department||'—'}</td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.designation||'—'}</td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.structure_name||'—'}</td>
                    <td className="px-3 py-2.5 font-semibold" style={{ color:'#10b981' }}>{inr(r.gross_salary)}</td>
                    <td className="px-3 py-2.5" style={{ color:'#3b82f6' }}>{inr(r.total_benefits)}</td>
                    <td className="px-3 py-2.5" style={{ color:'#f87171' }}>{inr(r.total_deductions)}</td>
                    <td className="px-3 py-2.5 font-black" style={{ color:'var(--text-h)' }}>{inr(r.net_salary)}</td>
                    <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={r.payslip_status==='Generated'?{background:'rgba(16,185,129,0.12)',color:'#10b981'}:{background:'var(--bg-input)',color:'var(--text-muted)'}}>{r.payslip_status}</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
    </div>
  )
}

/* ── Department-wise report + bar viz ── */
function DepartmentReport({ params, showToast }) {
  const { data, loading } = useReport(hrApi.payroll.reports.departments, params, showToast)
  if (loading) return <HrLoading label="Loading department report…" />
  const rows = data || []
  const max = Math.max(1, ...rows.map(r => r.net_payroll_cost))
  return (
    <div className="space-y-3">
      <div className="flex justify-end"><ExportButtons report="departments" params={params} /></div>
      {rows.length === 0 ? <HrEmpty icon={Building2} title="No department data" hint="No processed payroll matches the current filters." />
        : (
          <>
            <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
              <table className="w-full text-sm" style={{ minWidth:720 }}>
                <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Department','Employees','Gross','Benefits','Deductions','Net Payroll Cost'].map(h=><th key={h} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
                <tbody>
                  {rows.map((r,i) => (
                    <tr key={i} style={{ borderBottom:'1px solid var(--border)' }}>
                      <td className="px-3 py-2.5 font-bold" style={{ color:'var(--text-h)' }}>{r.department}</td>
                      <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.employees}</td>
                      <td className="px-3 py-2.5" style={{ color:'#10b981' }}>{inr(r.gross_salary)}</td>
                      <td className="px-3 py-2.5" style={{ color:'#3b82f6' }}>{inr(r.total_benefits)}</td>
                      <td className="px-3 py-2.5" style={{ color:'#f87171' }}>{inr(r.total_deductions)}</td>
                      <td className="px-3 py-2.5 font-black" style={{ color:'#7C3AED' }}>{inr(r.net_payroll_cost)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="card-3d" style={{ padding:'18px' }}>
              <p className="text-[11px] font-bold uppercase mb-3" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Net Payroll Cost by Department</p>
              <div className="space-y-2.5">
                {rows.map((r,i) => (
                  <div key={i}>
                    <div className="flex justify-between mb-1"><span className="text-xs font-semibold" style={{ color:'var(--text-muted)' }}>{r.department}</span><span className="text-xs font-black" style={{ color:'#7C3AED' }}>{inr(r.net_payroll_cost)}</span></div>
                    <div className="h-2 rounded-full" style={{ background:'var(--bg-input)' }}><div className="h-full rounded-full" style={{ width:`${Math.round(r.net_payroll_cost/max*100)}%`, background:GRAD }}/></div>
                  </div>
                ))}
              </div>
            </div>
          </>
        )}
    </div>
  )
}

/* ── Component analysis + contribution bars ── */
function ComponentReport({ params, showToast }) {
  const { data, loading } = useReport(hrApi.payroll.reports.components, params, showToast)
  if (loading) return <HrLoading label="Loading component analysis…" />
  const rows = data?.components || []
  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <p className="text-xs" style={{ color:'var(--text-muted)' }}>Grand total: <b style={{ color:'var(--text-h)' }}>{inr(data?.grand_total)}</b></p>
        <ExportButtons report="components" params={params} />
      </div>
      {rows.length === 0 ? <HrEmpty icon={PieChart} title="No component data" hint="Generate payslips to populate the component breakdown." />
        : (
          <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:640 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Component','Type','Total Amount','Employees','Contribution'].map(h=><th key={h} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
              <tbody>
                {rows.map((r,i) => {
                  const tc = TYPE_C[r.type] || {}
                  return (
                    <tr key={i} style={{ borderBottom:'1px solid var(--border)' }}>
                      <td className="px-3 py-2.5 font-bold" style={{ color:'var(--text-h)' }}>{r.component}</td>
                      <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:tc.bg, color:tc.c }}>{r.type}</span></td>
                      <td className="px-3 py-2.5 font-semibold" style={{ color:'var(--text-h)' }}>{inr(r.total_amount)}</td>
                      <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.employee_count}</td>
                      <td className="px-3 py-2.5" style={{ minWidth:160 }}>
                        <div className="flex items-center gap-2">
                          <div className="flex-1 h-1.5 rounded-full" style={{ background:'var(--bg-input)' }}><div className="h-full rounded-full" style={{ width:`${r.percentage}%`, background:tc.c||'#7C3AED' }}/></div>
                          <span className="text-[11px] font-bold" style={{ color:tc.c||'var(--text-h)' }}>{r.percentage}%</span>
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
    </div>
  )
}

/* ── Trends: inline SVG bar charts ── */
function TrendsReport({ params, showToast }) {
  const { data, loading } = useReport(hrApi.payroll.reports.trends, params, showToast)
  if (loading) return <HrLoading label="Loading trends…" />
  const series = data || []
  if (series.length === 0) return <HrEmpty icon={TrendingUp} title="No trend data" hint="Process payroll runs across months to see trends." />

  const CHARTS = [
    { key:'payroll_cost', label:'Payroll Cost Trend', color:'#7C3AED', money:true },
    { key:'employee_count', label:'Employee Count Trend', color:'#0ea5e9', money:false },
    { key:'deductions', label:'Deduction Trend', color:'#f87171', money:true },
    { key:'benefits', label:'Benefit Trend', color:'#3b82f6', money:true },
  ]

  return (
    <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
      {CHARTS.map(c => <BarChart key={c.key} series={series} dataKey={c.key} label={c.label} color={c.color} money={c.money} />)}
    </div>
  )
}

function BarChart({ series, dataKey, label, color, money }) {
  const max = Math.max(1, ...series.map(d => d[dataKey]))
  const W = 460, H = 150, pad = 8, n = series.length
  const bw = Math.min(48, (W - pad * 2) / n - 8)
  return (
    <div className="card-3d" style={{ padding:'18px' }}>
      <p className="text-[11px] font-bold uppercase mb-3" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>{label}</p>
      <svg viewBox={`0 0 ${W} ${H + 28}`} width="100%" preserveAspectRatio="xMidYMid meet">
        {series.map((d, i) => {
          const x = pad + i * ((W - pad * 2) / n) + ((W - pad * 2) / n - bw) / 2
          const h = Math.round((d[dataKey] / max) * H)
          const y = H - h
          return (
            <g key={i}>
              <rect x={x} y={y} width={bw} height={h} rx={4} fill={color} opacity={0.85} />
              <text x={x + bw / 2} y={y - 4} textAnchor="middle" fontSize="9" fill="var(--text-muted)">{money ? `₹${(d[dataKey]/1000).toFixed(d[dataKey]>=100000?0:1)}k` : d[dataKey]}</text>
              <text x={x + bw / 2} y={H + 14} textAnchor="middle" fontSize="8.5" fill="var(--text-muted)">{d.period}</text>
            </g>
          )
        })}
      </svg>
    </div>
  )
}
