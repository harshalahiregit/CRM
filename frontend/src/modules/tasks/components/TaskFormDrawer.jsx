import { useState, useEffect, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { X, Check, Link2, IndianRupee } from 'lucide-react'
import { taskApi, TASK_STATUS, TASK_PRIORITY, TASK_ACCENT, REL_TYPES, RECURRING_TYPES } from '@/services/taskApi'
import Select from '@/components/ui/Select'
import SearchPicker from '@/components/ui/SearchPicker'
import TagInput from '@/components/ui/TagInput'
import { projectApi } from '@/services/projectApi'
import { helpdeskApi } from '@/services/helpdeskApi'
import { tagApi } from '@/services/tagApi'

/**
 * Create/edit a task. One drawer serves both — before this, taskApi.update had no
 * call site at all, so a task's name, dates and link were uneditable once saved.
 *
 * The "what is this linked to?" and "who does it?" fields are name-based pickers,
 * not raw id inputs: you should never have to look an id up to fill a form.
 */

const today = () => new Date().toISOString().split('T')[0]
const EMPTY = {
  name: '', description: '', priority: 'medium', status: '',
  start_date: today(), due_date: '', rel_type: 'standalone', rel_id: '',
  billable: false, hourly_rate: '', is_public: false, visible_to_client: false,
  assignee_ids: [], tags: [],
  recurring: false, recurring_type: 'month', repeat_every: 1, cycles: 0,
}

export default function TaskFormDrawer({ open, onClose, task = null, defaults = {}, onSaved }) {
  const qc = useQueryClient()
  const editing = Boolean(task)
  const [form, setForm] = useState(EMPTY)
  const [picker, setPicker] = useState(null)   // 'rel' | 'assignee'
  const [err, setErr] = useState('')

  useEffect(() => {
    if (!open) return
    setErr('')
    setForm(editing
      ? {
          ...EMPTY, ...task,
          due_date: task.due_date ? String(task.due_date).split('T')[0] : '',
          start_date: task.start_date ? String(task.start_date).split('T')[0] : today(),
          hourly_rate: task.hourly_rate ?? '',
          rel_id: task.rel_id ?? '',
          assignee_ids: (task.assignees || []).map(a => a.user_id),
          tags: (task.tags || []).map(t => t.name),
        }
      : { ...EMPTY, ...defaults })
  }, [open, task, editing]) // eslint-disable-line react-hooks/exhaustive-deps

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  const { data: staff = [] } = useQuery({ queryKey: ['task-staff'], queryFn: taskApi.staff, enabled: open })
  const { data: tagSuggestions = [] } = useQuery({ queryKey: ['tags', 'task'], queryFn: () => tagApi.list('task'), enabled: open })
  const { data: projects = [], isLoading: pLoading } = useQuery({
    queryKey: ['projects-picker'], queryFn: () => projectApi.list(), enabled: picker === 'rel' && form.rel_type === 'project',
  })
  const { data: tickets = [], isLoading: tLoading } = useQuery({
    queryKey: ['tickets-picker'], queryFn: () => helpdeskApi.tickets.list(), enabled: picker === 'rel' && form.rel_type === 'ticket',
  })

  // Both list endpoints may hand back either a bare array or a paginated envelope.
  const rows = (x) => (Array.isArray(x) ? x : x?.data ?? [])

  const relItems = useMemo(() => {
    if (form.rel_type === 'project') return rows(projects).map(p => ({ id: p.id, label: p.name, sublabel: p.status }))
    if (form.rel_type === 'ticket') return rows(tickets).map(t => ({ id: t.id, label: t.subject, sublabel: `#${t.id} · ${t.status}` }))
    return []
  }, [form.rel_type, projects, tickets])

  const relName = useMemo(() => {
    if (form.rel_type === 'standalone' || !form.rel_id) return ''
    const hit = relItems.find(i => String(i.id) === String(form.rel_id))
    return hit?.label || task?.rel_label || `${form.rel_type} #${form.rel_id}`
  }, [form.rel_type, form.rel_id, relItems, task])

  const save = useMutation({
    mutationFn: async (payload) => {
      if (editing) {
        const t = await taskApi.update(task.id, payload)
        // Assignees are a separate pivot endpoint, not a task column.
        await taskApi.assignees(task.id, payload.assignee_ids || [])
        return t
      }
      return taskApi.create(payload)
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['tasks'] })
      qc.invalidateQueries({ queryKey: ['tags', 'task'] })
      if (editing) qc.invalidateQueries({ queryKey: ['task', String(task.id)] })
      onSaved?.()
      onClose?.()
    },
    onError: (e) => setErr(e?.message || 'Could not save the task.'),
  })

  const submit = (e) => {
    e?.preventDefault?.()
    setErr('')
    const p = { ...form }
    // Standalone tasks carry no link; sending a stale rel_id would 422.
    if (p.rel_type === 'standalone') { delete p.rel_id }
    if (!p.status) delete p.status
    if (p.hourly_rate === '') delete p.hourly_rate
    if (p.due_date === '') delete p.due_date
    if (editing) delete p.assignees
    Object.keys(p).forEach(k => p[k] === null && delete p[k])
    save.mutate(p)
  }

  if (!open) return null
  const busy = save.isPending

  return (
    <div className="fixed inset-0 z-[55] flex justify-end" style={{ background: 'rgba(0,0,0,0.5)' }} onClick={onClose}>
      <form onSubmit={submit} onClick={e => e.stopPropagation()}
        className="w-full max-w-md h-full overflow-y-auto flex flex-col"
        style={{ background: 'var(--bg-global)', borderLeft: '1px solid var(--border)' }}>

        <header className="flex items-center justify-between px-5 py-4 sticky top-0 z-10"
          style={{ background: 'var(--bg-global)', borderBottom: '1px solid var(--border)' }}>
          <h2 className="font-bold" style={{ color: 'var(--text-h)', fontSize: 15 }}>{editing ? 'Edit Task' : 'New Task'}</h2>
          <button type="button" onClick={onClose} aria-label="Close"><X size={17} style={{ color: 'var(--text-muted)' }} /></button>
        </header>

        <div className="p-5 space-y-3.5 flex-1">
          <Field label="Name" required>
            <input value={form.name} onChange={e => sf('name', e.target.value)} className={INPUT} style={INPUT_S} autoFocus />
          </Field>

          <Field label="Description">
            <textarea value={form.description || ''} onChange={e => sf('description', e.target.value)} rows={3} className={INPUT} style={INPUT_S} />
          </Field>

          <div className="grid grid-cols-2 gap-3">
            <Field label="Priority">
              <Select value={form.priority} onChange={v => sf('priority', v)}
                options={Object.entries(TASK_PRIORITY).map(([v, c]) => ({ value: v, label: v[0].toUpperCase() + v.slice(1), dot: c }))} />
            </Field>
            <Field label="Status">
              <Select value={form.status} onChange={v => sf('status', v)} placeholder="Auto"
                options={[{ value: '', label: 'Auto (from start date)' },
                  ...Object.entries(TASK_STATUS).map(([v, m]) => ({ value: v, label: m.label, dot: m.color }))]} />
            </Field>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <Field label="Start date" required>
              <input type="date" value={form.start_date} onChange={e => sf('start_date', e.target.value)} className={INPUT} style={INPUT_S} />
            </Field>
            <Field label="Due date">
              <input type="date" value={form.due_date || ''} onChange={e => sf('due_date', e.target.value)} className={INPUT} style={INPUT_S} />
            </Field>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <Field label="Linked to">
              <Select value={form.rel_type} onChange={v => setForm(p => ({ ...p, rel_type: v, rel_id: '' }))} options={REL_TYPES} />
            </Field>
            {form.rel_type !== 'standalone' && (
              <Field label={form.rel_type}>
                <button type="button" onClick={() => setPicker('rel')}
                  className="w-full flex items-center gap-2 rounded-xl text-left"
                  style={{ ...INPUT_S, padding: '9px 12px', fontSize: 13 }}>
                  <Link2 size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
                  <span className="truncate" style={{ color: relName ? 'var(--text-h)' : 'var(--text-muted)' }}>
                    {relName || `Choose ${form.rel_type}…`}
                  </span>
                </button>
              </Field>
            )}
          </div>

          <Field label="Assignees">
            <PeopleChips ids={form.assignee_ids} staff={staff}
              onRemove={id => sf('assignee_ids', form.assignee_ids.filter(i => i !== id))}
              onAdd={() => setPicker('assignee')} />
          </Field>

          <Field label="Tags">
            <TagInput value={form.tags} onChange={v => sf('tags', v)} suggestions={tagSuggestions} accent={TASK_ACCENT} />
          </Field>

          <div className="rounded-xl p-3 space-y-2.5" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
            <Toggle label="Billable" hint="Time logged can be invoiced" checked={!!form.billable} onChange={v => sf('billable', v)} />
            {form.billable && (
              <Field label="Hourly rate">
                <div className="relative">
                  <IndianRupee size={13} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
                  <input type="number" min="0" step="0.01" value={form.hourly_rate}
                    onChange={e => sf('hourly_rate', e.target.value)} className={INPUT}
                    style={{ ...INPUT_S, paddingLeft: 30 }} placeholder="0.00" />
                </div>
              </Field>
            )}
            <Toggle label="Public" hint="Visible to all staff" checked={!!form.is_public} onChange={v => sf('is_public', v)} />
            <Toggle label="Visible to client" hint="Shows in the customer portal" checked={!!form.visible_to_client} onChange={v => sf('visible_to_client', v)} />
          </div>

          <div className="rounded-xl p-3 space-y-2.5" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
            <Toggle label="Repeat this task" hint="A copy is created automatically each cycle"
              checked={!!form.recurring} onChange={v => sf('recurring', v)} />
            {form.recurring && (
              <>
                <div className="grid grid-cols-2 gap-3">
                  <Field label="Repeat every">
                    <input type="number" min="1" max="365" value={form.repeat_every}
                      onChange={e => sf('repeat_every', Number(e.target.value) || 1)} className={INPUT} style={INPUT_S} />
                  </Field>
                  <Field label="Unit">
                    <Select value={form.recurring_type} onChange={v => sf('recurring_type', v)} options={RECURRING_TYPES} />
                  </Field>
                </div>
                <Field label="Stop after">
                  <input type="number" min="0" max="1000" value={form.cycles}
                    onChange={e => sf('cycles', Number(e.target.value) || 0)} className={INPUT} style={INPUT_S} placeholder="0" />
                </Field>
                <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
                  {form.cycles > 0
                    ? `Creates ${form.cycles} cop${form.cycles === 1 ? 'y' : 'ies'}, then stops.`
                    : 'Set 0 to repeat forever. Each copy is an independent task.'}
                </p>
              </>
            )}
          </div>

          {err && (
            <p className="text-xs px-3 py-2 rounded-lg"
              style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>{err}</p>
          )}
        </div>

        <footer className="p-5 sticky bottom-0" style={{ background: 'var(--bg-global)', borderTop: '1px solid var(--border)' }}>
          <button type="submit" disabled={!form.name.trim() || busy}
            className="w-full flex items-center justify-center gap-1.5 py-2.5 rounded-xl text-sm font-bold disabled:opacity-40"
            style={{ background: TASK_ACCENT, color: '#fff' }}>
            <Check size={15} /> {busy ? 'Saving…' : editing ? 'Save changes' : 'Create task'}
          </button>
        </footer>
      </form>

      <SearchPicker
        open={picker === 'rel'} onClose={() => setPicker(null)}
        onPick={it => sf('rel_id', it ? it.id : '')}
        items={relItems} loading={pLoading || tLoading}
        title={`Link to a ${form.rel_type}`}
        subtitle="Search by name — you don't need the id."
        emptyText={`No ${form.rel_type}s found.`}
        accent={TASK_ACCENT} allowClear
      />
      <SearchPicker
        open={picker === 'assignee'} onClose={() => setPicker(null)}
        onPick={it => it && !form.assignee_ids.includes(it.id) && sf('assignee_ids', [...form.assignee_ids, it.id])}
        items={staff.filter(s => !form.assignee_ids.includes(s.id)).map(s => ({ id: s.id, label: s.name, sublabel: s.role }))}
        title="Assign to" subtitle="Only staff can be assigned." emptyText="Everyone is already assigned."
        accent={TASK_ACCENT}
      />
    </div>
  )
}

/* ── Bits ─────────────────────────────────────────────────────── */

const INPUT = 'w-full rounded-xl outline-none'
const INPUT_S = { padding: '9px 12px', fontSize: 13, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }

export function Field({ label, required, children }) {
  return (
    <label className="block">
      <span className="text-[10px] font-bold uppercase tracking-wide block mb-1.5" style={{ color: 'var(--text-muted)' }}>
        {label}{required && <span style={{ color: 'var(--color-danger-500)' }}> *</span>}
      </span>
      {children}
    </label>
  )
}

function Toggle({ label, hint, checked, onChange }) {
  return (
    <button type="button" onClick={() => onChange(!checked)} className="w-full flex items-center gap-2.5 text-left">
      <span className="w-8 h-[18px] rounded-full relative transition-colors shrink-0"
        style={{ background: checked ? TASK_ACCENT : 'var(--border)' }}>
        <span className="absolute w-3.5 h-3.5 rounded-full top-[2px] transition-all"
          style={{ background: '#fff', left: checked ? 16 : 2 }} />
      </span>
      <span className="flex-1 min-w-0">
        <span className="block text-xs font-semibold" style={{ color: 'var(--text-h)' }}>{label}</span>
        {hint && <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{hint}</span>}
      </span>
    </button>
  )
}

export function PeopleChips({ ids = [], staff = [], onRemove, onAdd, addLabel = 'Add' }) {
  const byId = useMemo(() => Object.fromEntries(staff.map(s => [s.id, s])), [staff])
  return (
    <div className="flex flex-wrap items-center gap-1.5">
      {ids.map(id => (
        <span key={id} className="flex items-center gap-1 text-[11px] font-semibold px-2 py-1 rounded-lg"
          style={{ background: `color-mix(in srgb, ${TASK_ACCENT} 12%, transparent)`, color: TASK_ACCENT }}>
          {byId[id]?.name || `#${id}`}
          <button type="button" onClick={() => onRemove(id)} className="hover:opacity-60" aria-label={`Remove ${byId[id]?.name || id}`}>
            <X size={11} />
          </button>
        </span>
      ))}
      <button type="button" onClick={onAdd} className="text-[11px] font-bold px-2 py-1 rounded-lg"
        style={{ border: '1px dashed var(--border)', color: 'var(--text-muted)' }}>+ {addLabel}</button>
    </div>
  )
}
