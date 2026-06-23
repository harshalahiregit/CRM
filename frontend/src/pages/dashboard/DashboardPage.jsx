import { useQuery } from '@tanstack/react-query'
import { useAuth } from '@/context/AuthContext'
import {
  Users, Briefcase, CheckSquare, Receipt, TrendingUp,
  TrendingDown, ArrowRight, Clock, Activity
} from 'lucide-react'
import api from '@/lib/api'

// ── Skeleton loader ──────────────────────────────────────────────
function SkeletonCard() {
  return (
    <div className="card space-y-3">
      <div className="skeleton h-4 w-24 rounded" />
      <div className="skeleton h-8 w-16 rounded" />
      <div className="skeleton h-3 w-32 rounded" />
    </div>
  )
}

// ── KPI Card ─────────────────────────────────────────────────────
function KpiCard({ label, value, icon: Icon, color, trend, trendLabel }) {
  const isUp = trend >= 0
  return (
    <div className="kpi-card group">
      <div className="flex items-start justify-between">
        <div className={`w-10 h-10 rounded-xl flex items-center justify-center ${color}`}>
          <Icon size={20} className="text-white" />
        </div>
        <div className={`flex items-center gap-1 text-xs font-medium
          ${isUp ? 'text-success' : 'text-danger'}`}>
          {isUp ? <TrendingUp size={13} /> : <TrendingDown size={13} />}
          {Math.abs(trend)}%
        </div>
      </div>
      <div>
        <p className="text-2xl font-bold text-white mt-2">{value ?? '—'}</p>
        <p className="text-sm text-gray-400">{label}</p>
      </div>
      <p className="text-xs text-gray-600">{trendLabel}</p>
    </div>
  )
}

// ── Activity Item ─────────────────────────────────────────────────
function ActivityItem({ action, description, time }) {
  return (
    <div className="flex items-start gap-3 py-2.5 border-b border-white/5 last:border-0">
      <div className="w-8 h-8 rounded-full bg-primary-600/20 flex items-center justify-center flex-shrink-0 mt-0.5">
        <Activity size={14} className="text-primary-400" />
      </div>
      <div className="flex-1 min-w-0">
        <p className="text-sm text-white font-medium truncate">{action}</p>
        <p className="text-xs text-gray-500 truncate">{description}</p>
      </div>
      <div className="flex items-center gap-1 text-xs text-gray-600 flex-shrink-0">
        <Clock size={11} />
        {time}
      </div>
    </div>
  )
}

export default function DashboardPage() {
  const { user } = useAuth()

  // Fetch dashboard data (will work once backend is ready)
  const { data, isLoading } = useQuery({
    queryKey: ['dashboard'],
    queryFn: () => api.get('/dashboard').then(r => r.data.data),
    // Use placeholder data until API is ready
    placeholderData: {
      contacts_count: 128,
      open_deals: 34,
      tasks_due_today: 7,
      overdue_invoices: 3,
      pipeline_value: 284500,
      win_rate: 68,
    },
  })

  const kpis = [
    {
      label: 'Total Contacts',
      value: data?.contacts_count?.toLocaleString(),
      icon: Users,
      color: 'bg-blue-500/30',
      trend: 12,
      trendLabel: 'vs last month',
    },
    {
      label: 'Open Deals',
      value: data?.open_deals,
      icon: Briefcase,
      color: 'bg-purple-500/30',
      trend: 8,
      trendLabel: `$${(data?.pipeline_value / 1000).toFixed(0)}K pipeline`,
    },
    {
      label: 'Tasks Due Today',
      value: data?.tasks_due_today,
      icon: CheckSquare,
      color: 'bg-orange-500/30',
      trend: -3,
      trendLabel: 'vs yesterday',
    },
    {
      label: 'Overdue Invoices',
      value: data?.overdue_invoices,
      icon: Receipt,
      color: 'bg-red-500/30',
      trend: -1,
      trendLabel: 'needs attention',
    },
  ]

  const recentActivity = [
    { action: 'New deal created', description: 'Acme Corp — $12,500', time: '2m ago' },
    { action: 'Invoice sent', description: 'INV-2024-042 to TechCorp Ltd', time: '1h ago' },
    { action: 'Contact added', description: 'Sarah Johnson — Globex Inc.', time: '2h ago' },
    { action: 'Deal won 🎉', description: 'Initech Partnership — $45,000', time: '3h ago' },
    { action: 'Task completed', description: 'Review Q2 proposal document', time: '5h ago' },
  ]

  return (
    <div className="space-y-6 animate-fade-in">
      {/* Greeting */}
      <div>
        <h1 className="text-2xl font-bold text-white">
          Good {getGreeting()},{' '}
          <span className="text-gradient">{user?.name?.split(' ')[0] ?? 'there'}</span> 👋
        </h1>
        <p className="text-gray-400 text-sm mt-1">Here's what's happening in your CRM today.</p>
      </div>

      {/* KPI Cards */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {isLoading
          ? Array(4).fill(0).map((_, i) => <SkeletonCard key={i} />)
          : kpis.map(kpi => <KpiCard key={kpi.label} {...kpi} />)
        }
      </div>

      {/* Charts placeholder + Activity Feed */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {/* Pipeline Chart placeholder */}
        <div className="card lg:col-span-2 space-y-3">
          <div className="flex items-center justify-between">
            <h2 className="text-base font-semibold text-white">Pipeline Overview</h2>
            <span className="badge badge-info">Live</span>
          </div>
          <div className="h-48 flex items-center justify-center rounded-lg bg-white/5 border border-white/5">
            <div className="text-center space-y-2">
              <div className="flex items-end justify-center gap-2 h-24">
                {[40, 65, 30, 80, 55, 45, 70].map((h, i) => (
                  <div key={i} className="w-8 rounded-t-md bg-primary-600/60 transition-all duration-500"
                    style={{ height: `${h}%` }} />
                ))}
              </div>
              <p className="text-xs text-gray-500">Revenue by month — Full chart coming with Recharts</p>
            </div>
          </div>
        </div>

        {/* Activity Feed */}
        <div className="card space-y-1">
          <div className="flex items-center justify-between mb-2">
            <h2 className="text-base font-semibold text-white">Recent Activity</h2>
            <button className="text-xs text-primary-400 hover:text-primary-300 flex items-center gap-1">
              View all <ArrowRight size={12} />
            </button>
          </div>
          {recentActivity.map((item, i) => (
            <ActivityItem key={i} {...item} />
          ))}
        </div>
      </div>

      {/* Win Rate Banner */}
      <div className="card flex items-center justify-between gap-4 bg-gradient-to-r
                      from-primary-600/20 to-purple-600/10 border-primary-500/30">
        <div>
          <p className="text-sm text-gray-400">Deal Win Rate</p>
          <p className="text-3xl font-bold text-white mt-0.5">{data?.win_rate ?? 68}%</p>
          <p className="text-xs text-success flex items-center gap-1 mt-1">
            <TrendingUp size={12} /> +4% vs last quarter
          </p>
        </div>
        <div className="w-24 h-24 rounded-full border-4 border-primary-500/40
                        flex items-center justify-center relative flex-shrink-0">
          <div className="absolute inset-0 rounded-full border-4 border-primary-500"
            style={{ clipPath: `inset(0 0 0 ${100 - (data?.win_rate ?? 68)}%)` }} />
          <span className="text-xl font-bold text-primary-400">{data?.win_rate ?? 68}%</span>
        </div>
      </div>
    </div>
  )
}

function getGreeting() {
  const h = new Date().getHours()
  if (h < 12) return 'morning'
  if (h < 17) return 'afternoon'
  return 'evening'
}
