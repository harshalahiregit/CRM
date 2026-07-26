<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise Manpower Request fields (SPK-1).
 *
 * All columns are nullable and additive, so existing requests, APIs and the
 * approval workflow keep working unchanged. Statuses stay string columns (SQLite
 * enum CHECK-constraints can't be altered later — a trap this codebase has hit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_manpower_requests', function (Blueprint $table) {
            // Section 1 — Basic Information
            $table->unsignedBigInteger('hiring_manager_id')->nullable()->after('assigned_manager_id');
            // Section 2 — Position Details
            $table->string('work_mode')->nullable()->after('job_type');          // Onsite | Remote | Hybrid
            $table->string('shift')->nullable()->after('work_mode');             // Day | Night | Rotational | Flexible
            // Section 3 — Compensation & Skills
            $table->decimal('budget', 14, 2)->nullable()->after('salary_max');   // optional total budget
            $table->json('certifications')->nullable()->after('education');
            // Section 4 — Hiring Details
            $table->string('hiring_reason')->nullable()->after('justification'); // New Position | Replacement | Expansion | Contract
            $table->unsignedBigInteger('replacement_employee_id')->nullable()->after('hiring_reason');
            $table->string('cost_center')->nullable()->after('replacement_employee_id');

            $table->index('hiring_manager_id');
        });
    }

    public function down(): void
    {
        Schema::table('hr_manpower_requests', function (Blueprint $table) {
            $table->dropIndex(['hiring_manager_id']);
            $table->dropColumn([
                'hiring_manager_id', 'work_mode', 'shift', 'budget',
                'certifications', 'hiring_reason', 'replacement_employee_id', 'cost_center',
            ]);
        });
    }
};
