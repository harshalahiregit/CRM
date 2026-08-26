<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §35 — an explicit vendor ↔ project association. Until now a vendor's projects
 * were only inferred through its work packages; this is a first-class TPV-local
 * pivot (project captured by name, no cross-module FK — per the module's local
 * project-field convention) with an engagement role and window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tpv_vendor_projects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->string('project', 160);          // TPV-local name, no FK
            $table->string('site', 160)->nullable();
            $table->string('role', 120)->nullable(); // e.g. Main contractor / Sub
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('Active');
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'vendor_id', 'project']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tpv_vendor_projects');
    }
};
