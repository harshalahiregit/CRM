<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A standing notice period for one person.
 *
 * Notice already resolves through three levels — a value typed onto the exit
 * request, the exit policy matched to the employee's grade / designation /
 * department, and the exit type's default. Grade-level notice is therefore NOT
 * missing: it is configured by giving a grade its own exit policy, and
 * ExitRepository::policyForEmployee() has matched on grade_id since exits
 * shipped. Adding a notice column to hr_grades would have created a second
 * grade-level source disagreeing with the first.
 *
 * What is missing is the person. Somebody hired on a six-month notice when
 * their grade says two had nowhere to record it: HR had to remember at the
 * moment of resignation and type it in, on the one screen where a forgotten
 * number is expensive and cannot be corrected afterwards without editing a
 * live exit.
 *
 * NULL IS NOT ZERO. Null means "no override, inherit"; 0 means "this person
 * genuinely serves no notice", which is a real arrangement for a contractor or
 * a settlement. A default of 0 would have made every employee an explicit
 * zero-notice override and silently emptied the policy layer, so the column is
 * nullable with no default and nothing is backfilled.
 *
 * Additive. Every existing employee keeps a null and resolves exactly as it
 * did before this ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_employees') || Schema::hasColumn('hr_employees', 'notice_days')) {
            return;
        }

        Schema::table('hr_employees', function (Blueprint $table) {
            $table->unsignedSmallInteger('notice_days')->nullable()->after('confirmation_date');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hr_employees') || ! Schema::hasColumn('hr_employees', 'notice_days')) {
            return;
        }

        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn('notice_days');
        });
    }
};
