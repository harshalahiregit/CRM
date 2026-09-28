import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { UserRound, Search, Database, IdCard, X, Check, Info, Building2, ArrowLeft } from 'lucide-react'
import { stosApi, STOS_ACCENT, LICENCE_CLASSES, SETTABLE_DRIVER_STATUSES, DRIVER_STATUS_LABELS } from '@/services/stosApi'
import Select from '@/components/ui/Select'
import HealthChip from '../components/HealthChip'
import DriverDocumentsPanel from '../components/DriverDocumentsPanel'
import DriverProfilePage from '../components/DriverProfilePage'

/**
 * Drivers — read live from the customer/vendor directory, never re-entered.
 *
 * Transport keeps no roll of drivers of its own. Add 40 workers under a vendor
 * in the CRM and they are on this screen the next time it loads: no import, no
 * sync, nothing to go stale. What STOS stores against each person is the thin
 * overlay this screen edits — licence and availability — because a customer
 * directory has no business holding a licence expiry, and Transport has no
 * business holding somebody's name.
 *
 * The banner names the directory in use, so nobody wonders why a person they
 * typed into the CRM is or is not here.
 */

const LICENCE_TONE = { valid: 'green', expiring: 'amber', expired: 'red', unknown: 'amber' }


/**
 * Out of Drivers and back to Fleet — D-148.
 *
 * Deliberately identical to `VehiclePassportView`'s `BackLink`: same target,
 * same icon, same words. Two sibling screens that behave differently is the
 * thing being fixed, so this is a copy of the established one and not an
 * improvement on it.
 */
function BackToFleet() {
  return (
    <Link to="/app/transport/fleet" className="inline-flex items-center gap-1.5 text-xs font-semibold mb-3"
      style={{ color: 'var(--text-muted)' }}>
      <ArrowLeft size={13} /> Back to fleet
    </Link>
  )
}

export default function DriversBoard() {
  const [term, setTerm] = useState('')
  const [driversOnly, setDriversOnly] = useState(false)
  const [editing, setEditing] = useState(null)
  const [viewing, setViewing] = useState(null)
  const [adding, setAdding] = useState(false)
  const [readyOnly, setReadyOnly] = useState(false)

  const params = { ...(term ? { q: term } : {}), ...(driversOnly ? { drivers_only: 1 } : {}) }

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['stos-drivers', params],
    queryFn: () => stosApi.drivers.list(params),
  })

  const qc = useQueryClient()

  // Drivers who signed up in the app and are waiting for the office to let them in.
  const registrations = useQuery({
    queryKey: ['stos-driver-registrations'],
    queryFn: () => stosApi.drivers.pendingRegistrations(),
    refetchInterval: 30000, // a new sign-up appears within half a minute
  })

  /**
   * Who can actually take a load right now.
   *
   * The same `{eligible, excluded[blockers]}` shape the vehicle picker uses, so
   * a dispatcher reads trucks and crew the same way — and each blocker names
   * the desk that can clear it rather than only saying "blocked".
   *
   * Fetched only when asked for: the list above is the directory, this is a
   * question about it.
   */
  const eligibility = useQuery({
    queryKey: ['stos-drivers-eligible', params],
    queryFn: () => stosApi.drivers.eligible(params),
    enabled: readyOnly,
  })

  const blockedBy = new Map(
    (eligibility.data?.excluded ?? []).map((d) => [d.ref, d.blockers])
  )

  const drivers = data?.drivers ?? []
  const counts = data?.counts

  return (
    <div className="max-w-5xl">
      {/* D-148 — the way out. The vehicle passport has had one since it was
          built; this screen, its sibling, had none, so adding a driver left
          you on a page with no route back to Fleet but the browser button.
          Same component and same wording as VehiclePassportView::BackLink,
          rather than a second pattern for two screens that sit side by side. */}
      <BackToFleet />

      <header className="flex flex-wrap items-center gap-2 mb-3">
        <span className="w-8 h-8 rounded-xl flex items-center justify-center shrink-0"
          style={{ background: `color-mix(in srgb, ${STOS_ACCENT} 14%, transparent)` }}>
          <UserRound size={17} style={{ color: STOS_ACCENT }} />
        </span>
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Drivers</h1>
        {counts && (
          <span className="text-xs px-2 py-0.5 rounded-lg" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
            {counts.total}
          </span>
        )}

        <button type="button" onClick={() => setAdding(true)}
          className="ml-auto flex items-center gap-1.5 text-[11px] font-bold px-3 py-1.5 rounded-xl"
          style={{ background: STOS_ACCENT, color: '#fff' }}>
          <UserRound size={13} /> Add driver
        </button>

        <label className="flex items-center gap-1.5 text-[11px] font-semibold cursor-pointer"
          style={{ color: 'var(--text-muted)' }}>
          <input type="checkbox" checked={driversOnly} onChange={(e) => setDriversOnly(e.target.checked)} />
          Drivers only
        </label>

        <div className="relative">
          <Search size={13} className="absolute left-2.5 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input value={term} onChange={(e) => setTerm(e.target.value)} placeholder="Name or phone…"
            aria-label="Search drivers"
            className="text-xs rounded-xl pl-7 pr-3 py-2 w-52"
            style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text)' }} />
        </div>
      </header>

      {/* Drivers waiting for the office to let them in (app sign-ups). */}
      {(registrations.data?.registrations?.length > 0) && (
        <PendingRegistrations
          rows={registrations.data.registrations}
          onChanged={() => { registrations.refetch(); qc.invalidateQueries({ queryKey: ['stos-drivers'] }) }} />
      )}

      {/* Where these people come from — stated, not assumed. */}
      {data?.directory && (
        <p className="flex items-start gap-1.5 text-[11px] mb-3 rounded-xl px-3 py-2"
          style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
          <Database size={12} className="shrink-0 mt-0.5" style={{ color: STOS_ACCENT }} />
          <span>
            {data.directory} Nobody is re-entered here — add a person under a customer or vendor in the CRM and they
            appear on this screen. Transport only stores their licence and availability.
          </span>
        </p>
      )}

      {/* The licence blocks the DRIVER, never the truck — Person 1's ruling.
          This is where that becomes visible: a name with a reason beside it,
          and the truck it usually drives stays fully allocatable. */}
      <label className="flex items-center gap-2 mb-3 cursor-pointer w-fit">
        <input type="checkbox" checked={readyOnly} onChange={(e) => setReadyOnly(e.target.checked)} />
        <span className="text-[11px] font-semibold" style={{ color: 'var(--text-h)' }}>
          Show who can take a load right now
        </span>
      </label>

      {readyOnly && eligibility.data && (
        <div className="rounded-xl p-3 mb-3" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <p className="text-[11px] font-bold mb-2" style={{ color: 'var(--text-h)' }}>
            {eligibility.data.counts.eligible} ready · {eligibility.data.counts.excluded} cannot be dispatched
          </p>

          {eligibility.data.excluded.map((d) => (
            <div key={d.ref} className="flex items-start gap-2 py-1">
              <span className="text-[11px] font-semibold shrink-0" style={{ color: 'var(--text-h)' }}>{d.name}</span>
              <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
                {d.blockers.map((b) => `${b.why} (${b.owner})`).join(' · ')}
              </span>
            </div>
          ))}

          {eligibility.data.excluded.length === 0 && (
            <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>Everyone on file can be dispatched.</p>
          )}
        </div>
      )}

      {/* The row shows when ANY of these is non-zero. It used to test only
          expired and unrecorded licences, so a fleet whose only problem was
          licences about to lapse never saw the "Expiring soon" chip at all.

          T-41 — the medical counts were already in the API and never reached
          the screen: every card said "no medical" while the summary said
          nothing, found by opening the page rather than by a test. Kept apart
          from the licence counts because they are different jobs — chasing a
          certificate nobody has captured, not one that has run out. */}
      {counts && [
        counts.licence_expired, counts.licence_expiring, counts.unlicensed,
        counts.medical_expired, counts.medical_expiring, counts.medical_unrecorded,
      ].some((n) => n > 0) && (
        <div className="flex flex-wrap gap-2 mb-3">
          {counts.licence_expired > 0 && <Summary tone="red" count={counts.licence_expired} label="Licence expired" />}
          {counts.licence_expiring > 0 && <Summary tone="amber" count={counts.licence_expiring} label="Licence expiring soon" />}
          {counts.unlicensed > 0 && <Summary tone="amber" count={counts.unlicensed} label="No licence recorded" />}
          {counts.medical_expired > 0 && <Summary tone="red" count={counts.medical_expired} label="Medical expired" />}
          {counts.medical_expiring > 0 && <Summary tone="amber" count={counts.medical_expiring} label="Medical expiring soon" />}
          {counts.medical_unrecorded > 0 && <Summary tone="amber" count={counts.medical_unrecorded} label="No medical recorded" />}
        </div>
      )}

      {driversOnly && (
        <p className="flex items-start gap-1.5 text-[10px] mb-3" style={{ color: 'var(--text-muted)' }}>
          <Info size={11} className="shrink-0 mt-0.5" />
          Designation is free text in the CRM directories, so this filters on keywords (driver, chauffeur, operator)
          rather than a structured field. Turn it off to see everyone.
        </p>
      )}

      {isError && (
        <p className="text-xs px-3 py-2 rounded-lg mb-3"
          style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>
          {error?.message || 'Could not load the driver directory.'}
        </p>
      )}

      {isLoading && [0, 1, 2].map((i) => (
        <div key={i} className="rounded-2xl animate-pulse mb-2" style={{ height: 64, background: 'var(--bg-card)' }} />
      ))}

      {!isLoading && !isError && drivers.length === 0 && (
        <div className="rounded-2xl p-10 text-center" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <UserRound size={22} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
          <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>Nobody in the directory</p>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            {term || driversOnly
              ? 'Nothing matches that filter.'
              : 'Add people under a customer or vendor in the CRM and they will appear here.'}
          </p>
        </div>
      )}

      <div className="space-y-2">
        {drivers.map((d) => (
          <section key={d.ref} className="rounded-2xl p-3" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
            <div className="flex items-center gap-3">
              <span className="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
                style={{ background: `color-mix(in srgb, ${STOS_ACCENT} 12%, transparent)` }}>
                <UserRound size={15} style={{ color: STOS_ACCENT }} />
              </span>

              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-1.5 flex-wrap">
                  <span className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{d.name}</span>
                  <HealthChip tone={LICENCE_TONE[d.licence.state]} size="sm">
                    {d.licence.state === 'unknown' ? 'no licence' : `licence ${d.licence.state}`}
                  </HealthChip>
                  {d.profile?.status && d.profile.status !== 'AVAILABLE' && (
                    <span className="text-[10px] px-1.5 py-0.5 rounded"
                      style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
                      {DRIVER_STATUS_LABELS[d.profile.status] || d.profile.status}
                    </span>
                  )}

                  {/* T-41 — shown beside the licence because a dispatcher asks
                      one question of both: can this person go out today? */}
                  {d.medical?.state && d.medical.state !== 'valid' && (
                    <HealthChip tone={LICENCE_TONE[d.medical.state]} size="sm">
                      {d.medical.state === 'unknown' ? 'no medical' : `medical ${d.medical.state}`}
                    </HealthChip>
                  )}
                  {d.designation && (
                    <span className="text-[10px] px-1.5 py-0.5 rounded" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
                      {d.designation}
                    </span>
                  )}
                </div>

                <p className="flex items-center gap-1 text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>
                  {d.employer && <><Building2 size={10} /> {d.employer} ·</>}
                  {d.phone || 'no phone'} · from {d.directory}
                </p>

                {d.licence.state !== 'valid' && (
                  <p className="text-[11px] mt-1"
                    style={{ color: d.licence.state === 'expired' ? 'var(--color-danger-500)' : 'var(--text-muted)' }}>
                    {d.licence.message}
                  </p>
                )}
              </div>

              <button onClick={() => (d.profile ? setViewing(d) : setEditing(d))}
                className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl shrink-0"
                style={{ background: d.profile ? 'var(--bg-input)' : STOS_ACCENT, color: d.profile ? 'var(--text-h)' : '#fff', border: d.profile ? '1px solid var(--border)' : 'none' }}>
                <IdCard size={13} /> {d.profile ? 'Open profile' : 'Add licence'}
              </button>
            </div>
          </section>
        ))}
      </div>

      {viewing && (
        <DriverProfilePage
          driver={viewing}
          onClose={() => setViewing(null)}
          onEditLicence={(d) => setEditing(d)} />
      )}

      <LicenceDialog driver={editing} onClose={() => setEditing(null)} />

      {adding && (
        <AddDriverDialog
          onClose={() => setAdding(false)}
          onAdded={(person) => { setAdding(false); setEditing(person) }} />
      )}
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

/** Closes only via ✕ or Cancel — never a backdrop click. */
/**
 * Add a driver STOS owns itself — one who is not a customer or vendor contact.
 *
 * Files the PERSON into STOS's own register; the licence is added next, in the
 * card that opens straight after, so the two never live in one form pretending
 * to be one record. Name, phone and employer are theirs; the licence is the
 * overlay's.
 */
function AddDriverDialog({ onClose, onAdded }) {
  const qc = useQueryClient()
  const [form, setForm] = useState({ name: '', phone: '', employer: '', designation: 'Driver' })
  const [err, setErr] = useState('')

  const add = useMutation({
    mutationFn: () => stosApi.drivers.register({
      name: form.name.trim(),
      phone: form.phone.trim() || null,
      employer: form.employer.trim() || null,
      designation: form.designation.trim() || null,
    }),
    onSuccess: (person) => {
      qc.invalidateQueries({ queryKey: ['stos-drivers'] })
      // Open their card so the licence goes on right away.
      onAdded?.(person)
    },
    onError: (e) => setErr(e?.message || 'Could not add that driver.'),
  })

  return (
    <div className="fixed inset-0 z-[70] flex items-start justify-center p-4 pt-[8vh] bg-black/50">
      <div className="w-full max-w-3xl rounded-2xl overflow-hidden flex flex-col"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', maxHeight: '84vh' }}
        onKeyDown={(e) => { if (e.key === 'Escape') onClose?.() }}>
        <div className="flex items-start justify-between gap-3 px-6 pt-5 pb-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <div>
            <h2 className="font-bold" style={{ color: 'var(--text-h)', fontSize: 17 }}>Add a driver</h2>
            <p className="text-[12.5px] mt-0.5 leading-snug" style={{ color: 'var(--text-muted)' }}>
              For your own drivers. People who are already a customer or vendor contact appear on the board automatically.
            </p>
          </div>
          <button type="button" onClick={onClose} aria-label="Close" style={{ color: 'var(--text-muted)' }}>
            <X size={15} />
          </button>
        </div>

        <form onSubmit={(e) => { e.preventDefault(); setErr(''); if (form.name.trim()) add.mutate() }}>
          <div className="px-6 py-5 space-y-4">
            <Field label="Full name">
              <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })}
                placeholder="Ramesh Kumar" autoFocus className={inputClass} style={inputStyle} />
            </Field>

            <div className="grid grid-cols-2 gap-3">
              <Field label="Phone">
                <input value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })}
                  placeholder="98765 43210" className={inputClass} style={inputStyle} />
              </Field>
              <Field label="Designation">
                <input value={form.designation} onChange={(e) => setForm({ ...form, designation: e.target.value })}
                  placeholder="Driver" className={inputClass} style={inputStyle} />
              </Field>
            </div>

            <Field label="Employer" hint="Optional — who they drive for">
              <input value={form.employer} onChange={(e) => setForm({ ...form, employer: e.target.value })}
                placeholder="Own fleet" className={inputClass} style={inputStyle} />
            </Field>

            <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
              You'll add their licence and availability on the next screen.
            </p>

            {err && (
              <p className="text-xs px-3 py-2 rounded-lg"
                style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>
                {err}
              </p>
            )}
          </div>

          <div className="flex items-center justify-end gap-2 px-6 py-4"
            style={{ background: 'var(--bg-input)', borderTop: '1px solid var(--border)' }}>
            <button type="button" onClick={onClose} className="text-xs font-semibold px-4 py-2 rounded-xl"
              style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
            <button type="submit" disabled={add.isPending || !form.name.trim()}
              className="flex items-center gap-1.5 text-xs font-bold px-4 py-2 rounded-xl disabled:opacity-60"
              style={{ background: STOS_ACCENT, color: '#fff' }}>
              <Check size={13} /> {add.isPending ? 'Adding…' : 'Add driver'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

function LicenceDialog({ driver, onClose }) {
  const qc = useQueryClient()
  const [form, setForm] = useState({
    licence_number: '', licence_class: 'HMV', licence_expiry: '',
    medical_expiry: '', status: 'AVAILABLE', note: '',
  })
  const [err, setErr] = useState('')
  const [loadedFor, setLoadedFor] = useState(null)

  // Seed from the driver being opened, once per driver.
  if (driver && loadedFor !== driver.ref) {
    setLoadedFor(driver.ref)
    setForm({
      licence_number: driver.profile?.licence_number || '',
      licence_class: driver.profile?.licence_class || 'HMV',
      licence_expiry: driver.profile?.licence_expiry || '',
      medical_expiry: driver.profile?.medical_expiry || '',
      status: driver.profile?.status || 'AVAILABLE',
      note: driver.profile?.note || '',
    })
    setErr('')
  }

  const save = useMutation({
    mutationFn: () => stosApi.drivers.saveProfile(driver.source, driver.source_id, {
      ...form,
      licence_number: form.licence_number.trim() || null,
      licence_expiry: form.licence_expiry || null,
      medical_expiry: form.medical_expiry || null,
      note: form.note.trim() || null,
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['stos-drivers'] })
      onClose?.()
    },
    onError: (e) => setErr(e?.message || 'Could not save those details.'),
  })

  if (!driver) return null

  return (
    <div className="fixed inset-0 z-[70] flex items-start justify-center p-4 pt-[8vh] bg-black/50">
      {/* The documents panel is a SIBLING of the form, not a child: it has its
          own buttons and a nested form would submit this one. */}
      <div
        className="w-full max-w-3xl rounded-2xl overflow-hidden flex flex-col"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', maxHeight: '84vh' }}
        onKeyDown={(e) => { if (e.key === 'Escape') onClose?.() }}>
        <div className="flex items-start justify-between gap-3 px-6 pt-5 pb-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <div>
            <h2 className="font-bold" style={{ color: 'var(--text-h)', fontSize: 17 }}>{driver.name}</h2>
            <p className="text-[12.5px] mt-0.5 leading-snug" style={{ color: 'var(--text-muted)' }}>
              {driver.employer || 'No employer'} · {driver.directory}
            </p>
          </div>
          <button type="button" onClick={onClose} aria-label="Close" style={{ color: 'var(--text-muted)' }}>
            <X size={15} />
          </button>
        </div>

        <form onSubmit={(e) => { e.preventDefault(); setErr(''); save.mutate() }} className="overflow-y-auto">
        <div className="px-6 py-5 space-y-4">
          <p className="text-[12px] leading-snug" style={{ color: 'var(--text-muted)' }}>
            Name, phone and employer come from the directory and are edited there. Only the licence and availability
            below belong to Transport.
          </p>

          {/* Everything on record for this person, in one place. */}
          <div className="rounded-xl p-4" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
            <div className="grid grid-cols-2 gap-x-5 gap-y-3">
              <Detail label="Phone" value={driver.phone} />
              <Detail label="Designation" value={driver.designation} />
              <Detail label="Employer" value={driver.employer} />
              <Detail label="Directory" value={driver.directory} />
              <Detail label="Reference" value={driver.ref} />
              <Detail label="Licence status" value={driver.licence?.message || driver.licence?.state} />
              {driver.profile?.licence_expiry && <Detail label="Licence expires" value={driver.profile.licence_expiry} />}
              {driver.profile?.medical_expiry && <Detail label="Medical expires" value={driver.profile.medical_expiry} />}
            </div>
          </div>

          <Field label="Licence number">
            <input value={form.licence_number} onChange={(e) => setForm({ ...form, licence_number: e.target.value })}
              placeholder="MH0120110012345" className={inputClass} style={inputStyle} />
          </Field>

          <div className="grid grid-cols-2 gap-3">
            <Field label="Class">
              <Select size="sm" value={form.licence_class} onChange={(v) => setForm({ ...form, licence_class: v })}
                options={LICENCE_CLASSES} ariaLabel="Licence class" />
            </Field>
            <Field label="Expires">
              <input type="date" value={form.licence_expiry}
                onChange={(e) => setForm({ ...form, licence_expiry: e.target.value })} className={inputClass} style={inputStyle} />
            </Field>
          </div>

          <Field label="Medical expires"
            hint="Verifying a medical certificate below sets this; an expired one blocks dispatch">
            <input type="date" value={form.medical_expiry}
              onChange={(e) => setForm({ ...form, medical_expiry: e.target.value })} className={inputClass} style={inputStyle} />
          </Field>

          <Field label="Availability" hint="On trip is set by Dispatch and cannot be chosen here">
            <Select size="sm" value={form.status} onChange={(v) => setForm({ ...form, status: v })}
              options={SETTABLE_DRIVER_STATUSES} ariaLabel="Driver status" />
          </Field>

          {err && (
            <p className="text-xs px-3 py-2 rounded-lg"
              style={{ background: 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)', color: 'var(--color-danger-500)' }}>
              {err}
            </p>
          )}
        </div>

        <div className="flex items-center justify-end gap-2 px-6 py-4"
          style={{ background: 'var(--bg-input)', borderTop: '1px solid var(--border)' }}>
          <button type="button" onClick={onClose} className="text-xs font-semibold px-4 py-2 rounded-xl"
            style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
          <button type="submit" disabled={save.isPending}
            className="flex items-center gap-1.5 text-xs font-bold px-4 py-2 rounded-xl disabled:opacity-60"
            style={{ background: STOS_ACCENT, color: '#fff' }}>
            <Check size={13} /> {save.isPending ? 'Saving…' : 'Save'}
          </button>
        </div>
        </form>

        {/* T-43. The dates above are what Transport gates on today; these are
            the evidence behind them, and a verified licence sets the expiry
            rather than somebody retyping it. */}
        <div className="px-5 py-4 overflow-y-auto" style={{ borderTop: '1px solid var(--border)' }}>
          <p className="text-[11px] font-bold mb-2" style={{ color: 'var(--text-muted)' }}>Paperwork</p>
          <DriverDocumentsPanel driver={driver} onChanged={() => qc.invalidateQueries({ queryKey: ['stos-drivers'] })} />
        </div>
      </div>
    </div>
  )
}

const inputClass = 'w-full text-sm rounded-xl px-3.5 py-2.5'
const inputStyle = { background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text)' }

function Field({ label, hint, children }) {
  return (
    <div>
      <label className="text-[13px] font-semibold block mb-1.5" style={{ color: 'var(--text-h)' }}>{label}</label>
      {children}
      {hint && <p className="text-[12px] mt-1.5 leading-snug" style={{ color: 'var(--text-muted)' }}>{hint}</p>}
    </div>
  )
}

// The queue of app sign-ups waiting for the office's yes. Approve creates the
// driver's login and their profile; reject turns them away with a reason.
function PendingRegistrations({ rows, onChanged }) {
  const [busy, setBusy] = useState(null)
  const [err, setErr] = useState('')

  const act = async (id, fn) => {
    setBusy(id); setErr('')
    try { await fn(); onChanged?.() }
    catch (e) { setErr(e?.message || 'Could not complete that.') }
    finally { setBusy(null) }
  }

  return (
    <div className="mb-3 rounded-xl overflow-hidden" style={{ border: '1px solid var(--color-warning-500, #f59e0b)' }}>
      <div className="px-4 py-2.5 flex items-center gap-2" style={{ background: 'color-mix(in srgb, var(--color-warning-500) 14%, transparent)' }}>
        <IdCard size={14} style={{ color: 'var(--color-warning-500, #f59e0b)' }} />
        <span className="text-[13px] font-bold" style={{ color: 'var(--text-h)' }}>
          {rows.length} driver{rows.length === 1 ? '' : 's'} waiting for approval
        </span>
      </div>
      <div className="divide-y" style={{ background: 'var(--bg-card)' }}>
        {rows.map((r) => (
          <div key={r.id} className="px-4 py-3 flex items-center gap-3 flex-wrap" style={{ borderColor: 'var(--border)' }}>
            <div className="min-w-0 flex-1">
              <p className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{r.name}</p>
              <p className="text-[12px]" style={{ color: 'var(--text-muted)' }}>
                {r.email}{r.phone ? ` · ${r.phone}` : ''}{r.licence_number ? ` · licence ${r.licence_number}` : ''}
              </p>
            </div>
            <div className="flex items-center gap-2 shrink-0">
              <button type="button" disabled={busy === r.id}
                onClick={() => act(r.id, () => stosApi.drivers.approveRegistration(r.id))}
                className="text-[12px] font-bold px-3 py-1.5 rounded-lg disabled:opacity-60"
                style={{ background: 'var(--color-success-500, #10b981)', color: '#fff' }}>
                {busy === r.id ? '…' : 'Approve'}
              </button>
              <button type="button" disabled={busy === r.id}
                onClick={() => { const why = window.prompt('Reason for rejecting (optional):') ; if (why !== null) act(r.id, () => stosApi.drivers.rejectRegistration(r.id, why)) }}
                className="text-[12px] font-semibold px-3 py-1.5 rounded-lg"
                style={{ color: 'var(--color-danger-500)', border: '1px solid var(--border)' }}>
                Reject
              </button>
            </div>
          </div>
        ))}
        {err && <p className="px-4 py-2 text-[12px]" style={{ color: 'var(--color-danger-500)' }}>{err}</p>}
      </div>
    </div>
  )
}

// A read-only label/value pair for the details block.
function Detail({ label, value }) {
  return (
    <div>
      <p className="text-[11.5px] font-semibold" style={{ color: 'var(--text-muted)' }}>{label}</p>
      <p className="text-[13.5px] mt-0.5 break-words" style={{ color: 'var(--text-h)' }}>{value || '—'}</p>
    </div>
  )
}
