<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A task's rate was a bare number — the doc asks for a unit alongside it so a rate
 * reads as "₹500 / hour" vs "₹500 / day" vs a one-off fixed price. Nullable with a
 * sane default keeps every existing row valid and defaults new tasks to hourly,
 * which is what the single field always meant implicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tasks') && ! Schema::hasColumn('tasks', 'rate_unit')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->string('rate_unit', 10)->default('hourly')->after('hourly_rate');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tasks') && Schema::hasColumn('tasks', 'rate_unit')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->dropColumn('rate_unit');
            });
        }
    }
};
