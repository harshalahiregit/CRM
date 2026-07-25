import { useState } from 'react'
import { ChevronRight, Square, CheckSquare, Plus, CalendarDays, CornerDownRight } from 'lucide-react'
import { TASK_ACCENT, TASK_PRIORITY } from '@/services/taskApi'

/**
 * The recursive subtask tree that lives inside the task modal.
 *
 * One component renders every level, calling itself for `children` — the shape
 * the server sends back is already nested, so there is no depth logic here at
 * all beyond indentation. That is deliberate: the depth cap and the cycle guard
 * are enforced server-side (TaskTreeService), because a rule the UI enforces is
 * a rule an API call can walk around.
 *
 * Each row shows what makes a subtask a task rather than a checklist line: who
 * owns it, when it's due, and how much of ITS OWN tree is finished.
 */

const fmtDate = (d) => {
  if (!d) return null
  const dt = new Date(d)
  return dt.toLocaleDateString('en-IN', { day: '2-digit', month: 'short' })
}

/** Deep enough to be context rather than clutter — past this we collapse. */
const AUTO_OPEN_DEPTH = 2

function Row({ node, onToggle, onAddChild, onOpen, busyId }) {
  const [open, setOpen] = useState(node.depth < AUTO_OPEN_DEPTH)
  const [adding, setAdding] = useState(false)
  const [draft, setDraft] = useState('')

  const kids = node.children || []
  const hasKids = kids.length > 0
  const due = fmtDate(node.due_date)
  const overdue = node.due_date && !node.is_done && new Date(node.due_date) < new Date().setHours(0, 0, 0, 0)
  const busy = busyId === node.id

  const submit = () => {
    const name = draft.trim()
    if (!name) return
    onAddChild(node.id, name)
    setDraft('')
    setAdding(false)
    setOpen(true)   // you just added something in there — show it
  }

  return (
    <li>
      <div className="flex items-start gap-1.5 py-1 group" style={{ opacity: busy ? 0.5 : 1 }}>
        {/* Expander. Kept as a fixed-width slot even with no children, so names
            down a branch stay vertically aligned instead of stair-stepping. */}
        <button
          onClick={() => hasKids && setOpen(o => !o)}
          className="shrink-0 mt-0.5 w-4 h-4 flex items-center justify-center rounded"
          style={{ visibility: hasKids ? 'visible' : 'hidden', color: 'var(--text-muted)' }}
          aria-label={open ? 'Collapse' : 'Expand'}>
          <ChevronRight size={12} style={{ transform: open ? 'rotate(90deg)' : 'none', transition: 'transform .15s' }} />
        </button>

        <button onClick={() => onToggle(node)} disabled={busy} className="shrink-0 mt-0.5" aria-label="Toggle complete">
          {node.is_done
            ? <CheckSquare size={14} style={{ color: 'var(--color-success-500)' }} />
            : <Square size={14} style={{ color: 'var(--text-muted)' }} />}
        </button>

        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
            <button onClick={() => onOpen(node.id)} className="text-xs text-left truncate"
              style={{
                color: node.is_done ? 'var(--text-muted)' : 'var(--text-h)',
                textDecoration: node.is_done ? 'line-through' : 'none',
                fontWeight: hasKids ? 600 : 400,
              }}>
              {node.name}
            </button>

            {/* Priority pip — a dot, not a badge; the row is already busy. */}
            <span className="w-1.5 h-1.5 rounded-full shrink-0"
              style={{ background: TASK_PRIORITY[node.priority] || 'transparent' }} title={node.priority} />

            {node.assignees?.length > 0 && (
              <span className="flex items-center gap-0.5">
                {node.assignees.slice(0, 3).map(a => (
                  <span key={a.user_id} title={a.name}
                    className="w-4 h-4 rounded-full flex items-center justify-center text-[8px] font-black"
                    style={{ background: `color-mix(in srgb, ${TASK_ACCENT} 18%, transparent)`, color: TASK_ACCENT }}>
                    {(a.name || '?').slice(0, 1).toUpperCase()}
                  </span>
                ))}
                {node.assignees.length > 3 && (
                  <span className="text-[9px]" style={{ color: 'var(--text-muted)' }}>+{node.assignees.length - 3}</span>
                )}
              </span>
            )}

            {due && (
              <span className="flex items-center gap-0.5 text-[10px]"
                style={{ color: overdue ? 'var(--color-danger-500)' : 'var(--text-muted)' }}>
                <CalendarDays size={9} /> {due}
              </span>
            )}

            {/* Only meaningful when there IS work underneath — a childless row's
                progress is just its own tick box, which is already shown. */}
            {node.progress?.total > 0 && hasKids && (
              <span className="flex items-center gap-1 text-[10px] tabular-nums" style={{ color: 'var(--text-muted)' }}>
                <span className="inline-block rounded-full overflow-hidden" style={{ width: 34, height: 3, background: 'var(--bg-input)' }}>
                  <span className="block h-full rounded-full"
                    style={{ width: `${node.progress.percent}%`, background: 'var(--color-success-500)' }} />
                </span>
                {node.progress.done}/{node.progress.total}
              </span>
            )}

            <button onClick={() => { setAdding(a => !a); setOpen(true) }}
              className="opacity-0 group-hover:opacity-100 transition-opacity flex items-center gap-0.5 text-[10px] font-bold ml-auto"
              style={{ color: TASK_ACCENT }}>
              <Plus size={10} /> Subtask
            </button>
          </div>

          {adding && (
            <div className="flex items-center gap-1.5 mt-1">
              <CornerDownRight size={11} style={{ color: 'var(--text-muted)' }} />
              <input autoFocus value={draft} onChange={e => setDraft(e.target.value)}
                onKeyDown={e => { if (e.key === 'Enter') submit(); if (e.key === 'Escape') { setAdding(false); setDraft('') } }}
                placeholder={`Subtask under "${node.name}"…`}
                className="flex-1 rounded-lg outline-none text-xs"
                style={{ padding: '5px 9px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
              <button onClick={submit} className="text-[10px] font-bold px-2 py-1 rounded-lg"
                style={{ background: TASK_ACCENT, color: '#fff' }}>Add</button>
            </div>
          )}
        </div>
      </div>

      {hasKids && open && (
        <ul className="ml-[7px] pl-3" style={{ borderLeft: '1px solid var(--border)' }}>
          {kids.map(child => (
            <Row key={child.id} node={child} onToggle={onToggle} onAddChild={onAddChild}
              onOpen={onOpen} busyId={busyId} />
          ))}
        </ul>
      )}
    </li>
  )
}

export default function SubtaskTree({ nodes = [], onToggle, onAddChild, onOpen, busyId }) {
  if (!nodes.length) return null

  return (
    <ul className="mb-1">
      {nodes.map(n => (
        <Row key={n.id} node={n} onToggle={onToggle} onAddChild={onAddChild} onOpen={onOpen} busyId={busyId} />
      ))}
    </ul>
  )
}
