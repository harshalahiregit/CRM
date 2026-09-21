import { useState, useEffect, useCallback } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { Plus, RefreshCw, Search, Eye, Trash2, HardHat, QrCode, AlertTriangle, Upload } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { useVendorModule } from '@/modules/tpv/useVendorModule'
import { readFieldErrors } from '@/services/apiError'
import { useAuth } from '@/context/AuthContext'
import { canManagePR } from '../constants'
import {
  KIT3D_STYLE, inputStyle, Overlay, ModalFooter, InfoBox,
  Field, TextInput, SelectInput, ActBtn, StatusBadge as StatusPill,
} from '@/components/ui/kit3d'

// Worker lifecycle — the exact strings PurchaseWorkforceAdminController validates
// and PurchaseWorkforceService writes. Purchase calls the initial state 'Pending'
// where TPV calls it 'Draft', so the stats endpoint still returns that count under
// its `draft` key; the labels here follow Purchase, not the payload.
export const WORKER_STATUS = {
  PENDING:    'Pending',
  ACTIVE:     'Active',
  SUSPENDED:  'Suspended',
  TERMINATED: 'Terminated',
  INACTIVE:   'Inactive',
}
const WORKER_STATUS_CONFIG = {
  [WORKER_STATUS.PENDING]:    { label: 'Pending',    color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  [WORKER_STATUS.ACTIVE]:     { label: 'Active',     color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  [WORKER_STATUS.SUSPENDED]:  { label: 'Suspended',  color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  [WORKER_STATUS.TERMINATED]: { label: 'Terminated', color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
  [WORKER_STATUS.INACTIVE]:   { label: 'Inactive',   color: '#64748b', bg: 'rgba(100,116,139,0.15)' },
}
export const workerStatusCfg = (s) => WORKER_STATUS_CONFIG[s] || WORKER_STATUS_CONFIG[WORKER_STATUS.PENDING]

// Medical verdicts — App\Support\Purchase\PurchaseMedicalFitness. Fit AND
// Fit-with-restrictions are passing, so the amber one is not a failure badge.
const FITNESS_CONFIG = {
  Pending:               { label: 'Pending',               color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  Fit:                   { label: 'Fit',                   color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Fit_With_Restrictions: { label: 'Fit with Restrictions', color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Unfit:                 { label: 'Unfit',                 color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
  Expired:               { label: 'Expired',               color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
const fitnessCfg = (s) => FITNESS_CONFIG[s] || { label: 'Not recorded', color: '#94a3b8', bg: 'rgba(148,163,184,0.12)' }

// Purchase keeps medicals and inductions NORMALISED (one-to-many), so a list row
// carries the latest of each as a relation rather than columns on the worker.
// Both spellings are accepted: the list eager-loads latestMedical/latestInduction,
// while a freshly created/updated worker comes back with the full collections.
const latestMedical   = (w) => w.latest_medical   ?? w.medicals?.[0]   ?? null
const latestInduction = (w) => w.latest_induction ?? w.inductions?.[0] ?? null

const _days = (d) => (d ? Math.ceil((new Date(d) - Date.now()) / 86400000) : null)
// Age is derived here rather than stored — dob is the only fact the API keeps.
const ageOf = (dob) => (dob ? Math.floor((Date.now() - new Date(dob)) / 31557600000) : null)

// Vendor-scoped KPI cards, derived from the (already vendor-filtered) list —
// mirrors the shape workforce.stats returns for the global view.
function deriveWorkerStats(list) {
  const by = (s) => list.filter(w => w.status === s).length
  return {
    total:      list.length,
    draft:      by(WORKER_STATUS.PENDING),
    active:     by(WORKER_STATUS.ACTIVE),
    suspended:  by(WORKER_STATUS.SUSPENDED),
    terminated: by(WORKER_STATUS.TERMINATED),
    expiring:   list.filter(w => w.status === WORKER_STATUS.ACTIVE && w.badge_valid_until
      && _days(w.badge_valid_until) != null && _days(w.badge_valid_until) <= 30 && _days(w.badge_valid_until) >= 0).length,
  }
}

export default function PurchaseWorkers() {
  const navigate = useNavigate()
  const { vendorId: routeVendorId } = useParams()   // present inside a vendor-scoped route
  const [searchParams] = useSearchParams()
  const { user } = useAuth()
  const manageRole = canManagePR(user)

  /*
   * One register, two surfaces — the way TPV has always done it.
   *
   * TpvWorkers picks its client with `isPortal ? portalApi : tpvApi` and is
   * mounted by both the admin route and the vendor portal. Purchase hardcoded
   * the admin client here, so its portal could not use this screen and a
   * separate, smaller one was written instead — a different layout showing less
   * of the same data.
   *
   * The portal is resolved from the PATH, not from a role: a Purchase vendor
   * holds a PurchaseVendor token and has no `user.role` to test.
   */
  const cfg = useVendorModule()
  const api = cfg.api
  const isPortal = cfg.portal

  /*
   * A vendor manages its OWN people.
   *
   * `canManagePR` reads a staff role, and a Purchase vendor has none — it holds
   * a PurchaseVendor token, so every `manage` gate would have been false and the
   * vendor would have lost the Register button on their own workforce screen.
   * TPV grants the same way (`manage || isPortal`).
   *
   * This reveals only what the vendor may actually do: register a worker, delete
   * one still pending, issue PPE. ACTIVATION is a separate `admin` gate that
   * stays false here, and the portal client refuses it outright — whether a
   * worker may walk on site is the site's decision, not the vendor's.
   */
  const manage = manageRole || isPortal

  // Vendor scope. The register lives on its own path today, so ?vendor_id= is how
  // the vendor workspace deep-links one company's people; a future nested route
  // supplies the same value as a path param and nothing else has to change.
  const vendorId = routeVendorId || searchParams.get('vendor_id') || ''

  // The portal keeps clean URLs with no vendor id in the path.
  const workerHref = (wid) => isPortal
    ? `/purchase-portal/workforce/workers/${wid}`
    : `/app/purchase/workers/${wid}`

  const [rows, setRows]       = useState([])
  const [stats, setStats]     = useState({})
  const [loading, setLoading] = useState(true)
  const [search, setSearch]   = useState('')
  const [filterStatus, setFilterStatus] = useState(searchParams.get('status') || 'All')
  const [creating, setCreating] = useState(false)
  const [uploading, setUploading] = useState(false)
  const [uploadingMedical, setUploadingMedical] = useState(false)
  const [selectedIds, setSelectedIds] = useState([])
  const [groupInducting, setGroupInducting] = useState(false)
  const [viewMode, setViewMode] = useState('cards')

  const fetchAll = useCallback(async () => {
    setLoading(true)
    try {
      // vendor_id only FILTERS — the server scopes by tenant, so a tampered id
      // narrows the list and never widens it.
      // The portal sends no scope at all — the server reads the vendor from the
      // token and would 404 anyone else's worker regardless.
      const listRes = await api.workforce.workers(
        isPortal ? {} : (vendorId ? { vendor_id: vendorId } : {}),
      )
      const list = Array.isArray(listRes?.data ?? listRes) ? (listRes.data ?? listRes) : []
      setRows(list)
      // stats is tenant-wide, so a vendor-scoped view derives its own counters
      // rather than showing totals that contradict the rows underneath them.
      if (isPortal || vendorId) {
        setStats(deriveWorkerStats(list))
      } else {
        const statRes = await api.workforce.stats()
        setStats(statRes?.data ?? statRes ?? {})
      }
    } catch (e) { console.error('Failed to load workers', e) }
    finally { setLoading(false) }
  }, [vendorId, isPortal, api])
  useEffect(() => { fetchAll() }, [fetchAll])

  const filtered = rows.filter(r => {
    const q = search.toLowerCase()
    const match = !q || r.full_name?.toLowerCase().includes(q) || r.worker_code?.toLowerCase().includes(q)
      || r.designation?.toLowerCase().includes(q) || r.vendor?.company_name?.toLowerCase().includes(q)
    return match && (filterStatus === 'All' || r.status === filterStatus)
  })

  const remove = async (r) => {
    if (!confirm(`Delete pending worker ${r.full_name}?`)) return
    try { await api.workforce.deleteWorker(r.id); fetchAll() }
    catch (e) { alert(e?.response?.data?.message || 'Delete failed') }
  }

  const statCards = [
    { label: 'Total',      value: stats.total,      color: '#7C3AED', filter: 'All' },
    { label: 'Pending',    value: stats.draft,      color: '#94a3b8', filter: WORKER_STATUS.PENDING },
    { label: 'Active',     value: stats.active,     color: '#10b981', filter: WORKER_STATUS.ACTIVE },
    { label: 'Suspended',  value: stats.suspended,  color: '#f59e0b', filter: WORKER_STATUS.SUSPENDED },
    { label: 'Terminated', value: stats.terminated, color: '#ef4444', filter: WORKER_STATUS.TERMINATED },
    { label: 'Expiring 30d', value: stats.expiring, color: '#f59e0b', filter: WORKER_STATUS.ACTIVE },
  ]

  const toggleSelectAll = () => {
    if (selectedIds.length === filtered.length) {
      setSelectedIds([])
    } else {
      setSelectedIds(filtered.map(w => w.id))
    }
  }

  const toggleSelectWorker = (id, e) => {
    e.stopPropagation()
    setSelectedIds(p => p.includes(id) ? p.filter(x => x !== id) : [...p, id])
  }

  const selectedWorkers = rows.filter(r => selectedIds.includes(r.id))

  return (
    <div style={{ padding: '24px 32px' }}>
      <style>{KIT3D_STYLE}</style>

      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 24 }}>
        <div>
          <h1 style={{ margin: 0, fontSize: 22, fontWeight: 900, color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
            {isPortal ? 'My Workforce' : 'Workforce Register'}
          </h1>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--text-muted)' }}>
            {isPortal
              ? 'Your registered personnel · 5-step statutory onboarding'
              : 'Registered personnel · 5-step statutory onboarding tracking'}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
          {selectedIds.length > 0 && (
            <button onClick={() => setGroupInducting(true)} style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '9px 18px', borderRadius: 10, background: 'linear-gradient(135deg,#8b5cf6,#6d28d9)', color: '#fff', fontWeight: 800, border: 'none', cursor: 'pointer', fontSize: 13, boxShadow: '0 8px 20px -4px rgba(139,92,246,0.5)' }}>
              👥 Group Induction ({selectedIds.length} Selected)
            </button>
          )}

          {/* View Mode Toggle */}
          <div style={{ display: 'flex', background: 'var(--bg-input)', padding: 3, borderRadius: 10, border: '1px solid var(--border)' }}>
            <button onClick={() => setViewMode('cards')} style={{ padding: '6px 14px', borderRadius: 8, border: 'none', background: viewMode === 'cards' ? '#7c3aed' : 'transparent', color: viewMode === 'cards' ? '#fff' : 'var(--text-muted)', fontWeight: 800, fontSize: 12, cursor: 'pointer' }}>
              🪪 Cards View
            </button>
            <button onClick={() => setViewMode('table')} style={{ padding: '6px 14px', borderRadius: 8, border: 'none', background: viewMode === 'table' ? '#7c3aed' : 'transparent', color: viewMode === 'table' ? '#fff' : 'var(--text-muted)', fontWeight: 800, fontSize: 12, cursor: 'pointer' }}>
              📋 Master Table View
            </button>
          </div>

          <button onClick={fetchAll} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', fontWeight: 700, cursor: 'pointer', fontSize: 13 }}>
            <RefreshCw size={14} />
          </button>
          {/* Staff may register and correct workers; ACTIVATION stays admin-only,
              and it lives in the wizard's step 5, not here. */}
          {manage && (
            <button onClick={() => setUploading(true)}
              style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '9px 16px', borderRadius: 10, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)', fontWeight: 700, cursor: 'pointer', fontSize: 13 }}>
              <Upload size={15} /> Bulk Upload
            </button>
          )}
          {/* The second half of the same request: a stack of fitness
              certificates is as common as a sheet of workers. */}
          {manage && !isPortal && (
            <button onClick={() => setUploadingMedical(true)}
              style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '9px 16px', borderRadius: 10, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)', fontWeight: 700, cursor: 'pointer', fontSize: 13 }}>
              <Upload size={15} /> Bulk Medical
            </button>
          )}
          {manage && (
            <button onClick={() => setCreating(true)} style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '9px 18px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }}>
              <Plus size={15} /> Register Worker
            </button>
          )}
        </div>
      </div>

      {/* KPI filter strip */}
      {statCards.some(c => c.value != null) && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(6,1fr)', gap: 10, marginBottom: 18 }}>
          {statCards.map((s, idx) => (
            <div key={idx} className="pr-glass pr-lift"
              style={{
                padding: '12px 14px', cursor: 'pointer', borderRadius: 12,
                border: filterStatus === s.filter && s.filter !== 'All' ? `1.5px solid ${s.color}` : '1px solid var(--border)',
                background: filterStatus === s.filter ? `${s.color}15` : undefined,
              }}
              onClick={() => setFilterStatus(s.filter)}>
              <div style={{ fontSize: 20, fontWeight: 900, color: s.color, lineHeight: 1 }}>{s.value ?? '—'}</div>
              <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 600, marginTop: 3 }}>{s.label}</div>
            </div>
          ))}
        </div>
      )}

      <div className="pr-glass" style={{ padding: '14px 18px', marginBottom: 18, display: 'flex', gap: 12, alignItems: 'center', borderRadius: 14 }}>
        <input type="checkbox" checked={selectedIds.length > 0 && selectedIds.length === filtered.length} onChange={toggleSelectAll} style={{ width: 18, height: 18, cursor: 'pointer' }} title="Select All" />
        <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-muted)' }}>Select All</span>
        <div style={{ position: 'relative', flex: 1, marginLeft: 8 }}>
          <Search size={14} style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search name, code, designation or vendor…" style={{ ...inputStyle, paddingLeft: 32 }} />
        </div>
        <select value={filterStatus} onChange={e => setFilterStatus(e.target.value)} style={{ ...inputStyle, width: 'auto', cursor: 'pointer' }}>
          <option value="All">All Status</option>
          {Object.values(WORKER_STATUS).map(s => <option key={s} value={s}>{WORKER_STATUS_CONFIG[s]?.label || s}</option>)}
        </select>
      </div>

      {loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}>Loading workforce…</div>
      ) : filtered.length === 0 ? (
        <div className="pr-glass" style={{ padding: 60, textAlign: 'center' }}>
          <div style={{ width: 60, height: 60, borderRadius: 18, margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'linear-gradient(145deg,#7C3AED,#5b21b6)', boxShadow: '0 10px 24px -6px rgba(124,58,237,.6)' }}>
            <HardHat size={26} color="#fff" />
          </div>
          <h3 style={{ color: 'var(--text-h)', fontSize: 16, fontWeight: 800, margin: '0 0 6px' }}>No workers registered</h3>
          <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '0 0 18px' }}>Register a worker to take them through medical, induction, PPE and badging.</p>
          {manage && <button onClick={() => setCreating(true)} style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '9px 20px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }}><Plus size={15} /> Register Worker</button>}
        </div>
      ) : viewMode === 'table' ? (
        <div className="pr-glass" style={{ padding: 0, borderRadius: 16, overflow: 'hidden' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5, color: 'var(--text-h)' }}>
            <thead>
              <tr style={{ background: 'var(--bg-input)', borderBottom: '1px solid var(--border)', textAlign: 'left' }}>
                <th style={{ padding: '12px 14px', width: 40 }}>#</th>
                <th style={{ padding: '12px 14px' }}>Worker Code &amp; Name</th>
                <th style={{ padding: '12px 14px' }}>Designation</th>
                <th style={{ padding: '12px 14px' }}>Medical (Step 2)</th>
                <th style={{ padding: '12px 14px' }}>Induction (Step 3)</th>
                <th style={{ padding: '12px 14px' }}>PPE (Step 4)</th>
                <th style={{ padding: '12px 14px' }}>Access &amp; Badge (Step 5)</th>
                <th style={{ padding: '12px 14px', textAlign: 'right' }}>Action</th>
              </tr>
            </thead>
            <tbody>
              {filtered.map(r => {
                const med = latestMedical(r)
                const ind = latestInduction(r)
                // current_step is the persisted pointer the service advances, so
                // "PPE issued" is read from it rather than recomputed here.
                const ppeOk = Number(r.current_step || 1) >= 4
                const isTerm = r.status === WORKER_STATUS.TERMINATED
                const isSusp = r.status === WORKER_STATUS.SUSPENDED
                const lapsed = r.badge_valid_until && _days(r.badge_valid_until) < 0
                return (
                  <tr key={r.id} style={{ borderBottom: '1px solid var(--border)', background: selectedIds.includes(r.id) ? 'rgba(124,58,237,0.06)' : 'transparent' }}>
                    <td style={{ padding: '12px 14px' }}>
                      <input type="checkbox" checked={selectedIds.includes(r.id)} onChange={e => toggleSelectWorker(r.id, e)} style={{ width: 16, height: 16, cursor: 'pointer' }} />
                    </td>
                    <td style={{ padding: '12px 14px' }}>
                      <strong style={{ color: 'var(--text-h)', display: 'block' }}>{r.full_name}</strong>
                      <span style={{ fontSize: 11, color: '#a78bfa', fontFamily: 'monospace' }}>{r.worker_code}</span>
                    </td>
                    <td style={{ padding: '12px 14px', color: 'var(--text-muted)' }}>{r.designation || 'Worker'}</td>
                    <td style={{ padding: '12px 14px' }}>
                      {med
                        ? <StatusPill cfg={fitnessCfg(med.fitness_status)} />
                        : <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>— Pending</span>}
                    </td>
                    <td style={{ padding: '12px 14px' }}>
                      {ind
                        ? <span style={{ fontSize: 11, fontWeight: 800, padding: '3px 8px', borderRadius: 6, background: ind.status === 'Completed' ? '#dcfce7' : '#fef9c3', color: ind.status === 'Completed' ? '#166534' : '#92400e' }}>{ind.status === 'Completed' ? '✓ Completed' : `⏳ ${ind.status || 'Pending'}`}</span>
                        : <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>— Pending</span>}
                    </td>
                    <td style={{ padding: '12px 14px' }}>
                      {ppeOk
                        ? <span style={{ fontSize: 11, fontWeight: 800, padding: '3px 8px', borderRadius: 6, background: '#e0f2fe', color: '#0369a1' }}>✓ PPE Issued</span>
                        : <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>— Pending</span>}
                    </td>
                    <td style={{ padding: '12px 14px' }}>
                      <span style={{ fontSize: 11, fontWeight: 800, padding: '4px 10px', borderRadius: 20, background: isTerm ? '#fee2e2' : isSusp ? '#ffedd5' : lapsed ? '#fef9c3' : r.badge_number ? '#dcfce7' : 'var(--bg-input)', color: isTerm ? '#991b1b' : isSusp ? '#9a3412' : lapsed ? '#92400e' : r.badge_number ? '#166534' : 'var(--text-muted)' }}>
                        {isTerm ? '⛔ Terminated' : isSusp ? '🚨 Suspended' : lapsed ? '⚠ Badge lapsed' : r.badge_number ? `✓ ${r.badge_number}` : '— Not issued'}
                      </span>
                    </td>
                    <td style={{ padding: '12px 14px', textAlign: 'right' }}>
                      <button onClick={() => navigate(workerHref(r.id))} style={{ padding: '5px 12px', borderRadius: 7, border: '1px solid var(--border)', background: 'var(--bg-card)', color: '#7c3aed', fontWeight: 800, cursor: 'pointer', fontSize: 11.5 }}>
                        Open →
                      </button>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      ) : (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2,1fr)', gap: 14 }}>
          {filtered.map(r => {
            const med = latestMedical(r)
            const ind = latestInduction(r)
            const age = ageOf(r.dob)
            return (
              <div key={r.id} className="pr-glass pr-lift pr-pop" style={{ padding: 18, cursor: 'pointer', border: selectedIds.includes(r.id) ? '2px solid #7c3aed' : undefined }}
                onClick={() => navigate(workerHref(r.id))}>
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12 }}>
                  <div onClick={e => e.stopPropagation()} style={{ padding: 2, display: 'flex', alignItems: 'center' }}>
                    <input type="checkbox" checked={selectedIds.includes(r.id)} onClick={e => e.stopPropagation()} onChange={e => toggleSelectWorker(r.id, e)} style={{ width: 18, height: 18, cursor: 'pointer' }} />
                  </div>
                  <div style={{ width: 44, height: 44, borderRadius: 14, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 15, fontWeight: 900, color: '#fff', background: 'linear-gradient(145deg,#9f67ff,#7C3AED)', boxShadow: '0 6px 16px -4px rgba(124,58,237,.6)' }}>
                    {r.full_name?.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase()}
                  </div>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap', marginBottom: 4 }}>
                      <span style={{ color: 'var(--text-h)', fontWeight: 800, fontSize: 14 }}>{r.full_name}</span>
                      <span style={{ color: '#a78bfa', fontWeight: 700, fontSize: 11 }}>{r.worker_code}</span>
                      <StatusPill cfg={workerStatusCfg(r.status)} />
                      {r.badge_number && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3, fontSize: 10.5, fontWeight: 700, color: '#10b981' }}><QrCode size={10} /> {r.badge_number}</span>}
                    </div>
                    <div style={{ display: 'flex', gap: 12, color: 'var(--text-muted)', fontSize: 11.5, flexWrap: 'wrap' }}>
                      {r.designation && <span>{r.designation}</span>}
                      {age != null && <span>{age} yrs</span>}
                      {!vendorId && r.vendor?.company_name && <span>· {r.vendor.company_name}</span>}
                    </div>
                    <div style={{ display: 'flex', gap: 8, marginTop: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                      {med?.fitness_status && <StatusPill cfg={fitnessCfg(med.fitness_status)} />}
                      {ind && (
                        <span style={{ fontSize: 10.5, fontWeight: 700, padding: '2px 8px', borderRadius: 8, background: ind.status === 'Completed' ? 'rgba(16,185,129,0.14)' : 'rgba(245,158,11,0.14)', color: ind.status === 'Completed' ? '#10b981' : '#f59e0b' }}>
                          Induction {ind.status === 'Completed' ? 'completed' : 'pending'}
                        </span>
                      )}
                      {r.status === WORKER_STATUS.ACTIVE && r.badge_valid_until && _days(r.badge_valid_until) < 0 && (
                        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3, fontSize: 10.5, fontWeight: 700, color: '#ef4444' }}><AlertTriangle size={10} /> badge lapsed</span>
                      )}
                    </div>
                    <div className="pr-bar" style={{ marginTop: 10 }}>
                      <span style={{ width: `${Math.round(((r.current_step || 1) / 5) * 100)}%` }} />
                    </div>
                  </div>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 6, flexShrink: 0 }} onClick={e => e.stopPropagation()}>
                    <ActBtn onClick={() => navigate(workerHref(r.id))} icon={Eye} color="var(--text-muted)" bg="var(--bg-card)" border>Open</ActBtn>
                    {manage && r.status === WORKER_STATUS.PENDING && (
                      <ActBtn onClick={() => remove(r)} icon={Trash2} color="#f87171" bg="var(--bg-card)" border>Delete</ActBtn>
                    )}
                  </div>
                </div>
              </div>
            )
          })}
        </div>
      )}

      {uploading && <BulkUploadModal vendorId={vendorId} api={api} isPortal={isPortal}
        onClose={() => setUploading(false)} onUploaded={() => { setUploading(false); fetchAll() }} />}
      {uploadingMedical && <BulkMedicalModal vendorId={vendorId}
        onClose={() => setUploadingMedical(false)} onUploaded={() => { setUploadingMedical(false); fetchAll() }} />}
      {creating && <CreateModal vendorId={vendorId} api={api} isPortal={isPortal} onClose={() => setCreating(false)} onCreated={(id) => { setCreating(false); navigate(workerHref(id)) }} />}
      {groupInducting && <GroupInductionModal workers={selectedWorkers} onClose={() => setGroupInducting(false)} onCompleted={() => { setGroupInducting(false); setSelectedIds([]); fetchAll() }} />}
    </div>
  )
}

/**
 * Group Induction — one session recorded against every selected worker.
 *
 * Purchase stores an induction as a NORMALISED row (induction_date, status,
 * conducted_by, remarks), so the session details below are exactly the fields that
 * survive the save. The per-worker chips flip as each POST lands, which is why the
 * loop is sequential: a half-finished batch still shows precisely how far it got.
 */
/**
 * Bulk worker import — the TPV modal, for Purchase.
 *
 * The two things that make an import trustworthy, and that a bare "3 skipped"
 * destroys: it says WHICH rows did not land and WHY, and it never guesses whose
 * workers these are. On the portal the vendor is the caller, so no id is sent;
 * on the admin surface the operator must pick one.
 */
const NEWLINE = String.fromCharCode(10)

/**
 * Import many medical certificates at once.
 *
 * Rows are matched to workers by `worker_code`, which is why the template is
 * worth downloading rather than guessing at: a sheet without that column is
 * refused outright. Certificates are optional and matched the same way -- name
 * the file after the worker_code and it is filed against that row.
 *
 * Every rejected row is listed with its line number and the reason, because a
 * batch that reports "18 imported" and nothing else leaves nobody able to find
 * the two that did not.
 */
function BulkMedicalModal({ vendorId, onClose, onUploaded }) {
  const [vendors, setVendors] = useState([])
  const [vid, setVid] = useState(vendorId ? String(vendorId) : '')
  const [file, setFile] = useState(null)
  const [certs, setCerts] = useState([])
  const [busy, setBusy] = useState(false)
  const [result, setResult] = useState(null)
  // Shown in the modal rather than as a browser dialog: a modal dialog throws
  // the reader out of the form and loses the file they already picked.
  const [err, setErr] = useState('')

  useEffect(() => {
    if (vendorId) return
    purchaseApi.vendors.list({ per_page: 200 })
      .then(res => setVendors(Array.isArray(res?.data ?? res) ? (res.data ?? res) : []))
      .catch(() => {})
  }, [vendorId])

  const downloadSample = () => {
    const csv = [
      'worker_code,exam_date,valid_until,fitness_status,doctor_name,doctor_license_no,clinic_name,blood_group,height_cm,weight_kg,bp_systolic,bp_diastolic,restrictions,remarks',
      'PW-0001,2026-09-01,2027-09-01,Fit,Dr A Mehta,MH-99213,City Clinic,B+,172,68,120,80,,Cleared for all site work',
      'PW-0002,2026-09-01,2027-03-01,Fit_With_Restrictions,Dr A Mehta,MH-99213,City Clinic,O+,165,72,130,85,No work at height,Review in six months',
    ].join(NEWLINE)
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }))
    const a = document.createElement('a')
    a.href = url
    a.download = 'purchase_medical_bulk_upload_sample.csv'
    document.body.appendChild(a); a.click(); document.body.removeChild(a)
    URL.revokeObjectURL(url)
  }

  const doUpload = async () => {
    if (!file) { setErr('Choose the certificate sheet first.'); return }
    setBusy(true); setErr('')
    try { setResult(await purchaseApi.workforce.bulkMedical(file, vid ? Number(vid) : null, certs)) }
    catch (e) { setErr(readFieldErrors(e).summary) }
    finally { setBusy(false) }
  }

  const batch = result?.data ?? result
  const errors = Array.isArray(batch?.errors) ? batch.errors : []

  return (
    <Overlay onClose={() => !busy && onClose()} width={660}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>Bulk Medical Upload</h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '0 0 16px' }}>
        One row per certificate, matched to a worker by <strong>worker_code</strong>. CSV or XLSX.
      </p>

      <div style={{ padding: '10px 14px', borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)', marginBottom: 16, display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
        <div>
          <strong style={{ fontSize: 12.5, display: 'block', color: 'var(--text-h)' }}>Need the template?</strong>
          <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>Every column the importer reads, with two example rows.</span>
        </div>
        <button onClick={downloadSample} style={{ padding: '7px 13px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-h)', cursor: 'pointer', fontSize: 12, fontWeight: 700 }}>
          Download sample
        </button>
      </div>

      {err && (
        <p style={{ margin: '0 0 12px', padding: '9px 12px', borderRadius: 9, fontSize: 12.5, color: '#b91c1c', background: 'rgba(239,68,68,.08)', border: '1px solid rgba(239,68,68,.25)' }}>
          {err}
        </p>
      )}

      {!result ? (
        <>
          {/* Optional, and it narrows the worker_code lookup to one vendor --
              worth doing where two vendors use overlapping codes. */}
          {!vendorId && (
            <Field label="Vendor (optional — narrows the worker_code match)" full>
              <SelectInput value={vid} onChange={e => setVid(e.target.value)} pairs
                options={[['', 'Any vendor'], ...vendors.map(v => [String(v.id), v.company_name])]} />
            </Field>
          )}

          <Field label="Certificate sheet *" full>
            <input type="file" accept=".csv,.txt,.xlsx"
              onChange={e => setFile(e.target.files?.[0] || null)}
              style={{ ...inputStyle, padding: 8 }} />
          </Field>

          <Field label="Scanned certificates (optional)" full>
            <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png"
              onChange={e => setCerts(Array.from(e.target.files || []))}
              style={{ ...inputStyle, padding: 8 }} />
            <span style={{ display: 'block', fontSize: 11.5, color: 'var(--text-muted)', marginTop: 4 }}>
              Name each file after the worker_code it belongs to — e.g. PW-0001.pdf.
              {certs.length > 0 && <strong> {certs.length} selected.</strong>}
            </span>
          </Field>
        </>
      ) : (
        <div style={{ padding: 14, borderRadius: 12, marginBottom: 8, background: batch?.failed_count ? 'rgba(245,158,11,.08)' : 'rgba(16,185,129,.08)', border: `1px solid ${batch?.failed_count ? '#fcd34d' : '#6ee7b7'}` }}>
          <strong style={{ display: 'block', marginBottom: 8, fontSize: 13.5, color: batch?.failed_count ? '#92400e' : '#047857' }}>
            {result?.message || `${batch?.created_count ?? 0} certificate(s) imported.`}
          </strong>
          {errors.length > 0 && (
            <>
              <span style={{ fontSize: 11.5, fontWeight: 800, color: '#b91c1c' }}>Rejected rows</span>
              <ul style={{ margin: '4px 0 0', paddingLeft: 18, fontSize: 12, color: '#b91c1c' }}>
                {errors.map((e, i) => (
                  <li key={i}>Row {e.row}{e.worker_code ? ` (${e.worker_code})` : ''}: {e.error}</li>
                ))}
              </ul>
            </>
          )}
        </div>
      )}

      <ModalFooter onClose={onClose} onConfirm={result ? onUploaded : doUpload}
        loading={busy} confirmLabel={result ? 'Done' : 'Upload & Process'} />
    </Overlay>
  )
}

function BulkUploadModal({ vendorId, api, isPortal, onClose, onUploaded }) {
  const [file, setFile] = useState(null)
  const [vid, setVid] = useState(vendorId ? String(vendorId) : '')
  const [vendors, setVendors] = useState([])
  const [busy, setBusy] = useState(false)
  const [result, setResult] = useState(null)

  useEffect(() => {
    if (isPortal || vendorId) return
    purchaseApi.vendors.list({ per_page: 200 })
      .then(res => setVendors(Array.isArray(res?.data ?? res) ? (res.data ?? res) : []))
      .catch(() => {})
  }, [isPortal, vendorId])

  // The same column order TPV's template uses, so one sheet serves both engines.
  const downloadSample = () => {
    const csv = [
      'Full Name,Gender,DOB,Mobile,Blood Group,Designation,Skill Category,ID Number,Photo Filename',
      'Suresh Patil,Male,1990-05-15,9876543210,B+,Electrician,Skilled,123456789012,',
      'Priya Sharma,Female,1995-02-10,9876543212,A+,Helper,Unskilled,345678901234,',
    ].join(NEWLINE)
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }))
    const a = document.createElement('a')
    a.href = url
    a.download = 'purchase_workforce_bulk_upload_sample.csv'
    document.body.appendChild(a); a.click(); document.body.removeChild(a)
    URL.revokeObjectURL(url)
  }

  const blockedTarget = (() => {
    if (isPortal) return null
    const v = vendors.find(x => String(x.id) === String(vid || vendorId))
    return v && v.can_register_workers === false ? v : null
  })()

  const doUpload = async () => {
    if (!file) { alert('Choose a CSV, Excel or ZIP file first.'); return }
    const target = isPortal ? undefined : Number(vid || vendorId)
    if (!isPortal && !target) { alert('Choose the vendor these workers belong to.'); return }

    setBusy(true)
    try { setResult(await api.workforce.uploadWorkers(file, target)) }
    catch (e) { alert(readFieldErrors(e).summary) }
    finally { setBusy(false) }
  }

  return (
    <Overlay onClose={() => !busy && onClose()} width={640}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>Bulk Worker Upload</h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '0 0 16px' }}>
        CSV, XLS, XLSX or a ZIP holding the sheet plus photos. Columns: Full Name, Gender, DOB,
        Mobile, Blood Group, Designation, Skill Category, ID Number, Photo Filename (optional).
      </p>

      <div style={{ padding: '10px 14px', borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)', marginBottom: 16, display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
        <div>
          <strong style={{ fontSize: 12.5, display: 'block', color: 'var(--text-h)' }}>Need the template?</strong>
          <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>A sample CSV with the right headers and three example rows.</span>
        </div>
        <button onClick={downloadSample} style={{ padding: '7px 13px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-h)', cursor: 'pointer', fontSize: 12, fontWeight: 700 }}>
          Download sample
        </button>
      </div>

      {!isPortal && !vendorId && (
        <Field label="Employing Vendor *" full>
          <SelectInput value={vid} onChange={e => setVid(e.target.value)} pairs
            options={[['', 'Select vendor…'],
              ...vendors.filter(v => v.can_register_workers !== false).map(v => [String(v.id), v.company_name])]} />
          {vendors.some(v => v.can_register_workers === false) && (
            <span style={{ display: 'block', fontSize: 11.5, color: 'var(--text-muted)', marginTop: 5 }}>
              Vendors whose onboarding is not approved are not listed.
            </span>
          )}
        </Field>
      )}

      {/* Forty rows refused one at a time is worse than one refusal up front. */}
      {blockedTarget && (
        <InfoBox tone="danger">
          <strong>{blockedTarget.company_name}</strong> is {blockedTarget.status_label || blockedTarget.status} —
          its workforce cannot be uploaded yet. Approve the vendor&rsquo;s onboarding first.
        </InfoBox>
      )}

      {!result ? (
        <Field label="Worker file *" full>
          <input type="file" accept=".csv,.xls,.xlsx,.txt,.zip"
            onChange={e => setFile(e.target.files?.[0] || null)}
            style={{ ...inputStyle, padding: 8 }} />
        </Field>
      ) : (
        <div style={{ padding: 14, borderRadius: 12, marginBottom: 8, background: result.status === 'success' ? 'rgba(16,185,129,.08)' : 'rgba(245,158,11,.08)', border: `1px solid ${result.status === 'success' ? '#6ee7b7' : '#fcd34d'}` }}>
          <strong style={{ display: 'block', marginBottom: 8, fontSize: 13.5, color: result.status === 'success' ? '#047857' : '#92400e' }}>
            {result.message}
          </strong>

          {/* Name the rows that did not land. A bare count is indistinguishable
              from an import that silently failed. */}
          {result.duplicates?.length > 0 && (
            <>
              <span style={{ fontSize: 11.5, fontWeight: 800, color: '#92400e' }}>Already registered</span>
              <ul style={{ margin: '4px 0 8px', paddingLeft: 18, fontSize: 12, color: '#92400e' }}>
                {result.duplicates.map((d, i) => <li key={i}>{d}</li>)}
              </ul>
            </>
          )}
          {result.errors?.length > 0 && (
            <>
              <span style={{ fontSize: 11.5, fontWeight: 800, color: '#b91c1c' }}>Could not be read</span>
              <ul style={{ margin: '4px 0 0', paddingLeft: 18, fontSize: 12, color: '#b91c1c' }}>
                {result.errors.map((e, i) => <li key={i}>{e}</li>)}
              </ul>
            </>
          )}
        </div>
      )}

      <ModalFooter onClose={onClose} onConfirm={result ? onUploaded : doUpload}
        loading={busy} disabled={!result && !!blockedTarget} confirmLabel={result ? 'Done' : 'Upload & Process'} />
    </Overlay>
  )
}

function GroupInductionModal({ workers, onClose, onCompleted }) {
  const { api } = useVendorModule()

  const [f, setF] = useState({
    induction_date: new Date().toISOString().slice(0, 10),
    conducted_by: 'Safety Officer – Rahul Sharma',
    custom_conducted_by: '',
    status: 'Completed',
    remarks: '',
  })
  const [done, setDone] = useState({})          // { [workerId]: true } as each save lands
  const [saving, setSaving] = useState(false)
  const [progressMsg, setProgressMsg] = useState('')

  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))

  const TRAINER_PRESETS = [
    { group: 'Safety Team', items: ['Safety Officer – Rahul Sharma', 'Safety Supervisor – Priya Patel', 'HSE Lead – Amit Verma', 'HSSE Manager – Neha Singh', 'Safety Inspector – Ravi Kumar'] },
    { group: 'HR Team', items: ['HR Manager – Sunita Joshi', 'HR Executive – Deepak Nair', 'HR Coordinator – Anjali Mehta'] },
    { group: 'Site Management', items: ['Site Engineer – Vikram Rao', 'Project Manager – Suresh Pillai', 'Site Supervisor – Mohan Das'] },
    { group: 'Custom', items: ['Other / Custom Trainer...'] },
  ]

  const doneCount = Object.values(done).filter(Boolean).length

  const saveGroupInduction = async () => {
    const conductedBy = f.conducted_by === 'Other / Custom Trainer...' ? f.custom_conducted_by : f.conducted_by
    if (!conductedBy.trim()) { alert('Conducted By is required.'); return }
    if (!f.induction_date) { alert('Induction Date is required.'); return }

    setSaving(true)
    try {
      let count = 0
      for (const w of workers) {
        count++
        setProgressMsg(`Saving worker ${count}/${workers.length}: ${w.full_name}...`)
        await api.workforce.saveInduction(w.id, {
          induction_date: f.induction_date,
          status:         f.status,
          conducted_by:   conductedBy,
          remarks:        f.remarks || null,
        })
        setDone(p => ({ ...p, [w.id]: true }))
      }
      alert(`Successfully saved induction for ${workers.length} workers!`)
      onCompleted()
    } catch (e) {
      alert(e?.response?.data?.message || 'Group induction save failed')
    } finally {
      setSaving(false)
      setProgressMsg('')
    }
  }

  return (
    <Overlay onClose={() => !saving && onClose()} width={820}>
      <div style={{ marginBottom: 16 }}>
        <h2 style={{ fontSize: 18, fontWeight: 900, color: 'var(--text-h)', margin: 0 }}>
          👥 Group Induction Session ({workers.length} Workers Selected)
        </h2>
        <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>Apply one session's details to every selected worker</span>
      </div>

      {/* Session Details */}
      <div style={{ padding: 14, borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)', marginBottom: 16 }}>
        <strong style={{ fontSize: 12.5, color: '#0284c7', display: 'block', marginBottom: 10 }}>📋 Session Details (Applies to all selected workers)</strong>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 12, marginBottom: 10 }}>
          <Field label="Induction Date *">
            <TextInput type="date" value={f.induction_date} onChange={set('induction_date')} />
          </Field>
          <Field label="Conducted By *">
            <select value={f.conducted_by} onChange={set('conducted_by')} style={inputStyle}>
              {TRAINER_PRESETS.map(grp => (
                <optgroup key={grp.group} label={grp.group}>
                  {grp.items.map(item => <option key={item} value={item}>{item}</option>)}
                </optgroup>
              ))}
            </select>
          </Field>
          {/* Step 3 only clears on a Completed induction, so the outcome is a
              choice here rather than an assumption. */}
          <Field label="Outcome *">
            <SelectInput value={f.status} onChange={set('status')} pairs options={[['Completed', 'Completed'], ['Pending', 'Pending']]} />
          </Field>
          {f.conducted_by === 'Other / Custom Trainer...' && (
            <Field label="Trainer Name *" full>
              <TextInput value={f.custom_conducted_by} onChange={set('custom_conducted_by')} placeholder="Who conducted this induction?" />
            </Field>
          )}
          <Field label="Remarks" full>
            <TextInput value={f.remarks} onChange={set('remarks')} placeholder="Topics covered, site rules briefed, observations…" />
          </Field>
        </div>
      </div>

      {/* Save Progress Bar */}
      <div style={{ padding: 12, borderRadius: 10, background: 'linear-gradient(135deg, #f3e8ff, #e9d5ff)', border: '1px solid #c084fc', marginBottom: 16 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
          <strong style={{ fontSize: 12.5, color: '#6b21a8' }}>📝 Induction Records Progress</strong>
          <span style={{ fontSize: 12, fontWeight: 900, color: '#6b21a8' }}>{doneCount} / {workers.length} Recorded</span>
        </div>
        <div style={{ height: 8, borderRadius: 999, background: '#d8b4fe', overflow: 'hidden' }}>
          <div style={{ height: '100%', width: `${Math.round((doneCount / Math.max(workers.length, 1)) * 100)}%`, background: 'linear-gradient(90deg, #10b981, #059669)', transition: 'width 0.3s' }} />
        </div>
      </div>

      {/* Per-worker rows */}
      <div style={{ maxHeight: 340, overflowY: 'auto', marginBottom: 16 }}>
        {workers.map(w => (
          <div key={w.id} style={{ borderRadius: 10, border: done[w.id] ? '2px solid #10b981' : '1px solid var(--border)', marginBottom: 8, overflow: 'hidden', background: 'var(--bg-card)' }}>
            <div style={{ padding: '10px 14px', background: done[w.id] ? '#dcfce7' : 'var(--bg-input)', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
              <div>
                <strong style={{ fontSize: 13, color: 'var(--text-h)' }}>{w.full_name}</strong>
                <span style={{ fontSize: 11, color: 'var(--text-muted)', marginLeft: 8 }}>({w.worker_code || 'W-0001'})</span>
              </div>
              <span style={{ fontSize: 11, fontWeight: 800, padding: '2px 10px', borderRadius: 20, background: done[w.id] ? '#10b981' : '#f59e0b', color: '#fff' }}>
                {done[w.id] ? '✓ Recorded' : 'Pending'}
              </span>
            </div>
          </div>
        ))}
      </div>

      {progressMsg && (
        <div style={{ padding: '8px 12px', borderRadius: 8, background: '#e0f2fe', color: '#0369a1', fontSize: 12, fontWeight: 700, marginBottom: 12 }}>
          ⏳ {progressMsg}
        </div>
      )}

      <ModalFooter onClose={onClose} onConfirm={saveGroupInduction} loading={saving} confirmLabel={`Complete Group Induction (${workers.length} Workers)`} />
    </Overlay>
  )
}

function CreateModal({ vendorId, api, isPortal, onClose, onCreated }) {
  const [vendors, setVendors] = useState([])
  const [f, setF] = useState({
    vendor_id: vendorId ? String(vendorId) : '',
    full_name: '', email: '', phone: '', gender: 'Male', dob: '',
    designation: 'Helper', id_proof_type: 'Aadhaar', id_proof_number: '',
    address: '', city: '', state: '', pincode: '', notes: '',
  })
  const [saving, setSaving] = useState(false)

  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))

  useEffect(() => {
    // A vendor registering its own worker has exactly one employer and no
    // business listing the others — the server assigns it from the token.
    if (isPortal) return
    purchaseApi.vendors.list({ per_page: 200 })
      .then(res => setVendors(Array.isArray(res?.data ?? res) ? (res.data ?? res) : []))
      .catch(() => {})
  }, [isPortal])

  const age = ageOf(f.dob)
  const isAgeException = age !== null && (age < 18 || age > 60)
  // Split once: what may be picked, and what is deliberately not offered.
  const eligibleVendors = vendors.filter(v => v.can_register_workers !== false)
  const hiddenVendors = vendors.filter(v => v.can_register_workers === false)

  const chosen = vendors.find(v => String(v.id) === String(f.vendor_id))
  /*
   * The server decides, and says so on the payload.
   *
   * Comparing status to 'Active' here would be wrong: a workforce category
   * (security, housekeeping, manpower) registers its people at onboarding step
   * 3, BEFORE the approval at step 4, so that check would disable the very form
   * those vendors are meant to use. `can_register_workers` is the same rule the
   * service enforces, computed once on the model.
   *
   * `chosen` is undefined until the vendor list lands, so an unknown vendor is
   * not treated as a blocked one — that would disable the form while loading.
   */
  const blockedVendor = !isPortal && !!chosen && chosen.can_register_workers === false

  const create = async () => {
    if (!isPortal && !f.vendor_id) { alert('Vendor is required.'); return }
    if (!f.full_name?.trim()) { alert('Full Name is required.'); return }
    if (!f.gender) { alert('Gender is required.'); return }
    if (!f.dob) { alert('Date of Birth is required.'); return }
    if (!f.designation) { alert('Designation is required.'); return }

    setSaving(true)
    try {
      // Blank optional fields are dropped rather than posted as '' — the request
      // rules are nullable, and an empty string is not the same as "not supplied".
      const payload = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== null))
      // `purchase_vendor_id`, not `vendor_id` — the latter names a company on
      // the shared vendors table, whose ids are unrelated to these.
      const { vendor_id: _drop, ...rest } = payload
      const w = await api.workforce.createWorker(
        isPortal ? rest : { ...rest, purchase_vendor_id: Number(f.vendor_id) },
      )
      onCreated(w?.id ?? w?.data?.id)
    } catch (e) {
      const errObj = e?.response?.data?.errors
      const errText = errObj ? Object.values(errObj).flat().join('\n') : (e?.response?.data?.message || 'Could not register worker')
      alert(errText)
    }
    finally { setSaving(false) }
  }

  return (
    <Overlay onClose={() => !saving && onClose()} width={860}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>➕ Worker Registration (Step 1)</h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 16px' }}>Step 1 of 5 — Personal, Work Details &amp; ID Proof.</p>

      {/*
        A vendor that is not approved has no workforce to enter yet.

        This used to warn and then let the registration through, which put people
        on the books who could never be badged and left nobody sure why
        (SIR-000014). The server refuses it now; this says so first, so the
        refusal is not a surprise at the end of a five-step form.
      */}
      {blockedVendor && (
        <InfoBox tone="danger">
          <strong>{chosen.company_name}</strong> is {chosen.status_label || chosen.status} —
          workers cannot be registered against it yet. Approve the vendor&rsquo;s onboarding
          first, then add its workforce.
        </InfoBox>
      )}

      {/* A vendor in its own portal is the employer — there is nothing to pick. */}
      {!isPortal && (
      <Field label="Employing Vendor *" full>
        {vendorId ? (
          <div style={{ ...inputStyle, display: 'flex', alignItems: 'center', opacity: 0.9 }}>
            {chosen ? `${chosen.company_name} · ${chosen.status_label || chosen.status}` : 'Loading vendor…'}
          </div>
        ) : (
          <>
          <SelectInput value={f.vendor_id} onChange={set('vendor_id')} pairs
            options={[['', 'Select vendor…'], ...eligibleVendors.map(v => [String(v.id), v.company_name])]} />
          {/*
            Vendors whose onboarding is not approved are LEFT OUT rather than
            shown greyed: the report asked for them not to be offered at all, and
            a list you can pick from but not submit is the confusion it names.
            The count is still said out loud, so nobody concludes their vendor
            has vanished.
          */}
          {hiddenVendors.length > 0 && (
            <span style={{ display: 'block', fontSize: 11.5, color: 'var(--text-muted)', marginTop: 5 }}>
              {hiddenVendors.length} vendor{hiddenVendors.length > 1 ? 's are' : ' is'} not listed — onboarding not approved yet
              ({hiddenVendors.slice(0, 3).map(v => v.company_name).join(', ')}{hiddenVendors.length > 3 ? '…' : ''}).
            </span>
          )}
          </>
        )}
      </Field>
      )}

      {/* Personal Info Section */}
      <div style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 10, borderBottom: '1px solid var(--border)', paddingBottom: 4, marginTop: 10 }}>
        📇 Personal Information
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 12, marginBottom: 14 }}>
        <Field label="Full Name *"><TextInput value={f.full_name} onChange={set('full_name')} placeholder="e.g. Suresh Patil" /></Field>
        <Field label="Email"><TextInput type="email" value={f.email} onChange={set('email')} placeholder="worker@email.com" /></Field>
        <Field label="Mobile"><TextInput type="tel" value={f.phone} onChange={set('phone')} maxLength={15} placeholder="10-digit mobile" /></Field>

        <Field label="Gender *"><SelectInput value={f.gender} onChange={set('gender')} pairs options={[['Male', 'Male'], ['Female', 'Female'], ['Transgender', 'Transgender']]} /></Field>
        <Field label="Date of Birth *"><TextInput type="date" value={f.dob} onChange={set('dob')} /></Field>
        {/* Age is a read-out of dob, not a stored field — the badge flags the two
            statutory exceptions (underage / overage) at the point of entry. */}
        <Field label="Age (Auto)">
          <div style={{ ...inputStyle, background: 'var(--bg-input)', display: 'flex', alignItems: 'center', justifyContent: 'space-between', opacity: 0.9 }}>
            <span>{age !== null ? `${age} yrs` : 'Auto'}</span>
            {age !== null && (
              <span style={{ fontSize: 10, fontWeight: 800, padding: '2px 6px', borderRadius: 4, background: age < 18 ? '#fef2f2' : age > 60 ? '#fffbe6' : '#ecfdf5', color: age < 18 ? '#dc2626' : age > 60 ? '#d97706' : '#059669' }}>
                {age < 18 ? 'Underage' : age > 60 ? 'Overage' : 'Valid'}
              </span>
            )}
          </div>
        </Field>

        {isAgeException && (
          <Field label="Age Exception" full>
            <InfoBox tone="danger">
              This worker is {age < 18 ? 'under 18' : 'over 60'}. Record the approving authority in Notes below —
              the badge gate is an admin decision and will be reviewed against it.
            </InfoBox>
          </Field>
        )}
      </div>

      {/* Work Details Section */}
      <div style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 10, borderBottom: '1px solid var(--border)', paddingBottom: 4, marginTop: 14 }}>
        💼 Work Details &amp; ID Proof
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 12, marginBottom: 14 }}>
        <Field label="Designation *"><SelectInput value={f.designation} onChange={set('designation')} pairs options={[['Mason', 'Mason'], ['Helper', 'Helper'], ['Electrician', 'Electrician'], ['Carpenter', 'Carpenter'], ['Plumber', 'Plumber'], ['Welder', 'Welder'], ['Supervisor', 'Supervisor'], ['Operator', 'Operator'], ['Fitter', 'Fitter'], ['Rigger', 'Rigger']]} /></Field>
        <Field label="ID Proof Type"><SelectInput value={f.id_proof_type} onChange={set('id_proof_type')} pairs options={[['Aadhaar', 'Aadhaar'], ['PAN', 'PAN'], ['Voter ID', 'Voter ID'], ['Driving Licence', 'Driving Licence'], ['Passport', 'Passport']]} /></Field>
        <Field label="ID Proof Number"><TextInput value={f.id_proof_number} onChange={set('id_proof_number')} maxLength={80} placeholder="e.g. 12-digit Aadhaar" /></Field>
      </div>

      {/* Address Section */}
      <div style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 10, borderBottom: '1px solid var(--border)', paddingBottom: 4, marginTop: 14 }}>
        📍 Address
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 12, marginBottom: 16 }}>
        <Field label="Address" full><TextInput value={f.address} onChange={set('address')} placeholder="House / street / locality" /></Field>
        <Field label="City"><TextInput value={f.city} onChange={set('city')} placeholder="e.g. Pune" /></Field>
        <Field label="State"><TextInput value={f.state} onChange={set('state')} placeholder="e.g. Maharashtra" /></Field>
        <Field label="Pincode"><TextInput value={f.pincode} onChange={set('pincode')} maxLength={20} placeholder="6-digit pincode" /></Field>
        <Field label="Notes" full><TextInput value={f.notes} onChange={set('notes')} placeholder="Anything the site should know about this worker" /></Field>
      </div>

      <ModalFooter onClose={onClose} onConfirm={create} loading={saving} disabled={(!isPortal && !f.vendor_id) || blockedVendor || !f.full_name || !f.dob} confirmLabel="Save &amp; Continue to Step 2 →" />
    </Overlay>
  )
}
