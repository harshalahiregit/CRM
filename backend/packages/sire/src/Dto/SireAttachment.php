<?php

namespace Sire\Dto;

/**
 * SIRE SDK — a stored file, as SIRE sees it.
 *
 * SIRE stores nothing itself. Screenshots from Report Issue and QA evidence go
 * wherever the host already puts files — local disk, S3, an existing attachment
 * service — and come back as this.
 *
 * NOTE WHAT IS ABSENT: no disk name, no storage key, no filesystem path. SIRE
 * neither needs them nor should be able to leak them, and `url` is whatever
 * access-controlled URL the host already issues. A descriptor carrying a raw
 * path is one JSON response away from being a download endpoint nobody guarded.
 */
final class SireAttachment
{
    public function __construct(
        public readonly int|string $id,
        public readonly string $name,
        public readonly int $size,
        public readonly ?string $mime = null,
        public readonly ?string $url = null,
        public readonly ?string $createdAt = null,
        public readonly ?int $uploadedBy = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            name: (string) ($data['name'] ?? 'attachment'),
            size: (int) ($data['size'] ?? 0),
            mime: $data['mime'] ?? $data['mime_type'] ?? null,
            url: $data['url'] ?? null,
            createdAt: $data['created_at'] ?? null,
            uploadedBy: isset($data['uploaded_by']) ? (int) $data['uploaded_by'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'size'        => $this->size,
            'mime'        => $this->mime,
            'url'         => $this->url,
            'created_at'  => $this->createdAt,
            'uploaded_by' => $this->uploadedBy,
        ];
    }
}
