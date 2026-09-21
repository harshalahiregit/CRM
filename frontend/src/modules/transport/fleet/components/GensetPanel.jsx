import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Zap, Plus, Check, X, Unplug } from 'lucide-react'
import { stosApi, STOS_ACCENT, GENSET_STATUSES } from '@/services/stosApi'
import Select from '@/components/ui/Select'

/**
 * The power units on this vehicle, and the spares available to swap in (T-05).
 *
 * A genset is its own asset — serial, service life and history follow the UNIT,
 * not the trailer it happens to be bolted to today. So this panel does three
 * things a "genset serial" field on the vehicle could not: register a new unit,
 * move a spare across when one fails on the road, and take a unit off without
 * retiring it.
 */
export default function GensetPanel({ vehicle, gensets = [], onChanged }) {
  const qc = useQueryClient()
  const [adding, setAdding] = useState(false)
  const [serial, setSerial] = useState('')
  const [status, setStatus] = useState('active')
  const [err, setErr] = useState('')

  // Only fetched when the user opens the swap control — a passport should not
  // pull the whole register on every view.
  const [swapping, setSwapping] = useState(false)
  const spares = useQuery({
    queryKey: ['stos-gensets', 'spare'],
    queryFn: () => stosApi.gensets.register({ unfitted_only: 1 }),
    enabled: swapping,
  })

  const done = () => {
    qc.invalidateQueries({ queryKey: ['stos-gensets'] })
    onChanged?.()
  }

  const create = useMutation({
    mutationFn: () => stosApi.gensets.create({ serial_number: serial, status, vehicle_id: vehicle.id }),
    onSuccess: () => { setAdding(false); setSerial(''); setErr(''); done() },
    onError: (e) => setErr(e?.message || 'Could not register that unit.'),
  })

  const fit = useMutation({
    mutationFn: (id) => stosApi.gensets.fit(id, vehicle.id),
    onSuccess: () => { setSwapping(false); setErr(''); done() },
    onError: (e) => setErr(e?.message || 'Could not fit that unit.'),
  })

  const unfit = useMutation({
    mutationFn: (id) => stosApi.gensets.unfit(id),
    onSuccess: () => { setErr(''); done() },
    onError: (e) => setErr(e?.message || 'Could not remove that unit.'),
  })

  const isReefer = vehicle?.vehicle_type === 'reefer'

  return (
    <div className="space-y-2">
      {gensets.length === 0 ? (
        <p className="text-[11px]" style={{ color: isReefer ? 'var(--color-warning-500, #f59e0b)' : 'var(--text-muted)' }}>
          {isReefer
            ? 'No power unit on record. A reefer without a registered genset cannot have its temperature trail explained — either it was never registered, or it came off and nobody said so.'
            : 'No power unit fitted. Normal for a vehicle that is not a reefer.'}
        </p>
      ) : (
        gensets.map((g) => (
          <div key={g.id} className="flex items-center gap-2 rounded-xl px-3 py-2"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            <Zap size={13} style={{ color: STOS_ACCENT }} />
            <div className="min-w-0 flex-1">
              <p className="text-[11px] font-bold truncate" style={{ color: 'var(--text-h)' }}>{g.serial_number}</p>
              <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
                {GENSET_STATUSES.find((s) => s.value === g.status)?.label || g.status}
              </p>
            </div>
            <button type="button" onClick={() => unfit.mutate(g.id)} disabled={unfit.isPending}
              className="flex items-center gap-1 text-[10px] font-semibold px-2 py-1 rounded-lg disabled:opacity-60"
              style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
              <Unplug size={10} /> Take off
            </button>
          </div>
        ))
      )}

      {/* ── Register a new unit ─────────────────────────────────── */}
      {adding ? (
        <div className="rounded-xl p-3 space-y-2" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <input value={serial} onChange={(e) => setSerial(e.target.value)} placeholder="Serial number, e.g. GS-0014"
            className="w-full text-xs rounded-lg px-3 py-2"
            style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text)' }} />
          <Select size="sm" value={status} onChange={setStatus}
            options={GENSET_STATUSES.filter((s) => s.value !== 'retired')} ariaLabel="Genset status" />
          <div className="flex items-center justify-end gap-2">
            <button type="button" onClick={() => { setAdding(false); setErr('') }}
              className="text-[11px] font-semibold px-3 py-1.5 rounded-lg"
              style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
              Cancel
            </button>
            <button type="button" onClick={() => create.mutate()} disabled={create.isPending || serial.trim() === ''}
              className="flex items-center gap-1 text-[11px] font-bold px-3 py-1.5 rounded-lg disabled:opacity-60"
              style={{ background: STOS_ACCENT, color: '#fff' }}>
              <Check size={11} /> {create.isPending ? 'Saving…' : 'Register & fit'}
            </button>
          </div>
        </div>
      ) : swapping ? (
        <div className="rounded-xl p-3 space-y-2" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <div className="flex items-center gap-2">
            <span className="text-[11px] font-bold" style={{ color: 'var(--text-h)' }}>Spares in the yard</span>
            <button type="button" onClick={() => setSwapping(false)} aria-label="Close"
              className="ml-auto p-1 rounded" style={{ color: 'var(--text-muted)' }}>
              <X size={12} />
            </button>
          </div>

          {spares.isLoading && <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>Loading…</p>}

          {spares.data && spares.data.gensets.length === 0 && (
            <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
              No spare units. Register one instead.
            </p>
          )}

          {spares.data?.gensets.map((g) => (
            <button key={g.id} type="button" onClick={() => fit.mutate(g.id)} disabled={fit.isPending}
              className="w-full flex items-center gap-2 rounded-lg px-3 py-2 text-left disabled:opacity-60"
              style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
              <Zap size={12} style={{ color: STOS_ACCENT }} />
              <span className="text-[11px] font-bold" style={{ color: 'var(--text-h)' }}>{g.serial_number}</span>
              <span className="text-[10px] ml-auto" style={{ color: 'var(--text-muted)' }}>
                {GENSET_STATUSES.find((s) => s.value === g.status)?.label || g.status}
              </span>
            </button>
          ))}
        </div>
      ) : (
        <div className="flex items-center gap-2">
          <button type="button" onClick={() => { setAdding(true); setErr('') }}
            className="flex items-center gap-1 text-[11px] font-semibold" style={{ color: STOS_ACCENT }}>
            <Plus size={11} /> Register a unit
          </button>
          <button type="button" onClick={() => { setSwapping(true); setErr('') }}
            className="flex items-center gap-1 text-[11px] font-semibold" style={{ color: 'var(--text-muted)' }}>
            Fit a spare
          </button>
        </div>
      )}

      {err && (
        <p className="text-[11px] px-3 py-2 rounded-lg"
          style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>
          {err}
        </p>
      )}
    </div>
  )
}
