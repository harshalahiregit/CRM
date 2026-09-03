import { useState, useEffect, useCallback, useMemo } from 'react'
import { Landmark, Check, Loader2, RefreshCw, Info } from 'lucide-react'
import api from '@/lib/api'
import LoadError from '@/components/ui/LoadError'
import { Empty } from './PurchaseGateLog'
import { KIT3D_STYLE } from '@/components/ui/kit3d'

/**
 * HSSE Authority Matrix (Doc 1) — the named authorities and the governance
 * actions each one owns. The single reference for "who signs off what".
 *
 * SCOPE, and this is the one thing worth being precise about: unlike the
 * governance DASHBOARD, this screen is a straight alias of the TPV endpoint on
 * purpose. PurchaseGovernanceDashboard deliberately refuses to reuse TPV's
 * service because those figures count TPV's registers — printing them under a
 * Purchase heading would be a false claim about the Purchase vendor master.
 *
 * Nothing of the kind applies here. GET /purchase/governance/authority-matrix
 * resolves TpvSettings::authority() over config/authority.php, which holds no
 * rows from any register at all: it is the tenant's org chart of sign-off
 * ownership. "Safety owns permit approval" is true of Purchase permits and TPV
 * permits alike, because it is a statement about the tenant's people, not about
 * either module's tables. So there is one matrix, and both modules read it.
 */

// purchaseApi has no governance namespace — the aliased governance reads live
// here in exactly the shape it uses (`api.<verb>(…).then(r => r.data)`), the
// same way PurchaseGovernanceDashboard holds its own, ready to lift into
// services/purchaseApi.js unchanged the moment that namespace lands.
const governanceApi = {
  authorityMatrix: () => api.get('/purchase/governance/authority-matrix').then(r => r.data),
}

// The four authorities config/authority.php ships with. This is a colour LOOKUP,
// not the list of authorities — the matrix is tenant-configurable (§34, deep
// merged over the baseline), so a tenant may well name a fifth. Unknown keys
// fall back to slate rather than rendering colourless or, worse, being dropped.
const AUTH_COLOR = { PMC: '#7C3AED', Safety: '#ef4444', Accounts: '#0ea5e9', Admin: '#f59e0b' }
const authColor = (k) => AUTH_COLOR[k] || '#94a3b8'

// `rfq-spin` is NOT defined globally — PurchaseRfqDetail injects its own copy,
// so a page relying on that class renders a spinner that does not spin.
const SPIN_STYLE = '@keyframes prAuthSpin{to{transform:rotate(360deg)}}.pr-auth-spin{animation:prAuthSpin .9s linear infinite}'

export default function PurchaseAuthorityMatrix() {
  const [d, setD] = useState(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setError] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try { setD(await governanceApi.authorityMatrix()); setError(null) }
    catch (e) { setD(null); setError(e) }
    finally { setLoading(false) }
  }, [])
  useEffect(() => { load() }, [load])

  // The columns come from the PAYLOAD, never from AUTH_COLOR. A tenant that has
  // added or renamed an authority must see it as a column; keying off the local
  // colour map would silently hide whoever the governance team just added.
  const keys = useMemo(() => Object.keys(d?.authorities || {}), [d])
  const matrix = useMemo(() => d?.matrix || [], [d])

  // How many governance actions each authority is named on — derived from the
  // matrix itself, so it cannot drift from the ticks in the table below.
  const ownedCount = useMemo(() => {
    const counts = {}
    for (const row of matrix) for (const a of (row.authorities || [])) counts[a] = (counts[a] || 0) + 1
    return counts
  }, [matrix])

  const th = { padding: '10px 12px', fontSize: 10.5, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--text-muted)', borderBottom: '1px solid var(--border)', textAlign: 'center', whiteSpace: 'nowrap' }
  const td = { padding: '11px 12px', borderBottom: '1px solid var(--border)', fontSize: 12.5 }

  return (
    <div style={{ padding: '24px 32px' }}>
      <style>{KIT3D_STYLE}{SPIN_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <Landmark size={22} style={{ color: '#7C3AED' }} />
          <div>
            <h1 style={{ margin: 0, fontSize: 22, fontWeight: 900, color: 'var(--text-h)', letterSpacing: '-0.02em' }}>Authority Matrix</h1>
            <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--text-muted)' }}>
              The named authorities and who signs off each governance action (Doc 1).
            </p>
          </div>
        </div>
        <button onClick={load} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', fontWeight: 700, cursor: 'pointer', fontSize: 13 }}>
          <RefreshCw size={14} />
        </button>
      </div>

      {/* Said once, at the top: this page is read-only and tenant-wide. Someone
          arriving from the Purchase menu should not have to guess whether these
          authorities are Purchase's own or the tenant's. */}
      <div className="pr-glass" style={{ display: 'flex', alignItems: 'flex-start', gap: 10, padding: '12px 16px', marginBottom: 18, borderRadius: 14 }}>
        <Info size={15} style={{ color: '#a78bfa', flexShrink: 0, marginTop: 1 }} />
        <p style={{ margin: 0, fontSize: 12, color: 'var(--text-muted)', lineHeight: 1.6 }}>
          This matrix is <strong style={{ color: 'var(--text-h)' }}>tenant-wide</strong>, not Purchase-only — it names who
          in the organisation owns each sign-off, and the same authorities answer for TPV. It is a reference, not a
          control: nothing is approved from here, and it is tuned in governance settings rather than in code.
        </p>
      </div>

      {loadError ? (
        <LoadError error={loadError} onRetry={load} title="Could not load the authority matrix" />
      ) : loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}>
          <Loader2 size={18} className="pr-auth-spin" /> Loading the authority matrix…
        </div>
      ) : !keys.length && !matrix.length ? (
        <Empty icon={Landmark} title="No authorities configured"
          hint="The matrix answered with nothing. config/authority.php ships a baseline, so an empty result usually means a tenant override has cleared it." />
      ) : (
        <>
          {/* ── The authorities, and what each is accountable for ── */}
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: 12, marginBottom: 20 }}>
            {keys.map(k => {
              const a = d.authorities[k] || {}
              const color = authColor(k)
              return (
                // Not a `pr-kpi`: these are reference cards, and pr-kpi carries a
                // pointer cursor and a lift on hover that promise a click-through
                // this page has nowhere to send anyone.
                <div key={k} className="pr-glass" style={{ borderRadius: 14, padding: 16, borderTop: `3px solid ${color}` }}>
                  <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', gap: 8 }}>
                    <span style={{ fontSize: 14, fontWeight: 900, color }}>{k}</span>
                    <span style={{ fontSize: 10.5, fontWeight: 700, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>
                      {ownedCount[k] || 0} action{(ownedCount[k] || 0) === 1 ? '' : 's'}
                    </span>
                  </div>
                  <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginBottom: 8 }}>{a.label || k}</div>
                  <ul style={{ margin: 0, paddingLeft: 16, fontSize: 12, color: 'var(--text-body)', lineHeight: 1.6 }}>
                    {(a.responsibilities || []).map((r, i) => <li key={i}>{r}</li>)}
                  </ul>
                </div>
              )
            })}
          </div>

          {/* ── The matrix itself: action × authority ── */}
          <div className="pr-glass" style={{ borderRadius: 14, padding: 6, overflow: 'hidden' }}>
            <div style={{ overflowX: 'auto' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead>
                  <tr>
                    <th style={{ ...th, textAlign: 'left', minWidth: 240 }}>Governance Action</th>
                    <th style={{ ...th, textAlign: 'left' }}>Gate</th>
                    {keys.map(k => <th key={k} style={{ ...th, color: authColor(k) }}>{k}</th>)}
                  </tr>
                </thead>
                <tbody>
                  {matrix.map((row, i) => {
                    const owners = row.authorities || []
                    return (
                      <tr key={i} className="pr-li-row">
                        <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)' }}>{row.action}</td>
                        <td style={{ ...td, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{row.gate || '—'}</td>
                        {keys.map(k => (
                          <td key={k} style={{ ...td, textAlign: 'center' }}>
                            {/* The tick is titled as well as coloured — a matrix
                                read by colour alone is unreadable to anyone who
                                cannot separate the four, and unusable in print. */}
                            {owners.includes(k)
                              ? <Check size={16} style={{ color: authColor(k) }} aria-label={`${k} signs off`} />
                              : <span style={{ color: 'var(--border)' }} aria-hidden>·</span>}
                          </td>
                        ))}
                      </tr>
                    )
                  })}
                  {/* An action with no authority against it is a governance hole,
                      not a formatting problem — the row would otherwise render as
                      a line of dots and read as "nothing to see here". */}
                  {matrix.length === 0 && (
                    <tr>
                      <td colSpan={keys.length + 2} style={{ ...td, textAlign: 'center', color: 'var(--text-muted)', borderBottom: 'none' }}>
                        No governance actions are mapped yet.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>

          {/* Any action nobody owns, named explicitly. */}
          {matrix.some(r => !(r.authorities || []).length) && (
            <p style={{ margin: '12px 0 0', fontSize: 11.5, color: '#f59e0b' }}>
              Some actions above have no authority against them — nobody is currently accountable for signing them off.
            </p>
          )}
        </>
      )}
    </div>
  )
}
