import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  LayoutDashboard, Briefcase, Timer, TrendingUp, Users, ClipboardList,
  MessageSquare, ArrowRightCircle,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'

const unwrap = r => r?.data ?? r
const SLA_C = { green: '#10b981', yellow: '#f59e0b', red: '#ef4444' }
const SLA_BG = { green: 'rgba(16,185,129,0.12)', yellow: 'rgba(245,158,11,0.12)', red: 'rgba(239,68,68,0.1)' }
const label = { display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 6, textTransform: 'uppercase', letterSpacing: '0.05em' }
const statusLabel = s => (s || '').replace(/_/g, ' ')

export default function RecruiterWorkspace() {
  const navigate = useNavigate()
  const [tab, setTab] = useState('workspace')
  const [loading, setLoading] = useState(true)
  const [ws, setWs] = useState({ assigned_projects: [] })
  const [dash, setDash] = useState({})
  const [sla, setSla] = useState([])
  const [perf, setPerf] = useState([])
  const [toast, setToast] = useState(null)
  const showToast = (msg, type = 'success') => { setToast({ msg, type }); setTimeout(() => setToast(null), 3000) }

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [w, d, s, p] = await Promise.all([
        hrApi.recruitmentServices.workspace(),
        hrApi.recruitmentServices.recruiterDashboard(),
        hrApi.recruitmentServices.sla(),
        hrApi.recruitmentServices.performance(),
      ])
      setWs(unwrap(w) || { assigned_projects: [] }); setDash(unwrap(d) || {})
      setSla(unwrap(s) || []); setPerf(unwrap(p) || [])
    } catch (e) { showToast(e.response?.data?.message || 'Failed to load recruiter data', 'error') }
    finally { setLoading(false) }
  }, [])
  useEffect(() => { load() }, [load])

  const TABS = [
    { key: 'workspace', label: 'My Workspace', icon: Briefcase },
    { key: 'dashboard', label: 'Dashboard', icon: LayoutDashboard },
    { key: 'sla', label: 'SLA Tracking', icon: Timer },
    { key: 'performance', label: 'Performance', icon: TrendingUp },
  ]

  const DASH = [
    ['Total Projects', dash.total_projects, '#60a5fa'],
    ['Active Hiring', dash.active_hiring, '#f59e0b'],
    ['Candidates Submitted', dash.candidates_submitted, '#f472b6'],
    ['Interviews', dash.interviews, '#a78bfa'],
    ['Offers', dash.offers, '#22d3ee'],
    ['Closures', dash.closures, '#10b981'],
  ]

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      {toast && <div style={{ position: 'fixed', top: 20, right: 20, zIndex: 9999, padding: '12px 20px', borderRadius: 14, fontSize: 13, fontWeight: 700, color: '#fff', background: toast.type === 'success' ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      <div style={{ marginBottom: 16 }}>
        <p style={{ ...label, marginBottom: 2 }}>HR · Recruitment Services</p>
        <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 800, margin: 0 }}>Recruiter Workspace</h1>
      </div>

      <div style={{ display: 'flex', gap: 8, marginBottom: 20, flexWrap: 'wrap' }}>
        {TABS.map(t => (
          <button key={t.key} onClick={() => setTab(t.key)} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, fontSize: 13, fontWeight: 700, cursor: 'pointer',
            border: `1px solid ${tab === t.key ? 'rgba(124,58,237,0.5)' : 'var(--border)'}`, background: tab === t.key ? 'rgba(124,58,237,0.15)' : 'var(--bg-card)', color: tab === t.key ? '#a78bfa' : 'var(--text-muted)' }}>
            <t.icon size={14} /> {t.label}
          </button>
        ))}
      </div>

      {loading ? <HrLoading label="Loading recruiter workspace…" /> : (
        <>
          {/* ── MY WORKSPACE ── */}
          {tab === 'workspace' && (
            <>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(160px,1fr))', gap: 12, marginBottom: 20 }}>
                {[
                  ['Assigned Projects', ws.assigned_projects?.length, Briefcase, '#60a5fa'],
                  ['Open Positions', ws.open_positions, Users, '#f59e0b'],
                  ['Submission Queue', ws.submission_queue, ClipboardList, '#f472b6'],
                  ['Pending Client Feedback', ws.pending_feedback, MessageSquare, '#a78bfa'],
                ].map(([l, v, Icon, c]) => (
                  <div key={l} className="kpi-3d" style={{ padding: 16 }}>
                    <Icon size={16} style={{ color: c }} />
                    <div style={{ fontSize: 24, fontWeight: 900, color: c, marginTop: 6 }}>{v ?? 0}</div>
                    <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 600, marginTop: 2 }}>{l}</div>
                  </div>
                ))}
              </div>

              <p style={{ ...label }}>My Assigned Projects</p>
              {(!ws.assigned_projects || ws.assigned_projects.length === 0) ? (
                <HrEmpty icon={Briefcase} title="No assigned projects" hint="Projects assigned to you appear here." />
              ) : (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                  {ws.assigned_projects.map(p => (
                    <div key={p.id} className="card-3d" style={{ padding: 16, display: 'flex', justifyContent: 'space-between', gap: 14, flexWrap: 'wrap', alignItems: 'center' }}>
                      <div style={{ flex: 1, minWidth: 200 }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                          <span style={{ color: '#a78bfa', fontWeight: 800, fontSize: 12 }}>HR-{p.id}</span>
                          <span style={{ fontWeight: 700, color: 'var(--text-h)', fontSize: 14.5 }}>{p.job_title}</span>
                          <span style={{ fontSize: 10, fontWeight: 700, padding: '2px 8px', borderRadius: 8, background: 'var(--bg-input)', color: 'var(--text-muted)' }}>{statusLabel(p.status)}</span>
                        </div>
                        <p style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 4 }}>{p.company || '—'} · {p.positions} position(s)</p>
                        {p.counts && (
                          <div style={{ display: 'flex', gap: 12, marginTop: 6, fontSize: 11.5, color: 'var(--text-muted)', flexWrap: 'wrap' }}>
                            <span>Applications <b style={{ color: 'var(--text-h)' }}>{p.counts.applications}</b></span>
                            <span>Shortlisted <b style={{ color: 'var(--text-h)' }}>{p.counts.shortlisted}</b></span>
                            <span>Interviews <b style={{ color: 'var(--text-h)' }}>{p.counts.interviews}</b></span>
                            <span>Selected <b style={{ color: 'var(--text-h)' }}>{p.counts.selected}</b></span>
                            <span>Delivered <b style={{ color: '#f472b6' }}>{p.counts.delivered}</b></span>
                          </div>
                        )}
                      </div>
                      <button onClick={() => navigate('/app/hr/recruitment-services')} style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '7px 12px', borderRadius: 8, border: 'none', cursor: 'pointer', fontSize: 12, fontWeight: 700, color: '#a78bfa', background: 'rgba(124,58,237,0.1)' }}><ArrowRightCircle size={13} /> Open in Recruitment Services</button>
                    </div>
                  ))}
                </div>
              )}
            </>
          )}

          {/* ── DASHBOARD ── */}
          {tab === 'dashboard' && (
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(160px,1fr))', gap: 12 }}>
              {DASH.map(([l, v, c]) => (
                <div key={l} className="kpi-3d" style={{ padding: 20, textAlign: 'center' }}>
                  <div style={{ fontSize: 30, fontWeight: 900, color: c }}>{v ?? 0}</div>
                  <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 600, marginTop: 4 }}>{l}</div>
                </div>
              ))}
            </div>
          )}

          {/* ── SLA TRACKING ── */}
          {tab === 'sla' && (
            <>
              <div style={{ display: 'flex', gap: 16, marginBottom: 14, flexWrap: 'wrap', fontSize: 12, color: 'var(--text-muted)' }}>
                {[['green', 'On track / met'], ['yellow', 'At risk'], ['red', 'Breached']].map(([k, t]) => (
                  <span key={k} style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}><span style={{ width: 10, height: 10, borderRadius: '50%', background: SLA_C[k] }} /> {t}</span>
                ))}
              </div>
              {sla.length === 0 ? <HrEmpty icon={Timer} title="No active requests" hint="SLA lines appear for active hiring requests." /> : (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                  {sla.map(r => (
                    <div key={r.id} className="card-3d" style={{ padding: 16 }}>
                      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap', marginBottom: 10 }}>
                        <div>
                          <span style={{ color: '#a78bfa', fontWeight: 800, fontSize: 12 }}>HR-{r.id}</span>
                          <span style={{ fontWeight: 700, color: 'var(--text-h)', fontSize: 14, marginLeft: 8 }}>{r.job_title}</span>
                          <p style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 2 }}>{r.company || '—'} · {r.recruiter || 'Unassigned'}</p>
                        </div>
                      </div>
                      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))', gap: 10 }}>
                        {[['Recruiter Assignment', r.sla.assignment], ['First Candidate', r.sla.first_candidate], ['Target Closure', r.sla.closure]].map(([name, line]) => (
                          <div key={name} style={{ padding: 12, borderRadius: 10, background: SLA_BG[line.status], border: `1px solid ${SLA_C[line.status]}33` }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                              <span style={{ width: 9, height: 9, borderRadius: '50%', background: SLA_C[line.status] }} />
                              <span style={{ fontSize: 11.5, fontWeight: 700, color: 'var(--text-h)' }}>{name}</span>
                            </div>
                            <p style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 5 }}>
                              {line.pending
                                ? `${line.elapsed_days ?? 0}d elapsed / ${line.target_days}d target${line.elapsed_days == null ? ' (not started)' : ''}`
                                : `${line.met ? 'Met' : 'Missed'} · ${line.elapsed_days}d / ${line.target_days}d`}
                            </p>
                          </div>
                        ))}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </>
          )}

          {/* ── PERFORMANCE ── */}
          {tab === 'performance' && (
            perf.length === 0 ? <HrEmpty icon={TrendingUp} title="No recruiters" hint="Recruiter performance appears here." /> : (
              <div className="card-3d" style={{ padding: 0, overflow: 'auto' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
                  <thead>
                    <tr style={{ background: 'var(--bg-input)' }}>
                      {['Recruiter', 'Projects', 'Closures', 'Avg Hiring Time', 'Offer Ratio', 'Selection Ratio', 'Client Acceptance'].map(h => (
                        <th key={h} style={{ textAlign: h === 'Recruiter' ? 'left' : 'center', padding: '11px 14px', fontWeight: 700, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {perf.map(p => (
                      <tr key={p.recruiter_id} style={{ borderTop: '1px solid var(--border)' }}>
                        <td style={{ padding: '11px 14px', fontWeight: 700, color: 'var(--text-h)' }}>{p.recruiter}</td>
                        <td style={{ textAlign: 'center', color: 'var(--text-h)' }}>{p.projects}</td>
                        <td style={{ textAlign: 'center', color: '#10b981', fontWeight: 700 }}>{p.closures}</td>
                        <td style={{ textAlign: 'center', color: 'var(--text-muted)' }}>{p.avg_hiring_days != null ? `${p.avg_hiring_days} days` : '—'}</td>
                        <td style={{ textAlign: 'center', color: 'var(--text-h)' }}>{p.offer_ratio ?? 0}%</td>
                        <td style={{ textAlign: 'center', color: 'var(--text-h)' }}>{p.selection_ratio ?? 0}%</td>
                        <td style={{ textAlign: 'center', color: '#f472b6', fontWeight: 700 }}>{p.client_acceptance ?? 0}%</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )
          )}
        </>
      )}
    </div>
  )
}
