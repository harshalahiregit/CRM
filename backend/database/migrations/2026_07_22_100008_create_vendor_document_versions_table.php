<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 · Feature 1 — Document Versioning.
 *
 * Append-only history for vendor documents. Every file change snapshots an
 * immutable copy here; rows are never deleted (no soft-deletes) so previous
 * versions are kept permanently. New table only — additive and reversible.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('vendor_document_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('vendor_document_id')->index();
            $table->unsignedInteger('version_no');
            $table->string('file_path');                 // immutable copy on vendor_docs
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('status_at_capture')->nullable();
            $table->unsignedBigInteger('captured_by')->nullable();
            $table->boolean('is_current')->default(false);
            $table->unsignedBigInteger('restored_from_version_id')->nullable();
            $table->timestamps();

            $table->unique(['vendor_document_id', 'version_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_document_versions');
    }
};
