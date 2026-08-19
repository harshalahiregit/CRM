import { useState, useEffect, useCallback, useMemo, useRef } from 'react'
import {
  Plus, Search, Eye, Pencil, RefreshCw, Power, Star, Users,
  ChevronLeft, ChevronRight, Upload, X, Camera, User,
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
              <thead><tr>{['Name', 'Code', 'Designation', 'Department', 'Mobile', 'Email', 'Primary', 'Status', 'Actions'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
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
function ContactModal({ vendorId, vendor, mode, contact, onClose, onSaved, api = tpvApi }) {
  const view = mode === 'view'
  const isEdit = mode === 'edit'

  // ── Form state — ALL fields ────────────────────────────────────────────
  const EMPTY = {
    // Personal
    full_name:        '',
    dob:              '',
    gender:           '',
    mobile:           '',
    email:            '',
    aadhaar_number:   '',
    designation:      '',
    department:       '',
    // Company (auto-filled)
    company_name:     vendor?.company_name ?? '',
    vendor_code:      vendor?.vendor_code ?? '',
    site_name:        '',
    joining_date:     '',
    // Emergency
    emergency_name:   '',
    emergency_number: '',
    relationship:     '',
    // Address
    address:          '',
    city:             '',
    state:            '',
    pincode:          '',
    // Identity
    pan_number:       '',
    passport_number:  '',
    driving_license:  '',
    // Meta
    is_primary:       false,
    status:           CONTACT_STATUS.ACTIVE,
    notes:            '',
  }

  const [f, setF] = useState(contact ? {
    full_name:        contact.full_name ?? '',
    dob:              contact.dob ?? '',
    gender:           contact.gender ?? '',
    mobile:           contact.mobile ?? '',
    email:            contact.email ?? '',
    aadhaar_number:   contact.aadhaar_number ?? '',
    designation:      contact.designation ?? '',
    department:       contact.department ?? '',
    company_name:     contact.company_name ?? vendor?.company_name ?? '',
    vendor_code:      contact.vendor_code ?? vendor?.vendor_code ?? '',
    site_name:        contact.site_name ?? '',
    joining_date:     contact.joining_date ?? '',
    emergency_name:   contact.emergency_name ?? '',
    emergency_number: contact.emergency_number ?? '',
    relationship:     contact.relationship ?? '',
    address:          contact.address ?? '',
    city:             contact.city ?? '',
    state:            contact.state ?? '',
    pincode:          contact.pincode ?? '',
    pan_number:       contact.pan_number ?? '',
    passport_number:  contact.passport_number ?? '',
    driving_license:  contact.driving_license ?? '',
    is_primary:       contact.is_primary ?? false,
    status:           contact.status ?? CONTACT_STATUS.ACTIVE,
    notes:            contact.notes ?? '',
  } : EMPTY)

  // ── Photo upload state ─────────────────────────────────────────────────
  const [photoFile, setPhotoFile]     = useState(null)
  const [photoPreview, setPhotoPreview] = useState(contact?.photo_url ?? null)
  const fileRef = useRef(null)

  const pickPhoto = (e) => {
    const file = e.target.files?.[0]
    if (!file) return
    if (file.size > 5 * 1024 * 1024) { alert('Photo must be under 5 MB.'); return }
    if (!['image/jpeg', 'image/png'].includes(file.type)) { alert('Only JPG and PNG are supported.'); return }
    setPhotoFile(file)
    setPhotoPreview(URL.createObjectURL(file))
  }
  const removePhoto = () => { setPhotoFile(null); setPhotoPreview(null); if (fileRef.current) fileRef.current.value = '' }

  const [saving, setSaving] = useState(false)
  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))

  // ── Validation ─────────────────────────────────────────────────────────
  const errs = {
    full_name:      !f.full_name.trim() ? 'Full Name is required' : '',
    mobile:         !f.mobile.trim() ? 'Mobile is required' : (!PHONE_RE.test(f.mobile) ? 'Enter a valid mobile number (7–15 digits)' : ''),
    designation:    !f.designation.trim() ? 'Designation is required' : '',
    email:          f.email && !EMAIL_RE.test(f.email) ? 'Enter a valid email address' : '',
    aadhaar_number: f.aadhaar_number && !AADHAAR_RE.test(f.aadhaar_number.replace(/\s/g, '')) ? 'Must be a 12-digit Aadhaar number' : '',
    pan_number:     f.pan_number && !PAN_RE.test(f.pan_number) ? 'Invalid PAN (AAAAA9999A format)' : '',
    pincode:        f.pincode && !PIN_RE.test(f.pincode) ? 'Must be a 6-digit pincode' : '',
    emergency_number: f.emergency_number && !PHONE_RE.test(f.emergency_number) ? 'Invalid phone number' : '',
  }
  const canSave = !errs.full_name && !errs.mobile && !errs.designation && !errs.email &&
                  !errs.aadhaar_number && !errs.pan_number && !errs.pincode && !errs.emergency_number

  // ── Save ───────────────────────────────────────────────────────────────
  const save = async () => {
    if (!canSave) {
      const first = Object.values(errs).find(Boolean)
      return alert(first || 'Please fix the highlighted errors.')
    }
    setSaving(true)
    try {
      let payload
      // If there's a photo to upload, use FormData; otherwise send JSON.
      if (photoFile) {
        payload = new FormData()
        Object.entries(f).forEach(([k, v]) => {
          if (v !== null && v !== undefined && v !== '') payload.append(k, v)
        })
        payload.append('photo', photoFile)
      } else {
        payload = { ...f }
        // Nullify optional blanks so backend doesn't store empty strings
        const optional = ['dob', 'gender', 'email', 'aadhaar_number', 'department', 'site_name',
          'joining_date', 'emergency_name', 'emergency_number', 'relationship', 'address', 'city',
          'state', 'pincode', 'pan_number', 'passport_number', 'driving_license', 'notes']
        optional.forEach(k => { if (payload[k] === '') payload[k] = null })
      }

      if (isEdit) await api.contacts.update(vendorId, contact.id, payload)
      else        await api.contacts.create(vendorId, payload)
      onSaved()
    } catch (e) { alert(e?.response?.data?.message || 'Could not save contact') }
    finally { setSaving(false) }
  }

  const roStyle = view ? { pointerEvents: 'none', opacity: 0.85 } : undefined
  const title   = view ? 'Contact Details' : isEdit ? 'Edit Contact' : 'Add New Contact'
  const subtitle = view
    ? `Read-only view — ${contact?.full_name ?? 'contact'}`
    : isEdit
      ? `Update details for ${contact?.full_name ?? 'contact'}`
      : `Register a new contact person for ${vendor ? `Vendor #${vendorId} — ${vendor.company_name}` : `Vendor #${vendorId}`}`

  // ── Field style helpers ────────────────────────────────────────────────
  const errInput = (key) => errs[key] ? { borderColor: '#ef444480' } : {}

  return (
    <Overlay onClose={() => !saving && onClose()} width={820} showClose={false}>
      {/* Modal header */}
      <div style={{ marginBottom: 20 }}>
        <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>{title}</h2>
        <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: 0 }}>{subtitle}</p>
      </div>

      {f.is_primary && !view && (
        <InfoBox>This contact will be marked <strong>Primary</strong> for the vendor.</InfoBox>
      )}

      {/* ── Photo upload ─────────────────────────────────────────── */}
      {!view && (
        <div style={{ gridColumn: '1/-1', marginBottom: 20 }}>
          <label style={labelStyle}>Contact Photo</label>
          <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
            <div style={{ width: 72, height: 72, borderRadius: '50%', border: '2px dashed var(--border)', display: 'flex', alignItems: 'center', justifyContent: 'center', overflow: 'hidden', background: 'var(--bg-input)', flexShrink: 0, position: 'relative' }}>
              {photoPreview
                ? <img src={photoPreview} alt="preview" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                : <Camera size={24} style={{ color: 'var(--text-muted)', opacity: 0.5 }} />}
            </div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              <button type="button" onClick={() => fileRef.current?.click()}
                style={{ ...ghostBtn, gap: 8 }}>
                <Upload size={14} /> {photoPreview ? 'Change Photo' : 'Upload Photo'}
              </button>
              {photoPreview && (
                <button type="button" onClick={removePhoto}
                  style={{ ...ghostBtn, color: '#f87171', fontSize: 12 }}>
                  <X size={12} /> Remove
                </button>
              )}
              <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>Supported: JPG, PNG · Max 5 MB</span>
            </div>
          </div>
          <input ref={fileRef} type="file" accept="image/jpeg,image/png" onChange={pickPhoto} style={{ display: 'none' }} />
        </div>
      )}

      {/* ── View-only photo ─────────────────────────────────────── */}
      {view && contact?.photo_url && (
        <div style={{ marginBottom: 20 }}>
          <img src={contact.photo_url} alt="contact" style={{ width: 72, height: 72, borderRadius: '50%', objectFit: 'cover', border: '2px solid var(--border)' }} />
        </div>
      )}

      {/* ── Form grid ──────────────────────────────────────────── */}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14, ...roStyle }}>

        {/* ── Contact Information ─────────────────────────────── */}
        <Section title="Contact Information" />

        <Field label="Full Name *">
          <TextInput value={f.full_name} onChange={set('full_name')} placeholder="John Smith" style={errInput('full_name')} />
          <ErrHint msg={errs.full_name} />
        </Field>

        <Field label="Employee ID / Contact Code">
          {/* Auto-generated by backend on save — show existing code or placeholder */}
          <ReadOnlyInput value={contact?.contact_code ?? ''} placeholder="Auto-generated (e.g. CNT-00025)" />
        </Field>

        <Field label="Date of Birth">
          <TextInput type="date" value={f.dob} onChange={set('dob')} />
        </Field>

        <Field label="Gender">
          <SelectInput value={f.gender} onChange={set('gender')} pairs
            options={[['', 'Select gender…'], ...GENDERS.map(g => [g, g])]} />
        </Field>

        <Field label="Mobile Number *">
          <div style={{ display: 'flex' }}>
            <span style={{ ...inputStyle, width: 'auto', borderRight: 'none', borderRadius: '8px 0 0 8px', color: 'var(--text-muted)', flexShrink: 0, paddingRight: 8, background: 'var(--bg-input)' }}>+91</span>
            <TextInput value={f.mobile} onChange={set('mobile')} placeholder="10-digit mobile"
              style={{ borderRadius: '0 8px 8px 0', ...errInput('mobile') }} />
          </div>
          <ErrHint msg={errs.mobile} />
        </Field>

        <Field label="Email Address">
          <TextInput type="email" value={f.email} onChange={set('email')} placeholder="john@company.com"
            style={errInput('email')} />
          <ErrHint msg={errs.email} />
        </Field>

        <Field label="Aadhaar Number">
          <TextInput value={f.aadhaar_number} onChange={set('aadhaar_number')} placeholder="xxxx xxxx xxxx"
            maxLength={14} style={errInput('aadhaar_number')} />
          <ErrHint msg={errs.aadhaar_number} />
        </Field>

        <Field label="Designation *">
          <ComboInput value={f.designation} onChange={set('designation')} id="designation"
            placeholder="e.g. Site Engineer" list={DESIGNATIONS} style={errInput('designation')} />
          <ErrHint msg={errs.designation} />
        </Field>

        <Field label="Department">
          <ComboInput value={f.department} onChange={set('department')} id="department"
            placeholder="e.g. Operations" list={DEPARTMENTS} />
        </Field>

        {/* ── Company Information ─────────────────────────────── */}
        <Section title="Company Information" />

        <Field label="Company Name">
          <ReadOnlyInput value={f.company_name} placeholder="Auto-filled from vendor" />
        </Field>

        <Field label="Vendor Code">
          <ReadOnlyInput value={f.vendor_code} placeholder="Auto-filled" />
        </Field>

        <Field label="Site Name">
          <TextInput value={f.site_name} onChange={set('site_name')} placeholder="e.g. Main Plant Gate 3" />
        </Field>

        <Field label="Joining Date">
          <TextInput type="date" value={f.joining_date} onChange={set('joining_date')} />
        </Field>

        {/* ── Emergency Contact ───────────────────────────────── */}
        <Section title="Emergency Contact" />

        <Field label="Emergency Contact Name">
          <TextInput value={f.emergency_name} onChange={set('emergency_name')} placeholder="e.g. Jane Smith" />
        </Field>

        <Field label="Emergency Contact Number">
          <TextInput value={f.emergency_number} onChange={set('emergency_number')} placeholder="10-digit mobile"
            style={errInput('emergency_number')} />
          <ErrHint msg={errs.emergency_number} />
        </Field>

        <Field label="Relationship">
          <SelectInput value={f.relationship} onChange={set('relationship')} pairs
            options={[['', 'Select relationship…'], ...RELATIONSHIPS.map(r => [r, r])]} />
        </Field>

        {/* ── Address ─────────────────────────────────────────── */}
        <Section title="Address" />

        <Field label="Address Line" full>
          <TextInput value={f.address} onChange={set('address')} placeholder="Street address, building name…" />
        </Field>

        <Field label="City">
          <TextInput value={f.city} onChange={set('city')} placeholder="e.g. Mumbai" />
        </Field>

        <Field label="State">
          <TextInput value={f.state} onChange={set('state')} placeholder="e.g. Maharashtra" />
        </Field>

        <Field label="Pincode">
          <TextInput value={f.pincode} onChange={set('pincode')} placeholder="6-digit pincode"
            maxLength={6} style={errInput('pincode')} />
          <ErrHint msg={errs.pincode} />
        </Field>

        {/* ── Identity ─────────────────────────────────────────── */}
        <Section title="Identity" />

        <Field label="PAN Number">
          <TextInput value={f.pan_number} onChange={set('pan_number')} placeholder="AAAAA9999A"
            maxLength={10} style={{ textTransform: 'uppercase', ...errInput('pan_number') }} />
          <ErrHint msg={errs.pan_number} />
        </Field>

        <Field label="Passport Number (Optional)">
          <TextInput value={f.passport_number} onChange={set('passport_number')} placeholder="e.g. J1234567" />
        </Field>

        <Field label="Driving License Number (Optional)">
          <TextInput value={f.driving_license} onChange={set('driving_license')} placeholder="e.g. MH0120210012345" />
        </Field>

        {/* ── Status ───────────────────────────────────────────── */}
        <Section title="Status & Settings" />

        <Field label="Status">
          <SelectInput value={f.status} onChange={set('status')} pairs
            options={[[CONTACT_STATUS.ACTIVE, 'Active'], [CONTACT_STATUS.INACTIVE, 'Inactive']]} />
        </Field>

        <Field label="Primary Contact">
          <label style={{ display: 'inline-flex', alignItems: 'center', gap: 8, fontSize: 13, color: 'var(--text-h)', cursor: view ? 'default' : 'pointer', paddingTop: 2 }}>
            <input type="checkbox" checked={f.is_primary}
              onChange={e => setF(p => ({ ...p, is_primary: e.target.checked }))}
              style={{ cursor: view ? 'default' : 'pointer', width: 15, height: 15 }} />
            Mark as the vendor's primary contact
          </label>
        </Field>

        {/* ── Notes ────────────────────────────────────────────── */}
        <Section title="Notes" />

        <Field label="Additional Notes" full>
          <textarea value={f.notes} onChange={set('notes')} placeholder="Additional information…"
            rows={3} style={{ ...inputStyle, resize: 'vertical', fontFamily: 'inherit' }} />
        </Field>

      </div>

      {/* ── Footer ──────────────────────────────────────────────── */}
      {view ? (
        <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 22 }}>
          <button onClick={onClose} style={primaryBtn}>Close</button>
        </div>
      ) : (
        <ModalFooter
          onClose={onClose}
          onConfirm={save}
          loading={saving}
          disabled={!canSave}
          confirmLabel={isEdit ? 'Save Changes' : 'Save Contact'}
        />
      )}
    </Overlay>
  )
}

// ── Style tokens ───────────────────────────────────────────────────────────────
const ghostBtn   = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 12px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }
const primaryBtn = { display: 'inline-flex', alignItems: 'center', gap: 8, padding: '9px 16px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13, boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)' }
