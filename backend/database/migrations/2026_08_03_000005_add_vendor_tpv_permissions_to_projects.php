<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separate portal-permission sets for a Vendor and a Third-party vendor, alongside
 * the existing customer_permissions — so the "Project Settings" step can configure
 * each party independently (the doc's "separate setting tabs for vendor, TPV").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('projects')) {
            return;
        }
        Schema::table('projects', function (Blueprint $table) {
            if (! Schema::hasColumn('projects', 'vendor_permissions')) {
                $table->json('vendor_permissions')->nullable()->after('customer_permissions');
            }
            if (! Schema::hasColumn('projects', 'tpv_permissions')) {
                $table->json('tpv_permissions')->nullable()->after('vendor_permissions');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('projects')) {
            return;
        }
        Schema::table('projects', function (Blueprint $table) {
            foreach (['vendor_permissions', 'tpv_permissions'] as $col) {
                if (Schema::hasColumn('projects', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
