<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §20 — a unified gate-events log. Beyond the worker badge-scan gate, the doc
 * calls for Equipment and Material entry/exit events, and a single model that can
 * also carry Vehicle and Visitor movements. Each event records project / work
 * package / location so the live gate view can filter by those dimensions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tpv_gate_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('vendor_id')->nullable()->index();

            // Person / Vehicle / Visitor / Equipment / Material.
            $table->string('event_kind', 20);
            // Entry / Exit.
            $table->string('direction', 10);

            // What moved — a plate, a person's name, an asset tag, a material desc.
            $table->string('label', 200);
            $table->string('reference', 120)->nullable();
            $table->decimal('quantity', 12, 3)->nullable();   // for Material
            $table->string('unit', 40)->nullable();

            // Filter dimensions (§20 live-view filters).
            $table->string('project', 160)->nullable();
            $table->unsignedBigInteger('work_package_id')->nullable();
            $table->string('location', 160)->nullable();
            $table->string('gate', 80)->nullable();

            $table->timestamp('occurred_at')->nullable()->index();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->json('details')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tpv_gate_events');
    }
};
