<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site safety registers (Doc_4 Phase 5/6): emergency DRILLS (mock fire/evacuation/
 * medical exercises), the VISITOR register and the VEHICLE register at the gate.
 * Simple dated logs — the field records that complete the HSSE evidence trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emergency_drills', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('conducted_by')->nullable();
            $table->string('drill_type');                       // Fire | Evacuation | Medical | Spill | Other
            $table->dateTime('conducted_at')->nullable();
            $table->string('location')->nullable();
            $table->unsignedInteger('participants')->default(0);
            $table->unsignedInteger('evacuation_seconds')->nullable();
            $table->text('findings')->nullable();
            $table->timestamps();
        });

        Schema::create('site_visitors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('visitor_name');
            $table->string('company')->nullable();
            $table->string('purpose')->nullable();
            $table->string('host')->nullable();
            $table->string('contact')->nullable();
            $table->string('badge_number')->nullable();
            $table->dateTime('check_in_at')->nullable();
            $table->dateTime('check_out_at')->nullable();
            $table->timestamps();
        });

        Schema::create('site_vehicles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->string('vehicle_number');
            $table->string('vehicle_type')->nullable();
            $table->string('driver_name')->nullable();
            $table->string('purpose')->nullable();
            $table->boolean('fitness_valid')->default(true);
            $table->dateTime('check_in_at')->nullable();
            $table->dateTime('check_out_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_vehicles');
        Schema::dropIfExists('site_visitors');
        Schema::dropIfExists('emergency_drills');
    }
};
