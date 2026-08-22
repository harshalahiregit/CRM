<?php

/*
| Purchase Meetings — the Purchase-side mirror of config/meetings.php (parity
| rule). Kickoff is ONE configurable meeting type, not a separate module
| (Sangoe TPV §9 / §39). The key is what is stored in
| purchase_kickoff_meetings.meeting_type. Config-driven source of truth until a
| tenant-editable Settings UI (later slice) layers over it.
*/
return [

    'default_type' => 'kickoff',

    'types' => [
        'kickoff'             => 'Kickoff Meeting',
        'weekly_coordination' => 'Weekly Coordination Meeting',
        'daily_coordination'  => 'Daily Coordination Meeting',
        'hse'                 => 'HSE Meeting',
        'toolbox'             => 'Toolbox / Safety Meeting',
        'progress_review'     => 'Progress Review',
        'vendor_review'       => 'Vendor Review',
        'performance_review'  => 'Performance Review',
        'compliance_review'   => 'Compliance Review',
        'audit_review'        => 'Audit Review',
        'incident_review'     => 'Incident Review',
        'ncr_review'          => 'NCR Review',
        'capa_review'         => 'CAPA Review',
        'technical'           => 'Technical Meeting',
        'commercial'          => 'Commercial Meeting',
        'procurement'         => 'Procurement Meeting',
        'workforce_review'    => 'Workforce Review',
        'client'              => 'Client Meeting',
        'emergency'           => 'Emergency Meeting',
        'management_review'   => 'Management Review',
        'monthly_review'      => 'Monthly Review',
        'quarterly_review'    => 'Quarterly Review',
        'closure'             => 'Closure Meeting',
        'other'               => 'Other',
    ],

    /*
    | Standard agenda by meeting type — one-click load into the Agenda Builder,
    | then edit. A type with no entry simply has no template. Mirrors the TPV set.
    */
    'templates' => [
        'kickoff' => [
            ['item' => 'Introductions & roles',                'duration_minutes' => 10, 'priority' => 'Medium'],
            ['item' => 'Scope of work & deliverables',         'duration_minutes' => 20, 'priority' => 'High'],
            ['item' => 'HSE requirements & site rules',        'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Documentation & compliance checklist', 'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Schedule, milestones & mobilisation',  'duration_minutes' => 15, 'priority' => 'Medium'],
            ['item' => 'Communication & escalation matrix',    'duration_minutes' => 10, 'priority' => 'Medium'],
            ['item' => 'Actions, owners & next meeting',       'duration_minutes' => 10, 'priority' => 'Medium'],
        ],
        'vendor_review' => [
            ['item' => 'Performance vs KPIs / SLAs',           'duration_minutes' => 20, 'priority' => 'High'],
            ['item' => 'Compliance & documentation status',    'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'HSE & incident record',                'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Commercial & invoicing matters',       'duration_minutes' => 10, 'priority' => 'Medium'],
            ['item' => 'Improvement actions & owners',         'duration_minutes' => 10, 'priority' => 'Medium'],
        ],
        'commercial' => [
            ['item' => 'Pricing & rate review',                'duration_minutes' => 20, 'priority' => 'High'],
            ['item' => 'Open POs & delivery status',           'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Invoicing & payment matters',          'duration_minutes' => 15, 'priority' => 'Medium'],
            ['item' => 'Disputes & credit notes',              'duration_minutes' => 10, 'priority' => 'Medium'],
            ['item' => 'Actions & owners',                     'duration_minutes' => 10, 'priority' => 'Medium'],
        ],
        'progress_review' => [
            ['item' => 'Progress vs baseline schedule',        'duration_minutes' => 20, 'priority' => 'High'],
            ['item' => 'Milestones achieved & upcoming',       'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Delays, causes & recovery plan',       'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Actions & owners',                     'duration_minutes' => 10, 'priority' => 'Medium'],
        ],
        'closure' => [
            ['item' => 'Scope completion & sign-off',          'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Outstanding actions & snags',          'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Final documentation & handover',       'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Commercial closeout',                  'duration_minutes' => 10, 'priority' => 'Medium'],
            ['item' => 'Lessons learned',                      'duration_minutes' => 10, 'priority' => 'Medium'],
        ],
    ],

    'priorities'         => ['Low', 'Medium', 'High'],
    'meeting_priorities' => ['Low', 'Medium', 'High', 'Urgent'],
    'confidentiality'    => ['Public', 'Internal', 'Confidential', 'Restricted'],
    'modes'              => ['onsite', 'online', 'hybrid'],

    'issue_severities'   => ['Low', 'Medium', 'High', 'Critical'],
    'issue_categories'   => ['Safety', 'Compliance', 'Quality', 'Commercial', 'Workforce', 'Schedule', 'Technical', 'Environmental', 'Other'],
    'decision_statuses'  => ['Active', 'Superseded', 'Rescinded'],
];
