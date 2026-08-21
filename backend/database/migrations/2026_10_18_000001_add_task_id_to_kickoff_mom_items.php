<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a MOM action to a real Sangoe Task (Meeting.docx §8 — "every action should
 * become a trackable Sangoe task … a real task record connected to Meeting →
 * Project → Vendor → Work Package → Person").
 *
 * A soft link (no FK): the task lives in Shivam's Task module; if it is deleted
 * the action simply shows as unlinked again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kickoff_mom_items', function (Blueprint $table) {
            $table->unsignedBigInteger('task_id')->nullable()->after('depends_on_id');
        });
    }

    public function down(): void
    {
        Schema::table('kickoff_mom_items', function (Blueprint $table) {
            $table->dropColumn('task_id');
        });
    }
};
