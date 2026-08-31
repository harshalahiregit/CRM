/**
 * SangoeTrack's HRM policy — the handful of settings that decide how attendance,
 * leave and advances behave for everyone.
 *
 * These write to exactly where SangoeTrack's own web admin writes, so a change
 * made here is the change made there. Small screen, large blast radius: the
 * start time decides who counts as late, and the approval limits decide who can
 * release money. Each field says what it actually affects rather than repeating
 * its own name.
 *
 * Unlike every other screen in this module, the endpoints behind this one had to
 * be ADDED to SangoeTrack — they are not part of what its mobile app uses. Until
 * that side is deployed the call fails, so the screen names that specifically
 * instead of reporting a generic error.
 */

import { useState, useEffect, useCallback } from 'react'
import { Save, ServerCrash } from 'lucide-react'
import { sangoeTrackApi, trackErrorMessage } from '@/services/sangoeTrackApi'
import { useToast } from '@/hooks/useToast'
import LoadError from '@/components/ui/LoadError'
import { TrackHeader } from './TrackShell'

const HRM_FIELDS = [
  { key: 'company_start_time',  label: 'Working day starts', type: 'time',
    hint: 'Anyone clocking in after this is marked late. There is no grace period.' },
  { key: 'company_end_time',    label: 'Working day ends',   type: 'time',
    hint: 'Used to work out early leaving.' },
  { key: 'max_shift_hours',     label: 'Longest possible shift', type: 'number',
    hint: 'After this a shift with no clock-out is treated as forgotten rather than still running.' },
  { key: 'employee_prefix',     label: 'Employee code prefix', type: 'text',
    hint: 'Goes in front of generated employee codes.' },
  { key: 'hr_notification_email', label: 'HR notification email', type: 'email',
    hint: 'Where requests and alerts are sent. Leave blank for none.' },
]

const LEAVE_FIELDS = [
  { key: 'leave_paid_days',     label: 'Paid' },
  { key: 'leave_casual_days',   label: 'Casual' },
  { key: 'leave_unpaid_days',   label: 'Unpaid' },
  { key: 'leave_comp_off_days', label: 'Comp off' },
]

const WHATSAPP_FIELDS = [
  { key: 'whatsapp_global_enabled', label: 'WhatsApp notifications', hint: 'Master switch — off silences all of the below.' },
  { key: 'notify_leave',            label: 'Leave decisions' },
  { key: 'notify_reimbursement',    label: 'Reimbursement decisions' },
  { key: 'notify_attendance_raise', label: 'Attendance corrections' },
  { key: 'notify_clock_reminder',   label: 'Forgotten clock-out reminders' },
]

function Toggle({ on, onChange, label, hint, disabled }) {
  return (
    <label className="flex items-start gap-2.5 cursor-pointer" style={{ opacity: disabled ? 0.45 : 1 }}>
      <button type="button" role="switch" aria-checked={on} disabled={disabled}
        onClick={() => onChange(!on)}
        className="rounded-full flex-shrink-0 transition-colors"
        style={{
          width: 34, height: 20, marginTop: 1, padding: 2,
          background: on ? '#7C3AED' : 'var(--border)',
          cursor: disabled ? 'not-allowed' : 'pointer',
        }}>
        <span className="block rounded-full transition-transform"
          style={{ width: 16, height: 16, background: '#fff', transform: on ? 'translateX(14px)' : 'none' }} />
      </button>
      <span>
        <span className="text-sm font-semibold block" style={{ color: 'var(--text-h)' }}>{label}</span>
        {hint && <span className="text-[11px] block" style={{ color: 'var(--text-muted)' }}>{hint}</span>}
      </span>
    </label>
  )
}

export default function TrackSettings() {
  const [hrm, setHrm]           = useState(null)
  const [whatsapp, setWhatsapp] = useState(null)
  const [loading, setLoading]   = useState(true)
  const [error, setError]       = useState(null)
  const [savingHrm, setSavingHrm] = useState(false)
  const [savingWa, setSavingWa]   = useState(false)
  const toast = useToast()

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const res = await sangoeTrackApi.settings.get()
      setHrm(res?.hrm ?? {})
      setWhatsapp(res?.whatsapp ?? null)
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  const set = (k, v) => setHrm(prev => ({ ...prev, [k]: v }))

  async function saveHrm() {
    setSavingHrm(true)
    try {
      await sangoeTrackApi.settings.save({
        employee_prefix:        hrm.employee_prefix,
        company_start_time:     hrm.company_start_time,
        company_end_time:       hrm.company_end_time,
        hr_notification_email:  hrm.hr_notification_email || null,
        max_shift_hours:        hrm.max_shift_hours ? Number(hrm.max_shift_hours) : null,
        ip_restrict:            hrm.ip_restrict === 'on' ? 'on' : 'off',
        leave_paid_days:        Number(hrm.leave_paid_days ?? 0),
        leave_casual_days:      Number(hrm.leave_casual_days ?? 0),
        leave_unpaid_days:      Number(hrm.leave_unpaid_days ?? 0),
        leave_comp_off_days:    Number(hrm.leave_comp_off_days ?? 0),
        advance_manager_limit:  Number(hrm.advance_manager_limit ?? 0),
        advance_accounts_limit: Number(hrm.advance_accounts_limit ?? 0),
      })
      toast.success('Settings saved', 'They apply to SangoeTrack immediately.')
      load()
    } catch (err) {
      toast.error(trackErrorMessage(err, 'Could not save settings.'))
    } finally {
      setSavingHrm(false)
    }
  }

  async function saveWhatsapp(next) {
    setWhatsapp(next)
    setSavingWa(true)
    try {
      await sangoeTrackApi.settings.saveWhatsapp(next)
    } catch (err) {
      toast.error(trackErrorMessage(err, 'Could not save WhatsApp settings.'))
      load()   // put the switches back to what SangoeTrack actually holds
    } finally {
      setSavingWa(false)
    }
  }

  if (error) {
    return (
      <div className="p-5 md:p-7 flex flex-col gap-5">
        <TrackHeader title="Settings" subtitle="SangoeTrack's HRM policy." onRefresh={load} loading={loading} />
        <LoadError error={error} onRetry={load} title="Could not load settings" />
        {/* The likely cause, named — this is the one screen whose endpoints are
            new on SangoeTrack rather than shipped with it. */}
        <div className="rounded-xl p-4 flex gap-3" style={{ border: '1px solid var(--border)', background: 'var(--bg-card)' }}>
          <ServerCrash size={16} style={{ color: '#fbbf24', flexShrink: 0, marginTop: 2 }} />
          <div>
            <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>
              This screen needs endpoints that are new on SangoeTrack
            </p>
            <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
              Every other screen in this module runs on endpoints SangoeTrack already had.
              These four were added for the CRM and only work once that side is deployed.
              Until then, settings can still be changed in SangoeTrack's own web admin.
            </p>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="p-5 md:p-7 flex flex-col gap-6" style={{ maxWidth: 820 }}>
      <TrackHeader
        title="Settings"
        subtitle="SangoeTrack's HRM policy. Changes here are the same as changing them there."
        onRefresh={load}
        loading={loading}
      />

      {loading || !hrm ? (
        <p className="text-sm py-8 text-center" style={{ color: 'var(--text-muted)' }}>Loading…</p>
      ) : (
        <>
          {/* ── working day ──────────────────────────────────────── */}
          <section className="flex flex-col gap-3">
            <h2 className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>The working day</h2>
            <div className="grid gap-3.5" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(230px, 1fr))' }}>
              {HRM_FIELDS.map(f => (
                <label key={f.key} className="flex flex-col gap-1">
                  <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
                    {f.label}
                  </span>
                  <input
                    type={f.type}
                    value={hrm[f.key] ?? ''}
                    onChange={e => set(f.key, e.target.value)}
                    className="rounded-lg text-sm px-2.5 py-2"
                    style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}
                  />
                  <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{f.hint}</span>
                </label>
              ))}
            </div>

            <Toggle
              on={hrm.ip_restrict === 'on'}
              onChange={v => set('ip_restrict', v ? 'on' : 'off')}
              label="Restrict clock-in to approved IP addresses"
              hint="Only applies to the web punch. The mobile app is not checked against it, so this does not stop phone clock-ins from anywhere."
            />
          </section>

          {/* ── leave ────────────────────────────────────────────── */}
          <section className="flex flex-col gap-3">
            <h2 className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>Yearly leave allowance</h2>
            <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
              Days each employee gets per year. Existing balances are not recalculated when these change.
            </p>
            <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(130px, 1fr))' }}>
              {LEAVE_FIELDS.map(f => (
                <label key={f.key} className="flex flex-col gap-1">
                  <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
                    {f.label}
                  </span>
                  <input type="number" min="0" max="365"
                    value={hrm[f.key] ?? 0}
                    onChange={e => set(f.key, e.target.value)}
                    className="rounded-lg text-sm px-2.5 py-2"
                    style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }} />
                </label>
              ))}
            </div>
          </section>

          {/* ── advances ─────────────────────────────────────────── */}
          <section className="flex flex-col gap-3">
            <h2 className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>Advance approval limits</h2>
            <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))' }}>
              {[
                ['advance_manager_limit',  'Manager can approve up to', 'Anything above this goes to accounts.'],
                ['advance_accounts_limit', 'Accounts can approve up to', 'Must be at least the manager limit.'],
              ].map(([key, label, hint]) => (
                <label key={key} className="flex flex-col gap-1">
                  <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
                    {label}
                  </span>
                  <input type="number" min="0"
                    value={hrm[key] ?? 0}
                    onChange={e => set(key, e.target.value)}
                    className="rounded-lg text-sm px-2.5 py-2"
                    style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', fontVariantNumeric: 'tabular-nums' }} />
                  <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{hint}</span>
                </label>
              ))}
            </div>
            {Number(hrm.advance_accounts_limit ?? 0) < Number(hrm.advance_manager_limit ?? 0) && (
              <p className="text-[11px] font-semibold" style={{ color: '#fbbf24' }}>
                Accounts is currently below manager — this will be refused on save.
              </p>
            )}
          </section>

          <div>
            <button onClick={saveHrm} disabled={savingHrm}
              className="rounded-lg text-xs font-bold flex items-center gap-1.5 disabled:opacity-50"
              style={{ padding: '9px 16px', background: '#7C3AED', color: '#fff' }}>
              <Save size={13} />
              {savingHrm ? 'Saving…' : 'Save settings'}
            </button>
          </div>

          {/* ── whatsapp ─────────────────────────────────────────── */}
          {whatsapp && (
            <section className="flex flex-col gap-3 pt-2" style={{ borderTop: '1px solid var(--border)' }}>
              <div>
                <h2 className="text-sm font-bold mt-3" style={{ color: 'var(--text-h)' }}>WhatsApp notifications</h2>
                <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>
                  Saved as you switch them. These are held in SangoeTrack's cache rather than its
                  database, so clearing its cache resets them to all-on.
                </p>
              </div>
              <div className="flex flex-col gap-2.5">
                {WHATSAPP_FIELDS.map(f => (
                  <Toggle key={f.key}
                    on={!!whatsapp[f.key]}
                    label={f.label}
                    hint={f.hint}
                    disabled={savingWa || (f.key !== 'whatsapp_global_enabled' && !whatsapp.whatsapp_global_enabled)}
                    onChange={v => saveWhatsapp({ ...whatsapp, [f.key]: v })}
                  />
                ))}
              </div>
            </section>
          )}
        </>
      )}
    </div>
  )
}
