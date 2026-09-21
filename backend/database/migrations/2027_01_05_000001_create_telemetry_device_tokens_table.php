<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STOS-INT — one credential per GPS unit (T-07).
 *
 * ── WHY THIS IS NOT HOUSEKEEPING ──────────────────────────────────────────
 * Ingestion has been guarded by ONE fleet-wide secret in `X-Device-Token`. Two
 * consequences, and the second is the one that actually breaks things:
 *
 * 1. One secret across every customer and every unit. It cannot be rotated for
 *    a single stolen box without re-flashing the whole fleet, and anyone who
 *    learns it can post positions and temperatures for any truck in any
 *    company — and a forged temperature trail is a forged delivery record.
 *
 * 2. `gps_device_id` is unique per COMPANY, not globally. Two companies fitting
 *    units from the same batch can hold the same id, and a shared secret says
 *    nothing about who is calling — so ingestion refuses with 409 rather than
 *    guess and write onto the wrong company's truck. Those vehicles cannot
 *    receive telemetry AT ALL today. A per-device token carries its company, so
 *    the ambiguity disappears rather than being arbitrated.
 *
 * ── WHAT IS STORED ────────────────────────────────────────────────────────
 * The hash, never the token. A stolen database should not yield working
 * credentials for a fleet of vehicles. The plaintext is shown exactly once, at
 * issue, which is the same bargain every API key makes.
 *
 * SHA-256 rather than bcrypt, deliberately: this is verified on every single
 * ping from every unit, and a deliberately slow hash would turn the ingest
 * endpoint into its own denial of service. The value is 32 bytes of CSPRNG
 * output with no user-chosen entropy, so the offline-guessing attack bcrypt
 * defends against does not apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_device_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();

            // The unit this credential speaks for. Not a FK to vehicles: a
            // token is issued to a BOX, and a box is moved between trucks.
            $table->string('device_id', 64);

            // What a person calls it in the list — "Reefer 3 dashboard unit".
            $table->string('label', 120)->nullable();

            $table->string('token_hash', 64)->unique();

            // A rotation issues the new token before the old one is revoked, so
            // a unit that has not yet been re-flashed keeps reporting. Without
            // that overlap every rotation is an outage.
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->string('revoked_reason', 255)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'device_id']);
            $table->index(['company_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_device_tokens');
    }
};
