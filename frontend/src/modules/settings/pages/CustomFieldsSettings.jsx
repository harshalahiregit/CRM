import { useState, useEffect } from 'react'
import { Plus, Edit2, Trash2, ChevronUp, ChevronDown } from 'lucide-react'
import { customerApi } from '@/services/customerApi'
import { useToast } from '@/hooks/useToast'
import ConfirmDialog from '@/components/ui/ConfirmDialog'
import CustomFieldForm, { FIELD_TYPES, WIDTHS } from '@/modules/customer/components/CustomFieldForm'
import ToggleSwitch from '@/modules/customer/components/ToggleSwitch'

// Platform-wide custom fields (meeting 1.2): pick a module, define fields for
// it; they render on that module's forms. Modules activate as they ship.
const MODULES = [
  { value: 'customers', label: 'Customers', ready: true },
  { value: 'contacts', label: 'Contacts', ready: true },
  { value: 'tasks', label: 'Tasks', ready: false },
  { value: 'projects', label: 'Projects', ready: false },
  { value: 'tickets', label: 'Tickets', ready: false },
]

const typeLabel = (t) => FIELD_TYPES.find(x => x.value === t)?.label || t
const widthLabel = (w) => WIDTHS.find(x => x.value === Number(w))?.label || 'Full width'

export default function CustomFieldsSettings() {
  const toast = useToast()
  const [module, setModule] = useState('customers')
  const [fields, setFields] = useState(null)
  const [editing, setEditing] = useState(null)   // null = list; {} new; {id} edit
  const [confirmDel, setConfirmDel] = useState(null)

  const load = () => {
    setFields(null)
    customerApi.customFields.list(module).then(setFields).catch(e => toast.error(e.message))
  }
  useEffect(() => { setEditing(null); load() }, [module])

  const doDelete = async () => {
    try { await customerApi.customFields.remove(confirmDel.id); toast.success('Custom field deleted'); load() }
    catch (e) { toast.error(e.message) } finally { setConfirmDel(null) }
  }

  const toggleActive = async (f) => {
    setFields(list => list.map(x => x.id === f.id ? { ...x, active: !x.active } : x))
    try { await customerApi.customFields.update(f.id, { name: f.name, type: f.type, active: !f.active }) }
    catch (e) { toast.error(e.message); load() }
  }

  // Move a field up/down and persist the new order for the whole module.
  const move = async (idx, dir) => {
    const next = [...fields]
    const j = idx + dir
    if (j < 0 || j >= next.length) return
    ;[next[idx], next[j]] = [next[j], next[idx]]
    setFields(next)
    try { await customerApi.customFields.reorder(next.map(f => f.id)) }
    catch (e) { toast.error(e.message); load() }
  }

  return (
    <div className="card-3d">
      <div className="flex flex-wrap items-start justify-between gap-3 mb-5">
        <div>
          <h2 className="font-bold text-base" style={{ color: 'var(--text-h)' }}>Custom Fields</h2>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            Define extra fields per module — they appear automatically on that module's forms.
          </p>
        </div>
        {!editing && (
          <button onClick={() => setEditing({})} className="flex items-center gap-1 px-4 py-2 rounded-xl text-xs font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
            <Plus size={13} /> New Field
          </button>
        )}
      </div>

      {/* Module selector */}
      <div className="flex gap-1.5 flex-wrap mb-5">
        {MODULES.map(m => (
          <button key={m.value} disabled={!m.ready}
            onClick={() => m.ready && setModule(m.value)}
            title={m.ready ? '' : 'Coming soon'}
            className={`px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all ${!m.ready ? 'opacity-40 cursor-not-allowed' : ''}`}
            style={module === m.value
              ? { background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff' }
              : { background: 'var(--bg-input)', color: 'var(--text-body)', border: '1px solid var(--border)' }}>
            {m.label}{!m.ready && ' ·soon'}
          </button>
        ))}
      </div>

      {editing ? (
        <CustomFieldForm
          fieldTo={module}
          initial={editing.id ? editing : null}
          onSaved={() => { setEditing(null); load() }}
          onCancel={() => setEditing(null)}
        />
      ) : (
        <div className="space-y-2.5">
          {fields === null ? (
            <div className="skeleton h-16 rounded-xl" style={{ background: 'var(--border)' }} />
          ) : fields.length === 0 ? (
            <div className="py-10 text-center text-sm" style={{ color: 'var(--text-muted)' }}>No custom fields for {MODULES.find(m => m.value === module)?.label} yet.</div>
          ) : fields.map((f, i) => (
            <div key={f.id} className="flex items-center justify-between gap-2 p-3 rounded-xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', opacity: f.active ? 1 : 0.55 }}>
              {/* Reorder */}
              <div className="flex flex-col">
                <button onClick={() => move(i, -1)} disabled={i === 0} className="p-0.5 disabled:opacity-25 hover:text-[var(--accent)]" style={{ color: 'var(--text-muted)' }} title="Move up"><ChevronUp size={14} /></button>
                <button onClick={() => move(i, 1)} disabled={i === fields.length - 1} className="p-0.5 disabled:opacity-25 hover:text-[var(--accent)]" style={{ color: 'var(--text-muted)' }} title="Move down"><ChevronDown size={14} /></button>
              </div>
              <div className="flex-1 min-w-0">
                <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>{f.name}{f.required ? ' *' : ''}</p>
                <div className="flex items-center flex-wrap gap-1.5 mt-1">
                  <Tag>{typeLabel(f.type)}</Tag>
                  <Tag>{widthLabel(f.bs_column)}</Tag>
                  {f.show_on_table && <Tag tone="accent">In list</Tag>}
                  {f.only_admin && <Tag tone="amber">Admin only</Tag>}
                </div>
              </div>
              <div className="flex items-center gap-2">
                <ToggleSwitch checked={!!f.active} onChange={() => toggleActive(f)} title="Active / disabled" size="sm" />
                <button onClick={() => setEditing(f)} className="p-1.5 rounded-lg hover:bg-[rgba(124,58,237,0.08)]" title="Edit"><Edit2 size={13} style={{ color: 'var(--text-muted)' }} /></button>
                <button onClick={() => setConfirmDel(f)} className="p-1.5 rounded-lg hover:bg-[rgba(239,68,68,0.08)]" title="Delete"><Trash2 size={13} style={{ color: '#f87171' }} /></button>
              </div>
            </div>
          ))}
        </div>
      )}

      {confirmDel && (
        <ConfirmDialog
          title="Delete custom field?"
          message={`“${confirmDel.name}” and all its saved values will be permanently removed.`}
          confirmLabel="Delete"
          onConfirm={doDelete}
          onCancel={() => setConfirmDel(null)}
        />
      )}
    </div>
  )
}

function Tag({ children, tone }) {
  const map = {
    accent: { background: 'rgba(124,58,237,0.12)', color: 'var(--accent)' },
    amber: { background: 'rgba(245,158,11,0.14)', color: '#d97706' },
    default: { background: 'var(--bg-card)', color: 'var(--text-muted)', border: '1px solid var(--border)' },
  }
  return <span className="px-2 py-0.5 rounded-md text-[10px] font-bold" style={map[tone] || map.default}>{children}</span>
}
