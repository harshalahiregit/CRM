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
 * `key` is the URL segment under /app/purchase/vendors/:id/….
 *
 * NOTE: `implemented` is documentation only — nothing reads it. The real switch
 * is membership in TAB_ELEMENTS (vendorDetailTabs.jsx), which the layout indexes
 * by this key and falls back to ComingSoonTab for. Flipping a flag here without
 * adding the TAB_ELEMENTS entry ships a Coming-Soon page; the flags are kept in
 * step so the file does not mislead.
 *
 * 21 of 29 are active. The 8 without a flag have no backing table anywhere in the
 * schema — see the note against each — so they are unbuilt features, not gaps in
 * the wiring.
 */
export const VENDOR_NAV_GROUPS = [
  {
    title: 'General',
    items: [
      { key: 'profile',    label: 'Profile',    icon: User,          implemented: true },
      { key: 'contacts',   label: 'Contacts',   icon: Users,         implemented: true },
      { key: 'medical',    label: 'Medical',    icon: HeartPulse,    implemented: true },
      { key: 'training',   label: 'Training',   icon: GraduationCap, implemented: true },
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
      { key: 'statement',         label: 'Purchase Statement', icon: FileBarChart2, implemented: true },
      { key: 'payments',          label: 'Payments',           icon: Wallet,        implemented: true },
    ],
  },
  {
    title: 'Execution',
    items: [
      { key: 'project',     label: 'Project',     icon: FolderKanban,  implemented: true },
      { key: 'tasks',       label: 'Tasks',       icon: ListChecks,    implemented: true },
      { key: 'expenses',    label: 'Expenses',    icon: Coins,         implemented: true },
      { key: 'appointment', label: 'Appointment', icon: CalendarClock, implemented: true },
      { key: 'meeting',     label: 'Meeting',     icon: Video,         implemented: true },
      // No todo table exists. tasks/task_checklist_items belong to the Task
      // module and neither is vendor-scoped.
      { key: 'todo',        label: 'Todo Item',   icon: CheckSquare },
      { key: 'notes',       label: 'Notes',       icon: StickyNote,    implemented: true },
      { key: 'attachments', label: 'Attachments', icon: Paperclip,     implemented: true },
      { key: 'ticket',      label: 'Ticket',      icon: LifeBuoy,      implemented: true },
      // kb_articles are tenant-wide help content with no vendor link.
      { key: 'kb',          label: 'KB',          icon: BookOpen },
      // client_vault_entries is Customer-owned; there is no vendor vault.
      { key: 'vault',       label: 'Vault',       icon: Lock },
      { key: 'reminders',   label: 'Reminders',   icon: BellRing,      implemented: true },
    ],
  },
  {
    // None of these five has a backing table. Every score / feedback / referral
    // table in the schema is foreign-keyed to another domain — hr_employee_scores,
    // ticket_feedback (ticket_id), kb_article_feedback, hr_candidates.referred_by —
    // and none is polymorphic, so none can hold a vendor. purchase_vendors itself
    // carries no score, rating or risk column. These need new tables plus the
    // business rules to fill them, not wiring.
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
