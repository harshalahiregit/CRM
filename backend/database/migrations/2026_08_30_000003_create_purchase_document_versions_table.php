<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only version history for Purchase-vendor documents. Every file change
 * (upload / replace / resubmit / restore) snapshots an immutable copy here; rows
 * are never deleted so previous versions are kept permanently. Purchase-owned —
 * independent of vendor_document_versions and any TPV table.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_document_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_document_id')->index();
            $table->unsignedInteger('version_no');
            $table->string('file_path');                 // immutable copy on purchase_docs
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('status_at_capture')->nullable();
            $table->unsignedBigInteger('captured_by')->nullable();
            $table->boolean('is_current')->default(false);
            $table->unsignedBigInteger('restored_from_version_id')->nullable();
            $table->timestamps();

            $table->unique(['purchase_document_id', 'version_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_document_versions');
    }
};
