<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exit / Separation Management — Phase 4 (Clearance Management).
 *
 * Two tenant-scoped tables built on the existing Exit data. hr_exit_clearances is
 * the per-exit parent (one row per Approved exit request, overall status derived
 * from its items); hr_exit_clearance_items is the departmental checklist (HR / IT /
 * Admin / Finance / Reporting Manager), each cleared independently. No masters or
 * employee data duplicated — the parent links back to hr_exit_requests. The
 * polymorphic audit log provides clearance history (no separate history table).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_exit_clearances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('exit_request_id');   // hr_exit_requests
            $table->unsignedBigInteger('employee_id')->index(); // hr_employees (denormalised for filtering)
            $table->string('status')->default('Pending')->index(); // Pending | In Progress | Completed | Rejected
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'exit_request_id']); // one clearance per exit
        });

        Schema::create('hr_exit_clearance_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('clearance_id')->index(); // hr_exit_clearances
            $table->string('department');                        // HR | IT | Admin | Finance | Reporting Manager
            $table->boolean('is_mandatory')->default(true);
            $table->string('status')->default('Pending');        // Pending | In Progress | Cleared | Rejected
            $table->string('assigned_to')->nullable();           // e.g. reporting manager name / dept queue
            $table->text('remarks')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_exit_clearance_items');
        Schema::dropIfExists('hr_exit_clearances');
    }
};
