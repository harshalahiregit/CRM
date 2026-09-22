<?php

namespace App\Support\Hr;

/**
 * The vocabulary of the staff permission grid.
 *
 * The grid has been drawn and stored since Staff Management shipped — an admin
 * ticks boxes, they persist to users.meta.permissions, and they are correct. What
 * has never existed is anything that READS them: no Gates, no Policies, and 91
 * hardcoded canManageHrQueue() calls that ignore the grid entirely.
 *
 * This file is the shared vocabulary so the screen and the enforcement cannot
 * drift. The keys match components/admin/StaffModal.jsx exactly; a module missing
 * from here can never be granted, and one missing from there can never be ticked.
 */
final class StaffPermission
{
    /** What may be done to a module. Mirrors the old CRM's capability set. */
    public const VIEW_OWN    = 'view_own';
    public const VIEW_GLOBAL = 'view_global';
    public const CREATE      = 'create';
    public const EDIT        = 'edit';
    public const DELETE      = 'delete';

    public const CAPABILITIES = [
        self::VIEW_OWN,
        self::VIEW_GLOBAL,
        self::CREATE,
        self::EDIT,
        self::DELETE,
    ];

    /**
     * Every module the grid covers.
     *
     * This list and StaffModal.jsx's PERMISSION_MODULES are one vocabulary in two
     * files, and StaffPermissionModuleParityTest fails if they disagree. They had
     * already drifted — hr_attendance and self existed here with no checkbox
     * anywhere, so the only way to grant them was to pick a role template.
     *
     *   hr_attendance — so "may approve leave" is separable from "may see
     *                   payroll". Today both sit behind one coarse HR gate.
     *   self          — "my own record only". Without it, letting somebody clock
     *                   themselves in means granting HR-admin rights over the
     *                   whole company, which is what blocks self check-in.
     *
     * The four hr_* modules below name the parts of HR that could not be spoken
     * about at all. There was no way to say "may run payroll" or "may approve
     * leave but not see salaries": every one of those sits behind the single
     * canManageHrQueue() check, on 113 call sites, which reads none of this.
     *
     * NAMING A MODULE HERE GRANTS NOTHING. It makes the statement expressible;
     * something has to read it before it means anything, and today nothing does.
     * That separation is deliberate — the vocabulary lands first so the screen
     * and the enforcement can be reviewed apart from each other.
     */
    public const MODULES = [
        'contacts', 'deals', 'tasks', 'projects', 'invoices', 'estimates',
        'expenses', 'credit_notes', 'customers', 'vendors', 'tickets', 'reports',
        'email_templates', 'inventory', 'goals', 'surveys', 'appointments',
        'delivery_notes', 'hr_recruitment', 'hr_checklists', 'hr_settings',
        'affiliates', 'staff_mgmt',
        'hr_attendance', 'self',
        'hr_employees', 'hr_payroll', 'hr_leave', 'hr_exit',

        /*
         | Narrow authorities that were hardcoded to role slugs.
         |
         | Each replaces one User capability helper that matched internal_role
         | against a fixed list, which meant a role created in HR Settings could
         | never hold it however many boxes were ticked. They are separate
         | modules rather than capabilities on an existing one because each is a
         | DIFFERENT authority, and the seeded roles that hold them are disjoint
         | sets — an L1 approver is not an L2 approver.
         |
         | The capability used is view_global, following canManageHrQueue's use
         | of hr_employees:view_global: on a module this narrow, "sees all of it"
         | and "may act on it" are the same statement.
         |
         | hr_manpower_l1/l2 are the two rungs of the manpower ladder. Their
         | MEMBERSHIP is static, which is why it can live here; the ORDER stays
         | in ManpowerRequestService, which checks the request's status. Advance
         | tiers are deliberately NOT here — their manager rung is resolved per
         | record from the reporting line, which no permission can express.
         */
        'hr_onboarding', 'hr_manpower_l1', 'hr_manpower_l2', 'hr_ai_jd',

        /*
         | Raising a POSH complaint, and nothing else.
         |
         | Kept apart from every other key on purpose. It is not admin, not
         | hr_settings, not the HR queue and not can_manage_case. Somebody who
         | takes complaints at the door needs none of those, and none of them
         | should imply this — least of all on a module where the person who
         | logs a case is deliberately not entitled to read it afterwards.
         */
        'hr_posh_intake',

        /*
         | POSH aggregate statistics — counts by period, status and outcome.
         |
         | A DIFFERENT QUESTION FROM CASE ACCESS. "How many complaints were
         | upheld last quarter" is fair for a board or a compliance officer to
         | ask without being entitled to open a single file, so unlike
         | hr_posh_intake this one behaves like every other capability,
         | administrator bypass included.
         |
         | It must never be read by PoshAccessResolver, PoshCaseAuthority or any
         | case-content service. A test asserts the string does not appear in
         | them.
         */
        'hr_posh_reports',
    ];

    public static function isModule(string $module): bool
    {
        return in_array($module, self::MODULES, true);
    }

    public static function isCapability(string $capability): bool
    {
        return in_array($capability, self::CAPABILITIES, true);
    }

    /**
     * Keep only the modules and capabilities that exist, discarding the rest.
     *
     * Whatever arrives from the client is untrusted, and whatever is already
     * stored may name a module that has since been renamed or dropped. Filtering
     * on both read and write means a stale key cannot grant anything, and cannot
     * make the grid render a row nobody can turn off.
     *
     * @param  mixed  $raw
     * @return array<string, list<string>>
     */
    public static function sanitise($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $clean = [];

        foreach ($raw as $module => $capabilities) {
            if (! is_string($module) || ! self::isModule($module) || ! is_array($capabilities)) {
                continue;
            }

            $kept = array_values(array_unique(array_filter(
                $capabilities,
                fn ($c) => is_string($c) && self::isCapability($c)
            )));

            // An EMPTY list is kept, and means "this person may do nothing here".
            // effectiveGrants() overrides the role at module level, so an empty
            // list is the only way to express "they hold the HR role but not this
            // part of it". Dropping it made un-ticking every box for a module a
            // no-op: the admin saved, the role's grant quietly won, and the
            // person kept access nobody thought they still had.
            //
            // A module NOT mentioned at all is different, and still means "no
            // opinion — use the role". Only a module the grid names is a decision.
            $clean[$module] = $kept;
        }

        return $clean;
    }
}
