import { useState, useMemo } from 'react'
import {
  Eye, Pencil, Rocket, Pause, Lock, Trash2, ChevronUp, ChevronDown, ChevronsUpDown,
  ChevronLeft, ChevronRight, X,
} from 'lucide-react'
import { jobStatusColor, jobStatusLabel, PRIORITY_COLORS } from '../constants'

const PAGE_SIZE = 12
const PRIORITY_ORDER = { Critical: 4, High: 3, Medium: 2, Low: 1 }
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: '2-digit' }) : '—'

// column definitions — each: key, label, sortable, get(job) accessor for sorting
const COLS = [
  { key: 'id',         label: 'ID',            sortable: true,  get: j => j.id },
  { key: 'title',      label: 'Job Title',     sortable: true,  get: j => j.title?.toLowerCase() },
  { key: 'department', label: 'Department',    sortable: true,  get: j => j.department?.toLowerCase() },
  { key: 'bu',         label: 'Business Unit', sortable: false, get: j => j.manpower_request?.business_unit },
  { key: 'project',    label: 'Project',       sortable: false, get: j => j.manpower_request?.project },
  { key: 'location',   label: 'Location',      sortable: true,  get: j => j.location?.toLowerCase() },
  { key: 'type',       label: 'Type',          sortable: true,  get: j => j.job_type },
  { key: 'priority',   label: 'Priority',      sortable: true,  get: j => PRIORITY_ORDER[j.manpower_request?.priority] || 0 },
  { key: 'status',     label: 'Status',        sortable: true,  get: j => j.status },
  { key: 'req',        label: 'Req',           sortable: true,  get: j => j.progress?.required ?? j.number_of_openings ?? 0 },
  { key: 'filled',     label: 'Filled',        sortable: true,  get: j => j.progress?.filled ?? 0 },
  { key: 'apps',       label: 'Apps',          sortable: true,  get: j => j.progress?.applications ?? 0 },
  { key: 'published',  label: 'Published',     sortable: true,  get: j => j.published_at ? new Date(j.published_at).getTime() : 0 },
  { key: 'closing',    label: 'Closing',       sortable: true,  get: j => j.closing_date ? new Date(j.closing_date).getTime() : 0 },
  { key: 'requester',  label: 'Requested By',  sortable: false, get: j => j.manpower_request?.requester?.name },
  { key: 'recruiter',  label: 'Recruiter',     sortable: false, get: j => j.manpower_request?.assigned_manager?.name },
]

const th = { padding: '9px 10px', fontSize: 10.5, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap', borderBottom: '1px solid var(--border)', textAlign: 'left', background: 'var(--bg-card)', position: 'sticky', top: 0 }
const td = { padding: '9px 10px', fontSize: 12, color: 'var(--text-h)', whiteSpace: 'nowrap', borderBottom: '1px solid var(--border)' }

export default function JobListView({ jobs, manageHr, busy, navigate, onEdit, onAction, onClose, onBulk }) {
  const [sortKey, setSortKey] = useState('id')
  const [sortDir, setSortDir] = useState('desc')
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState(new Set())

  const col = COLS.find(c => c.key === sortKey)
  const sorted = useMemo(() => {
    const arr = [...jobs]
    if (col) arr.sort((a, b) => {
      const av = col.get(a), bv = col.get(b)
      if (av == null && bv == null) return 0
      if (av == null) return 1
      if (bv == null) return -1
      const r = typeof av === 'number' ? av - bv : String(av).localeCompare(String(bv))
      return sortDir === 'asc' ? r : -r
    })
    return arr
  }, [jobs, sortKey, sortDir, col])

  const totalPages = Math.max(1, Math.ceil(sorted.length / PAGE_SIZE))
  const curPage = Math.min(page, totalPages)
  const rows = sorted.slice((curPage - 1) * PAGE_SIZE, curPage * PAGE_SIZE)

  const toggleSort = (key) => {
    if (sortKey === key) setSortDir(d => d === 'asc' ? 'desc' : 'asc')
    else { setSortKey(key); setSortDir('asc') }
  }
  const allSelected = jobs.length > 0 && jobs.every(j => selected.has(j.id))
  const toggleAll = () => setSelected(allSelected ? new Set() : new Set(jobs.map(j => j.id)))
  const toggleOne = (id) => setSelected(s => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n })

  const runBulk = async (action) => {
    if (action === 'delete' && !window.confirm(`Delete ${selected.size} job(s)? This cannot be undone.`)) return
    await onBulk(action, [...selected])
    setSelected(new Set())
  }

  const SortIcon = ({ k }) => sortKey !== k ? <ChevronsUpDown size={11} style={{ opacity: 0.4 }} /> : sortDir === 'asc' ? <ChevronUp size={11} /> : <ChevronDown size={11} />

  return (
    <div className="card-3d" style={{ padding: 0, overflow: 'hidden' }}>
      {/* Bulk action bar */}
      {manageHr && selected.size > 0 && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '10px 14px', background: 'rgba(124,58,237,0.08)', borderBottom: '1px solid var(--border)', flexWrap: 'wrap' }}>
          <span style={{ fontSize: 12.5, fontWeight: 700, color: '#a78bfa' }}>{selected.size} selected</span>
          <BulkBtn onClick={() => runBulk('publish')} icon={Rocket} color="#10b981" disabled={busy}>Publish</BulkBtn>
          <BulkBtn onClick={() => runBulk('pause')} icon={Pause} color="#a855f7" disabled={busy}>Pause</BulkBtn>
          <BulkBtn onClick={() => runBulk('close')} icon={Lock} color="#f59e0b" disabled={busy}>Close</BulkBtn>
          <BulkBtn onClick={() => runBulk('delete')} icon={Trash2} color="#ef4444" disabled={busy}>Delete</BulkBtn>
          <button onClick={() => setSelected(new Set())} style={{ marginLeft: 'auto', display: 'inline-flex', alignItems: 'center', gap: 4, background: 'none', border: 'none', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12 }}><X size={13} /> Clear</button>
        </div>
      )}

      <div style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 1200 }}>
          <thead>
            <tr>
              {manageHr && <th style={{ ...th, width: 34 }}><input type="checkbox" checked={allSelected} onChange={toggleAll} style={{ cursor: 'pointer' }} /></th>}
              {COLS.map(c => (
                <th key={c.key} style={{ ...th, cursor: c.sortable ? 'pointer' : 'default' }} onClick={() => c.sortable && toggleSort(c.key)}>
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3 }}>{c.label}{c.sortable && <SortIcon k={c.key} />}</span>
                </th>
              ))}
              <th style={{ ...th, textAlign: 'right' }}>Actions</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr><td colSpan={COLS.length + 2} style={{ ...td, textAlign: 'center', padding: 40, color: 'var(--text-muted)' }}>No job postings found</td></tr>
            ) : rows.map(job => {
              const mr = job.manpower_request || {}
              const p = job.progress || {}
              const sc = jobStatusColor(job.status)
              const live = ['Published', 'Hiring', 'Partially_Filled'].includes(job.status)
              const sel = selected.has(job.id)
              return (
                <tr key={job.id} style={{ background: sel ? 'rgba(124,58,237,0.06)' : 'transparent' }}>
                  {manageHr && <td style={td}><input type="checkbox" checked={sel} onChange={() => toggleOne(job.id)} style={{ cursor: 'pointer' }} /></td>}
                  <td style={{ ...td, color: '#a78bfa', fontWeight: 700 }}>#{job.id}</td>
                  <td style={{ ...td, fontWeight: 700, cursor: 'pointer' }} onClick={() => navigate(`/app/hr/jobs/${job.id}`)}>{job.title}</td>
                  <td style={td}>{job.department}</td>
                  <td style={{ ...td, color: 'var(--text-muted)' }}>{mr.business_unit || '—'}</td>
                  <td style={{ ...td, color: 'var(--text-muted)' }}>{mr.project || '—'}</td>
                  <td style={{ ...td, color: 'var(--text-muted)' }}>{job.location}</td>
                  <td style={{ ...td, color: 'var(--text-muted)' }}>{job.job_type}</td>
                  <td style={td}>{mr.priority ? <span style={{ padding: '1px 7px', borderRadius: 7, fontSize: 10.5, fontWeight: 700, background: `${PRIORITY_COLORS[mr.priority]}20`, color: PRIORITY_COLORS[mr.priority] }}>{mr.priority}</span> : '—'}</td>
                  <td style={td}><span style={{ padding: '2px 8px', borderRadius: 12, fontSize: 10.5, fontWeight: 700, background: sc.bg, color: sc.color, border: `1px solid ${sc.color}40` }}>{jobStatusLabel(job.status)}</span></td>
                  <td style={{ ...td, textAlign: 'center', fontWeight: 700 }}>{p.required ?? job.number_of_openings ?? 0}</td>
                  <td style={{ ...td, textAlign: 'center', fontWeight: 700, color: '#10b981' }}>{p.filled ?? 0}</td>
                  <td style={{ ...td, textAlign: 'center', fontWeight: 700 }}>{p.applications ?? 0}</td>
                  <td style={{ ...td, color: 'var(--text-muted)' }}>{fmtDate(job.published_at)}</td>
                  <td style={{ ...td, color: 'var(--text-muted)' }}>{fmtDate(job.closing_date)}</td>
                  <td style={{ ...td, color: 'var(--text-muted)' }}>{mr.requester?.name || '—'}</td>
                  <td style={{ ...td, color: 'var(--text-muted)' }}>{mr.assigned_manager?.name || '—'}</td>
                  <td style={{ ...td, textAlign: 'right' }}>
                    <div style={{ display: 'inline-flex', gap: 4 }}>
                      <IcoBtn icon={Eye} title="View" onClick={() => navigate(`/app/hr/jobs/${job.id}`)} />
                      {manageHr && <IcoBtn icon={Pencil} title="Edit" onClick={() => onEdit(job)} />}
                      {manageHr && live && <IcoBtn icon={Pause} title="Pause" color="#a855f7" onClick={() => onAction(job, 'pause')} disabled={busy} />}
                      {manageHr && !live && !['Closed', 'Cancelled'].includes(job.status) && <IcoBtn icon={Rocket} title="Publish" color="#10b981" onClick={() => onAction(job, 'publish')} disabled={busy} />}
                      {manageHr && !['Closed', 'Cancelled', 'Completed'].includes(job.status) && <IcoBtn icon={Lock} title="Close" color="#f59e0b" onClick={() => onClose(job, 'close')} disabled={busy} />}
                    </div>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      {/* Pagination */}
      {sorted.length > 0 && (
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '10px 14px', borderTop: '1px solid var(--border)', flexWrap: 'wrap', gap: 8 }}>
          <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>
            {(curPage - 1) * PAGE_SIZE + 1}–{Math.min(curPage * PAGE_SIZE, sorted.length)} of {sorted.length}
          </span>
          <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
            <PageBtn disabled={curPage <= 1} onClick={() => setPage(curPage - 1)}><ChevronLeft size={15} /></PageBtn>
            <span style={{ fontSize: 12, color: 'var(--text-h)', fontWeight: 600, minWidth: 60, textAlign: 'center' }}>Page {curPage}/{totalPages}</span>
            <PageBtn disabled={curPage >= totalPages} onClick={() => setPage(curPage + 1)}><ChevronRight size={15} /></PageBtn>
          </div>
        </div>
      )}
    </div>
  )
}

const BulkBtn = ({ onClick, icon: Icon, color, disabled, children }) => (
  <button onClick={onClick} disabled={disabled} style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '5px 11px', borderRadius: 8, background: `${color}18`, border: `1px solid ${color}45`, color, cursor: disabled ? 'not-allowed' : 'pointer', fontSize: 12, fontWeight: 600, opacity: disabled ? 0.6 : 1 }}><Icon size={13} /> {children}</button>
)
const IcoBtn = ({ icon: Icon, title, color, onClick, disabled }) => (
  <button onClick={onClick} disabled={disabled} title={title} style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', width: 28, height: 26, borderRadius: 7, background: color ? `${color}14` : 'var(--bg-input)', border: `1px solid ${color ? color + '3a' : 'var(--border)'}`, color: color || 'var(--text-muted)', cursor: disabled ? 'not-allowed' : 'pointer', opacity: disabled ? 0.6 : 1 }}><Icon size={13} /></button>
)
const PageBtn = ({ disabled, onClick, children }) => (
  <button onClick={onClick} disabled={disabled} style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', width: 30, height: 28, borderRadius: 8, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: disabled ? 'not-allowed' : 'pointer', opacity: disabled ? 0.4 : 1 }}>{children}</button>
)
