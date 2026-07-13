<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-scoped address book + recipients — the two lightweight, customer-
 * owned pieces of the legacy Logistics module's customer tabs. The operational
 * logistics tabs (Pre-Alert, Packages, Shipping) belong to a dedicated
 * Logistics module and are surfaced as placeholders until it ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('label')->nullable();       // e.g. "Warehouse", "Head Office"
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('zip', 20)->nullable();
            $table->string('country', 100)->nullable();
            $table->timestamps();
            $table->index('client_id');
        });

        Schema::create('client_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->timestamps();
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_recipients');
        Schema::dropIfExists('client_addresses');
    }
};
