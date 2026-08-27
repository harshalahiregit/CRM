<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Packages inside a vendor shipment. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_shipment_packages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('vendor_shipment_id')->index();
            $table->string('description');
            $table->unsignedInteger('qty')->default(1);
            $table->string('weight')->nullable();
            $table->string('dimensions')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_shipment_packages');
    }
};
