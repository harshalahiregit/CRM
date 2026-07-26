import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { SlidersHorizontal, Plus, GripVertical, Pencil, Trash2, Check, X, Lock } from 'lucide-react'
import { statusApi } from '@/services/statusApi'
import { ConfirmModal } from '@/components/ui/SearchPicker'
import { useAuth } from '@/context/AuthContext'

/**
 * Advanced Status Manager — configure the Task and Project status lists.
 *
 * Statuses have an immutable `key` (what records store) and an editable `name`
 * (the label), so renaming "Not Started" to "Backlog" never touches a task. The
 * seeded statuses are is_system: renamable and recolourable, never deletable,
 * because service logic keys off them (date_finished on the closing status).
 */

const ACCENT = 'var(--color-primary-500)'
const TYPES = [{ key: 'task', label: 'Task Statuses' }, { key: 'project', label: 'Project Statuses' }]

export default function StatusManager() {
  const { user } = useAuth()
  const [type, setType] = useState('task')
  const isAdmin = user?.role === 'admin'

  return (
    <div className="max-w-3xl">
      <div className="flex items-center gap-2 mb-1">
        <span className="w-8 h-8 rounded-xl flex items-center justify-center"
          style={{ background: `color-mix(in srgb, ${ACCENT} 14%, transparent)` }}>
          <SlidersHorizontal size={16} style={{ color: ACCENT }} />
        </span>
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Advanced Status Manager</h1>
      </div>
      <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>
        Rename, recolour, reorder and add statuses. Built-in statuses can’t be deleted, and a status in use can’t be removed until its records move.
      </p>

      {!isAdmin && (
        <p className="text-xs px-3 py-2 rounded-lg mb-4"
          style={{ background: 'color-mix(in srgb, var(--color-warning-500) 12%, transparent)', color: 'var(--color-warning-600)' }}>
          You can view statuses, but only admins can change them.
        </p>
      )}

      <div className="flex gap-1 mb-4" style={{ borderBottom: '1px solid var(--border)' }}>
        {TYPES.map(t => (
          <button key={t.key} onClick={() => setType(t.key)}
            className="px-3 py-2 text-xs font-bold transition-colors"
            style={{
              color: type === t.key ? ACCENT : 'var(--text-muted)',
              borderBottom: `2px solid ${type === t.key ? ACCENT : 'transparent'}`,
              marginBottom: -1,
            }}>
            {t.label}
          </button>
        ))}
      </div>

      <StatusList type={type} isAdmin={isAdmin} />
    </div>
  )
}

function StatusList({ type, isAdmin }) {
  const qc = useQueryClient()
  const [editing, setEditing] = useState(null)   // id or 'new'
  const [confirmDelete, setConfirmDelete] = useState(null)
  const [err, setErr] = useState('')
  const [dragId, setDragId] = useState(null)

  const { data: statuses = [], isLoading } = useQuery({ queryKey: ['statuses', type], queryFn: () => statusApi.list(type) })
  const bust = () => { qc.invalidateQueries({ queryKey: ['statuses', type] }); qc.invalidateQueries({ queryKey: ['statuses'] }) }
  const onErr = (e) => setErr(e?.message || 'That action failed.')

  const save = useMutation({
    mutationFn: ({ id, data }) => id === 'new' ? statusApi.create(type, data) : statusApi.update(type, id, data),
    onSuccess: () => { setErr(''); setEditing(null); bust() }, onError: onErr,
  })
  const remove = useMutation({
    mutationFn: (id) => statusApi.remove(type, id),
    onSuccess: () => { setConfirmDelete(null); bust() }, onError: onErr,
  })
  const reorder = useMutation({ mutationFn: (ids) => statusApi.reorder(type, ids), onSuccess: bust, onError: onErr })

  const onDrop = (targetId) => {
    if (!dragId || dragId === targetId) { setDragId(null); return }
    const ids = statuses.map(s => s.id)
    const from = ids.indexOf(dragId), to = ids.indexOf(targetId)
    ids.splice(to, 0, ids.splice(from, 1)[0])
    setDragId(null)
    reorder.mutate(ids)
  }

  if (isLoading) return <div className="rounded-2xl animate-pulse" style={{ height: 200, background: 'var(--bg-card)' }} />

  return (
    <div>
      {err && (
        <p className="text-xs px-3 py-2 rounded-lg mb-3"
          style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>{err}</p>
      )}

      <div className="rounded-2xl overflow-hidden" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        {statuses.map(s => (
          editing === s.id ? (
            <StatusRow key={s.id} status={s} statuses={statuses} onSave={data => save.mutate({ id: s.id, data })}
              onCancel={() => setEditing(null)} busy={save.isPending} />
          ) : (
            <div key={s.id}
              draggable={isAdmin}
              onDragStart={() => setDragId(s.id)}
              onDragOver={e => e.preventDefault()}
              onDrop={() => onDrop(s.id)}
              className="flex items-center gap-3 px-4 py-3"
              style={{ borderBottom: '1px solid var(--border)', opacity: dragId === s.id ? 0.4 : 1 }}>
              {isAdmin && <GripVertical size={13} style={{ color: 'var(--text-muted)', cursor: 'grab' }} />}
              <span className="w-3 h-3 rounded-full shrink-0" style={{ background: s.color }} />
              <span className="flex-1 min-w-0">
                <span className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>{s.name}</span>
                <span className="ml-2 text-[10px] font-mono" style={{ color: 'var(--text-muted)' }}>{s.key}</span>
              </span>
              {s.is_closed_status && (
                <span className="text-[9px] font-bold px-1.5 py-0.5 rounded"
                  style={{ background: 'color-mix(in srgb, var(--color-success-500) 14%, transparent)', color: 'var(--color-success-500)' }}>closing</span>
              )}
              {s.is_system && <Lock size={11} style={{ color: 'var(--text-muted)' }} title="Built-in — can’t be deleted" />}
              {isAdmin && (
                <div className="flex items-center gap-1">
                  <IconBtn onClick={() => setEditing(s.id)} label="Edit"><Pencil size={12} /></IconBtn>
                  {!s.is_system && <IconBtn onClick={() => setConfirmDelete(s)} label="Delete" danger><Trash2 size={12} /></IconBtn>}
                </div>
              )}
            </div>
          )
        ))}

        {editing === 'new' && (
          <StatusRow status={null} statuses={statuses} onSave={data => save.mutate({ id: 'new', data })}
            onCancel={() => setEditing(null)} busy={save.isPending} />
        )}
      </div>

      {isAdmin && editing !== 'new' && (
        <button onClick={() => setEditing('new')} className="flex items-center gap-1.5 text-xs font-bold mt-3 px-3 py-2 rounded-xl"
          style={{ border: '1px dashed var(--border)', color: ACCENT }}>
          <Plus size={13} /> Add status
        </button>
      )}

      <ConfirmModal open={Boolean(confirmDelete)} onClose={() => setConfirmDelete(null)}
        onConfirm={() => remove.mutate(confirmDelete.id)}
        title={`Delete “${confirmDelete?.name}”?`}
        message="This status will be removed. Records using it must be moved first, or the delete is refused."
        confirmLabel="Delete" danger />
    </div>
  )
}

const PRESET_COLORS = ['#64748b', '#3b82f6', '#0284c7', '#84cc16', '#22c55e', '#f97316', '#ef4444', '#8b5cf6', '#ec4899', '#f59e0b']

function StatusRow({ status, statuses, onSave, onCancel, busy }) {
  const [name, setName] = useState(status?.name || '')
  const [color, setColor] = useState(status?.color || PRESET_COLORS[0])
  const [transitions, setTransitions] = useState(status?.can_be_changed_to || [])

  const others = statuses.filter(s => s.key !== status?.key)
  const toggleTransition = (key) =>
    setTransitions(t => t.includes(key) ? t.filter(k => k !== key) : [...t, key])

  const submit = () => {
    const t = name.trim()
    if (!t) return
    onSave({ name: t, color, can_be_changed_to: transitions.length ? transitions : null })
  }

  return (
    <div className="px-4 py-3 space-y-3" style={{ borderBottom: '1px solid var(--border)', background: 'var(--bg-input)' }}>
      <div className="flex items-center gap-2">
        <input value={name} onChange={e => setName(e.target.value)} placeholder="Status name" autoFocus
          className="flex-1 rounded-lg outline-none"
          style={{ padding: '7px 10px', fontSize: 13, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }} />
        <button onClick={submit} disabled={!name.trim() || busy} className="w-8 h-8 rounded-lg flex items-center justify-center disabled:opacity-40"
          style={{ background: ACCENT, color: '#fff' }} aria-label="Save"><Check size={14} /></button>
        <button onClick={onCancel} className="w-8 h-8 rounded-lg flex items-center justify-center"
          style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)' }} aria-label="Cancel"><X size={14} /></button>
      </div>

      <div className="flex items-center gap-1.5 flex-wrap">
        {PRESET_COLORS.map(c => (
          <button key={c} onClick={() => setColor(c)} aria-label={`Colour ${c}`}
            className="w-6 h-6 rounded-lg" style={{ background: c, outline: color === c ? '2px solid var(--text-h)' : 'none', outlineOffset: 1 }} />
        ))}
      </div>

      {others.length > 0 && (
        <div>
          <p className="text-[10px] font-bold uppercase tracking-wide mb-1.5" style={{ color: 'var(--text-muted)' }}>
            Can change to <span className="font-normal">(empty = no restriction)</span>
          </p>
          <div className="flex flex-wrap gap-1.5">
            {others.map(s => {
              const on = transitions.includes(s.key)
              return (
                <button key={s.key} onClick={() => toggleTransition(s.key)}
                  className="text-[11px] font-semibold px-2 py-1 rounded-lg"
                  style={{
                    background: on ? `color-mix(in srgb, ${s.color} 18%, transparent)` : 'var(--bg-card)',
                    color: on ? s.color : 'var(--text-muted)',
                    border: `1px solid ${on ? s.color : 'var(--border)'}`,
                  }}>
                  {s.name}
                </button>
              )
            })}
          </div>
        </div>
      )}
    </div>
  )
}

function IconBtn({ onClick, label, danger, children }) {
  return (
    <button onClick={onClick} aria-label={label} title={label}
      className="w-7 h-7 rounded-lg flex items-center justify-center"
      style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: danger ? 'var(--color-danger-500)' : 'var(--text-muted)' }}>
      {children}
    </button>
  )
}
