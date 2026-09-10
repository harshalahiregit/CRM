<?php

namespace Sire\Contracts;

use Sire\Dto\SireKnowledgeArticle;
use Sire\Dto\SireUserIdentity;

/**
 * SIRE SDK — WHAT WE ALREADY KNOW.
 *
 * SIRE owns the recommendation logic, relevance scoring and the links between
 * issues and articles. The host owns the articles.
 *
 * THE ONE PROVIDER WHOSE MISWIRING IS SILENT
 *
 * Every knowledge call in SIRE is wrapped so that a missing, slow or
 * misconfigured knowledge base degrades to "no related articles" instead of
 * breaking an issue page. That is the right behaviour — knowledge is an
 * enhancement, and an enhancement must not take down the workflow.
 *
 * The cost is that "no KB connected" and "connected, but the implementation is
 * wrong" look identical from the UI. VERIFY THIS PROVIDER WITH A SEARCH YOU KNOW
 * SHOULD RETURN A HIT — never by the absence of errors.
 *
 * Links survive without it. SIRE stores its own issue↔article links, so even
 * with no host KB the linkage feature works; only search and drafting go quiet.
 */
interface SireKnowledgeProvider
{
    /** Null when the article does not exist IN THIS TENANT. */
    public function find(int $tenantId, int|string $articleId): ?SireKnowledgeArticle;

    /**
     * Relevance search within ONE tenant.
     *
     * MUST be tenant-scoped. SIRE surfaces article titles directly to the user,
     * so an unscoped search leaks another tenant's article titles into a SIRE
     * panel.
     *
     * @return array<int, SireKnowledgeArticle>
     */
    public function search(int $tenantId, string $query, int $limit = 5): array;

    /**
     * Create an UNPUBLISHED draft from a confirmed root cause.
     *
     * Never publishes. SIRE proposes knowledge; a human approves it.
     *
     * @param  array<string, mixed> $attributes title, body, tags
     */
    public function createDraft(int $tenantId, array $attributes, SireUserIdentity $author): SireKnowledgeArticle;

    /** Whether a knowledge base is actually connected — drives what the UI offers. */
    public function isAvailable(): bool;
}
