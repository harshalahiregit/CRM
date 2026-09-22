import { useState, useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  ClipboardList, CalendarDays, ChevronRight, ChevronDown, ListTree,
  CheckSquare, Loader2, AlertTriangle, Users,
} from 'lucide-react'
import api from '@/lib/api'
import { handleErr } from '@/services/apiError'

/**
 * "Assigned to me" — the same screen in all three portals.
 *
 * One component, not one per portal, because the rule behind it is one rule and
 * that is the half that must not drift: you see the tasks assigned to you, and
 * the work nested underneath them, and nothing else. Three copies of this screen
 * would be three chances for one of them to show a client the rest of a board.
 *
 * It also needs no api client passed in. The other shared portal pages take one
 * because each portal reads a DIFFERENT endpoint for the same idea; here the
 * endpoint really is the same URL for all three, and the server works out who is
 * asking from the token rather than from the path.
 *
 * Read-only on purpose, for now: an external contact marking their own work
 * complete is a real feature and a bigger decision than this screen — it changes
 * a percentage that internal staff are reporting on. Seeing the work is the part
 * that was missing entirely.
 */

const fetchTasks = () =>
  api.get('/portal/assigned-tasks').then((r) => r.data?.data ?? r.data).catch(handleErr)

const fmtDate = (d) => (d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : null)

const ACCENT = '#7C3AED'

export default function PortalAssignedTasks() {
  const { data: tasks = [], isLoading, isError, error } = useQuery({
    queryKey: ['portal-assigned-tasks'], queryFn: fetchTasks,
  })

  const [open, setOpen] = useState(() => new Set())
  const toggle = (id) => setOpen((s) => {
    const next = new Set(s)
    next.has(id) ? next.delete(id) : next.add(id)
    return next
  })

  /*
   * The server sends a flat list — the tasks handed to this person plus every
   * subtask underneath them. Nesting it here rather than asking the server for a
   * tree keeps that one endpoint honest: it returns exactly the rows this person
   * may see, and the shape is this screen's business.
   *
   * A child whose parent is NOT in the list is shown at the top level. That
   * happens whenever somebody is given a subtask on its own — the tree above it
   * stays shut, so its parent is not theirs to see, and orphaning it here would
   * hide the one thing they were actually assigned.
   */
  const { roots, childrenOf } = useMemo(() => {
    const present = new Set(tasks.map((t) => t.id))
    const childrenOf = new Map()
    const roots = []

    for (const t of tasks) {
      if (t.parent_id && present.has(t.parent_id)) {
        if (!childrenOf.has(t.parent_id)) childrenOf.set(t.parent_id, [])
        childrenOf.get(t.parent_id).push(t)
      } else {
        roots.push(t)
      }
    }

    return { roots, childrenOf }
  }, [tasks])

  if (isLoading) {
    return (
      <div className="flex justify-center py-16" style={{ color: 'var(--text-muted)' }}>
        <Loader2 size={22} className="animate-spin" />
      </div>
    )
  }

  if (isError) {
    return (
      <div className="flex items-start gap-2 rounded-xl p-4 text-sm"
        style={{ border: '1px solid color-mix(in srgb, var(--color-danger-500) 30%, transparent)', color: 'var(--color-danger-500)' }}>
        <AlertTriangle size={16} className="mt-0.5 shrink-0" />
        <span>{error?.title || error?.message || 'Your tasks could not be loaded.'}</span>
      </div>
    )
  }

  const mine = tasks.filter((t) => t.assigned_to_me).length

  return (
    <div className="space-y-4">
      <header className="flex items-start gap-3">
        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl"
          style={{ background: `color-mix(in srgb, ${ACCENT} 14%, transparent)`, color: ACCENT }}>
          <ClipboardList size={19} />
        </span>
        <div>
          <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>My Tasks</h1>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            {mine === 0
              ? 'Work assigned to you will appear here.'
              : `${mine} ${mine === 1 ? 'task' : 'tasks'} assigned to you${
                  tasks.length > mine ? `, with ${tasks.length - mine} more inside them` : ''
                }.`}
          </p>
        </div>
      </header>

      {!tasks.length ? (
        <div className="rounded-xl px-6 py-14 text-center"
          style={{ border: '1px dashed var(--border)', background: 'var(--bg-card)' }}>
          <ClipboardList size={26} style={{ color: 'var(--text-muted)', margin: '0 auto 10px' }} />
          <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>Nothing assigned to you yet</p>
          {/* Said plainly, because an empty screen and a broken screen look the
              same and only one of them is worth a phone call. */}
          <p className="mx-auto mt-1.5 max-w-sm text-xs" style={{ color: 'var(--text-muted)' }}>
            When someone puts your name on a task, it shows up here and you are emailed at the same time.
          </p>
        </div>
      ) : (
        <ul className="space-y-2">
          {roots.map((t) => (
            <Node key={t.id} task={t} childrenOf={childrenOf} open={open} toggle={toggle} level={0} />
          ))}
        </ul>
      )}
    </div>
  )
}

function Node({ task, childrenOf, open, toggle, level }) {
  const kids = childrenOf.get(task.id) || []
  const expanded = open.has(task.id)
  const due = fmtDate(task.due_date)
  const overdue = task.due_date && new Date(task.due_date) < new Date().setHours(0, 0, 0, 0)

  const sub = task.progress?.breakdown?.subtasks
  const list = task.progress?.breakdown?.checklist

  return (
    <li>
      <div className="rounded-xl p-3"
        style={{
          background: 'var(--bg-card)',
          border: '1px solid var(--border)',
          // The work they were HANDED reads differently from a piece of it. A
          // subtask styled identically reads as a second assignment.
          borderLeft: task.assigned_to_me ? `3px solid ${ACCENT}` : '1px solid var(--border)',
          marginLeft: level * 16,
        }}>
        <div className="flex items-start gap-2">
          <button onClick={() => kids.length && toggle(task.id)}
            className="mt-0.5 shrink-0"
            style={{ visibility: kids.length ? 'visible' : 'hidden', color: 'var(--text-muted)' }}
            aria-label={expanded ? 'Collapse' : 'Expand'}>
            {expanded ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
          </button>

          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
              <span className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>{task.name}</span>
              {task.assigned_to_me && (
                <span className="rounded-md px-1.5 py-0.5 text-[9px] font-black uppercase tracking-wide"
                  style={{ background: `color-mix(in srgb, ${ACCENT} 14%, transparent)`, color: ACCENT }}>
                  Yours
                </span>
              )}
              {due && (
                <span className="flex items-center gap-1 text-[11px]"
                  style={{ color: overdue ? 'var(--color-danger-500)' : 'var(--text-muted)' }}>
                  <CalendarDays size={10} /> {due}{overdue ? ' · overdue' : ''}
                </span>
              )}
            </div>

            {/* The two tallies kept apart, exactly as the internal tree shows
                them — one rolled-up percentage does not say whether four
                checklist lines are ticked or two subtasks are finished. */}
            <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
              {task.progress?.total > 0 && (
                <span className="flex items-center gap-1.5 text-[11px] tabular-nums" style={{ color: 'var(--text-muted)' }}>
                  <span className="inline-block overflow-hidden rounded-full" style={{ width: 46, height: 4, background: 'var(--bg-input)' }}>
                    <span className="block h-full rounded-full"
                      style={{ width: `${task.progress.percent}%`, background: 'var(--color-success-500)' }} />
                  </span>
                  {task.progress.percent}%
                </span>
              )}
              {sub?.total > 0 && (
                <span className="flex items-center gap-1 text-[11px] tabular-nums" style={{ color: 'var(--text-muted)' }}>
                  <ListTree size={10} /> {sub.done}/{sub.total} subtasks
                </span>
              )}
              {list?.total > 0 && (
                <span className="flex items-center gap-1 text-[11px] tabular-nums" style={{ color: 'var(--text-muted)' }}>
                  <CheckSquare size={10} /> {list.done}/{list.total} items
                </span>
              )}
              {task.people?.length > 1 && (
                <span className="flex items-center gap-1 text-[11px]" style={{ color: 'var(--text-muted)' }}>
                  <Users size={10} /> {task.people.map((p) => p.name).join(', ')}
                </span>
              )}
            </div>
          </div>
        </div>
      </div>

      {expanded && kids.length > 0 && (
        <ul className="mt-2 space-y-2">
          {kids.map((k) => (
            <Node key={k.id} task={k} childrenOf={childrenOf} open={open} toggle={toggle} level={level + 1} />
          ))}
        </ul>
      )}
    </li>
  )
}
