<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asking for a wrong or missing punch to be fixed.
 *
 * SangoeTrack has this and the CRM never did — the only corrections routes are a
 * proxy to theirs. It is the last everyday thing an employee can do in the app
 * and could not do here.
 *
 * The requested times are kept SEPARATELY from hr_attendance and only written on
 * approval. A request is a claim about a day, not an edit to it, and storing it
 * as an edit would mean an unapproved claim had already changed the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employee_id')->index();

            // Nullable: the whole point of some requests is that no record exists
            // for that day, so there is nothing to point at yet.
            $table->unsignedBigInteger('attendance_id')->nullable()->index();
            $table->date('attendance_date');

            // What they say the times should be. All nullable — a request may fix
            // only the clock-out, and a null means "leave this one alone" rather
            // than "clear it".
            $table->time('requested_check_in')->nullable();
            $table->time('requested_check_out')->nullable();
            $table->time('requested_break_start')->nullable();
            $table->time('requested_break_end')->nullable();

            $table->text('reason');

            $table->enum('status', ['pending', 'on_hold', 'approved', 'rejected'])->default('pending');
            $table->string('held_from', 20)->nullable();

            $table->text('admin_remarks')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();

            // Whether the approval actually reached hr_attendance. Approving and
            // writing are two steps, and a row that says approved while the day
            // still reads wrong is the failure worth being able to see.
            $table->boolean('applied')->default(false);

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            // Named explicitly: the generated name would be 69 characters, over
            // MySQL's 64-character limit. SQLite accepts it, so this only breaks
            // where it matters — which is exactly why there is a test for it.
            $table->index(['tenant_id', 'employee_id', 'attendance_date'], 'hr_att_corr_tenant_emp_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_attendance_corrections');
    }
};
