<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase-vendor statutory documents — the Purchase module's OWN document store
 * (purchase_documents), fully independent of TPV and of the shared Vendor Master
 * doc tables. Keyed to the shared Vendor Master by vendor_id only. Files live on
 * the private `purchase_docs` disk; versioning is captured in
 * purchase_document_versions. Row-level multi-tenancy; no DB foreign keys.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('vendor_id')->index();

            $table->string('type');
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('status')->default('Under_Review')->index();
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_documents');
    }
};
