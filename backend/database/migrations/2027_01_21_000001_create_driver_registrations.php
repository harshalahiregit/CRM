<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STOS-FLEET — a driver's request to join, awaiting the office's yes.
 *
 * A driver signs up in the phone app. That does NOT create a login — it creates
 * a REQUEST that lands here as `pending`. An admin reviews it on the drivers
 * board and approves or rejects. Only on approval is a real user and driver
 * profile created, and only then can the driver log in. Open self-signup into a
 * fleet system is not wanted; the office decides who drives for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_registrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('email', 190);
            // Hashed at rest, exactly like a user's — the approval copies it to
            // the created account, so the driver's chosen password just works.
            $table->string('password');

            $table->string('licence_number', 40)->nullable();
            $table->string('licence_class', 20)->nullable();

            // pending → approved | rejected. A terminal row is kept for the
            // record, not deleted.
            $table->string('status', 20)->default('pending')->index();
            $table->string('reject_reason', 255)->nullable();

            // Set on approval: who created the account, and the user it became.
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->unsignedBigInteger('created_user_id')->nullable();

            $table->timestamps();

            // One open request per email per workspace — a driver cannot spam
            // the queue, and a second attempt updates the first.
            $table->index(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_registrations');
    }
};
