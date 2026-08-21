<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase CAPA register — the Purchase-side mirror of tpv_capas (parity rule).
 * Cross-source Corrective & Preventive Actions on their own table keyed to
 * purchase_vendors. Evidence closes the loop (Rule 12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_capas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('reference')->index();               // PCAPA-YYYY-###
            $table->unsignedBigInteger('purchase_vendor_id')->nullable()->index();

            $table->string('source_kind', 24)->default('manual')->index();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('title');
            $table->string('type', 12)->default('Corrective');
            $table->text('root_cause')->nullable();
            $table->text('action')->nullable();
            $table->string('priority', 12)->default('Medium');
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
        Schema::dropIfExists('purchase_capas');
    }
};
