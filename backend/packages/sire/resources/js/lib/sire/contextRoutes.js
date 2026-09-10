/**
 * SIRE — route → context map.
 *
 * This is DATA, not logic. Adding a screen means adding one line here; no page
 * component is modified. `resolveRouteContext()` sorts by specificity at import
 * time, so the order of entries in this file does not matter.
 *
 * Fields
 *   pattern      route path, ':name' captures a param, trailing '*' matches any depth
 *   module       stable module key (must exist in MODULE_LABELS)
 *   section      stable section key, e.g. 'leads'
 *   screen       stable screen key, e.g. 'lead-details'
 *   entityType   optional — the record this screen is about
 *   entityParam  optional — which captured param holds the id (default 'id')
 *   *Label       optional — overrides the auto-titleised label
 *
 * !! SEEDED, NOT VERIFIED !!
 * These paths were inferred from the module inventory in the discovery report,
 * not read out of src/app/routes.jsx. Run `node tools/sire-route-audit.mjs
 * <path-to>/src/app/routes.jsx` inside the repo: it prints every real route with
 * no entry here, and every entry here that matches no real route. Fixing the map
 * is a one-file edit and touches nothing else.
 */

export const MODULE_LABELS = {
  hr: 'HR & Recruitment',
  sales: 'Sales',
  customer: 'Customer 360',
  accounts: 'Accounts',
  helpdesk: 'Helpdesk',
  tasks: 'Tasks',
  projects: 'Projects',
  inventory: 'Inventory',
  purchase: 'Purchase',
  tpv: 'TPV / HSSE',
  compliance: 'Compliance',
  shared: 'Shared',
  notifications: 'Notifications',
  settings: 'Settings',
  sire: 'SIRE',
  dashboard: 'Dashboard',
};

/**
 * Entity types whose auto-titleised form reads badly ('Hsse Incident'). Only
 * acronyms and renames belong here; everything else titleises correctly.
 */
export const ENTITY_LABELS = {
  hsse_incident: 'HSSE Incident',
  tpv_vendor: 'Vendor',
  purchase_vendor: 'Vendor',
  purchase_requisition: 'Purchase Requisition',
  purchase_order: 'Purchase Order',
  kb_article: 'KB Article',
  sire_report: 'SIRE Case',
  compliance_checklist: 'Checklist',
  kickoff_meeting: 'Kickoff Meeting',
};

export const ROUTE_CONTEXT_MAP = [
  // ---- dashboard ---------------------------------------------------------
  { pattern: '/app', module: 'dashboard', section: 'home', screen: 'dashboard' },
  { pattern: '/app/dashboard', module: 'dashboard', section: 'home', screen: 'dashboard' },

  // ---- sales -------------------------------------------------------------
  { pattern: '/app/sales', module: 'sales', section: 'overview', screen: 'sales-overview' },
  { pattern: '/app/sales/leads', module: 'sales', section: 'leads', screen: 'lead-list' },
  { pattern: '/app/sales/leads/:id', module: 'sales', section: 'leads', screen: 'lead-details', entityType: 'lead' },
  { pattern: '/app/sales/leads/:id/edit', module: 'sales', section: 'leads', screen: 'lead-edit', entityType: 'lead' },
  { pattern: '/app/sales/deals', module: 'sales', section: 'deals', screen: 'deal-list' },
  { pattern: '/app/sales/deals/:id', module: 'sales', section: 'deals', screen: 'deal-details', entityType: 'deal' },
  { pattern: '/app/sales/quotes', module: 'sales', section: 'quotes', screen: 'quote-list' },
  { pattern: '/app/sales/quotes/:id', module: 'sales', section: 'quotes', screen: 'quote-details', entityType: 'quote' },
  { pattern: '/app/sales/proposals', module: 'sales', section: 'proposals', screen: 'proposal-list' },
  { pattern: '/app/sales/proposals/:id', module: 'sales', section: 'proposals', screen: 'proposal-details', entityType: 'proposal' },
  { pattern: '/app/sales/invoices', module: 'sales', section: 'invoices', screen: 'invoice-list' },
  { pattern: '/app/sales/invoices/:id', module: 'sales', section: 'invoices', screen: 'invoice-details', entityType: 'invoice' },
  { pattern: '/app/sales/commissions', module: 'sales', section: 'commissions', screen: 'commission-list' },

  // ---- customer 360 ------------------------------------------------------
  { pattern: '/app/customer/clients', module: 'customer', section: 'clients', screen: 'client-list' },
  { pattern: '/app/customer/clients/:id', module: 'customer', section: 'clients', screen: 'client-details', entityType: 'client' },
  { pattern: '/app/customer/clients/:id/timeline', module: 'customer', section: 'clients', screen: 'client-timeline', entityType: 'client' },
  { pattern: '/app/customer/contracts', module: 'customer', section: 'contracts', screen: 'contract-list' },
  { pattern: '/app/customer/contracts/:id', module: 'customer', section: 'contracts', screen: 'contract-details', entityType: 'contract' },
  { pattern: '/app/customer/complaints', module: 'customer', section: 'complaints', screen: 'complaint-list' },

  // ---- helpdesk ----------------------------------------------------------
  { pattern: '/app/helpdesk/tickets', module: 'helpdesk', section: 'tickets', screen: 'ticket-list' },
  { pattern: '/app/helpdesk/tickets/:id', module: 'helpdesk', section: 'tickets', screen: 'ticket-details', entityType: 'ticket' },
  { pattern: '/app/helpdesk/kb', module: 'helpdesk', section: 'knowledge-base', screen: 'kb-list' },
  { pattern: '/app/helpdesk/kb/:id', module: 'helpdesk', section: 'knowledge-base', screen: 'kb-article', entityType: 'kb_article' },

  // ---- tasks / projects --------------------------------------------------
  { pattern: '/app/tasks', module: 'tasks', section: 'tasks', screen: 'task-list' },
  { pattern: '/app/tasks/:id', module: 'tasks', section: 'tasks', screen: 'task-details', entityType: 'task' },
  { pattern: '/app/projects', module: 'projects', section: 'projects', screen: 'project-list' },
  { pattern: '/app/projects/:id', module: 'projects', section: 'projects', screen: 'project-details', entityType: 'project' },
  { pattern: '/app/projects/:projectId/milestones', module: 'projects', section: 'milestones', screen: 'milestone-list', entityType: 'project', entityParam: 'projectId' },
  { pattern: '/app/projects/:projectId/tasks/:id', module: 'projects', section: 'project-tasks', screen: 'project-task-details', entityType: 'task' },

  // ---- hr ----------------------------------------------------------------
  { pattern: '/app/hr/candidates', module: 'hr', section: 'recruitment', screen: 'candidate-list' },
  { pattern: '/app/hr/candidates/:id', module: 'hr', section: 'recruitment', screen: 'candidate-details', entityType: 'candidate' },
  { pattern: '/app/hr/employees', module: 'hr', section: 'employees', screen: 'employee-list' },
  { pattern: '/app/hr/employees/:id', module: 'hr', section: 'employees', screen: 'employee-details', entityType: 'employee' },
  { pattern: '/app/hr/leave', module: 'hr', section: 'leave', screen: 'leave-list' },
  { pattern: '/app/hr/attendance', module: 'hr', section: 'attendance', screen: 'attendance-list' },
  { pattern: '/app/hr/onboarding/:id', module: 'hr', section: 'onboarding', screen: 'onboarding-details', entityType: 'onboarding' },

  // ---- accounts ----------------------------------------------------------
  { pattern: '/app/accounts/vouchers', module: 'accounts', section: 'vouchers', screen: 'voucher-list' },
  { pattern: '/app/accounts/vouchers/:id', module: 'accounts', section: 'vouchers', screen: 'voucher-details', entityType: 'voucher' },
  { pattern: '/app/accounts/ledgers', module: 'accounts', section: 'ledgers', screen: 'ledger-list' },
  { pattern: '/app/accounts/ledgers/:id', module: 'accounts', section: 'ledgers', screen: 'ledger-details', entityType: 'ledger' },
  { pattern: '/app/accounts/reconciliation', module: 'accounts', section: 'banking', screen: 'reconciliation' },

  // ---- inventory ---------------------------------------------------------
  { pattern: '/app/inventory/products', module: 'inventory', section: 'products', screen: 'product-list' },
  { pattern: '/app/inventory/products/:id', module: 'inventory', section: 'products', screen: 'product-details', entityType: 'product' },
  { pattern: '/app/inventory/movements', module: 'inventory', section: 'movements', screen: 'movement-list' },
  { pattern: '/app/inventory/cycle-counts', module: 'inventory', section: 'cycle-counts', screen: 'cycle-count-list' },

  // ---- purchase ----------------------------------------------------------
  { pattern: '/app/purchase/requisitions', module: 'purchase', section: 'requisitions', screen: 'pr-list', sectionLabel: 'Purchase Requisitions' },
  { pattern: '/app/purchase/requisitions/:id', module: 'purchase', section: 'requisitions', screen: 'pr-details', entityType: 'purchase_requisition' },
  { pattern: '/app/purchase/rfqs', module: 'purchase', section: 'rfqs', screen: 'rfq-list', sectionLabel: 'RFQs' },
  { pattern: '/app/purchase/orders', module: 'purchase', section: 'orders', screen: 'po-list', screenLabel: 'Purchase Orders' },
  { pattern: '/app/purchase/orders/:id', module: 'purchase', section: 'orders', screen: 'po-details', entityType: 'purchase_order', screenLabel: 'Purchase Order Details' },
  { pattern: '/app/purchase/grn', module: 'purchase', section: 'grn', screen: 'grn-list', sectionLabel: 'Goods Receipt' },
  { pattern: '/app/purchase/vendors/:id', module: 'purchase', section: 'vendors', screen: 'vendor-details', entityType: 'purchase_vendor' },

  // ---- tpv / hsse --------------------------------------------------------
  { pattern: '/app/tpv/vendors', module: 'tpv', section: 'vendors', screen: 'vendor-list' },
  { pattern: '/app/tpv/vendors/:id', module: 'tpv', section: 'vendors', screen: 'vendor-details', entityType: 'tpv_vendor' },
  { pattern: '/app/tpv/workers', module: 'tpv', section: 'workforce', screen: 'worker-list' },
  { pattern: '/app/tpv/permits', module: 'tpv', section: 'permits', screen: 'permit-list' },
  { pattern: '/app/tpv/incidents', module: 'tpv', section: 'hsse', screen: 'incident-list', sectionLabel: 'HSSE' },
  { pattern: '/app/tpv/incidents/:id', module: 'tpv', section: 'hsse', screen: 'incident-details', entityType: 'hsse_incident', sectionLabel: 'HSSE' },

  // ---- compliance / shared / settings ------------------------------------
  { pattern: '/app/compliance/checklists', module: 'compliance', section: 'checklists', screen: 'checklist-list' },
  { pattern: '/app/compliance/checklists/:id', module: 'compliance', section: 'checklists', screen: 'checklist-details', entityType: 'compliance_checklist' },
  { pattern: '/app/shared/meetings', module: 'shared', section: 'meetings', screen: 'meeting-list' },
  { pattern: '/app/shared/meetings/:id', module: 'shared', section: 'meetings', screen: 'meeting-details', entityType: 'kickoff_meeting' },
  { pattern: '/app/notifications', module: 'notifications', section: 'inbox', screen: 'notification-list' },
  { pattern: '/app/settings/*', module: 'settings', section: 'settings', screen: 'settings' },

  // ---- sire itself -------------------------------------------------------
  { pattern: '/app/sire', module: 'sire', section: 'register', screen: 'register-list' },
  { pattern: '/app/sire/cases/:id', module: 'sire', section: 'register', screen: 'case-details', entityType: 'sire_report' },
  { pattern: '/app/sire/actions', module: 'sire', section: 'actions', screen: 'actions-queue' },
  { pattern: '/app/sire/approvals', module: 'sire', section: 'approvals', screen: 'approvals-queue' },
];
