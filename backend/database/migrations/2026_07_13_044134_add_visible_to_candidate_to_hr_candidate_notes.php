<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets HR send a note that is visible to the candidate in their self-service
 * portal (Communication tab). Default false keeps every existing internal note
 * private to HR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_candidate_notes', function (Blueprint $table) {
            $table->boolean('visible_to_candidate')->default(false)->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('hr_candidate_notes', function (Blueprint $table) {
            $table->dropColumn('visible_to_candidate');
        });
    }
};
