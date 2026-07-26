<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            // Portal engagement — a separate signal from email opens.
            $table->dateTime('portal_viewed_at')->nullable()->after('email_opened_count');
            $table->unsignedInteger('portal_view_count')->default(0)->after('portal_viewed_at');
            // Submit-email draft persists even if the send fails.
            $table->string('email_subject')->nullable()->after('portal_view_count');
            $table->text('email_body')->nullable()->after('email_subject');
            $table->json('email_cc')->nullable()->after('email_body');
            $table->dateTime('last_emailed_at')->nullable()->after('email_cc');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn([
                'portal_viewed_at', 'portal_view_count',
                'email_subject', 'email_body', 'email_cc', 'last_emailed_at',
            ]);
        });
    }
};
