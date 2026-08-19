<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permit-to-Work + Job Safety Analysis (Doc_4 Phase 5). High-risk work (hot work,
 * height, confined space, …) may not proceed without an approved permit, and a
 * permit carries a JSA — the step-by-step hazard/control breakdown. A permit
 * cannot be approved for a suspended vendor, and it expires at its valid_to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_permits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('reference')->index();               // PTW-YYYY-NNN
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->unsignedBigInteger('requested_by')->nullable();

            $table->string('type');                             // Hot_Work | Work_At_Height | Confined_Space | Electrical | Excavation | Lifting | General
            $table->string('title');
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->text('hazards')->nullable();
            $table->text('precautions')->nullable();
            $table->dateTime('valid_from')->nullable();
            $table->dateTime('valid_to')->nullable();

            $table->string('status')->default('Requested');     // Requested | Approved | Active | Closed | Rejected | Expired
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->text('decision_remarks')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamps();
        });

        Schema::create('permit_jsa_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('permit_id')->index();
            $table->unsignedInteger('step_no')->default(1);
            $table->string('activity');
            $table->text('hazard')->nullable();
            $table->text('control')->nullable();
            $table->string('residual_risk')->default('Low');    // Low | Medium | High
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permit_jsa_steps');
        Schema::dropIfExists('work_permits');
    }
};
