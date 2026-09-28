<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A self-registered driver gets a login (users row) AND a person in STOS's own
 * driver register (stos_drivers). Nothing tied the two together, so the app had
 * no way to answer "which driver am I?" — needed for a driver to see and manage
 * their own profile and documents. This adds that link.
 *
 * Nullable: office-managed drivers in stos_drivers have no login and never will.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stos_drivers', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('company_id');
            $table->index(['company_id', 'user_id'], 'stos_drivers_company_user_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stos_drivers', function (Blueprint $table) {
            $table->dropIndex('stos_drivers_company_user_idx');
            $table->dropColumn('user_id');
        });
    }
};
