<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §5 Vendor Master — the doc's Project / Department / Site / Client context on the
 * vendor profile. TPV-local, nullable, no hard FK into the Projects/Customer
 * modules (a decision to keep module isolation). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (! Schema::hasColumn('vendors', 'project')) {
                $table->string('project', 160)->nullable()->after('subcategory');
            }
            if (! Schema::hasColumn('vendors', 'site')) {
                $table->string('site', 160)->nullable()->after('project');
            }
            if (! Schema::hasColumn('vendors', 'department')) {
                $table->string('department', 160)->nullable()->after('site');
            }
            if (! Schema::hasColumn('vendors', 'client_id')) {
                $table->unsignedBigInteger('client_id')->nullable()->after('department');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['project', 'site', 'department', 'client_id']);
        });
    }
};
