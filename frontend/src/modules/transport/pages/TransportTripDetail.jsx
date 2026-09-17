import { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, Truck, Building2, Package, History, Route as RouteIcon,
  AlertTriangle, Loader2, Gauge, Pencil, ClipboardCheck, Send, Boxes, CheckCircle2, Undo2,
} from 'lucide-react'
import { transportTripApi, transportCapabilityApi } from '@/services/transportApi'
import { useToast } from '@/components/ui/Toast'
import Modal from '@/components/ui/Modal'
import { Wrap, Panel, KV, AuditList } from './TransportOrderDetail'
import AllocationPanel from '../components/AllocationPanel'
import PretripPanel from '../components/PretripPanel'
import DispatchPanel from '../components/DispatchPanel'
import TripProgress from '../components/TripProgress'
import { tripStatusCfg, orderStatusCfg, fmtMoney, fmtDate } from '../constants'

/**
 * Trip detail (SNG-TRN-007).
 *
 * ── THE PAGE IS A SEQUENCE, AND IT IS LAID OUT AS ONE ────────────────────
 * The panels are not four independent boxes; they are the stages of one job,
 * and they only make sense in order. So the left column runs
 *
 *     tracker  →  1 Vehicle & driver  →  2 Pre-trip checks  →  3 Dispatch
 *
 * with the tracker at the top naming the same steps. Someone being walked
 * through the page for the first time can follow it top to bottom and never
 * needs the 16-state machine explained to them.
 *
 * Reference material — who the customer is, which order this came from, the
 * commercial figures — sits in the right column, and the activity trail at the
 * foot, out of the path of the work.
 *
 * ── THE STATUS IS SHOWN ONCE ─────────────────────────────────────────────
 * It used to appear five times: the header chip, a "Status" row in the Trip
 * panel, and a chip inside each of the three stage panels. Five copies of one
 * fact is not reassurance, it is noise — and read at different moments they
 * could even disagree. The header chip and the tracker carry it now; the stage
 * panels report only their own state.
 *
 * Vehicle and driver are shown as "Not assigned yet" rather than hidden: the
 * concept exists and saying so is more honest than pretending it does not.
 */
export default function TransportTripDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const toast = useToast()

  const [trip, setTrip] = useState(null)
  const [audit, setAudit] = useState([])
  const [assignment, setAssignment] = useState(null)
  // Asked, never assumed — no button appears that the API would refuse.
  const [grants, setGrants] = useState({})
  const [loading, setLoading] = useState(true)
  const [notFound, setNotFound] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const [editOpen, setEditOpen] = useState(false)
  const [approveOpen, setApproveOpen] = useState(false)
  const [rejectOpen, setRejectOpen] = useState(false)
  const [rejectReason, setRejectReason] = useState('')
  const [form, setForm] = useState({ approved_freight: '', currency: 'INR', route: '' })

  const load = useCallback(async () => {
    setLoading(true); setError(null)
    try {
      const d = await transportTripApi.get(id)
      setTrip(d?.trip ?? null)
      setAudit(Array.isArray(d?.audit) ? d.audit : [])
      setAssignment(d?.assignment ?? null)
    } catch (e) {
      if (e?.status === 404) setNotFound(true)
      else setError(e?.message || 'Could not load this trip.')
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => { load() }, [load])
  useEffect(() => { transportCapabilityApi.get().then((c) => setGrants(c?.grants || {})).catch(() => {}) }, [])

  const submitViability = async () => {
    setBusy(true)
    try {
      await transportTripApi.submitForViability(id)
      toast.success('Trip submitted for viability.')
      load()
    } catch (e) {
      toast.error(e?.message || 'That change was refused.')
    } finally {
      setBusy(false)
    }
  }

  const approve = async () => {
    setBusy(true)
    try {
      await transportTripApi.approve(id)
      toast.success('Trip approved. It can now be given a vehicle and driver.')
      setApproveOpen(false)
      load()
    } catch (e) {
      toast.error(e?.message || 'That trip could not be approved.')
    } finally {
      setBusy(false)
    }
  }

  const reject = async () => {
    setBusy(true)
    try {
      await transportTripApi.reject(id, rejectReason)
      toast.success('Trip sent back for correction.')
      setRejectOpen(false); setRejectReason('')
      load()
    } catch (e) {
      toast.error(e?.message || 'That trip could not be sent back.')
    } finally {
      setBusy(false)
    }
  }

  const saveEdit = async () => {
    setBusy(true)
    try {
      await transportTripApi.update(id, {
        approved_freight: form.approved_freight === '' ? null : Number(form.approved_freight),
        currency: form.currency || 'INR',
        route: form.route || null,
      })
      toast.success('Trip updated.')
      setEditOpen(false)
      load()
    } catch (e) {
      toast.error(e?.message || 'The trip could not be updated.')
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

  if (notFound || !trip) {
    return (
      <Wrap>
        <div style={{ textAlign: 'center', padding: 60 }}>
          <AlertTriangle size={30} style={{ color: 'var(--text-muted)', marginBottom: 10 }} />
          <p style={{ color: 'var(--text-h)', fontSize: 16, fontWeight: 800, margin: 0 }}>Trip not found</p>
          <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '6px 0 14px' }}>It may have been removed, or it belongs to another workspace.</p>
          <button onClick={() => navigate('/app/transport/trips')} style={btn('#7C3AED', true)}>Back to trips</button>
        </div>
      </Wrap>
    )
  }

  const st = tripStatusCfg(trip.status)
  const isDraft = trip.status === 'draft'
  // STT-002. The button appears only in the one state it applies to, and only
  // for a role PERM-003 grants — which deliberately excludes the Dispatcher.
  const awaitingApproval = trip.status === 'viability_pending'
  const canApprove = !!grants['transport.trip.approve']

  return (
    <Wrap>
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12, marginBottom: 18, flexWrap: 'wrap' }}>
        <button onClick={() => navigate('/app/transport/trips')} style={backBtn}><ArrowLeft size={16} /></button>
        <div style={{ flex: 1, minWidth: 0 }}>
          <p style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>
            {trip.trip_number}{trip.order?.order_number ? ` · from ${trip.order.order_number}` : ''}
          </p>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
            <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>
              {trip.customer?.company || 'Trip'}
            </h1>
            <span style={{ padding: '4px 11px', borderRadius: 999, background: st.bg, color: st.color, fontSize: 11.5, fontWeight: 800 }}>{st.label}</span>
          </div>
          {trip.route && (
            <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginTop: 5, fontSize: 12.5, color: 'var(--text-muted)' }}>
              <RouteIcon size={13} /> {trip.route}
            </div>
          )}
        </div>
        <div style={{ display: 'flex', gap: 8, flexShrink: 0, flexWrap: 'wrap', justifyContent: 'flex-end' }}>
          {isDraft && (
            <button disabled={busy}
              onClick={() => { setForm({ approved_freight: trip.approved_freight ?? '', currency: trip.currency || 'INR', route: trip.route || '' }); setEditOpen(true) }}
              style={btn('#94a3b8')}>
              <Pencil size={14} /> Edit
            </button>
          )}
          {/* STT-001 — the only transition this ticket owns. */}
          {isDraft && (
            <button disabled={busy} onClick={submitViability} style={btn('#f59e0b', true)}>
              {busy ? <Loader2 size={13} className="animate-spin" /> : <Gauge size={14} />} Submit for viability
            </button>
          )}
          {/* STT-002. Until this shipped, a trip reaching viability_pending was
              stuck there forever and nothing downstream could be reached. */}
          {/* STT-003. Beside Approve, because they are the two answers to one
              question and a reviewer who can only say yes is not reviewing. */}
          {awaitingApproval && canApprove && (
            <button disabled={busy} onClick={() => { setRejectReason(''); setRejectOpen(true) }} style={btn('#94a3b8')}>
              <Undo2 size={14} /> Send back
            </button>
          )}
          {awaitingApproval && canApprove && (
            <button disabled={busy} onClick={() => setApproveOpen(true)} style={btn('#10b981', true)}>
              <CheckCircle2 size={14} /> Approve trip
            </button>
          )}
          {awaitingApproval && !canApprove && (
            <span style={{ fontSize: 11.5, color: 'var(--text-muted)', alignSelf: 'center', maxWidth: 260, textAlign: 'right' }}>
              This trip is waiting for approval. Your role cannot approve trips.
            </span>
          )}
        </div>
      </div>

      {/* STT-003's "Return to edit": the objection is on the page the person
          has to act on, not only in the history. */}
      {trip.rejection_reason && (
        <div style={{
          display: 'flex', gap: 10, alignItems: 'flex-start', padding: '12px 14px', borderRadius: 10,
          background: 'rgba(245,158,11,0.10)', border: '1px solid rgba(245,158,11,0.32)', marginBottom: 14,
        }}>
          <Undo2 size={15} style={{ color: '#f59e0b', flexShrink: 0, marginTop: 1 }} />
          <div>
            <p style={{ margin: 0, fontSize: 12.5, fontWeight: 800, color: '#f59e0b' }}>
              Sent back for correction
            </p>
            <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-p)' }}>{trip.rejection_reason}</p>
            <p style={{ margin: '4px 0 0', fontSize: 11.5, color: 'var(--text-muted)' }}>
              Fix it, then submit for viability again.
            </p>
          </div>
        </div>
      )}

      {error && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '11px 14px', borderRadius: 10, background: 'rgba(239,68,68,0.10)', border: '1px solid rgba(239,68,68,0.30)', color: '#f87171', fontSize: 13, marginBottom: 14 }}>
          <AlertTriangle size={15} /> {error}
        </div>
      )}

      {/* Where this trip stands, before any detail. */}
      <TripProgress status={trip.status} />

      <div style={{ display: 'grid', gridTemplateColumns: '1.6fr 1fr', gap: 16, alignItems: 'start' }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          {/* Step 1. SNG-TRN-009. Assigning only becomes possible once the trip
              is approved (STT-004's from-state), so before that the panel says
              what has to happen first rather than offering buttons the API
              would refuse. Titled "Vehicle & driver", not "Allocation" —
              allocation is our word for it, not the reader's. */}
          <Panel icon={Truck} step={1} title="Vehicle & driver"
            subtitle="Choose which vehicle and which driver will run this trip. Only ones that are free and have valid papers are offered.">
            {['draft', 'viability_pending'].includes(trip.status) ? (
              <>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginTop: 12 }}>
                  <KV label="Vehicle" value="Not assigned yet" />
                  <KV label="Driver" value="Not assigned yet" />
                </div>
                <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '12px 0 0' }}>
                  You can assign a vehicle and driver once the trip has been approved.
                </p>
              </>
            ) : (
              <AllocationPanel
                trip={trip}
                assignment={assignment}
                canAssign={!!grants['transport.trip.assign']}
                onChanged={load}
              />
            )}
          </Panel>

          {/* Step 2. SNG-TRN-010. The panel appears once the trip is approved,
              which is the first state a checklist can be built from — RTM
              OPS-004's acceptance is "missing requirements identified", and a
              checklist that could only be built after crewing could never
              identify a missing driver. */}
          <Panel icon={ClipboardCheck} step={2} title="Pre-trip checks"
            subtitle="Confirm the trip is fit to leave. The system checks the order, the driver's papers and the vehicle's papers, and you confirm each one.">
            {['draft', 'viability_pending'].includes(trip.status) ? (
              <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '12px 0 0' }}>
                These checks begin once the trip has been approved.
              </p>
            ) : (
              <PretripPanel
                trip={trip}
                canPerform={!!grants['transport.pretrip.perform']}
                onChanged={load}
              />
            )}
          </Panel>

          {/* Step 3. No ticket owns dispatch (D-18); the owner authorised the
              scope on 2026-09-10. The panel appears from `allocated` onward —
              early enough that a dispatcher can see WHY a trip cannot leave,
              which is UX §35's whole point, and not so early that it offers a
              control nothing could satisfy. */}
          <Panel icon={Send} step={3} title="Dispatch"
            subtitle="Release the trip. Record when it leaves, when it should arrive, and what the driver needs to know.">
            {['draft', 'viability_pending', 'approved'].includes(trip.status) ? (
              <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '12px 0 0' }}>
                You can dispatch this trip once a vehicle and driver are assigned and the pre-trip checks have passed.
              </p>
            ) : (
              <DispatchPanel
                trip={trip}
                canDispatch={!!grants['transport.trip.dispatch']}
                onChanged={load}
              />
            )}
          </Panel>

        </div>

        {/* Right column: reference. Nothing here is a step, so nothing here
            competes with the sequence on the left. */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          <Panel icon={Truck} title="Trip details">
            <div style={{ display: 'grid', gap: 12, marginTop: 12 }}>
              <KV label="Customer" value={trip.customer?.company} />
              <KV label="Trip number" value={trip.trip_number} />
              <KV label="Route" value={trip.route} />
              <KV label="Agreed price" value={fmtMoney(trip.approved_freight, trip.currency)} />
            </div>
          </Panel>

          {/* What is actually being moved. Without this the page showed an
              order and a vehicle with nothing in between, and the chain the
              walkthrough is meant to demonstrate was invisible. */}
          <Panel icon={Boxes} title="What is being moved">
            {trip.consignment ? (
              <div style={{ marginTop: 12 }}>
                {/* Consignments have NO detail route by design — the detail is a
                    Drawer on the list. So this deep-links into the list and asks
                    it to open that drawer. Adding a consignments/:id route just
                    for this button would give consignments two different detail
                    experiences depending on how you arrived. */}
                <button onClick={() => navigate(`/app/transport/consignments?open=${trip.consignment.id}`)}
                  style={{ textAlign: 'left', width: '100%', padding: '11px 12px', borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)', cursor: 'pointer' }}>
                  <span style={{ fontSize: 12.5, fontWeight: 800, color: '#a78bfa' }}>
                    {trip.consignment.consignment_number}
                  </span>
                  <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 5 }}>
                    {trip.consignment.cargo_description || 'Not described yet'}
                  </div>
                </button>
                <div style={{ display: 'grid', gap: 12, marginTop: 12 }}>
                  <KV label="Customer reference" value={trip.consignment.customer_reference} />
                  <KV label="Packages" value={trip.consignment.package_count} />
                  <KV label="Weight"
                    value={trip.consignment.gross_weight_kg
                      ? `${Number(trip.consignment.gross_weight_kg).toLocaleString('en-IN')} kg`
                      : null} />
                </div>
              </div>
            ) : (
              <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '12px 0 0' }}>
                No consignment is linked to this trip yet.
              </p>
            )}
          </Panel>

          <Panel icon={Package} title="Source order">
            {trip.order ? (
              <div style={{ marginTop: 12 }}>
                <button onClick={() => navigate(`/app/transport/orders/${trip.order.id}`)}
                  style={{ textAlign: 'left', width: '100%', padding: '11px 12px', borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)', cursor: 'pointer' }}>
                  <span style={{ fontSize: 12.5, fontWeight: 800, color: '#a78bfa' }}>{trip.order.order_number}</span>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 5 }}>
                    <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>{trip.order.service_type}</span>
                    {trip.order.order_status && (() => {
                      const os = orderStatusCfg(trip.order.order_status)
                      return <span style={{ padding: '2px 8px', borderRadius: 999, background: os.bg, color: os.color, fontSize: 10, fontWeight: 800 }}>{os.label}</span>
                    })()}
                  </div>
                </button>
                <div style={{ display: 'grid', gap: 12, marginTop: 12 }}>
                  <KV label="Priority" value={trip.order.priority} />
                  <KV label="Required by" value={fmtDate(trip.order.required_at)} />
                </div>
              </div>
            ) : (
              <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '12px 0 0' }}>—</p>
            )}
          </Panel>
        </div>
      </div>

      {/* The trail sits at the foot, full width: it is what HAPPENED, and it
          should not sit between two things a person still has to DO. */}
      <div style={{ marginTop: 16 }}>
        <Panel icon={History} title="History"
          subtitle="Everything that has happened to this trip, newest first — who did it and when.">
          <AuditList entries={audit} />
        </Panel>
      </div>

      {/* STT-003. The reason is the precondition, so the button stays disabled
          until there is one — the person correcting the trip has to know what
          to change. */}
      <Modal open={rejectOpen} onClose={() => !busy && setRejectOpen(false)} style={{ maxWidth: 460, width: '92vw' }}>
        <div style={{ padding: '18px 22px', borderBottom: '1px solid var(--border)' }}>
          <h2 style={{ margin: 0, fontSize: 16, fontWeight: 900, color: 'var(--text-h)' }}>Send this trip back?</h2>
          <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>
            It returns to draft so it can be corrected and submitted again.
          </p>
        </div>
        <div style={{ padding: '18px 22px', display: 'grid', gap: 10 }}>
          <label style={{ fontSize: 11, fontWeight: 800, letterSpacing: '.05em', color: 'var(--text-muted)' }}>
            WHAT NEEDS CHANGING?
          </label>
          <textarea
            value={rejectReason}
            onChange={(e) => setRejectReason(e.target.value)}
            rows={4}
            placeholder="e.g. The agreed price is below the rate for this route."
            style={{
              width: '100%', padding: '10px 12px', borderRadius: 10, fontSize: 13, resize: 'vertical',
              border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-p)',
            }}
          />
          <p style={{ margin: 0, fontSize: 11.5, color: 'var(--text-muted)' }}>
            Whoever picks this trip up will see this, so say what to change rather than only that it is wrong.
          </p>
        </div>
        <div style={{ padding: '14px 22px', borderTop: '1px solid var(--border)', display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
          <button disabled={busy} onClick={() => setRejectOpen(false)} style={btn('#94a3b8')}>Cancel</button>
          <button disabled={busy || rejectReason.trim().length < 3} onClick={reject} style={btn('#f59e0b', true)}>
            {busy ? <Loader2 size={13} className="animate-spin" /> : <Undo2 size={14} />} Send back
          </button>
        </div>
      </Modal>

      {/* STT-002. The dialog states what the system did NOT check, because a
          user approving a trip should know. D-59. */}
      <Modal open={approveOpen} onClose={() => !busy && setApproveOpen(false)} style={{ maxWidth: 460, width: '92vw' }}>
        <div style={{ padding: '18px 22px', borderBottom: '1px solid var(--border)' }}>
          <h2 style={{ margin: 0, fontSize: 16, fontWeight: 900, color: 'var(--text-h)' }}>Approve this trip?</h2>
          <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>
            {trip.trip_number} — {trip.customer?.company || 'this customer'}
          </p>
        </div>
        <div style={{ padding: '18px 22px', display: 'grid', gap: 14 }}>
          <p style={{ margin: 0, fontSize: 13, color: 'var(--text-p)' }}>
            Approving records that you accepted this trip. It can then be given a vehicle and a driver.
          </p>

          {/* The honest part. Not buried, not a tooltip. */}
          <div style={{
            padding: '11px 13px', borderRadius: 10,
            background: 'rgba(245,158,11,0.10)', border: '1px solid rgba(245,158,11,0.32)',
          }}>
            <p style={{ margin: 0, fontSize: 12.5, fontWeight: 800, color: '#f59e0b' }}>
              The profit check is not running yet
            </p>
            <p style={{ margin: '4px 0 0', fontSize: 12, color: 'var(--text-p)', lineHeight: 1.5 }}>
              The system has checked that this trip is at the right stage and that you are allowed to
              approve it. It has <strong>not</strong> checked whether the price covers the cost — that
              calculation has not been built. Approve only if you are satisfied with the commercials
              yourself.
            </p>
          </div>

          <div style={{ display: 'grid', gap: 10 }}>
            <KV label="Agreed price" value={fmtMoney(trip.approved_freight, trip.currency)} />
            <KV label="Route" value={trip.route} />
          </div>
        </div>
        <div style={{ padding: '14px 22px', borderTop: '1px solid var(--border)', display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
          <button disabled={busy} onClick={() => setApproveOpen(false)} style={btn('#94a3b8')}>Cancel</button>
          <button disabled={busy} onClick={approve} style={btn('#10b981', true)}>
            {busy ? <Loader2 size={13} className="animate-spin" /> : <CheckCircle2 size={14} />} Approve trip
          </button>
        </div>
      </Modal>

      <Modal open={editOpen} onClose={() => !busy && setEditOpen(false)} style={{ maxWidth: 460, width: '92vw' }}>
        <div style={{ padding: '18px 22px', borderBottom: '1px solid var(--border)' }}>
          <h2 style={{ margin: 0, fontSize: 16, fontWeight: 900, color: 'var(--text-h)' }}>Edit trip</h2>
          <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>Only a draft trip can be edited.</p>
        </div>
        <div style={{ padding: 22, display: 'grid', gap: 12 }}>
          <div>
            <label className="label" style={{ display: 'block', marginBottom: 4 }}>Approved freight</label>
            <input type="number" min="0" step="0.01" value={form.approved_freight}
              onChange={(e) => setForm({ ...form, approved_freight: e.target.value })} style={modalInput} />
          </div>
          <div>
            <label className="label" style={{ display: 'block', marginBottom: 4 }}>Currency</label>
            <input value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value.toUpperCase().slice(0, 3) })} style={modalInput} />
          </div>
          <div>
            <label className="label" style={{ display: 'block', marginBottom: 4 }}>Route</label>
            <input value={form.route} onChange={(e) => setForm({ ...form, route: e.target.value })} style={modalInput} />
          </div>
        </div>
        <div style={{ padding: '14px 22px', borderTop: '1px solid var(--border)', display: 'flex', justifyContent: 'flex-end', gap: 9 }}>
          <button onClick={() => setEditOpen(false)} disabled={busy} style={btn('#94a3b8')}>Cancel</button>
          <button onClick={saveEdit} disabled={busy} style={btn('#7C3AED', true)}>
            {busy && <Loader2 size={13} className="animate-spin" />} Save
          </button>
        </div>
      </Modal>
    </Wrap>
  )
}

const modalInput = { width: '100%', padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 9, color: 'var(--text-h)', fontSize: 13, outline: 'none', boxSizing: 'border-box' }
const backBtn = { width: 34, height: 34, borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)', display: 'grid', placeItems: 'center', cursor: 'pointer', flexShrink: 0 }

const btn = (color, solid = false) => ({
  padding: '8px 14px', borderRadius: 9, fontSize: 12.5, fontWeight: 800, cursor: 'pointer',
  background: solid ? color : 'var(--bg-input)',
  border: `1px solid ${solid ? color : 'var(--border)'}`,
  color: solid ? '#fff' : color,
  display: 'inline-flex', alignItems: 'center', gap: 6,
})
