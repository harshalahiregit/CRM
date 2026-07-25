<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the cheque register for the full cheque module (spec §2 & §4):
 *  - chequebook_id links an issued cheque to the book its leaf came from
 *    (drives auto-sequencing).
 *  - is_account_payee records the "A/C Payee" crossing for issued cheques.
 *  - reference holds the project / work reference (issued) or purpose (received).
 *  - source_type + payer_bank describe an INCOMING cheque: who it's from
 *    (client / vendor) and which bank it's drawn on (the drawee), which for a
 *    received cheque is NOT our own bank_account_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_cheques', function (Blueprint $table) {
            $table->foreignId('chequebook_id')->nullable()->after('bank_account_id')
                ->constrained('acc_chequebooks')->nullOnDelete();
            $table->boolean('is_account_payee')->default(true)->after('amount');
            $table->string('reference', 255)->nullable()->after('memo');   // project/work ref (issued) or purpose (received)
            $table->string('source_type', 20)->nullable()->after('reference'); // client | vendor | other (received)
            $table->string('payer_bank', 255)->nullable()->after('source_type'); // drawee bank on a received cheque
        });
    }

    public function down(): void
    {
        Schema::table('acc_cheques', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chequebook_id');
            $table->dropColumn(['is_account_payee', 'reference', 'source_type', 'payer_bank']);
        });
    }
};
