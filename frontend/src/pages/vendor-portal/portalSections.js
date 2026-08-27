/**
 * Canonical vendor-portal navigation tree — the single source of truth for BOTH
 * the TPV and the Purchase vendor portals. One registry, rendered against two
 * databases via a portal descriptor (see PortalShell).
 *
 * Each portal descriptor supplies `builtRoutes: { key: 'route-segment' }` listing
 * which sections are actually built in THAT portal and where they live. Any tree
 * node without a built route renders a ComingSoon placeholder at `s/:key`, so the
 * whole tree is always visible and navigable while features are filled in group
 * by group. As a feature ships, add its key to both portals' builtRoutes.
 *
 * Keep labels/keys identical across portals — parity is the rule.
 */
import {
  LayoutDashboard, ClipboardCheck, Gauge, User, Contact, Stethoscope,
  GraduationCap, Users, FileText, FileSignature, ShoppingCart, Receipt,
  FileMinus, BookOpen, Wallet, FolderKanban, ListChecks, Coins, CalendarClock,
  CalendarDays, CheckSquare, StickyNote, Files, LifeBuoy, BookMarked, Lock,
  BellRing, ShieldCheck, HardHat, AlertTriangle, BellDot, Package, Truck,
  Award, Gavel, MessageSquare, Share2, AppWindow, LayoutGrid, Store,
} from 'lucide-react'

/**
 * The full nav tree, in display order. Groups map 1:1 to the sections the user
 * specified: Main → General → Commercial → Execution → Compliance & HSSE →
 * Performance → Extra.
 */
export const PORTAL_NAV = [
  {
    group: 'Main',
    items: [
      { key: 'dashboard',  label: 'Dashboard',  icon: LayoutDashboard },
      { key: 'onboarding', label: 'Onboarding', icon: ClipboardCheck },
    ],
  },
  {
    group: 'General',
    items: [
      { key: 'overview', label: 'Overview', icon: Gauge },
      { key: 'profile',  label: 'Profile',  icon: User },
      { key: 'contact',  label: 'Contact',  icon: Contact },
      { key: 'medical',  label: 'Medical',  icon: Stethoscope },
      { key: 'training', label: 'Training', icon: GraduationCap },
      { key: 'customer', label: 'Customer', icon: Users },
    ],
  },
  {
    group: 'Commercial',
    items: [
      { key: 'quotation',          label: 'Quotation',          icon: FileText },
      { key: 'contracts',          label: 'Contracts',          icon: FileSignature },
      { key: 'purchase-order',     label: 'Purchase Order',     icon: ShoppingCart },
      { key: 'purchase-invoice',   label: 'Purchase Invoice',   icon: Receipt },
      { key: 'debit-notes',        label: 'Debit Notes',        icon: FileMinus },
      { key: 'purchase-statement', label: 'Purchase Statement', icon: BookOpen },
      { key: 'payments',           label: 'Payments',           icon: Wallet },
    ],
  },
  {
    group: 'Execution',
    items: [
      { key: 'project',    label: 'Project',            icon: FolderKanban },
      { key: 'tasks',      label: 'Tasks',              icon: ListChecks },
      { key: 'expenses',   label: 'Expenses',           icon: Coins },
      { key: 'appointment',label: 'Appointment',        icon: CalendarClock },
      { key: 'meeting',    label: 'Meeting',            icon: CalendarDays },
      { key: 'todo',       label: 'ToDo',               icon: CheckSquare },
      { key: 'notes',      label: 'Notes',              icon: StickyNote },
      { key: 'documents',  label: 'Documents / Files',  icon: Files },
      { key: 'ticket',     label: 'Ticket',             icon: LifeBuoy },
      { key: 'kb',         label: 'KB',                 icon: BookMarked },
      { key: 'vault',      label: 'Vault',              icon: Lock },
      { key: 'reminders',  label: 'Reminders',          icon: BellRing },
    ],
  },
  {
    group: 'Compliance & HSSE',
    items: [
      { key: 'comply',         label: 'Comply',    icon: ShieldCheck },
      { key: 'ptw',            label: 'PTW',       icon: HardHat },
      { key: 'incidents',      label: 'Incidents', icon: AlertTriangle },
      { key: 'hsse-documents', label: 'Documents', icon: FileText },
      { key: 'pre-alert',      label: 'Pre Alert', icon: BellDot },
      { key: 'packages',       label: 'Packages',  icon: Package },
      { key: 'shipping',       label: 'Shipping',  icon: Truck },
    ],
  },
  {
    group: 'Performance',
    items: [
      { key: 'risk-score', label: 'Risk Score',     icon: Gauge },
      { key: 'award',      label: 'Award / Reward', icon: Award },
      { key: 'penalty',    label: 'Penalty',        icon: Gavel },
      { key: 'feedback',   label: 'Feedback',       icon: MessageSquare },
      { key: 'referral',   label: 'Referral',       icon: Share2 },
    ],
  },
  {
    group: 'Extra',
    items: [
      { key: 'apps',      label: 'Apps',      icon: AppWindow },
      { key: 'widgets',   label: 'Widgets',   icon: LayoutGrid },
      { key: 'ecommerce', label: 'Ecommerce', icon: Store },
    ],
  },
]

/** Flat { key: { label, group } } lookup — used by the ComingSoon placeholder. */
export const SECTION_INDEX = PORTAL_NAV.reduce((acc, { group, items }) => {
  items.forEach(it => { acc[it.key] = { label: it.label, group } })
  return acc
}, {})

/**
 * Resolve the tree into rendered nav groups for one portal.
 *
 * @param {object}  o
 * @param {string}  o.base          e.g. '/vendor-portal'
 * @param {object}  o.builtRoutes   { key: 'route-segment' } for built sections
 * @param {object}  o.vendor        the loaded vendor (for gating)
 * @returns groups of { group, items: [{ key, label, icon, to, built, gated }] }
 */
export function resolveNav({ base, builtRoutes = {}, vendor }) {
  return PORTAL_NAV.map(({ group, items }) => ({
    group,
    items: items
      .filter(it => !it.gate || it.gate(vendor))
      .map(it => {
        const seg = builtRoutes[it.key]
        return {
          ...it,
          built: Boolean(seg),
          to: seg ? `${base}/${seg}` : `${base}/s/${it.key}`,
        }
      }),
  }))
}
