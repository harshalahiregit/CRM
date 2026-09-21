import { useEffect, useState } from 'react'
import { Stethoscope, AlertTriangle } from 'lucide-react'
import { medicalApi } from '@/services/medicalApi'
import { portalApi } from '@/services/portalApi'
import { purchasePortalApi } from '@/services/purchasePortalApi'

/**
 * Pick one of our own doctors, instead of typing their name again.
 *
 * ── The problem ─────────────────────────────────────────────────────────
 * Every medical record named its doctor in a free-text box. An admin had
 * already entered each in-house doctor once — name, licence, council, clinic —
 * in the doctor directory, and none of that was ever offered back. So the same
 * doctor was recorded as "Dr Sharma", "Dr. A Sharma" and "sharma", the licence
 * number was retyped (or mistyped) onto every certificate, and the record never
 * pointed at the actual person.
 *
 * ── The shape ───────────────────────────────────────────────────────────
 * A dropdown, ABOVE the name box, never instead of it. Picking a doctor fills
 * the name in and hands the id up so the server can copy the rest from the
 * directory. Choosing "Not listed" clears the id and leaves the box to be typed
 * as it always was — which is how an outside doctor, a locum, or one not in the
 * directory yet keeps working.
 *
 * So the dropdown is a shortcut, never a cage.
 *
 * ── Where the list comes from ───────────────────────────────────────────
 * Three endpoints, one per identity, because these forms are mounted on all
 * three: an admin/staff screen, the TPV portal and the Purchase portal. The
 * caller says which; the module is enforced server-side either way.
 */
/**
 * The doctor list on its own, for forms built from a field DESCRIPTION rather
 * than from JSX — the vendor-detail "Record Medical" quick-adds, which declare
 * `{ name, label, type: 'select', options }` and render themselves.
 *
 * Same endpoints, same rules. Returns [] until loaded and on failure, so a
 * caller that spreads it into `options` degrades to a select with only the
 * "not listed" entry, and the free-text examiner box below still works.
 */
export function useDoctorOptions(module, portal = false) {
  const [rows, setRows] = useState([])

  useEffect(() => {
    let alive = true
    const load = portal
      ? (module === 'purchase' ? purchasePortalApi.workforce.doctorOptions : portalApi.workers.doctorOptions)
      : () => medicalApi.doctorOptions(module)

    Promise.resolve()
      .then(load)
      .then(d => { if (alive) setRows(Array.isArray(d) ? d : (d?.data ?? [])) })
      .catch(() => { if (alive) setRows([]) })

    return () => { alive = false }
  }, [module, portal])

  return rows
}

/** The same rows shaped for a declarative `type: 'select'` field. */
export function doctorSelectOptions(rows) {
  return (rows || []).map(d => ({
    value: String(d.user_id),
    label: `${d.name}${d.license_no ? ` · ${d.license_no}` : ''}`,
  }))
}

export default function InternalDoctorSelect({
  module,            // 'tpv' | 'purchase'
  portal = false,    // true when mounted inside a vendor portal
  value,             // the chosen doctor_user_id, or null
  onPick,            // (doctor|null) => void — doctor has name/license_no/clinic_name
  label = 'Internal doctor',
  disabled = false,
}) {
  const [rows, setRows] = useState(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    let alive = true
    const load = portal
      ? (module === 'purchase' ? purchasePortalApi.workforce.doctorOptions : portalApi.workers.doctorOptions)
      : () => medicalApi.doctorOptions(module)

    Promise.resolve()
      .then(load)
      .then(d => { if (alive) setRows(Array.isArray(d) ? d : (d?.data ?? [])) })
      // A picker that cannot load must not block the form: the name box below
      // still works, which is exactly how this screen behaved before.
      .catch(() => { if (alive) { setRows([]); setFailed(true) } })

    return () => { alive = false }
  }, [module, portal])

  const pick = (id) => {
    if (!id) { onPick(null); return }
    onPick((rows || []).find(r => String(r.user_id) === String(id)) || null)
  }

  const chosen = (rows || []).find(r => String(r.user_id) === String(value))

  // Nothing to offer and nothing to say — don't take up space above the name
  // box with an empty control.
  if (rows !== null && rows.length === 0 && !failed) return null

  return (
    <div style={{ marginBottom: 10 }}>
      <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 11.5, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 4 }}>
        <Stethoscope size={13} /> {label}
      </label>

      <select
        value={value ?? ''}
        disabled={disabled || rows === null}
        onChange={e => pick(e.target.value)}
        style={{
          width: '100%', padding: '8px 10px', borderRadius: 8, fontSize: 13,
          background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)',
        }}
      >
        <option value="">
          {rows === null ? 'Loading doctors…' : 'Not listed — type the name below'}
        </option>
        {(rows || []).map(d => (
          <option key={d.user_id} value={d.user_id}>
            {d.name}{d.license_no ? ` · ${d.license_no}` : ''}{d.clinic_name ? ` · ${d.clinic_name}` : ''}
          </option>
        ))}
      </select>

      {/* A doctor with no licence may examine but cannot sign a certificate.
          Better said here than discovered at the point of printing one. */}
      {chosen && !chosen.is_signable && (
        <p style={{ display: 'flex', alignItems: 'center', gap: 5, fontSize: 11, margin: '5px 0 0', color: '#f59e0b' }}>
          <AlertTriangle size={11} /> No licence number on file — this doctor cannot sign a certificate yet.
        </p>
      )}

      {failed && (
        <p style={{ fontSize: 11, margin: '5px 0 0', color: 'var(--text-muted)' }}>
          The doctor list could not be loaded. Type the name below instead.
        </p>
      )}

      {!failed && rows !== null && (
        <p style={{ fontSize: 11, margin: '5px 0 0', color: 'var(--text-muted)' }}>
          {chosen
            ? 'Licence and clinic are taken from this doctor’s profile.'
            : 'Pick one of our doctors, or leave this and type any name below.'}
        </p>
      )}
    </div>
  )
}
