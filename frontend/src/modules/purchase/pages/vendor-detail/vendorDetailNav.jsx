import {
  User, Users, HeartPulse, GraduationCap, ClipboardList,
  FileSignature, FileText, ShoppingBag, Receipt, FileX, FileBarChart2, Wallet,
  FolderKanban, ListChecks, Coins, CalendarClock, Video, CheckSquare, StickyNote,
  Paperclip, LifeBuoy, BookOpen, Lock, BellRing,
  Gauge, Trophy, Gavel, MessageSquare, Share2,
} from 'lucide-react'

/**
 * Purchase Vendor Detail — sidebar navigation model. Single source of truth for
 * both the sidebar (PurchaseVendorDetailLayout) and the nested routes (routes.jsx).
 * 100% Purchase-owned: `key` is the URL segment under /app/purchase/vendors/:id/…,
 * `implemented` items map to a real Purchase-owned tab, the rest render a clean
 * Coming-Soon placeholder. No TPV / shared-Vendor reference anywhere.
 */
export const VENDOR_NAV_GROUPS = [
  {
    title: 'General',
    items: [
      { key: 'profile',    label: 'Profile',    icon: User,          implemented: true },
      { key: 'contacts',   label: 'Contacts',   icon: Users,         implemented: true },
      { key: 'medical',    label: 'Medical',    icon: HeartPulse },
      { key: 'training',   label: 'Training',   icon: GraduationCap },
      { key: 'onboarding', label: 'Onboarding', icon: ClipboardList, implemented: true },
    ],
  },
  {
    title: 'Commercial',
    items: [
      { key: 'quotations',        label: 'Quotations',         icon: FileSignature, implemented: true },
      { key: 'contracts',         label: 'Contracts',          icon: FileText,      implemented: true },
      { key: 'purchase-orders',   label: 'Purchase Order',     icon: ShoppingBag,   implemented: true },
      { key: 'purchase-invoices', label: 'Purchase Invoice',   icon: Receipt,       implemented: true },
      { key: 'debit-notes',       label: 'Debit Notes',        icon: FileX,         implemented: true },
      { key: 'statement',         label: 'Purchase Statement', icon: FileBarChart2 },
      { key: 'payments',          label: 'Payments',           icon: Wallet },
    ],
  },
  {
    title: 'Execution',
    items: [
      { key: 'project',     label: 'Project',     icon: FolderKanban },
      { key: 'tasks',       label: 'Tasks',       icon: ListChecks },
      { key: 'expenses',    label: 'Expenses',    icon: Coins },
      { key: 'appointment', label: 'Appointment', icon: CalendarClock },
      { key: 'meeting',     label: 'Meeting',     icon: Video },
      { key: 'todo',        label: 'Todo Item',   icon: CheckSquare },
      { key: 'notes',       label: 'Notes',       icon: StickyNote },
      { key: 'attachments', label: 'Attachments', icon: Paperclip },
      { key: 'ticket',      label: 'Ticket',      icon: LifeBuoy },
      { key: 'kb',          label: 'KB',          icon: BookOpen },
      { key: 'vault',       label: 'Vault',       icon: Lock },
      { key: 'reminders',   label: 'Reminders',   icon: BellRing },
    ],
  },
  {
    title: 'Performance',
    items: [
      { key: 'risk-score', label: 'Risk Score', icon: Gauge },
      { key: 'award',      label: 'Award',      icon: Trophy },
      { key: 'penalty',    label: 'Penalty',    icon: Gavel },
      { key: 'feedback',   label: 'Feedback',   icon: MessageSquare },
      { key: 'referral',   label: 'Referral',   icon: Share2 },
    ],
  },
]

// Flattened item list — used to generate the nested routes.
export const VENDOR_NAV_ITEMS = VENDOR_NAV_GROUPS.flatMap((g) => g.items)
