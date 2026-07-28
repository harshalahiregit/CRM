import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { RefreshCw, Package, CheckCircle, AlertTriangle, Boxes, Search, ExternalLink } from 'lucide-react'
import { inventoryApi } from '@/services/inventoryApi'
import { fmtMoney } from '../constants'
import { KIT3D_STYLE as PURCHASE_STYLE, inputStyle } from '@/components/ui/kit3d'

/**
 * Purchase → Items. These are the SAME items as the Inventory module — Purchase
 * reads the shared inventory product catalog rather than keeping a second copy,
 * so a buyer picks from exactly what's in stock. Read-only here: items are
 * created and maintained in Inventory (the owning module); this is a lens on it.
 */
export default function PurchaseCatalog() {
  const navigate = useNavigate()
  const [rows, setRows] = useState([])
  const [loading, setLoad] = useState(true)
  const [filter, setFilter] = useState('All')   // All | Active | Low
  const [search, setSearch] = useState('')

  const load = useCallback(() => {
    setLoad(true)
    const params = { per_page: 500 }
    if (search.trim()) params.search = search.trim()
    inventoryApi.products.list(params)
      .then(res => { setRows(Array.isArray(res) ? res : (res?.data ?? [])); setLoad(false) })
      .catch(() => { setRows([]); setLoad(false) })
  }, [search])
  useEffect(() => { const t = setTimeout(load, search ? 300 : 0); return () => clearTimeout(t) }, [load, search])

  const isActive = (r) => String(r.status || '').toLowerCase() === 'active'
  const shown = rows.filter(r => filter === 'All' ? true : filter === 'Active' ? isActive(r) : !!r.low_stock)
  const stats = {
    total: rows.length,
    active: rows.filter(isActive).length,
    low: rows.filter(r => r.low_stock).length,
    categories: new Set(rows.map(r => (r.category?.name || r.category)).filter(Boolean)).size,
  }
  const catName = (r) => r.category?.name || (typeof r.category === 'string' ? r.category : '') || '—'

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{PURCHASE_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>ITEM MASTER · FROM INVENTORY</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 24, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>Items</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>The same catalog as the Inventory module — buyers pick from real stock items.</p>
        </div>
        <div style={{ display: 'flex', gap: 9 }}>
          <button onClick={load} style={ghostBtn}><RefreshCw size={14} /> Refresh</button>
          <button onClick={() => navigate('/app/inventory/products')} style={solidBtn}><ExternalLink size={15} /> Manage in Inventory</button>
        </div>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 14, marginBottom: 18 }}>
        <Kpi label="Total items" value={stats.total} icon={Package} color="#a78bfa" onClick={() => setFilter('All')} />
        <Kpi label="Active" value={stats.active} icon={CheckCircle} color="#10b981" onClick={() => setFilter('Active')} />
        <Kpi label="Low stock" value={stats.low} icon={AlertTriangle} color="#f59e0b" onClick={() => setFilter('Low')} />
        <Kpi label="Categories" value={stats.categories} icon={Boxes} color="#0ea5e9" />
      </div>

      <div style={{ display: 'flex', gap: 10, marginBottom: 16, flexWrap: 'wrap', alignItems: 'center' }}>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {['All', 'Active', 'Low'].map(fv => {
            const on = filter === fv
            return <button key={fv} onClick={() => setFilter(fv)} style={{ padding: '6px 14px', borderRadius: 999, fontSize: 12.5, fontWeight: 700, cursor: 'pointer', background: on ? 'linear-gradient(145deg,#a78bfa,#7C3AED)' : 'var(--bg-card)', border: on ? 'none' : '1px solid var(--border)', color: on ? '#fff' : 'var(--text-muted)', boxShadow: on ? '0 6px 16px -6px rgba(124,58,237,.6)' : 'none' }}>{fv === 'Low' ? 'Low stock' : fv}</button>
          })}
        </div>
        <div style={{ position: 'relative', marginLeft: 'auto', minWidth: 240 }}>
          <Search size={14} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search name, SKU…" style={{ ...inputStyle, paddingLeft: 32 }} />
        </div>
      </div>

      {loading ? (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>{[1, 2, 3, 4].map(i => <div key={i} className="skeleton" style={{ height: 64, borderRadius: 14, background: 'var(--border)' }} />)}</div>
      ) : shown.length === 0 ? (
        <div className="pr-glass" style={{ padding: '48px 24px', textAlign: 'center' }}>
          <div style={{ width: 60, height: 60, borderRadius: '50%', margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.12)' }}><Package size={28} style={{ color: '#a78bfa' }} /></div>
          <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>No items</h3>
          <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '6px 0 18px' }}>Add items in the Inventory module — they show up here automatically.</p>
          <button onClick={() => navigate('/app/inventory/products')} style={{ ...solidBtn, margin: '0 auto' }}><ExternalLink size={15} /> Open Inventory Items</button>
        </div>
      ) : (
        <div className="pr-glass" style={{ padding: 0, borderRadius: 16, overflow: 'hidden' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 760 }}>
              <thead><tr>{['SKU', 'Item', 'Category', 'UOM', 'Cost Rate', 'In Stock', 'Status'].map((h, i) => (
                <th key={h} style={{ textAlign: i === 4 || i === 5 ? 'right' : 'left', padding: '11px 14px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', borderBottom: '1px solid var(--border)' }}>{h}</th>
              ))}</tr></thead>
              <tbody>
                {shown.map(r => {
                  const active = isActive(r)
                  return (
                    <tr key={r.id} className="pr-li-row" style={{ cursor: 'pointer' }} onClick={() => navigate('/app/inventory/products')}>
                      <td style={{ padding: '11px 14px', fontSize: 12.5, fontWeight: 700, color: '#a78bfa', whiteSpace: 'nowrap' }}>{r.sku}</td>
                      <td style={{ padding: '11px 14px', fontSize: 12.5, color: 'var(--text-h)' }}>{r.name}{r.hsn && <span style={{ color: 'var(--text-muted)', fontSize: 11 }}> · HSN {r.hsn}</span>}</td>
                      <td style={{ padding: '11px 14px', fontSize: 12.5, color: 'var(--text-muted)' }}>{catName(r)}</td>
                      <td style={{ padding: '11px 14px', fontSize: 12.5, color: 'var(--text-muted)' }}>{r.base_unit || '—'}</td>
                      <td style={{ padding: '11px 14px', fontSize: 12.5, textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: 'var(--text-h)' }}>{fmtMoney(r.cost_price)}</td>
                      <td style={{ padding: '11px 14px', fontSize: 12.5, textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: r.low_stock ? '#f59e0b' : 'var(--text-body)', fontWeight: r.low_stock ? 700 : 400 }}>{Number(r.on_hand ?? 0)}</td>
                      <td style={{ padding: '11px 14px' }}><span style={{ padding: '3px 9px', borderRadius: 999, background: active ? 'rgba(16,185,129,0.14)' : 'rgba(148,163,184,0.16)', color: active ? '#10b981' : '#94a3b8', fontSize: 10.5, fontWeight: 800, textTransform: 'capitalize' }}>{r.status || '—'}</span></td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}
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

const solidBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 700, color: '#fff', border: 'none', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)' }
const ghostBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 600, color: 'var(--text-muted)', background: 'var(--bg-card)', border: '1px solid var(--border)' }
