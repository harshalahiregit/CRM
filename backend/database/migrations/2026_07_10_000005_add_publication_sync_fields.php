<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reserve per-channel sync fields on the publication ledger so future external
 * integrations (LinkedIn, Naukri, Indeed, TrulyTalents) can record their last
 * sync time and any error without further schema changes. `external_ref`
 * (External Job ID) and `published_at` already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_job_publications', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_job_publications', 'last_synced_at')) {
                $table->timestamp('last_synced_at')->nullable()->after('published_at');
            }
            if (! Schema::hasColumn('hr_job_publications', 'error_message')) {
                $table->text('error_message')->nullable()->after('last_synced_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_job_publications', function (Blueprint $table) {
            foreach (['last_synced_at', 'error_message'] as $c) {
                if (Schema::hasColumn('hr_job_publications', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
