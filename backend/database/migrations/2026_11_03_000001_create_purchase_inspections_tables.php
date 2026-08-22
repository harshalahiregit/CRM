<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Inspections & Audits — the Purchase-side mirror of tpv_inspections
 * (parity rule). Plan → Inspect → Finding → Action → NCR → Close. A finding can
 * be escalated into a Purchase NCR (purchase_ncrs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_inspections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('reference')->index();              // PINS-YYYY-###
            $table->unsignedBigInteger('purchase_vendor_id')->nullable()->index();
            $table->string('type', 40);
            $table->string('title');
            $table->date('scheduled_date')->nullable();
            $table->date('conducted_date')->nullable();
            $table->unsignedBigInteger('inspector_by')->nullable();
            $table->string('location')->nullable();
            $table->string('status', 20)->default('Planned')->index();
            $table->unsignedTinyInteger('score')->nullable();
            $table->text('summary')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('purchase_inspection_findings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('inspection_id')->index();
            $table->text('description');
            $table->string('category', 24)->default('Observation');
            $table->string('severity', 12)->default('Minor');
            $table->string('status', 16)->default('Open')->index();
            $table->text('corrective_action')->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedBigInteger('responsible_by')->nullable();
            $table->unsignedBigInteger('ncr_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_inspection_findings');
        Schema::dropIfExists('purchase_inspections');
    }
};
