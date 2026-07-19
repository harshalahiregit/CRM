import { useState, useEffect, useMemo, useRef } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, Users, Eye, CheckSquare, Square, MessageSquare, Play, StopCircle,
  Clock, Pencil, Trash2, ExternalLink, Send, Plus, Copy, RefreshCw, BookmarkPlus, ListPlus,
  Lock, Globe, LifeBuoy,
} from 'lucide-react'
import RaiseTicketModal from '../../helpdesk/components/RaiseTicketModal'
import { taskApi, TASK_STATUS, TASK_PRIORITY, TASK_ACCENT, relLabel, fmtDuration } from '@/services/taskApi'
import Select from '@/components/ui/Select'
import SearchPicker, { ConfirmModal, InputModal } from '@/components/ui/SearchPicker'
import { useAuth } from '@/context/AuthContext'
import { useStatuses, statusOptions } from '@/hooks/useStatuses'
import TaskFormDrawer, { PeopleChips } from '../components/TaskFormDrawer'
import { FilesCard, RemindersCard } from '../components/TaskExtras'

const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

export default function TaskDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const qc = useQueryClient()

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['task', id] })
    qc.invalidateQueries({ queryKey: ['tasks'] })
  }
  const invalidateTime = () => { invalidate(); qc.invalidateQueries({ queryKey: ['task-time', id] }) }

  const { data: task, isLoading, isError, error } = useQuery({ queryKey: ['task', id], queryFn: () => taskApi.get(id) })
  const { data: time } = useQuery({ queryKey: ['task-time', id], queryFn: () => taskApi.totalTime(id) })
  const { data: staff = [] } = useQuery({ queryKey: ['task-staff'], queryFn: taskApi.staff })
  const { map: statusMap, list: statusList } = useStatuses('task')

  const [editing, setEditing] = useState(false)
  const [raising, setRaising] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [picker, setPicker] = useState(null)      // 'assignee' | 'follower' | 'template'
  const [savingTpl, setSavingTpl] = useState(false)
  const [newItem, setNewItem] = useState('')
  const [comment, setComment] = useState('')
  const [actionErr, setActionErr] = useState('')

  const onErr = (e) => setActionErr(e?.message || 'That action failed.')
  const mut = (fn, after = invalidate) => ({ mutationFn: fn, onSuccess: () => { setActionErr(''); after() }, onError: onErr })

  const setStatus   = useMutation(mut((s) => taskApi.setStatus(id, s)))
  const togglePublic = useMutation(mut((next) => taskApi.update(id, { is_public: next })))
  const syncAssign  = useMutation(mut((ids) => taskApi.assignees(id, ids)))
  const syncFollow  = useMutation(mut((ids) => taskApi.followers(id, ids)))
  const addItem     = useMutation(mut((desc) => taskApi.addChecklist(id, desc)))
  const toggleItem  = useMutation(mut((iid) => taskApi.toggleChecklist(iid)))
  const addComment  = useMutation(mut((c) => taskApi.addComment(id, c)))
  const startTimer  = useMutation(mut(() => taskApi.startTimer(id), invalidateTime))
  const stopTimer   = useMutation(mut(() => taskApi.stopTimer(id), invalidateTime))
  const applyTpl    = useMutation(mut((tid) => taskApi.applyTemplate(id, tid)))
  const saveTpl     = useMutation({
    mutationFn: (name) => taskApi.saveChecklistAsTemplate(id, name),
    onSuccess: () => { setActionErr(''); qc.invalidateQueries({ queryKey: ['task-templates'] }) },
    onError: onErr,
  })
  const remove      = useMutation({
    mutationFn: () => taskApi.remove(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['tasks'] }); navigate('/app/tasks') },
    onError: onErr,
  })
  const copy        = useMutation({
    mutationFn: () => taskApi.copy(id, { copy_checklist: true, copy_assignees: true, copy_followers: true }),
    onSuccess: (t) => { qc.invalidateQueries({ queryKey: ['tasks'] }); navigate(`/app/tasks/${t.id}`) },
    onError: onErr,
  })

  const { data: templates = [] } = useQuery({ queryKey: ['task-templates'], queryFn: taskApi.templates })

  if (isLoading) return <div className="rounded-2xl animate-pulse" style={{ height: 200, background: 'var(--bg-card)' }} />
  if (isError) {
    return (
      <div className="p-6 rounded-2xl" style={{ border: '1px solid color-mix(in srgb, var(--color-danger-500) 30%, transparent)', background: 'var(--bg-card)' }}>
        <p className="text-sm" style={{ color: 'var(--color-danger-500)' }}>{error?.message}</p>
        <button onClick={() => navigate('/app/tasks')} className="text-xs mt-3 underline" style={{ color: 'var(--text-muted)' }}>Back to tasks</button>
      </div>
    )
  }

  // Guarded: an unrecognised status/priority used to throw on .color here.
  const st = statusMap[task.status] || { label: task.status, color: 'var(--text-muted)' }
  const pr = TASK_PRIORITY[task.priority] || 'var(--text-muted)'
  const assignees = task.assignees || []
  const followers = task.followers || []
  const checklist = task.checklistItems || []
  const comments = task.comments || []
  const myTimer = (task.timers || []).find(t => !t.end_time && t.user_id === user?.id)
  const link = relLabel(task)
  const done = checklist.filter(c => c.finished).length
  const pct = checklist.length ? Math.round((done / checklist.length) * 100) : 0

  const assigneeIds = assignees.map(a => a.user_id)
  const followerIds = followers.map(f => f.user_id)

  return (
    <div className="max-w-4xl">
      <button onClick={() => navigate('/app/tasks')} className="flex items-center gap-1.5 text-xs mb-4" style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={13} /> Back to tasks
      </button>

      {/* Header */}
      <div className="flex items-start justify-between gap-4 mb-4 flex-wrap">
        <div className="min-w-0">
          <div className="flex items-center gap-1.5 mb-1">
            <span className="w-2 h-2 rounded-full" style={{ background: pr }} />
            <span className="text-[10px] font-black uppercase tracking-wide" style={{ color: pr }}>{task.priority}</span>
          </div>
          <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>{task.name}</h1>
          <p className="text-xs mt-1 flex items-center gap-1.5 flex-wrap" style={{ color: 'var(--text-muted)' }}>
            {link ? (
              <>
                <span>Linked to {link}</span>
                {task.rel_url && (
                  <button onClick={() => navigate(task.rel_url)} className="inline-flex items-center gap-0.5 font-semibold hover:underline" style={{ color: TASK_ACCENT }}>
                    open <ExternalLink size={10} />
                  </button>
                )}
              </>
            ) : <span>Standalone</span>}
            <span>· Due {fmtDate(task.due_date)}</span>
            {task.billable && <span className="px-1.5 py-0.5 rounded-md font-bold" style={{ background: 'color-mix(in srgb, var(--color-success-500) 14%, transparent)', color: 'var(--color-success-500)' }}>Billable</span>}
            {task.recurring && (
              <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md font-bold"
                style={{ background: `color-mix(in srgb, ${TASK_ACCENT} 14%, transparent)`, color: TASK_ACCENT }}>
                <RefreshCw size={9} /> Every {task.repeat_every > 1 ? `${task.repeat_every} ` : ''}{task.recurring_type}
                {task.cycles > 0 && ` · ${task.total_cycles}/${task.cycles}`}
              </span>
            )}
            {task.is_recurring_from && (
              <span className="px-1.5 py-0.5 rounded-md" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
                auto-created from #{task.is_recurring_from}
              </span>
            )}
          </p>
        </div>

        <div className="flex items-center gap-2">
          {(() => {
            const isPublic = Boolean(task.is_public)
            const color = isPublic ? 'var(--color-primary-500)' : 'var(--text-muted)'
            return (
              <button onClick={() => togglePublic.mutate(!isPublic)} disabled={togglePublic.isPending}
                title={isPublic ? 'Make private' : 'Make public'}
                className="flex items-center gap-1.5 text-xs font-semibold px-2.5 py-2 rounded-xl disabled:opacity-40"
                style={{
                  background: isPublic ? `color-mix(in srgb, ${color} 12%, transparent)` : 'var(--bg-input)',
                  border: '1px solid var(--border)', color,
                }}>
                {isPublic ? <Globe size={13} /> : <Lock size={13} />}
                {isPublic ? 'Public' : 'Private'}
              </button>
            )
          })()}
          <div style={{ minWidth: 150 }}>
            <Select value={task.status} onChange={v => setStatus.mutate(v)} ariaLabel="Task status"
              options={statusOptions(statusList, user?.role)}
              buttonStyle={{ borderColor: st.color, color: st.color, fontWeight: 700 }} />
          </div>
          <button onClick={() => setRaising(true)} title="Raise a support ticket for this task"
            className="flex items-center gap-1.5 text-xs font-semibold px-2.5 py-2 rounded-xl"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--color-support-500)' }}>
            <LifeBuoy size={13} /> Raise Ticket
          </button>
          <IconBtn onClick={() => setEditing(true)} label="Edit task"><Pencil size={14} /></IconBtn>
          <IconBtn onClick={() => copy.mutate()} label="Duplicate task"><Copy size={14} /></IconBtn>
          <IconBtn onClick={() => setConfirmDelete(true)} label="Delete task" danger><Trash2 size={14} /></IconBtn>
        </div>
      </div>

      {actionErr && (
        <p className="text-xs px-3 py-2 rounded-lg mb-3"
          style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>{actionErr}</p>
      )}

      <TimerBar total={time?.total_seconds} timer={myTimer}
        onStart={() => startTimer.mutate()} onStop={() => stopTimer.mutate()}
        busy={startTimer.isPending || stopTimer.isPending} />

      <div className="grid gap-4 md:grid-cols-2 mt-4">
        <Card title="People" icon={Users}>
          <p className="text-[10px] font-bold uppercase tracking-wide mb-1.5" style={{ color: 'var(--text-muted)' }}>Assignees</p>
          <PeopleChips ids={assigneeIds} staff={staff} addLabel="Assign"
            onAdd={() => setPicker('assignee')}
            onRemove={uid => syncAssign.mutate(assigneeIds.filter(i => i !== uid))} />

          <p className="text-[10px] font-bold uppercase tracking-wide mt-4 mb-1.5" style={{ color: 'var(--text-muted)' }}>
            <Eye size={10} className="inline mr-1" />Followers
          </p>
          <PeopleChips ids={followerIds} staff={staff} addLabel="Follow"
            onAdd={() => setPicker('follower')}
            onRemove={uid => syncFollow.mutate(followerIds.filter(i => i !== uid))} />
        </Card>

        <Card title={`Checklist${checklist.length ? ` · ${done}/${checklist.length}` : ''}`} icon={CheckSquare}>
          {checklist.length > 0 && (
            <div className="h-1 rounded-full mb-3 overflow-hidden" style={{ background: 'var(--bg-input)' }}>
              <div className="h-full rounded-full transition-all" style={{ width: `${pct}%`, background: 'var(--color-success-500)' }} />
            </div>
          )}
          <ul className="space-y-1.5 mb-3">
            {checklist.map(c => (
              <li key={c.id}>
                <button onClick={() => toggleItem.mutate(c.id)} className="flex items-start gap-2 text-left w-full group">
                  {c.finished
                    ? <CheckSquare size={14} className="mt-0.5 shrink-0" style={{ color: 'var(--color-success-500)' }} />
                    : <Square size={14} className="mt-0.5 shrink-0" style={{ color: 'var(--text-muted)' }} />}
                  <span className="text-xs" style={{ color: c.finished ? 'var(--text-muted)' : 'var(--text-h)', textDecoration: c.finished ? 'line-through' : 'none' }}>
                    {c.description}
                  </span>
                </button>
              </li>
            ))}
            {checklist.length === 0 && <li className="text-xs" style={{ color: 'var(--text-muted)' }}>No items yet.</li>}
          </ul>
          <InlineAdd value={newItem} onChange={setNewItem} placeholder="Add checklist item…"
            onSubmit={() => { const t = newItem.trim(); if (t) { addItem.mutate(t); setNewItem('') } }} icon={Plus} />

          <div className="flex items-center gap-2 mt-2.5 pt-2.5" style={{ borderTop: '1px solid var(--border)' }}>
            <button onClick={() => setPicker('template')} className="flex items-center gap-1 text-[10px] font-bold"
              style={{ color: TASK_ACCENT }}>
              <ListPlus size={11} /> Use template
            </button>
            {checklist.length > 0 && (
              <button onClick={() => setSavingTpl(true)} className="flex items-center gap-1 text-[10px] font-bold ml-auto"
                style={{ color: 'var(--text-muted)' }}>
                <BookmarkPlus size={11} /> Save as template
              </button>
            )}
          </div>
        </Card>
      </div>

      <div className="grid gap-4 md:grid-cols-2 mt-4">
        <FilesCard taskId={id} />
        <RemindersCard taskId={id} staff={staff} currentUserId={user?.id} />
      </div>

      <Card title="Comments" icon={MessageSquare} className="mt-4">
        <ul className="space-y-3 mb-3">
          {comments.map(c => (
            <li key={c.id} className="flex gap-2.5">
              <span className="w-7 h-7 rounded-lg flex items-center justify-center text-[10px] font-black shrink-0"
                style={{ background: `color-mix(in srgb, ${TASK_ACCENT} 14%, transparent)`, color: TASK_ACCENT }}>
                {(c.user?.name || '?').slice(0, 1).toUpperCase()}
              </span>
              <div className="min-w-0">
                <p className="text-xs">
                  <span className="font-bold" style={{ color: 'var(--text-h)' }}>{c.user?.name || 'Unknown'}</span>
                  <span className="ml-2 text-[10px]" style={{ color: 'var(--text-muted)' }}>
                    {new Date(c.created_at).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })}
                  </span>
                </p>
                {/* Plain text, deliberately: comments are typed into a plain input,
                    so rendering them as HTML would be an XSS sink for no benefit. */}
                <p className="text-xs mt-0.5 whitespace-pre-wrap break-words" style={{ color: 'var(--text-body)' }}>{c.content}</p>
              </div>
            </li>
          ))}
          {comments.length === 0 && <li className="text-xs" style={{ color: 'var(--text-muted)' }}>No comments yet.</li>}
        </ul>
        <InlineAdd value={comment} onChange={setComment} placeholder="Write a comment… use @name to notify someone"
          onSubmit={() => { const t = comment.trim(); if (t) { addComment.mutate(t); setComment('') } }} icon={Send} />
      </Card>

      {task.description && (
        <Card title="Description" className="mt-4">
          <p className="text-xs whitespace-pre-wrap break-words leading-relaxed" style={{ color: 'var(--text-body)' }}>
            {task.description}
          </p>
        </Card>
      )}

      <TaskFormDrawer open={editing} onClose={() => setEditing(false)} task={task} onSaved={invalidate} />

      {/* Raise a helpdesk ticket for this task — linked to its project when it has one. */}
      <RaiseTicketModal open={raising} onClose={() => setRaising(false)}
        projectId={task.rel_type === 'project' ? task.rel_id : null}
        defaultSubject={`[Task] ${task.name}`}
        defaultDescription={link ? `Raised from ${link}.` : ''} />

      <ConfirmModal open={confirmDelete} onClose={() => setConfirmDelete(false)} onConfirm={() => remove.mutate()}
        title="Delete this task?" message={`“${task.name}” will be removed from the board. This can't be undone from the UI.`}
        confirmLabel="Delete" danger />

      <SearchPicker
        open={picker === 'assignee'} onClose={() => setPicker(null)}
        onPick={it => it && syncAssign.mutate([...new Set([...assigneeIds, it.id])])}
        items={staff.filter(s => !assigneeIds.includes(s.id)).map(s => ({ id: s.id, label: s.name, sublabel: s.role }))}
        title="Assign to" subtitle="They'll get a notification." emptyText="Everyone is already assigned." accent={TASK_ACCENT}
      />
      <SearchPicker
        open={picker === 'follower'} onClose={() => setPicker(null)}
        onPick={it => it && syncFollow.mutate([...new Set([...followerIds, it.id])])}
        items={staff.filter(s => !followerIds.includes(s.id)).map(s => ({ id: s.id, label: s.name, sublabel: s.role }))}
        title="Add follower" subtitle="Followers get updates but aren't doing the work."
        emptyText="Everyone is already following." accent={TASK_ACCENT}
      />
      <SearchPicker
        open={picker === 'template'} onClose={() => setPicker(null)}
        onPick={it => it && applyTpl.mutate(it.id)}
        items={templates.map(t => ({ id: t.id, label: t.name, sublabel: `${(t.items || []).length} items` }))}
        title="Apply a checklist template" subtitle="Items are added to this task's checklist."
        emptyText="No templates yet — save a checklist as one first." accent={TASK_ACCENT}
      />
      <InputModal
        open={savingTpl} onClose={() => setSavingTpl(false)}
        onSubmit={name => saveTpl.mutate(name)}
        title="Save checklist as template"
        subtitle={`Reuse these ${checklist.length} items on any task.`}
        placeholder="e.g. Code Review Checklist" submitLabel="Save" accent={TASK_ACCENT}
      />
    </div>
  )
}

/* ── Timer ────────────────────────────────────────────────────── */

/**
 * The running timer ticks. It used to render a frozen duration that only moved
 * on refetch, which read as a broken timer.
 */
function TimerBar({ total = 0, timer, onStart, onStop, busy }) {
  const [now, setNow] = useState(() => Date.now())
  const running = Boolean(timer)

  useEffect(() => {
    if (!running) return
    const t = setInterval(() => setNow(Date.now()), 1000)
    return () => clearInterval(t)
  }, [running])

  const live = useMemo(() => {
    if (!running) return Number(total) || 0
    const started = new Date(timer.start_time).getTime()
    return (Number(total) || 0) + Math.max(0, Math.floor((now - started) / 1000))
  }, [running, total, timer, now])

  return (
    <div className="flex items-center gap-3 p-3 rounded-2xl" style={{ border: '1px solid var(--border)', background: 'var(--bg-card)' }}>
      <Clock size={15} style={{ color: running ? 'var(--color-success-500)' : TASK_ACCENT }} />
      <span className="text-sm font-black tabular-nums" style={{ color: 'var(--text-h)' }}>{fmtDuration(live)}</span>
      <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{running ? 'running' : 'logged'}</span>
      {running && <span className="w-1.5 h-1.5 rounded-full animate-pulse" style={{ background: 'var(--color-success-500)' }} />}
      <button onClick={running ? onStop : onStart} disabled={busy}
        className="ml-auto flex items-center gap-1.5 text-xs font-bold px-3 py-1.5 rounded-lg disabled:opacity-40"
        style={running
          ? { background: 'color-mix(in srgb, var(--color-danger-500) 15%, transparent)', color: 'var(--color-danger-500)' }
          : { background: 'color-mix(in srgb, var(--color-success-500) 15%, transparent)', color: 'var(--color-success-500)' }}>
        {running ? <><StopCircle size={13} /> Stop</> : <><Play size={13} /> Start timer</>}
      </button>
    </div>
  )
}

/* ── Bits ─────────────────────────────────────────────────────── */

function InlineAdd({ value, onChange, onSubmit, placeholder, icon: Icon }) {
  const ref = useRef(null)
  return (
    <form onSubmit={e => { e.preventDefault(); onSubmit() }} className="flex gap-2">
      <input ref={ref} value={value} onChange={e => onChange(e.target.value)} placeholder={placeholder}
        className="flex-1 rounded-xl outline-none"
        style={{ padding: '8px 11px', fontSize: 12.5, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
      <button type="submit" disabled={!value.trim()}
        className="px-3 rounded-xl disabled:opacity-30" style={{ background: TASK_ACCENT, color: '#fff' }} aria-label="Add">
        <Icon size={13} />
      </button>
    </form>
  )
}

function IconBtn({ onClick, label, danger, children }) {
  return (
    <button onClick={onClick} aria-label={label} title={label}
      className="w-9 h-9 rounded-xl flex items-center justify-center"
      style={{
        background: 'var(--bg-input)', border: '1px solid var(--border)',
        color: danger ? 'var(--color-danger-500)' : 'var(--text-muted)',
      }}>
      {children}
    </button>
  )
}

function Card({ title, icon: Icon, children, className = '' }) {
  return (
    <section className={`rounded-2xl p-4 ${className}`} style={{ border: '1px solid var(--border)', background: 'var(--bg-card)' }}>
      <h2 className="font-bold text-xs mb-3 flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
        {Icon && <Icon size={14} style={{ color: TASK_ACCENT }} />}{title}
      </h2>
      {children}
    </section>
  )
}
