<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR1 — an optional FIXED billable amount per task (a quote amount, independent
 * of logged hours). When set it is the task's billable amount; when null the
 * effective amount falls back to hourly_rate × logged hours. Nullable & additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->decimal('billable_amount', 12, 2)->nullable()->after('hourly_rate');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('billable_amount');
        });
    }
};
