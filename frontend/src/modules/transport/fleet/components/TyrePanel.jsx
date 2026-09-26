import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Disc3, Plus, Ruler, LogOut, AlertTriangle, X, Check, ArrowLeftRight } from 'lucide-react'
import { stosApi, STOS_ACCENT, TYRE_POSITIONS, TYRE_POSITION_LABEL, TYRE_FITMENT_STATUS_LABELS, fmtWhen } from '@/services/stosApi'
import Select from '@/components/ui/Select'

/**
 * Tyres on this vehicle, and the casings that have come off it.
 *
 * A tyre is an asset with its own life, not a part of the truck: it moves
 * between vehicles, gets retreaded, and its cost is only meaningful per
 * kilometre run. So each row shows the distance it has covered, and removing
 * one asks where it went — stock, retreader, or scrap.
 *
 * Both dialogs close only via ✕ or Cancel — never a backdrop click.
 */
export default function TyrePanel({ tyres, vehicle, onChanged }) {
  const [fitting, setFitting] = useState(false)
  const [acting, setActing] = useState(null)      // { fitment, mode: 'inspect' | 'remove' }
  const [rotating, setRotating] = useState(false)

  if (!tyres) return null

  const { fitted = [], history = [], moves = [], due_replacement = 0, min_tread_mm } = tyres

  return (
    <div className="space-y-3">
      <div className="flex items-center gap-2 flex-wrap">
        <span className="text-[11px] font-semibold" style={{ color: 'var(--text-muted)' }}>
          {fitted.length} fitted · {history.length} taken off
        </span>
        {due_replacement > 0 && (
          <span className="inline-flex items-center gap-1 text-[10px] font-bold px-1.5 py-0.5 rounded"
            style={{ background: 'color-mix(in srgb, var(--color-danger-500) 14%, transparent)', color: 'var(--color-danger-500)' }}>
            <AlertTriangle size={9} /> {due_replacement} at or below {min_tread_mm} mm
          </span>
        )}
        {/* T-37 — two tyres swap positions in one act. Offered only when there
            are two to swap; one fitted tyre has nothing to rotate with. */}
        {fitted.length >= 2 && (
          <button onClick={() => setRotating(true)}
            className="ml-auto flex items-center gap-1 text-[11px] font-bold px-2.5 py-1.5 rounded-xl"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
            <ArrowLeftRight size={11} /> Rotate
          </button>
        )}
        <button onClick={() => setFitting(true)}
          className={`${fitted.length >= 2 ? '' : 'ml-auto '}flex items-center gap-1 text-[11px] font-bold px-2.5 py-1.5 rounded-xl`}
          style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>
          <Plus size={11} /> Fit tyre
        </button>
      </div>

      {fitted.length === 0 ? (
        <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
          No tyres recorded on this vehicle yet.
        </p>
      ) : (
        <div className="space-y-1.5">
          {fitted.map((t) => (
            <div key={t.id} className="rounded-xl px-3 py-2"
              style={{
                background: 'var(--bg-input)',
                border: t.worn_out ? '1px solid var(--color-danger-500)' : '1px solid transparent',
              }}>
              <div className="flex items-center gap-2 flex-wrap">
                <Disc3 size={13} style={{ color: t.worn_out ? 'var(--color-danger-500)' : STOS_ACCENT }} />
                <span className="text-[11px] font-bold" style={{ color: 'var(--text-h)' }}>{t.tyre_id}</span>
                <span className="text-[10px] px-1.5 py-0.5 rounded capitalize"
                  style={{ background: 'var(--bg-card)', color: 'var(--text-muted)' }}>
                  {TYRE_POSITION_LABEL(t.position)}
                </span>
                {t.tread_depth !== null && (
                  <span className="text-[10px] font-bold px-1.5 py-0.5 rounded"
                    style={{
                      background: t.worn_out
                        ? 'color-mix(in srgb, var(--color-danger-500) 14%, transparent)'
                        : 'var(--bg-card)',
                      color: t.worn_out ? 'var(--color-danger-500)' : 'var(--text-muted)',
                    }}>
                    {Number(t.tread_depth).toFixed(1)} mm
                  </span>
                )}

                <button onClick={() => setActing({ fitment: t, mode: 'inspect' })}
                  className="ml-auto flex items-center gap-1 text-[10px] font-semibold" style={{ color: STOS_ACCENT }}>
                  <Ruler size={10} /> Inspect
                </button>
                <button onClick={() => setActing({ fitment: t, mode: 'remove' })}
                  className="flex items-center gap-1 text-[10px] font-semibold" style={{ color: 'var(--text-muted)' }}>
                  <LogOut size={10} /> Remove
                </button>
              </div>

              <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>
                Fitted {t.fitted_on ? fmtWhen(t.fitted_on).split(',')[0] : '—'}
                {t.odometer_at_fitment ? ` at ${Number(t.odometer_at_fitment).toLocaleString('en-IN')} km` : ''}
                {t.inspected_on ? ` · last checked ${fmtWhen(t.inspected_on).split(',')[0]}` : ' · never inspected'}
              </p>

              {t.worn_out && (
                <p className="text-[10px] mt-1 font-semibold" style={{ color: 'var(--color-danger-500)' }}>
                  At or below the {min_tread_mm} mm legal limit — replace before the next trip.
                </p>
              )}
            </div>
          ))}
        </div>
      )}

      {history.length > 0 && (
        <details>
          <summary className="text-[11px] font-bold cursor-pointer" style={{ color: 'var(--text-muted)' }}>
            Removed casings ({history.length})
          </summary>
          <div className="space-y-1 mt-2">
            {history.map((t) => (
              <div key={t.id} className="flex items-center gap-2 rounded-xl px-3 py-1.5" style={{ background: 'var(--bg-input)' }}>
                <span className="text-[11px] font-bold" style={{ color: 'var(--text-h)' }}>{t.tyre_id}</span>
                <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
                  {TYRE_FITMENT_STATUS_LABELS[t.status] || t.status}
                </span>
                <span className="ml-auto text-[10px]" style={{ color: 'var(--text-muted)' }}>
                  {t.km_run !== null ? `${Number(t.km_run).toLocaleString('en-IN')} km run` : 'distance not recorded'}
                </span>
              </div>
            ))}
          </div>
        </details>
      )}

      {/* T-37 — where tyres that are STILL on this vehicle used to sit. Kept
          apart from "taken off" so a rotation never reads as a removal. */}
      {moves.length > 0 && (
        <details>
          <summary className="text-[11px] font-bold cursor-pointer" style={{ color: 'var(--text-muted)' }}>
            Earlier positions ({moves.length})
          </summary>
          <div className="space-y-1 mt-2">
            {moves.map((t) => (
              <div key={t.id} className="flex items-center gap-2 rounded-xl px-3 py-1.5" style={{ background: 'var(--bg-input)' }}>
                <span className="text-[11px] font-bold" style={{ color: 'var(--text-h)' }}>{t.tyre_id}</span>
                <span className="text-[10px] capitalize" style={{ color: 'var(--text-muted)' }}>
                  was {TYRE_POSITION_LABEL(t.position)}
                </span>
                <span className="ml-auto text-[10px]" style={{ color: 'var(--text-muted)' }}>
                  {t.km_run !== null ? `${Number(t.km_run).toLocaleString('en-IN')} km there` : 'distance not recorded'}
                </span>
              </div>
            ))}
          </div>
        </details>
      )}

      <FitTyreDialog open={fitting} onClose={() => setFitting(false)} vehicle={vehicle} onSaved={onChanged} />
      <TyreActionDialog acting={acting} onClose={() => setActing(null)} onSaved={onChanged} />
      {rotating && (
        <RotateDialog fitted={fitted} onClose={() => setRotating(false)} onSaved={onChanged} />
      )}
    </div>
  )
}

function FitTyreDialog({ open, onClose, vehicle, onSaved }) {
  const qc = useQueryClient()
  const [form, setForm] = useState({ tyre_id: '', position: 'front_left', tread_depth: '', odometer_at_fitment: '' })
  const [err, setErr] = useState('')

  const save = useMutation({
    mutationFn: () => stosApi.tyres.fit({
      vehicle_id: vehicle.id,
      tyre_id: form.tyre_id.trim(),
      position: form.position,
      tread_depth: form.tread_depth || null,
      odometer_at_fitment: form.odometer_at_fitment || null,
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['stos-vehicle'] })
      setForm({ tyre_id: '', position: 'front_left', tread_depth: '', odometer_at_fitment: '' })
      onSaved?.(); onClose?.()
    },
    onError: (e) => setErr(e?.message || 'Could not fit that tyre.'),
  })

  if (!open) return null

  return (
    <Dialog title="Fit a tyre" onClose={onClose} onSubmit={(e) => {
      e.preventDefault(); setErr('')
      if (form.tyre_id.trim().length < 2) return setErr('The tyre needs its casing number.')
      save.mutate()
    }} busy={save.isPending} err={err}>
      <Field label="Tyre / casing number *">
        <input value={form.tyre_id} onChange={(e) => setForm({ ...form, tyre_id: e.target.value })}
          placeholder="TY-00184" className={inputClass} style={inputStyle} autoFocus />
      </Field>
      <Field label="Position *">
        <Select size="sm" value={form.position} onChange={(v) => setForm({ ...form, position: v })}
          options={TYRE_POSITIONS.map((p) => ({ value: p, label: TYRE_POSITION_LABEL(p) }))} ariaLabel="Position" />
      </Field>
      <div className="grid grid-cols-2 gap-3">
        <Field label="Tread depth (mm)">
          <input type="number" step="0.1" value={form.tread_depth}
            onChange={(e) => setForm({ ...form, tread_depth: e.target.value })} className={inputClass} style={inputStyle} />
        </Field>
        <Field label="Odometer at fitting">
          <input type="number" step="0.1" value={form.odometer_at_fitment}
            onChange={(e) => setForm({ ...form, odometer_at_fitment: e.target.value })} className={inputClass} style={inputStyle} />
        </Field>
      </div>
      <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
        If this casing is fitted elsewhere it will be moved here, and anything currently in this position comes off.
      </p>
    </Dialog>
  )
}

function TyreActionDialog({ acting, onClose, onSaved }) {
  const qc = useQueryClient()
  const [value, setValue] = useState('')
  const [outcome, setOutcome] = useState('REMOVED')
  const [err, setErr] = useState('')

  const inspecting = acting?.mode === 'inspect'

  const save = useMutation({
    mutationFn: () => inspecting
      ? stosApi.tyres.inspect(acting.fitment.id, { tread_depth: value })
      : stosApi.tyres.remove(acting.fitment.id, { status: outcome, odometer_at_removal: value || null }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['stos-vehicle'] })
      setValue(''); onSaved?.(); onClose?.()
    },
    onError: (e) => setErr(e?.message || 'Could not save that.'),
  })

  if (!acting) return null

  return (
    <Dialog
      title={inspecting ? `Inspect ${acting.fitment.tyre_id}` : `Remove ${acting.fitment.tyre_id}`}
      onClose={onClose}
      onSubmit={(e) => {
        e.preventDefault(); setErr('')
        if (inspecting && !value) return setErr('Record the tread depth you measured.')
        save.mutate()
      }}
      busy={save.isPending}
      err={err}
    >
      {inspecting ? (
        <Field label="Tread depth (mm) *" hint="Tread only goes down — a deeper reading is refused">
          <input type="number" step="0.1" value={value} onChange={(e) => setValue(e.target.value)}
            className={inputClass} style={inputStyle} autoFocus />
        </Field>
      ) : (
        <>
          <Field label="Where is it going?">
            <Select size="sm" value={outcome} onChange={setOutcome} ariaLabel="Outcome"
              options={[
                { value: 'IN_STOCK', label: 'Back to stock' },
                { value: 'RETREADED', label: 'To the retreader' },
                { value: 'SCRAPPED', label: 'Scrapped' },
                { value: 'REMOVED', label: 'Removed (undecided)' },
              ]} />
          </Field>
          <Field label="Odometer at removal" hint="What makes cost-per-kilometre answerable for this casing">
            <input type="number" step="0.1" value={value} onChange={(e) => setValue(e.target.value)}
              className={inputClass} style={inputStyle} />
          </Field>
        </>
      )}
    </Dialog>
  )
}

/**
 * T-37 — swap two fitted tyres, in one operation.
 *
 * Not "remove both, fit both": doing it as four acts writes two rows that are
 * lies — the casings never went into the store — and the cost per kilometre
 * would count a swap as two new fittings. The server closes and reopens both
 * at one odometer; this dialog only has to name the pair.
 */
function RotateDialog({ fitted, onClose, onSaved }) {
  const qc = useQueryClient()
  const [first, setFirst] = useState(String(fitted[0]?.id ?? ''))
  const [second, setSecond] = useState(String(fitted[1]?.id ?? ''))
  const [odometer, setOdometer] = useState('')
  const [err, setErr] = useState('')

  const options = fitted.map((t) => ({
    value: String(t.id), label: `${TYRE_POSITION_LABEL(t.position)} — ${t.tyre_id}`,
  }))

  const save = useMutation({
    mutationFn: () => stosApi.tyres.rotate(Number(first), Number(second), odometer || null),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['stos-vehicle'] })
      qc.invalidateQueries({ queryKey: ['stos-tyres'] })
      onSaved?.(); onClose?.()
    },
    onError: (e) => setErr(e?.message || 'Could not rotate those tyres.'),
  })

  return (
    <Dialog title="Rotate two tyres" onClose={onClose} busy={save.isPending} err={err}
      onSubmit={(e) => {
        e.preventDefault(); setErr('')
        if (first === second) return setErr('Choose two different tyres.')
        save.mutate()
      }}>
      <Field label="This tyre">
        <Select size="sm" value={first} onChange={setFirst} options={options} ariaLabel="First tyre" />
      </Field>
      <Field label="Swaps with">
        <Select size="sm" value={second} onChange={setSecond} options={options} ariaLabel="Second tyre" />
      </Field>
      <Field label="Odometer now" hint="Both tyres change position at this reading, so the distance each ran stays exact">
        <input type="number" step="0.1" value={odometer} onChange={(e) => setOdometer(e.target.value)}
          className={inputClass} style={inputStyle} />
      </Field>
    </Dialog>
  )
}

/* ── shared dialog chrome ─────────────────────────────────────── */

function Dialog({ title, children, onClose, onSubmit, busy, err }) {
  return (
    <div className="fixed inset-0 z-[70] flex items-start justify-center p-4 pt-[14vh] bg-black/50">
      <form onSubmit={onSubmit}
        className="w-full max-w-lg rounded-2xl overflow-hidden"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}
        onKeyDown={(e) => { if (e.key === 'Escape') onClose?.() }}>
        <div className="flex items-start justify-between gap-3 px-5 pt-4 pb-3" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="font-bold" style={{ color: 'var(--text-h)', fontSize: 14 }}>{title}</h2>
          <button type="button" onClick={onClose} aria-label="Close" style={{ color: 'var(--text-muted)' }}>
            <X size={15} />
          </button>
        </div>

        <div className="px-5 py-4 space-y-3">
          {children}
          {err && (
            <p className="text-xs px-3 py-2 rounded-lg"
              style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>
              {err}
            </p>
          )}
        </div>

        <div className="flex items-center justify-end gap-2 px-5 py-3"
          style={{ background: 'var(--bg-input)', borderTop: '1px solid var(--border)' }}>
          <button type="button" onClick={onClose} className="text-xs font-semibold px-4 py-2 rounded-xl"
            style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
          <button type="submit" disabled={busy}
            className="flex items-center gap-1.5 text-xs font-bold px-4 py-2 rounded-xl disabled:opacity-60"
            style={{ background: STOS_ACCENT, color: '#fff' }}>
            <Check size={13} /> {busy ? 'Saving…' : 'Save'}
          </button>
        </div>
      </form>
    </div>
  )
}

const inputClass = 'w-full text-xs rounded-xl px-3 py-2'
const inputStyle = { background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text)' }

function Field({ label, hint, children }) {
  return (
    <div>
      <label className="text-[11px] font-bold block mb-1" style={{ color: 'var(--text-muted)' }}>{label}</label>
      {children}
      {hint && <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>{hint}</p>}
    </div>
  )
}
