import { useState, useEffect, useCallback, useMemo, useRef } from 'react'
import {
  Plus, Search, Eye, Pencil, RefreshCw, Power, Star, Users,
  ChevronLeft, ChevronRight, Upload, X, Camera, User, KeyRound, ShieldCheck,
} from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { CONTACT_STATUS, contactStatusCfg, GENDERS, fmtDate } from '../constants'
import {
  KIT3D_STYLE, inputStyle, labelStyle, Overlay, ModalFooter, InfoBox,
  Field, TextInput, SelectInput, ActBtn, StatusBadge as StatusPill,
} from '@/components/ui/kit3d'

const PAGE_SIZE = 10
const EMAIL_RE   = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const PHONE_RE   = /^[0-9+\-\s()]{7,15}$/
const AADHAAR_RE = /^[2-9]{1}[0-9]{11}$/
const PAN_RE     = /^[A-Z]{5}[0-9]{4}[A-Z]$/i
const PIN_RE     = /^[0-9]{6}$/

const DESIGNATIONS = ['Site Engineer', 'Supervisor', 'HR', 'Safety Officer', 'Project Manager', 'Technician', 'Foreman', 'Electrician', 'Welder']
const DEPARTMENTS  = ['HR', 'Operations', 'Safety', 'Administration', 'Projects', 'Finance', 'Procurement', 'Maintenance']
const RELATIONSHIPS = ['Spouse', 'Parent', 'Sibling', 'Child', 'Friend', 'Colleague', 'Other']

/**
 * Vendor Contact Management — the master contact list for a single TPV vendor.
 * Vendor-scoped CRUD (no hard delete; status is the soft toggle). Client-side
 * search + pagination, consistent with the rest of the TPV module.
 *
 * @param vendorId  — numeric vendor ID (required)
 * @param vendor    — the vendor object for auto-fill in the Add/Edit modal
 * @param manage    — boolean, true for admin/staff
 * @param api       — data source (defaults to tpvApi; purchase/portal swap it in)
 */
export default function TpvVendorContacts({ vendorId, vendor, manage, api = tpvApi }) {
  const [rows, setRows]       = useState([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch]   = useState('')
  const [page, setPage]       = useState(1)
  const [modal, setModal]     = useState(null)   // { mode: 'create'|'edit'|'view', contact }

  const fetchAll = useCallback(async () => {
    setLoading(true)
    try {
      const res = await api.contacts.list(vendorId)
      setRows(Array.isArray(res?.data ?? res) ? (res.data ?? res) : [])
    } catch (e) { console.error('Failed to load contacts', e) }
    finally { setLoading(false) }
  }, [vendorId, api])
  useEffect(() => { fetchAll() }, [fetchAll])

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return rows
    return rows.filter(c =>
      c.full_name?.toLowerCase().includes(q) ||
      c.designation?.toLowerCase().includes(q) ||
      c.department?.toLowerCase().includes(q) ||
      c.email?.toLowerCase().includes(q) ||
      c.mobile?.toLowerCase().includes(q))
  }, [rows, search])

  const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE))
  useEffect(() => { if (page > totalPages) setPage(totalPages) }, [page, totalPages])
  const pageRows = filtered.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE)

  const toggleStatus = async (c) => {
    const next = c.status === CONTACT_STATUS.ACTIVE ? CONTACT_STATUS.INACTIVE : CONTACT_STATUS.ACTIVE
    if (!confirm(`${next === CONTACT_STATUS.ACTIVE ? 'Activate' : 'Deactivate'} ${c.full_name}?`)) return
    try { await api.contacts.setStatus(vendorId, c.id, next); fetchAll() }
    catch (e) { alert(e?.response?.data?.message || 'Could not update status') }
  }

  // Enable this contact as an assignable EMPLOYEE — provisions a portal login so
  // they can be assigned tasks/projects and see their own work (enhancement #9).
  const [granting, setGranting] = useState(null)
  const grantAccess = async (c) => {
    if (!c.email) { alert('Add an email address for this contact first — a login needs one.'); return }
    if (!confirm(`Give ${c.full_name} portal access so they can be assigned work? A login will be created for ${c.email}.`)) return
    setGranting(c.id)
    try { await api.employees.grantAccess(vendorId, c.id); fetchAll() }
    catch (e) { alert(e?.response?.data?.message || 'Could not enable portal access') }
    finally { setGranting(null) }
  }

  const th = { textAlign: 'left', padding: '10px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
  const td = { padding: '11px 12px', borderBottom: '1px solid var(--border)', fontSize: 12.5, verticalAlign: 'middle' }

  return (
    <>
    <div className="pr-glass" style={{ padding: 20, borderRadius: 16 }}>
      <style>{KIT3D_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 14, flexWrap: 'wrap', gap: 12 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <Users size={16} style={{ color: '#a78bfa' }} />
          <span style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>Contacts</span>
          <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>({filtered.length})</span>
        </div>
        <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
          <div style={{ position: 'relative' }}>
            <Search size={14} style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
            <input value={search} onChange={e => { setSearch(e.target.value); setPage(1) }} placeholder="Search contacts…"
              style={{ ...inputStyle, paddingLeft: 32, width: 200 }} />
          </div>
          <button onClick={fetchAll} title="Refresh" style={ghostBtn}><RefreshCw size={14} /></button>
          {manage && (
            <button onClick={() => setModal({ mode: 'create' })} style={primaryBtn}><Plus size={15} /> Add Contact</button>
          )}
        </div>
      </div>

      {loading ? (
        <div style={{ textAlign: 'center', padding: 48, color: 'var(--text-muted)' }}>Loading contacts…</div>
      ) : filtered.length === 0 ? (
        <div style={{ padding: 48, textAlign: 'center' }}>
          <div style={{ width: 54, height: 54, borderRadius: 16, margin: '0 auto 14px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'linear-gradient(145deg,#9f67ff,#7C3AED)', boxShadow: '0 10px 24px -6px rgba(124,58,237,.6)' }}>
            <Users size={24} color="#fff" />
          </div>
          <h3 style={{ color: 'var(--text-h)', fontSize: 15, fontWeight: 800, margin: '0 0 5px' }}>{search ? 'No matching contacts' : 'No contacts yet'}</h3>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '0 0 16px' }}>{search ? 'Try a different search.' : 'Add the people you deal with at this vendor.'}</p>
          {manage && !search && <button onClick={() => setModal({ mode: 'create' })} style={{ ...primaryBtn, margin: '0 auto' }}><Plus size={15} /> Add Contact</button>}
        </div>
      ) : (
        <>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead><tr>{['Name', 'Code', 'Designation', 'Department', 'Mobile', 'Email', 'Primary', 'Portal', 'Status', 'Actions'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
              <tbody>
                {pageRows.map(c => (
                  <tr key={c.id} className="pr-li-row">
                    <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)', whiteSpace: 'nowrap' }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        {c.photo_url
                          ? <img src={c.photo_url} alt="" style={{ width: 28, height: 28, borderRadius: '50%', objectFit: 'cover', flexShrink: 0 }} />
                          : <span style={{ width: 28, height: 28, borderRadius: '50%', background: 'rgba(124,58,237,0.14)', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}><User size={13} style={{ color: '#a78bfa' }} /></span>}
                        {c.full_name || '—'}
                      </div>
                    </td>
                    <td style={{ ...td, color: '#a78bfa', fontWeight: 700, fontSize: 12 }}>{c.contact_code || '—'}</td>
                    <td style={{ ...td, color: 'var(--text-muted)' }}>{c.designation || '—'}</td>
                    <td style={{ ...td, color: 'var(--text-muted)' }}>{c.department || '—'}</td>
                    <td style={{ ...td, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{c.mobile || '—'}</td>
                    <td style={{ ...td, color: 'var(--text-muted)' }}>{c.email || '—'}</td>
                    <td style={td}>
                      {c.is_primary
                        ? <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11, fontWeight: 700, color: '#f59e0b' }}><Star size={12} fill="#f59e0b" /> Primary</span>
                        : <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>—</span>}
                    </td>
                    <td style={td}>
                      {c.user_id
                        ? <span title="Has a portal login — can be assigned work" style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11, fontWeight: 700, color: '#10b981' }}><ShieldCheck size={13} /> Assignable</span>
                        : manage
                          ? <button onClick={() => grantAccess(c)} disabled={granting === c.id}
                              title={c.email ? 'Create a portal login so this person can be assigned work' : 'Add an email first'}
                              style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '5px 10px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-card)', color: c.email ? '#a78bfa' : 'var(--text-muted)', cursor: granting === c.id ? 'default' : 'pointer', fontSize: 11.5, fontWeight: 700, opacity: granting === c.id ? 0.6 : 1 }}>
                              <KeyRound size={12} /> {granting === c.id ? 'Enabling…' : 'Grant access'}
                            </button>
                          : <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>—</span>}
                    </td>
                    <td style={td}><StatusPill cfg={contactStatusCfg(c.status)} /></td>
                    <td style={td}>
                      <div style={{ display: 'flex', gap: 6 }}>
                        <ActBtn onClick={() => setModal({ mode: 'view', contact: c })} icon={Eye} color="var(--text-muted)" bg="var(--bg-card)" border>View</ActBtn>
                        {manage && <ActBtn onClick={() => setModal({ mode: 'edit', contact: c })} icon={Pencil} color="#a78bfa" bg="var(--bg-card)" border>Edit</ActBtn>}
                        {manage && (
                          <ActBtn onClick={() => toggleStatus(c)} icon={Power}
                            color={c.status === CONTACT_STATUS.ACTIVE ? '#f87171' : '#10b981'} bg="var(--bg-card)" border>
                            {c.status === CONTACT_STATUS.ACTIVE ? 'Deactivate' : 'Activate'}
                          </ActBtn>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {totalPages > 1 && (
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'flex-end', gap: 12, marginTop: 14 }}>
              <button onClick={() => setPage(p => Math.max(1, p - 1))} disabled={page <= 1} style={{ ...ghostBtn, opacity: page <= 1 ? 0.5 : 1 }}><ChevronLeft size={15} /></button>
              <span style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>Page {page} of {totalPages}</span>
              <button onClick={() => setPage(p => Math.min(totalPages, p + 1))} disabled={page >= totalPages} style={{ ...ghostBtn, opacity: page >= totalPages ? 0.5 : 1 }}><ChevronRight size={15} /></button>
            </div>
          )}
        </>
      )}

    </div>

    {/* Rendered OUTSIDE the .pr-glass card: that card uses backdrop-filter, which
        would otherwise become the containing block for the modal's fixed overlay
        and trap it inside the card instead of covering the viewport. */}
    {modal && (
      <ContactModal
        vendorId={vendorId}
        vendor={vendor}
        mode={modal.mode}
        contact={modal.contact}
        api={api}
        onClose={() => setModal(null)}
        onSaved={() => { setModal(null); fetchAll() }}
      />
    )}
    </>
  )
}

// ── Section separator used inside the modal ────────────────────────────────
function Section({ title }) {
  return (
    <div style={{ gridColumn: '1/-1', display: 'flex', alignItems: 'center', gap: 10, margin: '10px 0 2px' }}>
      <span style={{ fontSize: 11, fontWeight: 800, color: '#a78bfa', textTransform: 'uppercase', letterSpacing: '0.07em', whiteSpace: 'nowrap' }}>{title}</span>
      <span style={{ flex: 1, height: 1, background: 'var(--border)' }} />
    </div>
  )
}

// ── Datalist-backed combo input (shows suggestions, allows free typing) ───
function ComboInput({ value, onChange, list, id, placeholder, style: extraStyle }) {
  const listId = `combo-${id}`
  return (
    <>
      <input value={value} onChange={onChange} placeholder={placeholder} list={listId}
        style={{ ...inputStyle, ...extraStyle }} />
      <datalist id={listId}>
        {list.map(o => <option key={o} value={o} />)}
      </datalist>
    </>
  )
}

// ── Error hint ──────────────────────────────────────────────────────────────
const ErrHint = ({ msg }) => msg ? (
  <span style={{ fontSize: 10.5, color: '#ef4444', marginTop: 4, display: 'block' }}>{msg}</span>
) : null

// ── Readonly / autofill input ──────────────────────────────────────────────
const ReadOnlyInput = ({ value, placeholder }) => (
  <input value={value} readOnly placeholder={placeholder || '—'}
    style={{ ...inputStyle, opacity: 0.7, cursor: 'default', background: 'var(--bg-input)', pointerEvents: 'none' }} />
)

// ── Full Add / Edit / View modal ───────────────────────────────────────────
/**
 * Add / edit a vendor contact.
 *
 * THE SAME SIXTEEN FIELDS PURCHASE CAPTURES, and only those.
 *
 * This form used to ask for twenty-six: date of birth, gender, Aadhaar, PAN,
 * passport, driving licence, joining date, site, emergency contact and a photo.
 * The endpoint accepted nine of them and the table had columns for nine, so
 * everything else was typed in and dropped on save without a word.
 *
 * Worse, the name box was `full_name` while the request required `first_name`
 * and `last_name`, so the save did not merely lose data -- it failed outright,
 * every time, on a field this form did not have and could not put an error
 * against. The dialog just said "Could not save contact". That is why a TPV
 * vendor's contact count stayed at zero, and why the Add Contact onboarding
 * step could never be completed on this side.
 *
 * Purchase's version of this screen asks for sixteen, accepts sixteen and
 * stores sixteen. This is now the same form against the same contract, with
 * tpv_contacts given the seven columns purchase_contacts already had.
 *
 * The KYC fields are not hidden, they are gone: a box that has never once
 * stored what somebody typed is not a feature being withheld. If Aadhaar and
 * PAN belong on a vendor contact, they need columns, rules and a screen that
 * says what they are for -- in both workspaces, not one.
 */
function ContactModal({ vendorId, vendor, mode, contact, onClose, onSaved, api = tpvApi }) {
  const view   = mode === 'view'
  const isEdit = mode === 'edit'

  const EMPTY = {
    first_name: '', last_name: '', designation: '', department: '',
    email: '', phone: '', mobile: '', alternate_mobile: '',
    address: '', city: '', state: '', country: '', pincode: '',
    notes: '', is_primary: false, status: CONTACT_STATUS.ACTIVE,
  }

  const [f, setF] = useState(contact
    ? Object.fromEntries(Object.keys(EMPTY).map(k => [k, contact[k] ?? EMPTY[k]]))
    : EMPTY)

  const [saving, setSaving] = useState(false)
  const set = (k) => (e) => setF(prev => ({
    ...prev,
    [k]: e?.target?.type === 'checkbox' ? e.target.checked : e.target.value,
  }))

  const errs = {
    first_name:  !String(f.first_name).trim() ? 'First name is required' : '',
    last_name:   !String(f.last_name).trim() ? 'Last name is required' : '',
    designation: !String(f.designation).trim() ? 'Designation is required' : '',
    email:       !String(f.email).trim()
      ? 'Email is required'
      : (!EMAIL_RE.test(f.email) ? 'Enter a valid email address' : ''),
    mobile:      !String(f.mobile).trim()
      ? 'Mobile is required'
      : (!PHONE_RE.test(f.mobile) ? 'Enter a valid mobile number (7-15 digits)' : ''),
  }
  const firstError = Object.values(errs).find(Boolean)

  const save = async () => {
    if (firstError) return alert(firstError)
    setSaving(true)
    try {
      // Blanks go as null so an optional field is absent rather than an empty
      // string sitting in the column.
      const payload = Object.fromEntries(
        Object.entries(f).map(([k, v]) => [k, v === '' ? null : v]),
      )

      if (isEdit) await api.contacts.update(vendorId, contact.id, payload)
      else        await api.contacts.create(vendorId, payload)
      onSaved()
    } catch (e) {
      alert(e?.response?.data?.message || 'Could not save contact')
    } finally { setSaving(false) }
  }

  const title = view ? 'Contact Details' : isEdit ? 'Edit Contact' : 'Add Contact'
  const ro = view ? { pointerEvents: 'none', opacity: 0.85 } : undefined

  const T = (k, label, { required = false, full = false, type = 'text' } = {}) => (
    <Field label={required ? `${label} *` : label} full={full}>
      <TextInput type={type} value={f[k] ?? ''} onChange={set(k)} style={ro} />
      <ErrHint msg={!view ? errs[k] : ''} />
    </Field>
  )

  return (
    <Overlay onClose={() => !saving && onClose()} width={760}>
      <div style={{ marginBottom: 16 }}>
        <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 17, fontWeight: 800 }}>{title}</h2>
        <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: 0 }}>
          {vendor?.company_name ? `For ${vendor.company_name}` : 'Vendor contact'}
        </p>
      </div>

      {f.is_primary && !view && (
        <InfoBox>This contact will be marked <strong>Primary</strong> for the vendor.</InfoBox>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        {T('first_name', 'First Name', { required: true })}
        {T('last_name', 'Last Name', { required: true })}
        {T('designation', 'Designation', { required: true })}
        {T('department', 'Department')}
        {T('email', 'Email', { required: true, type: 'email' })}
        {T('phone', 'Phone')}
        {T('mobile', 'Mobile', { required: true })}
        {T('alternate_mobile', 'Alternate Phone')}
        {T('address', 'Address', { full: true })}
        {T('city', 'City')}
        {T('state', 'State')}
        {T('country', 'Country')}
        {T('pincode', 'Pincode')}

        <Field label="Status">
          <SelectInput value={f.status} onChange={set('status')} style={ro}
            options={Object.values(CONTACT_STATUS)} />
        </Field>

        <Field label="Primary Contact">
          <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12.5, color: 'var(--text-h)', ...(ro || {}) }}>
            <input type="checkbox" checked={!!f.is_primary} onChange={set('is_primary')} />
            Mark as the primary contact
          </label>
        </Field>

        {T('notes', 'Notes', { full: true })}
      </div>

      {view ? (
        <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 20 }}>
          <button type="button" onClick={onClose}
            style={{ padding: '9px 20px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>
            Close
          </button>
        </div>
      ) : (
        <ModalFooter
          onClose={onClose}
          onConfirm={save}
          loading={saving}
          disabled={!!firstError}
          confirmLabel={isEdit ? 'Save Changes' : 'Add Contact'}
        />
      )}
    </Overlay>
  )
}

// ── Style tokens ───────────────────────────────────────────────────────────────
const ghostBtn   = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 12px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }
const primaryBtn = { display: 'inline-flex', alignItems: 'center', gap: 8, padding: '9px 16px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13, boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)' }
