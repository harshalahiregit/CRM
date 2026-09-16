import {
  LayoutDashboard, User, Users, Building2, HeartPulse, GraduationCap,
  FileSignature, FileText, ShoppingBag, Receipt, FileX, FileBarChart2, Wallet,
  FolderKanban, ListChecks, Coins, Video, CheckSquare, StickyNote,
  Paperclip, LifeBuoy, Lock, BellRing, Briefcase, Wrench, PackageCheck, Bell,
  Gauge, Trophy, Gavel, MessageSquare, Share2, BarChart3,
  ClipboardCheck, ShieldCheck, HardHat, DoorOpen, AlertOctagon,
  Boxes, RefreshCw, LogOut, ClipboardList, Home,
} from 'lucide-react'

/**
 * Purchase Vendor Detail — sidebar navigation model. Single source of truth for
 * both the sidebar (PurchaseVendorDetailLayout) and the nested routes (routes.jsx).
 * `key` is the URL segment under /app/purchase/vendors/:id/….
 *
 * THIS IS A MIRROR OF THE TPV VENDOR SIDEBAR. Same six groups, same order, same
 * labels, item for item. The two workspaces are the same job done against two
 * vendor masters, and every place they drifted apart cost somebody time working
 * out whether a missing entry meant a missing feature or just a different word:
 * Quotation vs Quotations, Debit Note vs Debit Notes, Project vs Projects,
 * Referral vs Referrals, Operations vs Execution, Contact vs Contacts.
 *
 * If TPV gains or renames an item, change it here too. A difference between
 * these two lists should mean a real difference in what the module can do —
 * never a difference in vocabulary.
 *
 * The layout renders only the entries TAB_ELEMENTS backs, so an item listed
 * here and not built shows nothing rather than a dead link. Those are marked
 * below with the reason, exactly as TPV marks its own.
 *
 * Three Purchase-only screens are deliberately NOT listed, because TPV has no
 * counterpart and this list is a mirror: Onboarding, Due Diligence and
 * Agreements. Their routes and components are untouched and still reachable —
 * onboarding from the decision panel at the top of this workspace and from the
 * Vendor Onboarding screen, due diligence from the Compliance register, and
 * agreements from the Contract module.
 *
 * NOT every entry here is shown at once. Until a vendor is onboarded the layout
 * narrows this list to the four sections that step needs — see
 * lib/vendors/workspaceLock. Everything below is still routed and still
 * reachable by URL; the lock decides what the sidebar offers, not what exists.
 */
export const VENDOR_NAV_GROUPS = [
  {
    title: 'General',
    items: [
      { key: 'overview', label: 'Overview', icon: LayoutDashboard },
      { key: 'profile',  label: 'Profile',  icon: User },
      { key: 'contacts', label: 'Contact',  icon: Users },
      { key: 'customer', label: 'Customer', icon: Building2 },
      // TPV keeps meetings in General. Purchase had it in Operations, which is
      // the same screen two clicks further from where anybody looks for it.
      { key: 'meeting',  label: 'Meetings', icon: Video },
    ],
  },
  {
    title: 'Workforce',
    items: [
      { key: 'workforce', label: 'Workforce', icon: HardHat },
      { key: 'medical',   label: 'Medical',   icon: HeartPulse },
      { key: 'training',  label: 'Training',  icon: GraduationCap },
      { key: 'gate-log',  label: 'Gate Log',  icon: DoorOpen },
      { key: 'strikes',   label: 'Strikes',   icon: AlertOctagon },
    ],
  },
  {
    title: 'Commercial',
    items: [
      { key: 'quotations',        label: 'Quotation',          icon: FileSignature },
      { key: 'contracts',         label: 'Contracts',          icon: FileText },
      { key: 'purchase-orders',   label: 'Purchase Order',     icon: ShoppingBag },
      { key: 'purchase-invoices', label: 'Purchase Invoice',   icon: Receipt },
      { key: 'debit-notes',       label: 'Debit Note',         icon: FileX },
      { key: 'statement',         label: 'Purchase Statement', icon: FileBarChart2 },
      { key: 'payments',          label: 'Payments',           icon: Wallet },
    ],
  },
  {
    title: 'Operations',
    items: [
      { key: 'project',       label: 'Projects',      icon: FolderKanban },
      // Shed projects are a TPV concept (a shed is a TPV work location). No
      // Purchase table carries one.
      { key: 'shed-projects', label: 'Shed Projects', icon: Home },
      { key: 'work-packages', label: 'Work Packages', icon: Boxes },
      { key: 'tasks',         label: 'Tasks',         icon: ListChecks },
      { key: 'expenses',      label: 'Expenses',      icon: Coins },
      { key: 'attachments',   label: 'Attachments',   icon: Paperclip },
      // No todo table exists on either side. tasks/task_checklist_items belong
      // to the Task module and neither is vendor-scoped.
      { key: 'todo',          label: 'ToDo',          icon: CheckSquare },
      { key: 'notes',         label: 'Notes',         icon: StickyNote },
      // Unbuilt on TPV too — awaiting a business definition.
      { key: 'tech-file',     label: 'Technical File Maintenance', icon: Wrench },
      { key: 'ticket',        label: 'Ticket',        icon: LifeBuoy },
      // Unbuilt on TPV too: `jobs` is the queue table and hr_job_* is recruitment.
      { key: 'job',           label: 'Job',           icon: Briefcase },
      { key: 'reminders',     label: 'Reminders',     icon: BellRing },
    ],
  },
  {
    title: 'Compliance',
    items: [
      { key: 'documents',           label: 'Documents',           icon: FileText },
      { key: 'prequalification',    label: 'Prequalification',    icon: ClipboardCheck },
      { key: 'compliance-register', label: 'Compliance Register', icon: ShieldCheck },
      { key: 'inspections',         label: 'Inspections',         icon: ClipboardCheck },
      { key: 'ncr',                 label: 'NCR',                 icon: FileX },
      { key: 'capa',                label: 'CAPA',                icon: CheckSquare },
      // client_vault_entries is Customer-owned; there is no vendor vault.
      { key: 'vault',               label: 'Vault',               icon: Lock },
      // Unbuilt on TPV too.
      { key: 'survey',              label: 'Survey',              icon: ClipboardList },
      { key: 'ptw',                 label: 'PTW',                 icon: Lock },
      { key: 'incidents',           label: 'Incidents',           icon: AlertOctagon },
      // Pre-alerts and packages are TPV shipment concepts with no Purchase table.
      { key: 'pre-alert',           label: 'Pre Alert',           icon: Bell },
      { key: 'package',             label: 'Package',             icon: PackageCheck },
      { key: 'visitors',            label: 'Visitors',            icon: Users },
    ],
  },
  {
    title: 'Performance',
    items: [
      { key: 'risk-score',        label: 'Risk Score',        icon: Gauge },
      { key: 'performance-index', label: 'Performance Index', icon: BarChart3 },
      { key: 'renewal',           label: 'Renewal',           icon: RefreshCw },
      { key: 'offboarding',       label: 'Offboarding',       icon: LogOut },
      { key: 'award',             label: 'Award / Reward',    icon: Trophy },
      { key: 'penalty',           label: 'Penalty',           icon: Gavel },
      { key: 'feedback',          label: 'Feedback',          icon: MessageSquare },
      { key: 'referral',          label: 'Referrals',         icon: Share2 },
    ],
  },
]

// Flattened item list — used to generate the nested routes.
export const VENDOR_NAV_ITEMS = VENDOR_NAV_GROUPS.flatMap((g) => g.items)
