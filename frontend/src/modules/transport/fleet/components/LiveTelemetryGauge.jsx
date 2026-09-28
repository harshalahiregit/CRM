import { Gauge, Power, Navigation, Clock, Satellite, AlertTriangle, Key } from 'lucide-react'
import { STOS_ACCENT, SIGNALS, toneOf, fmtAgo, fmtWhen, GENSET_NOT_COOLING, GENSET_STATE_LABELS } from '@/services/stosApi'

/**
 * Live telemetry: the reefer dial, the genset, the speedometer and the signal.
 *
 * Progressive disclosure is enforced by the API, not guessed here: the backend
 * sends `is_reefer` and leaves temperature/generator NULL on a dry vehicle, so
 * a tipper never renders an empty -18°C dial it could never satisfy.
 *
 * The excursion banner uses the same rule the server raises the event on —
 * genset off, body warmer than target — so the screen and the alert log can
 * never disagree about whether the cold chain is breaking.
 */
export default function LiveTelemetryGauge({ live, signal = 'active', size = 'md' }) {
  if (!live) {
    return (
      <div className="rounded-xl p-4 text-center" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
        <Satellite size={18} className="mx-auto mb-1.5" style={{ color: 'var(--text-muted)' }} />
        <p className="text-xs font-semibold" style={{ color: 'var(--text-h)' }}>No signal</p>
        <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>
          This vehicle has never reported. Check that a device is fitted and powered.
        </p>
      </div>
    )
  }

  const target = live.target_temperature === null || live.target_temperature === undefined
    ? null : Number(live.target_temperature)
  const actual = live.temperature === null || live.temperature === undefined ? null : Number(live.temperature)
  // T-06 — OFF and FAULT both mean the load is not being cooled. A faulted
  // unit treated as running is how a breach goes unannounced on this gauge.
  const gensetOff = GENSET_NOT_COOLING.includes(live.generator_status)
  const breach = live.is_reefer && actual !== null && gensetOff && target !== null && actual > target

  const sig = SIGNALS[signal] || SIGNALS.active
  const compact = size === 'sm'

  return (
    <div className="space-y-2">
      {/* The cold chain is breaking right now — say so loudly, and say what to do. */}
      {breach && (
        <div
          className="flex items-start gap-2 rounded-xl px-3 py-2"
          style={{
            background: 'color-mix(in srgb, var(--color-danger-500) 14%, transparent)',
            border: '1px solid var(--color-danger-500)',
          }}
        >
          <AlertTriangle size={14} className="shrink-0 mt-0.5" style={{ color: 'var(--color-danger-500)' }} />
          <div className="min-w-0">
            <p className="text-xs font-bold" style={{ color: 'var(--color-danger-500)' }}>
              Temperature excursion — genset off at {actual.toFixed(1)}°C
            </p>
            <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
              {(actual - target).toFixed(1)}°C above the {target.toFixed(1)}°C set point. The load is warming with nothing cooling it — call the driver.
            </p>
          </div>
        </div>
      )}

      <div className="flex flex-wrap items-stretch gap-2">
        {live.is_reefer && <TemperatureDial target={target} actual={actual} breach={breach} />}

        <div className="grid grid-cols-2 sm:grid-cols-3 gap-2 flex-1 min-w-[220px]">
          <Cell icon={Gauge} label="Speed" tone={STOS_ACCENT} compact={compact}
            value={live.speed === null || live.speed === undefined ? '—' : `${Number(live.speed).toFixed(0)} km/h`} />

          {live.is_reefer && (
            <Cell icon={Power} label="Genset" compact={compact}
              tone={gensetOff ? 'var(--color-danger-500)' : 'var(--color-success-500)'}
              value={GENSET_STATE_LABELS[live.generator_status] || '—'} />
          )}

          <Cell icon={Key} label="Ignition" tone={STOS_ACCENT} compact={compact}
            value={live.ignition === null || live.ignition === undefined ? '—' : (live.ignition ? 'ON' : 'OFF')} />

          <Cell icon={Satellite} label="Signal" tone={toneOf(sig.tone).dot} compact={compact} value={sig.label} />

          <Cell icon={Navigation} label="Position" tone={STOS_ACCENT} compact={compact}
            value={live.latitude && live.longitude
              ? `${Number(live.latitude).toFixed(4)}, ${Number(live.longitude).toFixed(4)}`
              : 'No fix'} small />

          <Cell icon={Clock} label="Last report" tone={STOS_ACCENT} compact={compact}
            value={fmtAgo(live.last_ping_at)} title={fmtWhen(live.last_ping_at)} small />
        </div>
      </div>
    </div>
  )
}

/**
 * Target vs actual, as an arc.
 *
 * Plain SVG rather than a chart library: one dial does not justify a dependency,
 * and the team rule is that a new package gets discussed before it is installed.
 */
function TemperatureDial({ target, actual, breach }) {
  const MIN = -30
  const MAX = 10
  const clamp = (v) => Math.max(MIN, Math.min(MAX, v))
  const frac = (v) => (clamp(v) - MIN) / (MAX - MIN)

  // A 240° arc, starting bottom-left.
  const R = 44
  const CX = 54
  const CY = 54
  const START = 150
  const SWEEP = 240
  const pt = (angleDeg, radius = R) => {
    const rad = (angleDeg * Math.PI) / 180
    return [CX + radius * Math.cos(rad), CY + radius * Math.sin(rad)]
  }
  const arc = (fromFrac, toFrac, radius = R) => {
    const a0 = START + SWEEP * fromFrac
    const a1 = START + SWEEP * toFrac
    const [x0, y0] = pt(a0, radius)
    const [x1, y1] = pt(a1, radius)
    const large = Math.abs(a1 - a0) > 180 ? 1 : 0
    return `M ${x0} ${y0} A ${radius} ${radius} 0 ${large} 1 ${x1} ${y1}`
  }

  const tone = breach ? 'var(--color-danger-500)' : STOS_ACCENT
  const hasReading = actual !== null

  return (
    <div className="rounded-xl p-2 flex flex-col items-center justify-center shrink-0"
      style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', width: 128 }}>
      <svg viewBox="0 0 108 96" width="108" height="86" role="img"
        aria-label={hasReading ? `Body temperature ${actual} degrees, target ${target}` : 'No temperature reading'}>
        <path d={arc(0, 1)} fill="none" stroke="var(--border)" strokeWidth="9" strokeLinecap="round" />
        {hasReading && (
          <path d={arc(0, frac(actual))} fill="none" stroke={tone} strokeWidth="9" strokeLinecap="round" />
        )}
        {target !== null && (() => {
          const a = START + SWEEP * frac(target)
          const [x0, y0] = pt(a, R - 8)
          const [x1, y1] = pt(a, R + 8)
          return <line x1={x0} y1={y0} x2={x1} y2={y1} stroke="var(--text-muted)" strokeWidth="2" />
        })()}
        <text x={CX} y={CY + 2} textAnchor="middle" fontSize="19" fontWeight="700" fill={tone}>
          {hasReading ? `${actual.toFixed(1)}°` : '—'}
        </text>
        <text x={CX} y={CY + 17} textAnchor="middle" fontSize="9" fill="var(--text-muted)">
          {target !== null ? `target ${target.toFixed(1)}°` : 'body temp'}
        </text>
      </svg>
    </div>
  )
}

function Cell({ icon: Icon, label, value, tone, compact = false, small = false, title }) {
  return (
    <div className={`rounded-xl ${compact ? 'p-2' : 'p-2.5'}`}
      style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
      <div className="flex items-center gap-1.5 mb-1">
        <Icon size={11} style={{ color: tone }} />
        <span className="text-[10px] font-semibold truncate" style={{ color: 'var(--text-muted)' }}>{label}</span>
      </div>
      <p className={`font-bold truncate ${small ? 'text-[11px]' : 'text-sm'}`} style={{ color: tone }} title={title || String(value)}>
        {value}
      </p>
    </div>
  )
}
