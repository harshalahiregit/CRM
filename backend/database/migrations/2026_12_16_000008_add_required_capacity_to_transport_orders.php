<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLN-001 — "Plan vehicle requirement". Acceptance: "Required capacity identified".
 *
 * ── WHY THIS COLUMN DID NOT ALREADY EXIST ─────────────────────────────────
 * An audit found PLN-001 sitting in no scope list at all; it was ruled IN_SCOPE
 * on 2026-09-07. Building it then exposed a second problem: the comparison had
 * only one side. transport_vehicles.capacity_tonnes exists, but nothing anywhere
 * held the capacity an ORDER requires.
 *
 * Searched all 42 documents. FRS TRP-P0-003 lists "payload" among the inputs to
 * vehicle allocation, and RTM PLN-001's acceptance is "Required capacity
 * identified" — so the requirement is stated twice. But Step 11 specifies only
 * three fields for DB-001 (company_id, customer_id, order_status), so the field
 * itself is absent from the registry. That is registry defect D-3 again, this
 * time on the order side.
 *
 * Ruled 2026-09-07: add the column, because the alternative is a PLN-001 that is
 * "built" but permanently inert.
 *
 * Nullable on purpose. CMP §10's NO ASSUMPTION PRINCIPLE applies by analogy — an
 * order that states no capacity requirement must not be treated as requiring
 * zero, or as requiring anything. The eligibility check reports "not applicable"
 * rather than passing or failing when this is null.
 *
 * Same unit convention as transport_vehicles.capacity_tonnes: metric tonnes named
 * in the column, so the two sides of the comparison cannot silently disagree
 * about their units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_orders', function (Blueprint $table) {
            $table->decimal('required_capacity_tonnes', 10, 3)->nullable()->after('service_type');
        });
    }

    public function down(): void
    {
        Schema::table('transport_orders', function (Blueprint $table) {
            $table->dropColumn('required_capacity_tonnes');
        });
    }
};
