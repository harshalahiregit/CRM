import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Plus, RefreshCw, FileQuestion, Send, CheckCircle, Clock, ArrowRight,
  Trash2, Users, X, Package, Search,
} from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { useAuth } from '@/context/AuthContext'
import { RFQ_STATUS, rfqStatusCfg, RFQ_STAGES, fmtDate, canManagePR } from '../constants'
import {
  KIT3D_STYLE as PURCHASE_STYLE, labelStyle, inputStyle, Overlay, ModalFooter,
  Field, TextInput,
} from '@/components/ui/kit3d'

const EMPTY_ITEM = { description: '', qty: 1, unit: '', tax: 18, catalog_item_id: null, sku: '' }

export default function PurchaseRfqs() {
  const navigate = useNavigate()
  const { user } = useAuth()
  const manage = canManagePR(user)
  const [rows, setRows]   = useState([])
  const [stats, setStats] = useState({})
  const [loading, setLoad] = useState(true)
  const [filter, setFilter] = useState('All')
  const [showNew, setShowNew] = useState(false)

  const load = useCallback(() => {
    setLoad(true)
    Promise.all([
      purchaseApi.rfqs.list(filter === 'All' ? {} : { status: filter }),
      purchaseApi.rfqs.stats(),
    ]).then(([list, s]) => { setRows(list?.data ?? list ?? []); setStats(s?.data ?? s ?? {}); setLoad(false) })
      .catch(() => setLoad(false))
  }, [filter])
  useEffect(() => { load() }, [load])

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{PURCHASE_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>SOURCING</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 24, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>Quotations (RFQ)</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Request quotes, compare vendors, award to a purchase order.</p>
        </div>
        <div style={{ display: 'flex', gap: 9 }}>
          <button onClick={load} style={ghostBtn}><RefreshCw size={14} /> Refresh</button>
          {manage && <button onClick={() => setShowNew(true)} style={solidBtn}><Plus size={15} /> New RFQ</button>}
        </div>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 14, marginBottom: 18 }}>
        <Kpi label="Draft" value={stats.draft} icon={FileQuestion} color="#94a3b8" onClick={() => setFilter(RFQ_STATUS.DRAFT)} />
        <Kpi label="Sent" value={stats.sent} icon={Send} color="#0ea5e9" onClick={() => setFilter(RFQ_STATUS.SENT)} />
        <Kpi label="Under Review" value={stats.under_review} icon={Clock} color="#f59e0b" onClick={() => setFilter(RFQ_STATUS.UNDER_REVIEW)} />
        <Kpi label="Awarded" value={stats.awarded} icon={CheckCircle} color="#10b981" onClick={() => setFilter(RFQ_STATUS.AWARDED)} />
      </div>

      <div style={{ display: 'flex', gap: 8, marginBottom: 16, flexWrap: 'wrap' }}>
        {['All', ...RFQ_STAGES.map(s => s.statuses[0])].map(f => {
          const on = filter === f
          const label = f === 'All' ? 'All' : rfqStatusCfg(f).label
          return (
            <button key={f} onClick={() => setFilter(f)} style={{ padding: '6px 14px', borderRadius: 999, fontSize: 12.5, fontWeight: 700, cursor: 'pointer',
              background: on ? 'linear-gradient(145deg,#a78bfa,#7C3AED)' : 'var(--bg-card)', border: on ? 'none' : '1px solid var(--border)',
              color: on ? '#fff' : 'var(--text-muted)', boxShadow: on ? '0 6px 16px -6px rgba(124,58,237,.6)' : 'none' }}>{label}</button>
          )
        })}
      </div>

      {loading ? (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>{[1, 2, 3].map(i => <div key={i} className="skeleton" style={{ height: 84, borderRadius: 16, background: 'var(--border)' }} />)}</div>
      ) : rows.length === 0 ? (
        <div className="pr-glass" style={{ padding: '48px 24px', textAlign: 'center' }}>
          <div style={{ width: 60, height: 60, borderRadius: '50%', margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.12)' }}><FileQuestion size={28} style={{ color: '#a78bfa' }} /></div>
          <h3 style={{ margin: 0, fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>{filter === 'All' ? 'No RFQs yet' : `No ${rfqStatusCfg(filter).label} RFQs`}</h3>
          <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '6px 0 18px' }}>Raise a request for quotation to source from your vendors.</p>
          {manage && filter === 'All' && <button onClick={() => setShowNew(true)} style={{ ...solidBtn, margin: '0 auto' }}><Plus size={15} /> New RFQ</button>}
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          {rows.map(r => <RfqRow key={r.id} r={r} onClick={() => navigate(`/app/purchase/quotations/${r.id}`)} />)}
        </div>
      )}

      {showNew && <NewRfqModal onClose={() => setShowNew(false)} onDone={(id) => { setShowNew(false); navigate(`/app/purchase/quotations/${id}`) }} />}
    </div>
  )
}

function Kpi({ label, value, icon: Icon, color, onClick }) {
  return (
    <div className="pr-kpi" onClick={onClick} style={{ padding: 16, cursor: 'pointer' }}>
      <div style={{ width: 38, height: 38, borderRadius: 12, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `${color}1f` }}><Icon size={18} style={{ color }} /></div>
      <div style={{ fontSize: 24, fontWeight: 900, color: 'var(--text-h)', marginTop: 11, lineHeight: 1 }}>{value ?? 0}</div>
      <div style={{ fontSize: 12, fontWeight: 600, color: 'var(--text-muted)', marginTop: 4 }}>{label}</div>
    </div>
  )
}

function RfqRow({ r, onClick }) {
  const cfg = rfqStatusCfg(r.status)
  return (
    <div className="pr-glass pr-lift" onClick={onClick} style={{ padding: 16, cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 16 }}>
      <div style={{ width: 46, height: 46, borderRadius: 14, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `${cfg.color}18`, border: `1px solid ${cfg.color}44` }}>
        <FileQuestion size={20} style={{ color: cfg.color }} />
      </div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <span style={{ fontSize: 13, fontWeight: 800, color: '#a78bfa' }}>{r.rfq_number}</span>
          <span style={{ fontSize: 14, fontWeight: 700, color: 'var(--text-h)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{r.title}</span>
        </div>
        <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap', fontSize: 11.5, color: 'var(--text-muted)', marginTop: 3 }}>
          {r.department && <span>{r.department}</span>}
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><Users size={12} /> {r.rfq_vendors_count ?? 0} vendors</span>
          <span>{r.quotations_count ?? 0} quotes</span>
          {r.closes_at && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><Clock size={12} /> closes {fmtDate(r.closes_at)}</span>}
        </div>
      </div>
      <span style={{ padding: '4px 11px', borderRadius: 999, background: cfg.bg, color: cfg.color, fontSize: 11.5, fontWeight: 800, flexShrink: 0 }}>{cfg.label}</span>
      <ArrowRight size={16} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
    </div>
  )
}

// ── New RFQ modal ─────────────────────────────────────────────────────────────
/**
 * Exported so the vendor screens can raise an RFQ without a second copy of this
 * form — the TPV vendor's Quotation tab opens exactly this, with that vendor
 * already on the recipient list.
 *
 * @param presetVendorIds  Recipients to start with. Additive: omitted, the modal
 *                         behaves exactly as it always has (nothing preselected).
 */
export function NewRfqModal({ onClose, onDone, presetVendorIds = [] }) {
  const [vendors, setVendors] = useState([])
  const [f, setF] = useState({ title: '', department: '', required_by: '', closes_at: '', notes: '' })
  const [items, setItems] = useState([{ ...EMPTY_ITEM }])
  const [picked, setPicked] = useState(presetVendorIds)   // vendor ids
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)
  const [catalog, setCatalog] = useState([])       // active catalog items
  const [catQ, setCatQ] = useState('')
  const [catOpen, setCatOpen] = useState(false)

  useEffect(() => { purchaseApi.vendors.list().then(r => setVendors(r?.data ?? r ?? [])).catch(() => {}) }, [])
  useEffect(() => { purchaseApi.catalog.search('').then(r => setCatalog(r?.data ?? r ?? [])).catch(() => {}) }, [])

  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))
  const setItem = (i, k, v) => setItems(prev => prev.map((it, idx) => idx === i ? { ...it, [k]: v } : it))
  const addItem = () => setItems(prev => [...prev, { ...EMPTY_ITEM }])
  const rmItem = (i) => setItems(prev => prev.length > 1 ? prev.filter((_, idx) => idx !== i) : prev)
  const toggleVendor = (id) => setPicked(prev => prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id])

  // Append a line pre-filled from a catalog master (description/unit/tax snapshot;
  // catalog_item_id kept as the soft link the server re-snapshots on save).
  const addFromCatalog = (c) => {
    const line = { ...EMPTY_ITEM, catalog_item_id: c.id, sku: c.sku, description: c.name, unit: c.uom || '', tax: c.default_tax ?? 18 }
    setItems(prev => {
      const onlyBlank = prev.length === 1 && !prev[0].description.trim() && !prev[0].catalog_item_id
      return onlyBlank ? [line] : [...prev, line]
    })
    setCatQ(''); setCatOpen(false)
  }
  const catMatches = catQ.trim()
    ? catalog.filter(c => `${c.name} ${c.sku} ${c.category || ''}`.toLowerCase().includes(catQ.trim().toLowerCase())).slice(0, 8)
    : catalog.slice(0, 8)

  const save = async () => {
    const cleanItems = items.filter(it => it.description.trim() || it.catalog_item_id)
    if (!f.title.trim()) { setErr('Enter a title.'); return }
    if (cleanItems.length === 0) { setErr('Add at least one line item.'); return }
    setSaving(true); setErr(null)
    try {
      const rfq = await purchaseApi.rfqs.create({
        ...f, department: f.department || null, required_by: f.required_by || null, closes_at: f.closes_at || null,
        items: cleanItems.map((it, i) => ({ catalog_item_id: it.catalog_item_id || null, description: it.description, qty: Number(it.qty) || 1, unit: it.unit || null, tax: Number(it.tax) || 0, sort_order: i })),
        vendor_ids: picked,
      })
      onDone(rfq.id)
    } catch (e) { setErr(e?.response?.data?.message || 'Could not create the RFQ.'); setSaving(false) }
  }

  return (
    <Overlay onClose={onClose} width={1120}>
      <div style={{ padding: '20px 22px 8px' }}>
        <h2 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>New Request for Quotation</h2>
        <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>Define what you need and which vendors to ask.</p>
      </div>
      <div style={{ padding: '10px 22px', maxHeight: '60vh', overflowY: 'auto' }}>
        <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 12 }}>
          <Field label="Title *"><TextInput value={f.title} onChange={set('title')} placeholder="Q3 site materials" /></Field>
          <Field label="Department"><TextInput value={f.department} onChange={set('department')} placeholder="Projects" /></Field>
          <Field label="Required by"><TextInput type="date" value={f.required_by} onChange={set('required_by')} /></Field>
          <Field label="Quote deadline"><TextInput type="date" value={f.closes_at} onChange={set('closes_at')} /></Field>
        </div>

        <label style={{ ...labelStyle, marginTop: 12 }}>Line items *</label>

        {/* Catalog picker — pull standardized SKUs instead of typing free text */}
        <div style={{ position: 'relative', marginBottom: 8 }}>
          <div style={{ position: 'relative' }}>
            <Search size={14} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: '#a78bfa' }} />
            <input
              value={catQ}
              onChange={e => { setCatQ(e.target.value); setCatOpen(true) }}
              onFocus={() => setCatOpen(true)}
              onBlur={() => setTimeout(() => setCatOpen(false), 150)}
              placeholder={catalog.length ? 'Pick from catalog — search name, SKU or category…' : 'No active catalog items yet'}
              disabled={!catalog.length}
              style={{ ...inputStyle, paddingLeft: 32, borderColor: '#7C3AED55' }}
            />
          </div>
          {catOpen && catMatches.length > 0 && (
            <div className="pr-glass" style={{ position: 'absolute', zIndex: 30, left: 0, right: 0, marginTop: 4, borderRadius: 12, padding: 6, maxHeight: 240, overflowY: 'auto', boxShadow: '0 20px 40px -12px rgba(0,0,0,.5)' }}>
              {catMatches.map(c => (
                <button key={c.id} onMouseDown={() => addFromCatalog(c)} style={{ display: 'flex', width: '100%', alignItems: 'center', gap: 10, padding: '8px 10px', borderRadius: 9, cursor: 'pointer', background: 'transparent', border: 'none', textAlign: 'left' }}
                  onMouseEnter={e => e.currentTarget.style.background = 'rgba(124,58,237,0.12)'} onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                  <Package size={15} style={{ color: '#a78bfa', flexShrink: 0 }} />
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{c.name}</div>
                    <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{c.sku}{c.category ? ` · ${c.category}` : ''}{c.uom ? ` · ${c.uom}` : ''}</div>
                  </div>
                  <Plus size={14} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
                </button>
              ))}
            </div>
          )}
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {items.map((it, i) => (
            <div key={i} style={{ display: 'grid', gridTemplateColumns: '3fr 1fr 1fr 1fr auto', gap: 8, alignItems: 'center' }}>
              <div>
                <TextInput value={it.description} onChange={e => setItem(i, 'description', e.target.value)} placeholder="Description" />
                {it.catalog_item_id && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, marginTop: 4, fontSize: 10.5, fontWeight: 800, color: '#a78bfa' }}><Package size={11} /> {it.sku}</span>}
              </div>
              <TextInput type="number" value={it.qty} onChange={e => setItem(i, 'qty', e.target.value)} placeholder="Qty" />
              <TextInput value={it.unit} onChange={e => setItem(i, 'unit', e.target.value)} placeholder="Unit" />
              <TextInput type="number" value={it.tax} onChange={e => setItem(i, 'tax', e.target.value)} placeholder="Tax %" />
              <button onClick={() => rmItem(i)} style={{ width: 34, height: 34, borderRadius: 8, cursor: 'pointer', background: 'rgba(239,68,68,0.12)', border: '1px solid rgba(239,68,68,0.3)', color: '#f87171', display: 'flex', alignItems: 'center', justifyContent: 'center' }}><Trash2 size={14} /></button>
            </div>
          ))}
        </div>
        <button onClick={addItem} style={{ ...ghostBtn, marginTop: 8, fontSize: 12 }}><Plus size={13} /> Add blank line</button>

        <label style={{ ...labelStyle, marginTop: 16 }}>Send to vendors ({picked.length} selected)</label>
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
          {vendors.length === 0 && <span style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>No active vendors found.</span>}
          {vendors.map(v => {
            const on = picked.includes(v.id)
            return (
              <button key={v.id} onClick={() => toggleVendor(v.id)} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 12px', borderRadius: 999, fontSize: 12.5, fontWeight: 700, cursor: 'pointer',
                background: on ? 'rgba(124,58,237,0.16)' : 'var(--bg-input)', border: `1px solid ${on ? '#7C3AED88' : 'var(--border)'}`, color: on ? '#a78bfa' : 'var(--text-muted)' }}>
                {on ? <CheckCircle size={13} /> : <Plus size={13} />} {v.company_name}
              </button>
            )
          })}
        </div>
        <p style={{ fontSize: 11, color: 'var(--text-muted)', margin: '8px 0 0' }}>Vendors can be added now or later — but the RFQ can only be sent once at least one is chosen.</p>

        {err && <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 12px', borderRadius: 10, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)', marginTop: 12 }}><X size={14} style={{ color: '#ef4444' }} /><span style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{err}</span></div>}
      </div>
      <ModalFooter onClose={onClose} onConfirm={save} loading={saving} confirmLabel="Create RFQ" color="#7C3AED" />
    </Overlay>
  )
}

const solidBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 700, color: '#fff', border: 'none', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)' }
const ghostBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 600, color: 'var(--text-muted)', background: 'var(--bg-card)', border: '1px solid var(--border)' }
