/**
 * Who may sign off each exit-clearance department.
 *
 * One permission check used to cover all five departments, so anybody on the
 * HR queue could clear IT, Finance or the reporting manager's item. This is
 * where that becomes explicit.
 *
 * The badge on each row is the important part of this screen. A department is
 * in one of three states, and an administrator has to be able to tell them
 * apart at a glance:
 *
 *   FALLBACK      nobody named — the HR queue still acts, exactly as before.
 *                 Every department starts here, so nothing breaks on the day
 *                 this ships.
 *   CONFIGURED    named people act, and only them. Not the HR queue, not an
 *                 administrator.
 *   MISCONFIGURED somebody is named but none of them can act any more —
 *                 deactivated, or removed from the role. Nobody can clear the
 *                 department until it is repaired, and it does NOT quietly
 *                 revert to the HR queue.
 */

import { useState, useEffect, useCallback } from 'react'
import {
  ShieldCheck, Plus, Trash2, ChevronUp, ChevronDown, Info, Save, X, Pencil, Users,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import { useToast } from '@/components/ui/Toast'
import ConfirmDialog from '@/components/ui/ConfirmDialog'

const inputStyle = {
  padding: '8px 11px', background: 'var(--bg-input)',
  border: '1px solid var(--border)', color: 'var(--text-p)',
}

const MODES = {
  fallback: {
    label: 'HR queue fallback', color: '#f59e0b',
    hint: 'No one is configured, so anyone who manages the HR queue can action this department.',
  },
  configured: {
    label: 'Configured', color: '#10b981',
    hint: 'Only the people below can action this department.',
  },
  misconfigured: {
    label: 'Nobody can action this', color: '#ef4444',
    hint: 'People are configured but none of them is an active staff account. This department cannot be actioned until that is fixed.',
  },
}

export default function ClearanceDepartmentSettings() {
  const toast = useToast()

  const [data, setData]       = useState(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy]       = useState(false)
  const [draft, setDraft]     = useState(null)
  const [editing, setEditing] = useState(null)
  const [authFor, setAuthFor] = useState(null)   // department whose people are being edited
  const [confirm, setConfirm] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setData(await hrApi.clearanceDepartments.list())
    } catch (e) {
      toast.error(e?.response?.data?.message || 'Could not load clearance departments')
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

  const departments = data?.departments ?? []
  const userOptions = data?.options?.users ?? []
  const roleOptions = data?.options?.roles ?? []

  const move = (index, delta) => {
    const next = [...departments]
    const target = index + delta
    if (target < 0 || target >= next.length) return
    ;[next[index], next[target]] = [next[target], next[index]]
    run(() => hrApi.clearanceDepartments.reorder(next.map(d => d.id)))
  }

  if (loading) return <HrLoading label="Loading departments…" />

  return (
    <div className="p-4 md:p-6 space-y-4">
      <header className="flex items-center gap-3">
        <ShieldCheck size={22} style={{ color: '#7C3AED' }} />
        <div className="min-w-0">
          <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Exit clearance departments</h1>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            Who signs off each department when somebody leaves.
          </p>
        </div>
      </header>

      <div className="flex items-start gap-2 rounded-xl p-3"
        style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
        <Info size={15} style={{ color: '#7C3AED', flexShrink: 0, marginTop: 1 }} />
        <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
          A department with nobody configured stays on the <strong style={{ color: 'var(--text-h)' }}>HR queue</strong>,
          exactly as before. Once you name someone, <strong style={{ color: 'var(--text-h)' }}>only they</strong> can
          action that department — the HR queue no longer can. Changes apply to clearances
          started from now on; departments already on an existing clearance keep their names.
        </p>
      </div>

      {departments.length === 0 && (
        <HrEmpty icon={ShieldCheck} title="No departments"
          hint="New clearances will use the standard five until you add your own." />
      )}

      <div className="space-y-2">
        {departments.map((dept, index) => {
          const mode = MODES[dept.authorization] ?? MODES.fallback

          return (
            <div key={dept.id} className="rounded-xl p-3"
              style={{
                background: 'var(--bg-card)', border: '1px solid var(--border)',
                opacity: dept.is_active ? 1 : 0.55,
              }}>
              {editing?.id === dept.id ? (
                <DepartmentForm
                  value={editing} onChange={setEditing} busy={busy}
                  onCancel={() => setEditing(null)}
                  onSave={async () => {
                    const ok = await run(
                      () => hrApi.clearanceDepartments.update(dept.id, editing), 'Department updated')
                    if (ok) setEditing(null)
                  }}
                />
              ) : (
                <>
                  <div className="flex items-center gap-3">
                    <div className="flex flex-col">
                      <button type="button" aria-label="Move up" disabled={busy || index === 0}
                        onClick={() => move(index, -1)} style={{ color: 'var(--text-muted)' }}>
                        <ChevronUp size={15} />
                      </button>
                      <button type="button" aria-label="Move down" disabled={busy || index === departments.length - 1}
                        onClick={() => move(index, 1)} style={{ color: 'var(--text-muted)' }}>
                        <ChevronDown size={15} />
                      </button>
                    </div>

                    <div className="min-w-0 flex-1">
                      <p className="text-sm font-bold truncate" style={{ color: 'var(--text-h)' }}>
                        {dept.name}
                        {!dept.is_mandatory && (
                          <span className="ml-2 text-[9px] font-black uppercase tracking-wider"
                            style={{ color: 'var(--text-muted)' }}>Optional</span>
                        )}
                        {!dept.is_active && (
                          <span className="ml-2 text-[9px] font-black uppercase tracking-wider"
                            style={{ color: 'var(--text-muted)' }}>Disabled</span>
                        )}
                      </p>
                      <p className="text-[10px] mt-0.5 flex items-center gap-1.5">
                        <span className="px-1.5 py-0.5 rounded font-bold"
                          style={{ background: `${mode.color}22`, color: mode.color }}>
                          {mode.label}
                        </span>
                        <span style={{ color: 'var(--text-muted)' }}>{mode.hint}</span>
                      </p>
                    </div>

                    <button type="button" disabled={busy}
                      onClick={() => setAuthFor({
                        id: dept.id, name: dept.name,
                        userIds: dept.users.map(u => u.id),
                        roleIds: dept.staff_roles.map(r => r.id),
                      })}
                      className="flex items-center gap-1 rounded-lg text-[11px] font-bold px-2.5 py-1.5"
                      style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
                      <Users size={13} /> People
                    </button>

                    <button type="button" aria-label="Edit" disabled={busy}
                      onClick={() => setEditing({ id: dept.id, name: dept.name, is_mandatory: dept.is_mandatory })}
                      className="p-2 rounded-lg" style={{ color: 'var(--text-muted)' }}>
                      <Pencil size={15} />
                    </button>

                    <button type="button" aria-pressed={dept.is_active} disabled={busy}
                      aria-label={dept.is_active ? 'Disable department' : 'Enable department'}
                      onClick={() => run(
                        () => hrApi.clearanceDepartments.setActive(dept.id, !dept.is_active),
                        dept.is_active ? 'Department disabled' : 'Department enabled')}
                      className="w-11 h-6 rounded-full relative transition-all shrink-0"
                      style={{ background: dept.is_active ? '#10b981' : 'var(--border)' }}>
                      <span className="absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-all"
                        style={{ left: dept.is_active ? '22px' : '2px' }} />
                    </button>

                    <button type="button" aria-label="Remove" disabled={busy}
                      onClick={() => setConfirm(dept)}
                      className="p-2 rounded-lg" style={{ color: '#ef4444' }}>
                      <Trash2 size={15} />
                    </button>
                  </div>

                  {(dept.users.length > 0 || dept.staff_roles.length > 0) && (
                    <p className="text-[10px] mt-2 pl-8" style={{ color: 'var(--text-muted)' }}>
                      {dept.users.map(u => u.name).concat(
                        dept.staff_roles.map(r => `${r.name} (role)`)
                      ).join(' · ')}
                    </p>
                  )}
                </>
              )}
            </div>
          )
        })}
      </div>

      {draft ? (
        <div className="rounded-xl p-3" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <DepartmentForm
            value={draft} onChange={setDraft} busy={busy}
            onCancel={() => setDraft(null)}
            onSave={async () => {
              const ok = await run(() => hrApi.clearanceDepartments.create(draft), 'Department added')
              if (ok) setDraft(null)
            }}
          />
        </div>
      ) : (
        <button type="button" disabled={busy} onClick={() => setDraft({ name: '', is_mandatory: true })}
          className="flex items-center gap-2 rounded-lg text-xs font-bold px-3 py-2"
          style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
          <Plus size={14} /> Add a department
        </button>
      )}

      {authFor && (
        <AuthorityDialog
          department={authFor} users={userOptions} roles={roleOptions} busy={busy}
          onChange={setAuthFor}
          onClose={() => setAuthFor(null)}
          onSave={async () => {
            const ok = await run(
              () => hrApi.clearanceDepartments.authorities(authFor.id, authFor.userIds, authFor.roleIds),
              'Authorities updated')
            if (ok) setAuthFor(null)
          }}
        />
      )}

      {confirm && (
        <ConfirmDialog
          title="Remove this department?"
          message={`"${confirm.name}" will no longer appear on new clearances. Clearances already under way keep it. Disable it instead if you want to keep the record.`}
          confirmLabel="Remove"
          onCancel={() => setConfirm(null)}
          onConfirm={async () => {
            await run(() => hrApi.clearanceDepartments.remove(confirm.id), 'Department removed')
            setConfirm(null)
          }}
        />
      )}
    </div>
  )
}

function DepartmentForm({ value, onChange, busy, onSave, onCancel }) {
  const set = (key, v) => onChange({ ...value, [key]: v })

  return (
    <div className="space-y-2">
      <input autoFocus value={value.name ?? ''} placeholder="Department name"
        onChange={e => set('name', e.target.value)}
        className="rounded-lg text-sm w-full" style={inputStyle} />

      <div className="flex flex-wrap items-center gap-2">
        <label className="flex items-center gap-2 text-xs" style={{ color: 'var(--text-muted)' }}>
          <input type="checkbox" checked={!!value.is_mandatory}
            onChange={e => set('is_mandatory', e.target.checked)} />
          Required for the clearance to complete
        </label>

        <div className="flex-1" />

        <button type="button" onClick={onCancel} disabled={busy}
          className="flex items-center gap-1 rounded-lg text-xs font-bold px-3 py-2"
          style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
          <X size={13} /> Cancel
        </button>
        <button type="button" onClick={onSave} disabled={busy || !String(value.name ?? '').trim()}
          className="flex items-center gap-1 rounded-lg text-xs font-bold px-3 py-2"
          style={{ background: '#7C3AED', color: '#fff' }}>
          <Save size={13} /> Save
        </button>
      </div>
    </div>
  )
}

/** Named people and staff roles together — the two add up, they do not replace. */
function AuthorityDialog({ department, users, roles, busy, onChange, onSave, onClose }) {
  const toggle = (key, id) => {
    const current = department[key]
    onChange({
      ...department,
      [key]: current.includes(id) ? current.filter(x => x !== id) : [...current, id],
    })
  }

  const none = department.userIds.length === 0 && department.roleIds.length === 0

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ background: 'rgba(0,0,0,0.7)' }}>
      <div className="rounded-2xl shadow-2xl w-full max-w-lg flex flex-col"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', maxHeight: '85vh' }}>
        <div className="p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-black" style={{ color: 'var(--text-h)' }}>
            Who can clear {department.name}?
          </h2>
          <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>
            {none
              ? 'Nobody selected — this department stays on the HR queue.'
              : 'Only these people can action this department. The HR queue no longer can.'}
          </p>
        </div>

        <div className="p-5 space-y-4 overflow-y-auto">
          <section>
            <h3 className="text-[10px] font-black uppercase tracking-wider mb-2" style={{ color: 'var(--text-muted)' }}>
              Staff roles
            </h3>
            <p className="text-[10px] mb-2" style={{ color: 'var(--text-muted)' }}>
              Everyone in the role can act, so you do not have to update this when somebody joins or leaves.
            </p>
            <div className="flex flex-wrap gap-1.5">
              {roles.map(r => (
                <Chip key={r.value} on={department.roleIds.includes(r.value)}
                  onClick={() => toggle('roleIds', r.value)} label={r.label} />
              ))}
            </div>
          </section>

          <section>
            <h3 className="text-[10px] font-black uppercase tracking-wider mb-2" style={{ color: 'var(--text-muted)' }}>
              Named people
            </h3>
            <div className="flex flex-wrap gap-1.5">
              {users.map(u => (
                <Chip key={u.value} on={department.userIds.includes(u.value)}
                  onClick={() => toggle('userIds', u.value)} label={u.label} />
              ))}
            </div>
          </section>
        </div>

        <div className="p-4 flex justify-end gap-2" style={{ borderTop: '1px solid var(--border)' }}>
          <button type="button" onClick={onClose} disabled={busy}
            className="rounded-lg text-xs font-bold px-3 py-2"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
            Cancel
          </button>
          <button type="button" onClick={onSave} disabled={busy}
            className="rounded-lg text-xs font-bold px-4 py-2"
            style={{ background: '#7C3AED', color: '#fff' }}>
            Save
          </button>
        </div>
      </div>
    </div>
  )
}

function Chip({ on, onClick, label }) {
  return (
    <button type="button" onClick={onClick} aria-pressed={on}
      className="rounded-lg text-[11px] font-semibold px-2.5 py-1.5 text-left"
      style={{
        background: on ? 'rgba(124,58,237,0.15)' : 'var(--bg-input)',
        border: `1px solid ${on ? '#7C3AED' : 'var(--border)'}`,
        color: on ? '#7C3AED' : 'var(--text-p)',
      }}>
      {label}
    </button>
  )
}
