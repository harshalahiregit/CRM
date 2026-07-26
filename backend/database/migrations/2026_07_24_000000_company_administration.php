<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company Administration — Sprint 5.
 *
 * Additive columns only. The company gains an About + JSON notification-settings
 * and branding blobs; users gain last-login tracking (set on every login, reused
 * for the Security screen). Team management reuses the existing users table +
 * AuthService; login history reuses audit_logs. No user-management table is added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_external_companies', function (Blueprint $table) {
            $table->text('about')->nullable()->after('notes');
            $table->json('notification_settings')->nullable()->after('about');
            $table->json('branding')->nullable()->after('notification_settings');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('status');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('hr_external_companies', function (Blueprint $table) {
            $table->dropColumn(['about', 'notification_settings', 'branding']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_login_at', 'last_login_ip']);
        });
    }
};
