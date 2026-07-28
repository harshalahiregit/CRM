<?php

namespace App\Services\Tpv;

use App\Models\User;
use App\Models\Vendor\Vendor;

/**
 * Records TPV portal sign-ins on the TPV vendor row.
 *
 * TPV logins arrive through the shared auth entry point (a User with role
 * third_party_vendor), but the *numbers* belong to TPV — so they are stamped
 * here, on vendors, rather than inferred from the users table. Purchase keeps
 * the equivalent counters on purchase_vendors; neither module reads the other's.
 */
class TpvLoginTracker
{
    /** Best-effort: a tracking failure must never block a successful login. */
    public function record(User $user): void
    {
        if ($user->role !== 'third_party_vendor') {
            return;
        }

        try {
            $vendor = Vendor::where('user_id', $user->id)->first();
            if (! $vendor) {
                return;
            }

            $vendor->forceFill([
                'last_login_at'  => now(),
                // Stamped once, never overwritten.
                'first_login_at' => $vendor->first_login_at ?? now(),
                'login_count'    => (int) ($vendor->login_count ?? 0) + 1,
            ])->saveQuietly();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
