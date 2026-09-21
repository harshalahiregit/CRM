<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let staff_roles hold a role NAME that grants nothing.
 *
 * users.internal_role has always been two things at once: the job somebody
 * holds, and — through a dozen hardcoded checks — what they may do. Two tables
 * grew up around that. staff_roles said what a role may DO; access_roles said
 * which role NAMES are legal, and nothing else. Both wrote the same column.
 *
 * The duplication was never redundancy, it was one column doing two jobs. This
 * column is how staff_roles takes on the second one: a row may now exist purely
 * to say "this is a legal value for internal_role" without carrying permissions.
 *
 * That is what `hr` and `manager` are. routes/sangoetrack.php gates 32 routes on
 * role:admin,hr,manager, and EnsureUserHasRole matches those names against
 * users.internal_role as plain strings. Neither name was a staff_roles slug, so
 * the only place they were written down was access_roles — a table with no rows
 * and no reader. Recording them here makes the legal vocabulary explicit without
 * granting anybody anything.
 *
 * NOT NULL, default false: every existing row takes the default on the way in,
 * so the column's arrival cannot change what any role does. A nullable column
 * would have made "ordinary role" and "not yet decided" two spellings of the
 * same thing.
 *
 * It is NOT a permission bypass and must never become one. A vocabulary-only
 * role grants exactly what its (empty) permission set grants: nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_roles', function (Blueprint $table) {
            $table->boolean('is_vocabulary_only')
                ->default(false)
                ->after('is_system');
        });

        // Belt and braces for drivers that add the column without applying the
        // default to rows already present.
        DB::table('staff_roles')->whereNull('is_vocabulary_only')->update(['is_vocabulary_only' => false]);
    }

    public function down(): void
    {
        Schema::table('staff_roles', function (Blueprint $table) {
            $table->dropColumn('is_vocabulary_only');
        });
    }
};
