import { useState, useEffect, useCallback, useMemo } from 'react'
import {
  ShieldCheck, Plus, RefreshCw, Loader2, CheckCircle, XCircle, Ban, ShieldAlert,
} from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { useAuth } from '@/context/AuthContext'
import { canApprovePR, canManagePR, fmtDate } from '../constants'
import LoadError from '@/components/ui/LoadError'
import { Empty } from './PurchaseGateLog'
import {
  KIT3D_STYLE, inputStyle, Overlay, ModalFooter,
  Field, TextInput, SelectInput, StatusBadge,
} from '@/components/ui/kit3d'

/**
 * Purchase central Approval Register (Sangoe TPV §12) — the Purchase mirror of
 * TpvApprovalRegister.
 *
 * WHAT THIS IS, because Purchase has two things called "approvals" and they are
 * not the same register:
 *
 *   • THIS page reads purchase_approval_requests via /purchase/approval-requests
 *     — a flat, CROSS-VENDOR queue of the ~18 generic governance approval types
 *     (PurchaseApprovalType). Raised by anyone, decided by an admin, no
 *     side-effects. An entry may name a vendor or name none at all.
 *
 *   • The onboarding STAGE CHAIN is a different thing entirely:
 *     /purchase/onboarding/{id}/approvals is the five-stage sign-off chain for
 *     one onboarding record (purchase_approvals / PurchaseApprovalStage). It is
 *     per-onboarding by construction and has no cross-vendor listing endpoint,
 *     which is exactly why it is not what this screen shows.
 *
 * Both exist server-side; conflating them would let someone approve a stage from
 * a register that never loaded that onboarding's chain.
 */

// purchaseApi ALREADY owns this namespace (`approvalRequests`), so the calls are
// not re-declared locally the way permitsApi and governanceApi are on the
// neighbouring screens. Those two are local only because purchaseApi has no such
// namespace yet — their own comments say they lift into the service "the moment
// that namespace lands", and for this register it landed. A second local copy
// would be a second place for the URL to go stale.

// PurchaseApprovalRequest::STATUSES / ::PRIORITIES — the exact strings the
// controller validates against.
const STATUSES = ['Pending', 'Approved', 'Rejected', 'Cancelled']
// NOT the module's shared `PRIORITIES` from ../constants: that list is the
// purchase-REQUEST ladder and says "Normal" where this register says "Medium".
// Importing it would offer a value the server rejects with a 422.
const PRIORITIES = ['Low', 'Medium', 'High', 'Urgent']

const STATUS_CONFIG = {
  Pending:   { label: 'Pending',   color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Approved:  { label: 'Approved',  color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Rejected:  { label: 'Rejected',  color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
  Cancelled: { label: 'Cancelled', color: '#64748b', bg: 'rgba(100,116,139,0.15)' },
}
const statusCfg = (s) => STATUS_CONFIG[s] || STATUS_CONFIG.Pending

const PRIORITY_CONFIG = {
  Low:    { label: 'Low',    color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  Medium: { label: 'Medium', color: '#0ea5e9', bg: 'rgba(14,165,233,0.15)' },
  High:   { label: 'High',   color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Urgent: { label: 'Urgent', color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}
const priorityCfg = (p) => PRIORITY_CONFIG[p] || { label: p || '—', color: '#94a3b8', bg: 'rgba(148,163,184,0.12)' }

// The three decisions the controller accepts, and what each is called to a user.
const DECISIONS = {
  approve: { verb: 'Approve', past: 'approved', color: '#10b981', icon: CheckCircle },
  reject:  { verb: 'Reject',  past: 'rejected', color: '#ef4444', icon: XCircle },
  cancel:  { verb: 'Cancel',  past: 'cancelled', color: '#64748b', icon: Ban },
}

// `rfq-spin` is NOT defined globally — PurchaseRfqDetail injects its own copy,
// so a page relying on that class renders a spinner that does not spin.
const SPIN_STYLE = '@keyframes prAregSpin{to{transform:rotate(360deg)}}.pr-areg-spin{animation:prAregSpin .9s linear infinite}'

// Purchase list endpoints answer either a bare array or a { data: [] } envelope
// depending on how far the service unwrapped it — the workforce register's idiom.
const asArray = (r) => (Array.isArray(r?.data ?? r) ? (r.data ?? r) : [])

/**
 * What the server actually said.
 *
 * Deciding is gated in-controller (`abort_unless role === 'admin'`, 403) and the
 * service throws BusinessException as a 422 for the two real refusals: deciding
 * something already decided, and rejecting with no reason. Both are worth naming
 * — "Action failed" would send someone looking for a bug that is not there.
 */
const apiError = (e, fallback) => {
  if (e?.response?.status === 403) {
    return 'Only an admin can decide an approval. Ask an administrator to approve, reject or cancel it.'
  }
  const errors = e?.response?.data?.errors
  if (errors) return Object.values(errors).flat().join(' ')
  return e?.response?.data?.message || fallback
}

export default function PurchaseApprovalRegister() {
  const { user } = useAuth()
  // Raising is open to staff; DECIDING is admin-only, because whoever asks for a
  // deviation to be waved through must not also wave it through.
  const manage = canManagePR(user)
  const admin  = canApprovePR(user)

  const [rows, setRows]       = useState([])
  const [types, setTypes]     = useState([])
  const [vendors, setVendors] = useState([])
  const [loading, setLoading] = useState(true)
  const [loadError, setError] = useState(null)
  const [raising, setRaising] = useState(false)
  const [deciding, setDeciding] = useState(null)   // { row, decision }

  // All three are real query parameters on the controller
  // (`$request->only(['status','approval_type','purchase_vendor_id'])`), so the
  // rows and the count of them always agree — nothing is filtered client-side.
  const [filters, setFilters] = useState({ status: '', approval_type: '', purchase_vendor_id: '' })

  // Loaded once for the vendor filter and the raise form. The register's own
  // vendor relation selects id/company_name/purchase_vendor_code only, so the
  // full list is the only place a vendor can be picked from.
  useEffect(() => {
    purchaseApi.vendors.list({ per_page: 200 })
      .then(res => setVendors(asArray(res)))
      .catch(() => {})
  }, [])

  const load = useCallback(async () => {
    setLoading(true)
    try {
      // Blank filters are dropped rather than posted as '' — an empty status is
      // "no filter", not an approval whose status is the empty string.
      const res = await purchaseApi.approvalRequests.list(
        Object.fromEntries(Object.entries(filters).filter(([, v]) => v)),
      )
      setRows(asArray(res))
      // The type catalogue rides along with every listing, so the dropdown is
      // whatever PurchaseApprovalType currently defines rather than a copy of
      // the 19 values that would rot the moment one is added.
      if (res?.types) setTypes(res.types)
      setError(null)
    } catch (e) { setRows([]); setError(e) }
    finally { setLoading(false) }
  }, [filters])
  useEffect(() => { load() }, [load])

  const setFilter = (k) => (e) => setFilters(f => ({ ...f, [k]: e.target.value }))

  // Counted over the loaded rows, and the register is NOT paginated server-side
  // (the service `->get()`s the tenant's entries), so these describe the whole
  // filtered set — but they are still stated as "of the rows shown", because a
  // filter is applied above them.
  const counts = useMemo(() => {
    const c = { Pending: 0, Approved: 0, Rejected: 0, Cancelled: 0 }
    for (const r of rows) if (c[r.status] !== undefined) c[r.status] += 1
    return c
  }, [rows])

  const statCards = [
    { label: 'Showing',   value: rows.length,       color: '#7C3AED', status: '' },
    { label: 'Pending',   value: counts.Pending,    color: '#f59e0b', status: 'Pending' },
    { label: 'Approved',  value: counts.Approved,   color: '#10b981', status: 'Approved' },
    { label: 'Rejected',  value: counts.Rejected,   color: '#ef4444', status: 'Rejected' },
    { label: 'Cancelled', value: counts.Cancelled,  color: '#64748b', status: 'Cancelled' },
  ]

  const th = { textAlign: 'left', padding: '10px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
  const td = { padding: '11px 12px', borderBottom: '1px solid var(--border)', fontSize: 12.5, verticalAlign: 'middle' }

  return (
    <div style={{ padding: '24px 32px' }}>
      <style>{KIT3D_STYLE}{SPIN_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <ShieldCheck size={22} style={{ color: '#7C3AED' }} />
          <div>
            <h1 style={{ margin: 0, fontSize: 22, fontWeight: 900, color: 'var(--text-h)', letterSpacing: '-0.02em' }}>Approval Register</h1>
            <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--text-muted)' }}>
              Every Purchase governance approval in one queue — the onboarding stage chain lives on each onboarding.
            </p>
          </div>
        </div>
        <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
          <button onClick={load} style={ghostBtn}><RefreshCw size={14} /></button>
          {manage && (
            <button onClick={() => setRaising(true)} style={primaryBtn}>
              <Plus size={15} /> Raise Approval
            </button>
          )}
        </div>
      </div>

      {/* KPI filter strip. "Showing" clears the status filter rather than
          pretending to be a tenant-wide total it is not. */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(5,1fr)', gap: 10, marginBottom: 18 }}>
        {statCards.map(s => (
          <div key={s.label} className="pr-kpi" onClick={() => setFilters(f => ({ ...f, status: s.status }))}
            style={{
              textAlign: 'center',
              border: filters.status === s.status && s.status ? `1.5px solid ${s.color}` : '1px solid var(--border)',
              background: filters.status === s.status && s.status ? `${s.color}15` : undefined,
            }}>
            <div style={{ fontSize: 22, fontWeight: 900, color: s.color, lineHeight: 1 }}>{loading ? '—' : s.value}</div>
            <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 600, marginTop: 3 }}>{s.label}</div>
          </div>
        ))}
      </div>

      <div className="pr-glass" style={{ padding: '14px 18px', marginBottom: 18, display: 'flex', gap: 12, alignItems: 'center', flexWrap: 'wrap', borderRadius: 14 }}>
        <select value={filters.status} onChange={setFilter('status')} style={{ ...inputStyle, width: 'auto', cursor: 'pointer' }}>
          <option value="">All statuses</option>
          {STATUSES.map(s => <option key={s} value={s}>{s}</option>)}
        </select>
        <select value={filters.approval_type} onChange={setFilter('approval_type')} style={{ ...inputStyle, width: 'auto', minWidth: 180, cursor: 'pointer' }}>
          <option value="">All approval types</option>
          {types.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
        </select>
        <select value={filters.purchase_vendor_id} onChange={setFilter('purchase_vendor_id')} style={{ ...inputStyle, width: 'auto', minWidth: 200, cursor: 'pointer' }}>
          <option value="">All vendors</option>
          {vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}</option>)}
        </select>
        <span style={{ marginLeft: 'auto', fontSize: 11.5, color: 'var(--text-muted)' }}>
          {rows.length} approval{rows.length === 1 ? '' : 's'}
        </span>
      </div>

      {loadError ? (
        <LoadError error={loadError} onRetry={load} title="Could not load the approval register" />
      ) : loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}>
          <Loader2 size={18} className="pr-areg-spin" /> Loading approvals…
        </div>
      ) : rows.length === 0 ? (
        <Empty icon={ShieldCheck} title="No approvals here"
          hint="This is the central register of governance approvals — raise one for a deviation, an exception, a suspension or anything else that needs a decision on the record." />
      ) : (
        <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead>
                <tr>{['Reference', 'Type', 'Title', 'Vendor', 'Priority', 'Raised by', 'Status', 'Decision', ''].map(h => <th key={h} style={th}>{h}</th>)}</tr>
              </thead>
              <tbody>
                {rows.map(r => (
                  <tr key={r.id} className="pr-li-row">
                    <td style={{ ...td, fontWeight: 700, color: '#a78bfa', whiteSpace: 'nowrap' }}>{r.reference}</td>
                    <td style={{ ...td, color: 'var(--text-muted)' }}>{r.type_label}</td>
                    <td style={{ ...td, color: 'var(--text-h)', fontWeight: 700 }}>
                      {r.title}
                      {r.description && (
                        <div style={{ fontSize: 11, fontWeight: 400, color: 'var(--text-muted)', maxWidth: 320, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{r.description}</div>
                      )}
                    </td>
                    {/* An entry with no vendor is normal, not missing data — the
                        column is nullable and plenty of governance approvals are
                        about the tenant rather than about one vendor. */}
                    <td style={{ ...td, color: 'var(--text-muted)' }}>
                      {r.vendor?.company_name || <span style={{ opacity: 0.6 }}>Not vendor-specific</span>}
                    </td>
                    <td style={td}><StatusBadge cfg={priorityCfg(r.priority)} /></td>
                    <td style={{ ...td, color: 'var(--text-muted)' }}>{r.requester?.name || '—'}</td>
                    <td style={td}><StatusBadge cfg={statusCfg(r.status)} /></td>
                    {/* Who decided and when. A register whose decisions have no
                        name against them is not an audit trail. */}
                    <td style={{ ...td, color: 'var(--text-muted)', fontSize: 11.5 }}>
                      {r.status === 'Pending' ? '—' : (
                        <>
                          <div>{r.decider?.name || '—'} · {fmtDate(r.decided_at)}</div>
                          {r.decision_remarks && (
                            <div style={{ color: 'var(--text-body)', maxWidth: 260, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}
                              title={r.decision_remarks}>&ldquo;{r.decision_remarks}&rdquo;</div>
                          )}
                        </>
                      )}
                    </td>
                    <td style={{ ...td, whiteSpace: 'nowrap' }}>
                      {/* Only Pending entries are mutable — the service throws on
                          anything else, so a decided row offers no buttons. */}
                      {r.status === 'Pending' && admin && (
                        <div style={{ display: 'flex', gap: 4 }}>
                          {Object.entries(DECISIONS).map(([key, cfg]) => (
                            <button key={key} onClick={() => setDeciding({ row: r, decision: key })}
                              title={`${cfg.verb} ${r.reference}`} aria-label={`${cfg.verb} ${r.reference}`}
                              style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', padding: 6, borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-card)', color: cfg.color, cursor: 'pointer' }}>
                              <cfg.icon size={15} />
                            </button>
                          ))}
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* The reason the decision column is empty for staff, said rather than
          left to look like a rendering fault. */}
      {!admin && !loading && !loadError && rows.some(r => r.status === 'Pending') && (
        <p style={{ display: 'inline-flex', alignItems: 'flex-start', gap: 6, fontSize: 11.5, color: 'var(--text-muted)', margin: '12px 0 0' }}>
          <ShieldAlert size={13} style={{ flexShrink: 0, marginTop: 1 }} />
          Approving, rejecting and cancelling are admin decisions — whoever raises an approval must not also decide it.
        </p>
      )}

      {raising && (
        <RaiseModal types={types} vendors={vendors}
          onClose={() => setRaising(false)} onSaved={() => { setRaising(false); load() }} />
      )}
      {deciding && (
        <DecideModal payload={deciding}
          onClose={() => setDeciding(null)} onDone={() => { setDeciding(null); load() }} />
      )}
    </div>
  )
}

/* ── Raise an approval ─────────────────────────────────────────────────────── */
function RaiseModal({ types, vendors, onClose, onSaved }) {
  const [f, setF] = useState({
    approval_type: '', title: '', description: '', purchase_vendor_id: '', priority: 'Medium',
  })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))

  // The type list arrives with the listing, so on a cold open it may not be here
  // yet. Defaulting to the first only once it exists keeps the select from
  // silently posting '' — which the controller rejects as not-in-ALL.
  useEffect(() => {
    if (types.length && !f.approval_type) setF(p => ({ ...p, approval_type: types[0].value }))
  }, [types, f.approval_type])

  const save = async () => {
    // Mirrored from the controller's rules so the common mistakes are caught
    // before a round trip; the server still validates authoritatively.
    if (!f.approval_type) { setErr('An approval type is required.'); return }
    if (!f.title.trim()) { setErr('A title is required — the register is read by people who were not in the room.'); return }

    setBusy(true); setErr('')
    try {
      // Blank optional fields are dropped rather than posted as '' — the rules
      // are nullable, and an empty string is not "not supplied". The vendor id is
      // cast because the rule is `integer`, not `numeric-string`.
      const payload = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== null))
      if (payload.purchase_vendor_id) payload.purchase_vendor_id = Number(payload.purchase_vendor_id)
      await purchaseApi.approvalRequests.create(payload)
      onSaved()
    } catch (e) { setErr(apiError(e, 'Could not raise the approval.')) }
    finally { setBusy(false) }
  }

  return (
    <Overlay onClose={() => !busy && onClose()} width={620}>
      <h2 style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>
        <ShieldCheck size={18} style={{ color: '#7C3AED' }} /> Raise Approval
      </h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 16px' }}>
        This goes on the central register as Pending. It carries no side-effects — an admin decides it, and the
        decision is what the record shows.
      </p>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <Field label="Approval type *">
          <SelectInput value={f.approval_type} onChange={set('approval_type')} pairs
            options={types.length
              ? types.map(t => [t.value, t.label])
              : [['', 'Loading types…']]} />
        </Field>
        <Field label="Priority">
          <SelectInput value={f.priority} onChange={set('priority')} options={PRIORITIES} />
        </Field>
        <Field label="Title *" full>
          <TextInput value={f.title} onChange={set('title')} maxLength={200} placeholder="What needs a decision?" />
        </Field>
        {/* Optional by design: plenty of governance approvals are about the
            tenant, not about one vendor. purchase_vendor_id is nullable. */}
        <Field label="Vendor" full>
          <SelectInput value={f.purchase_vendor_id} onChange={set('purchase_vendor_id')} pairs
            options={[['', 'Not vendor-specific'], ...vendors.map(v => [String(v.id), `${v.company_name} · ${v.status_label || v.status}`])]} />
        </Field>
        <Field label="Description" full>
          <textarea value={f.description} onChange={set('description')} rows={3} maxLength={2000}
            style={{ ...inputStyle, resize: 'vertical' }}
            placeholder="The context whoever decides this will need — what was tried, what the risk is, what happens either way." />
        </Field>
      </div>

      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '12px 0 0' }}>{err}</p>}
      <ModalFooter onClose={onClose} onConfirm={save} loading={busy}
        disabled={!f.approval_type || !f.title.trim()} confirmLabel="Raise Approval" />
    </Overlay>
  )
}

/* ── Decide one entry ──────────────────────────────────────────────────────── */
/**
 * Approve / reject / cancel, with the remarks the decision carries.
 *
 * Approval and cancellation remarks are optional; REJECTION remarks are not —
 * PurchaseApprovalRequestService throws "A rejection needs a reason" on an empty
 * string, because a refusal nobody can answer is not one. Collected here rather
 * than discovered from the 422, and never through window.prompt: a prompt cannot
 * be styled, cannot be read back before sending, and is silently suppressed by
 * some browsers, which would drop the decision on the floor.
 */
function DecideModal({ payload, onClose, onDone }) {
  const { row, decision } = payload
  const cfg = DECISIONS[decision]
  const [remarks, setRemarks] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const rejecting = decision === 'reject'
  const ready = !rejecting || remarks.trim().length > 0

  const confirm = async () => {
    if (!ready) { setErr('A rejection needs a reason.'); return }
    setBusy(true); setErr('')
    try {
      // `remarks` is nullable server-side; an empty string is sent as null so a
      // blank note is not stored as one the register would then render as "".
      await purchaseApi.approvalRequests.decide(row.id, { decision, remarks: remarks.trim() || null })
      onDone()
    } catch (e) { setErr(apiError(e, 'Could not record the decision.')); setBusy(false) }
  }

  return (
    <Overlay onClose={() => !busy && onClose()} width={480}>
      <h2 style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>
        <cfg.icon size={18} style={{ color: cfg.color }} /> {cfg.verb} approval
      </h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '0 0 14px' }}>
        <strong style={{ color: 'var(--text-h)' }}>{row.reference}</strong> · {row.title}
      </p>

      <Field label={rejecting ? 'Reason for rejection *' : 'Remarks (optional)'}>
        <textarea value={remarks} onChange={e => setRemarks(e.target.value)} rows={3} maxLength={2000} autoFocus
          style={{ ...inputStyle, resize: 'vertical' }}
          placeholder={rejecting
            ? 'What has to change before this can be approved?'
            : 'Conditions, scope notes, anything the record should carry.'} />
      </Field>

      {/* A decision is final here: only Pending entries are mutable, so there is
          no un-approving one from this screen. */}
      <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '10px 0 0' }}>
        Once {cfg.past}, this entry is closed — only a Pending approval can be decided.
      </p>

      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '10px 0 0' }}>{err}</p>}
      <ModalFooter onClose={onClose} onConfirm={confirm} loading={busy}
        disabled={!ready} confirmLabel={`${cfg.verb} it`} color={cfg.color} />
    </Overlay>
  )
}

/* ── shared bits ── */
const primaryBtn = { display: 'inline-flex', alignItems: 'center', gap: 8, padding: '9px 18px', borderRadius: 10, background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', fontWeight: 700, border: 'none', cursor: 'pointer', fontSize: 13 }
const ghostBtn   = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', fontWeight: 700, cursor: 'pointer', fontSize: 13 }
