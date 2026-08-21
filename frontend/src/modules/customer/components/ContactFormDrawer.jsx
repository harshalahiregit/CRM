import { useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { X, Eye, EyeOff, RefreshCw } from 'lucide-react'
import { customerApi } from '@/services/customerApi'
import { useToast } from '@/hooks/useToast'
import CustomFieldInput, { cfWidthStyle } from './CustomFieldInput'
import ContactPermissions, { DEFAULT_PERMISSIONS, DEFAULT_NOTIFICATIONS, permissionsFromLegacy } from './ContactPermissions'

/**
 * The one customer-contact form. Extracted from CustomerDetail's ContactsTab so
 * the identical form (all fields, portal password, permission matrix, custom
 * fields) can be opened from anywhere a contact is needed — the customer profile
 * and the proposal wizard both mount THIS, rather than each maintaining a copy
 * that drifts.
 *
 * Saving is unchanged from the profile implementation: it POSTs/PUTs through
 * customerApi.contacts so the module's own validation, primary-contact
 * reconciliation and custom-field persistence all still apply.
 *
 * Props:
 *   clientId  customer the contact belongs to (required)
 *   contact   existing contact to edit; null/undefined = create
 *   onSaved   (contact) => void — receives the saved record, so a caller like
 *             the proposal wizard can immediately select the new contact
 *   onClose   () => void
 */
const EMPTY_CONTACT = {
  first_name: '', last_name: '', title: '', email: '', phone: '',
  avatar: '', direction: '', password: '',
  // §11 — title is the designation on their card, department is where they sit,
  // role is what they are to us (and is what drives which mail they get).
  department: '', role: '', whatsapp: '',
  is_decision_maker: false, influence: '', is_secondary: false, reports_to: '',
  is_primary: false, active: true,
  permissions: DEFAULT_PERMISSIONS, email_notifications: DEFAULT_NOTIFICATIONS, emails_enabled: true,
  custom_fields: {},
}

const d10 = s => (s ? String(s).slice(0, 10) : '—')

/** Random strong-ish password for the "generate" button. */
function genPassword() {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%'
  return Array.from({ length: 12 }, () => chars[Math.floor(Math.random() * chars.length)]).join('')
}

/** Seed custom-field values keyed by definition id. */
function seedCf(c) {
  const cf = {}
  ;(c?.custom_fields ?? []).forEach(f => { cf[f.id] = f.value ?? '' })
  return cf
}

export default function ContactFormDrawer({ clientId, contact = null, siblings = [], onSaved, onClose }) {
  const toast = useToast()
  const editing = contact
  const [cfDefs, setCfDefs] = useState([])
  const [showPwd, setShowPwd] = useState(false)
  const [saving, setSaving] = useState(false)
  // Fetched here, not passed in: the drawer is a portal and the tab that owns
  // these lists may not be mounted, and a datalist is resolved by id from the
  // live DOM — borrowing one that isn't rendered silently yields no suggestions.
  const [options, setOptions] = useState({})
  useEffect(() => { customerApi.options().then(setOptions).catch(() => setOptions({})) }, [])
  const [form, setForm] = useState(() => (editing ? {
    first_name: editing.first_name || '', last_name: editing.last_name || '', title: editing.title || '',
    email: editing.email || '', phone: editing.phone || '', avatar: editing.avatar || '',
    direction: editing.direction || '', password: '',
    department: editing.department || '', role: editing.role || '', whatsapp: editing.whatsapp || '',
    is_decision_maker: !!editing.is_decision_maker, influence: editing.influence || '',
    is_secondary: !!editing.is_secondary, reports_to: editing.reports_to ?? '',
    is_primary: !!editing.is_primary, active: editing.active !== false,
    permissions: editing.permissions ?? permissionsFromLegacy(editing.email_notifications),
    email_notifications: { ...DEFAULT_NOTIFICATIONS, ...(editing.email_notifications || {}) },
    emails_enabled: editing.emails_enabled !== false,
    custom_fields: seedCf(editing),
  } : { ...EMPTY_CONTACT, custom_fields: {} }))

  const sf = (k, v) => setForm(p => ({ ...p, [k]: v }))

  useEffect(() => { customerApi.customFields.list('contacts').then(setCfDefs).catch(() => {}) }, [])

  const save = async () => {
    if (!form.first_name.trim()) return toast.error('First name required')
    if (form.password && form.password.length < 6) return toast.error('Portal password must be at least 6 characters')
    // Blank password → omit entirely (backend keeps the existing one).
    const payload = { ...form, password: form.password || undefined }
    setSaving(true)
    try {
      const saved = editing
        ? await customerApi.contacts.update(clientId, editing.id, payload)
        : await customerApi.contacts.create(clientId, payload)
      toast.success(editing ? 'Contact updated' : 'Contact added')
      onSaved?.(saved)
      onClose?.()
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  return createPortal(
    <>
      <div className="drawer-backdrop" />
      <div className="drawer-panel" style={{ width: 'min(560px, 96vw)' }}>
        <div className="drawer-header">
          <div>
            <h2 className="font-black text-lg" style={{ color: 'var(--text-h)', letterSpacing: '-0.02em' }}>{editing ? 'Edit Contact' : 'New Contact'}</h2>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{editing ? 'Update this contact’s details' : 'Add a person under this customer'}</p>
          </div>
          <button onClick={onClose} className="w-9 h-9 rounded-xl flex items-center justify-center hover:bg-[rgba(239,68,68,0.08)] transition-colors" style={{ border: '1px solid var(--border)' }}>
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        <div className="drawer-body space-y-4">
          {/* Profile image */}
          <div className="flex items-center gap-4">
            <div className="w-16 h-16 rounded-2xl overflow-hidden flex-shrink-0 flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.1)', border: '1px solid var(--border)' }}>
              {form.avatar
                ? <img src={form.avatar} alt="" className="w-full h-full object-cover" />
                : <span className="font-black text-lg" style={{ color: 'var(--accent)' }}>{(form.first_name?.[0] || '?').toUpperCase()}</span>}
            </div>
            <div>
              <label className="label">Profile Image</label>
              <div className="flex items-center gap-2">
                <label className="px-3 py-1.5 rounded-lg text-xs font-bold cursor-pointer" style={{ background: 'var(--bg-input)', color: 'var(--accent)', border: '1px solid var(--border)' }}>
                  Upload
                  <input type="file" accept="image/*" className="hidden" onChange={e => {
                    const f = e.target.files?.[0]; if (!f) return
                    if (f.size > 400 * 1024) return toast.error('Image must be under 400 KB')
                    const r = new FileReader(); r.onload = () => sf('avatar', r.result); r.readAsDataURL(f)
                  }} />
                </label>
                {form.avatar && <button type="button" onClick={() => sf('avatar', '')} className="text-xs font-bold" style={{ color: '#f87171' }}>Remove</button>}
              </div>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">First Name *</label><input className="input-3d text-sm" value={form.first_name} onChange={e => sf('first_name', e.target.value)} /></div>
            <div><label className="label">Last Name</label><input className="input-3d text-sm" value={form.last_name} onChange={e => sf('last_name', e.target.value)} /></div>
          </div>
          <div><label className="label">Position / Title</label><input className="input-3d text-sm" value={form.title} onChange={e => sf('title', e.target.value)} /></div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Email</label><input className="input-3d text-sm" value={form.email} onChange={e => sf('email', e.target.value)} /></div>
            <div><label className="label">Phone</label><input className="input-3d text-sm" value={form.phone} onChange={e => sf('phone', e.target.value)} /></div>
          </div>

          {/* §11 — relationship depth. Role drives which documents they are
              mailed; Decision Maker and Influence say who actually signs. The
              reporting line is what makes those two mean anything, so it is a
              picker over this customer's other contacts, never free text. */}
          <div className="grid grid-cols-2 gap-3">
            <div><label className="label">Department</label>
              <input className="input-3d text-sm" value={form.department} onChange={e => sf('department', e.target.value)} /></div>
            <div><label className="label">Role</label>
              <input className="input-3d text-sm" list="opt-contact_role" value={form.role} onChange={e => sf('role', e.target.value)} /></div>
            <div><label className="label">WhatsApp</label>
              <input className="input-3d text-sm" value={form.whatsapp} onChange={e => sf('whatsapp', e.target.value)} placeholder="If different from phone" /></div>
            <div><label className="label">Influence</label>
              <input className="input-3d text-sm" list="opt-influence" value={form.influence} onChange={e => sf('influence', e.target.value)} /></div>
            <datalist id="opt-contact_role">{(options.contact_role ?? []).map(o => <option key={o} value={o} />)}</datalist>
            <datalist id="opt-influence">{(options.influence ?? []).map(o => <option key={o} value={o} />)}</datalist>
            <div><label className="label">Reports To</label>
              <select className="input-3d text-sm" value={form.reports_to} onChange={e => sf('reports_to', e.target.value)}>
                <option value="">Nobody / top of the chain</option>
                {(siblings ?? []).filter(c => c.id !== editing?.id).map(c => (
                  <option key={c.id} value={c.id}>{[c.first_name, c.last_name].filter(Boolean).join(' ')}</option>
                ))}
              </select></div>
            <div className="flex items-end gap-4 pb-1">
              <label className="flex items-center gap-2 text-xs font-semibold cursor-pointer" style={{ color: 'var(--text-muted)' }}>
                <input type="checkbox" checked={form.is_decision_maker} onChange={e => sf('is_decision_maker', e.target.checked)} />
                Decision maker
              </label>
              <label className="flex items-center gap-2 text-xs font-semibold cursor-pointer" style={{ color: 'var(--text-muted)' }}>
                <input type="checkbox" checked={form.is_secondary} onChange={e => sf('is_secondary', e.target.checked)} />
                Secondary
              </label>
            </div>
          </div>
          <div><label className="label">Document Direction</label>
            <select className="input-3d text-sm" value={form.direction || ''} onChange={e => sf('direction', e.target.value)}>
              <option value="">System default</option>
              <option value="ltr">LTR (left-to-right)</option>
              <option value="rtl">RTL (right-to-left)</option>
            </select>
          </div>
          <div>
            <label className="label">Portal Password</label>
            <div className="flex items-center gap-2">
              <input
                type={showPwd ? 'text' : 'password'}
                className="input-3d text-sm flex-1"
                autoComplete="new-password"
                placeholder={editing?.has_password ? '••••••••  (leave blank to keep)' : 'Set a login password'}
                value={form.password || ''}
                onChange={e => sf('password', e.target.value)}
              />
              <button type="button" onClick={() => setShowPwd(s => !s)} title={showPwd ? 'Hide' : 'Show'}
                className="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0" style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                {showPwd ? <EyeOff size={14} /> : <Eye size={14} />}
              </button>
              <button type="button" onClick={() => { sf('password', genPassword()); setShowPwd(true) }} title="Generate password"
                className="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0" style={{ border: '1px solid var(--border)', color: 'var(--accent)' }}>
                <RefreshCw size={14} />
              </button>
            </div>
            <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>
              The contact's own login for the client portal (min 6 chars). Not a CRM staff account.
              {editing?.last_password_change && <> · Last changed {d10(editing.last_password_change)}</>}
            </p>
          </div>
          <div className="flex items-center gap-5">
            <label className="flex items-center gap-2 text-xs font-semibold cursor-pointer" style={{ color: 'var(--text-muted)' }}>
              <input type="checkbox" checked={form.is_primary} onChange={e => sf('is_primary', e.target.checked)} /> Primary contact
            </label>
            <label className="flex items-center gap-2 text-xs font-semibold cursor-pointer" style={{ color: 'var(--text-muted)' }}>
              <input type="checkbox" checked={form.active} onChange={e => sf('active', e.target.checked)} /> Active
            </label>
          </div>

          <ContactPermissions
            permissions={form.permissions}
            notifications={form.email_notifications}
            emailsEnabled={form.emails_enabled}
            onChange={({ permissions, email_notifications, emails_enabled }) => setForm(p => ({ ...p, permissions, email_notifications, emails_enabled }))}
          />

          {cfDefs.length > 0 && (
            <div>
              <p className="label-caps mb-2" style={{ color: 'var(--accent)' }}>Custom Fields</p>
              <div className="flex flex-wrap gap-3">
                {cfDefs.map(def => (
                  <div key={def.id} style={cfWidthStyle(def.bs_column)}>
                    <label className="label">{def.name}{def.required ? ' *' : ''}</label>
                    <CustomFieldInput def={def}
                      value={form.custom_fields?.[def.id] ?? def.default_value ?? ''}
                      onChange={v => setForm(p => ({ ...p, custom_fields: { ...(p.custom_fields || {}), [def.id]: v } }))} />
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>

        <div className="drawer-footer">
          <button onClick={onClose} className="flex-1 py-3 rounded-2xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
          <button onClick={save} disabled={saving} className="flex-[2] py-3 rounded-2xl text-sm font-bold text-white hover:scale-[1.01] transition-all disabled:opacity-60" style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED,#5b21b6)', boxShadow: '0 6px 20px rgba(124,58,237,0.4)' }}>
            {saving ? 'Saving…' : editing ? 'Save Changes' : 'Add Contact'}
          </button>
        </div>
      </div>
    </>,
    document.body,
  )
}
