import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { ListChecks, Plus, Loader2, CheckCircle2, Circle, Clock } from 'lucide-react'
import { taskApi } from '@/services/taskApi'
import EmptyState from '@/components/ui/EmptyState'
import { useToast } from '@/hooks/useToast'

const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

const PRIORITY = {
  low:    { label: 'Low',    color: '#94a3b8' },
  medium: { label: 'Medium', color: '#3b82f6' },
  high:   { label: 'High',   color: '#f59e0b' },
  urgent: { label: 'Urgent', color: '#ef4444' },
}
const DONE = new Set(['complete', 'completed', 'done'])
const BLANK = { name: '', priority: 'medium', due_date: '', assigned_to: '' }

/**
 * Tasks linked to this lead.
 *
 * No new backend: tasks already accept `rel_type: 'lead'` and the list endpoint
 * already filters on rel_type + rel_id, so this is the existing Tasks module
 * scoped to one lead rather than a parallel implementation.
 */
export default function LeadTasksTab({ leadId }) {
  const navigate = useNavigate()
  const toast = useToast()
  const [rows, setRows] = useState(null)
  const [staff, setStaff] = useState([])
  const [adding, setAdding] = useState(false)
  const [form, setForm] = useState(BLANK)
  const [saving, setSaving] = useState(false)
  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  const load = useCallback(() => {
    taskApi.list({ rel_type: 'lead', rel_id: leadId })
      .then(d => setRows(Array.isArray(d) ? d : (d?.data ?? [])))
      .catch(e => { toast.error(e.message); setRows([]) })
  }, [leadId])
  useEffect(() => { load() }, [load])
  useEffect(() => { taskApi.staff().then(setStaff).catch(() => setStaff([])) }, [])

  const save = async () => {
    if (!form.name.trim()) return toast.error('Give the task a name')
    setSaving(true)
    try {
      await taskApi.create({
        name: form.name.trim(),
        priority: form.priority,
        due_date: form.due_date || undefined,
        assigned_to: form.assigned_to || undefined,
        rel_type: 'lead',
        rel_id: Number(leadId),
      })
      toast.success('Task created')
      setForm(BLANK); setAdding(false); load()
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  const open = (rows || []).filter(t => !DONE.has(String(t.status || '').toLowerCase()))

  return (
    <div className="card-3d" style={{ padding: '20px' }}>
      <div className="flex items-center justify-between mb-4">
        <h3 className="font-bold text-sm flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
          <ListChecks size={14} style={{ color: 'var(--accent)' }} /> Tasks
          {!!rows?.length && (
            <span className="text-[11px] font-semibold" style={{ color: 'var(--text-muted)' }}>
              {open.length} open of {rows.length}
            </span>
          )}
        </h3>
        <button onClick={() => setAdding(a => !a)}
          className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold"
          style={{ background: 'rgba(124,58,237,0.1)', color: 'var(--accent)', border: '1px solid var(--border)' }}>
          <Plus size={12} /> New task
        </button>
      </div>

      {adding && (
        <div className="rounded-xl p-3 mb-4 space-y-2" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <input className="input-3d text-sm" placeholder="Task, e.g. Send revised quote"
            value={form.name} onChange={e => sf('name', e.target.value)}
            onKeyDown={e => e.key === 'Enter' && save()} />
          <div className="grid grid-cols-3 gap-2">
            <div>
              <label className="label">Priority</label>
              <select className="input-3d text-sm" value={form.priority} onChange={e => sf('priority', e.target.value)}>
                {Object.entries(PRIORITY).map(([k, p]) => <option key={k} value={k}>{p.label}</option>)}
              </select>
            </div>
            <div>
              <label className="label">Due</label>
              <input type="date" className="input-3d text-sm" value={form.due_date} onChange={e => sf('due_date', e.target.value)} />
            </div>
            <div>
              <label className="label">Assign to</label>
              <select className="input-3d text-sm" value={form.assigned_to} onChange={e => sf('assigned_to', e.target.value)}>
                <option value="">Unassigned</option>
                {staff.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
            </div>
          </div>
          <div className="flex justify-end gap-2">
            <button onClick={() => { setAdding(false); setForm(BLANK) }}
              className="px-3 py-1.5 rounded-lg text-xs font-semibold"
              style={{ background: 'var(--bg-card)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
            <button onClick={save} disabled={saving}
              className="px-4 py-1.5 rounded-lg text-xs font-bold text-white inline-flex items-center gap-1.5 disabled:opacity-60"
              style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
              {saving && <Loader2 size={11} className="animate-spin" />} Create
            </button>
          </div>
        </div>
      )}

      {rows === null ? (
        <div className="skeleton h-20 rounded-xl" style={{ background: 'var(--border)' }} />
      ) : !rows.length ? (
        <EmptyState icon={ListChecks} title="No tasks" description="Work items linked to this lead will appear here." />
      ) : (
        <div className="space-y-2">
          {rows.map(t => {
            const done = DONE.has(String(t.status || '').toLowerCase())
            const pr = PRIORITY[String(t.priority || '').toLowerCase()] || PRIORITY.medium
            const overdue = !done && t.due_date && new Date(t.due_date) < new Date().setHours(0, 0, 0, 0)
            return (
              <button key={t.id} onClick={() => navigate(`/app/tasks/${t.id}`)}
                className="w-full flex items-center gap-3 px-3 py-2 rounded-xl text-left transition-colors hover:bg-[rgba(124,58,237,0.04)]"
                style={{ border: '1px solid var(--border)', background: 'var(--bg-card)', opacity: done ? 0.65 : 1 }}>
                {done
                  ? <CheckCircle2 size={14} className="flex-shrink-0" style={{ color: '#10b981' }} />
                  : <Circle size={14} className="flex-shrink-0" style={{ color: 'var(--text-muted)' }} />}
                <div className="min-w-0 flex-1">
                  <p className="text-xs font-bold truncate"
                    style={{ color: 'var(--text-h)', textDecoration: done ? 'line-through' : 'none' }}>{t.name}</p>
                  <p className="text-[11px] flex items-center gap-1.5 flex-wrap" style={{ color: 'var(--text-muted)' }}>
                    <span style={{ color: pr.color }}>{pr.label}</span>
                    {t.due_date && (
                      <span className="inline-flex items-center gap-1" style={overdue ? { color: '#f87171' } : undefined}>
                        <Clock size={9} /> {fmtDate(t.due_date)}{overdue ? ' · overdue' : ''}
                      </span>
                    )}
                    {t.status && <span>· {t.status}</span>}
                  </p>
                </div>
              </button>
            )
          })}
        </div>
      )}
    </div>
  )
}
