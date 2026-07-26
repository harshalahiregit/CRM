<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy contact-modal parity: a profile image (stored inline as a small
 * base64 data URL — no upload infra needed) and the document text direction
 * (ltr/rtl) used when rendering documents for this contact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_contacts', function (Blueprint $table) {
            $table->text('avatar')->nullable()->after('title');       // base64 data URL
            $table->string('direction', 5)->nullable()->after('avatar'); // ltr | rtl
        });
    }

    public function down(): void
    {
        Schema::table('client_contacts', function (Blueprint $table) {
            $table->dropColumn(['avatar', 'direction']);
        });
    }
};
