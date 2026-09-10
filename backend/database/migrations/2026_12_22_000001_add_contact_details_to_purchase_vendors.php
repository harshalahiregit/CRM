<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give a Purchase vendor somewhere to keep the person we actually deal with.
 *
 * Self-registration collects a contact name, their designation, a company
 * switchboard number, headcount and MSME status — and none of it fitted on
 * purchase_vendors. It was parked on a second, hidden `users` row created
 * alongside every registration: role `vendor`, status `pending`, no tenant, and
 * listed on no screen in the application (Staff Management shows only staff and
 * admins). Three of them had already accumulated on the live workspace, and the
 * only way to see one was to query the database.
 *
 * That hidden row was also what broke "forgot password". The reset searched
 * logins first, found the pending one, and stopped — never reaching the vendor
 * account that could have sent a link.
 *
 * With these columns the vendor record holds the whole registration, the second
 * row stops being created, and a supplier is one thing in one place.
 *
 * `company_phone` is separate from `phone` on purpose: `phone` is the contact
 * person's own number and `company_phone` is the switchboard. Collapsing them
 * loses whichever was entered second.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_vendors', function (Blueprint $table) {
            // Who we speak to. Stored as one field because that is how the form
            // asks for it and how anybody addresses an email — splitting it
            // again here would invent a distinction the screens never use.
            $table->string('contact_person', 150)->nullable()->after('legal_name');
            $table->string('contact_designation', 120)->nullable()->after('contact_person');

            // The switchboard, as opposed to the contact's own line.
            $table->string('company_phone', 30)->nullable()->after('phone');

            // Free text, not an integer: the form accepts "50-100" and "approx
            // 40" as readily as a number, and rejecting those at registration
            // loses a supplier over a field nobody reports on.
            $table->string('manpower', 60)->nullable();

            // Yes / No / a registration number — a string for the same reason.
            $table->string('msme', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_vendors', function (Blueprint $table) {
            $table->dropColumn([
                'contact_person', 'contact_designation',
                'company_phone', 'manpower', 'msme',
            ]);
        });
    }
};
