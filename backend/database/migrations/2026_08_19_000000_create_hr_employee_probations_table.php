<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Probation Management — Phase 2 (Employee Probation).
 *
 * One tenant-scoped table linking an employee to a Probation Policy + Type
 * (Phase 1 masters). Reuses hr_employees / hr_probation_policies /
 * hr_probation_types — all referenced, none duplicated. One active probation per
 * employee (enforced in the service). Lifecycle: Assigned → Active → Extended →
 * Confirmed / Failed / Cancelled. Never hard-deleted (cancel to retire).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_employee_probations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->index();        // hr_employees
            $table->unsignedBigInteger('probation_policy_id');         // hr_probation_policies
            $table->unsignedBigInteger('probation_type_id');           // hr_probation_types

            $table->date('joining_date')->nullable();
            $table->date('probation_start_date');
            $table->date('probation_end_date');
            $table->date('confirmation_due_date')->nullable();
            $table->string('current_status')->default('Assigned')->index(); // Assigned|Active|Extended|Confirmed|Failed|Cancelled
            $table->string('review_cycle')->nullable();                 // Weekly | Monthly | Quarterly
            $table->unsignedSmallInteger('extension_count')->default(0);
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_probations');
    }
};
