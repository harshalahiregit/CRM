import { useState, useEffect } from 'react'
import { companyPortalApi } from '@/services/companyPortalApi'

const unwrap = r => r?.data ?? r
const lbl = { fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em' }

export default function CompanyReports() {
  const [d, setD] = useState(null)
  const [activity, setActivity] = useState(null)
  const [err, setErr] = useState(null)

  useEffect(() => {
    companyPortalApi.reports().then(r => setD(unwrap(r))).catch(e => setErr(e.response?.data?.message || 'Failed to load'))
    companyPortalApi.activity().then(r => setActivity(unwrap(r))).catch(() => {}) // admin-only; hidden otherwise
  }, [])

  if (err) return <div style={{ color: '#f87171', padding: 24 }}>{err}</div>
  if (!d) return <div style={{ color: 'var(--text-muted)', padding: 40, textAlign: 'center' }}>Loading…</div>
  const hs = d.hiring_summary || {}, is = d.interview_summary || {}, os = d.offer_summary || {}, js = d.joining_summary || {}

  const grp = (title, entries) => (
    <div className="card-3d" style={{ padding: 18 }}>
      <p style={{ ...lbl, marginBottom: 12 }}>{title}</p>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(90px,1fr))', gap: 10 }}>
        {entries.map(([l, v, c]) => (
          <div key={l} style={{ textAlign: 'center' }}>
            <div style={{ fontSize: 22, fontWeight: 900, color: c || '#a78bfa' }}>{v ?? 0}</div>
            <div style={{ fontSize: 10, color: 'var(--text-muted)', fontWeight: 600, marginTop: 2 }}>{l}</div>
          </div>
        ))}
      </div>
    </div>
  )
  const maxM = Math.max(1, ...(d.monthly_hiring || []).map(m => Math.max(m.requests, m.closures)))

  return (
    <div>
      <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 800, margin: '0 0 18px' }}>Reports</h1>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(240px,1fr))', gap: 14, marginBottom: 18 }}>
        {grp('Hiring Summary', [['Total', hs.total, '#60a5fa'], ['Active', hs.active, '#f59e0b'], ['Completed', hs.completed, '#10b981'], ['Open Pos.', hs.open_positions, '#f472b6']])}
        {grp('Interview Summary', [['Total', is.total, '#a78bfa'], ['Scheduled', is.scheduled, '#3b82f6'], ['Completed', is.completed, '#10b981'], ['Cancelled', is.cancelled, '#ef4444']])}
        {grp('Offer Summary', [['Total', os.total, '#22d3ee'], ['Pending', os.pending, '#f59e0b'], ['Accepted', os.accepted, '#10b981'], ['Rejected', os.rejected, '#ef4444']])}
        {grp('Joining Summary', [['Joined', js.joined, '#34d399'], ['Pending', js.pending, '#f59e0b']])}
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(300px,1fr))', gap: 14, marginBottom: 18 }}>
        <div className="card-3d" style={{ padding: 18 }}>
          <p style={{ ...lbl, marginBottom: 14 }}>Monthly Hiring</p>
          <div style={{ display: 'flex', alignItems: 'flex-end', gap: 10, height: 140 }}>
            {(d.monthly_hiring || []).map(m => (
              <div key={m.month} style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 4 }}>
                <div style={{ display: 'flex', gap: 3, alignItems: 'flex-end', height: 110 }}>
                  <div title={`Requests ${m.requests}`} style={{ width: 12, height: `${(m.requests / maxM) * 100}%`, minHeight: 2, background: '#7C3AED', borderRadius: 3 }} />
                  <div title={`Closures ${m.closures}`} style={{ width: 12, height: `${(m.closures / maxM) * 100}%`, minHeight: 2, background: '#10b981', borderRadius: 3 }} />
                </div>
                <span style={{ fontSize: 9.5, color: 'var(--text-muted)' }}>{m.month.split(' ')[0]}</span>
              </div>
            ))}
          </div>
          <div style={{ display: 'flex', gap: 14, marginTop: 10, fontSize: 11, color: 'var(--text-muted)' }}><span><span style={{ display: 'inline-block', width: 9, height: 9, background: '#7C3AED', borderRadius: 2, marginRight: 4 }} />Requests</span><span><span style={{ display: 'inline-block', width: 9, height: 9, background: '#10b981', borderRadius: 2, marginRight: 4 }} />Closures</span></div>
        </div>

        <div className="card-3d" style={{ padding: 0, overflow: 'auto' }}>
          <p style={{ ...lbl, padding: '18px 18px 8px' }}>Recruiter Performance</p>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
            <thead><tr style={{ background: 'var(--bg-input)' }}>{['Recruiter', 'Projects', 'Delivered', 'Accepted', 'Closures'].map(h => <th key={h} style={{ textAlign: h === 'Recruiter' ? 'left' : 'center', padding: '9px 12px', fontWeight: 700, color: 'var(--text-muted)' }}>{h}</th>)}</tr></thead>
            <tbody>
              {(d.recruiter_performance || []).length === 0 ? <tr><td colSpan={5} style={{ padding: 20, textAlign: 'center', color: 'var(--text-muted)' }}>No recruiters assigned yet.</td></tr> :
                d.recruiter_performance.map((p, i) => (
                  <tr key={i} style={{ borderTop: '1px solid var(--border)' }}>
                    <td style={{ padding: '9px 12px', color: 'var(--text-h)', fontWeight: 700 }}>{p.recruiter}</td>
                    <td style={{ textAlign: 'center', color: 'var(--text-h)' }}>{p.projects}</td>
                    <td style={{ textAlign: 'center', color: 'var(--text-muted)' }}>{p.candidates_delivered}</td>
                    <td style={{ textAlign: 'center', color: '#10b981' }}>{p.accepted}</td>
                    <td style={{ textAlign: 'center', color: '#a78bfa' }}>{p.closures}</td>
                  </tr>
                ))}
            </tbody>
          </table>
        </div>
      </div>

      {activity && (
        <div className="card-3d" style={{ padding: 0, overflow: 'auto' }}>
          <p style={{ ...lbl, padding: '18px 18px 8px' }}>Activity Log</p>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
            <thead><tr style={{ background: 'var(--bg-input)' }}>{['User', 'Action', 'Date', 'IP', 'Device'].map(h => <th key={h} style={{ textAlign: 'left', padding: '9px 12px', fontWeight: 700, color: 'var(--text-muted)' }}>{h}</th>)}</tr></thead>
            <tbody>
              {activity.data.map((a, i) => (
                <tr key={i} style={{ borderTop: '1px solid var(--border)' }}>
                  <td style={{ padding: '9px 12px', color: 'var(--text-h)', fontWeight: 600 }}>{a.user}</td>
                  <td style={{ padding: '9px 12px', color: 'var(--text-muted)' }}>{a.action}</td>
                  <td style={{ padding: '9px 12px', color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{a.at ? new Date(a.at).toLocaleString() : '—'}</td>
                  <td style={{ padding: '9px 12px', color: 'var(--text-muted)' }}>{a.ip || '—'}</td>
                  <td style={{ padding: '9px 12px', color: 'var(--text-muted)', maxWidth: 220, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{a.device || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
