import { useState, useEffect, useCallback, useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Plus, RefreshCw, Search, Eye, Pencil, Trash2, Users, CheckCircle, XCircle, Building2, X, Mail, CalendarDays,
} from 'lucide-react'
import { useAuth } from '@/context/AuthContext'
import { fmtDate } from '../constants'
import { useVendorModule } from '../useVendorModule'
import {
  KIT3D_STYLE, inputStyle, labelStyle, Overlay, ModalFooter, Field, TextInput, SelectInput,
} from '@/components/ui/kit3d'

const EMPTY_FORM = {
  name: '', company_name: '', email: '', phone: '', gst_number: '', status: 'Active',
  password: '', password_confirmation: '',
  address: '', city: '', state: '', pincode: '',
}

export default function TpvVendors() {
  const navigate = useNavigate()
  const { user } = useAuth()
  const cfg = useVendorModule()
  const manage = cfg.canManage(user)

  const [rows, setRows] = useState([])
  const [loading, setLoad] = useState(true)
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState(null)   // form object (with id when editing), or null
  const [emailing, setEmailing] = useState(null) // vendor to email, or null

  const load = useCallback(() => {
    setLoad(true)
    cfg.api.vendors.list()
      .then(r => { setRows(r?.data ?? r ?? []); setLoad(false) })
      .catch(() => setLoad(false))
  }, [cfg.api])
  useEffect(() => { load() }, [load])

  const counts = useMemo(() => {
    const total = rows.length
    const active = rows.filter(v => v.status === 'Active').length
    return { total, active, inactive: total - active }
  }, [rows])

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return rows
    return rows.filter(v =>
      `${v.user?.name || ''} ${v.company_name || ''} ${v.email || ''} ${v.phone || ''}`.toLowerCase().includes(q))
  }, [rows, search])

  const toggleStatus = async (v) => {
    const next = v.status === 'Active' ? 'Inactive' : 'Active'
    try { await cfg.api.vendors.setStatus(v.id, next); load() }
    catch (e) { alert(e?.response?.data?.message || 'Failed to update status') }
  }
  const remove = async (v) => {
    if (!confirm(`Delete vendor "${v.company_name}"? This cannot be undone.`)) return
    try { await cfg.api.vendors.delete(v.id); load() }
    catch (e) { alert(e?.response?.data?.message || 'Failed to delete') }
  }
  const openEdit = (v) => setEditing({
    id: v.id, name: v.user?.name || '', company_name: v.company_name || '', email: v.email || '',
    phone: v.phone || '', gst_number: v.gst_number || '', status: v.status === 'Active' ? 'Active' : 'Inactive',
    password: '', password_confirmation: '',
    address: v.address || '', city: v.city || '', state: v.state || '', pincode: v.pincode || '',
  })

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>{cfg.moduleName.toUpperCase()}S</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 24, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>Vendors</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Manage {cfg.moduleName.toLowerCase()} accounts, portal logins and status.</p>
        </div>
        <div style={{ display: 'flex', gap: 9 }}>
          <button onClick={load} style={ghostBtn}><RefreshCw size={14} /> Refresh</button>
          <button onClick={() => navigate(cfg.kickoffListPath)} style={ghostBtn}><CalendarDays size={14} /> Kickoff Meeting</button>
          {manage && <button onClick={() => setEditing({ ...EMPTY_FORM })} style={solidBtn}><Plus size={15} /> New {cfg.moduleName}</button>}
        </div>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 14, marginBottom: 18 }}>
        <Counter label="Total Vendors" value={counts.total} icon={Users} color="#a78bfa" />
        <Counter label="Active" value={counts.active} icon={CheckCircle} color="#10b981" />
        <Counter label="Inactive" value={counts.inactive} icon={XCircle} color="#ef4444" />
      </div>

      <div style={{ display: 'flex', gap: 10, marginBottom: 16 }}>
        <div style={{ position: 'relative', minWidth: 260, marginLeft: 'auto' }}>
          <Search size={14} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search name, company, email…" style={{ ...inputStyle, paddingLeft: 32 }} />
        </div>
      </div>

      {loading ? (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>{[1, 2, 3, 4].map(i => <div key={i} className="skeleton" style={{ height: 60, borderRadius: 14, background: 'var(--border)' }} />)}</div>
      ) : filtered.length === 0 ? (
        <div className="pr-glass" style={{ padding: '48px 24px', textAlign: 'center' }}>
          <div style={{ width: 60, height: 60, borderRadius: '50%', margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.12)' }}><Building2 size={28} style={{ color: '#a78bfa' }} /></div>
          <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>No vendors yet</h3>
          <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '6px 0 18px' }}>Add a {cfg.moduleName.toLowerCase()} to give them a portal login.</p>
          {manage && <button onClick={() => setEditing({ ...EMPTY_FORM })} style={{ ...solidBtn, margin: '0 auto' }}><Plus size={15} /> New {cfg.moduleName}</button>}
        </div>
      ) : (
        <div className="pr-glass" style={{ padding: 0, borderRadius: 16, overflow: 'hidden' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 900 }}>
              <thead><tr>{['ID', 'Vendor Name', 'Company', 'Phone', 'Email', 'Status', 'Date Created', 'Options'].map((h, i) => (
                <th key={h} style={{ textAlign: i === 7 ? 'right' : 'left', padding: '11px 14px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', borderBottom: '1px solid var(--border)' }}>{h}</th>
              ))}</tr></thead>
              <tbody>
                {filtered.map(v => (
                  <tr key={v.id} className="pr-li-row">
                    <td style={{ padding: '10px 14px', fontSize: 12.5, fontWeight: 700, color: '#a78bfa' }}>#{v.id}</td>
                    <td style={{ padding: '10px 14px', fontSize: 12.5, color: 'var(--text-h)', fontWeight: 600 }}>{v.user?.name || '—'}</td>
                    <td style={{ padding: '10px 14px', fontSize: 12.5, color: 'var(--text-h)' }}>{v.company_name}</td>
                    <td style={{ padding: '10px 14px', fontSize: 12.5, color: 'var(--text-muted)' }}>{v.phone || '—'}</td>
                    <td style={{ padding: '10px 14px', fontSize: 12.5, color: 'var(--text-muted)' }}>{v.email || '—'}</td>
                    <td style={{ padding: '10px 14px' }}>
                      <ToggleSwitch on={v.status === 'Active'} disabled={!manage} onChange={() => toggleStatus(v)} />
                    </td>
                    <td style={{ padding: '10px 14px', fontSize: 12.5, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{v.created_at ? fmtDate(v.created_at) : '—'}</td>
                    <td style={{ padding: '8px 14px', textAlign: 'right', whiteSpace: 'nowrap' }}>
                      <div style={{ display: 'inline-flex', gap: 6 }}>
                        <IconBtn title="View" onClick={() => navigate(cfg.viewPath(v.id))}><Eye size={13} /></IconBtn>
                        {manage && <IconBtn title="Edit" onClick={() => openEdit(v)}><Pencil size={13} /></IconBtn>}
                        {manage && <IconBtn title="Send Email" onClick={() => setEmailing(v)}><Mail size={13} /></IconBtn>}
                        {manage && <IconBtn title="Delete" color="#ef4444" onClick={() => remove(v)}><Trash2 size={13} /></IconBtn>}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {editing && <VendorModal form={editing} cfg={cfg} onClose={() => setEditing(null)} onDone={() => { setEditing(null); load() }} />}
      {emailing && <EmailModal vendor={emailing} api={cfg.api} onClose={() => setEmailing(null)} />}
    </div>
  )
}

// ── Send-email compose modal ────────────────────────────────────────────────────
function EmailModal({ vendor, api, onClose }) {
  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  const [sending, setSending] = useState(false)
  const [err, setErr] = useState(null)
  const [sent, setSent] = useState(false)

  const send = async () => {
    if (!subject.trim() || !body.trim()) { setErr('Subject and message are required.'); return }
    setSending(true); setErr(null)
    try { await api.vendors.sendEmail(vendor.id, { subject, body }); setSent(true); setTimeout(onClose, 900) }
    catch (e) { setErr(e?.response?.data?.message || 'Could not send email.'); setSending(false) }
  }

  return (
    <Overlay onClose={onClose} width={520}>
      <div style={{ padding: '20px 22px 6px' }}>
        <h2 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>Send Email</h2>
        <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>To {vendor.email || 'this vendor'} · {vendor.company_name}</p>
      </div>
      <div style={{ padding: '10px 22px' }}>
        {!vendor.email && <div style={{ padding: '8px 12px', borderRadius: 10, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)', marginBottom: 10, fontSize: 12.5, color: 'var(--text-h)' }}>This vendor has no email on file.</div>}
        <Field label="Subject"><TextInput value={subject} onChange={e => setSubject(e.target.value)} placeholder="Subject" /></Field>
        <Field label="Message" full><textarea value={body} onChange={e => setBody(e.target.value)} rows={6} placeholder="Type your message…" style={{ ...inputStyle, resize: 'vertical' }} /></Field>
        {err && <div style={{ fontSize: 12.5, color: '#ef4444', marginTop: 6 }}>{err}</div>}
        {sent && <div style={{ fontSize: 12.5, color: '#10b981', marginTop: 6, fontWeight: 700 }}>Email sent.</div>}
      </div>
      <ModalFooter onClose={onClose} onConfirm={send} loading={sending} confirmLabel="Send Email" color="#7C3AED" />
    </Overlay>
  )
}

function Counter({ label, value, icon: Icon, color }) {
  return (
    <div className="pr-kpi" style={{ padding: 16 }}>
      <div style={{ width: 38, height: 38, borderRadius: 12, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `${color}1f` }}><Icon size={18} style={{ color }} /></div>
      <div style={{ fontSize: 24, fontWeight: 900, color: 'var(--text-h)', marginTop: 11, lineHeight: 1 }}>{value ?? 0}</div>
      <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-muted)', marginTop: 4 }}>{label}</div>
    </div>
  )
}

function ToggleSwitch({ on, disabled, onChange }) {
  return (
    <button onClick={disabled ? undefined : onChange} title={on ? 'Active — click to deactivate' : 'Inactive — click to activate'}
      style={{ display: 'inline-flex', alignItems: 'center', gap: 7, cursor: disabled ? 'default' : 'pointer', background: 'none', border: 'none', padding: 0 }}>
      <span style={{ width: 34, height: 19, borderRadius: 999, background: on ? '#10b981' : 'var(--border)', position: 'relative', transition: 'background .18s', flexShrink: 0 }}>
        <span style={{ position: 'absolute', top: 2, left: on ? 17 : 2, width: 15, height: 15, borderRadius: '50%', background: '#fff', transition: 'left .18s', boxShadow: '0 1px 3px rgba(0,0,0,.3)' }} />
      </span>
      <span style={{ fontSize: 11.5, fontWeight: 800, color: on ? '#10b981' : 'var(--text-muted)' }}>{on ? 'Active' : 'Inactive'}</span>
    </button>
  )
}

const IconBtn = ({ children, title, color = 'var(--text-muted)', onClick }) => (
  <button title={title} onClick={onClick} style={{ width: 30, height: 30, borderRadius: 8, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', background: 'var(--bg-card)', border: '1px solid var(--border)', color }}>{children}</button>
)

// ── Add / edit modal — Vendor Info · Login Credentials · Address ────────────────
function VendorModal({ form, cfg, onClose, onDone }) {
  const isNew = !form.id
  const [f, setF] = useState(form)
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)
  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))

  const save = async () => {
    if (!f.company_name.trim()) { setErr('Company is required.'); return }
    if (f.password || f.password_confirmation) {
      if (f.password.length < 6) { setErr('Password must be at least 6 characters.'); return }
      if (f.password !== f.password_confirmation) { setErr('Passwords do not match.'); return }
    }
    if (isNew && !f.email.trim()) { setErr('Email is required to create the login.'); return }
    setSaving(true); setErr(null)
    const payload = {
      name: f.name || null, company_name: f.company_name, email: f.email || null, phone: f.phone || null,
      gst_number: f.gst_number || null, status: f.status,
      address: f.address || null, city: f.city || null, state: f.state || null, pincode: f.pincode || null,
      vendor_type: cfg.defaultVendorType, engagements: [cfg.engagement],
    }
    if (f.password) { payload.password = f.password; payload.password_confirmation = f.password_confirmation }
    try {
      if (isNew) await cfg.api.vendors.create(payload)
      else await cfg.api.vendors.update(f.id, payload)
      onDone()
    } catch (e) {
      setErr(e?.response?.data?.message || Object.values(e?.response?.data?.errors || {})[0]?.[0] || 'Could not save vendor.')
      setSaving(false)
    }
  }

  return (
    <Overlay onClose={onClose} width={640}>
      <div style={{ padding: '20px 22px 6px' }}>
        <h2 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>{isNew ? `Add ${cfg.moduleName}` : `Edit · ${form.company_name}`}</h2>
        <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>Vendor profile, portal login and address in one form.</p>
      </div>
      <div style={{ padding: '8px 22px', maxHeight: '64vh', overflowY: 'auto' }}>
        <Section title={`${cfg.moduleName} Information`} />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <Field label="Vendor Name"><TextInput value={f.name} onChange={set('name')} placeholder="Contact / login name" /></Field>
          <Field label="Company *"><TextInput value={f.company_name} onChange={set('company_name')} placeholder="Company name" /></Field>
          <Field label="Email"><TextInput type="email" value={f.email} onChange={set('email')} placeholder="login@vendor.com" /></Field>
          <Field label="Phone"><TextInput value={f.phone} onChange={set('phone')} placeholder="Phone" /></Field>
          <Field label="GST Number"><TextInput value={f.gst_number} onChange={set('gst_number')} placeholder="GSTIN" /></Field>
          <Field label="Status"><SelectInput value={f.status} onChange={set('status')} pairs options={[['Active', 'Active'], ['Inactive', 'Inactive']]} /></Field>
        </div>

        <Section title="Login Credentials" />
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <Field label="Password"><TextInput type="password" value={f.password} onChange={set('password')} placeholder="••••••" /></Field>
          <Field label="Confirm Password"><TextInput type="password" value={f.password_confirmation} onChange={set('password_confirmation')} placeholder="••••••" /></Field>
        </div>
        <p style={{ fontSize: 11, color: 'var(--text-muted)', margin: '2px 0 0' }}>Leave password blank to keep existing password.</p>

        <Section title="Address Information" />
        <Field label="Address" full><TextInput value={f.address} onChange={set('address')} placeholder="Street address" /></Field>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 12 }}>
          <Field label="City"><TextInput value={f.city} onChange={set('city')} placeholder="City" /></Field>
          <Field label="State"><TextInput value={f.state} onChange={set('state')} placeholder="State" /></Field>
          <Field label="Pincode"><TextInput value={f.pincode} onChange={set('pincode')} placeholder="Pincode" /></Field>
        </div>

        {err && <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 12px', borderRadius: 10, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)', marginTop: 10 }}><X size={14} style={{ color: '#ef4444' }} /><span style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{err}</span></div>}
      </div>
      <ModalFooter onClose={onClose} onConfirm={save} loading={saving} confirmLabel={isNew ? 'Create Vendor' : 'Save Changes'} color="#7C3AED" />
    </Overlay>
  )
}

const Section = ({ title }) => (
  <div style={{ display: 'flex', alignItems: 'center', gap: 8, margin: '14px 0 8px' }}>
    <span style={{ width: 6, height: 6, borderRadius: '50%', background: '#a78bfa' }} />
    <span style={{ fontSize: 11, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.05em', color: '#a78bfa' }}>{title}</span>
    <div style={{ flex: 1, height: 1, background: 'var(--border)' }} />
  </div>
)

const solidBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 700, color: '#fff', border: 'none', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)' }
const ghostBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 600, color: 'var(--text-muted)', background: 'var(--bg-card)', border: '1px solid var(--border)' }
