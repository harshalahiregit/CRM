// Friendly names and colours for a trip's status, in the words a driver uses.
import { theme } from './theme'

const LABELS = {
  draft: 'Draft',
  viability_pending: 'Being checked',
  approved: 'Approved',
  allocated: 'Truck assigned',
  pretrip_ok: 'Ready to go',
  dispatched: 'Dispatched',
  in_transit: 'On the road',
  delivered: 'Delivered',
  closed: 'Closed',
  cancelled: 'Cancelled',
  rejected: 'Rejected',
}

export const statusLabel = (s) =>
  LABELS[s] || String(s || '').replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase())

export const statusColor = (s) => {
  if (['delivered', 'closed'].includes(s)) return theme.success
  if (['in_transit', 'dispatched'].includes(s)) return theme.accent
  if (['rejected', 'cancelled'].includes(s)) return theme.danger
  if (['allocated', 'pretrip_ok', 'approved'].includes(s)) return theme.warning
  return theme.textMuted
}

// The journey, in order, for the read-only progress strip. Departed/arrived/
// delivered will become tappable once Dev 1 adds driver-reportable events.
export const JOURNEY = [
  { key: 'allocated', label: 'Truck assigned' },
  { key: 'dispatched', label: 'Dispatched' },
  { key: 'in_transit', label: 'On the road' },
  { key: 'delivered', label: 'Delivered' },
]

export const journeyIndex = (status) => {
  const order = ['allocated', 'pretrip_ok', 'dispatched', 'in_transit', 'delivered', 'closed']
  const i = order.indexOf(status)
  if (i < 0) return 0
  if (status === 'pretrip_ok') return 0
  if (status === 'closed') return 3
  return Math.min(JOURNEY.findIndex((j) => j.key === status), 3)
}
