<?php

namespace Sire\Support\Ai;

/**
 * SIRE AI — one request to a provider.
 *
 * Immutable, and deliberately carries no model, no Eloquent relation and no
 * request object. A provider gets scalars and arrays that SireAiRedactor has
 * already filtered — it cannot reach back for the row, so it cannot see anything
 * the redactor decided it should not.
 *
 * `tenantId` is present so a provider can partition its own caches. It is NOT a
 * hint to fetch more: providers have no database access.
 */
final class AiRequest
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $capability,
        public readonly string $subjectType,
        public readonly ?int $subjectId,
        /** @var array<string, mixed> already redacted; the complete input */
        public readonly array $context,
        /** What the redactor kept and dropped, stored alongside the suggestion. */
        public readonly array $redactionReport = [],
        public readonly ?string $locale = null,
        /** Who asked. Recorded for audit; never sent to a provider. */
        public readonly ?int $requestedBy = null,
    ) {
    }

    /**
     * Stable fingerprint of (capability + context). Used to avoid re-requesting an
     * identical suggestion and to spot when a suggestion has gone stale because
     * the issue changed underneath it.
     */
    public function fingerprint(): string
    {
        return hash('sha256', $this->capability.'|'.json_encode($this->context, JSON_UNESCAPED_SLASHES));
    }

    /** What a provider is allowed to see. Note the absence of requestedBy. */
    public function toProviderPayload(): array
    {
        return [
            'capability'   => $this->capability,
            'subject_type' => $this->subjectType,
            'context'      => $this->context,
            'locale'       => $this->locale,
        ];
    }
}
