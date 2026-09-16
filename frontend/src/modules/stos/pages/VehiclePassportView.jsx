import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useParams, Link, useLocation } from 'react-router-dom'
import {
  Truck, ArrowLeft, Fuel, Wrench, Receipt, ShieldCheck, Activity, Zap, Plus, FileText, AlertTriangle, Droplets, Disc3, UserRound,
} from 'lucide-react'
import { stosApi, STOS_ACCENT, VEHICLE_TYPE_LABELS, fmtMoney, fmtWhen } from '@/services/stosApi'
import HealthChip from '../components/HealthChip'
import ExceptionPanel from '../components/ExceptionPanel'
import LiveTelemetryGauge from '../components/LiveTelemetryGauge'
import FuelExpenseModal from '../components/FuelExpenseModal'
import MaintenanceJobCardForm from '../components/MaintenanceJobCardForm'
import CompliancePanel from '../components/CompliancePanel'
import TyrePanel from '../components/TyrePanel'
import AssignedDriverCard from '../components/AssignedDriverCard'

/**
 * The Digital Vehicle Passport (Feature 5) — one screen, five tabs.
 *
 * Tabs rather than one long scroll because the four audiences are different
 * people: the control tower wants live status, accounts wants cost, the
 * workshop wants job cards, compliance wants papers.
 *
 * A Next-Action link from the fleet board arrives with a hash (#telemetry,
 * #workshop, #compliance) and opens straight onto that tab — landing someone on
 * "Overview" when they clicked "Review compliance" is a dead end with extra steps.
 */

const TABS = [
  { key: 'overview',   label: 'Overview',    icon: Truck },
  { key: 'telemetry',  label: 'Live status', icon: Activity },
  { key: 'fuel',       label: 'Costs',       icon: Fuel },
  { key: 'workshop',   label: 'Maintenance', icon: Wrench },
  { key: 'compliance', label: 'Compliance',  icon: ShieldCheck },
]

export default function VehiclePassportView() {
  const { id } = useParams()
  const { hash } = useLocation()
  const [tab, setTab] = useState('overview')
  const [fuelOpen, setFuelOpen] = useState(false)
  const [jobOpen, setJobOpen] = useState(false)

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['stos-vehicle', id],
    queryFn: () => stosApi.fleet.passport(id),
    refetchInterval: 60_000,
  })

  // Honour the Next-Action hash, once, on arrival.
  useEffect(() => {
    const key = hash.replace('#', '')
    if (key && TABS.some((t) => t.key === key)) setTab(key)
  }, [hash])

  if (isLoading) {
    return <div className="rounded-2xl animate-pulse max-w-5xl" style={{ height: 320, background: 'var(--bg-card)' }} />
  }

  if (isError) {
    return (
      <div className="max-w-5xl">
        <BackLink />
        <p className="text-xs px-3 py-2 rounded-lg"
          style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>
          {error?.message || 'Could not load this vehicle.'}
        </p>
      </div>
    )
  }

  const { vehicle, health, live, signal, gensets = [], telemetry = [], fuel, tolls, workshop, compliance, urea, tyres, driver } = data

  return (
    <div className="max-w-5xl space-y-4">
      <BackLink />

      {/* ── Header card ────────────────────────────────────────── */}
      <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <div className="flex items-start gap-3 flex-wrap">
          <span className="w-10 h-10 rounded-xl flex items-center justify-center shrink-0"
            style={{ background: `color-mix(in srgb, ${STOS_ACCENT} 12%, transparent)` }}>
            <Truck size={18} style={{ color: STOS_ACCENT }} />
          </span>

          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2 flex-wrap">
              <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>{vehicle.registration_number}</h1>
              <HealthChip tone={health.tone} />
              <Tag>{VEHICLE_TYPE_LABELS[vehicle.vehicle_type] || vehicle.vehicle_type}</Tag>
              <Tag capitalize>{String(vehicle.ownership_type).replace('_', ' ')}</Tag>
              <Tag capitalize>{String(vehicle.status).replace('_', ' ')}</Tag>
            </div>
            <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>{health.headline}</p>
          </div>

          <div className="flex items-center gap-2 shrink-0">
            <button onClick={() => setFuelOpen(true)}
              className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
              style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
              <Fuel size={13} /> Record fill
            </button>
            <button onClick={() => setJobOpen(true)}
              className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
              style={{ background: STOS_ACCENT, color: '#fff' }}>
              <Plus size={13} /> Job card
            </button>
          </div>
        </div>

        {/* ── Tabs ─────────────────────────────────────────────── */}
        <div className="flex gap-1 mt-4 overflow-x-auto" style={{ borderBottom: '1px solid var(--border)' }}>
          {TABS.map(({ key, label, icon: Icon }) => (
            <button key={key} onClick={() => setTab(key)}
              className="flex items-center gap-1.5 px-3 py-2 text-xs whitespace-nowrap"
              style={{
                borderBottom: tab === key ? `2px solid ${STOS_ACCENT}` : '2px solid transparent',
                color: tab === key ? 'var(--text-h)' : 'var(--text-muted)',
                fontWeight: tab === key ? 700 : 500,
              }}>
              <Icon size={13} /> {label}
              {key === 'workshop' && workshop.open_count > 0 && (
                <span className="text-[9px] font-bold px-1 rounded"
                  style={{ background: `color-mix(in srgb, ${STOS_ACCENT} 18%, transparent)`, color: STOS_ACCENT }}>
                  {workshop.open_count}
                </span>
              )}
            </button>
          ))}
        </div>
      </section>

      {/* ── Overview ───────────────────────────────────────────── */}
      {tab === 'overview' && (
        <>
          {health.issues?.length > 0 && (
            <Card title="Needs attention" icon={ShieldCheck}>
              <ExceptionPanel issues={health.issues} vehicleId={vehicle.id} />
            </Card>
          )}

          <Card title="Identity" icon={Truck}>
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
              <Fact label="Chassis" value={vehicle.chassis_number} />
              <Fact label="Engine" value={vehicle.engine_number} />
              <Fact label="GPS device" value={vehicle.gps_device_id} />
              <Fact label="Ownership" value={vehicle.ownership_type} />
            </div>
            {/* Driver comes from Dispatch, which owns trips and crew. Saying so
                beats an empty field labelled "Current driver". */}
            <p className="text-[10px] mt-2" style={{ color: 'var(--text-muted)' }}>
              Current driver and trip are Dispatch data — they appear here once that module lands.
            </p>
          </Card>

          <Card title="Assigned driver" icon={UserRound}>
            <AssignedDriverCard driver={driver} vehicle={vehicle} onChanged={() => refetch()} />
          </Card>

          <Card title="Live status" icon={Activity}>
            <LiveTelemetryGauge live={live} signal={signal} size="sm" />
          </Card>
        </>
      )}

      {/* ── Live telemetry ─────────────────────────────────────── */}
      {tab === 'telemetry' && (
        <>
          <Card title="Live status" icon={Activity}>
            <LiveTelemetryGauge live={live} signal={signal} />

            {gensets.length > 0 && (
              <div className="mt-3 flex flex-wrap gap-2">
                {gensets.map((g) => (
                  <span key={g.id} className="inline-flex items-center gap-1.5 text-[11px] px-2 py-1 rounded-lg"
                    style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
                    <Zap size={11} style={{ color: STOS_ACCENT }} /> Genset {g.serial_number} · {g.status}
                  </span>
                ))}
              </div>
            )}
          </Card>

          <Card title={`Recent readings (${telemetry.length})`} icon={FileText}>
            {telemetry.length === 0 ? <Empty>No readings recorded yet.</Empty> : (
              <div className="overflow-x-auto">
                <table className="w-full text-[11px]">
                  <thead>
                    <tr style={{ color: 'var(--text-muted)' }}>
                      <Th>Recorded</Th><Th>Speed</Th><Th>Temp</Th><Th>Genset</Th><Th>Ignition</Th><Th>Position</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {telemetry.slice(0, 20).map((t) => (
                      <tr key={t.id} style={{ borderTop: '1px solid var(--border)' }}>
                        <Td>{fmtWhen(t.recorded_at)}</Td>
                        <Td>{t.speed === null ? '—' : `${Number(t.speed).toFixed(0)} km/h`}</Td>
                        <Td>{t.temperature === null ? '—' : `${Number(t.temperature).toFixed(1)}°C`}</Td>
                        <Td>{t.generator_status ?? '—'}</Td>
                        <Td>{t.ignition === null ? '—' : (t.ignition ? 'on' : 'off')}</Td>
                        <Td>{t.latitude && t.longitude ? `${Number(t.latitude).toFixed(4)}, ${Number(t.longitude).toFixed(4)}` : '—'}</Td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>
        </>
      )}

      {/* ── Costs ──────────────────────────────────────────────── */}
      {tab === 'fuel' && (
        <>
          <Card title="Fuel" icon={Fuel}
            badges={[`${Number(fuel.total_litres).toFixed(1)} L`, fmtMoney(fuel.total_amount)]}
            action={<SmallButton onClick={() => setFuelOpen(true)} icon={Plus}>Record fill</SmallButton>}>
            {fuel.recent.length === 0 ? <Empty>No fills recorded.</Empty> : (
              <div className="space-y-1.5">
                {fuel.recent.map((f) => (
                  <div key={f.id} className="rounded-xl px-3 py-2" style={{ background: 'var(--bg-input)' }}>
                    <div className="flex items-center gap-2">
                      <div className="min-w-0 flex-1">
                        <p className="text-[11px] font-bold truncate" style={{ color: 'var(--text-h)' }}>
                          {f.station_vendor || 'Unnamed station'}
                        </p>
                        <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
                          {Number(f.litres).toFixed(1)} L @ {fmtMoney(f.rate_per_litre)}
                          {f.odometer ? ` · ${Number(f.odometer).toLocaleString('en-IN')} km` : ''}
                          {f.efficiency_kmpl ? ` · ${Number(f.efficiency_kmpl).toFixed(2)} km/l` : ''}
                        </p>
                      </div>
                      {f.is_emergency && <Flag tone="var(--color-warning-500, #f59e0b)">emergency</Flag>}
                      {f.fuel_exception && <Flag tone="var(--color-danger-500)">variance</Flag>}
                      {f.receipt_path && (
                        <a href={`/api${stosApi.fuel.receiptUrl(f.id)}`} target="_blank" rel="noreferrer"
                          className="text-[10px] font-semibold" style={{ color: STOS_ACCENT }}>
                          receipt
                        </a>
                      )}
                      <span className="text-xs font-bold shrink-0" style={{ color: 'var(--text-h)' }}>{fmtMoney(f.amount)}</span>
                    </div>

                    {/* Never a bare flag: say what was expected and what happened. */}
                    {f.variance_note && (
                      <p className="text-[10px] mt-1 flex items-start gap-1" style={{ color: 'var(--color-danger-500)' }}>
                        <AlertTriangle size={9} className="shrink-0 mt-0.5" /> {f.variance_note}
                      </p>
                    )}
                    {f.emergency_reason && (
                      <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>
                        <span className="font-semibold">Emergency: </span>{f.emergency_reason}
                        {f.recovery_status === 'billable' ? ' · recoverable from customer' : ' · not yet assigned'}
                      </p>
                    )}
                  </div>
                ))}
              </div>
            )}
          </Card>

          <Card title="Urea / AdBlue" icon={Droplets}
            badges={[`${Number(urea?.total_litres ?? 0).toFixed(1)} L`, fmtMoney(urea?.total_amount)]}>
            {!urea?.recent?.length ? <Empty>No urea top-ups recorded.</Empty> : (
              <div className="space-y-1.5">
                {urea.recent.map((u) => (
                  <div key={u.id} className="flex items-center gap-2 rounded-xl px-3 py-2" style={{ background: 'var(--bg-input)' }}>
                    <div className="min-w-0 flex-1">
                      <p className="text-[11px] font-bold truncate" style={{ color: 'var(--text-h)' }}>
                        {u.station_vendor || 'Unnamed supplier'}
                      </p>
                      <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
                        {Number(u.litres).toFixed(1)} L
                        {u.litres_per_100km ? ` · ${Number(u.litres_per_100km).toFixed(2)} L/100km` : ''}
                        {u.odometer ? ` · ${Number(u.odometer).toLocaleString('en-IN')} km` : ''}
                      </p>
                    </div>
                    <span className="text-xs font-bold shrink-0" style={{ color: 'var(--text-h)' }}>{fmtMoney(u.amount)}</span>
                  </div>
                ))}
              </div>
            )}
          </Card>

          <Card title="FASTag" icon={Receipt}
            badges={[fmtMoney(tolls.total_amount), ...(tolls.unreconciled > 0 ? [`${tolls.unreconciled} unreconciled`] : [])]}>
            {tolls.recent.length === 0 ? <Empty>No toll crossings recorded.</Empty> : (
              <div className="space-y-1.5">
                {tolls.recent.map((t) => (
                  <div key={t.id} className="flex items-center gap-2 rounded-xl px-3 py-2" style={{ background: 'var(--bg-input)' }}>
                    <div className="min-w-0 flex-1">
                      <p className="text-[11px] font-bold truncate" style={{ color: 'var(--text-h)' }}>{t.plaza_name || 'Unnamed plaza'}</p>
                      <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{fmtWhen(t.transaction_timestamp)}</p>
                    </div>
                    {t.reconciliation_status === 'unreconciled' && <Flag tone="var(--color-warning-500, #f59e0b)">unreconciled</Flag>}
                    <span className="text-xs font-bold shrink-0" style={{ color: 'var(--text-h)' }}>{fmtMoney(t.amount)}</span>
                  </div>
                ))}
              </div>
            )}
          </Card>
        </>
      )}

      {/* ── Maintenance ────────────────────────────────────────── */}
      {tab === 'workshop' && (
        <Card title="Job cards" icon={Wrench}
          badges={[`${workshop.open_count} open`, `${fmtMoney(workshop.total_cost)} lifetime`]}
          action={<SmallButton onClick={() => setJobOpen(true)} icon={Plus}>Open job card</SmallButton>}>
          {workshop.jobs.length === 0 ? <Empty>No job cards have been raised for this vehicle.</Empty> : (
            <div className="space-y-2">
              {workshop.jobs.map((j) => (
                <div key={j.id} className="rounded-xl p-3" style={{ background: 'var(--bg-input)' }}>
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>{j.job_card_number}</span>
                    <Tag capitalize>{String(j.status).replace('_', ' ')}</Tag>
                    {j.is_safety_critical && <Flag tone="var(--color-danger-500)">safety-critical</Flag>}
                    <span className="ml-auto text-xs font-bold" style={{ color: STOS_ACCENT }}>{fmtMoney(j.total_cost)}</span>
                  </div>
                  {j.complaint && <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>{j.complaint}</p>}
                  {j.diagnosis && (
                    <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>
                      <span className="font-semibold">Found: </span>{j.diagnosis}
                    </p>
                  )}
                  <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>
                    Parts {fmtMoney(j.parts_cost)} · Labour {fmtMoney(j.labour_cost)}
                    {j.closed_at ? ` · closed ${fmtWhen(j.closed_at)}` : ''}
                  </p>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      {tab === 'workshop' && (
        <Card title="Tyres" icon={Disc3}>
          <TyrePanel tyres={tyres} vehicle={vehicle} onChanged={() => refetch()} />
        </Card>
      )}

      {/* ── Compliance ─────────────────────────────────────────── */}
      {tab === 'compliance' && (
        <>
          <Card title="Compliance & permits" icon={ShieldCheck}>
            <CompliancePanel compliance={compliance} vehicle={vehicle} />
          </Card>

          {/* The gate has two halves: the vehicle's papers and the driver's
              licence. Showing them apart is how one of them gets forgotten. */}
          <Card title="Driver licence" icon={UserRound}>
            <AssignedDriverCard driver={driver} vehicle={vehicle} onChanged={() => refetch()} />
          </Card>
        </>
      )}

      <FuelExpenseModal open={fuelOpen} onClose={() => setFuelOpen(false)} vehicle={vehicle} onSaved={() => refetch()} />
      <MaintenanceJobCardForm open={jobOpen} onClose={() => setJobOpen(false)} vehicle={vehicle} onSaved={() => refetch()} />
    </div>
  )
}

/* ── small pieces ─────────────────────────────────────────────── */

function BackLink() {
  return (
    <Link to="/app/stos/fleet" className="inline-flex items-center gap-1.5 text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>
      <ArrowLeft size={13} /> Back to fleet
    </Link>
  )
}

function Card({ title, icon: Icon, badges = [], action, children }) {
  return (
    <section className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <div className="flex items-center gap-2 flex-wrap mb-3">
        <Icon size={14} style={{ color: STOS_ACCENT }} />
        <h2 className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{title}</h2>
        {badges.map((b) => <Tag key={b}>{b}</Tag>)}
        {action && <span className="ml-auto">{action}</span>}
      </div>
      {children}
    </section>
  )
}

function SmallButton({ onClick, icon: Icon, children }) {
  return (
    <button onClick={onClick} className="flex items-center gap-1 text-[11px] font-bold px-2.5 py-1.5 rounded-xl"
      style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
      <Icon size={11} /> {children}
    </button>
  )
}

function Tag({ children, capitalize = false }) {
  return (
    <span className={`text-[10px] px-1.5 py-0.5 rounded ${capitalize ? 'capitalize' : ''}`}
      style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
      {children}
    </span>
  )
}

function Flag({ children, tone }) {
  return (
    <span className="text-[9px] font-bold px-1.5 py-0.5 rounded shrink-0"
      style={{ background: `color-mix(in srgb, ${tone} 15%, transparent)`, color: tone }}>
      {children}
    </span>
  )
}

function Fact({ label, value }) {
  return (
    <div className="rounded-xl p-2.5" style={{ background: 'var(--bg-input)' }}>
      <p className="text-[10px] font-semibold" style={{ color: 'var(--text-muted)' }}>{label}</p>
      <p className="text-xs font-bold mt-0.5 truncate capitalize" style={{ color: 'var(--text-h)' }} title={value || '—'}>
        {value || '—'}
      </p>
    </div>
  )
}

function Empty({ children }) {
  return <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{children}</p>
}

function Th({ children }) {
  return <th className="text-left font-semibold py-1.5 pr-3 whitespace-nowrap">{children}</th>
}

function Td({ children }) {
  return <td className="py-1.5 pr-3 whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>{children}</td>
}
