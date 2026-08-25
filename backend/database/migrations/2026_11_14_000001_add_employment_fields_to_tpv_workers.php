<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §14 Workforce — employment context fields the doc lists on a worker:
 * years of experience, joining date and exit date. All nullable so existing
 * workers are unaffected. (The doc's "Project" field is deferred: it crosses
 * into the Projects module and needs a cross-module decision.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tpv_workers', function (Blueprint $table) {
            $table->decimal('experience_years', 4, 1)->nullable()->after('skill_category');
            $table->date('joining_date')->nullable()->after('experience_years');
            $table->date('exit_date')->nullable()->after('joining_date');
        });
    }

    public function down(): void
    {
        Schema::table('tpv_workers', function (Blueprint $table) {
            $table->dropColumn(['experience_years', 'joining_date', 'exit_date']);
        });
    }
};
