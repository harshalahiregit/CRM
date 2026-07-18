import { useState, useEffect, useCallback, useMemo } from 'react'
import { useLocation } from 'react-router-dom'
import {
  Plus, RefreshCw, Search, Eye, Pencil, Trash2, Ban, CheckCircle, XCircle,
  FileText, ShieldCheck, Wallet, BadgeCheck, LayoutGrid, List, Trash, IndianRupee,
  ChevronLeft, ChevronRight, AlertTriangle, RotateCcw,
} from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { useAuth } from '@/context/AuthContext'
import AuditTimeline from '@/components/ui/AuditTimeline'
import {
  PINV_STATUS, PINV_STATUS_CONFIG, pinvStatusCfg, PINV_STAGES, PAYMENT_MODES, paymentModeLabel,
  canApprovePR as isAdmin, canManagePR as isStaffOrAdmin, matchCfg,
  fmtMoney, fmtMoneyShort, fmtDate, lineAmount, totalsOf,
} from '../constants'
import {
  KIT3D_STYLE as PURCHASE_STYLE, labelStyle, inputStyle, Overlay, ModalFooter, InfoBox,
  Field, TextInput, SelectInput, TotalRow, ActBtn, StatusBadge as StatusPill,
} from '@/components/ui/kit3d'

const EMPTY_ITEM = { description: '', qty: 1, unit: '', rate: 0, tax: 0 }
const EMPTY_FORM = {
  title: '', vendor_id: '', vendor_invoice_ref: '', currency: 'INR',
  invoice_date: new Date().toISOString().slice(0, 10), due_date: '', terms: '', notes: '',
  items: [{ ...EMPTY_ITEM }],
}

const StatusBadge = ({ status }) => <StatusPill cfg={pinvStatusCfg(status)} />

// ── Pipeline ─────────────────────────────────────────────────────────────────
function Pipeline({ stats = {}, active, onStage }) {
  const count = { draft: stats.draft || 0, awaiting: stats.awaiting || 0, partial: stats.partial || 0, paid: stats.paid || 0 }
  const COLORS = { draft: '#94a3b8', awaiting: '#0ea5e9', partial: '#f59e0b', paid: '#10b981' }
  const ICONS = { draft: FileText, awaiting: ShieldCheck, partial: Wallet, paid: BadgeCheck }
  return (
    <div style={{ display: 'flex', alignItems: 'center', width: 'max-content', minWidth: '100%', gap: 0 }}>
      {PINV_STAGES.map((s, i) => {
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
            {i < PINV_STAGES.length - 1 && <div className={`pr-flow${lit ? '' : ' pr-flow-dim'}`} style={{ width: 26, height: 3, borderRadius: 4, margin: '0 4px', flexShrink: 0, background: `linear-gradient(90deg, ${color}, ${COLORS[PINV_STAGES[i + 1].key]})` }} />}
          </div>
        )
      })}
    </div>
  )
}

// Paid progress bar
const PayBar = ({ paid, total }) => {
  const pct = total > 0 ? Math.round(Math.min(100, (Number(paid) / Number(total)) * 100)) : 0
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
      <div className="pr-bar" style={{ flex: 1 }}><span style={{ width: `${pct}%` }} /></div>
      <span style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', minWidth: 34, textAlign: 'right' }}>{pct}%</span>
    </div>
  )
}

// ── Main page ────────────────────────────────────────────────────────────────
export default function PurchaseInvoices() {
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
  const [viewMode, setViewMode] = useState(() => localStorage.getItem('pur_inv_view') || 'card')
  const changeViewMode = (v) => { setViewMode(v); localStorage.setItem('pur_inv_view', v) }

  const [showForm, setShowForm]   = useState(false)
  const [editing, setEditing]     = useState(null)
  const [saving, setSaving]       = useState(false)

  const [actionModal, setActionModal] = useState(null)   // { invoice, action }
  const [remarks, setRemarks]         = useState('')
  const [actionLoading, setActionLoading] = useState(false)

  const [detail, setDetail]   = useState(null)
  const [paying, setPaying]   = useState(null)           // invoice being paid

  const fetchAll = useCallback(async () => {
    setLoading(true)
    try {
      const [listRes, statRes] = await Promise.all([purchaseApi.invoices.list(), purchaseApi.invoices.stats()])
      setRows(Array.isArray(listRes?.data ?? listRes) ? (listRes.data ?? listRes) : [])
      setStats(statRes?.data ?? statRes ?? {})
    } catch (e) { console.error('Failed to load purchase invoices', e) }
    finally { setLoading(false) }
  }, [])
  useEffect(() => { fetchAll() }, [fetchAll])

  const filtered = rows.filter(r => {
    const q = search.toLowerCase()
    const matchSearch = !q || r.invoice_number?.toLowerCase().includes(q) || r.vendor_invoice_ref?.toLowerCase().includes(q) || r.title?.toLowerCase().includes(q) || r.vendor?.company_name?.toLowerCase().includes(q)
    const matchStatus = filterStatus === 'All'
      || r.status === filterStatus
      || (filterStatus === 'overdue' && r.is_overdue)
      || !!PINV_STAGES.find(s => s.key === filterStatus)?.statuses.includes(r.status)
    return matchSearch && matchStatus
  })

  // ── Create / edit ──────────────────────────────────────────────────────────
  const openCreate = () => { setEditing({ ...EMPTY_FORM }); setShowForm(true) }
  const openEdit = (r) => {
    setEditing({
      id: r.id, title: r.title || '', vendor_id: r.vendor_id ?? '', vendor_invoice_ref: r.vendor_invoice_ref || '',
      currency: r.currency || 'INR', invoice_date: r.invoice_date?.slice(0, 10) || '', due_date: r.due_date?.slice(0, 10) || '',
      terms: r.terms || '', notes: r.notes || '',
      items: (r.items?.length ? r.items : [{ ...EMPTY_ITEM }]).map(it => ({ description: it.description || '', qty: it.qty ?? 1, unit: it.unit || '', rate: it.rate ?? 0, tax: it.tax ?? 0 })),
    })
    setShowForm(true)
  }

  const handleSave = async (mode = 'draft') => {
    const f = editing
    const items = f.items.filter(it => it.description?.trim())
    if (items.length === 0) { alert('Add at least one line item.'); return }
    setSaving(true)
    try {
      const payload = {
        title: f.title || null, vendor_id: f.vendor_id || null, vendor_invoice_ref: f.vendor_invoice_ref || null, currency: f.currency,
        invoice_date: f.invoice_date || null, due_date: f.due_date || null, terms: f.terms || null, notes: f.notes || null,
        items: items.map((it, i) => ({ description: it.description, qty: Number(it.qty) || 1, unit: it.unit || null, rate: Number(it.rate) || 0, tax: Number(it.tax) || 0, sort_order: i })),
      }
      let saved
      if (f.id) saved = await purchaseApi.invoices.update(f.id, payload)
      else saved = await purchaseApi.invoices.create(payload)
      const id = f.id || saved?.id || saved?.data?.id
      if (mode === 'approve' && id) await purchaseApi.invoices.approve(id)   // admin-only; surfaces 403 otherwise
      setShowForm(false); setEditing(null); fetchAll()
    } catch (e) { alert(e?.response?.data?.message || 'Failed to save invoice') }
    finally { setSaving(false) }
  }

  // ── Actions ─────────────────────────────────────────────────────────────────
  const openAction = (invoice, action) => { setActionModal({ invoice, action }); setRemarks('') }
  const runAction = async () => {
    if (!actionModal) return
    const { invoice, action } = actionModal
    setActionLoading(true)
    try {
      const id = invoice.id
      if (action === 'approve')     await purchaseApi.invoices.approve(id)
      else if (action === 'cancel') await purchaseApi.invoices.cancel(id, remarks)
      else if (action === 'delete') await purchaseApi.invoices.delete(id)
      setActionModal(null); setRemarks(''); fetchAll()
    } catch (e) { alert(e?.response?.data?.message || 'Action failed') }
    finally { setActionLoading(false) }
  }

  const openDetail = async (r) => {
    setDetail(r)
    try { const full = await purchaseApi.invoices.get(r.id); if ((full?.data ?? full)?.id) setDetail(full.data ?? full) } catch { /* keep */ }
  }
  const refreshDetail = async (id) => {
    try { const full = await purchaseApi.invoices.get(id); setDetail(full?.data ?? full) } catch { /* noop */ }
    fetchAll()
  }

  const statCards = [
    { label: 'Total',       value: stats.total,       color: '#7C3AED', filter: 'All' },
    { label: 'Awaiting',    value: stats.awaiting,     color: '#0ea5e9', filter: PINV_STATUS.AWAITING_PAYMENT },
    { label: 'Partial',     value: stats.partial,      color: '#f59e0b', filter: PINV_STATUS.PARTIALLY_PAID },
    { label: 'Paid',        value: stats.paid,         color: '#10b981', filter: PINV_STATUS.PAID },
    { label: 'Overdue',     value: stats.overdue,      color: '#ef4444', filter: 'overdue' },
    { label: 'Outstanding', value: fmtMoneyShort(stats.outstanding), color: '#6366f1', filter: 'All', wide: true },
  ]

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{PURCHASE_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 800, margin: 0 }}>Purchase Invoices</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Record vendor bills, approve payables and track payments to settlement.</p>
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
              <Plus size={15} /> New Invoice
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
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search invoice #, vendor bill ref, title or vendor…" style={{ ...inputStyle, paddingLeft: 32 }} />
        </div>
        <select value={filterStatus} onChange={e => setFilterStatus(e.target.value)} style={{ ...inputStyle, width: 'auto', cursor: 'pointer' }}>
          <option value="All">All Status</option>
          {Object.values(PINV_STATUS).map(s => <option key={s} value={s}>{PINV_STATUS_CONFIG[s]?.label || s}</option>)}
          <option value="overdue">Overdue</option>
        </select>
      </div>

      {loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}>Loading invoices…</div>
      ) : filtered.length === 0 ? (
        <EmptyState onCreate={manage ? openCreate : null} />
      ) : viewMode === 'list' ? (
        <InvListView rows={filtered} onView={openDetail} onEdit={openEdit} admin={admin} manage={manage} openAction={openAction} onPay={setPaying} highlightId={highlightId} />
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          {filtered.map(r => (
            <InvCard key={r.id} r={r} admin={admin} manage={manage} highlight={r.id === highlightId}
              onView={openDetail} onEdit={openEdit} openAction={openAction} onPay={setPaying} />
          ))}
        </div>
      )}

      {showForm && <InvoiceFormModal editing={editing} setEditing={setEditing} saving={saving} admin={admin} onClose={() => setShowForm(false)} onSave={handleSave} />}
      {actionModal && <ActionModal actionModal={actionModal} remarks={remarks} setRemarks={setRemarks} loading={actionLoading} onClose={() => setActionModal(null)} onConfirm={runAction} />}
      {detail && <DetailModal invoice={detail} admin={admin} onClose={() => setDetail(null)} onPay={setPaying} onReversePayment={async (pid) => { try { await purchaseApi.invoices.deletePayment(detail.id, pid); refreshDetail(detail.id) } catch (e) { alert(e?.response?.data?.message || 'Failed to reverse') } }} />}
      {paying && <PaymentModal invoice={paying} onClose={() => setPaying(null)} onDone={(id) => { setPaying(null); if (detail?.id === id) refreshDetail(id); else fetchAll() }} />}
    </div>
  )
}

// ── Card ─────────────────────────────────────────────────────────────────────
function InvCard({ r, admin, manage, highlight, onView, onEdit, openAction, onPay }) {
  const canPay = admin && [PINV_STATUS.AWAITING_PAYMENT, PINV_STATUS.PARTIALLY_PAID].includes(r.status)
  return (
    <div className="pr-glass pr-lift pr-pop" style={{ padding: 20, outline: highlight ? '2px solid #7C3AED' : r.is_overdue ? '1.5px solid rgba(239,68,68,0.5)' : 'none' }}>
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16 }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8, flexWrap: 'wrap' }}>
            <span style={{ color: '#a78bfa', fontWeight: 800, fontSize: 12 }}>{r.invoice_number}</span>
            {r.title && <span style={{ color: 'var(--text-h)', fontWeight: 700, fontSize: 15 }}>{r.title}</span>}
            <StatusBadge status={r.status} />
            {r.is_overdue && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3, padding: '2px 8px', borderRadius: 10, fontSize: 10.5, fontWeight: 700, background: 'rgba(239,68,68,0.12)', color: '#ef4444', border: '1px solid rgba(239,68,68,0.3)' }}><AlertTriangle size={10} /> Overdue</span>}
            {r.purchase_order_id && <span style={{ padding: '2px 8px', borderRadius: 10, fontSize: 10.5, fontWeight: 700, background: 'rgba(99,102,241,0.12)', color: '#818cf8', border: '1px solid rgba(99,102,241,0.25)' }}>from PO</span>}
          </div>
          <div style={{ display: 'flex', gap: 16, color: 'var(--text-muted)', fontSize: 12, marginBottom: 10, flexWrap: 'wrap' }}>
            {r.vendor?.company_name && <span>🏷️ {r.vendor.company_name}</span>}
            {r.vendor_invoice_ref && <span>📄 {r.vendor_invoice_ref}</span>}
            {r.invoice_date && <span>🗓️ {fmtDate(r.invoice_date)}</span>}
            {r.due_date && <span style={{ color: r.is_overdue ? '#ef4444' : undefined }}>⏰ Due {fmtDate(r.due_date)}</span>}
          </div>
          {[PINV_STATUS.AWAITING_PAYMENT, PINV_STATUS.PARTIALLY_PAID, PINV_STATUS.PAID].includes(r.status) && (
            <div style={{ maxWidth: 320, marginBottom: 8 }}><PayBar paid={r.amount_paid} total={r.total} /></div>
          )}
          <div style={{ display: 'flex', gap: 18, alignItems: 'baseline' }}>
            <div style={{ fontSize: 18, fontWeight: 900, color: 'var(--text-h)' }}>{fmtMoney(r.total, r.currency)}</div>
            {Number(r.balance) > 0 && <div style={{ fontSize: 12.5, fontWeight: 700, color: '#f59e0b' }}>Balance {fmtMoney(r.balance, r.currency)}</div>}
          </div>
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 6, flexShrink: 0, alignItems: 'stretch' }}>
          {r.status === PINV_STATUS.DRAFT && admin && <ActBtn onClick={() => openAction(r, 'approve')} icon={ShieldCheck} color="#0ea5e9" bg="rgba(14,165,233,0.15)">Approve</ActBtn>}
          {canPay && <ActBtn onClick={() => onPay(r)} icon={IndianRupee} color="#10b981" bg="rgba(16,185,129,0.15)">Record Payment</ActBtn>}
          {r.status === PINV_STATUS.DRAFT && manage && <ActBtn onClick={() => onEdit(r)} icon={Pencil} color="var(--text-muted)" bg="var(--bg-card)" border>Edit</ActBtn>}
          <ActBtn onClick={() => onView(r)} icon={Eye} color="var(--text-muted)" bg="var(--bg-card)" border>View</ActBtn>
          {[PINV_STATUS.AWAITING_PAYMENT].includes(r.status) && admin && Number(r.amount_paid) === 0 && <ActBtn onClick={() => openAction(r, 'cancel')} icon={Ban} color="#f87171" bg="var(--bg-card)" border>Cancel</ActBtn>}
          {r.status === PINV_STATUS.DRAFT && manage && <ActBtn onClick={() => openAction(r, 'delete')} icon={Trash2} color="#f87171" bg="var(--bg-card)" border>Delete</ActBtn>}
        </div>
      </div>
    </div>
  )
}

function EmptyState({ onCreate }) {
  return (
    <div className="pr-glass" style={{ padding: 60, textAlign: 'center' }}>
      <div style={{ width: 60, height: 60, borderRadius: 18, margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'linear-gradient(145deg,#7C3AED,#5b21b6)', boxShadow: '0 10px 24px -6px rgba(124,58,237,.6)' }}>
        <FileText size={26} color="#fff" />
      </div>
      <h3 style={{ color: 'var(--text-h)', fontSize: 16, fontWeight: 800, margin: '0 0 6px' }}>No purchase invoices yet</h3>
      <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '0 0 18px' }}>Record a vendor bill directly, or raise one from a received purchase order.</p>
      {onCreate && <button onClick={onCreate} style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '9px 20px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }}><Plus size={15} /> New Invoice</button>}
    </div>
  )
}

// ── Create / edit modal ──────────────────────────────────────────────────────
function InvoiceFormModal({ editing, setEditing, saving, admin, onClose, onSave }) {
  const f = editing
  const set = (k) => (e) => setEditing(p => ({ ...p, [k]: e.target.value }))
  const [vendors, setVendors] = useState([])
  useEffect(() => { purchaseApi.vendors.list().then(res => setVendors(Array.isArray(res?.data ?? res) ? (res.data ?? res) : [])).catch(() => {}) }, [])

  const setItem = (i, k, v) => setEditing(p => { const items = [...p.items]; items[i] = { ...items[i], [k]: v }; return { ...p, items } })
  const addItem = () => setEditing(p => ({ ...p, items: [...p.items, { ...EMPTY_ITEM }] }))
  const removeItem = (i) => setEditing(p => ({ ...p, items: p.items.filter((_, idx) => idx !== i) }))
  const t = totalsOf(f.items)

  return (
    <Overlay onClose={onClose} width={860}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>{f.id ? 'Edit' : 'New'} Purchase Invoice</h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 18px' }}>Record the vendor's bill, add line items, then save as draft{admin ? ' or approve it as payable' : ''}.</p>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 14, marginBottom: 18 }}>
        <Field label="Title"><TextInput value={f.title} onChange={set('title')} placeholder="e.g. Cement supply — July" /></Field>
        <Field label="Vendor Bill Ref #"><TextInput value={f.vendor_invoice_ref} onChange={set('vendor_invoice_ref')} placeholder="Vendor's invoice number" /></Field>
        <Field label="Vendor (must be Active)">
          <SelectInput value={f.vendor_id} onChange={set('vendor_id')} pairs
            options={[['', 'Select vendor…'], ...vendors.map(v => [String(v.id), `${v.company_name}${v.status !== 'Active' ? ` (${v.status_label || v.status})` : ''}`])]} />
        </Field>
        <Field label="Currency"><SelectInput value={f.currency} onChange={set('currency')} options={['INR', 'USD', 'EUR', 'GBP']} /></Field>
        <Field label="Invoice Date"><TextInput type="date" value={f.invoice_date} onChange={set('invoice_date')} /></Field>
        <Field label="Due Date"><TextInput type="date" value={f.due_date} onChange={set('due_date')} /></Field>
        <Field label="Terms" full><textarea value={f.terms} onChange={set('terms')} rows={2} placeholder="Payment terms…" style={{ ...inputStyle, resize: 'vertical' }} /></Field>
      </div>

      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
        <span style={{ width: 22, height: 22, borderRadius: 7, background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontSize: 11, fontWeight: 800, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>#</span>
        <h3 style={{ margin: 0, fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>Line Items</h3>
        <div style={{ flex: 1, height: 1, background: 'var(--border)' }} />
      </div>
      <div className="pr-glass" style={{ padding: 0, marginBottom: 14, borderRadius: 12 }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Description', 'Qty', 'Unit', 'Rate', 'Tax %', 'Amount', ''].map((h, i) => (
            <th key={h + i} style={{ textAlign: i === 0 ? 'left' : i === 6 ? 'center' : 'right', padding: '9px 10px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', borderBottom: '1px solid var(--border)' }}>{h}</th>
          ))}</tr></thead>
          <tbody>
            {f.items.map((it, i) => (
              <tr key={i} className="pr-li-row">
                <td style={{ padding: '6px 8px' }}><input value={it.description} onChange={e => setItem(i, 'description', e.target.value)} placeholder="Item description" style={{ ...inputStyle, padding: '7px 9px' }} /></td>
                <td style={{ padding: '6px 8px', width: 72 }}><input type="number" min="0" step="any" value={it.qty} onChange={e => setItem(i, 'qty', e.target.value)} style={{ ...inputStyle, padding: '7px 9px', textAlign: 'right' }} /></td>
                <td style={{ padding: '6px 8px', width: 72 }}><input value={it.unit} onChange={e => setItem(i, 'unit', e.target.value)} placeholder="nos" style={{ ...inputStyle, padding: '7px 9px' }} /></td>
                <td style={{ padding: '6px 8px', width: 100 }}><input type="number" min="0" step="any" value={it.rate} onChange={e => setItem(i, 'rate', e.target.value)} style={{ ...inputStyle, padding: '7px 9px', textAlign: 'right' }} /></td>
                <td style={{ padding: '6px 8px', width: 72 }}><input type="number" min="0" max="100" step="any" value={it.tax} onChange={e => setItem(i, 'tax', e.target.value)} style={{ ...inputStyle, padding: '7px 9px', textAlign: 'right' }} /></td>
                <td style={{ padding: '6px 10px', width: 110, textAlign: 'right', fontWeight: 700, color: 'var(--text-h)', fontSize: 12.5, whiteSpace: 'nowrap' }}>{fmtMoney(lineAmount(it), f.currency)}</td>
                <td style={{ padding: '6px 8px', width: 36, textAlign: 'center' }}>
                  <button onClick={() => removeItem(i)} disabled={f.items.length === 1} title="Remove" style={{ background: 'none', border: 'none', cursor: f.items.length === 1 ? 'not-allowed' : 'pointer', color: '#f87171', opacity: f.items.length === 1 ? 0.3 : 1, padding: 4 }}><Trash size={14} /></button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        <div style={{ padding: '8px 12px', borderTop: '1px solid var(--border)' }}>
          <button onClick={addItem} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '6px 12px', borderRadius: 8, border: '1px dashed var(--border-purple)', background: 'rgba(124,58,237,0.06)', color: '#a78bfa', cursor: 'pointer', fontSize: 12, fontWeight: 700 }}><Plus size={13} /> Add Item</button>
        </div>
      </div>

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
        {admin && <button onClick={() => onSave('approve')} disabled={saving} style={{ padding: '9px 18px', borderRadius: 10, border: 'none', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontWeight: 800, fontSize: 13, cursor: 'pointer', opacity: saving ? 0.7 : 1 }}>{saving ? 'Saving…' : 'Save & Approve'}</button>}
      </div>
    </Overlay>
  )
}

// ── Payment modal ────────────────────────────────────────────────────────────
function PaymentModal({ invoice, onClose, onDone }) {
  const balance = Number(invoice.balance || 0)
  const [f, setF] = useState({ amount: balance, payment_date: new Date().toISOString().slice(0, 10), payment_mode: 'Bank_Transfer', reference: '', notes: '' })
  const [saving, setSaving] = useState(false)
  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))

  const submit = async () => {
    const amt = Number(f.amount) || 0
    if (amt <= 0) { alert('Enter a payment amount.'); return }
    if (amt > balance + 0.001) { alert(`Amount can't exceed the balance (${fmtMoney(balance, invoice.currency)}).`); return }
    setSaving(true)
    try {
      await purchaseApi.invoices.addPayment(invoice.id, { ...f, amount: amt })
      onDone(invoice.id)
    } catch (e) { alert(e?.response?.data?.message || 'Failed to record payment') }
    finally { setSaving(false) }
  }

  return (
    <Overlay onClose={() => !saving && onClose()} width={520}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>Record Payment · {invoice.invoice_number}</h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 16px' }}>Outstanding balance: <strong style={{ color: '#f59e0b' }}>{fmtMoney(balance, invoice.currency)}</strong></p>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>
        <Field label="Amount *"><TextInput type="number" min="0" max={balance} step="any" value={f.amount} onChange={e => setF(p => ({ ...p, amount: Math.min(Number(e.target.value) || 0, balance) }))} /></Field>
        <Field label="Payment Date"><TextInput type="date" value={f.payment_date} onChange={set('payment_date')} /></Field>
        <Field label="Payment Mode"><SelectInput value={f.payment_mode} onChange={set('payment_mode')} options={PAYMENT_MODES} pairs /></Field>
        <Field label="Reference #"><TextInput value={f.reference} onChange={set('reference')} placeholder="Txn / cheque number" /></Field>
        <Field label="Notes" full><TextInput value={f.notes} onChange={set('notes')} placeholder="Optional" /></Field>
      </div>
      <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
        <button onClick={() => setF(p => ({ ...p, amount: balance }))} style={{ fontSize: 11.5, fontWeight: 700, padding: '5px 12px', borderRadius: 8, border: '1px solid var(--border-purple)', background: 'rgba(124,58,237,0.06)', color: '#a78bfa', cursor: 'pointer' }}>Pay full balance</button>
      </div>

      <ModalFooter onClose={onClose} onConfirm={submit} loading={saving} disabled={Number(f.amount) <= 0} confirmLabel="Record Payment" color="#10b981" />
    </Overlay>
  )
}

// ── Action modal ─────────────────────────────────────────────────────────────
function ActionModal({ actionModal, remarks, setRemarks, loading, onClose, onConfirm }) {
  const { action, invoice } = actionModal
  const [match, setMatch] = useState(null)
  const [matchLoading, setMatchLoading] = useState(action === 'approve')
  const meta = {
    approve: { title: 'Approve Invoice',  color: '#0ea5e9' },
    cancel:  { title: 'Cancel Invoice',   color: '#ef4444' },
    delete:  { title: 'Delete Invoice',   color: '#ef4444' },
  }[action]

  // Approval runs the 3-way match — show variances before the decision, and
  // disable Confirm when the server would block it anyway (over-billing / price).
  useEffect(() => {
    if (action !== 'approve') return
    let alive = true
    purchaseApi.invoices.match(invoice.id)
      .then(m => { if (alive) { setMatch(m?.data ?? m); setMatchLoading(false) } })
      .catch(() => { if (alive) setMatchLoading(false) })
    return () => { alive = false }
  }, [action, invoice.id])

  const blocked = !!match?.blocked

  return (
    <Overlay onClose={() => !loading && onClose()} width={action === 'approve' ? 620 : 460}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14 }}>
        {action === 'approve' ? <CheckCircle size={22} color={meta.color} /> : <XCircle size={22} color={meta.color} />}
        <h3 style={{ color: 'var(--text-h)', margin: 0, fontSize: 16, fontWeight: 800 }}>{meta.title}</h3>
      </div>
      <p style={{ color: 'var(--text-muted)', fontSize: 13, marginBottom: 14 }}>
        <strong style={{ color: 'var(--text-h)' }}>{invoice.invoice_number}</strong>{invoice.vendor?.company_name ? ` — ${invoice.vendor.company_name}` : ''} · {fmtMoney(invoice.total, invoice.currency)}
      </p>

      {action === 'approve' && <MatchPanel match={match} loading={matchLoading} />}
      {action === 'approve' && !blocked && <InfoBox>Approving marks the invoice payable and locks line items. Payments can then be recorded.</InfoBox>}
      {action === 'delete' && <InfoBox tone="danger">This permanently deletes the draft invoice. This cannot be undone.</InfoBox>}
      {action === 'cancel' && <InfoBox tone="danger">Cancelling stops any further payment on this invoice.</InfoBox>}
      {action === 'cancel' && (
        <>
          <label style={labelStyle}>Remarks (optional)</label>
          <textarea value={remarks} onChange={e => setRemarks(e.target.value)} rows={3} placeholder="Add remarks…" style={{ ...inputStyle, resize: 'vertical' }} />
        </>
      )}
      <ModalFooter onClose={onClose} onConfirm={onConfirm} loading={loading}
        disabled={action === 'approve' && (matchLoading || blocked)}
        confirmLabel={blocked ? 'Blocked by match' : 'Confirm'} color={meta.color} />
    </Overlay>
  )
}

// ── 3-way match panel — billed vs ordered vs GRN-accepted ────────────────────
function MatchPanel({ match, loading }) {
  if (loading) return <div className="skeleton" style={{ height: 88, borderRadius: 12, background: 'var(--border)', marginBottom: 14 }} />
  if (!match) return null
  if (!match.applicable) {
    return <div style={{ marginBottom: 14 }}><InfoBox>Not raised from a purchase order — 3-way match does not apply.</InfoBox></div>
  }

  const cfg = matchCfg(match.verdict)
  const flagged = (match.lines || []).filter(l => l.verdict !== 'Matched')

  return (
    <div style={{ marginBottom: 14, borderRadius: 14, overflow: 'hidden', border: `1px solid ${cfg.color}44` }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '11px 14px', background: cfg.bg }}>
        {cfg.blocking ? <XCircle size={17} style={{ color: cfg.color }} /> : match.verdict === 'Matched' ? <BadgeCheck size={17} style={{ color: cfg.color }} /> : <AlertTriangle size={17} style={{ color: cfg.color }} />}
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ fontSize: 13, fontWeight: 800, color: cfg.color }}>3-way match: {cfg.label}</div>
          <div style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{match.summary}</div>
        </div>
      </div>
      {flagged.length > 0 && (
        <div style={{ padding: '4px 0', background: 'var(--bg-card)' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12 }}>
            <thead><tr>{['Item', 'Billed', 'Ordered', 'Received', 'Verdict'].map((h, i) => (
              <th key={h} style={{ textAlign: i === 0 ? 'left' : 'right', padding: '6px 14px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>{h}</th>
            ))}</tr></thead>
            <tbody>
              {flagged.map((l, i) => {
                const lc = matchCfg(l.verdict)
                return (
                  <tr key={i}>
                    <td style={{ padding: '6px 14px', color: 'var(--text-h)', maxWidth: 200, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{l.description}</td>
                    <td style={{ padding: '6px 14px', textAlign: 'right', fontWeight: 700, color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }}>{l.billed_qty}{l.billed_rate != null ? ` @₹${l.billed_rate}` : ''}</td>
                    <td style={{ padding: '6px 14px', textAlign: 'right', color: 'var(--text-muted)', fontVariantNumeric: 'tabular-nums' }}>{l.ordered_qty ?? '—'}{l.po_rate != null ? ` @₹${l.po_rate}` : ''}</td>
                    <td style={{ padding: '6px 14px', textAlign: 'right', color: 'var(--text-muted)', fontVariantNumeric: 'tabular-nums' }}>{l.received_qty ?? '—'}</td>
                    <td style={{ padding: '6px 14px', textAlign: 'right' }}><span style={{ padding: '2px 8px', borderRadius: 999, fontSize: 10.5, fontWeight: 800, background: lc.bg, color: lc.color }}>{lc.label}</span></td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

// ── Detail modal ─────────────────────────────────────────────────────────────
function DetailModal({ invoice, admin, onClose, onPay, onReversePayment }) {
  const items = invoice.items || []
  const payments = invoice.payments || []
  const credits = invoice.credit_applications || []
  const canPay = admin && [PINV_STATUS.AWAITING_PAYMENT, PINV_STATUS.PARTIALLY_PAID].includes(invoice.status)
  const info = [
    ['Vendor', invoice.vendor?.company_name], ['Vendor Bill Ref', invoice.vendor_invoice_ref],
    ['Invoice Date', invoice.invoice_date && fmtDate(invoice.invoice_date)], ['Due Date', invoice.due_date && fmtDate(invoice.due_date)],
    ['Created By', invoice.creator?.name], ['From Order', invoice.purchase_order?.po_number],
  ].filter(([, v]) => v)

  return (
    <Overlay onClose={onClose} width={760}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6, flexWrap: 'wrap' }}>
        <h3 style={{ color: 'var(--text-h)', margin: 0, fontSize: 17, fontWeight: 800 }}>{invoice.invoice_number}{invoice.title ? ` · ${invoice.title}` : ''}</h3>
        <StatusBadge status={invoice.status} />
        {invoice.is_overdue && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3, padding: '2px 8px', borderRadius: 10, fontSize: 10.5, fontWeight: 700, background: 'rgba(239,68,68,0.12)', color: '#ef4444', border: '1px solid rgba(239,68,68,0.3)' }}><AlertTriangle size={10} /> Overdue</span>}
        {/* The match verdict recorded at approval — shows WHY a payable was let
            through (e.g. under-billed) without re-running the engine. */}
        {invoice.match_verdict && (() => { const mc = matchCfg(invoice.match_verdict); return (
          <span title="3-way match at approval" style={{ display: 'inline-flex', alignItems: 'center', gap: 3, padding: '2px 8px', borderRadius: 10, fontSize: 10.5, fontWeight: 700, background: mc.bg, color: mc.color }}>
            <ShieldCheck size={10} /> {mc.label}
          </span>
        ) })()}
        {canPay && <button onClick={() => onPay(invoice)} style={{ marginLeft: 'auto', display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 14px', borderRadius: 9, border: 'none', background: 'linear-gradient(135deg,#10b981,#059669)', color: '#fff', fontWeight: 700, fontSize: 12.5, cursor: 'pointer' }}><IndianRupee size={14} /> Record Payment</button>}
      </div>
      {[PINV_STATUS.AWAITING_PAYMENT, PINV_STATUS.PARTIALLY_PAID, PINV_STATUS.PAID].includes(invoice.status) && (
        <div style={{ margin: '10px 0 16px', maxWidth: 360 }}><PayBar paid={invoice.amount_paid} total={invoice.total} /></div>
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
          <thead><tr>{['Description', 'Qty', 'Rate', 'Tax %', 'Amount'].map((h, i) => (
            <th key={h} style={{ textAlign: i === 0 ? 'left' : 'right', padding: '8px 10px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', borderBottom: '1px solid var(--border)' }}>{h}</th>
          ))}</tr></thead>
          <tbody>
            {items.map((it, i) => (
              <tr key={i}>
                <td style={{ padding: '8px 10px', fontSize: 12.5, color: 'var(--text-h)' }}>{it.description}{it.unit ? <span style={{ color: 'var(--text-muted)' }}> · {it.unit}</span> : ''}</td>
                <td style={{ padding: '8px 10px', fontSize: 12.5, textAlign: 'right', color: 'var(--text-muted)' }}>{it.qty}</td>
                <td style={{ padding: '8px 10px', fontSize: 12.5, textAlign: 'right', color: 'var(--text-muted)' }}>{fmtMoney(it.rate, invoice.currency)}</td>
                <td style={{ padding: '8px 10px', fontSize: 12.5, textAlign: 'right', color: 'var(--text-muted)' }}>{it.tax}%</td>
                <td style={{ padding: '8px 10px', fontSize: 12.5, textAlign: 'right', fontWeight: 700, color: 'var(--text-h)' }}>{fmtMoney(it.amount ?? lineAmount(it), invoice.currency)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 16 }}>
        <div style={{ minWidth: 240, display: 'flex', flexDirection: 'column', gap: 6 }}>
          <TotalRow label="Subtotal" value={fmtMoney(invoice.subtotal, invoice.currency)} />
          <TotalRow label="Tax" value={fmtMoney(invoice.tax_total, invoice.currency)} />
          <TotalRow label="Total" value={fmtMoney(invoice.total, invoice.currency)} strong />
          <div style={{ height: 1, background: 'var(--border)', margin: '2px 0' }} />
          <TotalRow label="Paid (cash)" value={fmtMoney(invoice.amount_paid, invoice.currency)} />
          {Number(invoice.amount_credited) > 0 && <TotalRow label="Credit applied" value={fmtMoney(invoice.amount_credited, invoice.currency)} />}
          <TotalRow label="Balance" value={fmtMoney(invoice.balance, invoice.currency)} strong />
        </div>
      </div>

      {/* Payment history */}
      <label style={labelStyle}>Payments ({payments.length})</label>
      {payments.length === 0 ? (
        <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '0 0 14px' }}>No payments recorded yet.</p>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginBottom: 14 }}>
          {payments.map(p => (
            <div key={p.id} className="pr-glass" style={{ padding: '10px 14px', display: 'flex', alignItems: 'center', gap: 12, borderRadius: 10 }}>
              <span style={{ fontWeight: 800, fontSize: 13.5, color: '#10b981' }}>{fmtMoney(p.amount, invoice.currency)}</span>
              <span style={{ fontSize: 11.5, fontWeight: 700, padding: '2px 8px', borderRadius: 8, background: 'rgba(124,58,237,0.1)', color: '#a78bfa' }}>{p.payment_mode_label || paymentModeLabel(p.payment_mode)}</span>
              <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>{fmtDate(p.payment_date)}</span>
              {p.reference && <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>Ref: {p.reference}</span>}
              {p.creator?.name && <span style={{ fontSize: 11.5, color: 'var(--text-faint, var(--text-muted))' }}>by {p.creator.name}</span>}
              {admin && (
                <button onClick={() => { if (confirm('Reverse this payment?')) onReversePayment(p.id) }} title="Reverse payment"
                  style={{ marginLeft: 'auto', display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 11, fontWeight: 700, padding: '4px 9px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--bg-card)', color: '#f87171', cursor: 'pointer' }}>
                  <RotateCcw size={11} /> Reverse
                </button>
              )}
            </div>
          ))}
        </div>
      )}

      {/* Debit-note credits netted against this invoice — read-only here; they're
          managed (applied/reversed) from the debit note itself. */}
      {credits.length > 0 && (
        <>
          <label style={labelStyle}>Credits Applied ({credits.length})</label>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginBottom: 14 }}>
            {credits.map(c => (
              <div key={c.id} className="pr-glass" style={{ padding: '10px 14px', display: 'flex', alignItems: 'center', gap: 12, borderRadius: 10 }}>
                <ShieldCheck size={14} style={{ color: '#a78bfa', flexShrink: 0 }} />
                <span style={{ fontWeight: 800, fontSize: 13.5, color: '#a78bfa' }}>{fmtMoney(c.amount, invoice.currency)}</span>
                <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>from debit note <strong style={{ color: 'var(--text-h)' }}>{c.debit_note?.debit_number || '—'}</strong></span>
                <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>{fmtDate(c.applied_date)}</span>
              </div>
            ))}
          </div>
        </>
      )}

      <label style={labelStyle}>Audit Trail</label>
      {invoice.audit_logs === undefined
        ? <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>Loading timeline…</p>
        : <AuditTimeline entries={invoice.audit_logs} />}

      <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 18 }}>
        <button onClick={onClose} style={{ padding: '9px 20px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>Close</button>
      </div>
    </Overlay>
  )
}

// ── List view ────────────────────────────────────────────────────────────────
const INV_PAGE = 12
function InvListView({ rows, onView, onEdit, admin, manage, openAction, onPay, highlightId }) {
  const [page, setPage] = useState(1)
  const pages = Math.max(1, Math.ceil(rows.length / INV_PAGE))
  const pageRows = useMemo(() => rows.slice((page - 1) * INV_PAGE, page * INV_PAGE), [rows, page])
  useEffect(() => { if (page > pages) setPage(1) }, [pages, page])
  const th = { textAlign: 'left', padding: '10px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
  const td = { padding: '11px 12px', borderBottom: '1px solid var(--border)', fontSize: 12.5, verticalAlign: 'middle', whiteSpace: 'nowrap' }

  return (
    <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
      <div style={{ overflowX: 'auto', maxHeight: '62vh', overflowY: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead style={{ position: 'sticky', top: 0, zIndex: 1, background: 'var(--bg-card, var(--bg-input))' }}>
            <tr>{['Invoice #', 'Vendor', 'Total', 'Paid', 'Balance', 'Due', 'Status'].map(h => <th key={h} style={th}>{h}</th>)}<th style={{ ...th, textAlign: 'right' }}>Actions</th></tr>
          </thead>
          <tbody>
            {pageRows.map(r => {
              const cfg = pinvStatusCfg(r.status)
              const canPay = admin && [PINV_STATUS.AWAITING_PAYMENT, PINV_STATUS.PARTIALLY_PAID].includes(r.status)
              return (
                <tr key={r.id} style={{ cursor: 'pointer', background: r.id === highlightId ? 'rgba(124,58,237,0.08)' : 'transparent' }} onClick={() => onView(r)}
                  onMouseEnter={e => { if (r.id !== highlightId) e.currentTarget.style.background = 'var(--bg-input)' }}
                  onMouseLeave={e => { if (r.id !== highlightId) e.currentTarget.style.background = 'transparent' }}>
                  <td style={{ ...td, fontWeight: 700, color: '#a78bfa' }}>{r.invoice_number}</td>
                  <td style={{ ...td, color: 'var(--text-h)', fontWeight: 600 }}>{r.vendor?.company_name || '—'}</td>
                  <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)' }}>{fmtMoney(r.total, r.currency)}</td>
                  <td style={{ ...td, color: '#10b981', fontWeight: 600 }}>{fmtMoney(r.amount_paid, r.currency)}</td>
                  <td style={{ ...td, color: Number(r.balance) > 0 ? '#f59e0b' : 'var(--text-muted)', fontWeight: 700 }}>{fmtMoney(r.balance, r.currency)}</td>
                  <td style={{ ...td, color: r.is_overdue ? '#ef4444' : 'var(--text-muted)', fontWeight: r.is_overdue ? 700 : 400 }}>{r.due_date ? fmtDate(r.due_date) : '—'}</td>
                  <td style={td}><span style={{ fontSize: 10.5, fontWeight: 700, padding: '2px 8px', borderRadius: 8, background: cfg.bg, color: cfg.color }}>{cfg.label}</span></td>
                  <td style={{ ...td, textAlign: 'right' }} onClick={e => e.stopPropagation()}>
                    <div style={{ display: 'inline-flex', gap: 6, justifyContent: 'flex-end' }}>
                      {r.status === PINV_STATUS.DRAFT && admin && <button onClick={() => openAction(r, 'approve')} style={{ fontSize: 11, fontWeight: 700, padding: '4px 9px', borderRadius: 7, border: 'none', background: 'rgba(14,165,233,0.15)', color: '#0ea5e9', cursor: 'pointer' }}>Approve</button>}
                      {canPay && <button onClick={() => onPay(r)} style={{ fontSize: 11, fontWeight: 700, padding: '4px 9px', borderRadius: 7, border: 'none', background: 'rgba(16,185,129,0.15)', color: '#10b981', cursor: 'pointer' }}>Pay</button>}
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
        <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{rows.length} invoice{rows.length === 1 ? '' : 's'} · page {page} of {pages}</span>
        <div style={{ display: 'flex', gap: 6 }}>
          <button onClick={() => setPage(p => Math.max(1, p - 1))} disabled={page === 1} style={{ padding: '5px 9px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: page === 1 ? 'not-allowed' : 'pointer', opacity: page === 1 ? 0.5 : 1 }}><ChevronLeft size={14} /></button>
          <button onClick={() => setPage(p => Math.min(pages, p + 1))} disabled={page === pages} style={{ padding: '5px 9px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: page === pages ? 'not-allowed' : 'pointer', opacity: page === pages ? 0.5 : 1 }}><ChevronRight size={14} /></button>
        </div>
      </div>
    </div>
  )
}
