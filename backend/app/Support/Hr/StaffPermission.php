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
     * The first 23 are what StaffModal already renders. The last two are added
     * here for reasons the plan sets out:
     *
     *   hr_attendance — so "may approve leave" is separable from "may see
     *                   payroll". Today both sit behind one coarse HR gate.
     *   self          — "my own record only". Without it, letting somebody clock
     *                   themselves in means granting HR-admin rights over the
     *                   whole company, which is what blocks self check-in.
     */
    public const MODULES = [
        'contacts', 'deals', 'tasks', 'projects', 'invoices', 'estimates',
        'expenses', 'credit_notes', 'customers', 'vendors', 'tickets', 'reports',
        'email_templates', 'inventory', 'goals', 'surveys', 'appointments',
        'delivery_notes', 'hr_recruitment', 'hr_checklists', 'hr_settings',
        'affiliates', 'staff_mgmt',
        'hr_attendance', 'self',
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

            if ($kept !== []) {
                $clean[$module] = $kept;
            }
        }

        return $clean;
    }
}
