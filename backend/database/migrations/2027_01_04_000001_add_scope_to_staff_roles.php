<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give a role a WIDTH as well as a set of permissions.
 *
 * staff_roles.permissions has always said what a role may DO. Nothing said
 * whose records it may do it to, so every authorised person saw the whole
 * tenant. `global` is that behaviour written down.
 *
 * NOT NULL with a default of 'global', deliberately: every existing row takes
 * it on the way in, so the column's arrival cannot change anybody's access. A
 * nullable column would have made "no opinion" and "the whole company" two
 * spellings of the same thing, and the next reader would have had to guess
 * which one a NULL meant.
 *
 * Nothing is backfilled per user. users.meta.permissions is untouched, and a
 * role only stops being global when an admin says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_roles', function (Blueprint $table) {
            $table->string('scope', 20)
                ->default('global')
                ->after('permissions');
        });

        // Belt and braces for drivers that add the column without applying the
        // default to rows already present.
        DB::table('staff_roles')->whereNull('scope')->update(['scope' => 'global']);
    }

    public function down(): void
    {
        Schema::table('staff_roles', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};
