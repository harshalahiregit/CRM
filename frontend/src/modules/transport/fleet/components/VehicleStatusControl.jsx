import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Pause, Play, Archive, Info } from 'lucide-react'
import { stosApi, vehicleStatusLabel } from '@/services/stosApi'

/**
 * The one hand-driven edge of the asset state machine (T-56).
 *
 * Only three buttons, because only three states are a person's decision. A
 * truck enters and leaves the workshop through its job cards, goes on and off a
 * trip through dispatch, and is blocked and cleared by the compliance sweep.
 *
 * ── THE REFUSAL IS THE FEATURE ────────────────────────────────────────────
 * When the server says no it also says who can clear it — "closing its job card
 * is what releases it, and that checks QC and its papers first". That sentence
 * is more useful than the button, so it is shown in full rather than replaced
 * with "Action not allowed".
 */
export default function VehicleStatusControl({ vehicle, onChanged }) {
  const qc = useQueryClient()
  const [message, setMessage] = useState('')
  const [confirmRetire, setConfirmRetire] = useState(false)

  const set = useMutation({
    mutationFn: (status) => stosApi.fleet.setStatus(vehicle.id, status),
    onSuccess: () => {
      setMessage(''); setConfirmRetire(false)
      qc.invalidateQueries({ queryKey: ['stos-vehicle'] })
      qc.invalidateQueries({ queryKey: ['stos-fleet'] })
      onChanged?.()
    },
    // The server's sentence, not a generic one. It names the desk that owns it.
    onError: (e) => setMessage(e?.message || 'Could not change the status.'),
  })

  if (!vehicle) return null

  const status = vehicle.status
  const isAvailable = status === 'AVAILABLE'
  const isIdle = status === 'IDLE'
  const isRetired = status === 'RETIRED'

  return (
    <div className="space-y-2">
      <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
        Currently <span className="font-bold" style={{ color: 'var(--text-h)' }}>{vehicleStatusLabel(status)}</span>
      </p>

      {!isRetired && (
        <div className="flex items-center gap-2 flex-wrap">
          {!isIdle && (
            <Action icon={Pause} onClick={() => set.mutate('IDLE')} busy={set.isPending}>
              Park it
            </Action>
          )}

          {!isAvailable && (
            <Action icon={Play} onClick={() => set.mutate('AVAILABLE')} busy={set.isPending}>
              Bring back
            </Action>
          )}

          <Action icon={Archive} danger onClick={() => setConfirmRetire(true)} busy={set.isPending}>
            Retire
          </Action>
        </div>
      )}

      {/* Retiring is the one that cannot be undone from here. */}
      {confirmRetire && (
        <div className="rounded-xl p-3 space-y-2"
          style={{ background: 'var(--bg-input)', border: '1px solid var(--color-danger-500)' }}>
          <p className="text-[11px]" style={{ color: 'var(--text-h)' }}>
            Retire {vehicle.registration_number}? Its genset is freed and its history is kept, but
            it cannot be brought back from this screen.
          </p>
          <div className="flex items-center justify-end gap-2">
            <button type="button" onClick={() => setConfirmRetire(false)}
              className="text-[11px] font-semibold px-3 py-1.5 rounded-lg"
              style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
            <button type="button" onClick={() => set.mutate('RETIRED')} disabled={set.isPending}
              className="text-[11px] font-bold px-3 py-1.5 rounded-lg disabled:opacity-60"
              style={{ background: 'var(--color-danger-500)', color: '#fff' }}>Retire</button>
          </div>
        </div>
      )}

      {/* The server explains which desk owns the state it would not leave. */}
      {message && (
        <p className="flex items-start gap-1.5 text-[11px] px-3 py-2 rounded-lg"
          style={{ background: 'color-mix(in srgb, var(--color-warning-500, #f59e0b) 12%, transparent)', color: 'var(--text-h)' }}>
          <Info size={12} className="mt-0.5 shrink-0" />
          {message}
        </p>
      )}

      {isRetired && (
        <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
          Retired vehicles are kept for their history. Reinstating one is not done from here.
        </p>
      )}
    </div>
  )
}

function Action({ icon: Icon, children, onClick, busy, danger }) {
  return (
    <button type="button" onClick={onClick} disabled={busy}
      className="flex items-center gap-1.5 text-[11px] font-semibold px-3 py-1.5 rounded-lg disabled:opacity-60"
      style={{
        color: danger ? 'var(--color-danger-500)' : 'var(--text-h)',
        border: '1px solid var(--border)',
        background: 'var(--bg-input)',
      }}>
      <Icon size={11} /> {children}
    </button>
  )
}
