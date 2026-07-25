<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-05 — per-user "new ticket" indicator. `seen_by` holds the ids of the staff
 * users who have already opened this ticket; a ticket is "new" for anyone whose
 * id is not in the list. Nullable so brand-new (and all existing) tickets start
 * as unseen-by-everyone until each user opens them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->json('seen_by')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('seen_by');
        });
    }
};
