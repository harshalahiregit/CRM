<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records WHICH table a cheque's party_id points at.
 *
 * The payee directory offers vendors from two places — control ledgers
 * auto-created by the Bills flow (acc_ledgers) and the vendor master
 * (vendors) — and both arrive as party_type 'vendor'. Their id spaces
 * overlap, so `party_id = 12` was ambiguous: ledger 12 and vendor 12 are
 * different companies, and neither is protected by a foreign key. Resolving a
 * saved payee back to its record was therefore guesswork.
 *
 *   client        -> clients.id
 *   ledger        -> acc_ledgers.id      (party ledger)
 *   vendor_master -> vendors.id          (vendor/TPV registry)
 *
 * Nullable, because party_name has always been the authoritative snapshot and
 * older rows have no way to say which table they meant.
 *
 * Backfill is deliberately conservative: only rows whose party_id resolves in
 * exactly ONE of the candidate tables are labelled. Anything ambiguous is left
 * null rather than guessed, since a wrong label is worse than an absent one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_cheques', function (Blueprint $table) {
            $table->string('party_source', 20)->nullable()->after('party_id');
        });

        $rows = DB::table('acc_cheques')
            ->whereNotNull('party_id')->whereNotNull('party_type')
            ->get(['id', 'tenant_id', 'party_type', 'party_id']);

        foreach ($rows as $row) {
            $candidates = [];

            if ($row->party_type === 'customer' && Schema::hasTable('clients')) {
                $candidates[] = ['client', 'clients'];
            }
            if (in_array($row->party_type, ['vendor', 'tpv'], true)) {
                if (Schema::hasTable('acc_ledgers')) {
                    $candidates[] = ['ledger', 'acc_ledgers'];
                }
                if (Schema::hasTable('vendors')) {
                    $candidates[] = ['vendor_master', 'vendors'];
                }
            }

            $hits = [];
            foreach ($candidates as [$label, $table]) {
                $exists = DB::table($table)
                    ->where('tenant_id', $row->tenant_id)
                    ->where('id', $row->party_id)
                    ->exists();
                if ($exists) {
                    $hits[] = $label;
                }
            }

            // Exactly one match = unambiguous. Zero or several = leave null.
            if (count($hits) === 1) {
                DB::table('acc_cheques')->where('id', $row->id)->update(['party_source' => $hits[0]]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('acc_cheques', function (Blueprint $table) {
            $table->dropColumn('party_source');
        });
    }
};
