<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor/TPV employees (enhancement #2/#9/#10). A TPV vendor's contacts (its
 * people, managed on the vendor's Contacts tab) become assignable "employees" by
 * gaining an optional login: `user_id` links the contact to a `users` row (role
 * third_party_vendor) so it can be dropped into `task_assignees` and see its own
 * assigned work — exactly like a ClientContact. A contact belongs to one vendor
 * (`vendor_id`), never two, satisfying "linked to one, not both".
 *
 * No DB index on user_id (kept as a plain nullable column) — lookups are small
 * and per-vendor, and it sidesteps SQLite's drop-indexed-column limitation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tpv_contacts', function (Blueprint $table) {
            if (! Schema::hasColumn('tpv_contacts', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('vendor_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tpv_contacts', function (Blueprint $table) {
            if (Schema::hasColumn('tpv_contacts', 'user_id')) {
                $table->dropColumn('user_id');
            }
        });
    }
};
