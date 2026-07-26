<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Helpdesk Phase 3 — CC on replies, ticket tags, ticket merge. SQLite-safe, additive.
 *
 *  - ticket_replies.cc: JSON array of CC email addresses on a reply.
 *  - tickets.merged_into_id: when a ticket is merged, points at the surviving
 *    ticket so visiting the merged one can redirect. No FK (self-reference kept
 *    loose; the merged ticket also gets status='merged' seeded in Phase 1).
 *  - ticket_tags + ticket_tag_pivot: free-form colored tags on tickets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_replies', function (Blueprint $table) {
            $table->json('cc')->nullable()->after('message'); // array of email strings
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('merged_into_id')->nullable()->after('project_id');
            $table->index('merged_into_id');
        });

        Schema::create('ticket_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 9)->default('#22d3ee');
            $table->timestamps();

            $table->index('tenant_id');
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('ticket_tag_pivot', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('ticket_tags')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ticket_id', 'tag_id']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_tag_pivot');
        Schema::dropIfExists('ticket_tags');

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('merged_into_id');
        });
        Schema::table('ticket_replies', function (Blueprint $table) {
            $table->dropColumn('cc');
        });
    }
};
