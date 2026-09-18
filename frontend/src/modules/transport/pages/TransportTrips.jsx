import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { RefreshCw, Search, Truck, AlertTriangle, Loader2, Eye } from 'lucide-react'
import { transportTripApi } from '@/services/transportApi'
import { tripStatusCfg, TRIP_STATUS_LABEL, fmtMoney, fmtDate, fmtLocation } from '../constants'

/**
 * Trips list (SNG-TRN-007).
 *
 * There is no "New Trip" button here on purpose: a trip is only ever created
 * FROM an approved order (CTR-004 — "Cannot create orphan trip"), so the action
 * lives on the order's detail page where the order is in hand. A create button
 * here would open a form whose first question is "which order?" and whose most
 * likely outcome is a 422.
 *
 * Status chips show only the states that currently exist in the data, plus the
 * two this ticket can reach. Listing all sixteen would advertise states no
 * ticket has built yet.
 */
export default function TransportTrips() {
  const navigate = useNavigate()

  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState(null)
  const [counts, setCounts] = useState({})
  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const load = useCallback(async () => {
    setLoading(true); setError(null)
    try {
      const [page, c] = await Promise.all([
        transportTripApi.list({ status: status || undefined, search: search || undefined }),
        transportTripApi.statusCounts(),
      ])
      setRows(Array.isArray(page?.data) ? page.data : [])
      setMeta(page ?? null)
      setCounts(c || {})
    } catch (e) {
      setError(e?.message || 'Could not load trips.')
    } finally {
      setLoading(false)
    }
  }, [status, search])

  useEffect(() => { load() }, [load])

  const total = Object.values(counts).reduce((a, b) => a + Number(b || 0), 0)
  // Only states that actually occur in this workspace, so the filter row stays
  // honest about what exists.
  const present = Object.keys(counts).filter((k) => Number(counts[k]) > 0)

  return (
    <div className="p-5 md:p-7 flex flex-col gap-5">
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <p style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em', textTransform: 'uppercase' }}>Transport</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>Trips</h1>
          <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: '4px 0 0' }}>
            The central operational object. A trip is created from an approved order.
          </p>
        </div>
        <button onClick={load} disabled={loading}
          style={{ padding: '8px 13px', borderRadius: 9, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)', fontSize: 12.5, fontWeight: 700, display: 'inline-flex', alignItems: 'center', gap: 6, cursor: 'pointer' }}>
          <RefreshCw size={13} className={loading ? 'animate-spin' : ''} /> Refresh
        </button>
      </div>

      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
        <Chip active={status === ''} onClick={() => setStatus('')} label="All" count={total} />
        {present.map((key) => {
          const cfg = tripStatusCfg(key)
          return (
            <Chip key={key} active={status === key} onClick={() => setStatus(key)}
              label={TRIP_STATUS_LABEL[key] || key} count={counts[key]} cfg={cfg} />
          )
        })}
      </div>

      <div style={{ position: 'relative', maxWidth: 420 }}>
        <Search size={14} style={{ position: 'absolute', left: 11, top: 11, color: 'var(--text-muted)' }} />
        <input value={search} onChange={(e) => setSearch(e.target.value)}
          placeholder="Trip number or route…"
          style={{ width: '100%', padding: '9px 12px 9px 32px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 9, color: 'var(--text-h)', fontSize: 13, outline: 'none' }} />
      </div>

      {error && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '11px 14px', borderRadius: 10, background: 'rgba(239,68,68,0.10)', border: '1px solid rgba(239,68,68,0.30)', color: '#f87171', fontSize: 13 }}>
          <AlertTriangle size={15} /> {error}
        </div>
      )}

      <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
        {loading ? (
          <div style={{ padding: 40, textAlign: 'center', color: 'var(--text-muted)', fontSize: 13 }}>
            <Loader2 size={18} className="animate-spin" style={{ display: 'inline' }} /> Loading trips…
          </div>
        ) : rows.length === 0 ? (
          <div style={{ padding: 48, textAlign: 'center' }}>
            <Truck size={30} style={{ color: 'var(--text-muted)', marginBottom: 10 }} />
            {/* Three situations, three sentences. It said "No X yet" even when
                the workspace was full of them, simply because a filter matched
                none — which tells the reader something false about their own
                data and hides the thing they should check: what they typed.
                Same defect as the Containers list carried until 2026-09-18. */}
            <p style={{ color: 'var(--text-h)', fontSize: 15, fontWeight: 700, margin: 0 }}>
              {search ? `Nothing here matches “${search}”` : status ? 'No trip is at that stage' : 'No trips yet'}
            </p>
            <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '6px 0 0' }}>
              {search
                ? 'Try the trip number, the customer, or the route.'
                : status
                  ? 'Choose “All” to see every trip.'
                  : 'Approve a transport order, then create its trip from the order page.'}
            </p>
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 880 }}>
              <thead>
                <tr>
                  {['Trip', 'Order', 'Customer', 'Route', 'Freight', 'Status', 'Created', ''].map((h, i) => (
                    <th key={h || i} style={{ textAlign: i === 4 ? 'right' : 'left', padding: '10px 14px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '.08em', borderBottom: '1px solid var(--border)', whiteSpace: 'nowrap' }}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {rows.map((t) => {
                  const st = tripStatusCfg(t.status)
                  return (
                    <tr key={t.id} onClick={() => navigate(`/app/transport/trips/${t.id}`)}
                      style={{ cursor: 'pointer', borderBottom: '1px solid var(--border-soft, var(--border))' }}>
                      <td style={{ padding: '11px 14px', fontSize: 12.5, fontWeight: 800, color: '#a78bfa', whiteSpace: 'nowrap' }}>{t.trip_number}</td>
                      <td style={{ padding: '11px 14px', fontSize: 12.5, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{t.order?.order_number || '—'}</td>
                      <td style={{ padding: '11px 14px', fontSize: 12.5, color: 'var(--text-h)' }}>{t.customer?.company || '—'}</td>
                      <td style={{ padding: '11px 14px', fontSize: 12, color: 'var(--text-muted)', maxWidth: 240 }}>{t.route || '—'}</td>
                      <td style={{ padding: '11px 14px', fontSize: 12.5, textAlign: 'right', fontWeight: 800, color: '#34d399', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' }}>
                        {fmtMoney(t.approved_freight, t.currency)}
                      </td>
                      <td style={{ padding: '11px 14px' }}>
                        <span style={{ padding: '3px 10px', borderRadius: 999, background: st.bg, color: st.color, fontSize: 10.5, fontWeight: 800, whiteSpace: 'nowrap' }}>{st.label}</span>
                      </td>
                      <td style={{ padding: '11px 14px', fontSize: 12, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{fmtDate(t.created_at)}</td>
                      <td style={{ padding: '11px 14px', textAlign: 'right' }}><Eye size={14} style={{ color: 'var(--text-muted)' }} /></td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {meta?.total > 0 && (
        <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: 0 }}>Showing {rows.length} of {meta.total}</p>
      )}
    </div>
  )
}

function Chip({ active, onClick, label, count, cfg }) {
  return (
    <button onClick={onClick}
      style={{
        padding: '6px 13px', borderRadius: 999, fontSize: 12, fontWeight: 700, cursor: 'pointer',
        background: active ? (cfg?.bg || 'rgba(124,58,237,0.16)') : 'var(--bg-input)',
        border: `1px solid ${active ? (cfg?.color || '#7C3AED') : 'var(--border)'}`,
        color: active ? (cfg?.color || '#a78bfa') : 'var(--text-muted)',
        display: 'inline-flex', alignItems: 'center', gap: 7,
      }}>
      {label}
      <span style={{ fontSize: 11, fontWeight: 800, opacity: 0.85, fontVariantNumeric: 'tabular-nums' }}>{count}</span>
    </button>
  )
}
