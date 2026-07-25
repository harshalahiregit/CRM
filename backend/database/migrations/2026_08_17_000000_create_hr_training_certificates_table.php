<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learning & Development — Phase 6 (Certificates & Completion).
 *
 * One tenant-scoped table: a certificate issued for a completed training
 * assignment (hr_employee_trainings). Completion itself is derived (no table).
 * Certificate number unique per tenant; issued certificates are immutable.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_training_certificates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('employee_training_id');        // hr_employee_trainings
            $table->string('certificate_number');
            $table->date('issue_date');
            $table->date('expiry_date')->nullable();
            $table->string('certificate_file')->nullable();
            $table->string('status')->default('Issued');               // Issued | Expired
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_training_id']);     // one certificate per assignment
            $table->unique(['tenant_id', 'certificate_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_training_certificates');
    }
};
