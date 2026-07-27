import { useState, useEffect, useMemo, useCallback } from 'react'
import {
  LayoutDashboard, Users, Building2, PieChart, Wallet, ClipboardCheck, TrendingUp,
  FileDown, FileText, Sparkles,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'

const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'
const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December']
const money = (v) => (v === null || v === undefined) ? '—' : `₹${Number(v).toLocaleString('en-IN', { maximumFractionDigits:0 })}`
const REQ_C = { Draft:'#94a3b8', Submitted:'#3b82f6', 'Under Review':'#f59e0b', Approved:'#10b981', Rejected:'#f87171', Withdrawn:'#94a3b8' }
const SET_C = { Pending:'#94a3b8', Generated:'#3b82f6', Reviewed:'#f59e0b', Approved:'#a78bfa', Settled:'#10b981' }

const SUBS = [
  { key:'dashboard',   label:'Dashboard',        icon:LayoutDashboard },
  { key:'employees',   label:'Employee Exit',    icon:Users },
  { key:'departments', label:'Department',       icon:Building2 },
  { key:'exit-types',  label:'Exit Type',        icon:PieChart },
  { key:'settlements', label:'Settlement',       icon:Wallet },
  { key:'clearances',  label:'Clearance',        icon:ClipboardCheck },
  { key:'trends',      label:'Trends',           icon:TrendingUp },
]

export default function ExitReports({ showToast }) {
  const [sub, setSub] = useState('dashboard')
  const [opts, setOpts] = useState({ years:[], departments:[], designations:[], employees:[], exit_types:[], statuses:[] })
  const [filters, setFilters] = useState({ year:'', month:'', employee_id:'', department:'', designation:'', exit_type_id:'', status:'' })

  useEffect(() => { hrApi.exit.reports.filters().then(setOpts).catch(() => {}) }, [])
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
          <div className="min-w-[160px]"><label className="label">Employee</label><select className="input-3d text-sm" value={filters.employee_id} onChange={e=>set('employee_id',e.target.value)}><option value="">All</option>{opts.employees.map(e=><option key={e.id} value={e.id}>{e.name}</option>)}</select></div>
          <div className="min-w-[150px]"><label className="label">Exit Type</label><select className="input-3d text-sm" value={filters.exit_type_id} onChange={e=>set('exit_type_id',e.target.value)}><option value="">All</option>{opts.exit_types.map(t=><option key={t.id} value={t.id}>{t.name}</option>)}</select></div>
          <div className="min-w-[140px]"><label className="label">Status</label><select className="input-3d text-sm" value={filters.status} onChange={e=>set('status',e.target.value)}><option value="">All</option>{(opts.statuses||[]).map(s=><option key={s}>{s}</option>)}</select></div>
          {hasF && <button onClick={()=>setFilters({ year:'', month:'', employee_id:'', department:'', designation:'', exit_type_id:'', status:'' })} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
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
      {sub === 'exit-types'  && <ExitTypeReport params={params} showToast={showToast} />}
      {sub === 'settlements' && <SettlementReport params={params} showToast={showToast} />}
      {sub === 'clearances'  && <ClearanceReport params={params} showToast={showToast} />}
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
  const run = async (fmt) => { setBusy(fmt); try { await hrApi.exit.reports.export(report, fmt, params) } catch { /* ignore */ } finally { setBusy(null) } }
  return (
    <div className="flex gap-2">
      <button onClick={()=>run('csv')} disabled={busy} className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><FileDown size={13}/> {busy==='csv'?'…':'Excel'}</button>
      <button onClick={()=>run('pdf')} disabled={busy} className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><FileText size={13}/> {busy==='pdf'?'…':'PDF'}</button>
    </div>
  )
}

const Badge = ({ v, map }) => <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${(map[v]||'#7C3AED')}1f`, color:map[v]||'#7C3AED' }}>{v}</span>

/* ── Dashboard ── */
function Dashboard({ showToast }) {
  const [d, setD] = useState(null)
  useEffect(() => { hrApi.exit.reports.dashboard().then(setD).catch(() => showToast('Failed to load dashboard', 'error')) }, [showToast])
  if (!d) return <HrLoading label="Loading exit dashboard…" />
  const KPIS = [
    { l:'Total Exit Requests', v:d.total_requests, c:'#7C3AED' }, { l:'Approved Exits', v:d.approved_exits, c:'#10b981' },
    { l:'Completed Clearances', v:d.completed_clearances, c:'#0ea5e9' }, { l:'Settled Employees', v:d.settled_employees, c:'#8b5cf6' },
    { l:'Average Notice Days', v:d.avg_notice_days, c:'#f59e0b' }, { l:'Average Exit Duration', v:`${d.avg_exit_duration}d`, c:'#ec4899' },
    { l:'Total Settlement Amount', v:money(d.total_settlement_amount), c:'#10b981' }, { l:'Pending Exit Cases', v:d.pending_exit_cases, c:'#f87171' },
  ]
  const AI = ['AI Attrition Prediction','High Risk Departments','Exit Trend Insights','Retention Suggestions','Workforce Planning']
  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {KPIS.map(k=><div key={k.l} className="kpi-3d"><p className="text-2xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}
      </div>
      <div>
        <p className="text-[11px] font-bold uppercase mb-2 flex items-center gap-1.5" style={{ color:'#a78bfa', letterSpacing:'0.04em' }}><Sparkles size={13}/> AI Exit Insights</p>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
          {AI.map(t=><div key={t} className="rounded-xl p-3.5 flex items-start gap-2" style={{ background:'rgba(124,58,237,0.05)', border:'1px dashed rgba(124,58,237,0.3)' }}><Sparkles size={14} style={{ color:'#a78bfa', marginTop:1, flexShrink:0 }}/><div><p className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{t}</p><p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}>AI Insights Coming Soon</p></div></div>)}
        </div>
      </div>
    </div>
  )
}

/* ── Employee Exit Report ── */
function EmployeeReport({ params, showToast }) {
  const { data, loading } = useReport(hrApi.exit.reports.employees, params, showToast)
  if (loading) return <HrLoading label="Loading employee exit report…" />
  const rows = data || []
  return (
    <div className="space-y-3">
      <div className="flex justify-end"><ExportButtons report="employees" params={params} /></div>
      {rows.length===0 ? <HrEmpty icon={Users} title="No exit requests" hint="No data matches the current filters." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:920 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Department','Exit Type','Status','Notice','Settlement','Timeline'].map(h=><th key={h} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
              <tbody>{rows.map((r,i)=>(
                <tr key={i} style={{ borderBottom:'1px solid var(--border)' }}>
                  <td className="px-3 py-2.5"><span className="font-bold" style={{ color:'var(--text-h)' }}>{r.employee_name}</span> <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code}</span></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.department||'—'}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-h)' }}>{r.exit_type}</td>
                  <td className="px-3 py-2.5"><Badge v={r.status} map={REQ_C} /></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.notice_days}d</td>
                  <td className="px-3 py-2.5 font-semibold" style={{ color:r.settlement!=null?'#10b981':'var(--text-muted)' }}>{r.settlement!=null?money(r.settlement):'—'}</td>
                  <td className="px-3 py-2.5 text-[11px]" style={{ color:'var(--text-muted)' }}>{r.timeline}</td>
                </tr>
              ))}</tbody>
            </table>
          </div>}
    </div>
  )
}

/* ── Department Exit Report + bar chart ── */
function DepartmentReport({ params, showToast }) {
  const { data, loading } = useReport(hrApi.exit.reports.departments, params, showToast)
  if (loading) return <HrLoading label="Loading department report…" />
  const rows = data || []; const max = Math.max(1, ...rows.map(r=>r.requests))
  return (
    <div className="space-y-3">
      <div className="flex justify-end"><ExportButtons report="departments" params={params} /></div>
      {rows.length===0 ? <HrEmpty icon={Building2} title="No department data" hint="No data matches the current filters." />
        : <>
            <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
              <table className="w-full text-sm" style={{ minWidth:720 }}>
                <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Department','Requests','Approved','Completed','Settled','Avg Notice','Exit Rate'].map(h=><th key={h} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
                <tbody>{rows.map((r,i)=>(
                  <tr key={i} style={{ borderBottom:'1px solid var(--border)' }}>
                    <td className="px-3 py-2.5 font-bold" style={{ color:'var(--text-h)' }}>{r.department}</td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.requests}</td>
                    <td className="px-3 py-2.5" style={{ color:'#10b981' }}>{r.approved}</td>
                    <td className="px-3 py-2.5" style={{ color:'#0ea5e9' }}>{r.completed}</td>
                    <td className="px-3 py-2.5" style={{ color:'#8b5cf6' }}>{r.settled}</td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.avg_notice}d</td>
                    <td className="px-3 py-2.5 font-black" style={{ color:'#7C3AED' }}>{r.exit_rate}%</td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
            <div className="card-3d" style={{ padding:'18px' }}>
              <p className="text-[11px] font-bold uppercase mb-3" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Exit Requests by Department</p>
              <div className="space-y-2.5">{rows.map((r,i)=>(
                <div key={i}><div className="flex justify-between mb-1"><span className="text-xs font-semibold" style={{ color:'var(--text-muted)' }}>{r.department}</span><span className="text-xs font-black" style={{ color:'#7C3AED' }}>{r.requests}</span></div><div className="h-2 rounded-full" style={{ background:'var(--bg-input)' }}><div className="h-full rounded-full" style={{ width:`${Math.round(r.requests/max*100)}%`, background:GRAD }}/></div></div>
              ))}</div>
            </div>
          </>}
    </div>
  )
}

/* ── Exit Type Analysis + donut-ish bars ── */
function ExitTypeReport({ params, showToast }) {
  const { data, loading } = useReport(hrApi.exit.reports.exitTypes, params, showToast)
  if (loading) return <HrLoading label="Loading exit type analysis…" />
  const rows = data || []; const max = Math.max(1, ...rows.map(r=>r.count))
  return (
    <div className="space-y-3">
      <div className="flex justify-end"><ExportButtons report="exit-types" params={params} /></div>
      {rows.length===0 ? <HrEmpty icon={PieChart} title="No exit type data" hint="No data matches the current filters." />
        : <>
            <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
              <table className="w-full text-sm" style={{ minWidth:640 }}>
                <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Exit Type','Count','Avg Notice','Avg Settlement','Approval %'].map(h=><th key={h} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
                <tbody>{rows.map((r,i)=>(
                  <tr key={i} style={{ borderBottom:'1px solid var(--border)' }}>
                    <td className="px-3 py-2.5 font-bold" style={{ color:'var(--text-h)' }}>{r.exit_type} <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.code}</span></td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.count}</td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.avg_notice}d</td>
                    <td className="px-3 py-2.5" style={{ color:'#10b981' }}>{money(r.avg_settlement)}</td>
                    <td className="px-3 py-2.5" style={{ minWidth:150 }}><div className="flex items-center gap-2"><div className="flex-1 h-1.5 rounded-full" style={{ background:'var(--bg-input)' }}><div className="h-full rounded-full" style={{ width:`${Math.min(100,r.approval_pct)}%`, background:GRAD }}/></div><span className="text-[11px] font-bold" style={{ color:'#7C3AED' }}>{r.approval_pct}%</span></div></td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
            <div className="card-3d" style={{ padding:'18px' }}>
              <p className="text-[11px] font-bold uppercase mb-3" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Exit Count by Type</p>
              <div className="space-y-2.5">{rows.map((r,i)=>(
                <div key={i}><div className="flex justify-between mb-1"><span className="text-xs font-semibold" style={{ color:'var(--text-muted)' }}>{r.exit_type}</span><span className="text-xs font-black" style={{ color:'#7C3AED' }}>{r.count}</span></div><div className="h-2 rounded-full" style={{ background:'var(--bg-input)' }}><div className="h-full rounded-full" style={{ width:`${Math.round(r.count/max*100)}%`, background:GRAD }}/></div></div>
              ))}</div>
            </div>
          </>}
    </div>
  )
}

/* ── Settlement Report ── */
function SettlementReport({ params, showToast }) {
  const { data, loading } = useReport(hrApi.exit.reports.settlements, params, showToast)
  if (loading) return <HrLoading label="Loading settlement report…" />
  const rows = data || []
  return (
    <div className="space-y-3">
      <div className="flex justify-end"><ExportButtons report="settlements" params={params} /></div>
      {rows.length===0 ? <HrEmpty icon={Wallet} title="No settlements" hint="No data matches the current filters." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:760 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Employee','Settlement Month','Gross','Recoveries','Net','Status'].map(h=><th key={h} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
              <tbody>{rows.map((r,i)=>(
                <tr key={i} style={{ borderBottom:'1px solid var(--border)' }}>
                  <td className="px-3 py-2.5"><span className="font-bold" style={{ color:'var(--text-h)' }}>{r.employee_name}</span> <span className="text-[10px] font-mono" style={{ color:'#a78bfa' }}>{r.employee_code}</span></td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.settlement_month||'—'}</td>
                  <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.gross!=null?money(r.gross):'—'}</td>
                  <td className="px-3 py-2.5" style={{ color:'#f87171' }}>{r.recoveries!=null?money(r.recoveries):'—'}</td>
                  <td className="px-3 py-2.5 font-black" style={{ color:'#10b981' }}>{r.net!=null?money(r.net):'—'}</td>
                  <td className="px-3 py-2.5"><Badge v={r.status} map={SET_C} /></td>
                </tr>
              ))}</tbody>
            </table>
          </div>}
    </div>
  )
}

/* ── Clearance Report + stacked bars ── */
function ClearanceReport({ params, showToast }) {
  const { data, loading } = useReport(hrApi.exit.reports.clearances, params, showToast)
  if (loading) return <HrLoading label="Loading clearance report…" />
  const rows = data || []
  return (
    <div className="space-y-3">
      <div className="flex justify-end"><ExportButtons report="clearances" params={params} /></div>
      <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
        <table className="w-full text-sm" style={{ minWidth:680 }}>
          <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Department','Pending','In Progress','Cleared','Rejected','Completion %'].map(h=><th key={h} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
          <tbody>{rows.map((r,i)=>(
            <tr key={i} style={{ borderBottom:'1px solid var(--border)' }}>
              <td className="px-3 py-2.5 font-bold" style={{ color:'var(--text-h)' }}>{r.department}</td>
              <td className="px-3 py-2.5" style={{ color:'#94a3b8' }}>{r.pending}</td>
              <td className="px-3 py-2.5" style={{ color:'#f59e0b' }}>{r.in_progress}</td>
              <td className="px-3 py-2.5" style={{ color:'#10b981' }}>{r.cleared}</td>
              <td className="px-3 py-2.5" style={{ color:'#f87171' }}>{r.rejected}</td>
              <td className="px-3 py-2.5" style={{ minWidth:150 }}><div className="flex items-center gap-2"><div className="flex-1 h-1.5 rounded-full" style={{ background:'var(--bg-input)' }}><div className="h-full rounded-full" style={{ width:`${Math.min(100,r.completion_pct)}%`, background:'linear-gradient(135deg,#10b981,#059669)' }}/></div><span className="text-[11px] font-bold" style={{ color:'#10b981' }}>{r.completion_pct}%</span></div></td>
            </tr>
          ))}</tbody>
        </table>
      </div>
      <div className="card-3d" style={{ padding:'18px' }}>
        <p className="text-[11px] font-bold uppercase mb-3" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Clearance Status by Department</p>
        <div className="space-y-3">{rows.map((r,i)=>{
          const total = Math.max(1, r.pending+r.in_progress+r.cleared+r.rejected)
          const seg = [['#10b981',r.cleared],['#f59e0b',r.in_progress],['#f87171',r.rejected],['#94a3b8',r.pending]]
          return (
            <div key={i}><div className="flex justify-between mb-1"><span className="text-xs font-semibold" style={{ color:'var(--text-muted)' }}>{r.department}</span><span className="text-xs font-black" style={{ color:'#10b981' }}>{r.completion_pct}%</span></div>
              <div className="flex h-2.5 rounded-full overflow-hidden" style={{ background:'var(--bg-input)' }}>{seg.map(([c,v],j)=>v>0 && <div key={j} style={{ width:`${v/total*100}%`, background:c }}/>)}</div>
            </div>
          )
        })}</div>
        <div className="flex gap-4 mt-3 flex-wrap">{[['Cleared','#10b981'],['In Progress','#f59e0b'],['Rejected','#f87171'],['Pending','#94a3b8']].map(([l,c])=><span key={l} className="flex items-center gap-1.5 text-[10px]" style={{ color:'var(--text-muted)' }}><span className="w-2.5 h-2.5 rounded-sm" style={{ background:c }}/>{l}</span>)}</div>
      </div>
    </div>
  )
}

/* ── Trends: inline SVG bar charts ── */
function TrendsReport({ params, showToast }) {
  const { data, loading } = useReport(hrApi.exit.reports.trends, params, showToast)
  if (loading) return <HrLoading label="Loading trends…" />
  const series = data || []
  if (!series.some(m=>m.requests>0)) return <HrEmpty icon={TrendingUp} title="No trend data" hint="Raise/approve/settle exits across months to see trends." />
  const CHARTS = [
    { key:'requests', label:'Exit Requests', color:'#7C3AED' }, { key:'approvals', label:'Approvals', color:'#10b981' },
    { key:'settlements', label:'Settlements', color:'#8b5cf6' }, { key:'avg_notice', label:'Average Notice (days)', color:'#f59e0b' },
    { key:'avg_settlement', label:'Average Settlement (₹)', color:'#ec4899', money:true },
  ]
  return <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">{CHARTS.map(c=><BarChart key={c.key} series={series} dataKey={c.key} label={c.label} color={c.color} money={c.money} />)}</div>
}

function BarChart({ series, dataKey, label, color, money:isMoney }) {
  const max = Math.max(1, ...series.map(d=>d[dataKey]))
  const W = 460, H = 130, pad = 8, n = series.length
  const bw = Math.min(26, (W - pad*2)/n - 4)
  const fmt = (v) => isMoney ? (v>=1000?`${Math.round(v/1000)}k`:Math.round(v)) : v
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
              {d[dataKey]>0 && <text x={x+bw/2} y={y-3} textAnchor="middle" fontSize="8" fill="var(--text-muted)">{fmt(d[dataKey])}</text>}
              <text x={x+bw/2} y={H+13} textAnchor="middle" fontSize="7.5" fill="var(--text-muted)">{d.month}</text>
            </g>
          )
        })}
      </svg>
    </div>
  )
}
