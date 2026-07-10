import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useParams, useNavigate } from 'react-router-dom'
import { ArrowLeft, RefreshCw, Plus, Users, Flag, Paperclip, Trash2, ListTodo } from 'lucide-react'
import { projectApi } from '@/services/projectApi'
import { PROJECT_STATUS } from './ProjectList'

const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const money = v => v != null ? '₹' + Number(v).toLocaleString('en-IN') : '—'

export default function ProjectDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const invalidate = () => qc.invalidateQueries({ queryKey: ['project', id] })

  const { data: project, isLoading, isError, error } = useQuery({ queryKey: ['project', id], queryFn: () => projectApi.get(id) })
  const { data: prog, refetch: refetchProg } = useQuery({ queryKey: ['project-progress', id], queryFn: () => projectApi.progress(id) })
  const { data: files = [] } = useQuery({ queryKey: ['project-files', id], queryFn: () => projectApi.files(id) })

  const setStatus = useMutation({ mutationFn: (s) => projectApi.setStatus(id, s), onSuccess: invalidate })
  const addMember = useMutation({ mutationFn: (ids) => projectApi.members(id, ids), onSuccess: invalidate })
  const addMilestone = useMutation({ mutationFn: (data) => projectApi.createMilestone(id, data), onSuccess: invalidate })
  const delMilestone = useMutation({ mutationFn: (mid) => projectApi.deleteMilestone(mid), onSuccess: invalidate })
  const uploadFile = useMutation({
    mutationFn: (file) => { const fd = new FormData(); fd.append('file', file); return projectApi.uploadFile(id, fd) },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['project-files', id] }),
  })

  const [memberId, setMemberId] = useState('')
  const [ms, setMs] = useState({ name: '', due_date: '' })

  if (isLoading) return <div className="skeleton h-40 rounded-2xl" style={{ background: 'var(--border)' }} />
  if (isError) return <div className="p-6 rounded-2xl border" style={{ borderColor: 'rgba(239,68,68,0.3)' }}><p className="text-red-400">{error?.message}</p></div>

  const s = PROJECT_STATUS[project.status] || PROJECT_STATUS.not_started
  const members = project.members || []

  return (
    <div className="text-slate-200 max-w-4xl">
      <button onClick={() => navigate('/app/projects')} className="flex items-center gap-1.5 text-xs mb-4" style={{ color: 'var(--text-muted)' }}><ArrowLeft size={13} /> Projects</button>

      <div className="flex items-start justify-between gap-4 mb-5">
        <div>
          <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>{project.name}</h1>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{project.customer?.name || 'No customer'} · created by {project.creator?.name || '—'}</p>
        </div>
        <div className="flex items-center gap-2">
          <button onClick={() => navigate(`/app/projects/${id}/tasks`)} className="flex items-center gap-1.5 text-xs font-semibold px-3 py-2 rounded-xl" style={{ background: 'rgba(236,72,153,0.15)', color: '#ec4899' }}><ListTodo size={13} /> Tasks</button>
          <select value={project.status} onChange={e => setStatus.mutate(e.target.value)} className="text-xs font-bold px-3 py-2 rounded-xl border bg-transparent" style={{ borderColor: s.color, color: s.color }}>
            {Object.entries(PROJECT_STATUS).map(([k, v]) => <option key={k} value={k} style={{ color: '#000' }}>{v.label}</option>)}
          </select>
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-3 mb-5">
        <Stat label="Progress" value={`${prog?.progress ?? project.progress}%`} extra={<button onClick={() => refetchProg()} title="Recalc from tasks" className="ml-1"><RefreshCw size={11} style={{ color: 'var(--text-muted)' }} /></button>} sub={prog?.source === 'tasks' ? `${prog.completed_tasks}/${prog.total_tasks} tasks done` : 'manual'} />
        <Stat label="Start → Deadline" value={fmtDate(project.start_date)} sub={`→ ${fmtDate(project.deadline)}`} />
        <Stat label="Billing" value={project.billing_type.replace('_', ' ')} sub={project.billing_type === 'fixed' ? money(project.project_cost) : project.billing_type === 'project_hours' ? money(project.rate_per_hour) + '/h' : 'per task'} />
      </div>

      {project.description && (
        <Card title="Description"><div className="prose prose-invert prose-sm max-w-none" style={{ color: 'var(--text-muted)' }} dangerouslySetInnerHTML={{ __html: project.description }} /></Card>
      )}

      <div className="grid gap-4 md:grid-cols-2 mt-4">
        {/* Members */}
        <Card title="Members" icon={Users}>
          <div className="flex flex-wrap gap-2 mb-3">
            {members.map(m => <span key={m.id} className="text-xs px-2 py-1 rounded-lg" style={{ background: 'rgba(236,72,153,0.12)', color: '#f9a8d4' }}>{m.user?.name}</span>)}
            {members.length === 0 && <span className="text-xs" style={{ color: 'var(--text-muted)' }}>No members yet.</span>}
          </div>
          <div className="flex gap-2">
            <input value={memberId} onChange={e => setMemberId(e.target.value)} placeholder="user id" className="flex-1 text-xs bg-transparent border rounded-lg px-2 py-1.5 outline-none" style={{ borderColor: 'var(--border)', color: 'var(--text-h)' }} />
            <button onClick={() => { const ids = [...members.map(m => m.user_id), Number(memberId)].filter(Boolean); addMember.mutate([...new Set(ids)]); setMemberId('') }} className="text-xs px-3 rounded-lg" style={{ background: 'rgba(236,72,153,0.15)', color: '#ec4899' }}>Add</button>
          </div>
        </Card>

        {/* Files */}
        <Card title="Files" icon={Paperclip}>
          <ul className="space-y-1 mb-3">
            {files.map(f => (
              <li key={f.id} className="flex items-center gap-2 text-xs" style={{ color: 'var(--text-muted)' }}>
                <Paperclip size={12} /><span className="flex-1 truncate">{f.original_name}</span>
                {f.visible_to_customer && <span className="text-[9px] px-1.5 py-0.5 rounded" style={{ background: 'rgba(16,185,129,0.12)', color: '#10b981' }}>client</span>}
              </li>
            ))}
            {files.length === 0 && <li className="text-xs" style={{ color: 'var(--text-muted)' }}>No files.</li>}
          </ul>
          <label className="text-xs cursor-pointer inline-flex items-center gap-1.5" style={{ color: '#ec4899' }}>
            <Plus size={13} /> {uploadFile.isPending ? 'Uploading…' : 'Upload file'}
            <input type="file" className="hidden" onChange={e => e.target.files[0] && uploadFile.mutate(e.target.files[0])} />
          </label>
        </Card>
      </div>

      {/* Milestones */}
      <Card title="Milestones" icon={Flag} className="mt-4">
        <ul className="space-y-2 mb-3">
          {(project.milestones || []).map(m => (
            <li key={m.id} className="flex items-center gap-2 text-sm">
              <span className="w-2 h-2 rounded-full" style={{ background: m.color || '#ec4899' }} />
              <span className="flex-1" style={{ color: 'var(--text-h)' }}>{m.name}</span>
              <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{fmtDate(m.due_date)}</span>
              <button onClick={() => delMilestone.mutate(m.id)} className="opacity-50 hover:opacity-100 hover:text-red-400"><Trash2 size={12} /></button>
            </li>
          ))}
          {(project.milestones || []).length === 0 && <li className="text-xs" style={{ color: 'var(--text-muted)' }}>No milestones.</li>}
        </ul>
        <div className="flex gap-2">
          <input value={ms.name} onChange={e => setMs({ ...ms, name: e.target.value })} placeholder="Milestone name" className="flex-1 text-xs bg-transparent border rounded-lg px-2 py-1.5 outline-none" style={{ borderColor: 'var(--border)', color: 'var(--text-h)' }} />
          <input type="date" value={ms.due_date} onChange={e => setMs({ ...ms, due_date: e.target.value })} className="text-xs bg-transparent border rounded-lg px-2 py-1.5 outline-none" style={{ borderColor: 'var(--border)', color: 'var(--text-h)' }} />
          <button disabled={!ms.name || !ms.due_date} onClick={() => { addMilestone.mutate(ms); setMs({ name: '', due_date: '' }) }} className="text-xs px-3 rounded-lg disabled:opacity-40" style={{ background: 'rgba(236,72,153,0.15)', color: '#ec4899' }}>Add</button>
        </div>
      </Card>
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
function Card({ title, icon: Icon, children, className = '' }) {
  return (
    <div className={`rounded-2xl border p-5 ${className}`} style={{ borderColor: 'var(--border)', background: 'var(--bg-card)' }}>
      <h2 className="font-semibold text-sm mb-3 flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>{Icon && <Icon size={15} style={{ color: '#ec4899' }} />}{title}</h2>
      {children}
    </div>
  )
}
