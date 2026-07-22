<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy contact-modal parity: a portal-login password stored (hashed)
 * directly on the contact record — same model as the old CRM's tblcontacts.
 * This is the contact's own credential for the client portal (when it ships);
 * it is NOT a main-app staff/user account, so it carries no CRM access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_contacts', function (Blueprint $table) {
            $table->string('password')->nullable()->after('direction');
            $table->dateTime('last_password_change')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('client_contacts', function (Blueprint $table) {
            $table->dropColumn(['password', 'last_password_change']);
        });
    }
};
