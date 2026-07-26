<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Per-tenant outgoing-mail (SMTP) configuration ────────────
        // Modeled on the old CRM's Setup→Email settings, but tenant-scoped:
        // TenantMailer resolves this at send time and falls back to the
        // global .env mailer when a tenant has no enabled config.
        Schema::create('tenant_mail_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->default(587);
            $table->string('username')->nullable();
            $table->text('password')->nullable();          // encrypted cast
            $table->string('encryption', 10)->default('tls'); // tls|ssl|none
            $table->string('from_name')->nullable();
            $table->string('from_email')->nullable();
            $table->string('reply_to')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_mail_settings');
    }
};
