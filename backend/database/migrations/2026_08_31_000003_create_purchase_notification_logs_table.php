<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase-owned notification log. Records every activation notification sent to
 * a PurchaseVendor, which is what makes "send exactly once" enforceable: a prior
 * sent row for the same vendor + type blocks an automatic resend.
 *
 * Purchase-owned: keyed to purchase_vendors. TPV has its own tpv_notification_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_notification_logs')) {
            return;
        }

        Schema::create('purchase_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // The PurchaseVendor this notification was about.
            $table->unsignedBigInteger('vendor_id')->index();

            $table->string('type', 60);            // account_activated
            $table->string('channel', 20);         // email | sms | whatsapp
            $table->string('subject', 255)->nullable();
            $table->string('recipient', 191)->nullable();
            $table->string('status', 20);          // sent | failed | skipped | queued
            $table->timestamp('sent_at')->nullable();
            // Transport outcome only — never a password, never a raw SMTP error.
            $table->text('response')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'vendor_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_notification_logs');
    }
};
