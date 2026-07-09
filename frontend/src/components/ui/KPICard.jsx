import { TrendingUp, TrendingDown } from 'lucide-react'

export default function KPICard({ label, value, icon: Icon, gradient, shadowColor, trend, trendLabel }) {
  const hasTrend = trend !== undefined && trend !== null
  const isUp = trend >= 0

  return (
    <div className="kpi-3d" style={{ cursor: 'default' }}>
      <div
        className="absolute -top-6 -right-6 w-28 h-28 rounded-full opacity-[0.07] pointer-events-none"
        style={{ background: gradient }}
      />

      <div className="flex items-start justify-between relative z-10">
        <div
          className="w-12 h-12 rounded-2xl flex items-center justify-center flex-shrink-0"
          style={{
            background: gradient,
            boxShadow: `0 8px 24px ${shadowColor}40, 0 2px 6px ${shadowColor}30, inset 0 1px 0 rgba(255,255,255,0.25)`,
          }}
        >
          {Icon && <Icon size={22} className="text-white" strokeWidth={2} />}
        </div>

        {hasTrend && (
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
        )}
      </div>

      <div className="relative z-10 mt-4">
        <p
          className="font-black"
          style={{ fontSize: 'clamp(1.5rem,3vw,2rem)', letterSpacing: '-0.03em', color: 'var(--text-h)', lineHeight: 1 }}
        >
          {value ?? '—'}
        </p>
        <p className="text-sm font-semibold mt-1.5" style={{ color: 'var(--text-muted)' }}>{label}</p>
      </div>

      {trendLabel && (
        <div className="relative z-10 mt-3 flex items-center gap-1.5">
          <div className="w-1 h-1 rounded-full" style={{ background: isUp ? '#10b981' : '#ef4444' }} />
          <p className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{trendLabel}</p>
        </div>
      )}
    </div>
  )
}

export function KPICardSkeleton() {
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
