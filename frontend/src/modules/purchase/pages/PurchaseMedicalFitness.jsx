import { useState, useEffect, useCallback, useMemo } from 'react'
import { useParams, useSearchParams } from 'react-router-dom'
import { HeartPulse, RefreshCw } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import { fmtDate } from '../constants'
import LoadError from '@/components/ui/LoadError'
import { KIT3D_STYLE as PURCHASE_STYLE } from '@/components/ui/kit3d'

// Purchase Medical Fitness register (mirror of TPV §3/§16). A cross-workforce
// view of worker medical examinations — fitness verdict and certificate
// currency — previously reachable only inside the worker wizard.
//
// Two things differ from TPV, and only these two:
//  1. /purchase/workforce/medicals is STRICTLY vendor-scoped (it 422s without a
//     vendor_id), so a vendor is a precondition rather than an optional filter.
//  2. Purchase keeps medicals as a HISTORY (purchase_worker_medicals is
//     one-to-many) where TPV projects one row per worker, so this lists the
//     records themselves and marks which one is a worker's current medical.
const FITNESS_TONE = {
  Fit: '#10b981',
  Fit_With_Restrictions: '#f59e0b',
  Pending: '#6366f1',
  Unfit: '#ef4444',
  Expired: '#ef4444',
}
// App\Support\Purchase\PurchaseMedicalFitness::ALL. TPV's register reads its
// options off the response; this endpoint returns rows only, so the list is held
// here against the PHP enum.
const FITNESS_STATUSES = ['Pending', 'Fit', 'Fit_With_Restrictions', 'Unfit', 'Expired']

// Mental-health screening bands, as TpvMedicalFitness::bandForScore writes them
// (Low <5 · Moderate 5-9 · High 10+). Informational triage, not a badge gate —
// hence tones that read as attention, not as a failed verdict.
const BAND_TONE = { Low: '#10b981', Moderate: '#f59e0b', High: '#ef4444' }

const label = (s) => (s || '—').replace(/_/g, ' ')

const _days = (d) => (d ? Math.ceil((new Date(d) - Date.now()) / 86400000) : null)

// A medical lapses on expiry_date. valid_until is the backstop: the save path
// copies one into the other, but a row written before that column existed
// carries only valid_until and would otherwise read as "never expires".
const lapseDate = (m) => m.expiry_date || m.valid_until || null

// BMI = kg / m² — the same formula and bands the worker wizard's health panel
// computes, so a register row and the examination it came from never disagree.
const bmiOf = (m) => (m.height_cm && m.weight_kg
  ? Number(m.weight_kg) / Math.pow(Number(m.height_cm) / 100, 2)
  : null)
const bmiBand = (v) => (v < 18.5 ? 'Underweight' : v <= 24.9 ? 'Normal' : v <= 29.9 ? 'Overweight' : 'Obese')
const bmiTone = (v) => (v >= 18.5 && v <= 24.9 ? '#10b981' : v >= 16 && v <= 30 ? '#f59e0b' : '#ef4444')

/** Newest examination first — mirrors the wizard's ordering so "latest" means the
 *  same thing on both screens. The endpoint already sorts this way; re-sorting
 *  keeps the latest marker honest if it ever stops. */
function sortMedicals(rows) {
  return [...(rows || [])].sort((a, b) => {
    const da = a.exam_date ? new Date(a.exam_date).getTime() : 0
    const db = b.exam_date ? new Date(b.exam_date).getTime() : 0
    return db - da || (b.id - a.id)
  })
}

export default function PurchaseMedicalFitness() {
  const { vendorId: routeVendorId } = useParams()   // present inside a vendor-scoped route
  const [searchParams, setSearchParams] = useSearchParams()

  // Vendor scope. Held in the query string rather than in state so one vendor's
  // register is deep-linkable and survives a reload — the same ?vendor_id= the
  // workforce register reads; a future nested route supplies it as a path param
  // and nothing else here has to change.
  const vendorId = routeVendorId || searchParams.get('vendor_id') || ''

  const [vendors, setVendors] = useState([])
  const [rows, setRows] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [filters, setFilters] = useState({ fitness_status: '', expiry: '' })

  useEffect(() => {
    purchaseApi.vendors.list()
      .then(res => setVendors(Array.isArray(res?.data ?? res) ? (res.data ?? res) : []))
      .catch(() => {})
  }, [])

  const load = useCallback(() => {
    // No vendor, no request: the endpoint would 422, and an error banner is the
    // wrong answer to "you have not chosen yet".
    if (!vendorId) { setRows(null); setLoadError(null); return }
    purchaseApi.workforce.medicals(vendorId).then(d => {
      setLoadError(null)
      setRows(d?.data ?? [])
    }).catch(e => { setRows([]); setLoadError(e) })
  }, [vendorId])
  useEffect(() => { load() }, [load])

  const pickVendor = (id) => {
    const next = new URLSearchParams(searchParams)
    if (id) next.set('vendor_id', id)
    else next.delete('vendor_id')
    setSearchParams(next, { replace: true })
  }

  const vendor = vendors.find(v => String(v.id) === String(vendorId)) || null

  // Which record is each worker's CURRENT medical. Computed over the whole
  // register rather than the filtered view — filtering to "Unfit" must not crown
  // a superseded record as the one the badge gate reads.
  const latestIds = useMemo(() => {
    const seen = new Set(), latest = new Set()
    for (const m of sortMedicals(rows)) {
      const w = m.purchase_worker_id ?? m.worker?.id
      if (w == null || seen.has(w)) continue
      seen.add(w)
      latest.add(m.id)
    }
    return latest
  }, [rows])

  // TPV filters server-side; this endpoint takes no parameter but the vendor, so
  // the same two controls narrow the rows here instead.
  const filtered = useMemo(() => sortMedicals(rows).filter(m => {
    if (filters.fitness_status && m.fitness_status !== filters.fitness_status) return false
    if (!filters.expiry) return true
    const d = _days(lapseDate(m))
    if (d == null) return false
    return filters.expiry === 'expired' ? d < 0 : (d >= 0 && d <= 30)
  }), [rows, filters])

  // The endpoint returns rows only — there is no summary block like TPV's — so
  // the strip is derived from them, the way the workforce register derives its
  // vendor-scoped KPIs. Counted over every record, not the filtered view, so the
  // totals never contradict themselves as the filters move.
  const summary = useMemo(() => {
    if (!rows) return null
    const by = (s) => rows.filter(m => m.fitness_status === s).length
    return {
      total: rows.length,
      fit: by('Fit'),
      pending: by('Pending'),
      unfit: by('Unfit'),
      expired: rows.filter(m => { const d = _days(lapseDate(m)); return d != null && d < 0 }).length,
    }
  }, [rows])

  return (
    <div style={{ padding: 4 }}>
      <style>{PURCHASE_STYLE}</style>
      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>WORKFORCE</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0', display: 'flex', alignItems: 'center', gap: 8 }}>
            <HeartPulse size={20} /> Medical Fitness
          </h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Worker medical examinations — fitness verdict and certificate currency. Unfit or lapsed is a hard gate.</p>
        </div>
        <button onClick={load} style={btnGhost}><RefreshCw size={14} /> Refresh</button>
      </div>

      {/* Summary strip */}
      {summary && (
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 14 }}>
          <Stat label="Records" value={summary.total} tone="#7C3AED" />
          <Stat label="Fit" value={summary.fit} tone="#10b981" />
          <Stat label="Pending" value={summary.pending} tone="#6366f1" />
          <Stat label="Unfit" value={summary.unfit} tone="#ef4444" />
          <Stat label="Expired" value={summary.expired} tone="#ef4444" />
        </div>
      )}

      {/* Filters. The vendor picker leads because it is the precondition, not a
          narrowing — nothing below it exists until a vendor is chosen. */}
      <div style={{ display: 'flex', gap: 10, marginBottom: 12, flexWrap: 'wrap' }}>
        <select value={vendorId} onChange={e => pickVendor(e.target.value)} disabled={!!routeVendorId} style={{ ...sel, minWidth: 220 }}>
          <option value="">Select a vendor…</option>
          {vendors.map(v => <option key={v.id} value={v.id}>{v.company_name}</option>)}
        </select>
        <select value={filters.fitness_status} onChange={e => setFilters(f => ({ ...f, fitness_status: e.target.value }))} style={sel}>
          <option value="">All fitness</option>
          {FITNESS_STATUSES.map(s => <option key={s} value={s}>{label(s)}</option>)}
        </select>
        <select value={filters.expiry} onChange={e => setFilters(f => ({ ...f, expiry: e.target.value }))} style={sel}>
          <option value="">Any currency</option>
          <option value="expiring">Expiring (≤30d)</option>
          <option value="expired">Expired</option>
        </select>
      </div>

      {!vendorId ? (
        <div className="pr-glass" style={{ padding: 60, textAlign: 'center', borderRadius: 14 }}>
          <div style={{ width: 60, height: 60, borderRadius: 18, margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'linear-gradient(145deg,#7C3AED,#5b21b6)', boxShadow: '0 10px 24px -6px rgba(124,58,237,.6)' }}>
            <HeartPulse size={26} color="#fff" />
          </div>
          <h3 style={{ color: 'var(--text-h)', fontSize: 16, fontWeight: 800, margin: '0 0 6px' }}>Choose a vendor</h3>
          <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>The Purchase medical register is kept per vendor. Pick one above to see its workers&rsquo; examinations.</p>
        </div>
      ) : (
        <div className="pr-glass" style={{ padding: 0, borderRadius: 14, overflow: 'hidden' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
              <thead>
                <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                  {['Worker', 'Vendor', 'Fitness', 'Exam date', 'Valid until', 'Examiner', 'Vitals / BMI', 'Screening'].map((h, i) => <th key={i} style={{ padding: '11px 14px' }}>{h}</th>)}
                </tr>
              </thead>
              <tbody>
                {loadError ? <tr><td colSpan={8} style={{ padding: 8 }}><LoadError error={loadError} onRetry={load} /></td></tr>
                  : rows === null ? <tr><td colSpan={8} style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</td></tr>
                  : filtered.length === 0 ? <tr><td colSpan={8} style={{ padding: 18, color: 'var(--text-muted)' }}>No medical records yet.</td></tr>
                  : filtered.map(m => {
                    const tone = FITNESS_TONE[m.fitness_status] || '#6b7280'
                    const lapsed = _days(lapseDate(m)) < 0
                    const isLatest = latestIds.has(m.id)
                    const bmi = bmiOf(m)
                    return (
                      <tr key={m.id} style={{ borderTop: '1px solid var(--border)' }}>
                        <td style={{ padding: '10px 14px', fontWeight: 700, color: 'var(--text-h)' }}>
                          {m.worker?.full_name || '—'}
                          {/* History, not a projection: say which row is the worker's
                              current medical so an old Unfit never reads as today's. */}
                          {isLatest
                            ? <span style={{ marginLeft: 6, padding: '1px 7px', borderRadius: 999, background: 'rgba(16,185,129,0.15)', color: '#10b981', fontSize: 10, fontWeight: 700 }}>latest</span>
                            : <span style={{ marginLeft: 6, color: 'var(--text-muted)', fontSize: 10, fontWeight: 500 }}>superseded</span>}
                          <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 500 }}>{m.worker?.worker_code}</div>
                        </td>
                        <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>{vendor?.company_name || '—'}</td>
                        <td style={{ padding: '10px 14px' }}>
                          <span style={{ display: 'inline-block', padding: '3px 9px', borderRadius: 999, background: tone + '22', color: tone, fontSize: 11.5, fontWeight: 700 }}>{label(m.fitness_status)}</span>
                          {/* Fit-with-restrictions passes the badge gate, so the
                              restriction is the part that has to travel with it. */}
                          {m.restrictions && <div style={{ fontSize: 10.5, color: 'var(--text-muted)', marginTop: 3, maxWidth: 200 }}>{m.restrictions}</div>}
                        </td>
                        <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>{fmtDate(m.exam_date)}</td>
                        <td style={{ padding: '10px 14px', color: lapsed ? '#ef4444' : 'var(--text-muted)', fontWeight: lapsed ? 700 : 500 }}>
                          {fmtDate(lapseDate(m))}{lapsed ? ' · expired' : ''}
                        </td>
                        <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>
                          {m.examiner_name || '—'}
                          {(m.clinic_name || m.exam_type) && (
                            <div style={{ fontSize: 10.5, opacity: 0.8 }}>{[m.clinic_name, m.exam_type && label(m.exam_type)].filter(Boolean).join(' · ')}</div>
                          )}
                        </td>
                        <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>
                          {bmi != null
                            ? <div style={{ color: bmiTone(bmi), fontWeight: 700 }}>BMI {bmi.toFixed(1)} <span style={{ fontWeight: 500 }}>({bmiBand(bmi)})</span></div>
                            : <div>—</div>}
                          <div style={{ fontSize: 10.5, opacity: 0.8 }}>
                            {[
                              m.height_cm && `${m.height_cm} cm`,
                              m.weight_kg && `${m.weight_kg} kg`,
                              m.bp_systolic && m.bp_diastolic && `BP ${m.bp_systolic}/${m.bp_diastolic}`,
                              m.vision && `vision ${m.vision}`,
                              m.blood_group,
                            ].filter(Boolean).join(' · ') || 'no vitals recorded'}
                          </div>
                        </td>
                        <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>
                          {m.screening_band
                            ? <span style={{ display: 'inline-block', padding: '3px 9px', borderRadius: 999, background: (BAND_TONE[m.screening_band] || '#6b7280') + '22', color: BAND_TONE[m.screening_band] || '#6b7280', fontSize: 11.5, fontWeight: 700 }}>{m.screening_band}</span>
                            : '—'}
                          {m.screening_score != null && <div style={{ fontSize: 10.5, opacity: 0.8 }}>score {m.screening_score}</div>}
                        </td>
                      </tr>
                    )
                  })}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}

function Stat({ label, value, tone }) {
  return (
    <div className="pr-glass" style={{ padding: '10px 16px', borderRadius: 12, minWidth: 96 }}>
      <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.04em' }}>{label}</div>
      <div style={{ fontSize: 22, fontWeight: 900, color: tone }}>{value ?? 0}</div>
    </div>
  )
}

const btnGhost = { display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }
const sel = { padding: '8px 12px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-h)', fontSize: 13 }
