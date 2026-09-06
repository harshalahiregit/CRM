<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles and departments as DATA, so creating either stops needing a developer.
 *
 * ── What this is, and deliberately is not ───────────────────────────────
 *
 * There are two different things called "role" in this system:
 *
 *  1. `users.role` — the ACCOUNT TYPE: admin, staff, vendor, third_party_vendor,
 *     client, company, doctor. Each one is a different front door with its own
 *     portal, its own login branch and its own middleware. Adding one is not a
 *     settings change; it is a feature. These stay in code, and this table shows
 *     them read-only so an admin can see the whole picture and see why.
 *
 *  2. `users.internal_role` — a staff member's JOB role: hr_executive,
 *     hiring_manager, manager and so on. Until now these were ad-hoc strings
 *     with no catalogue anywhere: nothing listed them, nothing validated them,
 *     and a typo made a silently powerless account. THAT is what becomes data
 *     here.
 *
 * The route guards are untouched. `role:hr_executive` still compares
 * `internal_role` as a string, so an existing guard keeps working and no change
 * here can lock anybody out — which is the whole reason it is built this way
 * round rather than as a permissions layer that replaces those guards.
 *
 * `is_system` protects the slugs the code itself already references. They can be
 * renamed for display and deactivated, but never deleted and never re-slugged,
 * because a guard somewhere spells that exact string.
 *
 * Departments were equally ad-hoc: `users.department` is a free-text column, and
 * the two department tables that exist (hr_departments, ticket_departments) each
 * belong to one module and neither is the company's list.
 *
 * Named `access_departments`, not `departments`. Every other department table
 * here carries its owner's prefix, and a bare `departments` is a name any
 * module — or any test standing in for an external database — may reasonably
 * want. One already did: the SangoeTrack import tests build a `departments`
 * table to model the remote schema, and an unprefixed table here collided with
 * it and took eighteen unrelated tests down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name', 100);
            // What lands in users.internal_role, and what a route guard spells.
            $table->string('slug', 80);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            // Referenced by code; renameable and deactivatable, never deletable.
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('access_departments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name', 120);
            $table->string('code', 40)->nullable();
            $table->string('description', 500)->nullable();
            // Soft link, deliberately no FK: a department outlives whoever ran it.
            $table->unsignedBigInteger('head_user_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_departments');
        Schema::dropIfExists('access_roles');
    }
};
