<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documents a candidate uploads during onboarding (Aadhaar, PAN, resume, photo,
 * address proof, company documents, …). Files live on the private hr_documents
 * disk; this row is the metadata. Tenant-scoped, mirrors hr_candidate_documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_onboarding_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('onboarding_id');
            $table->string('type')->default('other'); // aadhaar|pan|resume|photo|address_proof|company_document|other
            $table->string('original_name');
            $table->string('path');
            $table->unsignedInteger('size_kb')->default(0);
            $table->string('mime')->nullable();
            $table->boolean('verified')->default(false);
            $table->timestamps();

            $table->foreign('onboarding_id')->references('id')->on('hr_onboarding')->cascadeOnDelete();
            $table->index(['tenant_id', 'onboarding_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_onboarding_documents');
    }
};
