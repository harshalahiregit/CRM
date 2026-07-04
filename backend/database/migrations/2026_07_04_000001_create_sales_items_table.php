<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('long_description')->nullable();
            $table->decimal('rate', 12, 2)->default(0);
            $table->string('unit')->default('pcs');
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_rate_2', 5, 2)->default(0);
            $table->string('category')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_items');
    }
};
