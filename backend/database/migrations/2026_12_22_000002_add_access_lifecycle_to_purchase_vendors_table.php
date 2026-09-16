<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give a temporary Purchase Vendor's access window a lifecycle.
 *
 * Purchase had access_expires_at and nothing else, which made expiry cosmetic:
 * the countdown reached zero, the badge turned red, and the vendor kept full
 * portal access because the portal guard only ever looked at portal_status and
 * nothing set that. There was no sweep either, so nobody was warned first.
 *
 * TPV has carried both columns since its temporary-access work landed. These
 * are Purchase's own — same shape, separate table, neither module reading the
 * other's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_vendors', function (Blueprint $table) {
            // Active / Expired / Converted. "Expiring" is derived from the
            // remaining seconds for display and is deliberately never stored —
            // a persisted "Expiring" goes stale the moment the clock moves.
            $table->string('access_status', 20)->nullable()->after('access_expires_at');

            // Which of the 7d/3d/1d/6h warnings have gone out. Without it an
            // hourly sweep re-sends every threshold every hour, which trains
            // people to ignore the one that matters.
            $table->json('access_reminders_sent')->nullable()->after('access_status');

            // The sweep asks for exactly this: open windows with an end date.
            $table->index(['tenant_id', 'access_status', 'access_expires_at'], 'pv_access_sweep_idx');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_vendors', function (Blueprint $table) {
            $table->dropIndex('pv_access_sweep_idx');
            $table->dropColumn(['access_status', 'access_reminders_sent']);
        });
    }
};
