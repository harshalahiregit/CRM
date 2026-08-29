<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance › Award / Reward. Admin-granted recognitions a TPV vendor earns;
 * the vendor sees them read-only in its portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_awards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->string('title');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->date('awarded_on');
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_awards');
    }
};
