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
        'daily_coordination' => [
            ['item' => 'Yesterday progress & blockers',        'duration_minutes' => 5,  'priority' => 'High'],
            ['item' => 'Plan & manpower for today',            'duration_minutes' => 10, 'priority' => 'High'],
            ['item' => 'Permits, equipment & materials needed', 'duration_minutes' => 5,  'priority' => 'Medium'],
            ['item' => 'Safety focus for the day',             'duration_minutes' => 5,  'priority' => 'High'],
        ],
        'performance_review' => [
            ['item' => 'KPI scorecard vs target',              'duration_minutes' => 20, 'priority' => 'High'],
            ['item' => 'Delivery, quality & responsiveness',   'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'HSE and compliance record',            'duration_minutes' => 10, 'priority' => 'High'],
            ['item' => 'Improvement plan & owners',            'duration_minutes' => 10, 'priority' => 'Medium'],
            ['item' => 'Rating & next review date',            'duration_minutes' => 5,  'priority' => 'Medium'],
        ],
        'compliance_review' => [
            ['item' => 'Document validity & expiries',         'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Statutory / licence obligations',      'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Open compliance gaps',                 'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Remediation plan & owners',            'duration_minutes' => 10, 'priority' => 'High'],
        ],
        'audit_review' => [
            ['item' => 'Audit scope & methodology',            'duration_minutes' => 10, 'priority' => 'Medium'],
            ['item' => 'Findings & observations',              'duration_minutes' => 25, 'priority' => 'High'],
            ['item' => 'Non-conformities raised',              'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Corrective action plan & deadlines',   'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Follow-up audit date',                 'duration_minutes' => 5,  'priority' => 'Low'],
        ],
        'ncr_review' => [
            ['item' => 'Open NCRs & ageing',                   'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Root cause of each NCR',               'duration_minutes' => 20, 'priority' => 'High'],
            ['item' => 'Correction vs corrective action',      'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Evidence required to close',           'duration_minutes' => 10, 'priority' => 'High'],
        ],
        'capa_review' => [
            ['item' => 'CAPA status & overdue items',          'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Effectiveness of closed CAPAs',        'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Recurring causes across CAPAs',        'duration_minutes' => 15, 'priority' => 'Medium'],
            ['item' => 'Verification & evidence',              'duration_minutes' => 10, 'priority' => 'High'],
        ],
        'technical' => [
            ['item' => 'Technical scope & specifications',     'duration_minutes' => 20, 'priority' => 'High'],
            ['item' => 'Drawings, method statements & RFIs',   'duration_minutes' => 20, 'priority' => 'High'],
            ['item' => 'Interfaces & dependencies',            'duration_minutes' => 15, 'priority' => 'Medium'],
            ['item' => 'Technical actions & owners',           'duration_minutes' => 10, 'priority' => 'Medium'],
        ],
        'commercial' => [
            ['item' => 'Contract status & variations',         'duration_minutes' => 20, 'priority' => 'High'],
            ['item' => 'Invoicing, payments & retentions',     'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Claims & disputes',                    'duration_minutes' => 15, 'priority' => 'Medium'],
            ['item' => 'Commercial actions & owners',          'duration_minutes' => 10, 'priority' => 'Medium'],
        ],
        'procurement' => [
            ['item' => 'Requisitions & purchase-order status', 'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Delivery schedule & shortages',        'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Supplier issues & alternatives',       'duration_minutes' => 15, 'priority' => 'Medium'],
            ['item' => 'Procurement actions & owners',         'duration_minutes' => 10, 'priority' => 'Medium'],
        ],
        'workforce_review' => [
            ['item' => 'Headcount vs requirement',             'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Induction, training & competency',     'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'PPE issue & gate compliance',          'duration_minutes' => 10, 'priority' => 'High'],
            ['item' => 'Mobilisation / demobilisation plan',   'duration_minutes' => 10, 'priority' => 'Medium'],
        ],
        'client' => [
            ['item' => 'Previous minutes & open actions',      'duration_minutes' => 10, 'priority' => 'High'],
            ['item' => 'Progress & milestones',                'duration_minutes' => 20, 'priority' => 'High'],
            ['item' => 'Risks, issues & decisions needed',     'duration_minutes' => 20, 'priority' => 'High'],
            ['item' => 'Commercial & schedule matters',        'duration_minutes' => 10, 'priority' => 'Medium'],
            ['item' => 'Actions & next meeting',               'duration_minutes' => 10, 'priority' => 'Medium'],
        ],
        'emergency' => [
            ['item' => 'What has happened - facts only',       'duration_minutes' => 10, 'priority' => 'High'],
            ['item' => 'Immediate containment & safety of people', 'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Notifications & stakeholders to inform', 'duration_minutes' => 10, 'priority' => 'High'],
            ['item' => 'Immediate actions & owners',           'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Next update time',                     'duration_minutes' => 5,  'priority' => 'High'],
        ],
        'management_review' => [
            ['item' => 'Performance against objectives',       'duration_minutes' => 20, 'priority' => 'High'],
            ['item' => 'Risks, incidents & compliance status', 'duration_minutes' => 20, 'priority' => 'High'],
            ['item' => 'Resources & budget',                   'duration_minutes' => 15, 'priority' => 'Medium'],
            ['item' => 'Strategic decisions required',         'duration_minutes' => 20, 'priority' => 'High'],
            ['item' => 'Management actions & owners',          'duration_minutes' => 10, 'priority' => 'Medium'],
        ],
        'monthly_review' => [
            ['item' => 'Month in review vs plan',              'duration_minutes' => 20, 'priority' => 'High'],
            ['item' => 'Safety, quality & compliance summary', 'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Open actions carried from last month', 'duration_minutes' => 15, 'priority' => 'High'],
            ['item' => 'Next month look-ahead',                'duration_minutes' => 15, 'priority' => 'Medium'],
        ],
        'quarterly_review' => [
            ['item' => 'Quarter performance & trends',         'duration_minutes' => 25, 'priority' => 'High'],
            ['item' => 'Vendor scorecards & ratings',          'duration_minutes' => 20, 'priority' => 'High'],
            ['item' => 'Risk register review',                 'duration_minutes' => 20, 'priority' => 'High'],
            ['item' => 'Improvement initiatives for next quarter', 'duration_minutes' => 20, 'priority' => 'Medium'],
        ],
        'other' => [
            ['item' => 'Purpose of the meeting',               'duration_minutes' => 10, 'priority' => 'Medium'],
            ['item' => 'Discussion',                           'duration_minutes' => 25, 'priority' => 'Medium'],
            ['item' => 'Decisions taken',                      'duration_minutes' => 10, 'priority' => 'High'],
            ['item' => 'Actions & owners',                     'duration_minutes' => 10, 'priority' => 'High'],
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
