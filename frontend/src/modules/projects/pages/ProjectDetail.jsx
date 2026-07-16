import { useState, useRef, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, RefreshCw, Plus, Users, Flag, Paperclip, Trash2, ListTodo, LifeBuoy,
  Pencil, Copy, Pin, PinOff, MoreHorizontal, Download, Upload, ExternalLink, Check,
} from 'lucide-react'
import { projectApi, PROJECT_STATUS, PROJECT_ACCENT } from '@/services/projectApi'
import { taskApi } from '@/services/taskApi'
import Select from '@/components/ui/Select'
import SearchPicker, { ConfirmModal } from '@/components/ui/SearchPicker'
import { TagChips } from '@/components/ui/TagInput'
import ProjectFormDrawer from '../components/ProjectFormDrawer'

/**
 * Project detail — Overview / Tasks / Milestones / Files / Tickets.
 *
 * The tab bar is the spec's internal nav, trimmed to the tabs this CRM actually
 * has data for. The spec's other ~25 tabs (Appointly, Domain Manager, Recruitment,
 * Purchase orders…) are Perfex features with no counterpart here — they'd render
 * as empty shells, so they're deliberately not built.
 */

const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const money = v => v != null && v !== '' ? '₹' + Number(v).toLocaleString('en-IN') : '—'

const TABS = [
  { key: 'overview',   label: 'Overview' },
  { key: 'tasks',      label: 'Tasks' },
  { key: 'milestones', label: 'Milestones' },
  { key: 'files',      label: 'Files' },
  { key: 'tickets',    label: 'Tickets' },
]

export default function ProjectDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const qc = useQueryClient()

  const [tab, setTab] = useState('overview')
  const [editing, setEditing] = useState(false)
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

  const s = PROJECT_STATUS[project.status] || { label: project.status, color: 'var(--text-muted)' }

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
            {project.customer?.name || 'No customer'} · created by {project.creator?.name || '—'}
          </p>
          {project.tags?.length > 0 && <div className="mt-2"><TagChips tags={project.tags} max={6} size="md" /></div>}
        </div>

        <div className="flex items-center gap-2">
          <button onClick={() => navigate(`/app/tasks?rel_type=project&rel_id=${id}`)}
            className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
            style={{ background: `color-mix(in srgb, ${PROJECT_ACCENT} 14%, transparent)`, color: PROJECT_ACCENT }}>
            <ListTodo size={13} /> New Task
          </button>
          <div style={{ minWidth: 150 }}>
            <Select value={project.status} onChange={v => setStatus.mutate(v)} ariaLabel="Project status"
              options={Object.entries(PROJECT_STATUS).map(([k, m]) => ({ value: k, label: m.label, dot: m.color }))}
              buttonStyle={{ borderColor: s.color, color: s.color, fontWeight: 700 }} />
          </div>

          <div className="relative" ref={menuRef}>
            <button onClick={() => setMenuOpen(o => !o)} aria-label="More actions"
              className="w-9 h-9 rounded-xl flex items-center justify-center"
              style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
              <MoreHorizontal size={15} />
            </button>
            {menuOpen && (
              <div className="absolute right-0 mt-2 rounded-xl overflow-hidden z-40" style={{ width: 190, background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)' }}>
                <MenuItem icon={Pencil} onClick={() => { setMenuOpen(false); setEditing(true) }}>Edit Project</MenuItem>
                <MenuItem icon={project.is_pinned ? PinOff : Pin} onClick={() => { setMenuOpen(false); pin.mutate() }}>
                  {project.is_pinned ? 'Unpin Project' : 'Pin Project'}
                </MenuItem>
                <MenuItem icon={Copy} onClick={() => { setMenuOpen(false); copy.mutate() }}>Copy Project</MenuItem>
                <MenuItem icon={Trash2} danger onClick={() => { setMenuOpen(false); setConfirmDelete(true) }}>Delete Project</MenuItem>
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
        {TABS.map(t => (
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
      {tab === 'tasks' && <TasksTab projectId={id} navigate={navigate} />}
      {tab === 'milestones' && <MilestonesTab project={project} onChange={invalidate} onErr={onErr} />}
      {tab === 'files' && <FilesTab projectId={id} />}
      {tab === 'tickets' && <TicketsTab projectId={id} navigate={navigate} />}

      <ProjectFormDrawer open={editing} onClose={() => setEditing(false)} project={project} onSaved={invalidate} />

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
            <Row label="Customer" value={project.customer?.name || '—'} />
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

function TasksTab({ projectId, navigate }) {
  const { data: tasks = [], isLoading } = useQuery({
    queryKey: ['tasks', { rel_type: 'project', rel_id: projectId }],
    queryFn: () => taskApi.list({ rel_type: 'project', rel_id: projectId }),
  })

  const byStatus = ['not_started', 'in_progress', 'awaiting_feedback', 'testing', 'complete']
  const counts = Object.fromEntries(byStatus.map(k => [k, tasks.filter(t => t.status === k).length]))
  const done = counts.complete || 0

  if (isLoading) return <div className="rounded-2xl animate-pulse" style={{ height: 140, background: 'var(--bg-card)' }} />

  return (
    <div className="space-y-4">
      <div className="grid gap-2" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(110px, 1fr))' }}>
        {byStatus.map(k => (
          <div key={k} className="rounded-xl p-2.5" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
            <p className="text-[9px] font-bold uppercase tracking-wide truncate" style={{ color: 'var(--text-muted)' }}>{k.replace(/_/g, ' ')}</p>
            <p className="text-lg font-black" style={{ color: 'var(--text-h)' }}>{counts[k]}</p>
          </div>
        ))}
      </div>

      <div className="flex items-center gap-2">
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          <strong style={{ color: 'var(--text-h)' }}>{done}/{tasks.length}</strong> complete
        </p>
        <button onClick={() => navigate(`/app/tasks?rel_type=project&rel_id=${projectId}`)}
          className="ml-auto flex items-center gap-1 text-xs font-bold" style={{ color: PROJECT_ACCENT }}>
          Open in Tasks <ExternalLink size={11} />
        </button>
      </div>

      <div className="rounded-2xl overflow-hidden" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        {tasks.length === 0 && <p className="text-xs text-center py-10" style={{ color: 'var(--text-muted)' }}>No tasks linked to this project yet.</p>}
        {tasks.map(t => (
          <button key={t.id} onClick={() => navigate(`/app/tasks/${t.id}`)}
            className="w-full flex items-center gap-2 px-4 py-2.5 text-left"
            style={{ borderBottom: '1px solid var(--border)' }}
            onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'}
            onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
            {t.status === 'complete'
              ? <Check size={12} style={{ color: 'var(--color-success-500)', flexShrink: 0 }} />
              : <span className="w-2 h-2 rounded-full shrink-0" style={{ background: 'var(--text-muted)' }} />}
            <span className="flex-1 text-xs truncate" style={{ color: 'var(--text-h)' }}>{t.name}</span>
            <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{t.due_date ? fmtDate(t.due_date) : ''}</span>
          </button>
        ))}
      </div>
    </div>
  )
}

/* ── Milestones tab ───────────────────────────────────────────── */

function MilestonesTab({ project, onChange, onErr }) {
  const [ms, setMs] = useState({ name: '', due_date: '' })
  const add = useMutation({
    mutationFn: (data) => projectApi.createMilestone(project.id, data),
    onSuccess: () => { setMs({ name: '', due_date: '' }); onChange() }, onError: onErr,
  })
  const del = useMutation({ mutationFn: (mid) => projectApi.deleteMilestone(mid), onSuccess: onChange, onError: onErr })

  const milestones = project.milestones || []

  return (
    <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <h2 className="font-bold text-xs mb-3 flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
        <Flag size={14} style={{ color: PROJECT_ACCENT }} /> Milestones
      </h2>
      <ul className="space-y-1.5 mb-3">
        {milestones.map(m => (
          <li key={m.id} className="flex items-center gap-2 px-2 py-1.5 rounded-lg" style={{ background: 'var(--bg-input)' }}>
            <span className="w-2 h-2 rounded-full shrink-0" style={{ background: m.color || PROJECT_ACCENT }} />
            <span className="flex-1 text-xs truncate" style={{ color: 'var(--text-h)' }}>{m.name}</span>
            <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{fmtDate(m.due_date)}</span>
            <button onClick={() => del.mutate(m.id)} className="hover:opacity-60" aria-label={`Delete ${m.name}`}>
              <Trash2 size={11} style={{ color: 'var(--color-danger-500)' }} />
            </button>
          </li>
        ))}
        {milestones.length === 0 && <li className="text-xs" style={{ color: 'var(--text-muted)' }}>No milestones yet.</li>}
      </ul>
      <form onSubmit={e => { e.preventDefault(); if (ms.name && ms.due_date) add.mutate(ms) }} className="flex gap-2">
        <input value={ms.name} onChange={e => setMs({ ...ms, name: e.target.value })} placeholder="Milestone name"
          className="flex-1 rounded-lg outline-none"
          style={{ padding: '7px 10px', fontSize: 12, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
        <input type="date" value={ms.due_date} onChange={e => setMs({ ...ms, due_date: e.target.value })}
          className="rounded-lg outline-none"
          style={{ padding: '7px 10px', fontSize: 12, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
        <button type="submit" disabled={!ms.name || !ms.due_date || add.isPending}
          className="px-3 rounded-lg disabled:opacity-40" style={{ background: PROJECT_ACCENT, color: '#fff' }} aria-label="Add milestone">
          <Plus size={13} />
        </button>
      </form>
    </section>
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
