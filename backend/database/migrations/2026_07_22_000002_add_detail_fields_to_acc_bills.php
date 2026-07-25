<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Old-CRM Bills parity: attachment, approval step, and recurring metadata. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_bills', function (Blueprint $table) {
            $table->text('attachment')->nullable()->after('note'); // base64 data URL, small files only
            $table->string('attachment_name')->nullable()->after('attachment');

            $table->boolean('approved')->default(false)->after('status');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();

            $table->boolean('is_recurring')->default(false)->after('approved_at');
            $table->string('recurring_type', 10)->nullable(); // week|month|year
            $table->unsignedSmallInteger('recurring_every')->nullable(); // every N periods
            $table->unsignedSmallInteger('recurring_cycles')->nullable(); // null = indefinite
            $table->unsignedSmallInteger('recurring_done')->default(0);
            $table->foreignId('recurring_parent_id')->nullable()->constrained('acc_bills')->nullOnDelete();
            $table->date('next_recurrence_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('acc_bills', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('recurring_parent_id');
            $table->dropColumn([
                'attachment', 'attachment_name', 'approved', 'approved_at',
                'is_recurring', 'recurring_type', 'recurring_every',
                'recurring_cycles', 'recurring_done', 'next_recurrence_date',
            ]);
        });
    }
};
