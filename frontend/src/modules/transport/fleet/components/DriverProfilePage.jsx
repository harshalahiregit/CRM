import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { X, IdCard, FileText, Activity, UserRound, Building2, Pencil } from 'lucide-react'
import { STOS_ACCENT } from '@/services/stosApi'
import HealthChip from './HealthChip'
import DriverDocumentsPanel from './DriverDocumentsPanel'

const LICENCE_TONE = { valid: 'ok', expiring: 'warn', expired: 'bad', unknown: 'muted' }
const DRIVER_STATUS_LABELS = {
  AVAILABLE: 'Available', ON_TRIP: 'On trip', RESTING: 'Resting',
  SUSPENDED: 'Suspended', OFF_DUTY: 'Off duty', UNAVAILABLE: 'Unavailable',
}

/**
 * The full driver profile — a whole page, not a cramped dialog. Everything the
 * office holds on one driver, in one place: who they are, their paperwork (view
 * and approve), and their activity. The licence and availability are edited
 * through the existing card, opened from here.
 *
 * Opens over the board and closes only via ✕ (never a backdrop click), matching
 * the rest of STOS.
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
    <div className="fixed inset-0 z-[70] flex flex-col" style={{ background: 'var(--bg)' }}>
      {/* Header */}
      <header className="flex items-start justify-between gap-3 px-6 py-4 shrink-0"
        style={{ background: 'var(--bg-card)', borderBottom: '1px solid var(--border)' }}>
        <div className="flex items-center gap-3 min-w-0">
          <span className="w-11 h-11 rounded-2xl flex items-center justify-center shrink-0"
            style={{ background: `color-mix(in srgb, ${STOS_ACCENT} 14%, transparent)` }}>
            <UserRound size={20} style={{ color: STOS_ACCENT }} />
          </span>
          <div className="min-w-0">
            <div className="flex items-center gap-2 flex-wrap">
              <h1 className="font-bold truncate" style={{ color: 'var(--text-h)', fontSize: 19 }}>{driver.name}</h1>
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
            <p className="flex items-center gap-1 text-[12px] mt-0.5" style={{ color: 'var(--text-muted)' }}>
              {driver.employer && <><Building2 size={11} /> {driver.employer} · </>}
              {driver.phone || 'no phone'} · from {driver.directory}
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2 shrink-0">
          <button type="button" onClick={() => onEditLicence?.(driver)}
            className="flex items-center gap-1.5 text-xs font-bold px-3 py-2 rounded-xl"
            style={{ background: STOS_ACCENT, color: '#fff' }}>
            <Pencil size={13} /> Edit licence & availability
          </button>
          <button type="button" onClick={onClose} aria-label="Close"
            className="w-9 h-9 rounded-xl flex items-center justify-center"
            style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
            <X size={16} />
          </button>
        </div>
      </header>

      {/* Tabs */}
      <nav className="flex gap-1 px-4 shrink-0" style={{ background: 'var(--bg-card)', borderBottom: '1px solid var(--border)' }}>
        {tabs.map((t) => {
          const on = tab === t.key
          const Icon = t.icon
          return (
            <button key={t.key} type="button" onClick={() => setTab(t.key)}
              className="flex items-center gap-1.5 text-xs font-bold px-4 py-3"
              style={{ color: on ? STOS_ACCENT : 'var(--text-muted)', borderBottom: on ? `2px solid ${STOS_ACCENT}` : '2px solid transparent' }}>
              <Icon size={14} /> {t.label}
            </button>
          )
        })}
      </nav>

      {/* Body */}
      <div className="flex-1 overflow-y-auto">
        <div className="max-w-4xl mx-auto px-6 py-6">
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
      </div>
    </div>
  )
}

function Overview({ driver }) {
  return (
    <div className="space-y-5">
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
    </div>
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
