/**
 * The onboarding checklist every new joiner receives.
 *
 * These 27 tasks used to be a PHP constant, so a company that wanted one more
 * induction step — or did not issue laptops — needed a developer and a deploy.
 *
 * The screen holds no copy of the vocabulary: categories and owner roles come
 * back with the list, from the same place the server validates against, so the
 * form cannot offer something the save would refuse.
 *
 * The important thing this page has to say out loud is that editing here does
 * NOT reach an onboarding already under way. Each onboarding takes its own copy
 * of the list when it starts, so nothing is renamed, reordered or removed
 * underneath somebody half way through — which is the first thing an
 * administrator handed an editor is right to worry about.
 */

import { useState, useEffect, useCallback } from 'react'
import {
  ListChecks, Plus, Trash2, ChevronUp, ChevronDown, Info, Save, X, Pencil,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import { useToast } from '@/components/ui/Toast'
import ConfirmDialog from '@/components/ui/ConfirmDialog'

const inputStyle = {
  padding: '8px 11px', background: 'var(--bg-input)',
  border: '1px solid var(--border)', color: 'var(--text-p)',
}

const blank = { title: '', category: 'Orientation', owner_role: 'HR', is_mandatory: false }

export default function OnboardingChecklistSettings() {
  const toast = useToast()

  const [data, setData]         = useState(null)
  const [loading, setLoading]   = useState(true)
  const [busy, setBusy]         = useState(false)
  const [draft, setDraft]       = useState(null)   // new-task form, or null
  const [editing, setEditing]   = useState(null)   // { id, ...fields } or null
  const [confirm, setConfirm]   = useState(null)   // item pending deletion

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setData(await hrApi.onboardingChecklist.list())
    } catch (e) {
      toast.error(e?.response?.data?.message || 'Could not load the checklist')
    } finally {
      setLoading(false)
    }
  }, [toast])

  useEffect(() => { load() }, [load])

  const run = async (fn, okMessage) => {
    setBusy(true)
    try {
      await fn()
      if (okMessage) toast.success(okMessage)
      await load()
      return true
    } catch (e) {
      toast.error(e?.response?.data?.message || 'That did not work')
      return false
    } finally {
      setBusy(false)
    }
  }

  const items      = data?.items ?? []
  const categories = data?.options?.categories ?? []
  const ownerRoles = data?.options?.owner_roles ?? []
  const configured = data?.configured

  const move = (index, delta) => {
    const next = [...items]
    const target = index + delta
    if (target < 0 || target >= next.length) return
    ;[next[index], next[target]] = [next[target], next[index]]
    run(() => hrApi.onboardingChecklist.reorder(next.map(i => i.id)))
  }

  if (loading) return <HrLoading label="Loading checklist…" />

  return (
    <div className="p-4 md:p-6 space-y-4">
      <header className="flex items-center gap-3">
        <ListChecks size={22} style={{ color: '#7C3AED' }} />
        <div className="min-w-0">
          <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Onboarding checklist</h1>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            The tasks created for every new joiner.
          </p>
        </div>
      </header>

      <div className="flex items-start gap-2 rounded-xl p-3"
        style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
        <Info size={15} style={{ color: '#7C3AED', flexShrink: 0, marginTop: 1 }} />
        <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
          Changes here apply to <strong style={{ color: 'var(--text-h)' }}>onboardings started from now on</strong>.
          Anyone already part-way through keeps the checklist they were given, so nothing is
          renamed, reordered or removed underneath them.
        </p>
      </div>

      {!configured && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl p-3"
          style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
            This workspace is using the standard checklist. Copy it here to start editing it.
          </p>
          <button type="button" disabled={busy}
            onClick={() => run(() => hrApi.onboardingChecklist.adoptDefaults(), 'Standard checklist copied')}
            className="rounded-lg text-xs font-bold px-3 py-2"
            style={{ background: '#7C3AED', color: '#fff' }}>
            Copy the standard checklist
          </button>
        </div>
      )}

      {items.length === 0 && configured && (
        <HrEmpty icon={ListChecks} title="No tasks"
          hint="New joiners will receive no checklist until you add one." />
      )}

      <div className="space-y-2">
        {items.map((item, index) => (
          <div key={item.id} className="rounded-xl p-3"
            style={{
              background: 'var(--bg-card)', border: '1px solid var(--border)',
              opacity: item.is_active ? 1 : 0.55,
            }}>
            {editing?.id === item.id ? (
              <TaskForm
                value={editing} onChange={setEditing}
                categories={categories} ownerRoles={ownerRoles} busy={busy}
                onCancel={() => setEditing(null)}
                onSave={async () => {
                  const ok = await run(
                    () => hrApi.onboardingChecklist.update(item.id, editing), 'Task updated')
                  if (ok) setEditing(null)
                }}
              />
            ) : (
              <div className="flex items-center gap-3">
                <div className="flex flex-col">
                  <button type="button" aria-label="Move up" disabled={busy || index === 0}
                    onClick={() => move(index, -1)} style={{ color: 'var(--text-muted)' }}>
                    <ChevronUp size={15} />
                  </button>
                  <button type="button" aria-label="Move down" disabled={busy || index === items.length - 1}
                    onClick={() => move(index, 1)} style={{ color: 'var(--text-muted)' }}>
                    <ChevronDown size={15} />
                  </button>
                </div>

                <div className="min-w-0 flex-1">
                  <p className="text-sm font-bold truncate" style={{ color: 'var(--text-h)' }}>
                    {item.title}
                    {item.is_mandatory && (
                      <span className="ml-2 text-[9px] font-black uppercase tracking-wider"
                        style={{ color: '#ef4444' }}>Required</span>
                    )}
                  </p>
                  <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
                    {item.category_label} · {item.owner_role}
                    {!item.is_active && ' · Disabled'}
                  </p>
                </div>

                <button type="button" aria-label="Edit" disabled={busy}
                  onClick={() => setEditing({ ...item })}
                  className="p-2 rounded-lg" style={{ color: 'var(--text-muted)' }}>
                  <Pencil size={15} />
                </button>

                <button type="button" aria-pressed={item.is_active} disabled={busy}
                  aria-label={item.is_active ? 'Disable task' : 'Enable task'}
                  onClick={() => run(
                    () => hrApi.onboardingChecklist.setActive(item.id, !item.is_active),
                    item.is_active ? 'Task disabled' : 'Task enabled')}
                  className="w-11 h-6 rounded-full relative transition-all shrink-0"
                  style={{ background: item.is_active ? '#10b981' : 'var(--border)' }}>
                  <span className="absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-all"
                    style={{ left: item.is_active ? '22px' : '2px' }} />
                </button>

                <button type="button" aria-label="Remove" disabled={busy}
                  onClick={() => setConfirm(item)}
                  className="p-2 rounded-lg" style={{ color: '#ef4444' }}>
                  <Trash2 size={15} />
                </button>
              </div>
            )}
          </div>
        ))}
      </div>

      {draft ? (
        <div className="rounded-xl p-3" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <TaskForm
            value={draft} onChange={setDraft}
            categories={categories} ownerRoles={ownerRoles} busy={busy}
            onCancel={() => setDraft(null)}
            onSave={async () => {
              const ok = await run(() => hrApi.onboardingChecklist.create(draft), 'Task added')
              if (ok) setDraft(null)
            }}
          />
        </div>
      ) : (
        <button type="button" disabled={busy} onClick={() => setDraft({ ...blank })}
          className="flex items-center gap-2 rounded-lg text-xs font-bold px-3 py-2"
          style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
          <Plus size={14} /> Add a task
        </button>
      )}

      {confirm && (
        <ConfirmDialog
          title="Remove this task?"
          message={`"${confirm.title}" will no longer be added to new onboardings. Anyone already part-way through keeps it. Disable it instead if you want to keep the record.`}
          confirmLabel="Remove"
          onCancel={() => setConfirm(null)}
          onConfirm={async () => {
            await run(() => hrApi.onboardingChecklist.remove(confirm.id), 'Task removed')
            setConfirm(null)
          }}
        />
      )}
    </div>
  )
}

/** Add and edit share one form — two copies would drift on the next field. */
function TaskForm({ value, onChange, categories, ownerRoles, busy, onSave, onCancel }) {
  const set = (key, v) => onChange({ ...value, [key]: v })

  return (
    <div className="space-y-2">
      <input
        autoFocus value={value.title ?? ''} placeholder="What has to happen?"
        onChange={e => set('title', e.target.value)}
        className="rounded-lg text-sm w-full" style={inputStyle} />

      <div className="flex flex-wrap items-center gap-2">
        <select value={value.category} onChange={e => set('category', e.target.value)}
          aria-label="Category" className="rounded-lg text-xs" style={inputStyle}>
          {categories.map(c => <option key={c.value} value={c.value}>{c.label}</option>)}
        </select>

        <select value={value.owner_role} onChange={e => set('owner_role', e.target.value)}
          aria-label="Owner" className="rounded-lg text-xs" style={inputStyle}>
          {ownerRoles.map(r => <option key={r} value={r}>{r}</option>)}
        </select>

        <label className="flex items-center gap-2 text-xs" style={{ color: 'var(--text-muted)' }}>
          <input type="checkbox" checked={!!value.is_mandatory}
            onChange={e => set('is_mandatory', e.target.checked)} />
          Required
        </label>

        <div className="flex-1" />

        <button type="button" onClick={onCancel} disabled={busy}
          className="flex items-center gap-1 rounded-lg text-xs font-bold px-3 py-2"
          style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
          <X size={13} /> Cancel
        </button>
        <button type="button" onClick={onSave} disabled={busy || !String(value.title ?? '').trim()}
          className="flex items-center gap-1 rounded-lg text-xs font-bold px-3 py-2"
          style={{ background: '#7C3AED', color: '#fff' }}>
          <Save size={13} /> Save
        </button>
      </div>
    </div>
  )
}
