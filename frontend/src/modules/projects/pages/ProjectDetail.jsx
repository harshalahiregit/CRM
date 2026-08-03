import { useState, useRef, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useParams, useNavigate, useSearchParams } from 'react-router-dom'
import {
  ArrowLeft, RefreshCw, Plus, Users, Flag, Paperclip, Trash2, ListTodo, LifeBuoy,
  Pencil, Copy, Pin, PinOff, MoreHorizontal, Download, Upload, ExternalLink, Check, FileText,
  Play, Search, Clock, GitBranch, X,
} from 'lucide-react'
import { projectApi, PROJECT_STATUS, PROJECT_ACCENT } from '@/services/projectApi'
import { guardedClose } from '@/lib/confirmClose'
import { exportCsv, stampedName } from '@/lib/exportCsv'
import { useAuth } from '@/context/AuthContext'
import { useStatuses, statusOptions } from '@/hooks/useStatuses'
import { taskApi, TASK_PRIORITY, TASK_ACCENT } from '@/services/taskApi'
import Select from '@/components/ui/Select'
import SearchPicker, { ConfirmModal } from '@/components/ui/SearchPicker'
import { TagChips } from '@/components/ui/TagInput'
import ProjectFormDrawer from '../components/ProjectFormDrawer'
import { TimesheetsTab, NotesTab, ActivityTab, ExpensesTab, VendorTab } from '../components/ProjectTabs'
import { MeetingsTab } from '../components/ProjectMeetings'
import { DiscussionsTab } from '../components/ProjectDiscussions'
import ProjectInvoiceModal from '../components/ProjectInvoiceModal'
import ProjectGantt from '../components/ProjectGantt'
import TaskFormDrawer from '../../tasks/components/TaskFormDrawer'
import RaiseTicketModal from '../../helpdesk/components/RaiseTicketModal'

/**
 * Project detail — Overview / Tasks / Milestones / Files / Tickets.
 *
 * The tab bar is the spec's internal nav, trimmed to the tabs this CRM actually
 * has data for. The spec's other ~25 tabs (Appointly, Domain Manager, Recruitment,
 * Purchase orders…) are Perfex features with no counterpart here — they'd render
 * as empty shells, so they're deliberately not built.
 */

const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

// Export the whole project as one CSV — a summary block plus a milestones table
// (with each milestone's own progress). Opens cleanly in Excel (UTF-8 BOM).
function exportProjectData(p) {
  const esc = (v) => {
    const s = String(v ?? '')
    const safe = /^[=+\-@]/.test(s) ? `'${s}` : s
    return /[",\r\n]/.test(safe) ? `"${safe.replace(/"/g, '""')}"` : safe
  }
  const row = (...cells) => cells.map(esc).join(',')
  const party = p.customer?.name || p.vendor?.name || '—'
  const lines = [
    'Project',
    row('Name', p.name),
    row('Status', p.status),
    row('Customer / Vendor', party),
    row('Start date', p.start_date || '—'),
    row('Deadline', p.deadline || '—'),
    row('Progress %', p.progress ?? 0),
    '',
    'Milestones',
    row('Name', 'Due date', 'Done', 'Total', 'Percent'),
    ...(p.milestones || []).map(m => row(m.name, m.due_date || '—', m.progress?.done ?? 0, m.progress?.total ?? 0, `${m.progress?.percent ?? 0}%`)),
  ]
  const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `project-${p.id}-${String(p.name || 'data').replace(/\s+/g, '-').toLowerCase()}.csv`
  document.body.appendChild(a); a.click(); a.remove()
  URL.revokeObjectURL(url)
}

// "PDF" = open a formatted print view; the browser's print dialog offers Save as PDF.
function printProjectData(p) {
  const w = window.open('', '_blank', 'width=820,height=900')
  if (!w) return
  const party = p.customer?.name || p.vendor?.name || '—'
  const ms = (p.milestones || []).map(m => `<tr><td>${m.name || ''}</td><td>${m.due_date ? String(m.due_date).split('T')[0] : '—'}</td><td>${m.progress?.done ?? 0}/${m.progress?.total ?? 0}</td><td>${m.progress?.percent ?? 0}%</td></tr>`).join('')
  w.document.write(`<html><head><title>${p.name || 'Project'}</title><style>
    body{font-family:system-ui,Arial,sans-serif;padding:36px;color:#111}
    h1{font-size:22px;margin:0 0 4px} .muted{color:#666;font-size:12px}
    table{width:100%;border-collapse:collapse;margin-top:14px;font-size:13px}
    th,td{text-align:left;padding:7px 9px;border-bottom:1px solid #e2e8f0}
    th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#666}
    .kv{margin-top:12px;font-size:13px} .kv div{margin:3px 0}
  </style></head><body>
    <h1>${p.name || ''}</h1>
    <div class="muted">Project report · ${new Date().toLocaleDateString('en-IN')}</div>
    <div class="kv">
      <div><b>Status:</b> ${p.status || '—'}</div>
      <div><b>Customer / Vendor:</b> ${party}</div>
      <div><b>Start:</b> ${p.start_date || '—'} &nbsp; <b>Deadline:</b> ${p.deadline || '—'}</div>
      <div><b>Progress:</b> ${p.progress ?? 0}%</div>
    </div>
    <h3 style="margin-top:20px;font-size:15px">Milestones</h3>
    <table><thead><tr><th>Name</th><th>Due</th><th>Done/Total</th><th>Percent</th></tr></thead><tbody>${ms || '<tr><td colspan=4 class="muted">No milestones</td></tr>'}</tbody></table>
  </body></html>`)
  w.document.close(); w.focus(); w.print()
}
const money = v => v != null && v !== '' ? '₹' + Number(v).toLocaleString('en-IN') : '—'

const TABS = [
  { key: 'overview',   label: 'Overview' },
  { key: 'tasks',      label: 'Tasks' },
  { key: 'milestones', label: 'Milestones' },
  { key: 'gantt',      label: 'Gantt' },
  { key: 'meeting',    label: 'Meeting' },
  { key: 'timesheets', label: 'Timesheets' },
  { key: 'expenses',   label: 'Expenses' },
  { key: 'vendors',    label: 'Vendors / TPV' },
  { key: 'files',      label: 'Files' },
  { key: 'discussions', label: 'Discussions' },
  { key: 'notes',      label: 'Notes' },
  { key: 'activity',   label: 'Activity' },
  { key: 'tickets',    label: 'Tickets' },
]

export default function ProjectDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { user } = useAuth()
  const { map: statusMap, list: statusList } = useStatuses('project')

  const [searchParams, setSearchParams] = useSearchParams()
  const [editing, setEditing] = useState(false)
  const [invoicing, setInvoicing] = useState(false)
  const [creatingTask, setCreatingTask] = useState(false)
  const [raising, setRaising] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [menuOpen, setMenuOpen] = useState(false)
  const [err, setErr] = useState('')
  const menuRef = useRef(null)

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['project', id] })
    qc.invalidateQueries({ queryKey: ['projects'] })
  }
  const onErr = (e) => setErr(e?.message || 'That action failed.')

  const { data: project, isLoading, isError, error } = useQuery({ queryKey: ['project', id], queryFn: () => projectApi.get(id) })
  const { data: prog, refetch: refetchProg, isFetching: progBusy } = useQuery({
    queryKey: ['project-progress', id], queryFn: () => projectApi.progress(id),
  })

  const setStatus = useMutation({ mutationFn: (s) => projectApi.setStatus(id, s), onSuccess: () => { setErr(''); invalidate() }, onError: onErr })
  const pin = useMutation({ mutationFn: () => projectApi.pin(id), onSuccess: invalidate, onError: onErr })
  const copy = useMutation({
    mutationFn: () => projectApi.copy(id, { copy_members: true, copy_milestones: true }),
    onSuccess: (p) => { qc.invalidateQueries({ queryKey: ['projects'] }); navigate(`/app/projects/${p.id}`) },
    onError: onErr,
  })
  const remove = useMutation({
    mutationFn: () => projectApi.remove(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['projects'] }); navigate('/app/projects') },
    onError: onErr,
  })

  useEffect(() => {
    const h = (e) => { if (menuRef.current && !menuRef.current.contains(e.target)) setMenuOpen(false) }
    document.addEventListener('mousedown', h)
    return () => document.removeEventListener('mousedown', h)
  }, [])

  if (isLoading) return <div className="rounded-2xl animate-pulse" style={{ height: 200, background: 'var(--bg-card)' }} />
  if (isError) {
    return (
      <div className="p-6 rounded-2xl" style={{ border: '1px solid color-mix(in srgb, var(--color-danger-500) 30%, transparent)', background: 'var(--bg-card)' }}>
        <p className="text-sm" style={{ color: 'var(--color-danger-500)' }}>{error?.message}</p>
        <button onClick={() => navigate('/app/projects')} className="text-xs mt-3 underline" style={{ color: 'var(--text-muted)' }}>Back to projects</button>
      </div>
    )
  }

  const s = statusMap[project.status] || { label: project.status, color: 'var(--text-muted)' }
  // Admin or the creator may change the project; members are view-only (backend enforces).
  const canManage = user?.role === 'admin' || project.created_by === user?.id

  // The tab bar is driven by the project's Visible-Tabs setting: only tabs the
  // project switched on are shown (Overview is always present). Projects created
  // before the setting existed have no bag → show every built tab (legacy).
  // Newly-implemented tabs default to visible on existing projects (whose stored
  // visible_tabs bag predates them) — shown unless the project explicitly turns
  // them off. Older tabs keep the strict "must be enabled in the bag" rule.
  const NEW_TABS = new Set(['expenses', 'vendors'])
  const shownTabs = TABS.filter(t =>
    t.key === 'overview' || !project.visible_tabs || project.visible_tabs[t.key]
    || (NEW_TABS.has(t.key) && project.visible_tabs[t.key] === undefined))
  // The active tab lives in the URL (?group=) so a tab is linkable/bookmarkable
  // and survives a refresh. Fall back to Overview for an unknown/hidden group.
  const requested = searchParams.get('group') || 'overview'
  const tab = shownTabs.some(t => t.key === requested) ? requested : 'overview'
  const setTab = (key) => setSearchParams(p => {
    const n = new URLSearchParams(p); n.set('group', key); return n
  }, { replace: true })

  return (
    <div className="max-w-5xl">
      <button onClick={() => navigate('/app/projects')} className="flex items-center gap-1.5 text-xs mb-4" style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={13} /> Projects
      </button>

      {/* Header */}
      <div className="flex items-start justify-between gap-4 mb-4 flex-wrap">
        <div className="min-w-0">
          <div className="flex items-center gap-2">
            {project.is_pinned && <Pin size={13} style={{ color: PROJECT_ACCENT }} fill="currentColor" />}
            <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>{project.name}</h1>
          </div>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            {(project.link_type === 'vendor' || project.link_type === 'tpv')
              ? `${project.link_type === 'tpv' ? 'TPV' : 'Vendor'}: ${project.vendor?.name || '—'}`
              : (project.customer?.name || 'No customer')} · created by {project.creator?.name || '—'}
          </p>
          {project.tags?.length > 0 && <div className="mt-2"><TagChips tags={project.tags} max={6} size="md" /></div>}
        </div>

        <div className="flex items-center gap-2">
          <button onClick={() => setCreatingTask(true)}
            className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
            style={{ background: `color-mix(in srgb, ${PROJECT_ACCENT} 14%, transparent)`, color: PROJECT_ACCENT }}>
            <ListTodo size={13} /> New Task
          </button>
          <button onClick={() => setRaising(true)}
            className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
            style={{ border: '1px solid var(--border)', color: 'var(--text-body)' }}>
            <LifeBuoy size={13} /> Raise Ticket
          </button>
          <button onClick={() => setInvoicing(true)}
            className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
            style={{ border: '1px solid var(--border)', color: 'var(--text-body)' }}>
            <FileText size={13} /> Invoice Project
          </button>
          <button onClick={() => exportProjectData(project)}
            className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
            style={{ border: '1px solid var(--border)', color: 'var(--text-body)' }}>
            <Download size={13} /> Export
          </button>
          <button onClick={() => printProjectData(project)}
            className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
            style={{ border: '1px solid var(--border)', color: 'var(--text-body)' }}>
            <FileText size={13} /> PDF
          </button>
          <div style={{ minWidth: 150 }}>
            {canManage ? (
              <Select value={project.status} onChange={v => setStatus.mutate(v)} ariaLabel="Project status"
                options={statusOptions(statusList, user?.role)}
                buttonStyle={{ borderColor: s.color, color: s.color, fontWeight: 700 }} />
            ) : (
              <span className="inline-flex items-center text-xs font-bold px-3 py-2 rounded-xl"
                style={{ border: `1px solid ${s.color}`, color: s.color }}>{s.label}</span>
            )}
          </div>

          <div className="relative" ref={menuRef}>
            <button onClick={() => setMenuOpen(o => !o)} aria-label="More actions"
              className="w-9 h-9 rounded-xl flex items-center justify-center"
              style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
              <MoreHorizontal size={15} />
            </button>
            {menuOpen && (
              <div className="absolute right-0 mt-2 rounded-xl overflow-hidden z-40" style={{ width: 190, background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)' }}>
                {canManage && <MenuItem icon={Pencil} onClick={() => { setMenuOpen(false); setEditing(true) }}>Edit Project</MenuItem>}
                <MenuItem icon={project.is_pinned ? PinOff : Pin} onClick={() => { setMenuOpen(false); pin.mutate() }}>
                  {project.is_pinned ? 'Unpin Project' : 'Pin Project'}
                </MenuItem>
                <MenuItem icon={Copy} onClick={() => { setMenuOpen(false); copy.mutate() }}>Copy Project</MenuItem>
                {canManage && <MenuItem icon={Trash2} danger onClick={() => { setMenuOpen(false); setConfirmDelete(true) }}>Delete Project</MenuItem>}
              </div>
            )}
          </div>
        </div>
      </div>

      {err && (
        <p className="text-xs px-3 py-2 rounded-lg mb-3"
          style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>{err}</p>
      )}

      {/* Tabs */}
      <div className="flex items-center gap-1 mb-4 overflow-x-auto" style={{ borderBottom: '1px solid var(--border)' }}>
        {shownTabs.map(t => (
          <button key={t.key} onClick={() => setTab(t.key)}
            className="px-3 py-2 text-xs font-bold whitespace-nowrap transition-colors"
            style={{
              color: tab === t.key ? PROJECT_ACCENT : 'var(--text-muted)',
              borderBottom: `2px solid ${tab === t.key ? PROJECT_ACCENT : 'transparent'}`,
              marginBottom: -1,
            }}>
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'overview' && <Overview project={project} prog={prog} onRecalc={() => refetchProg()} busy={progBusy} />}
      {tab === 'tasks' && <TasksTab projectId={id} navigate={navigate} onNewTask={() => setCreatingTask(true)} />}
      {tab === 'milestones' && <MilestonesTab project={project} onChange={invalidate} onErr={onErr} canManage={canManage} />}
      {tab === 'gantt' && <ProjectGantt projectId={id} milestones={project.milestones || []} />}
      {tab === 'meeting' && <MeetingsTab projectId={id} canManage={canManage} />}
      {tab === 'timesheets' && <TimesheetsTab projectId={id} />}
      {tab === 'expenses' && <ExpensesTab projectId={id} />}
      {tab === 'vendors' && <VendorTab project={project} />}
      {tab === 'files' && <FilesTab projectId={id} />}
      {tab === 'discussions' && <DiscussionsTab projectId={id} />}
      {tab === 'notes' && <NotesTab projectId={id} />}
      {tab === 'activity' && <ActivityTab projectId={id} />}
      {tab === 'tickets' && <TicketsTab projectId={id} navigate={navigate} />}

      <ProjectFormDrawer open={editing} onClose={() => setEditing(false)} project={project} onSaved={invalidate} />

      <ProjectInvoiceModal open={invoicing} onClose={() => setInvoicing(false)} projectId={id} canManage={canManage} />

      {/* Create a task already linked to this project (Related To → Project preset). */}
      <TaskFormDrawer open={creatingTask} onClose={() => setCreatingTask(false)}
        defaults={{ rel_type: 'project', rel_id: Number(id) }}
        onSaved={() => { invalidate(); refetchProg(); qc.invalidateQueries({ queryKey: ['tasks'] }) }} />

      {/* Raise a helpdesk ticket linked to this project (normal ticket flow). */}
      <RaiseTicketModal open={raising} onClose={() => setRaising(false)} projectId={id} source="project"
        defaultSubject={`[${project.name}] `}
        onCreated={() => qc.invalidateQueries({ queryKey: ['project-tickets', id] })} />

      <ConfirmModal open={confirmDelete} onClose={() => setConfirmDelete(false)} onConfirm={() => remove.mutate()}
        title="Delete this project?"
        message={`“${project.name}” will be removed. Its tasks stay, but stop being linked to it.`}
        confirmLabel="Delete" danger />
    </div>
  )
}

/* ── Overview ─────────────────────────────────────────────────── */

function Overview({ project, prog, onRecalc, busy }) {
  const pct = prog?.progress ?? project.progress ?? 0
  return (
    <div className="space-y-4">
      <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <div className="flex items-center gap-2 mb-2">
          <span className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>Progress</span>
          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
            {prog?.source === 'tasks' ? `${prog.completed_tasks}/${prog.total_tasks} tasks done` : 'set manually'}
          </span>
          <button onClick={onRecalc} title="Recalculate from tasks" className="ml-auto flex items-center gap-1 text-[10px] font-bold"
            style={{ color: PROJECT_ACCENT }}>
            <RefreshCw size={10} className={busy ? 'animate-spin' : ''} /> Recalculate
          </button>
        </div>
        <div className="h-2 rounded-full overflow-hidden" style={{ background: 'var(--bg-input)' }}>
          <div className="h-full rounded-full transition-all" style={{ width: `${pct}%`, background: PROJECT_ACCENT }} />
        </div>
        <p className="text-2xl font-black mt-2" style={{ color: 'var(--text-h)' }}>{pct}%</p>
      </section>

      <div className="grid gap-4 md:grid-cols-2">
        <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <h2 className="font-bold text-xs mb-3" style={{ color: 'var(--text-h)' }}>Project info</h2>
          <dl className="space-y-2">
            <Row label="Project #" value={`#${project.id}`} />
            <Row label={project.link_type === 'tpv' ? 'Third-party vendor' : project.link_type === 'vendor' ? 'Vendor' : 'Customer'}
              value={(project.link_type === 'vendor' || project.link_type === 'tpv') ? (project.vendor?.name || '—') : (project.customer?.name || '—')} />
            <Row label="Billing type" value={(project.billing_type || '').replace('_', ' ')} />
            <Row label={project.billing_type === 'fixed' ? 'Total rate' : 'Rate / hour'}
              value={project.billing_type === 'fixed' ? money(project.project_cost) : money(project.rate_per_hour)} />
            <Row label="Estimated hours" value={project.estimated_hours ?? '—'} />
            <Row label="Start date" value={fmtDate(project.start_date)} />
            <Row label="Deadline" value={fmtDate(project.deadline)} />
            <Row label="Finished" value={project.date_finished ? fmtDate(project.date_finished) : '—'} />
          </dl>
        </section>

        <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <h2 className="font-bold text-xs mb-3 flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
            <Users size={14} style={{ color: PROJECT_ACCENT }} /> Members
          </h2>
          <div className="flex flex-wrap gap-1.5">
            {(project.members || []).map(m => (
              <span key={m.id} className="text-[11px] font-semibold px-2 py-1 rounded-lg"
                style={{ background: `color-mix(in srgb, ${PROJECT_ACCENT} 12%, transparent)`, color: PROJECT_ACCENT }}>
                {m.user?.name}
              </span>
            ))}
            {(project.members || []).length === 0 && <span className="text-xs" style={{ color: 'var(--text-muted)' }}>No members yet — add them via Edit Project.</span>}
          </div>
        </section>
      </div>

      {project.description && (
        <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <h2 className="font-bold text-xs mb-2" style={{ color: 'var(--text-h)' }}>Description</h2>
          {/* Plain text: the form writes a plain textarea, so rendering as HTML
              would be an XSS sink for no benefit. */}
          <p className="text-xs whitespace-pre-wrap break-words leading-relaxed" style={{ color: 'var(--text-body)' }}>{project.description}</p>
        </section>
      )}
    </div>
  )
}

const Row = ({ label, value }) => (
  <div className="flex items-center justify-between gap-3">
    <dt className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{label}</dt>
    <dd className="text-xs font-semibold capitalize truncate" style={{ color: 'var(--text-h)' }}>{value}</dd>
  </div>
)

/* ── Tasks tab ────────────────────────────────────────────────── */

const TASK_PAGE_SIZES = [{ value: 10, label: '10' }, { value: 25, label: '25' }, { value: 50, label: '50' }, { value: 100, label: '100' }, { value: 0, label: 'All' }]
const taskInitials = (n) => n ? n.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2) : '–'

/* ── Tasks tab: "Tasks Summary" status cards + a functional task table ──
 * One card per tenant-configured task status (total + assigned-to-me), then a
 * table with inline status change and a per-row Start Timer. Clicking a task
 * opens the full task workspace (checklist, attachments, comments, reminders…).
 */
function TasksTab({ projectId, navigate, onNewTask }) {
  const qc = useQueryClient()
  const { user } = useAuth()
  const { list: taskStatusList, map: taskStatusMap } = useStatuses('task')
  const [q, setQ] = useState('')
  const [pageSize, setPageSize] = useState(25)

  // Same rule as the main board: a project's task list is its jobs, not every
  // step inside them. Subtasks are one toggle away, never in the way.
  const [showSubtasks, setShowSubtasks] = useState(false)
  const taskFilters = { rel_type: 'project', rel_id: projectId, ...(showSubtasks ? { include_subtasks: 1 } : {}) }
  const taskKey = ['tasks', taskFilters]
  const { data: tasks = [], isLoading } = useQuery({ queryKey: taskKey, queryFn: () => taskApi.list(taskFilters) })

  const refetch = () => qc.invalidateQueries({ queryKey: ['tasks'] })
  const setStatus = useMutation({ mutationFn: ({ id, status }) => taskApi.setStatus(id, status), onSuccess: refetch })
  const startTimer = useMutation({ mutationFn: (id) => taskApi.startTimer(id), onSuccess: refetch })

  const uid = user?.id
  const mineOf = (t) => (t.assignees || []).some(a => a.user_id === uid || a.user?.id === uid)

  // Summary: one card per configured status (falls back to the built-in five).
  const summary = (taskStatusList || []).map(s => {
    const inS = tasks.filter(t => t.status === s.key)
    return { key: s.key, name: s.name ?? s.label, color: s.color, total: inS.length, mine: inS.filter(mineOf).length }
  })

  const rows = tasks
    .filter(t => { const term = q.trim().toLowerCase(); return !term || `${t.name} #${t.id}`.toLowerCase().includes(term) })
    .slice(0, pageSize === 0 ? undefined : pageSize)

  const priorityColor = (p) => TASK_PRIORITY[p] || 'var(--text-muted)'
  const statusMeta = (k) => taskStatusMap[k] || { label: k, color: 'var(--text-muted)' }
  const statusOpts = statusOptions(taskStatusList || [], user?.role)

  if (isLoading) return <div className="rounded-2xl animate-pulse" style={{ height: 160, background: 'var(--bg-card)' }} />

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <h2 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Tasks Summary</h2>
        <div className="flex items-center gap-2">
          <button onClick={() => exportCsv(stampedName(`project-${projectId}-tasks`), tasks, [
            { key: 'id', label: 'ID' },
            { key: 'name', label: 'Name' },
            { key: 'status', label: 'Status', value: t => statusMeta(t.status).label },
            { key: 'priority', label: 'Priority' },
            { key: 'start_date', label: 'Start', value: t => t.start_date ? String(t.start_date).split('T')[0] : '' },
            { key: 'due_date', label: 'Due', value: t => t.due_date ? String(t.due_date).split('T')[0] : '' },
            { key: 'assignees', label: 'Assigned to', value: t => (t.assignees || []).map(a => a.user?.name).filter(Boolean).join('; ') },
          ])} disabled={!tasks.length}
            className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-40"
            style={{ border: '1px solid var(--border)', color: 'var(--text-body)' }}>
            <Download size={13} /> Export
          </button>
          <button onClick={onNewTask} className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
            style={{ background: PROJECT_ACCENT, color: '#fff' }}>
            <Plus size={13} /> New Task
          </button>
        </div>
      </div>

      {/* Status summary cards — count + "assigned to me" per configured status */}
      <div className="grid gap-2.5" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(150px, 1fr))' }}>
        {summary.map(s => (
          <div key={s.key} className="rounded-xl p-3" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', borderLeft: `3px solid ${s.color}` }}>
            <p className="text-3xl font-black leading-none" style={{ color: 'var(--text-h)' }}>{s.total}</p>
            <p className="text-[11px] font-bold mt-1.5" style={{ color: s.color }}>{s.name}</p>
            <p className="text-[10px] mt-0.5" style={{ color: 'var(--text-muted)' }}>Tasks assigned to me: {s.mine}</p>
          </div>
        ))}
      </div>

      {/* Toolbar */}
      <div className="flex items-center gap-2 flex-wrap">
        <div style={{ width: 90 }}>
          <Select value={pageSize} onChange={v => setPageSize(Number(v))} options={TASK_PAGE_SIZES} size="sm" ariaLabel="Rows per page" />
        </div>
        <div className="relative flex-1" style={{ maxWidth: 260 }}>
          <Search size={13} className="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" style={{ color: 'var(--text-muted)' }} />
          <input value={q} onChange={e => setQ(e.target.value)} placeholder="Search…"
            style={{ width: '100%', padding: '7px 10px 7px 30px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-input)', fontSize: 13, outline: 'none', color: 'var(--text-h)' }} />
        </div>
        <button onClick={() => setShowSubtasks(v => !v)}
          className="flex items-center gap-1.5 text-[11px] font-bold px-2.5 py-1.5 rounded-lg"
          style={{
            background: showSubtasks ? `color-mix(in srgb, ${TASK_ACCENT} 14%, transparent)` : 'var(--bg-input)',
            border: `1px solid ${showSubtasks ? TASK_ACCENT : 'var(--border)'}`,
            color: showSubtasks ? TASK_ACCENT : 'var(--text-muted)',
          }}>
          <GitBranch size={12} /> Subtasks
        </button>
        <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>Showing {rows.length} of {tasks.length}</span>
      </div>

      {/* Task table */}
      <div className="rounded-2xl overflow-x-auto" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <table className="w-full" style={{ borderCollapse: 'collapse', minWidth: 720 }}>
          <thead>
            <tr style={{ borderBottom: '1px solid var(--border)' }}>
              {['#', 'Name', 'Status', 'Start Date', 'Due Date', 'Assigned to', 'Tags', 'Priority'].map(h => (
                <th key={h} style={{ padding: '10px 12px', textAlign: 'left', fontSize: 10.5, fontWeight: 800, letterSpacing: '0.05em', textTransform: 'uppercase', color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 && (
              <tr><td colSpan={8}><p className="text-xs text-center py-10" style={{ color: 'var(--text-muted)' }}>{q ? 'No matching tasks.' : 'No tasks linked to this project yet — click New Task.'}</p></td></tr>
            )}
            {rows.map(t => {
              const sm = statusMeta(t.status)
              const assignees = t.assignees || []
              const tags = (t.tags || []).map(tg => (typeof tg === 'string' ? tg : tg.name))
              return (
                <tr key={t.id} style={{ borderBottom: '1px solid var(--border)' }}
                  onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'}
                  onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                  <td style={{ padding: '9px 12px', fontSize: 12, fontFamily: 'monospace', color: 'var(--text-muted)' }}>{t.id}</td>
                  <td style={{ padding: '9px 12px' }}>
                    {t.parent && (
                      <span className="flex items-center gap-0.5 text-[10px] truncate" style={{ color: 'var(--text-muted)', maxWidth: 220 }}>
                        <GitBranch size={9} /> {t.parent.name}
                      </span>
                    )}
                    <button onClick={() => navigate(`/app/tasks/${t.id}`)} className="text-left font-semibold text-xs hover:underline" style={{ color: 'var(--text-h)' }}>{t.name}</button>
                    <div className="mt-1">
                      <button onClick={() => startTimer.mutate(t.id)} disabled={startTimer.isPending}
                        className="inline-flex items-center gap-1 text-[10px] font-bold disabled:opacity-40" style={{ color: PROJECT_ACCENT }}>
                        <Play size={9} /> Start Timer
                      </button>
                    </div>
                  </td>
                  <td style={{ padding: '9px 12px' }} onClick={e => e.stopPropagation()}>
                    <div style={{ minWidth: 130 }}>
                      <Select value={t.status} onChange={v => setStatus.mutate({ id: t.id, status: v })} ariaLabel={`Status for task ${t.id}`}
                        options={statusOpts.length ? statusOpts : [{ value: t.status, label: sm.label, dot: sm.color }]}
                        size="sm" buttonStyle={{ color: sm.color, background: `${sm.color}18`, border: `1px solid ${sm.color}30`, borderRadius: 999, fontWeight: 700, fontSize: 11 }} />
                    </div>
                  </td>
                  <td style={{ padding: '9px 12px', fontSize: 12, color: 'var(--text-body)', whiteSpace: 'nowrap' }}>{fmtDate(t.start_date)}</td>
                  <td style={{ padding: '9px 12px', fontSize: 12, color: 'var(--text-body)', whiteSpace: 'nowrap' }}>{t.due_date ? fmtDate(t.due_date) : '—'}</td>
                  <td style={{ padding: '9px 12px' }}>
                    {assignees.length === 0 ? <span className="text-xs" style={{ color: 'var(--text-muted)' }}>—</span> : (
                      <div className="flex items-center gap-1">
                        {assignees.slice(0, 3).map(a => (
                          <span key={a.user_id || a.user?.id} title={a.user?.name} className="w-6 h-6 rounded-full flex items-center justify-center text-[9px] font-bold"
                            style={{ background: `linear-gradient(135deg,var(--color-primary-400),var(--color-primary-600))`, color: '#fff', border: '1px solid var(--border)' }}>
                            {taskInitials(a.user?.name)}
                          </span>
                        ))}
                        {assignees.length > 3 && <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>+{assignees.length - 3}</span>}
                      </div>
                    )}
                  </td>
                  <td style={{ padding: '9px 12px' }}>
                    <div className="flex flex-wrap gap-1">
                      {tags.slice(0, 3).map(tg => (
                        <span key={tg} className="text-[10px] font-semibold px-1.5 py-0.5 rounded-md" style={{ background: `color-mix(in srgb, ${PROJECT_ACCENT} 12%, transparent)`, color: PROJECT_ACCENT }}>{tg}</span>
                      ))}
                      {tags.length === 0 && <span className="text-xs" style={{ color: 'var(--text-muted)' }}>—</span>}
                    </div>
                  </td>
                  <td style={{ padding: '9px 12px' }}>
                    <span className="inline-flex items-center gap-1 text-[11px] font-bold capitalize">
                      <span className="w-2 h-2 rounded-full" style={{ background: priorityColor(t.priority) }} />
                      <span style={{ color: priorityColor(t.priority) }}>{t.priority}</span>
                    </span>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </div>
  )
}

/* ── Milestones tab ───────────────────────────────────────────── */

function MilestonesTab({ project, onChange, onErr, canManage = true }) {
  const [formFor, setFormFor] = useState(null)   // null = closed | 'new' | milestone object
  const save = useMutation({
    mutationFn: ({ mid, data }) => (mid ? projectApi.updateMilestone(mid, data) : projectApi.createMilestone(project.id, data)),
    onSuccess: () => { setFormFor(null); onChange() }, onError: onErr,
  })
  const del = useMutation({ mutationFn: (mid) => projectApi.deleteMilestone(mid), onSuccess: onChange, onError: onErr })

  const milestones = project.milestones || []

  return (
    <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <div className="flex items-center justify-between mb-3">
        <h2 className="font-bold text-xs flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
          <Flag size={14} style={{ color: PROJECT_ACCENT }} /> Milestones
        </h2>
        {canManage && (
          <button onClick={() => setFormFor('new')} className="flex items-center gap-1 text-[11px] font-bold px-2.5 py-1.5 rounded-lg"
            style={{ background: PROJECT_ACCENT, color: '#fff' }}>
            <Plus size={12} /> New milestone
          </button>
        )}
      </div>
      <ul className="space-y-1.5">
        {milestones.map(m => (
          <li key={m.id} className="px-2 py-2 rounded-lg group" style={{ background: 'var(--bg-input)' }}>
            <div className="flex items-center gap-2">
              <span className="w-2 h-2 rounded-full shrink-0" style={{ background: m.color || PROJECT_ACCENT }} />
              <span className="flex-1 text-xs truncate" style={{ color: 'var(--text-h)' }}>{m.name}</span>
              <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{fmtDate(m.due_date)}</span>
              {canManage && (
                <>
                  <button onClick={() => setFormFor(m)} className="hover:opacity-60" aria-label={`Edit ${m.name}`}>
                    <Pencil size={11} style={{ color: 'var(--text-muted)' }} />
                  </button>
                  <button onClick={() => del.mutate(m.id)} className="hover:opacity-60" aria-label={`Delete ${m.name}`}>
                    <Trash2 size={11} style={{ color: 'var(--color-danger-500)' }} />
                  </button>
                </>
              )}
            </div>
            {/* Per-milestone progress — its own tasks done / total, distinct from the
                project-wide task bar. */}
            <div className="flex items-center gap-2 mt-1.5 pl-4">
              <div className="flex-1 h-1 rounded-full overflow-hidden" style={{ background: 'var(--bg-card)' }}>
                <div className="h-full rounded-full transition-all"
                  style={{ width: `${m.progress?.percent || 0}%`, background: (m.progress?.percent || 0) === 100 ? 'var(--color-success-500)' : PROJECT_ACCENT }} />
              </div>
              <span className="text-[10px] tabular-nums shrink-0" style={{ color: 'var(--text-muted)' }}>
                {m.progress?.total ? `${m.progress.done}/${m.progress.total} · ${m.progress.percent}%` : 'no tasks'}
              </span>
            </div>
          </li>
        ))}
        {milestones.length === 0 && <li className="text-xs" style={{ color: 'var(--text-muted)' }}>No milestones yet.</li>}
      </ul>

      {formFor && (
        <MilestoneFormModal
          milestone={formFor === 'new' ? null : formFor}
          busy={save.isPending}
          onClose={() => setFormFor(null)}
          onSubmit={(data) => save.mutate({ mid: formFor === 'new' ? null : formFor.id, data })}
        />
      )}
    </section>
  )
}

/**
 * Full milestone form — a field-for-field copy of the old system's "New Milestone"
 * popup: Name, Start/Due dates, Description, the two customer-visibility switches,
 * and Order. Doubles as the edit form when a milestone is passed in.
 */
function MilestoneFormModal({ milestone, onClose, onSubmit, busy }) {
  const todayStr = new Date().toISOString().split('T')[0]
  const [f, setF] = useState({
    name: milestone?.name || '',
    start_date: milestone?.start_date ? String(milestone.start_date).split('T')[0] : todayStr,
    due_date: milestone?.due_date ? String(milestone.due_date).split('T')[0] : '',
    description: milestone?.description || '',
    show_description_to_customer: !!milestone?.show_description_to_customer,
    hide_from_customer: !!milestone?.hide_from_customer,
    order: milestone?.order ?? 1,
  })
  const snapRef = useRef(null)
  if (snapRef.current === null) snapRef.current = JSON.stringify(f)
  const requestClose = () => guardedClose(onClose, JSON.stringify(f) !== snapRef.current)
  const set = (k, v) => setF(p => ({ ...p, [k]: v }))
  const ok = f.name.trim() && f.start_date && f.due_date
  const submit = (e) => { e.preventDefault(); if (ok && !busy) onSubmit(f) }

  const LBL = { display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--text-body)', marginBottom: 5 }
  const INP = { width: '100%', padding: '9px 12px', borderRadius: 8, fontSize: 13.5, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', outline: 'none' }
  const req = <span style={{ color: 'var(--color-danger-500)' }}>*</span>

  return (
    <div className="fixed inset-0 z-[70] flex items-start justify-center p-4 overflow-y-auto" style={{ background: 'rgba(15,23,42,0.55)' }} onClick={requestClose}>
      <form onSubmit={submit} onClick={e => e.stopPropagation()}
        className="w-full rounded-2xl my-6" style={{ maxWidth: 520, background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)' }}>
        <header className="flex items-center justify-between px-5 py-3.5" style={{ borderBottom: '1px solid var(--border)' }}>
          <h3 className="font-black text-sm" style={{ color: 'var(--text-h)' }}>{milestone ? 'Edit Milestone' : 'New Milestone'}</h3>
          <button type="button" onClick={requestClose} className="hover:opacity-70"><X size={17} style={{ color: 'var(--text-muted)' }} /></button>
        </header>

        <div className="px-5 py-4 space-y-3.5">
          <div>
            <label style={LBL}>{req} Name</label>
            <input autoFocus value={f.name} onChange={e => set('name', e.target.value)} style={INP} placeholder="Milestone name" />
          </div>
          <div>
            <label style={LBL}>{req} Start Date</label>
            <input type="date" value={f.start_date} onChange={e => set('start_date', e.target.value)} style={INP} />
          </div>
          <div>
            <label style={LBL}>{req} Due date</label>
            <input type="date" value={f.due_date} onChange={e => set('due_date', e.target.value)} style={INP} />
          </div>
          <div>
            <label style={LBL}>Description</label>
            <textarea value={f.description} onChange={e => set('description', e.target.value)} rows={4}
              style={{ ...INP, resize: 'vertical', minHeight: 90 }} placeholder="Optional" />
          </div>
          <label className="flex items-center gap-2 text-xs" style={{ color: 'var(--text-body)', cursor: 'pointer' }}>
            <input type="checkbox" checked={f.show_description_to_customer} onChange={e => set('show_description_to_customer', e.target.checked)}
              style={{ accentColor: PROJECT_ACCENT, width: 15, height: 15 }} />
            Show description to customer
          </label>
          <label className="flex items-center gap-2 text-xs" style={{ color: 'var(--text-body)', cursor: 'pointer' }}>
            <input type="checkbox" checked={f.hide_from_customer} onChange={e => set('hide_from_customer', e.target.checked)}
              style={{ accentColor: PROJECT_ACCENT, width: 15, height: 15 }} />
            Hide from customer
          </label>
          <div>
            <label style={LBL}>Order</label>
            <input type="number" min="0" value={f.order} onChange={e => set('order', Number(e.target.value) || 0)} style={INP} />
          </div>
        </div>

        <footer className="flex items-center justify-end gap-2 px-5 py-3.5" style={{ borderTop: '1px solid var(--border)' }}>
          <button type="button" onClick={requestClose} className="text-sm font-semibold px-4 py-2 rounded-xl"
            style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Close</button>
          <button type="submit" disabled={!ok || busy} className="text-sm font-bold px-5 py-2 rounded-xl disabled:opacity-40"
            style={{ background: PROJECT_ACCENT, color: '#fff' }}>
            {busy ? 'Saving…' : 'Submit'}
          </button>
        </footer>
      </form>
    </div>
  )
}

/* ── Files tab ────────────────────────────────────────────────── */

function FilesTab({ projectId }) {
  const qc = useQueryClient()
  const input = useRef(null)
  const [err, setErr] = useState('')
  const { data: files = [] } = useQuery({ queryKey: ['project-files', projectId], queryFn: () => projectApi.files(projectId) })
  const refresh = () => qc.invalidateQueries({ queryKey: ['project-files', projectId] })

  const upload = useMutation({
    mutationFn: (file) => { const fd = new FormData(); fd.append('file', file); return projectApi.uploadFile(projectId, fd) },
    onSuccess: () => { setErr(''); refresh() },
    onError: (e) => setErr(e?.message || 'Upload failed.'),
  })
  const del = useMutation({ mutationFn: (fid) => projectApi.deleteFile(projectId, fid), onSuccess: refresh, onError: (e) => setErr(e?.message) })

  return (
    <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <h2 className="font-bold text-xs mb-3 flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
        <Paperclip size={14} style={{ color: PROJECT_ACCENT }} /> Files
      </h2>

      <button onClick={() => input.current?.click()} className="w-full rounded-xl py-4 mb-3 text-center"
        style={{ border: '1px dashed var(--border)' }}>
        <Upload size={15} className="mx-auto mb-1" style={{ color: 'var(--text-muted)' }} />
        <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{upload.isPending ? 'Uploading…' : 'Click to upload a file'}</p>
        <input ref={input} type="file" hidden onChange={e => { if (e.target.files[0]) upload.mutate(e.target.files[0]); e.target.value = '' }} />
      </button>

      {err && <p className="text-[11px] mb-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

      <ul className="space-y-1.5">
        {files.map(f => (
          <li key={f.id} className="flex items-center gap-2 px-2 py-1.5 rounded-lg" style={{ background: 'var(--bg-input)' }}>
            <Paperclip size={12} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
            <span className="flex-1 min-w-0">
              <span className="block text-[11px] font-semibold truncate" style={{ color: 'var(--text-h)' }}>{f.original_name}</span>
              {f.uploader?.name && <span className="block text-[9px]" style={{ color: 'var(--text-muted)' }}>{f.uploader.name}</span>}
            </span>
            {f.visible_to_customer && (
              <span className="text-[9px] px-1.5 py-0.5 rounded font-bold"
                style={{ background: 'color-mix(in srgb, var(--color-success-500) 14%, transparent)', color: 'var(--color-success-500)' }}>client</span>
            )}
            <button onClick={() => projectApi.downloadFile(projectId, f.id, f.original_name)} aria-label={`Download ${f.original_name}`} className="hover:opacity-60">
              <Download size={12} style={{ color: 'var(--text-muted)' }} />
            </button>
            <button onClick={() => del.mutate(f.id)} aria-label={`Delete ${f.original_name}`} className="hover:opacity-60">
              <Trash2 size={12} style={{ color: 'var(--color-danger-500)' }} />
            </button>
          </li>
        ))}
        {files.length === 0 && <li className="text-xs" style={{ color: 'var(--text-muted)' }}>No files.</li>}
      </ul>
    </section>
  )
}

/* ── Tickets tab ──────────────────────────────────────────────── */

function TicketsTab({ projectId, navigate }) {
  const { data: tickets = [] } = useQuery({ queryKey: ['project-tickets', projectId], queryFn: () => projectApi.tickets(projectId) })

  return (
    <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <h2 className="font-bold text-xs mb-3 flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
        <LifeBuoy size={14} style={{ color: PROJECT_ACCENT }} /> Linked tickets
      </h2>
      <ul className="space-y-1">
        {tickets.map(t => (
          <li key={t.id}>
            <button onClick={() => navigate(`/app/helpdesk/tickets/${t.id}`)}
              className="w-full flex items-center gap-2 px-2 py-1.5 rounded-lg text-left"
              onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'}
              onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
              <span className="font-mono text-[10px]" style={{ color: 'var(--color-support-500)' }}>#{t.id}</span>
              <span className="flex-1 text-xs truncate" style={{ color: 'var(--text-h)' }}>{t.subject}</span>
              <span className="text-[10px] px-2 py-0.5 rounded-lg capitalize"
                style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>{t.status}</span>
            </button>
          </li>
        ))}
        {tickets.length === 0 && <li className="text-xs" style={{ color: 'var(--text-muted)' }}>No tickets linked to this project.</li>}
      </ul>
    </section>
  )
}

/* ── Bits ─────────────────────────────────────────────────────── */

function MenuItem({ icon: Icon, children, onClick, danger }) {
  return (
    <button onClick={onClick} className="w-full flex items-center gap-2 px-3 py-2 text-left text-xs font-semibold"
      style={{ color: danger ? 'var(--color-danger-500)' : 'var(--text-h)' }}
      onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'}
      onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
      <Icon size={12} /> {children}
    </button>
  )
}
