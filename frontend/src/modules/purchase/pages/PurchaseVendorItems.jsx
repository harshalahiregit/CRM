import { useState, useEffect, useCallback, useMemo } from 'react'
import { Plus, RefreshCw, Search, Pencil, Trash2, Boxes, Download } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { inventoryApi } from '@/services/inventoryApi'
import { exportCsv, stampedName } from '@/lib/exportCsv'
import { fmtDate } from '../constants'
import {
  KIT3D_STYLE as PURCHASE_STYLE, labelStyle, inputStyle, Overlay, ModalFooter, Field, TextInput, SelectInput,
} from '@/components/ui/kit3d'

/**
 * Purchase → Vendor Items.
 *
 * A pure MAPPING screen: Purchase Vendor (purchase_vendors) ↔ Inventory Item
 * (inventory_products). Vendors come from the Purchase API, item groups and
 * items are READ from the Inventory APIs — no item data is duplicated into
 * Purchase, and no second Item Master is created. Deleting a mapping removes the
 * supply link only. No TPV, no shared Vendor, purchase_vendor_id only.
 */

const PER_PAGE_OPTIONS = [10, 25, 50, 100]
const statusCfg = (s) => (s === 'Active'
  ? { label: 'Active', color: '#10b981', bg: 'rgba(16,185,129,0.15)' }
  : { label: 'Inactive', color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' })

// The Inventory product's display name / code — always read, never stored.
const itemName = (p) => p?.sku_name || p?.name || '—'
const itemCode = (p) => p?.sku_code || p?.sku || '—'

export default function PurchaseVendorItems() {
  const [page, setPage] = useState({ data: [], total: 0, from: 0, to: 0, current_page: 1, last_page: 1 })
  const [loading, setLoading] = useState(true)
  const [vendors, setVendors] = useState([])
  const [groups, setGroups] = useState([])
  const [products, setProducts] = useState([])

  const [vendorId, setVendorId] = useState('')
  const [groupId, setGroupId] = useState('')
  const [productId, setProductId] = useState('')
  const [search, setSearch] = useState('')
  const [perPage, setPerPage] = useState(25)
  const [pageNo, setPageNo] = useState(1)
  const [editing, setEditing] = useState(null)

  // Reference data: vendors from Purchase, groups + items from Inventory.
  useEffect(() => {
    purchaseApi.vendors.list().then(r => setVendors(r?.data ?? r ?? [])).catch(() => {})
    inventoryApi.settings.list('groups').then(r => setGroups(r?.data ?? r ?? [])).catch(() => {})
    inventoryApi.products.list().then(r => setProducts(r?.data ?? r ?? [])).catch(() => {})
  }, [])

  const load = useCallback(() => {
    setLoading(true)
    const params = { per_page: perPage, page: pageNo }
    if (vendorId) params.purchase_vendor_id = vendorId
    if (groupId) params.group_id = groupId
    if (productId) params.inventory_product_id = productId
    if (search.trim()) params.search = search.trim()
    purchaseApi.vendorItems.list(params)
      .then(r => setPage(r?.data ? r : { ...page, data: r ?? [] }))
      .catch(() => setPage({ data: [], total: 0, from: 0, to: 0, current_page: 1, last_page: 1 }))
      .finally(() => setLoading(false))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [vendorId, groupId, productId, search, perPage, pageNo])

  useEffect(() => { const t = setTimeout(load, search ? 300 : 0); return () => clearTimeout(t) }, [load, search])
  useEffect(() => { setPageNo(1) }, [vendorId, groupId, productId, search, perPage])

  // Item dropdown narrows to the chosen group (client-side: the Inventory list
  // endpoint has no group filter and Inventory must not be modified).
  const itemOptions = useMemo(
    () => (groupId ? products.filter(p => String(p.group_id) === String(groupId)) : products),
    [products, groupId],
  )

  const rows = page.data || []

  const remove = async (row) => {
    if (!window.confirm(`Remove "${itemName(row.product)}" from ${row.vendor?.company_name}?\n\nThis unlinks the item from this vendor only — the Inventory item and other vendors are unaffected.`)) return
    try { await purchaseApi.vendorItems.delete(row.id); load() }
    catch (e) { alert(e?.response?.data?.message || 'Could not delete the mapping.') }
  }

  const doExport = () => exportCsv(stampedName('vendor-items'), rows, [
    { label: 'Vendor', value: r => r.vendor?.company_name },
    { label: 'Item', value: r => itemName(r.product) },
    { label: 'Item Code', value: r => itemCode(r.product) },
    { label: 'Item Group', value: r => r.product?.group?.name || '' },
    { label: 'Date Created', value: r => fmtDate(r.created_at) },
    { label: 'Status', value: r => r.status },
  ])

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{PURCHASE_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>PURCHASE VENDOR ↔ INVENTORY ITEM</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 24, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>Vendor-Items</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Which of your Purchase Vendors supply which Inventory items. One item can be mapped to many vendors.</p>
        </div>
        <div style={{ display: 'flex', gap: 9 }}>
          <button onClick={load} style={ghostBtn}><RefreshCw size={14} /> Refresh</button>
          <button onClick={doExport} disabled={!rows.length} style={ghostBtn}><Download size={14} /> Export</button>
          <button onClick={() => setEditing({})} style={solidBtn}><Plus size={15} /> Vendor Item</button>
        </div>
      </div>

      {/* Filters — Vendor · Group item · Item */}
      <div className="pr-glass" style={{ padding: 16, borderRadius: 16, marginBottom: 16 }}>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))', gap: 14 }}>
          <div>
            <label style={labelStyle}>Vendors</label>
            <select value={vendorId} onChange={e => setVendorId(e.target.value)} style={{ ...inputStyle, cursor: 'pointer' }}>
              <option value="">None selected</option>
              {vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}</option>)}
            </select>
          </div>
          <div>
            <label style={labelStyle}>Group item</label>
            <select value={groupId} onChange={e => { setGroupId(e.target.value); setProductId('') }} style={{ ...inputStyle, cursor: 'pointer' }}>
              <option value="">None selected</option>
              {groups.map(g => <option key={g.id} value={g.id}>{g.name}</option>)}
            </select>
          </div>
          <div>
            <label style={labelStyle}>Item</label>
            <select value={productId} onChange={e => setProductId(e.target.value)} style={{ ...inputStyle, cursor: 'pointer' }}>
              <option value="">Select item</option>
              {itemOptions.map(p => <option key={p.id} value={p.id}>{itemCode(p)} - {itemName(p)}</option>)}
            </select>
          </div>
        </div>
      </div>

      {/* Rows-per-page + search */}
      <div style={{ display: 'flex', gap: 10, marginBottom: 14, flexWrap: 'wrap', alignItems: 'center' }}>
        <select value={perPage} onChange={e => setPerPage(Number(e.target.value))} style={{ ...inputStyle, width: 90, cursor: 'pointer' }}>
          {PER_PAGE_OPTIONS.map(n => <option key={n} value={n}>{n}</option>)}
        </select>
        <div style={{ position: 'relative', marginLeft: 'auto', minWidth: 260 }}>
          <Search size={14} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search vendor, item, code…" style={{ ...inputStyle, paddingLeft: 32 }} />
        </div>
      </div>

      {loading ? (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>{[1, 2, 3].map(i => <div key={i} className="skeleton" style={{ height: 56, borderRadius: 14, background: 'var(--border)' }} />)}</div>
      ) : rows.length === 0 ? (
        <div className="pr-glass" style={{ padding: '48px 24px', textAlign: 'center', borderRadius: 16 }}>
          <div style={{ width: 60, height: 60, borderRadius: '50%', margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.12)' }}><Boxes size={28} style={{ color: '#a78bfa' }} /></div>
          <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>No vendor items</h3>
          <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '6px 0 18px' }}>Map an Inventory item to a Purchase Vendor to record who supplies it.</p>
          <button onClick={() => setEditing({})} style={{ ...solidBtn, margin: '0 auto' }}><Plus size={15} /> Vendor Item</button>
        </div>
      ) : (
        <div className="pr-glass" style={{ padding: 0, borderRadius: 16, overflow: 'hidden' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 860 }}>
              <thead><tr>{['Vendor', 'Item', 'Item Code', 'Item Group', 'Date Created', 'Status', ''].map(h => (
                <th key={h} style={{ textAlign: 'left', padding: '11px 14px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', borderBottom: '1px solid var(--border)' }}>{h}</th>
              ))}</tr></thead>
              <tbody>
                {rows.map(r => {
                  const cfg = statusCfg(r.status)
                  return (
                    <tr key={r.id} className="pr-li-row">
                      <td style={{ padding: '11px 14px', fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>{r.vendor?.company_name || '—'}
                        {r.vendor?.purchase_vendor_code && <span style={{ color: '#a78bfa', fontSize: 11, fontWeight: 700 }}> · {r.vendor.purchase_vendor_code}</span>}</td>
                      <td style={{ padding: '11px 14px', fontSize: 12.5, color: 'var(--text-h)' }}>{itemName(r.product)}</td>
                      <td style={{ padding: '11px 14px', fontSize: 12.5, color: '#a78bfa', fontWeight: 700, whiteSpace: 'nowrap' }}>{itemCode(r.product)}</td>
                      <td style={{ padding: '11px 14px', fontSize: 12.5, color: 'var(--text-muted)' }}>{r.product?.group?.name || '—'}</td>
                      <td style={{ padding: '11px 14px', fontSize: 12.5, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{fmtDate(r.created_at)}</td>
                      <td style={{ padding: '11px 14px' }}><span style={{ padding: '3px 9px', borderRadius: 999, background: cfg.bg, color: cfg.color, fontSize: 10.5, fontWeight: 800 }}>{cfg.label}</span></td>
                      <td style={{ padding: '8px 14px', textAlign: 'right', whiteSpace: 'nowrap' }}>
                        <div style={{ display: 'inline-flex', gap: 6 }}>
                          <IconBtn title="Edit" onClick={() => setEditing(r)}><Pencil size={13} /></IconBtn>
                          <IconBtn title="Delete mapping" color="#ef4444" onClick={() => remove(r)}><Trash2 size={13} /></IconBtn>
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 16px', borderTop: '1px solid var(--border)', flexWrap: 'wrap', gap: 10 }}>
            <span style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>
              Showing {page.from ?? 0} to {page.to ?? 0} of {page.total ?? rows.length} entries
            </span>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <button onClick={() => setPageNo(p => Math.max(1, p - 1))} disabled={(page.current_page ?? 1) <= 1} style={pageBtn}>Previous</button>
              <span style={{ fontSize: 12.5, color: 'var(--text-h)', fontWeight: 700, minWidth: 26, textAlign: 'center' }}>{page.current_page ?? 1}</span>
              <button onClick={() => setPageNo(p => Math.min(page.last_page ?? 1, p + 1))} disabled={(page.current_page ?? 1) >= (page.last_page ?? 1)} style={pageBtn}>Next</button>
            </div>
          </div>
        </div>
      )}

      {editing && (
        <MappingModal
          mapping={editing} vendors={vendors} groups={groups} products={products}
          onClose={() => setEditing(null)} onDone={() => { setEditing(null); load() }}
        />
      )}
    </div>
  )
}

// ── Create / edit mapping ────────────────────────────────────────────────────
function MappingModal({ mapping, vendors, groups, products, onClose, onDone }) {
  const isNew = !mapping.id
  const [f, setF] = useState({
    purchase_vendor_id: mapping.purchase_vendor_id || '',
    group_id: mapping.product?.group_id || '',
    inventory_product_id: mapping.inventory_product_id || '',
    effective_date: (mapping.effective_date || '').slice(0, 10),
    status: mapping.status || 'Active',
    remarks: mapping.remarks || '',
  })
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)

  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))
  // Items narrow to the chosen Inventory group.
  const itemOptions = useMemo(
    () => (f.group_id ? products.filter(p => String(p.group_id) === String(f.group_id)) : products),
    [products, f.group_id],
  )

  const save = async () => {
    if (!f.purchase_vendor_id) { setErr('Select a Purchase Vendor.'); return }
    if (!f.inventory_product_id) { setErr('Select an Inventory Item.'); return }
    setSaving(true); setErr(null)
    try {
      // group_id is a UI-only narrowing filter — the mapping stores no item data.
      const payload = {
        purchase_vendor_id: Number(f.purchase_vendor_id),
        inventory_product_id: Number(f.inventory_product_id),
        effective_date: f.effective_date || null,
        status: f.status,
        remarks: f.remarks || null,
      }
      if (isNew) await purchaseApi.vendorItems.create(payload)
      else await purchaseApi.vendorItems.update(mapping.id, payload)
      onDone()
    } catch (e) {
      const errors = e?.response?.data?.errors
      setErr(errors ? Object.values(errors).flat()[0] : (e?.response?.data?.message || 'Could not save the mapping.'))
      setSaving(false)
    }
  }

  return (
    <Overlay onClose={onClose} width={620}>
      <div style={{ padding: '20px 22px 8px' }}>
        <h2 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>{isNew ? 'New Vendor Item' : 'Edit Vendor Item'}</h2>
        <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Links a Purchase Vendor to an existing Inventory item — it never creates an item.</p>
      </div>
      <div style={{ padding: '14px 22px 0', display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>
        <Field label="Purchase Vendor *" full>
          <select value={f.purchase_vendor_id} onChange={set('purchase_vendor_id')} style={{ ...inputStyle, cursor: 'pointer' }}>
            <option value="">Select vendor…</option>
            {vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}{v.purchase_vendor_code ? ` · ${v.purchase_vendor_code}` : ''}</option>)}
          </select>
        </Field>
        <Field label="Inventory Item Group *">
          <select value={f.group_id} onChange={e => setF(p => ({ ...p, group_id: e.target.value, inventory_product_id: '' }))} style={{ ...inputStyle, cursor: 'pointer' }}>
            <option value="">Select group…</option>
            {groups.map(g => <option key={g.id} value={g.id}>{g.name}</option>)}
          </select>
        </Field>
        <Field label="Inventory Item *">
          <select value={f.inventory_product_id} onChange={set('inventory_product_id')} style={{ ...inputStyle, cursor: 'pointer' }}>
            <option value="">Select item…</option>
            {itemOptions.map(p => <option key={p.id} value={p.id}>{itemCode(p)} - {itemName(p)}</option>)}
          </select>
        </Field>
        <Field label="Effective Date"><TextInput type="date" value={f.effective_date} onChange={set('effective_date')} /></Field>
        <Field label="Status"><SelectInput value={f.status} onChange={set('status')} options={['Active', 'Inactive']} /></Field>
        <Field label="Remarks" full>
          <textarea value={f.remarks} onChange={set('remarks')} rows={2} style={{ ...inputStyle, resize: 'vertical' }} />
        </Field>
      </div>
      <div style={{ padding: '0 22px 20px' }}>
        {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '12px 0 0' }}>{err}</p>}
        <ModalFooter onClose={onClose} onConfirm={save} loading={saving} confirmLabel={isNew ? 'Add Mapping' : 'Save Changes'} />
      </div>
    </Overlay>
  )
}

const IconBtn = ({ children, title, color = 'var(--text-muted)', onClick }) => (
  <button title={title} onClick={onClick} style={{ width: 30, height: 30, borderRadius: 8, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', background: 'var(--bg-card)', border: '1px solid var(--border)', color }}>{children}</button>
)

const ghostBtn = { display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 14px', borderRadius: 10, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const solidBtn = { display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 16px', borderRadius: 10, background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', border: 'none', color: '#fff', cursor: 'pointer', fontSize: 13, fontWeight: 800, boxShadow: '0 8px 20px -8px rgba(124,58,237,.7)' }
const pageBtn = { padding: '6px 14px', borderRadius: 8, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12.5, fontWeight: 700 }
