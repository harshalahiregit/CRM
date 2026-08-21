<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portal authentication for customer contacts.
 *
 * The old CRM's model, which this restores: a customer company never logs in —
 * its CONTACTS do. tblclients had no password; tblcontacts did. One company,
 * several logins, each seeing only what its permission flags allow.
 *
 * client_contacts already carries `password` and `permissions` — someone
 * modelled the login and never built the door, so the column has sat unused
 * (0 of 3 contacts have one set). These are the fields the door needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_contacts', function (Blueprint $table) {
            // Verification. An admin creating a contact IS the verification, so
            // that path stamps this immediately; self-service registration does not.
            $table->timestamp('email_verified_at')->nullable()->after('email');
            $table->string('email_verification_token', 64)->nullable()->after('email_verified_at')->index();

            // Set-password and reset both ride the same token, because they are
            // the same act from the contact's side: prove you own the mailbox,
            // then choose a password. Only the wording of the e-mail differs.
            $table->string('password_reset_token', 64)->nullable()->after('last_password_change')->index();
            $table->timestamp('password_reset_expires_at')->nullable()->after('password_reset_token');

            // Portal access is separate from `active`. A contact can be a live
            // relationship contact we mail invoices to without having a login,
            // which is the common case — so this defaults to off.
            $table->string('portal_status', 20)->default('inactive')->after('active')->index();
            $table->timestamp('last_login_at')->nullable()->after('portal_status');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('client_contacts', fn (Blueprint $t) => $t->dropColumn([
            'email_verified_at', 'email_verification_token',
            'password_reset_token', 'password_reset_expires_at',
            'portal_status', 'last_login_at', 'last_login_ip',
        ]));
    }
};
