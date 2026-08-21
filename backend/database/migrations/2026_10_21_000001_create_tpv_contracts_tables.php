<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPV Contracts & Work Orders (Sangoe TPV §8).
 *
 * Connects a third-party vendor relationship to its actual commercial engagement.
 * These are TPV-OWNED entities — distinct from the Purchase module's own contracts
 * (`purchase_contracts`), which TPV only reads. A contract can spawn many work
 * orders; both attach to a vendor and (optionally) a project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tpv_contracts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('reference')->index();              // CT-YYYY-###
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->string('contract_type')->nullable();
            $table->string('title');
            $table->text('scope')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('contract_value', 15, 2)->nullable();
            $table->string('currency', 8)->default('INR');
            $table->text('payment_terms')->nullable();
            $table->text('sla')->nullable();
            $table->text('kpi')->nullable();
            $table->text('penalties')->nullable();
            $table->text('insurance_requirements')->nullable();
            $table->text('hse_clauses')->nullable();
            $table->text('compliance_clauses')->nullable();
            $table->text('renewal_terms')->nullable();
            // Draft / Active / Expiring / Expired / Renewed / Terminated / Closed
            $table->string('status', 24)->default('Draft')->index();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tpv_work_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('reference')->index();              // WO-YYYY-###
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('contract_id')->nullable()->index();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->string('work_package')->nullable();
            $table->string('title');
            $table->text('scope')->nullable();
            $table->string('location')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('quantity')->nullable();
            $table->unsignedInteger('manpower_requirement')->nullable();
            $table->text('equipment_requirement')->nullable();
            $table->text('commercial_terms')->nullable();
            // Draft / Issued / In_Progress / Completed / Closed / Cancelled
            $table->string('status', 24)->default('Draft')->index();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tpv_work_orders');
        Schema::dropIfExists('tpv_contracts');
    }
};
