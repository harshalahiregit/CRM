<?php

namespace App\Services\Hr\Clearance;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrClearanceDepartment;
use App\Models\User;

/**
 * Who may act for one exit-clearance department.
 *
 * Until now the answer was canManageHrQueue(), identically for all five
 * departments, so anybody on the HR queue could clear IT, Finance or the
 * reporting manager's item. The only other candidate in the schema was
 * hr_exit_clearance_items.assigned_to — a free-text name copied from
 * reporting_manager_name, which is a label and cannot authorise anything.
 *
 * THREE STATES, and the distinction between the last two is the whole design:
 *
 *   FALLBACK      nobody is configured for this department. The HR queue acts,
 *                 exactly as it does today. This is where every department
 *                 starts, which is what makes the change safe on deploy.
 *
 *   CONFIGURED    somebody is configured. ONLY the resolved union may act —
 *                 the HR queue is no longer an alternative, and neither is an
 *                 administrator. Naming an authority means nothing if anyone
 *                 senior can act anyway.
 *
 *   MISCONFIGURED somebody is configured, and none of them can currently act:
 *                 deactivated, removed from the role, moved tenant. The
 *                 department does NOT drift back to the HR queue. It refuses,
 *                 and says what is wrong, because silently re-widening
 *                 authority is how a control stops being one.
 *
 * SCOPE IS NOT ASKED HERE. This answers who may perform a clearance action;
 * ScopeResolver decides whose records they may perform it on, and has already
 * run by the time an item is reached. Two separate questions, kept separate.
 */
class ClearanceAuthorityResolver
{
    public const MODE_FALLBACK     = 'fallback';
    public const MODE_CONFIGURED   = 'configured';
    public const MODE_MISCONFIGURED = 'misconfigured';

    /**
     * How this department is authorised, and by whom.
     *
     * @return array{mode:string, user_ids:array<int,int>, configured:bool}
     */
    public function describe(int $tenantId, string $department): array
    {
        $config = HrClearanceDepartment::where('tenant_id', $tenantId)
            ->where('name', $department)
            ->first();

        // No row at all — a legacy department string from before this master
        // existed, or one since removed. Treated as unconfigured, so the
        // clearance keeps moving under the HR queue rather than stranding.
        if (! $config || ! $config->isConfigured()) {
            return ['mode' => self::MODE_FALLBACK, 'user_ids' => [], 'configured' => false];
        }

        $userIds = $this->eligibleUserIds($config);

        return [
            'mode'       => $userIds === [] ? self::MODE_MISCONFIGURED : self::MODE_CONFIGURED,
            'user_ids'   => $userIds,
            'configured' => true,
        ];
    }

    /** The user ids that may currently act for this department. */
    public function authorizedUserIds(int $tenantId, string $department): array
    {
        return $this->describe($tenantId, $department)['user_ids'];
    }

    /** 'fallback' | 'configured' | 'misconfigured'. */
    public function mode(int $tenantId, string $department): string
    {
        return $this->describe($tenantId, $department)['mode'];
    }

    public function mayAct(User $actor, string $department): bool
    {
        $state = $this->describe((int) $actor->tenant_id, $department);

        if ($state['mode'] === self::MODE_FALLBACK) {
            return $actor->canManageHrQueue();
        }

        // Configured, or configured-and-broken. The HR queue is not a way in
        // either way: once somebody is named for a department, they are the
        // authority for it.
        return in_array((int) $actor->id, $state['user_ids'], true);
    }

    /**
     * Refuse, and say which of the three situations it is.
     *
     * 403 rather than 404: the clearance itself is legitimately visible to this
     * person — scope and tenancy already allowed it through — and they simply
     * may not act on this department. Hiding it would be a worse answer,
     * because it would read as "this clearance does not exist".
     */
    public function assertMayAct(User $actor, string $department): void
    {
        $state = $this->describe((int) $actor->tenant_id, $department);

        if ($state['mode'] === self::MODE_MISCONFIGURED) {
            // Nobody at all can act. Not this person's fault and not a
            // permission problem, so it says what to fix instead of implying
            // they should have had access.
            throw new BusinessException(
                "No active authority is configured for the {$department} clearance. "
                .'Ask an administrator to configure one before this department can be actioned.',
                422
            );
        }

        if ($this->mayAct($actor, $department)) {
            return;
        }

        throw new BusinessException(
            $state['mode'] === self::MODE_FALLBACK
                ? 'You are not authorised to action exit clearances.'
                : "You are not authorised to action the {$department} clearance.",
            403
        );
    }

    /**
     * The union, filtered to people who could actually act.
     *
     * Named users and role members together — a role exists so an organisation
     * need not maintain a list of individuals every time somebody joins IT, and
     * naming one person as well must not cancel that out, so these add rather
     * than override.
     *
     * Every resolved id is then checked against the account itself. A portal,
     * client or vendor login is not staff whatever it was configured as, a
     * deactivated account is not an authority, and a row pointing at another
     * workspace's user resolves to nobody.
     *
     * @return array<int, int>
     */
    private function eligibleUserIds(HrClearanceDepartment $config): array
    {
        $tenantId = (int) $config->tenant_id;

        $explicit = $config->users()->pluck('users.id');

        $roleIds = $config->roles()->pluck('staff_roles.id');

        $byRole = $roleIds->isEmpty()
            ? collect()
            : User::where('tenant_id', $tenantId)
                ->whereIn('staff_role_id', $roleIds)
                ->pluck('id');

        $candidates = $explicit->merge($byRole)->map(fn ($id) => (int) $id)->unique();

        if ($candidates->isEmpty()) {
            return [];
        }

        return User::whereIn('id', $candidates)
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereIn('role', ['admin', 'staff'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
