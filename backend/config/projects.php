<?php

/*
|--------------------------------------------------------------------------
| Projects module — canonical catalogs (owner: Shivam)
|--------------------------------------------------------------------------
|
| The single source of truth for the per-project "Project Settings" tab:
| the full set of feature TABS a project can switch on/off, the customer
| PERMISSION toggles, and the contact-notification modes. Both the backend
| (validation + defaults) and the frontend (rendering the settings form and
| the workspace tab bar, via GET /projects/meta) read from this list, so the
| two never drift.
|
| `implemented` marks a tab that actually has a working screen today. The
| spec lists ~31 possible tabs (Perfex parity); most have no backing data in
| this CRM yet, so they're catalogued but disabled — shown greyed in the
| settings picker and never rendered in the workspace. Flip a tab to true
| the moment its screen ships (Meeting/Discussions land in a later phase).
|
*/

return [

    // Ordered exactly as the spec lists them (A2). key => label + whether a
    // real screen exists. The order here IS the workspace tab-bar order.
    'tabs' => [
        ['key' => 'overview',          'label' => 'Overview',          'implemented' => true],
        ['key' => 'tasks',             'label' => 'Tasks',             'implemented' => true],
        ['key' => 'domain_manager',    'label' => 'Domain Manager',    'implemented' => false],
        ['key' => 'gantt',             'label' => 'Gantt',             'implemented' => true],
        ['key' => 'meeting',           'label' => 'Meeting',           'implemented' => true],
        ['key' => 'milestones',        'label' => 'Milestones',        'implemented' => true],
        ['key' => 'appointly',         'label' => 'Appointly',         'implemented' => false],
        ['key' => 'todo',              'label' => 'Todo',              'implemented' => false],
        ['key' => 'checklists',        'label' => 'Checklists',        'implemented' => false],
        ['key' => 'forms',             'label' => 'Forms',             'implemented' => false],
        ['key' => 'files',             'label' => 'Files',             'implemented' => true],
        ['key' => 'vendors',           'label' => 'Vendors / TPV',     'implemented' => true],
        ['key' => 'purchase_request',  'label' => 'Purchase request',  'implemented' => false],
        ['key' => 'purchase_order',    'label' => 'Purchase order',    'implemented' => false],
        ['key' => 'purchase_contract', 'label' => 'Purchase contract', 'implemented' => false],
        ['key' => 'discussions',       'label' => 'Discussions',       'implemented' => true],
        ['key' => 'recruitment',       'label' => 'Recruitment',       'implemented' => false],
        ['key' => 'timesheets',        'label' => 'Timesheets',        'implemented' => true],
        ['key' => 'inventory',         'label' => 'Inventory',         'implemented' => false],
        ['key' => 'budget',            'label' => 'Budget',            'implemented' => false],
        ['key' => 'guide',             'label' => 'Guide',             'implemented' => false],
        ['key' => 'tickets',           'label' => 'Tickets',           'implemented' => true],
        ['key' => 'contracts',         'label' => 'Contracts',         'implemented' => false],
        ['key' => 'notes',             'label' => 'Notes',             'implemented' => true],
        ['key' => 'activity',          'label' => 'Activity',          'implemented' => true],
        ['key' => 'proposals',         'label' => 'Proposals',         'implemented' => false],
        ['key' => 'estimates',         'label' => 'Estimates',         'implemented' => false],
        ['key' => 'invoices',          'label' => 'Invoices',          'implemented' => false],
        ['key' => 'expenses',          'label' => 'Expenses',          'implemented' => true],
        ['key' => 'credit_notes',      'label' => 'Credit Notes',      'implemented' => false],
        ['key' => 'subscriptions',     'label' => 'Subscriptions',     'implemented' => false],
    ],

    // Customer-portal permission toggles (A2). Stored on the project; honoured
    // by the (future) client portal — internal staff are never gated by these.
    'customer_permissions' => [
        ['key' => 'view_tasks',               'label' => 'View tasks'],
        ['key' => 'create_tasks',             'label' => 'Create tasks'],
        ['key' => 'edit_tasks',               'label' => 'Edit tasks (only their own)'],
        ['key' => 'comment_tasks',            'label' => 'Comment on tasks'],
        ['key' => 'view_task_comments',       'label' => 'View task comments'],
        ['key' => 'view_task_attachments',    'label' => 'View task attachments'],
        ['key' => 'view_task_checklist',      'label' => 'View task checklist items'],
        ['key' => 'upload_task_attachments',  'label' => 'Upload task attachments'],
        ['key' => 'view_logged_time',         'label' => 'View logged time'],
        ['key' => 'view_finance_overview',    'label' => 'View finance overview'],
        ['key' => 'upload_files',             'label' => 'Upload files'],
        ['key' => 'open_discussions',         'label' => 'Open discussions'],
        ['key' => 'view_milestones',          'label' => 'View milestones'],
        ['key' => 'view_gantt',               'label' => 'View Gantt'],
        ['key' => 'view_timesheets',          'label' => 'View timesheets'],
        ['key' => 'view_activity_log',        'label' => 'View activity log'],
        ['key' => 'view_team_members',        'label' => 'View team members'],
    ],

    // "Send Contacts Notifications" (A2, required select).
    'contacts_notification' => [
        ['key' => 'all',      'label' => 'All contacts with notifications enabled'],
        ['key' => 'specific', 'label' => 'Only specific contacts'],
        ['key' => 'none',     'label' => 'Do not send'],
    ],
];
