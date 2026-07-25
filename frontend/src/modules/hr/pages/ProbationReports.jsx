import { useState, useEffect, useMemo, useCallback } from 'react'
import {
  LayoutDashboard, Users, Building2, FileText, ClipboardList, Clock3, BadgeCheck,
  TrendingUp, FileDown, Sparkles,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'

const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'
const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December']
const REC_C = { Continue:'#3b82f6', Extend:'#f59e0b', Confirm:'#10b981', Fail:'#f87171' }
const ST_C = { Assigned:'#3b82f6', Active:'#10b981', Extended:'#f59e0b', Confirmed:'#10b981', Failed:'#f87171', Cancelled:'#94a3b8', Pending:'#f59e0b', Approved:'#3b82f6', Rejected:'#f87171' }

const SUBS = [
  { key:'dashboard',     label:'Dashboard',    icon:LayoutDashboard },
  { key:'employees',     label:'Employee',     icon:Users },
  { key:'departments',   label:'Department',   icon:Building2 },
  { key:'policies',      label:'Policy',       icon:FileText },
  { key:'reviews',       label:'Review',       icon:ClipboardList },
  { key:'extensions',    label:'Extension',    icon:Clock3 },
  { key:'confirmations', label:'Confirmation', icon:BadgeCheck },
  { key:'trends',        label:'Trends',       icon:TrendingUp },
]

export default function ProbationReports({ showToast }) {
  const [sub, setSub] = useState('dashboard')
  const [opts, setOpts] = useState({ years:[], departments:[], designations:[], employees:[], policies:[], statuses:[] })
  const [filters, setFilters] = useState({ year:'', month:'', department:'', designation:'', employee_id:'', policy_id:'', status:'' })

  useEffect(() => { hrApi.probation.reports.filters().then(setOpts).catch(() => {}) }, [])
  const params = useMemo(() => { const p = {}; Object.entries(filters).forEach(([k, v]) => { if (v && v !== 'All') p[k] = v }); return p }, [filters])
  const set = (k, v) => setFilters(f => ({ ...f, [k]: v }))
  const clear = () => setFilters({ year:'', month:'', department:'', designation:'', employee_id:'', policy_id:'', status:'' })
  const hasF = Object.keys(params).length > 0

  return (
    <div className="space-y-4">
      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="min-w-[90px]"><label className="label">Year</label><select className="input-3d text-sm" value={filters.year} onChange={e=>set('year',e.target.value)}><option value="">All</option>{opts.years.map(y=><option key={y} value={y}>{y}</option>)}</select></div>
          <div className="min-w-[110px]"><label className="label">Month</label><select className="input-3d text-sm" value={filters.month} onChange={e=>set('month',e.target.value)}><option value="">All</option>{MONTHS.map((m,i)=><option key={m} value={i+1}>{m}</option>)}</select></div>
          <div className="min-w-[150px]"><label className="label">Department</label><select className="input-3d text-sm" value={filters.department} onChange={e=>set('department',e.target.value)}><option value="">All</option>{opts.departments.map(d=><option key={d} value={d}>{d}</option>)}</select></div>
          <div className="min-w-[150px]"><label className="label">Designation</label><select className="input-3d text-sm" value={filters.designation} onChange={e=>set('designation',e.target.value)}><option value="">All</option>{opts.designations.map(d=><option key={d} value={d}>{d}</option>)}</select></div>
          <div className="min-w-[160px]"><label className="label">Employee</label><select className="input-3d text-sm" value={filters.employee_id} onChange={e=>set('employee_id',e.target.value)}><option value="">All</option>{opts.employees.map(e=><option key={e.id} value={e.id}>{e.name}</option>)}</select></div>
          <div className="min-w-[160px]"><label className="label">Policy</label><select className="input-3d text-sm" value={filters.policy_id} onChange={e=>set('policy_id',e.target.value)}><option value="">All</option>{opts.policies.map(p=><option key={p.id} value={p.id}>{p.name}</option>)}</select></div>
          <div className="min-w-[140px]"><label className="label">Status</label><select className="input-3d text-sm" value={filters.status} onChange={e=>set('status',e.target.value)}><option value="">All</option>{(opts.statuses||[]).map(s=><option key={s}>{s}</option>)}</select></div>
          {hasF && <button onClick={clear} className="px-3 py-2.5 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>Clear</button>}
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

      {sub === 'dashboard' && <Dashboard showToast={showToast} />}
      {sub === 'employees' && <Report report="employees" params={params} showToast={showToast} cols={['Employee','Department','Policy','Type','Start','End','Status','Ext','Latest Review','Confirmation']} keys={['employee_name','department','policy','type','start_date','end_date','status','extension_count','latest_review','confirmation_status']} />}
      {sub === 'departments' && <DeptReport params={params} showToast={showToast} />}
      {sub === 'policies' && <PolicyReport params={params} showToast={showToast} />}
      {sub === 'reviews' && <ReviewReport params={params} showToast={showToast} />}
      {sub === 'extensions' && <Report report="extensions" params={params} showToast={showToast} cols={['Department','Requested','Approved','Rejected','Avg Ext Days']} keys={['department','requested','approved','rejected','avg_days']} />}
      {sub === 'confirmations' && <Report report="confirmations" params={params} showToast={showToast} cols={['Employee','Policy','Recommendation','Decision','Confirmation Date','Effective Date','Status']} keys={['employee_name','policy','recommendation','decision','confirmation_date','effective_date','status']} />}
      {sub === 'trends' && <Trends params={params} showToast={showToast} />}
    </div>
  )
}

function ExportButtons({ report, params }) {
  const [busy, setBusy] = useState(null)
  const run = async (fmt) => { setBusy(fmt); try { await hrApi.probation.reports.export(report, fmt, params) } catch { /* ignore */ } finally { setBusy(null) } }
  return (
    <div className="flex gap-2">
      <button onClick={()=>run('csv')} disabled={busy} className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><FileDown size={13}/> {busy==='csv'?'…':'Excel'}</button>
      <button onClick={()=>run('pdf')} disabled={busy} className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><FileText size={13}/> {busy==='pdf'?'…':'PDF'}</button>
    </div>
  )
}

function useReport(fetcher, params, showToast) {
  const [data, setData] = useState(null); const [loading, setLoading] = useState(true)
  const key = JSON.stringify(params)
  const load = useCallback(() => { setLoading(true); fetcher(params).then(setData).catch(() => showToast('Failed to load report','error')).finally(() => setLoading(false)) // eslint-disable-line
  }, [key])
  useEffect(() => { load() }, [load])
  return { data, loading }
}

/* ── Generic tabular report ── */
function Report({ report, params, showToast, cols, keys }) {
  const { data, loading } = useReport(hrApi.probation.reports[report], params, showToast)
  if (loading) return <HrLoading label="Loading report…" />
  const rows = data || []
  const cell = (r, k) => {
    const v = r[k]
    if (k === 'status' || k === 'confirmation_status') { const c = ST_C[v]; return v && v !== '—' ? <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${c||'#7C3AED'}1f`, color:c||'var(--text-muted)' }}>{v}</span> : '—' }
    if (k === 'recommendation') { const c = REC_C[v]; return v && v !== '—' ? <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${c||'#7C3AED'}1f`, color:c||'#7C3AED' }}>{v}</span> : '—' }
    return (v === null || v === undefined || v === '') ? '—' : v
  }
  return (
    <div className="space-y-3">
      <div className="flex justify-end"><ExportButtons report={report} params={params} /></div>
      {rows.length===0 ? <HrEmpty icon={FileText} title="No data" hint="No records match the current filters." />
        : <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
            <table className="w-full text-sm" style={{ minWidth:cols.length*110 }}>
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{cols.map(h=><th key={h} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
              <tbody>{rows.map((r,i)=>(
                <tr key={i} style={{ borderBottom:'1px solid var(--border)' }}>{keys.map((k,j)=>(
                  <td key={k} className="px-3 py-2.5" style={{ color: j===0?'var(--text-h)':'var(--text-muted)', fontWeight:j===0?700:400 }}>{cell(r,k)}</td>
                ))}</tr>
              ))}</tbody>
            </table>
          </div>}
    </div>
  )
}

/* ── Department report + bar chart ── */
function DeptReport({ params, showToast }) {
  const { data, loading } = useReport(hrApi.probation.reports.departments, params, showToast)
  if (loading) return <HrLoading label="Loading department report…" />
  const rows = data || []; const max = Math.max(1, ...rows.map(r=>r.employees))
  return (
    <div className="space-y-3">
      <div className="flex justify-end"><ExportButtons report="departments" params={params} /></div>
      {rows.length===0 ? <HrEmpty icon={Building2} title="No department data" hint="No records match the current filters." />
        : <>
            <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
              <table className="w-full text-sm" style={{ minWidth:640 }}>
                <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Department','Employees','Active','Extended','Confirmed','Rejected','Avg Duration'].map(h=><th key={h} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
                <tbody>{rows.map((r,i)=>(
                  <tr key={i} style={{ borderBottom:'1px solid var(--border)' }}>
                    <td className="px-3 py-2.5 font-bold" style={{ color:'var(--text-h)' }}>{r.department}</td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.employees}</td>
                    <td className="px-3 py-2.5" style={{ color:'#10b981' }}>{r.active}</td>
                    <td className="px-3 py-2.5" style={{ color:'#f59e0b' }}>{r.extended}</td>
                    <td className="px-3 py-2.5" style={{ color:'#8b5cf6' }}>{r.confirmed}</td>
                    <td className="px-3 py-2.5" style={{ color:'#f87171' }}>{r.rejected}</td>
                    <td className="px-3 py-2.5 font-black" style={{ color:'#7C3AED' }}>{r.avg_duration}d</td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
            <div className="card-3d" style={{ padding:'18px' }}>
              <p className="text-[11px] font-bold uppercase mb-3" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Employees on Probation by Department</p>
              <div className="space-y-2.5">{rows.map((r,i)=>(
                <div key={i}><div className="flex justify-between mb-1"><span className="text-xs font-semibold" style={{ color:'var(--text-muted)' }}>{r.department}</span><span className="text-xs font-black" style={{ color:'#7C3AED' }}>{r.employees}</span></div><div className="h-2 rounded-full" style={{ background:'var(--bg-input)' }}><div className="h-full rounded-full" style={{ width:`${Math.round(r.employees/max*100)}%`, background:GRAD }}/></div></div>
              ))}</div>
            </div>
          </>}
    </div>
  )
}

/* ── Policy report + bar chart ── */
function PolicyReport({ params, showToast }) {
  const { data, loading } = useReport(hrApi.probation.reports.policies, params, showToast)
  if (loading) return <HrLoading label="Loading policy report…" />
  const rows = data || []; const max = Math.max(1, ...rows.map(r=>r.employees))
  return (
    <div className="space-y-3">
      <div className="flex justify-end"><ExportButtons report="policies" params={params} /></div>
      {rows.length===0 ? <HrEmpty icon={FileText} title="No policy data" hint="No records match the current filters." />
        : <>
            <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
              <table className="w-full text-sm" style={{ minWidth:600 }}>
                <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Policy','Employees','Confirmed %','Extended %','Avg Duration'].map(h=><th key={h} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
                <tbody>{rows.map((r,i)=>(
                  <tr key={i} style={{ borderBottom:'1px solid var(--border)' }}>
                    <td className="px-3 py-2.5 font-bold" style={{ color:'var(--text-h)' }}>{r.policy}</td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.employees}</td>
                    <td className="px-3 py-2.5" style={{ color:'#10b981' }}>{r.confirmed_pct}%</td>
                    <td className="px-3 py-2.5" style={{ color:'#f59e0b' }}>{r.extended_pct}%</td>
                    <td className="px-3 py-2.5 font-black" style={{ color:'#7C3AED' }}>{r.avg_duration}d</td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
            <div className="card-3d" style={{ padding:'18px' }}>
              <p className="text-[11px] font-bold uppercase mb-3" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Employees by Policy</p>
              <div className="space-y-2.5">{rows.map((r,i)=>(
                <div key={i}><div className="flex justify-between mb-1"><span className="text-xs font-semibold" style={{ color:'var(--text-muted)' }}>{r.policy}</span><span className="text-xs font-black" style={{ color:'#7C3AED' }}>{r.employees}</span></div><div className="h-2 rounded-full" style={{ background:'var(--bg-input)' }}><div className="h-full rounded-full" style={{ width:`${Math.round(r.employees/max*100)}%`, background:GRAD }}/></div></div>
              ))}</div>
            </div>
          </>}
    </div>
  )
}

/* ── Review report ── */
function ReviewReport({ params, showToast }) {
  const { data, loading } = useReport(hrApi.probation.reports.reviews, params, showToast)
  if (loading) return <HrLoading label="Loading review report…" />
  const d = data || { completed:0, pending:0, avg_rating:0, recommendations:{} }
  const recs = Object.entries(d.recommendations || {})
  const maxRec = Math.max(1, ...recs.map(([, v])=>v))
  const KPIS = [{l:'Completed Reviews',v:d.completed,c:'#10b981'},{l:'Pending Reviews',v:d.pending,c:'#f59e0b'},{l:'Average Rating',v:`${d.avg_rating}/5`,c:'#ec4899'}]
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-3 gap-4">{KPIS.map(k=><div key={k.l} className="kpi-3d"><p className="text-3xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}</div>
      <div className="card-3d" style={{ padding:'18px' }}>
        <p className="text-[11px] font-bold uppercase mb-3" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Recommendation Distribution</p>
        <div className="space-y-2.5">{recs.map(([k,v])=>(
          <div key={k}><div className="flex justify-between mb-1"><span className="text-xs font-semibold" style={{ color:REC_C[k]||'var(--text-muted)' }}>{k}</span><span className="text-xs font-black" style={{ color:REC_C[k]||'#7C3AED' }}>{v}</span></div><div className="h-2 rounded-full" style={{ background:'var(--bg-input)' }}><div className="h-full rounded-full" style={{ width:`${Math.round(v/maxRec*100)}%`, background:REC_C[k]||GRAD }}/></div></div>
        ))}</div>
      </div>
    </div>
  )
}

/* ── Dashboard ── */
function Dashboard({ showToast }) {
  const [d, setD] = useState(null)
  useEffect(() => { hrApi.probation.reports.dashboard().then(setD).catch(() => showToast('Failed to load dashboard','error')) }, [showToast])
  if (!d) return <HrLoading label="Loading probation dashboard…" />
  const KPIS = [
    { l:'On Probation', v:d.total, c:'#7C3AED' }, { l:'Active', v:d.active, c:'#10b981' },
    { l:'Extended', v:d.extended, c:'#f59e0b' }, { l:'Pending Confirmation', v:d.pending_confirmation, c:'#3b82f6' },
    { l:'Confirmed', v:d.confirmed, c:'#8b5cf6' }, { l:'Rejected', v:d.rejected, c:'#f87171' },
    { l:'Avg Duration (days)', v:d.avg_duration, c:'#ec4899' }, { l:'Due This Month', v:d.due_this_month, c:'#22c55e' },
  ]
  const AI = ['Confirmation Prediction','High Risk Employees','Department Risk','HR Suggestions','Workforce Insights']
  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">{KPIS.map(k=><div key={k.l} className="kpi-3d"><p className="text-2xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}</div>
      <div>
        <p className="text-[11px] font-bold uppercase mb-2 flex items-center gap-1.5" style={{ color:'#a78bfa', letterSpacing:'0.04em' }}><Sparkles size={13}/> AI Probation Insights</p>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
          {AI.map(t=><div key={t} className="rounded-xl p-3.5 flex items-start gap-2" style={{ background:'rgba(124,58,237,0.05)', border:'1px dashed rgba(124,58,237,0.3)' }}><Sparkles size={14} style={{ color:'#a78bfa', marginTop:1, flexShrink:0 }}/><div><p className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{t}</p><p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}>AI Insights Coming Soon</p></div></div>)}
        </div>
      </div>
    </div>
  )
}

/* ── Trends: inline SVG bar charts ── */
function Trends({ params, showToast }) {
  const { data, loading } = useReport(hrApi.probation.reports.trends, params, showToast)
  if (loading) return <HrLoading label="Loading trends…" />
  const series = data || []
  if (!series.some(m=>m.probations||m.reviews||m.extensions||m.confirmations||m.rejections)) return <HrEmpty icon={TrendingUp} title="No trend data" hint="Run probations across months to see trends." />
  const CHARTS = [
    { key:'probations', label:'New Probations', color:'#7C3AED' }, { key:'reviews', label:'Reviews', color:'#3b82f6' },
    { key:'extensions', label:'Extensions', color:'#f59e0b' }, { key:'confirmations', label:'Confirmations', color:'#10b981' },
    { key:'rejections', label:'Rejections', color:'#f87171' },
  ]
  return <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">{CHARTS.map(c=><BarChart key={c.key} series={series} dataKey={c.key} label={c.label} color={c.color} />)}</div>
}

function BarChart({ series, dataKey, label, color }) {
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
              {d[dataKey]>0 && <text x={x+bw/2} y={y-3} textAnchor="middle" fontSize="8" fill="var(--text-muted)">{d[dataKey]}</text>}
              <text x={x+bw/2} y={H+13} textAnchor="middle" fontSize="7.5" fill="var(--text-muted)">{d.month}</text>
            </g>
          )
        })}
      </svg>
    </div>
  )
}
