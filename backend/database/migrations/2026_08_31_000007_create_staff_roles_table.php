<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles as records, following the old CRM.
 *
 * Perfex keeps roles in tblroles — a name and a permissions blob — and points
 * tblstaff.role at one. Here the permission templates lived in a JavaScript map
 * inside one modal, so adding a role meant editing frontend code and deploying,
 * and changing what a role meant never reached anybody who already had it.
 *
 * NOT a replacement for users.role. That column is the account TYPE — admin,
 * staff, client, vendor — and is checked in dozens of places. This is the job
 * function and what it may do, which is a different question.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            $table->string('name', 80);
            // The slug is also written to users.internal_role, so it is what the
            // checks already scattered through the codebase match on.
            $table->string('slug', 60);
            $table->string('description', 255)->nullable();

            $table->json('permissions')->nullable();

            // Seeded roles can be edited but not deleted: removing the one every
            // employee holds is not an undo anybody wants to discover.
            $table->boolean('is_system')->default(false);

            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Nullable, and stays nullable: every existing user has no role and
            // must keep working exactly as they do now, on their own grid.
            $table->unsignedBigInteger('staff_role_id')->nullable()->after('internal_role')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The index goes FIRST. SQLite refuses to drop a column an index
            // still points at, so a down() that only drops the column works on
            // MySQL and fails everywhere else — the kind of difference that only
            // shows up when somebody actually needs to roll back.
            $table->dropIndex(['staff_role_id']);
            $table->dropColumn('staff_role_id');
        });

        Schema::dropIfExists('staff_roles');
    }
};
