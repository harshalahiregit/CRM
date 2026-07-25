<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared tags (owner: Shivam).
 *
 * This codebase already had three unrelated tag implementations — ticket_tags
 * (pivot), kb_articles.tags (JSON), leads.tags (comma-separated text) — so a
 * fourth per-module one would have been the worst option. This is generic and
 * polymorphic: any model can be tagged without a schema change.
 *
 * Deliberately NOT migrating the existing three: ticket_tags is wired into a
 * working helpdesk surface, and rewriting it isn't what this task is for. New
 * taggables (tasks, projects) use this; the old ones can move later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('color', 9)->default('#8b5cf6');
            $table->timestamps();

            // One tag per name per workspace — "Urgent" and "urgent" are the same
            // tag, which the service enforces by normalising case before lookup.
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->string('taggable_type', 60);        // 'task' | 'project' | …
            $table->unsignedBigInteger('taggable_id');  // no FK — polymorphic
            $table->timestamps();

            $table->unique(['tag_id', 'taggable_type', 'taggable_id'], 'taggables_unique');
            $table->index(['taggable_type', 'taggable_id']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
    }
};
