<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app (bell) notifications for the Purchase vendor portal. A deliberate
 * mirror of the shared `notifications` table, but keyed to purchase_vendors
 * instead of users: a Purchase vendor is its OWN Authenticatable (not a User),
 * so it cannot live in the users-keyed bell table. Same shape, separate store —
 * the "separate but parallel" Purchase pattern. Emitted via
 * PurchaseVendorNotificationService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_vendor_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_vendor_id')->constrained('purchase_vendors')->cascadeOnDelete();
            $table->string('type', 60);            // e.g. onboarding.approved
            $table->string('title');
            $table->string('message', 500)->nullable();
            $table->string('link')->nullable();    // in-app deep link (portal route)
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The bell's query: this vendor's rows, unread first, newest first.
            $table->index(['purchase_vendor_id', 'read_at']);
            $table->index(['tenant_id', 'purchase_vendor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_vendor_notifications');
    }
};
