<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HSSE incident management (Doc_4 Phase 5 — incidents / RCA / CAPA).
 *
 * An incident is reported, investigated (root-cause analysis), and closed only
 * once its corrective/preventive actions (CAPAs) are verified. A Serious/Fatal
 * incident or a stop-work order against a vendor auto-suspends that vendor
 * (tie-in with the Wave 4 compliance suspension).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hsse_incidents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('reference')->index();               // INC-YYYY-NNN
            $table->unsignedBigInteger('vendor_id')->nullable()->index();       // the responsible vendor
            $table->unsignedBigInteger('tpv_worker_id')->nullable()->index();   // person involved, if any
            $table->unsignedBigInteger('reported_by')->nullable();

            $table->string('title');
            $table->string('type');                             // Injury | Near_Miss | Property_Damage | Environmental | Fire | Fatality | Other
            $table->string('severity');                         // Minor | Moderate | Serious | Fatal
            $table->string('status')->default('Reported');      // Reported | Investigating | Closed
            $table->dateTime('occurred_at')->nullable();
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->text('immediate_action')->nullable();
            $table->boolean('stop_work')->default(false);       // a stop-work order was issued
            $table->boolean('triggered_suspension')->default(false);

            // Root-cause analysis (1:1 on the incident).
            $table->string('rca_method')->nullable();           // 5-Whys | Fishbone | Fault-Tree | Other
            $table->text('root_cause')->nullable();
            $table->text('contributing_factors')->nullable();
            $table->dateTime('rca_completed_at')->nullable();

            $table->dateTime('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamps();
        });

        Schema::create('incident_capas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('incident_id')->index();
            $table->string('type')->default('Corrective');      // Corrective | Preventive
            $table->text('description');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status')->default('Open');          // Open | In_Progress | Done | Verified
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_capas');
        Schema::dropIfExists('hsse_incidents');
    }
};
