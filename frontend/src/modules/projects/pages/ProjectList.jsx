import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { FolderKanban, Plus, X } from 'lucide-react'
import { projectApi } from '@/services/projectApi'

export const PROJECT_STATUS = {
  not_started: { label: 'Not Started', bg: 'rgba(100,116,139,0.12)', color: '#94a3b8' },
  in_progress: { label: 'In Progress', bg: 'rgba(59,130,246,0.12)', color: '#3b82f6' },
  on_hold:     { label: 'On Hold',     bg: 'rgba(245,158,11,0.12)', color: '#fbbf24' },
  finished:    { label: 'Finished',    bg: 'rgba(16,185,129,0.12)', color: '#10b981' },
  cancelled:   { label: 'Cancelled',   bg: 'rgba(239,68,68,0.12)',  color: '#f87171' },
}
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const money = v => v != null ? '₹' + Number(v).toLocaleString('en-IN') : '—'

const EMPTY = { name: '', description: '', status: 'not_started', customer_id: '', billing_type: 'fixed', project_cost: '', rate_per_hour: '', start_date: new Date().toISOString().split('T')[0], deadline: '', estimated_hours: '' }

export default function ProjectList() {
  const navigate = useNavigate()
  const qc = useQueryClient()
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState(EMPTY)

  const { data: projects = [], isLoading, isError, error } = useQuery({ queryKey: ['projects'], queryFn: () => projectApi.list() })

  const create = useMutation({
    mutationFn: () => {
      const payload = { ...form }
      Object.keys(payload).forEach(k => payload[k] === '' && delete payload[k])
      return projectApi.create(payload)
    },
    onSuccess: () => { setShowForm(false); setForm(EMPTY); qc.invalidateQueries({ queryKey: ['projects'] }) },
  })
  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  return (
    <div className="text-slate-200">
      <div className="flex items-center justify-between mb-5">
        <div className="flex items-center gap-2">
          <FolderKanban size={20} style={{ color: '#ec4899' }} />
          <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Projects</h1>
          {!isLoading && !isError && <span className="ml-2 text-xs" style={{ color: 'var(--text-muted)' }}>{projects.length}</span>}
        </div>
        <button onClick={() => setShowForm(true)} className="flex items-center gap-1.5 text-xs font-semibold px-3 py-2 rounded-xl" style={{ background: 'linear-gradient(135deg,#ec4899,#be185d)', color: '#fff' }}>
          <Plus size={14} /> New Project
        </button>
      </div>

      {isError && <div className="p-6 rounded-2xl border" style={{ borderColor: 'rgba(239,68,68,0.3)', background: 'rgba(239,68,68,0.06)' }}><p className="text-red-400 font-semibold">Couldn’t load projects</p><p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>{error?.message}</p></div>}

      {!isError && (
        <div className="overflow-x-auto rounded-2xl border" style={{ borderColor: 'var(--border)', background: 'var(--bg-card)' }}>
          <table className="w-full text-sm">
            <thead><tr className="text-left text-[11px] uppercase tracking-wide border-b" style={{ color: 'var(--text-muted)', borderColor: 'var(--border)' }}>
              <th className="px-4 py-3">Project</th><th className="px-4 py-3">Status</th><th className="px-4 py-3">Customer</th><th className="px-4 py-3">Progress</th><th className="px-4 py-3">Deadline</th><th className="px-4 py-3">Cost</th>
            </tr></thead>
            <tbody>
              {isLoading && [1, 2, 3].map(i => <tr key={i} className="border-b" style={{ borderColor: 'var(--border)' }}>{[...Array(6)].map((_, j) => <td key={j} className="px-4 py-3"><div className="skeleton h-4 rounded" style={{ background: 'var(--border)' }} /></td>)}</tr>)}
              {!isLoading && projects.length === 0 && <tr><td colSpan={6} className="px-4 py-10 text-center" style={{ color: 'var(--text-muted)' }}>No projects yet.</td></tr>}
              {!isLoading && projects.map(p => {
                const s = PROJECT_STATUS[p.status] || PROJECT_STATUS.not_started
                return (
                  <tr key={p.id} onClick={() => navigate(`/app/projects/${p.id}`)} className="border-b last:border-0 hover:bg-white/[0.03] cursor-pointer" style={{ borderColor: 'var(--border)' }}>
                    <td className="px-4 py-3 font-medium" style={{ color: 'var(--text-h)' }}>{p.name}</td>
                    <td className="px-4 py-3"><span className="px-2.5 py-0.5 rounded-xl text-[10px] font-bold" style={{ background: s.bg, color: s.color }}>{s.label}</span></td>
                    <td className="px-4 py-3" style={{ color: 'var(--text-muted)' }}>{p.customer?.name || '—'}</td>
                    <td className="px-4 py-3" style={{ color: 'var(--text-muted)' }}>
                      <div className="flex items-center gap-2"><div className="w-16 h-1.5 rounded-full overflow-hidden" style={{ background: 'var(--border)' }}><div className="h-full rounded-full" style={{ width: `${p.progress}%`, background: '#ec4899' }} /></div>{p.progress}%</div>
                    </td>
                    <td className="px-4 py-3" style={{ color: 'var(--text-muted)' }}>{fmtDate(p.deadline)}</td>
                    <td className="px-4 py-3" style={{ color: 'var(--text-muted)' }}>{p.billing_type === 'fixed' ? money(p.project_cost) : p.billing_type === 'project_hours' ? money(p.rate_per_hour) + '/h' : 'task hrs'}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      {/* Create drawer */}
      {showForm && (
        <div className="fixed inset-0 z-50 flex justify-end" style={{ background: 'rgba(0,0,0,0.5)' }} onClick={() => setShowForm(false)}>
          <div className="w-full max-w-md h-full overflow-y-auto p-5" style={{ background: 'var(--bg-global)' }} onClick={e => e.stopPropagation()}>
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-bold" style={{ color: 'var(--text-h)' }}>New Project</h2>
              <button onClick={() => setShowForm(false)}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
            </div>
            <div className="space-y-3">
              <Field label="Name *"><input value={form.name} onChange={e => sf('name', e.target.value)} className={inp} /></Field>
              <Field label="Description"><textarea value={form.description} onChange={e => sf('description', e.target.value)} rows={2} className={inp} /></Field>
              <div className="grid grid-cols-2 gap-3">
                <Field label="Status"><select value={form.status} onChange={e => sf('status', e.target.value)} className={inp}>{Object.entries(PROJECT_STATUS).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}</select></Field>
                <Field label="Customer ID"><input value={form.customer_id} onChange={e => sf('customer_id', e.target.value)} placeholder="e.g. 2 (mock)" className={inp} /></Field>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <Field label="Billing"><select value={form.billing_type} onChange={e => sf('billing_type', e.target.value)} className={inp}><option value="fixed">Fixed</option><option value="project_hours">Project hours</option><option value="task_hours">Task hours</option></select></Field>
                {form.billing_type === 'fixed'
                  ? <Field label="Project cost"><input value={form.project_cost} onChange={e => sf('project_cost', e.target.value)} className={inp} /></Field>
                  : <Field label="Rate / hour"><input value={form.rate_per_hour} onChange={e => sf('rate_per_hour', e.target.value)} className={inp} /></Field>}
              </div>
              <div className="grid grid-cols-2 gap-3">
                <Field label="Start date *"><input type="date" value={form.start_date} onChange={e => sf('start_date', e.target.value)} className={inp} /></Field>
                <Field label="Deadline"><input type="date" value={form.deadline} onChange={e => sf('deadline', e.target.value)} className={inp} /></Field>
              </div>
              <Field label="Estimated hours"><input value={form.estimated_hours} onChange={e => sf('estimated_hours', e.target.value)} className={inp} /></Field>
              {create.isError && <p className="text-xs text-red-400">{create.error?.message}</p>}
              <button disabled={!form.name || create.isPending} onClick={() => create.mutate()} className="w-full py-2.5 rounded-xl text-sm font-semibold disabled:opacity-40" style={{ background: 'linear-gradient(135deg,#ec4899,#be185d)', color: '#fff' }}>
                {create.isPending ? 'Creating…' : 'Create Project'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

const inp = 'w-full text-sm bg-transparent border rounded-lg px-3 py-2 outline-none'
function Field({ label, children }) {
  return <label className="block"><span className="text-[11px] font-semibold uppercase tracking-wide block mb-1" style={{ color: 'var(--text-muted)' }}>{label}</span><span className="[&_*]:border-[color:var(--border)] [&_*]:text-[color:var(--text-h)]">{children}</span></label>
}
