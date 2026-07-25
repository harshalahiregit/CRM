import { useState, useEffect, useMemo, useCallback } from 'react'
import {
  LayoutDashboard, Users, Building2, PieChart, Wallet, CalendarRange, TrendingUp,
  FileDown, FileText, Sparkles, CalendarCheck, CheckSquare,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'

const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'
const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December']
const HOL_C = { National:'#f87171', Festival:'#8b5cf6', Company:'#3b82f6', Optional:'#f59e0b' }
const APP_C = { Approved:'#10b981', Submitted:'#f59e0b', Rejected:'#f87171', Cancelled:'#94a3b8', Draft:'#94a3b8' }

const SUBS = [
  { key:'dashboard',   label:'Dashboard',       icon:LayoutDashboard },
  { key:'employees',   label:'Employee Report', icon:Users },
  { key:'departments', label:'Department',      icon:Building2 },
  { key:'types',       label:'Leave Type',      icon:PieChart },
  { key:'balances',    label:'Balance Report',  icon:Wallet },
  { key:'holidays',    label:'Holidays',        icon:CalendarRange },
  { key:'trends',      label:'Trends',          icon:TrendingUp },
]

export default function LeaveReports({ showToast }) {
  const [sub, setSub] = useState('dashboard')
  const [opts, setOpts] = useState({ years:[], departments:[], designations:[], employees:[], leave_types:[] })
  const [filters, setFilters] = useState({ year:'', month:'', employee_id:'', department:'', designation:'', leave_type_id:'', status:'' })

  useEffect(() => { hrApi.leave.reports.filters().then(setOpts).catch(() => {}) }, [])
  const params = useMemo(() => {
    const p = {}; Object.entries(filters).forEach(([k, v]) => { if (v && v !== 'All') p[k] = v }); return p
  }, [filters])
  const set = (k, v) => setFilters(f => ({ ...f, [k]: v }))
  const hasF = Object.keys(params).length > 0

  return (
    <div className="space-y-4">
      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="min-w-[100px]"><label className="label">Year</label><select className="input-3d text-sm" value={filters.year} onChange={e=>set('year',e.target.value)}><option value="">All</option>{opts.years.map(y=><option key={y} value={y}>{y}</option>)}</select></div>
          <div className="min-w-[120px]"><label className="label">Month</label><select className="input-3d text-sm" value={filters.month} onChange={e=>set('month',e.target.value)}><option value="">All</option>{MONTHS.map((m,i)=><option key={m} value={i+1}>{m}</option>)}</select></div>
          <div className="min-w-[150px]"><label className="label">Department</label><select className="input-3d text-sm" value={filters.department} onChange={e=>set('department',e.target.value)}><option value="">All</option>{opts.departments.map(d=><option key={d} value={d}>{d}</option>)}</select></div>
          <div className="min-w-[150px]"><label className="label">Designation</label><select className="input-3d text-sm" value={filters.designation} onChange={e=>set('designation',e.target.value)}><option value="">All</option>{opts.designations.map(d=><option key={d} value={d}>{d}</option>)}</select></div>
          <div className="min-w-[150px]"><label className="label">Leave Type</label><select className="input-3d text-sm" value={filters.leave_type_id} onChange={e=>set('leave_type_id',e.target.value)}><option value="">All</option>{opts.leave_types.map(t=><option key={t.id} value={t.id}>{t.name}</option>)}</select></div>
          <div className="min-w-[160px]"><label className="label">Employee</label><select className="input-3d text-sm" value={filters.employee_id} onChange={e=>set('employee_id',e.target.value)}><option value="">All</option>{opts.employees.map(e=><option key={e.id} value={e.id}>{e.name}</option>)}</select></div>
          <div className="min-w-[130px]"><label className="label">Status</label><select className="input-3d text-sm" value={filters.status} onChange={e=>set('status',e.target.value)}><option value="">All</option>{['Submitted','Approved','Rejected','Cancelled'].map(s=><option key={s}>{s}</option>)}</select></div>
          {hasF && <button onClick={()=>setFilters({ year:'', month:'', employee_id:'', department:'', designation:'', leave_type_id:'', status:'' })} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
        </div>
      </div>

      <div className="flex gap-1.5 flex-wrap">
        {SUBS.map(t => { const active = sub === t.key; return (
          <button key={t.key} onClick={()=>setSub(t.key)} className="flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold transition-all"
            style={{ background: active ? GRAD : 'var(--bg-input)', color: active ? '#fff' : 'var(--text-muted)', border: active ? 'none' : '1px solid var(--border)' }}>
            <t.icon size={14}/> {t.label}
          </button>
        )})}
      </div>

      {sub === 'dashboard'   && <Dashboard showToast={showToast} />}
      {sub === 'employees'   && <EmployeeReport params={params} showToast={showToast} />}
      {sub === 'departments' && <DepartmentReport params={params} showToast={showToast} />}
      {sub === 'types'       && <TypeReport params={params} showToast={showToast} />}
      {sub === 'balances'    && <BalanceReport params={params} showToast={showToast} />}
      {sub === 'holidays'    && <HolidayReport params={params} showToast={showToast} />}
      {sub === 'trends'      && <TrendsReport params={params} showToast={showToast} />}
    </div>
  )
}

function useReport(fetcher, params, showToast) {
  const [data, setData] = useState(null); const [loading, setLoading] = useState(true)
  const key = JSON.stringify(params)
  const load = useCallback(() => { setLoading(true); fetcher(params).then(setData).catch(() => showToast('Failed to load report', 'error')).finally(() => setLoading(false)) // eslint-disable-line
  }, [key])
  useEffect(() => { load() }, [load])
  return { data, loading }
}

function ExportButtons({ report, params }) {
  const [busy, setBusy] = useState(null)
  const run = async (fmt) => { setBusy(fmt); try { await hrApi.leave.reports.export(report, fmt, params) } catch { /* ignore */ } finally { setBusy(null) } }
  return (
    <div className="flex gap-2">
      <button onClick={()=>run('csv')} disabled={busy} className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><FileDown size={13}/> {busy==='csv'?'…':'Excel'}</button>
      <button onClick={()=>run('pdf')} disabled={busy} className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><FileText size={13}/> {busy==='pdf'?'…':'PDF'}</button>
    </div>
  )
}

/* ── Dashboard ── */
function Dashboard({ showToast }) {
  const [d, setD] = useState(null)
  useEffect(() => { hrApi.leave.reports.dashboard().then(setD).catch(() => showToast('Failed to load dashboard', 'error')) }, [showToast])
  if (!d) return <HrLoading label="Loading leave dashboard…" />
  const KPIS = [
    { l:'Total Applications', v:d.total_applications, c:'#7C3AED' }, { l:'Approved', v:d.approved, c:'#10b981' },
    { l:'Pending', v:d.pending, c:'#f59e0b' }, { l:'Rejected', v:d.rejected, c:'#f87171' },
    { l:'Cancelled', v:d.cancelled, c:'#94a3b8' }, { l:'On Leave Today', v:d.on_leave_today, c:'#0ea5e9' },
    { l:'Upcoming Holidays', v:d.upcoming_holidays, c:'#8b5cf6' }, { l:'Leave Utilization', v:`${d.utilization}%`, c:'#ec4899' },
  ]
  const AI = ['Leave Trend Insights','High Leave Utilization','Department Leave Risk','Carry Forward Suggestions','Workforce Availability']
  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {KPIS.map(k=><div key={k.l} className="kpi-3d"><p className="text-2xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}
      </div>
      <div>
        <p className="text-[11px] font-bold uppercase mb-2 flex items-center gap-1.5" style={{ color:'#a78bfa', letterSpacing:'0.04em' }}><Sparkles size={13}/> AI Leave Insights</p>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
          {AI.map(t=><div key={t} className="rounded-xl p-3.5 flex items-start gap-2" style={{ background:'rgba(124,58,237,0.05)', border:'1px dashed rgba(124,58,237,0.3)' }}><Sparkles size={14} style={{ color:'#a78bfa', marginTop:1, flexShrink:0 }}/><div><p className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{t}</p><p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}>AI Insights Coming Soon</p></div></div>)}
        </div>
      </div>
    </div>
  )
}

/* ── Employee Report ── */
function EmployeeReport({ params, showToast }) {
  const { data, loading } = useReport(hrApi.leave.reports.employees, params, showToast)
  if (loading) return <HrLoading label="Loading employee report…" />
  const rows = data || []
  return (
    <div className="space-y-3">
      <div className="flex justify-end"><ExportButtons report="employees" params={params} /></div>
      {rows.length===0 ? <HrEmpty icon={Users} title="No leave applications" hint="No data matches the current filters." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:900 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Department','Designation','Leave Type','Applied','Approved','Remaining','Status'].map(h=><th key={h} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
              <tbody>{rows.map((r,i)=>(
                <tr key={i} style={{ borderBottom:'1px solid var(--border)' }}>
                  <td className="px-3 py-2.5"><span className="font-bold" style={{ color:'var(--text-h)' }}>{r.employee_name}</span> <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code}</span></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.department||'—'}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.designation||'—'}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-h)' }}>{r.leave_type}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.applied_days}</td>
                  <td className="px-3 py-2.5 font-bold" style={{ color:'#10b981' }}>{r.approved_days}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-h)' }}>{r.remaining ?? '—'}</td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${APP_C[r.status]||'#7C3AED'}1f`, color:APP_C[r.status]||'#7C3AED' }}>{r.status}</span></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}
    </div>
  )
}

/* ── Department Report + bar chart ── */
function DepartmentReport({ params, showToast }) {
  const { data, loading } = useReport(hrApi.leave.reports.departments, params, showToast)
  if (loading) return <HrLoading label="Loading department report…" />
  const rows = data || []; const max = Math.max(1, ...rows.map(r=>r.total))
  return (
    <div className="space-y-3">
      <div className="flex justify-end"><ExportButtons report="departments" params={params} /></div>
      {rows.length===0 ? <HrEmpty icon={Building2} title="No department data" hint="No data matches the current filters." />
        : <>
            <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
              <table className="w-full text-sm" style={{ minWidth:720 }}>
                <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Department','Applications','Approved','Pending','Rejected','On Leave','Utilization'].map(h=><th key={h} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
                <tbody>{rows.map((r,i)=>(
                  <tr key={i} style={{ borderBottom:'1px solid var(--border)' }}>
                    <td className="px-3 py-2.5 font-bold" style={{ color:'var(--text-h)' }}>{r.department}</td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.total}</td>
                    <td className="px-3 py-2.5" style={{ color:'#10b981' }}>{r.approved}</td>
                    <td className="px-3 py-2.5" style={{ color:'#f59e0b' }}>{r.pending}</td>
                    <td className="px-3 py-2.5" style={{ color:'#f87171' }}>{r.rejected}</td>
                    <td className="px-3 py-2.5" style={{ color:'#0ea5e9' }}>{r.employees_on_leave}</td>
                    <td className="px-3 py-2.5 font-black" style={{ color:'#7C3AED' }}>{r.utilization}%</td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
            <div className="card-3d" style={{ padding:'18px' }}>
              <p className="text-[11px] font-bold uppercase mb-3" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Applications by Department</p>
              <div className="space-y-2.5">{rows.map((r,i)=>(
                <div key={i}><div className="flex justify-between mb-1"><span className="text-xs font-semibold" style={{ color:'var(--text-muted)' }}>{r.department}</span><span className="text-xs font-black" style={{ color:'#7C3AED' }}>{r.total}</span></div><div className="h-2 rounded-full" style={{ background:'var(--bg-input)' }}><div className="h-full rounded-full" style={{ width:`${Math.round(r.total/max*100)}%`, background:GRAD }}/></div></div>
              ))}</div>
            </div>
          </>}
    </div>
  )
}

/* ── Leave Type Analysis ── */
function TypeReport({ params, showToast }) {
  const { data, loading } = useReport(hrApi.leave.reports.types, params, showToast)
  if (loading) return <HrLoading label="Loading leave type analysis…" />
  const rows = data || []
  return (
    <div className="space-y-3">
      <div className="flex justify-end"><ExportButtons report="types" params={params} /></div>
      {rows.length===0 ? <HrEmpty icon={PieChart} title="No leave type data" hint="Assign policies to create balances." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:680 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Leave Type','Allocated','Used','Remaining','Carry Fwd','Utilization'].map(h=><th key={h} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
              <tbody>{rows.map((r,i)=>(
                <tr key={i} style={{ borderBottom:'1px solid var(--border)' }}>
                  <td className="px-3 py-2.5 font-bold" style={{ color:'var(--text-h)' }}>{r.leave_type}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.allocated}</td>
                  <td className="px-3 py-2.5" style={{ color:'#f87171' }}>{r.used}</td>
                  <td className="px-3 py-2.5" style={{ color:'#10b981' }}>{r.remaining}</td>
                  <td className="px-3 py-2.5" style={{ color:'#3b82f6' }}>{r.carry_forward}</td>
                  <td className="px-3 py-2.5" style={{ minWidth:150 }}><div className="flex items-center gap-2"><div className="flex-1 h-1.5 rounded-full" style={{ background:'var(--bg-input)' }}><div className="h-full rounded-full" style={{ width:`${Math.min(100,r.utilization)}%`, background:GRAD }}/></div><span className="text-[11px] font-bold" style={{ color:'#7C3AED' }}>{r.utilization}%</span></div></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}
    </div>
  )
}

/* ── Leave Balance Report ── */
function BalanceReport({ params, showToast }) {
  const { data, loading } = useReport(hrApi.leave.reports.balances, params, showToast)
  if (loading) return <HrLoading label="Loading balance report…" />
  const rows = data || []
  return (
    <div className="space-y-3">
      <div className="flex justify-end"><ExportButtons report="balances" params={params} /></div>
      {rows.length===0 ? <HrEmpty icon={Wallet} title="No balances" hint="No data matches the current filters." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:820 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Department','Leave Type','Opening','Allocated','Used','Adjusted','Carry Fwd','Available'].map(h=><th key={h} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
              <tbody>{rows.map((r,i)=>(
                <tr key={i} style={{ borderBottom:'1px solid var(--border)' }}>
                  <td className="px-3 py-2.5"><span className="font-bold" style={{ color:'var(--text-h)' }}>{r.employee_name}</span> <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code}</span></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.department||'—'}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-h)' }}>{r.leave_type}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.opening}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.allocated}</td>
                  <td className="px-3 py-2.5" style={{ color:'#f87171' }}>{r.used}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.adjusted}</td>
                  <td className="px-3 py-2.5" style={{ color:'#3b82f6' }}>{r.carry_forward}</td>
                  <td className="px-3 py-2.5 font-black" style={{ color:'#10b981' }}>{r.available}</td>
                </tr>
              ))}</tbody>
            </table>
          </div>}
    </div>
  )
}

/* ── Holiday Report ── */
function HolidayReport({ params, showToast }) {
  const { data, loading } = useReport(hrApi.leave.reports.holidays, params, showToast)
  if (loading) return <HrLoading label="Loading holiday report…" />
  const counts = data?.counts || {}; const rows = data?.holidays || []
  const KPIS = [{l:'Total',v:counts.total,c:'#7C3AED'},{l:'Upcoming',v:counts.upcoming,c:'#10b981'},{l:'Completed',v:counts.completed,c:'#94a3b8'},{l:'Optional',v:counts.optional,c:'#f59e0b'}]
  return (
    <div className="space-y-3">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">{KPIS.map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v ?? 0}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}</div>
      <div className="flex justify-end"><ExportButtons report="holidays" params={params} /></div>
      {rows.length===0 ? <HrEmpty icon={CalendarRange} title="No holidays" hint="No holidays match the filters." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:640 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Holiday','Date','Type','Applies To','Optional','Status'].map(h=><th key={h} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
              <tbody>{rows.map(h=>(
                <tr key={h.id} style={{ borderBottom:'1px solid var(--border)' }}>
                  <td className="px-3 py-2.5 font-bold" style={{ color:'var(--text-h)' }}>{h.title}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{h.holiday_date}</td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${HOL_C[h.holiday_type]||'#7C3AED'}1f`, color:HOL_C[h.holiday_type]||'#7C3AED' }}>{h.holiday_type}</span></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{h.applicable_for}{h.department_name?` · ${h.department_name}`:''}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{h.is_optional?'Yes':'—'}</td>
                  <td className="px-3 py-2.5"><span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={h.is_upcoming?{background:'rgba(16,185,129,0.12)',color:'#10b981'}:{background:'var(--bg-input)',color:'var(--text-muted)'}}>{h.is_upcoming?'Upcoming':'Completed'}</span></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}
    </div>
  )
}

/* ── Trends: inline SVG bar charts ── */
function TrendsReport({ params, showToast }) {
  const { data, loading } = useReport(hrApi.leave.reports.trends, params, showToast)
  if (loading) return <HrLoading label="Loading trends…" />
  const series = data || []
  if (!series.some(m=>m.applications>0)) return <HrEmpty icon={TrendingUp} title="No trend data" hint="Apply/approve leave across months to see trends." />
  const CHARTS = [
    { key:'applications', label:'Applications', color:'#7C3AED' }, { key:'approvals', label:'Approvals', color:'#10b981' },
    { key:'rejections', label:'Rejections', color:'#f87171' }, { key:'usage', label:'Leave Usage (days)', color:'#3b82f6' },
    { key:'utilization', label:'Leave Utilization %', color:'#ec4899', pct:true },
  ]
  return <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">{CHARTS.map(c=><BarChart key={c.key} series={series} dataKey={c.key} label={c.label} color={c.color} pct={c.pct} />)}</div>
}

function BarChart({ series, dataKey, label, color, pct }) {
  const max = Math.max(1, ...series.map(d=>d[dataKey]))
  const W = 460, H = 130, pad = 8, n = series.length
  const bw = Math.min(26, (W - pad*2)/n - 4)
  return (
    <div className="card-3d" style={{ padding:'18px' }}>
      <p className="text-[11px] font-bold uppercase mb-3" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>{label}</p>
      <svg viewBox={`0 0 ${W} ${H + 24}`} width="100%" preserveAspectRatio="xMidYMid meet">
        {series.map((d,i)=>{
          const x = pad + i*((W-pad*2)/n) + ((W-pad*2)/n - bw)/2
          const h = Math.round((d[dataKey]/max)*H); const y = H - h
          return (
            <g key={i}>
              <rect x={x} y={y} width={bw} height={h} rx={3} fill={color} opacity={d[dataKey]?0.85:0.2} />
              {d[dataKey]>0 && <text x={x+bw/2} y={y-3} textAnchor="middle" fontSize="8" fill="var(--text-muted)">{pct?`${d[dataKey]}%`:d[dataKey]}</text>}
              <text x={x+bw/2} y={H+13} textAnchor="middle" fontSize="7.5" fill="var(--text-muted)">{d.month}</text>
            </g>
          )
        })}
      </svg>
    </div>
  )
}
