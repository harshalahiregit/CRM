<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vault entry visibility (old-CRM `tblvault` parity):
 *   1 = all staff who can access this customer   (default)
 *   2 = administrators only
 *   3 = only the creator (administrators are NOT excluded)
 *
 * Plus `share_in_projects` — surface the credential to project members on the
 * customer's projects (honoured once the Projects module ships).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_vault_entries', function (Blueprint $table) {
            $table->unsignedTinyInteger('visibility')->default(1)->after('notes');
            $table->boolean('share_in_projects')->default(false)->after('visibility');
        });
    }

    public function down(): void
    {
        Schema::table('client_vault_entries', function (Blueprint $table) {
            $table->dropColumn(['visibility', 'share_in_projects']);
        });
    }
};
