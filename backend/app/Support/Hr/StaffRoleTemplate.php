<?php

namespace App\Support\Hr;

/**
 * The roles a workspace starts with, and what each one may do.
 *
 * These lived in JavaScript — a ROLE_TEMPLATES map inside StaffModal.jsx — while
 * the role DROPDOWN was generated separately on the server. The two disagreed:
 * the dropdown offered 'junior_executive', which had no template and so
 * pre-filled nothing, and the 'employee' and 'hr_recruiter' templates could not
 * be reached at all. One list, on the server, is the fix for that.
 *
 * They are seeded into staff_roles per tenant rather than read from here at
 * runtime, so a workspace can rename a role, change what it grants, or add its
 * own — the whole point of roles being records rather than code. This class is
 * only the starting point.
 *
 * Slugs are also what gets written to users.internal_role, which is why they
 * match the values already hardcoded around the codebase (canManageHrQueue,
 * AgencyContext, AdvanceTierService). Renaming one here silently breaks those.
 */
class StaffRoleTemplate
{
    public const DEFINITIONS = [
        'employee' => [
            'label'       => 'Employee',
            'permissions' => [
                'contacts' => ['view_own'],
                'deals' => ['view_own'],
                'tasks' => ['view_own', 'create', 'edit'],
                'projects' => ['view_own'],
                'expenses' => ['view_own', 'create'],
                'tickets' => ['view_own', 'create'],
                'appointments' => ['view_global'],
                'self' => ['view_own', 'create', 'edit'],
            ],
        ],
        'team_lead' => [
            'label'       => 'Team Lead',
            'permissions' => [
                'contacts' => ['view_own', 'view_global', 'create', 'edit'],
                'deals' => ['view_own', 'view_global', 'create', 'edit'],
                'tasks' => ['view_own', 'view_global', 'create', 'edit'],
                'projects' => ['view_own', 'view_global', 'create', 'edit'],
                'reports' => ['view_global'],
                'appointments' => ['view_global', 'create', 'edit'],
                'tickets' => ['view_own', 'view_global', 'create', 'edit'],
                'goals' => ['view_global', 'create'],
                'self' => ['view_own', 'create', 'edit'],
            ],
        ],
        'senior_executive' => [
            'label'       => 'Senior Executive',
            'permissions' => [
                'contacts' => ['view_own', 'view_global', 'create', 'edit'],
                'deals' => ['view_own', 'view_global', 'create', 'edit'],
                'tasks' => ['view_own', 'view_global', 'create', 'edit'],
                'projects' => ['view_own', 'view_global', 'create', 'edit'],
                'invoices' => ['view_own', 'view_global', 'create', 'edit'],
                'estimates' => ['view_own', 'view_global', 'create', 'edit'],
                'expenses' => ['view_own', 'view_global', 'create', 'edit'],
                'credit_notes' => ['view_own', 'view_global'],
                'customers' => ['view_own', 'view_global', 'create', 'edit'],
                'reports' => ['view_global'],
                'appointments' => ['view_global', 'create', 'edit'],
                'tickets' => ['view_own', 'view_global', 'create', 'edit'],
                'self' => ['view_own', 'create', 'edit'],
                // canApproveL2's hardcoded list, said in permissions.
                'hr_manpower_l2' => ['view_global'],
            ],
        ],
        'project_manager' => [
            'label'       => 'Project Manager',
            'permissions' => [
                'contacts' => ['view_own', 'view_global', 'create', 'edit'],
                'deals' => ['view_own', 'view_global'],
                'tasks' => ['view_own', 'view_global', 'create', 'edit', 'delete'],
                'projects' => ['view_own', 'view_global', 'create', 'edit', 'delete'],
                'invoices' => ['view_own', 'view_global', 'create'],
                'estimates' => ['view_own', 'view_global', 'create', 'edit'],
                'expenses' => ['view_own', 'view_global', 'create', 'edit'],
                'reports' => ['view_global'],
                'appointments' => ['view_global', 'create', 'edit'],
                'tickets' => ['view_own', 'view_global', 'create', 'edit'],
                'goals' => ['view_global', 'create', 'edit'],
                'surveys' => ['view_global'],
                'self' => ['view_own', 'create', 'edit'],
                // canApproveL2's hardcoded list, said in permissions.
                'hr_manpower_l2' => ['view_global'],
            ],
        ],
        'department_head' => [
            'label'       => 'Department Head',
            'permissions' => [
                'contacts' => ['view_own', 'view_global', 'create', 'edit', 'delete'],
                'deals' => ['view_own', 'view_global', 'create', 'edit', 'delete'],
                'tasks' => ['view_own', 'view_global', 'create', 'edit', 'delete'],
                'projects' => ['view_own', 'view_global', 'create', 'edit', 'delete'],
                'invoices' => ['view_own', 'view_global', 'create', 'edit'],
                'estimates' => ['view_own', 'view_global', 'create', 'edit'],
                'expenses' => ['view_own', 'view_global', 'create', 'edit'],
                'credit_notes' => ['view_own', 'view_global', 'create'],
                'customers' => ['view_own', 'view_global', 'create', 'edit'],
                'vendors' => ['view_own', 'view_global', 'create', 'edit'],
                'reports' => ['view_global'],
                'email_templates' => ['view_global'],
                'appointments' => ['view_global', 'create', 'edit'],
                'tickets' => ['view_own', 'view_global', 'create', 'edit', 'delete'],
                'goals' => ['view_global', 'create', 'edit'],
                'surveys' => ['view_global', 'create'],
                'staff_mgmt' => ['view_global'],
                'self' => ['view_own', 'create', 'edit'],
                // The authority this role already had through canApproveL1's
                // hardcoded slug list, now said in permissions so a custom role
                // can hold it too. The slug clause remains, so nothing changes
                // for anyone already holding this role.
                'hr_manpower_l1' => ['view_global'],
            ],
        ],
        'hr_recruiter' => [
            'label'       => 'HR Recruiter',
            'permissions' => [
                'contacts' => ['view_own', 'view_global', 'create', 'edit'],
                'tasks' => ['view_own', 'view_global', 'create', 'edit'],
                'hr_recruitment' => ['view_own', 'view_global', 'create', 'edit', 'delete'],
                'hr_checklists' => ['view_own', 'view_global', 'create', 'edit'],
                'reports' => ['view_global'],
                'appointments' => ['view_global', 'create', 'edit'],
                'surveys' => ['view_global', 'create'],
                'goals' => ['view_global'],
                'self' => ['view_own', 'create', 'edit'],
                'hr_attendance' => ['view_own', 'view_global', 'create', 'edit', 'delete'],
                // The two authorities this role already had through
                // canManageOnboarding's and canGenerateAiJd's hardcoded slug
                // lists, now said in permissions.
                'hr_onboarding' => ['view_global'],
                'hr_ai_jd' => ['view_global'],
            ],
        ],
        'hr_executive' => [
            'label'       => 'HR Executive',
            'permissions' => [
                'contacts' => ['view_own', 'view_global', 'create', 'edit'],
                'tasks' => ['view_own', 'view_global', 'create', 'edit'],
                'hr_recruitment' => ['view_own', 'view_global', 'create', 'edit', 'delete'],
                'hr_checklists' => ['view_own', 'view_global', 'create', 'edit', 'delete'],
                'hr_settings' => ['view_global', 'create', 'edit'],
                'reports' => ['view_global'],
                'staff_mgmt' => ['view_global'],
                'appointments' => ['view_global', 'create', 'edit'],
                'surveys' => ['view_global', 'create'],
                'goals' => ['view_global', 'create'],
                'self' => ['view_own', 'create', 'edit'],
                'hr_attendance' => ['view_own', 'view_global', 'create', 'edit', 'delete'],
                // canManageOnboarding's hardcoded list, said in permissions.
                // NOT hr_ai_jd: canGenerateAiJd admits hr_recruiter and
                // hr_manager only, and an HR Executive was never on that list.
                'hr_onboarding' => ['view_global'],
            ],
        ],
        'hiring_manager' => [
            'label'       => 'Hiring Manager',
            'permissions' => [
                'contacts' => ['view_own'],
                'tasks' => ['view_own', 'view_global', 'create', 'edit'],
                'hr_recruitment' => ['view_own', 'view_global', 'create', 'edit'],
                'hr_checklists' => ['view_own', 'view_global'],
                'reports' => ['view_global'],
                'appointments' => ['view_global', 'create', 'edit'],
                'self' => ['view_own', 'create', 'edit'],
                // canApproveL1's hardcoded list, said in permissions.
                'hr_manpower_l1' => ['view_global'],
            ],
        ],
        // These two existed nowhere, which is why the advance ladder had no
        // approvers: AdvanceTierService looks for internal_role 'accounts' and
        // 'director', and no screen could set either.
        'accounts' => [
            'label'       => 'Accounts',
            'permissions' => [
                'invoices' => ['view_own', 'view_global', 'create', 'edit'],
                'estimates' => ['view_own', 'view_global'],
                'expenses' => ['view_own', 'view_global', 'create', 'edit'],
                'credit_notes' => ['view_own', 'view_global', 'create', 'edit'],
                'customers' => ['view_own', 'view_global'],
                'vendors' => ['view_own', 'view_global'],
                'reports' => ['view_global'],
                'self' => ['view_own', 'create', 'edit'],
            ],
        ],
        /*
         | VOCABULARY ONLY — a legal name for users.internal_role, granting
         | nothing.
         |
         | routes/sangoetrack.php gates 32 routes on role:admin,hr,manager, and
         | EnsureUserHasRole matches those names against users.internal_role as
         | plain strings. Neither was a staff_roles slug, so the only place they
         | were written down was access_roles — a table with no rows and no
         | reader, which is how a gate came to reference a vocabulary nothing
         | defined.
         |
         | Their permission sets are EMPTY and must stay empty. They exist so the
         | legal vocabulary is explicit and an admin can assign them deliberately;
         | they are not a way to grant HR access, and `hr` is deliberately NOT a
         | coarse synonym for hr_executive here. Anyone who needs HR authority
         | gets a role that carries it.
         */
        'hr' => [
            'label'              => 'HR (SangoeTrack)',
            'permissions'        => [],
            'is_vocabulary_only' => true,
        ],
        'manager' => [
            'label'              => 'Manager (SangoeTrack)',
            'permissions'        => [],
            'is_vocabulary_only' => true,
        ],

        'director' => [
            'label'       => 'Director',
            'permissions' => [
                'contacts' => ['view_own', 'view_global'],
                'deals' => ['view_own', 'view_global'],
                'projects' => ['view_own', 'view_global'],
                'invoices' => ['view_own', 'view_global'],
                'estimates' => ['view_own', 'view_global'],
                'expenses' => ['view_own', 'view_global'],
                'customers' => ['view_own', 'view_global'],
                'vendors' => ['view_own', 'view_global'],
                'reports' => ['view_global'],
                'staff_mgmt' => ['view_global'],
                'self' => ['view_own', 'create', 'edit'],
            ],
        ],
    ];

    /** @return array<string,mixed>|null */
    public static function find(string $slug): ?array
    {
        return self::DEFINITIONS[$slug] ?? null;
    }

    public static function slugs(): array
    {
        return array_keys(self::DEFINITIONS);
    }
}
