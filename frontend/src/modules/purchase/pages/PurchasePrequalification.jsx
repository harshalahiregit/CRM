import { useState, useEffect, useCallback, useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  ClipboardCheck, RefreshCw, Search, ArrowRight, Loader2,
  AlertTriangle, ShieldCheck,
} from 'lucide-react'
import api from '@/lib/api'
import { fmtDate } from '../constants'
import LoadError from '@/components/ui/LoadError'
import { Empty } from './PurchaseGateLog'
import { PV_CATEGORIES } from '../components/purchaseVendorFormConstants'
import { KIT3D_STYLE, inputStyle, StatusBadge } from '@/components/ui/kit3d'

/**
 * Purchase Prequalification register — the Purchase mirror of TpvPrequalification.
 *
 * Purchase already scored prequalification ONE VENDOR AT A TIME on the vendor
 * workspace (/app/purchase/vendors/:id/prequalification). That answers "how did
 * this vendor score?" and never "who has nobody looked at yet?", which is the
 * only question a register exists to answer.
 *
 * So the unassessed vendors are the point of this page, not a footnote: they are
 * listed alongside the scored ones and badged, because a register showing only
 * the vendors somebody got round to scoring reports a perfect site while half
 * the roster was never opened.
 *
 * Read-only by design. Scoring is a questionnaire (PUT takes the whole `answers`
 * map against config/purchase_prequalification.php), and PurchasePrequalificationPanel
 * already renders it on the workspace tab — so Assess deep-links there rather
 * than growing a second copy of the form that could drift from the catalogue.
 */

// purchaseApi has no registers namespace yet, so the calls live here in exactly
// the shape it uses (`api.<verb>(…).then(r => r.data)`) — they lift into
// services/purchaseApi.js unchanged the moment that namespace lands.
const registersApi = {
  prequalification: (params = {}) => api.get('/purchase/registers/prequalification', { params }).then(r => r.data),
}

// purchase_vendors.status — the lifecycle PurchaseVendors.jsx colours. Offered as
// a filter because "unassessed" reads very differently for a Draft vendor nobody
// has engaged than for one already Active on site.
const VENDOR_STATUSES = ['Draft', 'Pending_Approval', 'Active', 'On_Hold', 'Rejected', 'Blacklisted', 'Inactive']

/**
 * PurchaseQualificationStatus — the only outcomes the scorer can produce.
 *
 * config('purchase_prequalification.outcomes') bands to Qualified / Conditional /
 * Not_Qualified only; Pending is the never-assessed sentinel, never a score. A
 * vendor with no status has not been assessed at all.
 */
const OUTCOME_CONFIG = {
  Qualified:     { label: 'Qualified',     color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  Conditional:   { label: 'Conditional',   color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  Not_Qualified: { label: 'Not Qualified', color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
}

// The absence of an assessment is a FINDING, so it gets a badge of its own rather
// than an em-dash. A blank cell reads as "nothing to report"; this one reads as
// "nobody has looked", which is what it actually means.
const UNASSESSED_CFG = { label: 'Never assessed', color: '#f97316', bg: 'rgba(249,115,22,0.15)' }

// Pending is stored, but it is the sentinel for "not scored yet" — group it with
// the never-assessed rather than inventing a fifth band nobody can act on.
const isUnassessed = (v) => !v.qualification_status || v.qualification_status === 'Pending'
const outcomeCfg = (v) => (isUnassessed(v) ? UNASSESSED_CFG : (OUTCOME_CONFIG[v.qualification_status]
  || { label: String(v.qualification_status).replace(/_/g, ' '), color: '#94a3b8', bg: 'rgba(148,163,184,0.12)' }))

// `rfq-spin` is NOT defined globally — PurchaseRfqDetail injects its own copy, so
// every other page using that class renders a spinner that does not spin. This
// one brings its own keyframes rather than inherit a dead class.
const SPIN_STYLE = '@keyframes prPrequalSpin{to{transform:rotate(360deg)}}.pr-prequal-spin{animation:prPrequalSpin .9s linear infinite}'

export default function PurchasePrequalification() {
  const navigate = useNavigate()

  const [rows, setRows]         = useState([])
  const [totals, setTotals]     = useState({})
  const [loading, setLoading]   = useState(true)
  const [loadError, setError]   = useState(null)
  const [filters, setFilters]   = useState({ status: '', category: '' })
  // The search box and the value actually sent are separate: `q` is a server-side
  // parameter, and firing a request per keystroke would put the whole register
  // through the database on every letter.
  const [search, setSearch]     = useState('')
  const [q, setQ]               = useState('')
  useEffect(() => {
    const t = setTimeout(() => setQ(search.trim()), 300)
    return () => clearTimeout(t)
  }, [search])

  const load = useCallback(async () => {
    setLoading(true)
    try {
      // Blank filters are dropped rather than posted as '' — the controller uses
      // filled(), so an empty status is "no filter", not a status of "".
      const params = Object.fromEntries(Object.entries({ ...filters, q }).filter(([, v]) => v))
      const res = await registersApi.prequalification(params)
      setRows(Array.isArray(res?.data) ? res.data : [])
      setTotals(res?.totals ?? {})
      setError(null)
    } catch (e) { setRows([]); setTotals({}); setError(e) }
    finally { setLoading(false) }
  }, [filters, q])
  useEffect(() => { load() }, [load])

  const setFilter = (k) => (e) => setFilters(f => ({ ...f, [k]: e.target.value }))

  /**
   * The bands, counted off the rows on screen.
   *
   * The endpoint's `totals` carries vendors/qualified/rejected/unassessed, but
   * `rejected` counts qualification_status === 'Rejected' — a value the scorer
   * can never write, since the outcome config bands only to Qualified /
   * Conditional / Not_Qualified. It is permanently 0. Counting the bands here
   * instead means the strip and the rows underneath it can never disagree, and
   * Conditional (which the API does not count at all) gets a number too.
   */
  const bands = useMemo(() => {
    const by = (s) => rows.filter(v => !isUnassessed(v) && v.qualification_status === s).length
    return {
      qualified:   by('Qualified'),
      conditional: by('Conditional'),
      notQualified: by('Not_Qualified'),
      unassessed:  rows.filter(isUnassessed).length,
    }
  }, [rows])

  // Never assessed is deliberately last and orange: it is the number this page
  // exists to make impossible to miss, not a rounding error at the end of a row
  // of greens.
  const statCards = [
    { label: 'Vendors',        value: totals.vendors ?? rows.length, color: '#7C3AED' },
    { label: 'Qualified',      value: bands.qualified,               color: '#10b981' },
    { label: 'Conditional',    value: bands.conditional,             color: '#f59e0b' },
    { label: 'Not Qualified',  value: bands.notQualified,            color: '#ef4444' },
    { label: 'Never assessed', value: bands.unassessed,              color: '#f97316' },
  ]

  const th = { textAlign: 'left', padding: '10px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
  const td = { padding: '11px 12px', borderBottom: '1px solid var(--border)', fontSize: 12.5, verticalAlign: 'middle' }

  return (
    <div style={{ padding: '24px 32px' }}>
      <style>{KIT3D_STYLE}{SPIN_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <ClipboardCheck size={22} style={{ color: '#7C3AED' }} />
          <div>
            <h1 style={{ margin: 0, fontSize: 22, fontWeight: 900, color: 'var(--text-h)', letterSpacing: '-0.02em' }}>Prequalification</h1>
            <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--text-muted)' }}>
              Is this vendor fit to engage? Every vendor, scored or not.
            </p>
          </div>
        </div>
        <button onClick={load} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', fontWeight: 700, cursor: 'pointer', fontSize: 13 }}>
          <RefreshCw size={14} /> Refresh
        </button>
      </div>

      {/* KPI strip. Not clickable: the API has no `qualification_status` filter,
          so a card that appeared to filter would silently do nothing. */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(5,1fr)', gap: 10, marginBottom: 18 }}>
        {/* cursor is reset on each card: .pr-kpi bakes in `pointer`, which on a
            card that does nothing is a promise the page does not keep. */}
        {statCards.map(s => (
          <div key={s.label} className="pr-kpi" style={{ textAlign: 'center', cursor: 'default' }}>
            <div style={{ fontSize: 22, fontWeight: 900, color: s.color, lineHeight: 1 }}>{s.value ?? '—'}</div>
            <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 600, marginTop: 3 }}>{s.label}</div>
          </div>
        ))}
      </div>

      {/* Filters. All three are server-side parameters on the register endpoint,
          so the rows and the counts above them always describe the same set. */}
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
        <span style={{ marginLeft: 'auto', fontSize: 11.5, color: 'var(--text-muted)' }}>
          {rows.length} vendor{rows.length === 1 ? '' : 's'}
        </span>
      </div>

      {/* The gap, stated in words before the table states it in rows. */}
      {!loading && !loadError && bands.unassessed > 0 && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '10px 14px', marginBottom: 14, borderRadius: 10, background: 'rgba(249,115,22,0.08)', border: '1px solid rgba(249,115,22,0.25)' }}>
          <AlertTriangle size={15} style={{ color: '#f97316', flexShrink: 0 }} />
          <span style={{ fontSize: 12.5, color: 'var(--text-body)' }}>
            <strong style={{ color: '#f97316' }}>{bands.unassessed}</strong> of {totals.vendors ?? rows.length} vendors
            have never been prequalified. They are listed below — an unscored vendor is an unknown, not a pass.
          </span>
        </div>
      )}

      {loadError ? (
        <LoadError error={loadError} onRetry={load} title="Could not load the prequalification register" />
      ) : loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}>
          <Loader2 size={18} className="pr-prequal-spin" /> Loading register…
        </div>
      ) : rows.length === 0 ? (
        <Empty icon={ClipboardCheck} title="No vendors match"
          hint={q || filters.status || filters.category
            ? 'No vendor matches these filters. Clear them to see the whole register.'
            : 'Prequalification scores vendors before engagement. Add a Purchase vendor and the register fills itself.'} />
      ) : (
        <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead><tr>{['Vendor', 'Vendor status', 'Category', 'Outcome', 'Score', 'Assessed', ''].map((h, i) => <th key={i} style={th}>{h}</th>)}</tr></thead>
              <tbody>
                {rows.map(v => {
                  const unassessed = isUnassessed(v)
                  return (
                    <tr key={v.id} className="pr-li-row"
                      onClick={() => navigate(`/app/purchase/vendors/${v.id}/prequalification`)}
                      style={{
                        cursor: 'pointer',
                        // A row nobody has assessed carries its own tint, so the
                        // gap is visible while scrolling and not only in its badge.
                        background: unassessed ? 'rgba(249,115,22,0.05)' : undefined,
                      }}>
                      <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)' }}>
                        {v.company_name}
                        {v.purchase_vendor_code && (
                          <div style={{ fontSize: 11, color: '#a78bfa', fontWeight: 700 }}>{v.purchase_vendor_code}</div>
                        )}
                      </td>
                      <td style={{ ...td, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{(v.status || '—').replace(/_/g, ' ')}</td>
                      <td style={{ ...td, color: 'var(--text-muted)' }}>{v.category || '—'}</td>
                      <td style={td}><StatusBadge cfg={outcomeCfg(v)} /></td>
                      {/* 0 is a real score and must not fall through to '—', which
                          is why this tests for null/undefined rather than falsy. */}
                      <td style={{ ...td, fontWeight: 800, color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' }}>
                        {v.qualification_score !== null && v.qualification_score !== undefined
                          ? `${v.qualification_score}/100`
                          : <span style={{ color: 'var(--text-muted)', fontWeight: 500 }}>—</span>}
                      </td>
                      <td style={{ ...td, color: unassessed ? '#f97316' : 'var(--text-muted)', fontWeight: unassessed ? 700 : 500, whiteSpace: 'nowrap' }}>
                        {v.qualified_at ? fmtDate(v.qualified_at) : 'Never'}
                      </td>
                      <td style={{ ...td, textAlign: 'right' }}>
                        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 12, fontWeight: 700, color: '#a78bfa', whiteSpace: 'nowrap' }}>
                          {unassessed ? <AlertTriangle size={12} /> : <ShieldCheck size={12} />}
                          {unassessed ? 'Assess' : 'Reassess'} <ArrowRight size={12} />
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
        Scoring happens on the vendor's own Prequalification tab, where the questionnaire lives — a row here opens it.
        Recording an assessment is an admin decision.
      </p>
    </div>
  )
}
