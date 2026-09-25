/**
 * POSH committee configuration.
 *
 * Composition, the committee's own role vocabulary, and who sits in each seat.
 * CONFIGURATION ONLY — no complaint, case or complainant data is reachable from
 * this screen, and none exists yet.
 *
 * The rule this page has to make legible: an ACTIVE committee must be able to
 * function. Enough active members to meet its own quorum, and at least one of
 * them in a role that can manage cases. An edit that would break that is
 * refused by the server, so the page shows the blockers up front rather than
 * letting somebody discover them on a failed save. Deactivate the committee
 * and it can be edited freely again — that is how one gets built in the first
 * place.
 *
 * No statutory minimum is implied anywhere here. A one-person committee is
 * valid as far as this software is concerned; whether it is lawful is a
 * question for a lawyer.
 */

import { useState, useEffect, useCallback } from 'react'
import {
  Scale, Plus, Trash2, Info, Save, X, Pencil, Users, AlertTriangle, ShieldCheck,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'
import { useToast } from '@/components/ui/Toast'
import ConfirmDialog from '@/components/ui/ConfirmDialog'

const inputStyle = {
  padding: '8px 11px', background: 'var(--bg-input)',
  border: '1px solid var(--border)', color: 'var(--text-p)',
}

export default function PoshCommitteeSettings() {
  const toast = useToast()

  const [data, setData]         = useState(null)
  const [loading, setLoading]   = useState(true)
  const [busy, setBusy]         = useState(false)
  const [draft, setDraft]       = useState(null)
  const [editing, setEditing]   = useState(null)
  const [rolesFor, setRolesFor] = useState(null)
  const [seatsFor, setSeatsFor] = useState(null)
  const [confirm, setConfirm]   = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setData(await hrApi.poshCommittees.list())
    } catch (e) {
      toast.error(e?.response?.data?.message || 'Could not load committees')
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
      // The server's refusals name the way out ("deactivate the committee
      // first, or appoint another member"), so they are shown verbatim.
      toast.error(e?.response?.data?.message || 'That did not work')
      return false
    } finally {
      setBusy(false)
    }
  }

  const committees = data?.committees ?? []
  const users      = data?.options?.users ?? []

  // Keep the open sub-dialogs pointed at fresh data after every reload.
  const current = (id) => committees.find(c => c.id === id)

  if (loading) return <HrLoading label="Loading committees…" />

  return (
    <div className="p-4 md:p-6 space-y-4">
      <header className="flex items-center gap-3">
        <Scale size={22} style={{ color: '#7C3AED' }} />
        <div className="min-w-0">
          <h1 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>POSH committees</h1>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            Who sits on the committee, and how many of them must agree.
          </p>
        </div>
      </header>

      <div className="flex items-start gap-2 rounded-xl p-3"
        style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
        <Info size={15} style={{ color: '#7C3AED', flexShrink: 0, marginTop: 1 }} />
        <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
          An <strong style={{ color: 'var(--text-h)' }}>active</strong> committee must have enough active
          members to meet its quorum and at least one member who can manage cases. Changes that would
          break that are refused — deactivate the committee first if you need to restructure it.
          This screen sets up the committee only; it shows no complaint or case information.
        </p>
      </div>

      {committees.length === 0 && (
        <HrEmpty icon={Scale} title="No committees" hint="Add one to get started." />
      )}

      <div className="space-y-2">
        {committees.map(c => (
          <div key={c.id} className="rounded-xl p-3"
            style={{
              background: 'var(--bg-card)', border: '1px solid var(--border)',
              opacity: c.is_active ? 1 : 0.7,
            }}>
            {editing?.id === c.id ? (
              <CommitteeForm
                value={editing} onChange={setEditing} busy={busy}
                onCancel={() => setEditing(null)}
                onSave={async () => {
                  const ok = await run(
                    () => hrApi.poshCommittees.update(c.id, editing), 'Committee updated')
                  if (ok) setEditing(null)
                }}
              />
            ) : (
              <>
                <div className="flex items-center gap-3 flex-wrap">
                  <div className="min-w-0 flex-1">
                    <p className="text-sm font-bold truncate" style={{ color: 'var(--text-h)' }}>
                      {c.name}
                      <span className="ml-2 text-[9px] font-black uppercase tracking-wider"
                        style={{ color: c.is_active ? '#10b981' : 'var(--text-muted)' }}>
                        {c.is_active ? 'Active' : 'Inactive'}
                      </span>
                    </p>
                    <p className="text-[10px] mt-0.5" style={{ color: 'var(--text-muted)' }}>
                      {c.quorum_mode === 'n_of_m'
                        ? `${c.quorum_required} of ${c.active_members} must agree`
                        : `All ${c.active_members} member${c.active_members === 1 ? '' : 's'} must agree`}
                      {' · '}{c.roles.length} role{c.roles.length === 1 ? '' : 's'}
                    </p>
                  </div>

                  <button type="button" disabled={busy} onClick={() => setRolesFor(c.id)}
                    className="flex items-center gap-1 rounded-lg text-[11px] font-bold px-2.5 py-1.5"
                    style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
                    <ShieldCheck size={13} /> Roles
                  </button>

                  <button type="button" disabled={busy} onClick={() => setSeatsFor(c.id)}
                    className="flex items-center gap-1 rounded-lg text-[11px] font-bold px-2.5 py-1.5"
                    style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
                    <Users size={13} /> Members
                  </button>

                  <button type="button" aria-label="Edit" disabled={busy}
                    onClick={() => setEditing({
                      id: c.id, name: c.name,
                      quorum_mode: c.quorum_mode, quorum_required: c.quorum_required ?? 1,
                    })}
                    className="p-2 rounded-lg" style={{ color: 'var(--text-muted)' }}>
                    <Pencil size={15} />
                  </button>

                  <button type="button" aria-pressed={c.is_active} disabled={busy}
                    aria-label={c.is_active ? 'Deactivate committee' : 'Activate committee'}
                    onClick={() => run(
                      () => hrApi.poshCommittees.setActive(c.id, !c.is_active),
                      c.is_active ? 'Committee deactivated' : 'Committee activated')}
                    className="w-11 h-6 rounded-full relative transition-all shrink-0"
                    style={{ background: c.is_active ? '#10b981' : 'var(--border)' }}>
                    <span className="absolute top-0.5 w-5 h-5 rounded-full bg-white shadow transition-all"
                      style={{ left: c.is_active ? '22px' : '2px' }} />
                  </button>

                  <button type="button" aria-label="Remove" disabled={busy}
                    onClick={() => setConfirm(c)}
                    className="p-2 rounded-lg" style={{ color: '#ef4444' }}>
                    <Trash2 size={15} />
                  </button>
                </div>

                {c.blockers.length > 0 && (
                  <div className="flex items-start gap-2 mt-2 rounded-lg p-2"
                    style={{ background: 'rgba(239,68,68,0.08)', border: '1px solid rgba(239,68,68,0.3)' }}>
                    <AlertTriangle size={13} style={{ color: '#ef4444', flexShrink: 0, marginTop: 1 }} />
                    <p className="text-[10px]" style={{ color: '#ef4444' }}>
                      {c.is_active ? 'This committee needs attention: ' : 'Cannot be activated yet: '}
                      {c.blockers.join(' ')}
                    </p>
                  </div>
                )}

                {c.members.length > 0 && (
                  <p className="text-[10px] mt-2" style={{ color: 'var(--text-muted)' }}>
                    {c.members.map(m =>
                      `${m.name}${m.role_label ? ` — ${m.role_label}` : ''}${m.is_active ? '' : ' (inactive)'}`
                    ).join(' · ')}
                  </p>
                )}
              </>
            )}
          </div>
        ))}
      </div>

      {draft ? (
        <div className="rounded-xl p-3" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <CommitteeForm
            value={draft} onChange={setDraft} busy={busy}
            onCancel={() => setDraft(null)}
            onSave={async () => {
              const ok = await run(() => hrApi.poshCommittees.create(draft), 'Committee added')
              if (ok) setDraft(null)
            }}
          />
        </div>
      ) : (
        <button type="button" disabled={busy}
          onClick={() => setDraft({ name: '', quorum_mode: 'all_members', quorum_required: 1 })}
          className="flex items-center gap-2 rounded-lg text-xs font-bold px-3 py-2"
          style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
          <Plus size={14} /> Add a committee
        </button>
      )}

      {rolesFor && current(rolesFor) && (
        <RoleDialog committee={current(rolesFor)} busy={busy} run={run}
          onClose={() => setRolesFor(null)} />
      )}

      {seatsFor && current(seatsFor) && (
        <SeatDialog committee={current(seatsFor)} users={users} busy={busy} run={run}
          onClose={() => setSeatsFor(null)} />
      )}

      {confirm && (
        <ConfirmDialog
          title="Remove this committee?"
          message={`"${confirm.name}" and its roles and members will be removed.`}
          confirmLabel="Remove"
          onCancel={() => setConfirm(null)}
          onConfirm={async () => {
            await run(() => hrApi.poshCommittees.remove(confirm.id), 'Committee removed')
            setConfirm(null)
          }}
        />
      )}
    </div>
  )
}

function CommitteeForm({ value, onChange, busy, onSave, onCancel }) {
  const set = (k, v) => onChange({ ...value, [k]: v })

  return (
    <div className="space-y-2">
      <input autoFocus value={value.name ?? ''} placeholder="Committee name"
        onChange={e => set('name', e.target.value)}
        className="rounded-lg text-sm w-full" style={inputStyle} />

      <div className="flex flex-wrap items-center gap-2">
        <select value={value.quorum_mode} onChange={e => set('quorum_mode', e.target.value)}
          aria-label="Quorum mode" className="rounded-lg text-xs" style={inputStyle}>
          <option value="all_members">All members must agree</option>
          <option value="n_of_m">A set number must agree</option>
        </select>

        {value.quorum_mode === 'n_of_m' && (
          <input type="number" min="1" aria-label="Quorum"
            value={value.quorum_required ?? 1}
            onChange={e => set('quorum_required', Number(e.target.value))}
            className="rounded-lg text-xs" style={{ ...inputStyle, width: 90 }} />
        )}

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

/** The committee's own seat titles. Nothing to do with staff roles elsewhere. */
function RoleDialog({ committee, busy, run, onClose }) {
  const [label, setLabel] = useState('')
  const [canManage, setCanManage] = useState(false)

  return (
    <Overlay title={`Roles on ${committee.name}`} onClose={onClose}
      hint="These titles belong to this committee. “Can manage cases” lets a member open or close an inquiry and publish a finding — it never overrides a quorum, breaks a tie, or reaches a case they are not a member of.">
      <div className="space-y-2">
        {committee.roles.map(r => (
          <div key={r.id} className="flex items-center gap-2 rounded-lg p-2"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            <span className="text-xs font-bold flex-1 min-w-0 truncate" style={{ color: 'var(--text-h)' }}>
              {r.label}
              {!r.is_active && <span className="ml-1.5 text-[9px]" style={{ color: 'var(--text-muted)' }}>inactive</span>}
            </span>

            <label className="flex items-center gap-1.5 text-[10px]" style={{ color: 'var(--text-muted)' }}>
              <input type="checkbox" checked={r.can_manage_case} disabled={busy}
                onChange={() => run(
                  () => hrApi.poshCommittees.updateRole(committee.id, r.id, { can_manage_case: !r.can_manage_case }),
                  'Role updated')} />
              Can manage cases
            </label>

            <button type="button" disabled={busy} aria-label="Remove role" style={{ color: '#ef4444' }}
              onClick={() => run(() => hrApi.poshCommittees.removeRole(committee.id, r.id), 'Role removed')}>
              <Trash2 size={14} />
            </button>
          </div>
        ))}

        <div className="flex flex-wrap items-center gap-2 pt-1">
          <input value={label} placeholder="New role, e.g. Presiding Officer"
            onChange={e => setLabel(e.target.value)}
            className="rounded-lg text-xs flex-1 min-w-[180px]" style={inputStyle} />
          <label className="flex items-center gap-1.5 text-[10px]" style={{ color: 'var(--text-muted)' }}>
            <input type="checkbox" checked={canManage} onChange={e => setCanManage(e.target.checked)} />
            Can manage cases
          </label>
          <button type="button" disabled={busy || !label.trim()}
            onClick={async () => {
              const ok = await run(
                () => hrApi.poshCommittees.addRole(committee.id, { label, can_manage_case: canManage }),
                'Role added')
              if (ok) { setLabel(''); setCanManage(false) }
            }}
            className="rounded-lg text-xs font-bold px-3 py-2"
            style={{ background: '#7C3AED', color: '#fff' }}>
            Add
          </button>
        </div>
      </div>
    </Overlay>
  )
}

/** Who sits in which seat. Saved as a whole set, never merged. */
function SeatDialog({ committee, users, busy, run, onClose }) {
  const [seats, setSeats] = useState(
    committee.members.map(m => ({ user_id: m.user_id, role_id: m.role_id, is_active: m.is_active }))
  )

  const roles = committee.roles.filter(r => r.is_active)
  const taken = new Set(seats.map(s => s.user_id))
  const free  = users.filter(u => !taken.has(u.value))

  const update = (i, patch) => setSeats(seats.map((s, n) => n === i ? { ...s, ...patch } : s))

  return (
    <Overlay title={`Members of ${committee.name}`} onClose={onClose}
      hint="One seat each — the same person cannot hold two roles on one committee. Saving replaces the whole membership.">
      <div className="space-y-2">
        {seats.length === 0 && (
          <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>Nobody yet.</p>
        )}

        {seats.map((s, i) => (
          <div key={s.user_id} className="flex flex-wrap items-center gap-2 rounded-lg p-2"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            <span className="text-xs font-bold flex-1 min-w-0 truncate" style={{ color: 'var(--text-h)' }}>
              {users.find(u => u.value === s.user_id)?.label ?? `User ${s.user_id}`}
            </span>

            <select value={s.role_id ?? ''} aria-label="Role" disabled={busy}
              onChange={e => update(i, { role_id: Number(e.target.value) })}
              className="rounded-lg text-[11px]" style={inputStyle}>
              <option value="">Choose a role…</option>
              {roles.map(r => <option key={r.id} value={r.id}>{r.label}</option>)}
            </select>

            <label className="flex items-center gap-1.5 text-[10px]" style={{ color: 'var(--text-muted)' }}>
              <input type="checkbox" checked={s.is_active}
                onChange={e => update(i, { is_active: e.target.checked })} />
              Active
            </label>

            <button type="button" aria-label="Remove member" style={{ color: '#ef4444' }}
              onClick={() => setSeats(seats.filter((_, n) => n !== i))}>
              <Trash2 size={14} />
            </button>
          </div>
        ))}

        <select value="" aria-label="Add member" disabled={busy || roles.length === 0}
          onChange={e => e.target.value &&
            setSeats([...seats, { user_id: Number(e.target.value), role_id: roles[0]?.id, is_active: true }])}
          className="rounded-lg text-xs w-full" style={inputStyle}>
          <option value="">{roles.length ? 'Add someone…' : 'Add a role first'}</option>
          {free.map(u => <option key={u.value} value={u.value}>{u.label}</option>)}
        </select>

        <div className="flex justify-end gap-2 pt-1">
          <button type="button" onClick={onClose} disabled={busy}
            className="rounded-lg text-xs font-bold px-3 py-2"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
            Cancel
          </button>
          <button type="button" disabled={busy || seats.some(s => !s.role_id)}
            onClick={() => run(
              () => hrApi.poshCommittees.setMembers(committee.id, seats), 'Members updated')}
            className="rounded-lg text-xs font-bold px-4 py-2"
            style={{ background: '#7C3AED', color: '#fff' }}>
            Save members
          </button>
        </div>
      </div>
    </Overlay>
  )
}

function Overlay({ title, hint, children, onClose }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ background: 'rgba(0,0,0,0.7)' }}>
      <div className="rounded-2xl shadow-2xl w-full max-w-xl flex flex-col"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', maxHeight: '85vh' }}>
        <div className="p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-black" style={{ color: 'var(--text-h)' }}>{title}</h2>
          {hint && <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>{hint}</p>}
        </div>
        <div className="p-5 overflow-y-auto">{children}</div>
        <div className="p-3 flex justify-end" style={{ borderTop: '1px solid var(--border)' }}>
          <button type="button" onClick={onClose}
            className="rounded-lg text-xs font-bold px-3 py-2"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)' }}>
            Close
          </button>
        </div>
      </div>
    </div>
  )
}
