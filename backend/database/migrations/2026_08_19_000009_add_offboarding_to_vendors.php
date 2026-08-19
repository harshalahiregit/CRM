<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor offboarding (Doc 5). Ending an engagement is terminal: the vendor is
 * Offboarded, the login is locked, and their on-site workers are terminated so
 * no badge survives the relationship. These columns record when and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (! Schema::hasColumn('vendors', 'offboarded_at')) {
                $table->timestamp('offboarded_at')->nullable();
            }
            if (! Schema::hasColumn('vendors', 'offboarding_reason')) {
                $table->string('offboarding_reason', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            foreach (['offboarded_at', 'offboarding_reason'] as $col) {
                if (Schema::hasColumn('vendors', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
