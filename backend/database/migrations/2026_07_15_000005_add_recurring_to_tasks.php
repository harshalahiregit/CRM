<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring tasks (owner: Shivam).
 *
 * A recurring task is a normal task that acts as a template: the scheduler copies
 * it on each due cycle, and each copy is an independent task (spec). The copy
 * points back at the template via is_recurring_from and is NOT itself recurring,
 * so copies can never spawn copies.
 *
 * deadline_notified stops the due-date reminder firing on every scheduler tick.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->boolean('recurring')->default(false)->after('kanban_order');
            $table->string('recurring_type', 10)->nullable()->after('recurring');   // day|week|month|year
            $table->unsignedInteger('repeat_every')->default(0)->after('recurring_type');
            $table->unsignedInteger('cycles')->default(0)->after('repeat_every');        // 0 = infinite
            $table->unsignedInteger('total_cycles')->default(0)->after('cycles');        // completed so far
            $table->date('last_recurring_date')->nullable()->after('total_cycles');
            $table->foreignId('is_recurring_from')->nullable()->after('last_recurring_date')
                ->constrained('tasks')->nullOnDelete();

            $table->boolean('deadline_notified')->default(false)->after('is_recurring_from');

            $table->index('recurring');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['is_recurring_from']);
            $table->dropColumn([
                'recurring', 'recurring_type', 'repeat_every', 'cycles', 'total_cycles',
                'last_recurring_date', 'is_recurring_from', 'deadline_notified',
            ]);
        });
    }
};
