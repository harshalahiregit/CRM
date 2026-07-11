<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collaborative notes thread for a candidate. Distinct from the single freeform
 * `hr_candidates.notes` column (kept for backward compatibility); this is a
 * per-user, timestamped conversation the whole HR team can contribute to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_candidate_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('body');
            $table->timestamps();

            $table->foreign('candidate_id')->references('id')->on('hr_candidates')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'candidate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_candidate_notes');
    }
};
