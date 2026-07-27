<?php

namespace App\Support\Hr;

/**
 * Checklist task categories + the default checklist seeded onto every new
 * onboarding, so "every checklist item from the tracker is a trackable task".
 * Each seed row declares owner_role (who may action it) so ESS can later expose
 * the right tasks to the employee without a schema change.
 */
class OnboardingTaskCategory
{
    public const ORIENTATION      = 'Orientation';
    public const TRAINING         = 'Training';
    public const IT_SETUP         = 'IT_Setup';
    public const HR_CHECKLIST     = 'HR_Checklist';
    public const MANAGER_CHECKLIST = 'Manager_Checklist';
    public const GENERAL          = 'General';

    public const ALL = [
        self::ORIENTATION, self::TRAINING, self::IT_SETUP,
        self::HR_CHECKLIST, self::MANAGER_CHECKLIST, self::GENERAL,
    ];

    public const LABELS = [
        self::ORIENTATION       => 'Orientation',
        self::TRAINING          => 'Training',
        self::IT_SETUP          => 'IT Setup',
        self::HR_CHECKLIST      => 'HR Checklist',
        self::MANAGER_CHECKLIST => 'Manager Checklist',
        self::GENERAL           => 'General',
    ];

    public const STATUS_PENDING     = 'Pending';
    public const STATUS_IN_PROGRESS = 'In Progress';
    public const STATUS_COMPLETED   = 'Completed';
    public const STATUS_REJECTED    = 'Rejected';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED, self::STATUS_REJECTED,
    ];

    /**
     * Default checklist seeded on onboarding creation.
     * [ category => [ ['title', 'owner_role', 'is_mandatory'], … ] ]
     * owner_role: HR|Manager|Employee|System — future ESS reads this to decide
     * which tasks an employee may complete themselves.
     */
    public const DEFAULT_TASKS = [
        self::ORIENTATION => [
            ['title' => 'Company overview & vision',        'owner_role' => 'HR',       'is_mandatory' => true],
            ['title' => 'HR policies briefing',             'owner_role' => 'HR',       'is_mandatory' => true],
            ['title' => 'Code of conduct acknowledgement',  'owner_role' => 'Employee', 'is_mandatory' => true],
            ['title' => 'Facility / office tour',           'owner_role' => 'HR',       'is_mandatory' => false],
            ['title' => 'Team introduction',                'owner_role' => 'Manager',  'is_mandatory' => false],
            ['title' => 'Buddy assigned',                   'owner_role' => 'Manager',  'is_mandatory' => false],
            ['title' => 'Welcome kit handed over',          'owner_role' => 'HR',       'is_mandatory' => false],
        ],
        self::TRAINING => [
            ['title' => 'Induction training',               'owner_role' => 'HR',       'is_mandatory' => true],
            ['title' => 'Role-specific training',           'owner_role' => 'Manager',  'is_mandatory' => true],
            ['title' => 'Compliance / POSH training',       'owner_role' => 'HR',       'is_mandatory' => true],
            ['title' => 'Tools & systems training',         'owner_role' => 'Manager',  'is_mandatory' => false],
        ],
        self::IT_SETUP => [
            ['title' => 'Official email account created',   'owner_role' => 'System',   'is_mandatory' => true],
            ['title' => 'Laptop / workstation issued',      'owner_role' => 'System',   'is_mandatory' => true],
            ['title' => 'Software / application access',    'owner_role' => 'System',   'is_mandatory' => true],
            ['title' => 'VPN / network access',             'owner_role' => 'System',   'is_mandatory' => false],
            ['title' => 'Biometric / access card',          'owner_role' => 'System',   'is_mandatory' => false],
        ],
        self::HR_CHECKLIST => [
            ['title' => 'Documents collected & verified',   'owner_role' => 'HR',       'is_mandatory' => true],
            ['title' => 'Offer letter signed & filed',      'owner_role' => 'HR',       'is_mandatory' => true],
            ['title' => 'Policy acknowledgements collected','owner_role' => 'HR',       'is_mandatory' => true],
            ['title' => 'Bank details captured',            'owner_role' => 'HR',       'is_mandatory' => true],
            ['title' => 'Statutory (PF/ESIC/UAN) setup',    'owner_role' => 'HR',       'is_mandatory' => true],
            ['title' => 'Payroll enrolment',                'owner_role' => 'HR',       'is_mandatory' => true],
        ],
        self::MANAGER_CHECKLIST => [
            ['title' => 'Workspace / seat ready',           'owner_role' => 'Manager',  'is_mandatory' => true],
            ['title' => 'Role & responsibilities briefed',  'owner_role' => 'Manager',  'is_mandatory' => true],
            ['title' => 'First-week plan shared',           'owner_role' => 'Manager',  'is_mandatory' => false],
            ['title' => 'Goals / KRA set',                  'owner_role' => 'Manager',  'is_mandatory' => true],
            ['title' => 'Introduced to key stakeholders',   'owner_role' => 'Manager',  'is_mandatory' => false],
        ],
    ];
}
