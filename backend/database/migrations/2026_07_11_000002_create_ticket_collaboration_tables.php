<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Helpdesk Phase 2 — ticket collaboration (owner: Shivam). SQLite-safe, additive.
 *
 *  - ticket_notes: PRIVATE internal notes, never sent to the client, never shown
 *    in the public/client thread. Deliberately separate from ticket_replies.
 *  - ticket_reminders: per-user reminders on a ticket (remind_at + done toggle).
 *  - ticket_relations: manual "related to" links between two tickets (the UI links
 *    both directions in one action; stored as directed rows, no FK cascade needed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('content');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('ticket_id');
        });

        Schema::create('ticket_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('remind_at');
            $table->string('note')->nullable();
            $table->boolean('is_done')->default(false);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('ticket_id');
            $table->index('remind_at');
        });

        Schema::create('ticket_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // No FK cascade on the ticket columns — just indexed integers, so deleting
            // one side leaves a harmless dangling row the service filters out.
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('related_ticket_id');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('ticket_id');
            $table->unique(['ticket_id', 'related_ticket_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_relations');
        Schema::dropIfExists('ticket_reminders');
        Schema::dropIfExists('ticket_notes');
    }
};
