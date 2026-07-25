<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `code` column was an enum (a CHECK constraint on SQLite) restricted to
 * the nine seeded voucher kinds, which blocked user-added custom types. Relax
 * it to a plain string so custom codes (slugs of the type name) are allowed.
 * Posting behaviour is unaffected — the manual entry screen supplies the legs,
 * and the automated bridges still match their fixed system codes exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_voucher_types', function (Blueprint $table) {
            $table->string('code', 50)->change();
        });
    }

    public function down(): void
    {
        // Not restoring the enum constraint — a plain string is a strict
        // superset and reverting could reject rows created since.
    }
};
