import { useTheme } from '@/context/ThemeContext'
import { useAuth } from '@/context/AuthContext'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { hrApi } from '@/services/hrApi'
import { statusColor, statusLabel, canApproveL1, canApproveL2 } from '../constants'
import NotificationWidget from '@/modules/notifications/NotificationWidget'
import RaiseTicketButton from '@/components/RaiseTicketButton'
import {
  Users, Briefcase, Calendar, FileText, TrendingUp, ClipboardList, Layers, Send, Rocket, PlayCircle, Lock,
  CheckCircle, XCircle, Clock, Bell, Mail, MessageCircle, ArrowRight, Timer, AlertCircle
} from 'lucide-react'

const KPI = ({ label, value, icon: Icon, gradient, shadow, sub, onClick }) => (
  <div 
    className={`kpi-3d relative overflow-hidden ${onClick ? 'cursor-pointer hover:scale-[1.02] transition-transform' : ''}`}
    onClick={onClick}
  >
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

const pc = p => p==='High'?{bg:'rgba(239,68,68,0.1)',color:'#f87171',bdr:'rgba(239,68,68,0.2)'}:p==='Medium'?{bg:'rgba(245,158,11,0.1)',color:'#fbbf24',bdr:'rgba(245,158,11,0.2)'}:{bg:'rgba(16,185,129,0.1)',color:'#10b981',bdr:'rgba(16,185,129,0.2)'}

// Compact stat tile for the recruitment-workflow metric row
const MiniStat = ({ label, value, icon: Icon, color, onClick }) => (
  <div className={`kpi-3d ${onClick ? 'cursor-pointer hover:scale-[1.02] transition-transform' : ''}`} onClick={onClick} style={{ padding: 14 }}>
    <div className="flex items-center gap-2 mb-2">
      <div className="w-7 h-7 rounded-lg flex items-center justify-center" style={{ background: `${color}20` }}><Icon size={14} style={{ color }} /></div>
    </div>
    <p className="text-xl font-black" style={{ color: 'var(--text-h)' }}>{value ?? 0}</p>
    <p className="text-[11px] font-semibold" style={{ color: 'var(--text-muted)' }}>{label}</p>
  </div>
)

export default function HRDashboard() {
  const navigate = useNavigate()
  const { user } = useAuth()
  
  // Fetch dashboard data from API
  const { data: dashboardData, isLoading } = useQuery({
    queryKey: ['hr-dashboard'],
    queryFn: hrApi.dashboard.get,
    refetchInterval: 30000, // Refresh every 30 seconds
  })

  if (isLoading) {
    return (
      <div className="space-y-6 animate-fade-in">
        <div className="skeleton h-12 w-64 rounded-xl" style={{ background: 'var(--border)' }} />
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
          {[1,2,3,4].map(i => (
            <div key={i} className="skeleton h-32 rounded-2xl" style={{ background: 'var(--border)' }} />
          ))}
        </div>
      </div>
    )
  }

  const kpis = dashboardData?.kpis || {}
  const rec = dashboardData?.recruitment_kpis || {}
  const manpower = dashboardData?.manpower || {}
  const pipeline = dashboardData?.pipeline || []
  const sourceBreakdown = dashboardData?.source_breakdown || []
  const recentRequests = dashboardData?.recent_requests || []
  const todayInterviews = dashboardData?.today_interviews || []
  const hiringTrend = dashboardData?.hiring_trend || []

  const maxS = Math.max(...sourceBreakdown.map(s => s.count), 1)
  const isManager = canApproveL1(user) || canApproveL2(user)

  // Recruitment onboarding → offer → joining KPIs (auto-derived, tenant-scoped)
  const recruitmentStats = [
    { label: 'Waiting for Candidate',   value: rec.waiting_for_candidate,     icon: Clock,       color: '#f59e0b', to: '/app/hr/onboarding' },
    { label: 'Waiting for HR Verify',   value: rec.waiting_for_hr,            icon: ClipboardList, color: '#0ea5e9', to: '/app/hr/onboarding' },
    { label: 'Ready for Approval',      value: rec.ready_for_approval,        icon: CheckCircle, color: '#10b981', to: '/app/hr/onboarding' },
    { label: 'Offer Pending',           value: rec.offer_pending,             icon: Send,        color: '#a78bfa', to: '/app/hr/offers' },
    { label: 'Offer Accepted',          value: rec.offer_accepted,            icon: FileText,    color: '#8b5cf6', to: '/app/hr/offers' },
    { label: 'Joining This Week',       value: rec.joining_this_week,         icon: Calendar,    color: '#14b8a6', to: '/app/hr/offers' },
    { label: 'Employee Creation Pending', value: rec.employee_creation_pending, icon: Rocket,    color: '#f97316', to: '/app/hr/offers' },
  ]

  // 8 recruitment-workflow metrics required by the HR module spec
  const workflowStats = [
    { label: 'Total Requests',   value: manpower.total_requests,   icon: ClipboardList, color: '#7C3AED', status: 'All' },
    { label: 'L1 Pending',       value: manpower.l1_pending,       icon: Clock,         color: '#f59e0b', status: 'L1_Pending' },
    { label: 'L2 Pending',       value: manpower.l2_pending,       icon: Clock,         color: '#8b5cf6', status: 'L2_Pending' },
    { label: 'Ready for HR',     value: manpower.ready_for_hr,     icon: Send,          color: '#0ea5e9', status: 'Ready_for_HR' },
    { label: 'Converted to JD',  value: manpower.converted_to_jd,  icon: Layers,        color: '#6366f1', status: 'Converted_to_JD' },
    { label: 'Posted Jobs',      value: manpower.posted_jobs,      icon: Rocket,        color: '#10b981', status: 'Job_Posted' },
    { label: 'Active Hiring',    value: manpower.active_hiring,    icon: PlayCircle,    color: '#14b8a6', status: 'Hiring_in_Progress' },
    { label: 'Closed Positions', value: manpower.closed_positions, icon: Lock,          color: '#64748b', status: 'Closed' },
  ]

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <p className="label-caps mb-1">HR & Recruitment</p>
          <h1 className="font-black" style={{ fontSize:'clamp(1.4rem,2.5vw,1.9rem)', color:'var(--text-h)', letterSpacing:'-0.02em' }}>
            Recruitment <span className="text-gradient">Overview</span>
          </h1>
          <p className="text-sm mt-0.5" style={{ color:'var(--text-muted)' }}>Live dashboard · updated in real-time</p>
        </div>
        <RaiseTicketButton source="hr" />
      </div>

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <KPI 
          label="Open Positions" 
          value={kpis.open_positions || 0} 
          icon={Briefcase} 
          gradient="linear-gradient(145deg,#9f67ff,#7C3AED)" 
          shadow="#7C3AED" 
        />
        <KPI 
          label="Active Candidates" 
          value={kpis.active_candidates || 0} 
          icon={Users} 
          gradient="linear-gradient(145deg,#34d399,#10B981)" 
          shadow="#10b981" 
        />
        <KPI 
          label="Today's Interviews" 
          value={kpis.today_interviews || 0} 
          icon={Calendar} 
          gradient="linear-gradient(145deg,#fcd34d,#F59E0B)" 
          shadow="#f59e0b" 
        />
        <KPI 
          label="Offers Released" 
          value={kpis.offers_released || 0} 
          icon={FileText} 
          gradient="linear-gradient(145deg,#f87171,#EF4444)" 
          shadow="#ef4444" 
          sub="This month"
        />
      </div>
      
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <KPI 
          label="Hired This Month" 
          value={kpis.hired_this_month || 0} 
          icon={CheckCircle} 
          gradient="linear-gradient(145deg,#34d399,#059669)" 
          shadow="#059669" 
        />
        
        {/* ⭐ NEW: Time to Hire */}
        <KPI 
          label="Time to Hire" 
          value={kpis.time_to_hire_days ? `${kpis.time_to_hire_days} days` : 'N/A'} 
          icon={Timer} 
          gradient="linear-gradient(145deg,#818cf8,#6366f1)" 
          shadow="#6366f1"
          sub="Average"
        />
        
        {/* ⭐ NEW: Pending Approvals (Manager only) */}
        {isManager && kpis.pending_approvals > 0 && (
          <KPI 
            label="Pending Approvals" 
            value={kpis.pending_approvals} 
            icon={AlertCircle} 
            gradient="linear-gradient(145deg,#fb923c,#f97316)" 
            shadow="#f97316"
            sub="Awaiting your action"
            onClick={() => navigate('/app/hr/manpower-requests')}
          />
        )}
        
        <KPI 
          label="Pending Feedback" 
          value={kpis.pending_feedback || 0} 
          icon={Clock} 
          gradient="linear-gradient(145deg,#a78bfa,#8b5cf6)" 
          shadow="#8b5cf6" 
        />
        
        <KPI
          label="Rejected"
          value={kpis.rejected || 0}
          icon={XCircle}
          gradient="linear-gradient(145deg,#f87171,#dc2626)"
          shadow="#dc2626"
        />
      </div>

      {/* ── Hiring KPIs (Phase 3) ── */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <KPI label="Total Hired" value={kpis.total_hired || 0} icon={CheckCircle}
          gradient="linear-gradient(145deg,#34d399,#059669)" shadow="#059669" sub="All time"
          onClick={()=>navigate('/app/hr/employees')} />
        <KPI label="Offers Sent" value={kpis.offers_sent || 0} icon={FileText}
          gradient="linear-gradient(145deg,#a78bfa,#7C3AED)" shadow="#7C3AED"
          onClick={()=>navigate('/app/hr/offers')} />
        <KPI label="Acceptance %" value={`${kpis.acceptance_pct || 0}%`} icon={Timer}
          gradient="linear-gradient(145deg,#34d399,#10B981)" shadow="#10b981" sub={`${kpis.offers_accepted || 0} accepted`} />
        <KPI label="Pending Interviews" value={kpis.pending_interviews || 0} icon={Calendar}
          gradient="linear-gradient(145deg,#fcd34d,#F59E0B)" shadow="#f59e0b"
          onClick={()=>navigate('/app/hr/interviews')} />
      </div>

      {/* ── Recruitment Pipeline KPIs — onboarding → offer → joining ── */}
      <div>
        <div className="flex items-center justify-between mb-3">
          <h2 className="font-bold text-base" style={{ color:'var(--text-h)' }}>Onboarding &amp; Offer Pipeline</h2>
          <button onClick={()=>navigate('/app/hr/onboarding')} className="text-xs font-semibold flex items-center gap-1" style={{ color:'#a78bfa' }}>Onboarding tracker <ArrowRight size={11}/></button>
        </div>
        <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
          {recruitmentStats.map(s => (
            <MiniStat key={s.label} label={s.label} value={s.value} icon={s.icon} color={s.color} onClick={()=>navigate(s.to)} />
          ))}
        </div>
      </div>

      {/* ── Recruitment Workflow — Manpower Request pipeline (8 metrics) ── */}
      <div>
        <div className="flex items-center justify-between mb-3">
          <h2 className="font-bold text-base" style={{ color:'var(--text-h)' }}>Recruitment Workflow</h2>
          <button onClick={()=>navigate('/app/hr/manpower-requests')} className="text-xs font-semibold flex items-center gap-1" style={{ color:'#a78bfa' }}>Manage requests <ArrowRight size={11}/></button>
        </div>
        <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-3">
          {workflowStats.map(s => (
            <MiniStat key={s.label} label={s.label} value={s.value} icon={s.icon} color={s.color}
              onClick={()=>navigate(`/app/hr/manpower-requests?status=${s.status}`)} />
          ))}
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <div className="card-3d lg:col-span-2" style={{ padding:'24px' }}>
          <div className="flex items-center justify-between mb-5">
            <div>
              <h2 className="font-bold text-base" style={{ color:'var(--text-h)' }}>Recruitment Pipeline</h2>
              <p className="text-xs mt-0.5" style={{ color:'var(--text-muted)' }}>End-to-end candidate funnel</p>
            </div>
            <button onClick={()=>navigate('/app/hr/candidates')} className="text-xs font-semibold flex items-center gap-1" style={{ color:'#a78bfa' }}>View all <ArrowRight size={11}/></button>
          </div>
          <div className="space-y-3">
            {pipeline.length > 0 ? pipeline.map(p=>{
              const pct = pipeline[0]?.count > 0 ? Math.round((p.count / pipeline[0].count) * 100) : 0
              const color = p.stage === 'Hired' ? '#059669' : p.stage === 'Offer' ? '#10b981' : p.stage === 'Interview' ? '#f59e0b' : p.stage === 'Screening' || p.stage === 'Assessment' ? '#a78bfa' : '#3b82f6'
              return(
                <div key={p.stage} className="flex items-center gap-3">
                  <span className="text-xs font-semibold w-20 text-right flex-shrink-0" style={{ color:'var(--text-muted)' }}>{p.stage}</span>
                  <div className="flex-1 h-7 rounded-xl overflow-hidden relative" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                    <div className="h-full rounded-xl flex items-center px-3" style={{ width:`${pct}%`, background:`linear-gradient(90deg,${color}99,${color})`, boxShadow:`0 0 10px ${color}25` }}>
                      <span className="text-xs font-black text-white">{p.count}</span>
                    </div>
                    <div className="absolute inset-0 rounded-xl" style={{ background:'linear-gradient(180deg,rgba(255,255,255,0.05) 0%,transparent 50%)' }}/>
                  </div>
                  <span className="text-xs font-bold w-8 flex-shrink-0" style={{ color }}>{pct}%</span>
                </div>
              )
            }) : (
              <p className="text-sm text-center py-8" style={{ color: 'var(--text-muted)' }}>No pipeline data available</p>
            )}
          </div>
        </div>
        <div className="card-3d" style={{ padding:'24px' }}>
          <h2 className="font-bold text-base mb-4" style={{ color:'var(--text-h)' }}>Source Wise Analytics</h2>
          <div className="space-y-3">
            {sourceBreakdown.length > 0 ? sourceBreakdown.map((s, idx)=>{
              const colors = ['#0077b5', '#7C3AED', '#ef4444', '#10b981', '#f59e0b', '#8b5cf6']
              const color = colors[idx % colors.length]
              return (
                <div key={s.source || idx}>
                  <div className="flex justify-between mb-1">
                    <span className="text-xs font-medium" style={{ color:'var(--text-muted)' }}>{s.source || 'Unknown'}</span>
                    <span className="text-xs font-bold" style={{ color }}>{s.count}</span>
                  </div>
                  <div className="h-1.5 rounded-full" style={{ background:'var(--bg-input)' }}>
                    <div className="h-full rounded-full" style={{ width:`${(s.count/maxS)*100}%`, background:color }}/>
                  </div>
                </div>
              )
            }) : (
              <p className="text-sm text-center py-8" style={{ color: 'var(--text-muted)' }}>No source data available</p>
            )}
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <div className="card-3d lg:col-span-2" style={{ padding:'24px' }}>
          <div className="flex items-center justify-between mb-4">
            <h2 className="font-bold text-base" style={{ color:'var(--text-h)' }}>Recent Manpower Requests</h2>
            <button onClick={()=>navigate('/app/hr/manpower-requests')} className="text-xs font-semibold flex items-center gap-1" style={{ color:'#a78bfa' }}>View all <ArrowRight size={11}/></button>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead><tr style={{ borderBottom:'1px solid var(--border)' }}>{['ID','Department','Position','Posts','Priority','Status','Date'].map(h=><th key={h} className="pb-2 text-left pr-3 label-caps">{h}</th>)}</tr></thead>
              <tbody>
                {recentRequests.length > 0 ? recentRequests.slice(0, 5).map(r=>{
                  const p=pc(r.priority)
                  const s=statusColor(r.status)
                  return(
                    <tr key={r.id} style={{ borderBottom:'1px solid var(--border)' }} onMouseEnter={e=>e.currentTarget.style.background='rgba(124,58,237,0.04)'} onMouseLeave={e=>e.currentTarget.style.background='transparent'}>
                      <td className="py-2.5 pr-3 font-bold" style={{ color:'#a78bfa' }}>MR-{r.id}</td>
                      <td className="py-2.5 pr-3" style={{ color:'var(--text-h)' }}>{r.department}</td>
                      <td className="py-2.5 pr-3 max-w-[120px] truncate" style={{ color:'var(--text-muted)' }}>{r.position_title}</td>
                      <td className="py-2.5 pr-3 text-center font-bold" style={{ color:'var(--text-h)' }}>{r.number_of_posts}</td>
                      <td className="py-2.5 pr-3"><span className="px-2 py-0.5 rounded-lg text-[10px] font-bold" style={{ background:p.bg,color:p.color,border:`1px solid ${p.bdr}` }}>{r.priority}</span></td>
                      <td className="py-2.5 pr-3"><span className="px-2 py-0.5 rounded-lg text-[10px] font-bold" style={{ background:s.bg,color:s.color }}>{statusLabel(r.status)}</span></td>
                      <td className="py-2.5" style={{ color:'var(--text-muted)' }}>{new Date(r.created_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })}</td>
                    </tr>
                  )
                }) : (
                  <tr><td colSpan="7" className="py-8 text-center" style={{ color: 'var(--text-muted)' }}>No requests found</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </div>

        <div className="space-y-4">
          <div className="card-3d" style={{ padding:'20px' }}>
            <h2 className="font-bold text-sm mb-3 flex items-center gap-2" style={{ color:'var(--text-h)' }}>
              <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"/>Today's Interviews
            </h2>
            <div className="space-y-2.5">
              {todayInterviews.length > 0 ? todayInterviews.map((iv,i)=>(
                <div key={i} className="flex items-center gap-2.5 p-2.5 rounded-xl" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                  <div className="w-8 h-8 rounded-xl flex items-center justify-center text-[10px] font-black text-white flex-shrink-0" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
                    {iv.candidate ? iv.candidate.split(' ').map(n=>n[0]).join('').slice(0,2) : '??'}
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="text-xs font-bold truncate" style={{ color:'var(--text-h)' }}>{iv.candidate || 'N/A'}</p>
                    <p className="text-[10px]" style={{ color:'var(--text-muted)' }}>{iv.round || 'Interview'} · {iv.time || 'TBD'}</p>
                  </div>
                </div>
              )) : (
                <p className="text-xs text-center py-4" style={{ color: 'var(--text-muted)' }}>No interviews today</p>
              )}
            </div>
          </div>
          <NotificationWidget />
        </div>
      </div>
    </div>
  )
}
