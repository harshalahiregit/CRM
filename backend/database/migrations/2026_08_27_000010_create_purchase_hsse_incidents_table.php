<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Purchase-side HSSE incidents — the Purchase mirror of tpv HsseIncident (lean). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_hsse_incidents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_vendor_id')->index();
            $table->string('reference');
            $table->string('title');
            $table->string('type');
            $table->string('severity');
            $table->string('status')->default('Reported');
            $table->dateTime('occurred_at')->nullable();
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->text('immediate_action')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('purchase_hsse_incidents'); }
};
