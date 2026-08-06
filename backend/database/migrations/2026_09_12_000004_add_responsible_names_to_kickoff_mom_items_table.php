<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-text responsible person(s), alongside the referential link.
 *
 * The existing Kickoff form lets a coordinator type a name that is not a vendor
 * contact — an external auditor, someone who joined late — and posts them as a
 * comma-separated string. responsible_attendee_id cannot represent that, and
 * refusing it would silently drop what the user typed.
 *
 * So both are kept: responsible_attendee_id when the owner IS an attendee (the
 * referential truth, safe to join on), responsible_names for the free-typed
 * case. The PDF and UI prefer the link and fall back to the string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kickoff_mom_items', function (Blueprint $table) {
            $table->string('responsible_names', 500)->nullable()->after('responsible_attendee_id');
        });
    }

    public function down(): void
    {
        Schema::table('kickoff_mom_items', function (Blueprint $table) {
            $table->dropColumn('responsible_names');
        });
    }
};
