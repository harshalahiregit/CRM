<?php

namespace Sire\Dto;

/**
 * SIRE SDK — a knowledge base article, as SIRE sees it.
 *
 * SIRE builds no knowledge base. It links to the host's, and drafts into it when
 * a confirmed root cause turns out to be worth writing down.
 *
 * `status` matters for one reason: SIRE may only ever CREATE DRAFTS. SIRE
 * proposes knowledge; a human publishes it.
 */
final class SireKnowledgeArticle
{
    public function __construct(
        public readonly int|string $id,
        public readonly string $title,
        public readonly ?string $excerpt = null,
        public readonly ?string $url = null,
        public readonly ?string $status = null,
        public readonly ?string $updatedAt = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            title: (string) ($data['title'] ?? ''),
            excerpt: $data['excerpt'] ?? $data['summary'] ?? null,
            url: $data['url'] ?? null,
            status: $data['status'] ?? null,
            updatedAt: $data['updated_at'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'title'      => $this->title,
            'excerpt'    => $this->excerpt,
            'url'        => $this->url,
            'status'     => $this->status,
            'updated_at' => $this->updatedAt,
        ];
    }

    /** Text the relevance scorer ranks on. Title carries most of the signal. */
    public function searchableText(): string
    {
        return trim($this->title.' '.($this->excerpt ?? ''));
    }
}
