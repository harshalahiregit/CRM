import { useCallback, useEffect, useState } from 'react'
import { BarChart3, RefreshCw, Download, Building2, UserRound, Wallet } from 'lucide-react'
import LoadError from '@/components/ui/LoadError'
import { KIT3D_STYLE } from '@/components/ui/kit3d'
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'
import ReportShell, { Card, Empty, Table, Stat, StatRow, download } from '@/components/reports/ReportShell'

/**
 * The Sales report.
 *
 * Deliberately not the Sales dashboard: that describes the current month and is
 * fixed, while this is pointed at a date range, narrowed by status, customer or
 * agent, and exported.
 *
 * Billed and collected are shown as separate figures because they answer
 * different questions. An invoice raised in March and paid in May belongs to
 * March's billing and May's collection, and a page that adds them together
 * flatters whichever month you happen to be looking at.
 */
export default function SalesReports() {
  const [report, setReport] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [filters, setFilters] = useState({ from: '', to: '', status: '', client_id: '', agent: '' })

  const load = useCallback(() => {
    const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v))
    api.get('/sales/reports', { params }).then(r => r.data?.data ?? r.data).catch(handleErr)
      .then(d => { setLoadError(null); setReport(d) })
      .catch(e => { setReport(null); setLoadError(e) })
  }, [filters])

  useEffect(() => { load() }, [load])

  const t = report?.totals
  const money = (n) => (n === null || n === undefined ? '—' : `₹${Number(n).toLocaleString('en-IN')}`)

  return (
    <div style={{ padding: 4 }}>
      <style>{KIT3D_STYLE}</style>

      <ReportShell
        eyebrow="SALES"
        title="Sales report"
        subtitle="What was billed, what has been collected, and what is still owed."
        onRefresh={load}
        onExport={(format) => download('/sales/reports/export', filters, format, `sales-report.${format}`)}
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
            <Field label="Customer">
              <select value={filters.client_id} onChange={e => setFilters(f => ({ ...f, client_id: e.target.value }))} className="input-3d text-sm">
                <option value="">Every customer</option>
                {(report?.by_customer ?? []).filter(c => c.client_id).map(c => (
                  <option key={c.client_id} value={c.client_id}>{c.client}</option>
                ))}
              </select>
            </Field>
            <Field label="Sales agent">
              <select value={filters.agent} onChange={e => setFilters(f => ({ ...f, agent: e.target.value }))} className="input-3d text-sm">
                <option value="">Everyone</option>
                {(report?.by_agent ?? []).map(a => <option key={a.agent} value={a.agent}>{a.agent}</option>)}
              </select>
            </Field>
            {Object.values(filters).some(Boolean) && (
              <button onClick={() => setFilters({ from: '', to: '', status: '', client_id: '', agent: '' })}
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
              <Stat label="Invoices" value={t.invoices} />
              <Stat label="Billed" value={money(t.billed)} tone="#7C3AED" />
              <Stat label="Paid" value={money(t.paid)} tone="#10b981" />
              <Stat label="Outstanding" value={money(t.outstanding)} tone="#f59e0b" />
              <Stat label="Overdue" value={money(t.overdue_value)} sub={`${t.overdue_count} invoice${t.overdue_count === 1 ? '' : 's'}`} tone="#ef4444" />
              <Stat label="Collected in period" value={money(t.collected_in_period)} sub="payments received" />
              <Stat label="Collection rate" value={t.collection_rate === null ? '—' : `${t.collection_rate}%`} />
              <Stat label="Average invoice" value={money(t.average_invoice)} />
            </StatRow>

            <Card title="Pipeline in this period" icon={Wallet} style={{ marginTop: 14 }}>
              <StatRow compact>
                <Stat label="Estimates" value={report.pipeline.estimates.count} sub={money(report.pipeline.estimates.value)} />
                <Stat label="Proposals" value={report.pipeline.proposals.count} sub={money(report.pipeline.proposals.value)} />
                <Stat label="Leads" value={report.pipeline.leads.count} sub={money(report.pipeline.leads.value)} />
              </StatRow>
            </Card>

            <Card title="By customer" icon={Building2} style={{ marginTop: 14 }}>
              {report.by_customer.length === 0 ? <Empty>No invoices in this range.</Empty> : (
                <Table
                  head={['Customer', 'Invoices', 'Billed', 'Paid', 'Outstanding', 'Overdue']}
                  rows={report.by_customer.map(c => [
                    c.client, c.invoices, money(c.billed), money(c.paid), money(c.outstanding), money(c.overdue),
                  ])}
                />
              )}
            </Card>

            <Card title="By sales agent" icon={UserRound} style={{ marginTop: 14 }}>
              {report.by_agent.length === 0 ? <Empty>No invoices in this range.</Empty> : (
                <Table
                  head={['Agent', 'Invoices', 'Billed', 'Paid', 'Outstanding']}
                  rows={report.by_agent.map(a => [a.agent, a.invoices, money(a.billed), money(a.paid), money(a.outstanding)])}
                />
              )}
            </Card>

            <Card title="Month by month" icon={BarChart3} style={{ marginTop: 14 }}>
              {report.by_month.length === 0 ? <Empty>No invoices in this range.</Empty> : (
                <Table
                  head={['Month', 'Invoices', 'Billed', 'Paid', 'Outstanding']}
                  rows={report.by_month.map(m => [m.label, m.invoices, money(m.billed), money(m.paid), money(m.outstanding)])}
                />
              )}
            </Card>

            <Card title="By status" icon={BarChart3} style={{ marginTop: 14 }}>
              {report.by_status.length === 0 ? <Empty>No invoices in this range.</Empty> : (
                <Table
                  head={['Status', 'Invoices', 'Billed', 'Outstanding']}
                  rows={report.by_status.map(s => [s.status, s.invoices, money(s.billed), money(s.outstanding)])}
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
