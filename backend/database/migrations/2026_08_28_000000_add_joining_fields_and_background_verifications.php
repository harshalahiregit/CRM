<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 employee-joining completion (additive).
 *
 * Adds the only genuinely-missing pieces:
 *   • location / shift / official_email on hr_employees (department-assignment step),
 *   • a first-class Background Verification table keyed to the employee onboarding.
 * The 14-stage onboarding, checklist tasks, assets and approval columns already exist
 * and are reused as-is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->string('location')->nullable()->after('reporting_manager_name');
            $table->string('shift')->nullable()->after('location');
            $table->string('official_email')->nullable()->after('shift');
        });

        Schema::create('hr_background_verifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('onboarding_id');   // → hr_employee_onboardings
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->string('vendor')->nullable();
            $table->string('reference_number')->nullable();
            // Pending | In Progress | Verified | Rejected
            $table->string('status')->default('Pending');
            $table->text('remarks')->nullable();
            $table->date('completed_date')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('onboarding_id')->references('id')->on('hr_employee_onboardings')->cascadeOnDelete();
            $table->unique('onboarding_id');   // one BGV record per onboarding
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_background_verifications');
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn(['location', 'shift', 'official_email']);
        });
    }
};
