<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPV Inspections & Audits (Sangoe TPV §22).
 *
 * Plan → Inspect → Finding → Action → CAPA → Verification → Close. An inspection
 * carries findings; a finding can be escalated into an NCR (tpv_ncrs). Covers the
 * inspection-type catalogue (pre-mobilisation / HSE / site / PPE / workforce /
 * equipment / compliance / behavioural / housekeeping / environmental / audit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tpv_inspections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('reference')->index();              // INS-YYYY-###
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->unsignedBigInteger('work_package_id')->nullable()->index();
            $table->string('type', 40);                        // InspectionType
            $table->string('title');
            $table->date('scheduled_date')->nullable();
            $table->date('conducted_date')->nullable();
            $table->unsignedBigInteger('inspector_by')->nullable();
            $table->string('location')->nullable();
            // Planned / In_Progress / Completed / Closed
            $table->string('status', 20)->default('Planned')->index();
            $table->unsignedTinyInteger('score')->nullable();
            $table->text('summary')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tpv_inspection_findings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('inspection_id')->index();
            $table->text('description');
            // Observation / Non_Conformance / Positive
            $table->string('category', 24)->default('Observation');
            $table->string('severity', 12)->default('Minor');  // Minor / Major / Critical
            // Open / Action / Closed
            $table->string('status', 16)->default('Open')->index();
            $table->text('corrective_action')->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedBigInteger('responsible_by')->nullable();
            // Set when this finding was escalated into an NCR.
            $table->unsignedBigInteger('ncr_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tpv_inspection_findings');
        Schema::dropIfExists('tpv_inspections');
    }
};
