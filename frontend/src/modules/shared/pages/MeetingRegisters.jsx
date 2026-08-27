import { useState, useEffect, useMemo, useCallback } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import {
  ShieldCheck, AlertTriangle, ListChecks, Search, RefreshCw, Loader2,
  Download, ExternalLink, CalendarDays, Building2, FolderKanban, X,
} from 'lucide-react'
import { kickoffApi } from '@/services/kickoffApi'
import { fmtDate } from '../kickoffConstants'
import { KIT3D_STYLE } from '@/components/ui/kit3d'

/**
 * The three cross-meeting registers (Meeting.docx §8 / §9 / §10).
 *
 * Decisions, issues and actions were only visible inside the one meeting that
 * produced them, which is why the Decision Register the doc asks for ("this
 * creates a searchable Project Decision Register") could not be found anywhere.
 * This is that register — one screen, three tabs, filtered across every meeting.
 *
 * One component for all three because they are the same table with different
 * columns; splitting them would triplicate the filter bar, the empty state and
 * the CSV export for no gain.
 */

const TABS = {
  decisions: {
    label: 'Decision Register',
    icon: ShieldCheck,
    colour: '#10b981',
    blurb: 'Every decision taken in every meeting — searchable, and traceable back to the meeting that took it.',
  },
  issues: {
    label: 'Issue Register',
    icon: AlertTriangle,
    colour: '#f59e0b',
    blurb: 'Every issue raised in a meeting, with what it was escalated into.',
  },
  actions: {
    label: 'Open Action Items',
    icon: ListChecks,
    colour: '#7C3AED',
    blurb: 'The action backlog across all meetings. Overdue items first.',
  },
}

export default function MeetingRegisters() {
  const navigate = useNavigate()
  const { register } = useParams()
  const tab = TABS[register] ? register : 'decisions'

  const [rows, setRows] = useState([])
  const [options, setOptions] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  // Filters. `status` means different things per tab, so it resets on switch.
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [severity, setSeverity] = useState('')
  const [vendor, setVendor] = useState('')
  const [project, setProject] = useState('')

  useEffect(() => { kickoffApi.registers.options().then(setOptions).catch(() => {}) }, [])
  useEffect(() => { setStatus(''); setSeverity(''); setVendor(''); setProject(''); setSearch('') }, [tab])

  const load = useCallback(() => {
    setLoading(true); setError(null)
    const params = {}
    if (search.trim()) params.search = search.trim()
    if (status) params.status = status
    if (severity) params.severity = severity
    if (vendor) params.vendor = vendor
    if (project) params.project_id = project

    kickoffApi.registers[tab](params)
      .then(d => setRows(Array.isArray(d) ? d : []))
      .catch(e => setError(e?.response?.data?.message || 'Could not load the register.'))
      .finally(() => setLoading(false))
  }, [tab, search, status, severity, vendor, project])

  // Debounced so typing in the search box does not fire a request per keystroke.
  useEffect(() => {
    const t = setTimeout(load, search ? 350 : 0)
    return () => clearTimeout(t)
  }, [load, search])

  // Filter choices come from the rows in hand, so they never offer a value that
  // would return nothing.
  const { vendors, projects } = useMemo(() => {
    const v = new Set(), p = new Map()
    rows.forEach(r => {
      if (r.vendor) v.add(r.vendor)
      if (r.project_id && r.project) p.set(String(r.project_id), r.project)
    })
    return { vendors: [...v].sort(), projects: [...p.entries()] }
  }, [rows])

  const cfg = TABS[tab]
  const Icon = cfg.icon

  const exportCsv = () => {
    const cols = COLUMNS[tab]
    const head = cols.map(c => c.label).concat(['Meeting', 'Vendor', 'Project']).join(',')
    const esc = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`
    const body = rows.map(r =>
      cols.map(c => esc(c.csv ? c.csv(r) : r[c.key])).concat([esc(r.meeting_no), esc(r.vendor), esc(r.project)]).join(',')
    ).join('\n')
    const blob = new Blob([`${head}\n${body}`], { type: 'text/csv;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url; a.download = `meeting-${tab}.csv`
    document.body.appendChild(a); a.click(); a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 30000)
  }

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>
      <style>{`@keyframes koSpin{to{transform:rotate(360deg)}}.ko-spin{animation:koSpin .9s linear infinite}`}</style>

      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 18, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: cfg.colour, margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>MEETINGS</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 24, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>{cfg.label}</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>{cfg.blurb}</p>
        </div>
        <div style={{ display: 'flex', gap: 9 }}>
          <button onClick={load} style={ghostBtn}><RefreshCw size={14} /> Refresh</button>
          <button onClick={exportCsv} disabled={!rows.length} style={{ ...ghostBtn, opacity: rows.length ? 1 : 0.5 }}>
            <Download size={14} /> Export CSV
          </button>
        </div>
      </div>

      {/* Tabs */}
      <div style={{ display: 'flex', gap: 8, marginBottom: 14, flexWrap: 'wrap' }}>
        {Object.entries(TABS).map(([key, t]) => {
          const on = key === tab
          const TIcon = t.icon
          return (
            <button key={key} onClick={() => navigate(`/app/tpv/meetings/registers/${key}`)}
              style={{
                display: 'inline-flex', alignItems: 'center', gap: 7, padding: '8px 14px', borderRadius: 10,
                fontSize: 12.5, fontWeight: 700, cursor: 'pointer',
                background: on ? `${t.colour}1f` : 'var(--bg-card)',
                border: `1px solid ${on ? t.colour : 'var(--border)'}`,
                color: on ? t.colour : 'var(--text-muted)',
              }}>
              <TIcon size={14} /> {t.label}
            </button>
          )
        })}
      </div>

      {/* Filters */}
      <div style={{ display: 'flex', gap: 8, marginBottom: 14, flexWrap: 'wrap', alignItems: 'center' }}>
        <div style={{ position: 'relative', flex: '1 1 240px', minWidth: 200 }}>
          <Search size={14} style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder={`Search ${cfg.label.toLowerCase()}…`}
            style={{ ...filterInput, paddingLeft: 30, width: '100%' }} />
          {search && (
            <button onClick={() => setSearch('')} title="Clear"
              style={{ position: 'absolute', right: 6, top: '50%', transform: 'translateY(-50%)', background: 'none', border: 'none', cursor: 'pointer', color: 'var(--text-muted)' }}>
              <X size={13} />
            </button>
          )}
        </div>

        {tab === 'decisions' && (
          <select value={status} onChange={e => setStatus(e.target.value)} style={filterInput}>
            <option value="">All statuses</option>
            {(options?.decision_statuses || []).map(s => <option key={s} value={s}>{s}</option>)}
          </select>
        )}
        {tab === 'issues' && (
          <>
            <select value={status} onChange={e => setStatus(e.target.value)} style={filterInput}>
              <option value="">All statuses</option>
              <option value="open">Open only</option>
              {(options?.issue_statuses || []).map(s => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
            </select>
            <select value={severity} onChange={e => setSeverity(e.target.value)} style={filterInput}>
              <option value="">All severities</option>
              {(options?.issue_severities || []).map(s => <option key={s} value={s}>{s}</option>)}
            </select>
          </>
        )}
        {tab === 'actions' && (
          <select value={status} onChange={e => setStatus(e.target.value)} style={filterInput}>
            <option value="">Open backlog</option>
            <option value="overdue">Overdue only</option>
            <option value="all">All actions</option>
            {(options?.action_statuses || []).map(s => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
          </select>
        )}

        <select value={vendor} onChange={e => setVendor(e.target.value)} style={filterInput}>
          <option value="">All vendors</option>
          {vendors.map(v => <option key={v} value={v}>{v}</option>)}
        </select>
        <select value={project} onChange={e => setProject(e.target.value)} style={filterInput}>
          <option value="">All projects</option>
          {projects.map(([id, name]) => <option key={id} value={id}>{name}</option>)}
        </select>

        <span style={{ fontSize: 12, color: 'var(--text-muted)', marginLeft: 'auto' }}>
          {loading ? 'Loading…' : `${rows.length} row${rows.length === 1 ? '' : 's'}`}
        </span>
      </div>

      {error && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '10px 13px', borderRadius: 10, marginBottom: 12, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.35)' }}>
          <AlertTriangle size={14} style={{ color: '#ef4444' }} />
          <span style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{error}</span>
        </div>
      )}

      <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
        {loading ? (
          <div style={{ padding: 44, textAlign: 'center' }}><Loader2 size={22} className="ko-spin" style={{ color: cfg.colour }} /></div>
        ) : rows.length === 0 ? (
          <div style={{ padding: 44, textAlign: 'center' }}>
            <Icon size={26} style={{ color: cfg.colour, opacity: 0.6 }} />
            <p style={{ color: 'var(--text-h)', fontWeight: 700, fontSize: 14, margin: '10px 0 4px' }}>
              Nothing in this register yet
            </p>
            <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: 0, lineHeight: 1.6 }}>
              {tab === 'decisions'
                ? 'Decisions are captured on a meeting — open a meeting, edit it, and add them under Decisions.'
                : tab === 'issues'
                  ? 'Issues are captured on a meeting — open a meeting, edit it, and add them under Issues Raised.'
                  : 'Action items are captured in a meeting’s minutes. Add them on the meeting form.'}
            </p>
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 900 }}>
              <thead>
                <tr>
                  {COLUMNS[tab].map(c => <th key={c.key} style={th}>{c.label}</th>)}
                  <th style={th}>Meeting</th>
                  <th style={th}>Vendor / Project</th>
                  <th style={{ ...th, width: 40 }} />
                </tr>
              </thead>
              <tbody>
                {rows.map(r => (
                  <tr key={`${tab}-${r.id}`} style={{ borderTop: '1px solid var(--border)' }}>
                    {COLUMNS[tab].map(c => (
                      <td key={c.key} style={td}>{c.render ? c.render(r) : (r[c.key] ?? '—')}</td>
                    ))}
                    <td style={td}>
                      <div style={{ fontWeight: 700, color: 'var(--text-h)', fontSize: 12 }}>{r.meeting_no || '—'}</div>
                      <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>
                        {r.meeting_type}{r.meeting_date ? ` · ${fmtDate(r.meeting_date)}` : ''}
                      </div>
                    </td>
                    <td style={td}>
                      {r.vendor && (
                        <div style={{ display: 'flex', alignItems: 'center', gap: 5, fontSize: 12, color: 'var(--text-h)' }}>
                          <Building2 size={11} style={{ color: 'var(--text-muted)' }} /> {r.vendor}
                        </div>
                      )}
                      {r.project && (
                        <div style={{ display: 'flex', alignItems: 'center', gap: 5, fontSize: 11, color: 'var(--text-muted)' }}>
                          <FolderKanban size={11} /> {r.project}
                        </div>
                      )}
                      {!r.vendor && !r.project && '—'}
                    </td>
                    <td style={{ ...td, textAlign: 'right' }}>
                      {r.meeting_id && (
                        <button onClick={() => navigate(`/app/tpv/kickoff/${r.meeting_id}`)} title="Open the meeting"
                          style={{ background: 'none', border: 'none', cursor: 'pointer', color: cfg.colour, padding: 4 }}>
                          <ExternalLink size={14} />
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  )
}

/* ── Per-tab columns ─────────────────────────────────────────────────────── */

const Pill = ({ children, colour }) => (
  <span style={{ display: 'inline-block', padding: '2px 8px', borderRadius: 999, fontSize: 10.5, fontWeight: 800, background: `${colour}1f`, color: colour, whiteSpace: 'nowrap' }}>
    {children}
  </span>
)

const dateCell = (v) => v ? fmtDate(v) : '—'

const COLUMNS = {
  decisions: [
    { key: 'ref', label: 'Ref', render: r => <span style={{ fontWeight: 800, fontSize: 11.5, color: 'var(--text-muted)' }}>{r.ref || '—'}</span> },
    {
      key: 'decision', label: 'Decision', render: r => (
        <div>
          <div style={{ fontSize: 12.5, color: 'var(--text-h)', lineHeight: 1.5 }}>{r.decision}</div>
          {r.agenda_item && <div style={{ fontSize: 10.5, color: '#a78bfa', marginTop: 2 }}>▸ {r.agenda_item}</div>}
        </div>
      ),
    },
    { key: 'decided_by', label: 'Decided By' },
    { key: 'impact', label: 'Impact' },
    { key: 'effective_date', label: 'Effective', render: r => dateCell(r.effective_date), csv: r => r.effective_date },
    {
      key: 'status', label: 'Status',
      render: r => <Pill colour={r.status === 'Active' ? '#10b981' : '#94a3b8'}>{r.status}</Pill>,
    },
  ],
  issues: [
    { key: 'ref', label: 'Ref', render: r => <span style={{ fontWeight: 800, fontSize: 11.5, color: 'var(--text-muted)' }}>{r.ref || '—'}</span> },
    {
      key: 'title', label: 'Issue', render: r => (
        <div>
          <div style={{ fontSize: 12.5, color: 'var(--text-h)', fontWeight: 600, lineHeight: 1.5 }}>{r.title}</div>
          {r.description && <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 2 }}>{r.description}</div>}
          {r.converted_to && (
            <div style={{ fontSize: 10.5, color: '#0ea5e9', marginTop: 3, fontWeight: 700 }}>
              → {r.converted_to} {r.converted_ref}
            </div>
          )}
        </div>
      ),
    },
    { key: 'category', label: 'Category' },
    {
      key: 'severity', label: 'Severity',
      render: r => r.severity
        ? <Pill colour={{ Critical: '#ef4444', High: '#f59e0b', Medium: '#0ea5e9', Low: '#94a3b8' }[r.severity] || '#94a3b8'}>{r.severity}</Pill>
        : '—',
    },
    { key: 'owner', label: 'Owner' },
    {
      key: 'due_date', label: 'Due',
      render: r => r.due_date
        ? <span style={{ color: r.is_overdue ? '#ef4444' : 'var(--text-body)', fontWeight: r.is_overdue ? 700 : 400 }}>{fmtDate(r.due_date)}</span>
        : '—',
      csv: r => r.due_date,
    },
    {
      key: 'status_label', label: 'Status',
      render: r => <Pill colour={r.is_open ? (r.is_overdue ? '#ef4444' : '#f59e0b') : '#10b981'}>{r.status_label}</Pill>,
    },
  ],
  actions: [
    { key: 'ref', label: 'Ref', render: r => <span style={{ fontWeight: 800, fontSize: 11.5, color: 'var(--text-muted)' }}>{r.ref || '—'}</span> },
    {
      key: 'description', label: 'Action', render: r => (
        <div>
          <div style={{ fontSize: 12.5, color: 'var(--text-h)', lineHeight: 1.5 }}>{r.description}</div>
          {r.agenda_item && <div style={{ fontSize: 10.5, color: '#a78bfa', marginTop: 2 }}>▸ {r.agenda_item}</div>}
          {r.task_id && <div style={{ fontSize: 10.5, color: '#0ea5e9', marginTop: 2, fontWeight: 700 }}>→ Task #{r.task_id}</div>}
        </div>
      ),
    },
    {
      key: 'responsible', label: 'Responsible', render: r => (
        <div>
          <div style={{ fontSize: 12 }}>{r.responsible || '—'}</div>
          {r.responsible_org && <div style={{ fontSize: 10.5, color: 'var(--text-muted)' }}>{r.responsible_org}</div>}
        </div>
      ),
    },
    {
      key: 'priority', label: 'Priority',
      render: r => r.priority
        ? <Pill colour={{ Urgent: '#ef4444', High: '#f59e0b', Medium: '#0ea5e9', Low: '#94a3b8' }[r.priority] || '#94a3b8'}>{r.priority}</Pill>
        : '—',
    },
    {
      key: 'target_date', label: 'Target',
      render: r => r.target_date
        ? (
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, color: r.is_overdue ? '#ef4444' : 'var(--text-body)', fontWeight: r.is_overdue ? 700 : 400 }}>
            <CalendarDays size={11} /> {fmtDate(r.target_date)}
          </span>
        )
        : '—',
      csv: r => r.target_date,
    },
    {
      key: 'status_label', label: 'Status',
      render: r => <Pill colour={r.is_open ? (r.is_overdue ? '#ef4444' : '#f59e0b') : '#10b981'}>{r.status_label}</Pill>,
    },
  ],
}

/* ── Styles ──────────────────────────────────────────────────────────────── */

const th = {
  padding: '10px 13px', textAlign: 'left', fontSize: 10.5, fontWeight: 800,
  textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--text-muted)',
  background: 'var(--bg-input)', whiteSpace: 'nowrap',
}
const td = { padding: '11px 13px', fontSize: 12.5, color: 'var(--text-body)', verticalAlign: 'top' }
const filterInput = {
  padding: '8px 11px', borderRadius: 9, fontSize: 12.5, background: 'var(--bg-input)',
  border: '1px solid var(--border)', color: 'var(--text-h)', outline: 'none',
}
const ghostBtn = {
  display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 14px', borderRadius: 10,
  fontSize: 12.5, fontWeight: 700, cursor: 'pointer', background: 'var(--bg-card)',
  border: '1px solid var(--border)', color: 'var(--text-muted)',
}
