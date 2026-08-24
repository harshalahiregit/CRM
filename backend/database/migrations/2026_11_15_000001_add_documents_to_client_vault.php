<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The vault holds documents, not just credentials.
 *
 * Both this CRM and the legacy one built the vault as a CREDENTIAL store —
 * legacy's tblvault is server_address / port / username / password and has no
 * file column at all. So a signed agreement had nowhere to go except Files,
 * which has no per-entry visibility and no access log.
 *
 * That is backwards: a server password was better protected than a contract.
 *
 * A vault entry may now carry a credential, a document, or both. Everything
 * that already guards credentials — visibility, creator-only management, the
 * access log — applies to the file without change, because it is the same row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_vault_entries', function (Blueprint $table) {
            // What this entry IS, so a list can be filtered and a document is
            // not shown with an empty "reveal password" affordance.
            $table->string('kind', 20)->default('credential')->after('client_id');

            // Which of the restricted classes it belongs to. Free string rather
            // than an enum: every business has its own list, and an unforeseen
            // category should be recordable rather than rejected.
            $table->string('category', 60)->nullable()->after('title');

            // The document itself. Stored on the private attachments disk, the
            // same as Files — the protection here is the access rules, not
            // obscurity of the path.
            $table->string('file_name')->nullable()->after('notes');
            $table->string('file_path')->nullable()->after('file_name');
            $table->string('mime_type')->nullable()->after('file_path');
            $table->unsignedBigInteger('file_size')->nullable()->after('mime_type');

            // Agreements and certificates expire. Recorded so the Customer 360
            // alerts can warn before one lapses, the way contracts already do.
            $table->date('expires_at')->nullable()->after('file_size');

            // Explicit short name: the generated one would be
            // client_vault_entries_tenant_id_client_id_kind_index at 52 chars —
            // under MySQL's 64 today, but the guard exists because we have been
            // bitten, and naming it costs nothing.
            $table->index(['tenant_id', 'client_id', 'kind'], 'cve_tenant_client_kind_idx');
        });
    }

    public function down(): void
    {
        Schema::table('client_vault_entries', function (Blueprint $table) {
            $table->dropIndex('cve_tenant_client_kind_idx');
            $table->dropColumn([
                'kind', 'category', 'file_name', 'file_path',
                'mime_type', 'file_size', 'expires_at',
            ]);
        });
    }
};
