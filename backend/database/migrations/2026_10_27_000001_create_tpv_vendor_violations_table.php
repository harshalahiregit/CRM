<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPV Vendor Violations & Strike escalation (Sangoe TPV §26).
 *
 * Vendor-level violations (PPE / unauthorized worker / expired doc / unsafe work
 * / gate / security / environmental / repeated non-compliance / training). Points
 * accumulate into the escalation ladder Warning → Strike 1/2/3 → Suspension →
 * Blacklist. Distinct from the existing WORKER-level safety strikes
 * (tpv_safety_strikes), which are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tpv_vendor_violations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('reference')->index();              // VIO-YYYY-###
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->string('type', 40);                        // ViolationType
            $table->string('severity', 12)->default('Minor');  // Minor / Major / Critical
            $table->text('description')->nullable();
            $table->date('occurred_at')->nullable();
            $table->unsignedSmallInteger('points')->default(1);
            // Optional origin (a gate scan, an inspection finding, an NCR…).
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            // Open (counts toward escalation) / Closed (resolved, no longer counts)
            $table->string('status', 12)->default('Open')->index();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tpv_vendor_violations');
    }
};
