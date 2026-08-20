<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor Prequalification (Sangoe TPV gap report area 6).
 *
 * A scored questionnaire outcome — Qualified / Conditional / Not Qualified —
 * that (in slice B4) gates onboarding approval. Stored on the vendor alongside
 * the risk classification (area 2) that B1 added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('qualification_status', 20)->nullable()->after('risk_assessed_by')->index();
            $table->unsignedTinyInteger('qualification_score')->nullable()->after('qualification_status');
            $table->json('qualification_responses')->nullable()->after('qualification_score');
            $table->text('qualification_notes')->nullable()->after('qualification_responses');
            $table->timestamp('qualification_assessed_at')->nullable()->after('qualification_notes');
            $table->unsignedBigInteger('qualification_assessed_by')->nullable()->after('qualification_assessed_at');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn([
                'qualification_status', 'qualification_score', 'qualification_responses',
                'qualification_notes', 'qualification_assessed_at', 'qualification_assessed_by',
            ]);
        });
    }
};
