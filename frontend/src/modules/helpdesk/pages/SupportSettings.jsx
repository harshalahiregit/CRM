import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  Flag, CircleDot, Building2, Plus, Trash2,
  ChevronUp, ChevronDown, Check, X, Settings, Globe
} from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'
import Select from '../components/ui/Select'

const KEY = ['helpdesk-settings']

export default function SupportSettings() {
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: KEY, queryFn: helpdeskApi.settings.all })
  const invalidate = () => qc.invalidateQueries({ queryKey: KEY })

  if (isLoading) {
    return (
      <div className="max-w-4xl space-y-4">
        {[1, 2, 3, 4].map(i => (
          <div key={i} className="h-40 rounded-2xl animate-pulse" style={{ background: 'var(--border)' }} />
        ))}
      </div>
    )
  }

  const { priorities = [], statuses = [], departments = [], settings = {} } = data || {}

  return (
    <div className="max-w-4xl space-y-5 animate-[tiltIn_0.35s_ease_forwards]">

      {/* Page header */}
      <div>
        <p className="label-caps mb-0.5">Helpdesk</p>
        <h1
          className="font-black"
          style={{ fontSize: 'clamp(1.3rem,2.2vw,1.7rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}
        >
          Support <span className="text-gradient">Settings</span>
        </h1>
        <p className="text-sm mt-0.5" style={{ color: 'var(--text-muted)' }}>
          Manage values agents can pick from when handling tickets
        </p>
      </div>

      <ListManager
        type="priorities"
        title="Priorities"
        description="Urgency levels + their SLA response / resolution targets (hours)"
        singular="priority"
        icon={Flag}
        accent="#ef4444"
        items={priorities}
        extraFlags={[{ key: 'is_default', label: 'default' }]}
        slaTargets
        onChange={invalidate}
      />

      <ListManager
        type="statuses"
        title="Statuses"
        description="Ticket lifecycle stages — flag a status to pause the SLA clock"
        singular="status"
        icon={CircleDot}
        accent="#22d3ee"
        items={statuses}
        extraFlags={[{ key: 'is_closed_status', label: 'closed' }, { key: 'sla_paused', label: 'SLA paused' }]}
        onChange={invalidate}
      />

      <ListManager
        type="departments"
        title="Departments"
        description="Route tickets to teams"
        singular="department"
        icon={Building2}
        accent="#a78bfa"
        items={departments}
        withDescription
        onChange={invalidate}
      />

      <PublicFormCard settings={settings} departments={departments} onChange={invalidate} />
    </div>
  )
}

/* ── Reusable list manager ─────────────────────────────────────── */
function ListManager({ type, title, description, singular, icon: Icon, accent, items, extraFlags = [], slaTargets, withDescription, onChange }) {
  const [draft, setDraft] = useState({ name: '', color: '#22d3ee', description: '' })

  const create = useMutation({
    mutationFn: () => helpdeskApi.settings.createItem(type, draft),
    onSuccess: () => { setDraft({ name: '', color: '#22d3ee', description: '' }); onChange() },
  })
  const update = useMutation({
    mutationFn: ({ id, data }) => helpdeskApi.settings.updateItem(type, id, data),
    onSuccess: onChange,
  })
  const remove = useMutation({
    mutationFn: (id) => helpdeskApi.settings.deleteItem(type, id),
    onSuccess: onChange,
  })
  const reorder = useMutation({
    mutationFn: (ids) => helpdeskApi.settings.reorder(type, ids),
    onSuccess: onChange,
  })

  const move = (idx, dir) => {
    const next = [...items]
    const j = idx + dir
    if (j < 0 || j >= next.length) return
    ;[next[idx], next[j]] = [next[j], next[idx]]
    reorder.mutate(next.map(i => i.id))
  }

  return (
    <div
      className="rounded-2xl p-5"
      style={{
        background: 'var(--bg-card)',
        border: '1px solid var(--border)',
        boxShadow: 'var(--shadow-card)',
      }}
    >
      {/* Section header */}
      <div className="flex items-center gap-3 mb-4">
        <div
          className="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: `${accent}18` }}
        >
          <Icon size={16} style={{ color: accent }} />
        </div>
        <div>
          <h2 className="font-bold text-sm flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
            {title}
            <span
              className="text-[10px] font-bold px-2 py-0.5 rounded-full"
              style={{ background: 'var(--bg-global)', color: 'var(--text-muted)' }}
            >
              {items.length}
            </span>
          </h2>
          {description && (
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{description}</p>
          )}
        </div>
      </div>

      {/* Items list */}
      <ul className="space-y-2 mb-4">
        {items.map((it, idx) => (
          <li
            key={it.id}
            className="flex items-center gap-2.5 rounded-xl px-3 py-2.5"
            style={{
              background: 'var(--bg-global)',
              border: '1px solid var(--border)',
            }}
          >
            {/* Reorder arrows */}
            <div className="flex flex-col gap-0.5">
              <button
                onClick={() => move(idx, -1)}
                disabled={idx === 0}
                className="disabled:opacity-20 hover:text-cyan-400 transition-colors"
                style={{ color: 'var(--text-muted)' }}
              >
                <ChevronUp size={11} />
              </button>
              <button
                onClick={() => move(idx, 1)}
                disabled={idx === items.length - 1}
                className="disabled:opacity-20 hover:text-cyan-400 transition-colors"
                style={{ color: 'var(--text-muted)' }}
              >
                <ChevronDown size={11} />
              </button>
            </div>

            {/* Color picker */}
            {'color' in it && (
              <input
                type="color"
                value={it.color || '#94a3b8'}
                onChange={e => update.mutate({ id: it.id, data: { color: e.target.value } })}
                className="w-7 h-7 rounded-lg cursor-pointer border-0 p-0.5"
                style={{ background: 'transparent' }}
                title="Change color"
              />
            )}

            {/* Name */}
            <span className="flex-1 text-sm capitalize font-semibold" style={{ color: 'var(--text-h)' }}>
              {it.name}
            </span>

            {/* Description */}
            {withDescription && it.description && (
              <span
                className="text-xs truncate max-w-[40%]"
                style={{ color: 'var(--text-muted)' }}
              >
                {it.description}
              </span>
            )}

            {/* Per-priority SLA targets (hours) */}
            {slaTargets && (
              <div className="flex items-center gap-1.5">
                <SlaHrs value={it.first_response_hours} label="resp" onSave={v => update.mutate({ id: it.id, data: { first_response_hours: v } })} />
                <SlaHrs value={it.resolution_hours} label="resolve" onSave={v => update.mutate({ id: it.id, data: { resolution_hours: v } })} />
              </div>
            )}

            {/* Extra flag toggles */}
            {extraFlags.map(flag => (
              <button
                key={flag.key}
                onClick={() => update.mutate({ id: it.id, data: { [flag.key]: !it[flag.key] } })}
                className="flex items-center gap-1 text-[10px] font-bold px-2.5 py-1 rounded-lg transition-all duration-150"
                style={{
                  background: it[flag.key] ? `${accent}18` : 'transparent',
                  color: it[flag.key] ? accent : 'var(--text-muted)',
                  border: `1px solid ${it[flag.key] ? `${accent}35` : 'var(--border)'}`,
                }}
              >
                {it[flag.key] && <Check size={10} />}
                {flag.label}
              </button>
            ))}

            {/* Delete */}
            <button
              onClick={() => remove.mutate(it.id)}
              className="opacity-40 hover:opacity-100 hover:text-red-400 transition-all duration-150"
              style={{ color: 'var(--text-muted)' }}
            >
              <Trash2 size={13} />
            </button>
          </li>
        ))}
        {items.length === 0 && (
          <li className="text-sm text-center py-4" style={{ color: 'var(--text-muted)' }}>
            No {type} yet.
          </li>
        )}
      </ul>

      {/* Add row */}
      <div className="flex items-center gap-2 pt-3" style={{ borderTop: '1px solid var(--border)' }}>
        <input
          type="color"
          value={draft.color}
          onChange={e => setDraft({ ...draft, color: e.target.value })}
          className="w-8 h-8 rounded-lg cursor-pointer border-0 p-0.5"
          style={{ background: 'transparent' }}
        />
        <input
          value={draft.name}
          onChange={e => setDraft({ ...draft, name: e.target.value })}
          placeholder={`New ${singular}…`}
          onKeyDown={e => e.key === 'Enter' && draft.name.trim() && create.mutate()}
          className="flex-1 text-sm rounded-xl px-3 py-2 outline-none"
          style={{ border: '1px solid var(--border)', color: 'var(--text-h)', background: 'var(--bg-input)' }}
        />
        {withDescription && (
          <input
            value={draft.description}
            onChange={e => setDraft({ ...draft, description: e.target.value })}
            placeholder="description (optional)"
            className="flex-1 text-sm rounded-xl px-3 py-2 outline-none"
            style={{ border: '1px solid var(--border)', color: 'var(--text-h)', background: 'var(--bg-input)' }}
          />
        )}
        <button
          disabled={!draft.name.trim() || create.isPending}
          onClick={() => create.mutate()}
          className="flex items-center gap-1.5 text-xs font-bold px-4 py-2 rounded-xl disabled:opacity-40 transition-all hover:opacity-80"
          style={{ background: `${accent}18`, color: accent, border: `1px solid ${accent}30` }}
        >
          <Plus size={13} />
          Add
        </button>
      </div>
      {create.isError && (
        <p className="text-xs mt-2" style={{ color: '#ef4444' }}>{create.error?.message}</p>
      )}
    </div>
  )
}

/* ── Small SLA-hours input (saves on blur/Enter) ───────────────── */
function SlaHrs({ value, label, onSave }) {
  const [v, setV] = useState(value ?? '')
  useEffect(() => setV(value ?? ''), [value])
  const commit = () => {
    const n = v === '' ? null : Math.max(0, parseInt(v, 10) || 0)
    if (n !== (value ?? null)) onSave(n)
  }
  return (
    <label className="flex items-center gap-1 text-[10px] font-semibold" style={{ color: 'var(--text-muted)' }} title={`${label} target (hours)`}>
      <input
        type="number" min="0" value={v}
        onChange={e => setV(e.target.value)}
        onBlur={commit}
        onKeyDown={e => e.key === 'Enter' && e.currentTarget.blur()}
        placeholder="—"
        className="w-11 text-xs text-center rounded-lg px-1 py-1 outline-none"
        style={{ border: '1px solid var(--border)', color: 'var(--text-h)', background: 'var(--bg-input)' }}
      />
      <span>{label}</span>
    </label>
  )
}

/* ── Public form settings ─────────────────────────────────────── */
function PublicFormCard({ settings, departments, onChange }) {
  const save = useMutation({
    mutationFn: (data) => helpdeskApi.settings.updateGeneral(data),
    onSuccess: onChange,
  })
  const variant = settings.public_form_logo_variant || 'with_logo'

  return (
    <div
      className="rounded-2xl p-5"
      style={{
        background: 'var(--bg-card)',
        border: '1px solid var(--border)',
        boxShadow: 'var(--shadow-card)',
      }}
    >
      {/* Header */}
      <div className="flex items-center gap-3 mb-5">
        <div
          className="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: 'rgba(16,185,129,0.12)' }}
        >
          <Globe size={16} style={{ color: '#10b981' }} />
        </div>
        <div>
          <h2 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Public Ticket Form</h2>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            Let customers submit tickets without logging in
          </p>
        </div>
      </div>

      <div className="space-y-4">
        {/* Enable toggle */}
        <div
          className="flex items-center justify-between py-3"
          style={{ borderBottom: '1px solid var(--border)' }}
        >
          <div>
            <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>Public form enabled</p>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
              Allow anyone to submit a support ticket
            </p>
          </div>
          <button
            onClick={() => save.mutate({ public_form_enabled: !settings.public_form_enabled })}
            className="relative w-12 h-6 rounded-full transition-colors duration-200 shrink-0"
            style={{ background: settings.public_form_enabled ? '#22d3ee' : 'var(--border)' }}
          >
            <span
              className="absolute top-0.5 w-5 h-5 rounded-full bg-white transition-all duration-200"
              style={{ left: settings.public_form_enabled ? 26 : 2 }}
            />
          </button>
        </div>

        {/* Logo variant */}
        <div className="py-2" style={{ borderBottom: '1px solid var(--border)' }}>
          <p className="text-sm font-semibold mb-3" style={{ color: 'var(--text-h)' }}>Form logo style</p>
          <div className="flex gap-2">
            {['with_logo', 'without_logo'].map(v => (
              <button
                key={v}
                onClick={() => save.mutate({ public_form_logo_variant: v })}
                className="flex items-center gap-2 text-xs font-bold px-4 py-2 rounded-xl transition-all duration-150"
                style={{
                  background: variant === v ? 'rgba(34,211,238,0.12)' : 'var(--bg-input)',
                  color: variant === v ? '#22d3ee' : 'var(--text-muted)',
                  border: `1px solid ${variant === v ? 'rgba(34,211,238,0.3)' : 'var(--border)'}`,
                }}
              >
                {variant === v ? <Check size={12} /> : <X size={12} style={{ opacity: 0.4 }} />}
                {v === 'with_logo' ? 'With logo' : 'Without logo'}
              </button>
            ))}
          </div>
        </div>

        {/* Default department */}
        <div className="py-2">
          <p className="text-sm font-semibold mb-2" style={{ color: 'var(--text-h)' }}>Default department</p>
          <Select
            value={settings.default_department_id ?? ''}
            onChange={v => save.mutate({ default_department_id: v ? Number(v) : null })}
            options={[{ value: '', label: '— none —' }, ...departments.map(d => ({ value: d.id, label: d.name }))]}
            placeholder="— none —"
            ariaLabel="Default department"
            style={{ maxWidth: 260 }}
          />
        </div>
      </div>
    </div>
  )
}
