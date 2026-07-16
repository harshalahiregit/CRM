<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Follow-ups / Reminders (polymorphic) ────────────────────
        // Data + manual "complete" + due lists only. Automated sending
        // (email/SMS/WhatsApp) and recurrence cron are deferred — Sales has
        // no Mailable/SMTP wiring yet (matches PaymentReminder precedent).
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('remindable_type');
            $table->unsignedBigInteger('remindable_id');
            $table->enum('type', ['call', 'meeting', 'email', 'whatsapp', 'sms', 'visit'])->default('call');
            $table->string('title');
            $table->text('notes')->nullable();
            $table->dateTime('due_at');
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('outcome')->nullable();
            $table->dateTime('next_follow_up')->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->unsignedInteger('recur_every')->nullable();
            $table->enum('recur_type', ['day', 'week', 'month', 'year'])->nullable();
            $table->boolean('is_notified')->default(false);
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['remindable_type', 'remindable_id']);
            $table->index('due_at');
            $table->index('is_notified');
            $table->index('staff_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
