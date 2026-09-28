<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files carried by a notification.
 *
 * An announcement could take one PDF, whose path went into `notifications.link`
 * — a column the phone never reads. So a policy could be attached, and nobody
 * on a phone could ever open it.
 *
 * Stored as a list of {path, name, mime, size}. The PATH is kept, never a URL:
 * the files sit on the private disk and are served through expiring signed
 * links, so a URL written into a row would be dead within the hour and a
 * permanent one would be a public link to a company document.
 *
 * Both stores get the column because both are read by somebody — hr_notifications
 * by the CRM's bell, notifications by the app — and an attachment that reaches
 * one but not the other is the failure this is meant to prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['notifications', 'hr_notifications'] as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'attachments')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->json('attachments')->nullable()->after('message');
            });
        }
    }

    public function down(): void
    {
        foreach (['notifications', 'hr_notifications'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'attachments')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('attachments'));
            }
        }
    }
};
