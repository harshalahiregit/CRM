import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useParams, useNavigate } from 'react-router-dom'
import { ArrowLeft, Users, CheckSquare, Square, MessageSquare, Play, StopCircle, Clock } from 'lucide-react'
import { taskApi, TASK_STATUS, TASK_PRIORITY } from '@/services/taskApi'
import { useAuth } from '@/context/AuthContext'

const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const fmtDur = s => { s = Number(s) || 0; const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60); return `${h}h ${m}m` }

export default function TaskDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const qc = useQueryClient()
  const invalidate = () => qc.invalidateQueries({ queryKey: ['task', id] })

  const { data: task, isLoading, isError, error } = useQuery({ queryKey: ['task', id], queryFn: () => taskApi.get(id) })
  const { data: time } = useQuery({ queryKey: ['task-time', id], queryFn: () => taskApi.totalTime(id) })

  const setStatus = useMutation({ mutationFn: (s) => taskApi.setStatus(id, s), onSuccess: invalidate })
  const addAssignee = useMutation({ mutationFn: (ids) => taskApi.assignees(id, ids), onSuccess: invalidate })
  const addItem = useMutation({ mutationFn: (desc) => taskApi.addChecklist(id, desc), onSuccess: invalidate })
  const toggleItem = useMutation({ mutationFn: (iid) => taskApi.toggleChecklist(iid), onSuccess: invalidate })
  const addComment = useMutation({ mutationFn: (c) => taskApi.addComment(id, c), onSuccess: invalidate })
  const startTimer = useMutation({ mutationFn: () => taskApi.startTimer(id), onSuccess: () => { invalidate(); qc.invalidateQueries({ queryKey: ['task-time', id] }) } })
  const stopTimer = useMutation({ mutationFn: () => taskApi.stopTimer(id), onSuccess: () => { invalidate(); qc.invalidateQueries({ queryKey: ['task-time', id] }) } })

  const [assigneeId, setAssigneeId] = useState('')
  const [newItem, setNewItem] = useState('')
  const [comment, setComment] = useState('')

  if (isLoading) return <div className="skeleton h-40 rounded-2xl" style={{ background: 'var(--border)' }} />
  if (isError) return <div className="p-6 rounded-2xl border" style={{ borderColor: 'rgba(239,68,68,0.3)' }}><p className="text-red-400">{error?.message}</p></div>

  const st = TASK_STATUS[task.status]
  const assignees = task.assignees || []
  const checklist = task.checklistItems || []
  const comments = task.comments || []
  const running = (task.timers || []).some(t => !t.end_time && t.user_id === user?.id)
  const relLink = task.rel_type === 'project' ? `/app/projects/${task.rel_id}` : task.rel_type === 'ticket' ? `/app/helpdesk/tickets/${task.rel_id}` : null
  const doneCount = checklist.filter(c => c.finished).length

  return (
    <div className="text-slate-200 max-w-3xl">
      <button onClick={() => navigate(-1)} className="flex items-center gap-1.5 text-xs mb-4" style={{ color: 'var(--text-muted)' }}><ArrowLeft size={13} /> Back</button>

      <div className="flex items-start justify-between gap-4 mb-5">
        <div>
          <div className="flex items-center gap-2 mb-1"><span className="w-2.5 h-2.5 rounded-full" style={{ background: TASK_PRIORITY[task.priority] }} /><span className="text-xs font-bold uppercase" style={{ color: TASK_PRIORITY[task.priority] }}>{task.priority}</span></div>
          <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>{task.name}</h1>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            {task.rel_type === 'standalone' ? 'Standalone' : <>Linked to {task.rel_type} #{task.rel_id}{relLink && <button onClick={() => navigate(relLink)} className="ml-1 underline" style={{ color: '#ec4899' }}>open</button>}</>}
            {task.customer?.name && ` · ${task.customer.name}`}
          </p>
        </div>
        <select value={task.status} onChange={e => setStatus.mutate(e.target.value)} className="text-xs font-bold px-3 py-2 rounded-xl border bg-transparent" style={{ borderColor: st.color, color: st.color }}>
          {Object.entries(TASK_STATUS).map(([k, v]) => <option key={k} value={k} style={{ color: '#000' }}>{v.label}</option>)}
        </select>
      </div>

      {/* Timer bar */}
      <div className="flex items-center gap-3 mb-5 p-3 rounded-2xl border" style={{ borderColor: 'var(--border)', background: 'var(--bg-card)' }}>
        <Clock size={16} style={{ color: '#ec4899' }} />
        <span className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{fmtDur(time?.total_seconds)}</span>
        <span className="text-xs" style={{ color: 'var(--text-muted)' }}>logged</span>
        <div className="ml-auto">
          {running
            ? <button onClick={() => stopTimer.mutate()} className="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-lg" style={{ background: 'rgba(239,68,68,0.15)', color: '#f87171' }}><StopCircle size={13} /> Stop</button>
            : <button onClick={() => startTimer.mutate()} className="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-lg" style={{ background: 'rgba(16,185,129,0.15)', color: '#10b981' }}><Play size={13} /> Start timer</button>}
        </div>
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        {/* Assignees */}
        <Card title="Assignees" icon={Users}>
          <div className="flex flex-wrap gap-2 mb-3">
            {assignees.map(a => <span key={a.id} className="text-xs px-2 py-1 rounded-lg" style={{ background: 'rgba(236,72,153,0.12)', color: '#f9a8d4' }}>{a.user?.name}</span>)}
            {assignees.length === 0 && <span className="text-xs" style={{ color: 'var(--text-muted)' }}>Unassigned.</span>}
          </div>
          <div className="flex gap-2">
            <input value={assigneeId} onChange={e => setAssigneeId(e.target.value)} placeholder="user id" className={inp} />
            <button onClick={() => { const ids = [...assignees.map(a => a.user_id), Number(assigneeId)].filter(Boolean); addAssignee.mutate([...new Set(ids)]); setAssigneeId('') }} className={btn}>Add</button>
          </div>
        </Card>

        {/* Checklist */}
        <Card title={`Checklist ${checklist.length ? `(${doneCount}/${checklist.length})` : ''}`} icon={CheckSquare}>
          <ul className="space-y-1 mb-3">
            {checklist.map(c => (
              <li key={c.id} className="flex items-center gap-2 text-sm cursor-pointer" onClick={() => toggleItem.mutate(c.id)}>
                {c.finished ? <CheckSquare size={15} style={{ color: '#10b981' }} /> : <Square size={15} style={{ color: 'var(--text-muted)' }} />}
                <span style={{ color: c.finished ? 'var(--text-muted)' : 'var(--text-h)', textDecoration: c.finished ? 'line-through' : 'none' }}>{c.description}</span>
              </li>
            ))}
            {checklist.length === 0 && <li className="text-xs" style={{ color: 'var(--text-muted)' }}>No items.</li>}
          </ul>
          <div className="flex gap-2">
            <input value={newItem} onChange={e => setNewItem(e.target.value)} placeholder="Add item…" className={inp} onKeyDown={e => e.key === 'Enter' && newItem && (addItem.mutate(newItem), setNewItem(''))} />
            <button onClick={() => { if (newItem) { addItem.mutate(newItem); setNewItem('') } }} className={btn}>Add</button>
          </div>
        </Card>
      </div>

      {/* Comments */}
      <Card title="Comments" icon={MessageSquare} className="mt-4">
        <div className="space-y-3 mb-3">
          {comments.map(c => (
            <div key={c.id} className="text-sm">
              <span className="font-semibold text-xs" style={{ color: 'var(--text-h)' }}>{c.user?.name}</span>
              <span className="text-[10px] ml-2" style={{ color: 'var(--text-muted)' }}>{new Date(c.created_at).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })}</span>
              <p style={{ color: 'var(--text-muted)' }}>{c.content}</p>
            </div>
          ))}
          {comments.length === 0 && <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No comments yet.</p>}
        </div>
        <div className="flex gap-2">
          <input value={comment} onChange={e => setComment(e.target.value)} placeholder="Write a comment…" className={inp} onKeyDown={e => e.key === 'Enter' && comment && (addComment.mutate(comment), setComment(''))} />
          <button onClick={() => { if (comment) { addComment.mutate(comment); setComment('') } }} className={btn}>Send</button>
        </div>
      </Card>

      {task.description && (
        <Card title="Description" className="mt-4"><div className="prose prose-invert prose-sm max-w-none" style={{ color: 'var(--text-muted)' }} dangerouslySetInnerHTML={{ __html: task.description }} /></Card>
      )}
    </div>
  )
}

const inp = 'flex-1 text-xs bg-transparent border rounded-lg px-2 py-1.5 outline-none border-[color:var(--border)] text-[color:var(--text-h)]'
const btn = 'text-xs px-3 rounded-lg bg-[rgba(236,72,153,0.15)] text-[#ec4899]'
function Card({ title, icon: Icon, children, className = '' }) {
  return (
    <div className={`rounded-2xl border p-5 ${className}`} style={{ borderColor: 'var(--border)', background: 'var(--bg-card)' }}>
      <h2 className="font-semibold text-sm mb-3 flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>{Icon && <Icon size={15} style={{ color: '#ec4899' }} />}{title}</h2>
      {children}
    </div>
  )
}
