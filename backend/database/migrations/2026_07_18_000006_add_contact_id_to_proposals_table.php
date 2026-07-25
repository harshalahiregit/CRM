<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            // The specific person the proposal is addressed to (meeting 2.2
            // step 1): recipient of the submit email and OTP codes.
            $table->foreignId('contact_id')->nullable()->after('rel_id')
                ->constrained('client_contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_id');
        });
    }
};
