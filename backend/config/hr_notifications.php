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

        // Announcements composed by hand in the Notification Center, rather than
        // raised by something happening in a module. The subject and body are
        // whatever was typed — the placeholders carry it through the same
        // renderer every other event uses, so one path builds every notification.
        'Announcement' => [
            'Broadcast' => [
                'priority' => 'Info',
                'subject'  => '{{title}}',
                'body'     => '{{body}}',
                // in_app puts it in the bell and the Center; push puts it on the
                // phone. Email is deliberately not default — an announcement to
                // everyone should not become an inbox-wide mail without asking.
                'channels' => ['in_app', 'push'],
            ],
        ],

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
            // My Services uses the verb it acted on ('submitted', 'approved').
            '*'                => ['priority' => 'Info', 'subject' => '{{title}}', 'body' => '{{body}}'],
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

        /*
         | Payroll — the module that moves the most money and told nobody.
         |
         | A run was approved, disbursed and its payslips published without a
         | single notification: an employee found out they had been paid by
         | opening the app and looking. These are the four moments somebody is
         | actually affected by, plus the one queue item somebody is blocking.
         |
         | 'Approval Pending' is addressed to the hr role, which is the only
         | role the inbox can currently express — see PayrollApprovalPendingSource.
         | Distinct Finance/Accounts targeting needs recipient_role matching in
         | NotificationRepository::visibleTo() and is deliberately NOT done here.
         */
        /*
         | POSH — existence only, and user-addressed only.
         |
         | Every body here names the case REFERENCE and what is wanted, and
         | nothing else. No complainant, no respondent, no narrative, no
         | evidence, no finding. A notification is read on a lock screen and
         | forwarded without thinking, so it carries the least that is still
         | useful.
         |
         | There is deliberately no '*' catch-all and no reminder spec: a
         | mistyped event must stay silent rather than deliver under a
         | wildcard, and no statutory timeline exists to remind anybody of.
         |
         | Role addressing is NOT used. The recipients are explicit user ids
         | taken from the case's own membership; a role would be a population
         | resolved somewhere other than PoshAccessResolver.
         */
        'Posh' => [
            'Member Added'      => ['priority' => 'Info',     'subject' => 'You have been added to a case', 'body' => 'You now have access to {{reference}}.'],
            'Inquiry Opened'    => ['priority' => 'Warning',  'subject' => 'Inquiry opened — {{reference}}', 'body' => 'An inquiry has been opened on {{reference}}.'],
            'Decision Required' => ['priority' => 'Critical', 'subject' => 'Your decision is needed — {{reference}}', 'body' => '{{reference}} is waiting on your decision.'],
            'Inquiry Concluded' => ['priority' => 'Info',     'subject' => 'Inquiry concluded — {{reference}}', 'body' => 'The inquiry on {{reference}} has concluded.'],
        ],

        'Payroll' => [
            'Run Approved'     => ['priority' => 'Success',  'subject' => 'Payroll approved — {{period}}', 'body' => 'The payroll run for {{period}} has been approved ({{employees}} employees, {{amount}}).'],
            'Run Rejected'     => ['priority' => 'Warning',  'subject' => 'Payroll rejected — {{period}}', 'body' => 'The payroll run for {{period}} was sent back. {{remarks}}'],
            'Payslip Released' => ['priority' => 'Info',     'subject' => 'Your payslip is ready — {{period}}', 'body' => 'Your payslip for {{period}} is now available.'],
            'Salary Paid'      => ['priority' => 'Success',  'subject' => 'Salary paid — {{period}}', 'body' => '{{amount}} has been paid to you for {{period}}.'],
            'Approval Pending' => ['priority' => 'Critical', 'subject' => 'Payroll awaiting approval — {{period}}', 'body' => 'The payroll run for {{period}} is awaiting approval ({{employees}} employees, {{amount}}).', 'reminder' => ['days' => [0], 'repeat' => true, 'escalation' => true]],
        ],

        /*
         | Loans — advances got a notifier in an earlier pass; loans never did.
         |
         | Somebody borrowed money from the company and heard nothing at any
         | point: not when it was approved, not when it was refused, not when it
         | was paid out. Closure and installment waivers are deliberately absent
         | — they are bookkeeping the employee sees on their statement, not
         | moments they are waiting on.
         */
        'Loan' => [
            'Applied'          => ['priority' => 'Info',    'subject' => 'Loan applied — {{employee}}', 'body' => '{{employee}} applied for a loan of {{amount}}.'],
            'Approved'         => ['priority' => 'Success', 'subject' => 'Loan approved — {{amount}}', 'body' => 'Your loan of {{amount}} has been approved.'],
            'Rejected'         => ['priority' => 'Warning', 'subject' => 'Loan rejected', 'body' => 'Your loan request was rejected. {{remarks}}'],
            'Disbursed'        => ['priority' => 'Success', 'subject' => 'Loan disbursed — {{amount}}', 'body' => '{{amount}} has been disbursed to you.'],
            'Approval Pending' => ['priority' => 'Warning', 'subject' => 'Loan awaiting approval — {{employee}}', 'body' => 'A loan request from {{employee}} ({{amount}}) is awaiting approval.', 'reminder' => ['days' => [0], 'repeat' => true, 'escalation' => true]],
        ],

        /*
         | Onboarding — already notified, but by raw e-mail only.
         |
         | EmployeeOnboardingService mails directly through the channel service,
         | which means no bell, no per-tenant template, no rule and no channel
         | preference. These registrations put the same three moments through
         | the engine as well. The existing e-mails are deliberately LEFT IN
         | PLACE: removing them would silently stop mail somebody relies on.
         */
        'Onboarding' => [
            'Started'               => ['priority' => 'Info',    'subject' => 'Onboarding started — {{employee}}', 'body' => 'Onboarding has started for {{employee}}.'],
            'Verification Complete' => ['priority' => 'Info',    'subject' => 'Background verification {{status}} — {{employee}}', 'body' => 'Background verification for {{employee}} is {{status}}.'],
            'Employee Activated'    => ['priority' => 'Success', 'subject' => 'Employee activated — {{employee}}', 'body' => '{{employee}} has been activated and onboarding is complete.'],
        ],

        /*
         | Employee lifecycle — transfer, promotion, demotion, redesignation.
         |
         | One event rather than four: the recipient is the same person and the
         | only thing that differs is the word, which {{type}} carries. A
         | promotion that nobody tells you about is the clearest example of the
         | gap this whole block closes.
         */
        'Lifecycle' => [
            'Movement Recorded' => ['priority' => 'Info', 'subject' => '{{type}} recorded — {{employee}}', 'body' => 'A {{type}} has been recorded for {{employee}}, effective {{date}}.'],
        ],

        /*
         | My Services — what an employee asked for and what happened to it.
         |
         | The '*' entries are the important part. The engine skips silently
         | when an event is not registered, and these callers pass the verb they
         | actually used ('approved', 'part-approved', 'paid out'), so every one
         | of these notifications was written as an app row and then dropped:
         | no push, no email, no WhatsApp, no queue item at all.
         |
         | '*' is a deliberate per-module opt-in, not a global default — an
         | unregistered module still skips, so nothing starts notifying by
         | accident. The subject and body are overridden per send by
         | RequestNotifier, which already carries the specific wording.
         */
        'Attendance' => [
            'Attendance Exception' => ['priority' => 'Warning', 'subject' => 'Attendance exception — {{employee}}', 'body' => 'An attendance exception was recorded for {{employee}} on {{date}}.'],
            'Clock-out reminder'   => ['priority' => 'Info',    'subject' => 'You are still clocked in', 'body' => '{{body}}'],
            '*'                    => ['priority' => 'Info',    'subject' => '{{title}}', 'body' => '{{body}}'],
        ],

        'Expense' => [
            '*' => ['priority' => 'Info', 'subject' => '{{title}}', 'body' => '{{body}}'],
        ],

        'Advance' => [
            '*' => ['priority' => 'Info', 'subject' => '{{title}}', 'body' => '{{body}}'],
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
