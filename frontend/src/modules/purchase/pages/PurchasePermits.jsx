import { useState, useEffect, useCallback, useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'
import {
  FileCheck2, Plus, RefreshCw, Loader2, CheckCircle, XCircle,
  PlayCircle, ClipboardList, Clock, AlertTriangle, ShieldAlert,
} from 'lucide-react'
import api from '@/lib/api'
import { purchaseApi } from '@/services/purchaseApi'
import { useAuth } from '@/context/AuthContext'
import { canApprovePR, canManagePR, fmtDate } from '../constants'
import LoadError from '@/components/ui/LoadError'
import { Empty } from './PurchaseGateLog'
import {
  KIT3D_STYLE, inputStyle, Overlay, ModalFooter, InfoBox,
  Field, TextInput, SelectInput, StatusBadge,
} from '@/components/ui/kit3d'

/**
 * Purchase Permit To Work + JSA — the Purchase mirror of TpvPermits.
 *
 * Request a permit, build its Job Safety Analysis, then approve → activate →
 * close. Every guard the backend enforces is stated here BEFORE the button is
 * pressed: PurchasePermitService refuses to approve a permit with no JSA or for
 * a vendor who is not Active, refuses to activate one past its window, and only
 * ever moves Requested → Approved → Active → Closed. A hidden button is not a
 * control, but neither is a 422 the user could not have seen coming.
 */

// purchaseApi has no permits namespace yet, so the calls live here in exactly
// the shape it uses (`api.<verb>(…).then(r => r.data)`) — they lift into
// services/purchaseApi.js unchanged the moment that namespace lands.
const permitsApi = {
  list:     (params = {}) => api.get('/purchase/permits', { params }).then(r => r.data),
  stats:    ()            => api.get('/purchase/permits/stats').then(r => r.data),
  get:      (id)          => api.get(`/purchase/permits/${id}`).then(r => r.data),
  create:   (data)        => api.post('/purchase/permits', data).then(r => r.data),
  // Step numbers are assigned on append server-side, so two people filling the
  // same analysis cannot both claim step 3 — never send step_no.
  addStep:  (id, data)    => api.post(`/purchase/permits/${id}/jsa`, data).then(r => r.data),
  approve:  (id, remarks) => api.post(`/purchase/permits/${id}/approve`, { remarks: remarks || null }).then(r => r.data),
  reject:   (id, remarks) => api.post(`/purchase/permits/${id}/reject`, { remarks }).then(r => r.data),
  activate: (id)          => api.post(`/purchase/permits/${id}/activate`).then(r => r.data),
  close:    (id)          => api.post(`/purchase/permits/${id}/close`).then(r => r.data),
}

// PurchaseWorkPermit::TYPES / ::STATUSES — the exact strings the controller
// validates against, humanised only for display.
const TYPES = ['Hot_Work', 'Work_At_Height', 'Confined_Space', 'Electrical', 'Excavation', 'Lifting', 'Isolation', 'Shutdown', 'Critical_Work', 'Other']
const STATUSES = ['Requested', 'Approved', 'Active', 'Closed', 'Rejected', 'Expired']
const RISKS = ['Low', 'Medium', 'High']

const STATUS_CONFIG = {
  Requested: { label: 'Requested', color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Approved:  { label: 'Approved',  color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' },
  Active:    { label: 'Active',    color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Closed:    { label: 'Closed',    color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  Rejected:  { label: 'Rejected',  color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
  Expired:   { label: 'Expired',   color: '#f97316', bg: 'rgba(249,115,22,0.15)' },
}
const statusCfg = (s) => STATUS_CONFIG[s] || STATUS_CONFIG.Requested

// Residual risk is per STEP, not per permit: one activity can stay high-risk
// after controls while the rest are low, which a permit-level figure hides.
const RISK_CONFIG = {
  Low:    { label: 'Low',    color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Medium: { label: 'Medium', color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  High:   { label: 'High',   color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
const riskCfg = (r) => RISK_CONFIG[r] || { label: r || 'Not rated', color: '#94a3b8', bg: 'rgba(148,163,184,0.12)' }

const pretty = (s) => (s || '').replace(/_/g, ' ')

// `rfq-spin` is NOT defined globally — PurchaseRfqDetail injects its own copy,
// so every other page using that class renders a spinner that does not spin.
// This one brings its own keyframes rather than inherit a dead class.
const SPIN_STYLE = '@keyframes prPermitSpin{to{transform:rotate(360deg)}}.pr-permit-spin{animation:prPermitSpin .9s linear infinite}'

// Terminal states. Nothing may be added to a closed permit's JSA and no
// transition leaves these — PurchasePermitService says so, and so does the UI.
const TERMINAL = ['Closed', 'Rejected', 'Expired']

/**
 * Past its validity window.
 *
 * PurchaseWorkPermit computes is_expired as an accessor but does NOT $append it
 * the way TPV's WorkPermit does, so the flag is simply absent from the payload
 * and a lapsed permit would otherwise read as "valid forever". valid_to is a
 * DATE column and the window runs to the end of that day — the same thing
 * `$this->valid_to->endOfDay()->isPast()` means — so it is derived here, and the
 * server's own flag is still preferred if it ever starts arriving.
 */
const isLapsed = (p) => {
  if (typeof p?.is_expired === 'boolean') return p.is_expired
  if (!p?.valid_to) return false
  return new Date(`${String(p.valid_to).slice(0, 10)}T23:59:59`) < new Date()
}

// Purchase list endpoints answer either a bare array or a { data: [] } envelope
// depending on how far the service unwrapped it — the workforce register's idiom.
const asArray = (r) => (Array.isArray(r?.data ?? r) ? (r.data ?? r) : [])

/**
 * What the server actually said.
 *
 * The four decisions sit behind role:admin on the route, so staff get a bare
 * 403 rather than a message. The buttons are hidden for them, but a role can
 * change under a session that is still open, so the refusal is named rather
 * than shown as "Action failed".
 */
const apiError = (e, fallback) => {
  if (e?.response?.status === 403) {
    return 'Only an admin can decide a permit. Ask an administrator to approve, reject, activate or close it.'
  }
  const errors = e?.response?.data?.errors
  if (errors) return Object.values(errors).flat().join(' ')
  return e?.response?.data?.message || fallback
}

export default function PurchasePermits() {
  const [searchParams] = useSearchParams()
  const { user } = useAuth()
  // Raising a permit is open to staff; the DECISIONS are admin-only, because
  // whoever asks for dangerous work to be cleared must not also clear it.
  const manage = canManagePR(user)
  const admin  = canApprovePR(user)

  const [rows, setRows]         = useState([])
  const [stats, setStats]       = useState({})
  const [vendors, setVendors]   = useState([])
  const [loading, setLoading]   = useState(true)
  const [loadError, setError]   = useState(null)
  const [creating, setCreating] = useState(false)
  const [openId, setOpenId]     = useState(null)
  // Seeded from the query string the way the workforce register seeds its
  // status filter, so a vendor workspace can deep-link its own permits.
  const [filters, setFilters] = useState({
    status:    searchParams.get('status') || '',
    type:      '',
    vendor_id: searchParams.get('vendor_id') || '',
  })

  // Loaded once for the vendor filter, the create form, and — the reason it
  // lives on the page rather than in the modal — the approval pre-check below:
  // the permit's own `vendor` relation selects id/company_name/code only, so
  // the vendor's STATUS is knowable nowhere else on this screen.
  useEffect(() => {
    purchaseApi.vendors.list({ per_page: 200 })
      .then(res => setVendors(asArray(res)))
      .catch(() => {})
  }, [])

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [list, s] = await Promise.all([
        // Blank filters are dropped rather than posted as '' — an empty status
        // is "no filter", not a permit whose status is the empty string.
        permitsApi.list(Object.fromEntries(Object.entries(filters).filter(([, v]) => v))),
        permitsApi.stats(),
      ])
      setRows(asArray(list))
      setStats(s?.data ?? s ?? {})
      setError(null)
    } catch (e) { setRows([]); setError(e) }
    finally { setLoading(false) }
  }, [filters])
  useEffect(() => { load() }, [load])

  const setFilter = (k) => (e) => setFilters(f => ({ ...f, [k]: e.target.value }))

  // The counters are tenant-wide and unfiltered, so clicking one sets the status
  // filter rather than pretending the strip describes the rows below it.
  const statCards = [
    { label: 'Total',     value: stats.total,     color: '#7C3AED', status: '' },
    { label: 'Requested', value: stats.requested, color: '#f59e0b', status: 'Requested' },
    { label: 'Approved',  value: stats.approved,  color: '#0ea5e9', status: 'Approved' },
    { label: 'Active',    value: stats.active,    color: '#10b981', status: 'Active' },
    { label: 'Closed',    value: stats.closed,    color: '#94a3b8', status: 'Closed' },
    { label: 'Rejected',  value: stats.rejected,  color: '#ef4444', status: 'Rejected' },
    { label: 'Expired',   value: stats.expired,   color: '#f97316', status: 'Expired' },
  ]

  const th = { textAlign: 'left', padding: '10px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
  const td = { padding: '11px 12px', borderBottom: '1px solid var(--border)', fontSize: 12.5, verticalAlign: 'middle' }

  return (
    <div style={{ padding: '24px 32px' }}>
      <style>{KIT3D_STYLE}{SPIN_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <FileCheck2 size={22} style={{ color: '#7C3AED' }} />
          <div>
            <h1 style={{ margin: 0, fontSize: 22, fontWeight: 900, color: 'var(--text-h)', letterSpacing: '-0.02em' }}>Permit to Work</h1>
            <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--text-muted)' }}>Request → JSA → approve → activate → close</p>
          </div>
        </div>
        <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
          <button onClick={load} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', fontWeight: 700, cursor: 'pointer', fontSize: 13 }}>
            <RefreshCw size={14} />
          </button>
          {manage && (
            <button onClick={() => setCreating(true)} style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '9px 18px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }}>
              <Plus size={15} /> Request Permit
            </button>
          )}
        </div>
      </div>

      {/* KPI filter strip — the lifecycle, counted. */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7,1fr)', gap: 10, marginBottom: 18 }}>
        {statCards.map(s => (
          <div key={s.label} className="pr-kpi" onClick={() => setFilters(f => ({ ...f, status: s.status }))}
            style={{
              textAlign: 'center',
              border: filters.status === s.status && s.status ? `1.5px solid ${s.color}` : '1px solid var(--border)',
              background: filters.status === s.status && s.status ? `${s.color}15` : undefined,
            }}>
            <div style={{ fontSize: 22, fontWeight: 900, color: s.color, lineHeight: 1 }}>{s.value ?? '—'}</div>
            <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 600, marginTop: 3 }}>{s.label}</div>
          </div>
        ))}
      </div>

      {/* Filters. All three are server-side parameters on /purchase/permits, so
          the rows and the count of them always agree. */}
      <div className="pr-glass" style={{ padding: '14px 18px', marginBottom: 18, display: 'flex', gap: 12, alignItems: 'center', flexWrap: 'wrap', borderRadius: 14 }}>
        <select value={filters.status} onChange={setFilter('status')} style={{ ...inputStyle, width: 'auto', cursor: 'pointer' }}>
          <option value="">All statuses</option>
          {STATUSES.map(s => <option key={s} value={s}>{s}</option>)}
        </select>
        <select value={filters.type} onChange={setFilter('type')} style={{ ...inputStyle, width: 'auto', cursor: 'pointer' }}>
          <option value="">All work types</option>
          {TYPES.map(t => <option key={t} value={t}>{pretty(t)}</option>)}
        </select>
        <select value={filters.vendor_id} onChange={setFilter('vendor_id')} style={{ ...inputStyle, width: 'auto', minWidth: 200, cursor: 'pointer' }}>
          <option value="">All vendors</option>
          {vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}</option>)}
        </select>
        <span style={{ marginLeft: 'auto', fontSize: 11.5, color: 'var(--text-muted)' }}>
          {rows.length} permit{rows.length === 1 ? '' : 's'}
        </span>
      </div>

      {loadError ? (
        <LoadError error={loadError} onRetry={load} title="Could not load permits" />
      ) : loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}><Loader2 size={18} className="pr-permit-spin" /> Loading permits…</div>
      ) : rows.length === 0 ? (
        <Empty icon={FileCheck2} title="No permits yet"
          hint="A permit to work is how dangerous work gets cleared — request one, build its JSA, and have an admin approve it." />
      ) : (
        <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead><tr>{['Permit', 'Type', 'Vendor', 'Location', 'Validity', 'JSA', 'Status'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
              <tbody>
                {rows.map(p => {
                  const steps = p.jsa_steps || []
                  const lapsed = isLapsed(p)
                  return (
                    <tr key={p.id} className="pr-li-row" onClick={() => setOpenId(p.id)} style={{ cursor: 'pointer' }}>
                      <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)' }}>
                        {p.title}
                        <div style={{ fontSize: 11, color: '#a78bfa', fontWeight: 700 }}>{p.reference}</div>
                      </td>
                      <td style={{ ...td, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{pretty(p.type)}</td>
                      <td style={{ ...td, color: 'var(--text-muted)' }}>{p.vendor?.company_name || '—'}</td>
                      <td style={{ ...td, color: 'var(--text-muted)' }}>{p.location || '—'}</td>
                      <td style={{ ...td, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>
                        {p.valid_from || p.valid_to ? `${fmtDate(p.valid_from)} → ${fmtDate(p.valid_to)}` : 'Open-ended'}
                        {/* A window that has passed while the permit still reads
                            Approved/Active is the one thing a glance must catch. */}
                        {lapsed && !TERMINAL.includes(p.status) && (
                          <div style={{ display: 'inline-flex', alignItems: 'center', gap: 3, fontSize: 10.5, fontWeight: 700, color: '#f97316' }}>
                            <Clock size={11} /> Window lapsed
                          </div>
                        )}
                      </td>
                      {/* No JSA means this permit cannot be approved — say so in
                          the register, not only once the button is refused. */}
                      <td style={{ ...td, color: steps.length ? 'var(--text-muted)' : '#f59e0b', fontWeight: steps.length ? 500 : 700, whiteSpace: 'nowrap' }}>
                        {steps.length ? `${steps.length} step${steps.length === 1 ? '' : 's'}` : 'None yet'}
                      </td>
                      <td style={td}><StatusBadge cfg={statusCfg(p.status)} /></td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {creating && (
        <CreateModal vendors={vendors} onClose={() => setCreating(false)}
          onSaved={(id) => { setCreating(false); load(); setOpenId(id) }} />
      )}
      {openId && (
        <DetailModal id={openId} admin={admin} vendors={vendors}
          onClose={() => setOpenId(null)} onChanged={load} />
      )}
    </div>
  )
}

/* ── Request a permit ─────────────────────────────────────────────────────── */
function CreateModal({ vendors, onClose, onSaved }) {
  const [f, setF] = useState({
    purchase_vendor_id: '', type: 'Hot_Work', title: '', location: '',
    description: '', hazards: '', precautions: '', valid_from: '', valid_to: '',
  })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))
  const vendor = vendors.find(v => String(v.id) === String(f.purchase_vendor_id))

  const save = async () => {
    // Mirrored from the controller's rules so the common mistakes are caught
    // before a round trip; the server still validates authoritatively.
    if (!f.purchase_vendor_id) { setErr('A vendor is required — purchase_work_permits.purchase_vendor_id is NOT NULL, and a permit nobody holds is not a permit.'); return }
    if (!f.title.trim()) { setErr('A title is required.'); return }
    if (f.valid_from && f.valid_to && f.valid_to < f.valid_from) { setErr('The permit cannot end before it starts.'); return }

    setBusy(true); setErr('')
    try {
      // Blank optional fields are dropped rather than posted as '' — the rules
      // are nullable, and an empty string is not "not supplied".
      const payload = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== null))
      const p = await permitsApi.create({ ...payload, purchase_vendor_id: Number(f.purchase_vendor_id) })
      onSaved(p?.id ?? p?.data?.id)
    } catch (e) { setErr(apiError(e, 'Could not create the permit.')) }
    finally { setBusy(false) }
  }

  return (
    <Overlay onClose={() => !busy && onClose()} width={720}>
      <h2 style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>
        <FileCheck2 size={18} style={{ color: '#7C3AED' }} /> Request Permit
      </h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 16px' }}>
        The request is step one. Nothing is cleared until the JSA is built and an admin approves it.
      </p>

      {/* Approval refuses a vendor who is not Active, so say it while the vendor
          is being chosen rather than at the moment approval is refused. */}
      {vendor && vendor.status !== 'Active' && (
        <InfoBox tone="danger">
          <strong>{vendor.company_name}</strong> is {vendor.status_label || vendor.status}. The permit can be raised,
          but approval will be refused until that vendor is Active.
        </InfoBox>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <Field label="Title *" full>
          <TextInput value={f.title} onChange={set('title')} maxLength={190} placeholder="What work is this permit for?" />
        </Field>
        <Field label="Work type *">
          <SelectInput value={f.type} onChange={set('type')} pairs options={TYPES.map(t => [t, pretty(t)])} />
        </Field>
        <Field label="Vendor *">
          <SelectInput value={f.purchase_vendor_id} onChange={set('purchase_vendor_id')} pairs
            options={[['', 'Select vendor…'], ...vendors.map(v => [String(v.id), `${v.company_name} · ${v.status_label || v.status}`])]} />
        </Field>
        {/* Date, not datetime: valid_from/valid_to are DATE columns and the model
            casts them as dates, so a time typed here would be discarded on save. */}
        <Field label="Valid from"><TextInput type="date" value={f.valid_from} onChange={set('valid_from')} /></Field>
        <Field label="Valid to"><TextInput type="date" value={f.valid_to} onChange={set('valid_to')} min={f.valid_from || undefined} /></Field>
        <Field label="Location" full><TextInput value={f.location} onChange={set('location')} maxLength={190} placeholder="e.g. Block B, Level 3" /></Field>
        <Field label="Description" full>
          <textarea value={f.description} onChange={set('description')} rows={2} style={{ ...inputStyle, resize: 'vertical' }} />
        </Field>
        <Field label="Hazards" full>
          <textarea value={f.hazards} onChange={set('hazards')} rows={2} style={{ ...inputStyle, resize: 'vertical' }} placeholder="The narrative summary — the step-by-step JSA is built next." />
        </Field>
        <Field label="Precautions" full>
          <textarea value={f.precautions} onChange={set('precautions')} rows={2} style={{ ...inputStyle, resize: 'vertical' }} />
        </Field>
      </div>

      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '12px 0 0' }}>{err}</p>}
      <ModalFooter onClose={onClose} onConfirm={save} loading={busy}
        disabled={!f.purchase_vendor_id || !f.title.trim()} confirmLabel="Create Permit" />
    </Overlay>
  )
}

/* ── One permit: its JSA, and the decisions available from where it stands ─── */
function DetailModal({ id, admin, vendors, onClose, onChanged }) {
  const [p, setP] = useState(null)
  const [step, setStep] = useState({ activity: '', hazard: '', control: '', residual_risk: 'Low' })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const [loadError, setLoadError] = useState(null)
  // Which decision is being composed, and the remarks going with it. A rejection
  // with no reason cannot be answered, so the API requires remarks (422 without)
  // — collected here rather than discovered from the failure.
  const [decision, setDecision] = useState(null)   // 'approve' | 'reject' | null
  const [remarks, setRemarks] = useState('')

  const load = useCallback(() => {
    setLoadError(null)
    permitsApi.get(id).then(d => setP(d?.data ?? d)).catch(e => setLoadError(e))
  }, [id])
  useEffect(() => { load() }, [load])

  const act = async (fn) => {
    setBusy(true); setErr('')
    try { await fn(); setDecision(null); setRemarks(''); load(); onChanged() }
    catch (e) { setErr(apiError(e, 'Action failed.')) }
    finally { setBusy(false) }
  }

  const addStep = () => {
    if (!step.activity.trim()) { setErr('An activity is required.'); return }
    act(async () => {
      await permitsApi.addStep(id, step)
      setStep({ activity: '', hazard: '', control: '', residual_risk: 'Low' })
    })
  }

  const steps = useMemo(() => p?.jsa_steps || [], [p])
  // The permit's own vendor relation carries id/company_name/code only, so its
  // status comes from the vendor list the page already loaded.
  const vendor = useMemo(
    () => vendors.find(v => String(v.id) === String(p?.purchase_vendor_id)) || null,
    [vendors, p],
  )

  if (loadError) {
    return (
      <Overlay onClose={onClose} width={760}>
        <LoadError error={loadError} onRetry={load} title="Could not load this permit" />
      </Overlay>
    )
  }
  if (!p) {
    return (
      <Overlay onClose={onClose} width={760}>
        <div style={{ padding: 20, textAlign: 'center', color: 'var(--text-muted)' }}><Loader2 size={18} className="pr-permit-spin" /> Loading permit…</div>
      </Overlay>
    )
  }

  const lapsed   = isLapsed(p)
  const editable = !TERMINAL.includes(p.status)
  // The three guards PurchasePermitService applies, evaluated here so the button
  // that cannot succeed is disabled with the reason beside it.
  const noJsa          = steps.length === 0
  const vendorInactive = !!vendor && vendor.status !== 'Active'
  const canApprove     = p.status === 'Requested' && !noJsa && !vendorInactive
  const canActivate    = p.status === 'Approved' && !lapsed
  const canClose       = ['Approved', 'Active'].includes(p.status)

  const th = { textAlign: 'left', padding: '8px 10px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
  const td = { padding: '9px 10px', borderTop: '1px solid var(--border)', fontSize: 12.5, color: 'var(--text-h)', verticalAlign: 'top' }

  return (
    <Overlay onClose={() => !busy && onClose()} width={760}>
      <h2 style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>
        <FileCheck2 size={18} style={{ color: '#7C3AED' }} /> {p.reference}
      </h2>
      <p style={{ color: 'var(--text-h)', fontSize: 14, fontWeight: 700, margin: '0 0 10px' }}>{p.title}</p>

      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 12 }}>
        <StatusBadge cfg={statusCfg(p.status)} />
        <StatusBadge cfg={{ label: pretty(p.type), color: '#818cf8', bg: 'rgba(129,140,248,0.15)' }} />
        {lapsed && <StatusBadge cfg={{ label: 'Window lapsed', color: '#f97316', bg: 'rgba(249,115,22,0.15)' }} />}
      </div>

      <div style={{ fontSize: 12.5, color: 'var(--text-muted)', marginBottom: 8 }}>
        {p.vendor?.company_name || '—'}
        {p.vendor?.purchase_vendor_code ? ` (${p.vendor.purchase_vendor_code})` : ''} · {p.location || 'No location'} ·{' '}
        {p.valid_from || p.valid_to ? `${fmtDate(p.valid_from)} → ${fmtDate(p.valid_to)}` : 'Open-ended'}
      </div>

      {p.description && <p style={{ fontSize: 12.5, color: 'var(--text-body)', margin: '6px 0' }}>{p.description}</p>}
      {p.hazards && <p style={{ fontSize: 12.5, color: 'var(--text-body)', margin: '6px 0' }}><strong>Hazards:</strong> {p.hazards}</p>}
      {p.precautions && <p style={{ fontSize: 12.5, color: 'var(--text-body)', margin: '6px 0' }}><strong>Precautions:</strong> {p.precautions}</p>}

      {/* ── Job Safety Analysis ── */}
      <Section title="Job Safety Analysis" count={steps.length} />
      {noJsa && (
        <InfoBox tone="danger">
          No JSA steps yet. A permit approved with no hazard analysis is the exact failure this form exists to
          prevent, so approval is refused until at least one step is recorded.
        </InfoBox>
      )}

      {steps.length > 0 && (
        <div style={{ borderRadius: 12, border: '1px solid var(--border)', overflow: 'hidden', marginBottom: 12 }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', background: 'var(--bg-input)' }}>
            <thead><tr>{['#', 'Activity', 'Hazard', 'Control', 'Residual risk'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
            <tbody>
              {steps.map(s => (
                <tr key={s.id}>
                  {/* step_no comes from the server, which numbers on append — the
                      row's index would renumber the list if one ever went missing. */}
                  <td style={{ ...td, fontWeight: 800, color: 'var(--text-muted)', width: 34 }}>{s.step_no}</td>
                  <td style={{ ...td, fontWeight: 700 }}>{s.activity}</td>
                  <td style={{ ...td, color: 'var(--text-muted)' }}>{s.hazard || '—'}</td>
                  <td style={{ ...td, color: 'var(--text-muted)' }}>{s.control || '—'}</td>
                  <td style={{ ...td, whiteSpace: 'nowrap' }}><StatusBadge cfg={riskCfg(s.residual_risk)} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {editable ? (
        <div style={{ display: 'grid', gridTemplateColumns: '1.4fr 1fr 1fr auto auto', gap: 8, alignItems: 'end', marginBottom: 14 }}>
          <Field label="Activity *"><TextInput value={step.activity} onChange={e => setStep(s => ({ ...s, activity: e.target.value }))} maxLength={500} placeholder="What is being done" /></Field>
          <Field label="Hazard"><TextInput value={step.hazard} onChange={e => setStep(s => ({ ...s, hazard: e.target.value }))} maxLength={500} placeholder="What could go wrong" /></Field>
          <Field label="Control"><TextInput value={step.control} onChange={e => setStep(s => ({ ...s, control: e.target.value }))} maxLength={500} placeholder="What prevents it" /></Field>
          <Field label="Residual risk">
            <SelectInput value={step.residual_risk} onChange={e => setStep(s => ({ ...s, residual_risk: e.target.value }))} options={RISKS} />
          </Field>
          <button onClick={addStep} disabled={busy || !step.activity.trim()}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: busy || !step.activity.trim() ? 'not-allowed' : 'pointer', fontSize: 12.5, fontWeight: 700, opacity: busy || !step.activity.trim() ? 0.6 : 1 }}>
            <Plus size={14} /> Add
          </button>
        </div>
      ) : (
        <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '0 0 14px' }}>
          This permit is {pretty(p.status).toLowerCase()} — its JSA can no longer be changed.
        </p>
      )}

      {/* ── Decision trail ── */}
      {(p.approved_at || p.decision_remarks || p.closed_at) && (
        <>
          <Section title="Decision" />
          <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 12 }}>
            {p.approved_at && <div>{p.status === 'Rejected' ? 'Rejected' : 'Approved'} {new Date(p.approved_at).toLocaleString()}</div>}
            {p.decision_remarks && <div style={{ color: 'var(--text-body)', marginTop: 2 }}>“{p.decision_remarks}”</div>}
            {p.closed_at && <div style={{ marginTop: 2 }}>Closed {new Date(p.closed_at).toLocaleString()}</div>}
          </div>
        </>
      )}

      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '0 0 10px' }}>{err}</p>}

      {/* ── Transitions. Only what is LEGAL from where the permit stands ── */}
      <div style={{ borderTop: '1px solid var(--border)', paddingTop: 14 }}>
        {TERMINAL.includes(p.status) ? (
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12.5, color: 'var(--text-muted)' }}>
            <CheckCircle size={14} /> This permit is {pretty(p.status).toLowerCase()} — no further transitions.
          </span>
        ) : !admin ? (
          // The decisions are role:admin routes. The buttons are hidden rather
          // than offered-and-refused, but the reason is stated so the screen does
          // not simply appear to be missing its actions.
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12.5, color: 'var(--text-muted)' }}>
            <ShieldAlert size={14} /> Approving, rejecting, activating and closing a permit are admin decisions —
            whoever raises a permit must not also clear it.
          </span>
        ) : decision ? (
          <RemarksPanel
            mode={decision} busy={busy} value={remarks} onChange={setRemarks}
            onCancel={() => { setDecision(null); setRemarks(''); setErr('') }}
            onConfirm={() => act(() => (decision === 'approve'
              ? permitsApi.approve(id, remarks.trim())
              : permitsApi.reject(id, remarks.trim())))}
          />
        ) : (
          <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
            {p.status === 'Requested' && (
              <>
                <button onClick={() => { setErr(''); setDecision('approve') }} disabled={busy || !canApprove}
                  style={{ ...primaryBtn, background: canApprove ? 'linear-gradient(135deg,#10b981,#059669)' : 'var(--bg-input)', color: canApprove ? '#fff' : 'var(--text-muted)', cursor: canApprove ? 'pointer' : 'not-allowed' }}>
                  <CheckCircle size={15} /> Approve
                </button>
                <button onClick={() => { setErr(''); setDecision('reject') }} disabled={busy}
                  style={{ ...ghostBtn, color: '#ef4444', borderColor: 'rgba(239,68,68,0.4)' }}>
                  <XCircle size={15} /> Reject
                </button>
                {/* Why the button is dead, next to the button. */}
                {!canApprove && (
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 11.5, color: '#f59e0b' }}>
                    <AlertTriangle size={12} />
                    {noJsa
                      ? 'Record at least one JSA step before approving.'
                      : `Vendor is ${vendor?.status_label || vendor?.status} — approval is refused until they are Active.`}
                  </span>
                )}
              </>
            )}
            {p.status === 'Approved' && (
              <>
                <button onClick={() => act(() => permitsApi.activate(id))} disabled={busy || !canActivate}
                  style={{ ...primaryBtn, background: canActivate ? 'linear-gradient(135deg,#0ea5e9,#0284c7)' : 'var(--bg-input)', color: canActivate ? '#fff' : 'var(--text-muted)', cursor: canActivate ? 'pointer' : 'not-allowed' }}>
                  <PlayCircle size={15} /> Activate
                </button>
                {!canActivate && (
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 11.5, color: '#f97316' }}>
                    <Clock size={12} /> Past its validity window — this permit can only be closed now.
                  </span>
                )}
              </>
            )}
            {canClose && (
              <button onClick={() => act(() => permitsApi.close(id))} disabled={busy} style={ghostBtn}>
                <CheckCircle size={15} /> Close
              </button>
            )}
          </div>
        )}
      </div>
    </Overlay>
  )
}

/**
 * The remarks a decision carries.
 *
 * Approval remarks are optional; rejection remarks are REQUIRED — the controller
 * 422s without them, because a refusal nobody can answer is not one. Confirm
 * stays disabled on a rejection until there is something to answer.
 */
function RemarksPanel({ mode, value, onChange, onConfirm, onCancel, busy }) {
  const rejecting = mode === 'reject'
  const ready = !rejecting || value.trim().length > 0
  return (
    <div>
      <label style={{ display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 6, textTransform: 'uppercase', letterSpacing: '0.05em' }}>
        {rejecting ? 'Reason for rejection *' : 'Approval remarks (optional)'}
      </label>
      <textarea value={value} onChange={e => onChange(e.target.value)} rows={2} maxLength={1000} autoFocus
        style={{ ...inputStyle, resize: 'vertical' }}
        placeholder={rejecting ? 'What has to change before this work can be cleared?' : 'Conditions, scope notes, anything the site should know.'} />
      <div style={{ display: 'flex', gap: 8, marginTop: 10 }}>
        <button onClick={onConfirm} disabled={busy || !ready}
          style={{ ...primaryBtn, background: ready ? (rejecting ? 'linear-gradient(135deg,#ef4444,#dc2626)' : 'linear-gradient(135deg,#10b981,#059669)') : 'var(--bg-input)', color: ready ? '#fff' : 'var(--text-muted)', cursor: busy || !ready ? 'not-allowed' : 'pointer' }}>
          {busy ? <Loader2 size={14} className="pr-permit-spin" /> : (rejecting ? <XCircle size={15} /> : <CheckCircle size={15} />)}
          {rejecting ? 'Confirm rejection' : 'Confirm approval'}
        </button>
        <button onClick={onCancel} disabled={busy} style={ghostBtn}>Cancel</button>
      </div>
      {rejecting && !ready && (
        <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '8px 0 0' }}>
          A reason is required — the vendor is told why, and rejection is terminal.
        </p>
      )}
    </div>
  )
}

/* ── shared bits ── */
const primaryBtn = { display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 16px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }
const ghostBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12.5, fontWeight: 700 }

function Section({ title, count }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 8, margin: '16px 0 8px' }}>
      <ClipboardList size={14} style={{ color: '#a78bfa' }} />
      <span style={{ fontSize: 12.5, fontWeight: 800, color: 'var(--text-h)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>{title}</span>
      {count != null && <span style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)' }}>{count}</span>}
      <span style={{ flex: 1, height: 1, background: 'var(--border)' }} />
    </div>
  )
}
