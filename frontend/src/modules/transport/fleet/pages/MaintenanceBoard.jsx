import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Wrench, ShieldAlert, CheckCircle2, ArrowRight } from 'lucide-react'
import { stosApi, STOS_ACCENT, JOB_STATUSES, fmtMoney, fmtWhen } from '@/services/stosApi'
import HealthChip from '../components/HealthChip'
import MaintenanceJobCardForm from '../components/MaintenanceJobCardForm'

/**
 * The workshop board (Feature 4).
 *
 * Safety-critical cards are pinned to the top regardless of age, because those
 * are the ones holding vehicles out of allocation — the rest is a queue, that
 * is a blockage.
 *
 * Closing a card happens here, and the result is honest about what happened:
 * a vehicle that could not be released says which hold stopped it.
 */
export default function MaintenanceBoard() {
  const [status, setStatus] = useState('')
  const [closing, setClosing] = useState(null)
  const [outcome, setOutcome] = useState(null)

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['stos-workshop', status],
    queryFn: () => stosApi.maintenance.board(status ? { status } : {}),
  })

  const jobs = [...(data?.jobs ?? [])].sort((a, b) => {
    const open = (j) => ['open', 'in_progress', 'awaiting_parts'].includes(j.status)
    // Safety first, then still-open, then most recent.
    if (a.is_safety_critical !== b.is_safety_critical) return a.is_safety_critical ? -1 : 1
    if (open(a) !== open(b)) return open(a) ? -1 : 1
    return b.id - a.id
  })

  return (
    <div className="max-w-5xl">
      <header className="flex flex-wrap items-center gap-2 mb-4">
        <span className="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: `color-mix(in srgb, ${STOS_ACCENT} 14%, transparent)` }}>
          <Wrench size={17} style={{ color: STOS_ACCENT }} />
        </span>
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Workshop</h1>
        {data && (
          <span className="text-xs px-2 py-0.5 rounded-lg" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
            {jobs.length}
          </span>
        )}
      </header>

      {data?.open_safety_critical > 0 && (
        <div className="flex items-start gap-2 rounded-xl px-3 py-2 mb-3"
          style={{
            background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)',
            border: '1px solid var(--color-danger-500)',
          }}>
          <ShieldAlert size={14} className="shrink-0 mt-0.5" style={{ color: 'var(--color-danger-500)' }} />
          <p className="text-xs font-bold" style={{ color: 'var(--color-danger-500)' }}>
            {data.open_safety_critical} safety-critical job{data.open_safety_critical === 1 ? '' : 's'} open — those vehicles cannot be allocated.
          </p>
        </div>
      )}

      <div className="flex flex-wrap gap-1.5 mb-4">
        {[{ value: '', label: 'All' }, ...JOB_STATUSES].map((s) => {
          const count = s.value ? (data?.counts?.[s.value] ?? 0) : Object.values(data?.counts ?? {}).reduce((a, b) => a + b, 0)
          const active = status === s.value
          return (
            <button key={s.value || 'all'} onClick={() => setStatus(s.value)}
              className="text-[11px] font-semibold px-2.5 py-1.5 rounded-xl"
              style={{
                background: active ? `color-mix(in srgb, ${STOS_ACCENT} 16%, transparent)` : 'var(--bg-input)',
                border: `1px solid ${active ? STOS_ACCENT : 'var(--border)'}`,
                color: active ? STOS_ACCENT : 'var(--text-muted)',
              }}>
              {s.label} <span className="opacity-70">{count}</span>
            </button>
          )
        })}
      </div>

      {/* What happened when a card was closed — including when the vehicle
          stayed put, and why. */}
      {outcome && (
        <div className="rounded-xl px-3 py-2 mb-3"
          style={{
            background: outcome.release.released
              ? 'color-mix(in srgb, var(--color-success-500, #10b981) 12%, transparent)'
              : 'color-mix(in srgb, var(--color-warning-500, #f59e0b) 12%, transparent)',
            border: `1px solid ${outcome.release.released ? 'var(--color-success-500, #10b981)' : 'var(--color-warning-500, #f59e0b)'}`,
          }}>
          <div className="flex items-start gap-2">
            <CheckCircle2 size={14} className="shrink-0 mt-0.5"
              style={{ color: outcome.release.released ? 'var(--color-success-500, #10b981)' : 'var(--color-warning-500, #f59e0b)' }} />
            <div className="min-w-0 flex-1">
              <p className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>
                {outcome.job.job_card_number} closed · {fmtMoney(outcome.job.total_cost)}
                {outcome.release.released ? ' — vehicle back on the road' : ' — vehicle still held'}
              </p>
              {outcome.release.holds?.map((h) => (
                <p key={h.code} className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>
                  {h.why} — {h.owner}.
                </p>
              ))}
            </div>
            <button onClick={() => setOutcome(null)} className="text-[10px] font-semibold" style={{ color: 'var(--text-muted)' }}>
              dismiss
            </button>
          </div>
        </div>
      )}

      {isError && (
        <p className="text-xs px-3 py-2 rounded-lg mb-3"
          style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>
          {error?.message || 'Could not load the workshop board.'}
        </p>
      )}

      {isLoading && [0, 1, 2].map((i) => (
        <div key={i} className="rounded-2xl animate-pulse mb-3" style={{ height: 96, background: 'var(--bg-card)' }} />
      ))}

      {!isLoading && !isError && jobs.length === 0 && (
        <div className="rounded-2xl p-10 text-center" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <Wrench size={22} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
          <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>Nothing in the workshop</p>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            Job cards are opened from a vehicle&apos;s passport.
          </p>
        </div>
      )}

      <div className="space-y-3">
        {jobs.map((j) => {
          const isOpen = ['open', 'in_progress', 'awaiting_parts'].includes(j.status)

          return (
            <section key={j.id} className="rounded-2xl p-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
              <div className="flex items-start gap-3">
                <span className="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
                  style={{ background: `color-mix(in srgb, ${STOS_ACCENT} 12%, transparent)` }}>
                  <Wrench size={15} style={{ color: STOS_ACCENT }} />
                </span>

                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-1.5 flex-wrap">
                    <span className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{j.job_card_number}</span>
                    {j.vehicle && (
                      <Link to={`/app/transport/fleet/vehicles/${j.vehicle.id}`} className="text-xs font-semibold hover:underline"
                        style={{ color: STOS_ACCENT }}>
                        {j.vehicle.registration_number}
                      </Link>
                    )}
                    <HealthChip tone={j.is_safety_critical && isOpen ? 'red' : isOpen ? 'amber' : 'green'} size="sm">
                      {String(j.status).replace('_', ' ')}
                    </HealthChip>
                    {j.is_safety_critical && (
                      <span className="inline-flex items-center gap-1 text-[9px] font-bold px-1.5 py-0.5 rounded"
                        style={{ background: 'color-mix(in srgb, var(--color-danger-500) 15%, transparent)', color: 'var(--color-danger-500)' }}>
                        <ShieldAlert size={9} /> SAFETY
                      </span>
                    )}
                  </div>

                  {j.complaint && <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>{j.complaint}</p>}
                  <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>
                    Parts {fmtMoney(j.parts_cost)} · Labour {fmtMoney(j.labour_cost)} · Total {fmtMoney(j.total_cost)}
                    {j.opened_at ? ` · opened ${fmtWhen(j.opened_at)}` : ''}
                  </p>
                </div>

                {isOpen && (
                  <button onClick={() => setClosing(j)}
                    className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl shrink-0"
                    style={{ background: STOS_ACCENT, color: '#fff' }}>
                    Close &amp; release <ArrowRight size={13} />
                  </button>
                )}
              </div>
            </section>
          )
        })}
      </div>

      <MaintenanceJobCardForm
        open={Boolean(closing)}
        onClose={() => setClosing(null)}
        vehicle={closing?.vehicle}
        job={closing}
        onSaved={(result) => { setOutcome(result); refetch() }}
      />
    </div>
  )
}
