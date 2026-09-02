import { useState, useEffect, useCallback, useMemo } from 'react'
import {
  ShieldCheck, ShieldAlert, RefreshCw, RotateCcw, ChevronDown, ChevronRight,
  Check, X, AlertCircle, Users,
} from 'lucide-react'
import api from '@/lib/api'
import { purchaseApi } from '@/services/purchaseApi'
import LoadError from '@/components/ui/LoadError'
import { Empty } from './PurchaseGateLog'
import { KIT3D_STYLE, inputStyle } from '@/components/ui/kit3d'

/**
 * Purchase Work Authorization — the Purchase mirror of TpvWorkAuthorization.
 *
 * A vendor being Active does not mean all work is authorised. This is the one
 * composite verdict over Vendor + Worker + Medical + Induction + Training +
 * Badge + Competency + Permit + Work Package, derived per request server-side
 * and written nowhere: a worker whose medical lapsed overnight stops being
 * authorised overnight rather than when something remembers to clear a flag.
 *
 * Read-only. The gate and the badge are what actually enforce entry; this is
 * the picture of why they will or will not.
 */

// purchaseApi has no work-authorization namespace yet, so the calls live here in
// exactly the shape it uses (`api.<verb>(…).then(r => r.data)`) — they lift into
// services/purchaseApi.js unchanged the moment that namespace lands.
const authApi = {
  roster:   (params = {}) => api.get('/purchase/work-authorization', { params }).then(r => r.data),
  // The same verdict for one worker, re-derived live — what a row is refreshed
  // with once somebody has gone and fixed the thing that was blocking them.
  worker:   (id, activityId) => api.get(`/purchase/work-authorization/workers/${id}`, {
    params: activityId ? { activity_id: activityId } : {},
  }).then(r => r.data),
  // The package list carries its activities in full, so one request populates
  // both the package filter and the activity filter under it.
  packages: (params = {}) => api.get('/purchase/work-packages', { params }).then(r => r.data),
}

const pretty = (s) => String(s || '').replace(/_/g, ' ')
const asArray = (r) => (Array.isArray(r?.data ?? r) ? (r.data ?? r) : [])

/**
 * A check's standing.
 *
 * `required: false` is ADVISORY — it is reported so somebody can go and fix it,
 * but it is NOT a reason the work was refused. `blockers` on the payload is
 * exactly the required-and-failing set, so an authorised worker can and does
 * carry failing advisory checks, and rendering those in refusal-red would say
 * the gate turned somebody away when it did nothing of the sort.
 */
const MET      = 'met'
const BLOCKING = 'blocking'
const ADVISORY = 'advisory'
const standingOf = (c) => (c.ok ? MET : c.required ? BLOCKING : ADVISORY)

const TONE = {
  [MET]:      { color: '#10b981', border: 'var(--border)',            bg: 'var(--bg-card)',            dash: false, icon: Check },
  [BLOCKING]: { color: '#ef4444', border: 'rgba(239,68,68,0.45)',     bg: 'rgba(239,68,68,0.07)',      dash: false, icon: X },
  [ADVISORY]: { color: '#f59e0b', border: 'rgba(245,158,11,0.45)',    bg: 'rgba(245,158,11,0.06)',     dash: true,  icon: AlertCircle },
}

const advisoryFails = (checks) => (checks || []).filter(c => standingOf(c) === ADVISORY)

const th = { textAlign: 'left', padding: '10px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
const td = { padding: '11px 12px', fontSize: 12.5, verticalAlign: 'middle' }
const iconBtn = { display: 'inline-flex', alignItems: 'center', justifyContent: 'center', padding: 6, borderRadius: 8, border: 'none', background: 'transparent', color: 'var(--text-muted)', cursor: 'pointer' }

export default function PurchaseWorkAuthorization() {
  const [data, setData]       = useState(null)     // { activity, rows, totals }
  const [packages, setPacks]  = useState([])
  const [vendors, setVendors] = useState([])
  const [workerIndex, setWorkerIndex] = useState(() => new Map())
  const [loading, setLoading] = useState(true)
  const [loadError, setError] = useState(null)
  const [expanded, setExpanded] = useState(null)
  const [filters, setFilters] = useState({ work_package_id: '', activity_id: '', vendor_id: '' })

  useEffect(() => {
    authApi.packages().then(res => setPacks(asArray(res))).catch(() => setPacks([]))
    purchaseApi.vendors.list({ per_page: 200 }).then(res => setVendors(asArray(res))).catch(() => {})
  }, [])

  /**
   * Who employs each of these people.
   *
   * The authorisation payload answers with the worker's own identity only —
   * PurchaseWorkPackageService returns $worker->only(['id','full_name',
   * 'worker_code','designation','status']) — so the employing company is
   * knowable nowhere inside it, and the vendor CHECK reports a status, not a
   * name. The register is loaded alongside and joined on id, narrowed by the
   * same vendor filter so the two sets stay the same size.
   */
  useEffect(() => {
    purchaseApi.workforce.workers(filters.vendor_id ? { vendor_id: filters.vendor_id } : {})
      .then(res => setWorkerIndex(new Map(asArray(res).map(w => [w.id, w]))))
      .catch(() => setWorkerIndex(new Map()))
  }, [filters.vendor_id])

  const load = useCallback(async () => {
    setLoading(true)
    try {
      // Blank filters are dropped rather than posted as '' — an empty vendor is
      // "no filter", not a worker employed by nobody.
      const res = await authApi.roster(Object.fromEntries(Object.entries(filters).filter(([, v]) => v)))
      setData(res?.data ?? res ?? null)
      setError(null)
    } catch (e) { setData(null); setError(e) }
    finally { setLoading(false) }
  }, [filters])
  useEffect(() => { load() }, [load])

  const rows   = data?.rows || []
  const totals = data?.totals || {}

  // Every activity the tenant has, labelled by its package. Narrowed to one
  // package when the package filter is set, because a list of forty activities
  // from packages you are not looking at is not a choice anybody can make.
  const activityOptions = useMemo(() => {
    const scope = filters.work_package_id
      ? packages.filter(p => String(p.id) === String(filters.work_package_id))
      : packages
    return scope.flatMap(p => (p.activities || []).map(a => ({
      id: a.id,
      label: filters.work_package_id ? a.name : `${p.reference || p.name} · ${a.name}`,
    })))
  }, [packages, filters.work_package_id])

  const setFilter = (k) => (e) => setFilters(f => ({ ...f, [k]: e.target.value }))
  // Changing the package clears the activity: one from the package you just
  // left would keep silently deciding every competency check on screen.
  const setPackage = (e) => setFilters(f => ({ ...f, work_package_id: e.target.value, activity_id: '' }))

  const statCards = [
    { label: 'Workers',    value: totals.workers,    color: '#7C3AED' },
    { label: 'Authorized', value: totals.authorized, color: '#10b981' },
    { label: 'Blocked',    value: totals.blocked,    color: '#ef4444' },
  ]

  return (
    <div style={{ padding: '24px 32px' }}>
      <style>{KIT3D_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <ShieldCheck size={22} style={{ color: '#7C3AED' }} />
          <div>
            <h1 style={{ margin: 0, fontSize: 22, fontWeight: 900, color: 'var(--text-h)', letterSpacing: '-0.02em' }}>Work Authorization</h1>
            <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--text-muted)' }}>
              Vendor + Worker + Medical + Induction + Training + Badge + Competency + Permit + Work Package — one verdict
            </p>
          </div>
        </div>
        <button onClick={load} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', fontWeight: 700, cursor: 'pointer', fontSize: 13 }}>
          <RefreshCw size={14} /> Refresh
        </button>
      </div>

      {/* Server-computed totals, so the strip and the rows can never disagree. */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 12, marginBottom: 18 }}>
        {statCards.map(s => (
          <div key={s.label} className="pr-kpi" style={{ textAlign: 'center', cursor: 'default' }}>
            <div style={{ fontSize: 24, fontWeight: 900, color: s.color, lineHeight: 1 }}>{s.value ?? '—'}</div>
            <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 600, marginTop: 3 }}>{s.label}</div>
          </div>
        ))}
      </div>

      {/* All three are server-side parameters on /purchase/work-authorization —
          the activity in particular is not cosmetic: it is what the competency
          and permit checks are evaluated against. */}
      <div className="pr-glass" style={{ padding: '14px 18px', marginBottom: 18, borderRadius: 14 }}>
        <div style={{ display: 'flex', gap: 12, alignItems: 'center', flexWrap: 'wrap' }}>
          <select value={filters.work_package_id} onChange={setPackage} style={{ ...inputStyle, width: 'auto', minWidth: 200, cursor: 'pointer' }}>
            <option value="">All work packages</option>
            {packages.map(p => <option key={p.id} value={p.id}>{p.reference ? `${p.reference} · ${p.name}` : p.name}</option>)}
          </select>
          <select value={filters.activity_id} onChange={setFilter('activity_id')} style={{ ...inputStyle, width: 'auto', minWidth: 200, cursor: 'pointer' }}>
            <option value="">{activityOptions.length ? 'No activity — general readiness' : 'No activities defined'}</option>
            {activityOptions.map(a => <option key={a.id} value={a.id}>{a.label}</option>)}
          </select>
          <select value={filters.vendor_id} onChange={setFilter('vendor_id')} style={{ ...inputStyle, width: 'auto', minWidth: 180, cursor: 'pointer' }}>
            <option value="">All vendors</option>
            {vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}</option>)}
          </select>
          <span style={{ marginLeft: 'auto', fontSize: 11.5, color: 'var(--text-muted)' }}>
            {rows.length} worker{rows.length === 1 ? '' : 's'}
          </span>
        </div>

        {/* What the verdicts on screen are actually a verdict ABOUT. Without an
            activity there is no competency to demand and no permit to name, so
            those two checks stand down to advisory — which is a different
            question from the one somebody standing at a hot-work face is asking. */}
        <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '10px 0 0' }}>
          {data?.activity
            ? <>Checked against <strong style={{ color: 'var(--text-h)' }}>{data.activity.name}</strong>
                {data.activity.required_competency
                  ? <> — which demands <strong style={{ color: '#a78bfa' }}>{data.activity.required_competency}</strong>.</>
                  : <> — which demands no particular competency.</>}
              </>
            : 'No activity selected, so these are general-readiness verdicts. Pick an activity to check competency and permit requirements too.'}
        </p>

        <Legend />
      </div>

      {loadError ? (
        <LoadError error={loadError} onRetry={load} title="Could not load work authorization" />
      ) : loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}>Deriving authorization…</div>
      ) : rows.length === 0 ? (
        <Empty icon={Users} title="No workers in scope"
          hint="Nobody matches these filters. Registered workers appear here whatever their state — the verdict is what says whether they may work." />
      ) : (
        <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead><tr>{['', 'Worker', 'Vendor', 'Authorization', 'Blockers'].map((h, i) => <th key={i} style={th}>{h}</th>)}</tr></thead>
              <tbody>
                {rows.map(r => (
                  <AuthRow key={r.worker?.id} row={r} activityId={filters.activity_id}
                    vendor={workerIndex.get(r.worker?.id)?.vendor?.company_name}
                    expanded={expanded === r.worker?.id}
                    onToggle={() => setExpanded(expanded === r.worker?.id ? null : r.worker?.id)} />
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}

/** Three standings, named — colour alone never carries the meaning. */
function Legend() {
  const items = [
    [MET,      'Met'],
    [BLOCKING, 'Blocking — the work is refused until this is fixed'],
    [ADVISORY, 'Advisory — worth fixing, but it does not refuse anybody'],
  ]
  return (
    <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap', marginTop: 10, paddingTop: 10, borderTop: '1px solid var(--border)' }}>
      {items.map(([k, label]) => {
        const t = TONE[k]
        const Icon = t.icon
        return (
          <span key={k} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 11, color: 'var(--text-muted)' }}>
            <Icon size={13} style={{ color: t.color }} /> {label}
          </span>
        )
      })}
    </div>
  )
}

/**
 * One worker's verdict.
 *
 * The roster already carries every check, so expanding costs nothing and shows
 * the whole picture rather than only what failed — the point of the screen is to
 * tell somebody which of nine things to go and fix. Re-check re-derives just
 * this worker once they have gone and fixed it.
 */
function AuthRow({ row, activityId, vendor, expanded, onToggle }) {
  const [live, setLive] = useState(null)      // a re-derived verdict, if asked for
  const [busy, setBusy] = useState(false)

  // A fresh roster load supersedes anything re-checked from the old one.
  useEffect(() => { setLive(null) }, [row])

  const r = live || row
  const w = r.worker || {}
  const advisories = advisoryFails(r.checks)
  const blockers = r.blockers || []

  const recheck = async () => {
    setBusy(true)
    try { setLive(await authApi.worker(w.id, activityId)) }
    catch { /* the row keeps the roster's verdict — a failed re-check is not a new one */ }
    finally { setBusy(false) }
  }

  return (
    <>
      <tr className="pr-li-row" style={{ borderTop: '1px solid var(--border)' }}>
        <td style={td}>
          <button onClick={onToggle} style={iconBtn} title={expanded ? 'Collapse' : 'Expand'}>
            {expanded ? <ChevronDown size={15} /> : <ChevronRight size={15} />}
          </button>
        </td>
        <td style={td}>
          <div style={{ fontWeight: 700, color: 'var(--text-h)' }}>{w.full_name || '—'}</div>
          <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>
            <span style={{ color: '#a78bfa', fontWeight: 700 }}>{w.worker_code}</span>
            {w.designation ? ` · ${w.designation}` : ''}
          </div>
        </td>
        <td style={{ ...td, color: 'var(--text-muted)' }}>{vendor || '—'}</td>
        <td style={td}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
            {r.authorized
              ? <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '3px 10px', borderRadius: 20, background: 'rgba(16,185,129,0.15)', color: '#10b981', border: '1px solid rgba(16,185,129,0.4)', fontSize: 11, fontWeight: 700, whiteSpace: 'nowrap' }}>
                  <ShieldCheck size={12} /> Authorized
                </span>
              : <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '3px 10px', borderRadius: 20, background: 'rgba(239,68,68,0.15)', color: '#ef4444', border: '1px solid rgba(239,68,68,0.4)', fontSize: 11, fontWeight: 700, whiteSpace: 'nowrap' }}>
                  <ShieldAlert size={12} /> Not authorized
                </span>}
            {/* An advisory failure rides ALONGSIDE the verdict, never replacing
                it — an authorised worker with an open advisory is authorised. */}
            {advisories.length > 0 && (
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '3px 9px', borderRadius: 20, background: 'rgba(245,158,11,0.12)', color: '#f59e0b', border: '1px dashed rgba(245,158,11,0.5)', fontSize: 10.5, fontWeight: 700, whiteSpace: 'nowrap' }}>
                <AlertCircle size={11} /> {advisories.length} advisory
              </span>
            )}
          </div>
        </td>
        {/* `blockers` is only the required-and-failing set. When it is empty the
            cell says so and then names the advisories, rather than showing a
            bare dash next to an amber chip nobody can account for. */}
        <td style={{ ...td, maxWidth: 380 }}>
          {blockers.length > 0
            ? <span style={{ color: '#ef4444', fontWeight: 600 }}>{blockers.map(pretty).join(' · ')}</span>
            : advisories.length > 0
              ? <span style={{ color: 'var(--text-muted)' }}>
                  Nothing blocking · <span style={{ color: '#f59e0b' }}>advisory: {advisories.map(c => pretty(c.label)).join(', ')}</span>
                </span>
              : <span style={{ color: 'var(--text-muted)' }}>—</span>}
        </td>
      </tr>

      {expanded && (
        <tr>
          <td colSpan={5} style={{ padding: '0 14px 14px', background: 'var(--bg-input)' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '12px 0 8px' }}>
              <strong style={{ fontSize: 12.5, color: 'var(--text-h)' }}>All checks</strong>
              <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>
                {(r.checks || []).length} · every one of them, passing or not
              </span>
              <button onClick={recheck} disabled={busy}
                style={{ marginLeft: 'auto', display: 'inline-flex', alignItems: 'center', gap: 6, padding: '6px 12px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', fontWeight: 700, fontSize: 11.5, cursor: busy ? 'not-allowed' : 'pointer', opacity: busy ? 0.6 : 1 }}>
                <RotateCcw size={12} /> {busy ? 'Re-checking…' : live ? 'Re-checked' : 'Re-check'}
              </button>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(260px,1fr))', gap: 8 }}>
              {(r.checks || []).map(c => <CheckCard key={c.key} c={c} />)}
            </div>
          </td>
        </tr>
      )}
    </>
  )
}

/**
 * One check, and what it means.
 *
 * The three standings are separated by icon, colour AND border style: a solid
 * red edge for a blocking failure, a dashed amber one for an advisory. Both are
 * "not ok", and drawing them the same way is what would make an authorised
 * worker look refused.
 */
function CheckCard({ c }) {
  const standing = standingOf(c)
  const t = TONE[standing]
  const Icon = t.icon
  return (
    <div style={{
      display: 'flex', alignItems: 'flex-start', gap: 9, padding: '9px 11px', borderRadius: 10,
      background: t.bg, border: `1px ${t.dash ? 'dashed' : 'solid'} ${t.border}`,
    }}>
      <span style={{ marginTop: 1, flexShrink: 0 }}><Icon size={15} style={{ color: t.color }} /></span>
      <div style={{ minWidth: 0 }}>
        <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>
          {/* The permit check's label embeds the raw enum — 'Permit to work
              (Hot_Work)' — so underscores are humanised here rather than left
              showing in the one label the server does not format itself. */}
          {pretty(c.label)}
          {!c.required && <span style={{ fontWeight: 400, color: 'var(--text-muted)', fontSize: 10.5 }}> · advisory</span>}
        </div>
        {/* The detail is the actionable half — 'Held but expired' and 'Not held'
            need different people to do different things about them. */}
        <div style={{ fontSize: 11, color: c.ok ? 'var(--text-muted)' : t.color }}>{c.detail || '—'}</div>
        {standing === ADVISORY && (
          <div style={{ fontSize: 10.5, color: 'var(--text-muted)', marginTop: 2 }}>Does not refuse this worker.</div>
        )}
      </div>
    </div>
  )
}
