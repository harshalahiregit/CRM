import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { X, Truck, Sparkles, AlertCircle, Check, Snowflake, Zap, Weight } from 'lucide-react'
import { stosApi, STOS_ACCENT, VEHICLE_TYPE_LABELS, VEHICLE_TYPE_OPTIONS, fmtAgo } from '@/services/stosApi'
import Select from '@/components/ui/Select'
import HealthChip from './HealthChip'

/**
 * Which vehicle should take this job (Feature 1).
 *
 * The board RECOMMENDS and the planner decides. Picking anything other than the
 * top-ranked vehicle demands a written reason — not to police the planner, but
 * because "why did we send the far one" is asked a week later and nobody
 * remembers. The reason is handed back to the caller; allocation itself belongs
 * to Dispatch, so nothing here writes it.
 *
 * Closes only via ✕ or Cancel — never a backdrop click.
 */
export default function VehicleAllocationModal({
  open, onClose, onSelect, vehicleType = '', pickup = null, requiredCapacityTonnes = null,
}) {
  const [type, setType] = useState(vehicleType)
  const [picked, setPicked] = useState(null)
  const [reason, setReason] = useState('')
  const [err, setErr] = useState('')

  // PLN-001 — the order's payload. Sent only when there is one, because zero
  // and absent must not be two spellings of "no limit"; the server refuses a
  // zero for the same reason.
  const required = Number(requiredCapacityTonnes) > 0 ? Number(requiredCapacityTonnes) : null

  const params = {
    ...(type ? { vehicle_type: type } : {}),
    ...(required ? { required_capacity_tonnes: required } : {}),
    ...(pickup?.lat ? { pickup_lat: pickup.lat, pickup_lng: pickup.lng } : {}),
  }

  const { data, isLoading } = useQuery({
    queryKey: ['stos-eligible', params],
    queryFn: () => stosApi.fleet.eligible(params),
    enabled: open,
  })

  if (!open) return null

  const eligible = data?.eligible ?? []
  const excluded = data?.excluded ?? []
  const top = eligible[0]
  const isOverride = picked && top && picked !== top.id

  const confirm = () => {
    if (!picked) return setErr('Choose a vehicle first.')
    if (isOverride && reason.trim().length < 5) {
      return setErr('A manual override needs a reason — a week from now, nobody will remember why.')
    }

    onSelect?.({
      vehicle: eligible.find((v) => v.id === picked),
      override: Boolean(isOverride),
      override_reason: isOverride ? reason.trim() : null,
    })
    onClose?.()
  }

  return (
    <div className="fixed inset-0 z-[60] flex items-start justify-center p-4 pt-[8vh] bg-black/50">
      <div
        className="w-full max-w-3xl rounded-2xl overflow-hidden flex flex-col"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', maxHeight: '84vh' }}
        onKeyDown={(e) => { if (e.key === 'Escape') onClose?.() }}
      >
        {/* ── Header ──────────────────────────────────────────── */}
        <div className="flex items-start justify-between gap-3 px-5 pt-4 pb-3" style={{ borderBottom: '1px solid var(--border)' }}>
          <div>
            <h2 className="font-bold" style={{ color: 'var(--text-h)', fontSize: 15 }}>Allocate a vehicle</h2>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
              Ranked by distance, running cost and how hard each has worked this week.
            </p>
          </div>
          <button onClick={onClose} aria-label="Close" className="p-1.5 rounded-lg shrink-0" style={{ color: 'var(--text-muted)' }}>
            <X size={16} />
          </button>
        </div>

        <div className="px-5 py-3 flex items-center gap-2" style={{ borderBottom: '1px solid var(--border)' }}>
          <span className="text-[11px] font-semibold" style={{ color: 'var(--text-muted)' }}>Type</span>
          <div className="w-44">
            <Select size="sm" value={type} onChange={setType} placeholder="Any type"
              options={[{ value: '', label: 'Any type' }, ...VEHICLE_TYPE_OPTIONS]} ariaLabel="Vehicle type" />
          </div>
          <span className="ml-auto text-[11px]" style={{ color: 'var(--text-muted)' }}>
            {eligible.length} eligible · {excluded.length} blocked
          </span>
        </div>

        {/* ── Body ────────────────────────────────────────────── */}
        <div className="px-5 py-4 overflow-y-auto flex-1 space-y-3">
          {isLoading && [0, 1].map((i) => (
            <div key={i} className="rounded-2xl animate-pulse" style={{ height: 92, background: 'var(--bg-input)' }} />
          ))}

          {!isLoading && eligible.length === 0 && (
            <div className="rounded-2xl p-8 text-center" style={{ background: 'var(--bg-input)' }}>
              <Truck size={20} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
              <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>No vehicle can take this job</p>
              <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
                {excluded.length > 0
                  ? 'Every vehicle is blocked — the reasons are listed below.'
                  : 'Nothing matches this type. Try widening it.'}
              </p>
            </div>
          )}

          {/* The recommendation, in the system's own words. */}
          {!isLoading && top?.recommendation && (
            <div className="flex items-start gap-2 rounded-xl px-3 py-2"
              style={{ background: `color-mix(in srgb, ${STOS_ACCENT} 10%, transparent)`, border: `1px solid color-mix(in srgb, ${STOS_ACCENT} 35%, transparent)` }}>
              <Sparkles size={14} className="shrink-0 mt-0.5" style={{ color: STOS_ACCENT }} />
              <p className="text-xs font-semibold" style={{ color: 'var(--text-h)' }}>{top.recommendation}</p>
            </div>
          )}

          {eligible.map((v) => (
            <VehicleCard key={v.id} vehicle={v} selected={picked === v.id} required={required}
              onPick={() => { setPicked(v.id); setErr('') }} />
          ))}

          {/* Blocked vehicles are shown, not hidden: "why isn't MH-04 here" has
              to be answerable on this screen. */}
          {excluded.length > 0 && (
            <div className="pt-1">
              <p className="text-[11px] font-bold mb-2" style={{ color: 'var(--text-muted)' }}>
                Not available ({excluded.length})
              </p>
              <div className="space-y-1.5">
                {excluded.map((v) => (
                  <div key={v.id} className="rounded-xl px-3 py-2" style={{ background: 'var(--bg-input)' }}>
                    <div className="flex items-center gap-2 flex-wrap">
                      <span className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>{v.registration_number}</span>
                      <HealthChip tone="red" size="sm">Blocked</HealthChip>
                      <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
                        {VEHICLE_TYPE_LABELS[v.vehicle_type] || v.vehicle_type}
                      </span>
                    </div>
                    {v.blockers.map((b) => (
                      <p key={b.code} className="text-[11px] mt-1 flex items-start gap-1.5" style={{ color: 'var(--text-muted)' }}>
                        <AlertCircle size={10} className="shrink-0 mt-0.5" style={{ color: 'var(--color-danger-500)' }} />
                        <span><span className="font-semibold">{b.why}</span> {b.missing} — {b.owner}.</span>
                      </p>
                    ))}
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>

        {/* ── Override + actions ──────────────────────────────── */}
        <div className="px-5 py-3 space-y-2" style={{ background: 'var(--bg-input)', borderTop: '1px solid var(--border)' }}>
          {isOverride && (
            <div>
              <label htmlFor="stos-override" className="text-[11px] font-bold" style={{ color: 'var(--color-warning-500, #f59e0b)' }}>
                Reason for manual allocation override *
              </label>
              <input
                id="stos-override"
                value={reason}
                onChange={(e) => { setReason(e.target.value); setErr('') }}
                placeholder="Why this vehicle instead of the recommended one?"
                className="w-full text-xs rounded-xl px-3 py-2 mt-1"
                style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text)' }}
              />
            </div>
          )}

          {err && <p className="text-[11px] font-semibold" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}

          <div className="flex items-center justify-end gap-2">
            <button onClick={onClose} className="text-xs font-semibold px-4 py-2 rounded-xl"
              style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
              Cancel
            </button>
            <button onClick={confirm} disabled={!picked}
              className="flex items-center gap-1.5 text-xs font-bold px-4 py-2 rounded-xl disabled:opacity-50"
              style={{ background: STOS_ACCENT, color: '#fff' }}>
              <Check size={13} /> Use this vehicle
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}

function VehicleCard({ vehicle: v, selected, onPick, required = null }) {
  const recommended = Boolean(v.recommended)
  const capacityUnknown = (v.flags ?? []).includes('capacity_unknown')

  return (
    <button
      type="button"
      onClick={onPick}
      className="w-full text-left rounded-2xl p-3"
      style={{
        background: selected ? `color-mix(in srgb, ${STOS_ACCENT} 10%, transparent)` : 'var(--bg-input)',
        border: `1px solid ${selected ? STOS_ACCENT : 'var(--border)'}`,
      }}
    >
      <div className="flex items-start gap-2">
        <span className="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: `color-mix(in srgb, ${STOS_ACCENT} 12%, transparent)` }}>
          {v.vehicle_type === 'reefer' ? <Snowflake size={15} style={{ color: STOS_ACCENT }} /> : <Truck size={15} style={{ color: STOS_ACCENT }} />}
        </span>

        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-1.5 flex-wrap">
            <span className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{v.registration_number}</span>
            <HealthChip tone="green" size="sm">Available</HealthChip>
            {recommended && (
              <span className="inline-flex items-center gap-1 text-[9px] font-bold px-1.5 py-0.5 rounded"
                style={{ background: `color-mix(in srgb, ${STOS_ACCENT} 18%, transparent)`, color: STOS_ACCENT }}>
                <Sparkles size={9} /> RECOMMENDED
              </span>
            )}
            <span className="text-[10px] px-1.5 py-0.5 rounded" style={{ background: 'var(--bg-card)', color: 'var(--text-muted)' }}>
              {VEHICLE_TYPE_LABELS[v.vehicle_type] || v.vehicle_type}
            </span>
            {v.open_jobs > 0 && (
              <span className="inline-flex items-center gap-1 text-[10px] px-1.5 py-0.5 rounded"
                style={{ background: 'var(--bg-card)', color: 'var(--text-muted)' }}>
                <Zap size={9} /> {v.open_jobs} minor job{v.open_jobs === 1 ? '' : 's'}
              </span>
            )}

            {/* The payload, whether or not an order asked for one — a planner
                choosing between two trucks wants the number in front of them. */}
            {v.capacity_tonnes != null && (
              <span className="inline-flex items-center gap-1 text-[10px] px-1.5 py-0.5 rounded"
                style={{ background: 'var(--bg-card)', color: 'var(--text-muted)' }}>
                <Weight size={9} /> {Number(v.capacity_tonnes)} t
              </span>
            )}
          </div>

          {/* Eligible, but not actually checked against the load. Said out loud
              rather than left to look like a pass. */}
          {capacityUnknown && (
            <p className="text-[10px] mt-1 flex items-start gap-1.5" style={{ color: 'var(--color-warning-500, #f59e0b)' }}>
              <AlertCircle size={10} className="shrink-0 mt-0.5" />
              <span>
                No payload recorded, so it has not been checked against the {required} t this order needs.
              </span>
            </p>
          )}

          <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>
            {v.reasons.join(' · ')}
          </p>
          <p className="text-[10px] mt-0.5" style={{ color: 'var(--text-muted)' }}>
            Last seen {fmtAgo(v.live?.last_ping_at)}
          </p>
        </div>

        <div className="text-right shrink-0">
          <p className="text-base font-black" style={{ color: STOS_ACCENT }}>{v.score}</p>
          <p className="text-[9px] font-semibold" style={{ color: 'var(--text-muted)' }}>SCORE</p>
        </div>
      </div>
    </button>
  )
}
