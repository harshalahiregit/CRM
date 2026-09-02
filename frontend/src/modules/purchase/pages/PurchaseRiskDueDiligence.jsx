import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Gauge, ShieldCheck, RefreshCw, Search, ArrowRight, Loader2,
  AlertTriangle, Clock, FileQuestion,
} from 'lucide-react'
import api from '@/lib/api'
import { useAuth } from '@/context/AuthContext'
import { canManagePR, fmtDate } from '../constants'
import LoadError from '@/components/ui/LoadError'
import { Empty } from './PurchaseGateLog'
import { PV_CATEGORIES } from '../components/purchaseVendorFormConstants'
import {
  KIT3D_STYLE, inputStyle, Overlay, ModalFooter, InfoBox,
  Field, TextInput, SelectInput, StatusBadge,
} from '@/components/ui/kit3d'

/**
 * Purchase Risk & Due Diligence — the Purchase mirror of TpvRiskDueDiligence.
 *
 * Two registers, one screen, because they answer halves of the same question:
 * how much risk does this vendor carry, and did anyone actually verify them?
 * A vendor scored Low risk with no due-diligence record has not been cleared —
 * it has been guessed at, and the two tabs sit together so that is visible.
 *
 * Both endpoints take the same status/category/q parameters, so the filter bar is
 * shared and switching tabs keeps the set you were looking at.
 *
 * Where the assessment gets WRITTEN differs, and not arbitrarily:
 *   • Risk           — inline, via the Overlay below. The vendor workspace's
 *                      `risk-score` tab is unbuilt (vendorDetailTabs.jsx has no
 *                      entry, so its NavLink is filtered out), which makes this
 *                      page the only place in the app that can set a risk tier.
 *                      PUT /purchase/vendors/{id}/risk is admin+staff.
 *   • Due diligence  — on the vendor's own Due Diligence tab, where
 *                      PurchaseDueDiligencePanel already renders the nine-check
 *                      list. A second copy here would drift from the catalogue,
 *                      so a row opens that tab instead.
 */

// purchaseApi has no registers namespace yet, so the calls live here in exactly
// the shape it uses (`api.<verb>(…).then(r => r.data)`) — they lift into
// services/purchaseApi.js unchanged the moment that namespace lands.
const registersApi = {
  risk:          (params = {}) => api.get('/purchase/registers/risk', { params }).then(r => r.data),
  dueDiligence:  (params = {}) => api.get('/purchase/registers/due-diligence', { params }).then(r => r.data),
  // PurchaseVendorController::assessRisk — risk_level and risk_score are BOTH
  // required, risk_notes is nullable. risk_assessed_at is stamped server-side.
  assessRisk:    (id, data)    => api.put(`/purchase/vendors/${id}/risk`, data).then(r => r.data),
}

// purchase_vendors.status — the lifecycle PurchaseVendors.jsx colours.
const VENDOR_STATUSES = ['Draft', 'Pending_Approval', 'Active', 'On_Hold', 'Rejected', 'Blacklisted', 'Inactive']

// The exact strings assessRisk validates (in:Low,Medium,High,Critical). Note the
// polarity is the OPPOSITE of prequalification: there a high score is good, here
// a high score is the vendor you worry about.
const RISK_LEVELS = ['Low', 'Medium', 'High', 'Critical']
const RISK_CONFIG = {
  Low:      { label: 'Low',      color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Medium:   { label: 'Medium',   color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  High:     { label: 'High',     color: '#f97316', bg: 'rgba(249,115,22,0.15)' },
  Critical: { label: 'Critical', color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}

// PurchaseDueDiligence::STATUSES, rolled up by deriveStatus() from the nine checks.
const DD_CONFIG = {
  Pending:     { label: 'Pending',     color: '#94a3b8', bg: 'rgba(148,163,184,0.15)' },
  In_Progress: { label: 'In Progress', color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Cleared:     { label: 'Cleared',     color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Rejected:    { label: 'Rejected',    color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}

// The absence of an assessment is a FINDING, so it gets a badge rather than an
// em-dash. A blank cell reads as "nothing to report"; these read as "nobody has
// looked", which is what they actually mean.
const UNCLASSIFIED_CFG = { label: 'Never assessed', color: '#f97316', bg: 'rgba(249,115,22,0.15)' }
const NO_RECORD_CFG    = { label: 'No record',      color: '#f97316', bg: 'rgba(249,115,22,0.15)' }

/**
 * Older than a year — the same rule PurchaseRegisterController counts as `stale`.
 *
 * An assessment from three years ago is not a current view of the vendor, and
 * letting it sit in the "assessed" column is exactly how a register goes quietly
 * out of date while reporting full coverage.
 */
const isStale = (at) => {
  if (!at) return false
  const cutoff = new Date()
  cutoff.setFullYear(cutoff.getFullYear() - 1)
  return new Date(at) < cutoff
}

// `rfq-spin` is NOT defined globally — PurchaseRfqDetail injects its own copy, so
// every other page using that class renders a spinner that does not spin. This
// one brings its own keyframes rather than inherit a dead class.
const SPIN_STYLE = '@keyframes prRiskSpin{to{transform:rotate(360deg)}}.pr-risk-spin{animation:prRiskSpin .9s linear infinite}'

// What the server actually said. The write sits behind role:admin,staff, so a
// role that changed under an open session gets a named refusal rather than
// "Action failed".
const apiError = (e, fallback) => {
  if (e?.response?.status === 403) return 'Only an admin or staff user can record a vendor risk assessment.'
  const errors = e?.response?.data?.errors
  if (errors) return Object.values(errors).flat().join(' ')
  return e?.response?.data?.message || fallback
}

const th = { textAlign: 'left', padding: '10px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
const td = { padding: '11px 12px', borderBottom: '1px solid var(--border)', fontSize: 12.5, verticalAlign: 'middle' }

const TABS = [
  { key: 'risk', label: 'Risk Register',  icon: Gauge },
  { key: 'dd',   label: 'Due Diligence',  icon: ShieldCheck },
]

export default function PurchaseRiskDueDiligence() {
  const [tab, setTab] = useState('risk')
  // Refresh lives in the header, but the loaders live in the tabs — bumping a
  // key both of them depend on re-reads whichever one is on screen without the
  // parent having to reach into a child's state.
  const [reloadKey, setReloadKey] = useState(0)

  // Shared, because both endpoints take the same three parameters — switching
  // tabs should not silently widen the set you were just looking at.
  const [filters, setFilters] = useState({ status: '', category: '' })
  // The search box and the value actually sent are separate: `q` is a server-side
  // parameter, and firing a request per keystroke would put the whole register
  // through the database on every letter.
  const [search, setSearch]   = useState('')
  const [q, setQ]             = useState('')
  useEffect(() => {
    const t = setTimeout(() => setQ(search.trim()), 300)
    return () => clearTimeout(t)
  }, [search])

  const setFilter = (k) => (e) => setFilters(f => ({ ...f, [k]: e.target.value }))
  const filtered = !!(q || filters.status || filters.category)

  return (
    <div style={{ padding: '24px 32px' }}>
      <style>{KIT3D_STYLE}{SPIN_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <Gauge size={22} style={{ color: '#7C3AED' }} />
          <div>
            <h1 style={{ margin: 0, fontSize: 22, fontWeight: 900, color: 'var(--text-h)', letterSpacing: '-0.02em' }}>Risk &amp; Due Diligence</h1>
            <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--text-muted)' }}>
              How much risk each vendor carries, and whether anyone verified them.
            </p>
          </div>
        </div>
        <button onClick={() => setReloadKey(k => k + 1)} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', fontWeight: 700, cursor: 'pointer', fontSize: 13 }}>
          <RefreshCw size={14} /> Refresh
        </button>
      </div>

      <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
        {TABS.map(t => {
          const on = tab === t.key
          return (
            <button key={t.key} onClick={() => setTab(t.key)}
              style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '8px 16px', borderRadius: 10, fontSize: 13, fontWeight: 700, cursor: 'pointer',
                border: `1px solid ${on ? 'rgba(124,58,237,0.5)' : 'var(--border)'}`,
                background: on ? 'rgba(124,58,237,0.15)' : 'var(--bg-card)',
                color: on ? '#a78bfa' : 'var(--text-muted)' }}>
              <t.icon size={14} /> {t.label}
            </button>
          )
        })}
      </div>

      {/* Filters. All three are server-side parameters on both register
          endpoints, so the rows and the counts above them describe one set. */}
      <div className="pr-glass" style={{ padding: '14px 18px', marginBottom: 18, display: 'flex', gap: 12, alignItems: 'center', flexWrap: 'wrap', borderRadius: 14 }}>
        <div style={{ position: 'relative', minWidth: 240 }}>
          <Search size={15} style={{ position: 'absolute', left: 11, top: 11, color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search name or vendor code…"
            style={{ ...inputStyle, paddingLeft: 34 }} />
        </div>
        <select value={filters.status} onChange={setFilter('status')} style={{ ...inputStyle, width: 'auto', cursor: 'pointer' }}>
          <option value="">All vendor statuses</option>
          {VENDOR_STATUSES.map(s => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
        </select>
        <select value={filters.category} onChange={setFilter('category')} style={{ ...inputStyle, width: 'auto', minWidth: 200, cursor: 'pointer' }}>
          <option value="">All categories</option>
          {PV_CATEGORIES.map(c => <option key={c} value={c}>{c}</option>)}
        </select>
      </div>

      {/* Each register unmounts with its tab, so switching back re-reads it. */}
      {tab === 'risk'
        ? <RiskRegister params={{ ...filters, q }} filtered={filtered} reloadKey={reloadKey} />
        : <DueDiligenceRegister params={{ ...filters, q }} filtered={filtered} reloadKey={reloadKey} />}
    </div>
  )
}

/* ── Risk register ────────────────────────────────────────────────────────── */
function RiskRegister({ params, filtered, reloadKey }) {
  const { user } = useAuth()
  const manage = canManagePR(user)

  const [rows, setRows]       = useState([])
  const [totals, setTotals]   = useState({})
  const [loading, setLoading] = useState(true)
  const [loadError, setError] = useState(null)
  const [editing, setEditing] = useState(null)   // the vendor row being assessed

  const load = useCallback(async () => {
    setLoading(true)
    try {
      // Blank filters are dropped rather than posted as '' — the controller uses
      // filled(), so an empty status is "no filter", not a status of "".
      const clean = Object.fromEntries(Object.entries(params).filter(([, v]) => v))
      const res = await registersApi.risk(clean)
      setRows(Array.isArray(res?.data) ? res.data : [])
      setTotals(res?.totals ?? {})
      setError(null)
    } catch (e) { setRows([]); setTotals({}); setError(e) }
    finally { setLoading(false) }
    // params is rebuilt every parent render, so depend on its VALUES — depending
    // on the object itself would re-fetch on every keystroke of the parent.
  }, [params.status, params.category, params.q, reloadKey]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { load() }, [load])

  // Straight from the endpoint: `high` counts High+Critical together, and `stale`
  // is the older-than-a-year count that would otherwise hide inside "assessed".
  const statCards = [
    { label: 'Vendors',        value: totals.vendors ?? rows.length, color: '#7C3AED' },
    { label: 'High / Critical', value: totals.high,                  color: '#ef4444' },
    { label: 'Stale (>1yr)',   value: totals.stale,                  color: '#eab308' },
    { label: 'Never assessed', value: totals.unassessed,             color: '#f97316' },
  ]

  return (
    <>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 10, marginBottom: 18 }}>
        {/* cursor is reset on each card: .pr-kpi bakes in `pointer`, which on a
            card that does nothing is a promise the page does not keep. */}
        {statCards.map(s => (
          <div key={s.label} className="pr-kpi" style={{ textAlign: 'center', cursor: 'default' }}>
            <div style={{ fontSize: 22, fontWeight: 900, color: s.color, lineHeight: 1 }}>{s.value ?? '—'}</div>
            <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 600, marginTop: 3 }}>{s.label}</div>
          </div>
        ))}
      </div>

      {/* The two gaps, in words, before the table shows them in rows. Stale is
          named separately from unassessed because they need different work:
          one vendor was never looked at, the other was looked at too long ago. */}
      {!loading && !loadError && (totals.unassessed > 0 || totals.stale > 0) && (
        <div style={{ display: 'flex', alignItems: 'flex-start', gap: 8, padding: '10px 14px', marginBottom: 14, borderRadius: 10, background: 'rgba(249,115,22,0.08)', border: '1px solid rgba(249,115,22,0.25)' }}>
          <AlertTriangle size={15} style={{ color: '#f97316', flexShrink: 0, marginTop: 1 }} />
          <span style={{ fontSize: 12.5, color: 'var(--text-body)' }}>
            {totals.unassessed > 0 && (
              <>
                <strong style={{ color: '#f97316' }}>{totals.unassessed}</strong> of {totals.vendors ?? rows.length} vendors
                carry no risk rating at all.{' '}
              </>
            )}
            {totals.stale > 0 && (
              <>
                <strong style={{ color: '#eab308' }}>{totals.stale}</strong> were last assessed over a year ago —
                a rating that old is a record, not a current view.
              </>
            )}
          </span>
        </div>
      )}

      {loadError ? (
        <LoadError error={loadError} onRetry={load} title="Could not load the risk register" />
      ) : loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}>
          <Loader2 size={18} className="pr-risk-spin" /> Loading risk register…
        </div>
      ) : rows.length === 0 ? (
        <Empty icon={Gauge} title="No vendors match"
          hint={filtered
            ? 'No vendor matches these filters. Clear them to see the whole register.'
            : 'A risk tier drives how deeply a vendor is onboarded and monitored. Add a Purchase vendor and the register fills itself.'} />
      ) : (
        <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead><tr>{['Vendor', 'Vendor status', 'Category', 'Risk level', 'Score', 'Assessed', ''].map((h, i) => <th key={i} style={th}>{h}</th>)}</tr></thead>
              {/* Rendered in the order the server sent: risk_score DESCENDING with
                  unassessed last. The riskiest vendor is the point of this page and
                  an alphabetical sort buries it, so nothing here re-sorts. */}
              <tbody>
                {rows.map(v => {
                  const unassessed = !v.risk_level
                  const stale = isStale(v.risk_assessed_at)
                  return (
                    <tr key={v.id} className="pr-li-row"
                      style={{ background: unassessed ? 'rgba(249,115,22,0.05)' : undefined }}>
                      <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)' }}>
                        {v.company_name}
                        {v.purchase_vendor_code && (
                          <div style={{ fontSize: 11, color: '#a78bfa', fontWeight: 700 }}>{v.purchase_vendor_code}</div>
                        )}
                      </td>
                      <td style={{ ...td, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{(v.status || '—').replace(/_/g, ' ')}</td>
                      <td style={{ ...td, color: 'var(--text-muted)' }}>{v.category || '—'}</td>
                      <td style={td}>
                        <StatusBadge cfg={unassessed ? UNCLASSIFIED_CFG : (RISK_CONFIG[v.risk_level]
                          || { label: v.risk_level, color: '#94a3b8', bg: 'rgba(148,163,184,0.12)' })} />
                      </td>
                      {/* 0 is a real (and meaningful) risk score, so this tests for
                          null/undefined rather than falsy. */}
                      <td style={{ ...td, fontWeight: 800, color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' }}>
                        {v.risk_score !== null && v.risk_score !== undefined
                          ? `${v.risk_score}/100`
                          : <span style={{ color: 'var(--text-muted)', fontWeight: 500 }}>—</span>}
                      </td>
                      <td style={{ ...td, whiteSpace: 'nowrap', color: unassessed ? '#f97316' : 'var(--text-muted)', fontWeight: unassessed ? 700 : 500 }}>
                        {v.risk_assessed_at ? fmtDate(v.risk_assessed_at) : 'Never'}
                        {stale && (
                          <div style={{ display: 'inline-flex', alignItems: 'center', gap: 3, marginLeft: 6, fontSize: 10.5, fontWeight: 800, color: '#eab308' }}>
                            <Clock size={11} /> Stale
                          </div>
                        )}
                      </td>
                      <td style={{ ...td, textAlign: 'right' }}>
                        {manage ? (
                          <button onClick={() => setEditing(v)}
                            style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 12px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: '#a78bfa', cursor: 'pointer', fontSize: 12, fontWeight: 700, whiteSpace: 'nowrap' }}>
                            {unassessed ? <AlertTriangle size={12} /> : <Gauge size={12} />}
                            {unassessed ? 'Assess' : 'Reassess'}
                          </button>
                        ) : (
                          <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>Read only</span>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {editing && (
        <RiskModal vendor={editing} onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); load() }} />
      )}
    </>
  )
}

/**
 * Record a vendor's risk tier.
 *
 * This is the only place in the app that can: the workspace's `risk-score` tab
 * has no TAB_ELEMENTS entry, so its NavLink is filtered out and the route falls
 * back to Overview.
 *
 * Fields are exactly what PurchaseVendorController::assessRisk validates —
 * risk_level (required, one of four) and risk_score (required integer 0–100) are
 * both mandatory, risk_notes is nullable up to 2000 characters. risk_assessed_at
 * is stamped by the server; sending one would be ignored.
 */
function RiskModal({ vendor, onClose, onSaved }) {
  const [f, setF] = useState({
    risk_level: vendor.risk_level || 'Medium',
    // Kept as a string so the field can legitimately be empty while typing; the
    // score is only cast to a number on save.
    risk_score: vendor.risk_score !== null && vendor.risk_score !== undefined ? String(vendor.risk_score) : '',
    risk_notes: vendor.risk_notes || '',
  })
  const [busy, setBusy] = useState(false)
  const [err, setErr]   = useState('')

  const set = (k) => (e) => setF(p => ({ ...p, [k]: e.target.value }))

  const score = Number(f.risk_score)
  const scoreOk = f.risk_score !== '' && Number.isInteger(score) && score >= 0 && score <= 100

  const save = async () => {
    // Mirrored from the controller's rules so the common mistakes are caught
    // before a round trip; the server still validates authoritatively.
    if (!scoreOk) { setErr('A risk score is required — a whole number from 0 to 100.'); return }

    setBusy(true); setErr('')
    try {
      await registersApi.assessRisk(vendor.id, {
        risk_level: f.risk_level,
        risk_score: score,
        // Blank notes are sent as null rather than '' — the rule is nullable, and
        // an empty string is not "not supplied".
        risk_notes: f.risk_notes.trim() || null,
      })
      onSaved()
    } catch (e) { setErr(apiError(e, 'Could not save the risk assessment.')) }
    finally { setBusy(false) }
  }

  return (
    <Overlay onClose={() => !busy && onClose()} width={560}>
      <h2 style={{ display: 'flex', alignItems: 'center', gap: 8, color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>
        <Gauge size={18} style={{ color: '#7C3AED' }} /> Risk Assessment
      </h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 16px' }}>
        {vendor.company_name}{vendor.purchase_vendor_code ? ` · ${vendor.purchase_vendor_code}` : ''}
      </p>

      {/* Polarity trips people up: on prequalification a high score is good news.
          Here it is the opposite, so say so beside the field. */}
      <InfoBox>
        Higher is <strong>worse</strong> — 100 is the vendor you worry about most. This is the reverse of the
        prequalification score, where 100 means fully qualified.
      </InfoBox>

      {isStale(vendor.risk_assessed_at) && (
        <InfoBox tone="danger">
          Last assessed {fmtDate(vendor.risk_assessed_at)} — over a year ago. Re-rate from what the vendor looks
          like today rather than confirming the old figure.
        </InfoBox>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <Field label="Risk level *">
          <SelectInput value={f.risk_level} onChange={set('risk_level')} options={RISK_LEVELS} />
        </Field>
        <Field label="Risk score * (0–100)">
          <TextInput type="number" min={0} max={100} step={1} value={f.risk_score} onChange={set('risk_score')}
            placeholder="0–100" />
        </Field>
        <Field label="Notes" full>
          <textarea value={f.risk_notes} onChange={set('risk_notes')} rows={4} maxLength={2000}
            style={{ ...inputStyle, resize: 'vertical' }}
            placeholder="What drives this rating? The next person to open this vendor reads only what is written here." />
        </Field>
      </div>

      {f.risk_score !== '' && !scoreOk && (
        <p style={{ color: '#f59e0b', fontSize: 11.5, margin: '10px 0 0' }}>
          The score must be a whole number from 0 to 100.
        </p>
      )}
      {err && <p style={{ color: '#ef4444', fontSize: 12.5, margin: '12px 0 0' }}>{err}</p>}

      <ModalFooter onClose={onClose} onConfirm={save} loading={busy} disabled={!scoreOk}
        confirmLabel="Save Assessment" />
    </Overlay>
  )
}

/* ── Due-diligence register ───────────────────────────────────────────────── */
function DueDiligenceRegister({ params, filtered, reloadKey }) {
  const navigate = useNavigate()

  const [rows, setRows]       = useState([])
  const [totals, setTotals]   = useState({})
  const [loading, setLoading] = useState(true)
  const [loadError, setError] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const clean = Object.fromEntries(Object.entries(params).filter(([, v]) => v))
      const res = await registersApi.dueDiligence(clean)
      setRows(Array.isArray(res?.data) ? res.data : [])
      setTotals(res?.totals ?? {})
      setError(null)
    } catch (e) { setRows([]); setTotals({}); setError(e) }
    finally { setLoading(false) }
  }, [params.status, params.category, params.q, reloadKey]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { load() }, [load])

  const statCards = [
    { label: 'Vendors',  value: totals.vendors ?? rows.length, color: '#7C3AED' },
    { label: 'Recorded', value: totals.recorded,               color: '#10b981' },
    { label: 'Missing',  value: totals.missing,                color: '#f97316' },
  ]

  return (
    <>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 10, marginBottom: 18 }}>
        {/* cursor is reset on each card: .pr-kpi bakes in `pointer`, which on a
            card that does nothing is a promise the page does not keep. */}
        {statCards.map(s => (
          <div key={s.label} className="pr-kpi" style={{ textAlign: 'center', cursor: 'default' }}>
            <div style={{ fontSize: 22, fontWeight: 900, color: s.color, lineHeight: 1 }}>{s.value ?? '—'}</div>
            <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 600, marginTop: 3 }}>{s.label}</div>
          </div>
        ))}
      </div>

      {!loading && !loadError && totals.missing > 0 && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '10px 14px', marginBottom: 14, borderRadius: 10, background: 'rgba(249,115,22,0.08)', border: '1px solid rgba(249,115,22,0.25)' }}>
          <AlertTriangle size={15} style={{ color: '#f97316', flexShrink: 0 }} />
          <span style={{ fontSize: 12.5, color: 'var(--text-body)' }}>
            <strong style={{ color: '#f97316' }}>{totals.missing}</strong> of {totals.vendors ?? rows.length} vendors
            have no due-diligence record at all. Nothing was checked and nothing failed — nobody started.
          </span>
        </div>
      )}

      {loadError ? (
        <LoadError error={loadError} onRetry={load} title="Could not load the due-diligence register" />
      ) : loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}>
          <Loader2 size={18} className="pr-risk-spin" /> Loading due-diligence register…
        </div>
      ) : rows.length === 0 ? (
        <Empty icon={ShieldCheck} title="No vendors match"
          hint={filtered
            ? 'No vendor matches these filters. Clear them to see the whole register.'
            : 'Due diligence is nine named checks per vendor, rolled up to one outcome. Add a Purchase vendor and the register fills itself.'} />
      ) : (
        <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead><tr>{['Vendor', 'Vendor status', 'Category', 'Due diligence', 'Last updated', ''].map((h, i) => <th key={i} style={th}>{h}</th>)}</tr></thead>
              <tbody>
                {rows.map(v => {
                  const missing = !v.has_record
                  return (
                    <tr key={v.id} className="pr-li-row"
                      onClick={() => navigate(`/app/purchase/vendors/${v.id}/due-diligence`)}
                      style={{ cursor: 'pointer', background: missing ? 'rgba(249,115,22,0.05)' : undefined }}>
                      <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)' }}>
                        {v.company_name}
                        {v.purchase_vendor_code && (
                          <div style={{ fontSize: 11, color: '#a78bfa', fontWeight: 700 }}>{v.purchase_vendor_code}</div>
                        )}
                      </td>
                      <td style={{ ...td, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{(v.status || '—').replace(/_/g, ' ')}</td>
                      <td style={{ ...td, color: 'var(--text-muted)' }}>{v.category || '—'}</td>
                      {/* A record can exist while its rolled-up status is still
                          Pending, so "no record" and "opened but unfinished" are
                          different states and read differently. */}
                      <td style={td}>
                        <StatusBadge cfg={missing ? NO_RECORD_CFG : (DD_CONFIG[v.dd_status]
                          || { label: String(v.dd_status || 'Pending').replace(/_/g, ' '), color: '#94a3b8', bg: 'rgba(148,163,184,0.12)' })} />
                      </td>
                      <td style={{ ...td, whiteSpace: 'nowrap', color: missing ? '#f97316' : 'var(--text-muted)', fontWeight: missing ? 700 : 500 }}>
                        {v.updated_at ? fmtDate(v.updated_at) : 'Never'}
                      </td>
                      <td style={{ ...td, textAlign: 'right' }}>
                        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 12, fontWeight: 700, color: '#a78bfa', whiteSpace: 'nowrap' }}>
                          {missing ? <FileQuestion size={12} /> : <ShieldCheck size={12} />}
                          {missing ? 'Start' : 'Review'} <ArrowRight size={12} />
                        </span>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '14px 0 0' }}>
        The nine checks are recorded on the vendor's own Due Diligence tab — a row here opens it. Recording the
        verification is an admin decision.
      </p>
    </>
  )
}
