<?php

namespace App\Services\Hr\Scoring\Dimensions;

/**
 * What every dimension returns.
 *
 * `score === null` means "this dimension had nothing to measure". It is NOT zero,
 * and the engine must drop it from the weighted sum rather than score it down —
 * absence of evidence is not evidence of a poor fit. That single rule is what
 * separates this engine from the heuristic it replaces.
 */
final class DimensionResult
{
    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?int $score,
        public readonly string $reason,
        public readonly array $evidence = [],
    ) {
    }

    public static function scored(string $key, string $label, float|int $score, string $reason, array $evidence = []): self
    {
        return new self($key, $label, (int) round(max(0, min(100, $score))), $reason, $evidence);
    }

    /** No data available. `$reason` must say WHICH input was missing. */
    public static function unavailable(string $key, string $label, string $reason, array $evidence = []): self
    {
        return new self($key, $label, null, $reason, $evidence);
    }

    public function isScored(): bool
    {
        return $this->score !== null;
    }

    public function toArray(): array
    {
        return [
            'key'      => $this->key,
            'name'     => $this->label,
            'score'    => $this->score,
            'reason'   => $this->reason,
            'evidence' => $this->evidence,
        ];
    }
}
