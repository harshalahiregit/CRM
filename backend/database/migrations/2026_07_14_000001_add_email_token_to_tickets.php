<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Email threading token. Outbound helpdesk emails carry a Reply-To that encodes
 * "{ticketId}-{token}"; an inbound reply is mapped back to its ticket by that
 * pair. The token makes the mapping unforgeable — knowing a ticket id is not
 * enough to post into its thread by email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('email_token', 40)->nullable()->after('source');
        });

        // Backfill existing tickets so their threads are reachable by email too.
        DB::table('tickets')->whereNull('email_token')->orderBy('id')->select('id')->get()
            ->each(fn ($row) => DB::table('tickets')->where('id', $row->id)->update(['email_token' => Str::random(32)]));
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('email_token');
        });
    }
};
