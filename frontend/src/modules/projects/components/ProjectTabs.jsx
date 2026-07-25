import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Clock, StickyNote, Activity, Plus, Trash2, Eye, Download } from 'lucide-react'
import { projectApi, PROJECT_ACCENT } from '@/services/projectApi'
import { fmtDuration } from '@/services/taskApi'
import { exportCsv, stampedName } from '@/lib/exportCsv'
import { ConfirmModal } from '@/components/ui/SearchPicker'

const fmtDateTime = d => d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'

/* ── Timesheets ───────────────────────────────────────────────── */

export function TimesheetsTab({ projectId }) {
  const { data, isLoading } = useQuery({ queryKey: ['project-timesheets', projectId], queryFn: () => projectApi.timesheets(projectId) })
  const rows = data?.rows || []

  const doExport = () => exportCsv(stampedName(`project-${projectId}-timesheets`), rows, [
    { key: 'member_name', label: 'Member' },
    { key: 'task_name', label: 'Task' },
    { key: 'start_time', label: 'Start' },
    { key: 'end_time', label: 'End' },
    { key: 'hours', label: 'Hours' },
    { key: 'cost', label: 'Cost' },
    { key: 'note', label: 'Note' },
  ])

  if (isLoading) return <Skeleton />

  return (
    <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <div className="flex items-center gap-2 mb-3">
        <h2 className="font-bold text-xs flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
          <Clock size={14} style={{ color: PROJECT_ACCENT }} /> Timesheets
        </h2>
        <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
          {data?.total_hours ?? 0}h logged across {rows.length} {rows.length === 1 ? 'entry' : 'entries'}
        </span>
        {rows.length > 0 && (
          <button onClick={doExport} className="ml-auto flex items-center gap-1 text-[10px] font-bold" style={{ color: PROJECT_ACCENT }}>
            <Download size={11} /> Export
          </button>
        )}
      </div>

      {rows.length === 0 && <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No time logged on this project's tasks yet.</p>}
      {rows.length > 0 && (
        <div className="overflow-x-auto">
          <table className="w-full text-xs" style={{ minWidth: 560 }}>
            <thead>
              <tr className="text-left text-[10px] uppercase tracking-wide" style={{ color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
                <th className="px-2 py-2 font-bold">Member</th>
                <th className="px-2 py-2 font-bold">Task</th>
                <th className="px-2 py-2 font-bold">When</th>
                <th className="px-2 py-2 font-bold text-right">Time</th>
                <th className="px-2 py-2 font-bold text-right">Cost</th>
              </tr>
            </thead>
            <tbody>
              {rows.map(r => (
                <tr key={r.id} style={{ borderBottom: '1px solid var(--border)' }}>
                  <td className="px-2 py-2" style={{ color: 'var(--text-h)' }}>{r.member_name || '—'}</td>
                  <td className="px-2 py-2" style={{ color: 'var(--text-muted)' }}>{r.task_name}</td>
                  <td className="px-2 py-2" style={{ color: 'var(--text-muted)' }}>{fmtDateTime(r.start_time)}</td>
                  <td className="px-2 py-2 text-right tabular-nums" style={{ color: 'var(--text-h)' }}>{fmtDuration(r.seconds)}</td>
                  <td className="px-2 py-2 text-right tabular-nums" style={{ color: 'var(--text-muted)' }}>{r.cost ? '₹' + r.cost.toLocaleString('en-IN') : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}

/* ── Notes ────────────────────────────────────────────────────── */

export function NotesTab({ projectId }) {
  const qc = useQueryClient()
  const [title, setTitle] = useState('')
  const [content, setContent] = useState('')
  const [confirmDelete, setConfirmDelete] = useState(null)
  const { data: notes = [], isLoading } = useQuery({ queryKey: ['project-notes', projectId], queryFn: () => projectApi.notes(projectId) })
  const bust = () => qc.invalidateQueries({ queryKey: ['project-notes', projectId] })

  const add = useMutation({
    mutationFn: () => projectApi.addNote(projectId, { title: title.trim(), content: content.trim() || null }),
    onSuccess: () => { setTitle(''); setContent(''); bust() },
  })
  const del = useMutation({ mutationFn: (nid) => projectApi.deleteNote(projectId, nid), onSuccess: () => { setConfirmDelete(null); bust() } })

  if (isLoading) return <Skeleton />

  return (
    <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <h2 className="font-bold text-xs mb-3 flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
        <StickyNote size={14} style={{ color: PROJECT_ACCENT }} /> Notes
      </h2>

      <form onSubmit={e => { e.preventDefault(); if (title.trim()) add.mutate() }} className="space-y-2 mb-4">
        <input value={title} onChange={e => setTitle(e.target.value)} placeholder="Note title"
          className="w-full rounded-lg outline-none"
          style={{ padding: '8px 11px', fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
        <textarea value={content} onChange={e => setContent(e.target.value)} placeholder="Write a note… (optional)" rows={2}
          className="w-full rounded-lg outline-none"
          style={{ padding: '8px 11px', fontSize: 12.5, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
        <button type="submit" disabled={!title.trim() || add.isPending}
          className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-40"
          style={{ background: PROJECT_ACCENT, color: '#fff' }}><Plus size={12} /> Add note</button>
      </form>

      <ul className="space-y-2">
        {notes.map(n => (
          <li key={n.id} className="rounded-xl p-3" style={{ background: 'var(--bg-input)' }}>
            <div className="flex items-start gap-2">
              <span className="flex-1 min-w-0">
                <span className="block text-xs font-bold" style={{ color: 'var(--text-h)' }}>{n.title}</span>
                {n.content && <span className="block text-xs mt-0.5 whitespace-pre-wrap break-words" style={{ color: 'var(--text-body)' }}>{n.content}</span>}
                <span className="block text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>
                  {n.author?.name || 'Someone'} · {fmtDateTime(n.created_at)}
                </span>
              </span>
              <button onClick={() => setConfirmDelete(n)} aria-label="Delete note" className="hover:opacity-60">
                <Trash2 size={12} style={{ color: 'var(--color-danger-500)' }} />
              </button>
            </div>
          </li>
        ))}
        {notes.length === 0 && <li className="text-xs" style={{ color: 'var(--text-muted)' }}>No notes yet.</li>}
      </ul>

      <ConfirmModal open={Boolean(confirmDelete)} onClose={() => setConfirmDelete(null)}
        onConfirm={() => del.mutate(confirmDelete.id)}
        title="Delete this note?" message={`“${confirmDelete?.title}” will be removed.`} confirmLabel="Delete" danger />
    </section>
  )
}

/* ── Activity ─────────────────────────────────────────────────── */

const ACTIVITY_DOT = {
  project_created: 'var(--color-primary-500)',
  status_changed:  'var(--color-info-500)',
  member_added:    'var(--color-success-500)',
  note_added:      'var(--color-warning-500)',
}

export function ActivityTab({ projectId }) {
  const { data: activity = [], isLoading } = useQuery({ queryKey: ['project-activity', projectId], queryFn: () => projectApi.activity(projectId) })
  if (isLoading) return <Skeleton />

  return (
    <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <h2 className="font-bold text-xs mb-3 flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
        <Activity size={14} style={{ color: PROJECT_ACCENT }} /> Activity
      </h2>
      <ul className="space-y-0">
        {activity.map((a, i) => (
          <li key={a.id} className="flex gap-3 pb-3">
            <div className="flex flex-col items-center">
              <span className="w-2 h-2 rounded-full mt-1 shrink-0" style={{ background: ACTIVITY_DOT[a.type] || 'var(--text-muted)' }} />
              {i < activity.length - 1 && <span className="w-px flex-1 mt-1" style={{ background: 'var(--border)' }} />}
            </div>
            <div className="min-w-0 flex-1">
              <p className="text-xs" style={{ color: 'var(--text-h)' }}>{a.description}</p>
              <p className="text-[10px] mt-0.5 flex items-center gap-1.5" style={{ color: 'var(--text-muted)' }}>
                {a.actor?.name || 'System'} · {fmtDateTime(a.created_at)}
                {a.visible_to_customer && <span className="inline-flex items-center gap-0.5"><Eye size={9} /> customer-visible</span>}
              </p>
            </div>
          </li>
        ))}
        {activity.length === 0 && <li className="text-xs" style={{ color: 'var(--text-muted)' }}>No activity recorded yet.</li>}
      </ul>
    </section>
  )
}

const Skeleton = () => <div className="rounded-2xl animate-pulse" style={{ height: 160, background: 'var(--bg-card)' }} />
