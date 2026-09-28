<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The trip's link to its consignment — STOS-CTD §6 ("Trip ID" on the passport).
 *
 * ── DIRECTION AND NULLABILITY ARE BOTH DELIBERATE ─────────────────────────
 * The trip references the consignment, not the reverse: a consignment can be
 * planned before any trip exists, and STOS-CTD §8 allows one consignment to be
 * carried across more than one movement.
 *
 * Nullable for two reasons. Trips have already shipped in production without
 * consignments and must keep working. And the container number is a SEARCH
 * ANCHOR, not a mandatory parent (TM-001 §4 rule 3) — forcing every trip to
 * carry a consignment would make the anchor a parent by the back door.
 *
 * There is deliberately NO container_id here. A container is reached through
 * the consignment; putting it on the trip as well would create a second path to
 * the same fact and let the two disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_trips', function (Blueprint $table) {
            $table->unsignedBigInteger('consignment_id')->nullable()->after('order_id');

            $table->index(['tenant_id', 'consignment_id'], 'transport_trips_tenant_consignment_idx');
        });
    }

    public function down(): void
    {
        Schema::table('transport_trips', function (Blueprint $table) {
            $table->dropIndex('transport_trips_tenant_consignment_idx');
            $table->dropColumn('consignment_id');
        });
    }
};
