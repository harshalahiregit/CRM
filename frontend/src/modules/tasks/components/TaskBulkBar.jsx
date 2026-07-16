import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { X, Trash2, Loader2 } from 'lucide-react'
import { taskApi, TASK_STATUS, TASK_PRIORITY, TASK_ACCENT } from '@/services/taskApi'
import Select from '@/components/ui/Select'
import { ConfirmModal } from '@/components/ui/SearchPicker'

/**
 * Bulk action bar. Fires ONE request to /tasks/bulk rather than N parallel
 * single-item calls (which is what the helpdesk grid does) — so 200 selected
 * tasks is one round trip, and the server validates the value once instead of
 * half-applying a bad one.
 */
export default function TaskBulkBar({ selected, onClear, staff = [] }) {
  const qc = useQueryClient()
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [err, setErr] = useState('')
  const ids = [...selected]

  const run = useMutation({
    mutationFn: ({ action, value }) => taskApi.bulk(action, ids, value),
    onSuccess: () => {
      setErr('')
      qc.invalidateQueries({ queryKey: ['tasks'] })
      qc.invalidateQueries({ queryKey: ['task-stats'] })
      onClear()
    },
    onError: (e) => setErr(e?.message || 'Bulk action failed.'),
  })

  if (!ids.length) return null

  return (
    <>
      <div className="flex flex-wrap items-center gap-2 mb-3 p-2.5 rounded-2xl"
        style={{ background: `color-mix(in srgb, ${TASK_ACCENT} 8%, var(--bg-card))`, border: `1px solid ${TASK_ACCENT}` }}>
        <span className="text-xs font-bold px-2 py-1 rounded-lg" style={{ background: TASK_ACCENT, color: '#fff' }}>
          {ids.length} selected
        </span>

        <div style={{ minWidth: 150 }}>
          <Select size="sm" value="" placeholder="Set status…"
            onChange={v => v && run.mutate({ action: 'status', value: v })}
            options={Object.entries(TASK_STATUS).map(([v, m]) => ({ value: v, label: m.label, dot: m.color }))} />
        </div>
        <div style={{ minWidth: 140 }}>
          <Select size="sm" value="" placeholder="Set priority…"
            onChange={v => v && run.mutate({ action: 'priority', value: v })}
            options={Object.entries(TASK_PRIORITY).map(([v, c]) => ({ value: v, label: v[0].toUpperCase() + v.slice(1), dot: c }))} />
        </div>
        <div style={{ minWidth: 150 }}>
          {/* Adds to existing assignees rather than replacing them. */}
          <Select size="sm" value="" placeholder="Assign to…"
            onChange={v => v && run.mutate({ action: 'assign', value: Number(v) })}
            options={staff.map(s => ({ value: String(s.id), label: s.name }))} />
        </div>

        <button onClick={() => setConfirmDelete(true)}
          className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
          style={{ background: 'color-mix(in srgb, var(--color-danger-500) 14%, transparent)', color: 'var(--color-danger-500)' }}>
          <Trash2 size={12} /> Delete
        </button>

        {run.isPending && <Loader2 size={14} className="animate-spin" style={{ color: TASK_ACCENT }} />}

        <button onClick={onClear} className="ml-auto flex items-center gap-1 text-xs font-semibold"
          style={{ color: 'var(--text-muted)' }}>
          <X size={12} /> Clear
        </button>
      </div>

      {err && (
        <p className="text-xs px-3 py-2 rounded-lg mb-3"
          style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>{err}</p>
      )}

      <ConfirmModal open={confirmDelete} onClose={() => setConfirmDelete(false)}
        onConfirm={() => run.mutate({ action: 'delete', value: null })}
        title={`Delete ${ids.length} ${ids.length === 1 ? 'task' : 'tasks'}?`}
        message="They'll be removed from the board. This can't be undone from the UI."
        confirmLabel="Delete" danger />
    </>
  )
}
