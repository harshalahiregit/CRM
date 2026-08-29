<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase-side Prequalification — the scored questionnaire outcome
 * (Qualified / Conditional / Not Qualified) stored on the Purchase vendor,
 * mirroring the TPV vendor's prequalification columns. Sits alongside the lean
 * admin-set Risk Score that 2026_12_01 added. Additive and idempotent so it is
 * safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_vendors', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_vendors', 'qualification_status')) {
                $table->string('qualification_status', 20)->nullable()->index();
            }
            if (! Schema::hasColumn('purchase_vendors', 'qualification_score')) {
                $table->unsignedTinyInteger('qualification_score')->nullable();
            }
            if (! Schema::hasColumn('purchase_vendors', 'qualification_responses')) {
                $table->json('qualification_responses')->nullable();
            }
            if (! Schema::hasColumn('purchase_vendors', 'qualification_notes')) {
                $table->text('qualification_notes')->nullable();
            }
            if (! Schema::hasColumn('purchase_vendors', 'qualified_at')) {
                $table->timestamp('qualified_at')->nullable();
            }
            if (! Schema::hasColumn('purchase_vendors', 'qualified_by')) {
                $table->unsignedBigInteger('qualified_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_vendors', function (Blueprint $table) {
            foreach ([
                'qualification_status', 'qualification_score', 'qualification_responses',
                'qualification_notes', 'qualified_at', 'qualified_by',
            ] as $col) {
                if (Schema::hasColumn('purchase_vendors', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
