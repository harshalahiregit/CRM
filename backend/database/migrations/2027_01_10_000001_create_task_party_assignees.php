<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assigning a task to a named person inside a Client, Vendor or TPV team.
 *
 * Until now "assigned" meant one thing: a row in task_assignees pointing at a
 * users id. That covers internal staff, and it covers a TPV employee only
 * because the cascade picker quietly PROVISIONS a login for them first. Everyone
 * else — a client's project coordinator, a purchase vendor's store manager, a
 * TPV's site supervisor — could not be assigned at all. The screen offered a
 * "Related to (company)" link instead, which files the task against an
 * organisation and assigns it to nobody. So the commonest real instruction in
 * this system — "Rakesh at Southgate is doing this one" — had nowhere to live.
 *
 * ── Why a second table rather than columns on task_assignees ──────────────
 * task_assignees.user_id is a non-null FK to users, and the whole module reads
 * it as one: pluck('user_id'), whereNotIn('user_id'), notify($uid), the mailer,
 * the "my tasks" filter. Making it nullable and bolting a morph beside it means
 * every one of those reads has to learn that a row might not be a user — and the
 * ones that are missed fail silently, assigning work to nobody or notifying user
 * id 0. A separate table leaves all of that untouched and keeps the new idea
 * legible: this is a PARTY assignment, and a party is not a login.
 *
 * ── Why the org and the name are stored here ──────────────────────────────
 * org_type/org_id are derivable from the contact, and the name is on the contact
 * record. Both are kept anyway:
 *
 *  • org — "every task for Southgate" and "this vendor's people only" are the
 *    two questions actually asked of this table, and neither should need a join
 *    across three different contact tables that have nothing in common.
 *  • name/email — a chip has to render in a list of fifty tasks without four
 *    joins, and a contact that is later renamed or removed should not turn a
 *    historical assignment into a blank.
 *
 * The snapshot is a cache, not the truth: the party_type/party_id pair is, and
 * TaskPartyService refreshes the snapshot whenever it re-reads a live contact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_party_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();

            // Which contact table, and which row in it. See App\Support\Task\TaskParty.
            $table->string('party_type', 32);
            $table->unsignedBigInteger('party_id');

            // The team they belong to — client / purchase_vendor / tpv_vendor.
            $table->string('org_type', 32);
            $table->unsignedBigInteger('org_id');

            $table->string('name')->nullable();
            $table->string('email')->nullable();

            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One person, one assignment per task. A second click is a no-op,
            // not a duplicate chip.
            $table->unique(['task_id', 'party_type', 'party_id'], 'task_party_unique');

            // "What is on this person's plate" — the portal's only query.
            $table->index(['tenant_id', 'party_type', 'party_id'], 'task_party_lookup');
            // "Everything assigned to anyone at this company."
            $table->index(['tenant_id', 'org_type', 'org_id'], 'task_party_org');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_party_assignees');
    }
};
