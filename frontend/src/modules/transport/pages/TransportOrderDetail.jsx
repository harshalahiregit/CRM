import { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, Building2, MapPin, Truck, FileText, History, Route as RouteIcon,
  AlertTriangle, Loader2, Plus, CheckCircle2, Send, CornerUpLeft, Ban,
} from 'lucide-react'
import { transportOrderApi, transportTripApi } from '@/services/transportApi'
import { useToast } from '@/components/ui/Toast'
import Modal from '@/components/ui/Modal'
import {
  orderStatusCfg, priorityCfg, tripStatusCfg, ORDER_TRANSITIONS, ORDER_SOURCES,
  ORDER_STATUS_LABEL, TRIP_STATUS_LABEL,
  fmtDateTime, fmtDate, fmtLocation, fmtMoney,
} from '../constants'

/**
 * Transport Order detail (SNG-TRN-006).
 *
 * Follows PurchaseContractDetail's shape: back arrow, accent eyebrow with the
 * reference, title + status pill, status-gated action buttons on the right, then
 * a 1.6fr/1fr grid of glass panels with the audit trail at the bottom left.
 *
 * Actions are inline and gated by the state machine — the UI only offers a
 * transition ORDER_TRANSITIONS says the API will accept, so a button never
 * produces a 422. That is the codebase's rule against fake affordances.
 *
 * "Create trip" appears only on an approved order, because that is the only
 * state SNG-TRN-007 accepts.
 */
export default function TransportOrderDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const toast = useToast()

  const [order, setOrder] = useState(null)
  const [audit, setAudit] = useState([])
  const [loading, setLoading] = useState(true)
  const [notFound, setNotFound] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const [rejectOpen, setRejectOpen] = useState(false)
  const [reason, setReason] = useState('')
  const [tripOpen, setTripOpen] = useState(false)
  const [tripForm, setTripForm] = useState({ approved_freight: '', currency: 'INR', route: '' })

  const load = useCallback(async () => {
    setLoading(true); setError(null)
    try {
      const d = await transportOrderApi.get(id)
      setOrder(d?.order ?? null)
      setAudit(Array.isArray(d?.audit) ? d.audit : [])
    } catch (e) {
      if (e?.status === 404) setNotFound(true)
      else setError(e?.message || 'Could not load this order.')
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => { load() }, [load])

  const move = async (to, why = null) => {
    setBusy(true); setError(null)
    try {
      await transportOrderApi.transition(id, to, why)
      toast.success('Order updated.')
      setRejectOpen(false); setReason('')
      load()
    } catch (e) {
      toast.error(e?.message || 'That change was refused.')
    } finally {
      setBusy(false)
    }
  }

  const createTrip = async () => {
    setBusy(true)
    try {
      const trip = await transportTripApi.create({
        order_id: Number(id),
        approved_freight: tripForm.approved_freight === '' ? null : Number(tripForm.approved_freight),
        currency: tripForm.currency || 'INR',
        route: tripForm.route || null,
      })
      toast.success(`Trip ${trip?.trip_number ?? ''} created.`)
      setTripOpen(false)
      navigate(`/app/transport/trips/${trip.id}`)
    } catch (e) {
      toast.error(e?.message || 'The trip could not be created.')
    } finally {
      setBusy(false)
    }
  }

  if (loading) {
    return (
      <Wrap>
        <div style={{ height: 44, width: 260, borderRadius: 12, background: 'var(--border)', marginBottom: 16 }} />
        <div style={{ height: 220, borderRadius: 16, background: 'var(--border)' }} />
      </Wrap>
    )
  }

  if (notFound || !order) {
    return (
      <Wrap>
        <div style={{ textAlign: 'center', padding: 60 }}>
          <AlertTriangle size={30} style={{ color: 'var(--text-muted)', marginBottom: 10 }} />
          <p style={{ color: 'var(--text-h)', fontSize: 16, fontWeight: 800, margin: 0 }}>Order not found</p>
          <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '6px 0 14px' }}>It may have been removed, or it belongs to another workspace.</p>
          <button onClick={() => navigate('/app/transport/orders')} style={btn('#7C3AED', true)}>Back to orders</button>
        </div>
      </Wrap>
    )
  }

  const st = orderStatusCfg(order.order_status)
  const pr = priorityCfg(order.priority)
  const moves = ORDER_TRANSITIONS[order.order_status] || []
  // TRP-P0-001 forbids a second ACTIVE trip on the same order, so the button is
  // hidden once one exists rather than offered and then refused with a 422.
  const openTrip = (order.trips || []).find((t) => t.status !== 'closed')
  const canCreateTrip = order.order_status === 'approved' && !openTrip
  const sourceLabel = ORDER_SOURCES.find((s) => s.value === order.source)?.label || order.source

  return (
    <Wrap>
      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12, marginBottom: 18, flexWrap: 'wrap' }}>
        <button onClick={() => navigate('/app/transport/orders')} style={backBtn}><ArrowLeft size={16} /></button>
        <div style={{ flex: 1, minWidth: 0 }}>
          <p style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>
            {order.order_number} · {order.service_type}
          </p>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
            <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>
              {order.customer?.company || 'Transport Order'}
            </h1>
            <span style={{ padding: '4px 11px', borderRadius: 999, background: st.bg, color: st.color, fontSize: 11.5, fontWeight: 800 }}>{st.label}</span>
            <span style={{ padding: '4px 11px', borderRadius: 999, background: pr.bg, color: pr.color, fontSize: 11.5, fontWeight: 800 }}>{order.priority}</span>
          </div>
          <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginTop: 5, fontSize: 12.5, color: 'var(--text-muted)' }}>
            <RouteIcon size={13} /> {order.route || `${fmtLocation(order.pickup_location)} → ${fmtLocation(order.delivery_location)}`}
          </div>
        </div>
        <div style={{ display: 'flex', gap: 8, flexShrink: 0, flexWrap: 'wrap', justifyContent: 'flex-end' }}>
          {moves.map((m) => (
            <button key={m.to} disabled={busy}
              onClick={() => (m.needsReason ? setRejectOpen(true) : move(m.to))}
              style={btn(m.to === 'approved' ? '#10b981' : m.to === 'rejected' ? '#ef4444' : m.to === 'submitted' ? '#0ea5e9' : '#94a3b8', m.to === 'approved' || m.to === 'submitted')}>
              {m.to === 'submitted' && <Send size={14} />}
              {m.to === 'approved' && <CheckCircle2 size={14} />}
              {m.to === 'rejected' && <Ban size={14} />}
              {m.to === 'draft' && <CornerUpLeft size={14} />}
              {m.label}
            </button>
          ))}
          {/* Only an approved order with no live trip can become one. */}
          {canCreateTrip && (
            <button disabled={busy} onClick={() => { setTripForm({ approved_freight: '', currency: 'INR', route: order.route || '' }); setTripOpen(true) }} style={btn('#7C3AED', true)}>
              <Plus size={14} /> Create trip
            </button>
          )}
        </div>
      </div>

      {error && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '11px 14px', borderRadius: 10, background: 'rgba(239,68,68,0.10)', border: '1px solid rgba(239,68,68,0.30)', color: '#f87171', fontSize: 13, marginBottom: 14 }}>
          <AlertTriangle size={15} /> {error}
        </div>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: '1.6fr 1fr', gap: 16, alignItems: 'start' }}>
        {/* Left */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          <Panel icon={MapPin} title="Pickup & Delivery">
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, marginTop: 12 }}>
              <LocationBlock label="Pickup" loc={order.pickup_location} />
              <LocationBlock label="Delivery" loc={order.delivery_location} />
            </div>
          </Panel>

          <Panel icon={Truck} title="Service">
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginTop: 12 }}>
              <KV label="Service type" value={order.service_type} />
              <KV label="Required by" value={fmtDateTime(order.required_at)} />
              <KV label="Route" value={order.route} />
              <KV label="Rate / contract reference" value={order.rate_reference} />
            </div>
          </Panel>

          {(order.special_requirements || order.billing_requirements) && (
            <Panel icon={FileText} title="Additional">
              {order.special_requirements && (
                <div style={{ marginTop: 12 }}>
                  <p className="label-caps" style={labelStyle}>Special requirements</p>
                  <p style={{ fontSize: 13, color: 'var(--text-h)', margin: '4px 0 0', whiteSpace: 'pre-wrap', lineHeight: 1.6 }}>{order.special_requirements}</p>
                </div>
              )}
              {order.billing_requirements && (
                <div style={{ marginTop: 14 }}>
                  <p className="label-caps" style={labelStyle}>Billing requirements</p>
                  <p style={{ fontSize: 13, color: 'var(--text-h)', margin: '4px 0 0', whiteSpace: 'pre-wrap', lineHeight: 1.6 }}>{order.billing_requirements}</p>
                </div>
              )}
            </Panel>
          )}

          {/* The audit trail IS the history — read from the record, not re-derived. */}
          <Panel icon={History} title="Activity">
            <AuditList entries={audit} />
          </Panel>
        </div>

        {/* Right */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          <Panel icon={Building2} title="Customer">
            <div style={{ display: 'grid', gap: 12, marginTop: 12 }}>
              <KV label="Customer" value={order.customer?.company} />
              <KV label="Customer reference" value={order.customer_reference} />
              <KV label="Source" value={sourceLabel} />
              <KV label="Created" value={fmtDate(order.created_at)} />
            </div>
          </Panel>

          <Panel icon={Truck} title={`Trips · ${(order.trips || []).length}`}>
            {(order.trips || []).length === 0 ? (
              <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '12px 0 0' }}>
                {order.order_status === 'approved'
                  ? 'No trip yet. Use \u201cCreate trip\u201d above.'
                  : 'A trip can be created once this order is approved.'}
              </p>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 8, marginTop: 12 }}>
                {order.trips.map((t) => {
                  const ts = tripStatusCfg(t.status)
                  return (
                    <button key={t.id} onClick={() => navigate(`/app/transport/trips/${t.id}`)}
                      style={{ textAlign: 'left', padding: '10px 12px', borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)', cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10 }}>
                      <span style={{ fontSize: 12.5, fontWeight: 800, color: '#a78bfa' }}>{t.trip_number}</span>
                      <span style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <span style={{ fontSize: 12, color: 'var(--text-muted)', fontVariantNumeric: 'tabular-nums' }}>{fmtMoney(t.approved_freight, t.currency)}</span>
                        <span style={{ padding: '2px 8px', borderRadius: 999, background: ts.bg, color: ts.color, fontSize: 10, fontWeight: 800 }}>{ts.label}</span>
                      </span>
                    </button>
                  )
                })}
              </div>
            )}
          </Panel>
        </div>
      </div>

      {/* Reject — the API requires a reason, so the UI collects one. */}
      <Modal open={rejectOpen} onClose={() => !busy && setRejectOpen(false)} style={{ maxWidth: 460, width: '92vw' }}>
        <div style={{ padding: '18px 22px', borderBottom: '1px solid var(--border)' }}>
          <h2 style={{ margin: 0, fontSize: 16, fontWeight: 900, color: 'var(--text-h)' }}>Reject this order</h2>
          <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>A rejection without a reason is not reviewable later.</p>
        </div>
        <div style={{ padding: 22 }}>
          <textarea value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Why is this order being rejected?"
            style={{ width: '100%', minHeight: 92, padding: '10px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 9, color: 'var(--text-h)', fontSize: 13, resize: 'vertical', fontFamily: 'inherit', outline: 'none' }} />
        </div>
        <div style={{ padding: '14px 22px', borderTop: '1px solid var(--border)', display: 'flex', justifyContent: 'flex-end', gap: 9 }}>
          <button onClick={() => setRejectOpen(false)} disabled={busy} style={btn('#94a3b8')}>Cancel</button>
          <button onClick={() => reason.trim() ? move('rejected', reason.trim()) : toast.error('Give a reason for the rejection.')} disabled={busy} style={btn('#ef4444', true)}>
            {busy && <Loader2 size={13} className="animate-spin" />} Reject order
          </button>
        </div>
      </Modal>

      {/* Create trip */}
      <Modal open={tripOpen} onClose={() => !busy && setTripOpen(false)} style={{ maxWidth: 470, width: '92vw' }}>
        <div style={{ padding: '18px 22px', borderBottom: '1px solid var(--border)' }}>
          <h2 style={{ margin: 0, fontSize: 16, fontWeight: 900, color: 'var(--text-h)' }}>Create trip</h2>
          <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>
            From order {order.order_number}. The trip starts as a draft; vehicle and driver are allocated later.
          </p>
        </div>
        <div style={{ padding: 22, display: 'grid', gap: 12 }}>
          <div>
            <label className="label" style={{ display: 'block', marginBottom: 4 }}>Approved freight</label>
            <input type="number" min="0" step="0.01" value={tripForm.approved_freight}
              onChange={(e) => setTripForm({ ...tripForm, approved_freight: e.target.value })}
              placeholder="e.g. 86000" style={modalInput} />
          </div>
          <div>
            <label className="label" style={{ display: 'block', marginBottom: 4 }}>Currency</label>
            <input value={tripForm.currency} onChange={(e) => setTripForm({ ...tripForm, currency: e.target.value.toUpperCase().slice(0, 3) })} style={modalInput} />
          </div>
          <div>
            <label className="label" style={{ display: 'block', marginBottom: 4 }}>Route</label>
            <input value={tripForm.route} onChange={(e) => setTripForm({ ...tripForm, route: e.target.value })} placeholder="Defaults to the order's route" style={modalInput} />
          </div>
        </div>
        <div style={{ padding: '14px 22px', borderTop: '1px solid var(--border)', display: 'flex', justifyContent: 'flex-end', gap: 9 }}>
          <button onClick={() => setTripOpen(false)} disabled={busy} style={btn('#94a3b8')}>Cancel</button>
          <button onClick={createTrip} disabled={busy} style={btn('#7C3AED', true)}>
            {busy && <Loader2 size={13} className="animate-spin" />} Create trip
          </button>
        </div>
      </Modal>
    </Wrap>
  )
}

/* ── shared bits ──────────────────────────────────────────────────────── */

const labelStyle = { fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '.08em', margin: 0 }
const modalInput = { width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 9, color: 'var(--text-h)', fontSize: 13, outline: 'none', boxSizing: 'border-box' }
const backBtn = { width: 34, height: 34, borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)', display: 'grid', placeItems: 'center', cursor: 'pointer', flexShrink: 0 }

const btn = (color, solid = false) => ({
  padding: '8px 14px', borderRadius: 9, fontSize: 12.5, fontWeight: 800, cursor: 'pointer',
  background: solid ? color : 'var(--bg-input)',
  border: `1px solid ${solid ? color : 'var(--border)'}`,
  color: solid ? '#fff' : color,
  display: 'inline-flex', alignItems: 'center', gap: 6,
})

export function Wrap({ children }) {
  return <div className="p-5 md:p-7">{children}</div>
}

/**
 * `step` and `subtitle` are optional and used by the trip page, where the panels
 * are stages of one process rather than independent boxes. Numbering them ties
 * each panel to the tracker at the top of that page; the subtitle says in one
 * plain line what the stage is FOR, so the heading does not have to carry it.
 */
export function Panel({ icon: Icon, title, step, subtitle, children }) {
  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        {step != null && (
          <span style={{
            width: 20, height: 20, borderRadius: 999, background: 'rgba(124,58,237,0.16)',
            color: '#a78bfa', fontSize: 11, fontWeight: 900, display: 'grid', placeItems: 'center', flexShrink: 0,
          }}>{step}</span>
        )}
        <Icon size={15} style={{ color: '#7C3AED' }} />
        <h3 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', margin: 0, textTransform: 'uppercase', letterSpacing: '.03em' }}>{title}</h3>
      </div>
      {subtitle && (
        <p style={{ margin: '6px 0 0', fontSize: 12, color: 'var(--text-muted)', lineHeight: 1.5 }}>{subtitle}</p>
      )}
      {children}
    </div>
  )
}

export function KV({ label, value }) {
  return (
    <div>
      <p className="label-caps" style={labelStyle}>{label}</p>
      <p style={{ fontSize: 13, color: 'var(--text-h)', margin: '3px 0 0', fontWeight: 600 }}>{value || '—'}</p>
    </div>
  )
}

function LocationBlock({ label, loc }) {
  return (
    <div>
      <p className="label-caps" style={labelStyle}>{label}</p>
      <p style={{ fontSize: 13, color: 'var(--text-h)', margin: '4px 0 0', fontWeight: 600, lineHeight: 1.5 }}>{loc?.address || '—'}</p>
      <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '2px 0 0' }}>
        {[loc?.city, loc?.state, loc?.pincode].filter(Boolean).join(', ') || '—'}
      </p>
      {loc?.contact && <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '2px 0 0' }}>Contact: {loc.contact}</p>}
    </div>
  )
}

/** Renders the immutable trail written by TransportAuditLogger. */
export function AuditList({ entries }) {
  if (!entries?.length) {
    return <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '12px 0 0' }}>No activity recorded yet.</p>
  }

  // Every event this module writes, in words. An unmapped key used to fall
  // through to its raw name — so the activity feed read
  // "transport.pretrip.generated" to whoever opened it, which is a developer's
  // vocabulary on a dispatcher's screen.
  const pretty = (a) => ({
    'transport.order.created': 'Order created',
    'transport.order.updated': 'Order updated',
    'transport.order.status_changed': 'Status changed',
    'transport.order.trip_created': 'Trip created from this order',
    'transport.trip.created': 'Trip created',
    'transport.trip.updated': 'Trip updated',
    'transport.trip.status_changed': 'Status changed',
    'transport.trip.assignment_created': 'Vehicle and driver assigned',
    'transport.trip.assignment_released': 'Vehicle and driver released',
    'transport.pretrip.generated': 'Pre-trip checklist prepared',
    'transport.pretrip.check_completed': 'Pre-trip check confirmed',
    'transport.pretrip.checks_removed': 'Pre-trip checks no longer required',
    'transport.pretrip.confirmations_revoked': 'Earlier confirmations no longer valid',
    'transport.pretrip.invalidated': 'Pre-trip checks reset',
    'transport.pretrip.refused': 'Pre-trip checks could not be passed',
    'transport.dispatch.amended': 'Dispatch details changed',
  }[a] || a)

  // Statuses are stored as codes; the feed should read them the way the rest of
  // the page does. Falls back to the raw value so a new state is never hidden.
  const statusLabel = (v) => (v ? (TRIP_STATUS_LABEL[v] || ORDER_STATUS_LABEL[v] || v) : v)

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginTop: 12 }}>
      {entries.map((e) => {
        const from = e.old_values?.status
        const to = e.new_values?.status
        return (
          <div key={e.id} style={{ display: 'flex', gap: 10, paddingBottom: 10, borderBottom: '1px solid var(--border)' }}>
            <div style={{ width: 6, height: 6, borderRadius: 999, background: '#7C3AED', marginTop: 6, flexShrink: 0 }} />
            <div style={{ minWidth: 0, flex: 1 }}>
              <p style={{ margin: 0, fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>
                {pretty(e.action)}
                {from && to && (
                  <span style={{ fontWeight: 500, color: 'var(--text-muted)' }}> — {statusLabel(from)} → {statusLabel(to)}</span>
                )}
              </p>
              <p style={{ margin: '2px 0 0', fontSize: 11.5, color: 'var(--text-muted)' }}>
                {e.actor_name || 'System'} · {fmtDateTime(e.occurred_at)}
              </p>
              {e.context?.reason && (
                <p style={{ margin: '3px 0 0', fontSize: 12, color: 'var(--text-p)', fontStyle: 'italic' }}>“{e.context.reason}”</p>
              )}
            </div>
          </div>
        )
      })}
    </div>
  )
}
