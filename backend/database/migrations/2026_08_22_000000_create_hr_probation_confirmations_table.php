<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Probation Management — Phase 5 (Confirmation Workflow).
 *
 * One tenant-scoped table: the confirmation decision on an employee probation
 * (hr_employee_probations), referencing the latest review + extension. Reuses
 * hr_employees / hr_probation_reviews / hr_probation_extensions — no duplicated
 * data. Lifecycle: Pending → Approved → Confirmed, or Rejected. Confirming closes
 * the probation (status Confirmed). One confirmation per probation (DB-enforced).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_probation_confirmations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('probation_id');                 // hr_employee_probations
            $table->unsignedBigInteger('employee_id')->index();         // hr_employees
            $table->unsignedBigInteger('latest_review_id')->nullable(); // hr_probation_reviews
            $table->unsignedBigInteger('latest_extension_id')->nullable(); // hr_probation_extensions

            $table->string('recommendation')->nullable();               // from latest review (Continue|Extend|Confirm|Fail)
            $table->string('decision')->nullable();                     // Confirm | Extend | Terminate | Continue
            $table->date('confirmation_date')->nullable();
            $table->date('effective_date')->nullable();
            $table->text('manager_comments')->nullable();
            $table->text('hr_comments')->nullable();
            $table->text('remarks')->nullable();

            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('status')->default('Pending')->index();      // Pending | Approved | Rejected | Confirmed

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'probation_id']);              // one confirmation per probation
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_probation_confirmations');
    }
};
