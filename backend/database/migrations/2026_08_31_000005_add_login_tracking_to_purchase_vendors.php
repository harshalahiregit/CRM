<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase-owned portal login tracking + welcome-banner dismissal.
 *
 * last_login_at already exists on this table; first_login_at and login_count
 * complete the picture, and welcome_banner_dismissed_at persists the dismissal
 * server-side (never localStorage) so the banner cannot reappear on another
 * device. TPV tracks the same facts on its own table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_vendors', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_vendors', 'first_login_at')) {
                $table->timestamp('first_login_at')->nullable();
            }
            if (! Schema::hasColumn('purchase_vendors', 'login_count')) {
                $table->unsignedInteger('login_count')->default(0);
            }
            if (! Schema::hasColumn('purchase_vendors', 'welcome_banner_dismissed_at')) {
                $table->timestamp('welcome_banner_dismissed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_vendors', function (Blueprint $table) {
            foreach (['first_login_at', 'login_count', 'welcome_banner_dismissed_at'] as $col) {
                if (Schema::hasColumn('purchase_vendors', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
