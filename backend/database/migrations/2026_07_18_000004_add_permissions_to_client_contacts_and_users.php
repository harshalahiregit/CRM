<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_contacts', function (Blueprint $table) {
            // Per-contact module access (meeting 1.4 + old-CRM contact
            // permissions union). Granting a module implies email
            // notifications for it — no separate notification toggles.
            $table->json('permissions')->nullable()->after('email_notifications');
            // Master email switch for this contact (meeting: keep ONE global
            // disable-all-emails option).
            $table->boolean('emails_enabled')->default(true)->after('permissions');
        });

        Schema::table('users', function (Blueprint $table) {
            // Same master switch for internal staff.
            $table->boolean('emails_enabled')->default(true)->after('status');
        });

        // Backfill: permissions start as the modules whose legacy
        // email_notifications flag was true (1:1 — the union set matches the
        // legacy keys). Legacy column is kept read-only for back-compat.
        foreach (DB::table('client_contacts')->whereNotNull('email_notifications')->get() as $row) {
            $legacy = json_decode($row->email_notifications, true) ?: [];
            $granted = array_keys(array_filter($legacy, fn ($v) => (bool) $v));
            DB::table('client_contacts')->where('id', $row->id)->update([
                'permissions' => json_encode(array_values($granted)),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('client_contacts', function (Blueprint $table) {
            $table->dropColumn(['permissions', 'emails_enabled']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('emails_enabled');
        });
    }
};
