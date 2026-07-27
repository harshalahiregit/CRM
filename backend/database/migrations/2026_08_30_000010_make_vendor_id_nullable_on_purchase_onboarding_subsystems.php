<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E-2 — the onboarding-subsystem code now writes purchase_vendor_id and no
 * longer sets the legacy vendor_id, so its NOT NULL constraint must relax.
 * vendor_id is dropped entirely in Phase E-6; until then it stays (nullable) for
 * backward reference. Only the five rewired subsystem tables are touched; the
 * procure-to-pay tables still populate vendor_id and are left unchanged.
 */
return new class extends Migration {
    private function tables(): array
    {
        return [
            'purchase_onboardings', 'purchase_contacts', 'purchase_documents',
            'purchase_kickoff_meetings', 'purchase_approvals',
        ];
    }

    public function up(): void
    {
        foreach ($this->tables() as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'vendor_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->unsignedBigInteger('vendor_id')->nullable()->change();
                });
            }
        }
    }

    public function down(): void
    {
        // Non-reversible tightening is intentionally skipped: existing rows may
        // legitimately have a null vendor_id after the rewire.
    }
};
