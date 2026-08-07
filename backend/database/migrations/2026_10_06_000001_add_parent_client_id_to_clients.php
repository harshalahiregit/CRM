<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make "Parent Company" a real relationship to another customer.
 *
 * parent_company (string) is kept alongside it, deliberately:
 *   • the CSV import/export round-trips the parent BY NAME, and an import may
 *     reference a parent that isn't a customer record (or isn't imported yet);
 *   • a holding company often exists only as a label on its subsidiaries.
 * So parent_client_id is the link when the parent IS a customer, and
 * parent_company remains the display/fallback name. Both are written together,
 * which keeps every existing reader (exports, the profile header) working
 * untouched.
 *
 * nullOnDelete: deleting a parent must not delete its subsidiaries — they just
 * lose the link and fall back to the retained name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('parent_client_id')->nullable()->after('parent_company')
                ->constrained('clients')->nullOnDelete();
        });

        // Backfill: link rows whose parent_company already matches another
        // client's company name in the same tenant (case-insensitive). Anything
        // that doesn't match stays name-only, which is a valid state.
        $rows = DB::table('clients')
            ->whereNotNull('parent_company')->where('parent_company', '!=', '')
            ->get(['id', 'tenant_id', 'parent_company']);

        foreach ($rows as $row) {
            $parentId = DB::table('clients')
                ->where('tenant_id', $row->tenant_id)
                ->whereRaw('LOWER(company) = ?', [mb_strtolower(trim($row->parent_company))])
                ->where('id', '!=', $row->id)          // never self-parent
                ->value('id');

            if ($parentId) {
                DB::table('clients')->where('id', $row->id)->update(['parent_client_id' => $parentId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_client_id');
        });
    }
};
