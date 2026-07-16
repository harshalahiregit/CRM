import { useState, useEffect, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { ListTodo, Plus, LayoutGrid, List, Search, X, SlidersHorizontal } from 'lucide-react'
import { taskApi, TASK_STATUS, TASK_PRIORITY, TASK_ACCENT, relLabel } from '@/services/taskApi'
import Select from '@/components/ui/Select'
import { TagChips } from '@/components/ui/TagInput'
import TaskFormDrawer from '../components/TaskFormDrawer'

/**
 * Task board — kanban + list.
 *
 * The kanban is a real board: cards are dragged between columns with the HTML5
 * drag API (no DnD dependency) and the resulting order is persisted to
 * kanban_order via POST /tasks/reorder. Dropping into another column is also the
 * status change, which is why the old per-card status <select> is gone.
 */

const STATUS_KEYS = Object.keys(TASK_STATUS)

export default function TaskBoard() {
  const navigate = useNavigate()
  const qc = useQueryClient()
  const [params] = useSearchParams()
  const relType = params.get('rel_type') || undefined
  const relId = params.get('rel_id') || undefined

  const [view, setView] = useState('kanban')
  const [showForm, setShowForm] = useState(false)
  const [showFilters, setShowFilters] = useState(false)

  // Filters — the backend already supported all of these; none had a UI.
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  const [status, setStatus] = useState('')
  const [priority, setPriority] = useState('')
  const [assignee, setAssignee] = useState('')

  useEffect(() => {
    const t = setTimeout(() => setDebounced(search.trim()), 300)
    return () => clearTimeout(t)
  }, [search])

  const filters = useMemo(() => {
    const f = {}
    if (relType) { f.rel_type = relType; if (relId) f.rel_id = relId }
    if (debounced) f.search = debounced
    if (status) f.status = status
    if (priority) f.priority = priority
    if (assignee) f.assignee = assignee
    return f
  }, [relType, relId, debounced, status, priority, assignee])

  const { data: tasks = [], isLoading } = useQuery({
    queryKey: ['tasks', filters],
    queryFn: () => taskApi.list(filters),
  })
  const { data: staff = [] } = useQuery({ queryKey: ['task-staff'], queryFn: taskApi.staff })

  const activeFilters = [status, priority, assignee, debounced].filter(Boolean).length
  const clearFilters = () => { setSearch(''); setStatus(''); setPriority(''); setAssignee('') }

  return (
    <div>
      <header className="flex flex-wrap items-center gap-2 mb-4">
        <span className="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: `color-mix(in srgb, ${TASK_ACCENT} 14%, transparent)` }}>
          <ListTodo size={17} style={{ color: TASK_ACCENT }} />
        </span>
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Tasks</h1>
        <span className="text-xs px-2 py-0.5 rounded-lg" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
          {tasks.length}
        </span>
        {relType && (
          <button onClick={() => navigate('/app/tasks')}
            className="flex items-center gap-1 text-xs px-2 py-0.5 rounded-lg"
            style={{ background: `color-mix(in srgb, ${TASK_ACCENT} 12%, transparent)`, color: TASK_ACCENT }}>
            {relType} #{relId} <X size={11} />
          </button>
        )}

        <div className="flex items-center gap-2 ml-auto">
          <button onClick={() => setShowFilters(s => !s)}
            className="relative flex items-center gap-1.5 text-xs font-semibold px-3 py-2 rounded-xl"
            style={{
              background: showFilters || activeFilters ? `color-mix(in srgb, ${TASK_ACCENT} 12%, transparent)` : 'var(--bg-input)',
              border: '1px solid var(--border)',
              color: showFilters || activeFilters ? TASK_ACCENT : 'var(--text-muted)',
            }}>
            <SlidersHorizontal size={13} /> Filters
            {activeFilters > 0 && (
              <span className="w-4 h-4 rounded-full text-[9px] font-black flex items-center justify-center"
                style={{ background: TASK_ACCENT, color: '#fff' }}>{activeFilters}</span>
            )}
          </button>

          <div className="flex rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
            {[['kanban', LayoutGrid], ['list', List]].map(([k, Icon]) => (
              <button key={k} onClick={() => setView(k)} className="px-2.5 py-2" aria-label={`${k} view`}
                style={{
                  background: view === k ? `color-mix(in srgb, ${TASK_ACCENT} 15%, transparent)` : 'transparent',
                  color: view === k ? TASK_ACCENT : 'var(--text-muted)',
                }}>
                <Icon size={14} />
              </button>
            ))}
          </div>

          <button onClick={() => setShowForm(true)}
            className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
            style={{ background: TASK_ACCENT, color: '#fff' }}>
            <Plus size={14} /> New Task
          </button>
        </div>
      </header>

      {showFilters && (
        <FilterBar
          {...{ search, setSearch, status, setStatus, priority, setPriority, assignee, setAssignee, staff }}
          onClear={clearFilters} activeFilters={activeFilters}
        />
      )}

      {isLoading && <div className="rounded-2xl animate-pulse" style={{ height: 240, background: 'var(--bg-card)' }} />}

      {!isLoading && view === 'kanban' && <Kanban tasks={tasks} qc={qc} navigate={navigate} filters={filters} />}
      {!isLoading && view === 'list' && <TaskTable tasks={tasks} navigate={navigate} />}

      <TaskFormDrawer
        open={showForm}
        onClose={() => setShowForm(false)}
        defaults={{ rel_type: relType || 'standalone', rel_id: relId || '' }}
        onSaved={() => qc.invalidateQueries({ queryKey: ['tasks'] })}
      />
    </div>
  )
}

/* ── Filters ──────────────────────────────────────────────────── */

function FilterBar({ search, setSearch, status, setStatus, priority, setPriority, assignee, setAssignee, staff, onClear, activeFilters }) {
  return (
    <div className="flex flex-wrap items-center gap-2 mb-4 p-2.5 rounded-2xl"
      style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <div className="relative flex-1" style={{ minWidth: 180 }}>
        <Search size={14} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
        <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search tasks…"
          className="w-full rounded-xl outline-none"
          style={{ padding: '8px 12px 8px 33px', fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
      </div>

      <FilterSelect value={status} onChange={setStatus} placeholder="Any status"
        options={Object.entries(TASK_STATUS).map(([v, m]) => ({ value: v, label: m.label, dot: m.color }))} />
      <FilterSelect value={priority} onChange={setPriority} placeholder="Any priority"
        options={Object.entries(TASK_PRIORITY).map(([v, c]) => ({ value: v, label: v[0].toUpperCase() + v.slice(1), dot: c }))} />
      <FilterSelect value={assignee} onChange={setAssignee} placeholder="Anyone"
        options={staff.map(s => ({ value: String(s.id), label: s.name }))} />

      {activeFilters > 0 && (
        <button onClick={onClear} className="text-xs font-semibold px-3 py-2 rounded-xl"
          style={{ color: 'var(--color-danger-500)', border: '1px solid var(--border)' }}>Clear</button>
      )}
    </div>
  )
}

function FilterSelect({ value, onChange, options, placeholder }) {
  return (
    <div style={{ minWidth: 132 }}>
      <Select size="sm" value={value} onChange={onChange} placeholder={placeholder}
        options={[{ value: '', label: placeholder }, ...options]} />
    </div>
  )
}

/* ── Kanban with drag & drop ──────────────────────────────────── */

function Kanban({ tasks, qc, navigate, filters }) {
  // Local mirror so a drag repaints instantly; re-synced whenever the server list changes.
  const [board, setBoard] = useState({})
  const [dragId, setDragId] = useState(null)
  const [over, setOver] = useState(null)   // { status, index }

  useEffect(() => {
    const next = {}
    STATUS_KEYS.forEach(k => {
      next[k] = tasks.filter(t => t.status === k).sort((a, b) => (a.kanban_order ?? 0) - (b.kanban_order ?? 0))
    })
    setBoard(next)
  }, [tasks])

  const reorder = useMutation({
    mutationFn: ({ status, ids }) => taskApi.reorder(status, ids),
    onSettled: () => qc.invalidateQueries({ queryKey: ['tasks'] }),
  })

  const drop = (toStatus, toIndex) => {
    const id = dragId
    setDragId(null); setOver(null)
    if (!id) return

    const from = STATUS_KEYS.find(k => (board[k] || []).some(t => t.id === id))
    if (!from) return
    const card = board[from].find(t => t.id === id)
    const sameCol = from === toStatus
    const curIndex = board[from].findIndex(t => t.id === id)
    if (sameCol && (toIndex === curIndex || toIndex === curIndex + 1)) return

    const source = board[from].filter(t => t.id !== id)
    const target = sameCol ? source : [...(board[toStatus] || [])]
    // Removing from earlier in the same column shifts the insert point left by one.
    const at = sameCol && curIndex < toIndex ? toIndex - 1 : toIndex
    target.splice(Math.max(0, Math.min(at, target.length)), 0, { ...card, status: toStatus })

    const next = { ...board, [toStatus]: target }
    if (!sameCol) next[from] = source
    setBoard(next)

    reorder.mutate({ status: toStatus, ids: target.map(t => t.id) })
    if (!sameCol && source.length) reorder.mutate({ status: from, ids: source.map(t => t.id) })
  }

  return (
    <div className="grid gap-3 items-start" style={{ gridTemplateColumns: `repeat(${STATUS_KEYS.length}, minmax(150px, 1fr))` }}>
      {STATUS_KEYS.map(key => {
        const meta = TASK_STATUS[key]
        const col = board[key] || []
        const isOverCol = over?.status === key
        return (
          <section key={key} className="rounded-2xl p-2.5 transition-colors"
            style={{
              border: `1px solid ${isOverCol ? meta.color : 'var(--border)'}`,
              background: isOverCol ? `color-mix(in srgb, ${meta.color} 6%, var(--bg-card))` : 'var(--bg-card)',
              minHeight: 120,
            }}
            onDragOver={e => { e.preventDefault(); if (!over || over.status !== key) setOver({ status: key, index: col.length }) }}
            onDragLeave={e => { if (!e.currentTarget.contains(e.relatedTarget)) setOver(o => (o?.status === key ? null : o)) }}
            onDrop={e => { e.preventDefault(); drop(key, over?.status === key ? over.index : col.length) }}
          >
            <div className="flex items-center gap-1.5 mb-2 px-1">
              <span className="w-2 h-2 rounded-full" style={{ background: meta.color }} />
              <span className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>{meta.label}</span>
              <span className="ml-auto text-[10px] font-bold px-1.5 rounded-md"
                style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>{col.length}</span>
            </div>

            <div className="space-y-2">
              {col.map((t, i) => (
                <div key={t.id}>
                  {isOverCol && over.index === i && <DropLine color={meta.color} />}
                  <TaskCard task={t} dragging={dragId === t.id}
                    onDragStart={() => setDragId(t.id)}
                    onDragEnd={() => { setDragId(null); setOver(null) }}
                    onDragOver={e => {
                      e.preventDefault(); e.stopPropagation()
                      const r = e.currentTarget.getBoundingClientRect()
                      setOver({ status: key, index: e.clientY < r.top + r.height / 2 ? i : i + 1 })
                    }}
                    onClick={() => navigate(`/app/tasks/${t.id}`)} />
                </div>
              ))}
              {isOverCol && over.index >= col.length && <DropLine color={meta.color} />}
              {col.length === 0 && !isOverCol && (
                <p className="text-[10px] text-center py-4" style={{ color: 'var(--text-muted)', opacity: 0.6 }}>Drop here</p>
              )}
            </div>
          </section>
        )
      })}
    </div>
  )
}

const DropLine = ({ color }) => (
  <div className="rounded-full mb-2" style={{ height: 2, background: color, boxShadow: `0 0 6px ${color}` }} />
)

function TaskCard({ task, dragging, onClick, ...dnd }) {
  const link = relLabel(task)
  const overdue = task.due_date && task.status !== 'complete' && new Date(task.due_date) < new Date().setHours(0, 0, 0, 0)
  return (
    <article draggable onClick={onClick} {...dnd}
      className="rounded-xl p-2.5 cursor-grab active:cursor-grabbing transition-opacity"
      style={{
        border: '1px solid var(--border)',
        background: 'var(--bg-global)',
        opacity: dragging ? 0.4 : 1,
      }}>
      <div className="flex items-start gap-1.5">
        <span className="w-1.5 h-1.5 rounded-full mt-1.5 shrink-0" style={{ background: TASK_PRIORITY[task.priority] || 'var(--text-muted)' }} />
        <p className="text-xs leading-snug flex-1" style={{ color: 'var(--text-h)' }}>{task.name}</p>
      </div>
      {task.tags?.length > 0 && <div className="mt-1.5 ml-3"><TagChips tags={task.tags} max={2} /></div>}
      {(link || task.due_date) && (
        <div className="flex items-center gap-1.5 mt-2 flex-wrap">
          {link && (
            <span className="text-[9px] px-1.5 py-0.5 rounded-md truncate" style={{ maxWidth: 110, background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
              {link}
            </span>
          )}
          {task.due_date && (
            <span className="text-[9px] px-1.5 py-0.5 rounded-md"
              style={{
                background: overdue ? 'color-mix(in srgb, var(--color-danger-500) 15%, transparent)' : 'var(--bg-input)',
                color: overdue ? 'var(--color-danger-500)' : 'var(--text-muted)',
              }}>
              {new Date(task.due_date).toLocaleDateString('en-IN', { day: '2-digit', month: 'short' })}
            </span>
          )}
        </div>
      )}
    </article>
  )
}

/* ── List view ────────────────────────────────────────────────── */

function TaskTable({ tasks, navigate }) {
  if (!tasks.length) {
    return (
      <div className="rounded-2xl py-16 text-center" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No tasks match these filters.</p>
      </div>
    )
  }
  return (
    <div className="overflow-x-auto rounded-2xl" style={{ border: '1px solid var(--border)', background: 'var(--bg-card)' }}>
      <table className="w-full text-sm" style={{ minWidth: 640 }}>
        <thead>
          <tr className="text-left text-[11px] uppercase tracking-wide" style={{ color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
            <th className="px-4 py-3 font-bold">Task</th>
            <th className="px-4 py-3 font-bold">Priority</th>
            <th className="px-4 py-3 font-bold">Status</th>
            <th className="px-4 py-3 font-bold">Linked</th>
            <th className="px-4 py-3 font-bold">Due</th>
          </tr>
        </thead>
        <tbody>
          {tasks.map(t => {
            // Guarded: an unrecognised status/priority from the server used to
            // throw on .color and white-screen the page.
            const st = TASK_STATUS[t.status] || { label: t.status, color: 'var(--text-muted)' }
            const pr = TASK_PRIORITY[t.priority] || 'var(--text-muted)'
            return (
              <tr key={t.id} onClick={() => navigate(`/app/tasks/${t.id}`)} className="cursor-pointer"
                style={{ borderBottom: '1px solid var(--border)' }}
                onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'}
                onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                <td className="px-4 py-3">
                  <span style={{ color: 'var(--text-h)' }}>{t.name}</span>
                  {t.tags?.length > 0 && <div className="mt-1"><TagChips tags={t.tags} /></div>}
                </td>
                <td className="px-4 py-3">
                  <span className="text-[10px] font-bold capitalize px-2 py-0.5 rounded-lg"
                    style={{ background: `color-mix(in srgb, ${pr} 15%, transparent)`, color: pr }}>{t.priority}</span>
                </td>
                <td className="px-4 py-3">
                  <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg"
                    style={{ background: `color-mix(in srgb, ${st.color} 15%, transparent)`, color: st.color }}>{st.label}</span>
                </td>
                <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{relLabel(t) || '—'}</td>
                <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>
                  {t.due_date ? new Date(t.due_date).toLocaleDateString('en-IN', { day: '2-digit', month: 'short' }) : '—'}
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
