import { useState, useEffect } from 'react'
import { Plus, Edit2, X } from 'lucide-react'
import { customerApi } from '@/services/customerApi'
import { useToast } from '@/hooks/useToast'
import ConfirmIconButton from './ConfirmIconButton'

const fmtMoney = v => '₹' + Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const d10 = s => (s ? String(s).slice(0, 10) : '—')
const cell = (row, col) => {
  const v = row[col.key]
  if (col.type === 'money') return fmtMoney(v)
  if (col.type === 'date') return d10(v)
  if (col.type === 'bool') return v ? 'Yes' : 'No'
  return v ?? '—'
}
const blankForm = (fields) => Object.fromEntries(fields.map(f => [f.key, f.type === 'checkbox' ? false : '']))

/**
 * Generic per-customer record tab: a list + inline add/edit form + delete,
 * driven by a field schema (see recordSchemas.js). Powers Contracts, Expenses,
 * Subscriptions, Pre-Alerts, Packages and Shipments.
 */
export default function RecordTab({ clientId, schema }) {
  const toast = useToast()
  const api = customerApi[schema.apiKey]
  const [rows, setRows] = useState(null)
  const [editing, setEditing] = useState(null)     // null | {id?} while form open
  const [form, setForm] = useState(blankForm(schema.fields))
  const [saving, setSaving] = useState(false)

  const load = () => api.list(clientId).then(setRows).catch(e => toast.error(e.message))
  useEffect(() => { load() }, [clientId, schema.apiKey])

  const openNew = () => { setForm(blankForm(schema.fields)); setEditing({}) }
  const openEdit = (r) => setEditing(r)   // the effect below seeds the form
  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  const save = async () => {
    const required = schema.fields.find(f => f.required && !String(form[f.key] ?? '').trim())
    if (required) return toast.error(`${required.label} is required`)
    setSaving(true)
    try {
      const payload = { ...form }
      schema.fields.forEach(f => { if ((f.type === 'money' || f.type === 'number') && payload[f.key] === '') payload[f.key] = null })
      if (editing?.id) { await api.update(clientId, editing.id, payload); toast.success(`${schema.title.replace(/s$/, '')} updated`) }
      else { await api.create(clientId, payload); toast.success(`${schema.title.replace(/s$/, '')} added`) }
      setEditing(null); load()
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  const del = async (r) => { try { await api.remove(clientId, r.id); toast.success('Deleted'); load() } catch (e) { toast.error(e.message) } }

  // openEdit needs to seed the form; keep it in sync when editing an existing row.
  useEffect(() => {
    if (editing && editing.id) {
      const f = {}
      schema.fields.forEach(fl => {
        const v = editing[fl.key]
        f[fl.key] = fl.type === 'checkbox' ? !!v : (['date'].includes(fl.type) ? d10(v).replace('—', '') : (v ?? ''))
      })
      setForm(f)
    }
  }, [editing])

  return (
    <div className="space-y-4">
      {!editing && (
        <div className="flex justify-end">
          <button onClick={openNew} className="flex items-center gap-1 px-4 py-2 rounded-xl text-xs font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
            <Plus size={13} /> {schema.addLabel}
          </button>
        </div>
      )}

      {/* Add / edit form */}
      {editing && (
        <div className="card-3d" style={{ padding: '18px' }}>
          <div className="flex items-center justify-between mb-4">
            <p className="label-caps" style={{ color: 'var(--accent)' }}>{editing.id ? 'Edit' : schema.addLabel}</p>
            <button onClick={() => setEditing(null)} className="p-1 rounded-lg hover:bg-[rgba(239,68,68,0.08)]"><X size={15} style={{ color: 'var(--text-muted)' }} /></button>
          </div>
          <div className="grid md:grid-cols-2 gap-4">
            {schema.fields.map(f => (
              <div key={f.key} className={f.type === 'textarea' ? 'md:col-span-2' : ''}>
                <label className="label">{f.label}{f.required ? ' *' : ''}</label>
                <FieldInput field={f} value={form[f.key]} onChange={v => sf(f.key, v)} />
              </div>
            ))}
          </div>
          <div className="flex justify-end gap-2 mt-4">
            <button onClick={() => setEditing(null)} className="px-4 py-2 rounded-xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
            <button onClick={save} disabled={saving} className="px-5 py-2 rounded-xl text-sm font-bold text-white disabled:opacity-60" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>{saving ? 'Saving…' : 'Save'}</button>
          </div>
        </div>
      )}

      {/* List */}
      <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead><tr style={{ background: 'rgba(124,58,237,0.04)', borderBottom: '1px solid var(--border)' }}>
              {schema.columns.map(c => <th key={c.key} className="py-3 px-4 text-left label-caps whitespace-nowrap">{c.label}</th>)}
              <th className="py-3 px-4" />
            </tr></thead>
            <tbody>
              {rows === null ? (
                <tr><td colSpan={schema.columns.length + 1} className="p-3"><div className="skeleton h-8 rounded-lg" style={{ background: 'var(--border)' }} /></td></tr>
              ) : rows.length === 0 ? (
                <tr><td colSpan={schema.columns.length + 1} className="py-10 text-center" style={{ color: 'var(--text-muted)' }}>No {schema.title.toLowerCase()} yet.</td></tr>
              ) : rows.map(r => (
                <tr key={r.id} style={{ borderBottom: '1px solid var(--border)' }}>
                  {schema.columns.map(c => (
                    <td key={c.key} className="py-3 px-4" style={{ color: c.bold ? 'var(--text-h)' : 'var(--text-muted)', fontWeight: c.bold ? 700 : 400 }}>{cell(r, c)}</td>
                  ))}
                  <td className="py-3 px-4">
                    <div className="flex gap-1 justify-end">
                      <button onClick={() => openEdit(r)} className="p-1.5 rounded-lg hover:bg-[rgba(124,58,237,0.08)]" title="Edit"><Edit2 size={13} style={{ color: 'var(--text-muted)' }} /></button>
                      <ConfirmIconButton onConfirm={() => del(r)} title={`Delete?`} message="This record will be permanently removed." />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}

function FieldInput({ field, value, onChange }) {
  if (field.type === 'textarea') return <textarea rows={2} className="input-3d text-sm resize-none" value={value} onChange={e => onChange(e.target.value)} />
  if (field.type === 'checkbox') return <label className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-muted)' }}><input type="checkbox" checked={!!value} onChange={e => onChange(e.target.checked)} /> Yes</label>
  if (field.type === 'select') return <select className="input-3d text-sm" value={value} onChange={e => onChange(e.target.value)}>{field.options.map(o => <option key={o} value={o}>{o}</option>)}</select>
  if (field.type === 'date') return <input type="date" className="input-3d text-sm" value={value} onChange={e => onChange(e.target.value)} />
  if (field.type === 'money' || field.type === 'number') return <input type="number" className="input-3d text-sm" value={value} onChange={e => onChange(e.target.value)} />
  return <input className="input-3d text-sm" value={value} onChange={e => onChange(e.target.value)} />
}
