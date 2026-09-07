<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave policies could be scoped to a Grade or a Designation but not to a
 * Department -- so "everyone in Sales gets 18 earned leaves" had no expression
 * in the product, and HR assigned the policy to each person in Sales by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_leave_policies', function (Blueprint $table) {
            $table->unsignedBigInteger('department_id')->nullable()->after('grade_id');
        });
    }

    public function down(): void
    {
        Schema::table('hr_leave_policies', function (Blueprint $table) {
            $table->dropColumn('department_id');
        });
    }
};
