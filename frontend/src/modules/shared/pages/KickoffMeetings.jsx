import { useState, useEffect, useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  CalendarDays, Plus, RefreshCw, Clock, CheckCircle2, XCircle, Send,
  Users, AlertTriangle, ClipboardCheck, Pencil, BellRing, Eye, Download, Loader2, Mail, MessageCircle, Smartphone,
  ChevronLeft, ChevronRight, List, LayoutGrid, Laptop, Building2, UserX, ListChecks, Trash2, Settings2, UserCheck,
} from 'lucide-react'
import { kickoffApi } from '@/services/kickoffApi'
import { useAuth } from '@/context/AuthContext'
import {
  KO_STATUS, koStatusCfg, koModeLabel, fmtDate, fmtDateTime, isKoClosed,
} from '../kickoffConstants'
import { KIT3D_STYLE, Overlay, ModalFooter } from '@/components/ui/kit3d'

/**
 * Kickoff meeting registry — the shared scheduling engine's list view.
 *
 * Lives under the TPV rail (its first consumer) but is not TPV-coupled: it reads
 * kickoffApi, which attaches to any allowlisted subject. The table is the
 * 12-column register; per-row actions sit in a fixed toolbar, not as a column.
 */
export default function KickoffMeetings() {
  const navigate = useNavigate()
  const { user } = useAuth()
  const [data, setData]   = useState([])
  const [stats, setStats] = useState(null)
  const [loading, setLoad] = useState(true)
  const [filter, setFilter] = useState('All')
  const [banner, setBanner] = useState(null)
  const [pdfBusy, setPdfBusy] = useState(null)
  const [view, setView] = useState('list')   // 'list' | 'calendar'
  const [pageSize, setPageSize] = useState(25)   // rows per page ('all' = no paging)
  const [page, setPage] = useState(1)
  const [projects, setProjects] = useState([])   // §16 project rollup source
  const [projectF, setProjectF] = useState('All') // '' | project id (client-side)
  // ?view=templates deep-links straight to Types & Templates, which is where TPV
  // Settings sends an admin who wants to add or remove a meeting type.
  const [quickView, setQuickView] = useState(() => {
    const v = new URLSearchParams(window.location.search).get('view')
    return ['all', 'my', 'upcoming', 'pending_mom', 'open_actions', 'templates'].includes(v) ? v : 'all'
  })

  // Row-action modal targets — showNew removed: create navigates to full page
  const [attendanceFor, setAttFor]    = useState(null)
  const [reminderFor, setRemindFor]   = useState(null)

  const load = () => {
    setLoad(true)
    Promise.all([
      kickoffApi.list(filter === 'All' ? {} : { status: filter }),
      kickoffApi.dashboard(),
    ]).then(([rows, s]) => { setData(rows?.data ?? rows); setStats(s); setLoad(false) })
      .catch(() => setLoad(false))
  }
  useEffect(() => { load() }, [filter]) // eslint-disable-line react-hooks/exhaustive-deps
  // Projects for the §16 rollup filter — soft link, empty on failure.
  useEffect(() => { kickoffApi.projects().then(d => { if (Array.isArray(d)) setProjects(d) }).catch(() => {}) }, [])
  // Any change to a filter or page size sends the reader back to page 1.
  useEffect(() => { setPage(1) }, [filter, pageSize, projectF, quickView])

  // Quick views (Meeting.docx nav sub-items) + project rollup are applied
  // client-side over the loaded rows (same pattern as the calendar's filters).
  const now = Date.now()
  // Mine = meetings I organise (creator) or attend (name matches an attendee).
  const mine = (m) => (m.creator?.id && user?.id && m.creator.id === user.id)
    || (user?.name && (m.attendees || []).some(a => a.name === user.name))
  const quickMatch = (m) => {
    switch (quickView) {
      case 'my':           return mine(m)
      case 'upcoming':     return !isKoClosed(m.status) && m.scheduled_at && new Date(m.scheduled_at).getTime() >= now
      // Completed meetings whose minutes are not yet distributed — the MOM is owed.
      case 'pending_mom':  return m.status === KO_STATUS.COMPLETED && m.mom_status !== 'Distributed'
      case 'open_actions': return (m.open_actions ?? 0) > 0
      default:             return true
    }
  }
  const rows = data
    .filter(m => projectF === 'All' || String(m.project_id) === String(projectF))
    .filter(quickMatch)

  // View / Download the MOM PDF — generate on demand if none exists yet.
  const handlePdf = async (m, download) => {
    setPdfBusy(`${m.id}:${download ? 'dl' : 'view'}`); setBanner(null)
    try {
      const fresh = !m.mom_path
      if (fresh) await kickoffApi.generateMom(m.id)
      const blob = await kickoffApi.momBlob(m.id)
      const url = URL.createObjectURL(blob)
      if (download) {
        const a = document.createElement('a')
        a.href = url; a.download = `MOM-${m.id}.pdf`
        document.body.appendChild(a); a.click(); a.remove()
      } else {
        window.open(url, '_blank', 'noopener')
      }
      setTimeout(() => URL.revokeObjectURL(url), 60000)
      if (fresh) load() // refresh the "MOM Sent" column
    } catch (e) {
      setBanner(e?.response?.data?.message || 'Could not open the MOM PDF.')
    } finally { setPdfBusy(null) }
  }

  // Client-side paging over the already-fetched rows (same data the calendar uses).
  const total      = rows.length
  const totalPages = pageSize === 'all' ? 1 : Math.max(1, Math.ceil(total / pageSize))
  const curPage    = Math.min(page, totalPages)
  const pageRows   = pageSize === 'all' ? rows : rows.slice((curPage - 1) * pageSize, curPage * pageSize)
  const rangeFrom  = total === 0 ? 0 : (pageSize === 'all' ? 1 : (curPage - 1) * pageSize + 1)
  const rangeTo    = pageSize === 'all' ? total : Math.min(curPage * pageSize, total)

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>
      <style>{`@keyframes koSpin{to{transform:rotate(360deg)}}.ko-spin{animation:koSpin .9s linear infinite}`}</style>

      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>PRE-ONBOARDING</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 24, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>Meetings</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Schedule, track attendance & minutes, and share them with the vendor.</p>
        </div>
        <div style={{ display: 'flex', gap: 9 }}>
          <button onClick={load} style={ghostBtn}><RefreshCw size={14} /> Refresh</button>
          <button onClick={() => navigate('/app/tpv/kickoff/new')} style={solidBtn}><Plus size={15} /> Schedule meeting</button>
        </div>
      </div>

      {/* Dashboard (Meeting.docx §14) */}
      {stats && (
        <>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(6,1fr)', gap: 12, marginBottom: 12 }}>
            <Kpi label="Today" value={stats.today} icon={CalendarDays} color="#7C3AED" />
            <Kpi label="Upcoming" value={stats.upcoming} icon={Clock} color="#0ea5e9" />
            <Kpi label="Pending MOM" value={stats.pending_mom} icon={ClipboardCheck} color="#f59e0b" danger={stats.pending_mom > 0} />
            <Kpi label="Overdue MOM" value={stats.overdue_mom} icon={AlertTriangle} color="#ef4444" danger={stats.overdue_mom > 0} />
            <Kpi label="Open actions" value={stats.open_actions} icon={ListChecks} color="#a78bfa" />
            <Kpi label="Overdue actions" value={stats.overdue_actions} icon={AlertTriangle} color="#ef4444" danger={stats.overdue_actions > 0} />
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: '1.4fr repeat(3,1fr)', gap: 12, marginBottom: 18, alignItems: 'stretch' }}>
            {/* Meeting effectiveness — action closure rate */}
            <div className="pr-kpi" style={{ padding: 16, display: 'flex', flexDirection: 'column', justifyContent: 'center' }}>
              <div style={{ display: 'flex', alignItems: 'baseline', gap: 8 }}>
                <span style={{ fontSize: 28, fontWeight: 900, color: stats.closure_rate >= 70 ? '#10b981' : stats.closure_rate >= 40 ? '#f59e0b' : '#ef4444', lineHeight: 1 }}>{stats.closure_rate}%</span>
                <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-muted)' }}>Action closure rate</span>
              </div>
              <div style={{ height: 7, borderRadius: 999, background: 'var(--bg-input)', overflow: 'hidden', margin: '10px 0 6px' }}>
                <div style={{ height: '100%', width: `${stats.closure_rate}%`, borderRadius: 999, background: stats.closure_rate >= 70 ? '#10b981' : stats.closure_rate >= 40 ? '#f59e0b' : '#ef4444' }} />
              </div>
              <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{stats.closed_actions}/{stats.total_actions} actions closed</span>
            </div>
            <Kpi label="Completed" value={stats.completed} icon={CheckCircle2} color="#10b981" />
            <Kpi label="Awaiting ack" value={stats.awaiting_ack} icon={Send} color="#a78bfa" danger={stats.awaiting_ack > 0} />
            <Kpi label="Decisions active" value={stats.decisions_active} icon={ClipboardCheck} color="#0ea5e9" />
          </div>
          {/* Breakdowns — by type / by project / by vendor (Meeting.docx §14). */}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(240px,1fr))', gap: 12, marginBottom: 18 }}>
            <BreakdownCard title="By type" rows={stats.by_type} keyField="type" />
            <BreakdownCard title="By project" rows={stats.by_project} keyField="project_id" empty="No project-linked meetings" />
            <BreakdownCard title="By vendor" rows={stats.by_vendor} keyField="name" empty="No vendor meetings" />
          </div>
        </>
      )}

      {banner && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 9, padding: '11px 14px', borderRadius: 12, marginBottom: 14, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)' }}>
          <AlertTriangle size={15} style={{ color: '#ef4444', flexShrink: 0 }} />
          <span style={{ fontSize: 13, color: 'var(--text-h)', flex: 1 }}>{banner}</span>
          <button onClick={() => setBanner(null)} style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)' }}><XCircle size={15} /></button>
        </div>
      )}

      {/* Quick views (Meeting.docx nav sub-items) — client-side over loaded rows. */}
      <div style={{ display: 'flex', gap: 6, marginBottom: 12, flexWrap: 'wrap', borderBottom: '1px solid var(--border)', paddingBottom: 2 }}>
        {[
          ['all', 'All', CalendarDays, data.length],
          ['my', 'My Meetings', UserCheck, data.filter(mine).length],
          ['upcoming', 'Upcoming', Clock, data.filter(m => !isKoClosed(m.status) && m.scheduled_at && new Date(m.scheduled_at).getTime() >= now).length],
          ['pending_mom', 'Pending MOM', ClipboardCheck, data.filter(m => m.status === KO_STATUS.COMPLETED && m.mom_status !== 'Distributed').length],
          ['open_actions', 'Open Actions', ListChecks, data.filter(m => (m.open_actions ?? 0) > 0).length],
          ['templates', 'Templates', LayoutGrid, null],
        ].map(([v, label, Icon, count]) => {
          const on = quickView === v
          return (
            <button key={v} onClick={() => setQuickView(v)}
              style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 13px', fontSize: 12.5, fontWeight: 700, cursor: 'pointer',
                border: 'none', borderBottom: `2px solid ${on ? '#7C3AED' : 'transparent'}`, background: 'transparent', color: on ? 'var(--text-h)' : 'var(--text-muted)' }}>
              <Icon size={14} style={{ color: on ? '#a78bfa' : 'var(--text-muted)' }} /> {label}
              {count != null && <span style={{ fontSize: 10.5, fontWeight: 800, padding: '1px 7px', borderRadius: 999, background: on ? 'rgba(124,58,237,0.15)' : 'var(--bg-input)', color: on ? '#a78bfa' : 'var(--text-muted)' }}>{count}</span>}
            </button>
          )
        })}
      </div>

      {/* Filter chips + view toggle */}
      <div style={{ display: quickView === 'templates' ? 'none' : 'flex', gap: 8, marginBottom: 16, flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between' }}>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {['All', KO_STATUS.SCHEDULED, KO_STATUS.DELAYED, KO_STATUS.COMPLETED, KO_STATUS.CANCELLED].map(f => {
            const on = filter === f
            return (
              <button key={f} onClick={() => setFilter(f)}
                style={{ padding: '6px 14px', borderRadius: 999, fontSize: 12.5, fontWeight: 700, cursor: 'pointer',
                  background: on ? 'linear-gradient(145deg,#a78bfa,#7C3AED)' : 'var(--bg-card)',
                  border: on ? 'none' : '1px solid var(--border)',
                  color: on ? '#fff' : 'var(--text-muted)',
                  boxShadow: on ? '0 6px 16px -6px rgba(124,58,237,.6)' : 'none' }}>
                {f}
              </button>
            )
          })}
          {/* §16 project rollup — every meeting tagged to one project. */}
          {projects.length > 0 && (
            <select value={projectF} onChange={e => setProjectF(e.target.value)} title="Filter by project"
              style={{ padding: '6px 10px', borderRadius: 999, fontSize: 12.5, fontWeight: 700, cursor: 'pointer',
                background: projectF !== 'All' ? 'linear-gradient(145deg,#a78bfa,#7C3AED)' : 'var(--bg-card)',
                border: projectF !== 'All' ? 'none' : '1px solid var(--border)', color: projectF !== 'All' ? '#fff' : 'var(--text-muted)' }}>
              <option value="All">All projects</option>
              {projects.map(p => <option key={p.id} value={String(p.id)}>{p.name}</option>)}
            </select>
          )}
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
        {/* Rows per page — only meaningful for the table view. */}
        {view === 'list' && (
          <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12, color: 'var(--text-muted)' }}>
            Rows
            <select value={pageSize} onChange={e => setPageSize(e.target.value === 'all' ? 'all' : Number(e.target.value))}
              style={{ padding: '6px 8px', borderRadius: 8, fontSize: 12.5, fontWeight: 700, cursor: 'pointer',
                background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
              {[10, 25, 50, 100].map(n => <option key={n} value={n}>{n}</option>)}
              <option value="all">All</option>
            </select>
          </label>
        )}
        {/* List / Calendar toggle — the calendar renders the same (filtered) rows. */}
        <div style={{ display: 'inline-flex', borderRadius: 10, overflow: 'hidden', border: '1px solid var(--border)' }}>
          {[['list', 'List', List], ['calendar', 'Calendar', LayoutGrid]].map(([v, label, Icon]) => {
            const on = view === v
            return (
              <button key={v} onClick={() => setView(v)}
                style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 13px', fontSize: 12.5, fontWeight: 700, cursor: 'pointer', border: 'none',
                  background: on ? 'linear-gradient(145deg,#a78bfa,#7C3AED)' : 'var(--bg-card)', color: on ? '#fff' : 'var(--text-muted)' }}>
                <Icon size={14} /> {label}
              </button>
            )
          })}
        </div>
        </div>
      </div>

      {/* 12-column register (or calendar) */}
      {quickView === 'templates' ? (
        <TemplatesPanel />
      ) : loading ? (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          {[1, 2, 3].map(i => <div key={i} className="skeleton" style={{ height: 64, borderRadius: 12, background: 'var(--border)' }} />)}
        </div>
      ) : view === 'calendar' ? (
        <MeetingCalendar data={rows} onOpen={(mid) => navigate(`/app/tpv/kickoff/${mid}`)} />
      ) : rows.length === 0 ? (
        <EmptyState filter={filter} onNew={() => navigate('/app/tpv/kickoff/new')} />
      ) : (
        <div className="pr-glass" style={{ padding: 0, borderRadius: 16, overflow: 'hidden' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 1180, fontSize: 12.5 }}>
              <thead>
                <tr>
                  {['ID', 'Third Party Vendor', 'Participants', 'Meeting Mode', 'Planned Date', 'MOM Sent', 'Status', 'Attendance', 'Meeting Date', 'Created At'].map(h => (
                    <th key={h} style={th}>{h}</th>
                  ))}
                  <th style={{ ...th, textAlign: 'right', position: 'sticky', right: 0, background: 'var(--bg-card)' }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {pageRows.map(m => {
                  const cfg = koStatusCfg(m.status)
                  const busyView = pdfBusy === `${m.id}:view`
                  const busyDl   = pdfBusy === `${m.id}:dl`
                  return (
                    <tr key={m.id} className="ko-row" onClick={() => navigate(`/app/tpv/kickoff/${m.id}`)} style={{ cursor: 'pointer', borderTop: '1px solid var(--border)' }}>
                      {/* The meeting's own reference (MTG-YYYY-NNNN), not the row
                          id: it is what the MOM prints and what a vendor quotes. */}
                      <td style={td}>
                        <span style={{ fontWeight: 800, color: 'var(--text-h)', fontSize: 12 }}>{m.meeting_no || `#${m.id}`}</span>
                      </td>
                      <td style={td}>
                        <div style={{ fontWeight: 700, color: 'var(--text-h)', maxWidth: 190, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}
                          title={(m.subject_list || []).map(s => s.name).join(', ') || undefined}>
                          {m.subject?.name || m.title}
                          {/* One row cannot hold five names — the count says there
                              are more and the tooltip lists them. */}
                          {m.subject_list?.length > 1 && (
                            <span style={{ marginLeft: 6, fontSize: 10, fontWeight: 800, color: '#a78bfa' }}>
                              +{m.subject_list.length - 1}
                            </span>
                          )}
                        </div>
                      </td>
                      <td style={td}><Chip icon={Users}>{m.attendees_count ?? 0}</Chip></td>
                      <td style={td}>{koModeLabel(m.mode)}</td>
                      {/* Planned Date is the date originally promised — distinct
                          from the meeting datetime, which has its own column. */}
                      <td style={td}>{m.planned_date ? fmtDate(m.planned_date) : '—'}</td>
                      <td style={td}><YesNo yes={!!m.mom_path} /></td>
                      <td style={td}><span style={{ padding: '3px 10px', borderRadius: 999, background: cfg.bg, color: cfg.color, fontSize: 11, fontWeight: 800, whiteSpace: 'nowrap' }}>{cfg.label}</span></td>
                      <td style={td}><Attn present={m.attended_count ?? 0} total={m.attendees_count ?? 0} /></td>
                      {/* Meeting Date = when it is/was held. */}
                      <td style={td}>{m.scheduled_at ? fmtDateTime(m.scheduled_at) : '—'}</td>
                      <td style={td}>{fmtDate(m.created_at)}</td>
                      {/* Fixed action toolbar — not a data column */}
                      <td style={{ ...td, textAlign: 'right', position: 'sticky', right: 0, background: 'var(--bg-card)' }} onClick={e => e.stopPropagation()}>
                        <div style={{ display: 'inline-flex', gap: 4 }}>
                          <ActionBtn title="Attendance" icon={ClipboardCheck} color="#0ea5e9" onClick={() => setAttFor(m.id)} />
                          {/* Opens the full create/edit form — participants, MOM
                              items, mode and venue. The old inline modal only
                              carried a handful of fields. */}
                          <ActionBtn title="Edit" icon={Pencil} color="#a78bfa" onClick={() => navigate(`/app/tpv/kickoff/${m.id}/edit`)} />
                          <ActionBtn title="Reminder" icon={BellRing} color="#f59e0b" onClick={() => setRemindFor(m)} />
                          <ActionBtn title="View PDF" icon={busyView ? Loader2 : Eye} color="#10b981" spin={busyView} onClick={() => handlePdf(m, false)} />
                          <ActionBtn title="Download PDF" icon={busyDl ? Loader2 : Download} color="#7C3AED" spin={busyDl} onClick={() => handlePdf(m, true)} />
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          {/* Pagination footer */}
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, padding: '12px 16px', borderTop: '1px solid var(--border)', flexWrap: 'wrap' }}>
            <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>
              Showing <strong style={{ color: 'var(--text-h)' }}>{rangeFrom}–{rangeTo}</strong> of {total}
            </span>
            {pageSize !== 'all' && totalPages > 1 && (
              <div style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                <button onClick={() => setPage(p => Math.max(1, p - 1))} disabled={curPage <= 1} style={pagerBtn(curPage <= 1)}>
                  <ChevronLeft size={14} /> Prev
                </button>
                <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>Page <strong style={{ color: 'var(--text-h)' }}>{curPage}</strong> / {totalPages}</span>
                <button onClick={() => setPage(p => Math.min(totalPages, p + 1))} disabled={curPage >= totalPages} style={pagerBtn(curPage >= totalPages)}>
                  Next <ChevronRight size={14} />
                </button>
              </div>
            )}
          </div>
        </div>
      )}

      {attendanceFor && <AttendanceModal id={attendanceFor} onClose={() => setAttFor(null)} onDone={() => { setAttFor(null); load() }} />}
      {reminderFor && <ReminderModal m={reminderFor} onClose={() => setRemindFor(null)} />}
    </div>
  )
}

/* ── cells & chips ──────────────────────────────────────────────────────────── */
function Kpi({ label, value, icon: Icon, color, danger }) {
  return (
    <div className="pr-kpi" style={{ padding: 16, outline: danger ? `1.5px solid ${color}66` : 'none' }}>
      <div style={{ width: 38, height: 38, borderRadius: 12, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `${color}1f` }}>
        <Icon size={18} style={{ color }} />
      </div>
      <div style={{ fontSize: 24, fontWeight: 900, color: 'var(--text-h)', marginTop: 11, lineHeight: 1 }}>{value ?? 0}</div>
      <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-muted)', marginTop: 4 }}>{label}</div>
    </div>
  )
}

/** One dashboard breakdown (by type / project / vendor) — top rows + count. */
function BreakdownCard({ title, rows, keyField, empty = 'No data' }) {
  const list = rows || []
  const max = Math.max(1, ...list.map(r => r.count || 0))
  return (
    <div className="pr-kpi" style={{ padding: 14 }}>
      <div style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', marginBottom: 10 }}>{title}</div>
      {list.length === 0 ? (
        <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: 0, fontStyle: 'italic' }}>{empty}</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 7 }}>
          {list.slice(0, 6).map(r => (
            <div key={String(r[keyField])} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <span style={{ flex: 1, minWidth: 0, fontSize: 12, color: 'var(--text-h)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{r.label || r.name}</span>
              <span style={{ width: 54, height: 5, borderRadius: 999, background: 'var(--bg-input)', overflow: 'hidden', flexShrink: 0 }}>
                <span style={{ display: 'block', height: '100%', width: `${Math.round((r.count / max) * 100)}%`, background: '#a78bfa', borderRadius: 999 }} />
              </span>
              <span style={{ fontSize: 12, fontWeight: 800, color: 'var(--text-h)', minWidth: 22, textAlign: 'right' }}>{r.count}</span>
            </div>
          ))}
          {list.length > 6 && <span style={{ fontSize: 10.5, color: 'var(--text-muted)' }}>+{list.length - 6} more</span>}
        </div>
      )}
    </div>
  )
}

const Chip = ({ icon: Icon, children }) => (
  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, color: 'var(--text-h)', fontWeight: 700 }}>
    <Icon size={13} style={{ color: 'var(--text-muted)' }} /> {children}
  </span>
)

const YesNo = ({ yes }) => (
  <span style={{ padding: '2px 9px', borderRadius: 999, fontSize: 10.5, fontWeight: 800,
    background: yes ? 'rgba(16,185,129,0.14)' : 'var(--bg-input)',
    color: yes ? '#10b981' : 'var(--text-muted)' }}>{yes ? 'Yes' : 'No'}</span>
)

const Attn = ({ present, total }) => {
  const all = total > 0 && present === total
  const none = present === 0
  const color = total === 0 ? 'var(--text-muted)' : all ? '#10b981' : none ? '#ef4444' : '#f59e0b'
  return <span style={{ fontWeight: 800, color }}>{present}/{total}</span>
}

function ActionBtn({ title, icon: Icon, color, onClick, spin }) {
  return (
    <button title={title} onClick={onClick}
      style={{ width: 30, height: 30, borderRadius: 8, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer',
        background: 'var(--bg-input)', border: '1px solid var(--border)', color }}>
      <Icon size={15} className={spin ? 'ko-spin' : ''} />
    </button>
  )
}

function EmptyState({ onNew, filter }) {
  return (
    <div className="pr-glass" style={{ padding: '48px 24px', textAlign: 'center' }}>
      <div style={{ width: 60, height: 60, borderRadius: '50%', margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.12)' }}>
        <CalendarDays size={28} style={{ color: '#a78bfa' }} />
      </div>
      <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>
        {filter === 'All' ? 'No kickoff meetings yet' : `No ${filter.toLowerCase()} meetings`}
      </h3>
      <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '6px 0 18px' }}>
        Schedule a pre-onboarding meeting with a vendor to get started.
      </p>
      {filter === 'All' && <button onClick={onNew} style={{ ...solidBtn, margin: '0 auto' }}><Plus size={15} /> Schedule meeting</button>}
    </div>
  )
}

/* ── Templates & Types settings (Meeting.docx §4 / admin Types-Templates) ─────
 * Reference of every meeting type's standard agenda (what the Agenda Builder's
 * one-click "Load template" inserts), PLUS an admin editor: types live in
 * config/meetings.php as the built-in baseline, and a tenant can add its own or
 * override a built-in via meeting_types rows (merged by MeetingTypeCatalog). */
function TemplatesPanel() {
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'
  const [data, setData] = useState(null)
  const [err, setErr] = useState(null)
  const [editing, setEditing] = useState(null) // row being edited / created

  const load = () => kickoffApi.typeSettings().then(setData).catch(() => setErr('Could not load the meeting types.'))
  useEffect(() => { load() }, [])

  const remove = async (row) => {
    if (!window.confirm(`Remove "${row.label}"? The built-in default (if any) applies again.`)) return
    try { await kickoffApi.deleteType(row.id); load() }
    catch (e) { setErr(e?.response?.data?.message || 'Could not remove the type.') }
  }

  if (err) return <div className="pr-glass" style={{ padding: 24, color: 'var(--text-muted)', fontSize: 13 }}>{err}</div>
  if (!data) return <div className="skeleton" style={{ height: 200, borderRadius: 16, background: 'var(--border)' }} />

  const builtins = data.builtins || []
  const custom = data.custom || []

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap', marginBottom: 14 }}>
        <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: 0, lineHeight: 1.5, maxWidth: 620 }}>
          Standard agendas per meeting type — the Agenda Builder loads these with one click. Built-in types ship with the app;
          {isAdmin ? ' admins can add their own or override a built-in below.' : ' an admin can add custom types.'}
        </p>
        {isAdmin && (
          <button onClick={() => setEditing({ key: '', label: '', templates: [], is_active: true, sort_order: 0 })} style={solidBtn}>
            <Plus size={15} /> Add meeting type
          </button>
        )}
      </div>

      {editing && <TypeEditor row={editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); load() }} onError={setErr} />}

      {custom.length > 0 && (
        <>
          <div style={sectionLabel}><Settings2 size={13} /> Custom &amp; overrides ({custom.length})</div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(320px,1fr))', gap: 14, marginBottom: 22 }}>
            {custom.map(row => (
              <TypeCard key={row.id} title={row.label} badge={row.is_active ? null : 'Hidden'} keyName={row.key} items={row.templates || []}
                actions={isAdmin ? (
                  <div style={{ display: 'inline-flex', gap: 4 }}>
                    <IconBtn title="Edit" icon={Pencil} color="#a78bfa" onClick={() => setEditing({ ...row, templates: row.templates || [] })} />
                    <IconBtn title="Remove" icon={Trash2} color="#ef4444" onClick={() => remove(row)} />
                  </div>
                ) : null} />
            ))}
          </div>
        </>
      )}

      <div style={sectionLabel}><LayoutGrid size={13} /> Built-in types ({builtins.length})</div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(320px,1fr))', gap: 14 }}>
        {builtins.map(b => (
          <TypeCard key={b.key} title={b.label} keyName={b.key} items={b.templates || []}
            actions={isAdmin ? <IconBtn title="Override for this tenant" icon={Pencil} color="#a78bfa"
              onClick={() => setEditing({ key: b.key, label: b.label, templates: b.templates || [], is_active: true, sort_order: 0 })} /> : null} />
        ))}
      </div>
    </div>
  )
}

function TypeCard({ title, keyName, items, actions, badge }) {
  return (
    <div className="pr-glass" style={{ padding: 18 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8, marginBottom: 12 }}>
        <div style={{ minWidth: 0 }}>
          <h3 style={{ margin: 0, fontSize: 14.5, fontWeight: 900, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 7 }}>
            {title}
            {badge && <span style={{ fontSize: 9.5, fontWeight: 800, padding: '1px 7px', borderRadius: 999, background: 'var(--bg-input)', color: 'var(--text-muted)' }}>{badge}</span>}
          </h3>
          <span style={{ fontSize: 10.5, color: 'var(--text-muted)', fontFamily: 'monospace' }}>{keyName}</span>
        </div>
        {actions}
      </div>
      {items.length === 0 ? (
        <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: 0, fontStyle: 'italic' }}>No template items.</p>
      ) : (
        <ol style={{ margin: 0, padding: 0, listStyle: 'none', display: 'flex', flexDirection: 'column', gap: 7 }}>
          {items.map((it, i) => (
            <li key={i} style={{ display: 'flex', gap: 9, fontSize: 12.5, color: 'var(--text-h)', lineHeight: 1.45 }}>
              <span style={{ fontSize: 10.5, fontWeight: 800, color: '#a78bfa', minWidth: 16 }}>{i + 1}.</span>
              <span style={{ flex: 1 }}>
                {it.item}
                {(it.duration_minutes || it.priority) && (
                  <span style={{ fontSize: 10.5, color: 'var(--text-muted)', marginLeft: 6 }}>
                    {[it.duration_minutes && `${it.duration_minutes} min`, it.priority].filter(Boolean).join(' · ')}
                  </span>
                )}
              </span>
            </li>
          ))}
        </ol>
      )}
    </div>
  )
}

const IconBtn = ({ title, icon: Icon, color, onClick }) => (
  <button title={title} onClick={onClick}
    style={{ width: 28, height: 28, borderRadius: 8, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', background: 'var(--bg-input)', border: '1px solid var(--border)', color }}>
    <Icon size={14} />
  </button>
)

const sectionLabel = { display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', margin: '0 0 10px' }

/* Admin editor for one meeting type + its agenda template. */
function TypeEditor({ row, onClose, onSaved, onError }) {
  const isOverrideOfBuiltin = !row.id && row.key !== ''
  const [key, setKey] = useState(row.key || '')
  const [label, setLabel] = useState(row.label || '')
  const [active, setActive] = useState(row.is_active !== false)
  const [items, setItems] = useState(row.templates?.length ? row.templates.map(t => ({ ...t })) : [{ item: '', duration_minutes: '', priority: '' }])
  const [busy, setBusy] = useState(false)

  const setItem = (i, k, v) => setItems(a => a.map((x, j) => j === i ? { ...x, [k]: v } : x))
  const addItem = () => setItems(a => [...a, { item: '', duration_minutes: '', priority: '' }])
  const rmItem = (i) => setItems(a => a.filter((_, j) => j !== i))

  const save = async () => {
    onError(null)
    const cleanKey = key.trim().toLowerCase().replace(/[^a-z0-9_]/g, '_')
    if (!/^[a-z][a-z0-9_]*$/.test(cleanKey)) { onError('Key must start with a letter and use only lowercase letters, digits and underscores.'); return }
    if (!label.trim()) { onError('A label is required.'); return }
    const payload = {
      key: cleanKey,
      label: label.trim(),
      is_active: active,
      templates: items.filter(it => it.item.trim()).map(it => ({
        item: it.item.trim(),
        duration_minutes: it.duration_minutes ? Number(it.duration_minutes) : undefined,
        priority: it.priority || undefined,
      })),
    }
    setBusy(true)
    try {
      if (row.id) await kickoffApi.updateType(row.id, payload)
      else await kickoffApi.createType(payload)
      onSaved()
    } catch (e) {
      onError(e?.response?.data?.message || 'Could not save the meeting type.')
      setBusy(false)
    }
  }

  return (
    <div className="pr-glass" style={{ padding: 20, marginBottom: 20, border: '1px solid rgba(124,58,237,0.35)' }}>
      <h3 style={{ margin: '0 0 14px', fontSize: 15, fontWeight: 900, color: 'var(--text-h)' }}>
        {row.id ? 'Edit type' : isOverrideOfBuiltin ? `Override built-in "${row.label}"` : 'New meeting type'}
      </h3>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginBottom: 14 }}>
        <label style={{ fontSize: 12 }}>
          <span style={editLbl}>Key</span>
          <input value={key} disabled={isOverrideOfBuiltin || !!row.id} onChange={e => setKey(e.target.value)}
            placeholder="e.g. safety_standdown" style={{ ...editInput, opacity: (isOverrideOfBuiltin || row.id) ? 0.6 : 1 }} />
        </label>
        <label style={{ fontSize: 12 }}>
          <span style={editLbl}>Label</span>
          <input value={label} onChange={e => setLabel(e.target.value)} placeholder="e.g. Safety Stand-down" style={editInput} />
        </label>
      </div>
      <label style={{ display: 'inline-flex', alignItems: 'center', gap: 8, fontSize: 12.5, color: 'var(--text-h)', marginBottom: 14, cursor: 'pointer' }}>
        <input type="checkbox" checked={active} onChange={e => setActive(e.target.checked)} />
        Active (unchecking hides this type — for a built-in key, removes it from the pickers)
      </label>

      <div style={editLbl}>Agenda template</div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginBottom: 10 }}>
        {items.map((it, i) => (
          <div key={i} style={{ display: 'grid', gridTemplateColumns: '1fr 90px 110px 30px', gap: 8, alignItems: 'center' }}>
            <input value={it.item} onChange={e => setItem(i, 'item', e.target.value)} placeholder={`Agenda item ${i + 1}`} style={editInput} />
            <input value={it.duration_minutes} onChange={e => setItem(i, 'duration_minutes', e.target.value)} placeholder="min" type="number" style={editInput} />
            <select value={it.priority || ''} onChange={e => setItem(i, 'priority', e.target.value)} style={editInput}>
              <option value="">Priority…</option>
              {['Low', 'Medium', 'High'].map(p => <option key={p} value={p}>{p}</option>)}
            </select>
            <button onClick={() => rmItem(i)} title="Remove" style={{ width: 28, height: 28, borderRadius: 8, border: '1px solid rgba(239,68,68,0.3)', background: 'rgba(239,68,68,0.06)', color: '#ef4444', cursor: 'pointer' }}><Trash2 size={12} /></button>
          </div>
        ))}
        <button onClick={addItem} style={{ ...ghostBtn, alignSelf: 'flex-start' }}><Plus size={13} /> Add item</button>
      </div>

      <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
        <button onClick={onClose} style={ghostBtn}>Cancel</button>
        <button onClick={save} disabled={busy} style={{ ...solidBtn, opacity: busy ? 0.6 : 1 }}>{busy ? 'Saving…' : 'Save type'}</button>
      </div>
    </div>
  )
}

const editLbl = { display: 'block', fontSize: 10.5, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--text-muted)', marginBottom: 5 }
const editInput = { width: '100%', padding: '8px 10px', borderRadius: 8, fontSize: 12.5, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }

/* ── Calendar ───────────────────────────────────────────────────────────────── */
/**
 * Meetings on a calendar, in one of four views — Month · Week · Day · Agenda
 * (Meeting.docx §15) — with in-view Type and Organizer filters. Renders the same
 * (status-filtered) rows the table does — no separate fetch — so the views never
 * disagree. Clicking a meeting opens its detail. */
const CAL_VIEWS = [['month', 'Month'], ['week', 'Week'], ['day', 'Day'], ['agenda', 'Agenda']]
const dayKeyOf = (d) => `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`
const hhmm = (d) => d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true }).replace(' ', '')

function MeetingCalendar({ data, onOpen }) {
  const [mode, setMode]     = useState('month')          // month | week | day | agenda
  const [cursor, setCursor] = useState(() => new Date()) // any date inside the shown range
  const [typeF, setTypeF]   = useState('All')
  const [orgF, setOrgF]     = useState('All')
  const [vendorF, setVendorF] = useState('All')
  const [deptF, setDeptF]     = useState('All')
  const [partF, setPartF]     = useState('All')

  // Distinct values present in the loaded rows, for the §15 filters.
  const { types, orgs, vendors, depts, participants } = useMemo(() => {
    const t = new Map(), o = new Set(), v = new Set(), d = new Set(), p = new Set()
    ;(data || []).forEach(m => {
      if (m.meeting_type) t.set(m.meeting_type, m.meeting_type_label || m.meeting_type)
      if (m.creator?.name) o.add(m.creator.name)
      if (m.subject?.name) v.add(m.subject.name)
      if (m.department) d.add(m.department)
      ;(m.attendees || []).forEach(a => { if (a.name) p.add(a.name) })
    })
    return { types: [...t.entries()], orgs: [...o], vendors: [...v], depts: [...d], participants: [...p] }
  }, [data])

  const rows = useMemo(() => (data || []).filter(m =>
    (typeF === 'All' || m.meeting_type === typeF) &&
    (orgF === 'All' || m.creator?.name === orgF) &&
    (vendorF === 'All' || m.subject?.name === vendorF) &&
    (deptF === 'All' || m.department === deptF) &&
    (partF === 'All' || (m.attendees || []).some(a => a.name === partF)),
  ), [data, typeF, orgF, vendorF, deptF, partF])

  const byDay = useMemo(() => {
    const map = {}
    rows.forEach(m => {
      if (!m.scheduled_at) return
      const d = new Date(m.scheduled_at)
      if (Number.isNaN(d.getTime())) return
      ;(map[dayKeyOf(d)] = map[dayKeyOf(d)] || []).push({ m, d })
    })
    Object.values(map).forEach(arr => arr.sort((a, b) => a.d - b.d))
    return map
  }, [rows])

  const today = new Date()
  const todayKey = dayKeyOf(today)

  // Prev/next step size follows the view.
  const step = (dir) => setCursor(c => {
    const d = new Date(c)
    if (mode === 'month') d.setMonth(d.getMonth() + dir)
    else if (mode === 'week' || mode === 'agenda') d.setDate(d.getDate() + dir * 7)
    else d.setDate(d.getDate() + dir)
    return d
  })

  const rangeLabel = mode === 'month'
    ? cursor.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' })
    : mode === 'day'
      ? cursor.toLocaleDateString('en-IN', { weekday: 'long', day: '2-digit', month: 'short', year: 'numeric' })
      : (() => { const s = new Date(cursor); s.setDate(s.getDate() - s.getDay()); const e = new Date(s); e.setDate(s.getDate() + 6)
          return `${s.toLocaleDateString('en-IN', { day: '2-digit', month: 'short' })} – ${e.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })}` })()

  const selStyle = { height: 32, borderRadius: 8, padding: '0 8px', fontSize: 12, fontWeight: 600, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)', cursor: 'pointer' }

  return (
    <div className="pr-glass" style={{ padding: 18 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 14, gap: 10, flexWrap: 'wrap' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <button onClick={() => step(-1)} style={navBtn} title="Previous"><ChevronLeft size={16} /></button>
          <h3 style={{ margin: 0, fontSize: 15, fontWeight: 900, color: 'var(--text-h)', minWidth: 190, textAlign: 'center' }}>{rangeLabel}</h3>
          <button onClick={() => step(1)} style={navBtn} title="Next"><ChevronRight size={16} /></button>
          <button onClick={() => setCursor(new Date())} style={{ ...navBtn, width: 'auto', padding: '0 12px', fontSize: 12, fontWeight: 700 }}>Today</button>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
          {types.length > 0 && (
            <select value={typeF} onChange={e => setTypeF(e.target.value)} style={selStyle} title="Filter by type">
              <option value="All">All types</option>
              {types.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
            </select>
          )}
          {orgs.length > 0 && (
            <select value={orgF} onChange={e => setOrgF(e.target.value)} style={selStyle} title="Filter by organizer">
              <option value="All">All organizers</option>
              {orgs.map(o => <option key={o} value={o}>{o}</option>)}
            </select>
          )}
          {vendors.length > 0 && (
            <select value={vendorF} onChange={e => setVendorF(e.target.value)} style={selStyle} title="Filter by vendor">
              <option value="All">All vendors</option>
              {vendors.map(v => <option key={v} value={v}>{v}</option>)}
            </select>
          )}
          {depts.length > 0 && (
            <select value={deptF} onChange={e => setDeptF(e.target.value)} style={selStyle} title="Filter by department">
              <option value="All">All departments</option>
              {depts.map(d => <option key={d} value={d}>{d}</option>)}
            </select>
          )}
          {participants.length > 0 && (
            <select value={partF} onChange={e => setPartF(e.target.value)} style={selStyle} title="Filter by participant">
              <option value="All">All participants</option>
              {participants.map(p => <option key={p} value={p}>{p}</option>)}
            </select>
          )}
          <div style={{ display: 'inline-flex', gap: 4, background: 'var(--bg-input)', borderRadius: 9, padding: 3, border: '1px solid var(--border)' }}>
            {CAL_VIEWS.map(([v, label]) => {
              const on = mode === v
              return (
                <button key={v} onClick={() => setMode(v)}
                  style={{ padding: '5px 11px', borderRadius: 7, fontSize: 12, fontWeight: 700, cursor: 'pointer', border: 'none',
                    background: on ? 'linear-gradient(135deg,#a78bfa,#7C3AED)' : 'transparent', color: on ? '#fff' : 'var(--text-muted)' }}>
                  {label}
                </button>
              )
            })}
          </div>
        </div>
      </div>

      {mode === 'month' && <MonthGrid cursor={cursor} byDay={byDay} todayKey={todayKey} onOpen={onOpen} />}
      {mode === 'week'  && <WeekGrid cursor={cursor} byDay={byDay} todayKey={todayKey} onOpen={onOpen} />}
      {mode === 'day'   && <DayList day={cursor} items={byDay[dayKeyOf(cursor)] || []} onOpen={onOpen} />}
      {mode === 'agenda' && <AgendaList cursor={cursor} rows={rows} onOpen={onOpen} />}

      <div style={{ display: 'flex', gap: 14, marginTop: 12, flexWrap: 'wrap' }}>
        {[KO_STATUS.SCHEDULED, KO_STATUS.DELAYED, KO_STATUS.COMPLETED, KO_STATUS.CANCELLED].map(s => {
          const c = koStatusCfg(s)
          return <span key={s} style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 11, color: 'var(--text-muted)' }}><span style={{ width: 8, height: 8, borderRadius: '50%', background: c.color }} /> {c.label}</span>
        })}
      </div>
    </div>
  )
}

/** An event pill used across all calendar views. */
function CalEvent({ m, d, onOpen, showDate = false }) {
  const cfg = koStatusCfg(m.status)
  return (
    <button onClick={() => onOpen(m.id)} title={`${m.subject?.name || m.title} · ${hhmm(d)}`}
      style={{ display: 'flex', alignItems: 'center', gap: 5, textAlign: 'left', padding: '3px 6px', borderRadius: 6, cursor: 'pointer', border: 'none', background: cfg.bg, color: cfg.color, fontSize: 10.5, fontWeight: 700, overflow: 'hidden', whiteSpace: 'nowrap', width: '100%' }}>
      <span style={{ width: 5, height: 5, borderRadius: '50%', background: cfg.color, flexShrink: 0 }} />
      <span style={{ overflow: 'hidden', textOverflow: 'ellipsis' }}>
        {showDate ? `${d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short' })} ` : ''}{hhmm(d)} {m.subject?.name || m.title}
      </span>
    </button>
  )
}

function MonthGrid({ cursor, byDay, todayKey, onOpen }) {
  const year = cursor.getFullYear(), month = cursor.getMonth()
  const gridStart = new Date(year, month, 1 - new Date(year, month, 1).getDay())
  const days = Array.from({ length: 42 }, (_, i) => { const d = new Date(gridStart); d.setDate(gridStart.getDate() + i); return d })
  return (
    <>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7,1fr)', gap: 6, marginBottom: 6 }}>
        {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(w => (
          <div key={w} style={{ fontSize: 10.5, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--text-muted)', textAlign: 'center', padding: '2px 0' }}>{w}</div>
        ))}
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7,1fr)', gap: 6 }}>
        {days.map((d, i) => {
          const items = byDay[dayKeyOf(d)] || []
          const other = d.getMonth() !== month
          const isToday = dayKeyOf(d) === todayKey
          return (
            <div key={i} style={{ minHeight: 98, borderRadius: 10, padding: 6, background: other ? 'transparent' : 'var(--bg-input)', border: `1px solid ${isToday ? '#7C3AED' : 'var(--border)'}`, opacity: other ? 0.4 : 1, display: 'flex', flexDirection: 'column', gap: 4 }}>
              <div style={{ fontSize: 11, fontWeight: isToday ? 800 : 600, color: isToday ? '#a78bfa' : 'var(--text-muted)', textAlign: 'right' }}>{d.getDate()}</div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 3, overflow: 'hidden' }}>
                {items.slice(0, 3).map(({ m, d: md }) => <CalEvent key={m.id} m={m} d={md} onOpen={onOpen} />)}
                {items.length > 3 && <span style={{ fontSize: 10, fontWeight: 700, color: 'var(--text-muted)', paddingLeft: 3 }}>+{items.length - 3} more</span>}
              </div>
            </div>
          )
        })}
      </div>
    </>
  )
}

function WeekGrid({ cursor, byDay, todayKey, onOpen }) {
  const start = new Date(cursor); start.setDate(start.getDate() - start.getDay())
  const days = Array.from({ length: 7 }, (_, i) => { const d = new Date(start); d.setDate(start.getDate() + i); return d })
  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7,1fr)', gap: 6 }}>
      {days.map((d, i) => {
        const items = byDay[dayKeyOf(d)] || []
        const isToday = dayKeyOf(d) === todayKey
        return (
          <div key={i} style={{ minHeight: 220, borderRadius: 10, padding: 7, background: 'var(--bg-input)', border: `1px solid ${isToday ? '#7C3AED' : 'var(--border)'}`, display: 'flex', flexDirection: 'column', gap: 5 }}>
            <div style={{ textAlign: 'center', paddingBottom: 5, borderBottom: '1px solid var(--border)' }}>
              <div style={{ fontSize: 9.5, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--text-muted)' }}>{d.toLocaleDateString('en-IN', { weekday: 'short' })}</div>
              <div style={{ fontSize: 14, fontWeight: isToday ? 900 : 700, color: isToday ? '#a78bfa' : 'var(--text-h)' }}>{d.getDate()}</div>
            </div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 4, overflow: 'hidden' }}>
              {items.length === 0
                ? <span style={{ fontSize: 10.5, color: 'var(--text-muted)', textAlign: 'center', marginTop: 6 }}>—</span>
                : items.map(({ m, d: md }) => <CalEvent key={m.id} m={m} d={md} onOpen={onOpen} />)}
            </div>
          </div>
        )
      })}
    </div>
  )
}

function DayList({ day, items, onOpen }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
      {items.length === 0 ? (
        <div style={{ padding: '28px 16px', borderRadius: 12, background: 'var(--bg-input)', border: '1px dashed var(--border)', textAlign: 'center', color: 'var(--text-muted)', fontSize: 13 }}>
          No meetings on {day.toLocaleDateString('en-IN', { weekday: 'long', day: '2-digit', month: 'long' })}.
        </div>
      ) : items.map(({ m, d }) => {
        const cfg = koStatusCfg(m.status)
        return (
          <button key={m.id} onClick={() => onOpen(m.id)}
            style={{ display: 'flex', alignItems: 'center', gap: 12, textAlign: 'left', padding: '12px 14px', borderRadius: 12, cursor: 'pointer', background: 'var(--bg-input)', border: '1px solid var(--border)', width: '100%' }}>
            <div style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', minWidth: 66 }}>{hhmm(d)}</div>
            <span style={{ width: 8, height: 8, borderRadius: '50%', background: cfg.color, flexShrink: 0 }} />
            <div style={{ minWidth: 0, flex: 1 }}>
              <div style={{ fontSize: 13.5, fontWeight: 700, color: 'var(--text-h)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{m.subject?.name || m.title}</div>
              <div style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{[m.meeting_type_label, m.creator?.name && `by ${m.creator.name}`].filter(Boolean).join(' · ')}</div>
            </div>
            <span style={{ fontSize: 10.5, fontWeight: 700, padding: '3px 9px', borderRadius: 999, background: cfg.bg, color: cfg.color }}>{cfg.label}</span>
          </button>
        )
      })}
    </div>
  )
}

/** Upcoming meetings from the cursor date onward, grouped by day. */
function AgendaList({ cursor, rows, onOpen }) {
  const from = new Date(cursor); from.setHours(0, 0, 0, 0)
  const upcoming = useMemo(() => rows
    .filter(m => m.scheduled_at && new Date(m.scheduled_at) >= from)
    .map(m => ({ m, d: new Date(m.scheduled_at) }))
    .sort((a, b) => a.d - b.d), [rows, from])

  const groups = useMemo(() => {
    const g = []
    let last = null
    upcoming.forEach(({ m, d }) => {
      const k = dayKeyOf(d)
      if (k !== last) { g.push({ key: k, d, items: [] }); last = k }
      g[g.length - 1].items.push({ m, d })
    })
    return g
  }, [upcoming])

  if (groups.length === 0) return (
    <div style={{ padding: '28px 16px', borderRadius: 12, background: 'var(--bg-input)', border: '1px dashed var(--border)', textAlign: 'center', color: 'var(--text-muted)', fontSize: 13 }}>
      No upcoming meetings from this date.
    </div>
  )
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
      {groups.map(g => (
        <div key={g.key}>
          <div style={{ fontSize: 11.5, fontWeight: 800, color: '#a78bfa', textTransform: 'uppercase', letterSpacing: '0.04em', marginBottom: 7 }}>
            {g.d.toLocaleDateString('en-IN', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' })}
          </div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
            {g.items.map(({ m, d }) => {
              const cfg = koStatusCfg(m.status)
              return (
                <button key={m.id} onClick={() => onOpen(m.id)}
                  style={{ display: 'flex', alignItems: 'center', gap: 12, textAlign: 'left', padding: '10px 13px', borderRadius: 11, cursor: 'pointer', background: 'var(--bg-input)', border: '1px solid var(--border)', width: '100%' }}>
                  <div style={{ fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)', minWidth: 60 }}>{hhmm(d)}</div>
                  <span style={{ width: 7, height: 7, borderRadius: '50%', background: cfg.color, flexShrink: 0 }} />
                  <div style={{ minWidth: 0, flex: 1 }}>
                    <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{m.subject?.name || m.title}</div>
                    <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{[m.meeting_type_label, m.creator?.name && `by ${m.creator.name}`].filter(Boolean).join(' · ')}</div>
                  </div>
                  <span style={{ fontSize: 10.5, fontWeight: 700, padding: '3px 9px', borderRadius: 999, background: cfg.bg, color: cfg.color }}>{cfg.label}</span>
                </button>
              )
            })}
          </div>
        </div>
      ))}
    </div>
  )
}

const navBtn = { width: 32, height: 32, borderRadius: 8, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)' }

/* ── Attendance modal ───────────────────────────────────────────────────────── */
function AttendanceModal({ id, onClose, onDone }) {
  const [rows, setRows] = useState(null)
  const [title, setTitle] = useState('')
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)

  useEffect(() => {
    kickoffApi.get(id).then(d => {
      const m = d?.data ?? d
      setTitle(m.title)
      setRows((m.attendees || []).map(a => ({
        id: a.id, name: a.name, role: a.role, organisation: a.organisation,
        // attendance_status is the truth; fall back to the legacy boolean for
        // meetings marked before the three-state column existed. null is a real
        // value here — "not marked yet" — so it must survive the round trip.
        attendance_status: a.attendance_status ?? (a.attended ? 'Present' : null),
        remark: a.remark ?? '',
      })))
    }).catch(() => setErr('Could not load the attendee list.'))
  }, [id])

  const setStatus = (aid, val) =>
    // Clicking the active button clears it, back to "not marked yet".
    setRows(rs => rs.map(r => (r.id === aid ? { ...r, attendance_status: r.attendance_status === val ? null : val } : r)))

  const setRemark = (aid, val) => setRows(rs => rs.map(r => (r.id === aid ? { ...r, remark: val } : r)))

  const save = async ({ notify = false } = {}) => {
    setSaving(true); setErr(null)
    try {
      await kickoffApi.markAttendance(id, rows.map(r => ({
        id: r.id,
        attendance_status: r.attendance_status,
        remark: r.remark || null,
      })))
      // The summary is the existing reminder mail — one endpoint, no new
      // notification path — sent after the save so it reflects what was stored.
      if (notify) await kickoffApi.remind(id)
      onDone()
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not save attendance.')
      setSaving(false)
    }
  }

  const marked = (rows || []).filter(r => r.attendance_status).length

  return (
    <Overlay onClose={onClose} width={560}>
      <div style={{ padding: '20px 22px 6px' }}>
        <h2 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>Mark attendance</h2>
        <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>{title}</p>
      </div>
      <div style={{ padding: '10px 22px', maxHeight: 420, overflowY: 'auto' }}>
        {rows === null ? (
          <div style={{ padding: 24, textAlign: 'center' }}><Loader2 size={20} className="ko-spin" style={{ color: '#a78bfa' }} /></div>
        ) : rows.length === 0 ? (
          <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>No attendees are recorded for this meeting. Add attendees via the schedule form first.</p>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            {rows.map(a => (
              <div key={a.id} style={{ padding: '10px 12px', borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                  <div style={{ width: 34, height: 34, borderRadius: '50%', flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.14)', color: '#a78bfa', fontWeight: 800, fontSize: 13 }}>
                    {(a.name || '?').charAt(0).toUpperCase()}
                  </div>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{a.name}</div>
                    {/* Participant type · Third Party Vendor */}
                    <div style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{[a.role, a.organisation].filter(Boolean).join(' · ') || '—'}</div>
                  </div>
                  <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4, justifyContent: 'flex-end' }}>
                    <SegBtn active={a.attendance_status === 'Present'} color="#10b981" icon={CheckCircle2} onClick={() => setStatus(a.id, 'Present')}>Present</SegBtn>
                    <SegBtn active={a.attendance_status === 'Late'}    color="#f59e0b" icon={Clock}        onClick={() => setStatus(a.id, 'Late')}>Late</SegBtn>
                    <SegBtn active={a.attendance_status === 'Absent'}  color="#ef4444" icon={XCircle}      onClick={() => setStatus(a.id, 'Absent')}>Absent</SegBtn>
                    <SegBtn active={a.attendance_status === 'Excused'} color="#a78bfa" icon={UserX}        onClick={() => setStatus(a.id, 'Excused')}>Excused</SegBtn>
                    <SegBtn active={a.attendance_status === 'Online'}  color="#0ea5e9" icon={Laptop}       onClick={() => setStatus(a.id, 'Online')}>Online</SegBtn>
                    <SegBtn active={a.attendance_status === 'Offline'} color="#64748b" icon={Building2}    onClick={() => setStatus(a.id, 'Offline')}>Offline</SegBtn>
                  </div>
                </div>
                <input
                  value={a.remark}
                  onChange={e => setRemark(a.id, e.target.value)}
                  placeholder="Remark (optional) — e.g. joined 10 minutes late"
                  maxLength={1000}
                  style={{ marginTop: 8, width: '100%', padding: '6px 10px', fontSize: 12, borderRadius: 8,
                    background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-body)' }}
                />
              </div>
            ))}
          </div>
        )}
        {err && <ModalError>{err}</ModalError>}
      </div>
      {/* Send Email Summary saves first, then reuses the existing reminder
          endpoint — so the mail always reflects what was actually stored. */}
      {rows !== null && rows.length > 0 && (
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, padding: '0 22px 4px' }}>
          <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{marked} of {rows.length} marked</span>
          <button onClick={() => save({ notify: true })} disabled={saving}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 12px', fontSize: 12, fontWeight: 700,
              borderRadius: 9, cursor: saving ? 'not-allowed' : 'pointer', background: 'rgba(14,165,233,0.12)',
              border: '1px solid rgba(14,165,233,0.4)', color: '#0ea5e9', opacity: saving ? 0.6 : 1 }}>
            <Send size={13} /> Save &amp; email summary
          </button>
        </div>
      )}
      <ModalFooter onClose={onClose} onConfirm={() => save()} loading={saving} confirmLabel="Save attendance" color="#7C3AED"
        disabled={rows === null || rows.length === 0} />
    </Overlay>
  )
}

const SegBtn = ({ active, color, icon: Icon, onClick, children }) => (
  <button onClick={onClick}
    style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 10px', fontSize: 11.5, fontWeight: 700, cursor: 'pointer',
      borderRadius: 8, border: `1px solid ${active ? color + '66' : 'var(--border)'}`,
      background: active ? `${color}22` : 'var(--bg-card)', color: active ? color : 'var(--text-muted)' }}>
    <Icon size={12} /> {children}
  </button>
)

/* ── Reminder modal ─────────────────────────────────────────────────────────── */
function ReminderModal({ m, onClose }) {
  const [sending, setSending] = useState(false)
  const [result, setResult] = useState(null)
  const [err, setErr] = useState(null)

  const send = async () => {
    setSending(true); setErr(null)
    try {
      const res = await kickoffApi.remind(m.id)
      setResult(res?.result ?? res)
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not send the reminder.')
    } finally { setSending(false) }
  }

  return (
    <Overlay onClose={onClose} width={480}>
      <div style={{ padding: '20px 22px 6px' }}>
        <h2 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>Send reminder</h2>
        <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>{m.title}</p>
      </div>
      <div style={{ padding: '12px 22px' }}>
        {result ? (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 9 }}>
            <ChannelRow icon={Mail} label="Email" state="Sent"
              detail={`${result.email?.sent ?? 0} sent${result.email?.skipped ? `, ${result.email.skipped} skipped` : ''}${result.email?.failed ? `, ${result.email.failed} failed` : ''} · ${result.recipients ?? 0} attendee(s)`}
              tone="#10b981" />
            <ChannelRow icon={MessageCircle} label="WhatsApp" state={stubLabel(result.whatsapp)} detail={stubDetail(result.whatsapp)} tone="#f59e0b" />
            <ChannelRow icon={Smartphone} label="SMS" state={stubLabel(result.sms)} detail={stubDetail(result.sms)} tone="#f59e0b" />
            <p style={{ fontSize: 11, color: 'var(--text-muted)', margin: '4px 0 0', lineHeight: 1.5 }}>
              WhatsApp and SMS are queued through a stub provider — delivery is not confirmed.
            </p>
          </div>
        ) : (
          <>
            <p style={{ fontSize: 13, color: 'var(--text-h)', margin: 0, lineHeight: 1.55 }}>
              Send a reminder to the meeting attendees. Email is delivered live; WhatsApp and SMS are queued via a stub provider (not delivered).
            </p>
            {err && <ModalError>{err}</ModalError>}
          </>
        )}
      </div>
      {result ? (
        <div style={{ display: 'flex', justifyContent: 'flex-end', padding: '10px 22px 18px' }}>
          <button onClick={onClose} style={solidBtn}>Done</button>
        </div>
      ) : (
        <ModalFooter onClose={onClose} onConfirm={send} loading={sending} confirmLabel="Send reminder" color="#f59e0b" />
      )}
    </Overlay>
  )
}

const stubLabel  = (s) => (s === 'queued' ? 'Queued' : s === 'skipped' ? 'Skipped' : (s || 'Skipped'))
const stubDetail = (s) => (s === 'queued' ? 'Queued via stub provider (not delivered)' : 'No vendor phone number on file')

function ChannelRow({ icon: Icon, label, state, detail, tone }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '11px 13px', borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
      <Icon size={17} style={{ color: tone, flexShrink: 0 }} />
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{label}</div>
        <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{detail}</div>
      </div>
      <span style={{ padding: '3px 11px', borderRadius: 999, fontSize: 11, fontWeight: 800, background: `${tone}1f`, color: tone }}>{state}</span>
    </div>
  )
}

/* ── bits ───────────────────────────────────────────────────────────────────── */
const ModalError = ({ children }) => (
  <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 12px', borderRadius: 10, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)', marginTop: 8 }}>
    <AlertTriangle size={14} style={{ color: '#ef4444', flexShrink: 0 }} />
    <span style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{children}</span>
  </div>
)

// ISO timestamp → the value a <input type="datetime-local"> expects, in local time.
function toLocalInput(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  const p = n => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`
}

const th = { textAlign: 'left', padding: '11px 14px', fontSize: 10.5, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--text-muted)', whiteSpace: 'nowrap', background: 'var(--bg-card)' }
const td = { padding: '11px 14px', color: 'var(--text-h)', whiteSpace: 'nowrap', verticalAlign: 'middle' }

const solidBtn = {
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 10, cursor: 'pointer',
  fontSize: 13, fontWeight: 700, color: '#fff', border: 'none',
  background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)',
}
const ghostBtn = {
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, cursor: 'pointer',
  fontSize: 13, fontWeight: 600, color: 'var(--text-muted)', background: 'var(--bg-card)', border: '1px solid var(--border)',
}
const pagerBtn = (disabled) => ({
  display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 12px', borderRadius: 8, fontSize: 12.5, fontWeight: 700,
  cursor: disabled ? 'not-allowed' : 'pointer', opacity: disabled ? 0.45 : 1,
  background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)',
})
