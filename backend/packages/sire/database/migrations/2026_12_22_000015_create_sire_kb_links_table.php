<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIRE — links to the EXISTING knowledge base.
 *
 * Helpdesk already owns a KB: kb_articles, kb categories, votes, feedback and
 * KnowledgeBaseService. SIRE builds none of that. This table is a join and
 * nothing more; `kb_article_id` is a logical link to Helpdesk's table, following
 * the house convention of no cross-module foreign keys.
 *
 * "Create article from resolved issue" calls KnowledgeBaseService and then writes
 * a row here. The article lives in the KB, is found by KB search, and is read by
 * agents in Helpdesk — which is the entire point of not building a second one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sire_kb_links')) {
            return;
        }

        Schema::create('sire_kb_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('report_id');
            $table->unsignedBigInteger('kb_article_id'); // logical -> kb_articles

            // resolution | known_issue | prevention | troubleshooting | reference
            $table->string('link_type', 24);
            $table->boolean('created_from_issue')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index('tenant_id');
            $table->unique(['tenant_id', 'report_id', 'kb_article_id', 'link_type'], 'sire_kb_unique_link_uq');
            $table->index(['tenant_id', 'kb_article_id'], 'sire_kb_article_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sire_kb_links');
    }
};
