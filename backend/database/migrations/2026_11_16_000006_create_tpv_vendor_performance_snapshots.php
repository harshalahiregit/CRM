<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §27 — persisted VPI history. The index is otherwise recomputed live; a snapshot
 * captures the overall score, band and per-dimension scores at a point in time
 * (optionally scoped to a project) so performance can be tracked across projects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tpv_vendor_performance_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->string('project', 160)->nullable();
            $table->unsignedTinyInteger('overall_score')->default(0);
            $table->string('band', 4)->nullable();
            $table->json('dimensions')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tpv_vendor_performance_snapshots');
    }
};
