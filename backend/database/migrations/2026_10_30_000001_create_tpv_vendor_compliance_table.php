<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPV Compliance engine (Sangoe TPV §21).
 *
 * A per-vendor compliance register across the 14 categories (legal / labour /
 * licences / statutory / contractual / HSE / training / medical / risk-assessment
 * / method-statement / PPE / environment / quality / security), each with a
 * status (Compliant / Partially / Non-Compliant / Expiring / Expired / Waived /
 * Under Review). Additive alongside the existing evidence locker.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tpv_vendor_compliance', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->string('category', 40);
            $table->string('status', 24)->default('Under_Review');
            $table->text('requirement')->nullable();
            $table->date('valid_until')->nullable()->index();
            $table->string('evidence_path')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vendor_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tpv_vendor_compliance');
    }
};
