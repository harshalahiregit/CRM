<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tenant's own WhatsApp sender.
 *
 * Same shape and same reason as tenant_mail_settings: a message that has to go
 * out from THIS company must leave from this company's number, and changing it
 * cannot mean editing .env and redeploying. The .env values stay as the
 * fallback for a tenant that has not set anything up.
 *
 * The access token is encrypted at rest and never returned by the API -- the
 * screen gets has_token, and saving an empty token means "keep the one you
 * have" so nobody has to paste it again to change the phone number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_whatsapp_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();

            // 'cloud' (Meta) or 'twilio'. Kept per tenant because a company that
            // already pays Twilio should not be forced onto Cloud API.
            $table->string('provider')->default('cloud');

            // Meta Cloud API
            $table->text('access_token')->nullable();          // encrypted
            $table->string('phone_number_id')->nullable();
            $table->string('waba_id')->nullable();
            $table->string('api_version')->default('v21.0');

            // What the sender looks like, for the settings screen only.
            $table->string('display_phone_number')->nullable();
            $table->string('verified_name')->nullable();

            $table->boolean('enabled')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_whatsapp_settings');
    }
};
