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
    | Agenda-item priorities
    |--------------------------------------------------------------------------
    */
    'priorities' => ['Low', 'Medium', 'High'],

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
