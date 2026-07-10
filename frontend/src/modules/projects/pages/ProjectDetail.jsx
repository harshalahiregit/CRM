import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useParams, useNavigate } from 'react-router-dom'
import { ArrowLeft, RefreshCw } from 'lucide-react'
import { projectApi } from '@/services/projectApi'
import { PROJECT_STATUS } from './ProjectList'

const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const money = v => v != null ? '₹' + Number(v).toLocaleString('en-IN') : '—'

export default function ProjectDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const qc = useQueryClient()

  const { data: project, isLoading, isError, error } = useQuery({ queryKey: ['project', id], queryFn: () => projectApi.get(id) })
  const { data: prog, refetch: refetchProg } = useQuery({ queryKey: ['project-progress', id], queryFn: () => projectApi.progress(id) })

  const setStatus = useMutation({
    mutationFn: (status) => projectApi.setStatus(id, status),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['project', id] }),
  })

  if (isLoading) return <div className="skeleton h-40 rounded-2xl" style={{ background: 'var(--border)' }} />
  if (isError) return <div className="p-6 rounded-2xl border" style={{ borderColor: 'rgba(239,68,68,0.3)' }}><p className="text-red-400">{error?.message}</p></div>

  const s = PROJECT_STATUS[project.status] || PROJECT_STATUS.not_started

  return (
    <div className="text-slate-200 max-w-4xl">
      <button onClick={() => navigate('/app/projects')} className="flex items-center gap-1.5 text-xs mb-4" style={{ color: 'var(--text-muted)' }}><ArrowLeft size={13} /> Projects</button>

      <div className="flex items-start justify-between gap-4 mb-5">
        <div>
          <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>{project.name}</h1>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            {project.customer?.name || 'No customer'} · created by {project.creator?.name || '—'}
          </p>
        </div>
        <select value={project.status} onChange={e => setStatus.mutate(e.target.value)} className="text-xs font-bold px-3 py-2 rounded-xl border bg-transparent" style={{ borderColor: s.color, color: s.color }}>
          {Object.entries(PROJECT_STATUS).map(([k, v]) => <option key={k} value={k} style={{ color: '#000' }}>{v.label}</option>)}
        </select>
      </div>

      <div className="grid gap-4 sm:grid-cols-3 mb-5">
        <Stat label="Progress" value={`${prog?.progress ?? project.progress}%`} extra={
          <button onClick={() => refetchProg()} title="Recalculate from tasks" className="ml-1"><RefreshCw size={11} style={{ color: 'var(--text-muted)' }} /></button>
        } sub={prog?.source === 'tasks' ? `${prog.completed_tasks}/${prog.total_tasks} tasks done` : 'manual'} />
        <Stat label="Start → Deadline" value={`${fmtDate(project.start_date)}`} sub={`→ ${fmtDate(project.deadline)}`} />
        <Stat label="Billing" value={project.billing_type.replace('_', ' ')} sub={project.billing_type === 'fixed' ? money(project.project_cost) : project.billing_type === 'project_hours' ? money(project.rate_per_hour) + '/h' : 'per task'} />
      </div>

      <div className="rounded-2xl border p-5" style={{ borderColor: 'var(--border)', background: 'var(--bg-card)' }}>
        <h2 className="font-semibold text-sm mb-3" style={{ color: 'var(--text-h)' }}>Description</h2>
        {project.description
          ? <div className="prose prose-invert prose-sm max-w-none" style={{ color: 'var(--text-muted)' }} dangerouslySetInnerHTML={{ __html: project.description }} />
          : <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No description.</p>}
      </div>

      {/* Members / Milestones / Files land here in Step 2; Tasks tab in Step 5 */}
    </div>
  )
}

function Stat({ label, value, sub, extra }) {
  return (
    <div className="rounded-2xl border p-4" style={{ borderColor: 'var(--border)', background: 'var(--bg-card)' }}>
      <p className="text-[11px] uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>{label}</p>
      <p className="text-lg font-black mt-1 capitalize flex items-center" style={{ color: 'var(--text-h)' }}>{value}{extra}</p>
      {sub && <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{sub}</p>}
    </div>
  )
}
