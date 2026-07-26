<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The candidate fills the onboarding form BEFORE they become an employee, so the
 * onboarding record must be able to exist against a candidate only. candidate_id and
 * onboarding_id are already nullable; employee_id is populated later, on joining.
 * Purely a nullability relaxation — no data change, fully backward compatible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employee_onboardings', function (Blueprint $t) {
            $t->unsignedBigInteger('employee_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('hr_employee_onboardings', function (Blueprint $t) {
            $t->unsignedBigInteger('employee_id')->nullable(false)->change();
        });
    }
};
