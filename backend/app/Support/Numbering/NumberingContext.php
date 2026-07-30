<?php

namespace App\Support\Numbering;

use Carbon\CarbonInterface;

/**
 * Everything a placeholder resolver may need to expand one token. Passing a
 * context object (rather than a growing argument list) is what lets new
 * placeholders plug in without changing the engine's signatures.
 */
final class NumberingContext
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $documentType,
        public readonly array $config,
        public readonly CarbonInterface $at,
        public readonly ?int $sequence = null,
        /** Free-form extras supplied by the caller: branch, department, company … */
        public readonly array $extra = [],
    ) {
    }

    public function extra(string $key, string $default = ''): string
    {
        $v = $this->extra[$key] ?? null;

        return $v === null || $v === '' ? $default : (string) $v;
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function withSequence(int $sequence): self
    {
        return new self($this->tenantId, $this->documentType, $this->config, $this->at, $sequence, $this->extra);
    }
}
