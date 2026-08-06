<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An anonymous poll hides WHO voted for what — only the tallies are shown.
 * A non-anonymous poll may additionally expose the voter names per option.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('polls') && ! Schema::hasColumn('polls', 'is_anonymous')) {
            Schema::table('polls', function (Blueprint $t) {
                $t->boolean('is_anonymous')->default(false)->after('allow_multiple');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('polls', 'is_anonymous')) {
            Schema::table('polls', function (Blueprint $t) {
                $t->dropColumn('is_anonymous');
            });
        }
    }
};
