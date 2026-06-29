import { useQuery } from '@tanstack/react-query'
import { useAuth } from '@/context/AuthContext'
import { useTheme } from '@/context/ThemeContext'
import {
  Users, Briefcase, CheckSquare, Receipt, TrendingUp,
  TrendingDown, ArrowRight, Clock, Activity, Zap, Target
} from 'lucide-react'
import api from '@/lib/api'

// ── Skeleton ──────────────────────────────────────────────────────
function SkeletonCard() {
  return (
    <div className="kpi-3d space-y-4">
      <div className="flex justify-between">
        <div className="skeleton w-11 h-11 rounded-2xl" style={{ background: 'var(--border)' }} />
        <div className="skeleton w-12 h-7 rounded-lg" style={{ background: 'var(--border)' }} />
      </div>
      <div>
        <div className="skeleton h-7 w-16 rounded-xl mt-2 mb-1" style={{ background: 'var(--border)' }} />
        <div className="skeleton h-3 w-24 rounded-lg" style={{ background: 'var(--border)' }} />
      </div>
    </div>
  )
}

// ── 3D KPI Card ───────────────────────────────────────────────────
function KpiCard({ label, value, icon: Icon, gradient, shadowColor, trend, trendLabel, isDark }) {
  const isUp = trend >= 0
  return (
    <div
      className="kpi-3d"
      style={{ cursor: 'default' }}
    >
      {/* Gradient glow blob behind card */}
      <div
        className="absolute -top-6 -right-6 w-28 h-28 rounded-full opacity-[0.07] pointer-events-none"
        style={{ background: gradient }}
      />

      {/* Top row */}
      <div className="flex items-start justify-between relative z-10">
        {/* 3D Icon */}
        <div
          className="w-12 h-12 rounded-2xl flex items-center justify-center flex-shrink-0"
          style={{
            background: gradient,
            boxShadow: `0 8px 24px ${shadowColor}40, 0 2px 6px ${shadowColor}30, inset 0 1px 0 rgba(255,255,255,0.25)`,
          }}
        >
          <Icon size={22} className="text-white" strokeWidth={2} />
        </div>

        {/* Trend badge */}
        <div
          className="flex items-center gap-1 text-xs font-bold px-2.5 py-1.5 rounded-xl"
          style={{
            background: isUp ? 'rgba(16,185,129,0.12)' : 'rgba(239,68,68,0.12)',
            color: isUp ? '#10b981' : '#ef4444',
            border: `1px solid ${isUp ? 'rgba(16,185,129,0.2)' : 'rgba(239,68,68,0.2)'}`,
          }}
        >
          {isUp ? <TrendingUp size={11} /> : <TrendingDown size={11} />}
          {Math.abs(trend)}%
        </div>
      </div>

      {/* Value */}
      <div className="relative z-10 mt-4">
        <p
          className="font-black"
          style={{ fontSize: 'clamp(1.5rem,3vw,2rem)', letterSpacing: '-0.03em', color: 'var(--text-h)', lineHeight: 1 }}
        >
          {value ?? '—'}
        </p>
        <p className="text-sm font-semibold mt-1.5" style={{ color: 'var(--text-muted)' }}>{label}</p>
      </div>

      {/* Trend label */}
      <div className="relative z-10 mt-3 flex items-center gap-1.5">
        <div className="w-1 h-1 rounded-full" style={{ background: isUp ? '#10b981' : '#ef4444' }} />
        <p className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{trendLabel}</p>
      </div>
    </div>
  )
}

// ── Activity Item ─────────────────────────────────────────────────
function ActivityItem({ action, description, time, idx }) {
  const colors = ['#7C3AED','#10b981','#f59e0b','#3b82f6','#ec4899']
  const color = colors[idx % colors.length]
  return (
    <div
      className="flex items-start gap-3 py-3 transition-all duration-150 rounded-xl px-2 -mx-2"
      style={{ borderBottom: '1px solid var(--border)' }}
      onMouseEnter={e => e.currentTarget.style.background = 'rgba(124,58,237,0.04)'}
      onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
    >
      {/* Colored 3D icon */}
      <div
        className="w-9 h-9 rounded-2xl flex items-center justify-center flex-shrink-0 mt-0.5"
        style={{
          background: `linear-gradient(135deg,${color}30,${color}15)`,
          border: `1px solid ${color}25`,
          boxShadow: `0 3px 10px ${color}15`,
        }}
      >
        <Activity size={14} style={{ color }} />
      </div>
      <div className="flex-1 min-w-0">
        <p className="text-sm font-semibold truncate" style={{ color: 'var(--text-h)' }}>{action}</p>
        <p className="text-xs truncate mt-0.5" style={{ color: 'var(--text-muted)' }}>{description}</p>
      </div>
      <div
        className="flex items-center gap-1 text-xs flex-shrink-0 px-2 py-1 rounded-lg"
        style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}
      >
        <Clock size={9} />
        {time}
      </div>
    </div>
  )
}

// ── Main Component ────────────────────────────────────────────────
export default function DashboardPage() {
  const { user } = useAuth()
  const { isDark } = useTheme()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['dashboard'],
    queryFn: () => api.get('/dashboard').then(r => r.data.data),
    // Keep showing placeholder until real modules are implemented
    placeholderData: {
      contacts_count: 128,
      open_deals: 34,
      tasks_due_today: 7,
      overdue_invoices: 3,
      pipeline_value: 284500,
      win_rate: 68,
    },
    // Only show real data if at least one count is non-zero
    select: (apiData) => {
      const hasRealData = apiData.contacts_count > 0 || 
                          apiData.open_deals > 0 || 
                          apiData.tasks_due_today > 0
      return hasRealData ? apiData : {
        contacts_count: 128,
        open_deals: 34,
        tasks_due_today: 7,
        overdue_invoices: 3,
        pipeline_value: 284500,
        win_rate: 68,
      }
    },
  })

  const kpis = [
    {
      label: 'Total Contacts',
      value: data?.contacts_count?.toLocaleString(),
      icon: Users,
      gradient: 'linear-gradient(145deg,#9f67ff,#7C3AED,#5b21b6)',
      shadowColor: '#7C3AED',
      trend: 12,
      trendLabel: 'vs last month',
    },
    {
      label: 'Open Deals',
      value: data?.open_deals,
      icon: Briefcase,
      gradient: 'linear-gradient(145deg,#34d399,#10B981,#059669)',
      shadowColor: '#10b981',
      trend: 8,
      trendLabel: `$${((data?.pipeline_value ?? 0) / 1000).toFixed(0)}K pipeline`,
    },
    {
      label: 'Tasks Due Today',
      value: data?.tasks_due_today,
      icon: CheckSquare,
      gradient: 'linear-gradient(145deg,#fcd34d,#F59E0B,#d97706)',
      shadowColor: '#f59e0b',
      trend: -3,
      trendLabel: 'vs yesterday',
    },
    {
      label: 'Overdue Invoices',
      value: data?.overdue_invoices,
      icon: Receipt,
      gradient: 'linear-gradient(145deg,#f87171,#EF4444,#dc2626)',
      shadowColor: '#ef4444',
      trend: -1,
      trendLabel: 'needs attention',
    },
  ]

  const recentActivity = [
    { action: 'New deal created',  description: 'Acme Corp — $12,500',              time: '2m ago'  },
    { action: 'Invoice sent',      description: 'INV-2024-042 to TechCorp Ltd',      time: '1h ago'  },
    { action: 'Contact added',     description: 'Sarah Johnson — Globex Inc.',       time: '2h ago'  },
    { action: 'Deal won 🎉',       description: 'Initech Partnership — $45,000',    time: '3h ago'  },
    { action: 'Task completed',    description: 'Review Q2 proposal document',       time: '5h ago'  },
  ]

  const barData = [40, 65, 30, 80, 55, 45, 70, 88, 60, 75, 50, 90]
  const months  = ['J','F','M','A','M','J','J','A','S','O','N','D']

  return (
    <div className="space-y-6">

      {/* ── Greeting Banner ─────────────────────────────────── */}
      <div
        className="relative rounded-3xl p-6 overflow-hidden"
        style={{
          background: isDark
            ? 'linear-gradient(135deg, rgba(124,58,237,0.2) 0%, rgba(91,33,182,0.12) 50%, rgba(11,11,22,0) 100%)'
            : 'linear-gradient(135deg, rgba(124,58,237,0.12) 0%, rgba(167,139,250,0.08) 50%, rgba(240,240,248,0) 100%)',
          border: '1px solid var(--border-purple)',
          boxShadow: isDark
            ? '0 8px 32px rgba(124,58,237,0.15), inset 0 1px 0 rgba(255,255,255,0.06)'
            : '0 8px 32px rgba(124,58,237,0.1), inset 0 1px 0 rgba(255,255,255,1)',
        }}
      >
        {/* Background orbs */}
        <div className="absolute top-0 right-0 w-48 h-48 rounded-full opacity-10 pointer-events-none"
             style={{ background: 'radial-gradient(circle, #7C3AED, transparent)', transform: 'translate(30%, -30%)' }} />
        <div className="absolute bottom-0 left-24 w-32 h-32 rounded-full opacity-05 pointer-events-none"
             style={{ background: 'radial-gradient(circle, #a78bfa, transparent)', transform: 'translate(-20%, 30%)' }} />

        <div className="relative z-10 flex items-center justify-between gap-4">
          <div>
            <p className="label-caps mb-1">{getGreeting()}</p>
            <h1 className="font-black" style={{ fontSize: 'clamp(1.5rem,3vw,2.2rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
              Welcome back, <span className="text-gradient">{user?.name?.split(' ')[0] ?? 'there'}</span> 👋
            </h1>
            <p className="text-sm mt-1.5" style={{ color: 'var(--text-muted)' }}>
              Here's what's happening in your workspace today.
            </p>
          </div>
          {/* Floating 3D badge */}
          <div
            className="hidden md:flex flex-col items-center justify-center w-20 h-20 rounded-3xl flex-shrink-0"
            style={{
              background: 'linear-gradient(145deg,#9f67ff,#7C3AED)',
              boxShadow: '0 12px 35px rgba(124,58,237,0.4), 0 4px 10px rgba(0,0,0,0.2), inset 0 1px 0 rgba(255,255,255,0.25)',
              transform: 'perspective(200px) rotateY(-8deg) rotateX(4deg)',
            }}
          >
            <Zap size={28} className="text-white" />
            <span className="text-white text-[10px] font-black mt-0.5">LIVE</span>
          </div>
        </div>
      </div>

      {/* ── KPI Cards Grid ──────────────────────────────────── */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {isLoading
          ? Array(4).fill(0).map((_, i) => <SkeletonCard key={i} />)
          : kpis.map(kpi => <KpiCard key={kpi.label} isDark={isDark} {...kpi} />)
        }
      </div>

      {/* ── Charts + Activity ───────────────────────────────── */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {/* Pipeline Chart — 3D bar chart */}
        <div
          className="card-3d lg:col-span-2"
          style={{ padding: '24px' }}
        >
          <div className="flex items-center justify-between mb-6">
            <div>
              <h2 className="text-base font-bold" style={{ color: 'var(--text-h)' }}>Pipeline Overview</h2>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Revenue by month</p>
            </div>
            <div className="flex items-center gap-2">
              <span
                className="flex items-center gap-1.5 text-xs font-bold px-2.5 py-1.5 rounded-xl"
                style={{ background: 'rgba(16,185,129,0.12)', color: '#10b981', border: '1px solid rgba(16,185,129,0.2)' }}
              >
                <div className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse" /> Live
              </span>
            </div>
          </div>

          {/* 3D Bar Chart */}
          <div className="relative h-44 flex items-end justify-between gap-1.5 px-1">
            {barData.map((h, i) => (
              <div key={i} className="flex flex-col items-center flex-1 gap-1.5">
                <div className="w-full relative" style={{ height: '160px', display: 'flex', alignItems: 'flex-end' }}>
                  {/* 3D bar */}
                  <div
                    className="w-full rounded-t-xl transition-all duration-700 relative group cursor-pointer"
                    style={{
                      height: `${h}%`,
                      background: i === barData.indexOf(Math.max(...barData))
                        ? 'linear-gradient(180deg,#a78bfa,#7C3AED)'
                        : isDark
                          ? 'linear-gradient(180deg,rgba(167,139,250,0.5),rgba(124,58,237,0.3))'
                          : 'linear-gradient(180deg,rgba(167,139,250,0.7),rgba(124,58,237,0.5))',
                      boxShadow: i === barData.indexOf(Math.max(...barData))
                        ? '0 -4px 20px rgba(124,58,237,0.4)'
                        : 'none',
                    }}
                    onMouseEnter={e => {
                      e.currentTarget.style.background = 'linear-gradient(180deg,#c4b5fd,#7C3AED)'
                      e.currentTarget.style.boxShadow = '0 -4px 20px rgba(124,58,237,0.4)'
                    }}
                    onMouseLeave={e => {
                      e.currentTarget.style.background = i === barData.indexOf(Math.max(...barData))
                        ? 'linear-gradient(180deg,#a78bfa,#7C3AED)'
                        : isDark
                          ? 'linear-gradient(180deg,rgba(167,139,250,0.5),rgba(124,58,237,0.3))'
                          : 'linear-gradient(180deg,rgba(167,139,250,0.7),rgba(124,58,237,0.5))'
                      e.currentTarget.style.boxShadow = i === barData.indexOf(Math.max(...barData))
                        ? '0 -4px 20px rgba(124,58,237,0.4)' : 'none'
                    }}
                  >
                    {/* Bar top highlight (3D effect) */}
                    <div className="absolute top-0 left-0 right-0 h-1 rounded-t-xl" style={{ background: 'rgba(255,255,255,0.3)' }} />
                  </div>
                </div>
                <span className="text-[9px] font-bold" style={{ color: 'var(--text-muted)' }}>{months[i]}</span>
              </div>
            ))}
            {/* Grid lines */}
            {[25,50,75,100].map(p => (
              <div
                key={p}
                className="absolute left-0 right-0 border-t"
                style={{ bottom: `${p * 1.6 + 20}px`, borderColor: 'var(--border)', borderStyle: 'dashed' }}
              />
            ))}
          </div>
          <p className="text-xs text-center mt-4" style={{ color: 'var(--text-muted)' }}>
            💡 Full interactive charts coming with Recharts
          </p>
        </div>

        {/* Activity Feed */}
        <div className="card-3d" style={{ padding: '24px' }}>
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-base font-bold" style={{ color: 'var(--text-h)' }}>Recent Activity</h2>
            <button
              className="flex items-center gap-1 text-xs font-semibold transition-all"
              style={{ color: '#a78bfa' }}
              onMouseEnter={e => e.currentTarget.style.gap = '4px'}
              onMouseLeave={e => e.currentTarget.style.gap = '4px'}
            >
              View all <ArrowRight size={12} />
            </button>
          </div>
          <div className="space-y-0">
            {recentActivity.map((item, i) => (
              <ActivityItem key={i} idx={i} {...item} />
            ))}
          </div>
        </div>
      </div>

      {/* ── Win Rate 3D Banner ──────────────────────────────── */}
      <div
        className="card-3d flex items-center justify-between gap-6 relative overflow-hidden"
        style={{
          padding: '24px 28px',
          background: isDark
            ? 'linear-gradient(135deg,rgba(124,58,237,0.2),rgba(91,33,182,0.15))'
            : 'linear-gradient(135deg,rgba(124,58,237,0.1),rgba(167,139,250,0.08))',
          borderColor: 'rgba(124,58,237,0.3)',
        }}
      >
        {/* BG orb */}
        <div className="absolute right-8 opacity-[0.08] pointer-events-none"
             style={{ background: 'radial-gradient(circle,#7C3AED,transparent)', width: 200, height: 200, borderRadius: '50%' }} />

        <div className="relative z-10">
          <p className="label-caps mb-1.5">Deal Win Rate</p>
          <p className="font-black" style={{ fontSize: 'clamp(2rem,4vw,3rem)', color: 'var(--text-h)', letterSpacing: '-0.03em', lineHeight: 1 }}>
            {data?.win_rate ?? 68}<span className="text-gradient">%</span>
          </p>
          <div className="flex items-center gap-1.5 mt-2">
            <TrendingUp size={14} style={{ color: '#10b981' }} />
            <p className="text-sm font-medium" style={{ color: '#10b981' }}>+4% vs last quarter</p>
          </div>
        </div>

        {/* 3D ring */}
        <div className="relative flex-shrink-0 relative z-10">
          <div
            className="w-24 h-24 rounded-full flex items-center justify-center"
            style={{
              background: 'conic-gradient(#7C3AED 0%, #7C3AED ' + (data?.win_rate ?? 68) + '%, var(--bg-input) ' + (data?.win_rate ?? 68) + '%)',
              padding: '4px',
            }}
          >
            <div
              className="w-full h-full rounded-full flex items-center justify-center"
              style={{ background: isDark ? '#1c1c2e' : '#ffffff' }}
            >
              <div className="text-center">
                <Target size={18} style={{ color: '#7C3AED', margin: '0 auto 2px' }} />
                <span className="text-sm font-black" style={{ color: 'var(--text-h)' }}>{data?.win_rate ?? 68}%</span>
              </div>
            </div>
          </div>
          {/* 3D ring drop shadow */}
          <div
            className="absolute inset-0 rounded-full"
            style={{ boxShadow: '0 8px 24px rgba(124,58,237,0.3)', borderRadius: '50%', pointerEvents: 'none' }}
          />
        </div>
      </div>
    </div>
  )
}

function getGreeting() {
  const h = new Date().getHours()
  if (h < 12) return 'Good Morning'
  if (h < 17) return 'Good Afternoon'
  return 'Good Evening'
}
