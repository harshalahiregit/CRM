<?php

namespace App\Services\Vendor;

use App\Exceptions\BusinessException;
use App\Models\Tpv\TpvContact;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Vendor/TPV "employees" (enhancement #2/#9/#10).
 *
 * An employee is a vendor CONTACT (TpvContact — the people on the vendor's
 * Contacts tab) that owns a login `User` (role third_party_vendor). Giving a
 * contact a login makes it assignable to tasks/projects (task_assignees.user_id)
 * and lets it see only its own assigned work — reusing the existing assignment +
 * visibility machinery rather than a parallel one. An employee belongs to exactly
 * one vendor (tpv_contacts.vendor_id), never two, satisfying "linked to one, not both".
 */
class VendorEmployeeService
{
    /** The vendor's employees (its contacts), primary first. */
    public function listForVendor(int $vendorId, int $tenantId): Collection
    {
        return TpvContact::where('tenant_id', $tenantId)
            ->where('vendor_id', $vendorId)
            ->with('user:id,name,email,status,role')
            ->orderByDesc('is_primary')
            ->orderBy('first_name')
            ->get();
    }

    /**
     * Ensure this contact has a login so it can be assigned work. Idempotent:
     * returns the existing user if already linked; links an existing vendor-side
     * user that shares the contact's email; otherwise mints a dormant login
     * (random password — the employee resets it) scoped to the vendor's tenant.
     */
    public function grantAccess(TpvContact $contact): User
    {
        if ($contact->user_id && $contact->user) {
            return $contact->user;
        }

        if (empty($contact->email)) {
            throw new BusinessException('This employee needs an email address before a login can be created.', 422);
        }

        // Reuse an existing account with the same email rather than colliding on
        // the unique index — but never hijack an internal staff/admin account.
        $existing = User::where('email', $contact->email)->first();
        if ($existing) {
            if (! in_array($existing->role, ['third_party_vendor', 'vendor'], true)) {
                throw new BusinessException("The email {$contact->email} already belongs to another account.", 422);
            }
            $contact->update(['user_id' => $existing->id]);

            return $existing;
        }

        $user = User::create([
            'name'      => $contact->full_name ?: $contact->email,
            'email'     => $contact->email,
            'password'  => Hash::make(Str::password(14)),
            'role'      => 'third_party_vendor',
            'tenant_id' => $contact->tenant_id,
            'status'    => 'active',
        ]);

        $contact->update(['user_id' => $user->id]);

        return $user;
    }

    /**
     * The vendor a login user belongs to, resolved for visibility. A login can be
     * a vendor's PRIMARY account (vendors.user_id / matching email) or one of its
     * EMPLOYEES (tpv_contacts.user_id). Returns the vendor id or null.
     */
    public function resolveVendorIdForUser(User $user): ?int
    {
        $primary = Vendor::where('tenant_id', $user->tenant_id)
            ->where(fn ($q) => $q->where('user_id', $user->id)
                ->when($user->email, fn ($w) => $w->orWhere('email', $user->email)))
            ->value('id');
        if ($primary) {
            return (int) $primary;
        }

        $viaEmployee = TpvContact::where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->value('vendor_id');

        return $viaEmployee ? (int) $viaEmployee : null;
    }
}
