import { useCallback, useEffect, useState } from 'react'
import { BarChart3, UserRound, Building2, Flag, Timer } from 'lucide-react'
import LoadError from '@/components/ui/LoadError'
import { KIT3D_STYLE } from '@/components/ui/kit3d'
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'
import ReportShell, { Card, Empty, Table, Stat, StatRow, download } from '@/components/reports/ReportShell'

/**
 * The Help Desk report.
 *
 * Deliberately not the analytics dashboard: that answers "what needs attention
 * right now", while this is pointed at a range, narrowed and exported.
 *
 * Tickets that were never answered are shown as their own figure rather than
 * averaged in as zero-minute responses — a report that buries them inside an
 * average reports the worst cases as the best ones.
 */
export default function HelpdeskReports() {
  const [report, setReport] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [filters, setFilters] = useState({ from: '', to: '', status: '', priority: '', assigned_to: '' })

  const load = useCallback(() => {
    const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v))
    api.get('/helpdesk/reports', { params }).then(r => r.data?.data ?? r.data).catch(handleErr)
      .then(d => { setLoadError(null); setReport(d) })
      .catch(e => { setReport(null); setLoadError(e) })
  }, [filters])

  useEffect(() => { load() }, [load])

  const t = report?.totals

  /** Minutes are unreadable past an hour or two; say it the way a person would. */
  const dur = (mins) => {
    if (mins === null || mins === undefined) return '—'
    if (mins < 60) return `${mins} min`
    if (mins < 1440) return `${(mins / 60).toFixed(1)} hr`
    return `${(mins / 1440).toFixed(1)} days`
  }

  return (
    <div style={{ padding: 4 }}>
      <style>{KIT3D_STYLE}</style>

      <ReportShell
        eyebrow="HELP DESK"
        title="Help Desk report"
        subtitle="Volume, outcome, and how long people waited."
        onRefresh={load}
        onExport={(format) => download('/helpdesk/reports/export', filters, format, `helpdesk-report.${format}`)}
        filters={
          <>
            <Field label="From">
              <input type="date" value={filters.from} onChange={e => setFilters(f => ({ ...f, from: e.target.value }))} className="input-3d text-sm" />
            </Field>
            <Field label="To">
              <input type="date" value={filters.to} onChange={e => setFilters(f => ({ ...f, to: e.target.value }))} className="input-3d text-sm" />
            </Field>
            <Field label="Status">
              <select value={filters.status} onChange={e => setFilters(f => ({ ...f, status: e.target.value }))} className="input-3d text-sm">
                <option value="">Any status</option>
                {(report?.by_status ?? []).map(s => <option key={s.status} value={s.status}>{s.status}</option>)}
              </select>
            </Field>
            <Field label="Priority">
              <select value={filters.priority} onChange={e => setFilters(f => ({ ...f, priority: e.target.value }))} className="input-3d text-sm">
                <option value="">Any priority</option>
                {(report?.by_priority ?? []).map(p => <option key={p.priority} value={p.priority}>{p.priority}</option>)}
              </select>
            </Field>
            <Field label="Agent">
              <select value={filters.assigned_to} onChange={e => setFilters(f => ({ ...f, assigned_to: e.target.value }))} className="input-3d text-sm">
                <option value="">Everyone</option>
                {(report?.by_agent ?? []).filter(a => a.agent_id).map(a => (
                  <option key={a.agent_id} value={a.agent_id}>{a.agent}</option>
                ))}
              </select>
            </Field>
            {Object.values(filters).some(Boolean) && (
              <button onClick={() => setFilters({ from: '', to: '', status: '', priority: '', assigned_to: '' })}
                className="btn-3d text-sm px-3 py-2 self-end">Clear</button>
            )}
          </>
        }
      />

      {loadError ? <LoadError error={loadError} onRetry={load} />
        : !report ? <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>Loading…</p>
        : (
          <>
            <StatRow>
              <Stat label="Tickets" value={t.tickets} />
              <Stat label="Resolved" value={t.resolved} tone="#10b981" />
              <Stat label="Still open" value={t.open} tone="#f59e0b" />
              <Stat label="Resolution rate" value={t.resolution_rate === null ? '—' : `${t.resolution_rate}%`} />
              <Stat label="Never answered" value={t.never_answered} tone={t.never_answered > 0 ? '#ef4444' : undefined}
                sub="no reply at all" />
              <Stat label="SLA breached" value={t.sla_breached} tone={t.sla_breached > 0 ? '#ef4444' : undefined} />
              <Stat label="Reopened" value={t.reopened} />
            </StatRow>

            <Card title="How long people waited" icon={Timer} style={{ marginTop: 14 }}>
              <StatRow compact>
                <Stat label="Avg first response" value={dur(t.avg_response_mins)}
                  sub={`over ${t.answered_count} answered`} />
                <Stat label="Avg resolution" value={dur(t.avg_resolution_mins)}
                  sub={`over ${t.resolved_count} resolved`} />
              </StatRow>
              {t.never_answered > 0 && (
                <p className="text-[11.5px] mt-3 mb-0" style={{ color: 'var(--text-muted)' }}>
                  {t.never_answered} ticket{t.never_answered === 1 ? '' : 's'} received no reply at all and
                  {' '}<strong>are not</strong> in these averages — they are counted above instead, because
                  averaging them in as instant responses would make the figures look better than the service was.
                </p>
              )}
            </Card>

            <Card title="By agent" icon={UserRound} style={{ marginTop: 14 }}>
              {report.by_agent.length === 0 ? <Empty>No tickets in this range.</Empty> : (
                <Table
                  head={['Agent', 'Tickets', 'Resolved', 'Open', 'SLA breached', 'Avg first response', 'Avg resolution']}
                  rows={report.by_agent.map(a => [
                    a.agent, a.tickets, a.resolved, a.open, a.sla_breached,
                    dur(a.avg_response_mins), dur(a.avg_resolution_mins),
                  ])}
                />
              )}
            </Card>

            <Card title="By priority" icon={Flag} style={{ marginTop: 14 }}>
              {report.by_priority.length === 0 ? <Empty>No tickets in this range.</Empty> : (
                <Table
                  head={['Priority', 'Tickets', 'Resolved', 'Open', 'SLA breached', 'Avg resolution']}
                  rows={report.by_priority.map(p => [
                    p.priority, p.tickets, p.resolved, p.open, p.sla_breached, dur(p.avg_resolution_mins),
                  ])}
                />
              )}
            </Card>

            <Card title="By department" icon={Building2} style={{ marginTop: 14 }}>
              {report.by_department.length === 0 ? <Empty>No tickets in this range.</Empty> : (
                <Table
                  head={['Department', 'Tickets', 'Resolved', 'Open', 'SLA breached', 'Avg resolution']}
                  rows={report.by_department.map(d => [
                    d.department, d.tickets, d.resolved, d.open, d.sla_breached, dur(d.avg_resolution_mins),
                  ])}
                />
              )}
            </Card>

            <Card title="By status" icon={BarChart3} style={{ marginTop: 14 }}>
              {report.by_status.length === 0 ? <Empty>No tickets in this range.</Empty> : (
                <Table
                  head={['Status', 'Tickets', 'SLA breached', 'Avg resolution']}
                  rows={report.by_status.map(s => [s.status, s.tickets, s.sla_breached, dur(s.avg_resolution_mins)])}
                />
              )}
            </Card>

            <Card title="Month by month" icon={BarChart3} style={{ marginTop: 14 }}>
              {report.by_month.length === 0 ? <Empty>No tickets in this range.</Empty> : (
                <Table
                  head={['Month', 'Tickets', 'Resolved', 'SLA breached']}
                  rows={report.by_month.map(m => [m.label, m.tickets, m.resolved, m.breached])}
                />
              )}
            </Card>
          </>
        )}
    </div>
  )
}

const Field = ({ label, children }) => (
  <div>
    <label className="label">{label}</label>
    {children}
  </div>
)
