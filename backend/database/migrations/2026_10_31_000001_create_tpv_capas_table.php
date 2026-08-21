<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generalised TPV CAPA register (Sangoe TPV §25).
 *
 * The legacy incident_capas table stays as-is (it gates incident closure);
 * this is the doc's cross-source Corrective & Preventive Action register — a
 * CAPA can originate from an NCR, an inspection/audit finding, a meeting issue,
 * a vendor violation or a renewal review, or be raised standalone. The origin
 * is captured both as a display kind (source_kind) and, when a concrete record
 * exists, a polymorphic pointer (source_type/source_id). Evidence closes the
 * loop (Rule 12): no CAPA is Verified without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tpv_capas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('reference')->index();               // CAPA-YYYY-###
            $table->unsignedBigInteger('vendor_id')->nullable()->index();

            // Origin. source_kind is the human/filter label (ncr, inspection,
            // audit, meeting, violation, renewal, incident, manual); the morph
            // pointer links the concrete record when one exists.
            $table->string('source_kind', 24)->default('manual')->index();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('title');
            $table->string('type', 12)->default('Corrective');  // Corrective / Preventive
            $table->text('root_cause')->nullable();
            $table->text('action')->nullable();                 // the action to take
            $table->string('priority', 12)->default('Medium');  // Low / Medium / High / Critical
            // Open / In_Progress / Done / Verified
            $table->string('status', 16)->default('Open')->index();

            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('evidence_path')->nullable();
            $table->text('verification_notes')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->unsignedBigInteger('raised_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tpv_capas');
    }
};
