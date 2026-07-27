<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise Salary Engine — Offer Letter integration (additive).
 *
 * Lets an offer optionally derive its CTC + breakup from a Salary Structure. Both
 * columns are nullable: existing offers (and any offer created without a structure)
 * behave exactly as before with the manually-entered offered_ctc. `salary_breakdown`
 * freezes the enterprise breakdown at generation time so the letter never changes if
 * the structure is later edited.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_offers', function (Blueprint $table) {
            $table->unsignedBigInteger('salary_structure_id')->nullable()->after('offered_ctc');
            $table->json('salary_breakdown')->nullable()->after('salary_structure_id');
        });
    }

    public function down(): void
    {
        Schema::table('hr_offers', function (Blueprint $table) {
            $table->dropColumn(['salary_structure_id', 'salary_breakdown']);
        });
    }
};
