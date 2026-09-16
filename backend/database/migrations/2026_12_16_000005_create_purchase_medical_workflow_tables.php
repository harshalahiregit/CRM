<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Purchase mirror of the medical timeline + bulk-upload batch tables. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_medical_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('medical_id');
            $table->unsignedBigInteger('purchase_worker_id')->index();

            $table->unsignedTinyInteger('iteration_no')->default(1);
            $table->string('action', 20);
            $table->string('author_side', 20)->default('system');
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('author_name', 160)->nullable();

            $table->string('reason_code', 60)->nullable();
            $table->text('body')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name', 200)->nullable();

            $table->timestamps();
            $table->index(['tenant_id', 'medical_id']);
        });

        Schema::create('purchase_medical_bulk_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_vendor_id')->nullable()->index();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('uploaded_side', 20)->default('vendor');

            $table->string('file_name', 200)->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->json('errors')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_medical_bulk_batches');
        Schema::dropIfExists('purchase_medical_messages');
    }
};
