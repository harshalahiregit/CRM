<?php

/*
|--------------------------------------------------------------------------
| Central Notification Engine — module registration + defaults
|--------------------------------------------------------------------------
|
| Every HR module registers its events here — this is the single plug-in
| point. Adding a future module means adding a block below; the engine, queue,
| reminder scheduler, templates and UI need no changes. Each event carries a
| default priority, template (subject/body with {{placeholders}}), the channels
| to attempt, and — for time-based events — a reminder spec (day offsets, repeat,
| escalation) consumed by the ReminderEngine. Templates + rules seeded from here
| are editable per tenant afterwards.
|
*/

return [

    // Configuration-driven escalation ladder (day-overdue → recipient role).
    // No hardcoded escalation anywhere: rules reference / override this.
    'escalation_ladder' => [
        ['days' => 1,  'role' => 'hr'],
        ['days' => 3,  'role' => 'hr_manager'],
        ['days' => 7,  'role' => 'department_head'],
        ['days' => 15, 'role' => 'admin'],
    ],

    // Channels the engine knows about. Only email + in_app actually deliver today;
    // the rest are prepared (queued + logged) so providers drop in without changes.
    'channels' => ['in_app', 'email', 'sms', 'whatsapp', 'teams', 'slack', 'push'],
    'live_channels' => ['in_app', 'email'],

    // Queue worker retry ceiling.
    'max_retries' => 3,

    'modules' => [

        'Recruitment' => [
            'Interview Scheduled' => ['priority' => 'Info',    'subject' => 'Interview scheduled — {{employee}}', 'body' => 'An interview for {{employee}} ({{module}}) has been scheduled on {{date}}.'],
            'Interview Tomorrow'  => ['priority' => 'Warning', 'subject' => 'Interview tomorrow — {{employee}}', 'body' => 'Reminder: the interview for {{employee}} is scheduled for {{date}}.', 'reminder' => ['days' => [1], 'repeat' => false]],
            'Offer Generated'     => ['priority' => 'Info',    'subject' => 'Offer generated — {{employee}}', 'body' => 'An offer has been generated for {{employee}}.'],
            'Offer Accepted'      => ['priority' => 'Success', 'subject' => 'Offer accepted — {{employee}}', 'body' => '{{employee}} has accepted the offer.'],
            'Offer Rejected'      => ['priority' => 'Warning', 'subject' => 'Offer rejected — {{employee}}', 'body' => '{{employee}} has rejected the offer.'],
        ],

        'Leave' => [
            'Applied'          => ['priority' => 'Info',    'subject' => 'Leave applied — {{employee}}', 'body' => '{{employee}} applied for leave ({{date}}).'],
            'Approved'         => ['priority' => 'Success', 'subject' => 'Leave approved — {{employee}}', 'body' => 'Your leave has been approved.'],
            'Rejected'         => ['priority' => 'Warning', 'subject' => 'Leave rejected — {{employee}}', 'body' => 'Your leave request was rejected.'],
            'Cancelled'        => ['priority' => 'Info',    'subject' => 'Leave cancelled — {{employee}}', 'body' => 'A leave request was cancelled.'],
            'Pending Approval' => ['priority' => 'Warning', 'subject' => 'Leave pending approval — {{employee}}', 'body' => 'A leave request from {{employee}} is awaiting your approval.', 'reminder' => ['days' => [0], 'repeat' => true, 'escalation' => true]],
        ],

        'Exit' => [
            'Request Submitted'  => ['priority' => 'Info',    'subject' => 'Exit request submitted — {{employee}}', 'body' => '{{employee}} submitted an exit request.'],
            'Approval Pending'   => ['priority' => 'Warning', 'subject' => 'Exit approval pending — {{employee}}', 'body' => 'The exit request for {{employee}} is awaiting approval.', 'reminder' => ['days' => [0], 'repeat' => true, 'escalation' => true]],
            'Clearance Pending'  => ['priority' => 'Warning', 'subject' => 'Exit clearance pending — {{employee}}', 'body' => 'Departmental clearance for {{employee}} is pending.', 'reminder' => ['days' => [0], 'repeat' => true]],
            'Settlement Pending' => ['priority' => 'Warning', 'subject' => 'Full & Final pending — {{employee}}', 'body' => 'The full & final settlement for {{employee}} is pending.', 'reminder' => ['days' => [0], 'repeat' => true]],
        ],

        'Learning' => [
            'Training Assigned'    => ['priority' => 'Info',     'subject' => 'Training assigned — {{employee}}', 'body' => '{{employee}} has been assigned a training.'],
            'Training Tomorrow'    => ['priority' => 'Warning',  'subject' => 'Training tomorrow — {{employee}}', 'body' => 'Reminder: your training session is on {{date}}.', 'reminder' => ['days' => [1], 'repeat' => false]],
            'Certificate Expiring' => ['priority' => 'Critical', 'subject' => 'Certificate expiring — {{employee}}', 'body' => 'A training certificate for {{employee}} expires on {{date}} ({{remaining_days}} days left).', 'reminder' => ['days' => [30, 15, 7, 1], 'repeat' => false]],
        ],

        'Probation' => [
            'Assigned'             => ['priority' => 'Info',     'subject' => 'Probation assigned — {{employee}}', 'body' => '{{employee}} has been placed on probation ({{department}}).'],
            'Review Due'           => ['priority' => 'Warning',  'subject' => 'Probation review due — {{employee}}', 'body' => 'A probation review for {{employee}} is due.', 'reminder' => ['days' => [7, 3, 0], 'repeat' => false, 'escalation' => true]],
            'Extension Pending'    => ['priority' => 'Warning',  'subject' => 'Probation extension pending — {{employee}}', 'body' => 'An extension request for {{employee}} is awaiting approval.', 'reminder' => ['days' => [0], 'repeat' => true, 'escalation' => true]],
            'Confirmation Pending' => ['priority' => 'Critical', 'subject' => 'Probation confirmation due — {{employee}}', 'body' => 'The probation for {{employee}} ends on {{date}} ({{remaining_days}} days) — confirmation is due.', 'reminder' => ['days' => [30, 15, 7, 2, 0], 'repeat' => false, 'escalation' => true]],
            'Confirmed'            => ['priority' => 'Success',  'subject' => 'Employee confirmed — {{employee}}', 'body' => '{{employee}} has been confirmed effective {{date}}.'],
        ],

        'Performance' => [
            'Review Pending' => ['priority' => 'Warning', 'subject' => 'Performance review pending — {{employee}}', 'body' => 'A performance review for {{employee}} is pending.', 'reminder' => ['days' => [7, 3, 0], 'repeat' => false]],
            'Goal Due'       => ['priority' => 'Warning', 'subject' => 'Goal due — {{employee}}', 'body' => 'A goal for {{employee}} is due on {{date}}.', 'reminder' => ['days' => [7, 1, 0], 'repeat' => false]],
        ],

        'Attendance' => [
            'Attendance Exception' => ['priority' => 'Warning', 'subject' => 'Attendance exception — {{employee}}', 'body' => 'An attendance exception was recorded for {{employee}} on {{date}}.'],
        ],

        'Purchase' => [
            'Approval Pending' => ['priority' => 'Warning', 'subject' => 'Purchase approval pending', 'body' => 'A purchase request is awaiting your approval.', 'reminder' => ['days' => [0], 'repeat' => true, 'escalation' => true]],
        ],

        'TPV' => [
            'Document Expiry'  => ['priority' => 'Critical', 'subject' => 'Document expiring — {{module}}', 'body' => 'A vendor document expires on {{date}} ({{remaining_days}} days left).', 'reminder' => ['days' => [30, 15, 7, 1], 'repeat' => false, 'escalation' => true]],
            'Vendor Approval'  => ['priority' => 'Warning',  'subject' => 'Vendor approval pending', 'body' => 'A vendor is awaiting approval.', 'reminder' => ['days' => [0], 'repeat' => true]],
        ],
    ],
];
