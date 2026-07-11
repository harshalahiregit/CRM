import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Flag, CircleDot, Building2, Plus, Trash2, ChevronUp, ChevronDown, Check, X, Settings } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

const KEY = ['helpdesk-settings']

export default function SupportSettings() {
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: KEY, queryFn: helpdeskApi.settings.all })
  const invalidate = () => qc.invalidateQueries({ queryKey: KEY })

  if (isLoading) return <div className="skeleton h-40 rounded-2xl" style={{ background: 'var(--border)' }} />

  const { priorities = [], statuses = [], departments = [], settings = {} } = data || {}

  return (
    <div className="text-slate-200 max-w-4xl space-y-5">
      <div className="flex items-center gap-2">
        <Settings size={20} style={{ color: '#22d3ee' }} />
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Support Settings</h1>
        <span className="text-xs" style={{ color: 'var(--text-muted)' }}>Manage the values agents can pick from</span>
      </div>

      <ListManager type="priorities" title="Priorities" singular="priority" icon={Flag} items={priorities}
        extraFlag={{ key: 'is_default', label: 'default' }} onChange={invalidate} />

      <ListManager type="statuses" title="Statuses" singular="status" icon={CircleDot} items={statuses}
        extraFlag={{ key: 'is_closed_status', label: 'closed' }} onChange={invalidate} />

      <ListManager type="departments" title="Departments" singular="department" icon={Building2} items={departments}
        withDescription onChange={invalidate} />

      <PublicFormCard settings={settings} departments={departments} onChange={invalidate} />
    </div>
  )
}

/* ── Reusable list manager (priorities | statuses | departments) ── */
function ListManager({ type, title, singular, icon: Icon, items, extraFlag, withDescription, onChange }) {
  const [draft, setDraft] = useState({ name: '', color: '#22d3ee', description: '' })

  const create = useMutation({
    mutationFn: () => helpdeskApi.settings.createItem(type, draft),
    onSuccess: () => { setDraft({ name: '', color: '#22d3ee', description: '' }); onChange() },
  })
  const update = useMutation({ mutationFn: ({ id, data }) => helpdeskApi.settings.updateItem(type, id, data), onSuccess: onChange })
  const remove = useMutation({ mutationFn: (id) => helpdeskApi.settings.deleteItem(type, id), onSuccess: onChange })
  const reorder = useMutation({ mutationFn: (ids) => helpdeskApi.settings.reorder(type, ids), onSuccess: onChange })

  const move = (idx, dir) => {
    const next = [...items]
    const j = idx + dir
    if (j < 0 || j >= next.length) return
    ;[next[idx], next[j]] = [next[j], next[idx]]
    reorder.mutate(next.map(i => i.id))
  }

  return (
    <div className="rounded-2xl border p-5" style={{ borderColor: 'var(--border)', background: 'var(--bg-card)' }}>
      <h2 className="font-semibold text-sm mb-3 flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
        <Icon size={15} style={{ color: '#22d3ee' }} />{title}
        <span className="text-[10px] font-normal" style={{ color: 'var(--text-muted)' }}>({items.length})</span>
      </h2>

      <ul className="space-y-1.5 mb-3">
        {items.map((it, idx) => (
          <li key={it.id} className="flex items-center gap-2 rounded-xl px-2.5 py-2 border" style={{ borderColor: 'var(--border)', background: 'var(--bg-global)' }}>
            <div className="flex flex-col">
              <button onClick={() => move(idx, -1)} disabled={idx === 0} className="disabled:opacity-20 hover:text-cyan-400" style={{ color: 'var(--text-muted)' }}><ChevronUp size={12} /></button>
              <button onClick={() => move(idx, 1)} disabled={idx === items.length - 1} className="disabled:opacity-20 hover:text-cyan-400" style={{ color: 'var(--text-muted)' }}><ChevronDown size={12} /></button>
            </div>
            {'color' in it && (
              <input type="color" value={it.color || '#94a3b8'} onChange={e => update.mutate({ id: it.id, data: { color: e.target.value } })}
                className="w-6 h-6 rounded cursor-pointer bg-transparent border-0 p-0" title="Change color" />
            )}
            <span className="flex-1 text-sm capitalize" style={{ color: 'var(--text-h)' }}>{it.name}</span>
            {withDescription && it.description && <span className="text-[11px] truncate max-w-[40%]" style={{ color: 'var(--text-muted)' }}>{it.description}</span>}
            {extraFlag && (
              <button onClick={() => update.mutate({ id: it.id, data: { [extraFlag.key]: !it[extraFlag.key] } })}
                className="text-[10px] px-2 py-0.5 rounded-lg font-bold flex items-center gap-1"
                style={{ background: it[extraFlag.key] ? 'rgba(34,211,238,0.15)' : 'transparent', color: it[extraFlag.key] ? '#22d3ee' : 'var(--text-muted)', border: '1px solid var(--border)' }}>
                {it[extraFlag.key] && <Check size={10} />}{extraFlag.label}
              </button>
            )}
            <button onClick={() => remove.mutate(it.id)} className="opacity-50 hover:opacity-100 hover:text-red-400"><Trash2 size={13} /></button>
          </li>
        ))}
        {items.length === 0 && <li className="text-xs px-1" style={{ color: 'var(--text-muted)' }}>None yet.</li>}
      </ul>

      <div className="flex items-center gap-2">
        <input type="color" value={draft.color} onChange={e => setDraft({ ...draft, color: e.target.value })}
          className="w-8 h-8 rounded cursor-pointer bg-transparent border-0 p-0" title="Color" />
        <input value={draft.name} onChange={e => setDraft({ ...draft, name: e.target.value })} placeholder={`New ${singular}…`}
          className="flex-1 text-sm bg-transparent border rounded-lg px-2.5 py-1.5 outline-none" style={{ borderColor: 'var(--border)', color: 'var(--text-h)' }} />
        {withDescription && (
          <input value={draft.description} onChange={e => setDraft({ ...draft, description: e.target.value })} placeholder="description (optional)"
            className="flex-1 text-sm bg-transparent border rounded-lg px-2.5 py-1.5 outline-none" style={{ borderColor: 'var(--border)', color: 'var(--text-h)' }} />
        )}
        <button disabled={!draft.name.trim() || create.isPending} onClick={() => create.mutate()}
          className="flex items-center gap-1 text-xs font-semibold px-3 py-2 rounded-lg disabled:opacity-40" style={{ background: 'rgba(34,211,238,0.15)', color: '#22d3ee' }}>
          <Plus size={13} /> Add
        </button>
      </div>
      {create.isError && <p className="text-xs text-red-400 mt-2">{create.error?.message}</p>}
    </div>
  )
}

/* ── Public form settings ── */
function PublicFormCard({ settings, departments, onChange }) {
  const save = useMutation({ mutationFn: (data) => helpdeskApi.settings.updateGeneral(data), onSuccess: onChange })
  const variant = settings.public_form_logo_variant || 'with_logo'

  return (
    <div className="rounded-2xl border p-5" style={{ borderColor: 'var(--border)', background: 'var(--bg-card)' }}>
      <h2 className="font-semibold text-sm mb-3 flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>Public Ticket Form</h2>

      <label className="flex items-center justify-between py-2">
        <span className="text-sm" style={{ color: 'var(--text-h)' }}>Public form enabled</span>
        <button onClick={() => save.mutate({ public_form_enabled: !settings.public_form_enabled })}
          className="w-11 h-6 rounded-full relative transition-colors" style={{ background: settings.public_form_enabled ? '#22d3ee' : 'var(--border)' }}>
          <span className="absolute top-0.5 w-5 h-5 rounded-full bg-white transition-all" style={{ left: settings.public_form_enabled ? '22px' : '2px' }} />
        </button>
      </label>

      <div className="py-2">
        <span className="text-sm block mb-2" style={{ color: 'var(--text-h)' }}>Form logo</span>
        <div className="flex gap-2">
          {['with_logo', 'without_logo'].map(v => (
            <button key={v} onClick={() => save.mutate({ public_form_logo_variant: v })}
              className="flex items-center gap-1.5 text-xs font-semibold px-3 py-2 rounded-xl border"
              style={{ borderColor: variant === v ? '#22d3ee' : 'var(--border)', background: variant === v ? 'rgba(34,211,238,0.12)' : 'transparent', color: variant === v ? '#22d3ee' : 'var(--text-muted)' }}>
              {variant === v ? <Check size={13} /> : <X size={13} style={{ opacity: 0.4 }} />}{v === 'with_logo' ? 'With logo' : 'Without logo'}
            </button>
          ))}
        </div>
      </div>

      <div className="py-2">
        <span className="text-sm block mb-2" style={{ color: 'var(--text-h)' }}>Default department</span>
        <select value={settings.default_department_id || ''} onChange={e => save.mutate({ default_department_id: e.target.value ? Number(e.target.value) : null })}
          className="text-sm bg-transparent border rounded-lg px-2.5 py-1.5 outline-none" style={{ borderColor: 'var(--border)', color: 'var(--text-h)' }}>
          <option value="" style={{ color: '#000' }}>— none —</option>
          {departments.map(d => <option key={d.id} value={d.id} style={{ color: '#000' }}>{d.name}</option>)}
        </select>
      </div>
    </div>
  )
}
