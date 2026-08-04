<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an employee record came from: 'manual' (created in the CRM) or
 * 'sangoetrack' (imported from the external HRM).
 *
 * Nullable with no backfill on purpose — every existing row predates the
 * importer, and NULL reads as "created before this was tracked" rather than
 * asserting something untrue about its origin. The UI treats NULL and 'manual'
 * the same; only 'sangoetrack' gets a badge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->string('source', 32)->nullable()->after('employee_code');
            $table->index(['tenant_id', 'source'], 'hr_employees_tenant_source_index');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropIndex('hr_employees_tenant_source_index');
            $table->dropColumn('source');
        });
    }
};
