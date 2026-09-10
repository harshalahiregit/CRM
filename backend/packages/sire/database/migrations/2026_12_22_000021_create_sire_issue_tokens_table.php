<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIRE — a relational inverted index over issue text.
 *
 * WHY THIS EXISTS, AND WHY IT IS NOT A VECTOR DATABASE
 *
 * The CRM has no Scout, no Meilisearch, no Elasticsearch and no full-text index —
 * every existing search is a SQL LIKE inside a module service. Duplicate detection
 * needs to shortlist candidates from tens of thousands of issues on a database
 * SHARED BY TWO DEPLOYMENTS. A LIKE scan there is somebody else's outage.
 *
 * The alternatives considered:
 *
 *   MySQL FULLTEXT   Fastest to write, but the whole test suite runs on SQLite,
 *                    which has no MATCH...AGAINST. That divergence has already
 *                    broken a deploy in this codebase.
 *   Vector store     A service to run, back up and secure, embeddings to generate,
 *                    and a second copy of every issue living outside the tenant
 *                    boundary. For a feature whose job is to produce five
 *                    candidates a human then reads.
 *   This table       Portable, indexed, ~40 narrow rows per issue, and the query
 *                    is an ordinary indexed lookup on (tenant_id, token).
 *
 * Roughly 400k rows at 10,000 issues. If a tenant ever outgrows it, the shape here
 * is exactly what a search engine would replace, one class at a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sire_issue_tokens')) {
            return;
        }

        Schema::create('sire_issue_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');   // NOT NULL by design
            $table->unsignedBigInteger('report_id');

            $table->string('token', 48);
            // Title tokens outrank body tokens; kept so retrieval can prefer them
            // without re-reading the issue.
            $table->unsignedTinyInteger('weight')->default(1);

            $table->timestamps();

            // THE retrieval index. Tenant first so the scan can never begin
            // outside the tenant, whatever the query planner decides.
            $table->index(['tenant_id', 'token'], 'sire_tok_tenant_token_idx');
            $table->unique(['tenant_id', 'report_id', 'token'], 'sire_tok_unique_idx');
            $table->index(['tenant_id', 'report_id'], 'sire_tok_report_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sire_issue_tokens');
    }
};
