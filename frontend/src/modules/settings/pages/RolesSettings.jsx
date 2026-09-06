import { useCallback, useEffect, useState } from 'react'
import { Shield, Plus, Trash2, Lock, RefreshCw, Users } from 'lucide-react'
import Modal from '@/components/ui/Modal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import LoadError from '@/components/ui/LoadError'
import { useToast } from '@/hooks/useToast'
import { settingsApi } from '@/services/settingsApi'

/**
 * Staff job roles, maintained here instead of by a developer.
 *
 * Two things are called "role" in this system and the page says so out loud,
 * because conflating them is how a settings screen locks a company out:
 *
 *  - A JOB role is what a staff member does — Site Supervisor, HR Executive.
 *    That is this list, and it is fully editable.
 *  - An ACCOUNT TYPE is a whole front door: admin, vendor, doctor, client.
 *    Each has its own portal, its own login branch and its own middleware, so
 *    creating one is a feature and not a settings change. They are listed at the
 *    bottom, read-only, so the picture is complete rather than mysteriously
 *    missing the roles everyone actually recognises.
 *
 * The key (slug) is fixed at creation because route guards spell it. Renaming
 * it would silently strip access from everyone holding it, so only the label
 * can be edited afterwards.
 */
export default function RolesSettings() {
  const toast = useToast()
  const [rows, setRows] = useState(null)
  const [accountTypes, setAccountTypes] = useState({})
  const [loadError, setLoadError] = useState(null)
  const [editing, setEditing] = useState(null)   // row, or {} for a new one
  const [confirm, setConfirm] = useState(null)

  const load = useCallback(() => {
    settingsApi.roles.list()
      .then(d => { setLoadError(null); setRows(d?.data ?? []); setAccountTypes(d?.account_types ?? {}) })
      .catch(e => { setRows([]); setLoadError(e) })
  }, [])

  useEffect(() => { load() }, [load])

  const remove = async () => {
    try {
      await settingsApi.roles.remove(confirm.id)
      toast.success('Role deleted.')
      load()
    } catch (e) { toast.error(e) } finally { setConfirm(null) }
  }

  return (
    <div>
      <header className="flex items-end justify-between gap-3 flex-wrap mb-4">
        <div>
          <h2 className="text-lg font-black flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
            <Shield size={18} /> Roles
          </h2>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            What a staff member does. Assign one on the staff member&rsquo;s own record.
          </p>
        </div>
        <div className="flex gap-2">
          <button onClick={() => setEditing({})} className="btn-3d-primary text-sm flex items-center gap-1.5 px-3 py-2">
            <Plus size={15} /> Add a role
          </button>
          <button onClick={load} className="btn-3d text-sm flex items-center gap-1.5 px-3 py-2">
            <RefreshCw size={14} /> Refresh
          </button>
        </div>
      </header>

      <div className="card-3d overflow-hidden">
        <table className="w-full text-sm" style={{ borderCollapse: 'collapse' }}>
          <thead>
            <tr className="text-[11px] uppercase tracking-wide text-left" style={{ color: 'var(--text-muted)' }}>
              {['Role', 'Key (used by permissions)', 'People', 'Status', ''].map((h, i) => (
                <th key={i} className="px-3 py-2.5 font-bold">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {loadError ? (
              <tr><td colSpan={5} className="p-2"><LoadError error={loadError} onRetry={load} /></td></tr>
            ) : rows === null ? (
              <tr><td colSpan={5} className="px-3 py-5" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>
            ) : rows.length === 0 ? (
              <tr><td colSpan={5} className="px-3 py-5" style={{ color: 'var(--text-muted)' }}>No roles yet.</td></tr>
            ) : rows.map(r => (
              <tr key={r.id} style={{ borderTop: '1px solid var(--border)' }}>
                <td className="px-3 py-2.5">
                  <div className="font-bold flex items-center gap-1.5" style={{ color: 'var(--text-h)' }}>
                    {r.name}
                    {r.is_system && <Lock size={11} style={{ color: 'var(--text-muted)' }} title="Built into the application" />}
                  </div>
                  {r.description && <div className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{r.description}</div>}
                </td>
                <td className="px-3 py-2.5 font-mono text-[11.5px]" style={{ color: 'var(--text-muted)' }}>{r.slug}</td>
                <td className="px-3 py-2.5" style={{ color: 'var(--text-muted)' }}>
                  <span className="inline-flex items-center gap-1"><Users size={12} /> {r.user_count ?? 0}</span>
                </td>
                <td className="px-3 py-2.5">
                  <Pill tone={r.is_active ? '#10b981' : '#6b7280'}>{r.is_active ? 'Active' : 'Inactive'}</Pill>
                </td>
                <td className="px-3 py-2.5 whitespace-nowrap">
                  <button onClick={() => setEditing(r)} className="btn-3d text-[11.5px] px-2.5 py-1">Edit</button>
                  {!r.is_system && (
                    <button onClick={() => setConfirm(r)} className="btn-3d text-[11.5px] px-2.5 py-1 ml-1.5"
                      style={{ color: '#ef4444', borderColor: '#ef444455' }}>
                      <Trash2 size={12} />
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* The other kind of "role" — named so the list does not look incomplete. */}
      {Object.keys(accountTypes).length > 0 && (
        <div className="card-3d mt-5 p-4">
          <h3 className="text-sm font-black mb-1" style={{ color: 'var(--text-h)' }}>Account types</h3>
          <p className="text-[11.5px] mb-3" style={{ color: 'var(--text-muted)' }}>
            These decide which <strong>portal</strong> somebody signs in to, not what they do inside it.
            Each one has its own login screen and its own rules, so they are part of the application
            rather than a setting — they are shown here so the picture is complete.
          </p>
          <div className="flex flex-wrap gap-2">
            {Object.entries(accountTypes).map(([slug, label]) => (
              <span key={slug} className="text-[11.5px] font-semibold px-2.5 py-1 rounded-lg inline-flex items-center gap-1.5"
                style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                <Lock size={10} /> {label}
              </span>
            ))}
          </div>
        </div>
      )}

      {editing && <RoleModal role={editing.id ? editing : null} onClose={() => setEditing(null)}
        onSaved={() => { load(); setEditing(null) }} />}

      {confirm && <ConfirmDialog
        title={`Delete "${confirm.name}"?`}
        message={
          (confirm.user_count ?? 0) > 0
            ? `${confirm.user_count} ${confirm.user_count === 1 ? 'person holds' : 'people hold'} this role. Move them to another role first — this will be refused.`
            : 'Nobody holds this role, so removing it changes nothing for anyone.'
        }
        confirmLabel="Delete"
        onConfirm={remove}
        onCancel={() => setConfirm(null)}
      />}
    </div>
  )
}

function RoleModal({ role, onClose, onSaved }) {
  const toast = useToast()
  const isEdit = !!role
  const [form, setForm] = useState({
    name: role?.name || '', description: role?.description || '', is_active: role?.is_active ?? true,
  })
  const [busy, setBusy] = useState(false)
  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))

  const save = async () => {
    if (!form.name.trim()) return toast.error('Give the role a name.')
    setBusy(true)
    try {
      if (isEdit) await settingsApi.roles.update(role.id, form)
      else await settingsApi.roles.create(form)
      toast.success(isEdit ? 'Role updated.' : 'Role created.')
      onSaved()
    } catch (e) { toast.error(e) } finally { setBusy(false) }
  }

  return (
    <Modal open onClose={onClose} style={{ width: 'min(500px, 96vw)' }}>
      <div className="px-4 py-3" style={{ borderBottom: '1px solid var(--border)' }}>
        <h3 className="font-black text-[15px]" style={{ color: 'var(--text-h)' }}>
          {isEdit ? `Edit ${role.name}` : 'Add a role'}
        </h3>
      </div>
      <div className="p-4 flex flex-col gap-3">
        <div>
          <label className="label">Role name</label>
          <input className="input-3d text-sm" value={form.name} onChange={e => set('name', e.target.value)}
            placeholder="Site Supervisor" />
        </div>

        {isEdit ? (
          <div className="text-[11.5px] p-2.5 rounded-lg"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
            Key: <strong className="font-mono">{role.slug}</strong>
            <br />
            The key cannot be changed — permissions are written against it, so renaming it would
            remove access from everyone who has this role. The display name above is free to change.
          </div>
        ) : (
          <div className="text-[11.5px] p-2.5 rounded-lg"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
            A key is generated from the name (&ldquo;Site Supervisor&rdquo; &rarr; <span className="font-mono">site_supervisor</span>)
            and is fixed once saved.
          </div>
        )}

        <div>
          <label className="label">Description (optional)</label>
          <input className="input-3d text-sm" value={form.description} onChange={e => set('description', e.target.value)}
            placeholder="What this role is for" />
        </div>

        <label className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-body)' }}>
          <input type="checkbox" checked={!!form.is_active} onChange={e => set('is_active', e.target.checked)} />
          Active — can be assigned to staff
        </label>
      </div>
      <div className="px-4 py-3 flex justify-end gap-2" style={{ borderTop: '1px solid var(--border)' }}>
        <button onClick={onClose} className="btn-3d text-sm px-3 py-2">Cancel</button>
        <button onClick={save} disabled={busy} className="btn-3d-primary text-sm px-4 py-2">
          {busy ? 'Saving…' : isEdit ? 'Save' : 'Create role'}
        </button>
      </div>
    </Modal>
  )
}

const Pill = ({ tone, children }) => (
  <span className="text-[10.5px] font-bold px-2 py-0.5 rounded-md"
    style={{ background: `${tone}1f`, color: tone }}>{children}</span>
)
