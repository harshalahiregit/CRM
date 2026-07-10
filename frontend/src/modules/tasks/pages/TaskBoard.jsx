import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { ListTodo, Plus, X, LayoutGrid, List } from 'lucide-react'
import { taskApi, TASK_STATUS, TASK_PRIORITY } from '@/services/taskApi'

const EMPTY = { name: '', description: '', priority: 'medium', start_date: new Date().toISOString().split('T')[0], due_date: '', rel_type: 'standalone', rel_id: '' }

export default function TaskBoard() {
  const navigate = useNavigate()
  const qc = useQueryClient()
  const [params] = useSearchParams()
  const relType = params.get('rel_type') || undefined
  const relId = params.get('rel_id') || undefined

  const [view, setView] = useState('kanban')
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({ ...EMPTY, rel_type: relType || 'standalone', rel_id: relId || '' })

  const filters = relType ? { rel_type: relType, rel_id: relId } : {}
  const { data: tasks = [], isLoading } = useQuery({ queryKey: ['tasks', relType, relId], queryFn: () => taskApi.list(filters) })

  const create = useMutation({
    mutationFn: () => { const p = { ...form }; Object.keys(p).forEach(k => p[k] === '' && delete p[k]); return taskApi.create(p) },
    onSuccess: () => { setShowForm(false); setForm({ ...EMPTY, rel_type: relType || 'standalone', rel_id: relId || '' }); qc.invalidateQueries({ queryKey: ['tasks'] }) },
  })
  const move = useMutation({ mutationFn: ({ id, status }) => taskApi.setStatus(id, status), onSuccess: () => qc.invalidateQueries({ queryKey: ['tasks'] }) })
  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  return (
    <div className="text-slate-200">
      <div className="flex items-center justify-between mb-5">
        <div className="flex items-center gap-2">
          <ListTodo size={20} style={{ color: '#ec4899' }} />
          <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Tasks</h1>
          {relType && <span className="text-xs px-2 py-0.5 rounded-lg" style={{ background: 'rgba(236,72,153,0.12)', color: '#f9a8d4' }}>{relType} #{relId}</span>}
        </div>
        <div className="flex items-center gap-2">
          <div className="flex rounded-xl border overflow-hidden" style={{ borderColor: 'var(--border)' }}>
            <button onClick={() => setView('kanban')} className="px-2.5 py-2" style={{ background: view === 'kanban' ? 'rgba(236,72,153,0.15)' : 'transparent', color: view === 'kanban' ? '#ec4899' : 'var(--text-muted)' }}><LayoutGrid size={14} /></button>
            <button onClick={() => setView('list')} className="px-2.5 py-2" style={{ background: view === 'list' ? 'rgba(236,72,153,0.15)' : 'transparent', color: view === 'list' ? '#ec4899' : 'var(--text-muted)' }}><List size={14} /></button>
          </div>
          <button onClick={() => setShowForm(true)} className="flex items-center gap-1.5 text-xs font-semibold px-3 py-2 rounded-xl" style={{ background: 'linear-gradient(135deg,#ec4899,#be185d)', color: '#fff' }}><Plus size={14} /> New Task</button>
        </div>
      </div>

      {isLoading && <div className="skeleton h-40 rounded-2xl" style={{ background: 'var(--border)' }} />}

      {/* Kanban */}
      {!isLoading && view === 'kanban' && (
        <div className="grid gap-3 md:grid-cols-5 items-start">
          {Object.entries(TASK_STATUS).map(([key, meta]) => {
            const col = tasks.filter(t => t.status === key)
            return (
              <div key={key} className="rounded-2xl border p-2.5" style={{ borderColor: 'var(--border)', background: 'var(--bg-card)' }}>
                <div className="flex items-center gap-1.5 mb-2 px-1">
                  <span className="w-2 h-2 rounded-full" style={{ background: meta.color }} />
                  <span className="text-xs font-semibold" style={{ color: 'var(--text-h)' }}>{meta.label}</span>
                  <span className="ml-auto text-[10px]" style={{ color: 'var(--text-muted)' }}>{col.length}</span>
                </div>
                <div className="space-y-2">
                  {col.map(t => (
                    <div key={t.id} className="rounded-xl p-2.5 border cursor-pointer hover:bg-white/[0.03]" style={{ borderColor: 'var(--border)', background: 'var(--bg-global)' }} onClick={() => navigate(`/app/tasks/${t.id}`)}>
                      <div className="flex items-start gap-1.5">
                        <span className="w-1.5 h-1.5 rounded-full mt-1.5 shrink-0" style={{ background: TASK_PRIORITY[t.priority] }} />
                        <p className="text-xs flex-1" style={{ color: 'var(--text-h)' }}>{t.name}</p>
                      </div>
                      <select value={t.status} onClick={e => e.stopPropagation()} onChange={e => move.mutate({ id: t.id, status: e.target.value })} className="mt-2 w-full text-[10px] bg-transparent border rounded px-1 py-0.5" style={{ borderColor: 'var(--border)', color: 'var(--text-muted)' }}>
                        {Object.entries(TASK_STATUS).map(([k, v]) => <option key={k} value={k} style={{ color: '#000' }}>{v.label}</option>)}
                      </select>
                    </div>
                  ))}
                  {col.length === 0 && <p className="text-[10px] px-1 py-2" style={{ color: 'var(--text-muted)' }}>—</p>}
                </div>
              </div>
            )
          })}
        </div>
      )}

      {/* List */}
      {!isLoading && view === 'list' && (
        <div className="overflow-x-auto rounded-2xl border" style={{ borderColor: 'var(--border)', background: 'var(--bg-card)' }}>
          <table className="w-full text-sm">
            <thead><tr className="text-left text-[11px] uppercase tracking-wide border-b" style={{ color: 'var(--text-muted)', borderColor: 'var(--border)' }}><th className="px-4 py-3">Task</th><th className="px-4 py-3">Priority</th><th className="px-4 py-3">Status</th><th className="px-4 py-3">Linked</th><th className="px-4 py-3">Due</th></tr></thead>
            <tbody>
              {tasks.length === 0 && <tr><td colSpan={5} className="px-4 py-10 text-center" style={{ color: 'var(--text-muted)' }}>No tasks.</td></tr>}
              {tasks.map(t => (
                <tr key={t.id} onClick={() => navigate(`/app/tasks/${t.id}`)} className="border-b last:border-0 hover:bg-white/[0.03] cursor-pointer" style={{ borderColor: 'var(--border)' }}>
                  <td className="px-4 py-3" style={{ color: 'var(--text-h)' }}>{t.name}</td>
                  <td className="px-4 py-3"><span className="text-[10px] font-bold capitalize px-2 py-0.5 rounded-lg" style={{ background: `${TASK_PRIORITY[t.priority]}1a`, color: TASK_PRIORITY[t.priority] }}>{t.priority}</span></td>
                  <td className="px-4 py-3"><span className="text-[10px] px-2 py-0.5 rounded-lg" style={{ background: `${TASK_STATUS[t.status].color}1a`, color: TASK_STATUS[t.status].color }}>{TASK_STATUS[t.status].label}</span></td>
                  <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{t.rel_type === 'standalone' ? '—' : `${t.rel_type} #${t.rel_id}`}</td>
                  <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{t.due_date ? new Date(t.due_date).toLocaleDateString('en-IN', { day: '2-digit', month: 'short' }) : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Create drawer */}
      {showForm && (
        <div className="fixed inset-0 z-50 flex justify-end" style={{ background: 'rgba(0,0,0,0.5)' }} onClick={() => setShowForm(false)}>
          <div className="w-full max-w-md h-full overflow-y-auto p-5" style={{ background: 'var(--bg-global)' }} onClick={e => e.stopPropagation()}>
            <div className="flex items-center justify-between mb-4"><h2 className="font-bold" style={{ color: 'var(--text-h)' }}>New Task</h2><button onClick={() => setShowForm(false)}><X size={18} style={{ color: 'var(--text-muted)' }} /></button></div>
            <div className="space-y-3">
              <F label="Name *"><input value={form.name} onChange={e => sf('name', e.target.value)} className={inp} /></F>
              <F label="Description"><textarea value={form.description} onChange={e => sf('description', e.target.value)} rows={2} className={inp} /></F>
              <div className="grid grid-cols-2 gap-3">
                <F label="Priority"><select value={form.priority} onChange={e => sf('priority', e.target.value)} className={inp}>{Object.keys(TASK_PRIORITY).map(p => <option key={p} value={p}>{p}</option>)}</select></F>
                <F label="Due date"><input type="date" value={form.due_date} onChange={e => sf('due_date', e.target.value)} className={inp} /></F>
              </div>
              <F label="Start date *"><input type="date" value={form.start_date} onChange={e => sf('start_date', e.target.value)} className={inp} /></F>
              <div className="grid grid-cols-2 gap-3">
                <F label="Linked to"><select value={form.rel_type} onChange={e => sf('rel_type', e.target.value)} className={inp}><option value="standalone">Standalone</option><option value="project">Project</option><option value="ticket">Ticket</option><option value="customer">Customer</option></select></F>
                {form.rel_type !== 'standalone' && <F label={`${form.rel_type} id`}><input value={form.rel_id} onChange={e => sf('rel_id', e.target.value)} className={inp} /></F>}
              </div>
              {create.isError && <p className="text-xs text-red-400">{create.error?.message}</p>}
              <button disabled={!form.name || create.isPending} onClick={() => create.mutate()} className="w-full py-2.5 rounded-xl text-sm font-semibold disabled:opacity-40" style={{ background: 'linear-gradient(135deg,#ec4899,#be185d)', color: '#fff' }}>{create.isPending ? 'Creating…' : 'Create Task'}</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

const inp = 'w-full text-sm bg-transparent border rounded-lg px-3 py-2 outline-none'
function F({ label, children }) {
  return <label className="block"><span className="text-[11px] font-semibold uppercase tracking-wide block mb-1" style={{ color: 'var(--text-muted)' }}>{label}</span><span className="[&_input]:border-[color:var(--border)] [&_select]:border-[color:var(--border)] [&_textarea]:border-[color:var(--border)] [&_*]:text-[color:var(--text-h)]">{children}</span></label>
}
