// Shared UI helpers for the Central Notification Engine screens.
// Priority + status badge palettes, kept consistent with the rest of the HR UI.

export const GRAD = 'linear-gradient(135deg,#7C3AED,#5b21b6)'

export const PRIORITY_STYLES = {
  Info:     { bg: 'rgba(59,130,246,0.12)',  fg: '#3b82f6', dot: '#3b82f6', label: 'Info' },
  Success:  { bg: 'rgba(16,185,129,0.12)',  fg: '#10b981', dot: '#10b981', label: 'Success' },
  Warning:  { bg: 'rgba(245,158,11,0.14)',  fg: '#f59e0b', dot: '#f59e0b', label: 'Warning' },
  Critical: { bg: 'rgba(239,68,68,0.14)',   fg: '#ef4444', dot: '#ef4444', label: 'Critical' },
}

export const priorityStyle = (p) => PRIORITY_STYLES[p] || PRIORITY_STYLES.Info

export const STATUS_STYLES = {
  Pending:    { bg: 'rgba(245,158,11,0.14)', fg: '#f59e0b' },
  Processing: { bg: 'rgba(59,130,246,0.12)', fg: '#3b82f6' },
  Sent:       { bg: 'rgba(16,185,129,0.12)', fg: '#10b981' },
  Failed:     { bg: 'rgba(239,68,68,0.14)',  fg: '#ef4444' },
}

export const statusStyle = (s) => STATUS_STYLES[s] || STATUS_STYLES.Pending

export const TYPE_LABEL = { event: 'Event', reminder: 'Reminder', escalation: 'Escalation' }

export const CHANNELS = [
  { key: 'in_app', label: 'In-App', live: true },
  { key: 'email', label: 'Email', live: true },
  { key: 'sms', label: 'SMS', live: false },
  { key: 'whatsapp', label: 'WhatsApp', live: false },
  { key: 'teams', label: 'Teams', live: false },
  { key: 'slack', label: 'Slack', live: false },
  { key: 'push', label: 'Push', live: false },
]

export function timeAgo(iso) {
  if (!iso) return ''
  const then = new Date(iso).getTime()
  const s = Math.floor((Date.now() - then) / 1000)
  if (s < 60) return 'just now'
  const m = Math.floor(s / 60); if (m < 60) return `${m}m ago`
  const h = Math.floor(m / 60); if (h < 24) return `${h}h ago`
  const d = Math.floor(h / 24); if (d < 7) return `${d}d ago`
  return new Date(iso).toLocaleDateString()
}
