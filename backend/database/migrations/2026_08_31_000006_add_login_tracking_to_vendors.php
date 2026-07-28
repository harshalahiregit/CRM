<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPV-owned portal login tracking + welcome-banner dismissal, recorded on the
 * vendor row itself so TPV owns its own numbers rather than reading Purchase's
 * (or inferring them from the shared users table). Purchase tracks the same
 * facts on purchase_vendors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (! Schema::hasColumn('vendors', 'first_login_at')) {
                $table->timestamp('first_login_at')->nullable();
            }
            if (! Schema::hasColumn('vendors', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable();
            }
            if (! Schema::hasColumn('vendors', 'login_count')) {
                $table->unsignedInteger('login_count')->default(0);
            }
            if (! Schema::hasColumn('vendors', 'welcome_banner_dismissed_at')) {
                $table->timestamp('welcome_banner_dismissed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            foreach (['first_login_at', 'last_login_at', 'login_count', 'welcome_banner_dismissed_at'] as $col) {
                if (Schema::hasColumn('vendors', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
