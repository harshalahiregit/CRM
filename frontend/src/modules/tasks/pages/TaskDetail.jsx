import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useParams, useNavigate } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'
import { taskApi, TASK_STATUS, TASK_PRIORITY } from '@/services/taskApi'

const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

export default function TaskDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const qc = useQueryClient()

  const { data: task, isLoading, isError, error } = useQuery({ queryKey: ['task', id], queryFn: () => taskApi.get(id) })
  const setStatus = useMutation({ mutationFn: (s) => taskApi.setStatus(id, s), onSuccess: () => qc.invalidateQueries({ queryKey: ['task', id] }) })

  if (isLoading) return <div className="skeleton h-40 rounded-2xl" style={{ background: 'var(--border)' }} />
  if (isError) return <div className="p-6 rounded-2xl border" style={{ borderColor: 'rgba(239,68,68,0.3)' }}><p className="text-red-400">{error?.message}</p></div>

  const st = TASK_STATUS[task.status]
  const relLink = task.rel_type === 'project' ? `/app/projects/${task.rel_id}` : task.rel_type === 'ticket' ? `/app/helpdesk/tickets/${task.rel_id}` : null

  return (
    <div className="text-slate-200 max-w-3xl">
      <button onClick={() => navigate(-1)} className="flex items-center gap-1.5 text-xs mb-4" style={{ color: 'var(--text-muted)' }}><ArrowLeft size={13} /> Back</button>

      <div className="flex items-start justify-between gap-4 mb-5">
        <div>
          <div className="flex items-center gap-2 mb-1">
            <span className="w-2.5 h-2.5 rounded-full" style={{ background: TASK_PRIORITY[task.priority] }} />
            <span className="text-xs font-bold uppercase" style={{ color: TASK_PRIORITY[task.priority] }}>{task.priority}</span>
          </div>
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

      <div className="grid gap-4 sm:grid-cols-3 mb-5">
        <Stat label="Start → Due" value={fmtDate(task.start_date)} sub={`→ ${fmtDate(task.due_date)}`} />
        <Stat label="Billable" value={task.billable ? 'Yes' : 'No'} sub={task.billed ? 'billed' : task.billable ? `₹${task.hourly_rate || 0}/h` : '—'} />
        <Stat label="Visibility" value={task.is_public ? 'All staff' : 'Assignees'} sub={task.visible_to_client ? 'client-visible' : 'internal'} />
      </div>

      {task.description && (
        <div className="rounded-2xl border p-5" style={{ borderColor: 'var(--border)', background: 'var(--bg-card)' }}>
          <h2 className="font-semibold text-sm mb-3" style={{ color: 'var(--text-h)' }}>Description</h2>
          <div className="prose prose-invert prose-sm max-w-none" style={{ color: 'var(--text-muted)' }} dangerouslySetInnerHTML={{ __html: task.description }} />
        </div>
      )}

      {/* Assignees / checklist / comments / timer land here in Step 4 */}
    </div>
  )
}

function Stat({ label, value, sub }) {
  return (
    <div className="rounded-2xl border p-4" style={{ borderColor: 'var(--border)', background: 'var(--bg-card)' }}>
      <p className="text-[11px] uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>{label}</p>
      <p className="text-lg font-black mt-1" style={{ color: 'var(--text-h)' }}>{value}</p>
      {sub && <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{sub}</p>}
    </div>
  )
}
