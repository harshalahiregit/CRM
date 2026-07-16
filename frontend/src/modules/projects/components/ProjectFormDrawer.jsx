import { useState, useEffect, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { X, Check, Building2, IndianRupee } from 'lucide-react'
import { projectApi, PROJECT_STATUS, BILLING_TYPES, PROJECT_ACCENT } from '@/services/projectApi'
import { tagApi } from '@/services/tagApi'
import Select from '@/components/ui/Select'
import SearchPicker from '@/components/ui/SearchPicker'
import TagInput from '@/components/ui/TagInput'

/**
 * Create/edit a project. One drawer serves both — projectApi.update had no call
 * site at all before this, so a project was uneditable once created.
 *
 * Customer and members are name pickers, not raw id inputs (the old form asked
 * for `e.g. 2 (mock)`).
 */

const today = () => new Date().toISOString().split('T')[0]
const EMPTY = {
  name: '', description: '', status: 'not_started', customer_id: '',
  billing_type: 'fixed', project_cost: '', rate_per_hour: '',
  start_date: today(), deadline: '', estimated_hours: '',
  progress: 0, progress_from_tasks: true,
  member_ids: [], tags: [],
}

export default function ProjectFormDrawer({ open, onClose, project = null, onSaved }) {
  const qc = useQueryClient()
  const editing = Boolean(project)
  const [form, setForm] = useState(EMPTY)
  const [picker, setPicker] = useState(null)   // 'customer' | 'member'
  const [err, setErr] = useState('')

  useEffect(() => {
    if (!open) return
    setErr('')
    setForm(editing
      ? {
          ...EMPTY, ...project,
          start_date: project.start_date ? String(project.start_date).split('T')[0] : today(),
          deadline: project.deadline ? String(project.deadline).split('T')[0] : '',
          customer_id: project.customer_id ?? '',
          project_cost: project.project_cost ?? '',
          rate_per_hour: project.rate_per_hour ?? '',
          estimated_hours: project.estimated_hours ?? '',
          member_ids: (project.members || []).map(m => m.user_id),
          tags: (project.tags || []).map(t => t.name),
        }
      : EMPTY)
  }, [open, project, editing]) // eslint-disable-line react-hooks/exhaustive-deps

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  const { data: staff = [] } = useQuery({ queryKey: ['project-staff'], queryFn: projectApi.staff, enabled: open })
  const { data: customers = [], isLoading: cLoading } = useQuery({
    queryKey: ['project-customers'], queryFn: projectApi.customers, enabled: picker === 'customer',
  })
  const { data: tagSuggestions = [] } = useQuery({ queryKey: ['tags', 'project'], queryFn: () => tagApi.list('project'), enabled: open })

  const staffById = useMemo(() => Object.fromEntries(staff.map(s => [s.id, s])), [staff])
  const customerName = useMemo(() => {
    if (!form.customer_id) return ''
    const hit = customers.find(c => String(c.id) === String(form.customer_id))
    return hit?.name || project?.customer?.name || `Customer #${form.customer_id}`
  }, [form.customer_id, customers, project])

  const save = useMutation({
    mutationFn: (payload) => editing ? projectApi.update(project.id, payload) : projectApi.create(payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['projects'] })
      qc.invalidateQueries({ queryKey: ['tags', 'project'] })
      if (editing) qc.invalidateQueries({ queryKey: ['project', String(project.id)] })
      onSaved?.()
      onClose?.()
    },
    onError: (e) => setErr(e?.message || 'Could not save the project.'),
  })

  const submit = (e) => {
    e?.preventDefault?.()
    setErr('')
    const p = { ...form }
    // Only the relevant money field for the chosen billing type.
    if (p.billing_type === 'fixed') delete p.rate_per_hour
    else delete p.project_cost
    // Progress is server-computed when it's driven by tasks.
    if (p.progress_from_tasks) delete p.progress
    ;['customer_id', 'deadline', 'project_cost', 'rate_per_hour', 'estimated_hours'].forEach(k => {
      if (p[k] === '' || p[k] === null) delete p[k]
    })
    delete p.members; delete p.milestones; delete p.customer; delete p.creator
    delete p.is_pinned; delete p.id; delete p.tenant_id
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
          <h2 className="font-bold" style={{ color: 'var(--text-h)', fontSize: 15 }}>{editing ? 'Edit Project' : 'New Project'}</h2>
          <button type="button" onClick={onClose} aria-label="Close"><X size={17} style={{ color: 'var(--text-muted)' }} /></button>
        </header>

        <div className="p-5 space-y-3.5 flex-1">
          <Field label="Project name" required>
            <input value={form.name} onChange={e => sf('name', e.target.value)} className={INPUT} style={INPUT_S} autoFocus />
          </Field>

          <Field label="Customer">
            <button type="button" onClick={() => setPicker('customer')}
              className="w-full flex items-center gap-2 rounded-xl text-left"
              style={{ ...INPUT_S, padding: '9px 12px', fontSize: 13 }}>
              <Building2 size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
              <span className="truncate" style={{ color: customerName ? 'var(--text-h)' : 'var(--text-muted)' }}>
                {customerName || 'Choose customer…'}
              </span>
            </button>
          </Field>

          <Field label="Description">
            <textarea value={form.description || ''} onChange={e => sf('description', e.target.value)} rows={3} className={INPUT} style={INPUT_S} />
          </Field>

          <div className="grid grid-cols-2 gap-3">
            <Field label="Status">
              <Select value={form.status} onChange={v => sf('status', v)}
                options={Object.entries(PROJECT_STATUS).map(([v, m]) => ({ value: v, label: m.label, dot: m.color }))} />
            </Field>
            <Field label="Billing type">
              <Select value={form.billing_type} onChange={v => sf('billing_type', v)} options={BILLING_TYPES} />
            </Field>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <Field label={form.billing_type === 'fixed' ? 'Total rate' : 'Rate / hour'}>
              <div className="relative">
                <IndianRupee size={13} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
                <input type="number" min="0" step="0.01"
                  value={form.billing_type === 'fixed' ? form.project_cost : form.rate_per_hour}
                  onChange={e => sf(form.billing_type === 'fixed' ? 'project_cost' : 'rate_per_hour', e.target.value)}
                  className={INPUT} style={{ ...INPUT_S, paddingLeft: 30 }} placeholder="0.00" />
              </div>
            </Field>
            <Field label="Estimated hours">
              <input type="number" min="0" step="0.5" value={form.estimated_hours}
                onChange={e => sf('estimated_hours', e.target.value)} className={INPUT} style={INPUT_S} placeholder="0" />
            </Field>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <Field label="Start date" required>
              <input type="date" value={form.start_date} onChange={e => sf('start_date', e.target.value)} className={INPUT} style={INPUT_S} />
            </Field>
            <Field label="Deadline">
              <input type="date" value={form.deadline} onChange={e => sf('deadline', e.target.value)} className={INPUT} style={INPUT_S} />
            </Field>
          </div>

          <Field label="Members">
            <div className="flex flex-wrap items-center gap-1.5">
              {form.member_ids.map(id => (
                <span key={id} className="flex items-center gap-1 text-[11px] font-semibold px-2 py-1 rounded-lg"
                  style={{ background: `color-mix(in srgb, ${PROJECT_ACCENT} 12%, transparent)`, color: PROJECT_ACCENT }}>
                  {staffById[id]?.name || `#${id}`}
                  <button type="button" onClick={() => sf('member_ids', form.member_ids.filter(i => i !== id))}
                    className="hover:opacity-60" aria-label="Remove member"><X size={11} /></button>
                </span>
              ))}
              <button type="button" onClick={() => setPicker('member')} className="text-[11px] font-bold px-2 py-1 rounded-lg"
                style={{ border: '1px dashed var(--border)', color: 'var(--text-muted)' }}>+ Add</button>
            </div>
          </Field>

          <Field label="Tags">
            <TagInput value={form.tags} onChange={v => sf('tags', v)} suggestions={tagSuggestions} accent={PROJECT_ACCENT} />
          </Field>

          <div className="rounded-xl p-3 space-y-2.5" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
            <Toggle label="Calculate progress from tasks" hint="Progress = completed tasks ÷ total tasks"
              checked={!!form.progress_from_tasks} onChange={v => sf('progress_from_tasks', v)} />
            {!form.progress_from_tasks && (
              <Field label={`Progress — ${form.progress}%`}>
                <input type="range" min="0" max="100" value={form.progress}
                  onChange={e => sf('progress', Number(e.target.value))} className="w-full"
                  style={{ accentColor: PROJECT_ACCENT }} />
              </Field>
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
            style={{ background: PROJECT_ACCENT, color: '#fff' }}>
            <Check size={15} /> {busy ? 'Saving…' : editing ? 'Save changes' : 'Create project'}
          </button>
        </footer>
      </form>

      <SearchPicker
        open={picker === 'customer'} onClose={() => setPicker(null)}
        onPick={it => sf('customer_id', it ? it.id : '')}
        items={customers.map(c => ({ id: c.id, label: c.name, sublabel: c.email }))}
        loading={cLoading} title="Choose a customer" subtitle="Search by name — you don't need the id."
        emptyText="No customers found." accent={PROJECT_ACCENT} allowClear
      />
      <SearchPicker
        open={picker === 'member'} onClose={() => setPicker(null)}
        onPick={it => it && !form.member_ids.includes(it.id) && sf('member_ids', [...form.member_ids, it.id])}
        items={staff.filter(s => !form.member_ids.includes(s.id)).map(s => ({ id: s.id, label: s.name, sublabel: s.role }))}
        title="Add a member" subtitle="They'll be notified. Only staff can be members."
        emptyText="Everyone is already a member." accent={PROJECT_ACCENT}
      />
    </div>
  )
}

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
        style={{ background: checked ? PROJECT_ACCENT : 'var(--border)' }}>
        <span className="absolute w-3.5 h-3.5 rounded-full top-[2px] transition-all" style={{ background: '#fff', left: checked ? 16 : 2 }} />
      </span>
      <span className="flex-1 min-w-0">
        <span className="block text-xs font-semibold" style={{ color: 'var(--text-h)' }}>{label}</span>
        {hint && <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{hint}</span>}
      </span>
    </button>
  )
}
