import { useState, useEffect, useCallback, useMemo } from 'react'
import { useLocation } from 'react-router-dom'
import {
  Plus, RefreshCw, Search, Send, Eye, Pencil, Trash2, Ban, CheckCircle, XCircle,
  FileText, PackageX, Wallet, BadgeCheck, LayoutGrid, List, Trash, IndianRupee,
  ChevronLeft, ChevronRight, RotateCcw, Undo2, GitMerge, Loader2,
} from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { useAuth } from '@/context/AuthContext'
import AuditTimeline from '@/components/ui/AuditTimeline'
import {
  DN_STATUS, DN_STATUS_CONFIG, dnStatusCfg, DN_STAGES, PAYMENT_MODES, paymentModeLabel,
  canApprovePR as isAdmin, canManagePR as isStaffOrAdmin,
  fmtMoney, fmtMoneyShort, fmtDate, lineAmount, totalsOf,
} from '../constants'
import {
  KIT3D_STYLE as PURCHASE_STYLE, labelStyle, inputStyle, Overlay, ModalFooter, InfoBox,
  Field, TextInput, SelectInput, TotalRow, ActBtn, StatusBadge as StatusPill,
} from '@/components/ui/kit3d'

const EMPTY_ITEM = { description: '', qty: 1, unit: '', rate: 0, tax: 0, purchase_order_item_id: null, on_hand: null }
const EMPTY_FORM = {
  vendor_id: '', purchase_order_id: '', reason: '', currency: 'INR',
  debit_date: new Date().toISOString().slice(0, 10), adjust_inventory: true, notes: '',
  items: [{ ...EMPTY_ITEM }],
}

const StatusBadge = ({ status }) => <StatusPill cfg={dnStatusCfg(status)} />

// ── Pipeline ─────────────────────────────────────────────────────────────────
function Pipeline({ stats = {}, active, onStage }) {
  const count = { draft: stats.draft || 0, open: stats.open || 0, settled: stats.settled || 0 }
  const COLORS = { draft: '#94a3b8', open: '#f59e0b', settled: '#10b981' }
  const ICONS = { draft: FileText, open: Wallet, settled: BadgeCheck }
  return (
    <div style={{ display: 'flex', alignItems: 'center', width: 'max-content', minWidth: '100%', gap: 0 }}>
      {DN_STAGES.map((s, i) => {
        const Icon = ICONS[s.key]; const color = COLORS[s.key]; const n = count[s.key] || 0
        const selected = active === s.key; const lit = n > 0 || selected
        return (
          <div key={s.key} style={{ display: 'flex', alignItems: 'center', flex: 1, minWidth: 160 }}>
            <button type="button" onClick={() => onStage(selected ? 'All' : s.key)} title={`${n} in ${s.label} — click to filter`}
              className="pr-node" style={{
                flex: 1, display: 'flex', alignItems: 'center', gap: 11, padding: '11px 15px', borderRadius: 16, cursor: 'pointer',
                background: lit ? `linear-gradient(135deg, ${color}26, ${color}0f)` : 'var(--bg-input)',
                border: `1.5px solid ${selected ? color : lit ? color + '55' : 'var(--border)'}`,
                opacity: lit ? 1 : 0.55, boxShadow: selected ? `0 10px 26px -8px ${color}88, inset 0 1px 0 rgba(255,255,255,.14)` : 'inset 0 1px 0 var(--card-shine)',
              }}>
              <span style={{ width: 38, height: 38, borderRadius: 13, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `linear-gradient(145deg, ${color}, ${color}aa)`, color: '#fff', boxShadow: lit ? `0 6px 16px -3px ${color}99, inset 0 1px 0 rgba(255,255,255,.4)` : 'none', flexShrink: 0 }}>
                <Icon size={17} />
              </span>
              <span style={{ display: 'flex', flexDirection: 'column', lineHeight: 1.2, textAlign: 'left', minWidth: 0 }}>
                <span style={{ fontSize: 9, fontWeight: 800, letterSpacing: '0.06em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>Stage {i + 1}</span>
                <span style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>{s.label}</span>
                <span style={{ fontSize: 9.5, fontWeight: 700, letterSpacing: '0.04em', textTransform: 'uppercase', color }}>{s.sub}</span>
              </span>
              <span style={{ marginLeft: 'auto', minWidth: 26, height: 26, padding: '0 8px', borderRadius: 999, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 12.5, fontWeight: 800, color: n > 0 ? '#fff' : 'var(--text-muted)', background: n > 0 ? color : 'var(--bg-card)', border: n > 0 ? 'none' : '1px solid var(--border)', flexShrink: 0 }}>{n}</span>
            </button>
            {i < DN_STAGES.length - 1 && <div className={`pr-flow${lit ? '' : ' pr-flow-dim'}`} style={{ width: 30, height: 3, borderRadius: 4, margin: '0 5px', flexShrink: 0, background: `linear-gradient(90deg, ${color}, ${COLORS[DN_STAGES[i + 1].key]})` }} />}
          </div>
        )
      })}
    </div>
  )
}

const RefundBar = ({ refunded, total }) => {
  const pct = total > 0 ? Math.round(Math.min(100, (Number(refunded) / Number(total)) * 100)) : 0
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
      <div className="pr-bar" style={{ flex: 1 }}><span style={{ width: `${pct}%` }} /></div>
      <span style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', minWidth: 34, textAlign: 'right' }}>{pct}%</span>
    </div>
  )
}

// ── Main page ────────────────────────────────────────────────────────────────
export default function PurchaseDebitNotes() {
  const { user } = useAuth()
  const admin  = isAdmin(user)
  const manage = isStaffOrAdmin(user)
  const location = useLocation()
  const highlightId = location.state?.highlight

  const [rows, setRows]       = useState([])
  const [stats, setStats]     = useState({})
  const [loading, setLoading] = useState(true)
  const [search, setSearch]   = useState('')
  const [filterStatus, setFilterStatus] = useState('All')
  const [viewMode, setViewMode] = useState(() => localStorage.getItem('pur_dn_view') || 'card')
  const changeViewMode = (v) => { setViewMode(v); localStorage.setItem('pur_dn_view', v) }

  const [showForm, setShowForm]   = useState(false)
  const [editing, setEditing]     = useState(null)
  const [saving, setSaving]       = useState(false)

  const [actionModal, setActionModal] = useState(null)
  const [remarks, setRemarks]         = useState('')
  const [actionLoading, setActionLoading] = useState(false)

  const [detail, setDetail]     = useState(null)
  const [refunding, setRefund]  = useState(null)
  const [applying, setApplying] = useState(null)   // DN being netted against an invoice

  const fetchAll = useCallback(async () => {
    setLoading(true)
    try {
      const [listRes, statRes] = await Promise.all([purchaseApi.debitNotes.list(), purchaseApi.debitNotes.stats()])
      setRows(Array.isArray(listRes?.data ?? listRes) ? (listRes.data ?? listRes) : [])
      setStats(statRes?.data ?? statRes ?? {})
    } catch (e) { console.error('Failed to load debit notes', e) }
    finally { setLoading(false) }
  }, [])
  useEffect(() => { fetchAll() }, [fetchAll])

  const filtered = rows.filter(r => {
    const q = search.toLowerCase()
    const matchSearch = !q || r.debit_number?.toLowerCase().includes(q) || r.reason?.toLowerCase().includes(q) || r.vendor?.company_name?.toLowerCase().includes(q)
    const matchStatus = filterStatus === 'All' || r.status === filterStatus
      || !!DN_STAGES.find(s => s.key === filterStatus)?.statuses.includes(r.status)
    return matchSearch && matchStatus
  })

  const openCreate = () => { setEditing({ ...EMPTY_FORM, items: [{ ...EMPTY_ITEM }] }); setShowForm(true) }
  const openEdit = (r) => {
    setEditing({
      id: r.id, vendor_id: r.vendor_id ?? '', purchase_order_id: r.purchase_order_id ?? '', reason: r.reason || '',
      currency: r.currency || 'INR', debit_date: r.debit_date?.slice(0, 10) || '', adjust_inventory: !!r.adjust_inventory, notes: r.notes || '',
      items: (r.items?.length ? r.items : [{ ...EMPTY_ITEM }]).map(it => ({
        description: it.description || '', qty: it.qty ?? 1, unit: it.unit || '', rate: it.rate ?? 0, tax: it.tax ?? 0,
        purchase_order_item_id: it.purchase_order_item_id ?? null, on_hand: null,
      })),
    })
    setShowForm(true)
  }

  const handleSave = async (mode = 'draft') => {
    const f = editing
    const items = f.items.filter(it => it.description?.trim() && Number(it.qty) > 0)
    if (items.length === 0) { alert('Add at least one line with a return quantity.'); return }
    setSaving(true)
    try {
      const payload = {
        vendor_id: f.vendor_id || null, purchase_order_id: f.purchase_order_id || null,
        reason: f.reason || null, currency: f.currency, debit_date: f.debit_date || null,
        adjust_inventory: !!f.purchase_order_id && f.adjust_inventory, notes: f.notes || null,
        items: items.map((it, i) => ({
          description: it.description, purchase_order_item_id: it.purchase_order_item_id || null,
          qty: Number(it.qty) || 1, unit: it.unit || null, rate: Number(it.rate) || 0, tax: Number(it.tax) || 0, sort_order: i,
        })),
      }
      let saved
      if (f.id) saved = await purchaseApi.debitNotes.update(f.id, payload)
      else saved = await purchaseApi.debitNotes.create(payload)
      const id = f.id || saved?.id || saved?.data?.id
      if (mode === 'issue' && id) await purchaseApi.debitNotes.issue(id)
      setShowForm(false); setEditing(null); fetchAll()
    } catch (e) { alert(e?.response?.data?.message || 'Failed to save debit note') }
    finally { setSaving(false) }
  }

  const openAction = (dn, action) => { setActionModal({ dn, action }); setRemarks('') }
  const runAction = async () => {
    if (!actionModal) return
    const { dn, action } = actionModal
    setActionLoading(true)
    try {
      const id = dn.id
      if (action === 'issue')       await purchaseApi.debitNotes.issue(id)
      else if (action === 'cancel') await purchaseApi.debitNotes.cancel(id, remarks)
      else if (action === 'delete') await purchaseApi.debitNotes.delete(id)
      setActionModal(null); setRemarks(''); fetchAll()
    } catch (e) { alert(e?.response?.data?.message || 'Action failed') }
    finally { setActionLoading(false) }
  }

  const openDetail = async (r) => {
    setDetail(r)
    try { const full = await purchaseApi.debitNotes.get(r.id); if ((full?.data ?? full)?.id) setDetail(full.data ?? full) } catch { /* keep */ }
  }
  const refreshDetail = async (id) => {
    try { const full = await purchaseApi.debitNotes.get(id); setDetail(full?.data ?? full) } catch { /* noop */ }
    fetchAll()
  }

  const statCards = [
    { label: 'Total',        value: stats.total,    color: '#7C3AED', filter: 'All' },
    { label: 'Draft',        value: stats.draft,    color: '#94a3b8', filter: DN_STATUS.DRAFT },
    { label: 'Open',         value: stats.open,     color: '#f59e0b', filter: DN_STATUS.OPEN },
    { label: 'Settled',      value: stats.settled,  color: '#10b981', filter: DN_STATUS.SETTLED },
    { label: 'Open Claims',  value: fmtMoneyShort(stats.open_claims), color: '#f59e0b', filter: DN_STATUS.OPEN, wide: true },
    { label: 'Refunded',     value: fmtMoneyShort(stats.refunded), color: '#10b981', filter: 'All', wide: true },
  ]

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{PURCHASE_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 800, margin: 0 }}>Debit Notes &amp; Returns</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Return goods to vendors, adjust inventory and reclaim value via refunds.</p>
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
              <Plus size={15} /> New Debit Note
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
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search debit #, reason or vendor…" style={{ ...inputStyle, paddingLeft: 32 }} />
        </div>
        <select value={filterStatus} onChange={e => setFilterStatus(e.target.value)} style={{ ...inputStyle, width: 'auto', cursor: 'pointer' }}>
          <option value="All">All Status</option>
          {Object.values(DN_STATUS).map(s => <option key={s} value={s}>{DN_STATUS_CONFIG[s]?.label || s}</option>)}
        </select>
      </div>

      {loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}>Loading debit notes…</div>
      ) : filtered.length === 0 ? (
        <EmptyState onCreate={manage ? openCreate : null} />
      ) : viewMode === 'list' ? (
        <DNListView rows={filtered} onView={openDetail} onEdit={openEdit} admin={admin} manage={manage} openAction={openAction} onRefund={setRefund} highlightId={highlightId} />
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          {filtered.map(r => (
            <DNCard key={r.id} r={r} admin={admin} manage={manage} highlight={r.id === highlightId}
              onView={openDetail} onEdit={openEdit} openAction={openAction} onRefund={setRefund} />
          ))}
        </div>
      )}

      {showForm && <DebitNoteFormModal editing={editing} setEditing={setEditing} saving={saving} manage={manage} onClose={() => setShowForm(false)} onSave={handleSave} />}
      {actionModal && <ActionModal actionModal={actionModal} remarks={remarks} setRemarks={setRemarks} loading={actionLoading} onClose={() => setActionModal(null)} onConfirm={runAction} />}
      {detail && <DetailModal dn={detail} admin={admin} onClose={() => setDetail(null)} onRefund={setRefund} onApply={setApplying}
        onReverseRefund={async (rid) => { try { await purchaseApi.debitNotes.deleteRefund(detail.id, rid); refreshDetail(detail.id) } catch (e) { alert(e?.response?.data?.message || 'Failed to reverse') } }}
        onReverseCredit={async (aid) => { try { await purchaseApi.debitNotes.reverseCredit(detail.id, aid); refreshDetail(detail.id) } catch (e) { alert(e?.response?.data?.message || 'Failed to reverse') } }} />}
      {refunding && <RefundModal dn={refunding} onClose={() => setRefund(null)} onDone={(id) => { setRefund(null); if (detail?.id === id) refreshDetail(id); else fetchAll() }} />}
      {applying && <ApplyCreditModal dn={applying} onClose={() => setApplying(null)} onDone={(id) => { setApplying(null); if (detail?.id === id) refreshDetail(id); else fetchAll() }} />}
    </div>
  )
}

// ── Card ─────────────────────────────────────────────────────────────────────
function DNCard({ r, admin, manage, highlight, onView, onEdit, openAction, onRefund }) {
  const canRefund = admin && r.status === DN_STATUS.OPEN && Number(r.balance) > 0
  return (
    <div className="pr-glass pr-lift pr-pop" style={{ padding: 20, outline: highlight ? '2px solid #7C3AED' : 'none' }}>
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16 }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8, flexWrap: 'wrap' }}>
            <span style={{ color: '#a78bfa', fontWeight: 800, fontSize: 12 }}>{r.debit_number}</span>
            {r.reason && <span style={{ color: 'var(--text-h)', fontWeight: 700, fontSize: 14 }}>{r.reason}</span>}
            <StatusBadge status={r.status} />
            {r.purchase_order_id && r.adjust_inventory && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3, padding: '2px 8px', borderRadius: 10, fontSize: 10.5, fontWeight: 700, background: 'rgba(245,158,11,0.12)', color: '#f59e0b', border: '1px solid rgba(245,158,11,0.25)' }}><PackageX size={10} /> inventory</span>}
          </div>
          <div style={{ display: 'flex', gap: 16, color: 'var(--text-muted)', fontSize: 12, marginBottom: 10, flexWrap: 'wrap' }}>
            {r.vendor?.company_name && <span>🏷️ {r.vendor.company_name}</span>}
            {r.purchase_order?.po_number && <span>📦 {r.purchase_order.po_number}</span>}
            {r.debit_date && <span>🗓️ {fmtDate(r.debit_date)}</span>}
            <span>↩︎ {(r.items?.length) || 0} line{(r.items?.length) === 1 ? '' : 's'}</span>
          </div>
          {[DN_STATUS.OPEN, DN_STATUS.SETTLED].includes(r.status) && (
            <div style={{ maxWidth: 320, marginBottom: 8 }}><RefundBar refunded={r.amount_refunded} total={r.total} /></div>
          )}
          <div style={{ display: 'flex', gap: 18, alignItems: 'baseline' }}>
            <div style={{ fontSize: 18, fontWeight: 900, color: 'var(--text-h)' }}>{fmtMoney(r.total, r.currency)}</div>
            {Number(r.balance) > 0 && r.status === DN_STATUS.OPEN && <div style={{ fontSize: 12.5, fontWeight: 700, color: '#f59e0b' }}>Claim {fmtMoney(r.balance, r.currency)}</div>}
          </div>
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 6, flexShrink: 0, alignItems: 'stretch' }}>
          {r.status === DN_STATUS.DRAFT && manage && <ActBtn onClick={() => openAction(r, 'issue')} icon={Send} color="#f59e0b" bg="rgba(245,158,11,0.15)">Issue</ActBtn>}
          {canRefund && <ActBtn onClick={() => onRefund(r)} icon={IndianRupee} color="#10b981" bg="rgba(16,185,129,0.15)">Record Refund</ActBtn>}
          {r.status === DN_STATUS.DRAFT && manage && <ActBtn onClick={() => onEdit(r)} icon={Pencil} color="var(--text-muted)" bg="var(--bg-card)" border>Edit</ActBtn>}
          <ActBtn onClick={() => onView(r)} icon={Eye} color="var(--text-muted)" bg="var(--bg-card)" border>View</ActBtn>
          {r.status === DN_STATUS.OPEN && admin && Number(r.amount_refunded) === 0 && <ActBtn onClick={() => openAction(r, 'cancel')} icon={Ban} color="#f87171" bg="var(--bg-card)" border>Cancel</ActBtn>}
          {r.status === DN_STATUS.DRAFT && manage && <ActBtn onClick={() => openAction(r, 'delete')} icon={Trash2} color="#f87171" bg="var(--bg-card)" border>Delete</ActBtn>}
        </div>
      </div>
    </div>
  )
}

function EmptyState({ onCreate }) {
  return (
    <div className="pr-glass" style={{ padding: 60, textAlign: 'center' }}>
      <div style={{ width: 60, height: 60, borderRadius: 18, margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'linear-gradient(145deg,#7C3AED,#5b21b6)', boxShadow: '0 10px 24px -6px rgba(124,58,237,.6)' }}>
        <Undo2 size={26} color="#fff" />
      </div>
      <h3 style={{ color: 'var(--text-h)', fontSize: 16, fontWeight: 800, margin: '0 0 6px' }}>No debit notes yet</h3>
      <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '0 0 18px' }}>Raise a debit note to return goods to a vendor and reclaim their value.</p>
      {onCreate && <button onClick={onCreate} style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '9px 20px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }}><Plus size={15} /> New Debit Note</button>}
    </div>
  )
}

// ── Create / edit modal ──────────────────────────────────────────────────────
function DebitNoteFormModal({ editing, setEditing, saving, manage, onClose, onSave }) {
  const f = editing
  const set = (k) => (e) => setEditing(p => ({ ...p, [k]: e.target.value }))
  const [vendors, setVendors] = useState([])
  const [orders, setOrders]   = useState([])
  const [poLoading, setPoLoading] = useState(false)

  useEffect(() => {
    purchaseApi.vendors.list().then(res => setVendors(Array.isArray(res?.data ?? res) ? (res.data ?? res) : [])).catch(() => {})
    // Only orders that have goods on hand can be returned against.
    purchaseApi.orders.list().then(res => {
      const all = Array.isArray(res?.data ?? res) ? (res.data ?? res) : []
      setOrders(all.filter(o => ['Received', 'Partially_Received', 'Closed'].includes(o.status)))
    }).catch(() => {})
  }, [])

  // Selecting a PO loads its received items as returnable lines (qty defaults to 0,
  // capped at on-hand received_qty). Clearing the PO reverts to a manual line.
  const onSelectPO = async (poId) => {
    setEditing(p => ({ ...p, purchase_order_id: poId }))
    if (!poId) { setEditing(p => ({ ...p, items: [{ ...EMPTY_ITEM }] })); return }
    setPoLoading(true)
    try {
      const po = await purchaseApi.orders.get(poId)
      const d = po?.data ?? po
      const lines = (d.items || []).filter(it => Number(it.received_qty) > 0).map(it => ({
        description: it.description, unit: it.unit || '', rate: it.rate ?? 0, tax: it.tax ?? 0,
        purchase_order_item_id: it.id, on_hand: Number(it.received_qty), qty: 0,
      }))
      setEditing(p => ({ ...p, vendor_id: d.vendor_id ?? p.vendor_id, currency: d.currency || p.currency, items: lines.length ? lines : [{ ...EMPTY_ITEM }] }))
    } catch { /* keep */ }
    finally { setPoLoading(false) }
  }

  const setItem = (i, k, v) => setEditing(p => { const items = [...p.items]; items[i] = { ...items[i], [k]: v }; return { ...p, items } })
  const addItem = () => setEditing(p => ({ ...p, items: [...p.items, { ...EMPTY_ITEM }] }))
  const removeItem = (i) => setEditing(p => ({ ...p, items: p.items.filter((_, idx) => idx !== i) }))
  const t = totalsOf(f.items.filter(it => Number(it.qty) > 0))
  const poMode = !!f.purchase_order_id

  return (
    <Overlay onClose={onClose} width={860}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>{f.id ? 'Edit' : 'New'} Debit Note</h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 18px' }}>Return goods against a purchase order (adjusts inventory) or raise a standalone debit.</p>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 14, marginBottom: 14 }}>
        <Field label="Vendor (must be Active)">
          <SelectInput value={f.vendor_id} onChange={set('vendor_id')} pairs
            options={[['', 'Select vendor…'], ...vendors.map(v => [String(v.id), `${v.company_name}${v.status !== 'Active' ? ` (${v.status_label || v.status})` : ''}`])]} />
        </Field>
        <Field label="Return against PO (optional)">
          <SelectInput value={f.purchase_order_id} onChange={e => onSelectPO(e.target.value)} pairs
            options={[['', 'Standalone (no inventory)'], ...orders.map(o => [String(o.id), `${o.po_number} · ${o.vendor?.company_name || ''}`])]} />
        </Field>
        <Field label="Debit Date"><TextInput type="date" value={f.debit_date} onChange={set('debit_date')} /></Field>
        <Field label="Reason" full><TextInput value={f.reason} onChange={set('reason')} placeholder="e.g. Damaged goods, wrong item, excess delivery" /></Field>
      </div>

      {poMode && (
        <InfoBox>
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}><PackageX size={13} /> Issuing this note will deduct the returned quantities from this order's received stock.</span>
        </InfoBox>
      )}

      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
        <span style={{ width: 22, height: 22, borderRadius: 7, background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontSize: 11, fontWeight: 800, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>↩</span>
        <h3 style={{ margin: 0, fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>{poMode ? 'Returned Items' : 'Debit Lines'}</h3>
        {poLoading && <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>loading order items…</span>}
        <div style={{ flex: 1, height: 1, background: 'var(--border)' }} />
      </div>
      <div className="pr-glass" style={{ padding: 0, marginBottom: 14, borderRadius: 12 }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Description', poMode ? 'On hand' : '', 'Return qty', 'Unit', 'Rate', 'Tax %', 'Amount', ''].filter((h, i) => !(i === 1 && !poMode)).map((h, i) => (
            <th key={h + i} style={{ textAlign: i === 0 ? 'left' : 'right', padding: '9px 10px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', borderBottom: '1px solid var(--border)' }}>{h}</th>
          ))}</tr></thead>
          <tbody>
            {f.items.map((it, i) => (
              <tr key={i} className="pr-li-row">
                <td style={{ padding: '6px 8px' }}><input value={it.description} onChange={e => setItem(i, 'description', e.target.value)} placeholder="Item description" readOnly={poMode} style={{ ...inputStyle, padding: '7px 9px', opacity: poMode ? 0.85 : 1 }} /></td>
                {poMode && <td style={{ padding: '6px 10px', width: 78, textAlign: 'right', fontWeight: 700, color: '#0ea5e9', fontSize: 12.5 }}>{it.on_hand ?? '—'}</td>}
                <td style={{ padding: '6px 8px', width: 84 }}>
                  <input type="number" min="0" max={it.on_hand ?? undefined} step="any" value={it.qty}
                    onChange={e => { let v = Number(e.target.value) || 0; if (it.on_hand != null) v = Math.min(v, it.on_hand); setItem(i, 'qty', v) }}
                    style={{ ...inputStyle, padding: '7px 9px', textAlign: 'right' }} />
                </td>
                <td style={{ padding: '6px 8px', width: 68 }}>{poMode
                  ? <div style={{ padding: '7px 4px', fontSize: 12.5, color: 'var(--text-muted)', textAlign: 'center' }}>{it.unit || '—'}</div>
                  : <input value={it.unit} onChange={e => setItem(i, 'unit', e.target.value)} placeholder="nos" style={{ ...inputStyle, padding: '7px 9px' }} />}</td>
                <td style={{ padding: '6px 8px', width: 96 }}>{poMode
                  ? <div style={{ padding: '7px 9px', fontSize: 12.5, color: 'var(--text-muted)', textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{Number(it.rate).toFixed(2)}</div>
                  : <input type="number" min="0" step="any" value={it.rate} onChange={e => setItem(i, 'rate', e.target.value)} style={{ ...inputStyle, padding: '7px 9px', textAlign: 'right' }} />}</td>
                <td style={{ padding: '6px 8px', width: 68 }}>{poMode
                  ? <div style={{ padding: '7px 9px', fontSize: 12.5, color: 'var(--text-muted)', textAlign: 'right' }}>{Number(it.tax)}%</div>
                  : <input type="number" min="0" max="100" step="any" value={it.tax} onChange={e => setItem(i, 'tax', e.target.value)} style={{ ...inputStyle, padding: '7px 9px', textAlign: 'right' }} />}</td>
                <td style={{ padding: '6px 10px', width: 104, textAlign: 'right', fontWeight: 700, color: 'var(--text-h)', fontSize: 12.5, whiteSpace: 'nowrap' }}>{fmtMoney(lineAmount(it), f.currency)}</td>
                <td style={{ padding: '6px 8px', width: 34, textAlign: 'center' }}>
                  {!poMode && <button onClick={() => removeItem(i)} disabled={f.items.length === 1} title="Remove" style={{ background: 'none', border: 'none', cursor: f.items.length === 1 ? 'not-allowed' : 'pointer', color: '#f87171', opacity: f.items.length === 1 ? 0.3 : 1, padding: 4 }}><Trash size={14} /></button>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {!poMode && (
          <div style={{ padding: '8px 12px', borderTop: '1px solid var(--border)' }}>
            <button onClick={addItem} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '6px 12px', borderRadius: 8, border: '1px dashed var(--border-purple)', background: 'rgba(124,58,237,0.06)', color: '#a78bfa', cursor: 'pointer', fontSize: 12, fontWeight: 700 }}><Plus size={13} /> Add Line</button>
          </div>
        )}
      </div>

      <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 8 }}>
        <div style={{ minWidth: 260, display: 'flex', flexDirection: 'column', gap: 6 }}>
          <TotalRow label="Subtotal" value={fmtMoney(t.subtotal, f.currency)} />
          <TotalRow label="Tax" value={fmtMoney(t.tax, f.currency)} />
          <div style={{ height: 1, background: 'var(--border)', margin: '2px 0' }} />
          <TotalRow label="Debit Total" value={fmtMoney(t.subtotal + t.tax, f.currency)} strong />
        </div>
      </div>

      <div style={{ position: 'sticky', bottom: -28, margin: '4px -28px -28px', padding: '14px 28px', background: 'var(--bg-card, var(--bg-input))', borderTop: '1px solid var(--border)', display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
        <button onClick={onClose} disabled={saving} style={{ padding: '9px 16px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-muted)', fontWeight: 700, fontSize: 13, cursor: 'pointer' }}>Cancel</button>
        <button onClick={() => onSave('draft')} disabled={saving} style={{ padding: '9px 16px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontWeight: 700, fontSize: 13, cursor: 'pointer' }}>Save Draft</button>
        {manage && <button onClick={() => onSave('issue')} disabled={saving} style={{ padding: '9px 18px', borderRadius: 10, border: 'none', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontWeight: 800, fontSize: 13, cursor: 'pointer', opacity: saving ? 0.7 : 1 }}>{saving ? 'Saving…' : 'Save & Issue'}</button>}
      </div>
    </Overlay>
  )
}

// ── Refund modal ─────────────────────────────────────────────────────────────
function RefundModal({ dn, onClose, onDone }) {
  const balance = Number(dn.balance || 0)
  const [f, setF] = useState({ amount: balance, refund_date: new Date().toISOString().slice(0, 10), refund_mode: 'Bank_Transfer', reference: '', notes: '' })
  const [saving, setSaving] = useState(false)
  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))

  const submit = async () => {
    const amt = Number(f.amount) || 0
    if (amt <= 0) { alert('Enter a refund amount.'); return }
    if (amt > balance + 0.001) { alert(`Amount can't exceed the claim balance (${fmtMoney(balance, dn.currency)}).`); return }
    setSaving(true)
    try { await purchaseApi.debitNotes.addRefund(dn.id, { ...f, amount: amt }); onDone(dn.id) }
    catch (e) { alert(e?.response?.data?.message || 'Failed to record refund') }
    finally { setSaving(false) }
  }

  return (
    <Overlay onClose={() => !saving && onClose()} width={520}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>Record Vendor Refund · {dn.debit_number}</h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 16px' }}>Outstanding claim: <strong style={{ color: '#f59e0b' }}>{fmtMoney(balance, dn.currency)}</strong></p>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>
        <Field label="Amount *"><TextInput type="number" min="0" max={balance} step="any" value={f.amount} onChange={e => setF(p => ({ ...p, amount: Math.min(Number(e.target.value) || 0, balance) }))} /></Field>
        <Field label="Refund Date"><TextInput type="date" value={f.refund_date} onChange={set('refund_date')} /></Field>
        <Field label="Refund Mode"><SelectInput value={f.refund_mode} onChange={set('refund_mode')} options={PAYMENT_MODES} pairs /></Field>
        <Field label="Reference #"><TextInput value={f.reference} onChange={set('reference')} placeholder="Txn / cheque number" /></Field>
        <Field label="Notes" full><TextInput value={f.notes} onChange={set('notes')} placeholder="Optional" /></Field>
      </div>
      <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
        <button onClick={() => setF(p => ({ ...p, amount: balance }))} style={{ fontSize: 11.5, fontWeight: 700, padding: '5px 12px', borderRadius: 8, border: '1px solid var(--border-purple)', background: 'rgba(124,58,237,0.06)', color: '#a78bfa', cursor: 'pointer' }}>Refund full balance</button>
      </div>
      <ModalFooter onClose={onClose} onConfirm={submit} loading={saving} disabled={Number(f.amount) <= 0} confirmLabel="Record Refund" color="#10b981" />
    </Overlay>
  )
}

// ── Apply-credit modal ───────────────────────────────────────────────────────
// Nets the debit note's open balance against a same-vendor payable invoice. The
// invoice list comes from the server (already vendor/status/balance filtered),
// so the user can only pick a valid target.
function ApplyCreditModal({ dn, onClose, onDone }) {
  const balance = Number(dn.balance || 0)
  const [invoices, setInvoices] = useState(null)
  const [invoiceId, setInvoiceId] = useState('')
  const [amount, setAmount] = useState(0)
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)

  useEffect(() => {
    purchaseApi.debitNotes.applicableInvoices(dn.id)
      .then(r => setInvoices(r?.data ?? r ?? []))
      .catch(() => setInvoices([]))
  }, [dn.id])

  const selected = (invoices || []).find(i => String(i.id) === String(invoiceId))
  // The most you can net is the smaller of the two open balances.
  const cap = selected ? Math.min(balance, Number(selected.balance)) : balance

  // Default the amount to the cap whenever the chosen invoice changes.
  useEffect(() => { if (selected) setAmount(Math.min(balance, Number(selected.balance))) }, [invoiceId]) // eslint-disable-line

  const submit = async () => {
    const amt = Number(amount) || 0
    if (!invoiceId) { setErr('Choose an invoice to apply the credit to.'); return }
    if (amt <= 0) { setErr('Enter a credit amount.'); return }
    if (amt > cap + 0.001) { setErr(`Amount can't exceed ${fmtMoney(cap, dn.currency)}.`); return }
    setSaving(true); setErr(null)
    try {
      await purchaseApi.debitNotes.applyCredit(dn.id, { purchase_invoice_id: Number(invoiceId), amount: amt })
      onDone(dn.id)
    } catch (e) { setErr(e?.response?.data?.message || 'Failed to apply credit'); setSaving(false) }
  }

  return (
    <Overlay onClose={() => !saving && onClose()} width={540}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 9, marginBottom: 4 }}>
        <GitMerge size={18} style={{ color: '#a78bfa' }} />
        <h2 style={{ color: 'var(--text-h)', margin: 0, fontSize: 18, fontWeight: 800 }}>Apply Credit · {dn.debit_number}</h2>
      </div>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 16px' }}>
        Net this note's open claim against an unpaid invoice from <strong style={{ color: 'var(--text-h)' }}>{dn.vendor?.company_name}</strong>. Available: <strong style={{ color: '#a78bfa' }}>{fmtMoney(balance, dn.currency)}</strong>
      </p>

      {invoices === null ? (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--text-muted)', fontSize: 13, padding: '10px 0' }}>
          <Loader2 size={15} className="dn-spin" /> Finding open invoices…
        </div>
      ) : invoices.length === 0 ? (
        <InfoBox tone="warn">This vendor has no open invoices to apply the credit against. Record a cash refund instead, or leave the claim open.</InfoBox>
      ) : (
        <>
          <Field label="Apply to invoice *">
            <SelectInput value={invoiceId} onChange={e => setInvoiceId(e.target.value)} pairs
              options={[['', 'Select an invoice…'],
                ...invoices.map(i => [i.id, `${i.invoice_number} · balance ${fmtMoney(i.balance, dn.currency)}`])]} />
          </Field>
          {selected && (
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14, marginTop: 4 }}>
              <Field label="Credit amount *">
                <TextInput type="number" min="0" max={cap} step="any" value={amount}
                  onChange={e => setAmount(Math.min(Number(e.target.value) || 0, cap))} />
              </Field>
              <div style={{ display: 'flex', flexDirection: 'column', justifyContent: 'flex-end', paddingBottom: 2 }}>
                <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>Invoice balance</span>
                <span style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>{fmtMoney(selected.balance, dn.currency)}</span>
              </div>
            </div>
          )}
          {selected && (
            <div style={{ display: 'flex', gap: 8, marginTop: 10, flexWrap: 'wrap' }}>
              <button onClick={() => setAmount(cap)} style={{ fontSize: 11.5, fontWeight: 700, padding: '5px 12px', borderRadius: 8, border: '1px solid var(--border-purple)', background: 'rgba(124,58,237,0.06)', color: '#a78bfa', cursor: 'pointer' }}>
                Apply max ({fmtMoney(cap, dn.currency)})
              </button>
              {/* Make the outcome explicit before they confirm. */}
              <span style={{ fontSize: 11.5, color: 'var(--text-muted)', alignSelf: 'center' }}>
                Leaves invoice balance {fmtMoney(Math.max(0, Number(selected.balance) - (Number(amount) || 0)), dn.currency)}, claim {fmtMoney(Math.max(0, balance - (Number(amount) || 0)), dn.currency)}
              </span>
            </div>
          )}
        </>
      )}

      {err && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 12px', borderRadius: 10, marginTop: 12, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)' }}>
          <XCircle size={14} style={{ color: '#ef4444', flexShrink: 0 }} />
          <span style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{err}</span>
        </div>
      )}
      <ModalFooter onClose={onClose} onConfirm={submit} loading={saving}
        disabled={!invoiceId || Number(amount) <= 0 || (invoices || []).length === 0}
        confirmLabel="Apply Credit" color="#7C3AED" />
      <style>{`@keyframes dnSpin{to{transform:rotate(360deg)}}.dn-spin{animation:dnSpin .9s linear infinite}`}</style>
    </Overlay>
  )
}

// ── Action modal ─────────────────────────────────────────────────────────────
function ActionModal({ actionModal, remarks, setRemarks, loading, onClose, onConfirm }) {
  const { action, dn } = actionModal
  const meta = {
    issue:  { title: 'Issue Debit Note',  color: '#f59e0b' },
    cancel: { title: 'Cancel Debit Note', color: '#ef4444' },
    delete: { title: 'Delete Debit Note', color: '#ef4444' },
  }[action]
  return (
    <Overlay onClose={() => !loading && onClose()} width={460}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14 }}>
        {action === 'issue' ? <CheckCircle size={22} color={meta.color} /> : <XCircle size={22} color={meta.color} />}
        <h3 style={{ color: 'var(--text-h)', margin: 0, fontSize: 16, fontWeight: 800 }}>{meta.title}</h3>
      </div>
      <p style={{ color: 'var(--text-muted)', fontSize: 13, marginBottom: 14 }}>
        <strong style={{ color: 'var(--text-h)' }}>{dn.debit_number}</strong>{dn.vendor?.company_name ? ` — ${dn.vendor.company_name}` : ''} · {fmtMoney(dn.total, dn.currency)}
      </p>
      {action === 'issue' && dn.purchase_order_id && dn.adjust_inventory && <InfoBox>Issuing deducts the returned quantities from the linked order's received stock and opens a claim on the vendor.</InfoBox>}
      {action === 'issue' && !(dn.purchase_order_id && dn.adjust_inventory) && <InfoBox>Issuing opens a claim on the vendor for the debit amount.</InfoBox>}
      {action === 'cancel' && <InfoBox tone="danger">Cancelling restores any returned inventory to the order and voids the claim.</InfoBox>}
      {action === 'delete' && <InfoBox tone="danger">This permanently deletes the draft debit note. This cannot be undone.</InfoBox>}
      {action === 'cancel' && (
        <>
          <label style={labelStyle}>Remarks (optional)</label>
          <textarea value={remarks} onChange={e => setRemarks(e.target.value)} rows={3} placeholder="Add remarks…" style={{ ...inputStyle, resize: 'vertical' }} />
        </>
      )}
      <ModalFooter onClose={onClose} onConfirm={onConfirm} loading={loading} confirmLabel="Confirm" color={meta.color} />
    </Overlay>
  )
}

// ── Detail modal ─────────────────────────────────────────────────────────────
function DetailModal({ dn, admin, onClose, onRefund, onApply, onReverseRefund, onReverseCredit }) {
  const items = dn.items || []
  const refunds = dn.refunds || []
  const applications = dn.credit_applications || []
  const hasBalance = dn.status === DN_STATUS.OPEN && Number(dn.balance) > 0
  const canRefund = admin && hasBalance
  const canApply = admin && hasBalance && dn.vendor_id
  const info = [
    ['Vendor', dn.vendor?.company_name], ['Against PO', dn.purchase_order?.po_number],
    ['Debit Date', dn.debit_date && fmtDate(dn.debit_date)], ['Reason', dn.reason],
    ['Created By', dn.creator?.name], ['Inventory Adjusted', dn.purchase_order_id ? (dn.adjust_inventory ? 'Yes' : 'No') : '—'],
  ].filter(([, v]) => v)

  return (
    <Overlay onClose={onClose} width={760}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6, flexWrap: 'wrap' }}>
        <h3 style={{ color: 'var(--text-h)', margin: 0, fontSize: 17, fontWeight: 800 }}>{dn.debit_number}</h3>
        <StatusBadge status={dn.status} />
        <div style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
          {canApply && <button onClick={() => onApply(dn)} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 14px', borderRadius: 9, border: 'none', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontWeight: 700, fontSize: 12.5, cursor: 'pointer' }}><GitMerge size={14} /> Apply to Invoice</button>}
          {canRefund && <button onClick={() => onRefund(dn)} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 14px', borderRadius: 9, border: 'none', background: 'linear-gradient(135deg,#10b981,#059669)', color: '#fff', fontWeight: 700, fontSize: 12.5, cursor: 'pointer' }}><IndianRupee size={14} /> Record Refund</button>}
        </div>
      </div>
      {[DN_STATUS.OPEN, DN_STATUS.SETTLED].includes(dn.status) && (
        <div style={{ margin: '10px 0 16px', maxWidth: 360 }}><RefundBar refunded={dn.amount_refunded} total={dn.total} /></div>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px 20px', marginBottom: 16 }}>
        {info.map(([k, v]) => (
          <div key={k} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, borderBottom: '1px solid var(--border)', paddingBottom: 6 }}>
            <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>{k}</span>
            <span style={{ color: 'var(--text-h)', fontSize: 12, fontWeight: 600, textAlign: 'right' }}>{v}</span>
          </div>
        ))}
      </div>

      <label style={labelStyle}>Returned Items</label>
      <div className="pr-glass" style={{ padding: 0, marginBottom: 14, borderRadius: 12 }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Description', 'Qty', 'Rate', 'Tax %', 'Amount'].map((h, i) => (
            <th key={h} style={{ textAlign: i === 0 ? 'left' : 'right', padding: '8px 10px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', borderBottom: '1px solid var(--border)' }}>{h}</th>
          ))}</tr></thead>
          <tbody>
            {items.map((it, i) => (
              <tr key={i}>
                <td style={{ padding: '8px 10px', fontSize: 12.5, color: 'var(--text-h)' }}>{it.description}{it.unit ? <span style={{ color: 'var(--text-muted)' }}> · {it.unit}</span> : ''}</td>
                <td style={{ padding: '8px 10px', fontSize: 12.5, textAlign: 'right', color: 'var(--text-muted)' }}>{it.qty}</td>
                <td style={{ padding: '8px 10px', fontSize: 12.5, textAlign: 'right', color: 'var(--text-muted)' }}>{fmtMoney(it.rate, dn.currency)}</td>
                <td style={{ padding: '8px 10px', fontSize: 12.5, textAlign: 'right', color: 'var(--text-muted)' }}>{it.tax}%</td>
                <td style={{ padding: '8px 10px', fontSize: 12.5, textAlign: 'right', fontWeight: 700, color: 'var(--text-h)' }}>{fmtMoney(it.amount ?? lineAmount(it), dn.currency)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 16 }}>
        <div style={{ minWidth: 240, display: 'flex', flexDirection: 'column', gap: 6 }}>
          <TotalRow label="Debit Total" value={fmtMoney(dn.total, dn.currency)} strong />
          <TotalRow label="Refunded (cash)" value={fmtMoney(dn.amount_refunded, dn.currency)} />
          <TotalRow label="Applied (credit)" value={fmtMoney(dn.amount_applied, dn.currency)} />
          <div style={{ height: 1, background: 'var(--border)', margin: '2px 0' }} />
          <TotalRow label="Open Claim" value={fmtMoney(dn.balance, dn.currency)} strong />
        </div>
      </div>

      <label style={labelStyle}>Vendor Refunds ({refunds.length})</label>
      {refunds.length === 0 ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '0 0 14px' }}>No refunds recorded yet.</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginBottom: 14 }}>
          {refunds.map(r => (
            <div key={r.id} className="pr-glass" style={{ padding: '10px 14px', display: 'flex', alignItems: 'center', gap: 12, borderRadius: 10 }}>
              <span style={{ fontWeight: 800, fontSize: 13.5, color: '#10b981' }}>{fmtMoney(r.amount, dn.currency)}</span>
              <span style={{ fontSize: 11.5, fontWeight: 700, padding: '2px 8px', borderRadius: 8, background: 'rgba(124,58,237,0.1)', color: '#a78bfa' }}>{r.refund_mode_label || paymentModeLabel(r.refund_mode)}</span>
              <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>{fmtDate(r.refund_date)}</span>
              {r.reference && <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>Ref: {r.reference}</span>}
              {admin && (
                <button onClick={() => { if (confirm('Reverse this refund?')) onReverseRefund(r.id) }} title="Reverse refund"
                  style={{ marginLeft: 'auto', display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11, fontWeight: 700, padding: '4px 9px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--bg-card)', color: '#f87171', cursor: 'pointer' }}>
                  <RotateCcw size={11} /> Reverse
                </button>
              )}
            </div>
          ))}
        </div>
      )}

      <label style={labelStyle}>Credits Applied to Invoices ({applications.length})</label>
      {applications.length === 0 ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '0 0 14px' }}>No credit netted against invoices yet.</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginBottom: 14 }}>
          {applications.map(a => (
            <div key={a.id} className="pr-glass" style={{ padding: '10px 14px', display: 'flex', alignItems: 'center', gap: 12, borderRadius: 10 }}>
              <GitMerge size={14} style={{ color: '#a78bfa', flexShrink: 0 }} />
              <span style={{ fontWeight: 800, fontSize: 13.5, color: '#a78bfa' }}>{fmtMoney(a.amount, dn.currency)}</span>
              <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>→ Invoice <strong style={{ color: 'var(--text-h)' }}>{a.invoice?.invoice_number || '—'}</strong></span>
              <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>{fmtDate(a.applied_date)}</span>
              {admin && (
                <button onClick={() => { if (confirm('Reverse this applied credit? Both balances will be restored.')) onReverseCredit(a.id) }} title="Reverse credit"
                  style={{ marginLeft: 'auto', display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11, fontWeight: 700, padding: '4px 9px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--bg-card)', color: '#f87171', cursor: 'pointer' }}>
                  <RotateCcw size={11} /> Reverse
                </button>
              )}
            </div>
          ))}
        </div>
      )}

      <label style={labelStyle}>Audit Trail</label>
      {dn.audit_logs === undefined
        ? <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>Loading timeline…</p>
        : <AuditTimeline entries={dn.audit_logs} />}

      <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 18 }}>
        <button onClick={onClose} style={{ padding: '9px 20px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>Close</button>
      </div>
    </Overlay>
  )
}

// ── List view ────────────────────────────────────────────────────────────────
const DN_PAGE = 12
function DNListView({ rows, onView, onEdit, admin, manage, openAction, onRefund, highlightId }) {
  const [page, setPage] = useState(1)
  const pages = Math.max(1, Math.ceil(rows.length / DN_PAGE))
  const pageRows = useMemo(() => rows.slice((page - 1) * DN_PAGE, page * DN_PAGE), [rows, page])
  useEffect(() => { if (page > pages) setPage(1) }, [pages, page])
  const th = { textAlign: 'left', padding: '10px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
  const td = { padding: '11px 12px', borderBottom: '1px solid var(--border)', fontSize: 12.5, verticalAlign: 'middle', whiteSpace: 'nowrap' }

  return (
    <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
      <div style={{ overflowX: 'auto', maxHeight: '62vh', overflowY: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead style={{ position: 'sticky', top: 0, zIndex: 1, background: 'var(--bg-card, var(--bg-input))' }}>
            <tr>{['Debit #', 'Vendor', 'Against PO', 'Total', 'Refunded', 'Claim', 'Status'].map(h => <th key={h} style={th}>{h}</th>)}<th style={{ ...th, textAlign: 'right' }}>Actions</th></tr>
          </thead>
          <tbody>
            {pageRows.map(r => {
              const cfg = dnStatusCfg(r.status)
              const canRefund = admin && r.status === DN_STATUS.OPEN && Number(r.balance) > 0
              return (
                <tr key={r.id} style={{ cursor: 'pointer', background: r.id === highlightId ? 'rgba(124,58,237,0.08)' : 'transparent' }} onClick={() => onView(r)}
                  onMouseEnter={e => { if (r.id !== highlightId) e.currentTarget.style.background = 'var(--bg-input)' }}
                  onMouseLeave={e => { if (r.id !== highlightId) e.currentTarget.style.background = 'transparent' }}>
                  <td style={{ ...td, fontWeight: 700, color: '#a78bfa' }}>{r.debit_number}</td>
                  <td style={{ ...td, color: 'var(--text-h)', fontWeight: 600 }}>{r.vendor?.company_name || '—'}</td>
                  <td style={{ ...td, color: 'var(--text-muted)' }}>{r.purchase_order?.po_number || '—'}</td>
                  <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)' }}>{fmtMoney(r.total, r.currency)}</td>
                  <td style={{ ...td, color: '#10b981', fontWeight: 600 }}>{fmtMoney(r.amount_refunded, r.currency)}</td>
                  <td style={{ ...td, color: Number(r.balance) > 0 && r.status === DN_STATUS.OPEN ? '#f59e0b' : 'var(--text-muted)', fontWeight: 700 }}>{fmtMoney(r.balance, r.currency)}</td>
                  <td style={td}><span style={{ fontSize: 10.5, fontWeight: 700, padding: '2px 8px', borderRadius: 8, background: cfg.bg, color: cfg.color, whiteSpace: 'nowrap' }}>{cfg.label}</span></td>
                  <td style={{ ...td, textAlign: 'right' }} onClick={e => e.stopPropagation()}>
                    <div style={{ display: 'inline-flex', gap: 6, justifyContent: 'flex-end' }}>
                      {r.status === DN_STATUS.DRAFT && manage && <button onClick={() => openAction(r, 'issue')} style={{ fontSize: 11, fontWeight: 700, padding: '4px 9px', borderRadius: 7, border: 'none', background: 'rgba(245,158,11,0.15)', color: '#f59e0b', cursor: 'pointer' }}>Issue</button>}
                      {canRefund && <button onClick={() => onRefund(r)} style={{ fontSize: 11, fontWeight: 700, padding: '4px 9px', borderRadius: 7, border: 'none', background: 'rgba(16,185,129,0.15)', color: '#10b981', cursor: 'pointer' }}>Refund</button>}
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
        <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{rows.length} debit note{rows.length === 1 ? '' : 's'} · page {page} of {pages}</span>
        <div style={{ display: 'flex', gap: 6 }}>
          <button onClick={() => setPage(p => Math.max(1, p - 1))} disabled={page === 1} style={{ padding: '5px 9px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: page === 1 ? 'not-allowed' : 'pointer', opacity: page === 1 ? 0.5 : 1 }}><ChevronLeft size={14} /></button>
          <button onClick={() => setPage(p => Math.min(pages, p + 1))} disabled={page === pages} style={{ padding: '5px 9px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: page === pages ? 'not-allowed' : 'pointer', opacity: page === pages ? 0.5 : 1 }}><ChevronRight size={14} /></button>
        </div>
      </div>
    </div>
  )
}
