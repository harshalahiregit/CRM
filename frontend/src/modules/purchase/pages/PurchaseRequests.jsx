import { useState, useEffect, useCallback, useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Plus, RefreshCw, Search, Send, ThumbsUp, ThumbsDown, Eye, Pencil, Trash2,
  FileText, ShieldCheck, ShoppingBag, CheckCircle, XCircle, Clock,
  LayoutGrid, List, Trash, PackagePlus, ChevronLeft, ChevronRight, Package,
} from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { useAuth } from '@/context/AuthContext'
import AuditTimeline from '@/components/ui/AuditTimeline'
import {
  PR_STATUS, STATUS_CONFIG, statusLabel, statusColor, PRIORITIES, PRIORITY_COLORS,
  P2P_STAGES, canApprovePR, canManagePR, fmtMoney, fmtMoneyShort, fmtDate,
  lineAmount, totalsOf,
} from '../constants'
import {
  KIT3D_STYLE as PURCHASE_STYLE, labelStyle, inputStyle, Overlay, ModalFooter, InfoBox,
  Field, TextInput, SelectInput, TotalRow, ActBtn, StatusBadge as StatusPill,
} from '@/components/ui/kit3d'

const EMPTY_ITEM = { description: '', qty: 1, unit: '', rate: 0, tax: 0, catalog_item_id: null, sku: '', contract_rate_applied: false, contract_number: null }
const EMPTY_FORM = {
  title: '', department: '', vendor_id: '', priority: 'Normal', required_by: '',
  currency: 'INR', justification: '', remarks: '',
  items: [{ ...EMPTY_ITEM }],
}

// Status badge — driven by the PR status config.
const StatusBadge = ({ status }) => <StatusPill cfg={{ ...statusColor(status), label: statusLabel(status) }} />

// ── P2P pipeline — 3D extruded stage knobs with live counts ──────────────────
function Pipeline({ stats = {}, active, onStage }) {
  const count = { request: (stats.draft || 0) + (stats.submitted || 0), approval: stats.approved || 0, order: stats.converted || 0 }
  const COLORS = { request: '#f59e0b', approval: '#10b981', order: '#6366f1' }
  const ICONS = { request: FileText, approval: ShieldCheck, order: ShoppingBag }
  return (
    <div style={{ display: 'flex', alignItems: 'center', width: 'max-content', minWidth: '100%', gap: 0 }}>
      {P2P_STAGES.map((s, i) => {
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
              <span style={{ marginLeft: 'auto', minWidth: 26, height: 26, padding: '0 8px', borderRadius: 999, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 12.5, fontWeight: 800, color: n > 0 ? '#fff' : 'var(--text-muted)', background: n > 0 ? color : 'var(--bg-card)', border: n > 0 ? 'none' : '1px solid var(--border)', boxShadow: n > 0 ? `0 3px 10px -2px ${color}aa` : 'none', flexShrink: 0 }}>{n}</span>
            </button>
            {i < P2P_STAGES.length - 1 && (
              <div className={`pr-flow${lit ? '' : ' pr-flow-dim'}`} style={{ width: 30, height: 3, borderRadius: 4, margin: '0 5px', flexShrink: 0, background: `linear-gradient(90deg, ${color}, ${COLORS[P2P_STAGES[i + 1].key]})` }} />
            )}
          </div>
        )
      })}
    </div>
  )
}

// ── Main page ────────────────────────────────────────────────────────────────
export default function PurchaseRequests() {
  const { user } = useAuth()
  const navigate = useNavigate()
  const canApprove = canApprovePR(user)
  const canManage  = canManagePR(user)

  // Convert an approved PR into a draft PO, then jump to the Orders screen.
  const [converting, setConverting] = useState(null)
  const handleConvert = async (r) => {
    if (converting) return
    setConverting(r.id)
    try {
      const po = await purchaseApi.orders.fromRequest(r.id)
      navigate('/app/purchase/orders', { state: { highlight: po?.id ?? po?.data?.id } })
    } catch (e) { alert(e?.response?.data?.message || 'Failed to convert to PO') }
    finally { setConverting(null) }
  }

  const [rows, setRows]       = useState([])
  const [stats, setStats]     = useState({})
  const [loading, setLoading] = useState(true)
  const [search, setSearch]   = useState('')
  const [filterStatus, setFilterStatus] = useState('All')
  const [viewMode, setViewMode] = useState(() => localStorage.getItem('pur_pr_view') || 'card')
  const changeViewMode = (v) => { setViewMode(v); localStorage.setItem('pur_pr_view', v) }

  const [showModal, setShowModal] = useState(false)
  const [editing, setEditing]     = useState(null)
  const [saving, setSaving]       = useState(false)

  const [actionModal, setActionModal] = useState(null)  // { request, action }
  const [remarks, setRemarks]         = useState('')
  const [actionLoading, setActionLoading] = useState(false)
  const [detail, setDetail]           = useState(null)

  const fetchAll = useCallback(async () => {
    setLoading(true)
    try {
      const [listRes, statRes] = await Promise.all([purchaseApi.requests.list(), purchaseApi.requests.stats()])
      setRows(Array.isArray(listRes?.data ?? listRes) ? (listRes.data ?? listRes) : [])
      setStats(statRes?.data ?? statRes ?? {})
    } catch (e) { console.error('Failed to load purchase requests', e) }
    finally { setLoading(false) }
  }, [])
  useEffect(() => { fetchAll() }, [fetchAll])

  const filtered = rows.filter(r => {
    const q = search.toLowerCase()
    const matchSearch = !q || r.title?.toLowerCase().includes(q) || r.pr_number?.toLowerCase().includes(q) || r.department?.toLowerCase().includes(q) || r.vendor?.company_name?.toLowerCase().includes(q)
    const matchStatus = filterStatus === 'All'
      || r.status === filterStatus
      || !!P2P_STAGES.find(s => s.key === filterStatus)?.statuses.includes(r.status)
    return matchSearch && matchStatus
  })

  const isOwner = (r) => r.requested_by === user?.id || user?.role === 'admin'

  // ── Create / edit ──────────────────────────────────────────────────────────
  const openCreate = () => { setEditing({ ...EMPTY_FORM }); setShowModal(true) }
  const openEdit = (r) => {
    setEditing({
      id: r.id, title: r.title || '', department: r.department || '', vendor_id: r.vendor_id ?? '',
      priority: r.priority || 'Normal', required_by: r.required_by?.slice(0, 10) || '',
      currency: r.currency || 'INR', justification: r.justification || '', remarks: r.remarks || '',
      items: (r.items?.length ? r.items : [{ ...EMPTY_ITEM }]).map(it => ({
        description: it.description || '', qty: it.qty ?? 1, unit: it.unit || '', rate: it.rate ?? 0, tax: it.tax ?? 0,
        catalog_item_id: it.catalog_item_id ?? null, sku: it.catalog_item?.sku || '',
        contract_rate_applied: !!it.contract_rate_applied, contract_number: null,
      })),
    })
    setShowModal(true)
  }

  const handleSave = async (mode = 'draft') => {
    const f = editing
    if (!f.title?.trim()) { alert('Title is required.'); return }
    const items = f.items.filter(it => it.description?.trim() || it.catalog_item_id)
    if (items.length === 0) { alert('Add at least one line item with a description.'); return }
    setSaving(true)
    try {
      const payload = {
        title: f.title, department: f.department || null, vendor_id: f.vendor_id || null,
        priority: f.priority, required_by: f.required_by || null, currency: f.currency,
        justification: f.justification || null, remarks: f.remarks || null,
        items: items.map((it, i) => ({
          catalog_item_id: it.catalog_item_id || null,
          description: it.description, qty: Number(it.qty) || 1, unit: it.unit || null,
          rate: Number(it.rate) || 0, tax: Number(it.tax) || 0, sort_order: i,
        })),
      }
      let saved
      if (f.id) saved = await purchaseApi.requests.update(f.id, payload)
      else saved = await purchaseApi.requests.create(payload)
      const id = f.id || saved?.id || saved?.data?.id
      if (mode === 'submit' && id) await purchaseApi.requests.submit(id)
      setShowModal(false); setEditing(null); fetchAll()
    } catch (e) { alert(e?.response?.data?.message || 'Failed to save request') }
    finally { setSaving(false) }
  }

  // ── Workflow actions ───────────────────────────────────────────────────────
  const openAction = (request, action) => { setActionModal({ request, action }); setRemarks('') }
  const runAction = async () => {
    if (!actionModal) return
    const { request, action } = actionModal
    setActionLoading(true)
    try {
      const id = request.id
      if (action === 'submit')       await purchaseApi.requests.submit(id)
      else if (action === 'approve') await purchaseApi.requests.approve(id, remarks)
      else if (action === 'reject')  await purchaseApi.requests.reject(id, remarks)
      else if (action === 'delete')  await purchaseApi.requests.delete(id)
      setActionModal(null); setRemarks(''); fetchAll()
    } catch (e) { alert(e?.response?.data?.message || 'Action failed') }
    finally { setActionLoading(false) }
  }

  const openDetail = async (r) => {
    setDetail(r)
    try { const full = await purchaseApi.requests.get(r.id); if ((full?.data ?? full)?.id) setDetail(full.data ?? full) }
    catch { /* keep list-row data */ }
  }

  const statCards = [
    { label: 'Total',     value: stats.total,     color: '#7C3AED', filter: 'All' },
    { label: 'Draft',     value: stats.draft,     color: '#94a3b8', filter: PR_STATUS.DRAFT },
    { label: 'Submitted', value: stats.submitted, color: '#f59e0b', filter: PR_STATUS.SUBMITTED },
    { label: 'Approved',  value: stats.approved,  color: '#10b981', filter: PR_STATUS.APPROVED },
    { label: 'Rejected',  value: stats.rejected,  color: '#ef4444', filter: PR_STATUS.REJECTED },
    { label: 'Approved Value', value: fmtMoneyShort(stats.value), color: '#6366f1', filter: 'All', wide: true },
  ]

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{PURCHASE_STYLE}</style>

      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 800, margin: 0 }}>Purchase Requests</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Raise, approve and track procurement requests through the procure-to-pay pipeline.</p>
        </div>
        <div style={{ display: 'flex', gap: 10 }}>
          <div style={{ display: 'flex', borderRadius: 10, overflow: 'hidden', border: '1px solid var(--border)' }}>
            {[['card', LayoutGrid, 'Card'], ['list', List, 'List']].map(([v, Icon, label]) => (
              <button key={v} onClick={() => changeViewMode(v)} title={`${label} view`}
                style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 12px', border: 'none', cursor: 'pointer', fontSize: 12.5, fontWeight: 700,
                  background: viewMode === v ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)', color: viewMode === v ? '#fff' : 'var(--text-muted)' }}>
                <Icon size={13} /> {label}
              </button>
            ))}
          </div>
          <button onClick={fetchAll} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>
            <RefreshCw size={14} /> Refresh
          </button>
          {canManage && (
            <button onClick={openCreate} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 18px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13, boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)' }}>
              <Plus size={15} /> New Request
            </button>
          )}
        </div>
      </div>

      {/* Pipeline */}
      <div style={{ marginBottom: 20, overflowX: 'auto', paddingBottom: 4 }}>
        <Pipeline stats={stats} active={filterStatus} onStage={setFilterStatus} />
      </div>

      {/* Stat tiles */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(6,1fr)', gap: 12, marginBottom: 22 }}>
        {statCards.map(s => (
          <div key={s.label} className="pr-kpi" onClick={() => setFilterStatus(s.filter)}
            style={{ textAlign: 'center', outline: filterStatus === s.filter && s.filter !== 'All' ? `1.5px solid ${s.color}` : 'none' }}>
            <div style={{ fontSize: s.wide ? 19 : 24, fontWeight: 900, color: s.color }}>{s.wide ? s.value : (s.value || 0)}</div>
            <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 600, marginTop: 3 }}>{s.label}</div>
          </div>
        ))}
      </div>

      {/* Filters */}
      <div className="pr-glass" style={{ padding: '14px 18px', marginBottom: 18, display: 'flex', gap: 12, alignItems: 'center', borderRadius: 14 }}>
        <div style={{ position: 'relative', flex: 1 }}>
          <Search size={14} style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search PR number, title, department or vendor…" style={{ ...inputStyle, paddingLeft: 32 }} />
        </div>
        <select value={filterStatus} onChange={e => setFilterStatus(e.target.value)} style={{ ...inputStyle, width: 'auto', cursor: 'pointer' }}>
          <option value="All">All Status</option>
          {Object.values(PR_STATUS).map(s => <option key={s} value={s}>{STATUS_CONFIG[s]?.label || s}</option>)}
        </select>
      </div>

      {/* List */}
      {loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}>Loading requests…</div>
      ) : filtered.length === 0 ? (
        <EmptyState onCreate={canManage ? openCreate : null} />
      ) : viewMode === 'list' ? (
        <PRListView rows={filtered} onView={openDetail} onEdit={openEdit} isOwner={isOwner} canApprove={canApprove} openAction={openAction} />
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          {filtered.map(r => (
            <PRCard key={r.id} r={r} isOwner={isOwner} canApprove={canApprove} canManage={canManage}
              onView={openDetail} onEdit={openEdit} openAction={openAction} onConvert={handleConvert} />
          ))}
        </div>
      )}

      {showModal && <RequestFormModal editing={editing} setEditing={setEditing} saving={saving} requestedBy={user?.name} onClose={() => setShowModal(false)} onSave={handleSave} />}
      {actionModal && <ActionModal actionModal={actionModal} remarks={remarks} setRemarks={setRemarks} loading={actionLoading} onClose={() => setActionModal(null)} onConfirm={runAction} />}
      {detail && <DetailModal request={detail} onClose={() => setDetail(null)} />}
    </div>
  )
}

// ── Card ─────────────────────────────────────────────────────────────────────
function PRCard({ r, isOwner, canApprove, canManage, onView, onEdit, openAction, onConvert }) {
  const t = totalsOf(r.items || [])
  const total = r.total != null ? Number(r.total) : t.subtotal + t.tax
  return (
    <div className="pr-glass pr-lift pr-pop" style={{ padding: 20 }}>
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16 }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8, flexWrap: 'wrap' }}>
            <span style={{ color: '#a78bfa', fontWeight: 800, fontSize: 12 }}>{r.pr_number || `PR-${r.id}`}</span>
            <span style={{ color: 'var(--text-h)', fontWeight: 700, fontSize: 15 }}>{r.title}</span>
            <StatusBadge status={r.status} />
            <span style={{ padding: '2px 8px', borderRadius: 10, fontSize: 11, fontWeight: 700, background: `${PRIORITY_COLORS[r.priority]}20`, color: PRIORITY_COLORS[r.priority], border: `1px solid ${PRIORITY_COLORS[r.priority]}40` }}>{r.priority}</span>
          </div>
          <div style={{ display: 'flex', gap: 16, color: 'var(--text-muted)', fontSize: 12, marginBottom: 6, flexWrap: 'wrap' }}>
            {r.department && <span>🏢 {r.department}</span>}
            {r.vendor?.company_name && <span>🏷️ {r.vendor.company_name}</span>}
            <span>📦 {(r.items?.length) || 0} item{(r.items?.length) === 1 ? '' : 's'}</span>
            {r.required_by && <span>🎯 {fmtDate(r.required_by)}</span>}
            <span>👤 {r.requester?.name || 'Unknown'}</span>
          </div>
          <div style={{ fontSize: 18, fontWeight: 900, color: 'var(--text-h)' }}>
            {fmtMoney(total, r.currency)}
            <span style={{ fontSize: 11, fontWeight: 600, color: 'var(--text-muted)', marginLeft: 8 }}>incl. tax</span>
          </div>
        </div>

        {/* Actions */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 6, flexShrink: 0, alignItems: 'stretch' }}>
          {r.status === PR_STATUS.DRAFT && isOwner(r) && (
            <ActBtn onClick={() => openAction(r, 'submit')} icon={Send} color="#a78bfa" bg="rgba(124,58,237,0.15)">Submit</ActBtn>
          )}
          {r.status === PR_STATUS.SUBMITTED && canApprove && (
            <div style={{ display: 'flex', gap: 6 }}>
              <ActBtn onClick={() => openAction(r, 'approve')} icon={ThumbsUp} color="#10b981" bg="rgba(16,185,129,0.15)">Approve</ActBtn>
              <ActBtn onClick={() => openAction(r, 'reject')} icon={ThumbsDown} color="#f87171" bg="rgba(239,68,68,0.1)">Reject</ActBtn>
            </div>
          )}
          {r.status === PR_STATUS.APPROVED && canManage && (
            <ActBtn onClick={() => onConvert(r)} icon={PackagePlus} color="#6366f1" bg="rgba(99,102,241,0.15)">Convert to PO</ActBtn>
          )}
          {r.status === PR_STATUS.DRAFT && isOwner(r) && (
            <ActBtn onClick={() => onEdit(r)} icon={Pencil} color="var(--text-muted)" bg="var(--bg-card)" border>Edit</ActBtn>
          )}
          <ActBtn onClick={() => onView(r)} icon={Eye} color="var(--text-muted)" bg="var(--bg-card)" border>View</ActBtn>
          {r.status === PR_STATUS.DRAFT && isOwner(r) && (
            <ActBtn onClick={() => openAction(r, 'delete')} icon={Trash2} color="#f87171" bg="var(--bg-card)" border>Delete</ActBtn>
          )}
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
      <h3 style={{ color: 'var(--text-h)', fontSize: 16, fontWeight: 800, margin: '0 0 6px' }}>No purchase requests yet</h3>
      <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '0 0 18px' }}>Raise a purchase request to start the procure-to-pay workflow.</p>
      {onCreate && <button onClick={onCreate} style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '9px 20px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }}><Plus size={15} /> New Request</button>}
    </div>
  )
}

// ── Create / edit modal — with the line-items editor + live totals ───────────
function RequestFormModal({ editing, setEditing, saving, requestedBy, onClose, onSave }) {
  const f = editing
  const set = (k) => (e) => setEditing(p => ({ ...p, [k]: e.target.value }))
  const [vendors, setVendors] = useState([])
  const [catalog, setCatalog] = useState([])
  const [contracts, setContracts] = useState([])
  const [catQ, setCatQ] = useState('')
  const [catOpen, setCatOpen] = useState(false)

  useEffect(() => {
    purchaseApi.vendors.list().then(res => setVendors(Array.isArray(res?.data ?? res) ? (res.data ?? res) : [])).catch(() => {})
  }, [])
  useEffect(() => { purchaseApi.catalog.search('').then(r => setCatalog(r?.data ?? r ?? [])).catch(() => {}) }, [])
  useEffect(() => {
    if (!f.vendor_id) { setContracts([]); return }
    purchaseApi.contracts.referenceable(f.vendor_id).then(r => setContracts(r?.data ?? r ?? [])).catch(() => setContracts([]))
  }, [f.vendor_id])

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
  const recompute = (line) => {
    if (!line.catalog_item_id) return { ...line, contract_rate_applied: false, contract_number: null }
    const cat = catalog.find(c => c.id === line.catalog_item_id)
    const con = contractFor(line.catalog_item_id, Number(line.qty) || 0)
    if (con) return { ...line, rate: con.rate, tax: con.tax, contract_rate_applied: true, contract_number: con.contract_number }
    if (line.contract_rate_applied && cat) return { ...line, rate: Number(cat.default_rate), tax: Number(cat.default_tax), contract_rate_applied: false, contract_number: null }
    return { ...line, contract_rate_applied: false, contract_number: null }
  }
  useEffect(() => { setEditing(p => p ? { ...p, items: p.items.map(recompute) } : p) }, [contracts, catalog])   // eslint-disable-line react-hooks/exhaustive-deps

  const setItem = (i, k, v) => setEditing(p => {
    const items = [...p.items]
    let ln = { ...items[i], [k]: v }
    if (k === 'qty') ln = recompute(ln)
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

  return (
    <Overlay onClose={onClose} width={1180}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>{f.id ? 'Edit' : 'New'} Purchase Request</h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 18px' }}>Fill the header, add line items, then save as draft or submit for approval.</p>

      {/* Header grid */}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 14, marginBottom: 18 }}>
        <Field label="Title *" full><TextInput value={f.title} onChange={set('title')} placeholder="e.g. Site safety barricades" /></Field>
        <Field label="Department"><TextInput value={f.department} onChange={set('department')} placeholder="e.g. Operations" /></Field>
        <Field label="Vendor (must be Active)">
          <SelectInput value={f.vendor_id} onChange={set('vendor_id')} pairs
            options={[['', 'No vendor / decide later'], ...vendors.map(v => [String(v.id), `${v.company_name}${v.status !== 'Active' ? ` (${v.status_label || v.status})` : ''}`])]} />
        </Field>
        <Field label="Priority"><SelectInput value={f.priority} onChange={set('priority')} options={PRIORITIES} /></Field>
        <Field label="Required By"><TextInput type="date" min={today} value={f.required_by} onChange={set('required_by')} /></Field>
        <Field label="Currency"><SelectInput value={f.currency} onChange={set('currency')} options={['INR', 'USD', 'EUR', 'GBP']} /></Field>
        <Field label="Justification" full><textarea value={f.justification} onChange={set('justification')} rows={2} placeholder="Why is this purchase required?" style={{ ...inputStyle, resize: 'vertical' }} /></Field>
      </div>

      {/* Line items editor */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 }}>
        <span style={{ width: 22, height: 22, borderRadius: 7, background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontSize: 11, fontWeight: 800, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>#</span>
        <h3 style={{ margin: 0, fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>Line Items</h3>
        <div style={{ flex: 1, height: 1, background: 'var(--border)' }} />
      </div>

      {/* Catalog picker — pull a standardized SKU; a vendor contract rate auto-applies */}
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
          <thead>
            <tr>
              {['Description', 'Qty', 'Unit', 'Rate', 'Tax %', 'Amount', ''].map((h, i) => (
                <th key={h + i} style={{ textAlign: i === 0 ? 'left' : i === 6 ? 'center' : 'right', padding: '9px 10px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', borderBottom: '1px solid var(--border)' }}>{h}</th>
              ))}
            </tr>
          </thead>
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
                <td style={{ padding: '6px 8px', width: 100 }}><input type="number" min="0" step="any" value={it.rate} disabled={it.contract_rate_applied} title={it.contract_rate_applied ? 'Locked to the contract rate' : undefined} onChange={e => setItem(i, 'rate', e.target.value)} style={{ ...inputStyle, padding: '7px 9px', textAlign: 'right', ...(it.contract_rate_applied ? { background: 'rgba(16,185,129,0.10)', borderColor: '#34d39955', color: '#34d399', fontWeight: 700, cursor: 'not-allowed' } : {}) }} /></td>
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
          <button onClick={addItem} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '6px 12px', borderRadius: 8, border: '1px dashed var(--border-purple)', background: 'rgba(124,58,237,0.06)', color: '#a78bfa', cursor: 'pointer', fontSize: 12, fontWeight: 700 }}>
            <Plus size={13} /> Add Item
          </button>
        </div>
      </div>

      {/* Totals */}
      <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 8 }}>
        <div style={{ minWidth: 260, display: 'flex', flexDirection: 'column', gap: 6 }}>
          <TotalRow label="Subtotal" value={fmtMoney(t.subtotal, f.currency)} />
          <TotalRow label="Tax" value={fmtMoney(t.tax, f.currency)} />
          <div style={{ height: 1, background: 'var(--border)', margin: '2px 0' }} />
          <TotalRow label="Total" value={fmtMoney(t.subtotal + t.tax, f.currency)} strong />
        </div>
      </div>

      {/* Sticky footer */}
      <div style={{ position: 'sticky', bottom: -28, margin: '4px -28px -28px', padding: '14px 28px', background: 'var(--bg-card, var(--bg-input))', borderTop: '1px solid var(--border)', display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
        <button onClick={onClose} disabled={saving} style={{ padding: '9px 16px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-muted)', fontWeight: 700, fontSize: 13, cursor: 'pointer' }}>Cancel</button>
        <button onClick={() => onSave('draft')} disabled={saving} style={{ padding: '9px 16px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-h)', fontWeight: 700, fontSize: 13, cursor: 'pointer' }}>Save Draft</button>
        <button onClick={() => onSave('submit')} disabled={saving} style={{ padding: '9px 18px', borderRadius: 10, border: 'none', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', fontWeight: 800, fontSize: 13, cursor: 'pointer', opacity: saving ? 0.7 : 1 }}>{saving ? 'Saving…' : 'Submit for Approval'}</button>
      </div>
    </Overlay>
  )
}

// ── Action modal (submit / approve / reject / delete) ────────────────────────
function ActionModal({ actionModal, remarks, setRemarks, loading, onClose, onConfirm }) {
  const { action, request } = actionModal
  const meta = {
    submit:  { title: 'Submit for Approval', color: '#7C3AED' },
    approve: { title: 'Approve Request',      color: '#10b981' },
    reject:  { title: 'Reject Request',       color: '#ef4444' },
    delete:  { title: 'Delete Request',       color: '#ef4444' },
  }[action]
  const needsReason = action === 'reject'
  const showRemarks = action === 'approve' || action === 'reject'
  return (
    <Overlay onClose={() => !loading && onClose()} width={460}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14 }}>
        {action === 'reject' || action === 'delete' ? <XCircle size={22} color={meta.color} /> : <CheckCircle size={22} color={meta.color} />}
        <h3 style={{ color: 'var(--text-h)', margin: 0, fontSize: 16, fontWeight: 800 }}>{meta.title}</h3>
      </div>
      <p style={{ color: 'var(--text-muted)', fontSize: 13, marginBottom: 14 }}>
        <strong style={{ color: 'var(--text-h)' }}>{request.pr_number || `PR-${request.id}`} · {request.title}</strong>{request.department ? ` — ${request.department}` : ''}
      </p>
      {action === 'submit' && <InfoBox>Once submitted, line items are locked and the request moves to <strong>admin approval</strong>.</InfoBox>}
      {action === 'delete' && <InfoBox tone="danger">This permanently deletes the request. This cannot be undone.</InfoBox>}
      {showRemarks && (
        <>
          <label style={labelStyle}>{needsReason ? 'Reason for Rejection *' : 'Remarks (optional)'}</label>
          <textarea value={remarks} onChange={e => setRemarks(e.target.value)} rows={3} placeholder={needsReason ? 'Enter reason…' : 'Add remarks…'}
            style={{ ...inputStyle, resize: 'vertical', borderColor: needsReason && !remarks ? '#ef444480' : 'var(--border)' }} />
        </>
      )}
      <ModalFooter onClose={onClose} onConfirm={onConfirm} loading={loading} disabled={needsReason && !remarks} confirmLabel="Confirm" color={meta.color} />
    </Overlay>
  )
}

// ── Detail modal — header, items table, totals, audit timeline ───────────────
function DetailModal({ request, onClose }) {
  const items = request.items || []
  const meta = [
    ['Department', request.department], ['Vendor', request.vendor?.company_name],
    ['Priority', request.priority], ['Required By', request.required_by && fmtDate(request.required_by)],
    ['Requested By', request.requester?.name], ['Approved By', request.approver?.name],
  ].filter(([, v]) => v)
  return (
    <Overlay onClose={onClose} width={1000}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14, flexWrap: 'wrap' }}>
        <h3 style={{ color: 'var(--text-h)', margin: 0, fontSize: 17, fontWeight: 800 }}>{request.pr_number || `PR-${request.id}`} · {request.title}</h3>
        <StatusBadge status={request.status} />
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px 20px', marginBottom: 16 }}>
        {meta.map(([k, v]) => (
          <div key={k} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, borderBottom: '1px solid var(--border)', paddingBottom: 6 }}>
            <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>{k}</span>
            <span style={{ color: 'var(--text-h)', fontSize: 12, fontWeight: 600, textAlign: 'right' }}>{v}</span>
          </div>
        ))}
      </div>

      <label style={labelStyle}>Line Items</label>
      <div className="pr-glass" style={{ padding: 0, marginBottom: 14, borderRadius: 12 }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead>
            <tr>{['Description', 'Qty', 'Rate', 'Tax %', 'Amount'].map((h, i) => (
              <th key={h} style={{ textAlign: i === 0 ? 'left' : 'right', padding: '8px 10px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', borderBottom: '1px solid var(--border)' }}>{h}</th>
            ))}</tr>
          </thead>
          <tbody>
            {items.map((it, i) => (
              <tr key={i}>
                <td style={{ padding: '8px 10px', fontSize: 12.5, color: 'var(--text-h)' }}>{it.description}{it.unit ? <span style={{ color: 'var(--text-muted)' }}> · {it.unit}</span> : ''}</td>
                <td style={{ padding: '8px 10px', fontSize: 12.5, textAlign: 'right', color: 'var(--text-muted)' }}>{it.qty}</td>
                <td style={{ padding: '8px 10px', fontSize: 12.5, textAlign: 'right', color: 'var(--text-muted)' }}>{fmtMoney(it.rate, request.currency)}</td>
                <td style={{ padding: '8px 10px', fontSize: 12.5, textAlign: 'right', color: 'var(--text-muted)' }}>{it.tax}%</td>
                <td style={{ padding: '8px 10px', fontSize: 12.5, textAlign: 'right', fontWeight: 700, color: 'var(--text-h)' }}>{fmtMoney(it.amount ?? lineAmount(it), request.currency)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 16 }}>
        <div style={{ minWidth: 240, display: 'flex', flexDirection: 'column', gap: 6 }}>
          <TotalRow label="Subtotal" value={fmtMoney(request.subtotal, request.currency)} />
          <TotalRow label="Tax" value={fmtMoney(request.tax_total, request.currency)} />
          <div style={{ height: 1, background: 'var(--border)', margin: '2px 0' }} />
          <TotalRow label="Total" value={fmtMoney(request.total, request.currency)} strong />
        </div>
      </div>

      {request.justification && (
        <div style={{ marginBottom: 14 }}>
          <label style={labelStyle}>Justification</label>
          <p style={{ color: 'var(--text-h)', fontSize: 13, margin: 0, whiteSpace: 'pre-wrap', lineHeight: 1.5 }}>{request.justification}</p>
        </div>
      )}

      <label style={labelStyle}>Approval Timeline &amp; Audit Trail</label>
      {request.audit_logs === undefined
        ? <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>Loading timeline…</p>
        : <AuditTimeline entries={request.audit_logs} />}

      <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 18 }}>
        <button onClick={onClose} style={{ padding: '9px 20px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>Close</button>
      </div>
    </Overlay>
  )
}

// ── List view ────────────────────────────────────────────────────────────────
const PR_PAGE = 12
function PRListView({ rows, onView, onEdit, isOwner, canApprove, openAction }) {
  const [page, setPage] = useState(1)
  const pages = Math.max(1, Math.ceil(rows.length / PR_PAGE))
  const pageRows = useMemo(() => rows.slice((page - 1) * PR_PAGE, page * PR_PAGE), [rows, page])
  useEffect(() => { if (page > pages) setPage(1) }, [pages, page])
  const th = { textAlign: 'left', padding: '10px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
  const td = { padding: '11px 12px', borderBottom: '1px solid var(--border)', fontSize: 12.5, verticalAlign: 'middle', whiteSpace: 'nowrap' }

  const primary = (r) => {
    if (r.status === PR_STATUS.DRAFT && isOwner(r)) return ['Submit', () => openAction(r, 'submit'), '#a78bfa']
    if (r.status === PR_STATUS.SUBMITTED && canApprove) return ['Approve', () => openAction(r, 'approve'), '#10b981']
    return null
  }

  return (
    <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
      <div style={{ overflowX: 'auto', maxHeight: '62vh', overflowY: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead style={{ position: 'sticky', top: 0, zIndex: 1, background: 'var(--bg-card, var(--bg-input))' }}>
            <tr>
              {['PR #', 'Title', 'Department', 'Vendor', 'Items', 'Total', 'Required By', 'Status'].map(h => <th key={h} style={th}>{h}</th>)}
              <th style={{ ...th, textAlign: 'right' }}>Actions</th>
            </tr>
          </thead>
          <tbody>
            {pageRows.map(r => {
              const p = primary(r); const sc = STATUS_CONFIG[r.status] || {}
              return (
                <tr key={r.id} style={{ cursor: 'pointer' }} onClick={() => onView(r)}
                  onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'}
                  onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                  <td style={{ ...td, fontWeight: 700, color: '#a78bfa' }}>{r.pr_number || `PR-${r.id}`}</td>
                  <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)', whiteSpace: 'normal', maxWidth: 220 }}>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                      <span style={{ width: 7, height: 7, borderRadius: '50%', background: PRIORITY_COLORS[r.priority] || '#94a3b8', flexShrink: 0 }} title={r.priority} />
                      {r.title}
                    </span>
                  </td>
                  <td style={{ ...td, color: 'var(--text-muted)' }}>{r.department || '—'}</td>
                  <td style={{ ...td, color: 'var(--text-muted)' }}>{r.vendor?.company_name || '—'}</td>
                  <td style={{ ...td, textAlign: 'center', fontWeight: 700 }}>{r.items?.length ?? '—'}</td>
                  <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)' }}>{fmtMoney(r.total, r.currency)}</td>
                  <td style={{ ...td, color: 'var(--text-muted)' }}>{r.required_by ? fmtDate(r.required_by) : '—'}</td>
                  <td style={td}><span style={{ fontSize: 10.5, fontWeight: 700, padding: '2px 8px', borderRadius: 8, background: sc.bg || 'var(--bg-input)', color: sc.color || 'var(--text-muted)' }}>{statusLabel(r.status)}</span></td>
                  <td style={{ ...td, textAlign: 'right' }} onClick={e => e.stopPropagation()}>
                    <div style={{ display: 'inline-flex', gap: 6, justifyContent: 'flex-end' }}>
                      {p && <button onClick={p[1]} style={{ fontSize: 11, fontWeight: 700, padding: '4px 9px', borderRadius: 7, border: 'none', background: `${p[2]}1f`, color: p[2], cursor: 'pointer' }}>{p[0]}</button>}
                      {r.status === PR_STATUS.DRAFT && isOwner(r) && <button onClick={() => onEdit(r)} title="Edit" style={{ fontSize: 11, padding: '4px 8px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer' }}><Pencil size={12} /></button>}
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
        <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{rows.length} request{rows.length === 1 ? '' : 's'} · page {page} of {pages}</span>
        <div style={{ display: 'flex', gap: 6 }}>
          <button onClick={() => setPage(p => Math.max(1, p - 1))} disabled={page === 1} style={{ padding: '5px 9px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: page === 1 ? 'not-allowed' : 'pointer', opacity: page === 1 ? 0.5 : 1 }}><ChevronLeft size={14} /></button>
          <button onClick={() => setPage(p => Math.min(pages, p + 1))} disabled={page === pages} style={{ padding: '5px 9px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: page === pages ? 'not-allowed' : 'pointer', opacity: page === pages ? 0.5 : 1 }}><ChevronRight size={14} /></button>
        </div>
      </div>
    </div>
  )
}

