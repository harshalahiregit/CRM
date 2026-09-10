<?php

namespace Sire\Dto;

/**
 * SIRE SDK — where the user was when something broke.
 *
 * This object is why Report Issue is one click instead of a form. Everything in
 * it is derived or supplied automatically; the user is asked for none of it.
 *
 * TWO WAYS IT GETS FILLED, AND THE ORDER MATTERS
 *
 *   1. The host DECLARES it — `SireContext.register({...})` in the SPA. Always
 *      wins, because a screen that names itself is authoritative in a way no
 *      pattern match can be.
 *   2. SIRE INFERS it from the path, using the host-supplied route map.
 *
 * `confidence` records which happened, and it is honest rather than flattering:
 *
 *   declared  the host said so
 *   high      an exact route pattern matched
 *   medium    a prefix matched; the module is right, the screen may not be
 *   low       nothing matched
 *
 * LOW IS A SUPPORTED OUTCOME, NOT A FAILURE. An unmapped route shows an editable
 * context and the report still submits in one click. A user who cannot report a
 * problem is a far worse outcome than an issue filed against an unknown screen.
 */
final class SireScreenContext
{
    public const CONFIDENCE_DECLARED = 'declared';
    public const CONFIDENCE_HIGH     = 'high';
    public const CONFIDENCE_MEDIUM   = 'medium';
    public const CONFIDENCE_LOW      = 'low';

    public function __construct(
        public readonly ?string $module = null,
        public readonly ?string $section = null,
        public readonly ?string $screen = null,
        public readonly ?string $route = null,
        public readonly ?string $entityType = null,
        public readonly int|string|null $entityId = null,
        public readonly ?string $entityLabel = null,
        public readonly string $confidence = self::CONFIDENCE_LOW,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            module: $data['module'] ?? null,
            section: $data['section'] ?? null,
            screen: $data['screen'] ?? null,
            route: $data['route'] ?? $data['path'] ?? null,
            entityType: $data['entity_type'] ?? $data['entityType'] ?? null,
            entityId: $data['entity_id'] ?? $data['entityId'] ?? null,
            entityLabel: $data['entity_label'] ?? $data['entityLabel'] ?? null,
            confidence: (string) ($data['confidence'] ?? self::CONFIDENCE_LOW),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'module'       => $this->module,
            'section'      => $this->section,
            'screen'       => $this->screen,
            'route'        => $this->route,
            'entity_type'  => $this->entityType,
            'entity_id'    => $this->entityId,
            'entity_label' => $this->entityLabel,
            'confidence'   => $this->confidence,
        ];
    }

    /** Nothing was resolved — the modal should invite a correction. */
    public function isUnresolved(): bool
    {
        return $this->module === null && $this->screen === null;
    }

    /**
     * Overlay a declared context on an inferred one.
     *
     * Declared values win field by field, so a host can name just the screen and
     * still keep the inferred module and entity. Anything it declares is marked
     * `declared` — a host that took the trouble to say knows better than a regex.
     */
    public function mergedWith(?self $declared): self
    {
        if ($declared === null) {
            return $this;
        }

        return new self(
            module: $declared->module ?? $this->module,
            section: $declared->section ?? $this->section,
            screen: $declared->screen ?? $this->screen,
            route: $declared->route ?? $this->route,
            entityType: $declared->entityType ?? $this->entityType,
            entityId: $declared->entityId ?? $this->entityId,
            entityLabel: $declared->entityLabel ?? $this->entityLabel,
            confidence: self::CONFIDENCE_DECLARED,
        );
    }
}
