<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per device, not one token per person.
 *
 * The token used to be written into `users.meta['fcm_token']`, so somebody with
 * a phone and a tablet silently lost one: whichever registered last overwrote
 * the other, and the first device simply stopped receiving anything with no
 * error anywhere to show it.
 *
 * The token is the identity, not the user — FCM reissues a token to the same
 * install after a reinstall or a data clear, and the same token must never
 * belong to two people. It is unique across the table for that reason, and a
 * re-registration by a different user moves it rather than duplicating it.
 *
 * last_used_at exists so a device that has been silent for months can be pruned
 * without waiting for FCM to declare it UNREGISTERED.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_device_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->index();

            // FCM tokens run long; 512 is comfortably clear of what they reach.
            $table->string('token', 512);
            $table->string('platform', 16)->nullable();   // android | ios | web
            $table->string('device_name', 120)->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            // Hashed, because a 512-char column cannot be indexed directly on
            // MySQL and the whole point is to look a token up quickly.
            $table->string('token_hash', 64)->unique();

            $table->index(['tenant_id', 'user_id'], 'hr_device_tokens_tenant_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_device_tokens');
    }
};
