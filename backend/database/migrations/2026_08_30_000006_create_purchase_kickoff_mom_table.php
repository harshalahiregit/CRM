<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase kickoff Minutes-of-Meeting documents — the generated (or uploaded)
 * MOM PDF for a Purchase kickoff meeting. Append-only history: each generate/
 * upload adds a row and flips is_current. Files live on the private
 * `purchase_kickoff_docs` disk. Purchase-owned — independent of the shared
 * kickoff engine (which stores mom_path inline on kickoff_meetings).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_kickoff_mom', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_kickoff_meeting_id')->index();

            $table->string('file_path');                 // PDF on purchase_kickoff_docs
            $table->string('original_name')->nullable();
            $table->string('source')->default('generated'); // generated | uploaded
            $table->boolean('is_current')->default(true);
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'purchase_kickoff_meeting_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_kickoff_mom');
    }
};
