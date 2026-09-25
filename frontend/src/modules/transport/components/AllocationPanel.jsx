import { useState, useEffect, useCallback } from 'react'
import {
  Truck, UserRound, Plus, X, ShieldCheck, ShieldAlert, AlertTriangle,
  Loader2, RefreshCw, Info, Search, CalendarClock,
} from 'lucide-react'
import Modal from '@/components/ui/Modal'
import { useToast } from '@/components/ui/Toast'
import { transportAllocationApi, transportResourceCommitmentApi } from '@/services/transportApi'
import { Chip } from './MasterFormFields'
import { vehicleStatusCfg, driverAvailabilityCfg } from '../constants'

/**
 * Allocation panel on the trip detail page — SNG-TRN-009 step 8.
 *
 * ── FLEET §16 IS THE WHOLE REASON THIS PANEL LOOKS LIKE IT DOES ──────────
 * "A vehicle may be Available but not Dispatch Ready. Fleet must distinguish:
 *  Available (asset is free) / Eligible (asset meets requirements) /
 *  Ready (asset has passed required readiness checks)."
 *
 * So a candidate row shows its own status chip AND its eligibility verdict as
 * two separate signals. A vehicle reading "Available" that still cannot be
 * picked is the exact case §16 exists to stop a UI from blurring, and hiding
 * such a vehicle would blur it just as badly — the dispatcher would be left
 * wondering where their truck went.
 *
 * ── UX §35: NEVER MERELY SHOW "BLOCKED" ──────────────────────────────────
 * §35 demands four things of a blocked state: why, what is missing, who must
 * fix it, and what happens after fixing. Three of the four come straight from
 * the eligibility verdict's `detail` string, which Step 5 wrote in exactly that
 * voice — "Licence expired on 07 Sep 2026. Upload a valid licence or assign
 * another eligible driver."
 *
 * The fourth, "who must fix it", is NOT shown, and that is a recorded gap rather
 * than an oversight: an owner per requirement lives in CMP §12's Compliance
 * Requirement Master, which no ticket in the 30-ticket register builds. Inventing
 * a check→team mapping here would be a requirement nobody approved.
 *
 * ── UX §36: WARNING IS NOT BLOCK ─────────────────────────────────────────
 * A failed REQUIRED check is red and makes the row unselectable. A failed
 * advisory check is amber and does not — "Warning: user may continue."
 */

const btn = {
  base: { padding: '8px 13px', borderRadius: 9, fontSize: 12.5, fontWeight: 700, display: 'inline-flex', alignItems: 'center', gap: 6, cursor: 'pointer', border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-p)' },
  primary: { padding: '8px 14px', borderRadius: 9, fontSize: 12.5, fontWeight: 800, display: 'inline-flex', alignItems: 'center', gap: 6, cursor: 'pointer', border: '1px solid #7C3AED', background: '#7C3AED', color: '#fff' },
  danger: { padding: '8px 13px', borderRadius: 9, fontSize: 12.5, fontWeight: 800, display: 'inline-flex', alignItems: 'center', gap: 6, cursor: 'pointer', border: '1px solid rgba(248,113,113,0.35)', background: 'rgba(248,113,113,0.12)', color: '#f87171' },
}

/** One check, rendered so a refusal explains itself (UX §35/§36). */
function CheckLine({ check }) {
  const failed = !check.passed
  const blocking = failed && check.required
  const colour = blocking ? '#f87171' : failed ? '#fbbf24' : '#34d399'

  return (
    <div style={{ display: 'flex', gap: 7, alignItems: 'flex-start' }}>
      <span style={{ marginTop: 4, width: 7, height: 7, borderRadius: 999, flexShrink: 0, background: colour }} />
      <div style={{ minWidth: 0 }}>
        <p style={{ margin: 0, fontSize: 12, fontWeight: 700, color: 'var(--text-h)' }}>
          {check.label}
          {failed && !check.required && <span style={{ fontWeight: 600, color: '#fbbf24' }}> · warning</span>}
        </p>
        <p style={{ margin: '1px 0 0', fontSize: 11.5, color: blocking ? '#f87171' : 'var(--text-muted)' }}>
          {check.detail}
        </p>
      </div>
    </div>
  )
}

/** A selectable candidate. Ineligible rows stay visible but cannot be chosen. */
function CandidateRow({ row, kind, onPick, picking, commitment }) {
  const [open, setOpen] = useState(false)
  const s = row.subject
  const eligible = row.eligible
  const statusChip = kind === 'vehicle' ? vehicleStatusCfg(s.status) : driverAvailabilityCfg(s.availability)

  /*
   * When one of OUR trips is holding this resource, the eligibility service
   * also reports it — as "Already assigned to trip #12 — release that
   * assignment first." That is the same fact as the sentence above, told with
   * a raw database id, and showing both makes the panel read like a debug log.
   *
   * So that ONE blocker is dropped when a commitment sentence is present.
   * Everything else the verdict says still shows: UX §35 requires the reason,
   * and this drops a duplicate, not a reason.
   *
   * Keyed on the check's `key`, never on its wording — Person 2 owns that text
   * and may reword it at any time without telling us.
   */
  const assignmentDetail = (row.checks || []).find((c) => c.key === 'assignment' && !c.passed)?.detail
  // Compared on `why`, not on the blocker itself — D-147. These were strings
  // until the eligibility services moved to Fleet, and `b === assignmentDetail`
  // then compared an object to a string, which is always false. No crash: the
  // de-duplication simply stopped, and the same sentence appeared twice. Worth
  // saying because it is the half of the shape change that did not announce
  // itself.
  const visibleBlockers = (row.blockers || []).filter(
    (b) => !(commitment && assignmentDetail && reason(b) === assignmentDetail),
  )

  return (
    <div style={{
      border: '1px solid var(--border)', borderRadius: 10, padding: '10px 12px',
      background: eligible ? 'var(--bg-input)' : 'transparent',
      opacity: eligible ? 1 : 0.72,
    }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
        <div style={{ flex: 1, minWidth: 160 }}>
          <p style={{ margin: 0, fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>
            {kind === 'vehicle' ? s.registration_number : s.name}
          </p>
          <p style={{ margin: '2px 0 0', fontSize: 11.5, color: 'var(--text-muted)' }}>
            {kind === 'vehicle'
              ? [s.vehicle_type, s.capacity_tonnes ? `${Number(s.capacity_tonnes)} t` : null].filter(Boolean).join(' · ') || '—'
              : [s.driver_code, s.licence_class].filter(Boolean).join(' · ') || '—'}
          </p>
        </div>

        {/* FLEET §16 — free (status) and usable (eligibility) are two signals. */}
        <Chip cfg={statusChip} />
        <Chip cfg={eligible
          ? { label: 'Eligible', color: '#34d399', bg: 'rgba(52,211,153,0.14)' }
          : { label: 'Not eligible', color: '#f87171', bg: 'rgba(248,113,113,0.16)' }} />

        {eligible ? (
          <button onClick={() => onPick(s.id)} disabled={picking} style={{ ...btn.primary, padding: '6px 12px' }}>
            {picking ? <Loader2 size={12} className="animate-spin" /> : <Plus size={12} />} Assign
          </button>
        ) : (
          <button onClick={() => setOpen((v) => !v)} style={{ ...btn.base, padding: '6px 11px' }}>
            <Info size={12} /> {open ? 'Hide' : 'Why not?'}
          </button>
        )}
      </div>

      {/* WHY IT IS BUSY, AND WHEN IT COMES FREE.
          This sentence comes from OUR OWN trips (trip_assignments +
          transport_trips), not from the eligibility verdict. It is shown above
          the blockers because it is the one a dispatcher can act on: it names a
          date. Absence of a commitment does NOT mean the resource is free —
          only that no trip of ours is holding it, in which case the blockers
          below are all we honestly know. */}
      {commitment && (
        <div style={{
          marginTop: 8, paddingTop: 8, borderTop: '1px solid var(--border)',
          display: 'flex', gap: 6, alignItems: 'flex-start',
        }}>
          <CalendarClock size={12} style={{ marginTop: 2, flexShrink: 0, color: 'var(--text-muted)' }} />
          <p style={{ margin: 0, fontSize: 12, color: 'var(--text-p)', fontWeight: 600 }}>
            {commitment.sentence}
          </p>
        </div>
      )}

      {/* UX §35 — the blocker is inline and specific, never a bare label. */}
      {!eligible && (visibleBlockers.length > 0 || open) && (
        <div style={{ marginTop: 8, paddingTop: commitment ? 0 : 8, borderTop: commitment ? 'none' : '1px solid var(--border)' }}>
          {visibleBlockers.map((b, i) => (
            <p key={i} style={{ margin: '0 0 4px', fontSize: 11.5, color: '#f87171', display: 'flex', gap: 6, alignItems: 'flex-start' }}>
              <AlertTriangle size={12} style={{ marginTop: 1, flexShrink: 0 }} /> {reason(b)}
            </p>
          ))}
          {open && (
            <div style={{ display: 'grid', gap: 6, marginTop: 8 }}>
              {(row.checks || []).map((c) => <CheckLine key={c.key} check={c} />)}
            </div>
          )}
        </div>
      )}

      {/* An eligible row can still carry advisory warnings — §36. */}
      {eligible && (row.warnings || []).length > 0 && (
        <div style={{ marginTop: 7 }}>
          {row.warnings.map((w, i) => (
            <p key={i} style={{ margin: 0, fontSize: 11.5, color: '#fbbf24' }}>⚠ {reason(w)}</p>
          ))}
        </div>
      )}
    </div>
  )
}


/**
 * What to show in a slot — D-135.
 *
 * This used to read `assignment?.driver ? assignment.driver.name : '#'+id`.
 * The repoint made `assignment.driver` a real object whose `name` was empty,
 * so the first branch won, the id fallback never ran, and a driver that HAD
 * been assigned rendered as a blank. A blank is indistinguishable from nothing
 * being assigned, which is exactly how it was reported.
 *
 * So the test is on the VALUE, not on whether the object exists. The server
 * resolves the name through the directory now and should always send one; if
 * it ever does not, the id says "something is here" rather than the screen
 * saying nothing is.
 */
const named = (value, isSet, id) => {
  const shown = (value ?? '').toString().trim()
  if (shown) return shown
  return isSet && id != null ? `#${id}` : null
}


/**
 * A blocker or a warning, as a sentence — D-147.
 *
 * These arrived as strings until the eligibility services were repointed at
 * Fleet (D-134). Fleet answers with `{code, why, owner}` — the owner being the
 * desk that can clear it — and rendering that object straight into JSX is the
 * "Objects are not valid as a React child" crash the owner hit.
 *
 * Shaped to match `DriversBoard` and `VehicleAllocationModal`, which already
 * print `why (owner)`. Naming the desk is the point: "Blocked" on its own
 * sends a dispatcher hunting; "the compliance desk holds this one" does not.
 *
 * No string fallback. Every producer of these is Fleet now, one shape, and a
 * dual-shape reader is how two shapes survive.
 */
const reason = (r) => (r?.owner ? `${r.why} (${r.owner})` : r?.why ?? '')

export default function AllocationPanel({ trip, assignment, canAssign, onChanged }) {
  const toast = useToast()

  const [picker, setPicker] = useState(null)          // 'vehicle' | 'driver' | null
  const [candidates, setCandidates] = useState({ vehicles: [], drivers: [] })
  const [showIneligible, setShowIneligible] = useState(true)
  const [loading, setLoading] = useState(false)
  const [picking, setPicking] = useState(false)
  const [refusal, setRefusal] = useState(null)
  const [releasing, setReleasing] = useState(false)
  const [search, setSearch] = useState('')
  const [commitments, setCommitments] = useState({ vehicles: {}, drivers: {} })

  const load = useCallback(async () => {
    setLoading(true)
    try {
      // Two calls, on purpose. Candidates come from the eligibility services
      // (Person 2's); commitments come from our own trips. Merging here rather
      // than server-side keeps us out of somebody else's contract.
      const [rows, held] = await Promise.all([
        transportAllocationApi.candidates(trip.id, showIneligible),
        transportResourceCommitmentApi.all(),
      ])
      setCandidates(rows)
      setCommitments(held)
    } catch (e) {
      toast.error(e?.message || 'Could not load candidates.')
    } finally {
      setLoading(false)
    }
  }, [trip.id, showIneligible])

  useEffect(() => { if (picker) load() }, [picker, load])

  // Clear the box each time the picker opens, so it never opens pre-filtered
  // with a term the user typed for the other resource.
  useEffect(() => { setSearch('') }, [picker])

  const pick = async (id) => {
    setPicking(true); setRefusal(null)
    try {
      const res = await transportAllocationApi.assign(trip.id, picker === 'vehicle' ? { vehicle_id: id } : { driver_id: id })
      if (res.ok) {
        toast.success(res.allocated ? 'Trip allocated.' : 'Assigned — the trip is allocated once both a vehicle and a driver are set.')
        setPicker(null)
        onChanged?.()
      } else {
        // A 422 is a verdict. Keep the picker open and show it in place.
        setRefusal(res.message)
        if (res.eligibility) setCandidates(res.eligibility)
        toast.error(res.message)
      }
    } catch (e) {
      toast.error(e?.message || 'The assignment could not be made.')
    } finally {
      setPicking(false)
    }
  }

  const release = async () => {
    const reason = window.prompt('Releasing frees the vehicle and driver and returns the trip to Approved.\n\nType a reason to confirm:')
    if (!reason) return
    setReleasing(true)
    try {
      await transportAllocationApi.release(trip.id, reason)
      toast.success('Assignment released.')
      onChanged?.()
    } catch (e) {
      toast.error(e?.message || 'The assignment could not be released.')
    } finally {
      setReleasing(false)
    }
  }

  const hasVehicle = !!assignment?.vehicle_id
  const hasDriver = !!assignment?.driver_id
  const anything = hasVehicle || hasDriver
  const allRows = (picker === 'vehicle' ? candidates.vehicles : candidates.drivers) || []
  const heldBy = picker === 'vehicle' ? commitments.vehicles : commitments.drivers

  /*
   * THE SEARCH BOX — a client-side filter over the list already fetched.
   *
   * Deliberately not a server query: the candidates endpoint belongs to the
   * eligibility services and adding a search parameter would be a contract
   * change to somebody else's surface. Everything matched on is already in the
   * payload, so no extra data is needed.
   *
   * A dispatcher types a registration or a name, not a code, so matching is
   * case-insensitive and ignores spaces and punctuation in BOTH the term and
   * the value — "mh12" and "MH 12" find the same truck, which is the whole
   * point when registrations are written four different ways.
   */
  const normalise = (v) => String(v || '').toLowerCase().replace(/[^a-z0-9]/g, '')
  const term = normalise(search)

  const rows = term
    ? allRows.filter((r) => {
        const s = r.subject
        const haystack = picker === 'vehicle'
          ? [s.registration_number, s.vehicle_type]
          : [s.name, s.driver_code, s.licence_class]
        return haystack.some((v) => normalise(v).includes(term))
      })
    : allRows

  const eligibleCount = rows.filter((r) => r.eligible).length

  // Grouped rather than one flat list: "who can I pick" and "who cannot I pick,
  // and why" are two different questions, and interleaving them made the reader
  // scan chips to tell them apart.
  const readyRows = rows.filter((r) => r.eligible)
  const blockedRows = rows.filter((r) => !r.eligible)

  return (
    <>
      {/* D-119 — the crew is released when the trip is delivered, and the
          screen has to say so. Without this line the vehicle and driver are
          still listed, look allocated, and are silently free for another trip;
          the owner's original complaint was the mirror of this, a driver that
          never came free at all. */}
      {assignment?.status === 'released' && (
        <p style={{
          margin: '12px 0 0', padding: '9px 11px', borderRadius: 9, fontSize: 12.5,
          background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-p)',
        }}>
          <strong>Released.</strong> This vehicle and driver were freed when the trip was
          delivered and are available for other trips. They stay listed here because this is
          who ran this trip.
        </p>
      )}

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginTop: 12 }}>
        <Slot kind="vehicle" icon={Truck} label="Vehicle"
          value={named(assignment?.vehicle?.registration_number, hasVehicle, assignment?.vehicle_id)}
          canAssign={canAssign} onAssign={() => { setRefusal(null); setPicker('vehicle') }} />
        <Slot kind="driver" icon={UserRound} label="Driver"
          value={named(assignment?.driver?.name, hasDriver, assignment?.driver_id)}
          canAssign={canAssign} onAssign={() => { setRefusal(null); setPicker('driver') }} />
      </div>

      {/* The trip's own status is on the header chip and the tracker above; a
          third copy here said nothing the reader did not already have. What
          belongs to THIS step is whether the step is finished. */}
      <div style={{ marginTop: 12, display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
        {anything && !(hasVehicle && hasDriver) && (
          <span style={{ fontSize: 11.5, color: '#fbbf24' }}>
            This step is finished once both a vehicle and a driver are assigned.
          </span>
        )}
        {hasVehicle && hasDriver && (
          <span style={{ fontSize: 11.5, color: '#34d399' }}>
            Vehicle and driver assigned.
          </span>
        )}
        {canAssign && anything && (
          <button onClick={release} disabled={releasing} style={{ ...btn.danger, marginLeft: 'auto', padding: '6px 11px' }}>
            <X size={12} /> {releasing ? 'Releasing…' : 'Release'}
          </button>
        )}
      </div>

      {!canAssign && (
        <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '10px 0 0' }}>
          You do not have permission to allocate resources to a trip.
        </p>
      )}

      <Modal open={!!picker} onClose={() => setPicker(null)}
        title={picker === 'vehicle' ? 'Choose a vehicle' : 'Choose a driver'} size="lg">
        <div style={{ display: 'grid', gap: 12 }}>
          {/* Search first, because with fifty vehicles it is the only thing
              on this screen anyone uses. */}
          <div style={{ position: 'relative' }}>
            <Search size={14} style={{
              position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)',
              color: 'var(--text-muted)', pointerEvents: 'none',
            }} />
            <input
              autoFocus
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder={picker === 'vehicle' ? 'Search by vehicle number…' : 'Search by driver name…'}
              style={{
                width: '100%', padding: '9px 32px 9px 32px', borderRadius: 9, fontSize: 13,
                border: '1px solid var(--border)', background: 'var(--bg-input)', color: 'var(--text-p)',
              }}
            />
            {search && (
              <button
                onClick={() => setSearch('')}
                aria-label="Clear search"
                style={{
                  position: 'absolute', right: 8, top: '50%', transform: 'translateY(-50%)',
                  border: 'none', background: 'transparent', cursor: 'pointer',
                  color: 'var(--text-muted)', display: 'flex', padding: 3,
                }}
              >
                <X size={13} />
              </button>
            )}
          </div>

          <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
            <p style={{ margin: 0, fontSize: 12, color: 'var(--text-muted)', flex: 1, minWidth: 180 }}>
              {eligibleCount === 0
                ? `None ready for ${trip.trip_number}`
                : `${eligibleCount} ready for ${trip.trip_number}`}
              {search && ` · filtered from ${allRows.length}`}
            </p>
            <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12, color: 'var(--text-p)', cursor: 'pointer' }}>
              <input type="checkbox" checked={showIneligible} onChange={(e) => setShowIneligible(e.target.checked)} />
              Show the ones that cannot be used
            </label>
            <button onClick={load} disabled={loading} style={{ ...btn.base, padding: '6px 11px' }}>
              <RefreshCw size={12} className={loading ? 'animate-spin' : ''} /> Refresh
            </button>
          </div>

          {refusal && (
            <div style={{ padding: '10px 12px', borderRadius: 9, background: 'rgba(248,113,113,0.12)', border: '1px solid rgba(248,113,113,0.35)', color: '#f87171', fontSize: 12.5, display: 'flex', gap: 8 }}>
              <ShieldAlert size={15} style={{ flexShrink: 0, marginTop: 1 }} /> {refusal}
            </div>
          )}

          {loading && <div style={{ padding: 24, textAlign: 'center' }}><Loader2 size={18} className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>}

          {!loading && rows.length === 0 && (
            <div style={{ padding: 26, textAlign: 'center' }}>
              <ShieldAlert size={22} style={{ color: 'var(--text-muted)', margin: '0 auto 8px' }} />
              <p style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)', margin: 0 }}>
                {search
                  ? `Nothing matches “${search}”`
                  : `No ${picker === 'vehicle' ? 'vehicles' : 'drivers'} to show`}
              </p>
              <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '4px 0 0' }}>
                {search
                  ? 'Check the spelling, or clear the search to see everything.'
                  : showIneligible
                    ? `Add a ${picker} first, or check the master list.`
                    : 'Tick the box above to see which ones exist and why they cannot be used.'}
              </p>
            </div>
          )}

          {!loading && rows.length > 0 && (
            <div style={{ display: 'grid', gap: 14, maxHeight: 420, overflowY: 'auto' }}>
              {readyRows.length > 0 && (
                <div style={{ display: 'grid', gap: 8 }}>
                  <GroupLabel text="Ready to assign" count={readyRows.length} />
                  {readyRows.map((r) => (
                    <CandidateRow key={r.subject.id} row={r} kind={picker} onPick={pick}
                      picking={picking} commitment={heldBy?.[r.subject.id]} />
                  ))}
                </div>
              )}

              {blockedRows.length > 0 && (
                <div style={{ display: 'grid', gap: 8 }}>
                  <GroupLabel text="Cannot be used right now" count={blockedRows.length} />
                  {blockedRows.map((r) => (
                    <CandidateRow key={r.subject.id} row={r} kind={picker} onPick={pick}
                      picking={picking} commitment={heldBy?.[r.subject.id]} />
                  ))}
                </div>
              )}
            </div>
          )}
        </div>
      </Modal>
    </>
  )
}

/** A plain heading between the two groups — no chip, no colour, just a label. */
function GroupLabel({ text, count }) {
  return (
    <p style={{
      margin: 0, fontSize: 10.5, fontWeight: 800, letterSpacing: '.06em',
      textTransform: 'uppercase', color: 'var(--text-muted)',
    }}>
      {text} · {count}
    </p>
  )
}

/** One assigned-or-empty slot. */
function Slot({ icon: Icon, label, value, canAssign, onAssign }) {
  return (
    <div style={{ padding: '11px 12px', borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
      <p style={{ margin: 0, fontSize: 10.5, fontWeight: 800, letterSpacing: '.06em', textTransform: 'uppercase', color: 'var(--text-muted)' }}>{label}</p>
      {value ? (
        <p style={{ margin: '5px 0 0', fontSize: 13.5, fontWeight: 700, color: 'var(--text-h)', display: 'flex', alignItems: 'center', gap: 7 }}>
          <ShieldCheck size={14} style={{ color: '#34d399' }} /> {value}
        </p>
      ) : (
        <div style={{ marginTop: 6 }}>
          <p style={{ margin: '0 0 7px', fontSize: 12.5, color: 'var(--text-muted)' }}>Not assigned</p>
          {canAssign && (
            <button onClick={onAssign} style={{ ...btn.base, padding: '6px 11px' }}>
              <Icon size={12} /> Assign {label.toLowerCase()}
            </button>
          )}
        </div>
      )}
    </div>
  )
}
