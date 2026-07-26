<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In the candidate-onboarding-before-offer flow, onboarding starts as soon as
 * the candidate is Selected — before a joining date exists (that's set on the
 * offer). Allow joining_date to be null until it's known.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_onboarding', function (Blueprint $table) {
            $table->date('joining_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('hr_onboarding', function (Blueprint $table) {
            $table->date('joining_date')->nullable(false)->change();
        });
    }
};
