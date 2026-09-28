import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Disc3, Search, Plus, X, Check, TrendingDown, RefreshCw, Trash2, Info } from 'lucide-react'
import {
  stosApi, STOS_ACCENT, fmtMoney, TYRE_MASTER_STATUS_LABELS, TYRE_WEAR_BASIS,
} from '@/services/stosApi'
import HealthChip from '../components/HealthChip'

/**
 * The casing register (T-36 / T-37 / T-38).
 *
 * The passport's tyre panel answers "what is on this axle". This answers the
 * two questions that had no screen at all: what is in the store right now, and
 * what has each casing cost per kilometre across every truck it has been on
 * and every retread it has had.
 *
 * A casing outlives the vehicle it is fitted to — that is the whole economics
 * of retreading — so the casing, not the fitment, is the row here.
 *
 * Fitting and rotating happen on the vehicle, because they are about a
 * position on a particular truck. This screen deliberately has no "fit"
 * button: a second place to fit a tyre would be two places that disagree.
 */

const STATUS_TONE = { IN_STOCK: 'green', FITTED: 'blue', RETREADED: 'amber', SCRAPPED: 'grey' }

const FILTERS = [
  { value: '', label: 'All', count: 'total' },
  { value: 'IN_STOCK', label: 'In the store', count: 'in_stock' },
  { value: 'FITTED', label: 'Fitted', count: 'fitted' },
  { value: 'RETREADED', label: 'Back from retread', count: 'retreaded' },
  { value: 'SCRAPPED', label: 'Scrapped', count: 'scrapped' },
]

export default function TyresBoard() {
  const [term, setTerm] = useState('')
  const [status, setStatus] = useState('')
  const [adding, setAdding] = useState(false)
  const [economicsFor, setEconomicsFor] = useState(null)
  const [retreading, setRetreading] = useState(null)
  const [scrapping, setScrapping] = useState(null)

  const params = { ...(term ? { q: term } : {}), ...(status ? { status } : {}) }

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['stos-tyres', params],
    queryFn: () => stosApi.tyres.register(params),
  })

  // The counts come from the unfiltered list so the filter chips do not all
  // drop to zero the moment one of them is picked.
  const { data: all } = useQuery({
    queryKey: ['stos-tyres', {}],
    queryFn: () => stosApi.tyres.register({}),
  })

  const tyres = data?.tyres ?? []
  const counts = all?.counts ?? data?.counts

  return (
    <div className="max-w-5xl">
      <header className="flex flex-wrap items-center gap-2 mb-3">
        <span className="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: `color-mix(in srgb, ${STOS_ACCENT} 14%, transparent)` }}>
          <Disc3 size={17} style={{ color: STOS_ACCENT }} />
        </span>
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Tyres</h1>
        {counts && (
          <span className="text-xs px-2 py-0.5 rounded-lg" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
            {counts.total}
          </span>
        )}

        <div className="ml-auto flex items-center gap-2">
          <div className="relative">
            <Search size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
            <input value={term} onChange={(e) => setTerm(e.target.value)} placeholder="Serial number…"
              aria-label="Search tyres"
              className="text-xs rounded-xl pl-7 pr-3 py-2 w-44"
              style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text)' }} />
          </div>
          <button type="button" onClick={() => setAdding(true)}
            className="flex items-center gap-1 text-xs font-bold px-3 py-2 rounded-xl"
            style={{ background: STOS_ACCENT, color: '#fff' }}>
            <Plus size={13} /> Register casing
          </button>
        </div>
      </header>

      <p className="flex items-start gap-1.5 text-[11px] mb-3 rounded-xl px-3 py-2"
        style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
        <Info size={12} className="shrink-0 mt-0.5" style={{ color: STOS_ACCENT }} />
        <span>
          Every casing, wherever it is. Cost per kilometre adds up every truck a casing has been on and
          every retread it has had. Fitting, inspecting and rotating are done on the vehicle, from its
          passport.
        </span>
      </p>

      <div className="flex flex-wrap gap-1.5 mb-3">
        {FILTERS.map((f) => {
          const active = status === f.value
          return (
            <button key={f.value || 'all'} type="button" onClick={() => setStatus(f.value)}
              className="text-[11px] font-semibold px-2.5 py-1 rounded-lg"
              style={{
                background: active ? `color-mix(in srgb, ${STOS_ACCENT} 16%, transparent)` : 'var(--bg-input)',
                color: active ? STOS_ACCENT : 'var(--text-muted)',
                border: `1px solid ${active ? STOS_ACCENT : 'var(--border)'}`,
              }}>
              {f.label} {counts ? counts[f.count] ?? 0 : ''}
            </button>
          )
        })}
      </div>

      {isError && (
        <p className="text-xs px-3 py-2 rounded-lg mb-3"
          style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>
          {error?.message || 'Could not load the tyre register.'}
        </p>
      )}

      {isLoading && [0, 1, 2].map((i) => (
        <div key={i} className="rounded-2xl animate-pulse mb-2" style={{ height: 56, background: 'var(--bg-card)' }} />
      ))}

      {!isLoading && !isError && tyres.length === 0 && (
        <div className="rounded-2xl p-8 text-center" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <Disc3 size={20} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
          <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>
            {status || term ? 'Nothing matches that filter' : 'No casings on the register'}
          </p>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            A casing is added here when it is bought, or automatically the first time it is fitted.
          </p>
        </div>
      )}

      <div className="space-y-2">
        {tyres.map((t) => {
          const offTheRoad = t.status !== 'FITTED' && t.status !== 'SCRAPPED'
          return (
            <div key={t.id} className="rounded-2xl px-3 py-2.5"
              style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
              <div className="flex items-center gap-2 flex-wrap">
                <span className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{t.serial_number}</span>
                <HealthChip tone={STATUS_TONE[t.status] || 'grey'} size="sm">
                  {TYRE_MASTER_STATUS_LABELS[t.status] || t.status}
                </HealthChip>
                {(t.brand || t.size) && (
                  <span className="text-[10px] px-1.5 py-0.5 rounded"
                    style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
                    {[t.brand, t.size].filter(Boolean).join(' · ')}
                  </span>
                )}
                {t.retread_count > 0 && (
                  <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
                    retreaded {t.retread_count}×
                  </span>
                )}

                <div className="ml-auto flex items-center gap-1.5">
                  <RowButton icon={TrendingDown} onClick={() => setEconomicsFor(t)}>Cost &amp; wear</RowButton>
                  {offTheRoad && (
                    <>
                      <RowButton icon={RefreshCw} onClick={() => setRetreading(t)}>Retread</RowButton>
                      <RowButton icon={Trash2} danger onClick={() => setScrapping(t)}>Scrap</RowButton>
                    </>
                  )}
                </div>
              </div>

              <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>
                {t.fitted_to
                  ? <>On <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{t.fitted_to}</span>{t.position ? `, ${t.position.replace(/_/g, ' ')}` : ''}</>
                  : t.status === 'SCRAPPED'
                    ? `Scrapped${t.scrapped_on ? ' on ' + t.scrapped_on : ''}${t.scrap_reason ? ' — ' + t.scrap_reason : ''}`
                    : 'Not on a vehicle.'}
                {' · '}
                {/* A casing registered by being fitted has no purchase cost
                    yet. Said as a gap to fill, not shown as zero. */}
                {t.lifetime_cost != null ? `${fmtMoney(t.lifetime_cost)} spent so far` : 'purchase cost not recorded'}
              </p>
            </div>
          )
        })}
      </div>

      {adding && <RegisterDialog onClose={() => setAdding(false)} />}
      {economicsFor && <EconomicsDialog tyre={economicsFor} onClose={() => setEconomicsFor(null)} />}
      {retreading && <RetreadDialog tyre={retreading} onClose={() => setRetreading(null)} />}
      {scrapping && <ScrapDialog tyre={scrapping} onClose={() => setScrapping(null)} />}
    </div>
  )
}

function RowButton({ icon: Icon, onClick, children, danger }) {
  return (
    <button type="button" onClick={onClick}
      className="flex items-center gap-1 text-[11px] font-semibold px-2 py-1 rounded-lg"
      style={{ color: danger ? 'var(--color-danger-500)' : 'var(--text-muted)', border: '1px solid var(--border)' }}>
      <Icon size={11} /> {children}
    </button>
  )
}

/* ── Dialogs — each closes only via ✕ or Cancel, never a backdrop click ── */

function RegisterDialog({ onClose }) {
  const qc = useQueryClient()
  const [form, setForm] = useState({
    serial_number: '', brand: '', size: '', pattern: '',
    purchase_cost: '', purchase_date: '', supplier: '',
    new_tread_depth: '', scrap_tread_depth: '',
  })
  const [err, setErr] = useState('')
  const set = (k, v) => setForm((f) => ({ ...f, [k]: v }))

  const save = useMutation({
    mutationFn: () => stosApi.tyres.create(
      Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''))
    ),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['stos-tyres'] }); onClose?.() },
    onError: (e) => setErr(e?.message || 'Could not register that casing.'),
  })

  return (
    <Dialog title="Register a casing" onClose={onClose}>
      <div className="px-5 py-4 space-y-3 overflow-y-auto" style={{ maxHeight: '60vh' }}>
        <Field label="Serial number" hint="As stamped on the casing">
          <input value={form.serial_number} onChange={(e) => set('serial_number', e.target.value)}
            placeholder="e.g. APL2295012345" className={inputClass} style={inputStyle} />
        </Field>

        <div className="grid grid-cols-2 gap-3">
          <Field label="Brand">
            <input value={form.brand} onChange={(e) => set('brand', e.target.value)} className={inputClass} style={inputStyle} />
          </Field>
          <Field label="Size">
            <input value={form.size} onChange={(e) => set('size', e.target.value)}
              placeholder="295/80 R22.5" className={inputClass} style={inputStyle} />
          </Field>
          <Field label="Purchase cost (₹)">
            <input type="number" step="0.01" value={form.purchase_cost}
              onChange={(e) => set('purchase_cost', e.target.value)} className={inputClass} style={inputStyle} />
          </Field>
          <Field label="Purchased on">
            <input type="date" value={form.purchase_date}
              onChange={(e) => set('purchase_date', e.target.value)} className={inputClass} style={inputStyle} />
          </Field>
          <Field label="New tread (mm)">
            <input type="number" step="0.1" value={form.new_tread_depth}
              onChange={(e) => set('new_tread_depth', e.target.value)} className={inputClass} style={inputStyle} />
          </Field>
          {/* Without this the forecast can say how fast it is wearing but not
              when to act on it — so it is asked for here, not later. */}
          <Field label="Scrap at (mm)" hint="The depth you replace at">
            <input type="number" step="0.1" value={form.scrap_tread_depth}
              onChange={(e) => set('scrap_tread_depth', e.target.value)} className={inputClass} style={inputStyle} />
          </Field>
        </div>

        <Field label="Supplier">
          <input value={form.supplier} onChange={(e) => set('supplier', e.target.value)} className={inputClass} style={inputStyle} />
        </Field>

        <Problem err={err} />
      </div>
      <Footer onClose={onClose} onSave={() => { setErr(''); save.mutate() }} busy={save.isPending} label="Register" />
    </Dialog>
  )
}

function EconomicsDialog({ tyre, onClose }) {
  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['stos-tyre-economics', tyre.id],
    queryFn: () => stosApi.tyres.economics(tyre.id),
  })

  const wear = data?.wear
  const basisNote = wear ? TYRE_WEAR_BASIS[wear.basis] : null

  return (
    <Dialog title={`${tyre.serial_number} — cost and wear`} onClose={onClose}>
      <div className="px-5 py-4 space-y-3">
        {isLoading && <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>Working it out…</p>}
        <Problem err={isError ? (error?.message || 'Could not load the figures.') : ''} />

        {data && (
          <>
            <div className="grid grid-cols-2 gap-2">
              <Stat label="Distance run" value={`${Number(data.km_run).toLocaleString('en-IN')} km`} />
              <Stat label="Lives" value={data.lives === 1 ? 'Original' : `${data.lives} (${data.lives - 1} retread${data.lives > 2 ? 's' : ''})`} />
              <Stat label="Spent so far" value={data.lifetime_cost != null ? fmtMoney(data.lifetime_cost) : 'not recorded'} />
              {/* Null, not zero, when there is nothing to divide: 0.00 would
                  read as "free". */}
              <Stat label="Cost per km" value={data.cost_per_km != null ? `₹${Number(data.cost_per_km).toFixed(2)}` : 'not yet known'} />
            </div>

            <div className="rounded-xl p-3 space-y-1" style={{ background: 'var(--bg-input)' }}>
              <p className="text-[11px] font-bold" style={{ color: 'var(--text-h)' }}>Wear forecast</p>
              {wear?.mm_per_10000km != null && (
                <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                  Losing <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{wear.mm_per_10000km} mm</span> every 10,000 km
                  {wear.current_depth != null ? `, now at ${wear.current_depth} mm` : ''}.
                </p>
              )}
              {wear?.km_remaining != null && wear.basis === 'measured' && (
                <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                  About <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{Number(wear.km_remaining).toLocaleString('en-IN')} km</span> left
                  {wear.replace_by_km != null ? ` — replace by ${Number(wear.replace_by_km).toLocaleString('en-IN')} km on the odometer.` : '.'}
                </p>
              )}
              {/* The service names thin evidence instead of guessing; the
                  screen says which case it is instead of showing a blank. */}
              {basisNote && (
                <p className="text-[11px]" style={{ color: wear.basis === 'at_or_below_floor' ? 'var(--color-danger-500)' : 'var(--text-muted)' }}>
                  {basisNote}
                </p>
              )}
              <p className="text-[10px] pt-1" style={{ color: 'var(--text-muted)' }}>
                Worked out from the first and last tread readings and the distance between them.
              </p>
            </div>
          </>
        )}
      </div>
      <Footer onClose={onClose} />
    </Dialog>
  )
}

function RetreadDialog({ tyre, onClose }) {
  const qc = useQueryClient()
  const [cost, setCost] = useState('')
  const [depth, setDepth] = useState('')
  const [err, setErr] = useState('')

  const go = useMutation({
    mutationFn: () => stosApi.tyres.retread(tyre.id, {
      ...(cost !== '' ? { cost } : {}),
      ...(depth !== '' ? { new_tread_depth: depth } : {}),
    }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['stos-tyres'] }); onClose?.() },
    onError: (e) => setErr(e?.message || 'Could not record the retread.'),
  })

  return (
    <Dialog title={`Retread ${tyre.serial_number}`} onClose={onClose}>
      <div className="px-5 py-4 space-y-3">
        <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
          Records this casing's {ordinal(tyre.retread_count + 2)} life. The cost is added to what it has cost
          so far, so the cost per kilometre stays honest.
        </p>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Retread cost (₹)">
            <input type="number" step="0.01" value={cost} onChange={(e) => setCost(e.target.value)}
              className={inputClass} style={inputStyle} />
          </Field>
          <Field label="New tread (mm)">
            <input type="number" step="0.1" value={depth} onChange={(e) => setDepth(e.target.value)}
              className={inputClass} style={inputStyle} />
          </Field>
        </div>
        <Problem err={err} />
      </div>
      <Footer onClose={onClose} onSave={() => { setErr(''); go.mutate() }} busy={go.isPending} label="Record retread" />
    </Dialog>
  )
}

function ScrapDialog({ tyre, onClose }) {
  const qc = useQueryClient()
  const [reason, setReason] = useState('')
  const [err, setErr] = useState('')

  const go = useMutation({
    mutationFn: () => stosApi.tyres.scrap(tyre.id, reason.trim()),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['stos-tyres'] }); onClose?.() },
    onError: (e) => setErr(e?.message || 'Could not scrap that casing.'),
  })

  return (
    <Dialog title={`Scrap ${tyre.serial_number}`} onClose={onClose}>
      <div className="px-5 py-4 space-y-3">
        {/* The reason is required: a scrapped casing is money written off,
            and "why" is the only thing that makes the next purchase better. */}
        <Field label="Why is it being scrapped?">
          <input value={reason} onChange={(e) => setReason(e.target.value)} autoFocus
            placeholder="e.g. sidewall cut, beyond retread" className={inputClass} style={inputStyle} />
        </Field>
        <Problem err={err} />
      </div>
      <Footer onClose={onClose} busy={go.isPending} label="Scrap casing" danger
        onSave={() => { setErr(''); reason.trim() ? go.mutate() : setErr('Say why — it is money written off.') }} />
    </Dialog>
  )
}

/* ── Shell ──────────────────────────────────────────────────────── */

function Dialog({ title, onClose, children }) {
  return (
    <div className="fixed inset-0 z-[70] flex items-start justify-center p-4 pt-[10vh] bg-black/50">
      <div className="w-full max-w-3xl rounded-2xl overflow-hidden flex flex-col"
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

function Footer({ onClose, onSave, busy, label, danger }) {
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
          style={{ background: danger ? 'var(--color-danger-500)' : STOS_ACCENT, color: '#fff' }}>
          <Check size={13} /> {busy ? 'Saving…' : label}
        </button>
      )}
    </div>
  )
}

function Stat({ label, value }) {
  return (
    <div className="rounded-xl px-3 py-2" style={{ background: 'var(--bg-input)' }}>
      <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{label}</p>
      <p className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{value}</p>
    </div>
  )
}

function Problem({ err }) {
  if (!err) return null
  return (
    <p className="text-[11px] px-3 py-2 rounded-lg"
      style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>
      {err}
    </p>
  )
}

function Field({ label, hint, children }) {
  return (
    <div>
      <label className="text-[11px] font-bold block mb-1" style={{ color: 'var(--text-muted)' }}>{label}</label>
      {children}
      {hint && <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>{hint}</p>}
    </div>
  )
}

const ordinal = (n) => `${n}${['th', 'st', 'nd', 'rd'][(n % 100 > 10 && n % 100 < 14) ? 0 : (n % 10 < 4 ? n % 10 : 0)]}`

const inputClass = 'w-full text-xs rounded-xl px-3 py-2'
const inputStyle = { background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text)' }
