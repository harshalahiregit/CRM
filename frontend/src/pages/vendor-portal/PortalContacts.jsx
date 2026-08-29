import { useEffect, useState } from 'react'
import { Plus, X, Loader2, Star, Pencil } from 'lucide-react'

/**
 * Shared "My Contacts" page for BOTH vendor portals. The two backends differ in
 * their field shape (TPV uses a single `name`; Purchase uses first/last name +
 * more), so each portal passes a `fields` descriptor and a `nameOf` accessor.
 * The API surface is identical across portals (contacts.list/create/update/
 * setStatus), so one component serves both via the `api` prop.
 */
export default function PortalContacts({ api, fields, nameOf }) {
  const [rows, setRows] = useState(null)
  const [editing, setEditing] = useState(null)   // contact | {} (new) | null

  const reload = () => api.contacts.list(null).then(d => setRows(Array.isArray(d) ? d : (d?.data || []))).catch(() => setRows([]))
  useEffect(() => { reload() }, [])

  const toggleStatus = async (c) => {
    const next = (c.status === 'active' || c.status === 'Active') ? 'inactive' : 'active'
    await api.contacts.setStatus(null, c.id, next).catch(() => {})
    reload()
  }

  return (
    <div style={{ maxWidth: 900, margin: '0 auto' }}>
      <style>{CSS}</style>
      <div className="pc-head">
        <h2 style={{ fontSize: 18, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>My Contacts</h2>
        <button className="pc-btn pc-btn-primary" onClick={() => setEditing({})}><Plus size={15} /> Add Contact</button>
      </div>

      {rows === null ? <Center><Loader2 className="pc-spin" size={22} /></Center>
        : rows.length === 0 ? <Empty />
        : (
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(260px,1fr))', gap: 12 }}>
            {rows.map(c => (
              <div key={c.id} className="pc-card">
                <div style={{ display: 'flex', alignItems: 'start', gap: 8 }}>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontWeight: 700, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 6 }}>
                      {nameOf(c) || '—'}
                      {c.is_primary ? <Star size={13} fill="#f59e0b" color="#f59e0b" /> : null}
                    </div>
                    {c.designation && <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>{c.designation}{c.department ? ` · ${c.department}` : ''}</div>}
                  </div>
                  <button className="pc-icon" title="Edit" onClick={() => setEditing(c)}><Pencil size={13} /></button>
                </div>
                <div style={{ marginTop: 8, fontSize: 12.5, color: 'var(--text-body,#cbd5e1)', display: 'flex', flexDirection: 'column', gap: 3 }}>
                  {c.email && <span>✉ {c.email}</span>}
                  {(c.phone || c.mobile) && <span>☎ {c.phone || c.mobile}</span>}
                </div>
                {c.status && (
                  <button onClick={() => toggleStatus(c)} className="pc-status" data-on={c.status === 'active' || c.status === 'Active'}>
                    {String(c.status)}
                  </button>
                )}
              </div>
            ))}
          </div>
        )}

      {editing && <ContactForm api={api} fields={fields} contact={editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); reload() }} />}
    </div>
  )
}

function ContactForm({ api, fields, contact, onClose, onSaved }) {
  const isNew = !contact?.id
  const [form, setForm] = useState(() => {
    const base = { is_primary: Boolean(contact?.is_primary) }
    fields.forEach(f => { base[f.key] = contact?.[f.key] ?? '' })
    return base
  })
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const set = (k, v) => setForm(s => ({ ...s, [k]: v }))

  const save = async () => {
    setError('')
    const missing = fields.find(f => f.required && !String(form[f.key] || '').trim())
    if (missing) { setError(`${missing.label} is required.`); return }
    setSaving(true)
    try {
      if (isNew) await api.contacts.create(null, form)
      else await api.contacts.update(null, contact.id, form)
      onSaved()
    } catch (e) {
      setError(e?.response?.data?.message || e?.message || 'Could not save the contact.')
    } finally { setSaving(false) }
  }

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.55)', zIndex: 60, display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '5vh 16px', overflowY: 'auto' }}>
      <div onClick={e => e.stopPropagation()} className="pc-modal">
        <div className="pc-modal-head">
          <strong style={{ color: 'var(--text-h)', flex: 1 }}>{isNew ? 'Add Contact' : 'Edit Contact'}</strong>
          <button onClick={onClose} className="pc-icon"><X size={16} /></button>
        </div>
        <div style={{ padding: 18, display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))', gap: 12 }}>
          {fields.map(f => (
            <label key={f.key} style={{ fontSize: 12, color: 'var(--text-muted)' }}>
              {f.label}{f.required ? ' *' : ''}
              <input type={f.type || 'text'} value={form[f.key]} onChange={e => set(f.key, e.target.value)} className="pc-input" style={{ marginTop: 4 }} />
            </label>
          ))}
          <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: 'var(--text-h)', alignSelf: 'end' }}>
            <input type="checkbox" checked={form.is_primary} onChange={e => set('is_primary', e.target.checked)} /> Primary contact
          </label>
        </div>
        {error && <div style={{ padding: '0 18px', color: '#ef4444', fontSize: 13 }}>{error}</div>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, padding: 18 }}>
          <button onClick={onClose} className="pc-btn">Cancel</button>
          <button onClick={save} disabled={saving} className="pc-btn pc-btn-primary">{saving ? <Loader2 className="pc-spin" size={14} /> : null} Save</button>
        </div>
      </div>
    </div>
  )
}

function Center({ children }) { return <div style={{ display: 'flex', justifyContent: 'center', padding: 60 }}>{children}</div> }
function Empty() { return <div style={{ textAlign: 'center', color: 'var(--text-muted)', padding: 48, fontSize: 14 }}>No contacts yet. Add your first one.</div> }

const CSS = `
.pc-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; gap: 12px; flex-wrap: wrap; }
.pc-card { background: var(--bg-card, rgba(255,255,255,0.02)); border: 1px solid var(--border, rgba(255,255,255,0.08)); border-radius: 12px; padding: 14px; }
.pc-input { display: block; width: 100%; background: var(--bg-input, rgba(255,255,255,0.05)); border: 1px solid var(--border, rgba(255,255,255,0.12)); border-radius: 8px; padding: 7px 9px; color: var(--text-h); font-size: 13px; }
.pc-input:focus { outline: none; border-color: var(--portal-purple, #7c3aed); }
.pc-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 9px; font-size: 13px; font-weight: 700; cursor: pointer; border: 1px solid var(--border, rgba(255,255,255,0.14)); background: transparent; color: var(--text-h); }
.pc-btn:hover { background: var(--bg-input, rgba(255,255,255,0.05)); }
.pc-btn-primary { background: var(--portal-purple, #7c3aed); border-color: var(--portal-purple, #7c3aed); color: #fff; }
.pc-btn-primary:disabled { opacity: 0.6; cursor: default; }
.pc-icon { background: transparent; border: none; color: var(--text-muted); cursor: pointer; padding: 4px; border-radius: 6px; }
.pc-icon:hover { color: var(--text-h); background: var(--bg-input, rgba(255,255,255,0.05)); }
.pc-status { margin-top: 10px; font-size: 11px; font-weight: 700; text-transform: capitalize; padding: 2px 10px; border-radius: 999px; cursor: pointer; border: none; background: rgba(148,163,184,0.15); color: #94a3b8; }
.pc-status[data-on="true"] { background: rgba(34,197,94,0.15); color: #22c55e; }
.pc-modal { width: 100%; max-width: 620px; background: var(--bg-card, #14161c); border: 1px solid var(--border, rgba(255,255,255,0.1)); border-radius: 14px; overflow: hidden; }
.pc-modal-head { display: flex; align-items: center; gap: 10px; padding: 14px 18px; border-bottom: 1px solid var(--border, rgba(255,255,255,0.08)); }
.pc-spin { animation: pc-spin 0.9s linear infinite; }
@keyframes pc-spin { to { transform: rotate(360deg); } }
`
