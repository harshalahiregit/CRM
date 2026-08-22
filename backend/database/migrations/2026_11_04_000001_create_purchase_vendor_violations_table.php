<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase vendor violations — the Purchase-side mirror of tpv_vendor_violations
 * (parity rule). Points accumulate from open violations into a strike-escalation
 * ladder (Warning → Strike → Suspension → Blacklist).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_vendor_violations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('reference')->index();              // PVIO-YYYY-###
            $table->unsignedBigInteger('purchase_vendor_id')->nullable()->index();
            $table->string('type', 40);
            $table->string('severity', 12)->default('Minor');
            $table->text('description')->nullable();
            $table->date('occurred_at')->nullable();
            $table->unsignedInteger('points')->default(0);
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('status', 12)->default('Open')->index();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_vendor_violations');
    }
};
