import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  UserCheck, Search, LayoutGrid, List, Plus, X, Clock, CalendarClock,
  AlertTriangle, Timer, ChevronLeft, ChevronRight, Building2,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import { ONB_STAGES, ONB_STATUS, onbStatusCfg, onbStatusLabel, fmtDate } from '@/modules/hr/employeeOnboardingConstants'

const unwrap = r => r?.data ?? r
const inputStyle = { width: '100%', padding: '9px 12px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontSize: 13, outline: 'none' }
const label = { fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em' }

const StatusPill = ({ status }) => {
  const c = onbStatusCfg(status)
  return <span style={{ fontSize: 10, fontWeight: 700, padding: '2px 9px', borderRadius: 8, background: c.bg, color: c.color, whiteSpace: 'nowrap' }}>{c.label}</span>
}

const ProgressBar = ({ value }) => (
  <div>
    <div style={{ height: 6, borderRadius: 6, background: 'var(--bg-input)', overflow: 'hidden' }}>
      <div style={{ width: `${value || 0}%`, height: '100%', background: 'linear-gradient(90deg,#a78bfa,#7C3AED)', borderRadius: 6, transition: 'width .3s' }} />
    </div>
    <p style={{ fontSize: 10, color: 'var(--text-muted)', marginTop: 3 }}>{value || 0}% complete</p>
  </div>
)

export default function EmployeeOnboarding() {
  const navigate = useNavigate()
  const [stats, setStats] = useState(null)
  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 })
  const [loading, setLoading] = useState(true)
  const [view, setView] = useState(() => localStorage.getItem('hr_onb_view') || 'card')
  const [search, setSearch] = useState('')
  const [statusF, setStatusF] = useState('All')
  const [stageF, setStageF] = useState('All')
  const [sort, setSort] = useState('created_at')
  const [page, setPage] = useState(1)
  const [toast, setToast] = useState(null)
  const [starter, setStarter] = useState(null) // { list, q, busy }

  const showToast = (msg, type = 'ok') => { setToast({ msg, type }); setTimeout(() => setToast(null), 2600) }
  const changeView = (v) => { setView(v); localStorage.setItem('hr_onb_view', v) }

  const fetchStats = useCallback(async () => {
    try { setStats(unwrap(await hrApi.employeeOnboarding.dashboard())) }
    catch (e) { showToast(e.response?.data?.message || 'Failed to load dashboard', 'error') }
  }, [])

  const fetchList = useCallback(async () => {
    setLoading(true)
    try {
      const [sortKey, dir] = sort.split(':')
      const res = unwrap(await hrApi.employeeOnboarding.list({
        search, status: statusF, stage: stageF, sort: sortKey, dir: dir || 'desc', page, per_page: 12,
      }))
      setRows(res.data || [])
      setMeta({ current_page: res.current_page || 1, last_page: res.last_page || 1, total: res.total || 0 })
    } catch (e) { showToast(e.response?.data?.message || 'Failed to load', 'error') }
    finally { setLoading(false) }
  }, [search, statusF, stageF, sort, page])

  useEffect(() => { fetchStats() }, [fetchStats])
  useEffect(() => { const t = setTimeout(fetchList, 250); return () => clearTimeout(t) }, [fetchList])
  useEffect(() => { setPage(1) }, [search, statusF, stageF, sort])

  // Onboarding runs off the candidate database, so the picker defaults to
  // employees who came from a candidate. Directly-created employees are still
  // reachable behind the toggle — hiding them outright would strand anyone
  // hired before the candidate pipeline existed.
  const loadEligible = async (source) => {
    const res = unwrap(await hrApi.employeeOnboarding.eligibleEmployees({ source })) || {}
    // Tolerate the old bare-array shape so a stale cached bundle keeps working.
    return Array.isArray(res)
      ? { list: res, counts: { from_candidate: res.length, direct_entry: 0 } }
      : { list: res.employees || [], counts: res.counts || { from_candidate: 0, direct_entry: 0 } }
  }

  const openStarter = async () => {
    setStarter({ list: [], q: '', busy: false, source: 'candidate', counts: null })
    try {
      const { list, counts } = await loadEligible('candidate')
      setStarter(s => ({ ...s, list, counts }))
    } catch (e) { showToast(e.response?.data?.message || 'Failed to load employees', 'error') }
  }
  const startOnboarding = async (empId) => {
    setStarter(s => ({ ...s, busy: true }))
    try {
      const res = unwrap(await hrApi.employeeOnboarding.create({ employee_id: empId }))
      showToast('Onboarding started')
      navigate(`/app/hr/employee-onboarding/${res.onboarding.id}`)
    } catch (e) { showToast(e.response?.data?.message || 'Failed to start', 'error'); setStarter(s => ({ ...s, busy: false })) }
  }

  const c = stats?.cards || {}
  const KPIS = [
    ['Total Employees', c.total_employees, '#60a5fa'],
    ['Pending', c.pending, '#f59e0b'],
    ['In Progress', c.in_progress, '#3b82f6'],
    ['Waiting for Documents', c.waiting_documents, '#a78bfa'],
    ['Waiting for HR', c.waiting_hr, '#f472b6'],
    ['Completed', c.completed, '#10b981'],
  ]

  return (
    <div>
      {/* Header */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12, marginBottom: 18 }}>
        <div>
          <h1 style={{ display: 'flex', alignItems: 'center', gap: 9, color: 'var(--text-h)', fontSize: 22, fontWeight: 800, margin: 0 }}>
            <UserCheck size={22} style={{ color: '#a78bfa' }} /> Employee Onboarding
          </h1>
          <p style={{ fontSize: 12.5, color: 'var(--text-muted)', marginTop: 4 }}>Track every new joiner from Employee Created to Active.</p>
        </div>
        <button onClick={openStarter} style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '10px 16px', borderRadius: 10, border: 'none', cursor: 'pointer', fontSize: 13, fontWeight: 700, color: '#fff', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
          <Plus size={15} /> Start Onboarding
        </button>
      </div>

      {/* KPI cards */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(155px,1fr))', gap: 12, marginBottom: 16 }}>
        {KPIS.map(([lbl, val, col]) => (
          <div key={lbl} className="kpi-3d" style={{ padding: 16 }}>
            <div style={{ fontSize: 26, fontWeight: 900, color: col }}>{val ?? 0}</div>
            <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 600, marginTop: 2 }}>{lbl}</div>
          </div>
        ))}
      </div>

      {/* Dashboard widgets */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(240px,1fr))', gap: 12, marginBottom: 18 }}>
        <JoiningWidget title="Recent Joining" icon={Clock} color="#10b981" items={stats?.recent_joining} onOpen={id => navigate(`/app/hr/employee-onboarding/${id}`)} />
        <JoiningWidget title="Upcoming Joining" icon={CalendarClock} color="#3b82f6" items={stats?.upcoming_joining} onOpen={id => navigate(`/app/hr/employee-onboarding/${id}`)} />
        <JoiningWidget title="Overdue Joining" icon={AlertTriangle} color="#ef4444" items={stats?.overdue_joining} onOpen={id => navigate(`/app/hr/employee-onboarding/${id}`)} />
        <div className="card-3d" style={{ padding: 18, display: 'flex', flexDirection: 'column', justifyContent: 'center', alignItems: 'center', textAlign: 'center' }}>
          <Timer size={20} style={{ color: '#a78bfa' }} />
          <div style={{ fontSize: 30, fontWeight: 900, color: 'var(--text-h)', marginTop: 8 }}>{stats?.avg_completion_days ?? '—'}</div>
          <div style={{ fontSize: 11.5, color: 'var(--text-muted)', fontWeight: 600 }}>Avg Completion Time (days)</div>
        </div>
      </div>

      {/* List toolbar */}
      <div className="card-3d" style={{ padding: '12px 16px', marginBottom: 16, display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
        <div style={{ position: 'relative', flex: 1, minWidth: 200 }}>
          <Search size={14} style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search by name, code, department…" style={{ ...inputStyle, paddingLeft: 32 }} />
        </div>
        <select value={statusF} onChange={e => setStatusF(e.target.value)} style={{ ...inputStyle, width: 'auto', cursor: 'pointer' }}>
          <option value="All">All Status</option>
          {Object.keys(ONB_STATUS).map(s => <option key={s} value={s}>{onbStatusLabel(s)}</option>)}
        </select>
        <select value={stageF} onChange={e => setStageF(e.target.value)} style={{ ...inputStyle, width: 'auto', cursor: 'pointer' }}>
          <option value="All">All Stages</option>
          {ONB_STAGES.map(s => <option key={s.key} value={s.key}>{s.label}</option>)}
        </select>
        <select value={sort} onChange={e => setSort(e.target.value)} style={{ ...inputStyle, width: 'auto', cursor: 'pointer' }}>
          <option value="created_at:desc">Newest</option>
          <option value="created_at:asc">Oldest</option>
          <option value="joining_date:asc">Joining ↑</option>
          <option value="joining_date:desc">Joining ↓</option>
          <option value="progress_percent:desc">Progress ↓</option>
        </select>
        <div style={{ display: 'flex', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 10, padding: 2 }}>
          {[['card', LayoutGrid], ['list', List]].map(([v, Icon]) => (
            <button key={v} onClick={() => changeView(v)} style={{ display: 'flex', alignItems: 'center', padding: '7px 11px', borderRadius: 8, border: 'none', cursor: 'pointer', background: view === v ? 'var(--bg-card)' : 'transparent', color: view === v ? '#a78bfa' : 'var(--text-muted)' }}><Icon size={14} /></button>
          ))}
        </div>
      </div>

      {/* List */}
      {loading ? <HrLoading label="Loading onboardings…" />
        : rows.length === 0 ? <HrEmpty icon={UserCheck} title="No onboardings yet" hint="Click “Start Onboarding” to begin a new joiner's onboarding." />
        : view === 'list' ? <ListView rows={rows} onOpen={id => navigate(`/app/hr/employee-onboarding/${id}`)} />
        : (
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(300px,1fr))', gap: 12 }}>
            {rows.map(r => (
              <div key={r.id} onClick={() => navigate(`/app/hr/employee-onboarding/${r.id}`)} className="card-3d" style={{ padding: 16, cursor: 'pointer' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
                  <div style={{ minWidth: 0 }}>
                    <p style={{ fontWeight: 800, color: 'var(--text-h)', fontSize: 14, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{r.employee || '—'}</p>
                    <p style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 2 }}>{r.employee_code} · {r.designation || '—'}</p>
                  </div>
                  <StatusPill status={r.status} />
                </div>
                <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '10px 0 8px', display: 'flex', alignItems: 'center', gap: 5 }}><Building2 size={12} /> {r.department || '—'} · Joining {fmtDate(r.joining_date)}</p>
                <p style={{ fontSize: 11, color: '#a78bfa', fontWeight: 600, marginBottom: 8 }}>{r.stage_label}</p>
                <ProgressBar value={r.progress} />
              </div>
            ))}
          </div>
        )}

      {/* Pagination */}
      {!loading && rows.length > 0 && (
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 16, fontSize: 12.5, color: 'var(--text-muted)' }}>
          <span>{meta.total} total · page {meta.current_page} of {meta.last_page}</span>
          <div style={{ display: 'flex', gap: 6 }}>
            <button disabled={page <= 1} onClick={() => setPage(p => p - 1)} style={pagerBtn(page <= 1)}><ChevronLeft size={14} /></button>
            <button disabled={page >= meta.last_page} onClick={() => setPage(p => p + 1)} style={pagerBtn(page >= meta.last_page)}><ChevronRight size={14} /></button>
          </div>
        </div>
      )}

      {/* Start Onboarding modal */}
      {starter && (
        <div onClick={() => setStarter(null)} style={overlay}>
          <div onClick={e => e.stopPropagation()} className="card-3d" style={{ width: 'min(520px,94vw)', maxHeight: '80vh', padding: 20, display: 'flex', flexDirection: 'column' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
              <h3 style={{ color: 'var(--text-h)', fontSize: 16, fontWeight: 800, margin: 0 }}>Start Onboarding</h3>
              <button onClick={() => setStarter(null)} style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)' }}><X size={18} /></button>
            </div>
            <input autoFocus placeholder="Search employees…" value={starter.q} onChange={e => setStarter(s => ({ ...s, q: e.target.value }))} style={{ ...inputStyle, marginBottom: 10 }} />

            {/* Source toggle — candidate-linked is the intended path; direct
                entries stay reachable so nobody is stranded. */}
            <div style={{ display: 'flex', gap: 6, marginBottom: 12 }}>
              {[
                { key: 'candidate', label: 'From Candidate', n: starter.counts?.from_candidate },
                { key: 'all',       label: 'All Employees',  n: (starter.counts?.from_candidate ?? 0) + (starter.counts?.direct_entry ?? 0) },
              ].map(t => {
                const on = (starter.source || 'candidate') === t.key
                return (
                  <button key={t.key} disabled={starter.busy}
                    onClick={async () => {
                      setStarter(s => ({ ...s, source: t.key, busy: true }))
                      try {
                        const { list, counts } = await loadEligible(t.key)
                        setStarter(s => ({ ...s, list, counts, busy: false }))
                      } catch { setStarter(s => ({ ...s, busy: false })) }
                    }}
                    style={{
                      flex: 1, padding: '7px 10px', borderRadius: 8, fontSize: 12, fontWeight: 700, cursor: 'pointer',
                      background: on ? 'rgba(124,58,237,0.15)' : 'var(--bg-input)',
                      color: on ? '#a78bfa' : 'var(--text-muted)',
                      border: `1px solid ${on ? 'rgba(124,58,237,0.4)' : 'var(--border)'}`,
                    }}>
                    {t.label}{typeof t.n === 'number' ? ` (${t.n})` : ''}
                  </button>
                )
              })}
            </div>

            {(starter.source || 'candidate') === 'candidate' && (starter.counts?.direct_entry ?? 0) > 0 && (
              <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '0 0 10px' }}>
                {starter.counts.direct_entry} employee{starter.counts.direct_entry === 1 ? '' : 's'} created directly (not from a candidate) {starter.counts.direct_entry === 1 ? 'is' : 'are'} hidden — switch to <b>All Employees</b> to include {starter.counts.direct_entry === 1 ? 'it' : 'them'}.
              </p>
            )}

            <div style={{ overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: 6 }}>
              {(starter.list || []).filter(e => !starter.q || `${e.name} ${e.employee_code}`.toLowerCase().includes(starter.q.toLowerCase())).map(e => (
                <button key={e.id} disabled={starter.busy} onClick={() => startOnboarding(e.id)} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, padding: '10px 12px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-input)', cursor: 'pointer', textAlign: 'left' }}>
                  <span>
                    <span style={{ fontWeight: 700, color: 'var(--text-h)', fontSize: 13 }}>{e.name}</span>
                    <span style={{ fontSize: 11, color: 'var(--text-muted)' }}> · {e.employee_code}</span>
                    {!e.candidate_id && (
                      <span style={{ marginLeft: 6, fontSize: 9.5, fontWeight: 700, padding: '2px 6px', borderRadius: 5, color: '#fab219', background: 'color-mix(in srgb, #fab219 14%, transparent)' }}>DIRECT ENTRY</span>
                    )}
                  </span>
                  <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>{e.designation || ''}</span>
                </button>
              ))}
              {(starter.list || []).length === 0 && (
                <p style={{ fontSize: 12.5, color: 'var(--text-muted)', textAlign: 'center', padding: 20 }}>
                  {(starter.source || 'candidate') === 'candidate'
                    ? 'No candidate-linked employees are awaiting onboarding.'
                    : 'All employees already have an onboarding record.'}
                </p>
              )}
            </div>
          </div>
        </div>
      )}

      {toast && <div style={{ position: 'fixed', bottom: 22, right: 22, padding: '11px 16px', borderRadius: 10, background: toast.type === 'error' ? '#ef4444' : '#10b981', color: '#fff', fontSize: 13, fontWeight: 600, zIndex: 60 }}>{toast.msg}</div>}
    </div>
  )
}

function JoiningWidget({ title, icon: Icon, color, items, onOpen }) {
  return (
    <div className="card-3d" style={{ padding: 16 }}>
      <p style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, fontWeight: 700, color: 'var(--text-h)', marginBottom: 10 }}><Icon size={14} style={{ color }} /> {title}</p>
      {(!items || items.length === 0) ? <p style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>None</p>
        : <div style={{ display: 'flex', flexDirection: 'column', gap: 7 }}>
          {items.slice(0, 5).map(i => (
            <div key={i.id} onClick={() => onOpen(i.id)} style={{ display: 'flex', justifyContent: 'space-between', gap: 8, cursor: 'pointer', fontSize: 12 }}>
              <span style={{ color: 'var(--text-h)', fontWeight: 600, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{i.employee}</span>
              <span style={{ color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{fmtDate(i.joining_date)}</span>
            </div>
          ))}
        </div>}
    </div>
  )
}

function ListView({ rows, onOpen }) {
  return (
    <div className="card-3d" style={{ padding: 0, overflowX: 'auto' }}>
      <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5, minWidth: 720 }}>
        <thead><tr style={{ background: 'var(--bg-input)' }}>{['Employee', 'Code', 'Department', 'Stage', 'Progress', 'Joining', 'Status'].map(h => <th key={h} style={{ textAlign: 'left', padding: '10px 14px', fontWeight: 700, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{h}</th>)}</tr></thead>
        <tbody>
          {rows.map(r => (
            <tr key={r.id} onClick={() => onOpen(r.id)} style={{ borderTop: '1px solid var(--border)', cursor: 'pointer' }}>
              <td style={{ padding: '10px 14px', color: 'var(--text-h)', fontWeight: 700 }}>{r.employee}</td>
              <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>{r.employee_code}</td>
              <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>{r.department || '—'}</td>
              <td style={{ padding: '10px 14px', color: '#a78bfa', fontWeight: 600, whiteSpace: 'nowrap' }}>{r.stage_label}</td>
              <td style={{ padding: '10px 14px', minWidth: 120 }}><ProgressBar value={r.progress} /></td>
              <td style={{ padding: '10px 14px', color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{fmtDate(r.joining_date)}</td>
              <td style={{ padding: '10px 14px' }}><StatusPill status={r.status} /></td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

const pagerBtn = (disabled) => ({ display: 'flex', alignItems: 'center', padding: '6px 10px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-card)', color: disabled ? 'var(--border)' : 'var(--text-h)', cursor: disabled ? 'not-allowed' : 'pointer' })
const overlay = { position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 50, padding: 16 }
