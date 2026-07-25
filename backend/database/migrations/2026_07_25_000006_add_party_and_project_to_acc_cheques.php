<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured payee + project links on a cheque, alongside the free-text
 * party_name snapshot.
 *
 * party_type / party_id capture WHICH directory entry a cheque's payee (or a
 * received cheque's payer) resolves to:
 *   customer      → party_id = clients.id
 *   vendor / tpv  → party_id = acc_ledgers.id (a party ledger)
 * These are deliberately NOT foreign keys — the source table differs by type,
 * and the dedicated Vendor / Third-Party-Vendor modules don't exist yet. When
 * they ship they populate party ledgers (or their own tables) and this link
 * already resolves. party_name always holds a human-readable snapshot so a
 * cheque still reads correctly even if the underlying record is renamed/removed.
 *
 * project_id links to the future Projects module (no table yet → no FK); the
 * directory endpoint returns an empty list until that module lands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_cheques', function (Blueprint $table) {
            $table->string('party_type', 20)->nullable()->after('party_name'); // customer | vendor | tpv
            $table->unsignedBigInteger('party_id')->nullable()->after('party_type');
            $table->unsignedBigInteger('project_id')->nullable()->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('acc_cheques', function (Blueprint $table) {
            $table->dropColumn(['party_type', 'party_id', 'project_id']);
        });
    }
};
