import ToggleSwitch from './ToggleSwitch'

// Old-CRM parity: access PERMISSIONS and email NOTIFICATIONS are two separate
// concerns. Permissions decide which modules a contact may access; email
// notifications decide which system emails they receive; `emails_enabled` is
// the master switch that mutes all mail regardless.
export const PERMISSION_MODULES = [
  { key: 'invoice', label: 'Invoices' },
  { key: 'estimate', label: 'Estimates' },
  { key: 'contract', label: 'Contracts' },
  { key: 'proposal', label: 'Proposals' },
  { key: 'support', label: 'Support' },
  { key: 'project', label: 'Projects' },
]

export const NOTIFICATION_TYPES = [
  { key: 'invoice', label: 'Invoices' },
  { key: 'estimate', label: 'Estimates' },
  { key: 'credit_note', label: 'Credit Notes' },
  { key: 'proposal', label: 'Proposals' },
  { key: 'project', label: 'Projects' },
  { key: 'ticket', label: 'Tickets' },
  { key: 'task', label: 'Tasks' },
  { key: 'contract', label: 'Contracts' },
]

export const DEFAULT_PERMISSIONS = PERMISSION_MODULES.map(m => m.key)
export const DEFAULT_NOTIFICATIONS = Object.fromEntries(NOTIFICATION_TYPES.map(n => [n.key, true]))

/** Legacy fallback: derive access permissions from a legacy notifications map. */
export function permissionsFromLegacy(emailNotifications) {
  if (!emailNotifications) return DEFAULT_PERMISSIONS
  const on = Object.entries(emailNotifications).filter(([, v]) => v).map(([k]) => k)
  return PERMISSION_MODULES.map(m => m.key).filter(k => on.includes(k) || (k === 'support' && on.includes('ticket')))
}

export default function ContactPermissions({
  permissions = [], notifications = DEFAULT_NOTIFICATIONS, emailsEnabled = true, onChange, compact = false,
}) {
  const emit = (patch) => onChange({
    permissions, email_notifications: notifications, emails_enabled: emailsEnabled, ...patch,
  })

  const togglePerm = (key) => emit({
    permissions: permissions.includes(key) ? permissions.filter(k => k !== key) : [...permissions, key],
  })
  const toggleNotif = (key) => emit({
    email_notifications: { ...notifications, [key]: !notifications[key] },
  })

  const grid = compact ? 'grid-cols-2 sm:grid-cols-3' : 'grid-cols-2'

  return (
    <div className="space-y-4">
      {/* Access permissions */}
      <div>
        <p className="label-caps mb-2" style={{ color: 'var(--accent)' }}>Permissions</p>
        <p className="text-[11px] mb-2" style={{ color: 'var(--text-muted)' }}>Which modules this contact can access.</p>
        <div className={`grid ${grid} gap-2 p-3 rounded-xl`} style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          {PERMISSION_MODULES.map(m => (
            <label key={m.key} className="flex items-center gap-2 text-xs cursor-pointer" style={{ color: 'var(--text-h)' }}>
              <input type="checkbox" checked={permissions.includes(m.key)} onChange={() => togglePerm(m.key)} /> {m.label}
            </label>
          ))}
        </div>
      </div>

      {/* Email notifications */}
      <div>
        <div className="flex items-center justify-between mb-2">
          <p className="label-caps" style={{ color: 'var(--accent)' }}>Email Notifications</p>
          <label className="flex items-center gap-2 text-[11px] font-semibold cursor-pointer" style={{ color: 'var(--text-muted)' }}>
            All emails
            <ToggleSwitch checked={emailsEnabled} onChange={() => emit({ emails_enabled: !emailsEnabled })} title="Master switch — off disables ALL emails to this contact" size="sm" />
          </label>
        </div>
        <div className={`grid ${grid} gap-2 p-3 rounded-xl transition-opacity`} style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', opacity: emailsEnabled ? 1 : 0.5 }}>
          {NOTIFICATION_TYPES.map(n => (
            <label key={n.key} className="flex items-center gap-2 text-xs cursor-pointer" style={{ color: 'var(--text-h)' }}>
              <input type="checkbox" checked={!!notifications[n.key]} onChange={() => toggleNotif(n.key)} /> {n.label}
            </label>
          ))}
        </div>
        {!emailsEnabled && (
          <p className="text-[11px] mt-1.5" style={{ color: '#f59e0b' }}>All emails to this contact are disabled. Module access still applies.</p>
        )}
      </div>
    </div>
  )
}
