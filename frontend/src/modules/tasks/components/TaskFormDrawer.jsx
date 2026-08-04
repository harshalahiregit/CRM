import { useState, useEffect, useMemo, useRef } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  X, Check, CheckCircle2, Link2, IndianRupee, Paperclip, ChevronDown, Flag, ListPlus,
} from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { purchaseApi } from '@/services/purchaseApi'
import { taskApi, TASK_PRIORITY, TASK_ACCENT, REL_TYPES, REL_TYPE_LABEL, fmtBytes } from '@/services/taskApi'
import Select from '@/components/ui/Select'
import SearchPicker from '@/components/ui/SearchPicker'
import TagInput from '@/components/ui/TagInput'
import { projectApi } from '@/services/projectApi'
import { helpdeskApi } from '@/services/helpdeskApi'
import { tagApi } from '@/services/tagApi'

/**
 * Create / edit a task — a full centered modal (was a cramped side drawer).
 *
 * The layout mirrors the Perfex "Add New Task" popup the team asked for: two-column
 * body, everything on one screen. Fields that DO map to this CRM are live; the
 * vendor / purchase dropdowns have no backing module here, so they're rendered
 * disabled rather than faked.
 *
 * Sub-resources that need a task id first (attachments) are STAGED in the form and
 * uploaded straight after the task is created, so "Attach files" works at create
 * time even though the file endpoint is task-scoped.
 */

const today = () => new Date().toISOString().split('T')[0]

const EMPTY = {
  name: '', description: '', priority: 'medium', status: '',
  start_date: today(), due_date: '', rel_type: 'standalone', rel_id: '', milestone_id: '',
  billable: false, hourly_rate: '', is_public: false, visible_to_client: false,
  assignee_ids: [], follower_ids: [], tags: [], template_ids: [],
  repeat_preset: '', recurring: false, recurring_type: 'month', repeat_every: 1, cycles: 0,
}

// "Repeat every" presets → our (recurring, recurring_type, repeat_every) model.
const REPEAT_PRESETS = [
  { value: '',        label: 'None selected' },
  { value: 'weekly',  label: 'Every week',      recurring_type: 'week',  repeat_every: 1 },
  { value: '2weeks',  label: 'Every 2 weeks',   recurring_type: 'week',  repeat_every: 2 },
  { value: 'monthly', label: 'Every month',     recurring_type: 'month', repeat_every: 1 },
  { value: '2months', label: 'Every 2 months',  recurring_type: 'month', repeat_every: 2 },
  { value: '3months', label: 'Every 3 months',  recurring_type: 'month', repeat_every: 3 },
  { value: '6months', label: 'Every 6 months',  recurring_type: 'month', repeat_every: 6 },
  { value: 'yearly',  label: 'Every year',      recurring_type: 'year',  repeat_every: 1 },
  { value: 'custom',  label: 'Custom…' },
]
const RECURRING_UNITS = [
  { value: 'day', label: 'Days' }, { value: 'week', label: 'Weeks' },
  { value: 'month', label: 'Months' }, { value: 'year', label: 'Years' },
]

const presetFromTask = (t) => {
  if (!t?.recurring) return ''
  const map = { '1:week': 'weekly', '2:week': '2weeks', '1:month': 'monthly', '2:month': '2months', '3:month': '3months', '6:month': '6months', '1:year': 'yearly' }
  return map[`${t.repeat_every}:${t.recurring_type}`] || 'custom'
}

export default function TaskFormDrawer({ open, onClose, task = null, defaults = {}, onSaved }) {
  const qc = useQueryClient()
  const editing = Boolean(task)
  const [form, setForm] = useState(EMPTY)
  const [picker, setPicker] = useState(null)   // 'rel' | 'assignee' | 'follower'
  const [pendingFiles, setPendingFiles] = useState([])
  const [showAttach, setShowAttach] = useState(false)
  const [err, setErr] = useState('')
  const fileInput = useRef(null)

  useEffect(() => {
    if (!open) return
    setErr(''); setPendingFiles([]); setShowAttach(false)
    setForm(editing
      ? {
          ...EMPTY, ...task,
          due_date: task.due_date ? String(task.due_date).split('T')[0] : '',
          start_date: task.start_date ? String(task.start_date).split('T')[0] : today(),
          hourly_rate: task.hourly_rate ?? '',
          rel_id: task.rel_id ?? '',
          milestone_id: task.milestone_id ?? '',
          assignee_ids: (task.assignees || []).map(a => a.user_id),
          follower_ids: (task.followers || []).map(f => f.user_id),
          tags: (task.tags || []).map(t => t.name),
          template_ids: [],
          repeat_preset: presetFromTask(task),
        }
      : { ...EMPTY, ...defaults })
  }, [open, task, editing]) // eslint-disable-line react-hooks/exhaustive-deps

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  const { data: staff = [] } = useQuery({ queryKey: ['task-staff'], queryFn: taskApi.staff, enabled: open })
  // Vendors & TPVs are also assignable — work delegated to them shows on their
  // portal dashboard. They go into the same assignee_ids array.
  const { data: vendors = [] } = useQuery({ queryKey: ['task-vendors', 'vendor'], queryFn: () => taskApi.vendors('vendor'), enabled: open })
  const { data: tpvs = [] } = useQuery({ queryKey: ['task-vendors', 'tpv'], queryFn: () => taskApi.vendors('tpv'), enabled: open })
  // Merged directory so a chip resolves a name whatever kind of person it is.
  const people = useMemo(() => [...staff, ...vendors, ...tpvs], [staff, vendors, tpvs])
  const idSet = (list) => new Set(list.map(x => x.id))
  const staffIds = useMemo(() => idSet(staff), [staff])
  const vendorIds = useMemo(() => idSet(vendors), [vendors])
  const tpvIds = useMemo(() => idSet(tpvs), [tpvs])
  const { data: tagSuggestions = [] } = useQuery({ queryKey: ['tags', 'task'], queryFn: () => tagApi.list('task'), enabled: open })
  const { data: templates = [] } = useQuery({ queryKey: ['task-templates'], queryFn: taskApi.templates, enabled: open })
  const { data: projects = [], isLoading: pLoading } = useQuery({
    queryKey: ['projects-picker'], queryFn: () => projectApi.list(), enabled: open && form.rel_type === 'project',
  })
  const { data: tickets = [], isLoading: tLoading } = useQuery({
    queryKey: ['tickets-picker'], queryFn: () => helpdeskApi.tickets.list(), enabled: open && form.rel_type === 'ticket',
  })
  const { data: customers = [], isLoading: cLoading } = useQuery({
    queryKey: ['project-customers'], queryFn: projectApi.customers, enabled: open && form.rel_type === 'customer',
  })
  // Vendor relation sources. Each module has its own list endpoint; a task links to
  // the vendor RECORD, not to a user account -- which is what lets a Purchase Vendor
  // (who has no User) carry tasks at all.
  const { data: tpvVendorList = [], isLoading: tvLoading } = useQuery({
    queryKey: ['task-rel-tpv-vendors'], queryFn: () => tpvApi.vendors.list(),
    enabled: open && form.rel_type === 'tpv_vendor',
  })
  const { data: purchaseVendorList = [], isLoading: pvLoading } = useQuery({
    queryKey: ['task-rel-purchase-vendors'], queryFn: () => purchaseApi.vendors.list(),
    enabled: open && form.rel_type === 'purchase_vendor',
  })
  // Milestones belong to the linked project — only load once one is chosen.
  const { data: milestones = [] } = useQuery({
    queryKey: ['project-milestones', form.rel_id], queryFn: () => projectApi.milestones(form.rel_id),
    enabled: open && form.rel_type === 'project' && !!form.rel_id,
  })

  const rows = (x) => (Array.isArray(x) ? x : x?.data ?? [])

  const relItems = useMemo(() => {
    if (form.rel_type === 'project') return rows(projects).map(p => ({ id: p.id, label: p.name, sublabel: p.status }))
    if (form.rel_type === 'ticket') return rows(tickets).map(t => ({ id: t.id, label: t.subject, sublabel: `#${t.id} · ${t.status}` }))
    if (form.rel_type === 'customer') return rows(customers).map(c => ({ id: c.id, label: c.name, sublabel: c.company || '' }))
    if (form.rel_type === 'tpv_vendor') return rows(tpvVendorList).map(v => ({ id: v.id, label: v.company_name || v.name, sublabel: [v.vendor_code, v.status].filter(Boolean).join(' · ') }))
    if (form.rel_type === 'purchase_vendor') return rows(purchaseVendorList).map(v => ({ id: v.id, label: v.company_name || v.name, sublabel: [v.purchase_vendor_code, v.status].filter(Boolean).join(' · ') }))
    return []
  }, [form.rel_type, projects, tickets, customers, tpvVendorList, purchaseVendorList])

  const relName = useMemo(() => {
    if (form.rel_type === 'standalone' || !form.rel_id) return ''
    const hit = relItems.find(i => String(i.id) === String(form.rel_id))
    return hit?.label || task?.rel_label || `${form.rel_type} #${form.rel_id}`
  }, [form.rel_type, form.rel_id, relItems, task])

  const onPreset = (v) => {
    const p = REPEAT_PRESETS.find(x => x.value === v)
    if (v === 'custom') setForm(f => ({ ...f, repeat_preset: 'custom', recurring: true }))
    else if (!v) setForm(f => ({ ...f, repeat_preset: '', recurring: false }))
    else setForm(f => ({ ...f, repeat_preset: v, recurring: true, recurring_type: p.recurring_type, repeat_every: p.repeat_every }))
  }

  const stageFiles = (list) => {
    const picked = Array.from(list || [])
    if (picked.length) setPendingFiles(prev => [...prev, ...picked].slice(0, 10))
  }

  const save = useMutation({
    mutationFn: async (payload) => {
      let saved
      if (editing) {
        saved = await taskApi.update(task.id, payload)
        await taskApi.assignees(task.id, payload.assignee_ids || [])
        await taskApi.followers(task.id, payload.follower_ids || [])
      } else {
        saved = await taskApi.create(payload)
      }
      // Checklist templates + staged files need a real task id → run after save.
      for (const tid of form.template_ids) { await taskApi.applyTemplate(saved.id, tid).catch(() => {}) }
      if (pendingFiles.length) { await taskApi.uploadFiles(saved.id, pendingFiles).catch(() => {}) }
      return saved
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['tasks'] })
      qc.invalidateQueries({ queryKey: ['tags', 'task'] })
      qc.invalidateQueries({ queryKey: ['task-stats'] })
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
    delete p.repeat_preset; delete p.template_ids
    if (p.rel_type === 'standalone' || !p.rel_id) delete p.rel_id
    if (p.rel_type !== 'project' || !p.milestone_id) delete p.milestone_id
    if (!p.status) delete p.status
    if (p.hourly_rate === '') delete p.hourly_rate
    if (p.due_date === '') delete p.due_date
    if (!p.recurring) { delete p.recurring_type; delete p.repeat_every; delete p.cycles }
    if (editing) delete p.assignees
    Object.keys(p).forEach(k => p[k] === null && delete p[k])
    save.mutate(p)
  }

  if (!open) return null
  const busy = save.isPending
  const isProject = form.rel_type === 'project'

  return (
    <div className="fixed inset-0 z-[55] flex items-start justify-center p-3 sm:p-6 overflow-y-auto"
      style={{ background: 'rgba(15,23,42,0.55)', backdropFilter: 'blur(2px)' }} onClick={onClose}>
      <form onSubmit={submit} onClick={e => e.stopPropagation()}
        className="w-full rounded-2xl overflow-hidden my-2 flex flex-col"
        style={{ maxWidth: 760, background: 'var(--bg-global)', boxShadow: '0 24px 70px rgba(0,0,0,0.45)', maxHeight: '94vh' }}>

        {/* Gradient header */}
        <header className="flex items-center gap-2.5 px-6 py-4 shrink-0"
          style={{ background: 'linear-gradient(120deg, var(--color-primary-600), var(--color-primary-500))' }}>
          <CheckCircle2 size={20} style={{ color: '#fff' }} />
          <h2 className="font-bold text-white" style={{ fontSize: 17 }}>{editing ? 'Edit Task' : 'Add New Task'}</h2>
          <button type="button" onClick={onClose} aria-label="Close" className="ml-auto opacity-90 hover:opacity-100">
            <X size={19} style={{ color: '#fff' }} />
          </button>
        </header>

        {/* Scrollable body */}
        <div className="px-6 py-5 space-y-5 overflow-y-auto" style={{ flex: 1 }}>
          {/* Options + attach */}
          <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
            <Check label="Public" hint="Visible to all staff" checked={!!form.is_public} onChange={v => sf('is_public', v)} />
            <Check label="Billable" hint="Time logged can be invoiced" checked={!!form.billable} onChange={v => sf('billable', v)} />
            {isProject && <Check label="Visible to Client" checked={!!form.visible_to_client} onChange={v => sf('visible_to_client', v)} />}
            <button type="button" onClick={() => setShowAttach(s => !s)}
              className="flex items-center gap-1.5 text-sm font-semibold" style={{ color: TASK_ACCENT }}>
              <Paperclip size={14} /> Attach files
            </button>
          </div>

          {showAttach && (
            <div className="rounded-xl p-3" style={{ background: 'var(--bg-card)', border: '1px dashed var(--border)' }}>
              <div className="flex items-center gap-2 flex-wrap">
                <button type="button" onClick={() => fileInput.current?.click()}
                  className="text-xs font-bold px-3 py-1.5 rounded-lg" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
                  Choose files
                </button>
                <span className="text-xs" style={{ color: 'var(--text-muted)' }}>Up to 10 files, 10 MB each</span>
                <input ref={fileInput} type="file" multiple hidden onChange={e => { stageFiles(e.target.files); e.target.value = '' }} />
              </div>
              {pendingFiles.length > 0 && (
                <ul className="mt-2.5 flex flex-wrap gap-1.5">
                  {pendingFiles.map((f, i) => (
                    <li key={i} className="flex items-center gap-1.5 text-xs px-2 py-1 rounded-lg" style={{ background: 'var(--bg-input)', color: 'var(--text-h)' }}>
                      <Paperclip size={11} style={{ color: 'var(--text-muted)' }} />
                      <span className="truncate" style={{ maxWidth: 160 }}>{f.name}</span>
                      <span style={{ color: 'var(--text-muted)' }}>{fmtBytes(f.size)}</span>
                      <button type="button" onClick={() => setPendingFiles(prev => prev.filter((_, j) => j !== i))} aria-label={`Remove ${f.name}`}>
                        <X size={12} style={{ color: 'var(--text-muted)' }} />
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}

          <Divider />

          <Field label="Subject" required>
            <input value={form.name} onChange={e => sf('name', e.target.value)} className={INPUT} style={INPUT_S} autoFocus placeholder="What needs doing?" />
          </Field>

          <Field label="Hourly Rate">
            <div className="relative">
              <IndianRupee size={15} style={{ position: 'absolute', left: 12, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
              <input type="number" min="0" step="0.01" value={form.hourly_rate}
                onChange={e => sf('hourly_rate', e.target.value)} className={INPUT} style={{ ...INPUT_S, paddingLeft: 32 }} placeholder="0" />
            </div>
          </Field>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <Field label="Start Date" required>
              <input type="date" value={form.start_date} onChange={e => sf('start_date', e.target.value)} className={INPUT} style={INPUT_S} />
            </Field>
            <Field label="Due Date">
              <input type="date" value={form.due_date || ''} onChange={e => sf('due_date', e.target.value)} className={INPUT} style={INPUT_S} />
            </Field>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <Field label="Priority">
              <Select value={form.priority} onChange={v => sf('priority', v)}
                options={Object.entries(TASK_PRIORITY).map(([v, c]) => ({ value: v, label: v[0].toUpperCase() + v.slice(1), dot: c }))} />
            </Field>
            <Field label="Repeat every">
              <Select value={form.repeat_preset} onChange={onPreset} options={REPEAT_PRESETS.map(p => ({ value: p.value, label: p.label }))} />
            </Field>
          </div>

          {form.repeat_preset === 'custom' && (
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 rounded-xl p-3" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
              <Field label="Every">
                <input type="number" min="1" max="365" value={form.repeat_every}
                  onChange={e => sf('repeat_every', Number(e.target.value) || 1)} className={INPUT} style={INPUT_S} />
              </Field>
              <Field label="Unit">
                <Select value={form.recurring_type} onChange={v => sf('recurring_type', v)} options={RECURRING_UNITS} />
              </Field>
              <Field label="Stop after (0 = forever)">
                <input type="number" min="0" max="1000" value={form.cycles}
                  onChange={e => sf('cycles', Number(e.target.value) || 0)} className={INPUT} style={INPUT_S} placeholder="0" />
              </Field>
            </div>
          )}

          <Field label="Related To">
            <Select value={form.rel_type} onChange={v => setForm(p => ({ ...p, rel_type: v, rel_id: '', milestone_id: '' }))} options={REL_TYPES} />
          </Field>

          {form.rel_type !== 'standalone' && (
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <Field label={REL_TYPE_LABEL[form.rel_type] || form.rel_type}>
                <button type="button" onClick={() => setPicker('rel')} className="w-full flex items-center gap-2 rounded-xl text-left" style={{ ...INPUT_S }}>
                  <Link2 size={14} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
                  <span className="truncate" style={{ color: relName ? 'var(--text-h)' : 'var(--text-muted)' }}>
                    {relName || `Choose ${(REL_TYPE_LABEL[form.rel_type] || form.rel_type).toLowerCase()}…`}
                  </span>
                </button>
              </Field>
              {isProject && (
                <Field label="Milestone">
                  <Select value={form.milestone_id ? String(form.milestone_id) : ''} onChange={v => sf('milestone_id', v ? Number(v) : '')}
                    placeholder={form.rel_id ? 'No milestone' : 'Pick a project first'}
                    options={[{ value: '', label: 'No milestone' }, ...milestones.map(m => ({ value: String(m.id), label: m.name, dot: m.color }))]} />
                </Field>
              )}
            </div>
          )}

          <Divider />

          {/* People — staff, vendors and third-party vendors can all be assigned.
              All three feed the same assignee_ids; chips are split by who each id is. */}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <Field label="Assignees (staff)">
              <PeopleChips ids={form.assignee_ids.filter(i => staffIds.has(i))} staff={people} addLabel="Assign"
                onRemove={id => sf('assignee_ids', form.assignee_ids.filter(i => i !== id))}
                onAdd={() => setPicker('assignee')} />
            </Field>
            <Field label="Followers">
              <PeopleChips ids={form.follower_ids} staff={people} addLabel="Follow"
                onRemove={id => sf('follower_ids', form.follower_ids.filter(i => i !== id))}
                onAdd={() => setPicker('follower')} />
            </Field>
            <Field label="Vendors">
              <PeopleChips ids={form.assignee_ids.filter(i => vendorIds.has(i))} staff={people} addLabel="Add vendor"
                onRemove={id => sf('assignee_ids', form.assignee_ids.filter(i => i !== id))}
                onAdd={() => setPicker('vendor')} />
            </Field>
            <Field label="Third-party vendors">
              <PeopleChips ids={form.assignee_ids.filter(i => tpvIds.has(i))} staff={people} addLabel="Add TPV"
                onRemove={id => sf('assignee_ids', form.assignee_ids.filter(i => i !== id))}
                onAdd={() => setPicker('tpv')} />
            </Field>
          </div>
          <p className="text-[11px] -mt-2" style={{ color: 'var(--text-muted)' }}>
            Vendors &amp; third-party vendors see tasks assigned to them on their portal dashboard.
          </p>

          {!editing && templates.length > 0 && (
            <Field label="Checklist templates">
              <div className="flex flex-wrap gap-1.5">
                {templates.map(t => {
                  const on = form.template_ids.includes(t.id)
                  return (
                    <button type="button" key={t.id}
                      onClick={() => sf('template_ids', on ? form.template_ids.filter(i => i !== t.id) : [...form.template_ids, t.id])}
                      className="flex items-center gap-1 text-xs font-semibold px-2.5 py-1.5 rounded-lg transition-colors"
                      style={on
                        ? { background: `color-mix(in srgb, ${TASK_ACCENT} 16%, transparent)`, color: TASK_ACCENT, border: `1px solid ${TASK_ACCENT}` }
                        : { background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
                      <ListPlus size={12} /> {t.name}
                    </button>
                  )
                })}
              </div>
            </Field>
          )}

          <Field label="Tags">
            <TagInput value={form.tags} onChange={v => sf('tags', v)} suggestions={tagSuggestions} accent={TASK_ACCENT} />
          </Field>

          <Field label="Task Description">
            <textarea value={form.description || ''} onChange={e => sf('description', e.target.value)} rows={4}
              className={INPUT} style={{ ...INPUT_S, resize: 'vertical', minHeight: 96 }} placeholder="Add description…" />
          </Field>

          {err && (
            <p className="text-sm px-3 py-2 rounded-lg"
              style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>{err}</p>
          )}
        </div>

        {/* Footer */}
        <footer className="flex items-center justify-end gap-2 px-6 py-4 shrink-0" style={{ borderTop: '1px solid var(--border)', background: 'var(--bg-card)' }}>
          <button type="button" onClick={onClose} className="text-sm font-semibold px-4 py-2.5 rounded-xl"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>Close</button>
          <button type="submit" disabled={!form.name.trim() || busy}
            className="flex items-center gap-1.5 text-sm font-bold px-5 py-2.5 rounded-xl disabled:opacity-40"
            style={{ background: TASK_ACCENT, color: '#fff' }}>
            <Check size={16} /> {busy ? 'Saving…' : editing ? 'Save changes' : 'Submit'}
          </button>
        </footer>
      </form>

      <SearchPicker
        open={picker === 'rel'} onClose={() => setPicker(null)}
        onPick={it => sf('rel_id', it ? it.id : '')}
        items={relItems} loading={pLoading || tLoading || cLoading || tvLoading || pvLoading}
        title={`Link to a ${(REL_TYPE_LABEL[form.rel_type] || form.rel_type).toLowerCase()}`} subtitle="Search by name — you don't need the id."
        emptyText={`No ${(REL_TYPE_LABEL[form.rel_type] || form.rel_type).toLowerCase()}s found.`} accent={TASK_ACCENT} allowClear
      />
      <SearchPicker
        open={picker === 'assignee'} onClose={() => setPicker(null)}
        onPick={it => it && !form.assignee_ids.includes(it.id) && sf('assignee_ids', [...form.assignee_ids, it.id])}
        items={staff.filter(s => !form.assignee_ids.includes(s.id)).map(s => ({ id: s.id, label: s.name, sublabel: s.role }))}
        title="Assign to" subtitle="Staff member doing the work." emptyText="Everyone is already assigned." accent={TASK_ACCENT}
      />
      <SearchPicker
        open={picker === 'vendor'} onClose={() => setPicker(null)}
        onPick={it => it && !form.assignee_ids.includes(it.id) && sf('assignee_ids', [...form.assignee_ids, it.id])}
        items={vendors.filter(s => !form.assignee_ids.includes(s.id)).map(s => ({ id: s.id, label: s.name, sublabel: s.email }))}
        title="Assign a vendor" subtitle="They'll see it on their vendor portal." emptyText="No vendors available." accent={TASK_ACCENT}
      />
      <SearchPicker
        open={picker === 'tpv'} onClose={() => setPicker(null)}
        onPick={it => it && !form.assignee_ids.includes(it.id) && sf('assignee_ids', [...form.assignee_ids, it.id])}
        items={tpvs.filter(s => !form.assignee_ids.includes(s.id)).map(s => ({ id: s.id, label: s.name, sublabel: s.email }))}
        title="Assign a third-party vendor" subtitle="They'll see it on their portal." emptyText="No third-party vendors available." accent={TASK_ACCENT}
      />
      <SearchPicker
        open={picker === 'follower'} onClose={() => setPicker(null)}
        onPick={it => it && !form.follower_ids.includes(it.id) && sf('follower_ids', [...form.follower_ids, it.id])}
        items={staff.filter(s => !form.follower_ids.includes(s.id)).map(s => ({ id: s.id, label: s.name, sublabel: s.role }))}
        title="Add follower" subtitle="Followers get updates but aren't doing the work." emptyText="Everyone is already following." accent={TASK_ACCENT}
      />
    </div>
  )
}

/* ── Bits ─────────────────────────────────────────────────────── */

const INPUT = 'w-full rounded-xl outline-none'
const INPUT_S = { padding: '11px 13px', fontSize: 14, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }

const Divider = () => <div style={{ height: 1, background: 'var(--border)' }} />

export function Field({ label, required, children }) {
  return (
    <label className="block">
      <span className="text-xs font-bold block mb-1.5 capitalize" style={{ color: 'var(--text-body)', letterSpacing: '0.01em' }}>
        {label}{required && <span style={{ color: 'var(--color-danger-500)' }}> *</span>}
      </span>
      {children}
    </label>
  )
}

/** Visual-only placeholder for the vendor/purchase fields that have no module here. */
function DisabledField({ label }) {
  return (
    <Field label={label}>
      <div className="w-full flex items-center justify-between rounded-xl cursor-not-allowed"
        style={{ ...INPUT_S, opacity: 0.55 }} title="No vendor module configured in this workspace">
        <span style={{ color: 'var(--text-muted)' }}>Nothing selected</span>
        <ChevronDown size={15} style={{ color: 'var(--text-muted)' }} />
      </div>
    </Field>
  )
}

export function PeopleChips({ ids = [], staff = [], onRemove, onAdd, addLabel = 'Add' }) {
  const byId = useMemo(() => Object.fromEntries(staff.map(s => [s.id, s])), [staff])
  return (
    <div className="flex flex-wrap items-center gap-1.5 rounded-xl px-2 py-2" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', minHeight: 44 }}>
      {ids.map(id => (
        <span key={id} className="flex items-center gap-1 text-xs font-semibold px-2 py-1 rounded-lg"
          style={{ background: `color-mix(in srgb, ${TASK_ACCENT} 14%, transparent)`, color: TASK_ACCENT }}>
          {byId[id]?.name || `#${id}`}
          <button type="button" onClick={() => onRemove(id)} className="hover:opacity-60" aria-label={`Remove ${byId[id]?.name || id}`}>
            <X size={12} />
          </button>
        </span>
      ))}
      <button type="button" onClick={onAdd} className="text-xs font-bold px-2 py-1 rounded-lg"
        style={{ border: '1px dashed var(--border)', color: 'var(--text-muted)' }}>+ {addLabel}</button>
    </div>
  )
}
