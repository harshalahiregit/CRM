import { useState, useEffect, useCallback } from 'react'
import { FileText, Printer, Loader2, RefreshCw } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'

const KINDS = [['DPR', 'Daily'], ['WPR', 'Weekly'], ['MCR', 'Monthly']]
const sevColor = (s) => ({ Minor: '#10b981', Moderate: '#f59e0b', Serious: '#f97316', Fatal: '#ef4444' }[s] || '#94a3b8')
const pretty = (s) => (s || '').replace(/_/g, ' ')

/**
 * DPR / WPR / MCR periodic compliance reports (Doc 6). Pick a period; the report
 * rolls up the incidents/permits/observations/talks in the window plus the
 * standing compliance posture. Print → Save as PDF produces the paper artefact.
 */
export default function TpvReports() {
  const [kind, setKind] = useState('DPR')
  const [r, setR] = useState(null)
  const [loading, setLoading] = useState(true)

  const load = useCallback(() => {
    setLoading(true)
    tpvApi.governance.report(kind).then(setR).catch(() => setR(null)).finally(() => setLoading(false))
  }, [kind])
  useEffect(() => { load() }, [load])

  return (
    <div style={{ padding: 24, maxWidth: 920, margin: '0 auto' }}>
      <style>{`@media print { .no-print { display: none !important } .report-sheet { box-shadow: none !important; border: none !important } }`}</style>
      <div className="no-print" style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 18, flexWrap: 'wrap', gap: 12 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <FileText size={22} style={{ color: '#7C3AED' }} />
          <h1 style={{ margin: 0, fontSize: 20, fontWeight: 800, color: 'var(--text-h)' }}>Compliance Reports</h1>
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <div style={{ display: 'flex', background: 'var(--bg-input)', borderRadius: 10, padding: 3 }}>
            {KINDS.map(([k, label]) => (
              <button key={k} onClick={() => setKind(k)} style={{ padding: '7px 14px', borderRadius: 8, border: 'none', background: kind === k ? '#7C3AED' : 'transparent', color: kind === k ? '#fff' : 'var(--text-muted)', fontWeight: 700, fontSize: 12.5, cursor: 'pointer' }}>{label}</button>
            ))}
          </div>
          <button onClick={load} style={btn}><RefreshCw size={14} /></button>
          <button onClick={() => window.print()} style={{ ...btn, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', border: 'none' }}><Printer size={14} /> Print</button>
        </div>
      </div>

      {loading ? <div style={{ textAlign: 'center', padding: 48, color: 'var(--text-muted)' }}><Loader2 size={20} className="rfq-spin" /></div>
        : !r ? <div style={{ textAlign: 'center', padding: 48, color: 'var(--text-muted)' }}>Could not load the report.</div>
        : (
          <div className="report-sheet pr-glass" style={{ borderRadius: 14, padding: 28 }}>
            <div style={{ borderBottom: '2px solid #7C3AED', paddingBottom: 12, marginBottom: 18 }}>
              <h2 style={{ margin: 0, fontSize: 20, fontWeight: 800, color: 'var(--text-h)' }}>{r.title}</h2>
              <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--text-muted)' }}>{r.period_label} · generated {new Date(r.generated_at).toLocaleString()}</p>
            </div>

            {/* Summary tiles */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(130px, 1fr))', gap: 10, marginBottom: 22 }}>
              {[
                ['Incidents', r.summary.incidents], ['Open Incidents', r.summary.open_incidents],
                ['Permits', r.summary.permits], ['Observations', r.summary.observations],
                ['Toolbox Talks', r.summary.toolbox_talks], ['Talk Attendance', r.summary.talk_attendance],
                ['Suspended Vendors', r.summary.suspended_vendors], ['Docs Expiring (30d)', r.summary.docs_expiring_30d],
              ].map(([label, val]) => (
                <div key={label} style={{ padding: 12, borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input)' }}>
                  <div style={{ fontSize: 10, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--text-muted)' }}>{label}</div>
                  <div style={{ fontSize: 24, fontWeight: 800, color: 'var(--text-h)', marginTop: 2 }}>{val ?? 0}</div>
                </div>
              ))}
            </div>

            <ReportTable title="Incidents in Period" rows={r.incidents} empty="No incidents." cols={[
              ['Ref', i => i.reference], ['Title', i => i.title],
              ['Severity', i => <span style={{ color: sevColor(i.severity), fontWeight: 700 }}>{i.severity}</span>],
              ['Status', i => pretty(i.status)], ['When', i => i.occurred_at ? new Date(i.occurred_at).toLocaleDateString() : '—'],
            ]} />

            <ReportTable title="Permits Issued" rows={r.permits} empty="No permits." cols={[
              ['Ref', p => p.reference], ['Type', p => pretty(p.type)], ['Status', p => pretty(p.status)],
              ['Created', p => p.created_at ? new Date(p.created_at).toLocaleDateString() : '—'],
            ]} />

            <ReportTable title="Suspended Vendors (as of now)" rows={r.suspended} empty="None — all compliant." cols={[
              ['Vendor', v => v.company_name], ['Reason', v => v.suspension_reason || '—'],
              ['Since', v => v.suspended_at ? new Date(v.suspended_at).toLocaleDateString() : '—'],
            ]} />

            <div style={{ marginTop: 20, paddingTop: 12, borderTop: '1px solid var(--border)', fontSize: 10.5, color: 'var(--text-muted)', textAlign: 'center' }}>
              HSSE {r.title} · {r.period_label} · system-generated
            </div>
          </div>
        )}
    </div>
  )
}

function ReportTable({ title, rows, cols, empty }) {
  return (
    <div style={{ marginBottom: 20 }}>
      <h3 style={{ fontSize: 13, fontWeight: 800, color: '#7C3AED', textTransform: 'uppercase', letterSpacing: '0.04em', margin: '0 0 8px' }}>{title}</h3>
      {(!rows || rows.length === 0) ? <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: 0 }}>{empty}</p> : (
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
            <thead><tr>{cols.map(([h]) => <th key={h} style={{ textAlign: 'left', padding: '6px 8px', color: 'var(--text-muted)', fontWeight: 700, borderBottom: '1px solid var(--border)', whiteSpace: 'nowrap' }}>{h}</th>)}</tr></thead>
            <tbody>{rows.map((row, i) => <tr key={i}>{cols.map(([h, fn]) => <td key={h} style={{ padding: '6px 8px', color: 'var(--text-h)', borderBottom: '1px solid var(--border)' }}>{fn(row)}</td>)}</tr>)}</tbody>
          </table>
        </div>
      )}
    </div>
  )
}

const btn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 13px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12.5, fontWeight: 700 }
