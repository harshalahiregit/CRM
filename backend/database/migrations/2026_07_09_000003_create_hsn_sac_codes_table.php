<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hsn_sac_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20);
            $table->string('description');
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->enum('type', ['HSN', 'SAC'])->default('SAC');
            $table->timestamps();

            $table->unique(['code', 'type']);
            $table->index('description');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hsn_sac_codes');
    }
};
