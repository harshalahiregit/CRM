<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Probation Management — Phase 4 (Probation Extensions).
 *
 * One tenant-scoped table: an extension request on an employee probation
 * (hr_employee_probations). Reuses hr_employees for employee/requester/approver —
 * no duplicated data. Lifecycle: Pending → Approved / Rejected; approval pushes
 * the probation end date and marks it Extended. Never hard-deleted.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_probation_extensions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('probation_id')->index();       // hr_employee_probations
            $table->unsignedBigInteger('employee_id')->index();        // hr_employees
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();

            $table->unsignedSmallInteger('extension_number')->default(1);
            $table->date('current_end_date');
            $table->date('extended_end_date');
            $table->unsignedSmallInteger('extension_days')->default(0);
            $table->text('reason')->nullable();
            $table->text('manager_comments')->nullable();
            $table->text('hr_comments')->nullable();
            $table->string('status')->default('Pending')->index();     // Pending | Approved | Rejected

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_probation_extensions');
    }
};
