<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Purchase-side PTW (Permit To Work) — the Purchase mirror of tpv WorkPermit. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_work_permits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_vendor_id')->index();
            $table->string('reference');
            $table->string('type');
            $table->string('title');
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->text('hazards')->nullable();
            $table->text('precautions')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->string('status')->default('Requested');
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('purchase_work_permits'); }
};
