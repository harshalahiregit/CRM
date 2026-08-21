<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Non-Conformance Reports — the Purchase-side mirror of tpv_ncrs
 * (parity rule), on its own table keyed to purchase_vendors. Raised → Assigned →
 * Response → Corrective Action → Verification → Closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_ncrs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('reference')->index();              // PNCR-YYYY-###
            $table->unsignedBigInteger('purchase_vendor_id')->nullable()->index();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('title');
            $table->text('requirement')->nullable();
            $table->text('finding')->nullable();
            $table->string('severity', 12)->default('Major');
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
        Schema::dropIfExists('purchase_ncrs');
    }
};
