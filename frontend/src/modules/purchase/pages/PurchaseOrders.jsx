import { useState, useEffect, useCallback, useMemo } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import {
  Plus, RefreshCw, Search, Send, Eye, Pencil, Trash2, PackageCheck, Ban, Lock,
  FileText, ShoppingBag, Truck, CheckCircle, XCircle, LayoutGrid, List, Trash,
  ChevronLeft, ChevronRight, PackagePlus, Package, ShieldCheck,
} from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { useAuth } from '@/context/AuthContext'
import AuditTimeline from '@/components/ui/AuditTimeline'
import {
  PO_STATUS, PO_STATUS_CONFIG, poStatusCfg, grnStatusCfg, PO_STAGES,
  canApprovePR as isAdmin, canManagePR as isStaffOrAdmin,
  fmtMoney, fmtMoneyShort, fmtDate, lineAmount, totalsOf,
} from '../constants'
import {
  KIT3D_STYLE as PURCHASE_STYLE, labelStyle, inputStyle, Overlay, ModalFooter, InfoBox,
  Field, TextInput, SelectInput, TotalRow, ActBtn, StatusBadge as StatusPill,
} from '@/components/ui/kit3d'

const EMPTY_ITEM = { description: '', qty: 1, unit: '', rate: 0, tax: 0, catalog_item_id: null, sku: '', contract_rate_applied: false, contract_number: null }
const EMPTY_FORM = {
  title: '', department: '', vendor_id: '', currency: 'INR',
  order_date: new Date().toISOString().slice(0, 10), expected_delivery_date: '', terms: '', notes: '',
  items: [{ ...EMPTY_ITEM }],
}

/**
 * Payload mapping, shared by this page's save and by NewOrderModal below, so a
 * PO raised from a vendor screen is built exactly like one raised here.
 */
const orderLines = (f) => (f.items || []).filter(it => it.description?.trim() || it.catalog_item_id)

const orderPayload = (f) => ({
  title: f.title, department: f.department || null, vendor_id: f.vendor_id || null, currency: f.currency,
  order_date: f.order_date || null, expected_delivery_date: f.expected_delivery_date || null,
  terms: f.terms || null, notes: f.notes || null,
  items: orderLines(f).map((it, i) => ({
    catalog_item_id: it.catalog_item_id || null, description: it.description, qty: Number(it.qty) || 1,
    unit: it.unit || null, rate: Number(it.rate) || 0, tax: Number(it.tax) || 0, sort_order: i,
  })),
})

/**
 * Standalone "new purchase order" modal for callers outside this page.
 *
 * The TPV vendor's Purchase Order tab opens this with the vendor preselected,
 * so there is one PO form and one save path rather than a reduced copy that
 * drifts. Mirrors NewRfqModal's shape.
 */
export function NewOrderModal({ onClose, onDone, presetVendorId = '' }) {
  const { user } = useAuth()
  const [editing, setEditing] = useState({ ...EMPTY_FORM, vendor_id: presetVendorId ? String(presetVendorId) : '' })
  const [saving, setSaving] = useState(false)

  const save = async () => {
    if (!editing.title?.trim()) { alert('Title is required.'); return }
    if (orderLines(editing).length === 0) { alert('Add at least one line item.'); return }
    setSaving(true)
    try {
      const saved = await purchaseApi.orders.create(orderPayload(editing))
      onDone(saved?.id ?? saved?.data?.id)
    } catch (e) { alert(e?.response?.data?.message || 'Failed to save order') }
    finally { setSaving(false) }
  }

  return (
    <OrderFormModal editing={editing} setEditing={setEditing} saving={saving}
      admin={isAdmin(user)} onClose={onClose} onSave={save} />
  )
}

const StatusBadge = ({ status }) => <StatusPill cfg={poStatusCfg(status)} />

// ── PO pipeline — 3D extruded stage knobs with live counts ───────────────────
function Pipeline({ stats = {}, active, onStage }) {
  const count = { draft: stats.draft || 0, issued: stats.issued || 0, receiving: stats.partial || 0, received: (stats.received || 0) + (stats.closed || 0) }
  const COLORS = { draft: '#94a3b8', issued: '#0ea5e9', receiving: '#f59e0b', received: '#10b981' }
  const ICONS = { draft: FileText, issued: ShoppingBag, receiving: Truck, received: PackageCheck }
  return (
    <div style={{ display: 'flex', alignItems: 'center', width: 'max-content', minWidth: '100%', gap: 0 }}>
      {PO_STAGES.map((s, i) => {
        const Icon = ICONS[s.key]; const color = COLORS[s.key]; const n = count[s.key] || 0
        const selected = active === s.key; const lit = n > 0 || selected
        return (
          <div key={s.key} style={{ display: 'flex', alignItems: 'center', flex: 1, minWidth: 150 }}>
            <button type="button" onClick={() => onStage(selected ? 'All' : s.key)} title={`${n} in ${s.label} — click to filter`}
              className="pr-node" style={{
                flex: 1, display: 'flex', alignItems: 'center', gap: 10, padding: '11px 14px', borderRadius: 16, cursor: 'pointer',
                background: lit ? `linear-gradient(135deg, ${color}26, ${color}0f)` : 'var(--bg-input)',
                border: `1.5px solid ${selected ? color : lit ? color + '55' : 'var(--border)'}`,
                opacity: lit ? 1 : 0.55, boxShadow: selected ? `0 10px 26px -8px ${color}88, inset 0 1px 0 rgba(255,255,255,.14)` : 'inset 0 1px 0 var(--card-shine)',
              }}>
              <span style={{ width: 36, height: 36, borderRadius: 12, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `linear-gradient(145deg, ${color}, ${color}aa)`, color: '#fff', boxShadow: lit ? `0 6px 16px -3px ${color}99, inset 0 1px 0 rgba(255,255,255,.4)` : 'none', flexShrink: 0 }}>
                <Icon size={16} />
              </span>
              <span style={{ display: 'flex', flexDirection: 'column', lineHeight: 1.2, textAlign: 'left', minWidth: 0 }}>
                <span style={{ fontSize: 9, fontWeight: 800, letterSpacing: '0.06em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>Stage {i + 1}</span>
                <span style={{ fontSize: 13.5, fontWeight: 800, color: 'var(--text-h)' }}>{s.label}</span>
                <span style={{ fontSize: 9.5, fontWeight: 700, letterSpacing: '0.04em', textTransform: 'uppercase', color }}>{s.sub}</span>
              </span>
              <span style={{ marginLeft: 'auto', minWidth: 24, height: 24, padding: '0 7px', borderRadius: 999, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 12, fontWeight: 800, color: n > 0 ? '#fff' : 'var(--text-muted)', background: n > 0 ? color : 'var(--bg-card)', border: n > 0 ? 'none' : '1px solid var(--border)', flexShrink: 0 }}>{n}</span>
            </button>
            {i < PO_STAGES.length - 1 && <div className={`pr-flow${lit ? '' : ' pr-flow-dim'}`} style={{ width: 26, height: 3, borderRadius: 4, margin: '0 4px', flexShrink: 0, background: `linear-gradient(90deg, ${color}, ${COLORS[PO_STAGES[i + 1].key]})` }} />}
          </div>
        )
      })}
    </div>
  )
}

// ── Main page ────────────────────────────────────────────────────────────────
export default function PurchaseOrders() {
  const { user } = useAuth()
  const admin  = isAdmin(user)
  const manage = isStaffOrAdmin(user)
  const location = useLocation()
  const navigate = useNavigate()
  const highlightId = location.state?.highlight

  // Raise an invoice from an issued/received PO, then jump to the Invoices screen.
  const [invoicing, setInvoicing] = useState(null)
  const handleInvoice = async (r) => {
    if (invoicing) return
    setInvoicing(r.id)
    try {
      const inv = await purchaseApi.invoices.fromOrder(r.id)
      navigate('/app/purchase/invoices', { state: { highlight: inv?.id ?? inv?.data?.id } })
    } catch (e) { alert(e?.response?.data?.message || 'Failed to create invoice') }
    finally { setInvoicing(null) }
  }

  const [rows, setRows]       = useState([])
  const [stats, setStats]     = useState({})
  const [loading, setLoading] = useState(true)
  const [search, setSearch]   = useState('')
  const [filterStatus, setFilterStatus] = useState('All')
  const [viewMode, setViewMode] = useState(() => localStorage.getItem('pur_po_view') || 'card')
  const changeViewMode = (v) => { setViewMode(v); localStorage.setItem('pur_po_view', v) }

  const [showForm, setShowForm]   = useState(false)
  const [editing, setEditing]     = useState(null)
  const [saving, setSaving]       = useState(false)

  const [actionModal, setActionModal] = useState(null)   // { order, action }
  const [remarks, setRemarks]         = useState('')
  const [actionLoading, setActionLoading] = useState(false)

  const [detail, setDetail]     = useState(null)
  const [receiving, setReceiving] = useState(null)       // the order being received against

  const fetchAll = useCallback(async () => {
    setLoading(true)
    try {
      const [listRes, statRes] = await Promise.all([purchaseApi.orders.list(), purchaseApi.orders.stats()])
      setRows(Array.isArray(listRes?.data ?? listRes) ? (listRes.data ?? listRes) : [])
      setStats(statRes?.data ?? statRes ?? {})
    } catch (e) { console.error('Failed to load purchase orders', e) }
    finally { setLoading(false) }
  }, [])
  useEffect(() => { fetchAll() }, [fetchAll])

  const filtered = rows.filter(r => {
    const q = search.toLowerCase()
    const matchSearch = !q || r.title?.toLowerCase().includes(q) || r.po_number?.toLowerCase().includes(q) || r.department?.toLowerCase().includes(q) || r.vendor?.company_name?.toLowerCase().includes(q)
    const matchStatus = filterStatus === 'All'
      || r.status === filterStatus
      || !!PO_STAGES.find(s => s.key === filterStatus)?.statuses.includes(r.status)
    return matchSearch && matchStatus
  })

  // ── Create / edit ──────────────────────────────────────────────────────────
  const openCreate = () => { setEditing({ ...EMPTY_FORM }); setShowForm(true) }
  const openEdit = (r) => {
    setEditing({
      id: r.id, title: r.title || '', department: r.department || '', vendor_id: r.vendor_id ?? '',
      currency: r.currency || 'INR', order_date: r.order_date?.slice(0, 10) || '',
      expected_delivery_date: r.expected_delivery_date?.slice(0, 10) || '', terms: r.terms || '', notes: r.notes || '',
      items: (r.items?.length ? r.items : [{ ...EMPTY_ITEM }]).map(it => ({
        description: it.description || '', qty: it.qty ?? 1, unit: it.unit || '', rate: it.rate ?? 0, tax: it.tax ?? 0,
        catalog_item_id: it.catalog_item_id ?? null, sku: it.catalog_item?.sku || '',
        contract_rate_applied: !!it.contract_rate_applied, contract_number: r.contract?.contract_number || null,
      })),
    })
    setShowForm(true)
  }

  const handleSave = async (mode = 'draft') => {
    const f = editing
    if (!f.title?.trim()) { alert('Title is required.'); return }
    const items = orderLines(f)
    if (items.length === 0) { alert('Add at least one line item.'); return }
    setSaving(true)
    try {
      const payload = orderPayload(f)
      let saved
      if (f.id) saved = await purchaseApi.orders.update(f.id, payload)
      else saved = await purchaseApi.orders.create(payload)
      const id = f.id || saved?.id || saved?.data?.id
      if (mode === 'issue' && id) await purchaseApi.orders.issue(id)   // admin-only; surfaces 403 if not
      setShowForm(false); setEditing(null); fetchAll()
    } catch (e) { alert(e?.response?.data?.message || 'Failed to save order') }
    finally { setSaving(false) }
  }

  // ── Lifecycle actions ───────────────────────────────────────────────────────
  const openAction = (order, action) => { setActionModal({ order, action }); setRemarks('') }
  const runAction = async () => {
    if (!actionModal) return
    const { order, action } = actionModal
    setActionLoading(true)
    try {
      const id = order.id
      if (action === 'issue')       await purchaseApi.orders.issue(id)
      else if (action === 'close')  await purchaseApi.orders.close(id, remarks)
      else if (action === 'cancel') await purchaseApi.orders.cancel(id, remarks)
      else if (action === 'delete') await purchaseApi.orders.delete(id)
      setActionModal(null); setRemarks(''); fetchAll()
    } catch (e) { alert(e?.response?.data?.message || 'Action failed') }
    finally { setActionLoading(false) }
  }

  const openDetail = async (r) => {
    setDetail(r)
    try { const full = await purchaseApi.orders.get(r.id); if ((full?.data ?? full)?.id) setDetail(full.data ?? full) }
    catch { /* keep list-row data */ }
  }
  const refreshDetail = async (id) => {
    try { const full = await purchaseApi.orders.get(id); setDetail(full?.data ?? full) } catch { /* noop */ }
    fetchAll()
  }

  const statCards = [
    { label: 'Total',      value: stats.total,    color: '#7C3AED', filter: 'All' },
    { label: 'Draft',      value: stats.draft,    color: '#94a3b8', filter: PO_STATUS.DRAFT },
    { label: 'Issued',     value: stats.issued,   color: '#0ea5e9', filter: PO_STATUS.ISSUED },
    { label: 'Partial',    value: stats.partial,  color: '#f59e0b', filter: PO_STATUS.PARTIALLY_RECEIVED },
    { label: 'Received',   value: stats.received, color: '#10b981', filter: PO_STATUS.RECEIVED },
    { label: 'Open Value', value: fmtMoneyShort(stats.open_value), color: '#6366f1', filter: 'All', wide: true },
  ]

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{PURCHASE_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 800, margin: 0 }}>Purchase Orders</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Issue orders to vendors and record goods received against them.</p>
        </div>
        <div style={{ display: 'flex', gap: 10 }}>
          <div style={{ display: 'flex', borderRadius: 10, overflow: 'hidden', border: '1px solid var(--border)' }}>
            {[['card', LayoutGrid, 'Card'], ['list', List, 'List']].map(([v, Icon, label]) => (
              <button key={v} onClick={() => changeViewMode(v)} title={`${label} view`}
                style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 12px', border: 'none', cursor: 'pointer', fontSize: 12.5, fontWeight: 700, background: viewMode === v ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)', color: viewMode === v ? '#fff' : 'var(--text-muted)' }}>
                <Icon size={13} /> {label}
              </button>
            ))}
          </div>
          <button onClick={fetchAll} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>
            <RefreshCw size={14} /> Refresh
          </button>
          {manage && (
            <button onClick={openCreate} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 18px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13, boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)' }}>
              <Plus size={15} /> New Order
            </button>
          )}
        </div>
      </div>

      <div style={{ marginBottom: 20, overflowX: 'auto', paddingBottom: 4 }}>
        <Pipeline stats={stats} active={filterStatus} onStage={setFilterStatus} />
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(6,1fr)', gap: 12, marginBottom: 22 }}>
        {statCards.map(s => (
          <div key={s.label} className="pr-kpi" onClick={() => setFilterStatus(s.filter)}
            style={{ textAlign: 'center', outline: filterStatus === s.filter && s.filter !== 'All' ? `1.5px solid ${s.color}` : 'none' }}>
            <div style={{ fontSize: s.wide ? 19 : 24, fontWeight: 900, color: s.color }}>{s.wide ? s.value : (s.value || 0)}</div>
            <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 600, marginTop: 3 }}>{s.label}</div>
          </div>
        ))}
      </div>

      <div className="pr-glass" style={{ padding: '14px 18px', marginBottom: 18, display: 'flex', gap: 12, alignItems: 'center', borderRadius: 14 }}>
        <div style={{ position: 'relative', flex: 1 }}>
          <Search size={14} style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search PO number, title, department or vendor…" style={{ ...inputStyle, paddingLeft: 32 }} />
        </div>
        <select value={filterStatus} onChange={e => setFilterStatus(e.target.value)} style={{ ...inputStyle, width: 'auto', cursor: 'pointer' }}>
          <option value="All">All Status</option>
          {Object.values(PO_STATUS).map(s => <option key={s} value={s}>{PO_STATUS_CONFIG[s]?.label || s}</option>)}
        </select>
      </div>

      {loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}>Loading orders…</div>
      ) : filtered.length === 0 ? (
        <EmptyState onCreate={manage ? openCreate : null} />
      ) : viewMode === 'list' ? (
        <POListView rows={filtered} onView={openDetail} onEdit={openEdit} admin={admin} manage={manage} openAction={openAction} onReceive={setReceiving} highlightId={highlightId} />
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          {filtered.map(r => (
            <POCard key={r.id} r={r} admin={admin} manage={manage} highlight={r.id === highlightId}
              onView={openDetail} onEdit={openEdit} openAction={openAction} onReceive={setReceiving} onInvoice={handleInvoice} />
          ))}
        </div>
      )}

      {showForm && <OrderFormModal editing={editing} setEditing={setEditing} saving={saving} admin={admin} onClose={() => setShowForm(false)} onSave={handleSave} />}
      {actionModal && <ActionModal actionModal={actionModal} remarks={remarks} setRemarks={setRemarks} loading={actionLoading} onClose={() => setActionModal(null)} onConfirm={runAction} />}
      {detail && <DetailModal order={detail} manage={manage} admin={admin} onClose={() => setDetail(null)} onReceive={setReceiving} onAction={openAction} />}
      {receiving && <ReceiveModal order={receiving} onClose={() => setReceiving(null)} onDone={(id) => { setReceiving(null); if (detail?.id === id) refreshDetail(id); else fetchAll() }} />}
    </div>
  )
}

// ── Progress bar ─────────────────────────────────────────────────────────────
const ReceiptBar = ({ percent }) => (
  <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
    <div className="pr-bar" style={{ flex: 1 }}><span style={{ width: `${percent || 0}%` }} /></div>
    <span style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', minWidth: 34, textAlign: 'right' }}>{percent || 0}%</span>
  </div>
)

// ── Card ─────────────────────────────────────────────────────────────────────
function POCard({ r, admin, manage, highlight, onView, onEdit, openAction, onReceive, onInvoice }) {
  const canReceive = manage && [PO_STATUS.ISSUED, PO_STATUS.PARTIALLY_RECEIVED].includes(r.status)
  const canInvoice = manage && [PO_STATUS.ISSUED, PO_STATUS.PARTIALLY_RECEIVED, PO_STATUS.RECEIVED].includes(r.status)
  return (
    <div className="pr-glass pr-lift pr-pop" style={{ padding: 20, outline: highlight ? '2px solid #7C3AED' : 'none' }}>
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16 }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8, flexWrap: 'wrap' }}>
            <span style={{ color: '#a78bfa', fontWeight: 800, fontSize: 12 }}>{r.po_number || `PO-${r.id}`}</span>
            <span style={{ color: 'var(--text-h)', fontWeight: 700, fontSize: 15 }}>{r.title}</span>
            <StatusBadge status={r.status} />
            {r.purchase_request_id && <span style={{ padding: '2px 8px', borderRadius: 10, fontSize: 10.5, fontWeight: 700, background: 'rgba(99,102,241,0.12)', color: '#818cf8', border: '1px solid rgba(99,102,241,0.25)' }}>from PR</span>}
          </div>
          <div style={{ display: 'flex', gap: 16, color: 'var(--text-muted)', fontSize: 12, marginBottom: 10, flexWrap: 'wrap' }}>
            {r.department && <span>🏢 {r.department}</span>}
            {r.vendor?.company_name && <span>🏷️ {r.vendor.company_name}</span>}
            <span>📦 {(r.items?.length) || 0} line{(r.items?.length) === 1 ? '' : 's'}</span>
            {r.expected_delivery_date && <span>🚚 {fmtDate(r.expected_delivery_date)}</span>}
          </div>
          {[PO_STATUS.ISSUED, PO_STATUS.PARTIALLY_RECEIVED, PO_STATUS.RECEIVED].includes(r.status) && (
            <div style={{ maxWidth: 320, marginBottom: 8 }}><ReceiptBar percent={r.received_percent} /></div>
          )}
          <div style={{ fontSize: 18, fontWeight: 900, color: 'var(--text-h)' }}>{fmtMoney(r.total, r.currency)}<span style={{ fontSize: 11, fontWeight: 600, color: 'var(--text-muted)', marginLeft: 8 }}>incl. tax</span></div>
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 6, flexShrink: 0, alignItems: 'stretch' }}>
          {r.status === PO_STATUS.DRAFT && admin && <ActBtn onClick={() => openAction(r, 'issue')} icon={Send} color="#0ea5e9" bg="rgba(14,165,233,0.15)">Issue</ActBtn>}
          {canReceive && <ActBtn onClick={() => onReceive(r)} icon={PackagePlus} color="#f59e0b" bg="rgba(245,158,11,0.15)">Receive</ActBtn>}
          {canInvoice && <ActBtn onClick={() => onInvoice(r)} icon={FileText} color="#10b981" bg="rgba(16,185,129,0.15)">Create Invoice</ActBtn>}
          {r.status === PO_STATUS.DRAFT && manage && <ActBtn onClick={() => onEdit(r)} icon={Pencil} color="var(--text-muted)" bg="var(--bg-card)" border>Edit</ActBtn>}
          <ActBtn onClick={() => onView(r)} icon={Eye} color="var(--text-muted)" bg="var(--bg-card)" border>View</ActBtn>
          {[PO_STATUS.PARTIALLY_RECEIVED, PO_STATUS.RECEIVED].includes(r.status) && admin && <ActBtn onClick={() => openAction(r, 'close')} icon={Lock} color="#94a3b8" bg="var(--bg-card)" border>Close</ActBtn>}
          {[PO_STATUS.DRAFT, PO_STATUS.ISSUED].includes(r.status) && admin && <ActBtn onClick={() => openAction(r, 'cancel')} icon={Ban} color="#f87171" bg="var(--bg-card)" border>Cancel</ActBtn>}
          {r.status === PO_STATUS.DRAFT && manage && <ActBtn onClick={() => openAction(r, 'delete')} icon={Trash2} color="#f87171" bg="var(--bg-card)" border>Delete</ActBtn>}
        </div>
      </div>
    </div>
  )
}

function EmptyState({ onCreate }) {
  return (
    <div className="pr-glass" style={{ padding: 60, textAlign: 'center' }}>
      <div style={{ width: 60, height: 60, borderRadius: 18, margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'linear-gradient(145deg,#7C3AED,#5b21b6)', boxShadow: '0 10px 24px -6px rgba(124,58,237,.6)' }}>
        <ShoppingBag size={26} color="#fff" />
      </div>
      <h3 style={{ color: 'var(--text-h)', fontSize: 16, fontWeight: 800, margin: '0 0 6px' }}>No purchase orders yet</h3>
      <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '0 0 18px' }}>Raise an order directly, or convert an approved purchase request.</p>
      {onCreate && <button onClick={onCreate} style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '9px 20px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }}><Plus size={15} /> New Order</button>}
    </div>
  )
}

// ── Create / edit modal ──────────────────────────────────────────────────────
function OrderFormModal({ editing, setEditing, saving, admin, onClose, onSave }) {
  const f = editing
  const set = (k) => (e) => setEditing(p => ({ ...p, [k]: e.target.value }))
  const [vendors, setVendors] = useState([])
  const [catalog, setCatalog] = useState([])
  const [contracts, setContracts] = useState([])   // Active, in-window contracts for the vendor
  const [catQ, setCatQ] = useState('')
  const [catOpen, setCatOpen] = useState(false)

  useEffect(() => { purchaseApi.vendors.list().then(res => setVendors(Array.isArray(res?.data ?? res) ? (res.data ?? res) : [])).catch(() => {}) }, [])
  useEffect(() => { purchaseApi.catalog.search('').then(r => setCatalog(r?.data ?? r ?? [])).catch(() => {}) }, [])
  useEffect(() => {
    if (!f.vendor_id) { setContracts([]); return }
    purchaseApi.contracts.referenceable(f.vendor_id).then(r => setContracts(r?.data ?? r ?? [])).catch(() => setContracts([]))
  }, [f.vendor_id])

  // catalog_item_id → locked rate lines from the vendor's referenceable contracts.
  const rateMap = useMemo(() => {
    const m = {}
    for (const c of contracts) for (const ci of (c.items || [])) {
      if (!ci.catalog_item_id) continue
      ;(m[ci.catalog_item_id] = m[ci.catalog_item_id] || []).push({
        contract_number: c.contract_number, rate: Number(ci.rate), tax: Number(ci.tax),
        min: ci.min_qty != null ? Number(ci.min_qty) : null, max: ci.max_qty != null ? Number(ci.max_qty) : null,
      })
    }
    return m
  }, [contracts])

  const contractFor = (catId, qty) => (rateMap[catId] || []).find(e => (e.min == null || qty >= e.min) && (e.max == null || qty <= e.max)) || null

  // Mirror the server: contract rate is authoritative & locked; else catalog default.
  const recompute = (line) => {
    if (!line.catalog_item_id) return { ...line, contract_rate_applied: false, contract_number: null }
    const cat = catalog.find(c => c.id === line.catalog_item_id)
    const con = contractFor(line.catalog_item_id, Number(line.qty) || 0)
    if (con) return { ...line, rate: con.rate, tax: con.tax, contract_rate_applied: true, contract_number: con.contract_number }
    if (line.contract_rate_applied && cat) return { ...line, rate: Number(cat.default_rate), tax: Number(cat.default_tax), contract_rate_applied: false, contract_number: null }
    return { ...line, contract_rate_applied: false, contract_number: null }
  }

  // Re-evaluate every line whenever the rate card (vendor's contracts) or catalog loads.
  useEffect(() => { setEditing(p => p ? { ...p, items: p.items.map(recompute) } : p) }, [contracts, catalog])   // eslint-disable-line react-hooks/exhaustive-deps

  const setItem = (i, k, v) => setEditing(p => {
    const items = [...p.items]
    let ln = { ...items[i], [k]: v }
    if (k === 'qty') ln = recompute(ln)   // crossing a qty band flips contract pricing on/off
    items[i] = ln
    return { ...p, items }
  })
  const addItem = () => setEditing(p => ({ ...p, items: [...p.items, { ...EMPTY_ITEM }] }))
  const removeItem = (i) => setEditing(p => ({ ...p, items: p.items.filter((_, idx) => idx !== i) }))
  const addFromCatalog = (c) => {
    const line = recompute({ ...EMPTY_ITEM, catalog_item_id: c.id, sku: c.sku, description: c.name, unit: c.uom || '', rate: Number(c.default_rate), tax: Number(c.default_tax), qty: 1 })
    setEditing(p => {
      const onlyBlank = p.items.length === 1 && !p.items[0].description?.trim() && !p.items[0].catalog_item_id
      return { ...p, items: onlyBlank ? [line] : [...p.items, line] }
    })
    setCatQ(''); setCatOpen(false)
  }
  const catMatches = (catQ.trim()
    ? catalog.filter(c => `${c.name} ${c.sku} ${c.category || ''}`.toLowerCase().includes(catQ.trim().toLowerCase()))
    : catalog).slice(0, 8)

  const t = totalsOf(f.items)
  const today = new Date().toISOString().slice(0, 10)

  // The contract this PO draws on (for the budget meter) — the one whose rate any line used.
  const appliedContract = useMemo(() => {
    const num = f.items.find(it => it.contract_rate_applied)?.contract_number
    return num ? contracts.find(c => c.contract_number === num) : null
  }, [f.items, contracts])
  const ceiling = appliedContract && appliedContract.spend_ceiling != null ? Number(appliedContract.spend_ceiling) : null
  const consumed = appliedContract ? Number(appliedContract.consumed_amount || 0) : 0
  const remaining = ceiling != null ? ceiling - consumed : null
  const poTotal = t.subtotal + t.tax
  const breach = remaining != null && poTotal > remaining + 0.01

  return (
    <Overlay onClose={onClose} width={1180}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>{f.id ? 'Edit' : 'New'} Purchase Order</h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 18px' }}>Fill the header, add line items, then save as draft{admin ? ' or issue to the vendor' : ''}.</p>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 14, marginBottom: 18 }}>
        <Field label="Title *" full><TextInput value={f.title} onChange={set('title')} placeholder="e.g. Site equipment order" /></Field>
        <Field label="Department"><TextInput value={f.department} onChange={set('department')} placeholder="e.g. Operations" /></Field>
        <Field label="Vendor (must be Active)">
          <SelectInput value={f.vendor_id} onChange={set('vendor_id')} pairs
            options={[['', 'Select vendor…'], ...vendors.map(v => [String(v.id), `${v.company_name}${v.status !== 'Active' ? ` (${v.status_label || v.status})` : ''}`])]} />
        </Field>
        <Field label="Currency"><SelectInput value={f.currency} onChange={set('currency')} options={['INR', 'USD', 'EUR', 'GBP']} /></Field>
        <Field label="Order Date"><TextInput type="date" value={f.order_date} onChange={set('order_date')} /></Field>
        <Field label="Expected Delivery"><TextInput type="date" min={today} value={f.expected_delivery_date} onChange={set('expected_delivery_date')} /></Field>
        <Field label="Terms & Conditions" full><textarea value={f.terms} onChange={set('terms')} rows={2} placeholder="Payment terms, delivery terms…" style={{ ...inputStyle, resize: 'vertical' }} /></Field>
      </div>

      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
        <span style={{ width: 22, height: 22, borderRadius: 7, background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontSize: 11, fontWeight: 800, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>#</span>
        <h3 style={{ margin: 0, fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>Line Items</h3>
        <div style={{ flex: 1, height: 1, background: 'var(--border)' }} />
      </div>

      {/* Catalog picker — pull a standardized SKU; an in-force contract rate auto-applies */}
      <div style={{ position: 'relative', marginBottom: 10 }}>
        <div style={{ position: 'relative' }}>
          <Search size={14} style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', color: '#a78bfa' }} />
          <input value={catQ} onChange={e => { setCatQ(e.target.value); setCatOpen(true) }} onFocus={() => setCatOpen(true)} onBlur={() => setTimeout(() => setCatOpen(false), 150)}
            placeholder={catalog.length ? (f.vendor_id ? 'Pick from catalog — contract rates auto-apply for this vendor…' : 'Pick from catalog — select a vendor first to pull contract rates…') : 'No active catalog items yet'}
            disabled={!catalog.length} style={{ ...inputStyle, paddingLeft: 32, borderColor: '#7C3AED55' }} />
        </div>
        {catOpen && catMatches.length > 0 && (
          <div className="pr-glass" style={{ position: 'absolute', zIndex: 30, left: 0, right: 0, marginTop: 4, borderRadius: 12, padding: 6, maxHeight: 240, overflowY: 'auto', boxShadow: '0 20px 40px -12px rgba(0,0,0,.5)' }}>
            {catMatches.map(c => {
              const hasContract = !!(rateMap[c.id] || []).length
              return (
                <button key={c.id} onMouseDown={() => addFromCatalog(c)} style={{ display: 'flex', width: '100%', alignItems: 'center', gap: 10, padding: '8px 10px', borderRadius: 9, cursor: 'pointer', background: 'transparent', border: 'none', textAlign: 'left' }}
                  onMouseEnter={e => e.currentTarget.style.background = 'rgba(124,58,237,0.12)'} onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                  <Package size={15} style={{ color: '#a78bfa', flexShrink: 0 }} />
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{c.name}</div>
                    <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{c.sku}{c.category ? ` · ${c.category}` : ''}{c.uom ? ` · ${c.uom}` : ''}</div>
                  </div>
                  {hasContract && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3, fontSize: 10, fontWeight: 800, color: '#34d399', flexShrink: 0 }}><ShieldCheck size={12} /> contract</span>}
                  <Plus size={14} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
                </button>
              )
            })}
          </div>
        )}
      </div>

      <div className="pr-glass" style={{ padding: 0, marginBottom: 14, borderRadius: 12 }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Description', 'Qty', 'Unit', 'Rate', 'Tax %', 'Amount', ''].map((h, i) => (
            <th key={h + i} style={{ textAlign: i === 0 ? 'left' : i === 6 ? 'center' : 'right', padding: '9px 10px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', borderBottom: '1px solid var(--border)' }}>{h}</th>
          ))}</tr></thead>
          <tbody>
            {f.items.map((it, i) => (
              <tr key={i} className="pr-li-row">
                <td style={{ padding: '6px 8px' }}>
                  <input value={it.description} onChange={e => setItem(i, 'description', e.target.value)} placeholder="Item description" style={{ ...inputStyle, padding: '7px 9px' }} />
                  {(it.sku || it.contract_rate_applied) && (
                    <div style={{ display: 'flex', gap: 8, marginTop: 4, alignItems: 'center', flexWrap: 'wrap' }}>
                      {it.sku && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3, fontSize: 10, fontWeight: 800, color: '#a78bfa' }}><Package size={10} /> {it.sku}</span>}
                      {it.contract_rate_applied && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3, fontSize: 10, fontWeight: 800, color: '#34d399', background: 'rgba(16,185,129,0.12)', padding: '1px 7px', borderRadius: 999 }}><ShieldCheck size={10} /> Contract Rate Applied{it.contract_number ? ` · ${it.contract_number}` : ''}</span>}
                    </div>
                  )}
                </td>
                <td style={{ padding: '6px 8px', width: 72 }}><input type="number" min="0" step="any" value={it.qty} onChange={e => setItem(i, 'qty', e.target.value)} style={{ ...inputStyle, padding: '7px 9px', textAlign: 'right' }} /></td>
                <td style={{ padding: '6px 8px', width: 72 }}><input value={it.unit} onChange={e => setItem(i, 'unit', e.target.value)} placeholder="nos" style={{ ...inputStyle, padding: '7px 9px' }} /></td>
                <td style={{ padding: '6px 8px', width: 100 }}>
                  <input type="number" min="0" step="any" value={it.rate} disabled={it.contract_rate_applied} title={it.contract_rate_applied ? 'Locked to the contract rate' : undefined}
                    onChange={e => setItem(i, 'rate', e.target.value)}
                    style={{ ...inputStyle, padding: '7px 9px', textAlign: 'right', ...(it.contract_rate_applied ? { background: 'rgba(16,185,129,0.10)', borderColor: '#34d39955', color: '#34d399', fontWeight: 700, cursor: 'not-allowed' } : {}) }} />
                </td>
                <td style={{ padding: '6px 8px', width: 72 }}><input type="number" min="0" max="100" step="any" value={it.tax} disabled={it.contract_rate_applied} onChange={e => setItem(i, 'tax', e.target.value)} style={{ ...inputStyle, padding: '7px 9px', textAlign: 'right', ...(it.contract_rate_applied ? { cursor: 'not-allowed', opacity: 0.8 } : {}) }} /></td>
                <td style={{ padding: '6px 10px', width: 110, textAlign: 'right', fontWeight: 700, color: 'var(--text-h)', fontSize: 12.5, whiteSpace: 'nowrap' }}>{fmtMoney(lineAmount(it), f.currency)}</td>
                <td style={{ padding: '6px 8px', width: 36, textAlign: 'center' }}>
                  <button onClick={() => removeItem(i)} disabled={f.items.length === 1} title="Remove" style={{ background: 'none', border: 'none', cursor: f.items.length === 1 ? 'not-allowed' : 'pointer', color: '#f87171', opacity: f.items.length === 1 ? 0.3 : 1, padding: 4 }}><Trash size={14} /></button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        <div style={{ padding: '8px 12px', borderTop: '1px solid var(--border)' }}>
          <button onClick={addItem} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '6px 12px', borderRadius: 8, border: '1px dashed var(--border-purple)', background: 'rgba(124,58,237,0.06)', color: '#a78bfa', cursor: 'pointer', fontSize: 12, fontWeight: 700 }}><Plus size={13} /> Add blank line</button>
        </div>
      </div>

      {/* Contract ceiling budget meter — appears once a line draws a contract rate */}
      {appliedContract && (
        <div className="pr-glass" style={{ padding: 14, marginBottom: 14, borderRadius: 12, border: `1px solid ${breach ? '#ef444455' : '#34d39955'}` }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
            <ShieldCheck size={15} style={{ color: breach ? '#ef4444' : '#34d399' }} />
            <span style={{ fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)' }}>Contract {appliedContract.contract_number} · {appliedContract.title}</span>
          </div>
          {ceiling == null ? (
            <p style={{ margin: 0, fontSize: 12, color: 'var(--text-muted)' }}>Uncapped contract — no spending ceiling to track.</p>
          ) : (
            <>
              <div style={{ height: 8, borderRadius: 999, background: 'var(--border)', overflow: 'hidden', display: 'flex' }}>
                <div style={{ width: `${Math.min(100, (consumed / ceiling) * 100)}%`, background: '#64748b' }} />
                <div style={{ width: `${Math.min(100 - Math.min(100, (consumed / ceiling) * 100), (poTotal / ceiling) * 100)}%`, background: breach ? '#ef4444' : '#34d399' }} />
              </div>
              <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 8, fontSize: 11.5 }}>
                <span style={{ color: 'var(--text-muted)' }}>Consumed <b style={{ color: 'var(--text-h)' }}>{fmtMoney(consumed, f.currency)}</b></span>
                <span style={{ color: 'var(--text-muted)' }}>This PO <b style={{ color: breach ? '#ef4444' : '#34d399' }}>{fmtMoney(poTotal, f.currency)}</b></span>
                <span style={{ color: 'var(--text-muted)' }}>Remaining <b style={{ color: 'var(--text-h)' }}>{fmtMoney(remaining, f.currency)}</b> / {fmtMoney(ceiling, f.currency)}</span>
              </div>
              {breach && <p style={{ margin: '10px 0 0', fontSize: 12, fontWeight: 700, color: '#ef4444', display: 'flex', alignItems: 'center', gap: 6 }}><Ban size={13} /> This order exceeds the contract's remaining ceiling and cannot be issued.</p>}
            </>
          )}
        </div>
      )}

      <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 8 }}>
        <div style={{ minWidth: 260, display: 'flex', flexDirection: 'column', gap: 6 }}>
          <TotalRow label="Subtotal" value={fmtMoney(t.subtotal, f.currency)} />
          <TotalRow label="Tax" value={fmtMoney(t.tax, f.currency)} />
          <div style={{ height: 1, background: 'var(--border)', margin: '2px 0' }} />
          <TotalRow label="Total" value={fmtMoney(t.subtotal + t.tax, f.currency)} strong />
        </div>
      </div>

      <div style={{ position: 'sticky', bottom: -28, margin: '4px -28px -28px', padding: '14px 28px', background: 'var(--bg-card, var(--bg-input))', borderTop: '1px solid var(--border)', display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
        <button onClick={onClose} disabled={saving} style={{ padding: '9px 16px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-muted)', fontWeight: 700, fontSize: 13, cursor: 'pointer' }}>Cancel</button>
        <button onClick={() => onSave('draft')} disabled={saving} style={{ padding: '9px 16px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontWeight: 700, fontSize: 13, cursor: 'pointer' }}>Save Draft</button>
        {admin && <button onClick={() => onSave('issue')} disabled={saving || breach} title={breach ? "Reduce the order below the contract's remaining ceiling to issue" : undefined} style={{ padding: '9px 18px', borderRadius: 10, border: 'none', background: breach ? 'var(--border)' : 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: breach ? 'var(--text-muted)' : '#fff', fontWeight: 800, fontSize: 13, cursor: (saving || breach) ? 'not-allowed' : 'pointer', opacity: saving ? 0.7 : 1 }}>{saving ? 'Saving…' : 'Save & Issue'}</button>}
      </div>
    </Overlay>
  )
}

// ── Action modal (issue / close / cancel / delete) ───────────────────────────
function ActionModal({ actionModal, remarks, setRemarks, loading, onClose, onConfirm }) {
  const { action, order } = actionModal
  const meta = {
    issue:  { title: 'Issue Purchase Order', color: '#0ea5e9' },
    close:  { title: 'Close Purchase Order',  color: '#94a3b8' },
    cancel: { title: 'Cancel Purchase Order', color: '#ef4444' },
    delete: { title: 'Delete Purchase Order', color: '#ef4444' },
  }[action]
  const showRemarks = action === 'close' || action === 'cancel'
  return (
    <Overlay onClose={() => !loading && onClose()} width={460}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14 }}>
        {action === 'cancel' || action === 'delete' ? <XCircle size={22} color={meta.color} /> : <CheckCircle size={22} color={meta.color} />}
        <h3 style={{ color: 'var(--text-h)', margin: 0, fontSize: 16, fontWeight: 800 }}>{meta.title}</h3>
      </div>
      <p style={{ color: 'var(--text-muted)', fontSize: 13, marginBottom: 14 }}>
        <strong style={{ color: 'var(--text-h)' }}>{order.po_number || `PO-${order.id}`} · {order.title}</strong>{order.vendor?.company_name ? ` — ${order.vendor.company_name}` : ''}
      </p>
      {action === 'issue' && <InfoBox>Issuing commits the order to the vendor and locks line items. Goods can then be received against it.</InfoBox>}
      {action === 'delete' && <InfoBox tone="danger">This permanently deletes the draft order. This cannot be undone.</InfoBox>}
      {action === 'cancel' && <InfoBox tone="danger">Cancelling stops any further receiving on this order.</InfoBox>}
      {showRemarks && (
        <>
          <label style={labelStyle}>Remarks (optional)</label>
          <textarea value={remarks} onChange={e => setRemarks(e.target.value)} rows={3} placeholder="Add remarks…" style={{ ...inputStyle, resize: 'vertical' }} />
        </>
      )}
      <ModalFooter onClose={onClose} onConfirm={onConfirm} loading={loading} confirmLabel="Confirm" color={meta.color} />
    </Overlay>
  )
}

// ── Receive Goods modal — creates + confirms a GRN in one action ─────────────
function ReceiveModal({ order, onClose, onDone }) {
  // Only lines with pending qty are receivable. Accepted is capped at pending.
  const pendingLines = (order.items || []).filter(it => Number(it.pending_qty ?? (it.qty - it.received_qty)) > 0)
  const [lines, setLines] = useState(() => pendingLines.map(it => ({
    purchase_order_item_id: it.id, description: it.description,
    pending: Number(it.pending_qty ?? (it.qty - it.received_qty)),
    accepted_qty: Number(it.pending_qty ?? (it.qty - it.received_qty)), rejected_qty: 0, remarks: '',
  })))
  const [meta, setMeta] = useState({ received_date: new Date().toISOString().slice(0, 10), delivery_note_ref: '', notes: '' })
  const [saving, setSaving] = useState(false)

  const setLine = (i, k, v) => setLines(p => { const n = [...p]; n[i] = { ...n[i], [k]: v }; return n })
  const totalAccepted = lines.reduce((s, l) => s + (Number(l.accepted_qty) || 0), 0)

  const submit = async () => {
    const items = lines
      .filter(l => (Number(l.accepted_qty) || 0) > 0 || (Number(l.rejected_qty) || 0) > 0)
      .map(l => ({ purchase_order_item_id: l.purchase_order_item_id, accepted_qty: Number(l.accepted_qty) || 0, rejected_qty: Number(l.rejected_qty) || 0, remarks: l.remarks || null }))
    if (items.length === 0) { alert('Enter a received quantity for at least one line.'); return }
    setSaving(true)
    try {
      // Two steps: create the draft GRN, then confirm it (which rolls quantities
      // up to the PO). Accepted is client-capped at pending, so confirm succeeds.
      const grn = await purchaseApi.receipts.create(order.id, { ...meta, items })
      const grnId = grn?.id ?? grn?.data?.id
      await purchaseApi.receipts.confirm(grnId)
      onDone(order.id)
    } catch (e) { alert(e?.response?.data?.message || 'Failed to record receipt') }
    finally { setSaving(false) }
  }

  return (
    <Overlay onClose={() => !saving && onClose()} width={1000}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>Receive Goods · {order.po_number || `PO-${order.id}`}</h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 16px' }}>Record accepted and rejected quantities. Accepted can't exceed the pending quantity.</p>

      {pendingLines.length === 0 ? (
        <InfoBox>All ordered quantities have already been received.</InfoBox>
      ) : (
        <>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 14, marginBottom: 16 }}>
            <Field label="Received Date"><TextInput type="date" value={meta.received_date} onChange={e => setMeta(m => ({ ...m, received_date: e.target.value }))} /></Field>
            <Field label="Delivery Note / Challan #"><TextInput value={meta.delivery_note_ref} onChange={e => setMeta(m => ({ ...m, delivery_note_ref: e.target.value }))} placeholder="e.g. DC-778" /></Field>
            <Field label="Notes"><TextInput value={meta.notes} onChange={e => setMeta(m => ({ ...m, notes: e.target.value }))} placeholder="Optional" /></Field>
          </div>

          <div className="pr-glass" style={{ padding: 0, marginBottom: 14, borderRadius: 12 }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead><tr>{['Item', 'Pending', 'Accepted', 'Rejected', 'Remarks'].map((h, i) => (
                <th key={h} style={{ textAlign: i === 0 ? 'left' : i < 4 ? 'right' : 'left', padding: '9px 10px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', borderBottom: '1px solid var(--border)' }}>{h}</th>
              ))}</tr></thead>
              <tbody>
                {lines.map((l, i) => (
                  <tr key={l.purchase_order_item_id} className="pr-li-row">
                    <td style={{ padding: '8px 10px', fontSize: 12.5, color: 'var(--text-h)', fontWeight: 600 }}>{l.description}</td>
                    <td style={{ padding: '6px 10px', textAlign: 'right', fontSize: 12.5, fontWeight: 700, color: '#f59e0b' }}>{l.pending}</td>
                    <td style={{ padding: '6px 8px', width: 96 }}>
                      <input type="number" min="0" max={l.pending} step="any" value={l.accepted_qty}
                        onChange={e => { const v = Math.min(Number(e.target.value) || 0, l.pending); setLine(i, 'accepted_qty', v) }}
                        style={{ ...inputStyle, padding: '7px 9px', textAlign: 'right' }} />
                    </td>
                    <td style={{ padding: '6px 8px', width: 90 }}><input type="number" min="0" step="any" value={l.rejected_qty} onChange={e => setLine(i, 'rejected_qty', Number(e.target.value) || 0)} style={{ ...inputStyle, padding: '7px 9px', textAlign: 'right' }} /></td>
                    <td style={{ padding: '6px 8px' }}><input value={l.remarks} onChange={e => setLine(i, 'remarks', e.target.value)} placeholder="e.g. damaged" style={{ ...inputStyle, padding: '7px 9px' }} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '0 0 4px', textAlign: 'right' }}>Total accepted this receipt: <strong style={{ color: 'var(--text-h)' }}>{totalAccepted}</strong> unit(s)</p>
        </>
      )}

      {pendingLines.length > 0 && <ModalFooter onClose={onClose} onConfirm={submit} loading={saving} disabled={totalAccepted <= 0} confirmLabel="Receive & Confirm" color="#f59e0b" />}
      {pendingLines.length === 0 && (
        <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 18 }}>
          <button onClick={onClose} style={{ padding: '9px 20px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>Close</button>
        </div>
      )}
    </Overlay>
  )
}

// ── Detail modal — items with received/pending, GRN history, audit ───────────
function DetailModal({ order, manage, admin, onClose, onReceive, onAction }) {
  const items = order.items || []
  const grns = order.goods_receipts || []
  const canReceive = manage && [PO_STATUS.ISSUED, PO_STATUS.PARTIALLY_RECEIVED].includes(order.status)
  const info = [
    ['Vendor', order.vendor?.company_name], ['Department', order.department],
    ['Order Date', order.order_date && fmtDate(order.order_date)], ['Expected', order.expected_delivery_date && fmtDate(order.expected_delivery_date)],
    ['Created By', order.creator?.name], ['Issued By', order.issuer?.name],
    ['From Request', order.purchase_request?.pr_number],
  ].filter(([, v]) => v)

  return (
    <Overlay onClose={onClose} width={1120}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6, flexWrap: 'wrap' }}>
        <h3 style={{ color: 'var(--text-h)', margin: 0, fontSize: 17, fontWeight: 800 }}>{order.po_number || `PO-${order.id}`} · {order.title}</h3>
        <StatusBadge status={order.status} />
        {canReceive && <button onClick={() => onReceive(order)} style={{ marginLeft: 'auto', display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 14px', borderRadius: 9, border: 'none', background: 'linear-gradient(135deg,#f59e0b,#d97706)', color: '#fff', fontWeight: 700, fontSize: 12.5, cursor: 'pointer' }}><PackagePlus size={14} /> Receive Goods</button>}
      </div>
      {[PO_STATUS.ISSUED, PO_STATUS.PARTIALLY_RECEIVED, PO_STATUS.RECEIVED].includes(order.status) && (
        <div style={{ margin: '10px 0 16px', maxWidth: 360 }}><ReceiptBar percent={order.received_percent} /></div>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px 20px', marginBottom: 16 }}>
        {info.map(([k, v]) => (
          <div key={k} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, borderBottom: '1px solid var(--border)', paddingBottom: 6 }}>
            <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>{k}</span>
            <span style={{ color: 'var(--text-h)', fontSize: 12, fontWeight: 600, textAlign: 'right' }}>{v}</span>
          </div>
        ))}
      </div>

      <label style={labelStyle}>Line Items</label>
      <div className="pr-glass" style={{ padding: 0, marginBottom: 14, borderRadius: 12 }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Description', 'Ordered', 'Received', 'Pending', 'Rate', 'Amount'].map((h, i) => (
            <th key={h} style={{ textAlign: i === 0 ? 'left' : 'right', padding: '8px 10px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', borderBottom: '1px solid var(--border)' }}>{h}</th>
          ))}</tr></thead>
          <tbody>
            {items.map((it, i) => {
              const pending = Number(it.pending_qty ?? (it.qty - it.received_qty))
              return (
                <tr key={i}>
                  <td style={{ padding: '8px 10px', fontSize: 12.5, color: 'var(--text-h)' }}>{it.description}{it.unit ? <span style={{ color: 'var(--text-muted)' }}> · {it.unit}</span> : ''}</td>
                  <td style={{ padding: '8px 10px', fontSize: 12.5, textAlign: 'right', color: 'var(--text-muted)' }}>{it.qty}</td>
                  <td style={{ padding: '8px 10px', fontSize: 12.5, textAlign: 'right', fontWeight: 700, color: '#10b981' }}>{it.received_qty}</td>
                  <td style={{ padding: '8px 10px', fontSize: 12.5, textAlign: 'right', fontWeight: 700, color: pending > 0 ? '#f59e0b' : 'var(--text-muted)' }}>{pending}</td>
                  <td style={{ padding: '8px 10px', fontSize: 12.5, textAlign: 'right', color: 'var(--text-muted)' }}>{fmtMoney(it.rate, order.currency)}</td>
                  <td style={{ padding: '8px 10px', fontSize: 12.5, textAlign: 'right', fontWeight: 700, color: 'var(--text-h)' }}>{fmtMoney(it.amount ?? lineAmount(it), order.currency)}</td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 16 }}>
        <div style={{ minWidth: 240, display: 'flex', flexDirection: 'column', gap: 6 }}>
          <TotalRow label="Subtotal" value={fmtMoney(order.subtotal, order.currency)} />
          <TotalRow label="Tax" value={fmtMoney(order.tax_total, order.currency)} />
          <div style={{ height: 1, background: 'var(--border)', margin: '2px 0' }} />
          <TotalRow label="Total" value={fmtMoney(order.total, order.currency)} strong />
        </div>
      </div>

      {/* GRN history */}
      <label style={labelStyle}>Goods Receipts ({grns.length})</label>
      {grns.length === 0 ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '0 0 14px' }}>No goods received yet.</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginBottom: 14 }}>
          {grns.map(g => (
            <div key={g.id} className="pr-glass" style={{ padding: '10px 14px', display: 'flex', alignItems: 'center', gap: 12, borderRadius: 10 }}>
              <span style={{ fontWeight: 800, fontSize: 12, color: '#a78bfa' }}>{g.grn_number}</span>
              <StatusPill cfg={grnStatusCfg(g.status)} />
              <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>{fmtDate(g.received_date)}</span>
              {g.delivery_note_ref && <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>DC: {g.delivery_note_ref}</span>}
              <span style={{ marginLeft: 'auto', fontSize: 12, fontWeight: 700, color: '#10b981' }}>
                {(g.items || []).reduce((s, i) => s + Number(i.accepted_qty || 0), 0)} accepted
              </span>
            </div>
          ))}
        </div>
      )}

      {order.terms && (<><label style={labelStyle}>Terms</label><p style={{ color: 'var(--text-h)', fontSize: 13, margin: '0 0 14px', whiteSpace: 'pre-wrap' }}>{order.terms}</p></>)}

      <label style={labelStyle}>Audit Trail</label>
      {order.audit_logs === undefined
        ? <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>Loading timeline…</p>
        : <AuditTimeline entries={order.audit_logs} />}

      <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 18 }}>
        <button onClick={onClose} style={{ padding: '9px 20px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>Close</button>
      </div>
    </Overlay>
  )
}

// ── List view ────────────────────────────────────────────────────────────────
const PO_PAGE = 12
function POListView({ rows, onView, onEdit, admin, manage, openAction, onReceive, highlightId }) {
  const [page, setPage] = useState(1)
  const pages = Math.max(1, Math.ceil(rows.length / PO_PAGE))
  const pageRows = useMemo(() => rows.slice((page - 1) * PO_PAGE, page * PO_PAGE), [rows, page])
  useEffect(() => { if (page > pages) setPage(1) }, [pages, page])
  const th = { textAlign: 'left', padding: '10px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
  const td = { padding: '11px 12px', borderBottom: '1px solid var(--border)', fontSize: 12.5, verticalAlign: 'middle', whiteSpace: 'nowrap' }

  return (
    <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
      <div style={{ overflowX: 'auto', maxHeight: '62vh', overflowY: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead style={{ position: 'sticky', top: 0, zIndex: 1, background: 'var(--bg-card, var(--bg-input))' }}>
            <tr>{['PO #', 'Title', 'Vendor', 'Lines', 'Total', 'Received', 'Status'].map(h => <th key={h} style={th}>{h}</th>)}<th style={{ ...th, textAlign: 'right' }}>Actions</th></tr>
          </thead>
          <tbody>
            {pageRows.map(r => {
              const cfg = poStatusCfg(r.status)
              const canReceive = manage && [PO_STATUS.ISSUED, PO_STATUS.PARTIALLY_RECEIVED].includes(r.status)
              return (
                <tr key={r.id} style={{ cursor: 'pointer', background: r.id === highlightId ? 'rgba(124,58,237,0.08)' : 'transparent' }} onClick={() => onView(r)}
                  onMouseEnter={e => { if (r.id !== highlightId) e.currentTarget.style.background = 'var(--bg-input)' }}
                  onMouseLeave={e => { if (r.id !== highlightId) e.currentTarget.style.background = 'transparent' }}>
                  <td style={{ ...td, fontWeight: 700, color: '#a78bfa' }}>{r.po_number || `PO-${r.id}`}</td>
                  <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)', whiteSpace: 'normal', maxWidth: 220 }}>{r.title}</td>
                  <td style={{ ...td, color: 'var(--text-muted)' }}>{r.vendor?.company_name || '—'}</td>
                  <td style={{ ...td, textAlign: 'center', fontWeight: 700 }}>{r.items?.length ?? '—'}</td>
                  <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)' }}>{fmtMoney(r.total, r.currency)}</td>
                  <td style={{ ...td, width: 130 }}>{[PO_STATUS.ISSUED, PO_STATUS.PARTIALLY_RECEIVED, PO_STATUS.RECEIVED].includes(r.status) ? <ReceiptBar percent={r.received_percent} /> : <span style={{ color: 'var(--text-muted)' }}>—</span>}</td>
                  <td style={td}><span style={{ fontSize: 10.5, fontWeight: 700, padding: '2px 8px', borderRadius: 8, background: cfg.bg, color: cfg.color }}>{cfg.label}</span></td>
                  <td style={{ ...td, textAlign: 'right' }} onClick={e => e.stopPropagation()}>
                    <div style={{ display: 'inline-flex', gap: 6, justifyContent: 'flex-end' }}>
                      {r.status === PO_STATUS.DRAFT && admin && <button onClick={() => openAction(r, 'issue')} style={{ fontSize: 11, fontWeight: 700, padding: '4px 9px', borderRadius: 7, border: 'none', background: 'rgba(14,165,233,0.15)', color: '#0ea5e9', cursor: 'pointer' }}>Issue</button>}
                      {canReceive && <button onClick={() => onReceive(r)} style={{ fontSize: 11, fontWeight: 700, padding: '4px 9px', borderRadius: 7, border: 'none', background: 'rgba(245,158,11,0.15)', color: '#f59e0b', cursor: 'pointer' }}>Receive</button>}
                      <button onClick={() => onView(r)} title="View" style={{ fontSize: 11, padding: '4px 8px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer' }}><Eye size={12} /></button>
                    </div>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '10px 14px', borderTop: '1px solid var(--border)' }}>
        <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{rows.length} order{rows.length === 1 ? '' : 's'} · page {page} of {pages}</span>
        <div style={{ display: 'flex', gap: 6 }}>
          <button onClick={() => setPage(p => Math.max(1, p - 1))} disabled={page === 1} style={{ padding: '5px 9px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: page === 1 ? 'not-allowed' : 'pointer', opacity: page === 1 ? 0.5 : 1 }}><ChevronLeft size={14} /></button>
          <button onClick={() => setPage(p => Math.min(pages, p + 1))} disabled={page === pages} style={{ padding: '5px 9px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: page === pages ? 'not-allowed' : 'pointer', opacity: page === pages ? 0.5 : 1 }}><ChevronRight size={14} /></button>
        </div>
      </div>
    </div>
  )
}
