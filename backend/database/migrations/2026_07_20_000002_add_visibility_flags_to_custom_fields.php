<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remaining legacy custom-field visibility flags (tblcustomfields parity):
 * show_on_pdf (document modules), show_on_client_portal + disallow_client_edit
 * (client-portal modules — forward-wired for when the portal ships) and
 * show_on_ticket_form (tickets). The form shows each only for the modules the
 * legacy CRM shows it for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->boolean('show_on_pdf')->default(false)->after('show_on_table');
            $table->boolean('show_on_client_portal')->default(false)->after('show_on_pdf');
            $table->boolean('disallow_client_edit')->default(false)->after('show_on_client_portal');
            $table->boolean('show_on_ticket_form')->default(false)->after('disallow_client_edit');
        });
    }

    public function down(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->dropColumn(['show_on_pdf', 'show_on_client_portal', 'disallow_client_edit', 'show_on_ticket_form']);
        });
    }
};
