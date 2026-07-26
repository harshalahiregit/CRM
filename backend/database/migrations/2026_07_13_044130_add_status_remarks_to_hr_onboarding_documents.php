<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-document verification for the candidate self-service portal: HR can mark
 * each uploaded document Verified or Rejected with remarks, and the candidate
 * can re-upload rejected documents. Additive — `verified` bool stays for compat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_onboarding_documents', function (Blueprint $table) {
            $table->string('status')->default('Pending')->after('verified'); // Pending|Verified|Rejected
            $table->text('remarks')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('hr_onboarding_documents', function (Blueprint $table) {
            $table->dropColumn(['status', 'remarks']);
        });
    }
};
