<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIRE — per-tenant settings, so SIRE configures itself without a host.
 *
 * WHY THIS TABLE EXISTS AT ALL
 *
 * Earlier SIRE assumed the host CRM had a settings store and read `sire.*` keys
 * out of it. That assumption was the single largest thing standing between SIRE
 * and "installs into almost any Laravel multi-tenant CRM": a host without a
 * settings table could not configure SLA policies, release gates, role rosters
 * or AI at all.
 *
 * So SIRE now owns a fallback. A host with its own settings system implements
 * SireSettingsProvider and this table stays empty; a host without one gets a
 * working SIRE out of the box. Exactly one is ever bound, so there is never a
 * second configuration system to keep in sync.
 *
 * SHAPE
 *
 * key/value, one row per setting per tenant, value stored as JSON text because
 * SIRE settings include arrays — SLA policy lists, release gate configurations,
 * role rosters. A scalar column would force every array through a second
 * encoding nobody would remember to reverse.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sire_settings')) {
            return;
        }

        Schema::create('sire_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');

            // 191, not 255: this column is part of a unique index, and 255 with
            // utf8mb4 exceeds MySQL's 3072-byte index limit on older row formats.
            $table->string('key', 191);
            $table->text('value')->nullable();

            $table->timestamps();

            // One row per key per tenant. The unique index is the enforcement,
            // not a convention — updateOrInsert depends on it.
            $table->unique(['tenant_id', 'key'], 'sire_settings_tenant_key_uq');
            $table->index('tenant_id', 'sire_settings_tenant_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sire_settings');
    }
};
