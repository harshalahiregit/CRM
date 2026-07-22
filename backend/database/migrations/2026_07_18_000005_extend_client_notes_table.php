<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_notes', function (Blueprint $table) {
            $table->string('priority', 10)->nullable()->after('content');   // low|medium|high|urgent
            $table->date('deadline')->nullable()->after('priority');
            $table->dateTime('reminder_at')->nullable()->after('deadline');
            // team = all staff; private = author only; client = visible on
            // the client portal once one exists.
            $table->string('visibility', 10)->default('team')->after('reminder_at');
        });

        // Legacy notes are plain text; the UI now renders content as HTML —
        // escape them once so "<3" or "a < b" in old notes can't break markup.
        foreach (DB::table('client_notes')->get(['id', 'content']) as $row) {
            if ($row->content !== strip_tags($row->content)) {
                continue; // already contains markup — leave untouched
            }
            $escaped = nl2br(e($row->content), false);
            if ($escaped !== $row->content) {
                DB::table('client_notes')->where('id', $row->id)->update(['content' => $escaped]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('client_notes', function (Blueprint $table) {
            $table->dropColumn(['priority', 'deadline', 'reminder_at', 'visibility']);
        });
    }
};
