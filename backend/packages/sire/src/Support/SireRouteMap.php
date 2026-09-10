<?php

namespace Sire\Support;

/**
 * SIRE — the route → context map, server side.
 *
 * GENERATED FILE. Do not edit.
 * Source:    resources/js/lib/sire/contextRoutes.js
 * Generator: tools/generate-route-map.mjs
 * Guarded by tests/route-map-sync.test.mjs, which fails the build if this file
 * and the JavaScript map disagree.
 *
 * The map is SEEDED from the CRM discovery report, not read from the CRM's route
 * registry. Reconcile it with `node tools/sire-route-audit.mjs <routes-file>`,
 * edit the JAVASCRIPT map, and re-run the generator.
 *
 * 70 routes, 16 modules.
 */
final class SireRouteMap
{
    /** @var array<int, array<string, string>> */
    public const ROUTES = [
        ['pattern' => '/app', 'module' => 'dashboard', 'section' => 'home', 'screen' => 'dashboard'],
        ['pattern' => '/app/dashboard', 'module' => 'dashboard', 'section' => 'home', 'screen' => 'dashboard'],
        ['pattern' => '/app/sales', 'module' => 'sales', 'section' => 'overview', 'screen' => 'sales-overview'],
        ['pattern' => '/app/sales/leads', 'module' => 'sales', 'section' => 'leads', 'screen' => 'lead-list'],
        ['pattern' => '/app/sales/leads/:id', 'module' => 'sales', 'section' => 'leads', 'screen' => 'lead-details', 'entity_type' => 'lead'],
        ['pattern' => '/app/sales/leads/:id/edit', 'module' => 'sales', 'section' => 'leads', 'screen' => 'lead-edit', 'entity_type' => 'lead'],
        ['pattern' => '/app/sales/deals', 'module' => 'sales', 'section' => 'deals', 'screen' => 'deal-list'],
        ['pattern' => '/app/sales/deals/:id', 'module' => 'sales', 'section' => 'deals', 'screen' => 'deal-details', 'entity_type' => 'deal'],
        ['pattern' => '/app/sales/quotes', 'module' => 'sales', 'section' => 'quotes', 'screen' => 'quote-list'],
        ['pattern' => '/app/sales/quotes/:id', 'module' => 'sales', 'section' => 'quotes', 'screen' => 'quote-details', 'entity_type' => 'quote'],
        ['pattern' => '/app/sales/proposals', 'module' => 'sales', 'section' => 'proposals', 'screen' => 'proposal-list'],
        ['pattern' => '/app/sales/proposals/:id', 'module' => 'sales', 'section' => 'proposals', 'screen' => 'proposal-details', 'entity_type' => 'proposal'],
        ['pattern' => '/app/sales/invoices', 'module' => 'sales', 'section' => 'invoices', 'screen' => 'invoice-list'],
        ['pattern' => '/app/sales/invoices/:id', 'module' => 'sales', 'section' => 'invoices', 'screen' => 'invoice-details', 'entity_type' => 'invoice'],
        ['pattern' => '/app/sales/commissions', 'module' => 'sales', 'section' => 'commissions', 'screen' => 'commission-list'],
        ['pattern' => '/app/customer/clients', 'module' => 'customer', 'section' => 'clients', 'screen' => 'client-list'],
        ['pattern' => '/app/customer/clients/:id', 'module' => 'customer', 'section' => 'clients', 'screen' => 'client-details', 'entity_type' => 'client'],
        ['pattern' => '/app/customer/clients/:id/timeline', 'module' => 'customer', 'section' => 'clients', 'screen' => 'client-timeline', 'entity_type' => 'client'],
        ['pattern' => '/app/customer/contracts', 'module' => 'customer', 'section' => 'contracts', 'screen' => 'contract-list'],
        ['pattern' => '/app/customer/contracts/:id', 'module' => 'customer', 'section' => 'contracts', 'screen' => 'contract-details', 'entity_type' => 'contract'],
        ['pattern' => '/app/customer/complaints', 'module' => 'customer', 'section' => 'complaints', 'screen' => 'complaint-list'],
        ['pattern' => '/app/helpdesk/tickets', 'module' => 'helpdesk', 'section' => 'tickets', 'screen' => 'ticket-list'],
        ['pattern' => '/app/helpdesk/tickets/:id', 'module' => 'helpdesk', 'section' => 'tickets', 'screen' => 'ticket-details', 'entity_type' => 'ticket'],
        ['pattern' => '/app/helpdesk/kb', 'module' => 'helpdesk', 'section' => 'knowledge-base', 'screen' => 'kb-list'],
        ['pattern' => '/app/helpdesk/kb/:id', 'module' => 'helpdesk', 'section' => 'knowledge-base', 'screen' => 'kb-article', 'entity_type' => 'kb_article'],
        ['pattern' => '/app/tasks', 'module' => 'tasks', 'section' => 'tasks', 'screen' => 'task-list'],
        ['pattern' => '/app/tasks/:id', 'module' => 'tasks', 'section' => 'tasks', 'screen' => 'task-details', 'entity_type' => 'task'],
        ['pattern' => '/app/projects', 'module' => 'projects', 'section' => 'projects', 'screen' => 'project-list'],
        ['pattern' => '/app/projects/:id', 'module' => 'projects', 'section' => 'projects', 'screen' => 'project-details', 'entity_type' => 'project'],
        ['pattern' => '/app/projects/:projectId/milestones', 'module' => 'projects', 'section' => 'milestones', 'screen' => 'milestone-list', 'entity_type' => 'project', 'entity_param' => 'projectId'],
        ['pattern' => '/app/projects/:projectId/tasks/:id', 'module' => 'projects', 'section' => 'project-tasks', 'screen' => 'project-task-details', 'entity_type' => 'task'],
        ['pattern' => '/app/hr/candidates', 'module' => 'hr', 'section' => 'recruitment', 'screen' => 'candidate-list'],
        ['pattern' => '/app/hr/candidates/:id', 'module' => 'hr', 'section' => 'recruitment', 'screen' => 'candidate-details', 'entity_type' => 'candidate'],
        ['pattern' => '/app/hr/employees', 'module' => 'hr', 'section' => 'employees', 'screen' => 'employee-list'],
        ['pattern' => '/app/hr/employees/:id', 'module' => 'hr', 'section' => 'employees', 'screen' => 'employee-details', 'entity_type' => 'employee'],
        ['pattern' => '/app/hr/leave', 'module' => 'hr', 'section' => 'leave', 'screen' => 'leave-list'],
        ['pattern' => '/app/hr/attendance', 'module' => 'hr', 'section' => 'attendance', 'screen' => 'attendance-list'],
        ['pattern' => '/app/hr/onboarding/:id', 'module' => 'hr', 'section' => 'onboarding', 'screen' => 'onboarding-details', 'entity_type' => 'onboarding'],
        ['pattern' => '/app/accounts/vouchers', 'module' => 'accounts', 'section' => 'vouchers', 'screen' => 'voucher-list'],
        ['pattern' => '/app/accounts/vouchers/:id', 'module' => 'accounts', 'section' => 'vouchers', 'screen' => 'voucher-details', 'entity_type' => 'voucher'],
        ['pattern' => '/app/accounts/ledgers', 'module' => 'accounts', 'section' => 'ledgers', 'screen' => 'ledger-list'],
        ['pattern' => '/app/accounts/ledgers/:id', 'module' => 'accounts', 'section' => 'ledgers', 'screen' => 'ledger-details', 'entity_type' => 'ledger'],
        ['pattern' => '/app/accounts/reconciliation', 'module' => 'accounts', 'section' => 'banking', 'screen' => 'reconciliation'],
        ['pattern' => '/app/inventory/products', 'module' => 'inventory', 'section' => 'products', 'screen' => 'product-list'],
        ['pattern' => '/app/inventory/products/:id', 'module' => 'inventory', 'section' => 'products', 'screen' => 'product-details', 'entity_type' => 'product'],
        ['pattern' => '/app/inventory/movements', 'module' => 'inventory', 'section' => 'movements', 'screen' => 'movement-list'],
        ['pattern' => '/app/inventory/cycle-counts', 'module' => 'inventory', 'section' => 'cycle-counts', 'screen' => 'cycle-count-list'],
        ['pattern' => '/app/purchase/requisitions', 'module' => 'purchase', 'section' => 'requisitions', 'screen' => 'pr-list'],
        ['pattern' => '/app/purchase/requisitions/:id', 'module' => 'purchase', 'section' => 'requisitions', 'screen' => 'pr-details', 'entity_type' => 'purchase_requisition'],
        ['pattern' => '/app/purchase/rfqs', 'module' => 'purchase', 'section' => 'rfqs', 'screen' => 'rfq-list'],
        ['pattern' => '/app/purchase/orders', 'module' => 'purchase', 'section' => 'orders', 'screen' => 'po-list'],
        ['pattern' => '/app/purchase/orders/:id', 'module' => 'purchase', 'section' => 'orders', 'screen' => 'po-details', 'entity_type' => 'purchase_order'],
        ['pattern' => '/app/purchase/grn', 'module' => 'purchase', 'section' => 'grn', 'screen' => 'grn-list'],
        ['pattern' => '/app/purchase/vendors/:id', 'module' => 'purchase', 'section' => 'vendors', 'screen' => 'vendor-details', 'entity_type' => 'purchase_vendor'],
        ['pattern' => '/app/tpv/vendors', 'module' => 'tpv', 'section' => 'vendors', 'screen' => 'vendor-list'],
        ['pattern' => '/app/tpv/vendors/:id', 'module' => 'tpv', 'section' => 'vendors', 'screen' => 'vendor-details', 'entity_type' => 'tpv_vendor'],
        ['pattern' => '/app/tpv/workers', 'module' => 'tpv', 'section' => 'workforce', 'screen' => 'worker-list'],
        ['pattern' => '/app/tpv/permits', 'module' => 'tpv', 'section' => 'permits', 'screen' => 'permit-list'],
        ['pattern' => '/app/tpv/incidents', 'module' => 'tpv', 'section' => 'hsse', 'screen' => 'incident-list'],
        ['pattern' => '/app/tpv/incidents/:id', 'module' => 'tpv', 'section' => 'hsse', 'screen' => 'incident-details', 'entity_type' => 'hsse_incident'],
        ['pattern' => '/app/compliance/checklists', 'module' => 'compliance', 'section' => 'checklists', 'screen' => 'checklist-list'],
        ['pattern' => '/app/compliance/checklists/:id', 'module' => 'compliance', 'section' => 'checklists', 'screen' => 'checklist-details', 'entity_type' => 'compliance_checklist'],
        ['pattern' => '/app/shared/meetings', 'module' => 'shared', 'section' => 'meetings', 'screen' => 'meeting-list'],
        ['pattern' => '/app/shared/meetings/:id', 'module' => 'shared', 'section' => 'meetings', 'screen' => 'meeting-details', 'entity_type' => 'kickoff_meeting'],
        ['pattern' => '/app/notifications', 'module' => 'notifications', 'section' => 'inbox', 'screen' => 'notification-list'],
        ['pattern' => '/app/settings/*', 'module' => 'settings', 'section' => 'settings', 'screen' => 'settings'],
        ['pattern' => '/app/sire', 'module' => 'sire', 'section' => 'register', 'screen' => 'register-list'],
        ['pattern' => '/app/sire/cases/:id', 'module' => 'sire', 'section' => 'register', 'screen' => 'case-details', 'entity_type' => 'sire_report'],
        ['pattern' => '/app/sire/actions', 'module' => 'sire', 'section' => 'actions', 'screen' => 'actions-queue'],
        ['pattern' => '/app/sire/approvals', 'module' => 'sire', 'section' => 'approvals', 'screen' => 'approvals-queue'],
    ];

    /** @var array<string, string> */
    public const MODULE_LABELS = [
        'hr' => 'HR & Recruitment',
        'sales' => 'Sales',
        'customer' => 'Customer 360',
        'accounts' => 'Accounts',
        'helpdesk' => 'Helpdesk',
        'tasks' => 'Tasks',
        'projects' => 'Projects',
        'inventory' => 'Inventory',
        'purchase' => 'Purchase',
        'tpv' => 'TPV / HSSE',
        'compliance' => 'Compliance',
        'shared' => 'Shared',
        'notifications' => 'Notifications',
        'settings' => 'Settings',
        'sire' => 'SIRE',
        'dashboard' => 'Dashboard',
    ];
}
