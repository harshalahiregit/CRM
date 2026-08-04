import { useState, useEffect, useCallback, useRef } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { Plus, RefreshCw, Search, Eye, Trash2, HardHat, QrCode, AlertTriangle } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { portalApi } from '@/services/portalApi'
import MyCompanyCard from '@/components/vendor/MyCompanyCard'
import { useAuth } from '@/context/AuthContext'
import {
  WORKER_STATUS, WORKER_STATUS_CONFIG, workerStatusCfg, fitnessCfg,
  SKILL_CATEGORIES, canManageTpv, fmtDate,
} from '../constants'
import {
  KIT3D_STYLE, inputStyle, Overlay, ModalFooter, InfoBox,
  Field, TextInput, SelectInput, ActBtn, StatusBadge as StatusPill,
} from '@/components/ui/kit3d'

// Vendor-scoped KPI cards, derived from the (already vendor-filtered) list —
// mirrors the shape workers.stats returns for the global view.
const _days = (d) => (d ? Math.ceil((new Date(d) - Date.now()) / 86400000) : null)
function deriveWorkerStats(list) {
  const by = (s) => list.filter(w => w.status === s).length
  return {
    total:      list.length,
    draft:      by(WORKER_STATUS.DRAFT),
    active:     by(WORKER_STATUS.ACTIVE),
    suspended:  by(WORKER_STATUS.SUSPENDED),
    terminated: by(WORKER_STATUS.TERMINATED),
    expiring:   list.filter(w => w.status === WORKER_STATUS.ACTIVE && w.badge_valid_until
      && _days(w.badge_valid_until) != null && _days(w.badge_valid_until) <= 30 && _days(w.badge_valid_until) >= 0).length,
  }
}

export default function TpvWorkers() {
  const navigate = useNavigate()
  const { vendorId } = useParams()           // present inside the admin vendor workspace
  const [searchParams] = useSearchParams()
  const { user } = useAuth()
  const manage = canManageTpv(user)
  // Portal vendors use portalApi; worker links resolve from role.
  const isPortal = user?.role === 'third_party_vendor'
  const api = isPortal ? portalApi : tpvApi

  // Worker detail link: admin uses /app/tpv/workforce/vendor/:id/workers/:wid,
  // portal uses /vendor-portal/workforce/workers/:wid
  const workerHref = (wid) => isPortal
    ? `/vendor-portal/workforce/workers/${wid}`
    : (vendorId
      ? `/app/tpv/workforce/vendor/${vendorId}/workers/${wid}`
      : `/app/tpv/workforce/${wid}`)

  // The portal shows one company's people, so it needs that company's identity for
  // the header card. Admin is cross-vendor and skips the fetch entirely.
  const [myVendor, setMyVendor] = useState(null)
  useEffect(() => {
    if (!isPortal) return
    portalApi.me().then(d => setMyVendor(d?.vendor ?? d ?? null)).catch(() => {})
  }, [isPortal])

  const [rows, setRows]       = useState([])
  const [stats, setStats]     = useState({})
  const [loading, setLoading] = useState(true)
  const [search, setSearch]   = useState('')
  const [filterStatus, setFilterStatus] = useState(searchParams.get('status') || 'All')
  const [creating, setCreating]   = useState(false)
  const [uploading, setUploading] = useState(false)
  const [selectedIds, setSelectedIds] = useState([])
  const [groupInducting, setGroupInducting] = useState(false)
  const [viewMode, setViewMode] = useState('cards')

  const fetchAll = useCallback(async () => {
    setLoading(true)
    try {
      // Portal: backend always scopes to own vendor. Admin: pass vendor_id when scoped.
      const listRes = await api.workers.list(
        isPortal ? {} : (vendorId ? { vendor_id: vendorId } : {})
      )
      const list = Array.isArray(listRes?.data ?? listRes) ? (listRes.data ?? listRes) : []
      setRows(list)
      if (isPortal || vendorId) {
        setStats(deriveWorkerStats(list))
      } else {
        const statRes = await api.workers.stats()
        setStats(statRes?.data ?? statRes ?? {})
      }
    } catch (e) { console.error('Failed to load workers', e) }
    finally { setLoading(false) }
  }, [vendorId, isPortal, api])
  useEffect(() => { fetchAll() }, [fetchAll])

  const filtered = rows.filter(r => {
    const q = search.toLowerCase()
    const match = !q || r.name?.toLowerCase().includes(q) || r.worker_code?.toLowerCase().includes(q)
      || r.designation?.toLowerCase().includes(q) || r.vendor?.company_name?.toLowerCase().includes(q)
    return match && (filterStatus === 'All' || r.status === filterStatus)
  })

  const remove = async (r) => {
    if (!confirm(`Delete draft worker ${r.name}?`)) return
    try { await api.workers.delete(r.id); fetchAll() }
    catch (e) { alert(e?.response?.data?.message || 'Delete failed') }
  }

  const statCards = [
    { label: 'Total',      value: stats.total,      color: '#7C3AED', filter: 'All' },
    { label: 'Draft',      value: stats.draft,      color: '#94a3b8', filter: WORKER_STATUS.DRAFT },
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

      {isPortal && (
        <MyCompanyCard
          vendor={myVendor}
          accent="#0ea5e9"
          stats={[
            { label: 'Active Workers',  value: rows.filter(r => (r.status || '').toLowerCase() === 'active').length },
            { label: 'Pending Workers', value: rows.filter(r => (r.status || '').toLowerCase() !== 'active').length },
            { label: 'Total',           value: rows.length },
          ]}
        />
      )}

      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 24 }}>
        <div>
          {/* Titles differ by audience: the portal shows ONE company's people, so it
              says "My Workforce". Admin sees every vendor's, so it stays a register. */}
          <h1 style={{ margin: 0, fontSize: 22, fontWeight: 900, color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
            {isPortal ? 'My Workforce' : 'Workforce Register'}
          </h1>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--text-muted)' }}>
            {isPortal
              ? 'Your company’s registered personnel · medical, induction, PPE and badging'
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
          {manage && (
            <>
              <button onClick={() => setUploading(true)} style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '9px 18px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-h)', fontWeight: 700, cursor: 'pointer', fontSize: 13 }}>
                📥 Bulk Upload
              </button>
              <button onClick={() => setCreating(true)} style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '9px 18px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }}>
                <Plus size={15} /> Register Worker
              </button>
            </>
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
                <th style={{ padding: '12px 14px' }}>Access &amp; Punches (Step 5)</th>
                <th style={{ padding: '12px 14px', textAlign: 'right' }}>Action</th>
              </tr>
            </thead>
            <tbody>
              {filtered.map((r, i) => {
                const pc = parseInt(r.punch_count || 0)
                const isTerm = pc >= 3 || r.status === WORKER_STATUS.TERMINATED
                const ppeOk = parseInt(r.ppe_status || 0) >= 1
                return (
                  <tr key={r.id} style={{ borderBottom: '1px solid var(--border)', background: selectedIds.includes(r.id) ? 'rgba(124,58,237,0.06)' : 'transparent' }}>
                    <td style={{ padding: '12px 14px' }}>
                      <input type="checkbox" checked={selectedIds.includes(r.id)} onChange={e => toggleSelectWorker(r.id, e)} style={{ width: 16, height: 16, cursor: 'pointer' }} />
                    </td>
                    <td style={{ padding: '12px 14px' }}>
                      <strong style={{ color: 'var(--text-h)', display: 'block' }}>{r.name}</strong>
                      <span style={{ fontSize: 11, color: '#a78bfa', fontFamily: 'monospace' }}>{r.worker_code}</span>
                    </td>
                    <td style={{ padding: '12px 14px', color: 'var(--text-muted)' }}>{r.designation || 'Worker'}</td>
                    <td style={{ padding: '12px 14px' }}>
                      {r.medical?.fitness_status
                        ? <StatusPill cfg={fitnessCfg(r.medical.fitness_status)} />
                        : <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>— Pending</span>}
                    </td>
                    <td style={{ padding: '12px 14px' }}>
                      {r.induction
                        ? <span style={{ fontSize: 11, fontWeight: 800, padding: '3px 8px', borderRadius: 6, background: r.induction.passed ? '#dcfce7' : '#fee2e2', color: r.induction.passed ? '#166534' : '#991b1b' }}>{r.induction.passed ? '✓ Passed' : '✕ Failed'}</span>
                        : <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>— Pending</span>}
                    </td>
                    <td style={{ padding: '12px 14px' }}>
                      {ppeOk
                        ? <span style={{ fontSize: 11, fontWeight: 800, padding: '3px 8px', borderRadius: 6, background: '#e0f2fe', color: '#0369a1' }}>✓ PPE Issued</span>
                        : <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>— Pending</span>}
                    </td>
                    <td style={{ padding: '12px 14px' }}>
                      <span style={{ fontSize: 11, fontWeight: 800, padding: '4px 10px', borderRadius: 20, background: isTerm ? '#fee2e2' : pc === 2 ? '#ffedd5' : pc === 1 ? '#fef9c3' : '#dcfce7', color: isTerm ? '#991b1b' : pc === 2 ? '#9a3412' : pc === 1 ? '#92400e' : '#166534' }}>
                        {isTerm ? '⛔ Terminated' : pc === 2 ? '🚨 2 Punches' : pc === 1 ? '⚠ 1 Punch' : '✓ Active (0 Punches)'}
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
          {filtered.map(r => (
            <div key={r.id} className="pr-glass pr-lift pr-pop" style={{ padding: 18, cursor: 'pointer', border: selectedIds.includes(r.id) ? '2px solid #7c3aed' : undefined }}
              onClick={() => navigate(workerHref(r.id))}>
              <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12 }}>
                <div onClick={e => e.stopPropagation()} style={{ padding: 2, display: 'flex', alignItems: 'center' }}>
                  <input type="checkbox" checked={selectedIds.includes(r.id)} onClick={e => e.stopPropagation()} onChange={e => toggleSelectWorker(r.id, e)} style={{ width: 18, height: 18, cursor: 'pointer' }} />
                </div>
                <div style={{ width: 44, height: 44, borderRadius: 14, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 15, fontWeight: 900, color: '#fff', background: 'linear-gradient(145deg,#9f67ff,#7C3AED)', boxShadow: '0 6px 16px -4px rgba(124,58,237,.6)' }}>
                  {r.name?.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase()}
                </div>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap', marginBottom: 4 }}>
                    <span style={{ color: 'var(--text-h)', fontWeight: 800, fontSize: 14 }}>{r.name}</span>
                    <span style={{ color: '#a78bfa', fontWeight: 700, fontSize: 11 }}>{r.worker_code}</span>
                    <StatusPill cfg={workerStatusCfg(r.status)} />
                    {r.badge_number && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3, fontSize: 10.5, fontWeight: 700, color: '#10b981' }}><QrCode size={10} /> {r.badge_number}</span>}
                  </div>
                  <div style={{ display: 'flex', gap: 12, color: 'var(--text-muted)', fontSize: 11.5, flexWrap: 'wrap' }}>
                    {r.designation && <span>{r.designation}</span>}
                    {r.age != null && <span>{r.age} yrs</span>}
                    {!isPortal && r.vendor?.company_name && <span>· {r.vendor.company_name}</span>}
                  </div>
                  <div style={{ display: 'flex', gap: 8, marginTop: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                    {r.medical?.fitness_status && <StatusPill cfg={fitnessCfg(r.medical.fitness_status)} />}
                    {r.induction && (
                      <span style={{ fontSize: 10.5, fontWeight: 700, padding: '2px 8px', borderRadius: 8, background: r.induction.passed ? 'rgba(16,185,129,0.14)' : 'rgba(239,68,68,0.14)', color: r.induction.passed ? '#10b981' : '#ef4444' }}>
                        Induction {r.induction.passed ? 'passed' : 'not passed'}
                      </span>
                    )}
                    {r.status === WORKER_STATUS.ACTIVE && !r.badge_valid && (
                      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 3, fontSize: 10.5, fontWeight: 700, color: '#ef4444' }}><AlertTriangle size={10} /> badge lapsed</span>
                    )}
                  </div>
                  <div className="pr-bar" style={{ marginTop: 10 }}>
                    <span style={{ width: `${Math.round(((r.current_step || 1) / 5) * 100)}%` }} />
                  </div>
                </div>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 6, flexShrink: 0 }} onClick={e => e.stopPropagation()}>
                  <ActBtn onClick={() => navigate(workerHref(r.id))} icon={Eye} color="var(--text-muted)" bg="var(--bg-card)" border>Open</ActBtn>
                  {manage && r.status === WORKER_STATUS.DRAFT && (
                    <ActBtn onClick={() => remove(r)} icon={Trash2} color="#f87171" bg="var(--bg-card)" border>Delete</ActBtn>
                  )}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {creating && <CreateModal vendorId={vendorId} onClose={() => setCreating(false)} onCreated={(id) => { setCreating(false); navigate(workerHref(id)) }} api={api} isPortal={isPortal} />}
      {uploading && <BulkUploadModal vendorId={vendorId} onClose={() => setUploading(false)} onUploaded={() => { setUploading(false); fetchAll() }} api={api} isPortal={isPortal} />}
      {groupInducting && <GroupInductionModal workers={selectedWorkers} onClose={() => setGroupInducting(false)} onCompleted={() => { setGroupInducting(false); setSelectedIds([]); fetchAll() }} api={api} />}
    </div>
  )
}

function GroupInductionModal({ workers, onClose, onCompleted, api }) {
  const [f, setF] = useState({
    induction_type: 'General Safety',
    trainer: 'Safety Officer – Rahul Sharma',
    custom_trainer: '',
    location: 'Site Office',
    time_mode: 'auto',
    start_time: new Date().toISOString(),
    end_time: '',
    duration_minutes: 15,
    m_start_date: new Date().toISOString().slice(0, 10),
    m_start_time: new Date().toTimeString().slice(0, 5),
    m_end_date: new Date().toISOString().slice(0, 10),
    m_end_time: new Date().toTimeString().slice(0, 5),
    photo_data: '',
  })

  // State per worker: { [wid]: { done: false, proofType: 'sig', sigData: '' } }
  const [workerProofs, setWorkerProofs] = useState(
    Object.fromEntries(workers.map(w => [w.id, { done: false, proofType: 'sig', sigData: '' }]))
  )
  const [activeWid, setActiveWid] = useState(workers[0]?.id || null)
  const [sessionActive, setSessionActive] = useState(false)
  const [saving, setSaving] = useState(false)
  const [progressMsg, setProgressMsg] = useState('')

  // Camera states
  const [camActive, setCamActive] = useState(false)
  const [photoPreview, setPhotoPreview] = useState(null)
  const videoRef = useRef(null)
  const photoCanvasRef = useRef(null)
  const streamRef = useRef(null)

  // Canvas Refs
  const canvasRef = useRef(null)
  const isDrawing = useRef(false)

  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))

  const TRAINER_PRESETS = [
    { group: 'Safety Team', items: ['Safety Officer – Rahul Sharma', 'Safety Supervisor – Priya Patel', 'HSE Lead – Amit Verma', 'HSSE Manager – Neha Singh', 'Safety Inspector – Ravi Kumar'] },
    { group: 'HR Team', items: ['HR Manager – Sunita Joshi', 'HR Executive – Deepak Nair', 'HR Coordinator – Anjali Mehta'] },
    { group: 'Site Management', items: ['Site Engineer – Vikram Rao', 'Project Manager – Suresh Pillai', 'Site Supervisor – Mohan Das'] },
    { group: 'Custom', items: ['Other / Custom Trainer...'] },
  ]

  // Canvas Drawing Handlers
  const startCanvasDraw = (e) => {
    const canvas = canvasRef.current; if (!canvas) return
    const ctx = canvas.getContext('2d')
    const rect = canvas.getBoundingClientRect()
    const x = (e.clientX || e.touches?.[0]?.clientX) - rect.left
    const y = (e.clientY || e.touches?.[0]?.clientY) - rect.top
    ctx.beginPath(); ctx.moveTo(x, y)
    isDrawing.current = true
  }

  const doCanvasDraw = (e) => {
    if (!isDrawing.current) return
    const canvas = canvasRef.current; if (!canvas) return
    const ctx = canvas.getContext('2d')
    const rect = canvas.getBoundingClientRect()
    const x = (e.clientX || e.touches?.[0]?.clientX) - rect.left
    const y = (e.clientY || e.touches?.[0]?.clientY) - rect.top
    ctx.lineTo(x, y); ctx.stroke()
  }

  const stopCanvasDraw = () => {
    isDrawing.current = false
  }

  const clearCanvas = () => {
    const canvas = canvasRef.current; if (!canvas) return
    const ctx = canvas.getContext('2d')
    ctx.clearRect(0, 0, canvas.width, canvas.height)
  }

  const confirmWorkerProof = (wid) => {
    const canvas = canvasRef.current
    if (!canvas) return
    const url = canvas.toDataURL('image/png')
    setWorkerProofs(p => ({
      ...p,
      [wid]: { ...p[wid], sigData: url, done: true }
    }))
    clearCanvas()

    // Auto-open next pending worker card
    const pending = workers.find(w => w.id !== wid && !workerProofs[w.id]?.done)
    if (pending) {
      setActiveWid(pending.id)
    }
  }

  // Count done
  const signedCount = Object.values(workerProofs).filter(w => w.done).length
  const allSigned = signedCount === workers.length

  const saveGroupInduction = async () => {
    const finalTrainer = f.trainer === 'Other / Custom Trainer...' ? f.custom_trainer : f.trainer
    if (!finalTrainer.trim()) { alert('Trainer Name is required.'); return }
    if (!f.location.trim()) { alert('Location is required.'); return }
    if (!allSigned) { alert('All selected workers must confirm signature first.'); return }

    setSaving(true)
    try {
      const now = new Date()
      let count = 0
      for (const w of workers) {
        count++
        setProgressMsg(`Saving worker ${count}/${workers.length}: ${w.name}...`)
        const proof = workerProofs[w.id]
        const payload = {
          induction_type: f.induction_type,
          trainer: finalTrainer,
          location: f.location,
          start_time: f.start_time || now.toISOString(),
          end_time: now.toISOString(),
          duration_minutes: f.duration_minutes || 15,
          photo_data: f.photo_data,
          signature_data: proof?.proofType === 'sig' ? proof?.sigData : null,
          thumb_data: proof?.proofType === 'thumb' ? proof?.sigData : null,
          topics: ['Site Safety Rules', 'PPE Usage', 'Emergency Response'],
          passed: true,
        }
        await api.workers.saveInduction(w.id, payload)
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

  const markGroupSkip = async () => {
    if (!window.confirm(`Skip HSSE Induction for all ${workers.length} selected workers?`)) return
    setSaving(true)
    try {
      let count = 0
      for (const w of workers) {
        count++
        setProgressMsg(`Skipping worker ${count}/${workers.length}: ${w.name}...`)
        await api.workers.saveInduction(w.id, { induction_type: 'skip' })
      }
      onCompleted()
    } catch (e) {
      alert(e?.response?.data?.message || 'Group skip failed')
    } finally {
      setSaving(false)
      setProgressMsg('')
    }
  }

  return (
    <Overlay onClose={() => !saving && onClose()} width={820}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
        <div>
          <h2 style={{ fontSize: 18, fontWeight: 900, color: 'var(--text-h)', margin: 0 }}>
            👥 Group Induction Session ({workers.length} Workers Selected)
          </h2>
          <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>Apply single session details &amp; collect individual signatures</span>
        </div>
        <button type="button" onClick={markGroupSkip} style={{ padding: '6px 12px', borderRadius: 8, background: '#f59e0b', color: '#fff', border: 'none', cursor: 'pointer', fontWeight: 800, fontSize: 11.5 }}>
          ⏩ Skip All ({workers.length})
        </button>
      </div>

      {/* Session Details */}
      <div style={{ padding: 14, borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)', marginBottom: 16 }}>
        <strong style={{ fontSize: 12.5, color: '#0284c7', display: 'block', marginBottom: 10 }}>📋 Session Details (Applies to all selected workers)</strong>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 12, marginBottom: 10 }}>
          <Field label="Induction Type *">
            <SelectInput value={f.induction_type} onChange={set('induction_type')} pairs options={[
              ['General Safety', 'General Safety'],
              ['Activity Specific', 'Activity Specific'],
              ['Site Specific', 'Site Specific'],
              ['Client Specific', 'Client Specific'],
              ['Emergency & Evacuation', 'Emergency & Evacuation'],
              ['Fire Safety', 'Fire Safety'],
              ['PPE Usage', 'PPE Usage'],
              ['Toolbox Talk', 'Toolbox Talk'],
            ]} />
          </Field>
          <Field label="Trainer *">
            <select value={f.trainer} onChange={set('trainer')} style={inputStyle}>
              {TRAINER_PRESETS.map(grp => (
                <optgroup key={grp.group} label={grp.group}>
                  {grp.items.map(item => <option key={item} value={item}>{item}</option>)}
                </optgroup>
              ))}
            </select>
          </Field>
          <Field label="Location *">
            <TextInput value={f.location} onChange={set('location')} placeholder="e.g. Site Office" />
          </Field>
        </div>
      </div>

      {/* Signature Progress Bar */}
      <div style={{ padding: 12, borderRadius: 10, background: 'linear-gradient(135deg, #f3e8ff, #e9d5ff)', border: '1px solid #c084fc', marginBottom: 16 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
          <strong style={{ fontSize: 12.5, color: '#6b21a8' }}>✍ Worker Signatures Progress</strong>
          <span style={{ fontSize: 12, fontWeight: 900, color: '#6b21a8' }}>{signedCount} / {workers.length} Signed</span>
        </div>
        <div style={{ height: 8, borderRadius: 999, background: '#d8b4fe', overflow: 'hidden' }}>
          <div style={{ height: '100%', width: `${Math.round((signedCount / workers.length) * 100)}%`, background: 'linear-gradient(90deg, #10b981, #059669)', transition: 'width 0.3s' }} />
        </div>
      </div>

      {/* Per-Worker Accordion Cards */}
      <div style={{ maxHeight: 340, overflowY: 'auto', marginBottom: 16 }}>
        {workers.map(w => {
          const proof = workerProofs[w.id] || {}
          const isActive = activeWid === w.id
          return (
            <div key={w.id} style={{ borderRadius: 10, border: proof.done ? '2px solid #10b981' : isActive ? '2px solid #0284c7' : '1px solid var(--border)', marginBottom: 8, overflow: 'hidden', background: 'var(--bg-card)' }}>
              <div onClick={() => setActiveWid(isActive ? null : w.id)} style={{ padding: '10px 14px', background: proof.done ? '#dcfce7' : isActive ? '#e0f2fe' : 'var(--bg-input)', cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                <div>
                  <strong style={{ fontSize: 13, color: 'var(--text-h)' }}>{w.name}</strong>
                  <span style={{ fontSize: 11, color: 'var(--text-muted)', marginLeft: 8 }}>({w.worker_code || 'W-0001'})</span>
                </div>
                <span style={{ fontSize: 11, fontWeight: 800, padding: '2px 10px', borderRadius: 20, background: proof.done ? '#10b981' : '#f59e0b', color: '#fff' }}>
                  {proof.done ? '✓ Signed' : 'Pending Signature'}
                </span>
              </div>

              {isActive && (
                <div style={{ padding: 14 }}>
                  <div style={{ display: 'flex', gap: 8, marginBottom: 10 }}>
                    <button type="button" onClick={() => setWorkerProofs(p => ({ ...p, [w.id]: { ...p[w.id], proofType: 'sig' } }))} style={{ padding: '3px 10px', borderRadius: 14, border: 'none', background: proof.proofType === 'sig' ? '#7c3aed' : 'var(--bg-input)', color: proof.proofType === 'sig' ? '#fff' : 'var(--text-muted)', fontWeight: 800, fontSize: 10.5, cursor: 'pointer' }}>✍ Signature</button>
                    <button type="button" onClick={() => setWorkerProofs(p => ({ ...p, [w.id]: { ...p[w.id], proofType: 'thumb' } }))} style={{ padding: '3px 10px', borderRadius: 14, border: 'none', background: proof.proofType === 'thumb' ? '#7c3aed' : 'var(--bg-input)', color: proof.proofType === 'thumb' ? '#fff' : 'var(--text-muted)', fontWeight: 800, fontSize: 10.5, cursor: 'pointer' }}>👍 Thumb</button>
                  </div>

                  <canvas ref={canvasRef} width={500} height={120} onMouseDown={startCanvasDraw} onMouseMove={doCanvasDraw} onMouseUp={stopCanvasDraw} onMouseLeave={stopCanvasDraw} onTouchStart={startCanvasDraw} onTouchMove={doCanvasDraw} onTouchEnd={stopCanvasDraw} style={{ background: '#fff', border: '2px dashed var(--border)', borderRadius: 8, cursor: 'crosshair', display: 'block', marginBottom: 8 }} />

                  <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
                    <button type="button" onClick={clearCanvas} style={{ padding: '4px 10px', borderRadius: 6, background: '#ef4444', color: '#fff', border: 'none', cursor: 'pointer', fontSize: 11, fontWeight: 800 }}>Clear</button>
                    <button type="button" onClick={() => confirmWorkerProof(w.id)} style={{ padding: '6px 14px', borderRadius: 6, background: '#10b981', color: '#fff', border: 'none', cursor: 'pointer', fontSize: 11.5, fontWeight: 800 }}>Confirm Signature for {w.name}</button>
                  </div>
                </div>
              )}
            </div>
          )
        })}
      </div>

      {progressMsg && (
        <div style={{ padding: '8px 12px', borderRadius: 8, background: '#e0f2fe', color: '#0369a1', fontSize: 12, fontWeight: 700, marginBottom: 12 }}>
          ⏳ {progressMsg}
        </div>
      )}

      <ModalFooter onClose={onClose} onConfirm={saveGroupInduction} loading={saving} disabled={!allSigned} confirmLabel={`Complete Group Induction (${workers.length} Workers)`} />
    </Overlay>
  )
}

function BulkUploadModal({ vendorId, onClose, onUploaded, api, isPortal }) {
  const [file, setFile] = useState(null)
  const [vid, setVid] = useState(vendorId ? String(vendorId) : '')
  const [vendors, setVendors] = useState([])
  const [uploading, setUploading] = useState(false)
  const [result, setResult] = useState(null)

  const downloadSampleCsv = () => {
    const headers = "Full Name,Gender,DOB,Mobile,Blood Group,Designation,Skill Category,Aadhaar Number\n"
    const sampleRows = "Suresh Patil,Male,1990-05-15,9876543210,B+,Electrician,Skilled,123456789012\n"
                     + "Ramesh Kumar,Male,1992-08-20,9876543211,O+,Mason,Semi Skilled,234567890123\n"
                     + "Priya Sharma,Female,1995-02-10,9876543212,A+,Helper,Unskilled,345678901234"
    const blob = new Blob([headers + sampleRows], { type: 'text/csv;charset=utf-8;' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement("a")
    link.setAttribute("href", url)
    link.setAttribute("download", "workforce_bulk_upload_sample.csv")
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
  }

  useEffect(() => {
    if (!isPortal && !vendorId) {
      api.vendors.list().then(res => setVendors(Array.isArray(res?.data ?? res) ? (res.data ?? res) : [])).catch(() => {})
    }
  }, [isPortal, vendorId, api])

  const doUpload = async () => {
    if (!file) { alert('Please select a CSV or Excel file.'); return }
    const targetVid = isPortal ? 1 : Number(vid || vendorId)
    if (!targetVid) { alert('Please select an employing vendor.'); return }

    setUploading(true)
    try {
      const res = await api.workers.uploadWorkers(file, targetVid)
      setResult(res)
    } catch (e) {
      alert(e?.response?.data?.message || 'Bulk upload failed')
    } finally {
      setUploading(false)
    }
  }

  return (
    <Overlay onClose={() => !uploading && onClose()} width={640}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>Bulk Worker Upload</h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '0 0 16px' }}>
        Upload a CSV, XLS, XLSX, or ZIP archive file. Columns: Full Name, Gender, DOB, Mobile, Blood Group, Designation, Skill Category, Aadhaar Number, Photo Filename (Optional).
      </p>

      {/* Download Sample Template Banner */}
      <div style={{ padding: '10px 14px', borderRadius: 10, background: '#f0f9ff', border: '1px solid #bae6fd', marginBottom: 16, display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 10 }}>
        <div>
          <strong style={{ color: '#0369a1', fontSize: 12.5, display: 'block' }}>Need an example template file?</strong>
          <span style={{ fontSize: 11.5, color: '#0284c7' }}>Download sample CSV pre-formatted with required headers and example rows.</span>
        </div>
        <button type="button" onClick={downloadSampleCsv} style={{ padding: '6px 12px', borderRadius: 8, background: '#0284c7', color: '#fff', fontWeight: 800, border: 'none', cursor: 'pointer', fontSize: 12, display: 'inline-flex', alignItems: 'center', gap: 6, whiteSpace: 'nowrap' }}>
          📥 Download Sample Template (.csv)
        </button>
      </div>

      {/* ZIP Photo Instructions Box */}
      <div style={{ padding: '10px 14px', borderRadius: 10, background: 'var(--bg-input)', border: '1px dashed var(--border)', marginBottom: 16, fontSize: 12, color: 'var(--text-muted)' }}>
        💡 <strong>How to upload worker photos in bulk:</strong> Create a <strong style={{ color: 'var(--text-h)' }}>ZIP file</strong> containing your <code style={{ color: '#a78bfa' }}>workers.csv</code> and a <code style={{ color: '#a78bfa' }}>photos/</code> folder with image files named by <strong>Aadhaar Number</strong> (e.g. <code style={{ color: '#10b981' }}>123456789012.jpg</code>) or <strong>Worker Name</strong> (e.g. <code style={{ color: '#10b981' }}>Suresh_Patil.jpg</code>).
      </div>

      {!isPortal && !vendorId && (
        <Field label="Employing Vendor *" full>
          <SelectInput value={vid} onChange={e => setVid(e.target.value)} pairs
            options={[['', 'Select vendor…'], ...vendors.map(v => [String(v.id), `${v.company_name} · ${v.status_label || v.status}`])]} />
        </Field>
      )}

      {!result ? (
        <Field label="Select Worker File (.csv, .xls, .xlsx, .zip) *" full>
          <input type="file" accept=".csv,.xls,.xlsx,.zip" onChange={e => setFile(e.target.files[0])} style={{ ...inputStyle, padding: 8 }} />
        </Field>
      ) : (
        <div style={{ padding: 16, borderRadius: 12, background: result.status === 'success' ? '#ecfdf5' : '#fef2f2', border: `1px solid ${result.status === 'success' ? '#6ee7b7' : '#fca5a5'}`, marginBottom: 16 }}>
          <strong style={{ color: result.status === 'success' ? '#047857' : '#991b1b', fontSize: 14, display: 'block', marginBottom: 6 }}>
            {result.message}
          </strong>
          {result.errors && result.errors.length > 0 && (
            <ul style={{ margin: '8px 0 0', paddingLeft: 18, fontSize: 12, color: '#b91c1c' }}>
              {result.errors.map((err, i) => <li key={i}>{err}</li>)}
            </ul>
          )}
        </div>
      )}

      <ModalFooter
        onClose={onClose}
        onConfirm={result ? onUploaded : doUpload}
        loading={uploading}
        confirmLabel={result ? 'Done' : 'Upload & Process'}
      />
    </Overlay>
  )
}

function CreateModal({ vendorId, onClose, onCreated, api, isPortal }) {
  const [vendors, setVendors] = useState([])
  const [f, setF] = useState({
    vendor_id: vendorId ? String(vendorId) : '',
    name: '', email: '', mobile: '', gender: 'Male', dob: '', age_reason: '',
    blood_group: '', designation: 'Helper', skill_category: 'Unskilled', aadhar_number: '', photo_file: null, photo_preview: null
  })
  const [saving, setSaving] = useState(false)
  const [showCam, setShowCam] = useState(false)
  const videoRef = useRef(null)
  const canvasRef = useRef(null)

  useEffect(() => {
    let stream = null
    if (showCam) {
      navigator.mediaDevices?.getUserMedia({ video: true })
        .then(s => {
          stream = s
          if (videoRef.current) videoRef.current.srcObject = s
        })
        .catch(err => alert('Unable to access camera: ' + err.message))
    }
    return () => {
      if (stream) stream.getTracks().forEach(t => t.stop())
    }
  }, [showCam])

  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))

  useEffect(() => {
    if (!isPortal) {
      api.vendors.list().then(res => setVendors(Array.isArray(res?.data ?? res) ? (res.data ?? res) : [])).catch(() => {})
    }
  }, [isPortal, api])

  const age = f.dob ? Math.floor((Date.now() - new Date(f.dob)) / 31557600000) : null
  const isAgeException = age !== null && (age < 18 || age > 60)
  const chosen = vendors.find(v => String(v.id) === String(f.vendor_id))

  const create = async () => {
    if (!isPortal && !f.vendor_id) { alert('Vendor is required.'); return }
    if (!f.name?.trim()) { alert('Full Name is required.'); return }
    if (!f.gender) { alert('Gender is required.'); return }
    if (!f.dob) { alert('Date of Birth is required.'); return }
    if (isAgeException && !f.age_reason?.trim()) {
      alert('Age Exception Reason is required for underage (<18) or overage (>60) workers.')
      return
    }
    if (!f.designation) { alert('Designation is required.'); return }
    if (!f.skill_category) { alert('Skill Category is required.'); return }

    setSaving(true)
    try {
      const payload = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== null))
      const body = isPortal
        ? payload
        : { ...payload, vendor_id: Number(f.vendor_id) }
      const w = await api.workers.create(body)
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
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 16px' }}>Step 1 of 5 — Personal, Work Details &amp; Aadhaar.</p>

      {chosen && chosen.status !== 'Active' && (
        <InfoBox tone="danger">
          <strong>{chosen.company_name}</strong> is {chosen.status_label || chosen.status}. You can register workers now,
          but no badge can be issued until that vendor's onboarding is approved.
        </InfoBox>
      )}

      {!isPortal && (
        <Field label="Employing Vendor *" full>
          {vendorId ? (
            <div style={{ ...inputStyle, display: 'flex', alignItems: 'center', opacity: 0.9 }}>
              {chosen ? `${chosen.company_name} · ${chosen.status_label || chosen.status}` : 'Loading vendor…'}
            </div>
          ) : (
            <SelectInput value={f.vendor_id} onChange={set('vendor_id')} pairs
              options={[['', 'Select vendor…'], ...vendors.map(v => [String(v.id), `${v.company_name} · ${v.status_label || v.status}`])]} />
          )}
        </Field>
      )}

      {/* Personal Info Section */}
      <div style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 10, borderBottom: '1px solid var(--border)', paddingBottom: 4, marginTop: 10 }}>
        📇 Personal Information
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 12, marginBottom: 14 }}>
        <Field label="Full Name *"><TextInput value={f.name} onChange={set('name')} placeholder="e.g. Suresh Patil" /></Field>
        <Field label="Email"><TextInput type="email" value={f.email} onChange={set('email')} placeholder="worker@email.com" /></Field>
        <Field label="Mobile"><TextInput type="tel" value={f.mobile} onChange={set('mobile')} maxLength={15} placeholder="10-digit mobile" /></Field>

        <Field label="Gender *"><SelectInput value={f.gender} onChange={set('gender')} pairs options={[['Male', 'Male'], ['Female', 'Female'], ['Transgender', 'Transgender']]} /></Field>
        <Field label="Date of Birth *"><TextInput type="date" value={f.dob} onChange={set('dob')} /></Field>
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
          <Field label="Age Exception Reason *" full>
            <TextInput value={f.age_reason} onChange={set('age_reason')} placeholder="Provide reason for underage / overage exception..." />
          </Field>
        )}

        <Field label="Blood Group"><SelectInput value={f.blood_group} onChange={set('blood_group')} pairs options={[['', '-- Select --'], ['A+', 'A+'], ['A-', 'A-'], ['B+', 'B+'], ['B-', 'B-'], ['AB+', 'AB+'], ['AB-', 'AB-'], ['O+', 'O+'], ['O-', 'O-']]} /></Field>
      </div>

      {/* Work Details Section */}
      <div style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 10, borderBottom: '1px solid var(--border)', paddingBottom: 4, marginTop: 14 }}>
        💼 Work Details
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 12, marginBottom: 14 }}>
        <Field label="Designation *"><SelectInput value={f.designation} onChange={set('designation')} pairs options={[['Mason', 'Mason'], ['Helper', 'Helper'], ['Electrician', 'Electrician'], ['Carpenter', 'Carpenter'], ['Plumber', 'Plumber'], ['Welder', 'Welder'], ['Supervisor', 'Supervisor'], ['Operator', 'Operator'], ['Fitter', 'Fitter'], ['Rigger', 'Rigger']]} /></Field>
        <Field label="Skill Category *"><SelectInput value={f.skill_category} onChange={set('skill_category')} pairs options={[['Skilled', 'Skilled'], ['Semi Skilled', 'Semi Skilled'], ['Unskilled', 'Unskilled']]} /></Field>
        <Field label="Aadhaar Number (12 digits)"><TextInput value={f.aadhar_number} onChange={set('aadhar_number')} maxLength={12} placeholder="12-digit Aadhaar" /></Field>
      </div>

      {/* Profile Photo & Camera Section */}
      <div style={{ fontSize: 11, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 10, borderBottom: '1px solid var(--border)', paddingBottom: 4, marginTop: 14 }}>
        📷 Profile Photo
      </div>

      <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 16 }}>
        <div style={{ width: 68, height: 68, borderRadius: 999, border: '2px dashed var(--border)', background: 'var(--bg-input)', display: 'flex', alignItems: 'center', justifyContent: 'center', overflow: 'hidden', flexShrink: 0 }}>
          {f.photo_preview ? (
            <img src={f.photo_preview} alt="Preview" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
          ) : (
            <span style={{ fontSize: 24, opacity: 0.5 }}>👤</span>
          )}
        </div>

        <div style={{ flex: 1 }}>
          <label style={{ fontSize: 12, color: 'var(--text-muted)', fontWeight: 600, display: 'block', marginBottom: 6 }}>Choose File (JPG/PNG, max 2MB) or Use Live Camera</label>
          <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
            <input type="file" accept="image/jpeg,image/png" onChange={e => {
              const file = e.target.files[0]
              if (file) {
                const reader = new FileReader()
                reader.onload = ev => setF(p => ({ ...p, photo_preview: ev.target.result, photo_file: file }))
                reader.readAsDataURL(file)
              }
            }} style={{ ...inputStyle, padding: 6, flex: 1 }} />

            <button type="button" onClick={() => setShowCam(true)} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 8, background: '#7C3AED', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 12.5, whiteSpace: 'nowrap' }}>
              📷 Use Camera
            </button>
          </div>
        </div>
      </div>

      {/* Live Camera Modal */}
      {showCam && (
        <Overlay onClose={() => setShowCam(false)} width={440}>
          <h3 style={{ color: 'var(--text-h)', margin: '0 0 12px', fontSize: 16, fontWeight: 800, display: 'flex', alignItems: 'center', gap: 8 }}>
            📷 Take Photo
          </h3>
          <div style={{ background: '#000', borderRadius: 12, overflow: 'hidden', position: 'relative', minHeight: 240, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
            <video ref={videoRef} autoPlay playsInline style={{ width: '100%', maxHeight: 300, objectFit: 'cover' }} />
            <canvas ref={canvasRef} style={{ display: 'none' }} />
          </div>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 14 }}>
            <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>Position worker facing camera</span>
            <div style={{ display: 'flex', gap: 8 }}>
              <button type="button" onClick={() => setShowCam(false)} style={{ padding: '8px 14px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer' }}>Cancel</button>
              <button type="button" onClick={() => {
                if (videoRef.current && canvasRef.current) {
                  const video = videoRef.current
                  const canvas = canvasRef.current
                  canvas.width = video.videoWidth || 320
                  canvas.height = video.videoHeight || 240
                  canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height)
                  const url = canvas.toDataURL('image/png')
                  setF(p => ({ ...p, photo_preview: url, photo_file: url }))
                  setShowCam(false)
                }
              }} style={{ padding: '8px 16px', borderRadius: 8, background: '#10b981', color: '#fff', fontWeight: 800, border: 'none', cursor: 'pointer' }}>📸 Capture</button>
            </div>
          </div>
        </Overlay>
      )}

      <ModalFooter onClose={onClose} onConfirm={create} loading={saving} disabled={(!isPortal && !f.vendor_id) || !f.name || !f.dob || (isAgeException && !f.age_reason)} confirmLabel="Save &amp; Continue to Step 2 →" />
    </Overlay>
  )
}
