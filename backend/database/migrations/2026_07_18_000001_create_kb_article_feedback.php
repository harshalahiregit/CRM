<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Star-rating + comment feedback for knowledge-base articles (REQ-11).
 *
 * This complements the existing thumbs up/down (kb_article_votes) — it does not
 * replace it. A reader can leave a 1-5 star rating and an optional free-text
 * comment on a published article.
 *
 * De-dup mirrors kb_article_votes exactly: `fingerprint` is an opaque sha256
 * (anonymous readers → IP + user-agent, signed-in staff → user id), and the
 * unique index on (article_id, fingerprint) guarantees one rating per reader.
 * A re-submission updates the existing row rather than inflating the average.
 *
 * `public_slug` is captured for traceability (which shared link the rating came
 * through); aggregates (avg_rating / total_ratings) are computed on read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_article_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('kb_articles')->cascadeOnDelete();
            $table->string('public_slug')->nullable();      // which share link it came through
            $table->tinyInteger('rating');                  // 1..5 (validated in the service)
            $table->text('comment')->nullable();            // optional free-text
            $table->string('fingerprint', 64);              // sha256 hex — same scheme as votes
            $table->timestamp('created_at')->nullable();

            // One rating per reader per article (also the lookup index for de-dup).
            $table->unique(['article_id', 'fingerprint']);
            $table->index('article_id');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_article_feedback');
    }
};
