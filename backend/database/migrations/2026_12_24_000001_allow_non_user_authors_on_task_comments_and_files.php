<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A task comment can now be written by somebody who is not a User.
 *
 * ── Why this is needed ──────────────────────────────────────────────────
 * A Purchase vendor is its own Authenticatable (a row in `purchase_vendors`),
 * not a User — that is the whole point of the vendor-role retirement. But
 * `task_comments.user_id` was `NOT NULL` with a foreign key to `users`, so the
 * vendor a task is filed against had no way to say anything on it. The portal
 * could show the task and nothing else, which is exactly the complaint this
 * change answers.
 *
 * ── Why not a separate table ────────────────────────────────────────────
 * Purchase mirrors rather than shares (purchase_vendor_notifications is the
 * pattern). A conversation is the exception: a comment thread only works if
 * both sides are reading the SAME thread. Two tables would mean the vendor
 * writing into a thread the admin never sees, which is worse than no comments
 * at all. So the author becomes polymorphic instead.
 *
 * ── The shape ───────────────────────────────────────────────────────────
 *   author_kind  'user' | 'purchase_vendor'   (null on nothing — backfilled)
 *   author_id    the id within that space
 *   author_name  a SNAPSHOT, written only for non-User authors
 *
 * author_name is a snapshot on purpose. A User author resolves live through the
 * `user` relation, so a rename shows up everywhere. A Purchase vendor has no
 * such relation from here without teaching the shared Task module about a
 * Purchase model — which the isolation rule forbids — so the name is copied at
 * write time. A company that is later deleted still leaves a readable thread.
 *
 * `user_id` becomes nullable and keeps its foreign key: a User author is still
 * a real, cascading FK, and only non-User rows leave it null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_comments', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('author_kind', 24)->nullable()->after('user_id');
            $table->unsignedBigInteger('author_id')->nullable()->after('author_kind');
            $table->string('author_name', 150)->nullable()->after('author_id');
        });

        Schema::table('task_files', function (Blueprint $table) {
            $table->string('author_kind', 24)->nullable()->after('uploaded_by');
            $table->unsignedBigInteger('author_id')->nullable()->after('author_kind');
            $table->string('author_name', 150)->nullable()->after('author_id');
        });

        // Everything that exists today was written by a User. Stamping that
        // explicitly means the reader never has to treat null as "probably a
        // user" — after this, null author_kind means the row predates nothing.
        DB::table('task_comments')->whereNull('author_kind')->update(['author_kind' => 'user']);
        DB::statement('UPDATE task_comments SET author_id = user_id WHERE author_id IS NULL');

        DB::table('task_files')->whereNull('author_kind')->whereNotNull('uploaded_by')->update(['author_kind' => 'user']);
        DB::statement('UPDATE task_files SET author_id = uploaded_by WHERE author_id IS NULL');
    }

    public function down(): void
    {
        // A non-User author cannot be represented in the old schema — user_id is
        // about to be NOT NULL again and there is no user to point at. Those rows
        // are removed rather than silently reattributed to somebody who did not
        // write them. Reversing this migration therefore drops vendor comments,
        // which is stated here so it is a decision and not a surprise.
        DB::table('task_comments')->whereNull('user_id')->delete();

        Schema::table('task_files', function (Blueprint $table) {
            $table->dropColumn(['author_kind', 'author_id', 'author_name']);
        });

        Schema::table('task_comments', function (Blueprint $table) {
            $table->dropColumn(['author_kind', 'author_id', 'author_name']);
        });

        Schema::table('task_comments', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
