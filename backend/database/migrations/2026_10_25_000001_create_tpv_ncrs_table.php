<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPV Non-Conformance Reports (Sangoe TPV §24).
 *
 * Raised → Assigned → Response → Corrective Action → Verification → Closed.
 * A dedicated NCR entity (previously folded into incidents/CAPA). Optionally
 * linked to a source (a meeting issue, an inspection finding) via a polymorphic
 * pointer — this is the Meetings §10 "issue → NCR" conversion target.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tpv_ncrs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('reference')->index();              // NCR-YYYY-###
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            // Optional origin (e.g. App\Models\Shared\MeetingIssue).
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('title');
            $table->text('requirement')->nullable();           // the requirement breached
            $table->text('finding')->nullable();               // the non-conformance
            $table->string('severity', 12)->default('Major');  // Minor / Major / Critical
            // Raised / Assigned / Response / Corrective_Action / Verification / Closed
            $table->string('status', 24)->default('Raised')->index();
            $table->unsignedBigInteger('responsible_by')->nullable();
            $table->date('due_date')->nullable();
            $table->text('response')->nullable();
            $table->text('corrective_action')->nullable();
            $table->string('evidence_path')->nullable();
            $table->unsignedBigInteger('raised_by')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tpv_ncrs');
    }
};
