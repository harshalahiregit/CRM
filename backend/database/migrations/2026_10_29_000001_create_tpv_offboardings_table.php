<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPV Offboarding / Closure (Sangoe TPV §29).
 *
 * A controlled exit: a checklist (contract closure, workforce exit, gate revoke,
 * ID/PPE/equipment return, doc archival, open-action + NCR/CAPA closure, financial
 * clearance, final review, lessons learned) that must be worked before completing
 * with a final status (Closed / Replaced / Suspended / Blacklisted). Completion
 * applies the status through the shared VendorService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tpv_offboardings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('reference')->index();              // OFF-YYYY-###
            $table->unsignedBigInteger('vendor_id')->index();
            $table->text('reason')->nullable();
            // [{key,label,done,notes}] — worked through before completion.
            $table->json('checklist')->nullable();
            // In_Progress / Completed
            $table->string('status', 16)->default('In_Progress')->index();
            // Closed / Replaced / Suspended / Blacklisted (set at completion)
            $table->string('final_status', 16)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->text('lessons_learned')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tpv_offboardings');
    }
};
