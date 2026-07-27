<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E-4 — Purchase-owned portal authentication fields on purchase_vendors.
 * The Purchase vendor portal authenticates directly against this table (Sanctum
 * tokens whose tokenable is PurchaseVendor) — never through the shared User /
 * vendors / TPV auth. Additive; touches only purchase_vendors.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('purchase_vendors')) {
            return;
        }

        Schema::table('purchase_vendors', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_vendors', 'password')) {
                $table->string('password')->nullable();
            }
            if (! Schema::hasColumn('purchase_vendors', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable();
            }
            if (! Schema::hasColumn('purchase_vendors', 'email_verification_token')) {
                $table->string('email_verification_token', 64)->nullable();
            }
            if (! Schema::hasColumn('purchase_vendors', 'password_reset_token')) {
                $table->string('password_reset_token', 64)->nullable();
                $table->timestamp('password_reset_expires_at')->nullable();
            }
            if (! Schema::hasColumn('purchase_vendors', 'remember_token')) {
                $table->rememberToken();
            }
            if (! Schema::hasColumn('purchase_vendors', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable();
                $table->string('last_login_ip')->nullable();
            }
            if (! Schema::hasColumn('purchase_vendors', 'portal_status')) {
                // inactive (no login yet) | active | suspended
                $table->string('portal_status')->default('inactive')->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('purchase_vendors')) {
            return;
        }
        Schema::table('purchase_vendors', function (Blueprint $table) {
            foreach ([
                'password', 'email_verified_at', 'email_verification_token',
                'password_reset_token', 'password_reset_expires_at', 'remember_token',
                'last_login_at', 'last_login_ip', 'portal_status',
            ] as $col) {
                if (Schema::hasColumn('purchase_vendors', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
