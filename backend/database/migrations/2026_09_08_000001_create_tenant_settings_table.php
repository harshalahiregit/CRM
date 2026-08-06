<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic per-tenant key/value settings store — the modern equivalent of the
 * reference CRM's `tbloptions`, but tenant-scoped. Scalar setting groups (general,
 * branding, localization, finance-format, upload, security, notifications …) live
 * here instead of a table-per-setting. Row-level multi-tenancy (no DB FK), matching
 * the rest of the app. Relational settings (tax rates, custom fields, etc.) keep
 * their own tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_settings')) {
            Schema::create('tenant_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('group', 60);
                $table->string('key', 100);
                $table->text('value')->nullable();
                $table->boolean('is_encrypted')->default(false);
                $table->boolean('autoload')->default(true);
                $table->timestamps();

                $table->unique(['tenant_id', 'group', 'key']);
                $table->index(['tenant_id', 'autoload']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
