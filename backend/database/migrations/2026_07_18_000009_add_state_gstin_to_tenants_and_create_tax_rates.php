<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Registered state powers intra/inter-state (CGST+SGST vs IGST)
            // auto-detection on documents.
            $table->string('state', 100)->nullable();
            $table->string('gstin', 15)->nullable();
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->decimal('rate', 5, 2);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'rate']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['state', 'gstin']);
        });
    }
};
