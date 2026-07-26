<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task reminders (owner: Shivam). "Remind {user} about {task} at {time}" — the
 * scheduler turns each due row into an in-app notification exactly once
 * (notified_at is the idempotency guard, not a log field).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();   // who to remind
            $table->foreignId('created_by')->constrained('users');

            $table->string('description', 255)->nullable();
            $table->timestamp('remind_at');
            $table->timestamp('notified_at')->nullable();   // null = still pending

            $table->timestamps();

            $table->index('task_id');
            // The scheduler's exact lookup: pending reminders that are now due.
            $table->index(['notified_at', 'remind_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_reminders');
    }
};
