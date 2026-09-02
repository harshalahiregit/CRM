<?php

namespace App\Http\Middleware;

use App\Models\Hr\HrEmployee;
use App\Models\User;
use App\Services\Hr\AdvanceTierService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may reach the advance queue at all.
 *
 * `hr.manage` is the wrong gate here, and the tests found it: it admits admins
 * and HR, while the people who actually approve advances are a line manager,
 * accounts and a director — none of whom are HR. Gated on hr.manage, the three
 * tiers could not reach the queue they exist to work.
 *
 * This is the coarse door. It says somebody has business with advances; it does
 * NOT say which rung they stand on, which stays with AdvanceTierService and is
 * decided per request. Getting in and being able to approve are different
 * questions, and conflating them is how a tier ladder stops meaning anything.
 */
class EnsureCanAccessAdvances
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);
        }

        // Portal identities carry their own columns and must never satisfy a
        // staff gate — the same guard the other HR middleware makes.
        if (! $user instanceof User || ! $this->hasBusinessHere($user)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'You are not authorised to manage advances.',
            ], 403);
        }

        return $next($request);
    }

    private function hasBusinessHere(User $user): bool
    {
        // Admins and HR oversee the process.
        if ($user->isAdmin() || $user->canManageHrQueue()) {
            return true;
        }

        // Accounts and directors hold the upper rungs.
        foreach (AdvanceTierService::TIER_ROLES as $roles) {
            if (in_array((string) $user->internal_role, $roles, true)) {
                return true;
            }
        }

        // A line manager, but only if somebody actually reports to them —
        // otherwise every linked employee would be let in.
        return $this->managesAnyone($user);
    }

    private function managesAnyone(User $user): bool
    {
        $me = HrEmployee::where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->first();

        return $me !== null
            && HrEmployee::where('tenant_id', $user->tenant_id)
                ->where('reporting_manager_id', $me->id)
                ->exists();
    }
}
