import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, IdCard, FileText, Activity, UserRound, Building2, Pencil } from 'lucide-react'
import { stosApi, STOS_ACCENT } from '@/services/stosApi'
import HealthChip from './HealthChip'
import DriverDocumentsPanel from './DriverDocumentsPanel'

const LICENCE_TONE = { valid: 'green', expiring: 'amber', expired: 'red', unknown: 'amber' }
const DRIVER_STATUS_LABELS = {
  AVAILABLE: 'Available', ON_TRIP: 'On trip', RESTING: 'Resting',
  SUSPENDED: 'Suspended', OFF_DUTY: 'Off duty', UNAVAILABLE: 'Unavailable',
}

/**
 * The full driver profile — a page inside the app shell (the sidebar and header
 * stay), not a modal and not a full-screen takeover. Everything the office holds
 * on one driver: who they are, their paperwork (view and approve), and their
 * activity. Licence and availability are edited through the existing card,
 * opened from here.
 */
export default function DriverProfilePage({ driver, onClose, onEditLicence }) {
  const qc = useQueryClient()
  const [tab, setTab] = useState('overview')
  if (!driver) return null

  const refresh = () => qc.invalidateQueries({ queryKey: ['stos-drivers'] })
  const tabs = [
    { key: 'overview', label: 'Overview', icon: UserRound },
    { key: 'documents', label: 'Documents', icon: FileText },
    { key: 'activity', label: 'Activity', icon: Activity },
  ]

  return (
    <div>
      {/* Back to the board */}
      <button type="button" onClick={onClose}
        className="flex items-center gap-1.5 text-xs font-semibold mb-4"
        style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={14} /> Back to drivers
      </button>

      {/* Header card */}
      <section className="rounded-2xl p-5 mb-4" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <div className="flex items-start justify-between gap-3 flex-wrap">
          <div className="flex items-center gap-3 min-w-0">
            <span className="w-12 h-12 rounded-2xl flex items-center justify-center shrink-0"
              style={{ background: `color-mix(in srgb, ${STOS_ACCENT} 14%, transparent)` }}>
              <UserRound size={22} style={{ color: STOS_ACCENT }} />
            </span>
            <div className="min-w-0">
              <div className="flex items-center gap-2 flex-wrap">
                <h1 className="font-bold truncate" style={{ color: 'var(--text-h)', fontSize: 20 }}>{driver.name}</h1>
                <HealthChip tone={LICENCE_TONE[driver.licence?.state]} size="sm">
                  {driver.licence?.state === 'unknown' ? 'no licence' : `licence ${driver.licence?.state}`}
                </HealthChip>
                {driver.medical?.state && driver.medical.state !== 'valid' && (
                  <HealthChip tone={LICENCE_TONE[driver.medical.state]} size="sm">
                    {driver.medical.state === 'unknown' ? 'no medical' : `medical ${driver.medical.state}`}
                  </HealthChip>
                )}
                {driver.profile?.status && (
                  <span className="text-[10px] px-1.5 py-0.5 rounded"
                    style={{ background: 'var(--bg-input)', color: 'var(--text-muted)' }}>
                    {DRIVER_STATUS_LABELS[driver.profile.status] || driver.profile.status}
                  </span>
                )}
              </div>
              <p className="flex items-center gap-1 text-[12.5px] mt-1" style={{ color: 'var(--text-muted)' }}>
                {driver.employer && <><Building2 size={11} /> {driver.employer} · </>}
                {driver.phone || 'no phone'} · from {driver.directory}
              </p>
            </div>
          </div>
          <button type="button" onClick={() => onEditLicence?.(driver)}
            className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl shrink-0"
            style={{ background: STOS_ACCENT, color: '#fff' }}>
            <Pencil size={13} /> Edit licence & availability
          </button>
        </div>

        {/* Tabs */}
        <nav className="flex gap-1 mt-4 -mb-1" style={{ borderTop: '1px solid var(--border)', paddingTop: 8 }}>
          {tabs.map((t) => {
            const on = tab === t.key
            const Icon = t.icon
            return (
              <button key={t.key} type="button" onClick={() => setTab(t.key)}
                className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-lg"
                style={{ color: on ? '#fff' : 'var(--text-muted)', background: on ? STOS_ACCENT : 'transparent' }}>
                <Icon size={14} /> {t.label}
              </button>
            )
          })}
        </nav>
      </section>

      {/* Body */}
      {tab === 'overview' && <Overview driver={driver} />}
      {tab === 'documents' && (
        <section className="rounded-2xl p-5" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
          <h2 className="text-sm font-bold mb-1" style={{ color: 'var(--text-h)' }}>Paperwork</h2>
          <p className="text-[12px] mb-4" style={{ color: 'var(--text-muted)' }}>
            Documents the driver uploaded from the app arrive here as “to verify”. Open one to view the file, then approve or reject it.
          </p>
          <DriverDocumentsPanel driver={driver} onChanged={refresh} />
        </section>
      )}
      {tab === 'activity' && <ActivityTab />}
    </div>
  )
}

function Overview({ driver }) {
  return (
    <div className="space-y-4">
      <section className="rounded-2xl p-5" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <h2 className="text-sm font-bold mb-4" style={{ color: 'var(--text-h)' }}>Details</h2>
        <div className="grid grid-cols-2 md:grid-cols-3 gap-x-6 gap-y-4">
          <Detail label="Phone" value={driver.phone} />
          <Detail label="Designation" value={driver.designation} />
          <Detail label="Employer" value={driver.employer} />
          <Detail label="Directory" value={driver.directory} />
          <Detail label="Reference" value={driver.ref} />
          <Detail label="Licence number" value={driver.profile?.licence_number} />
          <Detail label="Licence status" value={driver.licence?.message || driver.licence?.state} />
          {driver.profile?.licence_expiry && <Detail label="Licence expires" value={driver.profile.licence_expiry} />}
          {driver.profile?.medical_expiry && <Detail label="Medical expires" value={driver.profile.medical_expiry} />}
        </div>
      </section>

      <section className="rounded-2xl p-5 flex items-start gap-3"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
        <IdCard size={18} style={{ color: STOS_ACCENT, marginTop: 2 }} />
        <div>
          <h2 className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>Clearance to drive</h2>
          <p className="text-[12.5px] mt-1 leading-relaxed" style={{ color: 'var(--text-muted)' }}>
            A driver is cleared only when their dispatch-gating documents (licence, medical) are verified.
            Verify them on the <strong>Documents</strong> tab — a verified licence sets the expiry automatically.
          </p>
        </div>
      </section>

      {/* App login — only STOS's own drivers have one. */}
      {driver.source === 'stos' && <AppLogin driver={driver} />}
    </div>
  )
}

function AppLogin({ driver }) {
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState(null) // { ok, text }

  const reset = async () => {
    const pw = window.prompt(`Set a new app password for ${driver.name} (at least 6 characters). Tell them the new password after.`)
    if (pw === null) return
    if (pw.trim().length < 6) { setMsg({ ok: false, text: 'Password must be at least 6 characters.' }); return }
    setBusy(true); setMsg(null)
    try {
      await stosApi.drivers.resetPassword(driver.source, driver.source_id, pw)
      setMsg({ ok: true, text: 'Password reset. Tell the driver their new password — they can sign in with it now.' })
    } catch (e) {
      setMsg({ ok: false, text: e?.message || 'Could not reset the password.' })
    } finally { setBusy(false) }
  }

  return (
    <section className="rounded-2xl p-5" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <h2 className="text-sm font-bold mb-1" style={{ color: 'var(--text-h)' }}>App login</h2>
      <p className="text-[12.5px] mb-3 leading-relaxed" style={{ color: 'var(--text-muted)' }}>
        If this driver can't sign in, set a new password here and tell them.
      </p>
      <button type="button" onClick={reset} disabled={busy}
        className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl disabled:opacity-60"
        style={{ background: 'var(--bg-input)', color: 'var(--text-h)', border: '1px solid var(--border)' }}>
        <IdCard size={13} /> {busy ? 'Resetting…' : 'Reset password'}
      </button>
      {msg && (
        <p className="text-[12px] mt-3 px-3 py-2 rounded-lg"
          style={{ background: msg.ok ? 'color-mix(in srgb, var(--color-success-500, #10b981) 12%, transparent)' : 'color-mix(in srgb, var(--color-danger-500) 12%, transparent)',
            color: msg.ok ? 'var(--color-success-500, #10b981)' : 'var(--color-danger-500)' }}>
          {msg.text}
        </p>
      )}
    </section>
  )
}

function ActivityTab() {
  return (
    <section className="rounded-2xl p-8 text-center" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
      <Activity size={28} style={{ color: 'var(--text-muted)', margin: '0 auto 12px' }} />
      <h2 className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>Trip activity</h2>
      <p className="text-[12.5px] mt-2 max-w-md mx-auto leading-relaxed" style={{ color: 'var(--text-muted)' }}>
        This driver’s trips, POD history, exceptions and fuel/expense claims will list here. The trip records
        come from Dispatch (Ops), read through Fleet’s trip-history seam — being wired next.
      </p>
    </section>
  )
}

function Detail({ label, value }) {
  return (
    <div>
      <p className="text-[10.5px] uppercase tracking-wide font-semibold" style={{ color: 'var(--text-muted)' }}>{label}</p>
      <p className="text-sm mt-0.5" style={{ color: 'var(--text-h)' }}>{value || '—'}</p>
    </div>
  )
}
