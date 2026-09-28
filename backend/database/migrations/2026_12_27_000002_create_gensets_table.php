<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STOS-FLEET — gensets (reefer power units).
 *
 * A genset is its own asset with its own serial and its own service life; it is
 * fitted to a vehicle and can be moved to another, so `vehicle_id` is NULLABLE —
 * a unit sitting in the workshop or the yard is still a unit we own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gensets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();

            $table->string('serial_number', 60);

            // Detaching a vehicle must not take the genset with it — nullOnDelete,
            // never cascade.
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();

            // active | in_maintenance | idle | retired
            $table->string('status', 20)->default('active');

            $table->timestamps();

            $table->unique(['company_id', 'serial_number'], 'gensets_company_serial_unique');
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gensets');
    }
};
