<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase-owned workforce tables — completely independent of the TPV tpv_workers
 * engine. A worker always belongs to a purchase_vendor_id. Row-level multi-tenancy
 * only (no DB foreign keys), matching the rest of the Purchase module.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_workers')) {
            Schema::create('purchase_workers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('purchase_vendor_id')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('worker_code', 40)->nullable();
                $table->string('full_name', 150);
                $table->string('gender', 20)->nullable();
                $table->date('dob')->nullable();
                $table->string('phone', 30)->nullable();
                $table->string('email', 150)->nullable();
                $table->string('designation', 120)->nullable();
                $table->string('id_proof_type', 60)->nullable();
                $table->string('id_proof_number', 80)->nullable();
                $table->string('address', 255)->nullable();
                $table->string('city', 120)->nullable();
                $table->string('state', 120)->nullable();
                $table->string('pincode', 20)->nullable();
                $table->string('photo_path', 255)->nullable();
                $table->string('status', 20)->default('Pending')->index();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['tenant_id', 'worker_code']);
                $table->index(['tenant_id', 'purchase_vendor_id']);
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('purchase_worker_documents')) {
            Schema::create('purchase_worker_documents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('purchase_vendor_id')->index();
                $table->unsignedBigInteger('purchase_worker_id')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('type', 80);
                $table->string('original_name', 255)->nullable();
                $table->string('file_path', 255)->nullable();
                $table->string('status', 20)->default('uploaded')->index();
                $table->string('remarks', 500)->nullable();
                $table->date('expires_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['tenant_id', 'purchase_worker_id']);
            });
        }

        if (! Schema::hasTable('purchase_worker_medicals')) {
            Schema::create('purchase_worker_medicals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('purchase_vendor_id')->index();
                $table->unsignedBigInteger('purchase_worker_id')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->date('exam_date')->nullable();
                $table->date('expiry_date')->nullable();
                $table->string('fitness_status', 20)->default('Pending'); // Fit | Unfit | Pending
                $table->string('blood_group', 10)->nullable();
                $table->string('file_path', 255)->nullable();
                $table->string('remarks', 500)->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['tenant_id', 'purchase_worker_id']);
            });
        }

        if (! Schema::hasTable('purchase_worker_trainings')) {
            Schema::create('purchase_worker_trainings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('purchase_vendor_id')->index();
                $table->unsignedBigInteger('purchase_worker_id')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('title', 150);
                $table->date('training_date')->nullable();
                $table->date('expiry_date')->nullable();
                $table->string('status', 20)->default('Pending'); // Completed | Pending | Expired
                $table->string('score', 30)->nullable();
                $table->string('file_path', 255)->nullable();
                $table->string('remarks', 500)->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['tenant_id', 'purchase_worker_id']);
            });
        }

        if (! Schema::hasTable('purchase_worker_inductions')) {
            Schema::create('purchase_worker_inductions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('purchase_vendor_id')->index();
                $table->unsignedBigInteger('purchase_worker_id')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->date('induction_date')->nullable();
                $table->string('status', 20)->default('Pending'); // Completed | Pending
                $table->string('conducted_by', 150)->nullable();
                $table->string('remarks', 500)->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['tenant_id', 'purchase_worker_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_worker_inductions');
        Schema::dropIfExists('purchase_worker_trainings');
        Schema::dropIfExists('purchase_worker_medicals');
        Schema::dropIfExists('purchase_worker_documents');
        Schema::dropIfExists('purchase_workers');
    }
};
