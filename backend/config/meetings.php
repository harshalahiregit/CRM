<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Meeting types
    |--------------------------------------------------------------------------
    |
    | Kickoff is now ONE meeting type, not a separate module (Meeting.docx §2).
    | Each entry: key => human label. The key is what is stored in
    | kickoff_meetings.meeting_type. This is the config-driven source of truth
    | until the admin Settings UI (later phase) makes it tenant-editable.
    |
    */

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
    |--------------------------------------------------------------------------
    | Agenda templates (Meeting.docx — "standard agenda by meeting type")
    |--------------------------------------------------------------------------
    |
    | Keyed by meeting_type. Each is a standard agenda the coordinator can load
    | into the Agenda Builder with one click, then edit — so a recurring meeting
    | starts from its usual structure instead of a blank list. A type with no
    | entry here simply has no template (the button is hidden for it).
    |
    | Each row mirrors a meeting_agenda_items row: item · description · minutes ·
    | priority. Config-driven until the Settings UI (later phase) makes it
    | tenant-editable.
    |
    */
    'templates' => [
        'kickoff' => [
            ['item' => 'Introductions & roles',               'duration_minutes' => 10, 'priority' => 'Medium'],
            ['item' => 'Scope of work & deliverables',        'duration_minutes' => 20, 'priority' => 'High'],
            ['item' => 'HSE requirements & site rules',       'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Documentation & compliance checklist', 'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Schedule, milestones & mobilisation', 'duration_minutes' => 15, 'priority' => 'Medium'],
            ['item' => 'Communication & escalation matrix',   'duration_minutes' => 10, 'priority' => 'Medium'],
            ['item' => 'Actions, owners & next meeting',      'duration_minutes' => 10, 'priority' => 'Medium'],
        ],
        'hse' => [
            ['item' => 'Review of previous actions',          'duration_minutes' => 10, 'priority' => 'High'],
            ['item' => 'Incidents / near-misses since last meeting', 'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Hazards & risk assessments',          'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'PPE & site compliance',               'duration_minutes' => 10, 'priority' => 'Medium'],
            ['item' => 'Corrective actions & owners',         'duration_minutes' => 10, 'priority' => 'High'],
        ],
        'toolbox' => [
            ['item' => "Today's task & method statement",     'duration_minutes' => 10, 'priority' => 'High'],
            ['item' => 'Hazards specific to the task',        'duration_minutes' => 10, 'priority' => 'High'],
            ['item' => 'PPE & controls required',             'duration_minutes' => 5,  'priority' => 'High'],
            ['item' => 'Emergency arrangements',              'duration_minutes' => 5,  'priority' => 'Medium'],
            ['item' => 'Worker questions & sign-off',         'duration_minutes' => 5,  'priority' => 'Medium'],
        ],
        'weekly_coordination' => [
            ['item' => 'Review of last week / open actions',  'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Progress vs plan',                    'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Issues & blockers',                   'duration_minutes' => 15, 'priority' => 'Medium'],
            ['item' => 'Look-ahead for next week',            'duration_minutes' => 10, 'priority' => 'Medium'],
            ['item' => 'Actions & owners',                    'duration_minutes' => 5,  'priority' => 'Medium'],
        ],
        'progress_review' => [
            ['item' => 'Progress vs baseline schedule',       'duration_minutes' => 20, 'priority' => 'High'],
            ['item' => 'Milestones achieved & upcoming',      'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Delays, causes & recovery plan',      'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Quality & compliance status',         'duration_minutes' => 10, 'priority' => 'Medium'],
            ['item' => 'Actions & owners',                    'duration_minutes' => 10, 'priority' => 'Medium'],
        ],
        'vendor_review' => [
            ['item' => 'Performance vs KPIs / SLAs',          'duration_minutes' => 20, 'priority' => 'High'],
            ['item' => 'Compliance & documentation status',   'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'HSE & incident record',               'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Commercial & invoicing matters',      'duration_minutes' => 10, 'priority' => 'Medium'],
            ['item' => 'Improvement actions & owners',        'duration_minutes' => 10, 'priority' => 'Medium'],
        ],
        'incident_review' => [
            ['item' => 'Incident description & timeline',     'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Root cause analysis',                 'duration_minutes' => 20, 'priority' => 'High'],
            ['item' => 'Immediate & corrective actions',      'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Preventive actions & owners',         'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Lessons learned & communication',     'duration_minutes' => 10, 'priority' => 'Medium'],
        ],
        'closure' => [
            ['item' => 'Scope completion & sign-off',         'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Outstanding actions & snags',         'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Final documentation & handover',      'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Commercial closeout',                 'duration_minutes' => 10, 'priority' => 'Medium'],
            ['item' => 'Lessons learned',                     'duration_minutes' => 10, 'priority' => 'Medium'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Agenda-item priorities
    |--------------------------------------------------------------------------
    */
    'priorities' => ['Low', 'Medium', 'High'],

    /*
    |--------------------------------------------------------------------------
    | Meeting-level priority + confidentiality (Meeting.docx §2)
    |--------------------------------------------------------------------------
    */
    'meeting_priorities'   => ['Low', 'Medium', 'High', 'Urgent'],
    'confidentiality'      => ['Public', 'Internal', 'Confidential', 'Restricted'],
    // Meeting mode (Meeting.docx §2 — Physical / Online / Hybrid).
    'modes'                => ['onsite', 'online', 'hybrid'],

    /*
    |--------------------------------------------------------------------------
    | Issue register (Meeting.docx §10)
    |--------------------------------------------------------------------------
    */
    'issue_severities' => ['Low', 'Medium', 'High', 'Critical'],

    'issue_categories' => [
        'Safety', 'Compliance', 'Quality', 'Commercial',
        'Workforce', 'Schedule', 'Technical', 'Environmental', 'Other',
    ],

    // Targets an issue can be escalated into. Only 'Incident' auto-creates a
    // record today (via IncidentService); the rest are recorded as markers until
    // their modules are wired.
    'issue_convert_targets' => ['Incident', 'NCR', 'CAPA', 'Task', 'Approval'],

    /*
    |--------------------------------------------------------------------------
    | Decision register (Meeting.docx §9)
    |--------------------------------------------------------------------------
    */
    'decision_statuses' => ['Active', 'Superseded', 'Rescinded'],

];
