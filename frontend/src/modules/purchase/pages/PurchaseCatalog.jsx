import { useState, useEffect, useCallback } from 'react'
import {
  Plus, RefreshCw, Package, CheckCircle, Ban, Boxes, Search, Pencil, X, PlayCircle, Trash2,
} from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { useAuth } from '@/context/AuthContext'
import { CATALOG_STATUS, catalogStatusCfg, fmtMoney, canManagePR } from '../constants'
import {
  KIT3D_STYLE as PURCHASE_STYLE, labelStyle, inputStyle, Overlay, ModalFooter, Field, TextInput, SelectInput,
} from '@/components/ui/kit3d'

export default function PurchaseCatalog() {
  const { user } = useAuth()
  const manage = canManagePR(user)
  const [rows, setRows] = useState([])
  const [stats, setStats] = useState({})
  const [loading, setLoad] = useState(true)
  const [filter, setFilter] = useState('All')
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState(null)   // item being edited, or {} for new

  const load = useCallback(() => {
    setLoad(true)
    const params = {}
    if (filter !== 'All') params.status = filter
    if (search.trim()) params.search = search.trim()
    Promise.all([purchaseApi.catalog.list(params), purchaseApi.catalog.stats()])
      .then(([list, s]) => { setRows(list?.data ?? list ?? []); setStats(s?.data ?? s ?? {}); setLoad(false) })
      .catch(() => setLoad(false))
  }, [filter, search])
  useEffect(() => { const t = setTimeout(load, search ? 300 : 0); return () => clearTimeout(t) }, [load, search])

  const setStatus = async (item, status) => {
    try { await purchaseApi.catalog.setStatus(item.id, status); load() }
    catch (e) { alert(e?.response?.data?.message || 'Failed') }
  }
  const remove = async (item) => {
    if (!confirm(`Delete ${item.sku}? Only draft items can be deleted.`)) return
    try { await purchaseApi.catalog.delete(item.id); load() }
    catch (e) { alert(e?.response?.data?.message || 'Failed') }
  }

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{PURCHASE_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>ITEM MASTER</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 24, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>Catalog</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Standardized SKUs your requests, RFQs and orders pick from — no more free text.</p>
        </div>
        <div style={{ display: 'flex', gap: 9 }}>
          <button onClick={load} style={ghostBtn}><RefreshCw size={14} /> Refresh</button>
          {manage && <button onClick={() => setEditing({})} style={solidBtn}><Plus size={15} /> New Item</button>}
        </div>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 14, marginBottom: 18 }}>
        <Kpi label="Active" value={stats.active} icon={CheckCircle} color="#10b981" onClick={() => setFilter(CATALOG_STATUS.ACTIVE)} />
        <Kpi label="Draft" value={stats.draft} icon={Package} color="#94a3b8" onClick={() => setFilter(CATALOG_STATUS.DRAFT)} />
        <Kpi label="Discontinued" value={stats.discontinued} icon={Ban} color="#ef4444" onClick={() => setFilter(CATALOG_STATUS.DISCONTINUED)} />
        <Kpi label="Categories" value={stats.categories} icon={Boxes} color="#a78bfa" />
      </div>

      <div style={{ display: 'flex', gap: 10, marginBottom: 16, flexWrap: 'wrap', alignItems: 'center' }}>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {['All', CATALOG_STATUS.ACTIVE, CATALOG_STATUS.DRAFT, CATALOG_STATUS.DISCONTINUED].map(fv => {
            const on = filter === fv
            return <button key={fv} onClick={() => setFilter(fv)} style={{ padding: '6px 14px', borderRadius: 999, fontSize: 12.5, fontWeight: 700, cursor: 'pointer', background: on ? 'linear-gradient(145deg,#a78bfa,#7C3AED)' : 'var(--bg-card)', border: on ? 'none' : '1px solid var(--border)', color: on ? '#fff' : 'var(--text-muted)', boxShadow: on ? '0 6px 16px -6px rgba(124,58,237,.6)' : 'none' }}>{fv === 'All' ? 'All' : catalogStatusCfg(fv).label}</button>
          })}
        </div>
        <div style={{ position: 'relative', marginLeft: 'auto', minWidth: 240 }}>
          <Search size={14} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search name, SKU, HSN…" style={{ ...inputStyle, paddingLeft: 32 }} />
        </div>
      </div>

      {loading ? (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>{[1, 2, 3, 4].map(i => <div key={i} className="skeleton" style={{ height: 64, borderRadius: 14, background: 'var(--border)' }} />)}</div>
      ) : rows.length === 0 ? (
        <div className="pr-glass" style={{ padding: '48px 24px', textAlign: 'center' }}>
          <div style={{ width: 60, height: 60, borderRadius: '50%', margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.12)' }}><Package size={28} style={{ color: '#a78bfa' }} /></div>
          <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>No catalog items</h3>
          <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '6px 0 18px' }}>Add standardized items so buyers pick instead of typing.</p>
          {manage && <button onClick={() => setEditing({})} style={{ ...solidBtn, margin: '0 auto' }}><Plus size={15} /> New Item</button>}
        </div>
      ) : (
        <div className="pr-glass" style={{ padding: 0, borderRadius: 16, overflow: 'hidden' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 720 }}>
              <thead><tr>{['SKU', 'Item', 'Category', 'UOM', 'Default Rate', 'Status', ''].map((h, i) => (
                <th key={h} style={{ textAlign: i === 4 ? 'right' : 'left', padding: '11px 14px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', borderBottom: '1px solid var(--border)' }}>{h}</th>
              ))}</tr></thead>
              <tbody>
                {rows.map(r => {
                  const cfg = catalogStatusCfg(r.status)
                  return (
                    <tr key={r.id} className="pr-li-row">
                      <td style={{ padding: '11px 14px', fontSize: 12.5, fontWeight: 700, color: '#a78bfa', whiteSpace: 'nowrap' }}>{r.sku}</td>
                      <td style={{ padding: '11px 14px', fontSize: 12.5, color: 'var(--text-h)' }}>{r.name}{r.hsn_code && <span style={{ color: 'var(--text-muted)', fontSize: 11 }}> · HSN {r.hsn_code}</span>}</td>
                      <td style={{ padding: '11px 14px', fontSize: 12.5, color: 'var(--text-muted)' }}>{r.category || '—'}</td>
                      <td style={{ padding: '11px 14px', fontSize: 12.5, color: 'var(--text-muted)' }}>{r.uom || '—'}</td>
                      <td style={{ padding: '11px 14px', fontSize: 12.5, textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: 'var(--text-h)' }}>{fmtMoney(r.default_rate)}</td>
                      <td style={{ padding: '11px 14px' }}><span style={{ padding: '3px 9px', borderRadius: 999, background: cfg.bg, color: cfg.color, fontSize: 10.5, fontWeight: 800 }}>{cfg.label}</span></td>
                      <td style={{ padding: '8px 14px', textAlign: 'right', whiteSpace: 'nowrap' }}>
                        {manage && (
                          <div style={{ display: 'inline-flex', gap: 6 }}>
                            <IconBtn title="Edit" onClick={() => setEditing(r)}><Pencil size={13} /></IconBtn>
                            {r.status !== 'Active' && <IconBtn title="Activate" color="#10b981" onClick={() => setStatus(r, 'Active')}><PlayCircle size={13} /></IconBtn>}
                            {r.status === 'Active' && <IconBtn title="Discontinue" color="#ef4444" onClick={() => setStatus(r, 'Discontinued')}><Ban size={13} /></IconBtn>}
                            {r.status === 'Draft' && <IconBtn title="Delete" color="#ef4444" onClick={() => remove(r)}><Trash2 size={13} /></IconBtn>}
                          </div>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {editing && <ItemModal item={editing} onClose={() => setEditing(null)} onDone={() => { setEditing(null); load() }} />}
    </div>
  )
}

function Kpi({ label, value, icon: Icon, color, onClick }) {
  return (
    <div className="pr-kpi" onClick={onClick} style={{ padding: 16, cursor: onClick ? 'pointer' : 'default' }}>
      <div style={{ width: 38, height: 38, borderRadius: 12, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `${color}1f` }}><Icon size={18} style={{ color }} /></div>
      <div style={{ fontSize: 24, fontWeight: 900, color: 'var(--text-h)', marginTop: 11, lineHeight: 1 }}>{value ?? 0}</div>
      <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-muted)', marginTop: 4 }}>{label}</div>
    </div>
  )
}

const IconBtn = ({ children, title, color = 'var(--text-muted)', onClick }) => (
  <button title={title} onClick={onClick} style={{ width: 30, height: 30, borderRadius: 8, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', background: 'var(--bg-card)', border: '1px solid var(--border)', color }}>{children}</button>
)

// ── Create / edit modal ───────────────────────────────────────────────────────
function ItemModal({ item, onClose, onDone }) {
  const isNew = !item.id
  const [vendors, setVendors] = useState([])
  const [f, setF] = useState({
    sku: item.sku || '', name: item.name || '', category: item.category || '', uom: item.uom || '',
    default_rate: item.default_rate ?? '', default_tax: item.default_tax ?? 18, hsn_code: item.hsn_code || '',
    preferred_vendor_id: item.preferred_vendor_id || '', description: item.description || '',
    status: item.status || 'Draft',
  })
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)

  useEffect(() => { purchaseApi.vendors.list().then(r => setVendors(r?.data ?? r ?? [])).catch(() => {}) }, [])
  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))

  const save = async () => {
    if (!f.name.trim()) { setErr('Enter an item name.'); return }
    setSaving(true); setErr(null)
    try {
      const payload = {
        name: f.name, category: f.category || null, uom: f.uom || null,
        default_rate: f.default_rate === '' ? 0 : Number(f.default_rate), default_tax: Number(f.default_tax) || 0,
        hsn_code: f.hsn_code || null, preferred_vendor_id: f.preferred_vendor_id || null, description: f.description || null,
      }
      if (f.sku.trim()) payload.sku = f.sku.trim()
      if (isNew) { payload.status = f.status; await purchaseApi.catalog.create(payload) }
      else await purchaseApi.catalog.update(item.id, payload)
      onDone()
    } catch (e) { setErr(e?.response?.data?.message || 'Could not save the item.'); setSaving(false) }
  }

  return (
    <Overlay onClose={onClose} width={620}>
      <div style={{ padding: '20px 22px 8px' }}>
        <h2 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>{isNew ? 'New Catalog Item' : `Edit · ${item.sku}`}</h2>
        <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>Values here become the defaults snapshotted onto a line when this item is picked.</p>
      </div>
      <div style={{ padding: '10px 22px', maxHeight: '60vh', overflowY: 'auto' }}>
        <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 12 }}>
          <Field label="Item name *"><TextInput value={f.name} onChange={set('name')} placeholder="Cement OPC 50kg" /></Field>
          <Field label="SKU (auto if blank)"><TextInput value={f.sku} onChange={set('sku')} placeholder="SKU-2026-001" disabled={!isNew} /></Field>
          <Field label="Category"><TextInput value={f.category} onChange={set('category')} placeholder="Cement" /></Field>
          <Field label="Default UOM"><TextInput value={f.uom} onChange={set('uom')} placeholder="bag" /></Field>
          <Field label="Default rate (₹)"><TextInput type="number" value={f.default_rate} onChange={set('default_rate')} /></Field>
          <Field label="Default tax %"><TextInput type="number" value={f.default_tax} onChange={set('default_tax')} /></Field>
          <Field label="HSN code"><TextInput value={f.hsn_code} onChange={set('hsn_code')} placeholder="2523" /></Field>
          <Field label="Preferred vendor">
            <SelectInput value={f.preferred_vendor_id} onChange={set('preferred_vendor_id')} pairs
              options={[['', 'None'], ...vendors.map(v => [v.id, v.company_name])]} />
          </Field>
        </div>
        <Field label="Description" full><TextInput value={f.description} onChange={set('description')} placeholder="Optional detail" /></Field>
        {isNew && (
          <Field label="Create as">
            <SelectInput value={f.status} onChange={set('status')} pairs options={[['Draft', 'Draft (not yet selectable)'], ['Active', 'Active (immediately selectable)']]} />
          </Field>
        )}
        {err && <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 12px', borderRadius: 10, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)', marginTop: 8 }}><X size={14} style={{ color: '#ef4444' }} /><span style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{err}</span></div>}
      </div>
      <ModalFooter onClose={onClose} onConfirm={save} loading={saving} confirmLabel={isNew ? 'Create Item' : 'Save Changes'} color="#7C3AED" />
    </Overlay>
  )
}

const solidBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 700, color: '#fff', border: 'none', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)' }
const ghostBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 600, color: 'var(--text-muted)', background: 'var(--bg-card)', border: '1px solid var(--border)' }
