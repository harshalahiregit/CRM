<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Helpdesk module — core schema (owner: Shivam).
 *
 * SQLite-safe: only Schema::create + enum-as-varchar (Laravel maps enum to a
 * checked varchar on SQLite). No ALTER on existing tables, so this file is fully
 * reversible and never touches Sales/HR migrations.
 *
 * Cross-module note: `tickets.customer_id` intentionally has NO foreign key.
 * Customers belong to Zafar's Sales/Customer module; the link is logical only and
 * is resolved at runtime through CustomerServiceContract (mocked for now).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Tickets ─────────────────────────────────────────────────
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('subject');
            $table->text('description')->nullable();
            $table->enum('status', ['open', 'in-progress', 'closed'])->default('open');
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');

            // Assignee is an internal staff user (shared auth table) — real FK is safe.
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            // Customer lives in Zafar's module — logical link only, NO constraint.
            $table->unsignedBigInteger('customer_id')->nullable();

            $table->dateTime('deadline')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('status');
            $table->index('priority');
            $table->index('assigned_to');
            $table->index('customer_id');
        });

        // ── Ticket Replies (conversation thread) ────────────────────
        Schema::create('ticket_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();

            // sender_type drives UI alignment (client = left, admin/agent = right).
            // sender_id is polymorphic across users (admin/agent) and customers
            // (client, Zafar's module) — so no FK, resolved via service contracts.
            $table->enum('sender_type', ['client', 'admin', 'agent'])->default('admin');
            $table->unsignedBigInteger('sender_id')->nullable();

            $table->text('message');
            $table->boolean('has_attachments')->default(false);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('ticket_id');
        });

        // ── Ticket Attachments (files on a reply) ───────────────────
        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('reply_id')->constrained('ticket_replies')->cascadeOnDelete();

            $table->string('file_path');
            $table->string('file_name');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('reply_id');
        });

        // ── Knowledge Base Categories ───────────────────────────────
        Schema::create('kb_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->timestamps();

            $table->index('tenant_id');
            $table->unique(['tenant_id', 'slug']);   // slug unique within a tenant
        });

        // ── Knowledge Base Articles ─────────────────────────────────
        Schema::create('kb_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('kb_categories')->cascadeOnDelete();

            $table->string('title');
            $table->longText('content');
            $table->unsignedInteger('thumbs_up')->default(0);
            $table->unsignedInteger('thumbs_down')->default(0);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('category_id');
        });

        // ── Ticket Feedback (CSAT) ──────────────────────────────────
        Schema::create('ticket_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();

            $table->unsignedTinyInteger('rating');            // 1–5
            $table->text('comments')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        // Reverse dependency order.
        Schema::dropIfExists('ticket_feedback');
        Schema::dropIfExists('kb_articles');
        Schema::dropIfExists('kb_categories');
        Schema::dropIfExists('ticket_attachments');
        Schema::dropIfExists('ticket_replies');
        Schema::dropIfExists('tickets');
    }
};
