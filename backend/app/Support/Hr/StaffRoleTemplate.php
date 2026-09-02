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
                'appointments' => ['view'],
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
                'appointments' => ['view', 'create', 'edit'],
                'tickets' => ['view_own', 'view_global', 'create', 'edit'],
                'goals' => ['view_global', 'create'],
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
                'appointments' => ['view', 'create', 'edit'],
                'tickets' => ['view_own', 'view_global', 'create', 'edit'],
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
                'appointments' => ['view', 'create', 'edit', 'approve'],
                'tickets' => ['view_own', 'view_global', 'create', 'edit'],
                'goals' => ['view_global', 'create', 'edit'],
                'surveys' => ['view_global'],
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
                'appointments' => ['view', 'create', 'edit', 'delete', 'approve', 'view_reports'],
                'tickets' => ['view_own', 'view_global', 'create', 'edit', 'delete'],
                'goals' => ['view_global', 'create', 'edit'],
                'surveys' => ['view_global', 'create'],
                'staff_mgmt' => ['view_global'],
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
                'appointments' => ['view', 'create', 'edit'],
                'surveys' => ['view_global', 'create'],
                'goals' => ['view_global'],
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
                'appointments' => ['view', 'create', 'edit'],
                'surveys' => ['view_global', 'create'],
                'goals' => ['view_global', 'create'],
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
                'appointments' => ['view', 'create', 'edit', 'approve'],
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
            ],
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
