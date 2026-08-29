import {
  LayoutDashboard, User, Users, Building2, HeartPulse, GraduationCap, ClipboardList,
  FileSignature, FileText, ShoppingBag, Receipt, FileX, FileBarChart2, Wallet,
  FolderKanban, ListChecks, Coins, CalendarClock, Video, CheckSquare, StickyNote,
  Paperclip, LifeBuoy, BookOpen, Lock, BellRing,
  Gauge, Trophy, Gavel, MessageSquare, Share2,
  ClipboardCheck, ShieldCheck,
} from 'lucide-react'

/**
 * Purchase Vendor Detail — sidebar navigation model. Single source of truth for
 * both the sidebar (PurchaseVendorDetailLayout) and the nested routes (routes.jsx).
 * `key` is the URL segment under /app/purchase/vendors/:id/….
 *
 * This is the CATALOGUE of sections, not the list that gets rendered. The
 * layout filters it against TAB_ELEMENTS (vendorDetailTabs.jsx) and offers only
 * what exists, so adding an item here shows nothing until its TAB_ELEMENTS
 * entry lands — which is the point. There used to be an `implemented` flag
 * here that nothing read; it had drifted, and 8 items were rendering live
 * NavLinks onto a Coming-Soon placeholder. It is gone: TAB_ELEMENTS is the
 * single source of truth and cannot disagree with itself.
 *
 * 23 of the 31 below are built (incl. the Compliance group's Prequalification
 * and Due Diligence). The other 8 (todo, kb, vault, risk-score, award, penalty,
 * feedback, referral) have no backing table anywhere in the schema — they are
 * unbuilt features rather than gaps in the wiring, and they stay listed here so
 * the intended shape of the workspace is still recorded.
 */
export const VENDOR_NAV_GROUPS = [
  {
    title: 'General',
    items: [
      { key: 'overview',   label: 'Overview',   icon: LayoutDashboard },
      { key: 'profile',    label: 'Profile',    icon: User },
      { key: 'contacts',   label: 'Contacts',   icon: Users },
      { key: 'customer',   label: 'Customer',   icon: Building2 },
      { key: 'medical',    label: 'Medical',    icon: HeartPulse },
      { key: 'training',   label: 'Training',   icon: GraduationCap },
      { key: 'onboarding', label: 'Onboarding', icon: ClipboardList },
    ],
  },
  {
    title: 'Commercial',
    items: [
      { key: 'quotations',        label: 'Quotations',         icon: FileSignature },
      { key: 'contracts',         label: 'Contracts',          icon: FileText },
      { key: 'purchase-orders',   label: 'Purchase Order',     icon: ShoppingBag },
      { key: 'purchase-invoices', label: 'Purchase Invoice',   icon: Receipt },
      { key: 'debit-notes',       label: 'Debit Notes',        icon: FileX },
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
      // No todo table exists. tasks/task_checklist_items belong to the Task
      // module and neither is vendor-scoped.
      { key: 'todo',        label: 'Todo Item',   icon: CheckSquare },
      { key: 'notes',       label: 'Notes',       icon: StickyNote },
      { key: 'attachments', label: 'Attachments', icon: Paperclip },
      { key: 'ticket',      label: 'Ticket',      icon: LifeBuoy },
      // kb_articles are tenant-wide help content with no vendor link.
      { key: 'kb',          label: 'KB',          icon: BookOpen },
      // client_vault_entries is Customer-owned; there is no vendor vault.
      { key: 'vault',       label: 'Vault',       icon: Lock },
      { key: 'reminders',   label: 'Reminders',   icon: BellRing },
    ],
  },
  {
    // Compliance — Purchase-native prequalification (scored questionnaire) and
    // the due-diligence verification checklist, the mirror of the TPV workspace's
    // Compliance group. Both are backed by their own Purchase tables/columns.
    title: 'Compliance',
    items: [
      { key: 'prequalification', label: 'Prequalification', icon: ClipboardCheck },
      { key: 'due-diligence',    label: 'Due Diligence',    icon: ShieldCheck },
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
