import { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, Truck, Building2, Package, History, Route as RouteIcon,
  AlertTriangle, Loader2, Gauge, Pencil, ClipboardCheck, Send, Boxes, CheckCircle2, Undo2,
  Wallet, IndianRupee, FileCheck2, Receipt, Banknote, MapPin, Lock, ChevronDown, ChevronRight,
  AlertTriangle as AlertIcon,
} from 'lucide-react'
import { transportTripApi, transportCapabilityApi, transportPretripApi } from '@/services/transportApi'
import { useToast } from '@/components/ui/Toast'
import Modal from '@/components/ui/Modal'
import { Wrap, Panel, KV, AuditList } from './TransportOrderDetail'
import AllocationPanel from '../components/AllocationPanel'
import PretripPanel from '../components/PretripPanel'
import DispatchPanel from '../components/DispatchPanel'
import TripProgress from '../components/TripProgress'
// The rebuild of 2026-09-18: one next action at the top, every stage collapsed
// except the one you are on. See the page docblock.
import TripNextAction from '../components/TripNextAction'
import TripStep from '../components/TripStep'
// Steps 4 and 9 — Block 3. Transit and delivery are P1's (STT-006/007);
// closure is P1's too (STT-012) and is built but unreachable — see D-106.
import JourneyPanel from '../components/JourneyPanel'
import ClosurePanel from '../components/ClosurePanel'
// SNG-TRN-013, unblocked 2026-09-18 — step 9 of MS-001 §14.
import ExceptionsPanel from '../components/ExceptionsPanel'
// Steps 4-6 — P3's tickets. Self-contained panels, same shape as the three
// above, so the seam into this page stays three imports and three blocks.
import AdvancesPanel from '../components/AdvancesPanel'
import CostsPanel from '../components/CostsPanel'
import TripDocumentsPanel from '../components/TripDocumentsPanel'
import BillingPanel from '../components/BillingPanel'
import CollectionPanel from '../components/CollectionPanel'
import { tripStatusCfg, orderStatusCfg, fmtMoney, fmtDate, fmtDateTime } from '../constants'

/**
 * Trip detail (SNG-TRN-007).
 *
 * ── REBUILT 2026-09-18, BECAUSE THE OWNER COULD NOT USE IT ───────────────
 * This page rendered fourteen panels, all expanded, all at once, each with a
 * paragraph of explanation underneath. Vehicle & driver, pre-trip, dispatch, on
 * the road, advances, costs, paperwork, billing, getting paid, close, plus four
 * reference panels. Every one of them correct; nothing on the page said which
 * of them mattered right now. The owner opened it and could not work out what
 * to do — and that is a failure of the screen, not of the reader.
 *
 * A trip has twelve reachable states. At any moment the person looking at it
 * needs three things and nothing else, and the page is now those three things
 * in that order:
 *
 *   1. WHAT DO I DO NEXT.  One sentence and one button, at the top, before
 *      anything else. TripNextAction derives it from the trip's own state and
 *      the grants the capability endpoint returned. Where the next step is
 *      blocked it says what is blocking it and who can clear it, reusing the
 *      wording the API would have refused with.
 *
 *   2. WHERE IS THIS TRIP.  The seven-step tracker, one row.
 *
 *   3. THE DETAILS, IF I WANT THEM.  Every stage is a collapsed row. Done
 *      stages show their outcome and date on one line — "Dispatched 18 Sept
 *      06:40" — and open if asked. Stages not yet relevant are collapsed,
 *      greyed and locked, so the shape of the journey is visible without
 *      anyone being asked to act on it. Only the current stage is open.
 *      Trip details, what is being moved, the source order and the history are
 *      reference, not actions, and now sit in the side column instead of being
 *      interleaved with the work.
 *
 * ── THE TEACHING TEXT MOVED INSIDE ──────────────────────────────────────
 * The paragraph under each heading was useful the first time somebody met the
 * page and noise every time after. It now appears only inside the stage that is
 * open, and only as one line. A closed stage says what HAPPENED, not what it
 * is for.
 *
 * ── P3'S PANELS ─────────────────────────────────────────────────────────
 * Advances, costs, paperwork, billing and getting paid are Person 3's
 * components and their internals are untouched. WHERE they sit and WHETHER they
 * are open is this page's layout decision, and they now sit below the
 * operational stages: a dispatcher assigning a truck should not be scrolling
 * past billing to reach the vehicle list.
 *
 * Advances and costs are marked `available` rather than given a step number —
 * they are not stages of a journey, they may happen any time after approval,
 * and they never become "done". A tick or a lock on either would be a lie.
 *
 * ── NOTHING BECAME POSSIBLE THAT WAS NOT POSSIBLE BEFORE ────────────────
 * No rule, gate or refusal changed. Every permission check, every state guard
 * and every server sentence is exactly where it was; this is purely what the
 * screen shows and when.
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

  // Pre-trip readiness, read at page level for ONE reason: the next-action line
  // says "Pre-trip checks not done — 2 of 5", and a headline that cannot count
  // is a headline nobody trusts. The panel below fetches its own detail.
  const [readiness, setReadiness] = useState(null)

  // Which stages are expanded. Owned here rather than inside each step, so the
  // next-action button can open the one it points at.
  const [openKeys, setOpenKeys] = useState(() => new Set())

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

  // Only where it can say something: before a trip is approved there is no
  // checklist to count, and asking for one would be a request with no answer.
  useEffect(() => {
    if (!trip || ['draft', 'viability_pending'].includes(trip.status)) return
    transportPretripApi.readiness(id).then(setReadiness).catch(() => {})
  }, [id, trip?.status])

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

  // The current stage, derived once from the trip's own status. Nothing here
  // decides what is POSSIBLE — every gate and refusal is unchanged and still
  // lives in the panels and the API. This only decides what is OPEN.
  const ORDER = [
    'draft', 'viability_pending', 'approved', 'allocated', 'pretrip_ok',
    'dispatched', 'in_transit', 'delivered', 'pod_verified', 'billable',
    'billed', 'collection_pending', 'closed',
  ]
  const at = Math.max(0, ORDER.indexOf(trip?.status))

  /** done once the trip is past it, current while it is on it, later before. */
  const STAGES = {
    crew:      { done: 3, current: [2] },
    checks:    { done: 4, current: [3] },
    dispatch:  { done: 5, current: [4] },
    road:      { done: 7, current: [5, 6] },
    paperwork: { done: 8, current: [7] },
    billing:   { done: 10, current: [8, 9] },
    paid:      { done: 12, current: [10, 11] },
    close:     { done: 12, current: [11] },
    // Not stages of the journey — see the comment beside them in the markup.
    advances:  { available: 2 },
    costs:     { available: 2 },
    // Neither is an exception a STAGE. Something can go wrong at any point
    // after a trip exists, and a trip that never has one is not incomplete.
    exceptions: { available: 0 },
  }

  const stageState = (key) => {
    const s = STAGES[key]
    if (s.available !== undefined) return at >= s.available ? 'available' : 'later'
    if (s.current.includes(at)) return 'current'
    if (at >= s.done) return 'done'

    return 'later'
  }

  const goToStep = (key) => {
    setOpenKeys((prev) => new Set(prev).add(key))
    // After the row has rendered open, bring it into view.
    requestAnimationFrame(() => {
      document.getElementById(`trip-step-${key}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    })
  }

  const stepProps = (key) => {
    const state = stageState(key)

    return {
      id: `trip-step-${key}`,
      state,
      // The current stage is open on arrival. Everything else stays shut until
      // somebody asks for it — which is the whole point of the rebuild.
      open: openKeys.has(key) || (state === 'current' && !openKeys.has(`!${key}`)),
      onToggle: () => setOpenKeys((prev) => {
        const next = new Set(prev)
        const isOpen = next.has(key) || (state === 'current' && !next.has(`!${key}`))
        if (isOpen) { next.delete(key); next.add(`!${key}`) } else { next.delete(`!${key}`); next.add(key) }

        return next
      }),
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
          {/* Submit, Approve and Send back USED TO LIVE HERE, three buttons in
              a row above fourteen open panels. They are the next action for
              exactly one state each, so they now appear in the next-action card
              below — one sentence, one button, in the place the eye lands
              first. Edit stays: it is not the next step, it is a thing you may
              also want while the trip is still a draft. */}
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

      {/* ── 1. THE ONE NEXT THING, before anything else on the page ──────
          A trip has twelve reachable states and this page used to show every
          stage of all of them at once. Nothing said which one mattered now. */}
      <TripNextAction
        trip={trip}
        grants={grants}
        readiness={readiness}
        busy={busy}
        onSubmitViability={submitViability}
        onApprove={() => setApproveOpen(true)}
        onReject={() => { setRejectReason(''); setRejectOpen(true) }}
        onGoToStep={goToStep}
      />

      {/* ── 2. WHERE IT IS, in one row ─────────────────────────────────── */}
      <TripProgress status={trip.status} />

      <div style={{ display: 'grid', gridTemplateColumns: '1.6fr 1fr', gap: 16, alignItems: 'start' }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>

          <GroupLabel>Getting it on the road</GroupLabel>

          {/* SNG-TRN-009. Assigning only becomes possible once the trip is
              approved (STT-004's from-state). */}
          <TripStep {...stepProps('crew')} icon={Truck} n={1} title="Vehicle & driver"
            outcome={[assignment?.vehicle?.registration_number, assignment?.driver?.name]
              .filter(Boolean).join(' · ') || 'Assigned'}
            hint="Only vehicles and drivers that are free and have valid papers are offered.">
            {['draft', 'viability_pending'].includes(trip.status) ? (
              <p style={muted}>You can assign a vehicle and driver once the trip has been approved.</p>
            ) : (
              <AllocationPanel trip={trip} assignment={assignment}
                canAssign={!!grants['transport.trip.assign']} onChanged={load} />
            )}
          </TripStep>

          {/* SNG-TRN-010. RTM OPS-004 — "missing requirements identified". */}
          <TripStep {...stepProps('checks')} icon={ClipboardCheck} n={2} title="Pre-trip checks"
            outcome={readiness?.total ? `${readiness.completed} of ${readiness.total} confirmed` : 'Passed'}
            hint="The order, the driver's papers and the vehicle's papers — you confirm each one.">
            {['draft', 'viability_pending'].includes(trip.status) ? (
              <p style={muted}>These checks begin once the trip has been approved.</p>
            ) : (
              <PretripPanel trip={trip} canPerform={!!grants['transport.pretrip.perform']} onChanged={load} />
            )}
          </TripStep>

          {/* No ticket owns dispatch (D-18); owner authorised 2026-09-10. */}
          <TripStep {...stepProps('dispatch')} icon={Send} n={3} title="Dispatch"
            outcome={trip.dispatched_at ? `Dispatched ${fmtDateTime(trip.dispatched_at)}` : 'Released'}
            hint="Release the trip, and record when it should leave and arrive.">
            {['draft', 'viability_pending', 'approved'].includes(trip.status) ? (
              <p style={muted}>You can dispatch once a vehicle and driver are assigned and the pre-trip checks have passed.</p>
            ) : (
              <DispatchPanel trip={trip} canDispatch={!!grants['transport.trip.dispatch']} onChanged={load} />
            )}
          </TripStep>

          {/* STT-006 and STT-007 — D-105. Released is not moving. */}
          <TripStep {...stepProps('road')} icon={MapPin} n={4} title="On the road"
            outcome={trip.delivered_at
              ? `Delivered ${fmtDateTime(trip.delivered_at)}`
              : trip.departed_at ? `Left ${fmtDateTime(trip.departed_at)}` : 'Recorded'}
            hint="Recorded by hand — there is no live vehicle tracking yet.">
            <JourneyPanel trip={trip}
              canDepart={!!grants['transport.trip.dispatch']}
              canDeliver={!!grants['transport.trip.deliver']}
              onChanged={load} />
          </TripStep>

          {/* SNG-TRN-013. Blocked since 2026-09-10 on D-29 and D-30, both ruled
              on 2026-09-18 — and D-29 needed no new decision, only the standing
              Step 9 / Step 11 rule applied to it.

              Sits with the operational work rather than below it: an exception
              is the reason a dispatcher is on this page at all, and burying it
              under billing would be the same mistake this rebuild removed.

              `available`, not numbered — something can go wrong at any point,
              and a trip with no exceptions is not a trip missing a step. */}
          <TripStep {...stepProps('exceptions')} icon={AlertIcon} n="!" title="What has gone wrong"
            hint="Anything that needs somebody to own it and put it right. Raising is wider than resolving: whoever is nearest the problem can record it.">
            <ExceptionsPanel
              trip={trip}
              canRaise={!!grants['transport.exception.create']}
              canManage={!!grants['transport.exception.manage']}
              onChanged={load}
            />
          </TripStep>

          <GroupLabel>Paperwork and money</GroupLabel>

          {/* P3's components. WHERE they sit and WHETHER they are open is this
              page's layout decision; their internals are untouched. A
              dispatcher assigning a truck should not scroll past billing. */}
          <TripStep {...stepProps('paperwork')} icon={FileCheck2} n={5} title="Paperwork and proof of delivery"
            outcome="Proof of delivery verified"
            hint="A trip cannot be billed until its POD is verified, unless an exception waives it.">
            {['draft', 'viability_pending', 'approved'].includes(trip.status) ? (
              <p style={muted}>Paperwork can be filed once the trip has been dispatched.</p>
            ) : (
              <TripDocumentsPanel trip={trip}
                canSubmit={!!grants['transport.pod.submit']}
                canVerify={!!grants['transport.pod.verify']} onChanged={load} />
            )}
          </TripStep>

          <TripStep {...stepProps('billing')} icon={Receipt} n={6} title="Billing"
            outcome="Handed to Accounts"
            hint="Transport marks a trip ready to invoice; Accounts raise the invoice.">
            {['draft', 'viability_pending', 'approved'].includes(trip.status) ? (
              <p style={muted}>Billing becomes relevant once the trip has been dispatched.</p>
            ) : (
              <BillingPanel trip={trip} canPrepare={!!grants['transport.billing.prepare']} onChanged={load} />
            )}
          </TripStep>

          <TripStep {...stepProps('paid')} icon={Banknote} n={7} title="Getting paid"
            outcome="Settled"
            hint="Recording a receipt here tracks it; Accounts post the money.">
            {['draft', 'viability_pending', 'approved'].includes(trip.status) ? (
              <p style={muted}>This becomes relevant once the trip has been billed.</p>
            ) : (
              <CollectionPanel trip={trip} canRecord={!!grants['transport.collection.record']} onChanged={load} />
            )}
          </TripStep>

          {/* Advances and costs are not stages — they may happen any time after
              approval and never become "done". Marked `available` so they carry
              neither a tick nor a lock, both of which would be untrue. */}
          <TripStep {...stepProps('advances')} icon={Wallet} n="₹" title="Advances"
            hint="Money paid out before the trip earns anything. Requesting is separate from approving.">
            {['draft', 'viability_pending'].includes(trip.status) ? (
              <p style={muted}>Advances can be requested once the trip has been approved.</p>
            ) : (
              <AdvancesPanel trip={trip}
                canRequest={!!grants['transport.advance.request']}
                canApprove={!!grants['transport.advance.approve']} onChanged={load} />
            )}
          </TripStep>

          <TripStep {...stepProps('costs')} icon={IndianRupee} n="₹" title="Trip costs"
            hint="Fuel, tolls and anything else. Subtracted from the freight to give the margin.">
            {['draft', 'viability_pending'].includes(trip.status) ? (
              <p style={muted}>Costs can be recorded once the trip has been approved.</p>
            ) : (
              <CostsPanel trip={trip}
                canRecord={!!grants['transport.cost.record']}
                canRetract={!!grants['transport.cost.retract']} onChanged={load} />
            )}
          </TripStep>

          <GroupLabel>Closing</GroupLabel>

          {/* STT-012. Built and unreachable — D-106. The panel says so itself. */}
          <TripStep {...stepProps('close')} icon={Lock} n={8} title="Close the trip"
            outcome={trip.closed_at ? `Closed ${fmtDateTime(trip.closed_at)}` : 'Closed'}
            hint="What is still outstanding before this trip can be settled for good.">
            {['draft', 'viability_pending', 'approved', 'allocated', 'pretrip_ok', 'dispatched', 'in_transit'].includes(trip.status) ? (
              <p style={muted}>A trip can be closed once it has been delivered, invoiced and paid for.</p>
            ) : (
              <ClosurePanel trip={trip} canClose={!!grants['transport.trip.close']} onChanged={load} />
            )}
          </TripStep>
        </div>

        {/* ── 3. THE FACTS, where facts go ────────────────────────────────
            Trip details, what is being moved, the source order and the history
            are REFERENCE. They were interleaved with the things a dispatcher
            has to do; now they sit beside them and only the summary is open. */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          <Panel icon={Truck} title="Trip details">
            <div style={{ display: 'grid', gap: 12, marginTop: 12 }}>
              <KV label="Customer" value={trip.customer?.company} />
              <KV label="Trip number" value={trip.trip_number} />
              <KV label="Route" value={trip.route} />
              <KV label="Agreed price" value={fmtMoney(trip.approved_freight, trip.currency)} />
            </div>
          </Panel>

          <Fold icon={Boxes} title="What is being moved"
            summary={trip.consignment?.consignment_number || 'No consignment linked'}>
            {trip.consignment ? (
              <div>
                {/* Consignments have no detail ROUTE by design — the detail is a
                    drawer on the list, so this deep-links and asks it to open. */}
                <button onClick={() => navigate(`/app/transport/consignments?open=${trip.consignment.id}`)}
                  style={linkCard}>
                  <span style={{ fontSize: 12.5, fontWeight: 800, color: 'var(--accent)' }}>
                    {trip.consignment.consignment_number}
                  </span>
                  <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 5 }}>
                    {trip.consignment.cargo_description || 'Not described yet'}
                  </div>
                </button>
                <div style={{ display: 'grid', gap: 12, marginTop: 12 }}>
                  <KV label="Customer reference" value={trip.consignment.customer_reference} />
                  <KV label="Packages" value={trip.consignment.package_count} />
                  <KV label="Weight" value={trip.consignment.gross_weight_kg
                    ? `${Number(trip.consignment.gross_weight_kg).toLocaleString('en-IN')} kg` : null} />
                </div>
              </div>
            ) : (
              <p style={muted}>No consignment is linked to this trip yet.</p>
            )}
          </Fold>

          <Fold icon={Package} title="Source order" summary={trip.order?.order_number || 'None'}>
            {trip.order ? (
              <div>
                <button onClick={() => navigate(`/app/transport/orders/${trip.order.id}`)} style={linkCard}>
                  <span style={{ fontSize: 12.5, fontWeight: 800, color: 'var(--accent)' }}>{trip.order.order_number}</span>
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
              <p style={muted}>No source order.</p>
            )}
          </Fold>

          <Fold icon={History} title="History" summary={`${audit.length} event${audit.length === 1 ? '' : 's'}`}>
            <AuditList entries={audit} />
          </Fold>
        </div>
      </div>

      {/*
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
          user approving a trip should know. D-64. */}
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

/** A quiet divider between the three things a trip page is actually about. */
function GroupLabel({ children }) {
  return (
    <p style={{
      margin: '10px 0 2px', fontSize: 10.5, fontWeight: 900, letterSpacing: '.08em',
      color: 'var(--text-muted)', textTransform: 'uppercase',
    }}>
      {children}
    </p>
  )
}

/**
 * A reference panel that opens if you want it.
 *
 * The facts — what is being moved, which order it came from, what has happened
 * — are worth having and are not worth reading every time. Closed they cost one
 * line and still answer the question at a glance, because the summary carries
 * the identifier rather than the word "Consignment".
 */
function Fold({ icon: Icon, title, summary, children }) {
  const [open, setOpen] = useState(false)
  const Chevron = open ? ChevronDown : ChevronRight

  return (
    <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
      <button onClick={() => setOpen((v) => !v)}
        style={{
          width: '100%', display: 'flex', alignItems: 'center', gap: 9, padding: '12px 15px',
          background: 'transparent', border: 'none', cursor: 'pointer', textAlign: 'left',
        }}>
        <Icon size={14} style={{ color: 'var(--accent)', flexShrink: 0 }} />
        <span style={{ minWidth: 0, flex: 1 }}>
          <span style={{
            display: 'block', fontSize: 12, fontWeight: 800, color: 'var(--text-h)',
            textTransform: 'uppercase', letterSpacing: '.03em',
          }}>{title}</span>
          {summary && (
            <span style={{ display: 'block', fontSize: 11.5, color: 'var(--text-muted)', marginTop: 2 }}>
              {summary}
            </span>
          )}
        </span>
        <Chevron size={15} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
      </button>
      {open && <div style={{ padding: '0 15px 15px' }}>{children}</div>}
    </div>
  )
}

const muted = { fontSize: 12, color: 'var(--text-muted)', margin: 0, lineHeight: 1.5 }

const linkCard = {
  textAlign: 'left', width: '100%', padding: '11px 12px', borderRadius: 10,
  background: 'var(--bg-input)', border: '1px solid var(--border)', cursor: 'pointer',
}

const btn = (color, solid = false) => ({
  padding: '8px 14px', borderRadius: 9, fontSize: 12.5, fontWeight: 800, cursor: 'pointer',
  background: solid ? color : 'var(--bg-input)',
  border: `1px solid ${solid ? color : 'var(--border)'}`,
  color: solid ? '#fff' : color,
  display: 'inline-flex', alignItems: 'center', gap: 6,
})
