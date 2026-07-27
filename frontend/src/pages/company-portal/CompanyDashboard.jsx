import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { ClipboardList, Clock3, Eye, ThumbsUp, ArrowRightCircle, CheckCircle2, Briefcase, UserCheck } from 'lucide-react'
import { companyPortalApi } from '@/services/companyPortalApi'

const unwrap = r => r?.data ?? r

export default function CompanyDashboard() {
  const navigate = useNavigate()
  const [d, setD] = useState(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState(null)

  useEffect(() => {
    companyPortalApi.dashboard()
      .then(r => setD(unwrap(r) || {}))
      .catch(e => setErr(e.response?.data?.message || 'Failed to load dashboard'))
      .finally(() => setLoading(false))
  }, [])

  if (loading) return <div style={{ color: 'var(--text-muted)', padding: 40, textAlign: 'center' }}>Loading…</div>
  if (err) return <div style={{ color: '#f87171', padding: 24 }}>{err}</div>

  // The 8 workspace cards — live values, no caching.
  const KPIS = [
    ['Total Requests', d.total_requests, ClipboardList, '#60a5fa'],
    ['Pending Review', d.pending_review, Clock3, '#3b82f6'],
    ['Under Review', d.under_review, Eye, '#f59e0b'],
    ['Approved', d.approved, ThumbsUp, '#10b981'],
    ['Converted', d.converted, ArrowRightCircle, '#a78bfa'],
    ['Closed', d.closed, CheckCircle2, '#22d3ee'],
    ['Open Positions', d.open_positions, Briefcase, '#f472b6'],
    ['Filled Positions', d.filled_positions, UserCheck, '#34d399'],
  ]

  return (
    <div>
      <div style={{ marginBottom: 20, display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em' }}>Company Portal</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 800, margin: 0 }}>Dashboard</h1>
        </div>
        <button onClick={() => navigate('/company-portal/hiring-requests')} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 10, border: 'none', cursor: 'pointer', fontSize: 13, fontWeight: 700, color: '#fff', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
          <ClipboardList size={15} /> Hiring Requests
        </button>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(160px,1fr))', gap: 12, marginBottom: 24 }}>
        {KPIS.map(([label, val, Icon, c]) => (
          <div key={label} className="kpi-3d" style={{ padding: 18 }}>
            <Icon size={16} style={{ color: c }} />
            <div style={{ fontSize: 26, fontWeight: 900, color: c, marginTop: 8 }}>{val ?? 0}</div>
            <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 600, marginTop: 2 }}>{label}</div>
          </div>
        ))}
      </div>

      <p style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 10 }}>Candidates</p>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(120px,1fr))', gap: 10, marginBottom: 24 }}>
        {[
          ['Submitted', d.candidates_submitted, '#60a5fa'],
          ['Pending', d.candidates_pending, '#f59e0b'],
          ['Accepted', d.candidates_accepted, '#10b981'],
          ['Rejected', d.candidates_rejected, '#ef4444'],
          ['Need More', d.candidates_need_more, '#a78bfa'],
          ['Interviews', d.candidate_interviews, '#22d3ee'],
          ['Offers', d.candidate_offers, '#f472b6'],
        ].map(([l, v, c]) => (
          <div key={l} className="kpi-3d" style={{ padding: 14, textAlign: 'center' }}>
            <div style={{ fontSize: 22, fontWeight: 900, color: c }}>{v ?? 0}</div>
            <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 600, marginTop: 2 }}>{l}</div>
          </div>
        ))}
      </div>

      <p style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 10 }}>Interviews & Offers</p>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(120px,1fr))', gap: 10, marginBottom: 24 }}>
        {[
          ["Today's Interviews", d.todays_interviews, '#a78bfa'],
          ['Upcoming Interviews', d.upcoming_interviews, '#3b82f6'],
          ['Offers Pending', d.offers_pending, '#f59e0b'],
          ['Offers Accepted', d.offers_accepted, '#10b981'],
          ['Offers Rejected', d.offers_rejected, '#ef4444'],
          ['Joining Pending', d.joining_pending, '#22d3ee'],
          ['Joined', d.joined, '#34d399'],
        ].map(([l, v, c]) => (
          <div key={l} className="kpi-3d" style={{ padding: 14, textAlign: 'center' }}>
            <div style={{ fontSize: 22, fontWeight: 900, color: c }}>{v ?? 0}</div>
            <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 600, marginTop: 2 }}>{l}</div>
          </div>
        ))}
      </div>

      <p style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 10 }}>Recent Activities</p>
      {(!d.recent_activities || d.recent_activities.length === 0) ? (
        <div className="card-3d" style={{ padding: 24, color: 'var(--text-muted)', fontSize: 13, textAlign: 'center' }}>
          No activity yet. Create a hiring request to get started.
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {d.recent_activities.map((a, i) => (
            <div key={i} className="card-3d" style={{ padding: '12px 16px' }}>
              <p style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{a.action}</p>
              {a.note && <p style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>{a.note}</p>}
              <p style={{ fontSize: 10.5, color: 'var(--text-muted)', marginTop: 2 }}>{a.at ? new Date(a.at).toLocaleString() : ''}</p>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
