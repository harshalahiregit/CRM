<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exit Interview (SPK-1).
 *
 * Only the answers live here. Everything the employee record already holds —
 * name, employee_code, department, designation, reporting manager, joining date —
 * is read through the employee relation and never copied into this table.
 *
 * Status is a string, not an enum: SQLite turns enums into CHECK constraints that
 * cannot be altered later, which this codebase has been bitten by before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_exit_interviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();

            // Asked by the form but NOT derivable from hr_employees.
            $table->string('organization_or_project')->nullable();  // prefilled from tenant/department, editable
            $table->string('personal_mobile')->nullable();          // employees.phone is the work number
            $table->string('personal_email')->nullable();           // employees.email is the work address
            $table->date('exit_date')->nullable();

            // The 13 free-text questions, in form order.
            $table->text('reason_for_leaving')->nullable();         // why prompted you to look for a new job
            $table->text('return_circumstances')->nullable();       // when would you return
            $table->text('recognition_feedback')->nullable();       // was your contribution recognised
            $table->text('policies_feedback')->nullable();          // are policies hard to understand
            $table->text('jd_changed_feedback')->nullable();        // did your job description change
            $table->text('tools_resources_feedback')->nullable();   // tools / resources / conditions
            $table->text('training_feedback')->nullable();          // training to be successful
            $table->text('best_part')->nullable();
            $table->text('improvements')->nullable();
            $table->text('morale_suggestions')->nullable();
            $table->text('looking_forward_to')->nullable();
            $table->text('ideal_replacement')->nullable();
            $table->text('would_recommend')->nullable();

            $table->unsignedTinyInteger('rating')->nullable();      // 1–5 overall experience

            $table->string('status')->default('Draft');             // Draft | Submitted
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_exit_interviews');
    }
};
