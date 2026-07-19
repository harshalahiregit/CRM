import { useState, useEffect, useMemo } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { X, Check, Building2, IndianRupee, SlidersHorizontal, LayoutGrid, ShieldCheck } from 'lucide-react'
import { projectApi, PROJECT_STATUS, BILLING_TYPES, PROJECT_ACCENT } from '@/services/projectApi'
import { tagApi } from '@/services/tagApi'
import Select from '@/components/ui/Select'
import SearchPicker from '@/components/ui/SearchPicker'
import TagInput from '@/components/ui/TagInput'

/**
 * Create/edit a project across TWO tabs (matches the spec):
 *   • Project          — core details (name, customer, billing, dates, members…)
 *   • Project Settings — visibility & permissions (Visible Tabs, customer
 *                        permission toggles, contact notifications, hide-tasks).
 *
 * The Visible-Tabs / permission catalog comes from GET /projects/meta so the form
 * only ever offers what the backend accepts, and a tab with no working screen yet
 * is shown greyed and can't be switched on.
 */

const today = () => new Date().toISOString().split('T')[0]
const EMPTY = {
  name: '', description: '', status: 'not_started', customer_id: '',
  billing_type: 'fixed', project_cost: '', rate_per_hour: '',
  start_date: today(), deadline: '', estimated_hours: '',
  progress: 0, progress_from_tasks: true,
  member_ids: [], tags: [],
  // Tab 2 — settings
  visible_tabs: null,           // {key: bool} — defaulted from meta once loaded
  customer_permissions: {},     // {key: bool}
  hide_tasks_on_main: false,
  send_created_email: false,
  contacts_notification: 'all',
}

export default function ProjectFormDrawer({ open, onClose, project = null, onSaved }) {
  const qc = useQueryClient()
  const editing = Boolean(project)
  const [tab, setTab] = useState('project')     // 'project' | 'settings'
  const [form, setForm] = useState(EMPTY)
  const [picker, setPicker] = useState(null)    // 'customer' | 'member'
  const [err, setErr] = useState('')

  const { data: meta } = useQuery({ queryKey: ['project-meta'], queryFn: projectApi.meta, enabled: open, staleTime: 5 * 60_000 })

  useEffect(() => {
    if (!open) return
    setErr(''); setTab('project')
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
          visible_tabs: project.visible_tabs || null,
          customer_permissions: project.customer_permissions || {},
          hide_tasks_on_main: !!project.hide_tasks_on_main,
          send_created_email: !!project.send_created_email,
          contacts_notification: project.contacts_notification || 'all',
        }
      : EMPTY)
  }, [open, project, editing]) // eslint-disable-line react-hooks/exhaustive-deps

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  // Default the visible-tabs bag from meta the first time it loads (new projects
  // start with every working tab on). Editing keeps whatever the project stored.
  const effectiveTabs = useMemo(() => {
    if (form.visible_tabs) return form.visible_tabs
    return meta?.default_tabs || {}
  }, [form.visible_tabs, meta])

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

  const toggleTab = (key) => setForm(p => ({ ...p, visible_tabs: { ...effectiveTabs, [key]: !effectiveTabs[key] } }))
  const togglePerm = (key) => setForm(p => ({ ...p, customer_permissions: { ...p.customer_permissions, [key]: !p.customer_permissions?.[key] } }))

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
    // Persist the resolved tab bag (so a never-touched new project still saves its defaults).
    p.visible_tabs = effectiveTabs
    ;['customer_id', 'deadline', 'project_cost', 'rate_per_hour', 'estimated_hours'].forEach(k => {
      if (p[k] === '' || p[k] === null) delete p[k]
    })
    delete p.members; delete p.milestones; delete p.customer; delete p.creator
    delete p.is_pinned; delete p.id; delete p.tenant_id
    save.mutate(p)
  }

  if (!open) return null
  const busy = save.isPending
  const tabs = meta?.tabs || []
  const perms = meta?.customer_permissions || []
  const notifModes = meta?.contacts_notification || []

  return (
    <div className="fixed inset-0 z-[55] flex items-start justify-center p-3 sm:p-6 overflow-y-auto"
      style={{ background: 'rgba(15,23,42,0.55)', backdropFilter: 'blur(2px)' }} onClick={onClose}>
      <form onSubmit={submit} onClick={e => e.stopPropagation()}
        className="w-full rounded-2xl overflow-hidden my-2 flex flex-col"
        style={{ maxWidth: 640, background: 'var(--bg-global)', boxShadow: '0 24px 70px rgba(0,0,0,0.45)', maxHeight: '94vh' }}>

        <header className="shrink-0" style={{ background: 'var(--bg-global)', borderBottom: '1px solid var(--border)' }}>
          <div className="flex items-center justify-between px-5 py-4">
            <h2 className="font-bold" style={{ color: 'var(--text-h)', fontSize: 15 }}>{editing ? 'Edit Project' : 'New Project'}</h2>
            <button type="button" onClick={onClose} aria-label="Close"><X size={17} style={{ color: 'var(--text-muted)' }} /></button>
          </div>
          {/* Tab switcher */}
          <div className="flex items-center gap-1 px-5 -mb-px">
            {[['project', 'Project', SlidersHorizontal], ['settings', 'Project Settings', ShieldCheck]].map(([k, label, Icon]) => {
              const active = tab === k
              return (
                <button type="button" key={k} onClick={() => setTab(k)}
                  className="flex items-center gap-1.5 px-3 py-2 text-xs font-bold transition-colors"
                  style={{ color: active ? PROJECT_ACCENT : 'var(--text-muted)', borderBottom: `2px solid ${active ? PROJECT_ACCENT : 'transparent'}` }}>
                  <Icon size={13} /> {label}
                </button>
              )
            })}
          </div>
        </header>

        {/* Scrollable body */}
        <div className="overflow-y-auto" style={{ flex: 1 }}>
        {/* ── Tab 1: Project ──────────────────────────────────────── */}
        <div className="p-5 space-y-3.5" style={{ display: tab === 'project' ? 'block' : 'none' }}>
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

          <div className="rounded-xl p-3" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
            <Toggle label="Send project created email" hint="Email the customer's contacts when the project is created"
              checked={!!form.send_created_email} onChange={v => sf('send_created_email', v)} />
          </div>
        </div>

        {/* ── Tab 2: Project Settings ─────────────────────────────── */}
        <div className="p-5 space-y-4" style={{ display: tab === 'settings' ? 'block' : 'none' }}>
          <Field label="Send Contacts Notifications" required>
            <Select value={form.contacts_notification} onChange={v => sf('contacts_notification', v)}
              options={notifModes.map(m => ({ value: m.key, label: m.label }))} />
          </Field>

          <div>
            <div className="flex items-center gap-1.5 mb-2">
              <LayoutGrid size={13} style={{ color: PROJECT_ACCENT }} />
              <span className="text-[11px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-h)' }}>Visible Tabs</span>
            </div>
            <p className="text-[10px] mb-2.5" style={{ color: 'var(--text-muted)' }}>
              Switch feature tabs on/off for this project. Greyed tabs have no screen yet.
            </p>
            <div className="grid grid-cols-2 gap-1.5">
              {tabs.map(t => {
                const on = !!effectiveTabs[t.key]
                const disabled = !t.implemented
                return (
                  <button type="button" key={t.key} disabled={disabled}
                    onClick={() => toggleTab(t.key)}
                    className="flex items-center gap-2 px-2.5 py-1.5 rounded-lg text-left text-[11px] font-semibold transition-colors"
                    style={{
                      background: on && !disabled ? `color-mix(in srgb, ${PROJECT_ACCENT} 12%, transparent)` : 'var(--bg-input)',
                      color: disabled ? 'var(--text-muted)' : on ? PROJECT_ACCENT : 'var(--text-body)',
                      border: `1px solid ${on && !disabled ? PROJECT_ACCENT : 'var(--border)'}`,
                      opacity: disabled ? 0.5 : 1, cursor: disabled ? 'not-allowed' : 'pointer',
                    }}>
                    <span className="w-3.5 h-3.5 rounded flex items-center justify-center shrink-0"
                      style={{ border: `1px solid ${on && !disabled ? PROJECT_ACCENT : 'var(--border)'}`, background: on && !disabled ? PROJECT_ACCENT : 'transparent' }}>
                      {on && !disabled && <Check size={9} style={{ color: '#fff' }} />}
                    </span>
                    <span className="truncate">{t.label}{disabled && ' · soon'}</span>
                  </button>
                )
              })}
            </div>
          </div>

          <div>
            <div className="flex items-center gap-1.5 mb-2">
              <ShieldCheck size={13} style={{ color: PROJECT_ACCENT }} />
              <span className="text-[11px] font-bold uppercase tracking-wide" style={{ color: 'var(--text-h)' }}>Customer Permissions</span>
            </div>
            <div className="rounded-xl divide-y" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
              {perms.map(pm => (
                <div key={pm.key} className="px-3 py-2">
                  <Toggle label={pm.label} checked={!!form.customer_permissions?.[pm.key]} onChange={() => togglePerm(pm.key)} small />
                </div>
              ))}
            </div>
          </div>

          <div className="rounded-xl p-3" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
            <Toggle label="Hide project tasks on main tasks table" hint="Keep this project's tasks out of the global admin Tasks list"
              checked={!!form.hide_tasks_on_main} onChange={v => sf('hide_tasks_on_main', v)} />
          </div>
        </div>

        {err && (
          <p className="mx-5 mb-3 text-xs px-3 py-2 rounded-lg"
            style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>{err}</p>
        )}
        </div>{/* /scrollable body */}

        <footer className="p-5 shrink-0" style={{ background: 'var(--bg-global)', borderTop: '1px solid var(--border)' }}>
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

function Toggle({ label, hint, checked, onChange, small }) {
  return (
    <button type="button" onClick={() => onChange(!checked)} className="w-full flex items-center gap-2.5 text-left">
      <span className="rounded-full relative transition-colors shrink-0"
        style={{ width: 32, height: 18, background: checked ? PROJECT_ACCENT : 'var(--border)' }}>
        <span className="absolute w-3.5 h-3.5 rounded-full top-[2px] transition-all" style={{ background: '#fff', left: checked ? 16 : 2 }} />
      </span>
      <span className="flex-1 min-w-0">
        <span className="block font-semibold" style={{ color: 'var(--text-h)', fontSize: small ? 12 : 12 }}>{label}</span>
        {hint && <span className="block text-[10px]" style={{ color: 'var(--text-muted)' }}>{hint}</span>}
      </span>
    </button>
  )
}
