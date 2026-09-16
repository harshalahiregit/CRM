<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retire the `vendor` User role.
 *
 * There were two spellings of "vendor" in the users table and they did not mean
 * the same thing:
 *
 *   third_party_vendor  a TPV contractor. Attaches to a `vendors` row. Real.
 *   vendor              attaches to a `purchase_vendors` row — or to nothing.
 *
 * A purchase vendor is NOT a User. It authenticates as itself, out of
 * purchase_vendors, with its own password and its own Sanctum token. So every
 * `vendor` User linked to one is a second, redundant account for the same
 * supplier: one address, two logins, and only one of them able to reach the
 * portal their records are actually in.
 *
 * Registration used to create both at once, which is where these came from.
 * That has been removed, so nothing produces the role any more — this clears
 * what it left behind.
 *
 * Deactivated, never deleted. These rows are referenced elsewhere (a project's
 * vendor_user_id, an audit trail's actor) and removing them would turn readable
 * history into dangling ids. Deactivating stops the login and leaves the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('users')->where('role', 'vendor')->get(['id', 'email', 'status']);

        foreach ($rows as $user) {
            // Does this person already have the login they should have been
            // using all along?
            $hasPurchaseLogin = DB::table('purchase_vendors')
                ->whereRaw('lower(email) = ?', [mb_strtolower((string) $user->email)])
                ->whereNotNull('password')
                ->exists();

            DB::table('users')->where('id', $user->id)->update([
                'status' => 'inactive',
                'updated_at' => now(),
            ]);

            // Left in the log rather than a table: this runs once, and whoever
            // reads it needs to know which of the two cases each row was.
            \Illuminate\Support\Facades\Log::info('Retired a vendor-role user', [
                'user_id' => $user->id,
                'email' => $user->email,
                'was' => $user->status,
                'purchase_portal_login_exists' => $hasPurchaseLogin,
            ]);
        }
    }

    /**
     * Deliberately irreversible.
     *
     * Reactivating every one of them would restore logins that should never have
     * existed, and this migration cannot know which rows were already inactive
     * before it ran. Rolling back leaves them inactive; that is the safe
     * direction for a credential.
     */
    public function down(): void
    {
        // no-op
    }
};
