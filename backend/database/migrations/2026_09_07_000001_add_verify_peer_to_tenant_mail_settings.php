<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a tenant accept a mail server's self-signed certificate.
 *
 * Shared hosting panels (Plesk, cPanel) very often front their mail server with
 * a self-signed or hostname-mismatched certificate, which makes STARTTLS fail
 * with "certificate verify failed" no matter which hostname is used. Symfony's
 * SMTP transport can skip that check, but only if it is asked to — so it is a
 * per-tenant setting rather than a silent default.
 *
 * Defaults to true (verify), because turning verification off weakens the
 * connection and should be a deliberate choice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_mail_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_mail_settings', 'verify_peer')) {
                $table->boolean('verify_peer')->default(true);
            }
        });
    }

    public function down(): void
    {
        // SQLite cannot always drop a column cleanly; leaving it is harmless
        // because the column is nullable-by-default and unread once the code
        // that uses it is gone.
        if (Schema::hasColumn('tenant_mail_settings', 'verify_peer')) {
            Schema::table('tenant_mail_settings', function (Blueprint $table) {
                $table->dropColumn('verify_peer');
            });
        }
    }
};
