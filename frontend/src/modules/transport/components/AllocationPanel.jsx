import { useState, useEffect, useCallback } from 'react'
import {
  Truck, UserRound, Plus, X, ShieldCheck, ShieldAlert, AlertTriangle,
  Loader2, RefreshCw, Info,
} from 'lucide-react'
import Modal from '@/components/ui/Modal'
import { useToast } from '@/components/ui/Toast'
import { transportAllocationApi } from '@/services/transportApi'
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
function CandidateRow({ row, kind, onPick, picking }) {
  const [open, setOpen] = useState(false)
  const s = row.subject
  const eligible = row.eligible
  const statusChip = kind === 'vehicle' ? vehicleStatusCfg(s.status) : driverAvailabilityCfg(s.availability)

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

      {/* UX §35 — the blocker is inline and specific, never a bare label. */}
      {!eligible && (
        <div style={{ marginTop: 8, paddingTop: 8, borderTop: '1px solid var(--border)' }}>
          {(row.blockers || []).map((b, i) => (
            <p key={i} style={{ margin: '0 0 4px', fontSize: 11.5, color: '#f87171', display: 'flex', gap: 6, alignItems: 'flex-start' }}>
              <AlertTriangle size={12} style={{ marginTop: 1, flexShrink: 0 }} /> {b}
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
            <p key={i} style={{ margin: 0, fontSize: 11.5, color: '#fbbf24' }}>⚠ {w}</p>
          ))}
        </div>
      )}
    </div>
  )
}

export default function AllocationPanel({ trip, assignment, canAssign, onChanged }) {
  const toast = useToast()

  const [picker, setPicker] = useState(null)          // 'vehicle' | 'driver' | null
  const [candidates, setCandidates] = useState({ vehicles: [], drivers: [] })
  const [showIneligible, setShowIneligible] = useState(true)
  const [loading, setLoading] = useState(false)
  const [picking, setPicking] = useState(false)
  const [refusal, setRefusal] = useState(null)
  const [releasing, setReleasing] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      setCandidates(await transportAllocationApi.candidates(trip.id, showIneligible))
    } catch (e) {
      toast.error(e?.message || 'Could not load candidates.')
    } finally {
      setLoading(false)
    }
  }, [trip.id, showIneligible])

  useEffect(() => { if (picker) load() }, [picker, load])

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
  const rows = picker === 'vehicle' ? candidates.vehicles : candidates.drivers
  const eligibleCount = (rows || []).filter((r) => r.eligible).length

  return (
    <>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginTop: 12 }}>
        <Slot kind="vehicle" icon={Truck} label="Vehicle"
          value={assignment?.vehicle ? assignment.vehicle.registration_number : (hasVehicle ? `#${assignment.vehicle_id}` : null)}
          canAssign={canAssign} onAssign={() => { setRefusal(null); setPicker('vehicle') }} />
        <Slot kind="driver" icon={UserRound} label="Driver"
          value={assignment?.driver ? assignment.driver.name : (hasDriver ? `#${assignment.driver_id}` : null)}
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
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
            <p style={{ margin: 0, fontSize: 12, color: 'var(--text-muted)', flex: 1, minWidth: 200 }}>
              {eligibleCount} eligible for {trip.trip_number}. A resource must be free, compliant and meet the order's requirements.
            </p>
            <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12, color: 'var(--text-p)', cursor: 'pointer' }}>
              <input type="checkbox" checked={showIneligible} onChange={(e) => setShowIneligible(e.target.checked)} />
              Show ineligible
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

          {!loading && (rows || []).length === 0 && (
            <div style={{ padding: 26, textAlign: 'center' }}>
              <ShieldAlert size={22} style={{ color: 'var(--text-muted)', margin: '0 auto 8px' }} />
              <p style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)', margin: 0 }}>
                No {picker === 'vehicle' ? 'vehicles' : 'drivers'} to show
              </p>
              <p style={{ fontSize: 12, color: 'var(--text-muted)', margin: '4px 0 0' }}>
                {showIneligible
                  ? `Add a ${picker} first, or check the master list.`
                  : 'Tick “Show ineligible” to see which ones exist and why they cannot be used.'}
              </p>
            </div>
          )}

          {!loading && (rows || []).length > 0 && (
            <div style={{ display: 'grid', gap: 8, maxHeight: 420, overflowY: 'auto' }}>
              {rows.map((r) => (
                <CandidateRow key={r.subject.id} row={r} kind={picker} onPick={pick} picking={picking} />
              ))}
            </div>
          )}
        </div>
      </Modal>
    </>
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
