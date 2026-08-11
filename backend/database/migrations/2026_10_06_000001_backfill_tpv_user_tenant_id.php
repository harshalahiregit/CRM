<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data repair: self-registered TPV users were created with users.tenant_id NULL.
 *
 * The Vendor row created alongside them has always carried the agency tenant, so
 * the correct value is recoverable — copy it across from the linked vendor. Rows
 * created from now on are stamped at registration (AuthService::registerTPV), so
 * this only heals accounts that already exist.
 *
 * Without it, anything that scopes by the caller's tenant resolves nothing for
 * these users; the portal countdown lookup, Vendor::forTenant($user->tenant_id),
 * 404s and a temporary TPV never sees its access window.
 *
 * Deliberately narrow: only NULL tenant_id, only third_party_vendor, only where
 * exactly one linked vendor exists. It never overwrites a tenant already set, so
 * it is safe to re-run and cannot move a user between tenants.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('vendors')) {
            return;
        }
        if (! Schema::hasColumn('users', 'tenant_id') || ! Schema::hasColumn('vendors', 'user_id')) {
            return;
        }

        // Chunked and portable: an UPDATE..JOIN differs between MySQL and SQLite,
        // and this table is small enough that per-row updates are not a concern.
        DB::table('users')
            ->whereNull('tenant_id')
            ->where('role', 'third_party_vendor')
            ->orderBy('id')
            ->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    $tenantIds = DB::table('vendors')
                        ->where('user_id', $user->id)
                        ->whereNotNull('tenant_id')
                        ->distinct()
                        ->pluck('tenant_id');

                    // Exactly one candidate, or we cannot know which tenant is
                    // meant — leaving it NULL is better than guessing wrong and
                    // exposing a user inside someone else's tenant.
                    if ($tenantIds->count() !== 1) {
                        continue;
                    }

                    DB::table('users')
                        ->where('id', $user->id)
                        ->whereNull('tenant_id')
                        ->update(['tenant_id' => (int) $tenantIds->first()]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible by design: NULL was the defect, not a state worth restoring.
        // Re-nulling would re-break every account this repaired.
    }
};
