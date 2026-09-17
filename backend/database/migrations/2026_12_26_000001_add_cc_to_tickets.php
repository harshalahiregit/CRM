<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who else is copied on a ticket, for its whole life.
 *
 * `ticket_replies.cc` already exists and carries the addresses an agent adds to
 * ONE reply. That is a different thing: a manager who should see the whole
 * conversation had to be re-typed on every single message, and whoever typed
 * the first reply was the only person who knew to do it.
 *
 * This is the standing list, set when the ticket is raised and applied to every
 * outbound message on it. The per-reply field stays exactly as it is — a
 * one-off copy on a single message is still a real thing to want.
 *
 * Stored as JSON rather than a pivot: these are plain e-mail addresses, often
 * for people with no account here at all, and nothing joins on them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tickets', 'cc')) {
            return;
        }

        Schema::table('tickets', function (Blueprint $table) {
            $table->json('cc')->nullable()->after('requester_email');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('cc');
        });
    }
};
