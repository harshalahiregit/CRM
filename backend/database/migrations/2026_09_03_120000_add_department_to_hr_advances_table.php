<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The advance form's Department box had nowhere to go.
 *
 * The app posts `department` alongside `project_site`, and the API validated it
 * — then AdvanceService::request() never copied it, because no column existed.
 * Whatever somebody typed was accepted and discarded, and the response papered
 * over the loss by echoing the EMPLOYEE's department instead, so the screen
 * showed a plausible value that was never the one entered.
 *
 * Kept separate from the employee's own department on purpose: an advance can be
 * charged to a different department than the person requesting it, which is the
 * reason the field is editable rather than displayed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_advances', function (Blueprint $table) {
            // Matches project_site's width and the API's max:120 rule.
            $table->string('department', 120)->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('hr_advances', function (Blueprint $table) {
            $table->dropColumn('department');
        });
    }
};
