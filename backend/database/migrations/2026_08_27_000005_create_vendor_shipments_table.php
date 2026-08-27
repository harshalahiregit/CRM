<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compliance & HSSE › Pre Alert / Packages / Shipping. A dispatch notice a TPV
 * vendor sends us: the shipment (pre-alert + tracking + status) and its packages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_shipments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->string('reference');
            $table->string('courier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('status')->default('Pre-Alert');
            $table->date('expected_date')->nullable();
            $table->date('dispatched_on')->nullable();
            $table->date('delivered_on')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_shipments');
    }
};
