<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §25 CAPA field completeness. The doc separates the problem statement, the
 * immediate correction (containment), and the corrective vs. preventive actions
 * into distinct fields. `action` stays as the corrective action; these are added
 * alongside it. All nullable — existing rows keep working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tpv_capas', function (Blueprint $table) {
            $table->text('problem_statement')->nullable()->after('title');
            $table->text('immediate_correction')->nullable()->after('root_cause');
            $table->text('preventive_action')->nullable()->after('action');
        });
    }

    public function down(): void
    {
        Schema::table('tpv_capas', function (Blueprint $table) {
            $table->dropColumn(['problem_statement', 'immediate_correction', 'preventive_action']);
        });
    }
};
