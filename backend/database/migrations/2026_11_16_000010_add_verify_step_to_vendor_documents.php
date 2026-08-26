<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §30 — a distinct verify-vs-approve step for vendor documents. Verification
 * (the reviewer confirms the file is genuine/legible) is now captured separately
 * from approval (the authority accepts it), each with its own actor/timestamp.
 * Additive: the existing single-step approve/reject path keeps working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('verified_by')->nullable()->after('reviewed_at');
            $table->timestamp('verified_at')->nullable()->after('verified_by');
            // Explicit renewal tracking — when this document should be renewed by.
            $table->date('renewal_due_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_documents', function (Blueprint $table) {
            $table->dropColumn(['verified_by', 'verified_at', 'renewal_due_at']);
        });
    }
};
