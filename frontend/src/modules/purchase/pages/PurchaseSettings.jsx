import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { Save, Plus, Pencil, Trash2, ExternalLink, Info } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { useAuth } from '@/context/AuthContext'
import { KIT3D_STYLE as PURCHASE_STYLE, labelStyle, inputStyle, Overlay, ModalFooter, Field, TextInput } from '@/components/ui/kit3d'

/**
 * Purchase → Settings. Four Purchase-owned tabs backed by purchase_settings
 * (tenant/key/value) plus the vendor-category master. The remaining legacy tabs
 * (Units, Commodity/Sub group, Approval, Permissions) are NOT duplicated here —
 * they already exist elsewhere in the app, so those tabs link out instead.
 */

const TABS = [
  { key: 'general',    label: 'General Settings' },
  { key: 'options',    label: 'Purchase Options' },
  { key: 'governance', label: 'Governance' },
  { key: 'categories', label: 'Vendor category' },
  { key: 'return',     label: 'Order Return' },
  // Managed elsewhere — pointers, not duplicates.
  { key: 'units',      label: 'Units',           linkTo: '/app/inventory/settings', where: 'Inventory → Settings → Units' },
  { key: 'commodity',  label: 'Commodity Group', linkTo: '/app/inventory/settings', where: 'Inventory → Settings → Groups' },
  { key: 'subgroup',   label: 'Sub group',       linkTo: '/app/inventory/settings', where: 'Inventory → Settings → Sub-groups' },
  { key: 'approval',   label: 'Approval',        linkTo: '/app/purchase/onboarding', where: 'the Purchase approval chain on each onboarding' },
  { key: 'permissions', label: 'Permissions',    linkTo: '/app/admin/staff',         where: 'Admin → Staff (roles)' },
]

const TOGGLES = [
  ['purchase_order_setting', 'Create Purchase Order without a Request/Quotation', 'When on, a PO can be raised directly — no PR or quotation required first.'],
  ['item_by_vendor', 'Load items by vendor', 'Item pickers show only items mapped to the selected vendor (Vendor-Items).'],
  ['po_only_prefix_and_number', 'Show only prefix & number on the PO number field', null],
  ['allow_vendors_to_register', 'Allow vendors to self-register', 'Controls the public Purchase Vendor registration page.'],
  ['show_purchase_tax_column', 'Show TAX per item', null],
  ['send_email_welcome_for_new_contact', 'Send welcome email to new contacts', null],
  ['reset_purchase_order_number_every_month', 'Reset purchase order number every month', null],
]

export default function PurchaseSettings() {
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'
  const [tab, setTab] = useState('general')
  const [settings, setSettings] = useState(null)
  const [msg, setMsg] = useState(null)

  const load = useCallback(() => {
    purchaseApi.settings.get().then(setSettings).catch(() => setSettings({}))
  }, [])
  useEffect(() => { load() }, [load])

  const save = async (keys) => {
    setMsg(null)
    const payload = Object.fromEntries(keys.map(k => [k, settings[k]]))
    try {
      const next = await purchaseApi.settings.update(payload)
      setSettings(next)
      setMsg({ type: 'ok', text: 'Settings saved.' })
    } catch (e) {
      const errors = e?.response?.data?.errors
      setMsg({ type: 'err', text: errors ? Object.values(errors).flat()[0] : (e?.response?.data?.message || 'Could not save settings.') })
    }
  }

  const set = (k) => (e) => setSettings(s => ({ ...s, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }))
  const active = TABS.find(t => t.key === tab)

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{PURCHASE_STYLE}</style>

      <div style={{ marginBottom: 20 }}>
        <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>PURCHASE &amp; PROCUREMENT</p>
        <h1 style={{ color: 'var(--text-h)', fontSize: 24, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>Settings</h1>
      </div>

      {!isAdmin && (
        <div className="pr-glass" style={{ padding: '12px 16px', borderRadius: 12, marginBottom: 14, display: 'flex', gap: 8, alignItems: 'center' }}>
          <Info size={15} style={{ color: '#f59e0b' }} />
          <span style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>You can view these settings, but only an admin can change them.</span>
        </div>
      )}

      <div style={{ display: 'flex', gap: 16, alignItems: 'flex-start', flexWrap: 'wrap' }}>
        {/* Left rail */}
        <nav className="pr-glass" style={{ width: 250, flexShrink: 0, borderRadius: 14, padding: 8, position: 'sticky', top: 16 }}>
          {TABS.map(t => {
            const on = tab === t.key
            return (
              <button key={t.key} onClick={() => { setTab(t.key); setMsg(null) }} style={{
                display: 'block', width: '100%', textAlign: 'left', padding: '10px 12px', borderRadius: 9,
                background: on ? 'rgba(124,58,237,0.12)' : 'transparent', border: 'none',
                borderLeft: `2px solid ${on ? '#7C3AED' : 'transparent'}`,
                color: on ? '#a78bfa' : 'var(--text-muted)', fontWeight: on ? 800 : 600, fontSize: 12.5, cursor: 'pointer',
              }}>{t.label}</button>
            )
          })}
        </nav>

        {/* Panel */}
        <div className="pr-glass" style={{ flex: 1, minWidth: 320, borderRadius: 16, padding: 22 }}>
          {!settings ? <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>Loading…</p>
            : active?.linkTo ? <ElsewhereTab tab={active} />
              : tab === 'general' ? <GeneralTab s={settings} set={set} save={save} msg={msg} canEdit={isAdmin} />
                : tab === 'options' ? <OptionsTab s={settings} set={set} save={save} msg={msg} canEdit={isAdmin} />
                  : tab === 'governance' ? <GovernanceTab s={settings} set={set} save={save} msg={msg} canEdit={isAdmin} />
                    : tab === 'return' ? <ReturnTab s={settings} set={set} save={save} msg={msg} canEdit={isAdmin} />
                      : <CategoriesTab canEdit={isAdmin} />}
        </div>
      </div>
    </div>
  )
}

/* ── Tabs that live in another module ────────────────────────────────────── */

function ElsewhereTab({ tab }) {
  const navigate = useNavigate()
  return (
    <div style={{ padding: '28px 0', textAlign: 'center' }}>
      <h2 style={{ fontSize: 16, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>{tab.label} is managed elsewhere</h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '8px 0 18px', maxWidth: 460, marginLeft: 'auto', marginRight: 'auto' }}>
        This already exists in <strong style={{ color: 'var(--text-h)' }}>{tab.where}</strong>. Purchase reads it from there rather than keeping a second copy.
      </p>
      <button onClick={() => navigate(tab.linkTo)} style={solidBtn}><ExternalLink size={14} /> Open {tab.label}</button>
    </div>
  )
}

/* ── General Settings ────────────────────────────────────────────────────── */

function GeneralTab({ s, set, save, msg, canEdit }) {
  const keys = [
    'pur_order_prefix', 'pur_request_prefix', 'pur_inv_prefix', 'debit_note_prefix',
    'next_po_number', 'next_pr_number', 'pur_invoice_auto_operations_hour',
    'pur_company_address', 'pur_company_city', 'pur_company_state',
    'pur_company_country_text', 'pur_company_zipcode', 'pur_company_country_code',
    'terms_and_conditions', 'vendor_note',
  ]
  return (
    <>
      <Section title="General Information" />
      <div style={grid2}>
        <Field label="Purchase order number prefix"><TextInput value={s.pur_order_prefix ?? ''} onChange={set('pur_order_prefix')} disabled={!canEdit} /></Field>
        <Field label="Purchase request number prefix"><TextInput value={s.pur_request_prefix ?? ''} onChange={set('pur_request_prefix')} disabled={!canEdit} /></Field>
        <Field label="Purchase invoice prefix"><TextInput value={s.pur_inv_prefix ?? ''} onChange={set('pur_inv_prefix')} disabled={!canEdit} /></Field>
        <Field label="Debit note prefix"><TextInput value={s.debit_note_prefix ?? ''} onChange={set('debit_note_prefix')} disabled={!canEdit} /></Field>
        <Field label="Next purchase order number"><TextInput type="number" min="1" value={s.next_po_number ?? 1} onChange={set('next_po_number')} disabled={!canEdit} /></Field>
        <Field label="Next purchase request number"><TextInput type="number" min="1" value={s.next_pr_number ?? 1} onChange={set('next_pr_number')} disabled={!canEdit} /></Field>
        <Field label="Hour of day to perform automatic purchase invoice operations" full>
          <TextInput type="number" min="0" max="23" value={s.pur_invoice_auto_operations_hour ?? 21} onChange={set('pur_invoice_auto_operations_hour')} disabled={!canEdit} />
        </Field>
      </div>
      <p style={hintStyle}>Saving here switches new PO / PR / Order-Return numbers to <code>prefix + next number</code>. Existing documents keep their numbers.</p>

      <Section title="Shipping Information" />
      <div style={grid2}>
        <Field label="Address" full>
          <textarea value={s.pur_company_address ?? ''} onChange={set('pur_company_address')} rows={5} disabled={!canEdit} style={{ ...inputStyle, resize: 'vertical' }} />
        </Field>
        <Field label="City"><TextInput value={s.pur_company_city ?? ''} onChange={set('pur_company_city')} disabled={!canEdit} /></Field>
        <Field label="State"><TextInput value={s.pur_company_state ?? ''} onChange={set('pur_company_state')} disabled={!canEdit} /></Field>
        <Field label="Country Code"><TextInput value={s.pur_company_country_text ?? ''} onChange={set('pur_company_country_text')} placeholder="IN" disabled={!canEdit} /></Field>
        <Field label="Zip Code"><TextInput value={s.pur_company_zipcode ?? ''} onChange={set('pur_company_zipcode')} disabled={!canEdit} /></Field>
        <Field label="Country" full><TextInput value={s.pur_company_country_code ?? ''} onChange={set('pur_company_country_code')} placeholder="India" disabled={!canEdit} /></Field>
      </div>

      <Section title="Other Information" />
      <Field label="Terms &amp; Conditions" full>
        <textarea value={s.terms_and_conditions ?? ''} onChange={set('terms_and_conditions')} rows={7} disabled={!canEdit} style={{ ...inputStyle, resize: 'vertical' }} />
      </Field>
      <div style={{ height: 12 }} />
      <Field label="Vendor note" full>
        <textarea value={s.vendor_note ?? ''} onChange={set('vendor_note')} rows={4} disabled={!canEdit} style={{ ...inputStyle, resize: 'vertical' }} />
      </Field>

      <SaveBar msg={msg} canEdit={canEdit} onSave={() => save(keys)} />
    </>
  )
}

/* ── Purchase Options ────────────────────────────────────────────────────── */

function OptionsTab({ s, set, save, msg, canEdit }) {
  return (
    <>
      <Section title="Purchase Options" />
      <div style={{ display: 'grid', gap: 2 }}>
        {TOGGLES.map(([key, label, help]) => (
          <label key={key} style={{ display: 'flex', gap: 11, alignItems: 'flex-start', padding: '12px 4px', borderBottom: '1px solid var(--border)', cursor: canEdit ? 'pointer' : 'default' }}>
            <input type="checkbox" checked={!!s[key]} onChange={set(key)} disabled={!canEdit} style={{ width: 16, height: 16, marginTop: 2, cursor: canEdit ? 'pointer' : 'default' }} />
            <span>
              <span style={{ display: 'block', fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{label}</span>
              {help && <span style={{ display: 'block', fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>{help}</span>}
            </span>
          </label>
        ))}
      </div>
      <SaveBar msg={msg} canEdit={canEdit} onSave={() => save(TOGGLES.map(([k]) => k))} />
    </>
  )
}

/* ── Governance (§34) ────────────────────────────────────────────────────── */

function GovernanceTab({ s, set, save, msg, canEdit }) {
  const keys = ['gate_ppe_enforcement', 'communications_auto_dispatch', 'temporary_vendor_validity_days']
  const PPE_MODES = [
    ['warn', 'Warn — admit, but flag missing PPE to the guard (default)'],
    ['deny', 'Deny — refuse entry until PPE is issued'],
    ['off',  'Off — do not check PPE at the gate'],
  ]
  return (
    <>
      <Section title="Worker Gate — PPE enforcement" />
      <Field label="How the gate reacts when a worker holds no issued PPE" full>
        <select value={s.gate_ppe_enforcement ?? 'warn'} onChange={set('gate_ppe_enforcement')} disabled={!canEdit} style={inputStyle}>
          {PPE_MODES.map(([v, label]) => <option key={v} value={v}>{label}</option>)}
        </select>
      </Field>
      <p style={hintStyle}>Applies to every worker scan at the site gate. A PPE-subsystem error never blocks an otherwise-clear worker.</p>

      <Section title="Communications — automatic dispatch" />
      <label style={{ display: 'flex', gap: 11, alignItems: 'flex-start', padding: '12px 4px', borderBottom: '1px solid var(--border)', cursor: canEdit ? 'pointer' : 'default' }}>
        <input type="checkbox" checked={!!s.communications_auto_dispatch} onChange={set('communications_auto_dispatch')} disabled={!canEdit} style={{ width: 16, height: 16, marginTop: 2, cursor: canEdit ? 'pointer' : 'default' }} />
        <span>
          <span style={{ display: 'block', fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>Email the vendor automatically on governance events</span>
          <span style={{ display: 'block', fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>When on, an NCR raised or a violation recorded emails the vendor immediately, alongside the pull-based alerts feed. Off falls back to manual Send only.</span>
        </span>
      </label>

      <div style={{ height: 16 }} />
      <Section title="Temporary vendor access" />
      <Field label="Days of portal access a Temporary Vendor gets after activation">
        <TextInput type="number" min="1" value={s.temporary_vendor_validity_days ?? 5} onChange={set('temporary_vendor_validity_days')} disabled={!canEdit} />
      </Field>
      <p style={hintStyle}>Counted from the moment an admin activates the vendor, never from registration. Standard vendors never expire.</p>

      <SaveBar msg={msg} canEdit={canEdit} onSave={() => save(keys)} />
    </>
  )
}

/* ── Order Return ────────────────────────────────────────────────────────── */

function ReturnTab({ s, set, save, msg, canEdit }) {
  const keys = ['pur_order_return_number_prefix', 'next_pur_order_return_number']
  return (
    <>
      <Section title="Order Return" />
      <div style={grid2}>
        <Field label="Order Return Number Prefix"><TextInput value={s.pur_order_return_number_prefix ?? ''} onChange={set('pur_order_return_number_prefix')} disabled={!canEdit} /></Field>
        <Field label="Next Order Return Number"><TextInput type="number" min="1" value={s.next_pur_order_return_number ?? 1} onChange={set('next_pur_order_return_number')} disabled={!canEdit} /></Field>
      </div>
      <p style={hintStyle}>Applies to new order returns only.</p>
      <SaveBar msg={msg} canEdit={canEdit} onSave={() => save(keys)} />
    </>
  )
}

/* ── Vendor category (CRUD) ──────────────────────────────────────────────── */

function CategoriesTab({ canEdit }) {
  const [rows, setRows] = useState(null)
  const [modal, setModal] = useState(null)
  const [err, setErr] = useState(null)

  const load = useCallback(() => {
    purchaseApi.vendorCategories.list().then(r => setRows(r?.data ?? r ?? [])).catch(() => setRows([]))
  }, [])
  useEffect(() => { load() }, [load])

  const remove = async (row) => {
    if (!window.confirm(`Delete category "${row.name}"?`)) return
    setErr(null)
    try { await purchaseApi.vendorCategories.delete(row.id); load() }
    catch (e) { setErr(e?.response?.data?.message || 'Could not delete the category.') }
  }

  return (
    <>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 14, gap: 10 }}>
        <h2 style={{ fontSize: 15, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>Vendor category</h2>
        {canEdit && <button onClick={() => setModal({ name: '', description: '' })} style={solidBtn}><Plus size={14} /> New Category</button>}
      </div>

      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '0 0 10px' }}>{err}</p>}

      {rows === null ? <p style={{ color: 'var(--text-muted)', fontSize: 13 }}>Loading…</p>
        : rows.length === 0 ? <p style={{ color: 'var(--text-muted)', fontSize: 13, padding: '18px 0' }}>No categories yet. Add one so vendors can be classified.</p>
          : (
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead><tr>{['Name', 'Description', 'Vendors', ''].map(h => (
                <th key={h} style={{ textAlign: 'left', padding: '10px 12px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '.04em', borderBottom: '1px solid var(--border)' }}>{h}</th>
              ))}</tr></thead>
              <tbody>
                {rows.map(r => (
                  <tr key={r.id} className="pr-li-row">
                    <td style={{ padding: '10px 12px', fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>{r.name}</td>
                    <td style={{ padding: '10px 12px', fontSize: 12.5, color: 'var(--text-muted)' }}>{r.description || '—'}</td>
                    <td style={{ padding: '10px 12px', fontSize: 12.5, color: 'var(--text-muted)' }}>{r.vendor_count ?? 0}</td>
                    <td style={{ padding: '8px 12px', textAlign: 'right', whiteSpace: 'nowrap' }}>
                      {canEdit && (
                        <div style={{ display: 'inline-flex', gap: 6 }}>
                          <IconBtn title="Edit" onClick={() => setModal({ ...r })}><Pencil size={13} /></IconBtn>
                          <IconBtn title="Delete" color="#ef4444" onClick={() => remove(r)}><Trash2 size={13} /></IconBtn>
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}

      {modal && <CategoryModal category={modal} onClose={() => setModal(null)} onDone={() => { setModal(null); load() }} />}
    </>
  )
}

function CategoryModal({ category, onClose, onDone }) {
  const isNew = !category.id
  const [f, setF] = useState({ name: category.name || '', description: category.description || '' })
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)

  const save = async () => {
    if (!f.name.trim()) { setErr('Name is required.'); return }
    setSaving(true); setErr(null)
    try {
      if (isNew) await purchaseApi.vendorCategories.create(f)
      else await purchaseApi.vendorCategories.update(category.id, f)
      onDone()
    } catch (e) {
      const errors = e?.response?.data?.errors
      setErr(errors ? Object.values(errors).flat()[0] : (e?.response?.data?.message || 'Could not save the category.'))
      setSaving(false)
    }
  }

  return (
    <Overlay onClose={onClose} width={520}>
      <div style={{ padding: '20px 22px 8px' }}>
        <h2 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>{isNew ? 'New Vendor Category' : 'Edit Vendor Category'}</h2>
      </div>
      <div style={{ padding: '14px 22px 0', display: 'grid', gap: 14 }}>
        <Field label="Name *"><TextInput value={f.name} onChange={e => setF(p => ({ ...p, name: e.target.value }))} /></Field>
        <Field label="Description">
          <textarea value={f.description} onChange={e => setF(p => ({ ...p, description: e.target.value }))} rows={3} style={{ ...inputStyle, resize: 'vertical' }} />
        </Field>
      </div>
      <div style={{ padding: '0 22px 20px' }}>
        {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '12px 0 0' }}>{err}</p>}
        <ModalFooter onClose={onClose} onConfirm={save} loading={saving} confirmLabel={isNew ? 'Add Category' : 'Save Changes'} />
      </div>
    </Overlay>
  )
}

/* ── bits ────────────────────────────────────────────────────────────────── */

const Section = ({ title }) => (
  <div style={{ margin: '4px 0 14px' }}>
    <h3 style={{ fontSize: 13.5, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>{title}</h3>
    <div style={{ height: 1, background: 'var(--border)', marginTop: 8 }} />
  </div>
)

const SaveBar = ({ msg, canEdit, onSave }) => (
  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, marginTop: 20, paddingTop: 14, borderTop: '1px solid var(--border)' }}>
    <span style={{ fontSize: 12.5, color: msg?.type === 'err' ? '#ef4444' : '#10b981' }}>{msg?.text || ''}</span>
    {canEdit && <button onClick={onSave} style={solidBtn}><Save size={14} /> Save</button>}
  </div>
)

const IconBtn = ({ children, title, color = 'var(--text-muted)', onClick }) => (
  <button title={title} onClick={onClick} style={{ width: 30, height: 30, borderRadius: 8, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', background: 'var(--bg-card)', border: '1px solid var(--border)', color }}>{children}</button>
)

const grid2 = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(240px,1fr))', gap: 14, marginBottom: 6 }
const hintStyle = { fontSize: 11.5, color: 'var(--text-muted)', margin: '6px 0 18px' }
const solidBtn = { display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 16px', borderRadius: 10, background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', border: 'none', color: '#fff', cursor: 'pointer', fontSize: 13, fontWeight: 800 }
