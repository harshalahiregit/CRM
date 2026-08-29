<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance › Referral. A TPV vendor refers other companies; the admin sees
 * them as prospects and works them through a status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_referrals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('referred_by_vendor_id')->index();
            $table->string('company_name');
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->text('note')->nullable();
            $table->string('status')->default('New');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_referrals');
    }
};
