import { useCallback, useEffect, useState } from 'react'
import { Building2, Plus, Trash2, RefreshCw, Users } from 'lucide-react'
import Modal from '@/components/ui/Modal'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import LoadError from '@/components/ui/LoadError'
import { useToast } from '@/hooks/useToast'
import { settingsApi } from '@/services/settingsApi'

/**
 * The company's departments, maintained here instead of by a developer.
 *
 * This is the company-wide list. It is deliberately not the same thing as the
 * HR module's own departments or the Helpdesk's ticket queues — those two serve
 * their modules and stay where they are. Before this there was no company list
 * at all: `users.department` was a free-text column, so two people in the same
 * department could be spelled differently and never group together.
 *
 * A department with people in it cannot be deleted, because removing it would
 * leave those records pointing at a department that no longer exists.
 */
export default function DepartmentsSettings() {
  const toast = useToast()
  const [rows, setRows] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [editing, setEditing] = useState(null)
  const [confirm, setConfirm] = useState(null)

  const load = useCallback(() => {
    settingsApi.departments.list()
      .then(d => { setLoadError(null); setRows(Array.isArray(d) ? d : (d?.data ?? [])) })
      .catch(e => { setRows([]); setLoadError(e) })
  }, [])

  useEffect(() => { load() }, [load])

  const remove = async () => {
    try {
      await settingsApi.departments.remove(confirm.id)
      toast.success('Department deleted.')
      load()
    } catch (e) { toast.error(e) } finally { setConfirm(null) }
  }

  return (
    <div>
      <header className="flex items-end justify-between gap-3 flex-wrap mb-4">
        <div>
          <h2 className="text-lg font-black flex items-center gap-2" style={{ color: 'var(--text-h)' }}>
            <Building2 size={18} /> Departments
          </h2>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            The company&rsquo;s department list, used across the workspace.
          </p>
        </div>
        <div className="flex gap-2">
          <button onClick={() => setEditing({})} className="btn-3d-primary text-sm flex items-center gap-1.5 px-3 py-2">
            <Plus size={15} /> Add a department
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
              {['Department', 'Code', 'Head', 'People', 'Status', ''].map((h, i) => (
                <th key={i} className="px-3 py-2.5 font-bold">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {loadError ? (
              <tr><td colSpan={6} className="p-2"><LoadError error={loadError} onRetry={load} /></td></tr>
            ) : rows === null ? (
              <tr><td colSpan={6} className="px-3 py-5" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>
            ) : rows.length === 0 ? (
              <tr><td colSpan={6} className="px-3 py-5" style={{ color: 'var(--text-muted)' }}>
                No departments yet. Add the first one.
              </td></tr>
            ) : rows.map(d => (
              <tr key={d.id} style={{ borderTop: '1px solid var(--border)' }}>
                <td className="px-3 py-2.5">
                  <div className="font-bold" style={{ color: 'var(--text-h)' }}>{d.name}</div>
                  {d.description && <div className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{d.description}</div>}
                </td>
                <td className="px-3 py-2.5 font-mono text-[11.5px]" style={{ color: 'var(--text-muted)' }}>{d.code || '—'}</td>
                <td className="px-3 py-2.5" style={{ color: 'var(--text-muted)' }}>{d.head?.name || '—'}</td>
                <td className="px-3 py-2.5" style={{ color: 'var(--text-muted)' }}>
                  <span className="inline-flex items-center gap-1"><Users size={12} /> {d.user_count ?? 0}</span>
                </td>
                <td className="px-3 py-2.5">
                  <Pill tone={d.is_active ? '#10b981' : '#6b7280'}>{d.is_active ? 'Active' : 'Inactive'}</Pill>
                </td>
                <td className="px-3 py-2.5 whitespace-nowrap">
                  <button onClick={() => setEditing(d)} className="btn-3d text-[11.5px] px-2.5 py-1">Edit</button>
                  <button onClick={() => setConfirm(d)} className="btn-3d text-[11.5px] px-2.5 py-1 ml-1.5"
                    style={{ color: '#ef4444', borderColor: '#ef444455' }}>
                    <Trash2 size={12} />
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {editing && <DepartmentModal department={editing.id ? editing : null} onClose={() => setEditing(null)}
        onSaved={() => { load(); setEditing(null) }} />}

      {confirm && <ConfirmDialog
        title={`Delete "${confirm.name}"?`}
        message={
          (confirm.user_count ?? 0) > 0
            ? `${confirm.user_count} ${confirm.user_count === 1 ? 'person is' : 'people are'} in this department. Move them first — this will be refused.`
            : 'Nobody is in this department, so removing it changes nothing for anyone.'
        }
        confirmLabel="Delete"
        onConfirm={remove}
        onCancel={() => setConfirm(null)}
      />}
    </div>
  )
}

function DepartmentModal({ department, onClose, onSaved }) {
  const toast = useToast()
  const isEdit = !!department
  const [form, setForm] = useState({
    name: department?.name || '', code: department?.code || '',
    description: department?.description || '', is_active: department?.is_active ?? true,
  })
  const [busy, setBusy] = useState(false)
  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))

  const save = async () => {
    if (!form.name.trim()) return toast.error('Give the department a name.')
    setBusy(true)
    try {
      if (isEdit) await settingsApi.departments.update(department.id, form)
      else await settingsApi.departments.create(form)
      toast.success(isEdit ? 'Department updated.' : 'Department created.')
      onSaved()
    } catch (e) { toast.error(e) } finally { setBusy(false) }
  }

  return (
    <Modal open onClose={onClose} style={{ width: 'min(500px, 96vw)' }}>
      <div className="px-4 py-3" style={{ borderBottom: '1px solid var(--border)' }}>
        <h3 className="font-black text-[15px]" style={{ color: 'var(--text-h)' }}>
          {isEdit ? `Edit ${department.name}` : 'Add a department'}
        </h3>
      </div>
      <div className="p-4 flex flex-col gap-3">
        <div>
          <label className="label">Department name</label>
          <input className="input-3d text-sm" value={form.name} onChange={e => set('name', e.target.value)}
            placeholder="Projects" />
        </div>
        <div>
          <label className="label">Short code (optional)</label>
          <input className="input-3d text-sm" value={form.code} onChange={e => set('code', e.target.value)}
            placeholder="PRJ" />
        </div>
        <div>
          <label className="label">Description (optional)</label>
          <input className="input-3d text-sm" value={form.description} onChange={e => set('description', e.target.value)} />
        </div>
        <label className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-body)' }}>
          <input type="checkbox" checked={!!form.is_active} onChange={e => set('is_active', e.target.checked)} />
          Active
        </label>
      </div>
      <div className="px-4 py-3 flex justify-end gap-2" style={{ borderTop: '1px solid var(--border)' }}>
        <button onClick={onClose} className="btn-3d text-sm px-3 py-2">Cancel</button>
        <button onClick={save} disabled={busy} className="btn-3d-primary text-sm px-4 py-2">
          {busy ? 'Saving…' : isEdit ? 'Save' : 'Create department'}
        </button>
      </div>
    </Modal>
  )
}

const Pill = ({ tone, children }) => (
  <span className="text-[10.5px] font-bold px-2 py-0.5 rounded-md"
    style={{ background: `${tone}1f`, color: tone }}>{children}</span>
)
