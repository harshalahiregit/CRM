<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * client_contacts.email was created NOT NULL, but every write path in the app
 * treats it as optional:
 *
 *   • ClientContactController validates it 'nullable|email|max:255'
 *   • ClientImportExportService inserts a contact when only contact_first_name
 *     is present (a company-and-name row with no address)
 *   • ClientService::syncContacts keeps a row if EITHER email or first_name is set
 *
 * So saving a contact without an email failed at the database with
 * "1048 Column 'email' cannot be null" — which reached the user as raw SQL.
 * Only the column disagreed with the intent, so the column is what changes.
 *
 * Deliberately NOT solved by making email required: a contact you only ever
 * phone is legitimate, the CSV import would start rejecting valid rows, and the
 * two direct-insert paths above bypass controller validation anyway, so a
 * validation-only fix would have left the crash reachable.
 *
 * Sending still needs an address, and that is already enforced where it matters
 * — ProposalService::submit refuses to send without a recipient email, and the
 * proposal wizard flags a contact that has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_contacts', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Blank emails may exist by the time this is rolled back; NOT NULL would
        // fail on them, so normalise to '' first.
        \Illuminate\Support\Facades\DB::table('client_contacts')->whereNull('email')->update(['email' => '']);

        Schema::table('client_contacts', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
