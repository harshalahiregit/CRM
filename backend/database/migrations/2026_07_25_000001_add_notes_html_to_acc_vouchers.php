<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rich-text note support for vouchers, first used by Transfer Funds. `narration`
 * stays a plain string — it's read as plain text everywhere across the ledger
 * (Vouchers list, Day Book, General Ledger, Ledger Statement, GSTR reports…),
 * so it keeps carrying a stripped preview of the note. `notes_html` is the
 * formatted version (bold/italic/links/etc., sanitized via HtmlSanitizer) that
 * only screens built to render rich content read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_vouchers', function (Blueprint $table) {
            $table->longText('notes_html')->nullable()->after('narration');
        });
    }

    public function down(): void
    {
        Schema::table('acc_vouchers', function (Blueprint $table) {
            $table->dropColumn('notes_html');
        });
    }
};
