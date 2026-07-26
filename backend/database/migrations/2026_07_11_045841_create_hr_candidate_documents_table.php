<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Typed document store for a candidate (offer letter, ID proof, certificates,
 * additional resumes, etc.). The primary resume stays on
 * `hr_candidates.resume_path` for backward compatibility; this table holds
 * everything else and is fully tenant-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_candidate_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('type')->default('other'); // resume|offer|id_proof|certificate|other
            $table->string('original_name');
            $table->string('path');
            $table->unsignedInteger('size_kb')->default(0);
            $table->string('mime')->nullable();
            $table->timestamps();

            $table->foreign('candidate_id')->references('id')->on('hr_candidates')->cascadeOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'candidate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_candidate_documents');
    }
};
