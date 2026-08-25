<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-recipient read token.
 *
 * "Viewed" can only be tracked per person if each person gets their own link.
 * The meeting-level ack_token stays exactly as it is — it is the vendor's
 * ACKNOWLEDGE credential and must not be handed to everyone — this is a
 * read-only token that opens the minutes and stamps that one recipient's
 * viewed_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_distributions', function (Blueprint $table) {
            $table->string('token', 64)->nullable()->unique()->after('channel');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_distributions', function (Blueprint $table) {
            $table->dropColumn('token');
        });
    }
};
