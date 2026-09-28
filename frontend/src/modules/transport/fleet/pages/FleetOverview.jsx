import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Truck, Search, ArrowRight, Wrench, Radio, Info, Sparkles, Plus, Pencil } from 'lucide-react'
import { stosApi, STOS_ACCENT, FLEET_STATES, VEHICLE_TYPE_LABELS, nextActionTo } from '@/services/stosApi'
import HealthChip from '../components/HealthChip'
import ExceptionPanel from '../components/ExceptionPanel'
import LiveTelemetryGauge from '../components/LiveTelemetryGauge'
import VehicleAllocationModal from '../components/VehicleAllocationModal'
import VehicleFormModal from '../components/VehicleFormModal'

/**
 * Fleet & Asset control tower — the vehicle status grid.
 *
 * Exception-first: the fleet is sorted so whatever is blocked or needs
 * attention is at the top, because a list sorted by registration number buries
 * the one truck that cannot move under thirty that can.
 *
 * Every card ends in a single primary action (the Next-Action Engine), so no
 * card is a dead end — even a healthy vehicle offers its passport.
 */

const TONE_RANK = { red: 0, amber: 1, green: 2 }

export default function FleetOverview() {
  const [state, setState] = useState('')
  const [allocating, setAllocating] = useState(false)
  const [editing, setEditing] = useState(null)   // a vehicle, or 'new'
  const [term, setTerm] = useState('')

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['stos-fleet', state, term],
    queryFn: () => stosApi.fleet.grid({ ...(state ? { state } : {}), ...(term ? { q: term } : {}) }),
    // Live telemetry ages; a control tower showing a five-minute-old picture
    // without saying so is worse than one that refreshes.
    refetchInterval: 60_000,
  })

  const tiles = data?.tiles
  const vehicles = [...(data?.vehicles ?? [])].sort(
    (a, b) => (TONE_RANK[a.health.tone] ?? 3) - (TONE_RANK[b.health.tone] ?? 3)
  )

  return (
    <div className="max-w-6xl">
      <header className="flex flex-wrap items-center gap-2 mb-4">
        <span className="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: `color-mix(in srgb, ${STOS_ACCENT} 14%, transparent)` }}>
          <Truck size={17} style={{ color: STOS_ACCENT }} />
        </span>
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Vehicle status</h1>
        {tiles && (
          <span className="text-xs px-2 py-0.5 rounded-lg" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
            {tiles.total}
          </span>
        )}

        <button
          onClick={() => setAllocating(true)}
          className="ml-auto flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
          style={{ background: `color-mix(in srgb, ${STOS_ACCENT} 14%, transparent)`, color: STOS_ACCENT }}
        >
          <Sparkles size={13} /> Check readiness
        </button>

        <button
          onClick={() => setEditing('new')}
          className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
          style={{ background: STOS_ACCENT, color: '#fff' }}
        >
          <Plus size={13} /> Add vehicle
        </button>

        <div className="relative">
          <Search size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            value={term}
            onChange={(e) => setTerm(e.target.value)}
            placeholder="Registration or device…"
            aria-label="Search the fleet"
            className="text-xs rounded-xl pl-7 pr-3 py-2 w-56"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text)' }}
          />
        </div>
      </header>

      {/* ── Traffic-light summary ───────────────────────────────── */}
      {tiles && (
        <div className="flex flex-wrap gap-2 mb-3">
          <Summary tone="red" count={tiles.red} label="Blocked" />
          <Summary tone="amber" count={tiles.amber} label="Needs attention" />
          <Summary tone="green" count={tiles.green} label="Healthy" />
        </div>
      )}

      {/* ── State filters ──────────────────────────────────────── */}
      <div className="flex flex-wrap gap-1.5 mb-4">
        {FLEET_STATES.map((s) => {
          const count = s.value ? (tiles?.by_state?.[s.value] ?? 0) : (tiles?.total ?? 0)
          const active = state === s.value

          return (
            <button
              key={s.value || 'all'}
              onClick={() => setState(s.value)}
              className="text-[11px] font-semibold px-2.5 py-1.5 rounded-xl"
              style={{
                background: active ? `color-mix(in srgb, ${STOS_ACCENT} 16%, transparent)` : 'var(--bg-input)',
                border: `1px solid ${active ? STOS_ACCENT : 'var(--border)'}`,
                color: active ? STOS_ACCENT : 'var(--text-muted)',
              }}
            >
              {s.label} <span className="opacity-70">{count}</span>
            </button>
          )
        })}
      </div>

      {/* Honest about what this board cannot show yet. */}
      <p className="flex items-start gap-1.5 text-[11px] mb-4" style={{ color: 'var(--text-muted)' }}>
        <Info size={12} className="shrink-0 mt-0.5" />
        Allocated and In&nbsp;Transit are trip states owned by Dispatch — they appear here once that module lands.
        Moving / Idle / Offline are read from telemetry.
      </p>

      {isError && (
        <p className="text-xs px-3 py-2 rounded-lg mb-3"
          style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>
          {error?.message || 'Could not load the fleet.'}
        </p>
      )}

      {isLoading && (
        <div className="space-y-3">
          {[0, 1, 2].map((i) => (
            <div key={i} className="rounded-2xl animate-pulse" style={{ height: 130, background: 'var(--bg-card)' }} />
          ))}
        </div>
      )}

      {!isLoading && !isError && vehicles.length === 0 && (
        <div className="rounded-2xl p-10 text-center" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <Truck size={22} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
          <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>
            {state || term ? 'Nothing matches that filter' : 'No vehicles yet'}
          </p>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            {state || term
              ? 'Clear the filter to see the whole fleet.'
              : 'Add your first truck — everything else in STOS attaches to a vehicle.'}
          </p>
          {!state && !term && (
            <button onClick={() => setEditing('new')}
              className="inline-flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl mt-3"
              style={{ background: STOS_ACCENT, color: '#fff' }}>
              <Plus size={13} /> Add vehicle
            </button>
          )}
        </div>
      )}

      <div className="space-y-3">
        {vehicles.map((v) => <VehicleCard key={v.id} vehicle={v} onEdit={() => setEditing(v)} />)}
      </div>

      {/* Readiness is a question the fleet answers; the ALLOCATION itself is
          Dispatch's to record, so this only reports the choice. */}
      <VehicleAllocationModal open={allocating} onClose={() => setAllocating(false)} />

      <VehicleFormModal
        open={Boolean(editing)}
        vehicle={editing === 'new' ? null : editing}
        onClose={() => setEditing(null)}
      />
    </div>
  )
}

function Summary({ tone, count, label }) {
  return (
    <div className="flex items-center gap-2 rounded-xl px-3 py-2" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <HealthChip tone={tone}>{count}</HealthChip>
      <span className="text-[11px] font-semibold" style={{ color: 'var(--text-muted)' }}>{label}</span>
    </div>
  )
}

function VehicleCard({ vehicle: v, onEdit }) {
  const isHealthy = v.health.tone === 'green'

  return (
    <section className="rounded-2xl overflow-hidden" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <div className="p-4">
        <div className="flex items-start gap-3">
          <span className="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
            style={{ background: `color-mix(in srgb, ${STOS_ACCENT} 12%, transparent)` }}>
            <Truck size={16} style={{ color: STOS_ACCENT }} />
          </span>

          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-1.5 flex-wrap">
              <Link to={nextActionTo(v.id)} className="text-sm font-bold hover:underline" style={{ color: 'var(--text-h)' }}>
                {v.registration_number}
              </Link>
              <HealthChip tone={v.health.tone} size="sm" />
              <span className="text-[10px] px-1.5 py-0.5 rounded" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
                {VEHICLE_TYPE_LABELS[v.vehicle_type] || v.vehicle_type}
              </span>
              <span className="text-[10px] px-1.5 py-0.5 rounded capitalize" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
                {String(v.health.state).replace('_', ' ')}
              </span>
              {v.open_jobs > 0 && (
                <span className="inline-flex items-center gap-1 text-[10px] px-1.5 py-0.5 rounded"
                  style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
                  <Wrench size={9} /> {v.open_jobs} open
                </span>
              )}
              {!v.gps_device_id && (
                <span className="inline-flex items-center gap-1 text-[10px] px-1.5 py-0.5 rounded"
                  style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
                  <Radio size={9} /> no device
                </span>
              )}
            </div>

            {isHealthy && (
              <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>{v.health.headline}</p>
            )}
          </div>

          <button
            onClick={onEdit}
            aria-label={`Edit ${v.registration_number}`}
            className="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}
          >
            <Pencil size={13} />
          </button>

          {/* The Next-Action Engine: one primary button, always. */}
          <Link
            to={nextActionTo(v.id, v.health.next?.action)}
            className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl shrink-0"
            style={{ background: STOS_ACCENT, color: '#fff' }}
          >
            {v.health.next?.label || 'Open passport'} <ArrowRight size={13} />
          </Link>
        </div>

        {!isHealthy && (
          <div className="mt-3">
            <ExceptionPanel issues={v.health.issues} vehicleId={v.id} compact />
          </div>
        )}

        {v.live && (
          <div className="mt-3">
            <LiveTelemetryGauge live={v.live} size="sm" />
          </div>
        )}
      </div>
    </section>
  )
}
