<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let tenants add and rename voucher types. The nine seeded types are marked
 * is_system (renameable but not deletable — the automated posting bridges
 * resolve them by `code`); custom types are user-added labels with their own
 * numbering series. Posting is unaffected: PostingService derives the ledger
 * legs from the lines the caller supplies, never from the voucher-type code,
 * so a custom type is just a named + separately-numbered manual voucher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_voucher_types', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('name');
            $table->boolean('active')->default(true)->after('is_system');
        });

        // The nine seeded codes are system types.
        DB::table('acc_voucher_types')->whereIn('code', [
            'sales', 'purchase', 'payment', 'receipt', 'contra',
            'journal', 'debit_note', 'credit_note', 'stock_journal',
        ])->update(['is_system' => true]);
    }

    public function down(): void
    {
        Schema::table('acc_voucher_types', function (Blueprint $table) {
            $table->dropColumn(['is_system', 'active']);
        });
    }
};
