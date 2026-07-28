<?php

namespace App\Http\Requests\Helpdesk;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Tenant-scoped existence rules for helpdesk foreign keys.
 *
 * `exists:users,id` compiles to a raw query that ignores Eloquent scopes, so it
 * matches users in EVERY tenant. Ticket routes accepted such ids and then
 * emailed and notified the foreign user — leaking the subject line across the
 * tenant boundary. These rules exist so that trap has one shared answer instead
 * of being re-derived (and re-forgotten) in each FormRequest.
 */
final class TenantRules
{
    /**
     * Roles that may NOT hold a ticket. Vendors & third-party vendors CAN be
     * assigned (work delegated to them shows on their portal dashboard); only a
     * customer (`client`) can never be an assignee.
     */
    private const NON_ASSIGNABLE_ROLES = ['client'];

    /** A user in THIS tenant who may be assigned a ticket (staff, vendor or TPV). */
    public static function assignableUser(int $tenantId): Exists
    {
        return Rule::exists('users', 'id')
            ->where('tenant_id', $tenantId)
            ->whereNotIn('role', self::NON_ASSIGNABLE_ROLES);
    }

    /** A department belonging to THIS tenant. */
    public static function department(int $tenantId): Exists
    {
        return Rule::exists('ticket_departments', 'id')->where('tenant_id', $tenantId);
    }
}
