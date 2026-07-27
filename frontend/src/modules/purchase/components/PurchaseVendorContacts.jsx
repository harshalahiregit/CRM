import { useState, useEffect, useCallback, useMemo } from 'react'
import { Plus, Search, Eye, Pencil, Trash2, Star, X } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'

/**
 * Purchase Vendor Contacts — vendor-scoped contact manager for a single
 * PurchaseVendor. Every call is bound to the passed vendorId and hits Purchase
 * APIs only (/purchase/vendors/{id}/contacts…). purchase_vendor_id is attached
 * server-side from the route vendor — never chosen by the user, never sent as a
 * body field — so contacts are fully isolated per vendor. No TPV component.
 */

const inputStyle = { width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, outline: 'none', boxSizing: 'border-box' }
const selectStyle = { ...inputStyle, cursor: 'pointer' }
const PAGE_SIZE = 8

const EMPTY = { first_name: '', last_name: '', designation: '', department: '', email: '', phone: '', mobile: '', alternate_mobile: '', address: '', city: '', state: '', country: '', pincode: '', notes: '', is_primary: false, status: 'Active' }

export default function PurchaseVendorContacts({ vendorId }) {
  const [rows, setRows] = useState(null)
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const [modal, setModal] = useState(null)   // { mode:'add'|'edit'|'view', data }

  const load = useCallback(() => {
    setRows(null)
    purchaseApi.contacts.list(vendorId)
      .then((r) => setRows(Array.isArray(r) ? r : (r?.data ?? [])))
      .catch(() => setRows([]))
  }, [vendorId])

  useEffect(() => { load() }, [load])

  // Client-side, vendor-scoped search (the list itself is already this vendor's only).
  const filtered = useMemo(() => {
    const list = rows || []
    const s = q.trim().toLowerCase()
    if (!s) return list
    return list.filter((c) => [c.first_name, c.last_name, c.designation, c.department, c.email, c.phone, c.mobile]
      .filter(Boolean).some((v) => String(v).toLowerCase().includes(s)))
  }, [rows, q])

  const pageCount = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE))
  const pageRows = filtered.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE)
  useEffect(() => { setPage(1) }, [q, rows])

  const remove = async (c) => {
    if (!window.confirm(`Delete contact "${c.full_name || c.first_name}"? This cannot be undone.`)) return
    try { await purchaseApi.contacts.delete(vendorId, c.id); load() } catch { /* noop */ }
  }

  return (
    <div className="card-3d" style={{ padding: 18 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, marginBottom: 14, flexWrap: 'wrap' }}>
        <h2 style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>Contacts{rows && <span style={{ color: 'var(--text-muted)', fontWeight: 600 }}> · {rows.length}</span>}</h2>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <div style={{ position: 'relative' }}>
            <Search size={14} style={{ position: 'absolute', left: 9, top: 10, color: 'var(--text-muted)' }} />
            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search contacts…" style={{ ...inputStyle, paddingLeft: 30, width: 220 }} />
          </div>
          <button onClick={() => setModal({ mode: 'add', data: { ...EMPTY } })} style={primaryBtn}><Plus size={14} /> Add Contact</button>
        </div>
      </div>

      {rows === null ? <div style={{ color: 'var(--text-muted)' }}>Loading…</div>
        : filtered.length === 0 ? <div style={{ padding: '28px 0', textAlign: 'center', color: 'var(--text-muted)' }}>{q ? 'No contacts match your search.' : 'No contacts for this vendor yet.'}</div>
          : (
            <>
              <div style={{ overflowX: 'auto' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                  <thead><tr style={{ background: 'var(--bg-input)' }}>{['Name', 'Designation', 'Email', 'Phone', 'Primary', 'Status', ''].map((h) => <th key={h} style={th}>{h}</th>)}</tr></thead>
                  <tbody>
                    {pageRows.map((c) => (
                      <tr key={c.id} style={{ borderTop: '1px solid var(--border)' }}>
                        <td style={{ ...td, color: 'var(--text-h)', fontWeight: 700 }}>{c.full_name || `${c.first_name || ''} ${c.last_name || ''}`.trim() || '—'}</td>
                        <td style={td}>{c.designation || '—'}</td>
                        <td style={td}>{c.email || '—'}</td>
                        <td style={td}>{c.phone || c.mobile || '—'}</td>
                        <td style={td}>{c.is_primary ? <Star size={14} style={{ color: '#f59e0b', fill: '#f59e0b' }} /> : '—'}</td>
                        <td style={td}><span style={{ fontSize: 11, fontWeight: 700, color: c.status === 'Active' ? '#10b981' : '#6b7280' }}>{c.status_label || c.status}</span></td>
                        <td style={{ ...td, textAlign: 'right', whiteSpace: 'nowrap' }}>
                          <button onClick={() => setModal({ mode: 'view', data: c })} style={miniBtn} title="View"><Eye size={14} /></button>
                          <button onClick={() => setModal({ mode: 'edit', data: { ...c } })} style={miniBtn} title="Edit"><Pencil size={14} /></button>
                          <button onClick={() => remove(c)} style={{ ...miniBtn, color: '#ef4444' }} title="Delete"><Trash2 size={14} /></button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {pageCount > 1 && (
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'flex-end', gap: 8, marginTop: 12 }}>
                  <button onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page === 1} style={pageBtn}>Prev</button>
                  <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>Page {page} / {pageCount}</span>
                  <button onClick={() => setPage((p) => Math.min(pageCount, p + 1))} disabled={page === pageCount} style={pageBtn}>Next</button>
                </div>
              )}
            </>
          )}

      {modal && <ContactModal vendorId={vendorId} modal={modal} onClose={() => setModal(null)} onSaved={() => { setModal(null); load() }} onEdit={(c) => setModal({ mode: 'edit', data: { ...c } })} />}
    </div>
  )
}

function ContactModal({ vendorId, modal, onClose, onSaved, onEdit }) {
  const view = modal.mode === 'view'
  const [form, setForm] = useState(modal.data)
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState('')
  const set = (f) => (e) => setForm({ ...form, [f]: e?.target ? (e.target.type === 'checkbox' ? e.target.checked : e.target.value) : e })

  const validate = () => {
    if (!form.first_name?.trim()) return 'First Name is required.'
    if (!form.email?.trim()) return 'Email is required.'
    if (!form.phone?.trim()) return 'Phone is required.'
    if (!/^[0-9+\-\s()]{7,15}$/.test(form.phone)) return 'Phone format looks invalid.'
    return null
  }

  const save = async () => {
    const invalid = validate()
    if (invalid) { setErr(invalid); return }
    setSaving(true); setErr('')
    // purchase_vendor_id is NOT sent — the backend derives it from the route vendor.
    const payload = { ...form }; delete payload.purchase_vendor_id
    try {
      if (modal.mode === 'add') await purchaseApi.contacts.create(vendorId, payload)
      else await purchaseApi.contacts.update(vendorId, form.id, payload)
      onSaved()
    } catch (e) {
      const errors = e?.response?.data?.errors
      setErr(errors ? Object.values(errors).flat()[0] : (e?.response?.data?.message || 'Could not save contact.'))
    } finally { setSaving(false) }
  }

  const title = view ? 'Contact Details' : modal.mode === 'add' ? 'Add Contact' : 'Edit Contact'

  return (
    <div style={overlay} onClick={onClose}>
      <div className="card-3d" style={{ padding: 0, width: 680, maxWidth: '95vw', maxHeight: '92vh', display: 'flex', flexDirection: 'column' }} onClick={(e) => e.stopPropagation()}>
        <div style={{ padding: '16px 20px', borderBottom: '1px solid var(--border)', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <h2 style={{ fontSize: 16, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>{title}</h2>
          <button onClick={onClose} style={{ ...miniBtn, border: 'none' }}><X size={18} /></button>
        </div>

        <div style={{ padding: 20, overflowY: 'auto' }}>
          {view ? <ViewBody c={form} /> : (
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
              <F label="First Name" required><input value={form.first_name || ''} onChange={set('first_name')} style={inputStyle} /></F>
              <F label="Last Name"><input value={form.last_name || ''} onChange={set('last_name')} style={inputStyle} /></F>
              <F label="Designation"><input value={form.designation || ''} onChange={set('designation')} style={inputStyle} /></F>
              <F label="Department"><input value={form.department || ''} onChange={set('department')} style={inputStyle} /></F>
              <F label="Email" required><input value={form.email || ''} onChange={set('email')} style={inputStyle} /></F>
              <F label="Phone" required><input value={form.phone || ''} onChange={set('phone')} style={inputStyle} /></F>
              <F label="Mobile"><input value={form.mobile || ''} onChange={set('mobile')} style={inputStyle} /></F>
              <F label="Alternate Phone"><input value={form.alternate_mobile || ''} onChange={set('alternate_mobile')} style={inputStyle} /></F>
              <F label="Address" full><input value={form.address || ''} onChange={set('address')} style={inputStyle} /></F>
              <F label="City"><input value={form.city || ''} onChange={set('city')} style={inputStyle} /></F>
              <F label="State"><input value={form.state || ''} onChange={set('state')} style={inputStyle} /></F>
              <F label="Country"><input value={form.country || ''} onChange={set('country')} style={inputStyle} /></F>
              <F label="Pincode"><input value={form.pincode || ''} onChange={set('pincode')} style={inputStyle} /></F>
              <F label="Notes" full><textarea value={form.notes || ''} onChange={set('notes')} rows={2} style={{ ...inputStyle, resize: 'vertical' }} /></F>
              <F label="Status"><select value={form.status || 'Active'} onChange={set('status')} style={selectStyle}><option value="Active">Active</option><option value="Inactive">Inactive</option></select></F>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8, alignSelf: 'end', paddingBottom: 8 }}>
                <input id="pv-primary" type="checkbox" checked={!!form.is_primary} onChange={set('is_primary')} style={{ width: 16, height: 16, cursor: 'pointer' }} />
                <label htmlFor="pv-primary" style={{ fontSize: 13, color: 'var(--text-h)', cursor: 'pointer' }}>Primary Contact</label>
              </div>
            </div>
          )}
        </div>

        <div style={{ padding: '14px 20px', borderTop: '1px solid var(--border)', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8 }}>
          <span style={{ color: '#ef4444', fontSize: 12 }}>{err}</span>
          <div style={{ display: 'flex', gap: 8 }}>
            <button onClick={onClose} style={btn}>{view ? 'Close' : 'Cancel'}</button>
            {view
              ? <button onClick={() => onEdit(form)} style={primaryBtn}><Pencil size={14} /> Edit</button>
              : <button onClick={save} disabled={saving} style={primaryBtn}>{saving ? 'Saving…' : (modal.mode === 'add' ? 'Add Contact' : 'Save Changes')}</button>}
          </div>
        </div>
      </div>
    </div>
  )
}

function ViewBody({ c }) {
  const rows = [
    ['Name', c.full_name || `${c.first_name || ''} ${c.last_name || ''}`.trim()],
    ['Designation', c.designation], ['Department', c.department],
    ['Email', c.email], ['Phone', c.phone], ['Mobile', c.mobile], ['Alternate Phone', c.alternate_mobile],
    ['Address', c.address], ['City', c.city], ['State', c.state], ['Country', c.country], ['Pincode', c.pincode],
    ['Primary', c.is_primary ? 'Yes' : 'No'], ['Status', c.status_label || c.status], ['Notes', c.notes],
  ]
  return (
    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
      {rows.map(([k, v]) => (
        <div key={k}><div style={{ fontSize: 11, color: 'var(--text-muted)', textTransform: 'uppercase', fontWeight: 700 }}>{k}</div><div style={{ color: 'var(--text-h)', fontWeight: 600 }}>{v || '—'}</div></div>
      ))}
    </div>
  )
}

function F({ label, required, children, full }) {
  return (
    <div style={full ? { gridColumn: '1 / -1' } : undefined}>
      <label className="label" style={{ display: 'block', marginBottom: 4 }}>{label}{required && <span style={{ color: '#ef4444' }}> *</span>}</label>
      {children}
    </div>
  )
}

const th = { textAlign: 'left', padding: '9px 12px', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.04em', color: 'var(--text-muted)', fontWeight: 700 }
const td = { padding: '9px 12px', color: 'var(--text-muted)' }
const btn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 12px', borderRadius: 8, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const primaryBtn = { ...btn, background: '#7C3AED', color: '#fff', border: 'none' }
const miniBtn = { display: 'inline-flex', alignItems: 'center', padding: '5px 7px', borderRadius: 6, background: 'transparent', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer', marginLeft: 5 }
const pageBtn = { ...btn, padding: '6px 12px', fontSize: 12 }
const overlay = { position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 60 }
