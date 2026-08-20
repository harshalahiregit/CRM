import { useState, useEffect } from 'react'
import { TrendingUp, CheckCircle2, Clock, AlertTriangle, ListChecks, ClipboardCheck, Loader2 } from 'lucide-react'
import { kickoffApi } from '@/services/kickoffApi'
import { KIT3D_STYLE } from '@/components/ui/kit3d'

/**
 * Meeting Performance (Meeting.docx — the "Performance" top-nav item + §14
 * effectiveness). A focused analytics view over the meetings engine: action
 * closure effectiveness plus the by-type / by-project / by-vendor breakdowns.
 * Reads the same dashboard aggregate the Meetings registry summarises. */
export default function MeetingPerformance() {
  const [stats, setStats] = useState(null)
  const [err, setErr] = useState(null)

  useEffect(() => {
    kickoffApi.dashboard().then(setStats).catch(() => setErr('Could not load performance data.'))
  }, [])

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>
      <div style={{ marginBottom: 20 }}>
        <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>MEETINGS</p>
        <h1 style={{ color: 'var(--text-h)', fontSize: 24, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>Performance</h1>
        <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Action closure effectiveness and meeting distribution across types, projects and vendors.</p>
      </div>

      {err && <div className="pr-glass" style={{ padding: 18, color: 'var(--text-muted)', fontSize: 13 }}>{err}</div>}
      {!stats && !err && <div style={{ display: 'flex', justifyContent: 'center', padding: 60 }}><Loader2 size={26} className="mp-spin" style={{ color: '#a78bfa' }} /><style>{'@keyframes mpS{to{transform:rotate(360deg)}}.mp-spin{animation:mpS .9s linear infinite}'}</style></div>}

      {stats && (
        <>
          {/* Effectiveness hero */}
          <div className="pr-glass" style={{ padding: 22, marginBottom: 16 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 12 }}>
              <TrendingUp size={18} style={{ color: '#a78bfa' }} />
              <h2 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Action closure rate</h2>
            </div>
            <div style={{ display: 'flex', alignItems: 'baseline', gap: 10 }}>
              <span style={{ fontSize: 42, fontWeight: 900, lineHeight: 1, color: rateColor(stats.closure_rate) }}>{stats.closure_rate}%</span>
              <span style={{ fontSize: 13, color: 'var(--text-muted)' }}>{stats.closed_actions}/{stats.total_actions} actions closed</span>
            </div>
            <div style={{ height: 9, borderRadius: 999, background: 'var(--bg-input)', overflow: 'hidden', margin: '14px 0 0' }}>
              <div style={{ height: '100%', width: `${stats.closure_rate}%`, borderRadius: 999, background: rateColor(stats.closure_rate) }} />
            </div>
          </div>

          {/* Action / meeting KPIs */}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))', gap: 12, marginBottom: 18 }}>
            <Kpi label="Meetings" value={stats.total} icon={ClipboardCheck} color="#7C3AED" />
            <Kpi label="Completed" value={stats.completed} icon={CheckCircle2} color="#10b981" />
            <Kpi label="Open actions" value={stats.open_actions} icon={ListChecks} color="#a78bfa" />
            <Kpi label="Overdue actions" value={stats.overdue_actions} icon={AlertTriangle} color="#ef4444" danger={stats.overdue_actions > 0} />
            <Kpi label="Pending MOM" value={stats.pending_mom} icon={Clock} color="#f59e0b" danger={stats.pending_mom > 0} />
            <Kpi label="Decisions active" value={stats.decisions_active} icon={ClipboardCheck} color="#0ea5e9" />
          </div>

          {/* Breakdowns */}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(260px,1fr))', gap: 14 }}>
            <Breakdown title="By type" rows={stats.by_type} keyField="type" />
            <Breakdown title="By project" rows={stats.by_project} keyField="project_id" empty="No project-linked meetings" />
            <Breakdown title="By vendor" rows={stats.by_vendor} keyField="name" empty="No vendor meetings" />
          </div>
        </>
      )}
    </div>
  )
}

const rateColor = (r) => (r >= 70 ? '#10b981' : r >= 40 ? '#f59e0b' : '#ef4444')

function Kpi({ label, value, icon: Icon, color, danger }) {
  return (
    <div className="pr-kpi" style={{ padding: 16, outline: danger ? `1.5px solid ${color}66` : 'none' }}>
      <div style={{ width: 36, height: 36, borderRadius: 11, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `${color}1f` }}>
        <Icon size={17} style={{ color }} />
      </div>
      <div style={{ fontSize: 23, fontWeight: 900, color: 'var(--text-h)', marginTop: 10, lineHeight: 1 }}>{value ?? 0}</div>
      <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-muted)', marginTop: 4 }}>{label}</div>
    </div>
  )
}

function Breakdown({ title, rows, keyField, empty = 'No data' }) {
  const list = rows || []
  const max = Math.max(1, ...list.map(r => r.count || 0))
  return (
    <div className="pr-glass" style={{ padding: 16 }}>
      <div style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', marginBottom: 12 }}>{title}</div>
      {list.length === 0 ? (
        <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: 0, fontStyle: 'italic' }}>{empty}</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 9 }}>
          {list.slice(0, 10).map(r => (
            <div key={String(r[keyField])} style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
              <span style={{ flex: 1, minWidth: 0, fontSize: 12.5, color: 'var(--text-h)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{r.label || r.name}</span>
              <span style={{ width: 70, height: 6, borderRadius: 999, background: 'var(--bg-input)', overflow: 'hidden', flexShrink: 0 }}>
                <span style={{ display: 'block', height: '100%', width: `${Math.round((r.count / max) * 100)}%`, background: '#a78bfa', borderRadius: 999 }} />
              </span>
              <span style={{ fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)', minWidth: 24, textAlign: 'right' }}>{r.count}</span>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
