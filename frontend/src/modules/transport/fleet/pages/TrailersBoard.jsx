import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Container, Search, Plus, X, Check, Link2, Unlink, History, AlertTriangle } from 'lucide-react'
import {
  stosApi, STOS_ACCENT, TRAILER_TYPES, TRAILER_TYPE_LABELS,
  TRAILER_STATUS_LABELS, SETTABLE_TRAILER_STATUSES, fmtWhen,
} from '@/services/stosApi'
import Select from '@/components/ui/Select'
import HealthChip from '../components/HealthChip'

/**
 * The trailer register, and what is under which truck right now (T-54).
 *
 * A trailer is its own master. It has no engine, so nothing on this screen
 * talks about fuel, mileage or position — and there is no PUC field, because a
 * box on wheels emits nothing and asking for that certificate would ground a
 * legal trailer for a document it cannot have.
 *
 * ── THE COUPLING IS THE REASON THIS SCREEN EXISTS ─────────────────────────
 * "Which trailer is under MH12AB1234" is the easy question. The one people
 * actually ask is "which one was under it on the 14th", when a load spoils or
 * a claim is filed — so the history is one click from every row rather than
 * buried in a report.
 */

const STATUS_TONE = {
  AVAILABLE: 'green', COUPLED: 'blue', UNDER_MAINTENANCE: 'amber',
  COMPLIANCE_BLOCKED: 'red', RETIRED: 'grey',
}

export default function TrailersBoard() {
  const [term, setTerm] = useState('')
  const [type, setType] = useState('')
  const [adding, setAdding] = useState(false)
  const [coupling, setCoupling] = useState(null)
  const [historyFor, setHistoryFor] = useState(null)

  const params = { ...(term ? { q: term } : {}), ...(type ? { trailer_type: type } : {}) }

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['stos-trailers', params],
    queryFn: () => stosApi.trailers.list(params),
  })

  const trailers = data?.trailers ?? []
  const counts = data?.counts

  return (
    <div className="max-w-5xl">
      <header className="flex flex-wrap items-center gap-2 mb-3">
        <span className="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: `color-mix(in srgb, ${STOS_ACCENT} 14%, transparent)` }}>
          <Container size={17} style={{ color: STOS_ACCENT }} />
        </span>
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Trailers</h1>
        {counts && (
          <span className="text-xs px-2 py-0.5 rounded-lg" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
            {counts.total}
          </span>
        )}

        <div className="ml-auto flex items-center gap-2">
          <div className="w-40">
            <Select size="sm" value={type} onChange={setType} placeholder="Any type"
              options={[{ value: '', label: 'Any type' }, ...TRAILER_TYPES]} ariaLabel="Trailer type" />
          </div>
          <div className="relative">
            <Search size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
            <input value={term} onChange={(e) => setTerm(e.target.value)} placeholder="Registration…"
              aria-label="Search trailers"
              className="text-xs rounded-xl pl-7 pr-3 py-2 w-44"
              style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text)' }} />
          </div>
          <button type="button" onClick={() => setAdding(true)}
            className="flex items-center gap-1 text-xs font-bold px-3 py-2 rounded-xl"
            style={{ background: STOS_ACCENT, color: '#fff' }}>
            <Plus size={13} /> Register
          </button>
        </div>
      </header>

      <p className="flex items-start gap-1.5 text-[11px] mb-3 rounded-xl px-3 py-2"
        style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
        <Container size={12} className="shrink-0 mt-0.5" style={{ color: STOS_ACCENT }} />
        <span>
          A trailer is its own asset, not a kind of vehicle — it has its own registration, fitness,
          insurance and permit, and no PUC, because it has no engine. Coupling it to a tractor is
          recorded with a start and an end, so the pairing can be read back later.
        </span>
      </p>

      {counts && (counts.blocked > 0 || counts.off_road > 0) && (
        <div className="flex flex-wrap gap-2 mb-3">
          {counts.blocked > 0 && <Summary tone="red" count={counts.blocked} label="Papers lapsed" />}
          {counts.coupled > 0 && <Summary tone="blue" count={counts.coupled} label="Coupled" />}
          {counts.off_road > 0 && <Summary tone="amber" count={counts.off_road} label="Off the road" />}
        </div>
      )}

      {isError && (
        <p className="text-xs px-3 py-2 rounded-lg mb-3"
          style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>
          {error?.message || 'Could not load the trailer register.'}
        </p>
      )}

      {isLoading && [0, 1, 2].map((i) => (
        <div key={i} className="rounded-2xl animate-pulse mb-2" style={{ height: 56, background: 'var(--bg-card)' }} />
      ))}

      {!isLoading && !isError && trailers.length === 0 && (
        <div className="rounded-2xl p-8 text-center" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <Container size={20} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
          <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>No trailers on the register</p>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            Register one to record its papers and couple it to a tractor.
          </p>
        </div>
      )}

      <div className="space-y-2">
        {trailers.map((t) => (
          <div key={t.id} className="rounded-2xl px-3 py-2.5"
            style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
            <div className="flex items-center gap-2 flex-wrap">
              <span className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{t.trailer_number}</span>
              <HealthChip tone={STATUS_TONE[t.status] || 'grey'} size="sm">
                {TRAILER_STATUS_LABELS[t.status] || t.status}
              </HealthChip>
              <span className="text-[10px] px-1.5 py-0.5 rounded"
                style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
                {TRAILER_TYPE_LABELS[t.trailer_type] || t.trailer_type}
              </span>
              {t.capacity_tonnes != null && (
                <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{t.capacity_tonnes} t</span>
              )}
              {t.axles != null && (
                <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{t.axles} axles</span>
              )}

              <div className="ml-auto flex items-center gap-1.5">
                <button type="button" onClick={() => setHistoryFor(t)}
                  className="flex items-center gap-1 text-[11px] font-semibold px-2 py-1 rounded-lg"
                  style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
                  <History size={11} /> History
                </button>
                {t.coupled_to_id
                  ? <UncoupleButton trailer={t} />
                  : (
                    <button type="button" onClick={() => setCoupling(t)}
                      className="flex items-center gap-1 text-[11px] font-bold px-2 py-1 rounded-lg"
                      style={{ background: STOS_ACCENT, color: '#fff' }}>
                      <Link2 size={11} /> Couple
                    </button>
                  )}
              </div>
            </div>

            <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>
              {t.coupled_to
                ? <>Under <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{t.coupled_to}</span> since {fmtWhen(t.coupled_at)}</>
                : 'Not coupled.'}
            </p>

            {/* A lapsed trailer stops the truck it is under, so it says which
                paper and does not leave a reader guessing. */}
            {t.status === 'COMPLIANCE_BLOCKED' && (
              <p className="flex items-start gap-1.5 text-[11px] mt-1" style={{ color: 'var(--color-danger-500)' }}>
                <AlertTriangle size={11} className="shrink-0 mt-0.5" />
                A document has lapsed — any tractor this is coupled to cannot be dispatched.
              </p>
            )}
          </div>
        ))}
      </div>

      {adding && <TrailerDialog onClose={() => setAdding(false)} />}
      {coupling && <CoupleDialog trailer={coupling} onClose={() => setCoupling(null)} />}
      {historyFor && <HistoryDialog trailer={historyFor} onClose={() => setHistoryFor(null)} />}
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

function UncoupleButton({ trailer }) {
  const qc = useQueryClient()
  const [err, setErr] = useState('')

  const go = useMutation({
    mutationFn: () => stosApi.trailers.uncouple(trailer.id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['stos-trailers'] }),
    onError: (e) => setErr(e?.message || 'Could not uncouple that.'),
  })

  return (
    <>
      <button type="button" onClick={() => { setErr(''); go.mutate() }} disabled={go.isPending}
        className="flex items-center gap-1 text-[11px] font-semibold px-2 py-1 rounded-lg disabled:opacity-60"
        style={{ color: 'var(--text-h)', border: '1px solid var(--border)' }}>
        <Unlink size={11} /> {go.isPending ? 'Uncoupling…' : 'Uncouple'}
      </button>
      {err && <span className="text-[10px]" style={{ color: 'var(--color-danger-500)' }}>{err}</span>}
    </>
  )
}

/** Closes only via ✕ or Cancel — never a backdrop click. */
function CoupleDialog({ trailer, onClose }) {
  const qc = useQueryClient()
  const [vehicleId, setVehicleId] = useState('')
  const [err, setErr] = useState('')

  // The grid, not `eligible`: coupling records something that has already
  // happened in the yard, so a truck blocked for its own papers still has to
  // be nameable here. Refusing to record reality does not change it.
  const { data } = useQuery({
    queryKey: ['stos-fleet-for-coupling'],
    queryFn: () => stosApi.fleet.grid(),
  })

  const options = (data?.vehicles ?? []).map((v) => ({
    value: String(v.id), label: `${v.registration_number} · ${v.vehicle_type}`,
  }))

  const go = useMutation({
    mutationFn: () => stosApi.trailers.couple(trailer.id, Number(vehicleId)),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['stos-trailers'] }); onClose?.() },
    // The server's sentence names where the trailer already is, which is more
    // use than "could not couple".
    onError: (e) => setErr(e?.message || 'Could not couple that.'),
  })

  return (
    <Dialog title={`Couple ${trailer.trailer_number}`} onClose={onClose}>
      <div className="px-5 py-4 space-y-3">
        <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
          Recording a coupling that has already happened in the yard. A tractor already pulling
          something is refused — uncouple that first.
        </p>
        <Select size="sm" value={vehicleId} onChange={setVehicleId} placeholder="Choose a tractor"
          options={options} ariaLabel="Vehicle" />
        {err && (
          <p className="text-[11px] px-3 py-2 rounded-lg"
            style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>
            {err}
          </p>
        )}
      </div>
      <Footer onClose={onClose} onSave={() => { setErr(''); vehicleId ? go.mutate() : setErr('Choose a tractor first.') }}
        busy={go.isPending} label="Couple" />
    </Dialog>
  )
}

function HistoryDialog({ trailer, onClose }) {
  const { data, isLoading } = useQuery({
    queryKey: ['stos-trailer-history', trailer.id],
    queryFn: () => stosApi.trailers.history({ trailer_id: trailer.id }),
  })

  const rows = data ?? []

  return (
    <Dialog title={`${trailer.trailer_number} — coupling history`} onClose={onClose}>
      <div className="px-5 py-4 space-y-2 overflow-y-auto" style={{ maxHeight: '50vh' }}>
        <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
          Every tractor this trailer has been under. Kept whole — this is what answers "which
          trailer was on that truck" when a load spoils or a claim is filed.
        </p>

        {isLoading && <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>Loading…</p>}

        {!isLoading && rows.length === 0 && (
          <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>It has never been coupled.</p>
        )}

        {rows.map((r) => (
          <div key={r.id} className="rounded-xl px-3 py-2" style={{ background: 'var(--bg-input)' }}>
            <div className="flex items-center gap-2">
              <span className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>{r.registration_number}</span>
              {r.open && <HealthChip tone="blue" size="sm">Now</HealthChip>}
              {r.hours_coupled != null && (
                <span className="ml-auto text-[10px]" style={{ color: 'var(--text-muted)' }}>{r.hours_coupled} h</span>
              )}
            </div>
            <p className="text-[10px] mt-0.5" style={{ color: 'var(--text-muted)' }}>
              {fmtWhen(r.coupled_at)} → {r.uncoupled_at ? fmtWhen(r.uncoupled_at) : 'still coupled'}
              {r.reason ? ` · ${r.reason}` : ''}
            </p>
          </div>
        ))}
      </div>
      <Footer onClose={onClose} />
    </Dialog>
  )
}

function TrailerDialog({ onClose }) {
  const qc = useQueryClient()
  const [form, setForm] = useState({
    trailer_number: '', trailer_type: 'flatbed', ownership_type: 'OWNED',
    capacity_tonnes: '', axles: '', status: 'AVAILABLE',
    registration_expiry: '', fitness_expiry: '', insurance_expiry: '', permit_expiry: '',
  })
  const [err, setErr] = useState('')

  const set = (k, v) => setForm((f) => ({ ...f, [k]: v }))

  const save = useMutation({
    mutationFn: () => stosApi.trailers.create(
      Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v !== null))
    ),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['stos-trailers'] }); onClose?.() },
    onError: (e) => setErr(e?.message || 'Could not register that trailer.'),
  })

  return (
    <Dialog title="Register a trailer" onClose={onClose}>
      <div className="px-5 py-4 space-y-3 overflow-y-auto" style={{ maxHeight: '58vh' }}>
        <Field label="Registration number">
          <input value={form.trailer_number} onChange={(e) => set('trailer_number', e.target.value)}
            placeholder="MH12TR0001" className={inputClass} style={inputStyle} />
        </Field>

        <div className="grid grid-cols-2 gap-3">
          <Field label="Type">
            <Select size="sm" value={form.trailer_type} onChange={(v) => set('trailer_type', v)}
              options={TRAILER_TYPES} ariaLabel="Trailer type" />
          </Field>
          <Field label="Status">
            <Select size="sm" value={form.status} onChange={(v) => set('status', v)}
              options={SETTABLE_TRAILER_STATUSES} ariaLabel="Trailer status" />
          </Field>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <Field label="Payload (tonnes)">
            <input type="number" step="0.01" value={form.capacity_tonnes}
              onChange={(e) => set('capacity_tonnes', e.target.value)} className={inputClass} style={inputStyle} />
          </Field>
          <Field label="Axles">
            <input type="number" value={form.axles}
              onChange={(e) => set('axles', e.target.value)} className={inputClass} style={inputStyle} />
          </Field>
        </div>

        {/* FOUR dates, not five. There is no PUC field because a trailer has no
            engine, and offering one would invite somebody to record a
            certificate that cannot exist for it. */}
        <p className="text-[11px] font-bold pt-1" style={{ color: 'var(--text-muted)' }}>
          Papers — a lapsed one blocks any tractor it is coupled to
        </p>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Registration expires">
            <input type="date" value={form.registration_expiry}
              onChange={(e) => set('registration_expiry', e.target.value)} className={inputClass} style={inputStyle} />
          </Field>
          <Field label="Fitness expires">
            <input type="date" value={form.fitness_expiry}
              onChange={(e) => set('fitness_expiry', e.target.value)} className={inputClass} style={inputStyle} />
          </Field>
          <Field label="Insurance expires">
            <input type="date" value={form.insurance_expiry}
              onChange={(e) => set('insurance_expiry', e.target.value)} className={inputClass} style={inputStyle} />
          </Field>
          <Field label="Permit expires">
            <input type="date" value={form.permit_expiry}
              onChange={(e) => set('permit_expiry', e.target.value)} className={inputClass} style={inputStyle} />
          </Field>
        </div>

        {err && (
          <p className="text-[11px] px-3 py-2 rounded-lg"
            style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>
            {err}
          </p>
        )}
      </div>
      <Footer onClose={onClose} onSave={() => { setErr(''); save.mutate() }} busy={save.isPending} label="Register" />
    </Dialog>
  )
}

/* ── Shell ──────────────────────────────────────────────────────── */

function Dialog({ title, onClose, children }) {
  return (
    <div className="fixed inset-0 z-[70] flex items-start justify-center p-4 pt-[10vh] bg-black/50">
      <div className="w-full max-w-md rounded-2xl overflow-hidden flex flex-col"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', maxHeight: '84vh' }}
        onKeyDown={(e) => { if (e.key === 'Escape') onClose?.() }}>
        <div className="flex items-start justify-between gap-3 px-5 pt-4 pb-3" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="font-bold" style={{ color: 'var(--text-h)', fontSize: 14 }}>{title}</h2>
          <button type="button" onClick={onClose} aria-label="Close" style={{ color: 'var(--text-muted)' }}>
            <X size={15} />
          </button>
        </div>
        {children}
      </div>
    </div>
  )
}

function Footer({ onClose, onSave, busy, label }) {
  return (
    <div className="flex items-center justify-end gap-2 px-5 py-3"
      style={{ background: 'var(--bg-input)', borderTop: '1px solid var(--border)' }}>
      <button type="button" onClick={onClose} className="text-xs font-semibold px-4 py-2 rounded-xl"
        style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
        {onSave ? 'Cancel' : 'Close'}
      </button>
      {onSave && (
        <button type="button" onClick={onSave} disabled={busy}
          className="flex items-center gap-1.5 text-xs font-bold px-4 py-2 rounded-xl disabled:opacity-60"
          style={{ background: STOS_ACCENT, color: '#fff' }}>
          <Check size={13} /> {busy ? 'Saving…' : label}
        </button>
      )}
    </div>
  )
}

const inputClass = 'w-full text-xs rounded-xl px-3 py-2'
const inputStyle = { background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text)' }

function Field({ label, children }) {
  return (
    <div>
      <label className="text-[11px] font-bold block mb-1" style={{ color: 'var(--text-muted)' }}>{label}</label>
      {children}
    </div>
  )
}
