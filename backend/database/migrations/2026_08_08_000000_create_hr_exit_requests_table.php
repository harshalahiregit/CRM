<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exit / Separation Management — Phase 2 (Exit Requests).
 *
 * One tenant-scoped transactional table. A request links an employee
 * (hr_employees) to an Exit Type (hr_exit_types) and, optionally, the Exit
 * Policy (hr_exit_policies) that drives the notice period — all reused from
 * Phase 1, no duplicated masters. Lifecycle for this phase is
 * Draft → Submitted, plus Withdrawn (approval lives in a later phase).
 * Never hard-deleted (withdraw to retire).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_exit_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->index();        // hr_employees
            $table->unsignedBigInteger('exit_type_id');                // hr_exit_types
            $table->unsignedBigInteger('exit_policy_id')->nullable();  // hr_exit_policies (auto/optional)

            $table->date('request_date');
            $table->date('last_working_date')->nullable();
            $table->date('notice_start_date')->nullable();
            $table->date('notice_end_date')->nullable();
            $table->unsignedSmallInteger('notice_days')->default(0);

            $table->text('reason')->nullable();
            $table->text('employee_remarks')->nullable();
            $table->text('hr_remarks')->nullable();
            $table->string('attachment_path')->nullable();

            $table->string('status')->default('Draft')->index();       // Draft | Submitted | Withdrawn
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_exit_requests');
    }
};
