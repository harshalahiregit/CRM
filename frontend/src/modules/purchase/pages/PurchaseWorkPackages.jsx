import { useState, useEffect, useCallback } from 'react'
import {
  Boxes, Plus, RefreshCw, Pencil, Trash2, ChevronDown, ChevronRight,
  ListTree, Users, UserPlus, AlertTriangle, X,
} from 'lucide-react'
import api from '@/lib/api'
import { purchaseApi } from '@/services/purchaseApi'
import { fmtDate } from '../constants'
import LoadError from '@/components/ui/LoadError'
import { Empty } from './PurchaseGateLog'
import {
  KIT3D_STYLE, inputStyle, Overlay, ModalFooter,
  Field, TextInput, SelectInput, StatusBadge,
} from '@/components/ui/kit3d'

/**
 * Purchase Work Packages & Activities — the Purchase mirror of TpvWorkPackages.
 *
 * The accountability spine: Vendor → Work Package → Activity → Workforce. A
 * package is the named parcel of scope a vendor is actually on site to deliver;
 * the activities inside it are what the competency and permit rules gate on,
 * because welding and scaffolding in one package demand different tickets and a
 * package-level requirement would either over- or under-gate everybody on it.
 *
 * Every route behind this screen sits in the role:admin,staff group, so unlike
 * the permit register there is no admin-only decision to hide from staff — the
 * whole page is one permission, and nothing is offered that would then 403.
 */

// purchaseApi has no work-packages namespace yet, so the calls live here in
// exactly the shape it uses (`api.<verb>(…).then(r => r.data)`) — they lift into
// services/purchaseApi.js unchanged the moment that namespace lands.
const wpApi = {
  list:           (params = {}) => api.get('/purchase/work-packages', { params }).then(r => r.data),
  get:            (id)          => api.get(`/purchase/work-packages/${id}`).then(r => r.data),
  create:         (data)        => api.post('/purchase/work-packages', data).then(r => r.data),
  update:         (id, data)    => api.put(`/purchase/work-packages/${id}`, data).then(r => r.data),
  delete:         (id)          => api.delete(`/purchase/work-packages/${id}`).then(r => r.data),
  // sort_order is assigned on append server-side, so two people adding
  // activities to the same package cannot both claim the same position — the
  // client never sends it.
  addActivity:    (id, data)    => api.post(`/purchase/work-packages/${id}/activities`, data).then(r => r.data),
  updateActivity: (id, data)    => api.put(`/purchase/activities/${id}`, data).then(r => r.data),
  deleteActivity: (id)          => api.delete(`/purchase/activities/${id}`).then(r => r.data),
  // null clears the assignment. A package from another tenant is refused
  // server-side rather than silently orphaning the worker.
  assignWorker:   (workerId, workPackageId) =>
    api.post(`/purchase/workforce/workers/${workerId}/work-package`, { work_package_id: workPackageId }).then(r => r.data),
}

const pretty = (s) => String(s || '').replace(/_/g, ' ')

// Purchase list endpoints answer either a bare array or a { data: [] } envelope
// depending on how far the service unwrapped it — the workforce register's idiom.
const asArray = (r) => (Array.isArray(r?.data ?? r) ? (r.data ?? r) : [])

// PurchaseWorkPackage::STATUSES — the exact strings the controller validates
// against, humanised only for display. Purchase ends the lifecycle at Cancelled
// where TPV ends it at Closed, so the vocabulary here follows Purchase.
const WP_STATUSES = ['Planned', 'Active', 'On_Hold', 'Completed', 'Cancelled']
const WP_STATUS_CONFIG = {
  Planned:   { label: 'Planned',   color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  Active:    { label: 'Active',    color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  On_Hold:   { label: 'On Hold',   color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Completed: { label: 'Completed', color: '#22c55e', bg: 'rgba(34,197,94,0.15)' },
  Cancelled: { label: 'Cancelled', color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
const wpStatusCfg = (s) =>
  WP_STATUS_CONFIG[s] || { label: pretty(s) || 'Unknown', color: '#94a3b8', bg: 'rgba(148,163,184,0.12)' }

/**
 * Activity status vocabulary.
 *
 * purchase_activities.status is a free-form string(30) with no STATUSES constant
 * behind it, and PurchaseWorkPackageService defaults it to 'Active' on append.
 * So 'Active' leads the list — a select without it would render blank for every
 * activity the server created — and whatever a row actually holds is appended
 * rather than silently rewritten to the first option.
 */
const ACT_STATUSES = ['Active', 'Not_Started', 'In_Progress', 'On_Hold', 'Completed']
const actStatusOptions = (current) =>
  (!current || ACT_STATUSES.includes(current)) ? ACT_STATUSES : [...ACT_STATUSES, current]

// PurchaseWorkPermit::TYPES — the same vocabulary PurchasePermits offers, so an
// activity flagged high-risk names a type the permit register can actually issue.
const PERMIT_TYPES = ['Hot_Work', 'Work_At_Height', 'Confined_Space', 'Electrical', 'Excavation', 'Lifting', 'Isolation', 'Shutdown', 'Critical_Work', 'Other']

// A reset that drops keys turns the controlled permit checkbox into an
// uncontrolled one, so the blank form is a single constant both the initial
// state and every reset spread from.
const BLANK_ACTIVITY = { name: '', required_competency: '', status: 'Active', requires_permit: false, permit_type: '' }

const apiError = (e, fallback) => {
  const errors = e?.response?.data?.errors
  if (errors) return Object.values(errors).flat().join(' ')
  return e?.response?.data?.message || fallback
}

const th = { textAlign: 'left', padding: '10px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
const td = { padding: '11px 12px', fontSize: 12.5, verticalAlign: 'middle' }
const iconBtn = { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', padding: 6, borderRadius: 8, border: 'none', background: 'transparent', color: 'var(--text-muted)', cursor: 'pointer' }

export default function PurchaseWorkPackages() {
  const [rows, setRows]       = useState([])
  const [vendors, setVendors] = useState([])
  const [loading, setLoading] = useState(true)
  const [loadError, setError] = useState(null)
  const [modal, setModal]     = useState(null)     // {} = create, a row = edit
  const [expanded, setExpanded] = useState(null)
  const [filters, setFilters] = useState({ status: '', vendor_id: '' })

  // Loaded once for the filter and the create/edit form. The package's own
  // vendor relation carries id/company_name/code only, which is all the table
  // needs — this list is what makes the vendor CHOOSABLE.
  useEffect(() => {
    purchaseApi.vendors.list({ per_page: 200 })
      .then(res => setVendors(asArray(res)))
      .catch(() => {})
  }, [])

  const load = useCallback(async () => {
    setLoading(true)
    try {
      // Blank filters are dropped rather than posted as '' — an empty status is
      // "no filter", not a package whose status is the empty string.
      const res = await wpApi.list(Object.fromEntries(Object.entries(filters).filter(([, v]) => v)))
      setRows(asArray(res))
      setError(null)
    } catch (e) { setRows([]); setError(e) }
    finally { setLoading(false) }
  }, [filters])
  useEffect(() => { load() }, [load])

  const setFilter = (k) => (e) => setFilters(f => ({ ...f, [k]: e.target.value }))

  const remove = async (wp) => {
    // Deleting a package takes its activities with it and RELEASES every worker
    // assigned to it — PurchaseWorkPackageService clears work_package_id first
    // so nobody is left accountable to a row that no longer exists. Say that
    // before it happens rather than after.
    if (!window.confirm(`Delete ${wp.reference || 'this work package'}? Its activities go with it, and any worker assigned to it is released.`)) return
    try {
      await wpApi.delete(wp.id)
      if (expanded === wp.id) setExpanded(null)
      load()
    } catch (e) { alert(apiError(e, 'Could not delete this work package.')) }
  }

  return (
    <div style={{ padding: '24px 32px' }}>
      <style>{KIT3D_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <Boxes size={22} style={{ color: '#7C3AED' }} />
          <div>
            <h1 style={{ margin: 0, fontSize: 22, fontWeight: 900, color: 'var(--text-h)', letterSpacing: '-0.02em' }}>Work Packages</h1>
            <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--text-muted)' }}>Vendor → Work Package → Activity → Workforce</p>
          </div>
        </div>
        <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
          <button onClick={load} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', fontWeight: 700, cursor: 'pointer', fontSize: 13 }}>
            <RefreshCw size={14} />
          </button>
          <button onClick={() => setModal({})} style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '9px 18px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }}>
            <Plus size={15} /> New Work Package
          </button>
        </div>
      </div>

      {/* Both filters are server-side parameters on /purchase/work-packages, so
          the rows and the count of them always agree. */}
      <div className="pr-glass" style={{ padding: '14px 18px', marginBottom: 18, display: 'flex', gap: 12, alignItems: 'center', flexWrap: 'wrap', borderRadius: 14 }}>
        <select value={filters.status} onChange={setFilter('status')} style={{ ...inputStyle, width: 'auto', cursor: 'pointer' }}>
          <option value="">All statuses</option>
          {WP_STATUSES.map(s => <option key={s} value={s}>{pretty(s)}</option>)}
        </select>
        <select value={filters.vendor_id} onChange={setFilter('vendor_id')} style={{ ...inputStyle, width: 'auto', minWidth: 200, cursor: 'pointer' }}>
          <option value="">All vendors</option>
          {vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}</option>)}
        </select>
        <span style={{ marginLeft: 'auto', fontSize: 11.5, color: 'var(--text-muted)' }}>
          {rows.length} package{rows.length === 1 ? '' : 's'}
        </span>
      </div>

      {loadError ? (
        <LoadError error={loadError} onRetry={load} title="Could not load work packages" />
      ) : loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}>Loading work packages…</div>
      ) : rows.length === 0 ? (
        <Empty icon={Boxes} title="No work packages yet"
          hint="A work package is the parcel of scope a vendor is on site to deliver — create one, then add the activities its workers will be authorised against." />
      ) : (
        <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead>
                <tr>{['', 'Reference', 'Vendor', 'Name', 'Activities', 'Workers', 'Period', 'Status', ''].map((h, i) => <th key={i} style={th}>{h}</th>)}</tr>
              </thead>
              <tbody>
                {rows.map(wp => (
                  <WpRow key={wp.id} wp={wp}
                    expanded={expanded === wp.id}
                    onToggle={() => setExpanded(expanded === wp.id ? null : wp.id)}
                    onEdit={() => setModal(wp)} onDelete={() => remove(wp)} onChanged={load} />
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {modal && (
        <WpModal row={modal.id ? modal : null} vendors={vendors}
          onClose={() => setModal(null)} onSaved={() => { setModal(null); load() }} />
      )}
    </div>
  )
}

/* ── One package, and the activities/workers folded under it ──────────────── */
function WpRow({ wp, expanded, onToggle, onEdit, onDelete, onChanged }) {
  // The list eager-loads `activities` in full rather than a count, so the column
  // reads the array it was given instead of an activities_count that is not sent.
  const activities = wp.activities || []
  return (
    <>
      <tr className="pr-li-row" style={{ borderTop: '1px solid var(--border)' }}>
        <td style={td}>
          <button onClick={onToggle} style={iconBtn} title={expanded ? 'Collapse' : 'Expand'}>
            {expanded ? <ChevronDown size={15} /> : <ChevronRight size={15} />}
          </button>
        </td>
        <td style={{ ...td, fontWeight: 700, color: '#a78bfa', whiteSpace: 'nowrap' }}>{wp.reference || '—'}</td>
        <td style={{ ...td, color: 'var(--text-muted)' }}>{wp.vendor?.company_name || '—'}</td>
        <td style={{ ...td, color: 'var(--text-h)', fontWeight: 700 }}>
          {wp.name}
          {wp.location && <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 500 }}>{wp.location}</div>}
        </td>
        <td style={{ ...td, color: 'var(--text-muted)' }}>{activities.length}</td>
        <td style={{ ...td, color: 'var(--text-muted)' }}>{wp.workers_count ?? 0}</td>
        <td style={{ ...td, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>
          {wp.start_date || wp.end_date ? `${fmtDate(wp.start_date)} → ${fmtDate(wp.end_date)}` : 'Open-ended'}
        </td>
        <td style={td}><StatusBadge cfg={wpStatusCfg(wp.status)} /></td>
        <td style={{ ...td, whiteSpace: 'nowrap' }}>
          <button onClick={onEdit} style={iconBtn} title="Edit"><Pencil size={14} /></button>
          <button onClick={onDelete} style={iconBtn} title="Delete"><Trash2 size={14} /></button>
        </td>
      </tr>
      {expanded && (
        <tr>
          <td colSpan={9} style={{ padding: '0 14px 14px', background: 'var(--bg-input)' }}>
            <ActivitiesPanel wp={wp} onChanged={onChanged} />
          </td>
        </tr>
      )}
    </>
  )
}

/**
 * The inside of a package: what is to be done, and who is on it.
 *
 * The row already carries `activities`, but the detail endpoint is what also
 * returns `workers` — and re-reading after every write means the panel shows
 * what the server stored rather than what the form hoped it would.
 */
function ActivitiesPanel({ wp, onChanged }) {
  const [detail, setDetail]   = useState(null)
  const [roster, setRoster]   = useState([])          // candidates for assignment
  const [newAct, setNewAct]   = useState(BLANK_ACTIVITY)
  const [assignId, setAssign] = useState('')
  const [busy, setBusy]       = useState(false)
  const [err, setErr]         = useState('')
  const [loadError, setLoadError] = useState(null)

  const load = useCallback(() => {
    setLoadError(null)
    wpApi.get(wp.id)
      .then(d => setDetail(d?.data ?? d))
      .catch(e => { setDetail({ activities: [], workers: [] }); setLoadError(e) })
  }, [wp.id])
  useEffect(() => { load() }, [load])

  // Who could be put on this package. Scoped to the package's own vendor where
  // it has one; purchase_work_packages.purchase_vendor_id is NULLABLE, so a
  // package raised before the vendor is settled draws from the whole register —
  // which is the only honest answer when nobody yet owns the scope.
  useEffect(() => {
    purchaseApi.workforce.workers(wp.purchase_vendor_id ? { vendor_id: wp.purchase_vendor_id } : {})
      .then(res => setRoster(asArray(res)))
      .catch(() => setRoster([]))
  }, [wp.purchase_vendor_id])

  // One runner for every write: the panel reloads from the server and the table
  // above is told, so activities_count and workers_count never go stale.
  const act = async (fn) => {
    setBusy(true); setErr('')
    try { await fn(); load(); onChanged?.() }
    catch (e) { setErr(apiError(e, 'That did not save.')) }
    finally { setBusy(false) }
  }

  const addActivity = () => {
    if (!newAct.name.trim()) { setErr('An activity needs a name.'); return }
    act(async () => {
      // permit_type only travels with the flag it qualifies — a type left over
      // from an unticked checkbox would name a permit nothing requires.
      await wpApi.addActivity(wp.id, {
        name:                newAct.name.trim(),
        required_competency: newAct.required_competency.trim() || null,
        status:              newAct.status,
        requires_permit:     newAct.requires_permit,
        permit_type:         newAct.requires_permit ? (newAct.permit_type || null) : null,
      })
      setNewAct({ ...BLANK_ACTIVITY })
    })
  }

  const assignWorker = () => {
    if (!assignId) return
    act(async () => {
      await wpApi.assignWorker(assignId, wp.id)
      setAssign('')
      // The candidate list carries work_package_id, so it is refreshed too —
      // otherwise the worker just assigned stays offered as assignable.
      const res = await purchaseApi.workforce.workers(wp.purchase_vendor_id ? { vendor_id: wp.purchase_vendor_id } : {})
      setRoster(asArray(res))
    })
  }

  const releaseWorker = (workerId) => act(async () => {
    await wpApi.assignWorker(workerId, null)
    const res = await purchaseApi.workforce.workers(wp.purchase_vendor_id ? { vendor_id: wp.purchase_vendor_id } : {})
    setRoster(asArray(res))
  })

  if (loadError) return <div style={{ paddingTop: 12 }}><LoadError error={loadError} onRetry={load} title="Could not open this work package" /></div>
  if (!detail) return <div style={{ padding: 12, color: 'var(--text-muted)', fontSize: 12.5 }}>Loading activities…</div>

  const activities = detail.activities || []
  const workers    = detail.workers || []
  const onThis     = new Set(workers.map(w => w.id))
  const assignable = roster.filter(w => !onThis.has(w.id))

  return (
    <div style={{ display: 'grid', gridTemplateColumns: '1.6fr 1fr', gap: 16, paddingTop: 12 }}>
      {/* ── Activities ── */}
      <div>
        <PanelHead icon={ListTree} title="Activities" count={activities.length} />

        {activities.length === 0 && (
          <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '0 0 8px' }}>
            No activities yet. Until one exists there is nothing for a competency or a permit to be checked against.
          </p>
        )}

        <div style={{ display: 'flex', flexDirection: 'column', gap: 6, marginBottom: 10 }}>
          {activities.map(a => (
            <div key={a.id} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '7px 10px', borderRadius: 9, background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>{a.name}</div>
                {a.required_competency && (
                  <div style={{ fontSize: 10.5, color: 'var(--text-muted)' }}>needs: {a.required_competency}</div>
                )}
                {/* The activity that demands a permit is the one a glance must
                    catch — the authorisation check reports it as advisory, and
                    the permit's own lifecycle is what actually clears the work. */}
                {a.requires_permit && (
                  <div style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 10.5, color: '#d97706', fontWeight: 700 }}>
                    <AlertTriangle size={11} /> high-risk — permit required{a.permit_type ? `: ${pretty(a.permit_type)}` : ''}
                  </div>
                )}
              </div>
              <select value={a.status || ''} disabled={busy}
                onChange={e => act(() => wpApi.updateActivity(a.id, { status: e.target.value }))}
                style={{ ...inputStyle, width: 'auto', padding: '4px 6px', fontSize: 11, cursor: 'pointer' }}>
                {actStatusOptions(a.status).map(s => <option key={s} value={s}>{pretty(s)}</option>)}
              </select>
              <button onClick={() => act(() => wpApi.deleteActivity(a.id))} disabled={busy} style={iconBtn} title="Delete activity">
                <Trash2 size={13} />
              </button>
            </div>
          ))}
        </div>

        <div style={{ display: 'flex', gap: 6 }}>
          <input value={newAct.name} onChange={e => setNewAct(p => ({ ...p, name: e.target.value }))}
            placeholder="Activity name" maxLength={190} style={{ ...inputStyle, flex: 2, padding: '7px 9px', fontSize: 12.5 }} />
          <input value={newAct.required_competency} onChange={e => setNewAct(p => ({ ...p, required_competency: e.target.value }))}
            placeholder="Required competency" maxLength={120} style={{ ...inputStyle, flex: 2, padding: '7px 9px', fontSize: 12.5 }} />
          <button onClick={addActivity} disabled={busy || !newAct.name.trim()}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 12px', borderRadius: 9, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', fontSize: 12.5, cursor: busy || !newAct.name.trim() ? 'not-allowed' : 'pointer', opacity: busy || !newAct.name.trim() ? 0.6 : 1 }}>
            <Plus size={14} />
          </button>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 6, flexWrap: 'wrap' }}>
          <label style={{ display: 'flex', alignItems: 'center', gap: 5, fontSize: 11.5, color: 'var(--text-muted)', cursor: 'pointer' }}>
            <input type="checkbox" checked={newAct.requires_permit}
              onChange={e => setNewAct(p => ({ ...p, requires_permit: e.target.checked, permit_type: e.target.checked ? p.permit_type : '' }))} />
            High-risk — requires a permit to work
          </label>
          {newAct.requires_permit && (
            <select value={newAct.permit_type} onChange={e => setNewAct(p => ({ ...p, permit_type: e.target.value }))}
              style={{ ...inputStyle, width: 'auto', padding: '4px 6px', fontSize: 11, cursor: 'pointer' }}>
              <option value="">Any permit type</option>
              {PERMIT_TYPES.map(t => <option key={t} value={t}>{pretty(t)}</option>)}
            </select>
          )}
        </div>

        {err && <p style={{ color: '#ef4444', fontSize: 12, margin: '8px 0 0' }}>{err}</p>}
      </div>

      {/* ── Deployed workers ── */}
      <div>
        <PanelHead icon={Users} title="Deployed workers" count={workers.length} />

        {workers.length === 0
          ? <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '0 0 8px' }}>Nobody is assigned to this package yet.</p>
          : workers.map(w => (
            <div key={w.id} style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12.5, color: 'var(--text-h)', padding: '5px 0', borderBottom: '1px solid var(--border)' }}>
              <span style={{ flex: 1, minWidth: 0 }}>
                {w.full_name} <span style={{ color: '#a78bfa', fontSize: 11, fontWeight: 700 }}>{w.worker_code}</span>
              </span>
              <button onClick={() => releaseWorker(w.id)} disabled={busy} style={iconBtn} title="Release from this package">
                <X size={13} />
              </button>
            </div>
          ))}

        {/* Assignment is what turns the advisory "not assigned to a work package"
            check on the authorisation screen into an answer. */}
        <div style={{ display: 'flex', gap: 6, marginTop: 10 }}>
          <select value={assignId} onChange={e => setAssign(e.target.value)} disabled={busy}
            style={{ ...inputStyle, flex: 1, padding: '7px 9px', fontSize: 12, cursor: 'pointer' }}>
            <option value="">{assignable.length ? 'Assign a worker…' : 'No other workers available'}</option>
            {assignable.map(w => (
              <option key={w.id} value={w.id}>
                {w.full_name} · {w.worker_code}{w.work_package_id ? ' (on another package)' : ''}
              </option>
            ))}
          </select>
          <button onClick={assignWorker} disabled={busy || !assignId}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 12px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', fontWeight: 700, fontSize: 12, cursor: busy || !assignId ? 'not-allowed' : 'pointer', opacity: busy || !assignId ? 0.6 : 1 }}>
            <UserPlus size={13} />
          </button>
        </div>
        {!wp.purchase_vendor_id && (
          <p style={{ fontSize: 10.5, color: 'var(--text-muted)', margin: '6px 0 0' }}>
            This package has no vendor, so the list above is the whole workforce rather than one company's people.
          </p>
        )}
      </div>
    </div>
  )
}

function PanelHead({ icon: Icon, title, count }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 7, marginBottom: 8 }}>
      <Icon size={14} style={{ color: '#a78bfa' }} />
      <strong style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{title}</strong>
      {count != null && <span style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)' }}>{count}</span>}
    </div>
  )
}

/* ── Create / edit a package ──────────────────────────────────────────────── */
function WpModal({ row, vendors, onClose, onSaved }) {
  const [f, setF] = useState(() => ({
    // purchase_vendor_id, not vendor_id: purchase_work_packages keys to the
    // Purchase-owned vendor master, and it is NULLABLE — a package can be
    // scoped before anybody has been picked to deliver it.
    purchase_vendor_id: row?.purchase_vendor_id ? String(row.purchase_vendor_id) : '',
    status:      row?.status || 'Planned',
    name:        row?.name || '',
    location:    row?.location || '',
    // date columns arrive as full ISO timestamps from the model cast; the input
    // wants a bare YYYY-MM-DD or it renders empty.
    start_date:  (row?.start_date || '').slice(0, 10),
    end_date:    (row?.end_date || '').slice(0, 10),
    description: row?.description || '',
    scope:       row?.scope || '',
    notes:       row?.notes || '',
  }))
  const [busy, setBusy] = useState(false)
  const [err, setErr]   = useState('')

  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))

  const save = async () => {
    // Mirrored from the controller's rules so the common mistakes are caught
    // before a round trip; the server still validates authoritatively.
    if (!f.name.trim()) { setErr('A name is required — a package nobody can name is not a parcel of scope.'); return }
    if (f.start_date && f.end_date && f.end_date < f.start_date) { setErr('The package cannot end before it starts.'); return }

    setBusy(true); setErr('')
    try {
      // Blanks become null rather than being dropped. On an EDIT a dropped key
      // leaves the old value in place, so a location or a note could never be
      // cleared once set — and every optional column here is nullable, so null
      // is the honest "not supplied".
      const payload = Object.fromEntries(Object.entries(f).map(([k, v]) => [k, v === '' ? null : v]))
      payload.purchase_vendor_id = f.purchase_vendor_id ? Number(f.purchase_vendor_id) : null
      if (row) await wpApi.update(row.id, payload)
      else await wpApi.create(payload)
      onSaved()
    } catch (e) { setErr(apiError(e, 'Could not save this work package.')) }
    finally { setBusy(false) }
  }

  return (
    <Overlay onClose={() => !busy && onClose()} width={640}>
      <h2 style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>
        <Boxes size={18} style={{ color: '#7C3AED' }} /> {row ? `Edit ${row.reference || 'work package'}` : 'New Work Package'}
      </h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 16px' }}>
        The package is the scope. The activities inside it are what a worker is actually authorised — or refused — against.
      </p>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <Field label="Name *" full>
          <TextInput value={f.name} onChange={set('name')} maxLength={190} placeholder="What is this crew here to deliver?" />
        </Field>
        <Field label="Vendor">
          <SelectInput value={f.purchase_vendor_id} onChange={set('purchase_vendor_id')} pairs
            options={[['', 'Not assigned yet'], ...vendors.map(v => [String(v.id), `${v.company_name} · ${v.status_label || v.status}`])]} />
        </Field>
        <Field label="Status">
          <SelectInput value={f.status} onChange={set('status')} pairs options={WP_STATUSES.map(s => [s, pretty(s)])} />
        </Field>
        <Field label="Location" full>
          <TextInput value={f.location} onChange={set('location')} maxLength={190} placeholder="e.g. Block B, Level 3" />
        </Field>
        {/* Date, not datetime: start_date/end_date are DATE columns the model
            casts as dates, so a time typed here would be discarded on save. */}
        <Field label="Start date"><TextInput type="date" value={f.start_date} onChange={set('start_date')} /></Field>
        <Field label="End date"><TextInput type="date" value={f.end_date} onChange={set('end_date')} min={f.start_date || undefined} /></Field>
        <Field label="Description" full>
          <textarea value={f.description} onChange={set('description')} rows={2} style={{ ...inputStyle, resize: 'vertical' }} />
        </Field>
        <Field label="Scope" full>
          <textarea value={f.scope} onChange={set('scope')} rows={2} style={{ ...inputStyle, resize: 'vertical' }}
            placeholder="What is in — and what is deliberately out." />
        </Field>
        <Field label="Notes" full>
          <textarea value={f.notes} onChange={set('notes')} rows={2} style={{ ...inputStyle, resize: 'vertical' }} />
        </Field>
      </div>

      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '12px 0 0' }}>{err}</p>}
      <ModalFooter onClose={onClose} onConfirm={save} loading={busy} disabled={!f.name.trim()}
        confirmLabel={row ? 'Save changes' : 'Create Work Package'} />
    </Overlay>
  )
}
