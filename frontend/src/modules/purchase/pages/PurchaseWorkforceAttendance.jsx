import { useState, useEffect, useCallback, useMemo } from 'react'
import { useParams, useSearchParams } from 'react-router-dom'
import { RefreshCw, Users, Clock, LogIn, LogOut, History } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import LoadError from '@/components/ui/LoadError'
import { KIT3D_STYLE, inputStyle, Overlay } from '@/components/ui/kit3d'
import { fmtDate } from '../constants'
import { foldRoster, onSiteIdsOf, fmtTime, todayLocal, Empty } from './PurchaseGateLog'

/**
 * Daily site attendance for a vendor's workforce.
 *
 * The day's roster is folded out of the gate log by the SAME helper the gate log
 * screen uses — one derivation, so the two screens can never disagree about who
 * was on site on a given day. Refused scans are excluded there: a badge that was
 * turned away is not attendance.
 *
 * Two things differ from TPV, and only these two:
 *  1. TPV filters a tenant-wide roster down to the vendor's workers by matching
 *     worker_code client-side. /purchase/gate/log takes vendor_id, so the scope
 *     is applied at the server and a tampered id can only ever show less.
 *  2. TPV has no way in without a vendor (route param, or a portal token). There
 *     is no Purchase vendor portal for the gate, so the vendor is a picker here —
 *     optional, because gate.log is happy to answer for the whole site.
 */

const asArray = (r) => (Array.isArray(r?.data ?? r) ? (r.data ?? r) : [])

/** ±n days on a yyyy-mm-dd string, parsed locally so the site's day is kept. */
const shiftDays = (iso, n) => {
  const d = new Date(`${iso}T00:00:00`)
  d.setDate(d.getDate() + n)
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
}

// How far back a worker's history opens. The roster answers "was this person
// here today"; the history answers "how often", which one day cannot.
const HISTORY_DAYS = 30

export default function PurchaseWorkforceAttendance() {
  const { vendorId: routeVendorId } = useParams()   // present inside a vendor-scoped route
  const [searchParams, setSearchParams] = useSearchParams()

  // Vendor scope lives in the query string rather than in state, so one vendor's
  // attendance is deep-linkable and survives a reload — the same ?vendor_id= the
  // workforce and medical registers read.
  const vendorId = routeVendorId || searchParams.get('vendor_id') || ''

  const [vendors, setVendors] = useState([])
  const [workers, setWorkers] = useState([])
  const [onSiteIds, setOnSite] = useState(() => new Set())
  const [scans, setScans]     = useState([])
  const [loading, setLoading] = useState(true)
  const [loadError, setError] = useState(null)
  const [date, setDate]       = useState(todayLocal)
  const [history, setHistory] = useState(null)   // the worker whose history is open

  useEffect(() => {
    purchaseApi.vendors.list({ per_page: 200 })
      .then(res => setVendors(asArray(res)))
      .catch(() => {})
  }, [])

  // The picker lists everyone on the books, not just today's arrivals: the
  // question "why was this worker not on site" is only answerable about someone
  // the roster does not contain.
  useEffect(() => {
    purchaseApi.workforce.workers(vendorId ? { vendor_id: vendorId } : {})
      .then(res => setWorkers(asArray(res)))
      .catch(() => setWorkers([]))
  }, [vendorId])

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const scope = vendorId ? { vendor_id: vendorId } : {}
      const [on, l] = await Promise.all([
        purchaseApi.gate.onSite(date),
        purchaseApi.gate.log({ ...scope, from: date, to: date, limit: 500 }),
      ])
      // on-site is tenant-wide; the rows it is matched against are already
      // vendor-scoped, so the intersection needs no filtering here.
      setOnSite(onSiteIdsOf(on))
      setScans(asArray(l))
      setError(null)
    } catch (e) { setError(e) }
    finally { setLoading(false) }
  }, [vendorId, date])
  useEffect(() => { load() }, [load])

  const pickVendor = (id) => {
    const next = new URLSearchParams(searchParams)
    if (id) next.set('vendor_id', id)
    else next.delete('vendor_id')
    setSearchParams(next, { replace: true })
  }

  const { rows, summary } = useMemo(() => foldRoster(scans, onSiteIds), [scans, onSiteIds])

  const th = { textAlign: 'left', padding: '10px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
  const td = { padding: '11px 12px', borderBottom: '1px solid var(--border)', fontSize: 12.5, verticalAlign: 'middle' }

  return (
    <div style={{ padding: '24px 32px' }}>
      <style>{KIT3D_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 800, margin: 0 }}>Attendance</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>
            {vendorId ? "Daily site attendance for this vendor's workforce." : 'Daily site attendance across the site.'}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
          <select value={vendorId} onChange={e => pickVendor(e.target.value)} disabled={!!routeVendorId}
            style={{ ...inputStyle, width: 'auto', minWidth: 190, cursor: routeVendorId ? 'default' : 'pointer' }}>
            <option value="">All vendors</option>
            {vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}</option>)}
          </select>
          {/* Resets to the placeholder on pick, so the same worker can be opened
              twice without choosing someone else in between. */}
          <select value="" onChange={e => { const w = workers.find(x => String(x.id) === e.target.value); if (w) setHistory(w) }}
            style={{ ...inputStyle, width: 'auto', minWidth: 190, cursor: 'pointer' }}>
            <option value="">Worker history…</option>
            {workers.map(w => <option key={w.id} value={w.id}>{w.full_name}{w.worker_code ? ` · ${w.worker_code}` : ''}</option>)}
          </select>
          <input type="date" value={date} onChange={e => setDate(e.target.value)} style={{ ...inputStyle, width: 'auto' }} />
          <button onClick={load} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>
            <RefreshCw size={14} /> Refresh
          </button>
        </div>
      </div>

      {loadError ? (
        <LoadError error={loadError} onRetry={load} title="Could not load attendance" />
      ) : loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}>Loading attendance…</div>
      ) : rows.length === 0 ? (
        <Empty icon={Users} title="Nobody on site"
          hint={vendorId ? 'No worker from this vendor was admitted through the gate on this date.' : 'No worker was admitted through the gate on this date.'} />
      ) : (
        <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
          <div style={{ display: 'flex', gap: 16, padding: '12px 16px', borderBottom: '1px solid var(--border)', fontSize: 12 }}>
            <span style={{ color: 'var(--text-muted)' }}>Total <strong style={{ color: 'var(--text-h)' }}>{summary.total}</strong></span>
            <span style={{ color: 'var(--text-muted)' }}>On site <strong style={{ color: '#10b981' }}>{summary.on_site}</strong></span>
            <span style={{ color: 'var(--text-muted)' }}>Departed <strong style={{ color: 'var(--text-h)' }}>{summary.departed}</strong></span>
          </div>
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead><tr>{['Worker', 'In', 'Out', 'Duration', 'Status', ''].map((h, i) => <th key={i} style={th}>{h}</th>)}</tr></thead>
            <tbody>
              {rows.map(r => (
                <tr key={r.id} className="pr-li-row">
                  <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)' }}>
                    {r.worker?.full_name}
                    <span style={{ color: '#a78bfa', fontWeight: 700, fontSize: 11, marginLeft: 7 }}>{r.worker?.worker_code}</span>
                    {r.worker?.designation && <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 500 }}>{r.worker.designation}</div>}
                  </td>
                  <td style={{ ...td, color: 'var(--text-h)', fontWeight: 600 }}>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><LogIn size={12} style={{ color: '#10b981' }} /> {fmtTime(r.check_in_at)}</span>
                  </td>
                  <td style={{ ...td, color: r.check_out_at ? 'var(--text-h)' : 'var(--text-muted)', fontWeight: 600 }}>
                    {r.check_out_at
                      ? <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><LogOut size={12} style={{ color: '#f59e0b' }} /> {fmtTime(r.check_out_at)}</span>
                      : '—'}
                  </td>
                  <td style={{ ...td, color: 'var(--text-muted)' }}>{r.duration_label || '—'}</td>
                  <td style={td}>
                    {r.on_site
                      ? <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '3px 10px', borderRadius: 20, fontSize: 11, fontWeight: 700, background: 'rgba(16,185,129,0.15)', color: '#10b981', border: '1px solid rgba(16,185,129,0.4)' }}><Clock size={10} /> On site</span>
                      : <span style={{ padding: '3px 10px', borderRadius: 20, fontSize: 11, fontWeight: 700, background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Departed</span>}
                  </td>
                  <td style={{ ...td, textAlign: 'right' }}>
                    <button onClick={() => setHistory(r.worker)}
                      style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '5px 11px', borderRadius: 8, border: '1px solid var(--border)', background: 'var(--bg-card)', color: '#a78bfa', fontWeight: 700, cursor: 'pointer', fontSize: 11.5 }}>
                      <History size={12} /> History
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {history && (
        <HistoryModal worker={history} from={shiftDays(date, -(HISTORY_DAYS - 1))} to={date} onClose={() => setHistory(null)} />
      )}
    </div>
  )
}

/**
 * One worker's attendance over the window ending on the selected date.
 *
 * The endpoint counts ADMITTED crossings only, so a day a worker was refused at
 * the gate does not appear — which is the point: a refusal is not attendance.
 */
function HistoryModal({ worker, from, to, onClose }) {
  const [data, setData] = useState(null)
  const [err, setErr] = useState(null)

  const load = useCallback(() => {
    setErr(null)
    purchaseApi.gate.attendance(worker.id, { from, to })
      .then(d => setData(d?.data ?? d ?? null))
      .catch(e => { setData(null); setErr(e) })
  }, [worker.id, from, to])
  useEffect(() => { load() }, [load])

  const days = data?.days || []
  const totals = data?.totals || {}
  const th = { textAlign: 'left', padding: '9px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
  const td = { padding: '10px 12px', borderBottom: '1px solid var(--border)', fontSize: 12.5, verticalAlign: 'middle' }

  return (
    <Overlay onClose={onClose} width={720}>
      <h2 style={{ color: 'var(--text-h)', margin: '0 0 4px', fontSize: 18, fontWeight: 800 }}>
        {worker.full_name}
        <span style={{ color: '#a78bfa', fontWeight: 700, fontSize: 12, marginLeft: 8 }}>{worker.worker_code}</span>
      </h2>
      <p style={{ color: 'var(--text-muted)', fontSize: 12, margin: '0 0 16px' }}>
        Attendance · {fmtDate(from)} — {fmtDate(to)}{worker.designation ? ` · ${worker.designation}` : ''}
      </p>

      {err ? <LoadError error={err} onRetry={load} title="Could not load this worker's attendance" />
        : !data ? <div style={{ padding: 30, textAlign: 'center', color: 'var(--text-muted)', fontSize: 13 }}>Loading…</div>
        : (
          <>
            <div style={{ display: 'flex', gap: 16, padding: '12px 14px', marginBottom: 14, borderRadius: 12, background: 'var(--bg-input)', border: '1px solid var(--border)', fontSize: 12 }}>
              <span style={{ color: 'var(--text-muted)' }}>Days present <strong style={{ color: 'var(--text-h)' }}>{totals.days_present ?? 0}</strong></span>
              {/* Only days with both an entry and an exit contribute hours, so
                  this is time accounted for — not time on site. */}
              <span style={{ color: 'var(--text-muted)' }} title="Completed days only — a day with no exit contributes no hours">
                Hours accounted <strong style={{ color: '#10b981' }}>{totals.hours ?? 0}</strong>
              </span>
            </div>

            {days.length === 0 ? (
              <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '20px 0', textAlign: 'center' }}>
                No admitted crossings in this window.
              </p>
            ) : (
              <div style={{ maxHeight: 380, overflowY: 'auto', borderRadius: 12, border: '1px solid var(--border)' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                  <thead><tr>{['Date', 'First in', 'Last out', 'Crossings', 'Hours'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
                  <tbody>
                    {days.map(d => (
                      <tr key={d.date} className="pr-li-row">
                        <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)', whiteSpace: 'nowrap' }}>{fmtDate(d.date)}</td>
                        <td style={{ ...td, color: 'var(--text-h)' }}>
                          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><LogIn size={12} style={{ color: '#10b981' }} /> {fmtTime(d.first_in)}</span>
                        </td>
                        <td style={{ ...td, color: d.last_out ? 'var(--text-h)' : 'var(--text-muted)' }}>
                          {d.last_out
                            ? <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><LogOut size={12} style={{ color: '#f59e0b' }} /> {fmtTime(d.last_out)}</span>
                            : '—'}
                        </td>
                        <td style={{ ...td, color: 'var(--text-muted)' }}>{d.crossings ?? 0}</td>
                        {/* hours is NULL for a day with an entry and no exit. That
                            day is not zero hours — nobody knows how long it was —
                            so it is left open rather than counted as nothing. */}
                        <td style={{ ...td, fontWeight: 700, color: d.hours == null ? '#f59e0b' : 'var(--text-h)' }}>
                          {d.hours == null ? 'open' : `${d.hours} h`}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </>
        )}

      {/* Read-only, so there is nothing to confirm — the modal closes on its own
          ✕ or this button, never on a backdrop click. */}
      <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 20 }}>
        <button onClick={onClose} style={{ padding: '9px 20px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>Close</button>
      </div>
    </Overlay>
  )
}
