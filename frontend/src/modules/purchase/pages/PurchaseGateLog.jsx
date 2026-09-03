import { useState, useEffect, useCallback, useMemo } from 'react'
import { useParams, useSearchParams } from 'react-router-dom'
import {
  RefreshCw, Users, ScanLine, CheckCircle, AlertTriangle, XCircle, Clock, LogIn, LogOut,
} from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import LoadError from '@/components/ui/LoadError'
import { KIT3D_STYLE, inputStyle } from '@/components/ui/kit3d'

/**
 * The Purchase site gate — attendance for a day, and every badge presented at it.
 *
 * Purchase could always decide whether a worker may enter but recorded nothing
 * when it did, so there was no log and no attendance to show. PurchaseGateScan
 * now stores each crossing with the verdict and the reasons it was given, which
 * is what this reads: the decision is never re-derived here, because a worker
 * admitted last week under rules that have since changed was still admitted.
 */

// The stored verdict is binary — allow / deny — but a scan can be ALLOWED and
// still carry reasons: PurchaseGateService keeps gateDecision()'s `warning`
// alongside its `reason`, so the PPE 'warn' mode admits a worker with no kit and
// writes down why. Rendering that as a clean entry would bury it, so the amber
// light is derived from the reasons rather than stored. TPV persists all three
// lights; Purchase derives the middle one — a guard reads the same three.
const DECISION = { ALLOW: 'allow', DENY: 'deny' }
const VERDICT = { ADMIT: 'admit', WARN: 'warn', DENY: 'deny' }
const VERDICT_CONFIG = {
  [VERDICT.ADMIT]: { label: 'Admitted',              color: '#10b981', bg: 'rgba(16,185,129,0.15)', icon: CheckCircle },
  [VERDICT.WARN]:  { label: 'Admitted with Warning', color: '#f59e0b', bg: 'rgba(245,158,11,0.15)', icon: AlertTriangle },
  [VERDICT.DENY]:  { label: 'Refused',               color: '#ef4444', bg: 'rgba(239,68,68,0.15)', icon: XCircle },
}
const verdictOf = (s) => (s?.decision === DECISION.DENY ? VERDICT.DENY : (s?.reasons?.length ? VERDICT.WARN : VERDICT.ADMIT))
const verdictCfg = (v) => VERDICT_CONFIG[v] || VERDICT_CONFIG[VERDICT.DENY]

// Purchase records the crossing itself, where TPV recorded which endpoint the
// guard called (scan / check_in / check_out).
const ACTION_LABEL = { in: 'Entry', out: 'Exit' }

// Gate clock times, 24h. Kept beside the screens that read a gate log rather
// than in the module's shared constants, which formats dates for documents.
export const fmtTime = (d) => (d ? new Date(d).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: false }) : '—')
const fmtDateTime = (d) => (d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false }) : '—')

// The gate's day is the SITE's day. toISOString() is UTC, which in IST would
// hand an early-morning shift yesterday's roster, so today is taken locally.
export const todayLocal = () => {
  const d = new Date()
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
}

// Every Purchase list endpoint answers either a bare array or a { data: [] }
// envelope depending on how far the service unwrapped it — the same
// normalisation the workforce register uses.
const asArray = (r) => (Array.isArray(r?.data ?? r) ? (r.data ?? r) : [])

/**
 * A day's roster, folded out of that day's scans.
 *
 * /purchase/gate/on-site answers "who is inside right now": it returns each
 * worker's entry scan and nothing else, so it carries no exit time and no
 * duration, and a worker who has already gone home is absent from it entirely.
 * The roster is therefore built from the day's ALLOWED scans using the same
 * first-in / last-out rule PurchaseGateService::workerAttendance applies, so a
 * row here and that worker's attendance history can never disagree.
 *
 * REFUSED scans are dropped outright. A refusal means the person did not enter,
 * so counting one would put somebody on the roster who was turned away.
 *
 * `on_site` is not re-derived from these rows: it comes from the on-site
 * endpoint, which is the authority on who is still inside.
 *
 * Exported because the attendance screen folds the same day the same way — one
 * derivation means the two screens cannot drift apart.
 */
export function foldRoster(scans, onSiteIds) {
  const byWorker = new Map()
  // Oldest first, so "first in" and "last out" mean what they say. The log
  // arrives newest-first; nothing here should depend on that staying true.
  const ordered = [...(scans || [])].sort((a, b) => new Date(a.scanned_at) - new Date(b.scanned_at))

  for (const s of ordered) {
    const w = s.worker
    if (s.decision !== DECISION.ALLOW || !w?.id) continue
    const row = byWorker.get(w.id) || {
      id: w.id, worker: w, vendor: s.vendor,
      check_in_at: null, check_in_gate: null, check_out_at: null, check_out_gate: null,
    }
    if (s.action === 'in') {
      if (!row.check_in_at) { row.check_in_at = s.scanned_at; row.check_in_gate = s.gate }
    } else {
      row.check_out_at = s.scanned_at; row.check_out_gate = s.gate
    }
    byWorker.set(w.id, row)
  }

  const rows = [...byWorker.values()].map(r => {
    // Minutes need both ends. Somebody still inside has no duration yet —
    // measuring to "now" would grow all evening for a worker who simply has not
    // scanned out, and that is a guess, not a record.
    const mins = (r.check_in_at && r.check_out_at && new Date(r.check_out_at) > new Date(r.check_in_at))
      ? Math.round((new Date(r.check_out_at) - new Date(r.check_in_at)) / 60000)
      : null
    return {
      ...r,
      duration_minutes: mins,
      duration_label: mins == null ? null : `${Math.floor(mins / 60)}h ${mins % 60}m`,
      on_site: onSiteIds?.has(r.id) ?? false,
    }
  }).sort((a, b) => new Date(b.check_in_at || 0) - new Date(a.check_in_at || 0))

  const onSite = rows.filter(r => r.on_site).length
  return {
    rows,
    summary: {
      total: rows.length,
      on_site: onSite,
      departed: rows.length - onSite,
      total_minutes: rows.reduce((sum, r) => sum + (r.duration_minutes || 0), 0),
    },
  }
}

/** Worker ids still inside, from the on-site endpoint's scans. */
export const onSiteIdsOf = (scans) => new Set(asArray(scans).map(s => s.purchase_worker_id ?? s.worker?.id).filter(Boolean))

const TABS = [
  { key: 'roster', label: 'On Site', icon: Users },
  { key: 'log',    label: 'Scan Log', icon: ScanLine },
]

export default function PurchaseGateLog() {
  const { vendorId: routeVendorId } = useParams()
  const [searchParams] = useSearchParams()
  // Vendor scope, taken the way the workforce register takes it: a path param
  // inside the vendor workspace, ?vendor_id= when deep-linked from elsewhere.
  // Unlike TPV nothing is narrowed client-side — gate.log filters on vendor_id
  // server-side, and the server scopes by tenant regardless, so a tampered id
  // can only ever show less.
  const vendorId = routeVendorId || searchParams.get('vendor_id') || ''
  const scoped = !!vendorId

  const [tab, setTab]           = useState('roster')
  const [stats, setStats]       = useState({})
  const [onSiteIds, setOnSite]  = useState(() => new Set())
  const [scans, setScans]       = useState([])
  const [loading, setLoading]   = useState(true)
  const [loadError, setError]   = useState(null)
  const [date, setDate]         = useState(todayLocal)
  const [verdict, setVerdict]   = useState('All')

  const fetchAll = useCallback(async () => {
    setLoading(true)
    try {
      const scope = vendorId ? { vendor_id: vendorId } : {}
      const [s, on, l] = await Promise.all([
        // Tenant-wide counters, so they say nothing useful in a vendor-scoped
        // view — that branch derives its own from the rows underneath.
        scoped ? Promise.resolve(null) : purchaseApi.gate.stats(date),
        purchaseApi.gate.onSite(date),
        // The whole day, unfiltered by verdict. `decision` exists server-side,
        // but the roster is folded from these same rows: filtering at the server
        // would let the two tabs disagree about one day, and the amber verdict is
        // a subset of `allow` that no query parameter can express anyway.
        purchaseApi.gate.log({ ...scope, from: date, to: date, limit: 500 }),
      ])
      setStats(s?.data ?? s ?? {})
      // The on-site endpoint is tenant-wide; the roster it is matched against is
      // already vendor-scoped, so the intersection needs no filtering here.
      setOnSite(onSiteIdsOf(on))
      setScans(asArray(l))
      setError(null)
    } catch (e) { setError(e) }
    finally { setLoading(false) }
  }, [date, vendorId, scoped])
  useEffect(() => { fetchAll() }, [fetchAll])

  const roster  = useMemo(() => foldRoster(scans, onSiteIds), [scans, onSiteIds])
  const logView = useMemo(
    () => (verdict === 'All' ? scans : scans.filter(s => verdictOf(s) === verdict)),
    [scans, verdict],
  )

  // The counters follow the date picker, so they are not labelled "Today" — on
  // any other date that word would be a lie about the numbers beside it.
  // gate.stats has no vendor filter, so a scoped view derives its own from the
  // rows below rather than showing totals that contradict them.
  const statCards = scoped ? [
    { label: 'On Site Now', value: roster.summary.on_site, color: '#10b981' },
    { label: 'Checked In',  value: roster.summary.total,   color: '#0ea5e9' },
    { label: 'Scans',       value: scans.length,           color: '#7C3AED' },
    { label: 'Refused',     value: scans.filter(s => s.decision === DECISION.DENY).length, color: '#ef4444' },
  ] : [
    { label: 'On Site Now', value: stats.on_site, color: '#10b981' },
    { label: 'Checked In',  value: stats.entered, color: '#0ea5e9' },
    { label: 'Scans',       value: stats.scans,   color: '#7C3AED' },
    { label: 'Refused',     value: stats.denied,  color: '#ef4444' },
  ]

  return (
    <div style={{ padding: '24px 32px' }}>
      <style>{KIT3D_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 800, margin: 0 }}>Gate Log</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Site attendance and every badge presented at the gate.</p>
        </div>
        <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
          <input type="date" value={date} onChange={e => setDate(e.target.value)} style={{ ...inputStyle, width: 'auto' }} />
          <button onClick={fetchAll} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>
            <RefreshCw size={14} /> Refresh
          </button>
        </div>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 12, marginBottom: 20 }}>
        {statCards.map(s => (
          <div key={s.label} className="pr-kpi" style={{ textAlign: 'center', cursor: 'default' }}>
            <div style={{ fontSize: 24, fontWeight: 900, color: s.color }}>{s.value || 0}</div>
            <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 600, marginTop: 3 }}>{s.label}</div>
          </div>
        ))}
      </div>

      {/* Tabs */}
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
        {tab === 'log' && (
          <select value={verdict} onChange={e => setVerdict(e.target.value)} style={{ ...inputStyle, width: 'auto', marginLeft: 'auto', cursor: 'pointer' }}>
            <option value="All">All decisions</option>
            {Object.values(VERDICT).map(v => <option key={v} value={v}>{VERDICT_CONFIG[v].label}</option>)}
          </select>
        )}
      </div>

      {loadError ? (
        <LoadError error={loadError} onRetry={fetchAll} title="Could not load the gate log" />
      ) : loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}>Loading gate data…</div>
      ) : tab === 'roster' ? <Roster roster={roster} /> : <ScanLog rows={logView} />}
    </div>
  )
}

// ── Attendance roster ────────────────────────────────────────────────────────
function Roster({ roster }) {
  const rows = roster?.rows || []
  const s = roster?.summary || {}
  if (rows.length === 0) {
    return <Empty icon={Users} title="Nobody on site" hint="No worker was admitted through the gate on this date." />
  }
  const th = { textAlign: 'left', padding: '10px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
  const td = { padding: '11px 12px', borderBottom: '1px solid var(--border)', fontSize: 12.5, verticalAlign: 'middle' }

  return (
    <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
      <div style={{ display: 'flex', gap: 16, padding: '12px 16px', borderBottom: '1px solid var(--border)', fontSize: 12 }}>
        <span style={{ color: 'var(--text-muted)' }}>Total <strong style={{ color: 'var(--text-h)' }}>{s.total || 0}</strong></span>
        <span style={{ color: 'var(--text-muted)' }}>On site <strong style={{ color: '#10b981' }}>{s.on_site || 0}</strong></span>
        <span style={{ color: 'var(--text-muted)' }}>Departed <strong style={{ color: 'var(--text-h)' }}>{s.departed || 0}</strong></span>
        {/* Only completed sessions carry minutes — a worker still on site has no
            recorded duration until they scan out, so don't call this "total". */}
        <span style={{ marginLeft: 'auto', color: 'var(--text-muted)' }} title="Sum of completed sessions — workers still on site are not counted until they check out">
          Completed time <strong style={{ color: 'var(--text-h)' }}>{Math.floor((s.total_minutes || 0) / 60)}h {(s.total_minutes || 0) % 60}m</strong>
        </span>
      </div>
      <table style={{ width: '100%', borderCollapse: 'collapse' }}>
        <thead><tr>{['Worker', 'Vendor', 'In', 'Out', 'Duration', 'Status'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
        <tbody>
          {rows.map(r => (
            <tr key={r.id} className="pr-li-row">
              <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)' }}>
                {r.worker?.full_name}
                <span style={{ color: '#a78bfa', fontWeight: 700, fontSize: 11, marginLeft: 7 }}>{r.worker?.worker_code}</span>
                {r.worker?.designation && <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 500 }}>{r.worker.designation}</div>}
              </td>
              <td style={{ ...td, color: 'var(--text-muted)' }}>{r.vendor?.company_name || '—'}</td>
              <td style={{ ...td, color: 'var(--text-h)', fontWeight: 600 }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><LogIn size={12} style={{ color: '#10b981' }} /> {fmtTime(r.check_in_at)}</span>
                {r.check_in_gate && <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 500 }}>{r.check_in_gate}</div>}
              </td>
              <td style={{ ...td, color: r.check_out_at ? 'var(--text-h)' : 'var(--text-muted)', fontWeight: 600 }}>
                {r.check_out_at
                  ? <><span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><LogOut size={12} style={{ color: '#f59e0b' }} /> {fmtTime(r.check_out_at)}</span>
                      {r.check_out_gate && <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 500 }}>{r.check_out_gate}</div>}</>
                  : '—'}
              </td>
              <td style={{ ...td, color: 'var(--text-muted)' }}>{r.duration_label || '—'}</td>
              <td style={td}>
                {r.on_site
                  ? <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '3px 10px', borderRadius: 20, fontSize: 11, fontWeight: 700, background: 'rgba(16,185,129,0.15)', color: '#10b981', border: '1px solid rgba(16,185,129,0.4)' }}>
                      <Clock size={10} /> On site
                    </span>
                  : <span style={{ padding: '3px 10px', borderRadius: 20, fontSize: 11, fontWeight: 700, background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Departed</span>}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

// ── Scan log ─────────────────────────────────────────────────────────────────
function ScanLog({ rows }) {
  if (rows.length === 0) {
    return <Empty icon={ScanLine} title="No scans recorded" hint="Badges presented at the gate appear here — admitted and refused." />
  }
  const th = { textAlign: 'left', padding: '10px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
  const td = { padding: '11px 12px', borderBottom: '1px solid var(--border)', fontSize: 12.5, verticalAlign: 'middle' }

  return (
    <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
      <table style={{ width: '100%', borderCollapse: 'collapse' }}>
        <thead><tr>{['Time', 'Worker', 'Action', 'Decision', 'Reason', 'Gate'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
        <tbody>
          {rows.map(r => {
            const v = verdictOf(r)
            const cfg = verdictCfg(v)
            const Icon = cfg.icon
            return (
              <tr key={r.id} className="pr-li-row">
                <td style={{ ...td, color: 'var(--text-muted)', whiteSpace: 'nowrap', fontVariantNumeric: 'tabular-nums' }}>{fmtDateTime(r.scanned_at)}</td>
                <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)', whiteSpace: 'nowrap' }}>
                  {r.worker?.full_name || '—'}
                  <span style={{ color: '#a78bfa', fontWeight: 700, fontSize: 11, marginLeft: 7 }}>{r.worker?.worker_code}</span>
                </td>
                {/* A refused scan is a badge presented, not a crossing — the
                    direction it would have been is struck through so the column
                    never reads as an entry that happened. */}
                <td style={{ ...td, color: 'var(--text-muted)', whiteSpace: 'nowrap', textDecoration: v === VERDICT.DENY ? 'line-through' : undefined }}>
                  {ACTION_LABEL[r.action] || r.action || '—'}
                </td>
                <td style={td}>
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '3px 10px', borderRadius: 20, fontSize: 11, fontWeight: 700, background: cfg.bg, color: cfg.color, border: `1px solid ${cfg.color}44`, whiteSpace: 'nowrap' }}>
                    <Icon size={11} /> {cfg.label}
                  </span>
                </td>
                {/* Both strings the gate stored: the verdict's reason and, on an
                    admitted worker, the warning that came with it. */}
                <td style={{ ...td, color: cfg.color, maxWidth: 320 }}>
                  {(r.reasons || []).join(' · ') || <span style={{ color: 'var(--text-muted)' }}>—</span>}
                </td>
                <td style={{ ...td, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{r.gate || '—'}</td>
              </tr>
            )
          })}
        </tbody>
      </table>
      <div style={{ padding: '10px 14px', borderTop: '1px solid var(--border)', fontSize: 11.5, color: 'var(--text-muted)' }}>
        Showing {rows.length} scan{rows.length === 1 ? '' : 's'} for this date, newest first (up to 500).
        The log records every badge presented and the gate's verdict; who actually crossed is the roster.
      </div>
    </div>
  )
}

export const Empty = ({ icon: Icon, title, hint }) => (
  <div className="pr-glass" style={{ padding: 60, textAlign: 'center' }}>
    <div style={{ width: 60, height: 60, borderRadius: 18, margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'linear-gradient(145deg,#7C3AED,#5b21b6)', boxShadow: '0 10px 24px -6px rgba(124,58,237,.6)' }}>
      <Icon size={26} color="#fff" />
    </div>
    <h3 style={{ color: 'var(--text-h)', fontSize: 16, fontWeight: 800, margin: '0 0 6px' }}>{title}</h3>
    <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>{hint}</p>
  </div>
)
