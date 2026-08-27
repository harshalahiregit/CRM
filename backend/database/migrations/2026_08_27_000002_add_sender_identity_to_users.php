<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ST1 — a per-user sender identity. Each staff account may set its own From name
 * and From email; outgoing mail the user triggers then shows that user as the
 * sender (layered on the shared tenant SMTP). Both nullable — with neither set the
 * tenant's default From is used exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('mail_from_name')->nullable()->after('email');
            $table->string('mail_from_email')->nullable()->after('mail_from_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mail_from_name', 'mail_from_email']);
        });
    }
};
