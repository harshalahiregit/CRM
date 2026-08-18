<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rebrand the seeded demo tenant "MLA Consulting" (mla-consulting) to
 * "SANGOE OS" (sangoe-os). The sidebar shows the tenant's own name + subdomain,
 * so this is what changes that label on any environment — including the live
 * database — the next time migrations run on deploy. Idempotent and guarded so
 * it only touches the old demo tenant and never collides with an existing
 * sangoe-os subdomain.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenants')) {
            return;
        }

        // Only rename if the old tenant still exists and the new subdomain is free.
        $old = DB::table('tenants')
            ->where('subdomain', 'mla-consulting')
            ->orWhere('name', 'MLA Consulting')
            ->first();

        if ($old) {
            $subdomainTaken = DB::table('tenants')
                ->where('subdomain', 'sangoe-os')
                ->where('id', '!=', $old->id)
                ->exists();

            DB::table('tenants')->where('id', $old->id)->update([
                'name' => 'SANGOE OS',
                'slug' => $subdomainTaken ? ($old->slug ?? 'sangoe-os') : 'sangoe-os',
                'subdomain' => $subdomainTaken ? $old->subdomain : 'sangoe-os',
            ]);
        }

        if (Schema::hasColumn('users', 'company')) {
            DB::table('users')->where('company', 'MLA Consulting')->update(['company' => 'SANGOE OS']);
        }
    }

    public function down(): void
    {
        // One-way rebrand — intentionally not reverted.
    }
};
