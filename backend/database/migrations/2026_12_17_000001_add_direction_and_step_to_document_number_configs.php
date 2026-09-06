<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which WAY a numbering series counts, and by how much.
 *
 * The engine could already be given a manual baseline (`starting_number`), but
 * it only ever counted upwards in ones. The requirement is that an admin can
 * set the baseline AND the path — increasing or decreasing — from the UI.
 *
 * `direction` is that path. `step` is the size of each move, so a series can
 * run 100, 110, 120 or count down 5000, 4999, 4998.
 *
 * Not to be confused with `decrement_on_delete`, which already existed: that
 * rolls the cursor BACK when a document is deleted so its number can be reused.
 * It says nothing about which way the series runs.
 *
 * Defaults reproduce today's behaviour exactly — up, in ones — so no existing
 * series changes when this lands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_number_configs', function (Blueprint $table) {
            $table->string('direction', 10)->default('up')->after('starting_number');
            $table->unsignedInteger('step')->default(1)->after('direction');
        });
    }

    public function down(): void
    {
        Schema::table('document_number_configs', function (Blueprint $table) {
            $table->dropColumn(['direction', 'step']);
        });
    }
};
