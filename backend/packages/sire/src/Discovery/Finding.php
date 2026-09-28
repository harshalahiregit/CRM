<?php

namespace Sire\Discovery;

/**
 * SIRE — one thing discovery believes about the host, and how sure it is.
 *
 * A bare value would be a lie by omission. "tenant column: tenant_id" reads as
 * fact whether it came from an exact column match or from a hopeful guess at a
 * name, and the difference between those two is the difference between a
 * working installation and a silent cross-tenant leak.
 *
 * So every finding carries its CONFIDENCE and its EVIDENCE — what was actually
 * looked at to reach this conclusion. The installer uses confidence to decide
 * what it may apply silently and what a human must confirm; a person reading the
 * profile uses the evidence to decide whether discovery was right.
 *
 * CONFIDENCE LEVELS
 *
 *   HIGH    Directly observed and unambiguous. A class exists; a column exists;
 *           composer.json names the package.
 *   MEDIUM  Observed, but more than one reading is possible. Two plausible
 *           tenant columns; a role column with unfamiliar values.
 *   LOW     Inferred from a naming convention alone. Enough to propose, never
 *           enough to apply.
 *   NONE    Looked for and not found. Not an error: SIRE works without a
 *           knowledge base, an audit system or a version registry.
 */
final class Finding
{
    public const HIGH   = 'high';
    public const MEDIUM = 'medium';
    public const LOW    = 'low';
    public const NONE   = 'none';

    private const RANK = [self::NONE => 0, self::LOW => 1, self::MEDIUM => 2, self::HIGH => 3];

    /**
     * @param  mixed                $value      what was found; null when nothing was
     * @param  string               $confidence one of the constants above
     * @param  array<int, string>   $evidence   what was inspected to conclude this
     * @param  array<int, mixed>    $alternatives other readings, when more than one fit
     */
    public function __construct(
        public readonly mixed $value,
        public readonly string $confidence = self::NONE,
        public readonly array $evidence = [],
        public readonly array $alternatives = [],
    ) {
    }

    public static function found(mixed $value, string $confidence, array $evidence = [], array $alternatives = []): self
    {
        return new self($value, $confidence, $evidence, $alternatives);
    }

    /** Looked for, not present. A normal outcome for every optional integration. */
    public static function absent(array $evidence = []): self
    {
        return new self(null, self::NONE, $evidence);
    }

    public function isPresent(): bool
    {
        return $this->value !== null && $this->value !== [] && $this->value !== '';
    }

    /**
     * May the installer apply this without asking?
     *
     * Only HIGH. Security-sensitive findings are additionally gated by the
     * installer itself regardless of confidence — a HIGH-confidence tenant
     * column is still confirmed by a human, because being confidently wrong
     * about tenancy is the one failure with no symptoms.
     */
    public function isAutoApplicable(): bool
    {
        return $this->confidence === self::HIGH && $this->isPresent();
    }

    public function atLeast(string $confidence): bool
    {
        return (self::RANK[$this->confidence] ?? 0) >= (self::RANK[$confidence] ?? 0);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'value'        => $this->value,
            'confidence'   => $this->confidence,
            'evidence'     => $this->evidence ?: null,
            'alternatives' => $this->alternatives ?: null,
        ], static fn ($v) => $v !== null);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['value'] ?? null,
            (string) ($data['confidence'] ?? self::NONE),
            (array) ($data['evidence'] ?? []),
            (array) ($data['alternatives'] ?? []),
        );
    }
}
