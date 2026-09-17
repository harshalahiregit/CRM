import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { UserRound, IdCard, Phone, Building2, UserX, X, Check, Search } from 'lucide-react'
import { stosApi, STOS_ACCENT, fmtWhen } from '@/services/stosApi'
import HealthChip from './HealthChip'

/**
 * Who normally drives this vehicle, and whether they legally can.
 *
 * The name comes from the customer/vendor directory and the licence from the
 * STOS overlay — the same split as everywhere else, shown here as one answer
 * because a dispatcher asks one question: can this pairing go out today.
 *
 * The badge uses the same three states as every vehicle document, deliberately:
 * 🟢 valid · 🟡 unrecorded or expiring · 🔴 expired.
 */

const LICENCE_TONE = { valid: 'green', expiring: 'amber', unknown: 'amber', expired: 'red' }
const LICENCE_LABEL = { valid: 'Licence valid', expiring: 'Expiring soon', unknown: 'Licence unrecorded', expired: 'Licence expired' }

export default function AssignedDriverCard({ driver, vehicle, onChanged }) {
  const [picking, setPicking] = useState(false)
  const qc = useQueryClient()

  const unassign = useMutation({
    mutationFn: () => stosApi.drivers.assign(driver.source, driver.source_id, null),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['stos-vehicle'] }); onChanged?.() },
  })

  if (!driver) {
    return (
      <>
        <div className="flex items-center gap-3 rounded-xl px-3 py-3" style={{ background: 'var(--bg-input)' }}>
          <UserX size={15} style={{ color: 'var(--text-muted)' }} />
          <div className="min-w-0 flex-1">
            <p className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>No regular driver</p>
            <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
              This vehicle scores lower in allocation than one with a licensed driver assigned.
            </p>
          </div>
          <button onClick={() => setPicking(true)}
            className="text-[11px] font-bold px-2.5 py-1.5 rounded-xl shrink-0"
            style={{ background: STOS_ACCENT, color: '#fff' }}>
            Assign driver
          </button>
        </div>
        <DriverPicker open={picking} onClose={() => setPicking(false)} vehicle={vehicle} onChanged={onChanged} />
      </>
    )
  }

  const state = driver.licence?.state || 'unknown'

  return (
    <>
      <div className="rounded-xl p-3" style={{ background: 'var(--bg-input)' }}>
        <div className="flex items-start gap-3">
          <span className="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
            style={{ background: `color-mix(in srgb, ${STOS_ACCENT} 12%, transparent)` }}>
            <UserRound size={15} style={{ color: STOS_ACCENT }} />
          </span>

          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-1.5 flex-wrap">
              <span className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{driver.name}</span>
              <HealthChip tone={LICENCE_TONE[state]} size="sm">{LICENCE_LABEL[state]}</HealthChip>
              {driver.profile?.status && driver.profile.status !== 'available' && (
                <span className="text-[10px] px-1.5 py-0.5 rounded capitalize"
                  style={{ background: 'var(--bg-card)', color: 'var(--text-muted)' }}>
                  {driver.profile.status.replace('_', ' ')}
                </span>
              )}
            </div>

            <p className="flex items-center gap-2 text-[11px] mt-1 flex-wrap" style={{ color: 'var(--text-muted)' }}>
              {driver.employer && <span className="flex items-center gap-1"><Building2 size={10} /> {driver.employer}</span>}
              {driver.phone && <span className="flex items-center gap-1"><Phone size={10} /> {driver.phone}</span>}
            </p>

            <div className="grid grid-cols-2 sm:grid-cols-3 gap-2 mt-2">
              <Fact label="Licence number" value={driver.profile?.licence_number} />
              <Fact label="Class" value={driver.profile?.licence_class} />
              <Fact label="Expires"
                value={driver.profile?.licence_expiry ? fmtWhen(driver.profile.licence_expiry).split(',')[0] : null}
                tone={state === 'expired' ? 'var(--color-danger-500)' : undefined} />
            </div>

            {state !== 'valid' && (
              <p className="text-[11px] mt-2"
                style={{ color: state === 'expired' ? 'var(--color-danger-500)' : 'var(--text-muted)' }}>
                {driver.licence?.message}
              </p>
            )}

            {driver.in_directory === false && (
              <p className="text-[11px] mt-2" style={{ color: 'var(--color-warning-500, #f59e0b)' }}>
                This person is no longer in the customer directory — re-assign the vehicle to somebody current.
              </p>
            )}
          </div>

          <div className="flex flex-col gap-1.5 shrink-0">
            <button onClick={() => setPicking(true)}
              className="text-[11px] font-bold px-2.5 py-1.5 rounded-xl"
              style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
              Change
            </button>
            <button onClick={() => unassign.mutate()} disabled={unassign.isPending}
              className="text-[11px] font-semibold px-2.5 py-1.5 rounded-xl disabled:opacity-60"
              style={{ color: 'var(--text-muted)' }}>
              Remove
            </button>
          </div>
        </div>
      </div>

      <DriverPicker open={picking} onClose={() => setPicking(false)} vehicle={vehicle} onChanged={onChanged} />
    </>
  )
}

function Fact({ label, value, tone }) {
  return (
    <div className="rounded-lg px-2 py-1.5" style={{ background: 'var(--bg-card)' }}>
      <p className="text-[9px] font-semibold" style={{ color: 'var(--text-muted)' }}>{label}</p>
      <p className="text-[11px] font-bold truncate" style={{ color: tone || 'var(--text-h)' }}>{value || '—'}</p>
    </div>
  )
}

/**
 * Pick somebody from the directory. Closes only via ✕ or Cancel.
 *
 * The list is the CRM's — nobody is created here, which is why there is no
 * "add driver" button anywhere in Transport.
 */
function DriverPicker({ open, onClose, vehicle, onChanged }) {
  const qc = useQueryClient()
  const [term, setTerm] = useState('')
  const [err, setErr] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['stos-drivers', { q: term }],
    queryFn: () => stosApi.drivers.list(term ? { q: term } : {}),
    enabled: open,
  })

  const assign = useMutation({
    mutationFn: (person) => stosApi.drivers.assign(person.source, person.source_id, vehicle.id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['stos-vehicle'] })
      qc.invalidateQueries({ queryKey: ['stos-drivers'] })
      onChanged?.(); onClose?.()
    },
    onError: (e) => setErr(e?.message || 'Could not assign that driver.'),
  })

  if (!open) return null

  const people = data?.drivers ?? []

  return (
    <div className="fixed inset-0 z-[70] flex items-start justify-center p-4 pt-[10vh] bg-black/50">
      <div className="w-full max-w-md rounded-2xl overflow-hidden flex flex-col"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', maxHeight: '76vh' }}
        onKeyDown={(e) => { if (e.key === 'Escape') onClose?.() }}>

        <div className="flex items-start justify-between gap-3 px-5 pt-4 pb-3" style={{ borderBottom: '1px solid var(--border)' }}>
          <div>
            <h2 className="font-bold" style={{ color: 'var(--text-h)', fontSize: 14 }}>Assign a driver</h2>
            <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
              To {vehicle?.registration_number}, from the customer directory
            </p>
          </div>
          <button type="button" onClick={onClose} aria-label="Close" style={{ color: 'var(--text-muted)' }}>
            <X size={15} />
          </button>
        </div>

        <div className="px-5 py-3" style={{ borderBottom: '1px solid var(--border)' }}>
          <div className="relative">
            <Search size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
            <input value={term} onChange={(e) => setTerm(e.target.value)} placeholder="Search by name or phone…"
              autoFocus aria-label="Search the directory"
              className="w-full text-xs rounded-xl pl-7 pr-3 py-2"
              style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text)' }} />
          </div>
        </div>

        <div className="px-5 py-3 overflow-y-auto flex-1 space-y-1.5">
          {isLoading && <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>Loading the directory…</p>}

          {!isLoading && people.length === 0 && (
            <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
              Nobody matches. People are added under a customer or vendor in the CRM, not here.
            </p>
          )}

          {people.map((p) => {
            const state = p.licence?.state || 'unknown'
            const taken = p.assigned_vehicle_id && p.assigned_vehicle_id !== vehicle?.id

            return (
              <button key={p.ref} onClick={() => { setErr(''); assign.mutate(p) }} disabled={assign.isPending}
                className="w-full text-left rounded-xl px-3 py-2 disabled:opacity-60"
                style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <div className="flex items-center gap-2 flex-wrap">
                  <span className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>{p.name}</span>
                  <HealthChip tone={LICENCE_TONE[state]} size="sm">{LICENCE_LABEL[state]}</HealthChip>
                  {taken && (
                    <span className="text-[9px] font-bold px-1.5 py-0.5 rounded"
                      style={{ background: 'color-mix(in srgb, var(--color-warning-500, #f59e0b) 15%, transparent)', color: 'var(--color-warning-500, #f59e0b)' }}>
                      already on another vehicle
                    </span>
                  )}
                </div>
                <p className="text-[10px] mt-0.5" style={{ color: 'var(--text-muted)' }}>
                  {p.employer || 'No employer'} · {p.designation || 'no designation'} · {p.directory}
                </p>
              </button>
            )
          })}
        </div>

        {err && (
          <p className="text-xs px-5 py-2" style={{ color: 'var(--color-danger-500)' }}>{err}</p>
        )}

        <div className="flex items-center justify-between gap-2 px-5 py-3"
          style={{ background: 'var(--bg-input)', borderTop: '1px solid var(--border)' }}>
          <span className="flex items-center gap-1 text-[10px]" style={{ color: 'var(--text-muted)' }}>
            <IdCard size={11} /> Licences are recorded on the Drivers screen
          </span>
          <button type="button" onClick={onClose} className="text-xs font-semibold px-4 py-2 rounded-xl"
            style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
            <Check size={13} className="inline mr-1" /> Done
          </button>
        </div>
      </div>
    </div>
  )
}
