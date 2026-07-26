import { useState, useEffect, useMemo, useCallback } from 'react'
import {
  LayoutDashboard, Users, Building2, BookOpen, UserCheck, ClipboardCheck, Award,
  CheckCircle2, TrendingUp, FileDown, FileText, Sparkles, Presentation,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'

const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'
const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December']

const SUBS = [
  { key:'dashboard',    label:'Dashboard',    icon:LayoutDashboard },
  { key:'employees',    label:'Employee',     icon:Users },
  { key:'departments',  label:'Department',   icon:Building2 },
  { key:'programs',     label:'Program',      icon:BookOpen },
  { key:'trainers',     label:'Trainer',      icon:Presentation },
  { key:'attendance',   label:'Attendance',   icon:UserCheck },
  { key:'assessments',  label:'Assessment',   icon:ClipboardCheck },
  { key:'certificates', label:'Certificate',  icon:Award },
  { key:'completion',   label:'Completion',   icon:CheckCircle2 },
  { key:'trends',       label:'Trends',       icon:TrendingUp },
]

export default function LearningReports({ showToast }) {
  const [sub, setSub] = useState('dashboard')
  const [opts, setOpts] = useState({ years:[], departments:[], designations:[], employees:[], programs:[], providers:[], categories:[], training_types:[], trainers:[], statuses:[] })
  const [filters, setFilters] = useState({ year:'', month:'', department:'', designation:'', employee_id:'', training_program_id:'', trainer:'', provider_id:'', category_id:'', training_type_id:'', status:'' })

  useEffect(() => { hrApi.learning.reports.filters().then(setOpts).catch(() => {}) }, [])
  const params = useMemo(() => { const p = {}; Object.entries(filters).forEach(([k, v]) => { if (v && v !== 'All') p[k] = v }); return p }, [filters])
  const set = (k, v) => setFilters(f => ({ ...f, [k]: v }))
  const clear = () => setFilters({ year:'', month:'', department:'', designation:'', employee_id:'', training_program_id:'', trainer:'', provider_id:'', category_id:'', training_type_id:'', status:'' })
  const hasF = Object.keys(params).length > 0

  const Sel = ({ k, label, opts:o, ov, ol }) => (
    <div className="min-w-[130px]"><label className="label">{label}</label>
      <select className="input-3d text-sm" value={filters[k]} onChange={e=>set(k, e.target.value)}><option value="">All</option>
        {o.map(x => <option key={ov?x[ov]:x} value={ov?x[ov]:x}>{ol?x[ol]:x}</option>)}
      </select></div>
  )

  return (
    <div className="space-y-4">
      <div className="card-3d" style={{ padding:'16px' }}>
        <div className="flex gap-3 flex-wrap items-end">
          <div className="min-w-[90px]"><label className="label">Year</label><select className="input-3d text-sm" value={filters.year} onChange={e=>set('year',e.target.value)}><option value="">All</option>{opts.years.map(y=><option key={y} value={y}>{y}</option>)}</select></div>
          <div className="min-w-[110px]"><label className="label">Month</label><select className="input-3d text-sm" value={filters.month} onChange={e=>set('month',e.target.value)}><option value="">All</option>{MONTHS.map((m,i)=><option key={m} value={i+1}>{m}</option>)}</select></div>
          <Sel k="department" label="Department" opts={opts.departments} />
          <Sel k="designation" label="Designation" opts={opts.designations} />
          <Sel k="employee_id" label="Employee" opts={opts.employees} ov="id" ol="name" />
          <Sel k="training_program_id" label="Program" opts={opts.programs} ov="id" ol="name" />
          <Sel k="trainer" label="Trainer" opts={opts.trainers} />
          <Sel k="provider_id" label="Provider" opts={opts.providers} ov="id" ol="name" />
          <Sel k="category_id" label="Category" opts={opts.categories} ov="id" ol="name" />
          <Sel k="training_type_id" label="Type" opts={opts.training_types} ov="id" ol="name" />
          <Sel k="status" label="Status" opts={opts.statuses} />
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
      {sub === 'employees' && <Report report="employees" params={params} showToast={showToast} cols={['Employee','Department','Program','Session','Trainer','Attendance','Status','Completion','Certificate']} keys={['employee_name','department','program','session','trainer','attendance','status','completion','certificate']} />}
      {sub === 'departments' && <DeptReport params={params} showToast={showToast} />}
      {sub === 'programs' && <Report report="programs" params={params} showToast={showToast} cols={['Program','Code','Sessions','Assignments','Completed','Avg Score','Pass %']} keys={['program','code','sessions','assignments','completed','avg_score','pass_pct']} />}
      {sub === 'trainers' && <Report report="trainers" params={params} showToast={showToast} cols={['Trainer','Sessions','Assignments','Completed']} keys={['trainer','sessions','assignments','completed']} />}
      {sub === 'attendance' && <Report report="attendance" params={params} showToast={showToast} cols={['Employee','Department','Program','Session','Trainer','Attendance']} keys={['employee_name','department','program','session','trainer','attendance']} />}
      {sub === 'assessments' && <Report report="assessments" params={params} showToast={showToast} cols={['Employee','Department','Program','Assessment','Total','Obtained','%','Result']} keys={['employee_name','department','program','assessment','total','obtained','percentage','result']} />}
      {sub === 'certificates' && <Report report="certificates" params={params} showToast={showToast} cols={['Employee','Department','Program','Certificate No','Issue','Expiry','Status']} keys={['employee_name','department','program','certificate_number','issue_date','expiry_date','status']} />}
      {sub === 'completion' && <Report report="completion" params={params} showToast={showToast} cols={['Employee','Department','Program','Attendance','Assessment','Quiz','Completion %','Certified','Status']} keys={['employee_name','department','program','attendance','assessment','quiz','completion','certified','status']} />}
      {sub === 'trends' && <Trends params={params} showToast={showToast} />}
    </div>
  )
}

function ExportButtons({ report, params }) {
  const [busy, setBusy] = useState(null)
  const run = async (fmt) => { setBusy(fmt); try { await hrApi.learning.reports.export(report, fmt, params) } catch { /* ignore */ } finally { setBusy(null) } }
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
  const { data, loading } = useReport(hrApi.learning.reports[report], params, showToast)
  if (loading) return <HrLoading label="Loading report…" />
  const rows = data || []
  const cell = (r, k) => {
    const v = r[k]
    if (k === 'result') return <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:v==='Pass'?'rgba(16,185,129,0.14)':'rgba(239,68,68,0.12)', color:v==='Pass'?'#10b981':'#f87171' }}>{v}</span>
    if (k === 'status' || k === 'attendance') { const c = { Completed:'#10b981', Certified:'#10b981', 'In Progress':'#f59e0b', Failed:'#f87171', Present:'#10b981', Absent:'#f87171', Issued:'#10b981', Expired:'#f87171' }[v]; return v ? <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${c||'#7C3AED'}1f`, color:c||'var(--text-muted)' }}>{v}</span> : '—' }
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
  const { data, loading } = useReport(hrApi.learning.reports.departments, params, showToast)
  if (loading) return <HrLoading label="Loading department report…" />
  const rows = data || []; const max = Math.max(1, ...rows.map(r=>r.assignments))
  return (
    <div className="space-y-3">
      <div className="flex justify-end"><ExportButtons report="departments" params={params} /></div>
      {rows.length===0 ? <HrEmpty icon={Building2} title="No department data" hint="No records match the current filters." />
        : <>
            <div className="card-3d overflow-x-auto" style={{ padding:'6px' }}>
              <table className="w-full text-sm" style={{ minWidth:640 }}>
                <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['Department','Assignments','Completed','Certified','Avg Score','Completion %'].map(h=><th key={h} className="text-left px-3 py-3 label-caps whitespace-nowrap">{h}</th>)}</tr></thead>
                <tbody>{rows.map((r,i)=>(
                  <tr key={i} style={{ borderBottom:'1px solid var(--border)' }}>
                    <td className="px-3 py-2.5 font-bold" style={{ color:'var(--text-h)' }}>{r.department}</td>
                    <td className="px-3 py-2.5" style={{ color:'var(--text-muted)' }}>{r.assignments}</td>
                    <td className="px-3 py-2.5" style={{ color:'#10b981' }}>{r.completed}</td>
                    <td className="px-3 py-2.5" style={{ color:'#3b82f6' }}>{r.certified}</td>
                    <td className="px-3 py-2.5" style={{ color:'#f59e0b' }}>{r.avg_score}%</td>
                    <td className="px-3 py-2.5 font-black" style={{ color:'#7C3AED' }}>{r.completion_pct}%</td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
            <div className="card-3d" style={{ padding:'18px' }}>
              <p className="text-[11px] font-bold uppercase mb-3" style={{ color:'var(--text-muted)', letterSpacing:'0.04em' }}>Department Participation</p>
              <div className="space-y-2.5">{rows.map((r,i)=>(
                <div key={i}><div className="flex justify-between mb-1"><span className="text-xs font-semibold" style={{ color:'var(--text-muted)' }}>{r.department}</span><span className="text-xs font-black" style={{ color:'#7C3AED' }}>{r.assignments}</span></div><div className="h-2 rounded-full" style={{ background:'var(--bg-input)' }}><div className="h-full rounded-full" style={{ width:`${Math.round(r.assignments/max*100)}%`, background:GRAD }}/></div></div>
              ))}</div>
            </div>
          </>}
    </div>
  )
}

/* ── Dashboard ── */
function Dashboard({ showToast }) {
  const [d, setD] = useState(null)
  useEffect(() => { hrApi.learning.reports.dashboard().then(setD).catch(() => showToast('Failed to load dashboard','error')) }, [showToast])
  if (!d) return <HrLoading label="Loading training dashboard…" />
  const KPIS = [
    { l:'Total Programs', v:d.total_programs, c:'#7C3AED' }, { l:'Total Sessions', v:d.total_sessions, c:'#3b82f6' },
    { l:'Assignments', v:d.assignments, c:'#0ea5e9' }, { l:'Completed', v:d.completed, c:'#10b981' },
    { l:'Certificates', v:d.certificates, c:'#8b5cf6' }, { l:'Pass %', v:`${d.pass_pct}%`, c:'#f59e0b' },
    { l:'Average Score', v:`${d.average_score}%`, c:'#ec4899' }, { l:'Upcoming Sessions', v:d.upcoming_sessions, c:'#22c55e' },
  ]
  const AI = ['Training Recommendations','Skill Gap Analysis','Certification Risk','Learning Path Suggestions','Workforce Readiness']
  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">{KPIS.map(k=><div key={k.l} className="kpi-3d"><p className="text-2xl font-black" style={{ color:k.c }}>{k.v}</p><p className="text-xs font-medium mt-1" style={{ color:'var(--text-muted)' }}>{k.l}</p></div>)}</div>
      <DashboardCharts showToast={showToast} />
      <div>
        <p className="text-[11px] font-bold uppercase mb-2 flex items-center gap-1.5" style={{ color:'#a78bfa', letterSpacing:'0.04em' }}><Sparkles size={13}/> AI Learning Insights</p>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
          {AI.map(t=><div key={t} className="rounded-xl p-3.5 flex items-start gap-2" style={{ background:'rgba(124,58,237,0.05)', border:'1px dashed rgba(124,58,237,0.3)' }}><Sparkles size={14} style={{ color:'#a78bfa', marginTop:1, flexShrink:0 }}/><div><p className="text-xs font-bold" style={{ color:'var(--text-h)' }}>{t}</p><p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}>AI Insights Coming Soon</p></div></div>)}
        </div>
      </div>
    </div>
  )
}

function DashboardCharts({ showToast }) {
  const now = new Date()
  const { data } = useReport(hrApi.learning.reports.trends, { year: now.getFullYear() }, showToast)
  const series = data || []
  if (!series.some(m=>m.trainings>0 || m.sessions>0)) return null
  const CHARTS = [
    { key:'trainings', label:'Monthly Trainings', color:'#7C3AED' }, { key:'sessions', label:'Sessions', color:'#3b82f6' },
    { key:'completion_pct', label:'Completion %', color:'#10b981', pct:true }, { key:'pass_pct', label:'Pass %', color:'#f59e0b', pct:true },
    { key:'hours', label:'Training Hours', color:'#ec4899' }, { key:'certificates', label:'Certificates', color:'#8b5cf6' },
  ]
  return <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">{CHARTS.map(c=><BarChart key={c.key} series={series} dataKey={c.key} label={c.label} color={c.color} pct={c.pct} />)}</div>
}

/* ── Trends tab ── */
function Trends({ params, showToast }) {
  const { data, loading } = useReport(hrApi.learning.reports.trends, params, showToast)
  if (loading) return <HrLoading label="Loading trends…" />
  const series = data || []
  if (!series.some(m=>m.trainings>0)) return <HrEmpty icon={TrendingUp} title="No trend data" hint="Run trainings across months to see trends." />
  const CHARTS = [
    { key:'trainings', label:'Trainings', color:'#7C3AED' }, { key:'completed', label:'Completed', color:'#10b981' },
    { key:'sessions', label:'Sessions', color:'#3b82f6' }, { key:'certificates', label:'Certificates', color:'#8b5cf6' },
    { key:'hours', label:'Training Hours', color:'#ec4899' }, { key:'pass_pct', label:'Pass %', color:'#f59e0b', pct:true },
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
