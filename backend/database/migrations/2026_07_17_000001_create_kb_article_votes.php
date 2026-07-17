<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-vote-per-voter ledger for knowledge-base article feedback (BUG-06).
 *
 * Before this, publicVote() blindly incremented thumbs_up, so a single visitor
 * could inflate an article's score without limit (the 30/min throttle only
 * slowed it down, it never stopped it). This table records who voted so a
 * repeat vote can be rejected, and a changed vote can flip cleanly.
 *
 * `fingerprint` is an opaque sha256 — for anonymous public voters it is derived
 * from IP + user-agent, for signed-in staff from the user id. No raw IP is
 * stored, so this stays privacy-neutral.
 *
 * The thumbs_up / thumbs_down counters on kb_articles remain the fast read
 * path; this table is only consulted on write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_article_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('kb_articles')->cascadeOnDelete();
            $table->string('fingerprint', 64);      // sha256 hex
            $table->string('vote', 4);              // 'up' | 'down' (string, not enum — SQLite-safe)
            $table->timestamps();

            // The guarantee: one row per voter per article. Also the lookup index.
            $table->unique(['article_id', 'fingerprint']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_article_votes');
    }
};
